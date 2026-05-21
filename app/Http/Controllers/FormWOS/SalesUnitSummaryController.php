<?php

namespace App\Http\Controllers\FormWOS;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesUnitSummaryController extends Controller
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

    private array $groupLabelMap = [
        'D1' => 'D1 - เธเธธเธ“เธ”เธดเธฅเธ + เธเธธเธ“เธเธงเธฑเธเน€เธฃเธทเธญเธ',
        'D2' => 'D2 - เธเธธเธ“เธเธฃเธตเธขเธฒเธเธฃเธฃเธ“ + เธเธธเธ“เธเธดเธ•เธขเธฒ',
        'D3' => 'D3 - เธเธธเธ“เธ เธเธงเธ”เธต + เธเธธเธ“เธเธเธฑเธเธเธฒ',
        'D5' => 'D5 - เธเธธเธ“เธเธฑเธเธฅเธดเธเธฒ + เธเธธเธ“เน€เธเธญเธฃเนเธฅเธดเธเธฒ',
        'D6' => 'D6 - เธเธธเธ“เธชเธธเธฃเธจเธฑเธเธ”เธดเน + เธเธธเธ“เธเธ“เธฑเธเธเนเธเธดเธเธฒ',
        'D7' => 'D7 - เธเธธเธ“เธจเธดเธฃเธดเธเธ เธฒ + เธเธธเธ“เธกเธเธเธฑเธ—เธเน',
        'D8' => 'D8 - เธเธธเธ“เธชเธฒเธเธดเธ• + เธเธธเธ“เธชเธธเธเธฒเธชเธดเธเธต',
        'D9' => 'D9 - เธเธธเธ“เธงเธฃเน€เธ”เธเธฒ + เธเธธเธ“เธฅเธฑเธ”เธ”เธฒเธงเธฑเธฅเธขเน',
    ];

    private array $groupOrder = ['D1', 'D2', 'D3', 'D5', 'D6', 'D7', 'D9', 'TOTAL', 'D8'];

    private function groupLabels(): array
    {
        return [
            'D1' => 'D1 - คุณดิลก + คุณขวัญเรือน',
            'D2' => 'D2 - คุณปรียาพรรณ + คุณนิตยา',
            'D3' => 'D3 - คุณภควดี + คุณธนัชชา',
            'D5' => 'D5 - คุณธัธลิญา + คุณเชอร์ลิญา',
            'D6' => 'D6 - คุณสุรศักดิ์ + คุณคณัญญ์นุชา',
            'D7' => 'D7 - คุณศิรินภา + คุณมนพัทธ์',
            'D8' => 'D8 - คุณสาธิต + คุณสุธาสินี',
            'D9' => 'D9 - คุณวรเดชา + คุณลัดดาวัลย์',
        ];
    }

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
        'FG GRATING' => 1700000,
    ];

    private function weightedQtyExpr(string $itemAlias): string
    {
        return "{$itemAlias}.qty * CASE WHEN p.ref_unit = '03' THEN p.ref_unit_qty ELSE 1 END";
    }

    public function index(Request $request)
    {
        [$defaultFrom, $defaultTo] = $this->defaultRange();

        $year = (int)$request->query('year', Carbon::now('Asia/Bangkok')->year);
        $month = (int)$request->query('month', Carbon::now('Asia/Bangkok')->month);
        $from = $request->query('from', $defaultFrom);
        $to = $request->query('to', $defaultTo);
        $product = trim((string)$request->query('product', ''));
        $division = strtoupper(trim((string)$request->query('division', '')));

        if ($year < 2000 || $year > 2100) {
            $year = Carbon::now('Asia/Bangkok')->year;
        }

        if ($month < 1 || $month > 12) {
            $month = Carbon::now('Asia/Bangkok')->month;
        }

        if ($division !== '' && !array_key_exists($division, $this->groupLabels())) {
            $division = '';
        }

        $productOptions = $this->getProductOptions($year);
        if ($product !== '' && !in_array($product, $productOptions, true)) {
            $product = '';
        }

        $rawRows = $this->getSummaryRaw($from, $to, $product, $division);
        $baseTypeColumns = $product === '' ? $productOptions : [$product];
        [$rows, $typeColumns] = $this->buildPivotRows($rawRows, $product, $baseTypeColumns);
        $yearRows = $this->buildYearReport($year, $typeColumns, $product, $division);

        if ($request->query('export') === '1') {
            return $this->exportSummaryCsv($rows, $typeColumns, $from, $to);
        }

        return view('formwos.sales_unit_summary.index', [
            'rows' => $rows,
            'yearRows' => $yearRows,
            'typeColumns' => $typeColumns,
            'divisionOptions' => $this->groupLabels(),
            'productOptions' => $productOptions,
            'targets' => $this->typeTargets,
            'filters' => [
                'from' => $from,
                'to' => $to,
                'year' => $year,
                'month' => $month,
                'product' => $product,
                'division' => $division,
            ],
        ]);
    }

    public function dashboard(Request $request)
    {
        $year = (int)$request->query('year', Carbon::now()->year);
        $month = (int)$request->query('month', Carbon::now()->month);
        $groupCodes = $this->sanitizeDashboardDivisions($request->query('division', ['D1']));
        $groupCode = $groupCodes[0];
        $product = trim((string)$request->query('product', ''));

        if ($year < 2000 || $year > 2100) {
            $year = Carbon::now()->year;
        }

        if ($month < 1 || $month > 12) {
            $month = Carbon::now()->month;
        }

        $productOptions = $this->getProductOptions($year);
        if ($product !== '' && !in_array($product, $productOptions, true)) {
            $product = '';
        }

        $dashboard = $this->buildDivisionDashboardData($groupCode, $year, $month, $product);
        $comparisonRows = $this->buildDivisionComparisonRows($groupCodes, $year, $month, $product);
        $dashboard['selected_division_codes'] = $groupCodes;
        $dashboard['comparison_rows'] = $comparisonRows;
        $dashboard['division_trend_datasets'] = $this->buildDivisionTrendDatasets($comparisonRows, $dashboard['unit'], $year);
        $dashboard['trend_note'] = $this->buildDivisionTrendNote($comparisonRows, $dashboard['unit']);

        return view('formwos.sales_unit_summary.dashboard', [
            'dashboard' => $dashboard,
            'divisionOptions' => $this->groupLabels(),
            'productOptions' => $productOptions,
            'filters' => [
                'division' => $groupCodes,
                'year' => $year,
                'month' => $month,
                'product' => $product,
            ],
        ]);
    }

    public function dashboardPdf(Request $request)
    {
        $year = (int)$request->query('year', Carbon::now()->year);
        $month = (int)$request->query('month', Carbon::now()->month);
        $groupCodes = $this->sanitizeDashboardDivisions($request->query('division', ['D1']));
        $groupCode = $groupCodes[0];
        $product = trim((string)$request->query('product', ''));

        if ($year < 2000 || $year > 2100) {
            $year = Carbon::now()->year;
        }

        if ($month < 1 || $month > 12) {
            $month = Carbon::now()->month;
        }

        $productOptions = $this->getProductOptions($year);
        if ($product !== '' && !in_array($product, $productOptions, true)) {
            $product = '';
        }

        $dashboard = $this->buildDivisionDashboardData($groupCode, $year, $month, $product);
        $comparisonRows = $this->buildDivisionComparisonRows($groupCodes, $year, $month, $product);
        $dashboard['selected_division_codes'] = $groupCodes;
        $dashboard['comparison_rows'] = $comparisonRows;
        $dashboard['division_trend_datasets'] = $this->buildDivisionTrendDatasets($comparisonRows, $dashboard['unit'], $year);
        $dashboard['trend_note'] = $this->buildDivisionTrendNote($comparisonRows, $dashboard['unit']);
        $dashboard['all_type_drivers'] = $this->getDashboardTypeDrivers($groupCode, $year, $month, null, $product);
        $dashboard['all_type_compare'] = $this->getDivisionTypeCompare($this->getSalesIdsByGroupCode($groupCode), $year, $month, $year - 1, $groupCode === 'D8', null, $product);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.wos-sales-unit-summary-dashboard', [
            'dashboard' => $dashboard,
            'divisionOptions' => $this->groupLabels(),
            'filters' => [
                'division' => $groupCodes,
                'year' => $year,
                'month' => $month,
                'product' => $product,
            ],
            'generatedAt' => Carbon::now('Asia/Bangkok'),
        ])->setPaper('a4', 'landscape');

        $fileName = 'WOS-Qty-Dashboard-' . $groupCode . '-' . sprintf('%04d-%02d', $year, $month) . '.pdf';

        return $pdf->download($fileName);
    }

    public function detailByGroup(Request $request)
    {
        [$defaultFrom, $defaultTo] = $this->defaultRange();

        $from = $request->query('from', $defaultFrom);
        $to = $request->query('to', $defaultTo);
        $groupCode = trim((string)$request->query('group_code', ''));
        $typeName = trim((string)$request->query('type_name', ''));

        if ($groupCode === '' || !array_key_exists($groupCode, $this->groupLabels())) {
            abort(404);
        }

        $salesIds = $this->getSalesIdsByGroupCode($groupCode);
        if (empty($salesIds)) {
            abort(404);
        }

        $items = $typeName === ''
            ? $this->getDetailRowsForGroup($from, $to, $salesIds)
            : $this->getDetailRows($from, $to, $salesIds, $typeName);
        $sumQty = collect($items)->sum(fn($r) => (float)($r->qty ?? 0));

        return view('formwos.sales_unit_summary.detail', [
            'groupCode' => $groupCode,
            'divisionName' => $this->groupLabels()[$groupCode] ?? $groupCode,
            'typeName' => $typeName === '' ? null : $typeName,
            'items' => $items,
            'sumQty' => $sumQty,
            'filters' => [
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }

    public function detail(Request $request)
    {
        [$defaultFrom, $defaultTo] = $this->defaultRange();

        $from = $request->query('from', $defaultFrom);
        $to = $request->query('to', $defaultTo);
        $groupCode = trim((string)$request->query('group_code', ''));
        $typeName = trim((string)$request->query('type_name', ''));

        if ($groupCode === '' || $typeName === '' || !array_key_exists($groupCode, $this->groupLabels())) {
            abort(404);
        }

        $salesIds = $this->getSalesIdsByGroupCode($groupCode);
        if (empty($salesIds)) {
            abort(404);
        }

        $items = $this->getDetailRows($from, $to, $salesIds, $typeName);
        $sumQty = collect($items)->sum(fn($r) => (float)($r->qty ?? 0));

        return view('formwos.sales_unit_summary.detail', [
            'groupCode' => $groupCode,
            'divisionName' => $this->groupLabels()[$groupCode] ?? $groupCode,
            'typeName' => $typeName,
            'items' => $items,
            'sumQty' => $sumQty,
            'filters' => [
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }

    private function defaultRange(): array
    {
        $today = Carbon::now();

        return [
            $today->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
            $today->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        ];
    }

    private function getSummaryRaw(string $from, string $to, string $product = '', string $division = ''): array
    {
        $qtyExpr = $this->weightedQtyExpr('pi');
        $extraWhere = '';
        $bindings = [$from, $to];

        if ($product !== '') {
            $extraWhere .= " AND partstype.description = ?";
            $bindings[] = $product;
        }

        if ($division !== '') {
            $salesIds = $this->getSalesIdsByGroupCode($division);
            if (empty($salesIds)) {
                return [];
            }

            $extraWhere .= ' AND customer.saleperson_id IN (' . implode(',', array_fill(0, count($salesIds), '?')) . ')';
            $bindings = array_merge($bindings, $salesIds);
        }

        $sql = "
            WITH sale1 AS (
                SELECT
                    customer.saleperson_id,
                    partstype.description AS type,
                    $qtyExpr AS qty,
                    pi.sellprice * pi.qty AS bath
                FROM predm
                JOIN predmitems pi ON predm.id = pi.trans_id
                JOIN customer ON predm.customer_id = customer.id
                JOIN parts p ON pi.parts_id = p.id
                JOIN partstype ON p.partstype_id = partstype.id
                WHERE predm.ordnumber IS NOT NULL
                  AND predm.ordnumber LIKE 'SO%'
                  AND customer.saleperson_id IN (1506,1507,1431,1433,1434,1435,1436,528615586)
                  AND predm.transdate >= ?
                  AND predm.transdate <= ?
                  AND pi.unit != ' '
                  $extraWhere
            )
            SELECT
                sale1.saleperson_id AS sales_id,
                sale1.type AS type_name,
                ROUND(SUM(CASE WHEN sale1.saleperson_id = 1436 THEN sale1.bath ELSE sale1.qty END), 2) AS total_qty
            FROM sale1
            GROUP BY sale1.saleperson_id, sale1.type
            ORDER BY sale1.saleperson_id, sale1.type
        ";

        return DB::connection($this->conn)->select($sql, $bindings);
    }

    private function getDetailRowsForGroup(string $from, string $to, array $salesIds): array
    {
        return $this->getDetailRowsBase($from, $to, $salesIds, null);
    }

    private function getDetailRows(string $from, string $to, array $salesIds, string $typeName): array
    {
        return $this->getDetailRowsBase($from, $to, $salesIds, $typeName);
    }

    private function getDetailRowsBase(string $from, string $to, array $salesIds, ?string $typeName): array
    {
        $placeholders = implode(',', array_fill(0, count($salesIds), '?'));
        $typeWhere = $typeName === null ? '' : 'AND partstype.description = ?';
        $qtyExpr = $this->weightedQtyExpr('pi');

        $sql = "
            SELECT
                predm.transdate,
                predm.ordnumber,
                customer.saleperson_id AS sales_id,
                pi.description AS item_description,
                customer.customernumber,
                customer.name AS customer_name,
                partstype.description AS partstype_description,
                unitmeasure.description AS unit_description,
                ROUND(CASE WHEN customer.saleperson_id = 1436 THEN pi.sellprice * pi.qty ELSE $qtyExpr END, 2) AS qty
            FROM predm
            JOIN predmitems pi ON predm.id = pi.trans_id
            JOIN customer ON predm.customer_id = customer.id
            JOIN parts p ON pi.parts_id = p.id
            JOIN partstype ON p.partstype_id = partstype.id
            JOIN unitmeasure ON p.unit = unitmeasure.unit
            WHERE predm.ordnumber IS NOT NULL
              AND predm.ordnumber LIKE 'SO%'
              AND predm.transdate >= ?
              AND predm.transdate <= ?
              AND customer.saleperson_id IN ($placeholders)
              AND pi.unit != ' '
              $typeWhere
            ORDER BY predm.transdate, predm.ordnumber, pi.description
        ";

        $bindings = array_merge([$from, $to], $salesIds);
        if ($typeName !== null) {
            $bindings[] = $typeName;
        }

        return DB::connection($this->conn)->select($sql, $bindings);
    }

    private function buildPivotRows(array $rawRows, string $selectedProduct = '', array $baseTypeColumns = []): array
    {
        $groupedRows = [];
        $typeColumnsMap = array_fill_keys($baseTypeColumns, true);

        foreach ($this->groupLabels() as $groupCode => $groupName) {
            $groupedRows[$groupCode] = [
                'group_code' => $groupCode,
                'group_name' => $groupName,
                'cells' => [],
                'grand_total' => 0.0,
                'fg_grating' => 0.0,
            ];
        }

        foreach ($rawRows as $r) {
            $salesId = (int)($r->sales_id ?? 0);
            $groupCode = $this->salesToGroupMap[$salesId] ?? null;
            if (!$groupCode) {
                continue;
            }

            $typeName = trim((string)($r->type_name ?? '')) ?: 'UNKNOWN';
            $qty = (float)($r->total_qty ?? 0);

            if ($typeName === 'FG GRATING') {
                $groupedRows[$groupCode]['fg_grating'] += $qty;
                continue;
            }

            $typeColumnsMap[$typeName] = true;
            $groupedRows[$groupCode]['cells'][$typeName] = ($groupedRows[$groupCode]['cells'][$typeName] ?? 0) + $qty;
            $groupedRows[$groupCode]['grand_total'] += $qty;
        }

        if ($selectedProduct !== '' && $selectedProduct !== 'FG GRATING') {
            $typeColumnsMap[$selectedProduct] = true;
        }

        unset($typeColumnsMap['FG GRATING']);
        $typeColumns = $this->sortTypeColumns(array_keys($typeColumnsMap));

        foreach ($groupedRows as &$row) {
            foreach ($typeColumns as $typeColumn) {
                $row['cells'][$typeColumn] = (float)($row['cells'][$typeColumn] ?? 0);
            }
        }
        unset($row);

        $totalRow = [
            'group_code' => 'TOTAL',
            'group_name' => 'Grand Total',
            'cells' => array_fill_keys($typeColumns, 0.0),
            'grand_total' => 0.0,
            'fg_grating' => 0.0,
        ];

        foreach ($groupedRows as $code => $row) {
            if ($code === 'D8') {
                continue;
            }

            foreach ($typeColumns as $typeColumn) {
                $totalRow['cells'][$typeColumn] += (float)($row['cells'][$typeColumn] ?? 0);
            }

            $totalRow['grand_total'] += (float)($row['grand_total'] ?? 0);
            $totalRow['fg_grating'] += (float)($row['fg_grating'] ?? 0);
        }

        $rows = array_values($groupedRows);
        $rows[] = $totalRow;

        usort($rows, function ($a, $b) {
            $oa = array_search($a['group_code'], $this->groupOrder, true);
            $ob = array_search($b['group_code'], $this->groupOrder, true);

            return (($oa === false) ? 999 : $oa) <=> (($ob === false) ? 999 : $ob);
        });

        return [$rows, $typeColumns];
    }

    private function buildYearReport(int $year, array $typeColumns, string $product = '', string $division = ''): array
    {
        $from = Carbon::create($year, 1, 1)->startOfYear()->toDateString();
        $to = Carbon::create($year, 12, 31)->endOfYear()->toDateString();
        $rawRows = $this->getSummaryByMonthRaw($from, $to, $product, $division);
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
            $month = (int)($row->month_number ?? 0);
            if (!isset($rows[$month])) {
                continue;
            }

            $typeName = trim((string)($row->type_name ?? '')) ?: 'UNKNOWN';
            $qty = (float)($row->total_qty ?? 0);

            if ($typeName === 'FG GRATING') {
                $rows[$month]['fg_grating'] += $qty;
                continue;
            }

            if (!array_key_exists($typeName, $rows[$month]['cells'])) {
                continue;
            }

            $rows[$month]['cells'][$typeName] += $qty;
            $rows[$month]['grand_total'] += $qty;
        }

        return array_values($rows);
    }

    private function getSummaryByMonthRaw(string $from, string $to, string $product = '', string $division = ''): array
    {
        $qtyExpr = $this->weightedQtyExpr('pi');
        $extraWhere = '';
        $bindings = [$from, $to];

        if ($product !== '') {
            $extraWhere .= " AND partstype.description = ?";
            $bindings[] = $product;
        }

        if ($division !== '') {
            $salesIds = $this->getSalesIdsByGroupCode($division);
            if (empty($salesIds)) {
                return [];
            }

            $extraWhere .= ' AND customer.saleperson_id IN (' . implode(',', array_fill(0, count($salesIds), '?')) . ')';
            $bindings = array_merge($bindings, $salesIds);
        }

        $sql = "
            WITH sale1 AS (
                SELECT
                    EXTRACT(MONTH FROM predm.transdate) AS month_number,
                    customer.saleperson_id,
                    partstype.description AS type,
                    $qtyExpr AS qty,
                    pi.sellprice * pi.qty AS bath
                FROM predm
                JOIN predmitems pi ON predm.id = pi.trans_id
                JOIN customer ON predm.customer_id = customer.id
                JOIN parts p ON pi.parts_id = p.id
                JOIN partstype ON p.partstype_id = partstype.id
                WHERE predm.ordnumber IS NOT NULL
                  AND predm.ordnumber LIKE 'SO%'
                  AND customer.saleperson_id IN (1506,1507,1431,1433,1434,1435,1436,528615586)
                  AND predm.transdate >= ?
                  AND predm.transdate <= ?
                  AND pi.unit != ' '
                  $extraWhere
            )
            SELECT
                sale1.month_number,
                sale1.type AS type_name,
                ROUND(SUM(CASE WHEN sale1.saleperson_id = 1436 THEN sale1.bath ELSE sale1.qty END), 2) AS total_qty
            FROM sale1
            GROUP BY sale1.month_number, sale1.type
            ORDER BY sale1.month_number, sale1.type
        ";

        return DB::connection($this->conn)->select($sql, $bindings);
    }

    private function buildDivisionDashboardData(string $groupCode, int $year, int $month, string $product = ''): array
    {
        $salesIds = $this->getSalesIdsByGroupCode($groupCode);
        $isD8 = $groupCode === 'D8';
        $range = $this->monthRange($year, $month);
        $previousRange = $this->monthRange($year - 1, $month);
        $current = $this->getDivisionMonthStats($salesIds, $year, $month, $isD8, $product);
        $previous = $this->getDivisionMonthStats($salesIds, $year - 1, $month, $isD8, $product);
        $ytdCurrent = $this->getDivisionPeriodStats($salesIds, Carbon::create($year, 1, 1)->toDateString(), $range['to'], $isD8, $product);
        $ytdPrevious = $this->getDivisionPeriodStats($salesIds, Carbon::create($year - 1, 1, 1)->toDateString(), $previousRange['to'], $isD8, $product);
        $trendCurrent = $this->getDivisionYearTrend($salesIds, $year, $isD8, $product);
        $trendPrevious = $this->getDivisionYearTrend($salesIds, $year - 1, $isD8, $product);
        $typeCompare = $this->getDivisionTypeCompare($salesIds, $year, $month, $year - 1, $isD8, 12, $product);
        $ytdTypeCompare = $this->getDivisionTypePeriodCompare(
            $salesIds,
            Carbon::create($year, 1, 1)->toDateString(),
            $range['to'],
            Carbon::create($year - 1, 1, 1)->toDateString(),
            $previousRange['to'],
            $isD8,
            $product
        );
        $customerMix = $this->getCustomerMix($salesIds, $range['from'], $range['to'], $previousRange['from'], $previousRange['to'], $product);
        $customerDrivers = $this->getTopChangeDrivers($salesIds, $range['from'], $range['to'], $previousRange['from'], $previousRange['to'], $isD8, 'customer', 8, $product);
        $allCustomerDrivers = $this->getTopChangeDrivers($salesIds, $range['from'], $range['to'], $previousRange['from'], $previousRange['to'], $isD8, 'customer', null, $product);
        $typeDrivers = $this->getDashboardTypeDrivers($groupCode, $year, $month, 8, $product);
        $allTypeDrivers = $this->getDashboardTypeDrivers($groupCode, $year, $month, null, $product);
        $working = $this->workingDayPace($year, $month, (float)$current['total']);
        $monthLabels = [];
        for ($i = 1; $i <= 12; $i++) {
            $monthLabels[] = $this->thaiShortMonths()[$i] ?? (string)$i;
        }
        $monthDetailUrls = $this->buildDeliveryMonthDetailUrls($groupCode, $year, $product);

        return [
            'division_code' => $groupCode,
            'division_name' => $this->groupLabels()[$groupCode] ?? $groupCode,
            'unit' => $isD8 ? 'Baht' : 'Qty',
            'selected_product' => $product,
            'current' => $current,
            'previous' => $previous,
            'ytd_current' => $ytdCurrent,
            'ytd_previous' => $ytdPrevious,
            'ytd_change_percent' => $this->percentChange($ytdCurrent['total'], $ytdPrevious['total']),
            'working_day_pace' => $working,
            'customer_mix' => $customerMix,
            'customer_drivers' => $customerDrivers,
            'all_customer_drivers' => $allCustomerDrivers,
            'type_drivers' => $typeDrivers,
            'all_type_drivers' => $allTypeDrivers,
            'detail_url' => route('wos.sales_unit_summary.detail_group', [
                'group_code' => $groupCode,
                'type_name' => $product,
                'from' => $range['from'],
                'to' => $range['to'],
            ]),
            'change_percent' => $this->percentChange($current['total'], $previous['total']),
            'month_labels' => $monthLabels,
            'month_detail_urls' => $monthDetailUrls,
            'current_year_trend' => $trendCurrent,
            'previous_year_trend' => $trendPrevious,
            'type_compare' => $typeCompare,
            'ytd_type_compare' => $ytdTypeCompare,
            'insight' => $this->buildDashboardInsight($isD8 ? 'Baht' : 'Qty', $this->percentChange($current['total'], $previous['total']), $customerDrivers, $typeDrivers, $customerMix),
            'kpis' => [
                'selected_label' => $this->thaiMonthYearLabel($year, $month),
                'previous_label' => $this->thaiMonthYearLabel($year - 1, $month),
                'order_change_percent' => $this->percentChange($current['order_count'], $previous['order_count']),
                'customer_change_percent' => $this->percentChange($current['customer_count'], $previous['customer_count']),
            ],
        ];
    }

    private function sanitizeDashboardDivisions(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : [$raw];
        $valid = [];
        $labels = $this->groupLabels();

        foreach ($values as $value) {
            $code = strtoupper(trim((string)$value));
            if ($code !== '' && array_key_exists($code, $labels) && !in_array($code, $valid, true)) {
                $valid[] = $code;
            }
        }

        return $valid ?: ['D1'];
    }

    private function buildDivisionComparisonRows(array $groupCodes, int $year, int $month, string $product = ''): array
    {
        $rows = [];
        $labels = $this->groupLabels();

        foreach ($groupCodes as $groupCode) {
            $salesIds = $this->getSalesIdsByGroupCode($groupCode);
            $isD8 = $groupCode === 'D8';
            $range = $this->monthRange($year, $month);
            $previousRange = $this->monthRange($year - 1, $month);
            $current = $this->getDivisionMonthStats($salesIds, $year, $month, $isD8, $product);
            $previous = $this->getDivisionMonthStats($salesIds, $year - 1, $month, $isD8, $product);
            $ytdCurrent = $this->getDivisionPeriodStats($salesIds, Carbon::create($year, 1, 1)->toDateString(), $range['to'], $isD8, $product);
            $ytdPrevious = $this->getDivisionPeriodStats($salesIds, Carbon::create($year - 1, 1, 1)->toDateString(), $previousRange['to'], $isD8, $product);
            $trendCurrent = $this->getDivisionYearTrend($salesIds, $year, $isD8, $product);
            $trendPrevious = $this->getDivisionYearTrend($salesIds, $year - 1, $isD8, $product);

            $rows[] = [
                'division_code' => $groupCode,
                'division_name' => $labels[$groupCode] ?? $groupCode,
                'unit' => $isD8 ? 'Baht' : 'Qty',
                'current_total' => (float)($current['total'] ?? 0),
                'previous_total' => (float)($previous['total'] ?? 0),
                'change_percent' => $this->percentChange((float)($current['total'] ?? 0), (float)($previous['total'] ?? 0)),
                'ytd_current_total' => (float)($ytdCurrent['total'] ?? 0),
                'ytd_previous_total' => (float)($ytdPrevious['total'] ?? 0),
                'ytd_change_percent' => $this->percentChange((float)($ytdCurrent['total'] ?? 0), (float)($ytdPrevious['total'] ?? 0)),
                'order_count' => (int)($current['order_count'] ?? 0),
                'customer_count' => (int)($current['customer_count'] ?? 0),
                'current_year_trend' => $trendCurrent,
                'previous_year_trend' => $trendPrevious,
                'current_month_detail_urls' => $this->buildDeliveryMonthDetailUrls($groupCode, $year, $product),
                'previous_month_detail_urls' => $this->buildDeliveryMonthDetailUrls($groupCode, $year - 1, $product),
                'detail_url' => route('wos.sales_unit_summary.detail_group', [
                    'group_code' => $groupCode,
                    'type_name' => $product,
                    'from' => $range['from'],
                    'to' => $range['to'],
                ]),
            ];
        }

        return $rows;
    }

    private function buildDivisionTrendDatasets(array $comparisonRows, string $unit, int $year): array
    {
        $colors = ['#16a34a', '#2563eb', '#dc2626', '#9333ea', '#ea580c', '#0891b2', '#4f46e5', '#64748b'];
        $datasets = [];
        $i = 0;

        foreach ($comparisonRows as $row) {
            if (($row['unit'] ?? $unit) !== $unit) {
                continue;
            }

            $color = $colors[$i % count($colors)];
            $code = $row['division_code'] ?? '';
            $datasets[] = [
                'label' => trim($code . ' ' . $year),
                'data' => $row['current_year_trend'] ?? [],
                'borderColor' => $color,
                'backgroundColor' => 'transparent',
                'tension' => .3,
                'fill' => false,
                'spanGaps' => false,
                'pointRadius' => 3,
                'detailUrls' => $row['current_month_detail_urls'] ?? [],
            ];
            $datasets[] = [
                'label' => trim($code . ' ' . ($year - 1)),
                'data' => $row['previous_year_trend'] ?? [],
                'borderColor' => $color,
                'backgroundColor' => 'transparent',
                'borderDash' => [6, 4],
                'tension' => .3,
                'fill' => false,
                'spanGaps' => false,
                'pointRadius' => 3,
                'detailUrls' => $row['previous_month_detail_urls'] ?? [],
            ];
            $i++;
        }

        return $datasets;
    }

    private function buildDivisionTrendNote(array $comparisonRows, string $unit): ?string
    {
        $excluded = array_values(array_filter($comparisonRows, fn($row) => ($row['unit'] ?? $unit) !== $unit));

        if (empty($excluded)) {
            return null;
        }

        $codes = implode(', ', array_map(fn($row) => (string)($row['division_code'] ?? ''), $excluded));

        return 'กราฟเส้นแสดงเฉพาะ division ที่เป็นหน่วย ' . $unit . ' และไม่รวม ' . $codes . ' เพราะเป็นคนละหน่วย';
    }

    private function getDivisionMonthStats(array $salesIds, int $year, int $month, bool $isD8, string $product = ''): array
    {
        $from = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        $in = implode(',', array_fill(0, count($salesIds), '?'));
        $metricExpr = $isD8 ? 'pi.sellprice * pi.qty' : $this->weightedQtyExpr('pi');
        $productWhere = $product === '' ? '' : 'AND partstype.description = ?';
        $bindings = array_merge($salesIds, [$from, $to]);
        if ($product !== '') {
            $bindings[] = $product;
        }

        $sql = "
            SELECT
                ROUND(COALESCE(SUM($metricExpr), 0), 2) AS total,
                COUNT(DISTINCT predm.ordnumber) AS order_count,
                COUNT(DISTINCT customer.id) AS customer_count
            FROM predm
            JOIN predmitems pi ON predm.id = pi.trans_id
            JOIN customer ON predm.customer_id = customer.id
            JOIN parts p ON pi.parts_id = p.id
            JOIN partstype ON p.partstype_id = partstype.id
            WHERE predm.ordnumber IS NOT NULL
              AND predm.ordnumber LIKE 'SO%'
              AND customer.saleperson_id IN ($in)
              AND predm.transdate >= ?
              AND predm.transdate <= ?
              AND pi.unit != ' '
              $productWhere
        ";

        $row = DB::connection($this->conn)->selectOne($sql, $bindings);

        return [
            'total' => (float)($row->total ?? 0),
            'order_count' => (int)($row->order_count ?? 0),
            'customer_count' => (int)($row->customer_count ?? 0),
        ];
    }

    private function getDivisionPeriodStats(array $salesIds, string $from, string $to, bool $isD8, string $product = ''): array
    {
        $in = implode(',', array_fill(0, count($salesIds), '?'));
        $metricExpr = $isD8 ? 'pi.sellprice * pi.qty' : $this->weightedQtyExpr('pi');
        $productWhere = $product === '' ? '' : 'AND partstype.description = ?';
        $bindings = array_merge($salesIds, [$from, $to]);
        if ($product !== '') {
            $bindings[] = $product;
        }

        $sql = "
            SELECT
                ROUND(COALESCE(SUM($metricExpr), 0), 2) AS total,
                COUNT(DISTINCT predm.ordnumber) AS order_count,
                COUNT(DISTINCT customer.id) AS customer_count
            FROM predm
            JOIN predmitems pi ON predm.id = pi.trans_id
            JOIN customer ON predm.customer_id = customer.id
            JOIN parts p ON pi.parts_id = p.id
            JOIN partstype ON p.partstype_id = partstype.id
            WHERE predm.ordnumber IS NOT NULL
              AND predm.ordnumber LIKE 'SO%'
              AND customer.saleperson_id IN ($in)
              AND predm.transdate >= ?
              AND predm.transdate <= ?
              AND pi.unit != ' '
              $productWhere
        ";

        $row = DB::connection($this->conn)->selectOne($sql, $bindings);

        return [
            'total' => (float)($row->total ?? 0),
            'order_count' => (int)($row->order_count ?? 0),
            'customer_count' => (int)($row->customer_count ?? 0),
        ];
    }

    private function getCustomerMix(array $salesIds, string $from, string $to, string $previousFrom, string $previousTo, string $product = ''): array
    {
        $current = $this->getCustomerSet($salesIds, $from, $to, $product);
        $previous = $this->getCustomerSet($salesIds, $previousFrom, $previousTo, $product);

        return [
            'new' => count(array_diff($current, $previous)),
            'retained' => count(array_intersect($current, $previous)),
            'lost' => count(array_diff($previous, $current)),
            'current_total' => count($current),
            'previous_total' => count($previous),
        ];
    }

    private function getCustomerSet(array $salesIds, string $from, string $to, string $product = ''): array
    {
        $in = implode(',', array_fill(0, count($salesIds), '?'));
        $productWhere = $product === '' ? '' : 'AND partstype.description = ?';
        $bindings = array_merge($salesIds, [$from, $to]);
        if ($product !== '') {
            $bindings[] = $product;
        }

        $sql = "
            SELECT DISTINCT customer.id
            FROM predm
            JOIN predmitems pi ON predm.id = pi.trans_id
            JOIN customer ON predm.customer_id = customer.id
            JOIN parts p ON pi.parts_id = p.id
            JOIN partstype ON p.partstype_id = partstype.id
            WHERE predm.ordnumber IS NOT NULL
              AND predm.ordnumber LIKE 'SO%'
              AND customer.saleperson_id IN ($in)
              AND predm.transdate >= ?
              AND predm.transdate <= ?
              AND pi.unit != ' '
              $productWhere
        ";

        return array_map(fn($row) => (string)$row->id, DB::connection($this->conn)->select($sql, $bindings));
    }

    private function getDashboardTypeDrivers(string $groupCode, int $year, int $month, ?int $limit = 8, string $product = ''): array
    {
        $salesIds = $this->getSalesIdsByGroupCode($groupCode);
        $isD8 = $groupCode === 'D8';
        $range = $this->monthRange($year, $month);
        $previousRange = $this->monthRange($year - 1, $month);

        return $this->getTopChangeDrivers($salesIds, $range['from'], $range['to'], $previousRange['from'], $previousRange['to'], $isD8, 'type', $limit, $product);
    }

    private function getTopChangeDrivers(array $salesIds, string $from, string $to, string $previousFrom, string $previousTo, bool $isD8, string $mode, ?int $limit = 8, string $product = ''): array
    {
        $current = $this->getDriverTotals($salesIds, $from, $to, $isD8, $mode, $product);
        $previous = $this->getDriverTotals($salesIds, $previousFrom, $previousTo, $isD8, $mode, $product);
        $keys = array_unique(array_merge(array_keys($current), array_keys($previous)));
        $rows = [];

        foreach ($keys as $key) {
            $currentValue = (float)($current[$key] ?? 0);
            $previousValue = (float)($previous[$key] ?? 0);
            $rows[] = [
                'label' => $key,
                'current' => $currentValue,
                'previous' => $previousValue,
                'diff' => round($currentValue - $previousValue, 2),
                'change_percent' => $this->percentChange($currentValue, $previousValue),
            ];
        }

        usort($rows, fn($a, $b) => abs($b['diff']) <=> abs($a['diff']));

        return $limit === null ? $rows : array_slice($rows, 0, $limit);
    }

    private function getDriverTotals(array $salesIds, string $from, string $to, bool $isD8, string $mode, string $product = ''): array
    {
        $in = implode(',', array_fill(0, count($salesIds), '?'));
        $metricExpr = $isD8 ? 'pi.sellprice * pi.qty' : $this->weightedQtyExpr('pi');
        $labelExpr = $mode === 'type'
            ? "COALESCE(NULLIF(TRIM(partstype.description), ''), 'UNKNOWN')"
            : "COALESCE(NULLIF(TRIM(customer.name), ''), customer.customernumber, 'UNKNOWN')";
        $productWhere = $product === '' ? '' : 'AND partstype.description = ?';
        $bindings = array_merge($salesIds, [$from, $to]);
        if ($product !== '') {
            $bindings[] = $product;
        }

        $sql = "
            SELECT
                $labelExpr AS label,
                ROUND(COALESCE(SUM($metricExpr), 0), 2) AS total
            FROM predm
            JOIN predmitems pi ON predm.id = pi.trans_id
            JOIN customer ON predm.customer_id = customer.id
            JOIN parts p ON pi.parts_id = p.id
            JOIN partstype ON p.partstype_id = partstype.id
            WHERE predm.ordnumber IS NOT NULL
              AND predm.ordnumber LIKE 'SO%'
              AND customer.saleperson_id IN ($in)
              AND predm.transdate >= ?
              AND predm.transdate <= ?
              AND pi.unit != ' '
              $productWhere
            GROUP BY $labelExpr
        ";

        $rows = DB::connection($this->conn)->select($sql, $bindings);
        $totals = [];

        foreach ($rows as $row) {
            $totals[(string)$row->label] = (float)$row->total;
        }

        return $totals;
    }

    private function monthRange(int $year, int $month): array
    {
        $date = Carbon::create($year, $month, 1);

        return [
            'from' => $date->copy()->startOfMonth()->toDateString(),
            'to' => $date->copy()->endOfMonth()->toDateString(),
        ];
    }

    private function workingDayPace(int $year, int $month, float $total): array
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $today = Carbon::today('Asia/Bangkok');
        $elapsedEnd = ($today->year === $year && $today->month === $month) ? ($today->lt($end) ? $today : $end) : $end;
        $elapsed = $this->countWorkingDays($start, $elapsedEnd);
        $totalWorking = $this->countWorkingDays($start, $end);
        $average = $elapsed > 0 ? $total / $elapsed : 0.0;

        return [
            'elapsed_working_days' => $elapsed,
            'total_working_days' => $totalWorking,
            'average_per_day' => round($average, 2),
            'projected_total' => round($average * $totalWorking, 2),
        ];
    }

    private function countWorkingDays(Carbon $start, Carbon $end): int
    {
        $days = 0;
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            if (!$d->isWeekend()) {
                $days++;
            }
        }

        return $days;
    }

    private function getDivisionYearTrend(array $salesIds, int $year, bool $isD8, string $product = ''): array
    {
        $from = Carbon::create($year, 1, 1)->startOfYear()->toDateString();
        $to = Carbon::create($year, 12, 1)->endOfYear()->toDateString();
        $in = implode(',', array_fill(0, count($salesIds), '?'));
        $metricExpr = $isD8 ? 'pi.sellprice * pi.qty' : $this->weightedQtyExpr('pi');
        $productWhere = $product === '' ? '' : 'AND partstype.description = ?';
        $bindings = array_merge($salesIds, [$from, $to]);
        if ($product !== '') {
            $bindings[] = $product;
        }

        $sql = "
            SELECT
                EXTRACT(MONTH FROM predm.transdate) AS month_number,
                ROUND(COALESCE(SUM($metricExpr), 0), 2) AS total
            FROM predm
            JOIN predmitems pi ON predm.id = pi.trans_id
            JOIN customer ON predm.customer_id = customer.id
            JOIN parts p ON pi.parts_id = p.id
            JOIN partstype ON p.partstype_id = partstype.id
            WHERE predm.ordnumber IS NOT NULL
              AND predm.ordnumber LIKE 'SO%'
              AND customer.saleperson_id IN ($in)
              AND predm.transdate >= ?
              AND predm.transdate <= ?
              AND pi.unit != ' '
              $productWhere
            GROUP BY month_number
        ";

        $rows = DB::connection($this->conn)->select($sql, $bindings);
        $trend = array_fill(1, 12, null);

        foreach ($rows as $row) {
            $trend[(int)$row->month_number] = (float)$row->total;
        }

        return array_values($trend);
    }

    private function buildDeliveryMonthDetailUrls(string $groupCode, int $year, string $product = ''): array
    {
        $urls = array_fill(0, 12, null);

        for ($month = 1; $month <= 12; $month++) {
            $range = $this->monthRange($year, $month);
            $urls[$month - 1] = route('wos.sales_unit_summary.detail_group', [
                'group_code' => $groupCode,
                'type_name' => $product,
                'from' => $range['from'],
                'to' => $range['to'],
            ]);
        }

        return $urls;
    }

    private function buildDashboardInsight(string $unit, ?float $changePercent, array $customerDrivers, array $typeDrivers, array $customerMix): string
    {
        if ($changePercent === null && empty($customerDrivers) && empty($typeDrivers)) {
            return 'ข้อมูลไม่เพียงพอสำหรับสรุป Insight';
        }

        $direction = ((float)$changePercent) < 0 ? 'ลดลง' : 'เพิ่มขึ้น';
        $changeText = $changePercent === null ? 'เป็นรายการใหม่เมื่อเทียบกับปีก่อน' : $direction . ' ' . number_format(abs((float)$changePercent), 2) . '%';
        $mainDriver = collect($typeDrivers)->first(fn($row) => (float)($row['diff'] ?? 0) !== 0.0)
            ?: collect($customerDrivers)->first(fn($row) => (float)($row['diff'] ?? 0) !== 0.0);
        $driverText = $mainDriver
            ? ' สาเหตุหลักมาจาก ' . ($mainDriver['label'] ?? '-') . ' ' . (((float)($mainDriver['diff'] ?? 0) < 0) ? 'ลดลงมากที่สุด' : 'เพิ่มขึ้นมากที่สุด') . '.'
            : '';
        $newCustomerCount = (int)($customerMix['new'] ?? 0);
        $newText = $newCustomerCount > 0 ? ' มีลูกค้าใหม่ ' . number_format($newCustomerCount) . ' ราย.' : '';
        $newText = '';
        return 'ยอด ' . $unit . ' เดือนนี้' . $changeText . ' เมื่อเทียบกับเดือนเดียวกันของปีก่อน.' . $driverText . $newText;
    }

    private function thaiShortMonths(): array
    {
        return [
            1 => 'ม.ค.',
            2 => 'ก.พ.',
            3 => 'มี.ค.',
            4 => 'เม.ย.',
            5 => 'พ.ค.',
            6 => 'มิ.ย.',
            7 => 'ก.ค.',
            8 => 'ส.ค.',
            9 => 'ก.ย.',
            10 => 'ต.ค.',
            11 => 'พ.ย.',
            12 => 'ธ.ค.',
        ];
    }

    private function thaiMonthYearLabel(int $year, int $month): string
    {
        $months = [
            1 => 'มกราคม',
            2 => 'กุมภาพันธ์',
            3 => 'มีนาคม',
            4 => 'เมษายน',
            5 => 'พฤษภาคม',
            6 => 'มิถุนายน',
            7 => 'กรกฎาคม',
            8 => 'สิงหาคม',
            9 => 'กันยายน',
            10 => 'ตุลาคม',
            11 => 'พฤศจิกายน',
            12 => 'ธันวาคม',
        ];

        return ($months[$month] ?? (string)$month) . ' ' . $year;
    }

    private function getDivisionTypeCompare(array $salesIds, int $year, int $month, int $previousYear, bool $isD8, ?int $limit = 12, string $product = ''): array
    {
        $current = $this->getDivisionTypeTotals($salesIds, $year, $month, $isD8, $product);
        $previous = $this->getDivisionTypeTotals($salesIds, $previousYear, $month, $isD8, $product);
        return $this->buildTypeCompareRows($current, $previous, $limit);
    }

    private function getDivisionTypePeriodCompare(array $salesIds, string $from, string $to, string $previousFrom, string $previousTo, bool $isD8, string $product = '', ?int $limit = null): array
    {
        $current = $this->getDriverTotals($salesIds, $from, $to, $isD8, 'type', $product);
        $previous = $this->getDriverTotals($salesIds, $previousFrom, $previousTo, $isD8, 'type', $product);
        return $this->buildTypeCompareRows($current, $previous, $limit);
    }

    private function buildTypeCompareRows(array $current, array $previous, ?int $limit = 12): array
    {
        $types = array_unique(array_merge(array_keys($current), array_keys($previous)));
        $rows = [];

        foreach ($types as $type) {
            $currentValue = (float)($current[$type] ?? 0);
            $previousValue = (float)($previous[$type] ?? 0);
            $rows[] = [
                'type' => $type,
                'current' => $currentValue,
                'previous' => $previousValue,
                'change_percent' => $this->percentChange($currentValue, $previousValue),
            ];
        }

        usort($rows, fn($a, $b) => $b['current'] <=> $a['current']);

        return $limit === null ? $rows : array_slice($rows, 0, $limit);
    }

    private function getDivisionTypeTotals(array $salesIds, int $year, int $month, bool $isD8, string $product = ''): array
    {
        $from = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        $in = implode(',', array_fill(0, count($salesIds), '?'));
        $metricExpr = $isD8 ? 'pi.sellprice * pi.qty' : $this->weightedQtyExpr('pi');
        $productWhere = $product === '' ? '' : 'AND partstype.description = ?';
        $bindings = array_merge($salesIds, [$from, $to]);
        if ($product !== '') {
            $bindings[] = $product;
        }

        $sql = "
            SELECT
                partstype.description AS type_name,
                ROUND(COALESCE(SUM($metricExpr), 0), 2) AS total
            FROM predm
            JOIN predmitems pi ON predm.id = pi.trans_id
            JOIN customer ON predm.customer_id = customer.id
            JOIN parts p ON pi.parts_id = p.id
            JOIN partstype ON p.partstype_id = partstype.id
            WHERE predm.ordnumber IS NOT NULL
              AND predm.ordnumber LIKE 'SO%'
              AND customer.saleperson_id IN ($in)
              AND predm.transdate >= ?
              AND predm.transdate <= ?
              AND pi.unit != ' '
              $productWhere
            GROUP BY partstype.description
        ";

        $rows = DB::connection($this->conn)->select($sql, $bindings);
        $totals = [];

        foreach ($rows as $row) {
            $totals[trim((string)$row->type_name) ?: 'UNKNOWN'] = (float)$row->total;
        }

        return $totals;
    }

    private function percentChange(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return $current == 0.0 ? 0.0 : null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 2);
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

    private function exportSummaryCsv(array $rows, array $typeColumns, string $from, string $to): StreamedResponse
    {
        $fileName = 'sales_unit_summary_' . $from . '_to_' . $to . '.csv';

        return response()->streamDownload(function () use ($rows, $typeColumns) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($handle, array_merge(['Group'], $typeColumns, ['Grand Total', 'FG GRATING']));

            foreach ($rows as $row) {
                $line = [$row['group_name']];
                foreach ($typeColumns as $typeColumn) {
                    $line[] = (float)($row['cells'][$typeColumn] ?? 0);
                }
                $line[] = (float)($row['grand_total'] ?? 0);
                $line[] = (float)($row['fg_grating'] ?? 0);
                fputcsv($handle, $line);
            }

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function sortTypeColumns(array $columns): array
    {
        $columns = array_values(array_filter(
            array_map(fn($type) => trim((string)$type), $columns),
            fn($type) => $type !== '' && $type !== 'FG GRATING'
        ));
        $columns = array_values(array_unique($columns));
        $preferred = $this->preferredTypeOrder;

        usort($columns, function ($a, $b) use ($preferred) {
            $ia = array_search($a, $preferred, true);
            $ib = array_search($b, $preferred, true);

            if ($ia === $ib) {
                return strcmp($a, $b);
            }

            return (($ia === false) ? 999 : $ia) <=> (($ib === false) ? 999 : $ib);
        });

        return $columns;
    }

    private function getProductOptions(int $year): array
    {
        $from = Carbon::create($year, 1, 1)->startOfYear()->toDateString();
        $to = Carbon::create($year, 12, 31)->endOfYear()->toDateString();

        $sql = "
            SELECT DISTINCT COALESCE(NULLIF(TRIM(partstype.description), ''), 'UNKNOWN') AS type_name
            FROM predm
            JOIN predmitems pi ON predm.id = pi.trans_id
            JOIN customer ON predm.customer_id = customer.id
            JOIN parts p ON pi.parts_id = p.id
            JOIN partstype ON p.partstype_id = partstype.id
            WHERE predm.ordnumber IS NOT NULL
              AND predm.ordnumber LIKE 'SO%'
              AND customer.saleperson_id IN (1506,1507,1431,1433,1434,1435,1436,528615586)
              AND predm.transdate >= ?
              AND predm.transdate <= ?
              AND pi.unit != ' '
        ";

        $rows = DB::connection($this->conn)->select($sql, [$from, $to]);

        return $this->sortTypeColumns(array_map(
            fn($row) => trim((string)($row->type_name ?? '')) ?: 'UNKNOWN',
            $rows
        ));
    }

    private function getSalesIdsByGroupCode(string $groupCode): array
    {
        $ids = [];

        foreach ($this->salesToGroupMap as $salesId => $code) {
            if ($code === $groupCode) {
                $ids[] = (int)$salesId;
            }
        }

        return $ids;
    }
}
