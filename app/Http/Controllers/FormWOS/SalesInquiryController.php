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
        'D5' => 'D5 - คุณธัธลิญา + คุณกัณญิกา',
        'D6' => 'D6 - คุณสุรศักดิ์ + คุณคณัญญ์นิชา',
        'D7' => 'D7 - คุณศิรินภา + คุณมนพัทธ์',
        'D8' => 'D8 - คุณสาธิต + คุณสุธาสินี',
        'D9' => 'D9 - คุณวรเดชา + คุณลัดดาวัลย์',
    ];

    private array $groupOrder = ['D1', 'D2', 'D3', 'D5', 'D6', 'D7', 'D9', 'TOTAL', 'D8'];

    public function index(Request $request)
    {
        $from = $request->query('from', '2026-01-01');
        $to   = $request->query('to', Carbon::now()->toDateString());

        $monthBlocks = $this->getSummaryData($from, $to);

        return view('formwos.sales_weekly', [
            'monthBlocks' => $monthBlocks,
            'filters' => [
                'from' => $from,
                'to' => $to,
            ],
        ]);
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
                oi.qty * CASE WHEN p.ref_unit = '03' THEN p.ref_unit_qty ELSE 1 END AS qty,
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

        return view('formwos.sales_weekly_detail', [
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

        $sql = "
        WITH sale1 AS (
            SELECT
                oe.requester_id,
                oi.transdate,
                oi.qty * CASE WHEN p.ref_unit = '03' THEN p.ref_unit_qty ELSE 1 END AS qty,
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

    private function getDetailRowsByMonth(string $from, string $to, int $month): array
    {
        $in = implode(',', array_fill(0, count($this->requesterIds), '?'));

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
            oi.qty * CASE WHEN p.ref_unit = '03' THEN p.ref_unit_qty ELSE 1 END AS qty,
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

        return view('formwos.sales_weekly_status', [
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
            return view('formwos._sales_weekly_table', compact('monthBlocks', 'from', 'to'))->render();
        });
        return response()->json(['html' => $html]);
    }
}
