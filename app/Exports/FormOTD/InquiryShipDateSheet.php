<?php

namespace App\Exports\FormOTD;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

class InquiryShipDateSheet implements FromCollection, WithTitle, WithHeadings, WithMapping, WithEvents
{
    private array $docMap = [];
    private array $userMap = [];
    private array $groupRows = [];

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

    public function map($row): array
    {
        if (!empty($row->__group_row)) {
            return [
                (string) $row->group_label,
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
            ];
        }

        $codes = $this->normalizeDocCodes((string) ($row->attach_docs ?? ''));
        $docNames = array_map(fn($c) => $this->docMap[$c] ?? $c, $codes);
        $attachDocsText = implode(', ', $docNames);

        $moreText = collect([
            $row->remark ?? null,
            $row->edit_remark ?? null,
        ])
            ->map(fn($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->implode(' | ');

        $createdBy = (int) ($row->created_by ?? 0);
        $createdByName = $this->userMap[$createdBy] ?? ($createdBy > 0 ? (string) $createdBy : '');

        return [
            $row->due_date,
            $row->ship_posted_at,
            $row->window_at,
            $row->customer_name,
            $row->part_number,
            $row->part_desc,
            $row->mfg_no,
            is_numeric($row->qty) ? (float) $row->qty : $row->qty,
            is_numeric($row->stock_qty) ? (float) $row->stock_qty : $row->stock_qty,
            $row->line_qty,
            $row->line_qty_unit,
            $row->kg_per_line,
            $row->line_calc_kg,
            $row->address,
            $row->so_number,
            $attachDocsText,
            $moreText,
            $row->tel,
            $row->revision_number,
            $row->created_at,
            $createdByName,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                foreach ($this->groupRows as $rowNumber) {
                    $event->sheet->mergeCells("A{$rowNumber}:U{$rowNumber}");
                    $event->sheet->getStyle("A{$rowNumber}:U{$rowNumber}")->applyFromArray([
                        'font' => [
                            'bold' => true,
                        ],
                        'fill' => [
                            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                            'startColor' => ['argb' => 'FFE5E7EB'],
                        ],
                    ]);
                }
            },
        ];
    }

    public function collection()
    {
        $displayRevision = trim((string) ($this->filters['display_revision_number'] ?? ''));

        $q = $this->baseQuery()
            ->selectRaw("
                d.due_date,
                d.delivery_type,
                CONVERT(varchar(10), d.ship_posted_at, 23) as ship_posted_at,
                CONVERT(varchar(5), d.window_at, 108) as window_at,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(s.sales_name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(e.name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(e.login COLLATE DATABASE_DEFAULT)), ''),
                    CONCAT('Sales#', CAST(d.sales_id AS nvarchar(20)))
                ) as sales_name,
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
                d.remark,
                d.edit_remark,
                d.tel,
                d.revision_number,
                CONVERT(varchar(16), d.created_at, 120) as created_at,
                d.created_by
            ");

        if ($this->shipDate === 'NO_SHIP_DATE') {
            $q->whereNull('d.ship_posted_at');
        } else {
            $q->whereRaw("CONVERT(date, d.ship_posted_at) = ?", [$this->shipDate]);
        }

        $rows = $q->orderBy('d.sales_id')
            ->orderBy('c.name')
            ->orderBy('d.delivery_type')
            ->orderBy('d.part_number')
            ->get();

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

        $this->docMap = $this->loadDocMap();
        $this->userMap = $this->loadUserMap(
            $rows->pluck('created_by')->map(fn($x) => (int) $x)->filter()->unique()->values()->all()
        );

        return $this->withDivisionGroupRows($rows);
    }

    private function withDivisionGroupRows($rows)
    {
        $labels = $this->divisionLabels();
        $groups = [];

        foreach ($rows as $row) {
            $mode = strtoupper(trim((string) ($row->delivery_type ?? '')));
            $key = $mode === 'ACID' ? 'PLN' : $this->detectDivision((string) ($row->sales_name ?? ''));
            $groups[$key] = $groups[$key] ?? [];
            $groups[$key][] = $row;
        }

        $order = $this->divisionOrder();
        uksort($groups, function ($a, $b) use ($order) {
            $ia = array_search($a, $order, true);
            $ib = array_search($b, $order, true);

            $ia = $ia === false ? 999 : $ia;
            $ib = $ib === false ? 999 : $ib;

            return $ia <=> $ib ?: strcmp((string) $a, (string) $b);
        });

        $this->groupRows = [];
        $output = collect();
        $excelRow = 2;

        foreach ($groups as $key => $items) {
            $label = $labels[$key] ?? (string) $key;

            $output->push((object) [
                '__group_row' => true,
                'group_label' => $label . ' (' . count($items) . ' รายการ)',
            ]);
            $this->groupRows[] = $excelRow;
            $excelRow++;

            foreach ($items as $item) {
                $output->push($item);
                $excelRow++;
            }
        }

        return $output;
    }

    private function divisionLabels(): array
    {
        return [
            'D1'  => 'D1 - ดิลก + ขวัญเรือน',
            'D2'  => 'D2 - ปรียาพรรณ + นิตยา',
            'D3'  => 'D3 - ภควดี + ธนัชชา',
            'D5'  => 'D5 - ธัธลิญา + เฌอร์ลิญา',
            'D6'  => 'D6 - สุรศักดิ์ + คณัญญ์นิชา',
            'D7'  => 'D7 - ศิรินภา + มนพัทธ์',
            'D8'  => 'D8 - สาธิต + สุธาสินี',
            'D9'  => 'D9 - วรเดชา + ลัดดาวัลย์',
            'PLN' => 'วางแผน - กันยกร',
        ];
    }

    private function divisionOrder(): array
    {
        return ['D1', 'D2', 'D3', 'D5', 'D6', 'D7', 'D8', 'D9', 'PLN'];
    }

    private function detectDivision(string $salesName): string
    {
        $s = mb_strtolower(trim($salesName));

        $map = [
            'D1' => ['d1', 'ดิลก', 'ขวัญเรือน'],
            'D2' => ['d2', 'ปรียาพรรณ', 'นิตยา'],
            'D3' => ['d3', 'ภควดี', 'ธนัชชา'],
            'D5' => ['d5', 'ธัธลิญา', 'เฌอร์ลิญา'],
            'D6' => ['d6', 'สุรศักดิ์', 'คณัญญ์นิชา'],
            'D7' => ['d7', 'ศิรินภา', 'มนพัทธ์'],
            'D8' => ['d8', 'สาธิต', 'สุธาสินี'],
            'D9' => ['d9', 'วรเดชา', 'ลัดดาวัลย์'],
            'PLN' => ['pln', 'plan', 'planner', 'กันยกร'],
        ];

        foreach ($map as $div => $needles) {
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($s, mb_strtolower($needle))) {
                    return $div;
                }
            }
        }

        return 'PLN';
    }

    private function loadDocMap(): array
    {
        $map = DB::connection('sqlsrv_menam')
            ->table('attach_docs_master')
            ->pluck('name', 'code')
            ->mapWithKeys(fn($name, $code) => [
                strtoupper(trim((string) $code)) => trim((string) $name),
            ])
            ->all();

        return array_merge([
            'DO' => 'Delivery sheet (ลูกค้า)',
        ], $map);
    }

    private function loadUserMap(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return DB::connection('sqlsrv_menam')
            ->table('users')
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->map(fn($n) => trim((string) $n))
            ->all();
    }

    private function normalizeDocCodes(?string $value): array
    {
        return array_values(array_filter(array_map(
            fn($x) => strtoupper(trim((string) $x)),
            explode(',', (string) $value)
        )));
    }

    private function baseQuery()
    {
        $q = DB::connection('sqlsrv_menam')
            ->table('delivery_plan_data_dev as d')
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
        } else {
            $q->where(function ($w) {
                $w->whereNull('d.status')
                    ->orWhere('d.status', '!=', 'VOID');
            });
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
