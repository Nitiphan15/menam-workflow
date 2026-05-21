<?php

namespace App\Exports\FormOTD;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class InquiryShipDateSheet implements FromCollection, WithTitle, WithHeadings
{
    public function __construct(private string $shipDate, private array $filters = [])
    {
    }

    public function title(): string
    {
        return $this->shipDate === 'NO_SHIP_DATE' ? 'NO_SHIP' : $this->shipDate;
    }

    public function headings(): array
    {
        return [
            'Due date',
            'วันที่แท่งส่ง',
            'ช่วงเวลารับส่ง',
            'ลูกค้า',
            'Part No',
            'Part Desc',
            'MFG No',
            'KG',
            'Stock FG',
            'ขายระบุเส้น/ชิ้น',
            'หน่วย',
            'KG/เส้น',
            'KG จากระบุเส้น',
            'สถานที่ส่ง',
            'SO',
            'เอกสารแนบ',
            'เพิ่มเติม',
            'เบอร์โทร',
            'Revision',
            'สร้างเมื่อ',
            'สร้างโดย',
        ];
    }

    public function collection()
    {
        $displayRevision = trim((string) ($this->filters['display_revision_number'] ?? ''));

        $q = $this->baseQuery()
            ->selectRaw("
                d.due_date,
                CONVERT(varchar(10), d.ship_posted_at, 23) as ship_posted_at,
                CONVERT(varchar(5), d.window_at, 108) as window_at,
                COALESCE(NULLIF(LTRIM(RTRIM(c.name)), ''), CONCAT('#', d.customer_id)) as customer_name,
                d.part_number,
                d.part_desc,
                d.mfg_no,
                d.qty,
                d.stock_qty,
                CASE
                    WHEN ISNULL(d.sell_by_line, 0) = 1 AND ISNULL(d.line_qty, 0) > 0
                    THEN d.line_qty
                    ELSE NULL
                END as line_qty,
                CASE
                    WHEN ISNULL(d.sell_by_line, 0) = 1
                        AND ISNULL(d.line_qty, 0) > 0
                        AND ISNULL(d.qty, 0) = 0
                    THEN N'ชิ้น'
                    WHEN ISNULL(d.sell_by_line, 0) = 1
                        AND ISNULL(d.line_qty, 0) > 0
                    THEN N'เส้น'
                    ELSE NULL
                END as line_qty_unit,
                CASE
                    WHEN ISNULL(d.sell_by_line, 0) = 1
                        AND ISNULL(d.line_qty, 0) > 0
                        AND ISNULL(d.qty, 0) > 0
                        AND UPPER(LTRIM(RTRIM(ISNULL(p.ref_unit, '')))) = '03'
                        AND UPPER(LTRIM(RTRIM(ISNULL(d.part_number, '')))) LIKE '%E'
                        AND ISNULL(p.ref_unit_qty, 0) > 0
                    THEN p.ref_unit_qty
                    ELSE NULL
                END as kg_per_line,
                CASE
                    WHEN ISNULL(d.sell_by_line, 0) = 1
                        AND ISNULL(d.line_qty, 0) > 0
                        AND ISNULL(d.qty, 0) > 0
                        AND UPPER(LTRIM(RTRIM(ISNULL(p.ref_unit, '')))) = '03'
                        AND UPPER(LTRIM(RTRIM(ISNULL(d.part_number, '')))) LIKE '%E'
                        AND ISNULL(p.ref_unit_qty, 0) > 0
                    THEN ROUND(d.line_qty * p.ref_unit_qty, 3)
                    ELSE NULL
                END as line_calc_kg,
                d.address,
                d.so_number,
                d.attach_docs,
                d.attach_docs_other,
                CONCAT(
                    NULLIF(LTRIM(RTRIM(ISNULL(d.remark,''))), ''),
                    CASE WHEN NULLIF(LTRIM(RTRIM(ISNULL(d.edit_remark,''))), '') IS NULL THEN '' ELSE CONCAT(' | ', d.edit_remark) END,
                    CASE WHEN NULLIF(LTRIM(RTRIM(ISNULL(d.due_date_remark,''))), '') IS NULL THEN '' ELSE CONCAT(' | ', d.due_date_remark) END
                ) as more_text,
                d.tel,
                d.revision_number,
                d.created_at,
                d.created_by
            ");

        if ($this->shipDate === 'NO_SHIP_DATE') {
            $q->whereNull('d.ship_posted_at');
        } else {
            $q->whereRaw("CONVERT(date, d.ship_posted_at) = ?", [$this->shipDate]);
        }

        $rows = $q->orderBy('d.customer_id')->get();

        $parts = $rows
            ->pluck('part_number')
            ->map(fn($x) => trim((string) $x))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $stockMap = [];
        if (!empty($parts)) {
            $stockRows = DB::connection('pgsqlw')
                ->table('parts')
                ->select(['partnumber', 'totalonhand', 'onhand'])
                ->whereIn('partnumber', $parts)
                ->get();

            foreach ($stockRows as $st) {
                $pn = trim((string) $st->partnumber);
                $stockMap[$pn] = [
                    'totalonhand' => (float) ($st->totalonhand ?? 0),
                    'onhand'      => (float) ($st->onhand ?? 0),
                ];
            }
        }

        foreach ($rows as $r) {
            $partNo = trim((string) ($r->part_number ?? ''));
            $stock = $stockMap[$partNo] ?? null;
            $r->stock_qty = $stock['onhand'] ?? ($stock['totalonhand'] ?? 0);

            if ($displayRevision !== '' && is_numeric($displayRevision)) {
                $r->revision_number = (int) $displayRevision;
            }
        }

        return $rows;
    }

    private function baseQuery()
    {
        $q = DB::connection('sqlsrv_menam')
            ->table('delivery_plan_data as d')
            ->leftJoin('customer as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('sales_master as s', 's.sales_id', '=', 'd.sales_id')
            ->leftJoin('employees as e', 'e.id', '=', 'd.sales_id')
            ->leftJoin('parts as p', function ($join) {
                $join->on(
                    DB::raw("p.partnumber COLLATE SQL_Latin1_General_CP1_CI_AS"),
                    '=',
                    DB::raw("d.part_number COLLATE SQL_Latin1_General_CP1_CI_AS")
                );
            });

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
