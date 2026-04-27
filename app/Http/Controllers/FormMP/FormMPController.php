<?php

namespace App\Http\Controllers\FormMP;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Support\Collection;

class FormMPController extends Controller
{

    protected function getFormMPData(Request $request): array
    {
        // 1) อ่านค่า checkbox site
        $usePlus = $request->has('site_plus') ? (bool)$request->input('site_plus') : true;
        $useWire = $request->has('site_wire') ? (bool)$request->input('site_wire') : true;

        if (!$usePlus && !$useWire) {
            $usePlus = true; // กันเคสเอาติ๊กออกหมด
        }

        // 2) วันที่ default
        $defaultFrom = now()->startOfMonth()->toDateString();
        $defaultTo   = now()->endOfMonth()->toDateString();

        $fromDate = $request->input('from_date', $defaultFrom);
        $toDate   = $request->input('to_date', $defaultTo);

        // 3) ฟังก์ชันสร้าง query base ใช้ซ้ำได้ทั้ง 2 connection
        $buildQuery = function ($connectionName) use ($fromDate, $toDate, $request) {
            $db = DB::connection($connectionName);

            $q = $db->table('ap')
                ->join('acc_trans as ac', 'ap.id', '=', 'ac.trans_id')
                ->join('receive as r', 'ap.id', '=', 'r.ap_id')
                ->join('oe', 'oe.id', '=', 'r.ord_id')
                ->join('chart as c', 'c.id', '=', 'ac.chart_id')
                ->join('orderitems as oi', 'oe.id', '=', 'oi.trans_id')
                ->join('classinfo as ci', 'oi.class_id', '=', 'ci.id')
                ->whereIn('ac.chart_id', [1090, 1239, 161339764])
                ->whereBetween('ap.transdate', [$fromDate, $toDate])
                ->select(
                    'ap.transdate',
                    'ap.apnumber',
                    'c.description as chart_description',
                    'oi.qty',
                    'oi.sellprice',
                    DB::raw('oi.qty * oi.sellprice as price'),
                    'ap.invnumber',
                    'ap.ordnumber',
                    'ap.f3',
                    'ap.notes',
                    'ap.f1',
                    'ci.classnumber',
                    'ci.description as class_description'
                )
                ->addSelect(DB::raw("
                CASE
                    WHEN ap.notes ILIKE '%เครื่องรีด%'       THEN 'เครื่องรีด'
                    WHEN ap.notes ILIKE '%Shotblast%'        THEN 'Shotblast'
                    WHEN ap.notes ILIKE '%Chamfer%'          THEN 'Chamfer'
                    WHEN ap.notes ILIKE '%Combine%'          THEN 'Combine'
                    WHEN ap.notes ILIKE '%เครื่องปล่อยลวด%' THEN 'เครื่องปล่อยลวด'
                    WHEN ap.notes ILIKE '%เครื่องตัด%'       THEN 'เครื่องตัด'
                    ELSE 'ไม่มีชื่อเครื่องจักร'
                END AS machine_name
            "));

            // ฟิลเตอร์เหมือน index เดิม
            if ($request->filled('apnumber')) {
                $q->where('ap.apnumber', 'like', '%' . $request->apnumber . '%');
            }
            if ($request->filled('invnumber')) {
                $q->where('ap.invnumber', 'like', '%' . $request->invnumber . '%');
            }
            if ($request->filled('ordnumber')) {
                $q->where('ap.ordnumber', 'like', '%' . $request->ordnumber . '%');
            }
            if ($request->filled('exact_transdate')) {
                $q->whereDate('ap.transdate', $request->exact_transdate);
            }

            return $q;
        };

        // 4) ดึงข้อมูลตาม site ที่เลือก (ไม่ paginate ตรงนี้)
        $allRows = collect();

        if ($usePlus) {
            $rowsPlus = $buildQuery('pgsqlp')->get();
            $rowsPlus->transform(function ($r) {
                $r->site = 'PLUS';
                return $r;
            });
            $allRows = $allRows->concat($rowsPlus);
        }

        if ($useWire) {
            $rowsWire = $buildQuery('pgsqlw')->get();
            $rowsWire->transform(function ($r) {
                $r->site = 'WIRE';
                return $r;
            });
            $allRows = $allRows->concat($rowsWire);
        }

        // 5) sort รวม
        $allRows = $allRows->sortBy([
            ['machine_name', 'asc'],
            ['transdate', 'asc'],
            ['apnumber', 'asc'],
        ])->values();

        // 6) รวมยอดรวมทั้งหมด
        $totalPrice = $allRows->sum('price');

        return [
            'allRows'    => $allRows,
            'fromDate'   => $fromDate,
            'toDate'     => $toDate,
            'totalPrice' => $totalPrice,
            'usePlus'    => $usePlus,
            'useWire'    => $useWire,
        ];
    }

    public function index(Request $request)
    {
        $data      = $this->getFormMPData($request);
        $allRows   = $data['allRows'];
        $fromDate  = $data['fromDate'];
        $toDate    = $data['toDate'];
        $totalPrice = $data['totalPrice'];
        $usePlus   = $data['usePlus'];
        $useWire   = $data['useWire'];

        // manual pagination จาก allRows
        $perPage     = 30;
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $pageItems   = $allRows->forPage($currentPage, $perPage)->values();

        $rows = new LengthAwarePaginator(
            $pageItems,
            $allRows->count(),
            $perPage,
            $currentPage,
            [
                'path'  => $request->url(),
                'query' => $request->query(),
            ]
        );

        // group ตามเครื่อง จากรายการบนหน้าปัจจุบัน
        $groupedRows = collect($rows->items())->groupBy('machine_name');

        return view('formmp.index', [
            'rows'        => $rows,
            'groupedRows' => $groupedRows,
            'fromDate'    => $fromDate,
            'toDate'      => $toDate,
            'totalPrice'  => $totalPrice,
            'filters'     => [
                'apnumber'        => $request->apnumber,
                'invnumber'       => $request->invnumber,
                'ordnumber'       => $request->ordnumber,
                'exact_transdate' => $request->exact_transdate,
            ],
            'usePlus' => $usePlus,
            'useWire' => $useWire,
        ]);
    }


    public function exportExcel(Request $request)
    {
        $data      = $this->getFormMPData($request);
        $allRows   = $data['allRows'];
        $fromDate  = $data['fromDate'];
        $toDate    = $data['toDate'];
        // $totalPrice = $data['totalPrice']; // จะใช้รวมท้ายไฟล์ก็ได้

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Header สวย ๆ
        $sheet->setCellValue('A1', "รายงานค่าใช้จ่ายเครื่องจักร ($fromDate ถึง $toDate)");
        $sheet->mergeCells('A1:K1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        // Column Headers
        $headers = [
            'วันที่',
            'AP Number',
            'Inv Number',
            'Ord Number',
            'บัญชี',
            'ชื่อสินค้า',
            'จำนวน',
            'ราคาต่อหน่วย',
            'ราคารวม',
            'หมายเหตุ',
            'เครื่องจักร',
            // ถ้าอยากดู site ด้วย เปิดบรรทัดนี้:
            // 'Site',
        ];

        $sheet->fromArray($headers, null, 'A3');

        $rowIndex = 4;

        foreach ($allRows as $r) {
            $sheet->setCellValue("A{$rowIndex}", $r->transdate);
            $sheet->setCellValue("B{$rowIndex}", $r->apnumber);
            $sheet->setCellValue("C{$rowIndex}", $r->invnumber);
            $sheet->setCellValue("D{$rowIndex}", $r->ordnumber);
            $sheet->setCellValue("E{$rowIndex}", $r->chart_description);
            $sheet->setCellValue("F{$rowIndex}", $r->f3);
            $sheet->setCellValue("G{$rowIndex}", $r->qty);
            $sheet->setCellValue("H{$rowIndex}", $r->sellprice);
            $sheet->setCellValue("I{$rowIndex}", $r->price);
            $sheet->setCellValue("J{$rowIndex}", $r->notes);
            $sheet->setCellValue("K{$rowIndex}", $r->machine_name);
            // ถ้าอยากเก็บ site ด้วย:
            // $sheet->setCellValue("L{$rowIndex}", $r->site ?? '');

            $rowIndex++;
        }

        // ทำหัวข้อหนา
        $sheet->getStyle('A3:K3')->getFont()->setBold(true);

        // ทำ auto width
        foreach (range('A', 'K') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $fileName = "รายงานค่าใช้จ่ายเครื่องจักร-$fromDate-$toDate.xlsx";
        $writer   = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
