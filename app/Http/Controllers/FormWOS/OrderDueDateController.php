<?php

namespace App\Http\Controllers\FormWOS;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
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
        $selectedDivisions = $this->normalizedDivisionCodes($request->query('divisions', []));

        [$monthRows, $typeColumns] = $this->buildMonthReport($year, $month);
        $yearRows = $this->buildYearReport($year, $typeColumns);
        $deliveryComparison = $this->buildDeliveryComparison($year, $month, $selectedDivisions);
        return view('formwos.order_due_date.index', [
            'filters' => [
                'year' => $year,
                'month' => $month,
                'divisions' => $selectedDivisions,
            ],
            'monthRows' => $monthRows,
            'yearRows' => $yearRows,
            'typeColumns' => $typeColumns,
            'targets' => $this->typeTargets,
            'divisionOptions' => $this->groupLabels,
            'deliveryComparison' => $deliveryComparison,
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
        $selectedDivisions = $this->normalizedDivisionCodes($request->query('divisions', []));
        $comparison = $this->buildDeliveryComparison($year, $month, $selectedDivisions);

        $spreadsheet = new Spreadsheet();
        $summary = $spreadsheet->getActiveSheet();
        $this->writeComparisonSheet($summary, $comparison, $year, $month, $selectedDivisions);
        $this->writeOrderDetailsSheet($spreadsheet->createSheet(), $comparison['order_details']);
        $this->writeActualDetailsSheet($spreadsheet->createSheet(), $comparison['actual_details']);

        $spreadsheet->setActiveSheetIndex(0);
        $divisionLabel = count($selectedDivisions) === count($this->groupLabels)
            ? 'ALL'
            : implode('-', $selectedDivisions);
        $fileName = 'OrderDueDate_vs_Actual_' . sprintf('%04d-%02d', $year, $month) . '_' . $divisionLabel . '.xlsx';

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
        $mode = $request->query('mode') === 'actual' ? 'actual' : 'order';
        $duePeriod = $this->sanitizeDuePeriod($request->query('due_period'));
        $selectedDivisions = $groupCode
            ? [$groupCode]
            : $this->normalizedDivisionCodes($request->query('divisions', []));
        $detail = $mode === 'actual'
            ? $this->buildActualDetailData($year, $month, $selectedDivisions, $typeName, $duePeriod)
            : $this->buildDetailData($year, $month, $groupCode, $typeName);

        return view('formwos.order_due_date.detail', [
            'filters' => compact('year', 'month', 'groupCode', 'typeName', 'mode', 'duePeriod', 'selectedDivisions'),
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

    private function buildDeliveryComparison(int $year, int $month, array $divisionCodes): array
    {
        $from = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        $salesIds = $this->salesIdsForGroups($divisionCodes);
        $orderRows = $this->getComparisonOrderRows($from, $to, $salesIds);
        $actualRows = $this->getActualDeliveryRows($from, $to, $salesIds);

        return $this->summarizeDeliveryComparison($orderRows, $actualRows, $year, $month);
    }

    private function getComparisonOrderRows(string $from, string $to, array $salesIds): array
    {
        $placeholders = implode(',', array_fill(0, count($salesIds), '?'));
        $qtyExpr = $this->weightedQtyExpr('oi');
        $custPoExclusionSql = $this->customerPoExclusionSql();
        $sql = "
            SELECT
                oi.id AS orderitems_id,
                oe.requester_id AS sales_id,
                oe.ordnumber,
                oe.custponumber,
                oi.reqdate,
                cus.customernumber,
                cus.name AS customer_name,
                p.partnumber,
                oi.description,
                COALESCE(NULLIF(TRIM(pt.description), ''), 'UNKNOWN') AS product_type,
                oi.unit AS unit_code,
                ROUND(oi.qty::numeric, 2) AS source_order_qty,
                ROUND(($qtyExpr)::numeric, 2) AS order_qty,
                ROUND((oi.sellprice * oi.qty)::numeric, 2) AS order_amount,
                ROUND((CASE WHEN oe.requester_id = 1436 THEN oi.sellprice * oi.qty ELSE $qtyExpr END)::numeric, 2) AS metric_value
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
            ORDER BY oi.reqdate, oe.ordnumber, p.partnumber, oi.id
        ";

        $rows = DB::connection($this->conn)->select($sql, array_merge($salesIds, [$from, $to]));
        $this->decorateComparisonRows($rows);

        return $rows;
    }

    private function getActualDeliveryRows(
        string $from,
        string $to,
        array $salesIds,
        string $typeName = '',
        ?string $duePeriod = null
    ): array {
        $placeholders = implode(',', array_fill(0, count($salesIds), '?'));
        $typeWhere = $typeName !== '' ? 'AND pt.description = ?' : '';
        $dueWhere = '';
        if ($duePeriod === 'NO_DUE_DATE') {
            $dueWhere = 'AND oi.reqdate IS NULL';
        } elseif ($duePeriod === 'SAME_DUE_MONTH') {
            $dueWhere = "AND TO_CHAR(oi.reqdate, 'YYYY-MM') = ?";
        } elseif ($duePeriod === 'OTHER_DUE_MONTH') {
            $dueWhere = "AND (oi.reqdate IS NULL OR TO_CHAR(oi.reqdate, 'YYYY-MM') <> ?)";
        } elseif ($duePeriod !== null) {
            $dueWhere = "AND TO_CHAR(oi.reqdate, 'YYYY-MM') = ?";
        }
        $orderQtyExpr = $this->weightedQtyExpr('oi');
        $actualQtyExpr = $this->weightedQtyExpr('pi');
        $custPoExclusionSql = $this->customerPoExclusionSql();
        $sql = "
            SELECT
                predm.id AS predm_id,
                pi.id AS predmitems_id,
                pi.orderitems_id,
                oe.requester_id AS sales_id,
                oe.ordnumber,
                predm.dmnumber,
                predm.transdate AS actual_delivery_date,
                oi.reqdate AS original_due_date,
                oe.custponumber,
                cus.customernumber,
                cus.name AS customer_name,
                p.partnumber,
                COALESCE(NULLIF(TRIM(pi.description), ''), oi.description) AS description,
                COALESCE(NULLIF(TRIM(pt.description), ''), 'UNKNOWN') AS product_type,
                pi.unit AS unit_code,
                ROUND(oi.qty::numeric, 2) AS source_order_qty,
                ROUND(($orderQtyExpr)::numeric, 2) AS order_qty,
                ROUND((oi.sellprice * oi.qty)::numeric, 2) AS order_amount,
                ROUND(pi.qty::numeric, 2) AS source_actual_qty,
                ROUND(($actualQtyExpr)::numeric, 2) AS actual_qty,
                ROUND((pi.sellprice * pi.qty)::numeric, 2) AS actual_amount,
                ROUND((CASE WHEN oe.requester_id = 1436 THEN pi.sellprice * pi.qty ELSE $actualQtyExpr END)::numeric, 2) AS metric_value
            FROM predm
            JOIN predmitems pi ON pi.trans_id = predm.id
            JOIN orderitems oi ON oi.id = pi.orderitems_id
            JOIN oe ON oe.id = oi.trans_id
            JOIN customer cus ON cus.id = oe.customer_id
            JOIN parts p ON p.id = pi.parts_id
            JOIN partstype pt ON pt.id = p.partstype_id
            WHERE predm.ordnumber IS NOT NULL
              AND predm.ordnumber LIKE 'SO%'
              AND oe.requester_id IN ($placeholders)
              AND predm.transdate >= ?
              AND predm.transdate <= ?
              AND pi.unit <> ' '
              AND p.partnumber LIKE 'F%'
              $custPoExclusionSql
              AND pt.id <> 56
              $typeWhere
              $dueWhere
            ORDER BY predm.transdate, oe.ordnumber, p.partnumber, pi.id
        ";

        $bindings = array_merge($salesIds, [$from, $to]);
        if ($typeName !== '') {
            $bindings[] = $typeName;
        }
        if ($duePeriod !== null && $duePeriod !== 'NO_DUE_DATE') {
            $bindings[] = in_array($duePeriod, ['SAME_DUE_MONTH', 'OTHER_DUE_MONTH'], true)
                ? Carbon::parse($from)->format('Y-m')
                : $duePeriod;
        }

        $rows = DB::connection($this->conn)->select($sql, $bindings);
        $this->decorateComparisonRows($rows);

        return $rows;
    }

    private function decorateComparisonRows(array $rows): void
    {
        foreach ($rows as $row) {
            $code = $this->salesToGroupMap[(int) ($row->sales_id ?? 0)] ?? '-';
            $row->group_code = $code;
            $row->group_name = $this->groupLabels[$code] ?? $code;
            $row->metric_unit = $code === 'D8' ? 'Baht' : 'Qty';
        }
    }

    private function summarizeDeliveryComparison(array $orderRows, array $actualRows, int $year, int $month): array
    {
        $comparison = [];
        $actualByOrderLine = [];
        $dueOrigins = [];

        foreach ($orderRows as $row) {
            $key = ($row->group_code ?? '-') . '|' . ($row->product_type ?? 'UNKNOWN');
            $comparison[$key] ??= $this->emptyComparisonRow($row);
            $comparison[$key]['order_due'] += (float) ($row->metric_value ?? 0);
        }

        foreach ($actualRows as $row) {
            $key = ($row->group_code ?? '-') . '|' . ($row->product_type ?? 'UNKNOWN');
            $comparison[$key] ??= $this->emptyComparisonRow($row);
            $metric = (float) ($row->metric_value ?? 0);
            $comparison[$key]['actual_total'] += $metric;

            $sameDueMonth = $this->isSamePeriod($row->original_due_date ?? null, $year, $month);
            if ($sameDueMonth) {
                $comparison[$key]['actual_same_due_month'] += $metric;
            } else {
                $comparison[$key]['actual_other_due_month'] += $metric;
            }

            $orderLineId = (int) ($row->orderitems_id ?? 0);
            $actualByOrderLine[$orderLineId] = ($actualByOrderLine[$orderLineId] ?? 0) + $metric;

            $duePeriod = empty($row->original_due_date)
                ? 'NO_DUE_DATE'
                : Carbon::parse($row->original_due_date)->format('Y-m');
            $dueOrigins[$duePeriod] ??= [
                'due_period' => $duePeriod,
                'due_label' => $duePeriod === 'NO_DUE_DATE' ? 'No Due Date' : Carbon::parse($duePeriod . '-01')->format('M Y'),
                'qty_actual' => 0.0,
                'd8_baht' => 0.0,
                'line_count' => 0,
            ];
            if (($row->group_code ?? '') === 'D8') {
                $dueOrigins[$duePeriod]['d8_baht'] += $metric;
            } else {
                $dueOrigins[$duePeriod]['qty_actual'] += $metric;
            }
            $dueOrigins[$duePeriod]['line_count']++;

            $row->due_period = $duePeriod;
            $row->origin_type = $sameDueMonth ? 'Same Due Month' : 'Other Due Month';
        }

        foreach ($orderRows as $row) {
            $row->actual_delivery_metric = (float) ($actualByOrderLine[(int) $row->orderitems_id] ?? 0);
            $row->variance_metric = $row->actual_delivery_metric - (float) ($row->metric_value ?? 0);
        }

        foreach ($comparison as &$row) {
            $row['due_performance_variance'] = $row['actual_same_due_month'] - $row['order_due'];
            $row['monthly_variance'] = $row['actual_total'] - $row['order_due'];
        }
        unset($row);

        $comparisonRows = array_values($comparison);
        usort($comparisonRows, fn(array $a, array $b) => $this->comparisonSortKey($a) <=> $this->comparisonSortKey($b));
        ksort($dueOrigins);

        return [
            'period' => sprintf('%04d-%02d', $year, $month),
            'rows' => $comparisonRows,
            'totals' => [
                'qty' => $this->sumComparisonRows($comparisonRows, false),
                'baht' => $this->sumComparisonRows($comparisonRows, true),
            ],
            'due_origins' => array_values($dueOrigins),
            'order_details' => $orderRows,
            'actual_details' => $actualRows,
        ];
    }

    private function emptyComparisonRow(object $row): array
    {
        return [
            'group_code' => (string) ($row->group_code ?? '-'),
            'group_name' => (string) ($row->group_name ?? '-'),
            'product_type' => (string) ($row->product_type ?? 'UNKNOWN'),
            'metric_unit' => (string) ($row->metric_unit ?? 'Qty'),
            'order_due' => 0.0,
            'actual_same_due_month' => 0.0,
            'due_performance_variance' => 0.0,
            'actual_other_due_month' => 0.0,
            'actual_total' => 0.0,
            'monthly_variance' => 0.0,
        ];
    }

    private function sumComparisonRows(array $rows, bool $d8): array
    {
        $filtered = array_filter($rows, fn(array $row) => (($row['group_code'] ?? '') === 'D8') === $d8);
        $keys = ['order_due', 'actual_same_due_month', 'due_performance_variance', 'actual_other_due_month', 'actual_total', 'monthly_variance'];
        $totals = [];
        foreach ($keys as $key) {
            $totals[$key] = array_sum(array_map(fn(array $row) => (float) ($row[$key] ?? 0), $filtered));
        }

        return $totals;
    }

    private function comparisonSortKey(array $row): string
    {
        $groupIndex = array_search($row['group_code'] ?? '', $this->groupOrder, true);
        $typeIndex = array_search($row['product_type'] ?? '', $this->preferredTypeOrder, true);

        return sprintf(
            '%03d|%03d|%s',
            $groupIndex === false ? 999 : $groupIndex,
            $typeIndex === false ? 999 : $typeIndex,
            $row['product_type'] ?? ''
        );
    }

    private function isSamePeriod(mixed $date, int $year, int $month): bool
    {
        if (empty($date)) {
            return false;
        }

        $dueDate = Carbon::parse($date);

        return $dueDate->year === $year && $dueDate->month === $month;
    }

    private function buildActualDetailData(
        int $year,
        int $month,
        array $divisionCodes,
        string $typeName,
        ?string $duePeriod
    ): array {
        $from = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        $items = $this->getActualDeliveryRows(
            $from,
            $to,
            $this->salesIdsForGroups($divisionCodes),
            $typeName,
            $duePeriod
        );
        foreach ($items as $item) {
            $item->due_period = empty($item->original_due_date)
                ? 'NO_DUE_DATE'
                : Carbon::parse($item->original_due_date)->format('Y-m');
            $item->origin_type = $this->isSamePeriod($item->original_due_date ?? null, $year, $month)
                ? 'Same Due Month'
                : 'Other Due Month';
        }
        $qtyTotal = collect($items)->where('group_code', '!=', 'D8')->sum(fn($row) => (float) ($row->metric_value ?? 0));
        $bahtTotal = collect($items)->where('group_code', 'D8')->sum(fn($row) => (float) ($row->metric_value ?? 0));

        return [
            'mode' => 'actual',
            'title' => 'Actual Delivery · ' . implode(', ', $divisionCodes),
            'period_label' => Carbon::create($year, $month, 1)->format('F Y'),
            'due_period' => $duePeriod,
            'items' => $items,
            'line_count' => count($items),
            'total_qty' => $qtyTotal,
            'total_bath' => $bahtTotal,
            'total_metric' => null,
        ];
    }

    private function writeComparisonSheet(
        Worksheet $sheet,
        array $comparison,
        int $year,
        int $month,
        array $divisionCodes
    ): void {
        $sheet->setTitle('Comparison Summary');
        $sheet->fromArray([
            ['Report', 'Order Due Date vs Actual Delivery'],
            ['Period', sprintf('%04d-%02d', $year, $month)],
            ['Divisions', implode(', ', $divisionCodes)],
        ], null, 'A1');
        $headers = [
            'Division', 'Division Name', 'Product Type', 'Unit', 'Order Due',
            'Actual - Same Due Month', 'Due Performance Variance',
            'Actual - Other Due Month', 'Actual Delivery Total', 'Monthly Variance',
        ];
        $sheet->fromArray([$headers], null, 'A5');
        $rowNo = 6;
        foreach ($comparison['rows'] as $row) {
            $sheet->fromArray([[
                $row['group_code'], $row['group_name'], $row['product_type'], $row['metric_unit'],
                $row['order_due'], $row['actual_same_due_month'], $row['due_performance_variance'],
                $row['actual_other_due_month'], $row['actual_total'], $row['monthly_variance'],
            ]], null, 'A' . $rowNo++);
        }
        foreach ([['label' => 'TOTAL QTY', 'unit' => 'Qty', 'values' => $comparison['totals']['qty']], ['label' => 'TOTAL D8', 'unit' => 'Baht', 'values' => $comparison['totals']['baht']]] as $totalRow) {
            $values = $totalRow['values'];
            $sheet->fromArray([[
                $totalRow['label'], '', '', $totalRow['unit'],
                $values['order_due'], $values['actual_same_due_month'], $values['due_performance_variance'],
                $values['actual_other_due_month'], $values['actual_total'], $values['monthly_variance'],
            ]], null, 'A' . $rowNo);
            $sheet->getStyle("A{$rowNo}:J{$rowNo}")->getFont()->setBold(true);
            $rowNo++;
        }

        $originStart = $rowNo + 2;
        $sheet->fromArray([['Actual Delivery Composition by Original Due Month']], null, 'A' . $originStart);
        $sheet->fromArray([['Original Due Month', 'Actual Qty', 'D8 Actual Baht', 'Shipment Lines']], null, 'A' . ($originStart + 1));
        $originRow = $originStart + 2;
        foreach ($comparison['due_origins'] as $origin) {
            $sheet->fromArray([[
                $origin['due_label'], $origin['qty_actual'], $origin['d8_baht'], $origin['line_count'],
            ]], null, 'A' . $originRow++);
        }

        $this->styleExcelTable($sheet, 5, max(5, $rowNo - 1), 'J');
        $this->styleExcelTable($sheet, $originStart + 1, max($originStart + 1, $originRow - 1), 'D');
        $sheet->setAutoFilter('A5:J' . max(5, $rowNo - 1));
        $sheet->freezePane('A6');
        $sheet->getStyle('E6:J' . max(6, $rowNo - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('B' . ($originStart + 2) . ':C' . max($originStart + 2, $originRow - 1))->getNumberFormat()->setFormatCode('#,##0.00');
    }

    private function writeOrderDetailsSheet(Worksheet $sheet, array $rows): void
    {
        $sheet->setTitle('Order Details');
        $headers = [
            'Division', 'Product Type', 'SO No.', 'Customer Code', 'Customer', 'Customer PO',
            'Part No.', 'Description', 'Due Date', 'Source Order Qty', 'Weighted Order Qty',
            'Order Amount', 'Metric Value', 'Metric Unit', 'Actual in Selected Month', 'Variance',
        ];
        $sheet->fromArray([$headers], null, 'A1');
        $rowNo = 2;
        foreach ($rows as $row) {
            $sheet->fromArray([[
                $row->group_code, $row->product_type, $row->ordnumber, $row->customernumber,
                $row->customer_name, $row->custponumber, $row->partnumber, $row->description,
                $row->reqdate, $row->source_order_qty, $row->order_qty, $row->order_amount,
                $row->metric_value, $row->metric_unit, $row->actual_delivery_metric, $row->variance_metric,
            ]], null, 'A' . $rowNo++);
        }
        $this->styleExcelTable($sheet, 1, max(1, $rowNo - 1), 'P');
        $sheet->getStyle('J2:P' . max(2, $rowNo - 1))->getNumberFormat()->setFormatCode('#,##0.00');
    }

    private function writeActualDetailsSheet(Worksheet $sheet, array $rows): void
    {
        $sheet->setTitle('Actual Delivery Details');
        $headers = [
            'Division', 'Product Type', 'SO No.', 'Delivery No.', 'Customer Code', 'Customer',
            'Customer PO', 'Part No.', 'Description', 'Original Due Date', 'Original Due Month',
            'Actual Delivery Date', 'Actual Delivery Month', 'Source Order Qty', 'Weighted Order Qty',
            'Source Actual Qty', 'Weighted Actual Qty', 'Order Amount', 'Actual Amount',
            'Metric Value', 'Metric Unit', 'Origin Classification',
        ];
        $sheet->fromArray([$headers], null, 'A1');
        $rowNo = 2;
        foreach ($rows as $row) {
            $actualMonth = $row->actual_delivery_date ? Carbon::parse($row->actual_delivery_date)->format('Y-m') : '';
            $sheet->fromArray([[
                $row->group_code, $row->product_type, $row->ordnumber, $row->dmnumber,
                $row->customernumber, $row->customer_name, $row->custponumber, $row->partnumber,
                $row->description, $row->original_due_date, $row->due_period, $row->actual_delivery_date,
                $actualMonth, $row->source_order_qty, $row->order_qty, $row->source_actual_qty,
                $row->actual_qty, $row->order_amount, $row->actual_amount, $row->metric_value,
                $row->metric_unit, $row->origin_type,
            ]], null, 'A' . $rowNo++);
        }
        $this->styleExcelTable($sheet, 1, max(1, $rowNo - 1), 'V');
        $sheet->getStyle('N2:T' . max(2, $rowNo - 1))->getNumberFormat()->setFormatCode('#,##0.00');
    }

    private function styleExcelTable(Worksheet $sheet, int $headerRow, int $lastRow, string $lastColumn): void
    {
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->getFill()
            ->setFillType('solid')
            ->getStartColor()->setARGB('FFD9EAF7');
        $sheet->freezePane('A' . ($headerRow + 1));
        $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}{$lastRow}");
        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    private function normalizedDivisionCodes(mixed $input): array
    {
        $requested = is_array($input) ? $input : [$input];
        $requested = array_values(array_unique(array_map(
            fn($value) => strtoupper(trim((string) $value)),
            $requested
        )));
        if ($requested === [] || in_array('ALL', $requested, true)) {
            return array_keys($this->groupLabels);
        }

        $selected = array_values(array_filter(
            array_keys($this->groupLabels),
            fn(string $code) => in_array($code, $requested, true)
        ));

        return $selected !== [] ? $selected : array_keys($this->groupLabels);
    }

    private function salesIdsForGroups(array $groupCodes): array
    {
        return array_values(array_map(
            'intval',
            array_keys(array_filter(
                $this->salesToGroupMap,
                fn(string $code) => in_array($code, $groupCodes, true)
            ))
        ));
    }

    private function sanitizeDuePeriod(mixed $value): ?string
    {
        $period = strtoupper(trim((string) $value));
        if (in_array($period, ['NO_DUE_DATE', 'SAME_DUE_MONTH', 'OTHER_DUE_MONTH'], true)) {
            return $period;
        }

        return preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', $period) === 1 ? $period : null;
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
