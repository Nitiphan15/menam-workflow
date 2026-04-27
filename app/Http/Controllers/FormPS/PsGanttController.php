<?php

namespace App\Http\Controllers\FormPS;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PsGanttController extends Controller
{
    public function index(Request $request)
    {
        // หน้าแสดง Gantt ให้ planner
        return view('formps.gantt.index');
    }

    public function data(Request $request)
    {
        $sku   = $request->query('sku');
        $site  = $request->query('site');
        $from  = $request->query('date_from');  // YYYY-MM-DD
        $to    = $request->query('date_to');

        $fromDate = $from ? Carbon::parse($from) : null;
        $toDate   = $to   ? Carbon::parse($to)   : null;

        $tasks = collect();
        $links = collect();

        $mncFor = function (string $conn, string $siteLabel, ?string $sku = null, ?Carbon $fromDate = null, ?Carbon $toDate = null) {
            $cn = DB::connection($conn);

            $usageSub = $cn->table('workorderusage as wu')
                ->selectRaw('
                wu.workorder_id,
                wu.workcenter_id,
                MIN(wu.usagestamp) AS station_start,
                MAX(wu.usagestamp) AS station_end
            ')
                ->groupBy('wu.workorder_id', 'wu.workcenter_id');

            $q = $cn->table('workorder as w')
                ->join('parts as p', 'p.id', '=', 'w.parts_id')
                ->join('workorderworkcenter as woc', 'w.id', '=', 'woc.workorder_id')
                ->join('workcenter as wc', 'wc.id', '=', 'woc.workcenter_id')
                ->leftJoinSub($usageSub, 'u', function ($join) {
                    $join->on('u.workorder_id', '=', 'w.id')
                        ->on('u.workcenter_id', '=', 'wc.id');
                })
                ->selectRaw("
                w.workordernumber   AS workorder_no,
                w.dateopen          AS open_date,
                w.reqdate           AS due_date,
                w.ordnumber         AS so_no,
                w.customer          AS customer_name,
                p.partnumber        AS sku,
                p.description       AS sku_name,
                MAX(w.qty)          AS qty,
                w.fcat              AS product_category,
                '$siteLabel'        AS site,
                w.dateclose         AS close_date,
                wc.workcenternumber AS workcenter_no,
                u.station_start     AS station_start,
                u.station_end       AS station_end
            ");

            // 🔹 ตรงนี้สำคัญ: filter วันที่ตั้งแต่ใน DB
            if ($fromDate && $toDate) {
                $q->whereBetween('w.dateopen', [
                    $fromDate->toDateString(),
                    $toDate->toDateString(),
                ]);
            } elseif ($fromDate) {
                $q->where('w.dateopen', '>=', $fromDate->toDateString());
            } elseif ($toDate) {
                $q->where('w.dateopen', '<=', $toDate->toDateString());
            } else {
                // ถ้า user ไม่กรอกเลย กำหนด default เช่น 6 เดือนย้อนหลัง
                $q->where('w.dateopen', '>=', now()->subMonths(6)->toDateString());
            }

            if (!empty($sku)) {
                $q->where('p.partnumber', $sku);
            }

            $q->groupBy([
                'w.workordernumber',
                'w.dateopen',
                'w.reqdate',
                'w.ordnumber',
                'w.customer',
                'p.partnumber',
                'p.description',
                'w.fcat',
                'w.dateclose',
                'wc.workcenternumber',
                'u.station_start',
                'u.station_end',
            ])
                ->orderBy('w.workordernumber')
                ->orderBy('wc.workcenternumber');

            return $q->get();

            $grouped = $rows->groupBy('workorder_no');


            $bindings = ['site_label' => $siteLabel];

            // ถ้ากรอก SKU ให้เติมเงื่อนไขเข้าไป
            if (!empty($sku)) {
                $sql = str_replace('/**SKU_FILTER**/', "AND p.partnumber = :sku", $sql);
                $bindings['sku'] = $sku;
            } else {
                $sql = str_replace('/**SKU_FILTER**/', '', $sql);
            }

            // รัน SQL ดิบ แล้วแปลง array -> collection
            $rows = $cn->select($sql, $bindings);

            return collect($rows);
        };

        // ----- รวมหลาย site -----
        $rows = collect();

        if (!$site || strtoupper($site) === 'ALL') {
            $rows = $rows
                ->merge($mncFor('pgsqlmfgw', 'MENAM WIRE', $sku))
                ->merge($mncFor('pgsqlmfgp', 'MENAM PLUS', $sku));
        } elseif (strtoupper($site) === 'MENAM WIRE') {
            $rows = $rows->merge($mncFor('pgsqlmfgw', 'MENAM WIRE', $sku));
        } elseif (strtoupper($site) === 'MENAM PLUS') {
            $rows = $rows->merge($mncFor('pgsqlmfgp', 'MENAM PLUS', $sku));
        }

        $fromDate = $from ? Carbon::parse($from) : null;
        $toDate   = $to   ? Carbon::parse($to)   : null;

        $fromDate = $from ? Carbon::parse($from) : null;
        $toDate   = $to   ? Carbon::parse($to)   : null;

        // group ตาม WO
        $grouped = $rows->groupBy('workorder_no');

        $tasks = collect();

        foreach ($grouped as $wo => $items) {

            // ---------------- parent: WO ----------------
            // หา start/end รวมของ WO นี้ (จากสถานีทั้งหมด)
            $woStart = $items->min(function ($r) {
                return $r->station_start ?? $r->open_date;
            });
            $woEnd   = $items->max(function ($r) {
                return $r->station_end
                    ?? ($r->close_date ?? ($r->due_date ?? $r->open_date));
            });

            $stationHours = 24; // สมมติ 4 ชม./station
            $workStartTime = '08:00'; // เริ่มกี่โมงในวัน open
            $workEndTime   = '23:00'; // เวลาทำงานสูงสุดของวัน due (กันไม่ให้เกินกลางคืน)

            $woStart = Carbon::parse($items->first()->open_date . ' ' . $workStartTime);
            $woEnd   = Carbon::parse(($items->first()->due_date ?? $items->first()->open_date) . ' ' . $workEndTime);


            // clip ตาม filter date_from/date_to (กันไม่ให้หลุดช่วง)
            if ($fromDate && $woStart->lt($fromDate)) {
                $woStart = $fromDate->copy();
            }
            if ($toDate && $woEnd->gt($toDate)) {
                $woEnd = $toDate->copy();
            }
            if ($woEnd->lte($woStart)) {
                continue; // WO นี้อยู่นอกช่วง
            }

            $first = $items->first();

            $woId = 'WO-' . $wo;  // id parent


            $tasks->push([
                'id'         => $woId,
                'text'       => $wo,                         // ชื่อ WO
                'type'       => 'project',
                'start_date' => $woStart->format('Y-m-d H:i'),
                'end_date'   => $woEnd->format('Y-m-d H:i'),
                'open'       => true,
                'parent'     => 0,



                // meta เพิ่มเติม
                'site'       => $first->site,
                'sku'        => $first->sku,
                'sku_name'   => $first->sku_name,
                'customer'   => $first->customer_name,
                'qty'        => $first->qty,
            ]);

            // ---------- children: station blocks ----------
            $stations = $items->sortBy('workseq')->values();
            $cursor   = $woStart->copy();

            $prevTaskId = null;

            $prevTaskId = null;

            foreach ($stations as $r) {
                $start = $cursor->copy();
                $end   = $cursor->copy()->addHours($stationHours);

                if ($end->gt($woEnd)) {
                    $end = $woEnd->copy();
                }
                if ($end->lte($start)) {
                    continue;
                }

                $taskId = $woId . '-' . $r->workcenter_no;


                $planQty   = $r->qty;                 // ยอดแผนของ station (หรือจะใช้ field อื่น เช่น refqty ก็ได้)
                $doneQty   = $r->station_qty ?? 0;    // ยอดจาก workorderusage

                $progress = 0;
                if ($planQty > 0) {
                    $progress = min(1, $doneQty / $planQty);  // 0..1
                }

                $tasks->push([
                    'id'          => $taskId,
                    'text'        => $r->workcenter_no,
                    'start_date'  => $start->format('Y-m-d H:i'),
                    'end_date'    => $end->format('Y-m-d H:i'),
                    'parent'      => $woId,
                    'progress'    => $progress,
                    // meta ...

                    'site'       => $first->site,
                    'sku'        => $first->sku,
                    'sku_name'   => $first->sku_name,
                    'customer'   => $first->customer_name,
                    'qty'        => $first->qty,
                ]);

                // ✅ link เฉพาะ station ก่อนหน้า → station ปัจจุบัน
                if ($prevTaskId !== null) {
                    $links->push([
                        'id'     => $woId . '-link-' . $prevTaskId . '-' . $taskId,
                        'source' => $prevTaskId,
                        'target' => $taskId,
                        'type'   => 0,   // FS
                    ]);
                }

                $prevTaskId = $taskId;
                $cursor = $end->copy();
            }
        }
        return response()->json([
            'data'  => $tasks->values(),
            'links' => $links->values(),
        ]);
    }
}
