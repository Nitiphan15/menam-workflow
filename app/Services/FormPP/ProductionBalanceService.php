<?php

namespace App\Services\FormPP;

use App\Services\FormWIP\WipMonitoringService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductionBalanceService
{
    public function __construct(private ProductionBalanceCalculator $calculator, private WipMonitoringService $wipMonitoring)
    {
    }

    public function report(array $filters = []): Collection
    {
        $siteFilter = strtoupper((string) ($filters['site'] ?? 'ALL'));
        $orders = $this->salesOrderBacklog();
        $items = $orders->pluck('item')->filter()->unique()->values()->all();
        if ($items === []) {
            return collect();
        }

        $sites = $siteFilter === 'ALL' ? ['WIRE', 'PLUS'] : [$siteFilter];
        $production = collect();
        $fg = collect();
        foreach ($sites as $site) {
            $dataset = $this->wipMonitoring->computeSiteRows($site, WipMonitoringService::SYNC_DAYS);
            $production = $production->concat(collect($dataset['rows'])
                ->filter(fn (array $row) => in_array($this->normalize($row['part_number']), $items, true))
                ->map(fn (array $row) => (object) [
                    'so' => $this->normalize($row['ord_number']),
                    'item' => $this->normalize($row['part_number']),
                    'description' => $row['part_desc'],
                    'customer' => $row['customer'],
                    'site' => $site,
                    'mfg' => $this->normalizeMfg($row['wo_no']),
                    'mfg_qty' => (float) ($row['buckets']['WAITING_PRODUCTION'] ?? 0),
                    'wip_qty' => collect(['IN_PROCESS', 'WAITING_PACKAGING', 'WAITING_FG', 'HOLD'])
                        ->sum(fn (string $bucket) => (float) ($row['buckets'][$bucket] ?? 0)),
                ]));
            $fg = $fg->concat($this->fgStock($this->erpConnection($site), $site, $items));
        }

        return $this->calculator->calculate($orders, $production, $fg)
            ->filter(function ($row) use ($filters) {
                if (($filters['status'] ?? '') !== '' && $row->status !== strtoupper((string) $filters['status'])) {
                    return false;
                }
                $q = $this->normalize($filters['q'] ?? '');
                return $q === '' || str_contains($this->normalize($row->customer.' '.$row->item.' '.$row->so.' '.implode(' ', $row->mfg_numbers)), $q);
            })
            ->sortBy(fn ($row) => [array_search($row->status, ['DATA_ISSUE', 'EXCESS', 'SHORT', 'COMPLETE'], true), $row->due_date ?: '9999-12-31', $row->so])
            ->values();
    }

    private function salesOrderBacklog(): Collection
    {
        $db = DB::connection('pgsqlw');
        $shipped = $db->table('invoice as inv')->join('ar', 'inv.trans_id', '=', 'ar.id')
            ->whereNotNull('ar.ordnumber')->groupBy(['ar.ordnumber', 'inv.parts_id'])
            ->selectRaw('ar.ordnumber, inv.parts_id, SUM(inv.qty) shipped_qty');
        $dmShipped = $db->table('oe')->join('oedm', 'oe.id', '=', 'oedm.ord_id')
            ->join('dm', 'oedm.dm_id', '=', 'dm.id')->join('dmpredm', 'dm.id', '=', 'dmpredm.dm_id')
            ->join('dmitems as dmi', 'dmpredm.dmitems_id', '=', 'dmi.id')
            ->whereNotNull('oe.ordnumber')->groupBy(['oe.ordnumber', 'dmi.parts_id'])
            ->selectRaw('oe.ordnumber, dmi.parts_id, SUM(dmi.qty) shipped_qty');
        $returns = $db->table('returnitems as ri')->join('return as ret', 'ri.trans_id', '=', 'ret.id')
            ->join('invoice as inv', 'inv.id', '=', 'ri.invoice_id')->join('ar', 'inv.trans_id', '=', 'ar.id')
            ->where('ret.hasitems', true)->whereNotNull('ar.ordnumber')->groupBy(['ar.ordnumber', 'inv.parts_id'])
            ->selectRaw('ar.ordnumber, inv.parts_id, SUM(ri.qty) return_qty');

        return $db->table('orderitems as oi')->join('oe', 'oi.trans_id', '=', 'oe.id')
            ->join('parts as p', 'p.id', '=', 'oi.parts_id')->join('customer as c', 'c.id', '=', 'oe.customer_id')
            ->leftJoinSub($shipped, 's', fn ($j) => $j->on('s.ordnumber', '=', 'oe.ordnumber')->on('s.parts_id', '=', 'oi.parts_id'))
            ->leftJoinSub($dmShipped, 'd', fn ($j) => $j->on('d.ordnumber', '=', 'oe.ordnumber')->on('d.parts_id', '=', 'oi.parts_id'))
            ->leftJoinSub($returns, 'r', fn ($j) => $j->on('r.ordnumber', '=', 'oe.ordnumber')->on('r.parts_id', '=', 'oi.parts_id'))
            ->where('oe.shipped_or_received', false)->where('oe.invoiced', false)
            ->whereBetween('oe.transdate', [now()->subYear()->toDateString(), now()->toDateString()])
            ->groupBy(['oe.ordnumber', 'p.partnumber', 'p.description', 'p.unit', 'p.ref_unit_qty', 'c.name'])
            ->havingRaw('SUM(oi.qty) - (COALESCE(MAX(s.shipped_qty),MAX(d.shipped_qty),0) - COALESCE(MAX(r.return_qty),0)) > 0')
            ->selectRaw('UPPER(TRIM(oe.ordnumber)) so, UPPER(TRIM(p.partnumber)) item, p.description, p.unit, p.ref_unit_qty, TRIM(c.name) customer, MIN(oe.transdate) order_date, MIN(oi.reqdate) due_date, SUM(oi.qty) - (COALESCE(MAX(s.shipped_qty),MAX(d.shipped_qty),0) - COALESCE(MAX(r.return_qty),0)) backorder_qty')
            ->get()->map(function ($row) {
                [$qty, $issues] = $this->convertBackorderToKg((float) $row->backorder_qty, $row->unit, $row->ref_unit_qty);
                return (object) [
                    'so' => $row->so, 'item' => $row->item, 'customer' => $row->customer,
                    'description' => $row->description, 'backorder_kg' => round($qty, 4),
                    'order_date' => $row->order_date, 'due_date' => $row->due_date, 'data_issues' => $issues,
                ];
            });
    }

    public function convertBackorderToKg(float $qty, $unit, $refUnitQty): array
    {
        if (in_array($this->normalize($unit), ['KG', 'KGS', 'KILOGRAM'], true)) {
            return [$qty, []];
        }

        $factor = (float) ($refUnitQty ?? 0);
        if ($factor > 0) {
            return [round($qty * $factor, 4), []];
        }

        return [0.0, ["ไม่สามารถแปลงหน่วย {$unit} เป็น KG"]];
    }

    private function fgStock(string $connection, string $site, array $items): Collection
    {
        return DB::connection($connection)->table('parts as p')
            ->join('serializeunits as su', 'su.parts_id', '=', 'p.id')
            ->join('serializeunitsmvmt as sm', 'sm.su_id', '=', 'su.id')
            ->whereIn(DB::raw('UPPER(TRIM(p.partnumber))'), $items)->groupBy('p.partnumber')
            ->selectRaw("UPPER(TRIM(p.partnumber)) item, '{$site}' site, SUM(CASE WHEN su.onhand THEN sm.qty ELSE 0 END) qty")
            ->havingRaw('SUM(CASE WHEN su.onhand THEN sm.qty ELSE 0 END) > 0')->get();
    }

    private function erpConnection(string $site): string { return $site === 'PLUS' ? 'pgsqlp' : 'pgsqlw'; }
    private function normalizeMfg($value): string { return ltrim($this->normalize($value), '+'); }
    private function normalize($value): string { return mb_strtoupper(trim((string) $value), 'UTF-8'); }
}
