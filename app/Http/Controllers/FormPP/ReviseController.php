<?php

namespace App\Http\Controllers\FormPP;

use App\Http\Controllers\Controller;

use App\Models\FormPP\PpData;
use App\Services\WorkflowEngine;
use App\Support\WorkflowDb;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;

class ReviseController extends Controller
{
    public function showRevise(Request $req, $id)
    {
        $step = 1;
        $ppData = PpData::query()
            ->where('form_id', $id)
            ->firstOrFail();   // ไม่เจอ => 404
        $sku    = $ppData->part_no;
        //dd($id);
        $wfForm = WorkflowDb::findForm('pp', (int) $id);
        abort_unless($wfForm, 404);

        $canApprove = WorkflowDb::canApprove('pp', (int) $wfForm->id, (int) auth()->id());

        $forward = $req->duplicate(
            array_merge($req->query(), ['sku' => $sku]),
            $req->post()
        );

        // เรียกเมธอด search ตรง ๆ (ไม่ต้อง app(...))
        $skuSummary = $this->search($forward);


        //dd($skuSummary);
        return view('formpp.originator_revise', [
            // ของเดิมที่ index ต้องการ
            'sku'              => $sku,
            'result'           => $skuSummary['result'] ?? null,
            'datasets'         => $skuSummary['datasets'] ?? [],
            'customers'        => $skuSummary['customers'] ?? [],
            'selectedCustomer' => $skuSummary['selectedCustomer'] ?? null,
            'selectedSrc'      => $skuSummary['selectedSrc'] ?? [],

            // ตัวแปรสำหรับ "โหมด Planner"
            'plannerMode' => true,
            'ppData'      => $ppData,
            'form'        => $wfForm,
            'canApprove'  => $canApprove,
        ]);
    }

    public function search(Request $r)
    {
        $perPage = 20;

        $sku = trim((string) $r->query('sku', ''));
        $selectedCustomer = array_values(array_filter((array) $r->query('customers', [])));
        $selectedSrc = (array) $r->query('src', []);
        //dump($r);
        $useWire = empty($selectedSrc) || in_array('wire', $selectedSrc, true);
        $usePlus = empty($selectedSrc) || in_array('plus', $selectedSrc, true);

        $datasets = null;
        $result   = null;
        $customers = collect();

        if ($sku === '') {
            return view('formpp.originator', [
                'sku' => $sku,
                'result' => $result,
                'datasets' => $datasets,
                'customers' => $customers,
                'selectedCustomer' => $selectedCustomer,
                'selectedSrc' => $selectedSrc,
            ]);
        }

        /** ───────────────────────────── Cache key ───────────────────────────── */
        $cacheKey = 'pp:v2:' . md5(json_encode([
            'sku' => $sku,
            'src' => $selectedSrc,
            'cus' => $selectedCustomer,
        ]));

        /**
         * โหลดยกชุดด้วย Cache 10 นาที
         * ข้างในจะทำคิวรี่ทั้งหมด (FG, WIP, Booked, SO)
         */
        $data = Cache::remember($cacheKey, 600, function () use ($sku, $useWire, $usePlus) {

            /** ======================== FG (Wire) ======================== */
            $fgWire = collect();
            if ($useWire) {
                // Subquery: normalize ลูกค้า + เก็บคอลัมน์ที่ใช้ group
                $suByCust = DB::connection('pgsqlw')
                    ->table('parts as p')
                    ->join('serializeunits as su', 'su.parts_id', '=', 'p.id')
                    ->join('serializeunitsmvmt as sus', 'sus.su_id', '=', 'su.id')
                    ->leftJoin('workorderreceive as wr', function ($j) {
                        $j->on('wr.invoice_id', '=', 'sus.invoice_id')
                            ->on('wr.parts_id', '=', 'p.id');
                    })
                    ->leftJoin('workorder as wo', function ($j) {
                        $j->on('wo.id', '=', 'wr.workorder_id')
                            ->on('wo.parts_id', '=', 'p.id');
                    })
                    ->leftJoin(DB::raw('"return" as r'), 'r.id', '=', 'sus.trans_id')
                    ->leftJoin('customer as cwo', 'cwo.id', '=', 'wo.customer_id')
                    ->leftJoin('customer as cr', function ($j) {
                        $j->on('cr.id', '=', DB::raw('r.customer_id'));
                    })
                    ->where('p.active', true)
                    ->where('p.partnumber', $sku)
                    ->selectRaw("
                        p.id AS parts_id,
                        p.partnumber,
                        COALESCE(cwo.id, cr.id, 0) AS customer_id_norm,
                        COALESCE(NULLIF(TRIM(COALESCE(cwo.name, cr.name)), ''), 'Menam Plus') AS customer_name_norm,
                        wo.workordernumber,
                        sus.transdate,
                        sus.qty,
                        su.onhand
                    ");

                $fgWire = DB::connection('pgsqlw')
                    ->query()
                    ->fromSub($suByCust, 's')
                    ->join('parts as p', 'p.id', '=', 's.parts_id')
                    ->join('partstype as pt', 'pt.id', '=', 'p.partstype_id')
                    ->join('partscategory as pc', 'pc.id', '=', 'p.partscategory_id')
                    ->join('partsgroup as pg', 'pg.id', '=', 'p.partsgroup_id')
                    ->groupBy([
                        'p.partnumber',
                        'p.description',
                        's.customer_name_norm',
                        'p.unit',
                        'pt.description',
                        'pc.categorynumber',
                        'pc.description',
                        'pg.groupnumber',
                        'pg.description',
                        's.workordernumber',
                    ])
                    ->selectRaw("
                        p.partnumber,
                        p.description,
                        s.customer_name_norm AS customer,
                        'Wire' AS site,
                        MAX(s.transdate) AS transdate_max,
                        SUM(CASE WHEN s.qty > 0 THEN s.qty ELSE 0 END) AS receive_qty,
                        SUM(CASE WHEN s.onhand THEN s.qty ELSE 0 END)   AS balance_qty,
                        (SUM(CASE WHEN s.qty > 0 THEN s.qty ELSE 0 END)
                         - SUM(CASE WHEN s.onhand THEN s.qty ELSE 0 END)) AS issue_qty,
                        p.unit AS unit_name,
                        pt.description AS type_desc,
                        pc.categorynumber AS cat_no,
                        pc.description AS cat_desc,
                        pg.groupnumber AS grp_no,
                        pg.description AS grp_desc,
                        s.workordernumber
                    ")
                    ->orderBy('p.partnumber')
                    ->orderBy('s.customer_name_norm')
                    ->get();
            }

            /** ======================== FG (Plus) ======================== */
            $fgPlus = collect();
            if ($usePlus) {
                $suByCustPlus = DB::connection('pgsqlp')
                    ->table('parts as p')
                    ->join('serializeunits as su', 'su.parts_id', '=', 'p.id')
                    ->join('serializeunitsmvmt as sus', 'sus.su_id', '=', 'su.id')
                    ->join('workorderreceive as wr', 'wr.invoice_id', '=', 'sus.invoice_id')
                    ->join('workorder as wo', function ($j) {
                        $j->on('wo.id', '=', 'wr.workorder_id')
                            ->on('wo.parts_id', '=', 'p.id');
                    })
                    ->join('customer as c', 'c.id', '=', 'wo.customer_id')
                    ->where('p.partnumber', $sku)
                    ->selectRaw('
                        p.id AS parts_id,
                        p.partnumber,
                        c.id AS customer_id,
                        c.name AS customer_name,
                        wo.workordernumber,
                        sus.transdate,
                        sus.qty,
                        su.onhand
                    ');

                $fgPlus = DB::connection('pgsqlp')
                    ->query()
                    ->fromSub($suByCustPlus, 's')
                    ->join('parts as p', 'p.id', '=', 's.parts_id')
                    ->join('partstype as pt', 'pt.id', '=', 'p.partstype_id')
                    ->join('partscategory as pc', 'pc.id', '=', 'p.partscategory_id')
                    ->join('partsgroup as pg', 'pg.id', '=', 'p.partsgroup_id')
                    ->groupBy([
                        'p.partnumber',
                        'p.description',
                        's.customer_name',
                        'p.unit',
                        'pt.description',
                        'pc.categorynumber',
                        'pc.description',
                        'pg.groupnumber',
                        'pg.description',
                        's.workordernumber',
                    ])
                    ->selectRaw("
                        p.partnumber,
                        p.description,
                        s.customer_name AS customer,
                        'Plus' AS site,
                        MAX(s.transdate) AS transdate_max,
                        SUM(CASE WHEN s.qty > 0 THEN s.qty ELSE 0 END) AS receive_qty,
                        SUM(CASE WHEN s.onhand THEN s.qty ELSE 0 END)   AS balance_qty,
                        (SUM(CASE WHEN s.qty > 0 THEN s.qty ELSE 0 END)
                         - SUM(CASE WHEN s.onhand THEN s.qty ELSE 0 END)) AS issue_qty,
                        p.unit AS unit_name,
                        pt.description AS type_desc,
                        pc.categorynumber AS cat_no,
                        pc.description AS cat_desc,
                        pg.groupnumber AS grp_no,
                        pg.description AS grp_desc,
                        s.workordernumber
                    ")
                    ->orderBy('p.partnumber')
                    ->orderBy('s.customer_name')
                    ->get();
            }

            /** ======================== WIP (Wire/Plus) ======================== */
            $start = Carbon::now()->startOfYear()->toDateString();
            $end   = Carbon::now()->endOfYear()->toDateString();

            // helper: WIP per-connection
            $wipFor = function (string $conn, string $site) use ($sku, $start, $end) {
                // usage_rm (joinSub เพื่อใช้ใน WHERE)
                $usageRm = DB::connection($conn)
                    ->table('workorderusage')
                    ->selectRaw('workorder_id, SUM(qty) AS qty')
                    ->groupBy('workorder_id');

                $q = DB::connection($conn)
                    ->table('workorder')
                    ->join('parts', 'workorder.parts_id', '=', 'parts.id')
                    ->leftJoin('partstype', 'parts.partstype_id', '=', 'partstype.id')
                    ->leftJoin('customer', 'workorder.customer_id', '=', 'customer.id')
                    ->leftJoinSub($usageRm, 'usage_rm', function ($j) {
                        $j->on('usage_rm.workorder_id', '=', 'workorder.id');
                    })
                    ->where('parts.partnumber', $sku)
                    //->whereBetween('workorder.dateopen', [$start, $end])

                    // scalar subqueries: defect / return_rm / finished
                    ->select(
                        'workorder.parts_id',
                        'workorder.dateopen',
                        'workorder.reqdate',
                        'workorder.workordernumber',
                        'parts.partnumber',
                        'parts.description',
                        DB::raw('ROUND(workorder.qty, 2) AS จำนวนสั่งผลิต'),
                        DB::raw('ROUND(COALESCE(usage_rm.qty, 0), 2) AS เบิกวัตถุดิบ'),
                    )
                    ->selectSub(function ($s) use ($conn) {
                        $s->from('workorderreceive as wor')
                            ->join('parts as p2', 'p2.id', '=', 'wor.parts_id')
                            ->leftJoin('partstype as pt2', 'pt2.id', '=', 'p2.partstype_id')
                            ->whereColumn('wor.workorder_id', 'workorder.id')
                            ->whereIn('pt2.id', [61, 62])
                            ->selectRaw('ROUND(SUM(wor.qty),2)');
                    }, 'ของเสีย')
                    ->selectSub(function ($s) use ($conn) {
                        $s->from('workorderreceive as wor')
                            ->join('parts as p2', 'p2.id', '=', 'wor.parts_id')
                            ->leftJoin('partstype as pt2', 'pt2.id', '=', 'p2.partstype_id')
                            ->whereColumn('wor.workorder_id', 'workorder.id')
                            ->whereIn('pt2.id', [69, 70, 71, 95, 91])
                            ->selectRaw('ROUND(SUM(wor.qty),2)');
                    }, 'คืนวัตถุดิบ')
                    ->selectSub(function ($s) use ($conn) {
                        $s->from('workorderreceive as wor')
                            ->join('parts as p2', 'p2.id', '=', 'wor.parts_id')
                            ->leftJoin('partstype as pt2', 'pt2.id', '=', 'p2.partstype_id')
                            ->whereColumn('wor.workorder_id', 'workorder.id')
                            ->whereNotIn('pt2.id', [69, 70, 71, 95, 61, 62, 91, 0, 56])
                            ->selectRaw('ROUND(SUM(wor.qty),2)');
                    }, 'ของดี')
                    ->addSelect([
                        DB::raw('ROUND(COALESCE(usage_rm.qty, 0) - COALESCE(workorder.received, 0), 2) AS Balance'),
                        'workorder.received',
                        'parts.unit',
                        DB::raw('ROUND(COALESCE(parts.onhand, 0), 2) AS FG'),
                        'customer.name',
                        DB::raw("'$site' AS site"),
                    ])
                    ->groupBy(
                        'workorder.id',
                        'workorder.dateopen',
                        'workorder.reqdate',
                        'workorder.workordernumber',
                        'parts.partnumber',
                        'parts.description',
                        'workorder.qty',
                        'usage_rm.qty',
                        'workorder.received',
                        'parts.unit',
                        'parts.onhand',
                        'customer.name'
                    )
                    ->orderByDesc('workorder.dateopen')
                    ->orderBy('workorder.workordernumber');

                return $q->get();
            };

            $wipWire = $useWire ? $wipFor('pgsqlw', 'Wire') : collect();
            $wipPlus = $usePlus ? $wipFor('pgsqlp', 'Plus') : collect();

            /** =================== Menucost (Booked: Wire/Plus) =================== */
            $mfgWire = collect();
            $mfgPlus = collect();

            $mncFor = function (string $conn, string $site) use ($sku) {
                $cn = DB::connection($conn);

                $bomSub = $cn->table('workorderbom as b')
                    ->join('parts as p', 'p.id', '=', 'b.parts_id')
                    ->selectRaw("
                        b.workorder_id,
                        array_to_string(array_agg(DISTINCT p.partnumber), ', ')  AS bom_partnumbers,
                        array_to_string(array_agg(DISTINCT p.description), ', ') AS bom_descriptions
                    ")
                    ->groupBy('b.workorder_id');

                $xDedupSub = $cn->table('workorderworkcenter')
                    ->select('workorder_id')
                    ->distinct();

                return $cn->table('workorder as w')
                    ->joinSub($xDedupSub, 'x', function ($j) {
                        $j->on('x.workorder_id', '=', 'w.id');
                    })
                    ->join('parts as p', 'p.id', '=', 'w.parts_id')
                    ->leftJoinSub($bomSub, 'bom', function ($j) {
                        $j->on('bom.workorder_id', '=', 'w.id');
                    })
                    ->where('p.partnumber', $sku)
                    ->whereBetween('w.dateopen', ['2012-01-01', DB::raw('CURRENT_DATE')])
                    //->whereNull('w.dateclose')
                    ->groupBy([
                        'w.workordernumber',
                        'w.dateopen',
                        'w.reqdate',
                        'w.ordnumber',
                        'w.customer',
                        'p.partnumber',
                        'p.description',
                        'w.fcat',
                        'bom.bom_partnumbers',
                        'bom.bom_descriptions',
                        'w.dateclose'
                    ])
                    ->selectRaw("
                        w.workordernumber AS \"เลขที่คำสั่งผลิต\",
                        w.dateopen AS \"วันที่เปิด\",
                        w.reqdate AS \"วันที่ส่ง\",
                        w.ordnumber AS \"เลขที่คำสั่งขาย\",
                        w.customer AS \"ลูกค้า\",
                        p.partnumber AS \"รหัสสินค้า\",
                        p.description AS \"สินค้า\",
                        MAX(w.qty) AS \"จำนวน\",
                        w.fcat AS product_category,
                        bom.bom_partnumbers AS \"รหัสวัตถุดิบ\",
                        bom.bom_descriptions AS \"วัตถุดิบ\",
                        '$site' AS site,
                        w.dateclose
                    ")
                    ->orderByDesc('w.workordernumber')
                    ->get();
            };

            if ($useWire) $mfgWire = $mncFor('pgsqlmfgw', 'Wire');
            if ($usePlus) $mfgPlus = $mncFor('pgsqlmfgp', 'Plus');

            /** ======================== Sales Order (Wire) ======================== */
            $saleOrder = collect();
            if ($useWire) {
                $conn = DB::connection('pgsqlw');
                $ob = $conn->table('orderitems as oi')
                    ->join('oe', 'oi.trans_id', '=', 'oe.id')
                    ->leftJoin('oedm', 'oe.id', '=', 'oedm.ord_id')
                    ->leftJoin('dm', 'oedm.dm_id', '=', 'dm.id')
                    ->leftJoin('dmpredm', 'dm.id', '=', 'dmpredm.dm_id')
                    ->leftJoin('dmitems as dmi', 'dmpredm.dmitems_id', '=', 'dmi.id')
                    ->join('customer as cus', 'oe.customer_id', '=', 'cus.id')
                    ->leftJoin('workorder as wo', 'oe.ordnumber', '=', 'wo.workordernumber')
                    ->whereNotNull('oe.ordnumber')
                    ->where('oe.shipped_or_received', false)
                    ->where('oe.invoiced', false)
                    ->groupBy([
                        'oe.ordnumber',
                        'oi.parts_id',
                        'oe.customer_id',
                        'cus.saleperson_id',
                        'oe.shipped_or_received',
                        'oe.invoiced',
                    ])
                    ->selectRaw("
                        MAX(oe.custponumber) AS po,
                        oe.ordnumber AS ordnumber,
                        oi.parts_id AS parts_id,
                        SUM(oi.qty) AS ordered_qty,
                        MIN(oe.transdate) AS order_date,
                        oe.customer_id AS customer_id,
                        cus.saleperson_id AS saleperson_id,
                        'MENAM WIRE' AS company,
                        MAX(COALESCE(oi.reqdate, wo.reqdate)) AS due_date,
                        AVG(oi.sellprice) AS unit_price,
                        oe.shipped_or_received,
                        oe.invoiced,
	                    SUM(dmi.qty) AS shipped_qty
                    ");

                $sb = $conn->table('invoice as inv')
                    ->join('ar', 'inv.trans_id', '=', 'ar.id')
                    ->whereNotNull('ar.ordnumber')
                    ->groupBy(['ar.ordnumber', 'inv.parts_id'])
                    ->selectRaw("
                        ar.ordnumber AS ordnumber,
                        inv.parts_id AS parts_id,
                        SUM(inv.qty) AS shipped_qty,
                        MAX(ar.transdate) AS last_invoice_date
                    ");

                $rb = $conn->table('returnitems as rei')
                    ->join('return as ret', 'rei.trans_id', '=', 'ret.id')
                    ->join('invoice as inv', 'inv.id', '=', 'rei.invoice_id')
                    ->join('ar', 'inv.trans_id', '=', 'ar.id')
                    ->whereNotNull('ar.ordnumber')
                    ->where('ret.hasitems', true)
                    ->groupBy(['ar.ordnumber', 'inv.parts_id'])
                    ->selectRaw("
                        ar.ordnumber AS ordnumber,
                        inv.parts_id AS parts_id,
                        SUM(CASE WHEN ret.hasitems = TRUE THEN COALESCE(rei.qty,0) ELSE 0 END) AS return_qty
                    ");

                $sn = $conn->table('employee as e')
                    ->selectRaw('e.id AS salesperson_id, e.name AS salesperson');

                $saleOrder = $conn->query()
                    ->fromSub($ob, 'ob')
                    ->leftJoinSub($sb, 'sb', function ($j) {
                        $j->on('sb.ordnumber', '=', 'ob.ordnumber')
                            ->on('sb.parts_id', '=', 'ob.parts_id');
                    })
                    ->leftJoinSub($rb, 'rb', function ($j) {
                        $j->on('rb.ordnumber', '=', 'ob.ordnumber')
                            ->on('rb.parts_id', '=', 'ob.parts_id');
                    })
                    ->leftJoinSub($sn, 'sn', function ($j) {
                        $j->on('sn.salesperson_id', '=', 'ob.saleperson_id');
                    })
                    ->leftJoin('parts as p', 'p.id', '=', 'ob.parts_id')
                    ->leftJoin('customer as cus', 'cus.id', '=', 'ob.customer_id')
                    ->where('p.partnumber', $sku)
                    ->whereBetween('ob.order_date', [
                        Carbon::now()->subYear()->toDateString(),
                        Carbon::now()->toDateString(),
                    ])
                    ->where('ob.shipped_or_received', false)
                    ->where('ob.invoiced', false)
                    ->whereRaw("ob.ordered_qty - (COALESCE(sb.shipped_qty,ob.shipped_qty,0) - COALESCE(rb.return_qty,0)) > 0")
                    ->orderBy('ob.order_date')
                    ->selectRaw("
                        ob.po,
                        ob.company,
                        ob.ordnumber,
                        ob.parts_id,
                        p.partnumber AS part_code,
                        p.description AS part_name,
                        ob.ordered_qty,
                        COALESCE(sb.shipped_qty, ob.shipped_qty , 0) AS shipped_qty,
                        COALESCE(rb.return_qty,0) AS return_qty,
                        (COALESCE(sb.shipped_qty,0) - COALESCE(rb.return_qty,0)) AS shipped_qty_net,
                        (ob.ordered_qty - (COALESCE(sb.shipped_qty,ob.shipped_qty,0) - COALESCE(rb.return_qty,0))) AS backorder_qty,
                        ob.order_date,
                        ob.due_date,
                        sb.last_invoice_date,
                        ob.customer_id,
                        cus.name AS customer_name,
                        ob.saleperson_id AS salesperson_id,
                        sn.salesperson,
                        ob.shipped_or_received,
                        ob.invoiced
                    ")
                    ->get();
            }

            /** ====================== Find RM Part ======================= */
            if ($useWire) {
                $findRMWire = DB::connection('pgsqlw')
                    ->table('parts as p')
                    ->join('workorder as wo', 'p.id', '=', 'wo.parts_id')
                    ->join('workorderusage as wou', 'wo.id', '=', 'wou.workorder_id')
                    ->leftJoin('parts as rm', function ($join) {
                        $join->on('wou.parts_id', '=', 'rm.id')
                            ->on('p.f4', '=', 'rm.partnumber');   // เทียบคอลัมน์กับคอลัมน์
                    })
                    ->where('p.partnumber', $sku)
                    ->where('p.active', true)
                    ->where('wou.qty', '>', 0)
                    ->where('rm.totalonhand', '>', 0)
                    ->distinct()
                    ->select('rm.partnumber', 'rm.id')
                    ->get();
            }
            if ($usePlus) {
                $findRMPlus = DB::connection('pgsqlp')
                    ->table('parts as p')
                    ->join('workorder as wo', 'p.id', '=', 'wo.parts_id')
                    ->join('workorderusage as wou', 'wo.id', '=', 'wou.workorder_id')
                    ->leftJoin('parts as rm', function ($join) {
                        $join->on('wou.parts_id', '=', 'rm.id')
                            ->on('p.f4', '=', 'rm.partnumber');   // เทียบคอลัมน์กับคอลัมน์
                    })
                    ->where('p.partnumber', $sku)
                    ->where('p.active', true)
                    ->where('wou.qty', '>', 0)
                    ->where('rm.totalonhand', '>', 0)
                    ->distinct()
                    ->select('rm.partnumber', 'rm.id')
                    ->get();
            }

            //dd($findRMWire ?? null, $findRMPlus ?? null);

            /** ======================== RM (Wire) ======================== */
            $rmWire = collect();
            if ($useWire) {
                // Subquery: normalize ลูกค้า + เก็บคอลัมน์ที่ใช้ group
                $suByCust = DB::connection('pgsqlw')
                    ->table('parts as p')
                    ->join('serializeunits as su', 'su.parts_id', '=', 'p.id')
                    ->join('serializeunitsmvmt as sus', 'sus.su_id', '=', 'su.id')
                    ->leftJoin('workorderreceive as wr', function ($j) {
                        $j->on('wr.invoice_id', '=', 'sus.invoice_id')
                            ->on('wr.parts_id', '=', 'p.id');
                    })
                    ->leftJoin('workorder as wo', function ($j) {
                        $j->on('wo.id', '=', 'wr.workorder_id')
                            ->on('wo.parts_id', '=', 'p.id');
                    })
                    ->leftJoin(DB::raw('"return" as r'), 'r.id', '=', 'sus.trans_id')
                    ->leftJoin('customer as cwo', 'cwo.id', '=', 'wo.customer_id')
                    ->leftJoin('customer as cr', function ($j) {
                        $j->on('cr.id', '=', DB::raw('r.customer_id'));
                    })
                    ->where('p.active', true)
                    ->where('p.partnumber', $findRMWire[0]->partnumber ?? null)
                    ->selectRaw("
                        p.id AS parts_id,
                        p.partnumber,
                        COALESCE(cwo.id, cr.id, 0) AS customer_id_norm,
                        COALESCE(NULLIF(TRIM(COALESCE(cwo.name, cr.name)), ''), 'Menam Plus') AS customer_name_norm,
                        wo.workordernumber,
                        sus.transdate,
                        sus.qty,
                        su.onhand
                    ");

                $rmWire = DB::connection('pgsqlw')
                    ->query()
                    ->fromSub($suByCust, 's')
                    ->join('parts as p', 'p.id', '=', 's.parts_id')
                    ->join('partstype as pt', 'pt.id', '=', 'p.partstype_id')
                    ->join('partscategory as pc', 'pc.id', '=', 'p.partscategory_id')
                    ->join('partsgroup as pg', 'pg.id', '=', 'p.partsgroup_id')
                    ->groupBy([
                        'p.partnumber',
                        'p.description',
                        's.customer_name_norm',
                        'p.unit',
                        'pt.description',
                        'pc.categorynumber',
                        'pc.description',
                        'pg.groupnumber',
                        'pg.description'
                    ])
                    ->selectRaw("
                        p.partnumber,
                        p.description,
                        s.customer_name_norm AS customer,
                        'Wire' AS site,
                        MAX(s.transdate) AS transdate_max,
                        SUM(CASE WHEN s.qty > 0 THEN s.qty ELSE 0 END) AS receive_qty,
                        SUM(CASE WHEN s.onhand THEN s.qty ELSE 0 END)   AS balance_qty,
                        (SUM(CASE WHEN s.qty > 0 THEN s.qty ELSE 0 END)
                         - SUM(CASE WHEN s.onhand THEN s.qty ELSE 0 END)) AS issue_qty,
                        p.unit AS unit_name,
                        pt.description AS type_desc,
                        pc.categorynumber AS cat_no,
                        pc.description AS cat_desc,
                        pg.groupnumber AS grp_no,
                        pg.description AS grp_desc
                    ")
                    ->orderBy('p.partnumber')
                    ->orderBy('s.customer_name_norm')
                    ->get();
            }


            /** ======================== RM (Plus) ======================== */
            $rmPlus = collect();
            if ($usePlus) {

                $suByCust = DB::connection('pgsqlp')
                    ->table('parts as p')
                    ->join('serializeunits as su', 'su.parts_id', '=', 'p.id')
                    ->join('serializeunitsmvmt as sus', 'sus.su_id', '=', 'su.id')
                    ->leftJoin('workorderreceive as wr', function ($j) {
                        $j->on('wr.invoice_id', '=', 'sus.invoice_id')
                            ->on('wr.parts_id', '=', 'p.id');
                    })
                    ->leftJoin('workorder as wo', function ($j) {
                        $j->on('wo.id', '=', 'wr.workorder_id')
                            ->on('wo.parts_id', '=', 'p.id');
                    })
                    ->leftJoin(DB::raw('"return" as r'), 'r.id', '=', 'sus.trans_id')
                    ->leftJoin('customer as cwo', 'cwo.id', '=', 'wo.customer_id')
                    ->leftJoin('customer as cr', function ($j) {
                        $j->on('cr.id', '=', DB::raw('r.customer_id'));
                    })
                    ->where('p.active', true)
                    ->where('p.partnumber', $findRMPlus[0]->partnumber ?? null)
                    ->selectRaw("
                        p.id AS parts_id,
                        p.partnumber,
                        COALESCE(cwo.id, cr.id, 0) AS customer_id_norm,
                        COALESCE(NULLIF(TRIM(COALESCE(cwo.name, cr.name)), ''), 'Menam Plus') AS customer_name_norm,
                        wo.workordernumber,
                        sus.transdate,
                        sus.qty,
                        su.onhand
                    ");

                $rmPlus = DB::connection('pgsqlp')
                    ->query()
                    ->fromSub($suByCust, 's')
                    ->join('parts as p', 'p.id', '=', 's.parts_id')
                    ->join('partstype as pt', 'pt.id', '=', 'p.partstype_id')
                    ->join('partscategory as pc', 'pc.id', '=', 'p.partscategory_id')
                    ->join('partsgroup as pg', 'pg.id', '=', 'p.partsgroup_id')
                    ->groupBy([
                        'p.partnumber',
                        'p.description',
                        's.customer_name_norm',
                        'p.unit',
                        'pt.description',
                        'pc.categorynumber',
                        'pc.description',
                        'pg.groupnumber',
                        'pg.description',
                    ])
                    ->selectRaw("
                        p.partnumber,
                        p.description,
                        s.customer_name_norm AS customer,
                        'Wire' AS site,
                        MAX(s.transdate) AS transdate_max,
                        SUM(CASE WHEN s.qty > 0 THEN s.qty ELSE 0 END) AS receive_qty,
                        SUM(CASE WHEN s.onhand THEN s.qty ELSE 0 END)   AS balance_qty,
                        (SUM(CASE WHEN s.qty > 0 THEN s.qty ELSE 0 END)
                         - SUM(CASE WHEN s.onhand THEN s.qty ELSE 0 END)) AS issue_qty,
                        p.unit AS unit_name,
                        pt.description AS type_desc,
                        pc.categorynumber AS cat_no,
                        pc.description AS cat_desc,
                        pg.groupnumber AS grp_no,
                        pg.description AS grp_desc
                    ")
                    ->orderBy('p.partnumber')
                    ->orderBy('s.customer_name_norm')
                    ->get();

                //dd($rmWire, $rmPlus);
            }

            return [
                'FGWire' => $fgWire,
                'FGPlus' => $fgPlus,
                'WipWire' => $wipWire,
                'WipPlus' => $wipPlus,
                'MNCWire' => $mfgWire,
                'MNCPlus' => $mfgPlus,
                'SalesOrder' => $saleOrder,
                'RMWire'    => $rmWire,
                'RMPlus'    => $rmPlus,
            ];
        });

        //dd($data);
        // ──────────────────────── Combine / Map ─────────────────────────
        $FGWire     = $data['FGWire'];
        $FGPlus     = $data['FGPlus'];
        $WipWire    = $data['WipWire'];
        $WipPlus    = $data['WipPlus'];
        $MNCWire    = $data['MNCWire'];
        $MNCPlus    = $data['MNCPlus'];
        $SalesOrder = $data['SalesOrder'];
        $RMWire     = $data['RMWire'];
        $RMPlus     = $data['RMPlus'];

        //dd($WipWire, $WipPlus, $MNCWire, $MNCPlus);

        /** ---------- FG (Wire + Plus) ---------- */
        $fgBase = collect();
        if ($useWire) $fgBase = $fgBase->concat($FGWire);
        if ($usePlus) $fgBase = $fgBase->concat($FGPlus);

        $fgItemsAll = $fgBase
            ->groupBy(function ($r) {
                $pn   = $r->rm_partnumber ?? $r->partnumber ?? '';
                $site = $r->site ?? '';
                $cust = $r->customer ?? $r->customer_name ?? '';
                $wo   = $r->workordernumber ?? '';
                return "{$pn}|{$site}|{$cust}|{$wo}";
            })
            ->map(function ($rows) {
                $first = $rows->first();
                $customerName = $first->customer ?? $first->customer_name ?? null;

                return (object) [
                    'rm_partnumber' => $first->rm_partnumber ?? $first->partnumber ?? null,
                    'part_desc'     => $first->part_desc ?? $first->description ?? null,
                    'receive_qty'   => $rows->sum(fn($r) => (float) ($r->receive_qty ?? 0)),
                    'issue_qty'     => $rows->sum(fn($r) => (float) ($r->issue_qty ?? 0)),
                    'balance_qty'   => $rows->sum(fn($r) => (float) ($r->balance_qty ?? 0)),
                    'unit_name'     => $first->unit_name ?? null,
                    'type_desc'     => $first->type_desc ?? null,
                    'cat_no'        => $first->cat_no ?? null,
                    'cat_desc'      => $first->cat_desc ?? null,
                    'grp_no'        => $first->grp_no ?? null,
                    'grp_desc'      => $first->grp_desc ?? null,
                    'transdate_max' => $rows->max(fn($r) => $r->transdate_max ?? null),
                    'site'          => $first->site ?? null,
                    'customer'      => $customerName,
                    'customer_id'   => $first->customer_id ?? null,
                    'workordernumber' => $first->workordernumber ?? null
                ];
            })
            ->sortByDesc('transdate_max')
            ->values();

        $fgMap = $fgItemsAll
            ->filter(fn($r) => !empty($r->workordernumber))
            ->mapWithKeys(fn($r) => [
                $r->workordernumber => [
                    'issued'  => (float)($r->issue_qty ?? 0),
                    'qty'     => (float)($r->receive_qty ?? 0),
                    'balance' => (float)($r->balance_qty ?? 0),
                ]
            ]);
        //dd($fgMap, $MNCWire, $MNCPlus);
        /** ---------- WIP (Wire + Plus) ---------- */
        $wipBase = collect();
        if ($useWire) $wipBase = $wipBase->concat($WipWire);
        if ($usePlus) $wipBase = $wipBase->concat($WipPlus);

        $wipItemsAll = $wipBase
            ->groupBy(fn($r) => ($r->workordernumber ?? ($r->{'เลขที่คำสั่งผลิต'} ?? Str::uuid())) . '|' . ($r->site ?? ''))
            ->map(function ($rows) {
                $f = $rows->first();
                return (object) [
                    'workordernumber' => $f->workordernumber ?? ($f->{'เลขที่คำสั่งผลิต'} ?? null),
                    'dateopen'        => $rows->min(fn($r) => $r->dateopen ?? ($r->{'วันที่เปิด'} ?? null)),
                    'reqdate'         => $rows->max(fn($r) => $r->reqdate ?? ($r->{'วันที่ส่ง'} ?? null)),
                    'qty'             => $rows->sum(fn($r) => (float)($r->{'จำนวนสั่งผลิต'} ?? $r->qty ?? 0)),
                    'issued'          => $rows->sum(fn($r) => (float)($r->{'เบิกวัตถุดิบ'} ?? 0)),
                    'ok'              => $rows->sum(fn($r) => (float)($r->{'ของดี'} ?? 0)),
                    'ng'              => $rows->sum(fn($r) => (float)($r->{'ของเสีย'} ?? 0)),
                    'return_rm'       => $rows->sum(fn($r) => (float)($r->{'คืนวัตถุดิบ'} ?? 0)),
                    'Balance'         => $rows->sum(fn($r) => (float)($r->balance ?? 0)),
                    'customer'        => ($v = trim((string)($f->name ?? $f->ลูกค้า ?? ''))) !== '' ? $v : null,
                    'site'            => $f->site ?? null,
                ];
            })
            ->sortByDesc('dateopen')
            ->values();

        $wipMap = $wipItemsAll
            ->filter(fn($r) => !empty($r->workordernumber))
            ->mapWithKeys(fn($r) => [
                $r->workordernumber => [
                    'issued'  => (float)($r->issued ?? 0),
                    'qty'     => (float)($r->qty ?? 0),
                    'balance' => (float)($r->Balance ?? 0),
                ]
            ]);
        //dd($wipMap);
        $wipItemsFiltered = !empty($selectedCustomer)
            ? $wipItemsAll->filter(fn($r) => in_array(($r->customer ?? ''), $selectedCustomer))->values()
            : $wipItemsAll;


        //dd($wipItemsFiltered);
        /** ---------- Booked (Menucost) ---------- */
        $bookBase = collect();
        if ($useWire) $bookBase = $bookBase->concat($MNCWire);
        if ($usePlus) $bookBase = $bookBase->concat($MNCPlus);

        $bookedItemsAll = $bookBase
            ->groupBy(fn($r) => ($r->{'เลขที่คำสั่งผลิต'} ?? Str::uuid()) . '|' . ($r->site ?? ''))
            ->map(function ($rows) use ($wipMap) {
                $first   = $rows->first();
                $wo      = $first->{'เลขที่คำสั่งผลิต'} ?? null;
                $issued  = $wipMap[$wo]['issued'] ?? 0;
                $qty     = $rows->sum(fn($r) => (float)($r->จำนวน ?? 0));
                $unissued = max(0, $qty - $issued);

                return (object) [
                    'workordernumber' => $wo,
                    'dateopen'        => $rows->min(fn($r) => $r->{'วันที่เปิด'} ?? null),
                    'reqdate'         => $rows->max(fn($r) => $r->{'วันที่ส่ง'} ?? null),
                    'quantity'        => $unissued,
                    'customer' => ($v = trim((string)($first->{'ลูกค้า'} ?? ''))) !== '' ? $v : null,
                    'description'     => $first->{'สินค้า'} ?? null,
                    'rm_partnumber'   => $first->{'รหัสวัตถุดิบ'} ?? null,
                    'rm_description'  => $first->{'วัตถุดิบ'} ?? null,
                    'site'            => $first->site ?? null,
                    'dateclose'       => $first->dateclose ?? null,
                ];
            })
            ->sortByDesc('dateopen')
            ->values();



        /** ---------- FG after Booked filter ---------- */
        $fgAfterBooked = $fgItemsAll
            ->filter(fn($r) => (float)($r->balance_qty ?? 0) > 0)
            ->sortByDesc('transdate_max')
            ->values();

        $wipAfterBooked = $wipItemsAll
            ->filter(fn($r) => (float)($r->Balance ?? 0) > 0)
            ->sortByDesc('transdate_max')
            ->values();

        $nullishDate = fn($v) => is_null($v)
            || (is_string($v) && trim($v) === '')
            || in_array((string)$v, ['0000-00-00', '0000-00-00 00:00:00', '1900-01-01', '1970-01-01'], true);

        // สรุป dateclose ราย WO (จากฝั่ง booked)
        $bookCloseMap = $bookedItemsAll
            ->groupBy(fn($r) => ($r->workordernumber ?? null) . '|' . ($r->site ?? ''))
            ->map(function ($rows) {
                $first = $rows->first();
                return (object)[
                    'workordernumber' => $first->workordernumber ?? null,
                    'dateclose'       => $rows->max(fn($r) => $r->dateclose ?? ($r->{'วันที่ปิด'} ?? null)),
                ];
            })
            ->filter(fn($o) => !empty($o->workordernumber))
            ->mapWithKeys(fn($o) => [$o->workordernumber => $o->dateclose]);


        $wipAfterBooked = $wipItemsAll
            ->filter(function ($r) use ($bookCloseMap, $nullishDate) {
                $wo = $r->workordernumber ?? null;

                // ถ้า WO นี้มีใน booked และ dateclose ไม่ว่าง => ซ่อน
                if (!empty($wo) && isset($bookCloseMap[$wo])) {
                    $dc = $bookCloseMap[$wo];


                    if (!$nullishDate($dc)) {
                        return false; // ปิดแล้ว ไม่ให้โชว์
                    }
                }
                //dump($wo);
                // ผ่านเงื่อนไข dateclose แล้ว ค่อยเช็ค Balance > 0 ตามเดิม
                return (float)($r->Balance ?? 0) > 0;
            })
            ->sortByDesc(fn($r) => $r->transdate_max ?? $r->reqdate ?? $r->dateopen)
            ->values();

        $bookedItemsAll = $bookBase
            ->groupBy(fn($r) => ($r->{'เลขที่คำสั่งผลิต'} ?? Str::uuid()) . '|' . ($r->site ?? ''))
            ->map(function ($rows) use ($wipMap) {
                $first   = $rows->first();
                $wo      = $first->{'เลขที่คำสั่งผลิต'} ?? null;
                $issued  = $wipMap[$wo]['issued'] ?? 0;
                $qty     = $rows->sum(fn($r) => (float)($r->จำนวน ?? 0));
                $unissued = max(0, $qty - $issued);

                return (object) [
                    'workordernumber' => $wo,
                    'dateopen'        => $rows->min(fn($r) => $r->{'วันที่เปิด'} ?? null),
                    'reqdate'         => $rows->max(fn($r) => $r->{'วันที่ส่ง'} ?? null),
                    'quantity'        => $unissued,
                    'customer' => ($v = trim((string)($first->{'ลูกค้า'} ?? ''))) !== '' ? $v : null,
                    'description'     => $first->{'สินค้า'} ?? null,
                    'rm_partnumber'   => $first->{'รหัสวัตถุดิบ'} ?? null,
                    'rm_description'  => $first->{'วัตถุดิบ'} ?? null,
                    'site'            => $first->site ?? null,
                    'dateclose'       => $first->dateclose ?? null,
                ];
            })
            ->filter(function ($r) use ($wipMap, $fgMap) {
                $wo = $r->workordernumber;
                $dateclose = $r->dateclose;
                //dump($wo);
                $bookQty  = (float)($r->quantity ?? 0);

                if ($bookQty <= 0) return false;
                if (empty($wo)) return true;

                if (isset($wipMap[$wo]) && $wipMap[$wo]['issued'] >= $wipMap[$wo]['qty']) {
                    return false;
                }

                $fgQty = (float)($fgMap->get($wo)['qty'] ?? 0);
                if ($fgQty >= $bookQty) {
                    return false;
                }

                if ($dateclose != '') {
                    return false;
                }
                return true;
            })
            ->sortByDesc('dateopen')
            ->values();

        //dd($wipItemsFiltered, $wipAfterBooked, $bookedItemsAll);

        /** ---------- Sales Order (Wire) ---------- */
        $soItemsAll = $SalesOrder
            ->groupBy(fn($r) => ($r->ordnumber ?? '') . '|' . ($r->part_code ?? ''))
            ->map(function ($rows) {
                $f = $rows->first();
                $toNum = fn($v) => (float) (is_numeric($v) ? $v : preg_replace('/[^\d\.\-]/', '', (string) ($v ?? 0)));
                return (object)[
                    'ordnumber'        => $f->ordnumber ?? null,
                    'transdate'        => $f->order_date ?? null,
                    'due_date'         => $rows->max(fn($r) => $r->due_date ?? null),
                    'salesperson'      => $f->salesperson ?? null,
                    'customer'         => $f->customer_name ?? null,
                    'partnumber'       => $f->part_code ?? null,
                    'part_description' => $f->part_name ?? null,
                    'part_type'        => null,
                    'unit_price'       => $toNum($f->unit_price ?? 0),
                    'po'               => $f->po ?? null,
                    'ordered'          => $rows->max(fn($r) => $toNum($r->ordered_qty ?? 0)),
                    'shipped'          => $rows->sum(fn($r) => $toNum($r->shipped_qty ?? 0)),
                    'backorder'        => $rows->sum(fn($r) => $toNum($r->backorder_qty ?? 0)),
                ];
            })
            ->sortByDesc('transdate')
            ->values();



        //dump($fgItemsFiltered, $bookedItemsFiltered);
        /** ---------- RM (Wire + Plus) ---------- */
        $rmBase = collect();
        if ($useWire) $rmBase = $rmBase->concat($RMWire);
        if ($usePlus) $rmBase = $rmBase->concat($RMPlus);

        $rmItemsAll = $rmBase
            ->groupBy(function ($r) {
                $pn   = $r->rm_partnumber ?? $r->partnumber ?? '';
                $site = $r->site ?? '';
                $cust = $r->customer ?? $r->customer_name ?? '';
                $wo   = $r->workordernumber ?? '';
                return "{$pn}|{$site}|{$cust}|{$wo}";
            })
            ->map(function ($rows) use ($fgItemsAll) {

                $first = $rows->first();
                $pn      = $fgItemsAll[0]->rm_partnumber ?? null;
                //dump($pn);
                $customerName = $first->customer ?? $first->customer_name ?? null;

                return (object) [
                    'fg_partnumber' => $pn,
                    'rm_partnumber' => $first->rm_partnumber ?? $first->partnumber ?? null,
                    'part_desc'     => $first->part_desc ?? $first->description ?? null,
                    'receive_qty'   => $rows->sum(fn($r) => (float) ($r->receive_qty ?? 0)),
                    'issue_qty'     => $rows->sum(fn($r) => (float) ($r->issue_qty ?? 0)),
                    'balance_qty'   => $rows->sum(fn($r) => (float) ($r->balance_qty ?? 0)),
                    'unit_name'     => $first->unit_name ?? null,
                    'type_desc'     => $first->type_desc ?? null,
                    'cat_no'        => $first->cat_no ?? null,
                    'cat_desc'      => $first->cat_desc ?? null,
                    'grp_no'        => $first->grp_no ?? null,
                    'grp_desc'      => $first->grp_desc ?? null,
                    'transdate_max' => $rows->max(fn($r) => $r->transdate_max ?? null),
                    'site'          => $first->site ?? null,
                    'customer'      => $customerName,
                    'customer_id'   => $first->customer_id ?? null,
                ];
            })
            ->sortByDesc('transdate_max')
            ->values();

        $bookedItemsFiltered = !empty($selectedCustomer)
            ? $bookedItemsAll->filter(fn($r) => in_array(($r->customer ?? ''), $selectedCustomer))->values()
            : $bookedItemsAll;

        $wipItemsFiltered = !empty($selectedCustomer)
            ? $wipAfterBooked->filter(fn($r) => in_array(($r->customer ?? ''), $selectedCustomer))->values()
            : $wipAfterBooked;

        $fgItemsFiltered = !empty($selectedCustomer)
            ? $fgAfterBooked->filter(fn($r) => in_array(($r->customer ?? ''), $selectedCustomer))->values()
            : $fgAfterBooked;


        $soItemsFiltered = !empty($selectedCustomer)
            ? $soItemsAll->filter(fn($r) => in_array(($r->customer ?? ''), $selectedCustomer))->values()
            : $soItemsAll;

        $rmItemsFiltered = !empty($selectedCustomer)
            ? $rmItemsAll->filter(fn($r) => in_array(($r->customer ?? ''), $selectedCustomer))->values()
            : $rmItemsAll;


        /** ---------- Summary + Pagination ---------- */
        $wipQty = $wipItemsFiltered->sum('Balance');
        $bookedQty = $bookedItemsFiltered->sum('quantity');
        $fgQty = $fgItemsFiltered->sum('balance_qty');
        $soQty = $soItemsFiltered->sum('backorder');
        $rmQty = $rmItemsFiltered->sum('balance_qty');
        $needQty = $soQty - ($fgQty + $wipQty + $bookedQty);


        $totalRowWIP = (object) [
            'workordernumber' => 'รวมทั้งหมด',
            'dateopen'        => null,
            'reqdate'         => null,
            'qty'             => $wipItemsFiltered->sum('qty'),
            'issued'          => $wipItemsFiltered->sum('issued'),
            'ok'              => $wipItemsFiltered->sum('ok'),
            'ng'              => $wipItemsFiltered->sum('ng'),
            'return_rm'       => $wipItemsFiltered->sum('return_rm'),
            'Balance'         => $wipItemsFiltered->sum('Balance'),
            'customer'        => null,
            'grp_desc'        => null,
            'transdate_max'   => null,
            'site'            => null,
        ];
        $wipItemsView = $wipItemsFiltered->push($totalRowWIP);

        $totalRowBook = (object) [
            'workordernumber' => 'รวมทั้งหมด',
            'dateopen'        => null,
            'reqdate'         => null,
            'quantity'        => $bookedItemsFiltered->sum('quantity'),
            'customer'        => null,
            'description'     => null,
            'rm_partnumber'   => null,
            'rm_description'  => null,
            'site'            => null,
        ];
        $bookedItemsView = $bookedItemsFiltered->push($totalRowBook);

        $totalRowFG = (object) [
            'rm_partnumber' => 'รวมทั้งหมด',
            'part_desc'     => null,
            'receive_qty'   => $fgItemsFiltered->sum('receive_qty'),
            'issue_qty'     => $fgItemsFiltered->sum('issue_qty'),
            'balance_qty'   => $fgItemsFiltered->sum('balance_qty'),
            'unit_name'     => null,
            'type_desc'     => null,
            'cat_no'        => null,
            'cat_desc'      => null,
            'grp_no'        => null,
            'grp_desc'      => null,
            'transdate_max' => null,
            'site'          => null,
            'customer'      => null,
            'customer_id'   => null,
        ];
        $fgItems = $fgItemsFiltered->push($totalRowFG);

        $totalRowSO = (object) [
            'ordnumber'        => 'รวมทั้งหมด',
            'transdate'        => null,
            'due_date'         => null,
            'salesperson'      => null,
            'customer'         => null,
            'partnumber'       => null,
            'part_description' => null,
            'part_type'        => null,
            'unit_price'       => null,
            'po'               => null,
            'ordered'          => $soItemsFiltered->sum('ordered'),
            'shipped'          => $soItemsFiltered->sum('shipped'),
            'backorder'        => $soItemsFiltered->sum('backorder'),
        ];
        $soItemsView = $soItemsFiltered->push($totalRowSO);

        $totalRowRM = (object) [
            'rm_partnumber' => 'รวมทั้งหมด',
            'part_desc'     => null,
            'receive_qty'   => $rmItemsFiltered->sum('receive_qty'),
            'issue_qty'     => $rmItemsFiltered->sum('issue_qty'),
            'balance_qty'   => $rmItemsFiltered->sum('balance_qty'),
            'unit_name'     => null,
            'type_desc'     => null,
            'cat_no'        => null,
            'cat_desc'      => null,
            'grp_no'        => null,
            'grp_desc'      => null,
            'transdate_max' => null,
            'site'          => null,
            'customer'      => null,
            'customer_id'   => null,
        ];
        $rmItems = $rmItemsFiltered->push($totalRowRM);


        $fgPageNum  = (int) request()->query('fg_page', 1);
        $wipPageNum = (int) request()->query('wip_page', 1);
        $bookPageNum = (int) request()->query('mnc_page', 1);
        $soPageNum  = (int) request()->query('so_page', 1);
        $rmPageNum  = (int) request()->query('rm_page', 1);

        $fgPage = new LengthAwarePaginator(
            $fgItems->forPage($fgPageNum, $perPage),
            $fgItems->count(),
            $perPage,
            $fgPageNum,
            ['path' => request()->url(), 'query' => request()->query(), 'pageName' => 'fg_page']
        );
        $wipPage = new LengthAwarePaginator(
            $wipItemsView->forPage($wipPageNum, $perPage),
            $wipItemsView->count(),
            $perPage,
            $wipPageNum,
            ['path' => request()->url(), 'query' => request()->query(), 'pageName' => 'wip_page']
        );
        $bookPage = new LengthAwarePaginator(
            $bookedItemsView->forPage($bookPageNum, $perPage),
            $bookedItemsView->count(),
            $perPage,
            $bookPageNum,
            ['path' => request()->url(), 'query' => request()->query(), 'pageName' => 'mnc_page']
        );
        $soPage = new LengthAwarePaginator(
            $soItemsView->forPage($soPageNum, $perPage),
            $soItemsView->count(),
            $perPage,
            $soPageNum,
            ['path' => request()->url(), 'query' => request()->query(), 'pageName' => 'so_page']
        );
        $rmPage = new LengthAwarePaginator(
            $rmItems->forPage($rmPageNum, $perPage),
            $rmItems->count(),
            $perPage,
            $rmPageNum,
            ['path' => request()->url(), 'query' => request()->query(), 'pageName' => 'rm_page']
        );

        // รายชื่อลูกค้า (จากชุด All)
        $customers = collect([$wipItemsAll, $bookedItemsAll, $soItemsAll, $fgItemsAll, $rmItemsAll])
            ->flatMap(fn($c) => $c)
            ->pluck('customer')
            ->filter()
            ->map(fn($s) => trim((string)$s))
            ->unique()
            ->sort()
            ->values();
        //dd($customers);
        // meta/result
        $meta =
            ($fgItemsFiltered->first() ?? null) ??
            ($wipItemsFiltered->first() ?? null) ??
            ($bookedItemsFiltered->first() ?? null) ??
            ($soItemsFiltered->first() ?? null) ??
            ($rmItemsFiltered->first() ?? null) ??
            (null);
        //dump($meta);
        $result = $meta ? [
            'sku'        => $sku,
            'name'       => $meta->part_desc ?? ($meta->สินค้า ?? $meta->part_description ?? ''),
            'unit'       => $meta->unit_name ?? 'กก.',
            'type'       => $meta->type_desc ?? ($meta->part_type ?? ''),
            'category'   => trim(($meta->cat_no ?? '') . ' ' . ($meta->cat_desc ?? '')),
            'group'      => trim(($meta->grp_no ?? '') . ' ' . ($meta->grp_desc ?? '')),
            'last_date'  => $meta->transdate_max ?? $meta->dateopen ?? $meta->transdate ?? null,
            'fg_qty'     => $fgQty,
            'wip_qty'    => $wipQty,
            'booked_qty' => $bookedQty,
            'so_qty'     => $soQty,
            'need_qty'   => $needQty,
            'rm_qty'     => $rmQty
        ] : null;

        $datasets = [
            'fg'  => $fgPage,
            'wip' => $wipPage,
            'so'  => $soPage,
            'mnc' => $bookPage,
            'rm'  => $rmPage
        ];

        return view('formpp.originator', [
            'sku'      => $sku,
            'result'   => $result,
            'datasets' => $datasets,
            'customers' => $customers,
            'selectedCustomer' => $selectedCustomer,
            'selectedSrc' => $selectedSrc,
        ]);
    }

    public function sales_action(Request $request, $id)
    {
        $userId = $request->user()->id;


        $data = $request->validate([
            'part_no'           => 'required|string|max:100',
            'customers'         => 'nullable|array',
            'customers.*'       => 'string|max:255',
            'part_name'         => 'nullable|string|max:255',
            'type'              => 'nullable|string|max:255',
            'category'          => 'nullable|string|max:255',
            'group'             => 'nullable|string|max:255',
            'reason'            => 'nullable|string|max:255',

            // Sales input
            'sales_fg'          => 'nullable|numeric',
            'sales_wip'         => 'nullable|numeric',
            'sales_mfg'         => 'nullable|numeric',
            'sales_order'       => 'nullable|numeric',
            'sales_prod'        => 'nullable|numeric',
            'sales_rm'          => 'nullable|numeric',
            // Planner input
            'planner_fg'        => 'nullable|numeric',
            'planner_wip'       => 'nullable|numeric',
            'planner_mfg'       => 'nullable|numeric',
            'planner_order'     => 'nullable|numeric',
            'planner_prod'      => 'nullable|numeric',
            'planner_rm'        => 'nullable|numeric',
        ]);

        $payload = [
            // Planner (ถ้ามีคอลัมน์เหล่านี้ในตาราง ให้เติมด้วย)
            'sales_fg'      => $data['sales_fg'] ?? 0,
            'sales_wip'     => $data['sales_wip'] ?? 0,
            'sales_mfg'     => $data['sales_mfg'] ?? 0,
            'sales_order'   => $data['sales_order'] ?? 0,
            'sales_prod'    => $data['sales_prod'] ?? 0,
            'sales_rm'      => $data['sales_rm'] ?? 0,
        ];

        //dd($request, $id);
        // ดึงฟอร์ม + เช็คสิทธิ์เป็นผู้อนุมัติของ step ปัจจุบัน
        $form = WorkflowDb::findForm('pp', (int) $id);
        abort_unless($form, 404);

        $auth = WorkflowDb::table('pp', 'wf_form_authorizes')
            ->where('wf_form_id', $form->id)
            ->where('step_no', $form->current_step_no)
            ->where('approver_user_id', $userId)
            ->first();

        if (!$auth) abort(403, 'คุณไม่มีสิทธิ์อนุมัติฟอร์มนี้');
        if (strtoupper($auth->status) !== 'PENDING') {
            return back()->with('warning', 'ฟอร์มนี้ถูกดำเนินการแล้ว')->withInput();
        }
        $action = strtolower((string) $request->input('action')); // 'approve' | 'reject'

        // dd($action);
        if ($action === 'reject') {
            // ต้องมีเหตุผลเวลา Reject
            $request->validate([
                'reason' => 'required|string|max:2000',
            ], [
                'reason.required' => 'กรุณาระบุเหตุผลการ Reject',
            ]);

            WorkflowEngine::reject((int)$form->id, (int)$userId, (string) $request->input('reason'), 'pp');

            return redirect()
                ->route('pp.view', ['id' => $id])
                ->with('ok', 'ปฏิเสธคำขอเรียบร้อย ');
            //return back()->with('success', 'ปฏิเสธคำขอเรียบร้อย');
        }

        if ($action === 'approve') {

            DB::table('pp_data')
                ->where('form_id', $id)
                ->update($payload);


            // comment เป็น optional
            $comment = (string) $request->input('comment', '');
            WorkflowEngine::approve((int)$form->id, (int)$userId, $comment, 'pp');

            return redirect()
                ->route('pp.view', ['id' => $id])
                ->with('ok', 'อนุมัติสำเร็จ');
            //return back()->with('success', 'อนุมัติสำเร็จ');
        }

        // action ไม่ถูกต้อง
        return back()->with('warning', 'ไม่รู้จักคำสั่งที่ส่งมา')->withInput();
    }
}
