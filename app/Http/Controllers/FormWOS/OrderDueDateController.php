<?php

namespace App\Http\Controllers\FormWOS;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderDueDateController extends Controller
{
    private string $conn = 'pgsqlw';

    private array $salesToGroupMap = [
        1506 => 'D1',
        1507 => 'D2',
        1431 => 'D3',
        1433 => 'D5',
        1434 => 'D6',
        1435 => 'D7',
        1436 => 'D8',
        528615586 => 'D9',
    ];

    private array $groupLabels = [
        'D1' => 'D1 - คุณดิลก + คุณขวัญเรือน',
        'D2' => 'D2 - คุณปรียาพรรณ + คุณนิตยา',
        'D3' => 'D3 - คุณภคดี + คุณธนัชชา',
        'D5' => 'D5 - คุณธัชลิญา + คุณเชอร์ลิญา',
        'D6' => 'D6 - คุณสุรศักดิ์ + คุณคณัญญ์นิชา',
        'D7' => 'D7 - คุณศิรินภา + คุณมนพัทธ์',
        'D8' => 'D8 - คุณสาธิต + คุณสุธาสินี',
        'D9' => 'D9 - คุณวรเดชา + คุณลัดดาวัลย์',
    ];

    private array $groupOrder = ['D1', 'D2', 'D3', 'D5', 'D6', 'D7', 'D9', 'TOTAL', 'D8'];

    private array $preferredTypeOrder = [
        'CUT WIRE (CG)',
        'MM BAR (CG)',
        'MM BAR',
        'Profile bar',
        'Profile wire',
        'MIG',
        'WIRE',
        'CUT WIRE',
        'TIG',
        'STD BAR',
        'Steel bar',
        'Steel wire',
        'สินค้าจ้างผลิต',
        'FG GRATING',
    ];

    private array $typeTargets = [
        'CUT WIRE' => 38000,
        'CUT WIRE (CG)' => 22650,
        'MIG' => 88000,
        'MM BAR' => 110000,
        'MM BAR (CG)' => 39350,
        'Profile bar' => 3200,
        'Profile wire' => 59800,
        'STD BAR' => 60000,
        'Steel bar' => 330000,
        'Steel wire' => 10000,
        'TIG' => 17000,
        'WIRE' => 117000,
        'สินค้าจ้างผลิต' => 5000,
        'FG GRATING' => 1700000,
    ];

    private function weightedQtyExpr(string $itemAlias): string
    {
        return "{$itemAlias}.qty * CASE WHEN p.ref_unit = '03' THEN p.ref_unit_qty ELSE 1 END";
    }

    public function index(Request $request)
    {
        [$year, $month] = $this->normalizedPeriod($request);

        [$monthRows, $typeColumns] = $this->buildMonthReport($year, $month);
        $yearRows = $this->buildYearReport($year, $typeColumns);
        return view('formwos.order_due_date.index', [
            'filters' => [
                'year' => $year,
                'month' => $month,
            ],
            'monthRows' => $monthRows,
            'yearRows' => $yearRows,
            'typeColumns' => $typeColumns,
            'targets' => $this->typeTargets,
        ]);
    }

    public function dashboard(Request $request)
    {
        [$year, $month] = $this->normalizedPeriod($request);
        [$monthRows, $typeColumns] = $this->buildMonthReport($year, $month);
        [$previousMonthRows] = $this->buildMonthReport($year - 1, $month);
        $yearRows = $this->buildYearReport($year, $typeColumns);
        $previousYearRows = $this->buildYearReport($year - 1, $typeColumns);

        return view('formwos.order_due_date.dashboard', [
            'filters' => compact('year', 'month'),
            'dashboard' => $this->buildDashboardData($year, $month, $monthRows, $previousMonthRows, $yearRows, $previousYearRows, $typeColumns),
        ]);
    }

    public function dashboardPdf(Request $request)
    {
        [$year, $month] = $this->normalizedPeriod($request);
        [$monthRows, $typeColumns] = $this->buildMonthReport($year, $month);
        [$previousMonthRows] = $this->buildMonthReport($year - 1, $month);
        $yearRows = $this->buildYearReport($year, $typeColumns);
        $previousYearRows = $this->buildYearReport($year - 1, $typeColumns);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.wos-order-due-date-dashboard', [
            'filters' => compact('year', 'month'),
            'dashboard' => $this->buildDashboardData($year, $month, $monthRows, $previousMonthRows, $yearRows, $previousYearRows, $typeColumns),
            'generatedAt' => Carbon::now('Asia/Bangkok'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('WOS-Order-Due-Date-Dashboard-' . sprintf('%04d-%02d', $year, $month) . '.pdf');
    }

    public function exportExcel(Request $request): StreamedResponse
    {
        [$year, $month] = $this->normalizedPeriod($request);
        [$monthRows, $typeColumns] = $this->buildMonthReport($year, $month);
        [$previousMonthRows] = $this->buildMonthReport($year - 1, $month);
        $yearRows = $this->buildYearReport($year, $typeColumns);
        $previousYearRows = $this->buildYearReport($year - 1, $typeColumns);
        $dashboard = $this->buildDashboardData($year, $month, $monthRows, $previousMonthRows, $yearRows, $previousYearRows, $typeColumns);

        $spreadsheet = new Spreadsheet();
        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Dashboard Summary');
        $summary->fromArray([
            ['Metric', 'Value'],
            ['Selected Month', $dashboard['labels']['selected_month']],
            ['Current Total', $dashboard['current_total']],
            ['Previous Year Same Month', $dashboard['previous_total']],
            ['YoY %', $dashboard['change_percent']],
            ['Target', $dashboard['target_total']],
            ['Target Achievement %', $dashboard['target_achievement']],
            ['YTD Total', $dashboard['ytd_total']],
            ['Previous YTD Total', $dashboard['previous_ytd_total']],
            ['YTD YoY %', $dashboard['ytd_change_percent']],
        ], null, 'A1');

        $divisionSheet = $spreadsheet->createSheet();
        $divisionSheet->setTitle('Division Compare');
        $divisionSheet->fromArray([['Division', 'Current', 'Previous Year', 'YoY %']], null, 'A1');
        $rowNo = 2;
        foreach ($dashboard['division_compare'] as $row) {
            $divisionSheet->fromArray([[
                $row['label'],
                $row['current'],
                $row['previous'],
                $row['change_percent'],
            ]], null, 'A' . $rowNo);
            $rowNo++;
        }

        $typeSheet = $spreadsheet->createSheet();
        $typeSheet->setTitle('Product Type Compare');
        $typeSheet->fromArray([['Product Type', 'Order', 'Target', 'Achievement %']], null, 'A1');
        $rowNo = 2;
        foreach ($dashboard['type_compare'] as $row) {
            $typeSheet->fromArray([[
                $row['label'],
                $row['current'],
                $row['target'],
                $row['achievement'],
            ]], null, 'A' . $rowNo);
            $rowNo++;
        }

        $trendSheet = $spreadsheet->createSheet();
        $trendSheet->setTitle('Monthly Trend');
        $trendSheet->fromArray([['Month', (string) $year, (string) ($year - 1), 'YoY %']], null, 'A1');
        $rowNo = 2;
        foreach ($dashboard['monthly_trend'] as $row) {
            $trendSheet->fromArray([[
                $row['label'],
                $row['current'],
                $row['previous'],
                $row['change_percent'],
            ]], null, 'A' . $rowNo);
            $rowNo++;
        }

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            foreach (range('A', 'D') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
        }

        $spreadsheet->setActiveSheetIndex(0);
        $fileName = 'OrderDueDateDashboard_' . sprintf('%04d-%02d', $year, $month) . '.xlsx';

        return new StreamedResponse(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment;filename="' . $fileName . '"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function detail(Request $request)
    {
        [$year, $month] = $this->normalizedPeriod($request);
        $groupCode = $this->sanitizeGroupCode($request->query('group_code'));
        $typeName = trim((string) $request->query('type_name', ''));
        $detail = $this->buildDetailData($year, $month, $groupCode, $typeName);

        return view('formwos.order_due_date.detail', [
            'filters' => compact('year', 'month', 'groupCode', 'typeName'),
            'detail' => $detail,
        ]);
    }

    public function detailPdf(Request $request)
    {
        [$year, $month] = $this->normalizedPeriod($request);
        $groupCode = $this->sanitizeGroupCode($request->query('group_code'));
        $typeName = trim((string) $request->query('type_name', ''));
        $detail = $this->buildDetailData($year, $month, $groupCode, $typeName);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.wos-order-due-date-detail', [
            'filters' => compact('year', 'month', 'groupCode', 'typeName'),
            'detail' => $detail,
            'generatedAt' => Carbon::now('Asia/Bangkok'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('WOS-Order-Due-Date-Detail-' . sprintf('%04d-%02d', $year, $month) . '.pdf');
    }

    private function buildMonthReport(int $year, int $month): array
    {
        $from = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        $rawRows = $this->getDueSummaryRaw($from, $to);

        return $this->buildPivotRows($rawRows);
    }

    private function buildYearReport(int $year, array $typeColumns): array
    {
        $from = Carbon::create($year, 1, 1)->startOfYear()->toDateString();
        $to = Carbon::create($year, 12, 31)->endOfYear()->toDateString();
        $rawRows = $this->getDueSummaryByMonthRaw($from, $to);
        $rows = [];

        for ($month = 1; $month <= 12; $month++) {
            $rows[$month] = [
                'month' => $month,
                'month_label' => Carbon::create($year, $month, 1)->format('F'),
                'cells' => array_fill_keys($typeColumns, 0.0),
                'grand_total' => 0.0,
                'fg_grating' => 0.0,
            ];
        }

        foreach ($rawRows as $row) {
            $month = (int) ($row->month_number ?? 0);
            if (!isset($rows[$month])) {
                continue;
            }

            $salesId = (int) ($row->sales_id ?? 0);
            if (($this->salesToGroupMap[$salesId] ?? null) === 'D8') {
                continue;
            }

            $type = trim((string) ($row->type_name ?? '')) ?: 'UNKNOWN';
            $value = (float) ($row->total_qty ?? 0);

            if ($type === 'FG GRATING') {
                $rows[$month]['fg_grating'] += $value;
                continue;
            }

            if (!array_key_exists($type, $rows[$month]['cells'])) {
                continue;
            }

            $rows[$month]['cells'][$type] += $value;
            $rows[$month]['grand_total'] += $value;
        }

        foreach ($rows as &$row) {
            $row['achievement'] = $this->achievementPercent(
                $row['grand_total'],
                $this->targetGrandTotal()
            );
        }
        unset($row);

        return array_values($rows);
    }

    private function buildPivotRows(array $rawRows): array
    {
        $typeColumns = $this->sortTypeColumns(array_unique(array_merge(
            array_keys($this->typeTargets),
            array_map(fn($row) => trim((string) ($row->type_name ?? '')) ?: 'UNKNOWN', $rawRows)
        )));

        $rows = [];
        foreach ($this->groupLabels as $groupCode => $groupName) {
            $rows[$groupCode] = [
                'group_code' => $groupCode,
                'group_name' => $groupName,
                'cells' => array_fill_keys($typeColumns, 0.0),
                'grand_total' => 0.0,
                'fg_grating' => 0.0,
            ];
        }

        foreach ($rawRows as $row) {
            $salesId = (int) ($row->sales_id ?? 0);
            $groupCode = $this->salesToGroupMap[$salesId] ?? null;
            if (!$groupCode || !isset($rows[$groupCode])) {
                continue;
            }

            $type = trim((string) ($row->type_name ?? '')) ?: 'UNKNOWN';
            $value = (float) ($row->total_qty ?? 0);

            if ($type === 'FG GRATING') {
                $rows[$groupCode]['fg_grating'] += $value;
                continue;
            }

            if (!array_key_exists($type, $rows[$groupCode]['cells'])) {
                $rows[$groupCode]['cells'][$type] = 0.0;
            }

            $rows[$groupCode]['cells'][$type] += $value;
            $rows[$groupCode]['grand_total'] += $value;
        }

        $totalRow = [
            'group_code' => 'TOTAL',
            'group_name' => 'Order',
            'cells' => array_fill_keys($typeColumns, 0.0),
            'grand_total' => 0.0,
            'fg_grating' => 0.0,
        ];

        foreach ($rows as $groupCode => $row) {
            if ($groupCode === 'D8') {
                continue;
            }

            foreach ($typeColumns as $type) {
                $totalRow['cells'][$type] += (float) ($row['cells'][$type] ?? 0);
            }

            $totalRow['grand_total'] += (float) ($row['grand_total'] ?? 0);
            $totalRow['fg_grating'] += (float) ($row['fg_grating'] ?? 0);
        }

        $targetRow = [
            'group_code' => 'TARGET',
            'group_name' => 'Total Target',
            'cells' => array_fill_keys($typeColumns, 0.0),
            'grand_total' => $this->targetGrandTotal(),
            'fg_grating' => (float) ($this->typeTargets['FG GRATING'] ?? 0),
        ];

        foreach ($typeColumns as $type) {
            $targetRow['cells'][$type] = (float) ($this->typeTargets[$type] ?? 0);
        }

        $achievementRow = [
            'group_code' => 'ACH',
            'group_name' => '% Achievement',
            'cells' => [],
            'grand_total' => $this->achievementPercent($totalRow['grand_total'], $targetRow['grand_total']),
            'fg_grating' => $this->achievementPercent($totalRow['fg_grating'], $targetRow['fg_grating']),
        ];

        foreach ($typeColumns as $type) {
            $achievementRow['cells'][$type] = $this->achievementPercent(
                (float) ($totalRow['cells'][$type] ?? 0),
                (float) ($targetRow['cells'][$type] ?? 0)
            );
        }

        $list = array_values($rows);
        $list[] = $totalRow;

        usort($list, function ($a, $b) {
            $oa = array_search($a['group_code'], $this->groupOrder, true);
            $ob = array_search($b['group_code'], $this->groupOrder, true);

            return (($oa === false) ? 999 : $oa) <=> (($ob === false) ? 999 : $ob);
        });

        $list[] = $targetRow;
        $list[] = $achievementRow;

        return [$list, $typeColumns];
    }

    private function getDueSummaryRaw(string $from, string $to): array
    {
        $qtyExpr = $this->weightedQtyExpr('oi');
        $custPoExclusionSql = $this->customerPoExclusionSql();
        $sql = "
            WITH due_items AS (
                SELECT
                    oe.requester_id AS sales_id,
                    COALESCE(NULLIF(TRIM(pt.description), ''), 'UNKNOWN') AS type_name,
                    $qtyExpr AS qty,
                    oi.sellprice * oi.qty AS bath
                FROM orderitems oi
                JOIN oe ON oi.trans_id = oe.id
                JOIN customer cus ON oe.customer_id = cus.id
                JOIN parts p ON oi.parts_id = p.id
                JOIN partstype pt ON p.partstype_id = pt.id
                WHERE oe.ordnumber IS NOT NULL
                  AND oe.ordnumber LIKE 'SO%'
                  AND oe.requester_id IN (1506,1507,1431,1433,1434,1435,1436,528615586)
                  AND oi.reqdate >= ?
                  AND oi.reqdate <= ?
                  AND oi.unit <> ' '
                  AND p.partnumber LIKE 'F%'
                  $custPoExclusionSql
                  AND pt.id <> 56
            )
            SELECT
                sales_id,
                type_name,
                ROUND(SUM(CASE WHEN sales_id = 1436 THEN bath ELSE qty END), 2) AS total_qty
            FROM due_items
            GROUP BY sales_id, type_name
            ORDER BY sales_id, type_name
        ";

        return DB::connection($this->conn)->select($sql, [$from, $to]);
    }

    private function getDueSummaryByMonthRaw(string $from, string $to): array
    {
        $qtyExpr = $this->weightedQtyExpr('oi');
        $custPoExclusionSql = $this->customerPoExclusionSql();
        $sql = "
            WITH due_items AS (
                SELECT
                    EXTRACT(MONTH FROM oi.reqdate) AS month_number,
                    COALESCE(NULLIF(TRIM(pt.description), ''), 'UNKNOWN') AS type_name,
                    oe.requester_id AS sales_id,
                    $qtyExpr AS qty,
                    oi.sellprice * oi.qty AS bath
                FROM orderitems oi
                JOIN oe ON oi.trans_id = oe.id
                JOIN customer cus ON oe.customer_id = cus.id
                JOIN parts p ON oi.parts_id = p.id
                JOIN partstype pt ON p.partstype_id = pt.id
                WHERE oe.ordnumber IS NOT NULL
                  AND oe.ordnumber LIKE 'SO%'
                  AND oe.requester_id IN (1506,1507,1431,1433,1434,1435,1436,528615586)
                  AND oi.reqdate >= ?
                  AND oi.reqdate <= ?
                  AND oi.unit <> ' '
                  AND p.partnumber LIKE 'F%'
                  $custPoExclusionSql
                  AND pt.id <> 56
            )
            SELECT
                month_number,
                sales_id,
                type_name,
                ROUND(SUM(CASE WHEN sales_id = 1436 THEN bath ELSE qty END), 2) AS total_qty
            FROM due_items
            GROUP BY month_number, sales_id, type_name
            ORDER BY month_number, sales_id, type_name
        ";

        return DB::connection($this->conn)->select($sql, [$from, $to]);
    }

    private function buildChartPayload(array $rows, array $typeColumns): array
    {
        $orderRow = collect($rows)->firstWhere('group_code', 'TOTAL') ?? [];
        $targetRow = collect($rows)->firstWhere('group_code', 'TARGET') ?? [];

        $labels = array_values(array_filter($typeColumns, fn($type) => $type !== 'FG GRATING'));

        return [
            'labels' => $labels,
            'targets' => array_map(fn($type) => (float) ($targetRow['cells'][$type] ?? 0), $labels),
            'orders' => array_map(fn($type) => (float) ($orderRow['cells'][$type] ?? 0), $labels),
        ];
    }

    private function buildDashboardData(
        int $year,
        int $month,
        array $monthRows,
        array $previousMonthRows,
        array $yearRows,
        array $previousYearRows,
        array $typeColumns
    ): array {
        $currentOrder = collect($monthRows)->firstWhere('group_code', 'TOTAL') ?? [];
        $previousOrder = collect($previousMonthRows)->firstWhere('group_code', 'TOTAL') ?? [];
        $targetRow = collect($monthRows)->firstWhere('group_code', 'TARGET') ?? [];
        $currentTotal = (float) ($currentOrder['grand_total'] ?? 0);
        $previousTotal = (float) ($previousOrder['grand_total'] ?? 0);
        $targetTotal = (float) ($targetRow['grand_total'] ?? 0);
        $ytdTotal = (float) collect($yearRows)->where('month', '<=', $month)->sum('grand_total');
        $previousYtdTotal = (float) collect($previousYearRows)->where('month', '<=', $month)->sum('grand_total');

        $divisionCompare = [];
        foreach ($this->groupLabels as $groupCode => $label) {
            $current = collect($monthRows)->firstWhere('group_code', $groupCode) ?? [];
            $previous = collect($previousMonthRows)->firstWhere('group_code', $groupCode) ?? [];
            $currentValue = (float) ($current['grand_total'] ?? 0);
            $previousValue = (float) ($previous['grand_total'] ?? 0);
            $divisionCompare[] = [
                'code' => $groupCode,
                'label' => $label,
                'current' => $currentValue,
                'previous' => $previousValue,
                'change_percent' => $this->percentChange($currentValue, $previousValue),
                'detail_url' => route('wos.order_due_date.detail', [
                    'year' => $year,
                    'month' => $month,
                    'group_code' => $groupCode,
                ]),
            ];
        }

        $typeCompare = [];
        foreach ($typeColumns as $type) {
            if ($type === 'FG GRATING') {
                continue;
            }

            $current = (float) ($currentOrder['cells'][$type] ?? 0);
            $target = (float) ($targetRow['cells'][$type] ?? 0);
            $typeCompare[] = [
                'label' => $type,
                'current' => $current,
                'target' => $target,
                'achievement' => $this->achievementPercent($current, $target),
                'detail_url' => route('wos.order_due_date.detail', [
                    'year' => $year,
                    'month' => $month,
                    'type_name' => $type,
                ]),
            ];
        }

        $monthlyTrend = [];
        foreach ($yearRows as $index => $row) {
            $previous = $previousYearRows[$index] ?? [];
            $currentValue = (float) ($row['grand_total'] ?? 0);
            $previousValue = (float) ($previous['grand_total'] ?? 0);
            $monthlyTrend[] = [
                'month' => $row['month'],
                'label' => $row['month_label'],
                'current' => $currentValue,
                'previous' => $previousValue,
                'change_percent' => $this->percentChange($currentValue, $previousValue),
            ];
        }

        usort($typeCompare, fn($a, $b) => ($b['current'] ?? 0) <=> ($a['current'] ?? 0));

        return [
            'labels' => [
                'selected_month' => Carbon::create($year, $month, 1)->format('F Y'),
                'previous_month' => Carbon::create($year - 1, $month, 1)->format('F Y'),
            ],
            'current_total' => $currentTotal,
            'previous_total' => $previousTotal,
            'change_percent' => $this->percentChange($currentTotal, $previousTotal),
            'target_total' => $targetTotal,
            'target_achievement' => $this->achievementPercent($currentTotal, $targetTotal),
            'ytd_total' => $ytdTotal,
            'previous_ytd_total' => $previousYtdTotal,
            'ytd_change_percent' => $this->percentChange($ytdTotal, $previousYtdTotal),
            'fg_grating_total' => (float) ($currentOrder['fg_grating'] ?? 0),
            'division_compare' => $divisionCompare,
            'type_compare' => $typeCompare,
            'monthly_trend' => $monthlyTrend,
            'chart' => $this->buildChartPayload($monthRows, $typeColumns),
        ];
    }

    private function normalizedPeriod(Request $request): array
    {
        $year = (int) $request->query('year', Carbon::now('Asia/Bangkok')->year);
        $month = (int) $request->query('month', Carbon::now('Asia/Bangkok')->month);

        if ($year < 2000 || $year > 2100) {
            $year = Carbon::now('Asia/Bangkok')->year;
        }

        if ($month < 1 || $month > 12) {
            $month = Carbon::now('Asia/Bangkok')->month;
        }

        return [$year, $month];
    }

    private function sanitizeGroupCode(mixed $value): ?string
    {
        $code = strtoupper(trim((string) $value));

        return $code !== '' && array_key_exists($code, $this->groupLabels) ? $code : null;
    }

    private function buildDetailData(int $year, int $month, ?string $groupCode, string $typeName): array
    {
        $from = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        $items = $this->getDueDetailRows($from, $to, $groupCode, $typeName);
        $totalMetric = collect($items)->sum(fn($row) => (float) ($row->metric_value ?? 0));
        $totalQty = collect($items)->sum(fn($row) => (float) ($row->qty ?? 0));
        $totalBath = collect($items)->sum(fn($row) => (float) ($row->bath ?? 0));

        return [
            'title' => $groupCode ? ($this->groupLabels[$groupCode] ?? $groupCode) : 'All Divisions',
            'group_code' => $groupCode,
            'type_name' => $typeName !== '' ? $typeName : null,
            'period_label' => Carbon::create($year, $month, 1)->format('F Y'),
            'items' => $items,
            'total_metric' => $totalMetric,
            'total_qty' => $totalQty,
            'total_bath' => $totalBath,
        ];
    }

    private function getDueDetailRows(string $from, string $to, ?string $groupCode, string $typeName): array
    {
        $salesIds = $groupCode ? $this->salesIdsForGroup($groupCode) : array_keys($this->salesToGroupMap);
        $placeholders = implode(',', array_fill(0, count($salesIds), '?'));
        $typeWhere = $typeName !== '' ? 'AND pt.description = ?' : '';
        $qtyExpr = $this->weightedQtyExpr('oi');
        $custPoExclusionSql = $this->customerPoExclusionSql();

        $sql = "
            SELECT
                oe.requester_id AS sales_id,
                oe.ordnumber,
                oi.transdate,
                oi.reqdate,
                oe.custponumber,
                cus.customernumber,
                cus.name AS customer_name,
                p.partnumber,
                oi.description,
                pt.description AS partstype,
                oi.unit AS unit_code,
                oi.sellprice,
                $qtyExpr AS qty,
                oi.sellprice * oi.qty AS bath,
                CASE
                    WHEN oe.requester_id = 1436 THEN oi.sellprice * oi.qty
                    ELSE $qtyExpr
                END AS metric_value
            FROM orderitems oi
            JOIN oe ON oi.trans_id = oe.id
            JOIN customer cus ON oe.customer_id = cus.id
            JOIN parts p ON oi.parts_id = p.id
            JOIN partstype pt ON p.partstype_id = pt.id
            WHERE oe.ordnumber IS NOT NULL
              AND oe.ordnumber LIKE 'SO%'
              AND oe.requester_id IN ($placeholders)
              AND oi.reqdate >= ?
              AND oi.reqdate <= ?
              AND oi.unit <> ' '
              AND p.partnumber LIKE 'F%'
              $custPoExclusionSql
              AND pt.id <> 56
              $typeWhere
            ORDER BY oi.reqdate, oe.ordnumber, p.partnumber
        ";

        $bindings = array_merge($salesIds, [$from, $to]);
        if ($typeName !== '') {
            $bindings[] = $typeName;
        }

        $rows = DB::connection($this->conn)->select($sql, $bindings);
        foreach ($rows as $row) {
            $code = $this->salesToGroupMap[(int) ($row->sales_id ?? 0)] ?? '-';
            $row->group_code = $code;
            $row->group_name = $this->groupLabels[$code] ?? $code;
            $row->metric_unit = $code === 'D8' ? 'Baht' : 'Qty';
        }

        return $rows;
    }

    private function customerPoExclusionSql(): string
    {
        return "AND UPPER(TRIM(oe.custponumber)) NOT IN ('SAMPLE', 'FORECAST', 'STOCK')
                  AND UPPER(TRIM(oe.custponumber)) NOT LIKE 'STOCK%'";
    }

    private function salesIdsForGroup(string $groupCode): array
    {
        $ids = [];
        foreach ($this->salesToGroupMap as $salesId => $code) {
            if ($code === $groupCode) {
                $ids[] = $salesId;
            }
        }

        return $ids ?: array_keys($this->salesToGroupMap);
    }

    private function sortTypeColumns(array $types): array
    {
        $types = array_values(array_filter(
            array_map(fn($type) => trim((string) $type), $types),
            fn($type) => $type !== '' && $type !== 'FG GRATING'
        ));
        $types = array_values(array_unique($types));

        usort($types, function ($a, $b) {
            $oa = array_search($a, $this->preferredTypeOrder, true);
            $ob = array_search($b, $this->preferredTypeOrder, true);
            $oa = $oa === false ? 999 : $oa;
            $ob = $ob === false ? 999 : $ob;

            return $oa === $ob ? strcmp($a, $b) : $oa <=> $ob;
        });

        return $types;
    }

    private function targetGrandTotal(): float
    {
        return (float) collect($this->typeTargets)
            ->except(['FG GRATING'])
            ->sum();
    }

    private function achievementPercent(float $actual, float $target): ?float
    {
        if ($target <= 0) {
            return null;
        }

        return round(($actual / $target) * 100, 1);
    }

    private function percentChange(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return $current == 0.0 ? 0.0 : null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }
}
