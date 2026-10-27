<?php

namespace App\Services\FormStock;

use App\Models\FormStock\StockWithdrawalPlanItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class StockWithdrawalPlanningService
{
    private const CONNECTIONS = ['WIRE' => 'pgsqlmfgw', 'PLUS' => 'pgsqlmfgp'];

    public function allMfg(array $filters = []): Collection
    {
        $rows = collect();
        foreach (self::CONNECTIONS as $site => $connection) {
            if (!empty($filters['site']) && strtoupper($filters['site']) !== $site) continue;
            $bindings = [];
            $where = '';
            if (!empty($filters['date_from'])) { $where .= ' AND wo.reqdate::date >= ?::date'; $bindings[] = $filters['date_from']; }
            if (!empty($filters['date_to'])) { $where .= ' AND wo.reqdate::date <= ?::date'; $bindings[] = $filters['date_to']; }
            if (!empty($filters['mfg'])) { $where .= ' AND UPPER(wo.workordernumber) LIKE ?'; $bindings[] = '%'.mb_strtoupper(trim($filters['mfg'])).'%'; }

            $sql = "SELECT ?::text site, wo.id workorder_id, UPPER(TRIM(wo.workordernumber)) mfg,
                           UPPER(TRIM(p.partnumber)) partnumber, p.description part_description,
                           COALESCE(NULLIF(p.f1,''), p.description) size, wo.qty quantity, COALESCE(um.description, p.unit) unit,
                           c.name customer_name, wo.reqdate::date delivery_date, wo.notes mfg_note, UPPER(TRIM(wo.fcat)) fcat
                    FROM workorder wo
                    LEFT JOIN parts p ON p.id=wo.parts_id
                    LEFT JOIN unitmeasure um ON um.unit=p.unit
                    LEFT JOIN customer c ON c.id=wo.customer_id
                    WHERE wo.dateclose IS NULL AND COALESCE(wo.suspended,false)=false
                      AND wo.reqdate IS NOT NULL
                      AND wo.workordernumber !~* '^(\\+)?EX'
                      AND wo.workordernumber !~* '\\(C\\)$' {$where}
                    ORDER BY wo.reqdate, wo.workordernumber";
            array_unshift($bindings, $site);
            $siteRows = collect(DB::connection($connection)->select($sql, $bindings));
            $currentWorkCenters = $this->currentWorkCenters($site, $siteRows->pluck('workorder_id')->all());
            $siteRows->each(function ($row) use ($currentWorkCenters) {
                $workCenter = $currentWorkCenters->get($row->workorder_id);
                $classification = $this->withdrawalClassification($row->partnumber, $workCenter->workcenternumber ?? null, $workCenter->workcenter_name ?? null);
                $row->current_work_center = collect([$workCenter->workcenternumber ?? null, $workCenter->workcenter_name ?? null])->filter()->implode(' — ') ?: null;
                $row->production_type_code = $classification['code'];
                $row->production_type_name = $classification['name'];
            });
            $rows = $rows->merge($siteRows);
        }
        return $rows->values();
    }

    /**
     * Hide open MFGs only when every withdrawal-related BOM part has already
     * been issued in full. MFGs without a matching BOM stay visible so data
     * problems do not disappear silently from the planning screen.
     */
    public function excludeFullyWithdrawnMfg(Collection $rows): Collection
    {
        $completedKeys = collect();

        foreach ($rows->groupBy(fn ($row) => strtoupper((string) $row->site)) as $site => $siteRows) {
            $connection = self::CONNECTIONS[$site] ?? null;
            if (!$connection) continue;

            $siteRows->pluck('workorder_id')->filter()->unique()->chunk(500)->each(function (Collection $workorderIds) use ($connection, $site, $completedKeys) {
                $required = DB::connection($connection)->table('workorderbom as bom')
                    ->join('workorder as wo', 'wo.id', '=', 'bom.workorder_id')
                    ->join('parts as p', 'p.id', '=', 'bom.parts_id')
                    ->whereIn('bom.workorder_id', $workorderIds->all())
                    ->where(function ($query) {
                        $query->whereRaw("UPPER(TRIM(p.partnumber)) LIKE 'F%'")
                            ->orWhereRaw("UPPER(TRIM(p.partnumber)) LIKE 'R%'")
                            ->orWhereRaw("UPPER(TRIM(p.partnumber)) LIKE 'N%'")
                            ->orWhereRaw("UPPER(TRIM(p.partnumber)) LIKE 'S%'");
                    })
                    ->groupBy('bom.workorder_id', 'bom.parts_id')
                    ->selectRaw('bom.workorder_id, bom.parts_id, COALESCE(NULLIF(SUM(bom.qty), 0), NULLIF(MAX(wo.qty), 0), 0) required_qty');

                $issued = DB::connection($connection)->table('workordermatusage')
                    ->whereIn('workorder_id', $workorderIds->all())
                    ->groupBy('workorder_id', 'parts_id')
                    ->selectRaw('workorder_id, parts_id, COALESCE(SUM(qty), 0) issued_qty');

                $balances = DB::connection($connection)->query()
                    ->fromSub($required, 'required')
                    ->leftJoinSub($issued, 'issued', function ($join) {
                        $join->on('issued.workorder_id', '=', 'required.workorder_id')
                            ->on('issued.parts_id', '=', 'required.parts_id');
                    })
                    ->get([
                        'required.workorder_id',
                        'required.parts_id',
                        'required.required_qty',
                        DB::raw('COALESCE(issued.issued_qty, 0) issued_qty'),
                    ]);

                foreach ($balances->groupBy('workorder_id') as $workorderId => $parts) {
                    if ($parts->isNotEmpty() && $parts->every(fn ($part) => round((float) $part->issued_qty, 4) >= round((float) $part->required_qty, 4))) {
                        $completedKeys->push($site.'|'.$workorderId);
                    }
                }
            });
        }

        $completedKeys = $completedKeys->flip();

        return $rows->reject(fn ($row) => $completedKeys->has(strtoupper((string) $row->site).'|'.$row->workorder_id))->values();
    }

    public function findMfg(string $site, int $workorderId): ?object
    {
        $connection = self::CONNECTIONS[strtoupper($site)] ?? null;
        if (!$connection) return null;
        return DB::connection($connection)->table('workorder as wo')
            ->leftJoin('parts as p', 'p.id', '=', 'wo.parts_id')
            ->leftJoin('unitmeasure as um', 'um.unit', '=', 'p.unit')
            ->leftJoin('customer as c', 'c.id', '=', 'wo.customer_id')
            ->where('wo.id', $workorderId)
            ->selectRaw("wo.id workorder_id, UPPER(TRIM(wo.workordernumber)) mfg, UPPER(TRIM(p.partnumber)) partnumber,
                p.description part_description, COALESCE(NULLIF(p.f1,''),p.description) size, wo.qty quantity,
                COALESCE(um.description,p.unit) unit, c.name customer_name, wo.reqdate::date delivery_date, wo.notes mfg_note, UPPER(TRIM(wo.fcat)) fcat")
            ->first();
    }

    public function bomItems(string $site, int $workorderId): Collection
    {
        $connection = self::CONNECTIONS[strtoupper($site)] ?? null;
        if (!$connection) return collect();

        $currentWorkCenter = $this->currentWorkCenter($site, $workorderId);

        return DB::connection($connection)->table('workorderbom as bom')
            ->join('workorder as wo', 'wo.id', '=', 'bom.workorder_id')
            ->join('parts as p', 'p.id', '=', 'bom.parts_id')
            ->leftJoin('unitmeasure as um', 'um.unit', '=', 'p.unit')
            ->where('bom.workorder_id', $workorderId)
            ->where(function ($query) {
                $query->whereRaw("UPPER(TRIM(p.partnumber)) LIKE 'F%'")
                    ->orWhereRaw("UPPER(TRIM(p.partnumber)) LIKE 'R%'")
                    ->orWhereRaw("UPPER(TRIM(p.partnumber)) LIKE 'N%'")
                    ->orWhereRaw("UPPER(TRIM(p.partnumber)) LIKE 'S%'");
            })
            ->groupBy('p.id', 'p.partnumber', 'p.description', 'p.f1', 'p.unit', 'um.description', 'wo.qty')
            ->orderBy('p.partnumber')
            ->selectRaw("p.id source_part_id, UPPER(TRIM(p.partnumber)) partnumber, p.description part_description,
                COALESCE(NULLIF(p.f1,''), p.description) size,
                COALESCE(NULLIF(SUM(bom.qty), 0), NULLIF(wo.qty, 0)) quantity, COALESCE(um.description,p.unit) unit")
            ->get()
            ->map(function ($item) use ($currentWorkCenter) {
                $classification = $this->withdrawalClassification(
                    $item->partnumber,
                    $currentWorkCenter->workcenternumber ?? null,
                    $currentWorkCenter->workcenter_name ?? null
                );
                $item->type_code = $classification['code'];
                $item->type_name = $classification['name'];
                $item->plan_section = $classification['section'];
                return $item;
            });
    }

    public function currentWorkCenter(string $site, int $workorderId): ?object
    {
        return $this->currentWorkCenters($site, [$workorderId])->get($workorderId);
    }

    public function currentWorkCenters(string $site, array $workorderIds): Collection
    {
        $connection = self::CONNECTIONS[strtoupper($site)] ?? null;
        $workorderIds = collect($workorderIds)->filter()->unique()->values();
        if (!$connection || $workorderIds->isEmpty()) return collect();

        $ranked = DB::connection($connection)->table('workorderreceive as wr')
            ->whereIn('wr.workorder_id', $workorderIds)
            ->selectRaw('wr.workorder_id, wr.workseq, wr.workcenter_id, ROW_NUMBER() OVER (PARTITION BY wr.workorder_id ORDER BY wr.workseq DESC, wr.id DESC) AS row_number');

        return DB::connection($connection)->query()->fromSub($ranked, 'latest')
            ->join('workcenter as wc', 'wc.id', '=', 'latest.workcenter_id')
            ->where('latest.row_number', 1)
            ->get(['latest.workorder_id', 'latest.workseq', 'wc.workcenternumber', 'wc.description as workcenter_name'])
            ->keyBy('workorder_id');
    }

    public function withdrawalClassification(?string $partnumber, ?string $workCenterCode = null, ?string $workCenterName = null): array
    {
        $partnumber = strtoupper(trim((string) $partnumber));
        $workCenter = trim((string) $workCenterCode).' '.trim((string) $workCenterName);
        if (mb_stripos($workCenter, 'กัดกรด') !== false) return ['code' => 'ACID', 'name' => 'กัดกรด', 'section' => 1, 'standard_code' => 'RM'];
        if (str_starts_with($partnumber, 'NCE')) return ['code' => 'NCR', 'name' => 'NCR', 'section' => 2, 'standard_code' => 'FG'];
        if (str_starts_with($partnumber, 'R')) return ['code' => 'RM', 'name' => 'วัตถุดิบ', 'section' => 1, 'standard_code' => 'RM'];
        if (str_starts_with($partnumber, 'S')) return ['code' => 'S', 'name' => 'S', 'section' => 2, 'standard_code' => 'FG'];
        return ['code' => 'FG', 'name' => 'FG', 'section' => 2, 'standard_code' => 'FG'];
    }

    public function subtractWorkingDays(Carbon $date, int $days): Carbon
    {
        $result = $date->copy()->startOfDay();
        while ($days > 0) { $result->subDay(); if (!$result->isSunday()) $days--; }
        return $result;
    }

    public function completionDate(Carbon $requestDate, int $leadTimeDays): Carbon
    {
        $result = $requestDate->copy()->startOfDay();
        $remaining = max(1, $leadTimeDays) - 1;
        while ($remaining > 0) { $result->addDay(); if (!$result->isSunday()) $remaining--; }
        return $result;
    }

    public function nextWorkingDates(Carbon $start, int $count): array
    {
        $dates = []; $date = $start->copy()->startOfDay();
        while (count($dates) < $count) { if (!$date->isSunday()) $dates[] = $date->toDateString(); $date->addDay(); }
        return $dates;
    }

    public function nextWorkingDay(Carbon $date): Carbon
    {
        $next = $date->copy()->startOfDay()->addDay();
        while ($next->isSunday()) $next->addDay();
        return $next;
    }

    public function suggestedJobType(?string $fcat, ?string $partnumber): array
    {
        $fcat = strtoupper(trim((string) $fcat));
        $code = in_array($fcat, config('stock-withdrawal.wire_fcats', []), true) ? 'WIRE_PRODUCT'
            : (in_array($fcat, config('stock-withdrawal.shaft_fcats', []), true) ? 'SHAFT'
            : (str_starts_with(strtoupper(trim($partnumber)), 'R') ? 'RAW_MATERIAL' : 'WIRE_PRODUCT'));
        return ['code' => $code] + config("stock-withdrawal.job_types.{$code}");
    }

    public function issuedQuantities(string $site, array $keys): Collection
    {
        $connection = self::CONNECTIONS[strtoupper($site)] ?? null;
        if (!$connection || !$keys) return collect();

        $workorderIds = collect($keys)->pluck('workorder_id')->filter()->unique()->values();
        $partIds = collect($keys)->pluck('parts_id')->filter()->unique()->values();
        if ($workorderIds->isEmpty() || $partIds->isEmpty()) return collect();

        return DB::connection($connection)->table('workordermatusage')
            ->whereIn('workorder_id', $workorderIds)
            ->whereIn('parts_id', $partIds)
            ->groupBy('workorder_id', 'parts_id')
            ->selectRaw('workorder_id, parts_id, COALESCE(SUM(qty), 0) issued_qty')
            ->get()->keyBy(fn($row) => $row->workorder_id.'|'.$row->parts_id);
    }

    public function attachWithdrawalBalances(Collection $items, bool $useCache = false): Collection
    {
        foreach ($items->groupBy(fn($item) => $item->plan->site) as $site => $siteItems) {
            $keys = $siteItems->map(fn($item) => [
                'workorder_id' => $item->plan->workorder_id,
                'parts_id' => $item->source_part_id,
            ])->sortBy(fn($key) => $key['workorder_id'].'|'.$key['parts_id'])->values()->all();
            $connection = self::CONNECTIONS[strtoupper($site)] ?? null;
            $workorderIds = $siteItems->pluck('plan.workorder_id')->filter()->unique()->sort()->values();
            $loader = function () use ($site, $keys, $connection, $workorderIds) {
                $issued = $this->issuedQuantities($site, $keys);
                $workCenters = $connection
                    ? DB::connection($connection)->table('workorderworkcenter as wowc')
                    ->join('workcenter as wc', 'wc.id', '=', 'wowc.workcenter_id')
                    ->whereIn('wowc.workorder_id', $workorderIds)
                    ->whereRaw("UPPER(TRIM(COALESCE(wc.workcenternumber, ''))) <> ?", ['RM'])
                    ->orderBy('wowc.workorder_id')->orderBy('wowc.workseq')
                    ->get(['wowc.workorder_id', 'wowc.workseq', 'wc.workcenternumber', 'wc.description as workcenter_name'])
                    ->groupBy('workorder_id')->map->first()
                    : collect();
                return ['issued' => $issued, 'work_centers' => $workCenters];
            };
            $cacheKey = 'stock-withdrawal:erp-balances:v1:'.strtoupper($site).':'.sha1(json_encode([$keys, $workorderIds->all()]));
            $erp = $useCache ? Cache::remember($cacheKey, now()->addSeconds(15), $loader) : $loader();
            $issued = collect($erp['issued']);
            $workCenters = collect($erp['work_centers']);
            foreach ($siteItems as $item) {
                $row = $issued->get($item->plan->workorder_id.'|'.$item->source_part_id);
                $workCenter = $workCenters->get($item->plan->workorder_id);
                $total = round((float) ($item->withdraw_quantity ?? $item->quantity ?? 0), 4);
                $withdrawn = round((float) ($row->issued_qty ?? 0), 4);
                $item->withdrawn_qty = $withdrawn;
                $item->remaining_qty = max(0, round($total - $withdrawn, 4));
                $item->first_work_center_number = trim((string) ($workCenter->workcenternumber ?? '')) ?: null;
                $item->first_work_center_name = trim((string) ($workCenter->workcenter_name ?? '')) ?: null;
                $item->first_work_center = collect([$item->first_work_center_number, $item->first_work_center_name])->filter()->implode(' — ') ?: null;
                $item->withdrawal_status_code = $withdrawn <= 0 ? 'NOT_STARTED' : ($item->remaining_qty > 0 ? 'PARTIAL' : 'COMPLETED');
                $item->withdrawal_status = [
                    'NOT_STARTED' => 'ยังไม่เบิก',
                    'PARTIAL' => 'เบิกบางส่วน',
                    'COMPLETED' => 'เบิกครบแล้ว',
                ][$item->withdrawal_status_code];
                if ($item->planner_closed_at) {
                    $item->withdrawal_status_code = 'PLANNER_CLOSED';
                    $item->withdrawal_status = 'ปิดโดย Planner';
                }
                if ($item->plan->cancelled_at) {
                    $item->withdrawal_status_code = 'CANCELED';
                    $item->withdrawal_status = 'ยกเลิก';
                }

                // These values are calculated from ERP at runtime and are not
                // columns on stock_withdrawal_plan_items. Mark them as clean so
                // a later update on the same model never tries to persist them.
                $item->syncOriginalAttributes([
                    'withdrawn_qty',
                    'remaining_qty',
                    'withdrawal_status_code',
                    'withdrawal_status',
                    'first_work_center_number',
                    'first_work_center_name',
                    'first_work_center',
                ]);
            }
        }

        return $items;
    }

    public function erpSnapshotAvailable(): bool
    {
        return Schema::connection('sqlsrv_menam')->hasColumn('stock_withdrawal_plan_items', 'erp_synced_at');
    }

    public function syncErpSnapshots(Collection $items): int
    {
        if ($items->isEmpty() || !$this->erpSnapshotAvailable()) return 0;

        $items->loadMissing('plan');
        $this->attachWithdrawalBalances($items);
        $syncedAt = now('Asia/Bangkok');

        DB::connection('sqlsrv_menam')->transaction(function () use ($items, $syncedAt) {
            foreach ($items as $item) {
                DB::connection('sqlsrv_menam')->table('stock_withdrawal_plan_items')
                    ->where('id', $item->id)
                    ->update([
                        'erp_withdrawn_qty' => $item->withdrawn_qty,
                        'erp_remaining_qty' => $item->remaining_qty,
                        'erp_status_code' => in_array($item->withdrawal_status_code, ['NOT_STARTED', 'PARTIAL', 'COMPLETED'], true)
                            ? $item->withdrawal_status_code
                            : ((float) $item->withdrawn_qty <= 0 ? 'NOT_STARTED' : ((float) $item->remaining_qty > 0 ? 'PARTIAL' : 'COMPLETED')),
                        'erp_first_work_center_number' => $item->first_work_center_number,
                        'erp_first_work_center_name' => $item->first_work_center_name,
                        'erp_synced_at' => $syncedAt,
                    ]);
            }
        });

        return $items->count();
    }

    public function syncActiveErpSnapshots(bool $includeCompleted = false): int
    {
        if (!$this->erpSnapshotAvailable()) return 0;

        $count = 0;
        StockWithdrawalPlanItem::query()
            ->with('plan')
            ->whereNull('planner_closed_at')
            ->whereHas('plan', fn ($query) => $query->whereNull('cancelled_at'))
            ->when(!$includeCompleted, fn ($query) => $query->where(function ($query) {
                $query->whereNull('erp_status_code')->orWhere('erp_status_code', '<>', 'COMPLETED');
            }))
            ->orderBy('id')
            ->chunkById(200, function ($items) use (&$count) {
                $count += $this->syncErpSnapshots($items);
            });

        return $count;
    }

    public function attachSnapshotBalances(Collection $items): Collection
    {
        foreach ($items as $item) {
            $item->withdrawn_qty = round((float) ($item->erp_withdrawn_qty ?? 0), 4);
            $item->remaining_qty = max(0, round((float) ($item->erp_remaining_qty ?? ($item->withdraw_quantity ?? $item->quantity ?? 0)), 4));
            $item->first_work_center_number = $item->erp_first_work_center_number ?: null;
            $item->first_work_center_name = $item->erp_first_work_center_name ?: null;
            $item->first_work_center = collect([$item->first_work_center_number, $item->first_work_center_name])->filter()->implode(' — ') ?: null;
            $item->withdrawal_status_code = $item->erp_status_code ?: 'NOT_STARTED';
            $item->withdrawal_status = [
                'NOT_STARTED' => 'ยังไม่เบิก', 'PARTIAL' => 'เบิกบางส่วน', 'COMPLETED' => 'เบิกครบแล้ว',
            ][$item->withdrawal_status_code] ?? 'ยังไม่เบิก';
            if ($item->planner_closed_at) {
                $item->withdrawal_status_code = 'PLANNER_CLOSED';
                $item->withdrawal_status = 'ปิดโดย Planner';
            }
            if ($item->plan->cancelled_at) {
                $item->withdrawal_status_code = 'CANCELED';
                $item->withdrawal_status = 'ยกเลิก';
            }
            $item->syncOriginalAttributes(['withdrawn_qty', 'remaining_qty', 'withdrawal_status_code', 'withdrawal_status', 'first_work_center_number', 'first_work_center_name', 'first_work_center']);
        }

        return $items;
    }

    public function overdueDays($plannedDate, ?Carbon $asOf = null): int
    {
        $date = Carbon::parse($plannedDate)->startOfDay();
        $asOf ??= now('Asia/Bangkok')->startOfDay();
        return $date->lt($asOf) ? -$date->diffInDays($asOf) : $asOf->diffInDays($date);
    }

    public function alertRiskCounts(Collection $items, ?Carbon $asOf = null): array
    {
        $counts = ['red_count'=>0, 'orange_count'=>0, 'yellow_count'=>0, 'gray_count'=>0];
        foreach ($items as $item) {
            $days = $this->overdueDays($item->planned_withdraw_date, $asOf);
            $key = $days < 0 ? 'red_count' : ($days === 0 ? 'orange_count' : ($days === 1 ? 'yellow_count' : 'gray_count'));
            $counts[$key]++;
        }
        return $counts;
    }
}
