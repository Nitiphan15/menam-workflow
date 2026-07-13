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

    public function __construct(private string $shipDate, private array $filters = []) {}

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
                COALESCE(
                    NULLIF(LTRIM(RTRIM(d.customer_name COLLATE DATABASE_DEFAULT)), ''),
                    NULLIF(LTRIM(RTRIM(c.name COLLATE DATABASE_DEFAULT)), ''),
                    CONCAT('#', d.customer_id)
                ) as customer_name,
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

        $orderBy  = trim((string) ($this->filters['order_by'] ?? ''));
        $orderDir = strtolower(trim((string) ($this->filters['order_dir'] ?? '')));
        $dir = in_array($orderDir, ['asc', 'desc'], true) ? $orderDir : 'asc';

        $allowedOrder = [
            'so'             => 'd.so_number',
            'customer'       => 'c.name',
            'shipto'         => 'd.address',
            'qty'            => 'd.qty',
            'window_at'      => 'd.window_at',
            'ship_posted_at' => 'd.ship_posted_at',
            'revision'       => 'd.revision_number',
        ];

        if (isset($allowedOrder[$orderBy])) {
            $q->orderBy('d.sales_id')
                ->orderBy($allowedOrder[$orderBy], $dir)
                ->orderBy('d.delivery_type')
                ->orderBy('d.part_number');
        } else {
            $q->orderBy('d.sales_id')
                ->orderBy('c.name')
                ->orderBy('d.delivery_type')
                ->orderBy('d.part_number');
        }

        $rows = $q->get();

        $stockMap = $this->trackingStockFgMapForRows($rows);

        foreach ($rows as $r) {
            $r->stock_qty = $this->trackingStockFgForRow($r, $stockMap);

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

    private function trackingStockFgMapForRows($rows): array
    {
        $partsByConnection = [
            'pgsqlw' => collect(),
            'pgsqlp' => collect(),
        ];

        foreach (collect($rows) as $row) {
            $partNumber = trim((string) ($row->part_number ?? ''));
            if ($partNumber === '') {
                continue;
            }

            foreach ($this->stockConnectionsForMfg($row->mfg_no ?? '') as $connection) {
                $partsByConnection[$connection]->push($partNumber);
            }
        }

        $stockMap = [
            'pgsqlw' => [],
            'pgsqlp' => [],
        ];

        foreach ($partsByConnection as $connection => $partNumbers) {
            $partNumbers = $partNumbers->filter()->unique()->values();
            if ($partNumbers->isEmpty()) {
                continue;
            }

            $stockRows = DB::connection($connection)
                ->table('parts as p')
                ->join('serializeunits as su', 'su.parts_id', '=', 'p.id')
                ->join('serializeunitsmvmt as sus', 'sus.su_id', '=', 'su.id')
                ->whereIn('p.partnumber', $partNumbers->all())
                ->groupBy('p.partnumber')
                ->selectRaw('p.partnumber, SUM(CASE WHEN su.onhand THEN sus.qty ELSE 0 END) AS balance_qty')
                ->get();

            foreach ($stockRows as $stockRow) {
                $partNumber = trim((string) ($stockRow->partnumber ?? ''));
                if ($partNumber !== '') {
                    $stockMap[$connection][$partNumber] = (float) ($stockRow->balance_qty ?? 0);
                }
            }
        }

        return $stockMap;
    }

    private function trackingStockFgForRow($row, array $stockMap): float
    {
        $partNumber = trim((string) ($row->part_number ?? ''));
        if ($partNumber === '') {
            return 0.0;
        }

        $stock = 0.0;
        foreach ($this->stockConnectionsForMfg($row->mfg_no ?? '') as $connection) {
            $stock += (float) ($stockMap[$connection][$partNumber] ?? 0);
        }

        return $stock;
    }

    private function stockConnectionsForMfg($mfgNo): array
    {
        foreach (explode(',', (string) $mfgNo) as $rawToken) {
            $token = strtoupper(trim((string) $rawToken, " \t\n\r\0\x0B'\""));
            if ($token !== '' && str_starts_with($token, '+')) {
                return ['pgsqlp'];
            }
        }

        return ['pgsqlw'];
    }

    private function withDivisionGroupRows($rows)
    {
        $labels = $this->divisionLabels();
        $groups = [];

        foreach ($rows as $row) {
            $mode = strtoupper(trim((string) ($row->delivery_type ?? '')));
            $key = match ($mode) {
                'ACID' => 'PLN',
                'SPECIAL' => 'EXPORT',
                default => $this->detectDivision((string) ($row->sales_name ?? '')),
            };
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
            'EXPORT' => 'Export - งานพิเศษ',
        ];
    }

    private function divisionOrder(): array
    {
        return ['D1', 'D2', 'D3', 'D5', 'D6', 'D7', 'D8', 'D9', 'PLN', 'EXPORT'];
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
                    ->orWhere('c.customernumber', 'like', "%{$customer}%")
                    ->orWhereRaw('d.customer_name COLLATE DATABASE_DEFAULT LIKE ?', ["%{$customer}%"]);
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

        // เคส domain ตาม delivery_type (sync กับ controller):
        //   PLN (วางแผน) = 'ACID', EXPORT (งานพิเศษ) = 'SPECIAL' — ไม่ match sales_name
        $matchesGroupKeywords = function (string $divKey) use ($groups): bool {
            $keywords = collect($this->divisionSearchMap()[$divKey] ?? [])
                ->map(fn($v) => mb_strtolower(trim((string) $v)))
                ->filter()
                ->all();

            return collect($groups)->contains(function ($group) use ($keywords) {
                foreach ($group as $keyword) {
                    if (in_array(mb_strtolower(trim((string) $keyword)), $keywords, true)) {
                        return true;
                    }
                }
                return false;
            });
        };

        if ($matchesGroupKeywords('PLN')) {
            $q->where('d.delivery_type', 'ACID');
            return;
        }

        if ($matchesGroupKeywords('EXPORT')) {
            $q->where('d.delivery_type', 'SPECIAL');
            return;
        }

        // เมื่อ filter เป็นชื่อ Division ปกติ — ตัด delivery_type domain อื่น (ACID/SPECIAL) ออก
        $q->where(function ($w) {
            $w->whereNotIn('d.delivery_type', ['ACID', 'SPECIAL'])
                ->orWhereNull('d.delivery_type');
        });

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

            // ข้าม token ที่เป็นตัวเชื่อมล้วนๆ เช่น "-", "+", "/" ที่มาจาก label
            // เช่น "D3 - ภควดี + ธนัชชา" — ไม่งั้นจะถูก AND บังคับให้ sales_name
            // ต้องมี "-" และ "+" อยู่ในชื่อด้วย ทำให้ไม่เจอข้อมูล
            if (!preg_match('/[\p{L}\p{N}]/u', $token)) {
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

    // sync กับ DeliveryPlanInquiryController::divisionSearchMap()
    // ต้องตรงกันเพราะ export ต้อง filter ผลลัพธ์ให้เหมือนหน้า inquiry ทุกประการ
    private function divisionSearchMap(): array
    {
        return [
            'D1'  => ['D1', 'ดิลก', 'ขวัญเรือน'],
            'D2'  => ['D2', 'ปรียาพรรณ', 'นิตยา'],
            'D3'  => ['D3', 'ภควดี', 'ธนัชชา'],
            'D5'  => ['D5', 'ธัธลิญา', 'เฌอร์ลิญา'],
            'D6'  => ['D6', 'สุรศักดิ์', 'คณัญญ์นิชา'],
            'D7'  => ['D7', 'ศิรินภา', 'มนพัทธ์'],
            'D8'  => ['D8', 'สาธิต', 'สุธาสินี'],
            'D9'  => ['D9', 'วรเดชา', 'ลัดดาวัลย์'],
            'PLN' => ['PLN', 'วางแผน', 'กันยกร'],
            'EXPORT' => ['EXPORT', 'Export', 'งานพิเศษ', 'ส่งซ่อม', 'ส่งคืน'],
        ];
    }
}
