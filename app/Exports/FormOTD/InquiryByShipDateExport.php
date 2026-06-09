<?php

namespace App\Exports\FormOTD;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class InquiryByShipDateExport implements WithMultipleSheets
{
    public function __construct(private array $filters = []) {}

    public function sheets(): array
    {
        $dates = $this->baseQuery()
            ->selectRaw("CONVERT(date, d.ship_posted_at) as d")
            ->whereNotNull('d.ship_posted_at')
            ->groupByRaw("CONVERT(date, d.ship_posted_at)")
            ->orderByRaw("CONVERT(date, d.ship_posted_at)")
            ->pluck('d')
            ->map(fn($x) => (string) $x)
            ->all();

        if (!$dates) {
            $dates = ['NO_SHIP_DATE'];
        }

        return array_map(
            fn($d) => new InquiryShipDateSheet($d, $this->filters),
            $dates
        );
    }

    private function baseQuery()
    {
        $q = DB::connection('sqlsrv_menam')
            ->table('delivery_plan_data as d')
            ->leftJoin('customer as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('sales_master as s', 's.sales_id', '=', 'd.sales_id')
            ->leftJoin('employees as e', 'e.id', '=', 'd.sales_id');

        [$shipFrom, $shipTo] = $this->resolveShipDateRange();

        $q->whereBetween('d.ship_posted_at', [
            Carbon::parse($shipFrom)->startOfDay()->format('Y-m-d H:i:s'),
            Carbon::parse($shipTo)->endOfDay()->format('Y-m-d H:i:s'),
        ]);

        $mode = strtoupper(trim((string) ($this->filters['mode'] ?? '')));
        $status = strtoupper(trim((string) ($this->filters['status'] ?? 'NEW')));
        $revision = trim((string) ($this->filters['revision_number'] ?? ''));
        $revisionMax = trim((string) ($this->filters['revision_max_number'] ?? ''));
        $excludeVoidCancel = (string) ($this->filters['exclude_void_cancel'] ?? '') === '1';
        $so = trim((string) ($this->filters['so'] ?? ''));
        $customer = trim((string) ($this->filters['customer'] ?? ''));
        $shipto = trim((string) ($this->filters['shipto'] ?? ''));
        $divsales = trim((string) ($this->filters['divsales'] ?? ''));

        if ($mode === 'SO') {
            $q->where('d.delivery_type', 'SO');
        } elseif ($mode === 'ACID') {
            $q->where('d.delivery_type', 'ACID');
        }

        if ($so !== '') {
            $q->where('d.so_number', 'like', "%{$so}%");
        }

        if ($customer !== '') {
            $q->where(function ($w) use ($customer) {
                $w->where('c.name', 'like', "%{$customer}%")
                    ->orWhere('c.customernumber', 'like', "%{$customer}%");
            });
        }

        if ($shipto !== '') {
            $q->where('d.address', 'like', "%{$shipto}%");
        }

        if ($divsales !== '') {
            $this->applyDivisionSalesFilter($q, $divsales);
        }

        if ($status !== 'ALL' && $status !== '') {
            $q->where('d.status', $status);
        }

        if ($excludeVoidCancel) {
            $q->whereRaw("ISNULL(d.status,'') NOT IN ('VOID','CANCEL')");
        }

        if ($revisionMax !== '' && is_numeric($revisionMax)) {
            $q->where('d.revision_number', '<=', (int) $revisionMax);
        } elseif ($revision !== '' && is_numeric($revision)) {
            $q->where('d.revision_number', (int) $revision);
        }

        return $q;
    }

    private function resolveShipDateRange(): array
    {
        $shipFrom = $this->normalizeDateInput((string) ($this->filters['ship_from'] ?? ''));
        $shipTo = $this->normalizeDateInput((string) ($this->filters['ship_to'] ?? ''));

        if ($shipFrom === null && $shipTo === null) {
            $today = now()->startOfDay()->format('Y-m-d');
            return [$today, $today];
        }

        if ($shipFrom === null || $shipTo === null) {
            $today = now()->startOfDay()->format('Y-m-d');
            return [$today, $today];
        }

        return [$shipFrom, $shipTo];
    }

    private function normalizeDateInput(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function applyDivisionSalesFilter($q, string $divsales): void
    {
        $groups = $this->expandDivisionSearchTokens($divsales);

        if (empty($groups)) {
            return;
        }

        $q->where(function ($outer) use ($groups) {
            foreach ($groups as $group) {
                $outer->where(function ($w) use ($group) {
                    foreach ($group as $keyword) {
                        $kw = '%' . $keyword . '%';

                        $w->orWhere('s.sales_name', 'like', $kw)
                            ->orWhere('s.sales_code', 'like', $kw)
                            ->orWhere('e.login', 'like', $kw)
                            ->orWhere('e.name', 'like', $kw);
                    }
                });
            }
        });
    }

    private function expandDivisionSearchTokens(string $keyword): array
    {
        $tokens = preg_split('/\s+/u', trim($keyword), -1, PREG_SPLIT_NO_EMPTY);
        $map = $this->divisionSearchMap();
        $expanded = [];

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            $matched = false;

            foreach ($map as $groupKeywords) {
                $groupKeywordsLower = array_map(
                    fn($v) => mb_strtolower((string) $v),
                    $groupKeywords
                );

                if (in_array(mb_strtolower($token), $groupKeywordsLower, true)) {
                    $expanded[] = $groupKeywords;
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                $expanded[] = [$token];
            }
        }

        return $expanded;
    }

    private function divisionSearchMap(): array
    {
        return [
            ['D1', 'DIV1', 'DIVISION1'],
            ['D2', 'DIV2', 'DIVISION2'],
            ['D3', 'DIV3', 'DIVISION3'],
            ['D4', 'DIV4', 'DIVISION4'],
            ['D5', 'DIV5', 'DIVISION5'],
            ['D6', 'DIV6', 'DIVISION6'],
            ['PLN', 'PLAN', 'PLANNER'],
        ];
    }
}
