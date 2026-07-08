<?php

namespace App\Http\Controllers\FormPP;

use App\Http\Controllers\Controller;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use App\Support\WorkflowDb;
use App\Models\FormPP\PpData;
use App\Services\WorkflowEngine;
use App\Services\ApproverResolver;
use Carbon\Carbon;
use App\Services\SubmissionNotifier;


class ProductionPlanController extends Controller
{

    private function parseCustomersWithProtectedComma(string $s): array
    {
        $parts = array_map('trim', array_filter(explode(',', $s), fn($x) => $x !== ''));

        $out = [];
        for ($i = 0; $i < count($parts); $i++) {
            $cur = $parts[$i];

            if (
                isset($parts[$i + 1]) &&
                preg_match('/\bCO\.$/i', $cur) &&
                preg_match('/^LTD\.?$/i', $parts[$i + 1])
            ) {
                $out[] = $cur . ', ' . $parts[$i + 1]; // => "... CO., LTD."
                $i++; // ข้าม LTD.
                continue;
            }

            $out[] = $cur;
        }

        return $out;
    }
    public function index(Request $r)
    {
        $perPage = 20;


        $sku = trim((string) $r->query('sku', ''));

        $raw = $r->query('customers', []);

        if (is_array($raw)) {
            $selectedCustomer = array_values(array_filter($raw));
        } else {
            $selectedCustomer = $this->parseCustomersWithProtectedComma((string) $raw);
        }
        //$selectedCustomer = array_values(array_filter((array) $r->query('customers', [])));
        $selectedSrc = (array) $r->query('src', []);
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
        $cacheKey = 'pp:v3:' . md5(json_encode([
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
                //$this->ddSql($q);
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

                $dmShipped = $conn->table('oe')
                    ->join('oedm', 'oe.id', '=', 'oedm.ord_id')
                    ->join('dm', 'oedm.dm_id', '=', 'dm.id')
                    ->join('dmpredm', 'dm.id', '=', 'dmpredm.dm_id')
                    ->join('dmitems as dmi', 'dmpredm.dmitems_id', '=', 'dmi.id')
                    ->whereNotNull('oe.ordnumber')
                    ->groupBy(['oe.ordnumber', 'dmi.parts_id'])
                    ->selectRaw("
                        oe.ordnumber AS ordnumber,
                        dmi.parts_id AS parts_id,
                        SUM(dmi.qty) AS shipped_qty
                    ");

                $ob = $conn->table('orderitems as oi')
                    ->join('oe', 'oi.trans_id', '=', 'oe.id')
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
                        oe.invoiced
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
                    ->leftJoinSub($dmShipped, 'dmshipped', function ($j) {
                        $j->on('dmshipped.ordnumber', '=', 'ob.ordnumber')
                            ->on('dmshipped.parts_id', '=', 'ob.parts_id');
                    })
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
                    ->whereRaw("ob.ordered_qty - (COALESCE(sb.shipped_qty, dmshipped.shipped_qty, 0) - COALESCE(rb.return_qty,0)) > 0")
                    ->orderBy('ob.order_date')
                    ->selectRaw("
                        ob.po,
                        ob.company,
                        ob.ordnumber,
                        ob.parts_id,
                        p.partnumber AS part_code,
                        p.description AS part_name,
                        ob.ordered_qty,
                        COALESCE(sb.shipped_qty, dmshipped.shipped_qty, 0) AS shipped_qty,
                        COALESCE(rb.return_qty,0) AS return_qty,
                        (COALESCE(sb.shipped_qty, dmshipped.shipped_qty, 0) - COALESCE(rb.return_qty,0)) AS shipped_qty_net,
                        (ob.ordered_qty - (COALESCE(sb.shipped_qty, dmshipped.shipped_qty, 0) - COALESCE(rb.return_qty,0))) AS backorder_qty,
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

            //dd($saleOrder ?? null);
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


            $rmPartsWire = $findRMWire->pluck('partnumber')->filter()->unique()->values()->all();
            $rmPartsPlus = $findRMPlus->pluck('partnumber')->filter()->unique()->values()->all();

            /** ======================== RM (Wire) ======================== */
            // จำกัดกรอบเวลา movement (เร่งความเร็วมาก) — 5 ปีพอ ถ้าอยากให้ยืดหยุ่นอ่านจาก query string ได้
            $since = Carbon::now()->subYears(5)->toDateString();

            /** ======================== RM (Wire) ======================== */
            $rmWire = collect();
            if ($useWire && !empty($rmPartsWire)) {
                $conn = DB::connection('pgsqlw');

                // movement base: กรองด้วย part+เวลา ก่อน แล้วค่อยไป join ลูกค้า
                $movBase = $conn->table('serializeunits as su')
                    ->join('parts as p', 'p.id', '=', 'su.parts_id')
                    ->join('serializeunitsmvmt as sus', 'sus.su_id', '=', 'su.id')
                    ->leftJoin('partstype as pt', 'pt.id', '=', 'p.partstype_id')
                    ->leftJoin('partscategory as pc', 'pc.id', '=', 'p.partscategory_id')
                    ->leftJoin('partsgroup as pg', 'pg.id', '=', 'p.partsgroup_id')
                    ->where('p.active', true)
                    ->whereIn('p.partnumber', $rmPartsWire)         // หลาย RM
                    ->where('sus.transdate', '>=', $since)
                    ->selectRaw("
                        p.id                AS parts_id,
                        p.partnumber        AS rm_partnumber,
                        p.description       AS rm_description,
                        p.unit              AS unit_name,
                        pt.description      AS type_desc,
                        pc.categorynumber   AS cat_no,
                        pc.description      AS cat_desc,
                        pg.groupnumber      AS grp_no,
                        pg.description      AS grp_desc,
                        sus.invoice_id,
                        sus.trans_id,
                        sus.transdate,
                        sus.qty,
                        su.onhand
                    ");

                // ลูกค้าฝั่งใบรับของ WO (จาก invoice_id)
                $ordCust = $conn->table('workorderreceive as wr')
                    ->join('workorder as wo', 'wo.id', '=', 'wr.workorder_id')
                    ->leftJoin('customer as cwo', 'cwo.id', '=', 'wo.customer_id')
                    ->selectRaw('wr.invoice_id, cwo.id AS oc_customer_id, cwo.name AS oc_customer_name');

                // ลูกค้าฝั่ง Return (จาก trans_id)
                $retCust = $conn->table(DB::raw('"return" as r'))
                    ->leftJoin('customer as cr', 'cr.id', '=', 'r.customer_id')
                    ->selectRaw('r.id AS rc_trans_id, cr.id AS rc_customer_id, cr.name AS rc_customer_name');

                // รวม + จัดกลุ่มตาม customer
                $rmWire = $conn->query()
                    ->fromSub($movBase, 'm')
                    ->leftJoinSub($ordCust, 'oc', function ($j) {
                        $j->on('oc.invoice_id', '=', 'm.invoice_id');
                    })
                    ->leftJoinSub($retCust, 'rc', function ($j) {
                        $j->on('rc.rc_trans_id', '=', 'm.trans_id');
                    })
                    ->groupBy([
                        'm.rm_partnumber',
                        'm.rm_description',
                        'm.unit_name',
                        'm.type_desc',
                        'm.cat_no',
                        'm.cat_desc',
                        'm.grp_no',
                        'm.grp_desc',
                        // ชื่อ/ID ลูกค้าที่ normalize แล้วต้องอยู่ใน group by
                        DB::raw("COALESCE(NULLIF(TRIM(oc.oc_customer_name), ''), NULLIF(TRIM(rc.rc_customer_name), ''), 'Menam Plus')")
                    ])
                    ->selectRaw("
                        m.rm_partnumber AS partnumber,
                        m.rm_description AS description,
                        'Wire' AS site,
                        COALESCE(NULLIF(TRIM(oc.oc_customer_name), ''), NULLIF(TRIM(rc.rc_customer_name), ''), 'Menam Plus') AS customer,
                        MAX(m.transdate) AS transdate_max,
                        SUM(CASE WHEN m.qty > 0 THEN m.qty ELSE 0 END) AS receive_qty,
                        SUM(CASE WHEN m.onhand THEN m.qty ELSE 0 END)   AS balance_qty,
                        (SUM(CASE WHEN m.qty > 0 THEN m.qty ELSE 0 END)
                        - SUM(CASE WHEN m.onhand THEN m.qty ELSE 0 END)) AS issue_qty,
                        m.unit_name,
                        m.type_desc,
                        m.cat_no, m.cat_desc,
                        m.grp_no, m.grp_desc
                    ")
                    ->orderBy('partnumber')
                    ->orderBy('customer')
                    ->get();
            }

            /** ======================== RM (Plus) ======================== */
            $rmPlus = collect();
            if ($usePlus && !empty($rmPartsPlus)) {
                $conn = DB::connection('pgsqlp');

                $movBase = $conn->table('serializeunits as su')
                    ->join('parts as p', 'p.id', '=', 'su.parts_id')
                    ->join('serializeunitsmvmt as sus', 'sus.su_id', '=', 'su.id')
                    ->leftJoin('partstype as pt', 'pt.id', '=', 'p.partstype_id')
                    ->leftJoin('partscategory as pc', 'pc.id', '=', 'p.partscategory_id')
                    ->leftJoin('partsgroup as pg', 'pg.id', '=', 'p.partsgroup_id')
                    ->whereIn('p.partnumber', $rmPartsPlus)
                    ->where('sus.transdate', '>=', $since)
                    ->selectRaw("
                        p.id                AS parts_id,
                        p.partnumber        AS rm_partnumber,
                        p.description       AS rm_description,
                        p.unit              AS unit_name,
                        pt.description      AS type_desc,
                        pc.categorynumber   AS cat_no,
                        pc.description      AS cat_desc,
                        pg.groupnumber      AS grp_no,
                        pg.description      AS grp_desc,
                        sus.invoice_id,
                        sus.trans_id,
                        sus.transdate,
                        sus.qty,
                        su.onhand
                    ");

                $ordCust = $conn->table('workorderreceive as wr')
                    ->join('workorder as wo', 'wo.id', '=', 'wr.workorder_id')
                    ->leftJoin('customer as cwo', 'cwo.id', '=', 'wo.customer_id')
                    ->selectRaw('wr.invoice_id, cwo.id AS oc_customer_id, cwo.name AS oc_customer_name');

                $retCust = $conn->table(DB::raw('"return" as r'))
                    ->leftJoin('customer as cr', 'cr.id', '=', 'r.customer_id')
                    ->selectRaw('r.id AS rc_trans_id, cr.id AS rc_customer_id, cr.name AS rc_customer_name');

                $rmPlus = $conn->query()
                    ->fromSub($movBase, 'm')
                    ->leftJoinSub($ordCust, 'oc', function ($j) {
                        $j->on('oc.invoice_id', '=', 'm.invoice_id');
                    })
                    ->leftJoinSub($retCust, 'rc', function ($j) {
                        $j->on('rc.rc_trans_id', '=', 'm.trans_id');
                    })
                    ->groupBy([
                        'm.rm_partnumber',
                        'm.rm_description',
                        'm.unit_name',
                        'm.type_desc',
                        'm.cat_no',
                        'm.cat_desc',
                        'm.grp_no',
                        'm.grp_desc',
                        DB::raw("COALESCE(NULLIF(TRIM(oc.oc_customer_name), ''), NULLIF(TRIM(rc.rc_customer_name), ''), 'Menam Plus')")
                    ])
                    ->selectRaw("
                    m.rm_partnumber AS partnumber,
                    m.rm_description AS description,
                    'Plus' AS site,
                    COALESCE(NULLIF(TRIM(oc.oc_customer_name), ''), NULLIF(TRIM(rc.rc_customer_name), ''), 'Menam Plus') AS customer,
                    MAX(m.transdate) AS transdate_max,
                    SUM(CASE WHEN m.qty > 0 THEN m.qty ELSE 0 END) AS receive_qty,
                    SUM(CASE WHEN m.onhand THEN m.qty ELSE 0 END)   AS balance_qty,
                    (SUM(CASE WHEN m.qty > 0 THEN m.qty ELSE 0 END)
                    - SUM(CASE WHEN m.onhand THEN m.qty ELSE 0 END)) AS issue_qty,
                    m.unit_name,
                    m.type_desc,
                    m.cat_no, m.cat_desc,
                    m.grp_no, m.grp_desc
                    ")
                    ->orderBy('partnumber')
                    ->orderBy('customer')
                    ->get();
            }
            //dd($rmWire, $rmPlus);
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
            ->filter(fn($o) => (float)($o->balance_qty ?? 0) > 0)
            ->sortByDesc('transdate_max')
            ->values();



        $bookedItemsFiltered = !empty($selectedCustomer)
            ? $bookedItemsAll->filter(fn($r) => in_array(($r->customer ?? ''), $selectedCustomer))->values()
            : $bookedItemsAll;
        //dump($wipItemsFiltered);
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

        //dump($wipItemsFiltered);
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

    function ddSql($query)
    {
        $sql = $query->toSql();
        $bindings = $query->getBindings();

        $fullSql = vsprintf(
            str_replace('?', '%s', $sql),
            array_map(function ($b) {
                if (is_string($b)) {
                    return "'" . str_replace("'", "''", $b) . "'";
                }
                if (is_bool($b)) {
                    return $b ? 'TRUE' : 'FALSE';
                }
                if ($b === null) {
                    return 'NULL';
                }
                if ($b instanceof \Carbon\Carbon) {
                    return "'" . $b->toDateTimeString() . "'";
                }
                return $b;
            }, $bindings)
        );

        dd($fullSql);
    }

    public function store(Request $r)
    {
        $requestDate = $r->input('request_date') ?? now()->toDateString();
        $docuDate = $r->input('docu_date') ?? now()->toDateString();
        //dd($r);
        $data = $r->validate([
            'request_date'      => 'nullable|date',
            'docu_date'         => 'nullable|date',
            'docu_no'           => 'nullable|string|max:50',
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

        //dd($data);
        // แทนที่ค่าที่ขาด
        $data['request_date'] = $requestDate;
        $data['docu_date'] = $docuDate;

        $customers = collect($data['customers'] ?? [])
            ->map(fn($v) => trim((string) $v))
            ->reject(fn($v) => $v === '' || $v === '__ALL__') // ถ้าใช้ sentinel "ทุกลูกค้า"
            ->unique()
            ->values();

        $data['customer']       = $customers->isNotEmpty() ? $customers->implode(',') : 'ทั้งหมด';
        //dd($data);
        return DB::transaction(function () use ($r, $data) {
            $user   = $r->user();
            $departmentId = (int) ($user->department_id ?? 0);

            // gen เลขเอกสาร: DEPT+YY+MM+RUN4
            $docuNo = PpData::generateDocCode('pp');

            $ppId = DB::table('pp_data')->insertGetId([
                'form_id'       => null,
                'req_date'      => $data['request_date'],
                'docu_date'     => $data['docu_date'] ?? now()->toDateString(),
                'docu_no'       => $docuNo,
                'part_no'       => $data['part_no'],
                'part_name'     => $data['part_name'],
                'customer'      => $data['customer'],
                'type'          => $data['type'] ?? '-',
                'category'      => $data['category'] ?? '-',
                'groups'        => $data['group'] ?? '-',

                // Sales data
                'sales_fg'      => $data['sales_fg'] ?? 0,
                'sales_wip'     => $data['sales_wip'] ?? 0,
                'sales_mfg'     => $data['sales_mfg'] ?? 0,
                'sales_order'   => $data['sales_order'] ?? 0,
                'sales_prod'    => $data['sales_prod'] ?? 0,
                'sales_rm'      => $data['sales_rm'] ?? 0,
            ]);

            $options = [
                'form_no'            => $docuNo,
                'request_by_user_id' => (int) $user->id,
                'skip_flags'         => ['sup' => (bool)$r->boolean('skip_sup')],
                'context'            => [
                    'department_id' => $departmentId,
                    'part_no'   => $data['part_no'],
                    'customer'  => $data['customer'] ?? null,
                ],
                'submit_comment'     => $data['reason'] ?? null,
            ];

            // เปิด workflow
            $wfId = WorkflowEngine::submit(
                appCode: 'pp',
                refType: PpData::class,
                refId: $ppId,
                options: $options
            );


            $wfForm = WorkflowDb::table('pp', 'wf_forms')->find($wfId);
            $docuNo = (string) ($wfForm->form_no ?? $docuNo);
            $workflow = WorkflowDb::table('pp', 'workflows')->where('code', $wfForm->app_code)->first();

            $step  = WorkflowDb::table('pp', 'workflow_steps')
                ->where('workflow_id', $workflow->id)
                ->where('step_no', 1)
                ->first();

            $rules = WorkflowDb::table('pp', 'workflow_step_rules')
                ->where('workflow_step_id', $step->id)
                ->orderBy('priority')
                ->get();


            $approvers = collect();
            foreach ($rules as $rule) {
                $approvers = $approvers->merge(
                    ApproverResolver::resolve(
                        $rule,
                        $wfForm,
                        [
                            'department_id' => $departmentId,
                            'part_no'   => $data['part_no'],
                            'customer'  => $data['customer'] ?? null,
                        ],
                        ['sup' => (bool)$r->boolean('skip_sup')]
                    )
                );
            }

            $approvers = $approvers->unique()->values();

            if ($approvers->count() === 1 && $approvers->first() == $user->id) {
                WorkflowEngine::approve($wfId, $user->id, $data['reason'], 'pp');
            }

            // อัปเดต pp_data.form_id ให้ชี้ไปยัง wf_forms ที่เพิ่งสร้าง
            DB::table('pp_data')->where('id', $ppId)->update([
                'form_id' => $wfId,
                'docu_no' => $docuNo,
            ]);


            $nextApprovers = WorkflowDb::table('pp', 'wf_form_authorizes as wa')
                ->join('users as u', 'u.id', '=', 'wa.approver_user_id')
                ->where('wa.wf_form_id', $wfId)
                ->where('wa.status', 'PENDING')
                ->select('u.email', 'u.name', 'wa.approver_user_id')
                ->get();

            $flagtoPlanner = false;
            foreach ($nextApprovers as $users) {
                if ($users->approver_user_id == $user->id) {
                    $flagtoPlanner  = true;
                    break;
                }
            }
            $wf = WorkflowDb::table('pp', 'wf_forms')->find($wfId);

            $emails = collect($nextApprovers)->pluck('email')->filter()->unique()->all();

            /*if (!empty($emails)) {
                Mail::to($emails)->send(new NotifyNextApprovers($nextApprovers->all(), $wf, 'itprogramming@menamstainless.co.th'));
            }*/

            if ($flagtoPlanner) {
                // dd($ppId);
                return redirect()
                    ->route('pp.planner', ['id' => $ppId])
                    ->with('ok', 'ส่งข้อมูลแล้ว (รอ Planner อนุมัติ)');
            } else {
                return redirect()
                    ->route('pp.view', ['id' => $ppId])
                    ->with('ok', 'ส่งข้อมูลแล้ว (รอ Planner อนุมัติ)');
            }
        });
    }
}
