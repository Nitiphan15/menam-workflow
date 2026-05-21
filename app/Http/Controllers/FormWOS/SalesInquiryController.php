<?php

namespace App\Http\Controllers\FormWOS;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Cache;

class SalesInquiryController extends Controller
{
    private array $requesterIds = [1506, 1507, 1431, 1433, 1434, 1435, 1436, 528615586];
    private string $conn = 'pgsqlw';

    /** requester_id -> display name (ชื่อคน) */
    private array $salesDisplayNameMap = [
        1506 => 'คุณดิลก สอนแจ้ง',
        1507 => 'คุณปรียาพรรณ ทิพหา',
        1431 => 'คุณภควดี เรืองเชื้อเหมือน',
        1433 => 'คุณธัธลิญา พงษ์ศิริ',
        1434 => 'คุณสุรศักดิ์ เลียงมงคลการ',
        1435 => 'คุณศิรินภา สุวรรณมา',
        1436 => 'คุณสาธิต หมื่นนรินทร์',
        528615586 => 'คุณวรเดชา วัธนกุล',
    ];

    private array $targetMonth = [
        1506 => 60,
        1507 => 240,
        1431 => 68,
        1433 => 85,
        1434 => 205,
        1435 => 101,
        1436 => 1700000,
        528615586 => 141,
    ];

    private array $salesCodeToRequesterId = [
        'export sales 01' => 1506,
        'export sales 02' => 1507,
        'sales person 03' => 1431,
        'sales person 05' => 1433,
        'sales person 06' => 1434,
        'sales person 07' => 1435,
        'sales person 08' => 1436,
        'sales person 09' => 528615586,
    ];

    /** requester_id -> group_code */
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
        'D1' => 'D1 - คุณดิลก + คุณขวัญเรือน',
        'D2' => 'D2 - คุณปรียาพรรณ + คุณนิตยา',
        'D3' => 'D3 - คุณภควดี + คุณธนัชชา',
        'D5' => 'D5 - คุณธัธลิญา + คุณเฌอร์ลิญา',
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

    private function weightedQtyExpr(string $itemAlias): string
    {
        return "{$itemAlias}.qty * CASE WHEN p.ref_unit = '03' THEN p.ref_unit_qty ELSE 1 END";
    }

    public function index(Request $request)
    {
        $from = $request->query('from', '2026-01-01');
        $to   = $request->query('to', Carbon::now()->toDateString());

        $monthBlocks = $this->getSummaryData($from, $to);

        return view('formwos.sales_weekly.index', [
            'monthBlocks' => $monthBlocks,
            'filters' => [
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }

    public function dashboard(Request $request)
    {
        $year = (int)$request->query('year', Carbon::now()->year);
        $month = (int)$request->query('month', Carbon::now()->month);
        $groupCodes = $this->sanitizeDashboardDivisions($request->query('division', ['D1']));
        $groupCode = $groupCodes[0];

        if ($year < 2000 || $year > 2100) {
            $year = Carbon::now()->year;
        }

        if ($month < 1 || $month > 12) {
            $month = Carbon::now()->month;
        }

        $dashboard = $this->buildDivisionDashboardData($groupCode, $year, $month);
        $comparisonRows = $this->buildDivisionComparisonRows($groupCodes, $year, $month);
        $dashboard['selected_division_codes'] = $groupCodes;
        $dashboard['comparison_rows'] = $comparisonRows;
        $dashboard['division_trend_datasets'] = $this->buildDivisionTrendDatasets($comparisonRows, $dashboard['unit'], $year);
        $dashboard['trend_note'] = $this->buildDivisionTrendNote($comparisonRows, $dashboard['unit']);

        return view('formwos.sales_weekly.dashboard', [
            'dashboard' => $dashboard,
            'divisionOptions' => $this->groupLabelMap,
            'filters' => [
                'division' => $groupCodes,
                'year' => $year,
                'month' => $month,
            ],
        ]);
    }

    public function dashboardPdf(Request $request)
    {
        $year = (int)$request->query('year', Carbon::now()->year);
        $month = (int)$request->query('month', Carbon::now()->month);
        $groupCodes = $this->sanitizeDashboardDivisions($request->query('division', ['D1']));
        $groupCode = $groupCodes[0];

        if ($year < 2000 || $year > 2100) {
            $year = Carbon::now()->year;
        }

        if ($month < 1 || $month > 12) {
            $month = Carbon::now()->month;
        }

        $dashboard = $this->buildDivisionDashboardData($groupCode, $year, $month);
        $comparisonRows = $this->buildDivisionComparisonRows($groupCodes, $year, $month);
        $dashboard['selected_division_codes'] = $groupCodes;
        $dashboard['comparison_rows'] = $comparisonRows;
        $dashboard['division_trend_datasets'] = $this->buildDivisionTrendDatasets($comparisonRows, $dashboard['unit'], $year);
        $dashboard['trend_note'] = $this->buildDivisionTrendNote($comparisonRows, $dashboard['unit']);
        $dashboard['all_type_drivers'] = $this->getDashboardTypeDrivers($groupCode, $year, $month, null);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.wos-sales-weekly-dashboard', [
            'dashboard' => $dashboard,
            'divisionOptions' => $this->groupLabelMap,
            'filters' => [
                'division' => $groupCodes,
                'year' => $year,
                'month' => $month,
            ],
            'generatedAt' => Carbon::now('Asia/Bangkok'),
        ])->setPaper('a4', 'landscape');

        $fileName = 'WOS-Delivery-Volume-Dashboard-' . $groupCode . '-' . sprintf('%04d-%02d', $year, $month) . '.pdf';

        return $pdf->download($fileName);
    }

    public function detail(Request $request, $salesId)
    {
        $from = $request->query('from', '2026-01-01');
        $to   = $request->query('to', Carbon::now()->toDateString());
        $month = $request->query('month');

        // salesId เป็น requester_id (ตัวเลข)
        $requesterId = (int) $salesId;
        if ($requesterId <= 0) {
            abort(404);
        }

        $qtyExpr = $this->weightedQtyExpr('oi');
        $sql = "
        WITH sale1 AS (
            SELECT
                oe.requester_id,
                cus.saleperson_id,
                oe.ordnumber,
                oi.transdate,
                p.partnumber,
                p.unit,
                p.ref_unit,
                p.ref_unit_qty,
                p.purchase_unit,
                p.purchase_unit_qty,
                cus.customernumber,
                cus.name,
                oi.description,
                oi.unit AS unit2,
                $qtyExpr AS qty,
                oi.sellprice,
                oi.sellprice * oi.qty AS bath,
                oi.reqdate,
                oe.custponumber,
                pt.description AS partstype,  
                EXTRACT(MONTH FROM oi.transdate) AS month_number
            FROM orderitems oi
            JOIN oe ON oi.trans_id = oe.id
            JOIN customer cus ON oe.customer_id = cus.id
            JOIN parts p ON oi.parts_id = p.id
            JOIN partstype pt ON p.partstype_id = pt.id 
            WHERE
                oe.ordnumber IS NOT NULL
                AND oe.ordnumber LIKE 'SO%'
                AND oe.requester_id = ?
                AND oi.transdate >= ?
                AND oi.transdate <= ?
                AND oi.unit <> ' '
                AND p.partnumber LIKE 'F%'
                AND UPPER(TRIM(oe.custponumber)) NOT IN ('SAMPLE', 'FORECAST')
                AND pt.id <> 56
        )
        SELECT
            requester_id,
            saleperson_id,
            ordnumber,
            transdate,
            partnumber,
            partstype, 
            ref_unit,
            unit,
            ref_unit_qty,
            purchase_unit,
            purchase_unit_qty,
            customernumber,
            employee.name AS requester_name,
            sale1.name AS customer_name,
            description,
            unit2,
            qty,
            sellprice,
            bath,
            reqdate,
            custponumber,
            month_number
        FROM sale1
        JOIN employee ON sale1.requester_id = employee.id
        ";

        $bindings = [$requesterId, $from, $to];

        if ($month !== null && $month !== '') {
            $sql .= " WHERE month_number = ? ";
            $bindings[] = (int) $month;
        }

        $sql .= " ORDER BY transdate, ordnumber ";

        $items = DB::connection($this->conn)->select($sql, $bindings);

        $sumQty = 0.0;
        $sumBath = 0.0;

        foreach ($items as $it) {
            $it->requester_name = $this->salesDisplayNameMap[$it->requester_id] ?? $it->requester_name;
            $sumQty  += (float) $it->qty;
            $sumBath += (float) $it->bath;
        }

        $g = $this->salesToGroupMap[$requesterId] ?? null;
        $label = $g ? ($this->groupLabelMap[$g] ?? null) : null;
        $divisionName = $label ? preg_replace('/^D\d+\s*-\s*/u', '', $label) : ($this->salesDisplayNameMap[$requesterId] ?? ('ID ' . $requesterId));

        $isD8 = (($this->salesToGroupMap[$requesterId] ?? '') === 'D8');

        return view('formwos.sales_weekly.detail', [
            'salesId' => $requesterId,
            'divisionName' => $divisionName,
            'isD8' => $isD8,
            'items' => $items,
            'sumQty' => $sumQty,
            'sumBath' => $sumBath,
            'filters' => [
                'from' => $from,
                'to' => $to,
                'month' => $month,
            ],
        ]);
    }


    public function export(Request $request)
    {
        $from = $request->query('from', '2026-01-01');
        $to   = $request->query('to', Carbon::now()->toDateString());

        $monthBlocks = $this->getSummaryData($from, $to);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0); // ลบชีตเปล่าเริ่มต้น

        foreach ($monthBlocks as $blk) {
            $mNo = (int)$blk['month_number'];
            $mName = trim((string)$blk['month_name']);
            $mTag = sprintf('M%02d', $mNo);

            // =======================
            // 1) SUMMARY SHEET
            // =======================
            $shSum = $spreadsheet->createSheet();
            $shSum->setTitle(substr($mTag . ' Summary', 0, 31));

            $headers = ['ฝ่าย/กลุ่มฝ่ายขาย', 'สัปดาห์ 1', 'สัปดาห์ 2', 'สัปดาห์ 3', 'สัปดาห์ 4', 'สัปดาห์ 5', 'รวมทั้งเดือน', 'หน่วย'];
            $shSum->fromArray($headers, null, 'A1');

            $r = 2;
            foreach ($blk['rows'] as $row) {
                $isBaht = (bool)$row->is_baht;

                // หน้าเว็บ: D8 แสดง *1000 (เพราะ summary query /1000)
                $mul = $isBaht ? 1000 : 1;

                $shSum->setCellValue("A$r", $row->group_name);
                $shSum->setCellValue("B$r", (float)$row->qtyw1 * $mul);
                $shSum->setCellValue("C$r", (float)$row->qtyw2 * $mul);
                $shSum->setCellValue("D$r", (float)$row->qtyw3 * $mul);
                $shSum->setCellValue("E$r", (float)$row->qtyw4 * $mul);
                $shSum->setCellValue("F$r", (float)$row->qtyw5 * $mul);
                $shSum->setCellValue("G$r", (float)$row->total * $mul);
                $shSum->setCellValue("H$r", $isBaht ? 'บาท' : 'ตัน');
                $r++;
            }

            // =======================
            // 2) DETAIL SHEET (หลัง Summary)
            // =======================
            $detailRows = $this->getDetailRowsByMonth($from, $to, $mNo);

            $shDet = $spreadsheet->createSheet();
            $shDet->setTitle(substr($mTag . ' Detail', 0, 31));

            $detHeaders = [
                'ฝ่าย/กลุ่มฝ่ายขาย',        // division
                'ผู้ขาย',                  // sales_name
                'วันที่ขาย',
                'เลขที่ SO',
                'ลูกค้า',
                'รหัสสินค้า',
                'รายละเอียดสินค้า',
                'ชนิดงาน',
                'หน่วย',
                'ปริมาณ',
                'ราคาขาย',
                'มูลค่า (บาท)',
                'กำหนดส่ง',
                'เลขที่ PO ลูกค้า',
            ];
            $shDet->fromArray($detHeaders, null, 'A1');

            $rr = 2;
            foreach ($detailRows as $it) {
                $shDet->setCellValue("A$rr", $it->division ?? '');
                $shDet->setCellValue("B$rr", $it->sales_name ?? '');
                $shDet->setCellValue("C$rr", $it->transdate ? Carbon::parse($it->transdate)->format('Y-m-d') : '');
                $shDet->setCellValue("D$rr", $it->ordnumber ?? '');
                $shDet->setCellValue("E$rr", trim(($it->customer_name ?? '') . ' (' . ($it->customernumber ?? '') . ')'));
                $shDet->setCellValue("F$rr", $it->partnumber ?? '');
                $shDet->setCellValue("G$rr", $it->description ?? '');
                $shDet->setCellValue("H$rr", $it->partstype ?? '');
                $shDet->setCellValue("I$rr", $it->unit2 ?? '');
                $shDet->setCellValue("J$rr", (float)($it->qty ?? 0));
                $shDet->setCellValue("K$rr", (float)($it->sellprice ?? 0));
                $shDet->setCellValue("L$rr", (float)($it->bath ?? 0));
                $shDet->setCellValue("M$rr", $it->reqdate ? Carbon::parse($it->reqdate)->format('Y-m-d') : '');
                $shDet->setCellValue("N$rr", $it->custponumber ?? '');
                $rr++;
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        $fileName = "SalesWeekly_{$from}_to_{$to}.xlsx";

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment;filename="' . $fileName . '"',
            'Cache-Control' => 'max-age=0',
        ]);
    }
    private function getSummaryData(string $from, string $to): array
    {
        $in = implode(',', array_fill(0, count($this->requesterIds), '?'));
        $metricExpr = "CASE WHEN requester_id = 1436 THEN bath ELSE qty END";
        $qtyExpr = $this->weightedQtyExpr('oi');

        $sql = "
        WITH sale1 AS (
            SELECT
                oe.requester_id,
                oi.transdate,
                $qtyExpr AS qty,
                oi.sellprice * oi.qty AS bath,
                EXTRACT(MONTH FROM oi.transdate) AS month_number,
                TO_CHAR(oi.transdate, 'Month') AS month_name,
                CEIL(EXTRACT(DAY FROM oi.transdate) / 7.0) AS week_of_month
            FROM orderitems oi
            JOIN oe ON oi.trans_id = oe.id
            JOIN customer cus ON oe.customer_id = cus.id
            JOIN parts p ON oi.parts_id = p.id
            JOIN partstype pt ON p.partstype_id = pt.id
            WHERE
                pt.id <> 56
                AND oe.ordnumber IS NOT NULL
                AND oe.ordnumber LIKE 'SO%'
                AND oe.requester_id IN ($in)
                AND oi.transdate >= ?
                AND oi.transdate <= ?
                AND oi.unit <> ' '
                AND p.partnumber LIKE 'F%'
                AND UPPER(TRIM(oe.custponumber)) NOT IN ('SAMPLE', 'FORECAST')
                
        )
        SELECT
            month_number,
            month_name,
            requester_id,
            ROUND(SUM(CASE WHEN week_of_month = 1 THEN ($metricExpr) END)/1000, 2) AS qtyw1,
            ROUND(SUM(CASE WHEN week_of_month = 2 THEN ($metricExpr) END)/1000, 2) AS qtyw2,
            ROUND(SUM(CASE WHEN week_of_month = 3 THEN ($metricExpr) END)/1000, 2) AS qtyw3,
            ROUND(SUM(CASE WHEN week_of_month = 4 THEN ($metricExpr) END)/1000, 2) AS qtyw4,
            ROUND(SUM(CASE WHEN week_of_month = 5 THEN ($metricExpr) END)/1000, 2) AS qtyw5,
            ROUND(SUM(($metricExpr))/1000, 2) AS total
        FROM sale1
        GROUP BY month_number, month_name, requester_id
        ORDER BY month_number, requester_id
        ";

        $bindings = array_merge($this->requesterIds, [$from, $to]);
        $rows = DB::connection($this->conn)->select($sql, $bindings);

        // ใส่ group + name + requester_ids
        $rows = array_map(function ($r) {
            $r->month_name = trim((string)$r->month_name);
            $group = $this->salesToGroupMap[$r->requester_id] ?? '-';
            $r->group_code = $group;
            $r->group_name = $this->groupLabelMap[$group] ?? $group;
            $r->is_baht = ($group === 'D8');
            return $r;
        }, $rows);

        // รวมรายเดือน + รายกลุ่ม
        $agg = []; // [month][group] => row
        foreach ($rows as $r) {
            $m = (int)$r->month_number;
            $g = (string)$r->group_code;

            if (!isset($agg[$m][$g])) {
                $agg[$m][$g] = (object)[
                    'month_number' => $m,
                    'month_name'   => $r->month_name,
                    'group_code'   => $g,
                    'group_name'   => $r->group_name,
                    'requester_ids' => [],
                    'target_month' => $g === 'D8'
                        ? ((float)($this->targetMonth[(int)$r->requester_id] ?? 0) / 1000.0)
                        : (float)($this->targetMonth[(int)$r->requester_id] ?? 0),
                    'is_baht'      => ($g === 'D8'),
                    'qtyw1' => 0.0,
                    'qtyw2' => 0.0,
                    'qtyw3' => 0.0,
                    'qtyw4' => 0.0,
                    'qtyw5' => 0.0,
                    'total' => 0.0,
                ];
            }

            $agg[$m][$g]->requester_ids[] = (int)$r->requester_id;
            $agg[$m][$g]->requester_ids = array_values(array_unique($agg[$m][$g]->requester_ids));

            foreach (['qtyw1', 'qtyw2', 'qtyw3', 'qtyw4', 'qtyw5', 'total'] as $k) {
                $agg[$m][$g]->{$k} += (float)($r->{$k} ?? 0);
            }
        }

        // สร้าง monthBlocks + TOTAL ต่อเดือน (TOTAL อยู่ก่อน D8 เสมอ)
        krsort($agg);
        $monthBlocks = [];

        foreach ($agg as $m => $byGroup) {
            $totalRow = (object)[
                'month_number' => $m,
                'month_name'   => collect($byGroup)->first()->month_name ?? '',
                'group_code'   => 'TOTAL',
                'group_name'   => 'TOTAL',
                'requester_ids' => [],
                'target_month' => 900.0,
                'is_baht'      => false,
                'qtyw1' => 0.0,
                'qtyw2' => 0.0,
                'qtyw3' => 0.0,
                'qtyw4' => 0.0,
                'qtyw5' => 0.0,
                'total' => 0.0,
            ];

            foreach ($byGroup as $g => $row) {
                if ($g === 'D8') continue; // TOTAL ตัน ไม่รวม D8
                foreach (['qtyw1', 'qtyw2', 'qtyw3', 'qtyw4', 'qtyw5', 'total'] as $k) {
                    $totalRow->{$k} += (float)$row->{$k};
                }
            }

            $list = array_values($byGroup);
            $list[] = $totalRow;

            usort($list, function ($a, $b) {
                $oa = array_search($a->group_code, $this->groupOrder, true);
                $ob = array_search($b->group_code, $this->groupOrder, true);
                $oa = ($oa === false) ? 999 : $oa;
                $ob = ($ob === false) ? 999 : $ob;
                return $oa <=> $ob;
            });

            $monthBlocks[] = [
                'month_number' => $m,
                'month_name'   => $totalRow->month_name,
                'rows'         => $list,
            ];
        }

        return $monthBlocks;
    }

    private function buildDivisionDashboardData(string $groupCode, int $year, int $month): array
    {
        $salesIds = $this->getRequesterIdsByGroupCode($groupCode);
        $isD8 = $groupCode === 'D8';
        $target = $this->targetForGroup($groupCode);
        $range = $this->monthRange($year, $month);
        $previousRange = $this->monthRange($year - 1, $month);

        $current = $this->getDivisionMonthStats($salesIds, $year, $month, $isD8);
        $previous = $this->getDivisionMonthStats($salesIds, $year - 1, $month, $isD8);
        $ytdCurrent = $this->getDivisionPeriodStats($salesIds, Carbon::create($year, 1, 1)->toDateString(), $range['to'], $isD8);
        $ytdPrevious = $this->getDivisionPeriodStats($salesIds, Carbon::create($year - 1, 1, 1)->toDateString(), $previousRange['to'], $isD8);
        $trendCurrent = $this->getDivisionYearTrend($salesIds, $year, $isD8);
        $trendPrevious = $this->getDivisionYearTrend($salesIds, $year - 1, $isD8);
        $customerMix = $this->getCustomerMix($salesIds, $range['from'], $range['to'], $previousRange['from'], $previousRange['to']);
        $customerDrivers = $this->getTopChangeDrivers($salesIds, $range['from'], $range['to'], $previousRange['from'], $previousRange['to'], $isD8, 'customer');
        $typeDrivers = $this->getDashboardTypeDrivers($groupCode, $year, $month, 8);

        $change = $this->percentChange($current['total'], $previous['total']);
        $working = $this->workingDayPace($year, $month, (float)$current['total']);
        $targetPaceValue = null;
        $paceGap = null;
        $pacePercent = null;
        if ($target > 0 && ($working['total_working_days'] ?? 0) > 0) {
            $pacePercent = round(($working['elapsed_working_days'] / $working['total_working_days']) * 100, 2);
            $targetPaceValue = round($target * ($working['elapsed_working_days'] / $working['total_working_days']), 2);
            $paceGap = round((float)$current['total'] - $targetPaceValue, 2);
        }
        $requesterId = $salesIds[0] ?? 0;
        $monthLabels = [];
        for ($i = 1; $i <= 12; $i++) {
            $monthLabels[] = $this->thaiShortMonths()[$i] ?? (string)$i;
        }
        $monthDetailUrls = $this->buildWeeklyMonthDetailUrls($requesterId, $year);

        return [
            'division_code' => $groupCode,
            'division_name' => $this->groupLabelMap[$groupCode] ?? $groupCode,
            'unit' => $isD8 ? 'Baht' : 'Ton',
            'current' => $current,
            'previous' => $previous,
            'ytd_current' => $ytdCurrent,
            'ytd_previous' => $ytdPrevious,
            'ytd_change_percent' => $this->percentChange($ytdCurrent['total'], $ytdPrevious['total']),
            'working_day_pace' => $working,
            'target' => $target,
            'target_achievement_percent' => $target > 0 ? round(((float)$current['total'] / $target) * 100, 2) : null,
            'target_pace_percent' => $pacePercent,
            'target_pace_value' => $targetPaceValue,
            'target_pace_gap' => $paceGap,
            'customer_mix' => $customerMix,
            'customer_drivers' => $customerDrivers,
            'type_drivers' => $typeDrivers,
            'detail_url' => $requesterId > 0 ? route('wos.sales_weekly.detail', [
                'salesId' => $requesterId,
                'from' => $range['from'],
                'to' => $range['to'],
                'month' => $month,
            ]) : null,
            'change_percent' => $change,
            'month_labels' => $monthLabels,
            'month_detail_urls' => $monthDetailUrls,
            'current_year_trend' => $trendCurrent,
            'previous_year_trend' => $trendPrevious,
            'insight' => $this->buildDashboardInsight('Ton', $change, $customerDrivers, $typeDrivers, $customerMix),
            'kpis' => [
                'selected_label' => $this->thaiMonthYearLabel($year, $month),
                'previous_label' => $this->thaiMonthYearLabel($year - 1, $month),
                'so_change_percent' => $this->percentChange($current['so_count'], $previous['so_count']),
                'customer_change_percent' => $this->percentChange($current['customer_count'], $previous['customer_count']),
            ],
        ];
    }

    private function sanitizeDashboardDivisions(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : [$raw];
        $valid = [];

        foreach ($values as $value) {
            $code = strtoupper(trim((string)$value));
            if ($code !== '' && array_key_exists($code, $this->groupLabelMap) && !in_array($code, $valid, true)) {
                $valid[] = $code;
            }
        }

        return $valid ?: ['D1'];
    }

    private function buildDivisionComparisonRows(array $groupCodes, int $year, int $month): array
    {
        $rows = [];

        foreach ($groupCodes as $groupCode) {
            $salesIds = $this->getRequesterIdsByGroupCode($groupCode);
            $isD8 = $groupCode === 'D8';
            $range = $this->monthRange($year, $month);
            $previousRange = $this->monthRange($year - 1, $month);
            $current = $this->getDivisionMonthStats($salesIds, $year, $month, $isD8);
            $previous = $this->getDivisionMonthStats($salesIds, $year - 1, $month, $isD8);
            $ytdCurrent = $this->getDivisionPeriodStats($salesIds, Carbon::create($year, 1, 1)->toDateString(), $range['to'], $isD8);
            $ytdPrevious = $this->getDivisionPeriodStats($salesIds, Carbon::create($year - 1, 1, 1)->toDateString(), $previousRange['to'], $isD8);
            $trendCurrent = $this->getDivisionYearTrend($salesIds, $year, $isD8);
            $trendPrevious = $this->getDivisionYearTrend($salesIds, $year - 1, $isD8);
            $requesterId = $salesIds[0] ?? 0;

            $rows[] = [
                'division_code' => $groupCode,
                'division_name' => $this->groupLabelMap[$groupCode] ?? $groupCode,
                'unit' => $isD8 ? 'Baht' : 'Ton',
                'current_total' => (float)($current['total'] ?? 0),
                'previous_total' => (float)($previous['total'] ?? 0),
                'change_percent' => $this->percentChange((float)($current['total'] ?? 0), (float)($previous['total'] ?? 0)),
                'ytd_current_total' => (float)($ytdCurrent['total'] ?? 0),
                'ytd_previous_total' => (float)($ytdPrevious['total'] ?? 0),
                'ytd_change_percent' => $this->percentChange((float)($ytdCurrent['total'] ?? 0), (float)($ytdPrevious['total'] ?? 0)),
                'so_count' => (int)($current['so_count'] ?? 0),
                'customer_count' => (int)($current['customer_count'] ?? 0),
                'current_year_trend' => $trendCurrent,
                'previous_year_trend' => $trendPrevious,
                'current_month_detail_urls' => $this->buildWeeklyMonthDetailUrls($requesterId, $year),
                'previous_month_detail_urls' => $this->buildWeeklyMonthDetailUrls($requesterId, $year - 1),
                'detail_url' => $requesterId > 0 ? route('wos.sales_weekly.detail', [
                    'salesId' => $requesterId,
                    'from' => $range['from'],
                    'to' => $range['to'],
                    'month' => $month,
                ]) : null,
            ];
        }

        return $rows;
    }

    private function buildDivisionTrendDatasets(array $comparisonRows, string $unit, int $year): array
    {
        $colors = ['#2563eb', '#16a34a', '#dc2626', '#9333ea', '#ea580c', '#0891b2', '#4f46e5', '#64748b'];
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

    private function getDivisionMonthStats(array $salesIds, int $year, int $month, bool $isD8): array
    {
        $from = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        $in = implode(',', array_fill(0, count($salesIds), '?'));
        $metricExpr = $isD8 ? 'oi.sellprice * oi.qty' : $this->weightedQtyExpr('oi');
        $divisor = $isD8 ? 1 : 1000;

        $sql = "
            SELECT
                ROUND(COALESCE(SUM($metricExpr), 0) / $divisor, 2) AS total,
                COUNT(DISTINCT oe.ordnumber) AS so_count,
                COUNT(DISTINCT cus.id) AS customer_count,
                ROUND(COALESCE(SUM(CASE WHEN CEIL(EXTRACT(DAY FROM oi.transdate) / 7.0) = 1 THEN $metricExpr END), 0) / $divisor, 2) AS w1,
                ROUND(COALESCE(SUM(CASE WHEN CEIL(EXTRACT(DAY FROM oi.transdate) / 7.0) = 2 THEN $metricExpr END), 0) / $divisor, 2) AS w2,
                ROUND(COALESCE(SUM(CASE WHEN CEIL(EXTRACT(DAY FROM oi.transdate) / 7.0) = 3 THEN $metricExpr END), 0) / $divisor, 2) AS w3,
                ROUND(COALESCE(SUM(CASE WHEN CEIL(EXTRACT(DAY FROM oi.transdate) / 7.0) = 4 THEN $metricExpr END), 0) / $divisor, 2) AS w4,
                ROUND(COALESCE(SUM(CASE WHEN CEIL(EXTRACT(DAY FROM oi.transdate) / 7.0) = 5 THEN $metricExpr END), 0) / $divisor, 2) AS w5
            FROM orderitems oi
            JOIN oe ON oi.trans_id = oe.id
            JOIN customer cus ON oe.customer_id = cus.id
            JOIN parts p ON oi.parts_id = p.id
            JOIN partstype pt ON p.partstype_id = pt.id
            WHERE pt.id <> 56
              AND oe.ordnumber IS NOT NULL
              AND oe.ordnumber LIKE 'SO%'
              AND oe.requester_id IN ($in)
              AND oi.transdate >= ?
              AND oi.transdate <= ?
              AND oi.unit <> ' '
              AND p.partnumber LIKE 'F%'
              AND UPPER(TRIM(oe.custponumber)) NOT IN ('SAMPLE', 'FORECAST')
        ";

        $row = DB::connection($this->conn)->selectOne($sql, array_merge($salesIds, [$from, $to]));

        return [
            'total' => (float)($row->total ?? 0),
            'so_count' => (int)($row->so_count ?? 0),
            'customer_count' => (int)($row->customer_count ?? 0),
            'weeks' => [
                (float)($row->w1 ?? 0),
                (float)($row->w2 ?? 0),
                (float)($row->w3 ?? 0),
                (float)($row->w4 ?? 0),
                (float)($row->w5 ?? 0),
            ],
        ];
    }

    private function getDivisionPeriodStats(array $salesIds, string $from, string $to, bool $isD8): array
    {
        $in = implode(',', array_fill(0, count($salesIds), '?'));
        $metricExpr = $isD8 ? 'oi.sellprice * oi.qty' : $this->weightedQtyExpr('oi');
        $divisor = $isD8 ? 1 : 1000;

        $sql = "
            SELECT
                ROUND(COALESCE(SUM($metricExpr), 0) / $divisor, 2) AS total,
                COUNT(DISTINCT oe.ordnumber) AS so_count,
                COUNT(DISTINCT cus.id) AS customer_count
            FROM orderitems oi
            JOIN oe ON oi.trans_id = oe.id
            JOIN customer cus ON oe.customer_id = cus.id
            JOIN parts p ON oi.parts_id = p.id
            JOIN partstype pt ON p.partstype_id = pt.id
            WHERE pt.id <> 56
              AND oe.ordnumber IS NOT NULL
              AND oe.ordnumber LIKE 'SO%'
              AND oe.requester_id IN ($in)
              AND oi.transdate >= ?
              AND oi.transdate <= ?
              AND oi.unit <> ' '
              AND p.partnumber LIKE 'F%'
              AND UPPER(TRIM(oe.custponumber)) NOT IN ('SAMPLE', 'FORECAST')
        ";

        $row = DB::connection($this->conn)->selectOne($sql, array_merge($salesIds, [$from, $to]));

        return [
            'total' => (float)($row->total ?? 0),
            'so_count' => (int)($row->so_count ?? 0),
            'customer_count' => (int)($row->customer_count ?? 0),
        ];
    }

    private function getCustomerMix(array $salesIds, string $from, string $to, string $previousFrom, string $previousTo): array
    {
        $current = $this->getCustomerSet($salesIds, $from, $to);
        $previous = $this->getCustomerSet($salesIds, $previousFrom, $previousTo);

        return [
            'new' => count(array_diff($current, $previous)),
            'retained' => count(array_intersect($current, $previous)),
            'lost' => count(array_diff($previous, $current)),
            'current_total' => count($current),
            'previous_total' => count($previous),
        ];
    }

    private function getCustomerSet(array $salesIds, string $from, string $to): array
    {
        $in = implode(',', array_fill(0, count($salesIds), '?'));
        $sql = "
            SELECT DISTINCT cus.id
            FROM orderitems oi
            JOIN oe ON oi.trans_id = oe.id
            JOIN customer cus ON oe.customer_id = cus.id
            JOIN parts p ON oi.parts_id = p.id
            JOIN partstype pt ON p.partstype_id = pt.id
            WHERE pt.id <> 56
              AND oe.ordnumber IS NOT NULL
              AND oe.ordnumber LIKE 'SO%'
              AND oe.requester_id IN ($in)
              AND oi.transdate >= ?
              AND oi.transdate <= ?
              AND oi.unit <> ' '
              AND p.partnumber LIKE 'F%'
              AND UPPER(TRIM(oe.custponumber)) NOT IN ('SAMPLE', 'FORECAST')
        ";

        return array_map(fn($row) => (string)$row->id, DB::connection($this->conn)->select($sql, array_merge($salesIds, [$from, $to])));
    }

    private function getDashboardTypeDrivers(string $groupCode, int $year, int $month, ?int $limit = 8): array
    {
        $salesIds = $this->getRequesterIdsByGroupCode($groupCode);
        $isD8 = $groupCode === 'D8';
        $range = $this->monthRange($year, $month);
        $previousRange = $this->monthRange($year - 1, $month);

        $rows = $this->getTopChangeDrivers($salesIds, $range['from'], $range['to'], $previousRange['from'], $previousRange['to'], $isD8, 'type', $limit);

        usort($rows, function ($a, $b) {
            $oa = array_search((string) ($a['label'] ?? ''), $this->preferredTypeOrder, true);
            $ob = array_search((string) ($b['label'] ?? ''), $this->preferredTypeOrder, true);
            $oa = $oa === false ? 999 : $oa;
            $ob = $ob === false ? 999 : $ob;

            return $oa === $ob
                ? strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''))
                : $oa <=> $ob;
        });

        return $rows;
    }

    private function getTopChangeDrivers(array $salesIds, string $from, string $to, string $previousFrom, string $previousTo, bool $isD8, string $mode, ?int $limit = 8): array
    {
        $current = $this->getDriverTotals($salesIds, $from, $to, $isD8, $mode);
        $previous = $this->getDriverTotals($salesIds, $previousFrom, $previousTo, $isD8, $mode);
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

    private function getDriverTotals(array $salesIds, string $from, string $to, bool $isD8, string $mode): array
    {
        $in = implode(',', array_fill(0, count($salesIds), '?'));
        $metricExpr = $isD8 ? 'oi.sellprice * oi.qty' : $this->weightedQtyExpr('oi');
        $divisor = $isD8 ? 1 : 1000;
        $labelExpr = $mode === 'type'
            ? "COALESCE(NULLIF(TRIM(pt.description), ''), 'UNKNOWN')"
            : "COALESCE(NULLIF(TRIM(cus.name), ''), cus.customernumber, 'UNKNOWN')";

        $sql = "
            SELECT
                $labelExpr AS label,
                ROUND(COALESCE(SUM($metricExpr), 0) / $divisor, 2) AS total
            FROM orderitems oi
            JOIN oe ON oi.trans_id = oe.id
            JOIN customer cus ON oe.customer_id = cus.id
            JOIN parts p ON oi.parts_id = p.id
            JOIN partstype pt ON p.partstype_id = pt.id
            WHERE pt.id <> 56
              AND oe.ordnumber IS NOT NULL
              AND oe.ordnumber LIKE 'SO%'
              AND oe.requester_id IN ($in)
              AND oi.transdate >= ?
              AND oi.transdate <= ?
              AND oi.unit <> ' '
              AND p.partnumber LIKE 'F%'
              AND UPPER(TRIM(oe.custponumber)) NOT IN ('SAMPLE', 'FORECAST')
            GROUP BY $labelExpr
        ";

        $rows = DB::connection($this->conn)->select($sql, array_merge($salesIds, [$from, $to]));
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

    private function getDivisionYearTrend(array $salesIds, int $year, bool $isD8): array
    {
        $from = Carbon::create($year, 1, 1)->startOfYear()->toDateString();
        $to = Carbon::create($year, 12, 1)->endOfYear()->toDateString();
        $in = implode(',', array_fill(0, count($salesIds), '?'));
        $metricExpr = $isD8 ? 'oi.sellprice * oi.qty' : $this->weightedQtyExpr('oi');
        $divisor = $isD8 ? 1 : 1000;

        $sql = "
            SELECT
                EXTRACT(MONTH FROM oi.transdate) AS month_number,
                ROUND(COALESCE(SUM($metricExpr), 0) / $divisor, 2) AS total
            FROM orderitems oi
            JOIN oe ON oi.trans_id = oe.id
            JOIN customer cus ON oe.customer_id = cus.id
            JOIN parts p ON oi.parts_id = p.id
            JOIN partstype pt ON p.partstype_id = pt.id
            WHERE pt.id <> 56
              AND oe.ordnumber IS NOT NULL
              AND oe.ordnumber LIKE 'SO%'
              AND oe.requester_id IN ($in)
              AND oi.transdate >= ?
              AND oi.transdate <= ?
              AND oi.unit <> ' '
              AND p.partnumber LIKE 'F%'
              AND UPPER(TRIM(oe.custponumber)) NOT IN ('SAMPLE', 'FORECAST')
            GROUP BY month_number
        ";

        $rows = DB::connection($this->conn)->select($sql, array_merge($salesIds, [$from, $to]));
        $trend = array_fill(1, 12, null);

        foreach ($rows as $row) {
            $trend[(int)$row->month_number] = (float)$row->total;
        }

        return array_values($trend);
    }

    private function buildWeeklyMonthDetailUrls(int $requesterId, int $year): array
    {
        $urls = array_fill(0, 12, null);
        if ($requesterId <= 0) {
            return $urls;
        }

        for ($month = 1; $month <= 12; $month++) {
            $range = $this->monthRange($year, $month);
            $urls[$month - 1] = route('wos.sales_weekly.detail', [
                'salesId' => $requesterId,
                'from' => $range['from'],
                'to' => $range['to'],
                'month' => $month,
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

    private function getRequesterIdsByGroupCode(string $groupCode): array
    {
        $ids = [];

        foreach ($this->salesToGroupMap as $requesterId => $code) {
            if ($code === $groupCode) {
                $ids[] = (int)$requesterId;
            }
        }

        return $ids;
    }

    private function targetForGroup(string $groupCode): float
    {
        if ($groupCode === 'TOTAL') {
            return 900.0;
        }

        foreach ($this->salesToGroupMap as $requesterId => $code) {
            if ($code === $groupCode) {
                return (float)($this->targetMonth[$requesterId] ?? 0);
            }
        }

        return 0.0;
    }

    private function percentChange(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return $current == 0.0 ? 0.0 : null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 2);
    }

    private function getDetailRowsByMonth(string $from, string $to, int $month): array
    {
        $in = implode(',', array_fill(0, count($this->requesterIds), '?'));
        $qtyExpr = $this->weightedQtyExpr('oi');

        $sql = "
        SELECT
            oe.requester_id,
            oi.transdate,
            oe.ordnumber,
            cus.customernumber,
            cus.name AS customer_name,
            p.partnumber,
            oi.description,
            pt.description AS partstype,
            oi.unit AS unit2,
            $qtyExpr AS qty,
            oi.sellprice,
            oi.sellprice * oi.qty AS bath,
            oi.reqdate,
            oe.custponumber,
            EXTRACT(MONTH FROM oi.transdate) AS month_number
        FROM orderitems oi
        JOIN oe ON oi.trans_id = oe.id
        JOIN customer cus ON oe.customer_id = cus.id
        JOIN parts p ON oi.parts_id = p.id
        JOIN partstype pt ON p.partstype_id = pt.id
        WHERE
            oe.ordnumber IS NOT NULL
            AND oe.ordnumber LIKE 'SO%'
            AND oe.requester_id IN ($in)
            AND oi.transdate >= ?
            AND oi.transdate <= ?
            AND oi.unit <> ' '
            AND p.partnumber LIKE 'F%'
            AND UPPER(TRIM(oe.custponumber)) NOT IN ('SAMPLE', 'FORECAST')
            AND pt.id <> 56
            
            oe.ordnumber IS NOT NULL AND oe.ordnumber LIKE 'SO%' AND requester_id IN (1506,1507,1431,1432,1433,1434,1435,1436,528615586) AND 
            AND EXTRACT(MONTH FROM oi.transdate) = ?
        ORDER BY oi.transdate, oe.ordnumber
         ";

        $bindings = array_merge($this->requesterIds, [$from, $to, $month]);

        $rows = DB::connection($this->conn)->select($sql, $bindings);


        foreach ($rows as $r) {
            $rid = (int)$r->requester_id;
            $g = $this->salesToGroupMap[$rid] ?? null;
            $label = $g ? ($this->groupLabelMap[$g] ?? null) : null;


            $division = $label ? preg_replace('/^D\d+\s*-\s*/u', '', $label) : ($this->salesDisplayNameMap[$rid] ?? ('ID ' . $rid));

            $r->division = $division;
            $r->sales_name = $this->salesDisplayNameMap[$rid] ?? '';
        }

        return $rows;
    }

    public function status(Request $request)
    {
        $from = $request->query('from', '2026-01-01');
        $to   = $request->query('to', Carbon::now()->toDateString());

        $keyLast  = "sw:last_hash:$from:$to";
        $keyCount = "sw:change_count:$from:$to";
        $keyTime  = "sw:last_change_at:$from:$to";

        $currentHash = Cache::get($keyLast);
        $changeCount = Cache::get($keyCount, 0);
        $lastChange  = Cache::get($keyTime);

        return view('formwos.sales_weekly.status', [
            'from' => $from,
            'to' => $to,
            'currentHash' => $currentHash,
            'changeCount' => $changeCount,
            'lastChange' => $lastChange,
        ]);
    }

    public function hash(Request $request)
    {
        $from = $request->query('from', '2026-01-01');
        $to   = $request->query('to', Carbon::now()->toDateString());

        $monthBlocks = $this->getSummaryData($from, $to);

        $fingerprint = [];
        foreach ($monthBlocks as $blk) {
            foreach ($blk['rows'] as $r) {
                $fingerprint[] = implode('|', [
                    (int)$blk['month_number'],
                    (string)$r->group_code,
                    round((float)$r->qtyw1, 2),
                    round((float)$r->qtyw2, 2),
                    round((float)$r->qtyw3, 2),
                    round((float)$r->qtyw4, 2),
                    round((float)$r->qtyw5, 2),
                    round((float)$r->total, 2),
                ]);
            }
        }

        $newHash = md5(implode(';', $fingerprint));

        $keyHash  = "sw:last_hash:$from:$to";
        $keyTime  = "sw:last_change_at:$from:$to";
        $keyCount = "sw:change_count:$from:$to";

        $oldHash = Cache::get($keyHash);

        if ($oldHash !== null && $oldHash !== $newHash) {
            Cache::increment($keyCount);
            Cache::forever($keyTime, now()->toDateTimeString());
        }

        Cache::forever($keyHash, $newHash);

        return response()->json([
            'hash' => $newHash,
            'at'   => now()->toDateTimeString(),
        ]);
    }

    public function data(Request $request)
    {
        $from = $request->query('from', '2026-01-01');
        $to   = $request->query('to', Carbon::now()->toDateString());

        $monthBlocks = $this->getSummaryData($from, $to);

        $html = Cache::remember("sw:data:$from:$to", 20, function () use ($from, $to) {
            $monthBlocks = $this->getSummaryData($from, $to);
            return view('formwos.sales_weekly._table', compact('monthBlocks', 'from', 'to'))->render();
        });
        return response()->json(['html' => $html]);
    }
}
