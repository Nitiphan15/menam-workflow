<?php

namespace App\Http\Controllers\FormWR;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Carbon\Carbon;

use App\Exports\WirerodExport;
use App\Exports\WirerodReservationSheet;
use App\Services\FormWR\ReservedStockService;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;


class IncomeController extends Controller
{
    public function index(Request $req)
    {
        $today = Carbon::today('Asia/Bangkok');

        // 1) Filters
        $years = $req->input('year', [now('Asia/Bangkok')->year]); // year[] จาก form
        $years = array_values(array_unique(array_map('intval', (array)$years)));
        $years = array_filter($years);

        $vendors   = (array) $req->input('vendor', []);
        $itemLike  = trim((string) $req->input('item', ''));
        $poLike    = trim((string) $req->input('po', ''));
        $companies = (array) $req->input('company', ['MENAM PLUS', 'MENAM WIRE']);

        // 2) ดึง PO open (เฉพาะ Part ขึ้นต้น R)
        $connPo = 'pgsqlw';
        $params = [];
        $where  = "oe.ordnumber LIKE 'POR%' AND oe.shipped_or_received = false AND UPPER(TRIM(parts.partnumber)) LIKE 'R%' ";

        if ($itemLike !== '') {
            $where .= " AND (UPPER(TRIM(parts.partnumber)) LIKE :item OR UPPER(parts.description) LIKE :item)";
            $params['item'] = '%' . Str::upper($itemLike) . '%';
        }
        if ($poLike !== '') {
            $where .= " AND UPPER(oe.ordnumber) LIKE :po";
            $params['po'] = '%' . Str::upper($poLike) . '%';
        }
        if (!empty($vendors)) {
            $binds = [];
            foreach ($vendors as $i => $v) {
                $k = "v{$i}";
                $binds[] = ":{$k}";
                $params[$k] = $v;
            }
            $where .= " AND vendor.name IN (" . implode(',', $binds) . ")";
        }
        if (!empty($years)) {
            $ph = [];
            foreach ($years as $i => $y) {
                $k = "y{$i}";
                $ph[] = ":{$k}";
                $params[$k] = $y;
            }
            $where .= " AND EXTRACT(YEAR FROM oe.reqdate) IN (" . implode(',', $ph) . ")";
        }

        $wantWire = in_array('MENAM WIRE', $companies, true);
        $wantPlus = in_array('MENAM PLUS', $companies, true);
        $fetchOpenPO = function (string $conn, string $companyLabel) use ($where, $params) {
            $sql = <<<SQL
                SELECT
                    oe.ordnumber AS po_no,
                    oe.transdate AS po_date,
                    oe.reqdate   AS req_date,
                    oe.shipped_or_received,
                    orderitems.sellprice,
                    ROUND(
                        orderitems.sellprice /
                        CASE 
                            WHEN oe.curr = 'USD' THEN 35.00
                            WHEN oe.curr = 'EUR' THEN 38.00
                            WHEN oe.curr = 'THB' THEN 1
                            ELSE 1
                        END
                    , 2) AS price_thb,
                    oe.amount,
                    oe.netamount,
                    oe.discountamount,
                    oe.curr,
                    orderitems.unit        AS unit,
                    oe.terms,
                    orderitems.parts_id,
                    orderitems.qty         AS qty_order,
                    ROUND(COALESCE(SUM(receiveitems.qty), 0), 2)                      AS qty_received,
                    ROUND((orderitems.qty - COALESCE(SUM(receiveitems.qty), 0)), 2)   AS qty_open,
                    vendor.vendornumber   AS vendor_code,
                    vendor.name           AS vendor_name,
                    parts.partnumber      AS rm_partnumber,
                    parts.description     AS description,
                    orderitems.f3,
                    orderitems.f1,
                    pr.reqnumber          AS pr_reqnumber
    
                FROM oe
                JOIN orderitems ON oe.id = orderitems.trans_id
                JOIN vendor ON oe.vendor_id = vendor.id
                JOIN parts ON orderitems.parts_id = parts.id
                JOIN pr ON pr.oe_id = oe.id
                LEFT JOIN receiveitems ON orderitems.id = receiveitems.orderitems_id
                WHERE {$where} 
                GROUP BY
                    oe.ordnumber,
                    oe.transdate,
                    oe.reqdate,
                    oe.shipped_or_received,
                    orderitems.sellprice,
                    oe.amount,
                    oe.netamount,
                    oe.discountamount,
                    oe.curr,
                    orderitems.unit,
                    oe.terms,
                    orderitems.parts_id,
                    orderitems.qty,
                    vendor.vendornumber,
                    vendor.name,
                    parts.partnumber,
                    orderitems.f3,
                    orderitems.f1,
                    parts.description,
                    pr.reqnumber
                ORDER BY
                    parts.partnumber
                SQL;

            return collect(DB::connection($conn)->select($sql, $params))
                ->map(function ($r) use ($companyLabel) {
                    $a = (array) $r;
                    $a['rm_partnumber'] = Str::upper($a['rm_partnumber']);
                    $openValue = (float)$a['qty_open'] * (float)$a['price_thb'];

                    return [
                        'po_no'         => $a['po_no'],
                        'po_date'       => $a['po_date'],
                        'req_date'      => $a['req_date'],
                        'vendor'        => $a['vendor_name'],
                        'rm_partnumber' => $a['rm_partnumber'],
                        'description'   => $a['description'],
                        'qty'           => (float)$a['qty_order'],
                        'received'      => (float)$a['qty_received'],
                        'open'          => (float)$a['qty_open'],
                        'price_thb'     => (float)$a['price_thb'],
                        'open_value'    => (float)$openValue,
                        'company'       => $companyLabel,
                    ];
                });
        };

        // รวมผลลัพธ์จากฐานที่เลือก
        $poLinesAll = collect();
        if ($wantWire) $poLinesAll = $poLinesAll->merge($fetchOpenPO('pgsqlw', 'MENAM WIRE'));
        if ($wantPlus) $poLinesAll = $poLinesAll->merge($fetchOpenPO('pgsqlp', 'MENAM PLUS'));

        // แยกชุดตามคำสั่งล่าสุดของคุณ
        // ซ้าย + ตารางล่าง = ยังไม่รับแต่ยังไม่เกินกำหนด -> req_date >= today & open > 0
        $poNotDue = $poLinesAll->filter(
            fn($r) =>
            Carbon::parse($r['req_date'])->gte($today) && ($r['open'] ?? 0) > 0
        )->values();

        // ขวา = ยังไม่รับแต่ "เลยกำหนด" -> req_date < today & open > 0
        $poOverdue = $poLinesAll->filter(
            fn($r) =>
            Carbon::parse($r['req_date'])->lt($today) && ($r['open'] ?? 0) > 0
        )->values();

        // ---------- ฝั่งซ้าย (สรุปรายเดือน) ใช้ชุด not-due ----------
        // เดือนที่มียอดค้างและยังไม่ถึงกำหนด (สร้างอัตโนมัติจาก $poNotDue)
        $months = $poNotDue
            ->map(fn($r) => (int) Carbon::parse($r['req_date'])->month)
            ->unique()->sort()->values()->all();
        if (empty($months)) {
            $months = [(int) now('Asia/Bangkok')->month];
        }

        $monthOrder  = array_values($months);
        $monthLabels = collect($monthOrder)->mapWithKeys(function ($m) {
            // ป้ายหัวตาราง (ไทยย่อ)
            $label = Carbon::create(null, $m, 1)->locale('th')->isoFormat('MMM');
            return [$m => $label];
        })->all();
        // สร้างตารางซ้ายแบบ dynamic โดยอิงเดือนใน $monthOrder
        $monthSet = collect($monthOrder);
        $poByItemMonth = $poNotDue
            ->groupBy('rm_partnumber')
            ->map(function ($rows, $item) use ($monthSet) {
                $desc = (string) \Illuminate\Support\Arr::first($rows)['description'];
                $byMonth = $monthSet->mapWithKeys(function ($m) use ($rows) {
                    $sum = $rows->filter(fn($r) => (int)\Carbon\Carbon::parse($r['req_date'])->month === (int)$m)
                        ->sum('open');
                    return [(int)$m => (float)$sum];
                })->all();
                $total = (float)$rows->sum('open');
                return array_merge(
                    ['item' => $item, 'description' => $desc],
                    $this->expandMonthColumns($byMonth), // จะได้ key เป็น m<month> เช่น m10, m11
                    ['total' => $total]
                );
            })
            ->values()->sortBy('item')->all();

        // รวมท้ายตาราง (sum per month + total)
        $sumMonth = [];
        $sumMonthVals = collect($poByItemMonth);
        foreach ($monthOrder as $m) {
            $sumMonth['m' . $m] = (float) $sumMonthVals->sum('m' . $m);
        }
        $sumMonth['total'] = (float) $sumMonthVals->sum('total');


        // ---------- ฝั่งขวา ----------
        // balance = ยอดคงเหลือรวมจาก movement 
        // open    = ยอดค้าง "ที่เลยกำหนด" (req_date < today)
        $balanceAll = collect();
        if (in_array('MENAM WIRE', $companies, true)) {
            $balanceAll = $balanceAll->merge($this->fetchBalancePerItem('pgsqlw', 'MENAM WIRE', null, $itemLike, true));
        }
        if (in_array('MENAM PLUS', $companies, true)) {
            $balanceAll = $balanceAll->merge($this->fetchBalancePerItem('pgsqlp', 'MENAM PLUS', null, $itemLike, true));
        }
        $balanceIndex = $balanceAll->groupBy('rm_partnumber')->map(fn($g) => (float)$g->sum('balance'));
        $companyIndex = $balanceAll->groupBy('rm_partnumber')->map(fn($g) => $g->pluck('company')->unique()->implode(', '));

        // open ฝั่งขวา = ค้างที่ "เลยกำหนด"

        //$openByItemOverdue = $poOverdue->groupBy('rm_partnumber')->map->sum('open');
        $openByItemOverdue = $poOverdue
            ->groupBy('rm_partnumber')
            ->map->sum('open');

        // รวม key และจัดแพ็ก

        /*$allItems = $balanceIndex->keys()->merge($openByItemOverdue->keys())->unique()->values();
        $balanceWithOpen = $allItems->map(function ($item) use ($balanceIndex, $companyIndex, $openByItemOverdue) {
            return [
                'item'    => $item,
                'company' => $companyIndex[$item] ?? '',
                'balance' => (float)($balanceIndex[$item] ?? 0.0),
                'open'    => (float)($openByItemOverdue[$item] ?? 0.0),
            ];
        })
            ->filter(fn($r) => ($r['balance'] ?? 0) > 0 || ($r['open'] ?? 0) > 0)
            ->sortBy('item')->values()->all();*/
        $overduePoIndex = $poOverdue
            ->groupBy('rm_partnumber')
            ->map(function ($rows) {
                return $rows->map(function ($r) {
                    return [
                        'po_no'    => $r['po_no'],
                        'req_date' => $r['req_date'],
                        'open'     => (float) ($r['open'] ?? 0),  // หน่วยเดียวกับ open เดิม (ยังไม่คูณ 1000)
                        'vendor'   => $r['vendor'] ?? null,
                    ];
                })->values()->all();
            });

        // รวม key และจัดแพ็ก
        $allItems = $balanceIndex->keys()->merge($openByItemOverdue->keys())->unique()->values();

        $balanceWithOpen = $allItems
            ->map(function ($item) use ($balanceIndex, $companyIndex, $openByItemOverdue, $overduePoIndex) {
                return [
                    'item'       => $item,
                    'company'    => $companyIndex[$item] ?? '',
                    'balance'    => (float) ($balanceIndex[$item] ?? 0.0),
                    'open'       => (float) ($openByItemOverdue[$item] ?? 0.0),
                    'overdue_po' => $overduePoIndex[$item] ?? [],   // << เพิ่มคีย์นี้
                ];
            })
            ->filter(fn($r) => ($r['balance'] ?? 0) > 0 || ($r['open'] ?? 0) > 0)
            ->sortBy('item')
            ->values()
            ->all();
        //dd($balanceWithOpen);


        $totals = [
            'balance' => (float) collect($balanceWithOpen)->sum('balance'),
            'open'    => (float) collect($balanceWithOpen)->sum('open'),
        ];
        $SCALE = 1000;

        // ---------- KPI (อิง not-due ฝั่งซ้าย/ล่าง) ----------
        $kpi = [
            'po_lines_not_due' => (int) $poNotDue->count(),
            'open_not_due'     => (float) $poNotDue->sum('open') * $SCALE,
            'open_overdue'     => (float) $poOverdue->sum('open') * $SCALE,
            'open_value_thb'   => (float) $poNotDue->sum('open_value'),
        ];

        // ---------- Scale 1000 ----------


        // แถวฝั่งซ้าย (by_month แบบ dynamic)
        $poByItemMonthForView = collect($poByItemMonth)->map(function ($r) use ($monthOrder, $SCALE) {
            $row = [
                'item'        => $r['item'],
                'description' => $r['description'] ?? '',
                'by_month'    => [],
                'total'       => ($r['total'] ?? 0) * $SCALE,
            ];
            foreach ($monthOrder as $m) {
                $row['by_month'][$m] = ($r['m' . $m] ?? 0) * $SCALE;
            }
            return $row;
        })->values()->all();

        // รวมท้ายตารางฝั่งซ้าย (dynamic)
        $sumMonthForView = [
            'by_month' => collect($monthOrder)->mapWithKeys(function ($m) use ($sumMonth, $SCALE) {
                return [$m => ($sumMonth['m' . $m] ?? 0) * $SCALE];
            })->all(),
            'total'    => ($sumMonth['total'] ?? 0) * $SCALE,
        ];

        // ตารางล่าง (คูณ 1000 เฉพาะ qty/received/open)
        $poLinesForDetailScaled = $poNotDue->map(function ($l) use ($SCALE) {
            $l['qty']      = ($l['qty'] ?? 0) * $SCALE;
            $l['received'] = ($l['received'] ?? 0) * $SCALE;
            $l['open']     = ($l['open'] ?? 0) * $SCALE;
            return $l;
        });
        $rawTotalsScaled = [
            'qty'            => (float) $poLinesForDetailScaled->sum('qty'),
            'received'       => (float) $poLinesForDetailScaled->sum('received'),
            'open'           => (float) $poLinesForDetailScaled->sum('open'),
            'open_value_thb' => (float) $poNotDue->sum('open_value'), // THB ไม่คูณ
        ];

        $baseYear = !empty($years) ? max($years) : now('Asia/Bangkok')->year;

        // ---------- Options & Selected ----------
        $filters = [
            'vendors' => $this->allVendors($connPo),
            'years'   => range($baseYear - 3, $baseYear + 1),
        ];

        $selected = [
            'vendor'  => $vendors,
            'item'    => $itemLike,
            'po'      => $poLike,
            'year'    => $years,
            'company' => $companies,
        ];

        /*$balanceWithOpenScaled = collect($balanceWithOpen)
            ->map(function ($r) use ($SCALE) {
                $r['open'] = ($r['open'] ?? 0) * $SCALE;   // คูณเฉพาะค้างส่ง
                return $r;
            })
            ->values()
            ->all();*/

        $balanceWithOpenScaled = collect($balanceWithOpen)
            ->map(function ($r) use ($SCALE) {
                // คูณยอด open รวม
                $r['open'] = ($r['open'] ?? 0) * $SCALE;

                // คูณยอด open ของแต่ละ PO ด้วย
                if (!empty($r['overdue_po'])) {
                    $r['overdue_po'] = collect($r['overdue_po'])
                        ->map(function ($po) use ($SCALE) {
                            $po['open'] = ($po['open'] ?? 0) * $SCALE;
                            return $po;
                        })
                        ->all();
                }

                return $r;
            })
            ->values()
            ->all();

        //dd($balanceIndex, $allItems, $balanceWithOpen, $balanceWithOpenScaled);
        // รวมผลรวมให้ตรงกับสิ่งที่แสดง (balance ไม่คูณ, open คูณแล้ว)
        $totals = [
            'balance' => (float) collect($balanceWithOpenScaled)->sum('balance'),
            'open'    => (float) collect($balanceWithOpenScaled)->sum('open'),
        ];

        /** ======================== FG (Wire) ======================== */
        $fgWire = collect();
        if ($wantWire) {
            $sku = trim((string) $req->input('sku', ''));
            if ($sku === '') $sku = $itemLike;

            if ($sku !== '') {
                $suByCust = DB::connection('pgsqlw')
                    ->table('parts as p')
                    ->join('serializeunits as su', 'su.parts_id', '=', 'p.id')
                    ->join('serializeunitsmvmt as sus', 'sus.su_id', '=', 'su.id')
                    ->leftJoin('workorderreceive as wr', function ($j) {
                        $j->on('wr.invoice_id', '=', 'sus.invoice_id')
                            ->on('wr.parts_id',   '=', 'p.id');
                    })
                    ->leftJoin('workorder as wo', function ($j) {
                        $j->on('wo.id',       '=', 'wr.workorder_id')
                            ->on('wo.parts_id', '=', 'p.id');
                    })
                    ->leftJoin(DB::raw('"return" as r'), 'r.id', '=', 'sus.trans_id')
                    ->leftJoin('customer as cwo', 'cwo.id', '=', 'wo.customer_id')
                    ->leftJoin('customer as cr',  'cr.id',  '=', DB::raw('r.customer_id'))
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
                        SUM(CASE WHEN s.onhand IS TRUE THEN s.qty ELSE 0 END) AS balance_qty,
                        (SUM(CASE WHEN s.qty > 0 THEN s.qty ELSE 0 END)
                        - SUM(CASE WHEN s.onhand IS TRUE THEN s.qty ELSE 0 END)) AS issue_qty,
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
        }

        //dump($fgWire);
        /** ======================== FG (Plus) ======================== */
        $fgPlus = collect();
        if ($wantPlus) {
            $sku = trim((string) $req->input('sku', ''));
            if ($sku === '') $sku = $itemLike;

            if ($sku !== '') {
                $suByCustPlus = DB::connection('pgsqlp')
                    ->table('parts as p')
                    ->join('serializeunits as su', 'su.parts_id', '=', 'p.id')
                    ->join('serializeunitsmvmt as sus', 'sus.su_id', '=', 'su.id')
                    ->leftJoin('workorderreceive as wr', function ($j) {
                        $j->on('wr.invoice_id', '=', 'sus.invoice_id')
                            ->on('wr.parts_id',   '=', 'p.id');
                    })
                    ->leftJoin('workorder as wo', function ($j) {
                        $j->on('wo.id',       '=', 'wr.workorder_id')
                            ->on('wo.parts_id', '=', 'p.id');
                    })
                    ->leftJoin(DB::raw('"return" as r'), 'r.id', '=', 'sus.trans_id')
                    ->leftJoin('customer as cwo', 'cwo.id', '=', 'wo.customer_id')
                    ->leftJoin('customer as cr',  'cr.id',  '=', DB::raw('r.customer_id'))
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
                        'Plus' AS site,
                        MAX(s.transdate) AS transdate_max,
                        SUM(CASE WHEN s.qty > 0 THEN s.qty ELSE 0 END) AS receive_qty,
                        SUM(CASE WHEN s.onhand IS TRUE THEN s.qty ELSE 0 END) AS balance_qty,
                        (SUM(CASE WHEN s.qty > 0 THEN s.qty ELSE 0 END)
                        - SUM(CASE WHEN s.onhand IS TRUE THEN s.qty ELSE 0 END)) AS issue_qty,
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
        }

        /** ---------- FG (Wire + Plus) merge ---------- */
        $fgBase = collect();
        if ($wantWire) $fgBase = $fgBase->concat($fgWire);
        if ($wantPlus) $fgBase = $fgBase->concat($fgPlus);

        $fgItemsAll = $fgBase
            ->groupBy(function ($r) {
                $pn   = $r->rm_partnumber ?? $r->partnumber ?? '';
                $site = $r->site ?? '';
                $cust = $r->customer ?? '';
                $wo   = $r->workordernumber ?? '';
                return "{$pn}|{$site}|{$cust}|{$wo}";
            })
            ->map(function ($rows) {
                $first = $rows->first();
                return (object) [
                    'rm_partnumber'   => $first->rm_partnumber ?? $first->partnumber ?? null,
                    'part_desc'       => $first->part_desc ?? $first->description ?? null,
                    'receive_qty'     => (float) $rows->sum(fn($r) => (float) ($r->receive_qty ?? 0)),
                    'issue_qty'       => (float) $rows->sum(fn($r) => (float) ($r->issue_qty ?? 0)),
                    'balance_qty'     => (float) $rows->sum(fn($r) => (float) ($r->balance_qty ?? 0)),
                    'unit_name'       => $first->unit_name ?? null,
                    'type_desc'       => $first->type_desc ?? null,
                    'cat_no'          => $first->cat_no ?? null,
                    'cat_desc'        => $first->cat_desc ?? null,
                    'grp_no'          => $first->grp_no ?? null,
                    'grp_desc'        => $first->grp_desc ?? null,
                    'transdate_max'   => $rows->max(fn($r) => $r->transdate_max ?? null),
                    'site'            => $first->site ?? null,
                    'customer'        => $first->customer ?? null,
                    'customer_id'     => $first->customer_id ?? null,
                    'workordernumber' => $first->workordernumber ?? null,
                ];
            })
            ->sortByDesc('transdate_max')
            ->values();

        /** map ไว้ใช้ง่าย ๆ ตาม WO */
        $fgMap = $fgItemsAll
            ->filter(fn($r) => !empty($r->workordernumber))
            ->mapWithKeys(fn($r) => [
                $r->workordernumber => [
                    'issued'   => (float) ($r->issue_qty ?? 0),
                    'qty'      => (float) ($r->receive_qty ?? 0),
                    'balance'  => (float) ($r->balance_qty ?? 0),
                    'site'     => $r->site ?? null,
                    'customer' => $r->customer ?? null,
                    'part'     => $r->rm_partnumber ?? null,
                ]
            ]);

        $fgItemsAll = $fgItemsAll ?? collect();
        $fgMap      = $fgMap ?? collect();


        $reservationService = app(ReservedStockService::class);
        $stockReport = $reservationService->report($companies, $itemLike);
        $balanceWithOpenScaled = $reservationService->mergeBalances($balanceWithOpenScaled, $stockReport);
        foreach (['balance', 'open', 'reserved', 'net'] as $column) {
            $totals[$column] = (float) collect($balanceWithOpenScaled)->sum($column);
        }

        // ---------- ส่งให้ Blade ----------
        return view('formwr.index', [
            'stockReport'     => $stockReport,
            'monthOrder'      => $monthOrder,    // << ใช้ตัวเดียวให้ชัด
            'monthLabels'     => $monthLabels,
            'poByItemMonth'   => $poByItemMonthForView,
            'sumMonth'        => $sumMonthForView,
            'balanceWithOpen' => $balanceWithOpenScaled,
            'totals'          => $totals,
            'kpi'             => $kpi,
            'poLines'         => $poLinesForDetailScaled, // ใช้ตัวที่คูณแล้ว
            'rawTotals'       => $rawTotalsScaled,        // ใช้ตัวที่คูณแล้ว
            'filters'         => $filters,
            'selected'        => $selected,
            'fgItemsAll' => $fgItemsAll,
            'fgMap'      => $fgMap,
        ]);
    }



    // ============================
    // Helpers
    // ============================

    /**
     * ดึง Balance ต่อ item จากฐานที่ระบุ (เฉพาะ part เริ่มด้วย R)
     */
    private function fetchBalancePerItem(string $connection, string $companyLabel, int $year = null, string $itemLike = '', bool $onlyR = false)
    {
        $where  = '1=1';
        $params = [];

        if ($onlyR) {
            // ตัดช่องว่างก่อนเช็คขึ้นต้นด้วย R
            $where .= " AND UPPER(TRIM(p.partnumber)) LIKE 'R%'";
        }

        if ($year) {
            $where .= ' AND EXTRACT(YEAR FROM pm.transdate) <= :y';
            $params['y'] = $year;
        }
        if ($itemLike !== '') {
            $where .= ' AND (UPPER(TRIM(p.partnumber)) LIKE :item OR UPPER(p.description) LIKE :item)';
            $params['item'] = '%' . Str::upper($itemLike) . '%';
        }

        $sql = <<<SQL
            SELECT TRIM(p.partnumber) AS rm_partnumber, p.description, SUM(pm.qty) AS balance
            FROM partsmvmt pm
            JOIN parts p ON pm.parts_id = p.id
            WHERE {$where}
            GROUP BY TRIM(p.partnumber), p.description
        SQL;

        return collect(DB::connection($connection)->select($sql, $params))
            ->map(function ($r) use ($companyLabel) {
                $a = (array) $r;
                return [
                    'rm_partnumber' => Str::upper($a['rm_partnumber']),
                    'description'   => $a['description'],
                    'balance'       => (float) $a['balance'],
                    'company'       => $companyLabel,
                ];
            });
    }



    /** ขยายคีย์เดือนเป็น m1..m12 */
    private function expandMonthColumns(array $byMonth): array
    {
        $out = [];
        foreach ($byMonth as $m => $val) {
            $out[$this->monthKey((int) $m)] = (float) $val;
        }
        return $out;
    }
    private function monthKey(int $m): string
    {
        return 'm' . $m;
    }

    private function allVendors(string $connection): array
    {
        try {
            $rows = DB::connection($connection)->select("SELECT DISTINCT name FROM vendor ORDER BY name");
            return collect($rows)->map(fn($r) => $r->name)->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ============================
    // Export (CSV) — เฉพาะ PO ที่ part เริ่มด้วย R
    // ============================
    public function export(Request $req)
    {
        $today     = Carbon::today('Asia/Bangkok');
        $years = $req->input('year', [now('Asia/Bangkok')->year]);
        $years = array_values(array_unique(array_map('intval', (array)$years)));
        $years = array_filter($years);
        $vendors   = (array) $req->input('vendor', []);
        $itemLike  = trim((string) $req->input('item', ''));
        $poLike    = trim((string) $req->input('po', ''));
        $companies = (array) $req->input('company', ['MENAM PLUS', 'MENAM WIRE']);

        // --- WHERE & PARAMS (เหมือนหน้า index) ---
        $params = [];
        $where  = "oe.ordnumber LIKE 'POR%' 
               AND oe.shipped_or_received = false
               AND UPPER(TRIM(parts.partnumber)) LIKE 'R%'";

        if ($itemLike !== '') {
            $where .= " AND (UPPER(TRIM(parts.partnumber)) LIKE :item OR UPPER(parts.description) LIKE :item)";
            $params['item'] = '%' . Str::upper($itemLike) . '%';
        }
        if ($poLike !== '') {
            $where .= " AND UPPER(oe.ordnumber) LIKE :po";
            $params['po'] = '%' . Str::upper($poLike) . '%';
        }
        if (!empty($vendors)) {
            $binds = [];
            foreach ($vendors as $i => $v) {
                $k = "v{$i}";
                $binds[] = ":{$k}";
                $params[$k] = $v;
            }
            $where .= " AND vendor.name IN (" . implode(',', $binds) . ")";
        }
        if (!empty($years)) {
            $ph = [];
            foreach ($years as $i => $y) {
                $k = "y{$i}";
                $ph[] = ":{$k}";
                $params[$k] = $y;
            }
            $where .= " AND EXTRACT(YEAR FROM oe.reqdate) IN (" . implode(',', $ph) . ")";
        }

        $wantWire = in_array('MENAM WIRE', $companies, true);
        $wantPlus = in_array('MENAM PLUS', $companies, true);

        // --- fetch จากฐานที่เลือก (W/P) ---
        $fetchOpenPO = function (string $conn, string $companyLabel) use ($where, $params) {
            $sql = <<<SQL
        SELECT
            oe.ordnumber AS po_no,
            oe.transdate AS po_date,
            oe.reqdate   AS req_date,
            orderitems.qty                    AS qty_order,
            COALESCE(SUM(receiveitems.qty),0) AS qty_received,
            ROUND(orderitems.qty - COALESCE(SUM(receiveitems.qty),0), 2) AS qty_open,
            vendor.name           AS vendor_name,
            parts.partnumber      AS rm_partnumber,
            parts.description     AS description,
            ROUND(
                orderitems.sellprice /
                CASE 
                    WHEN oe.curr = 'USD' THEN 35.00
                    WHEN oe.curr = 'EUR' THEN 38.00
                    WHEN oe.curr = 'THB' THEN 1
                    ELSE 1
                END
            , 2) AS price_thb
        FROM oe
        JOIN orderitems ON oe.id = orderitems.trans_id
        JOIN vendor     ON oe.vendor_id = vendor.id
        JOIN parts      ON orderitems.parts_id = parts.id
        LEFT JOIN receiveitems ON orderitems.id = receiveitems.orderitems_id
        WHERE {$where}
        GROUP BY
            oe.ordnumber, oe.transdate, oe.reqdate,
            orderitems.qty, vendor.name, parts.partnumber, parts.description,
            oe.curr, orderitems.sellprice
        ORDER BY parts.partnumber
        SQL;

            return collect(DB::connection($conn)->select($sql, $params))->map(function ($r) use ($companyLabel) {
                $a = (array) $r;
                $a['rm_partnumber'] = Str::upper($a['rm_partnumber']);
                return [
                    'po_no'         => $a['po_no'],
                    'po_date'       => $a['po_date'],
                    'req_date'      => $a['req_date'],
                    'vendor'        => $a['vendor_name'],
                    'rm_partnumber' => $a['rm_partnumber'],
                    'description'   => $a['description'],
                    'qty'           => (float) $a['qty_order'],
                    'received'      => (float) $a['qty_received'],
                    'open'          => (float) $a['qty_open'],
                    'price_thb'     => (float) $a['price_thb'],
                    'open_value'    => (float) $a['qty_open'] * (float) $a['price_thb'], // ราคาไม่คูณสเกล
                    'company'       => $companyLabel,
                ];
            });
        };

        $poLinesAll = collect();
        if ($wantWire) $poLinesAll = $poLinesAll->merge($fetchOpenPO('pgsqlw', 'MENAM WIRE'));
        if ($wantPlus) $poLinesAll = $poLinesAll->merge($fetchOpenPO('pgsqlp', 'MENAM PLUS'));

        // --- Split Not-Due / Overdue ---
        $poNotDue = $poLinesAll->filter(fn($r) => Carbon::parse($r['req_date'])->gte($today) && ($r['open'] ?? 0) > 0)->values();
        $poOverdue = $poLinesAll->filter(fn($r) => Carbon::parse($r['req_date'])->lt($today)  && ($r['open'] ?? 0) > 0)->values();

        // --- Months dynamic (จาก Not-Due) ---
        $months = $poNotDue->map(fn($r) => (int) Carbon::parse($r['req_date'])->month)->unique()->sort()->values()->all();
        if (!$months) $months = [(int) now('Asia/Bangkok')->month];
        $monthOrder  = array_values($months);
        $monthLabels = collect($monthOrder)->mapWithKeys(fn($m) => [$m => Carbon::create(null, $m, 1)->locale('th')->isoFormat('MMM')])->all();

        // --- Summaries (ซ้าย) ---
        $poByItemMonth = $poNotDue
            ->groupBy('rm_partnumber')
            ->map(function ($rows, $item) use ($monthOrder) {
                $desc = (string) Arr::first($rows)['description'];
                $by   = [];
                foreach ($monthOrder as $m) {
                    $by[$m] = (float) $rows->filter(fn($r) => (int) Carbon::parse($r['req_date'])->month === (int) $m)->sum('open');
                }
                $cols = [];
                foreach ($by as $m => $v) $cols['m' . $m] = $v;
                return array_merge(['item' => $item, 'description' => $desc], $cols, ['total' => (float) $rows->sum('open')]);
            })
            ->values()->sortBy('item')->all();

        $sumMonth = [];
        $sumMonthVals = collect($poByItemMonth);
        foreach ($monthOrder as $m) $sumMonth['m' . $m] = (float) $sumMonthVals->sum('m' . $m);
        $sumMonth['total'] = (float) $sumMonthVals->sum('total');

        // --- Balance & Overdue (ขวา) ---
        $balanceAll = collect();
        if ($wantWire) $balanceAll = $balanceAll->merge($this->fetchBalancePerItem('pgsqlw', 'MENAM WIRE', null, $itemLike, true));
        if ($wantPlus) $balanceAll = $balanceAll->merge($this->fetchBalancePerItem('pgsqlp', 'MENAM PLUS', null, $itemLike, true));

        $balanceIndex = $balanceAll->groupBy('rm_partnumber')->map(fn($g) => (float) $g->sum('balance'));
        $companyIndex = $balanceAll->groupBy('rm_partnumber')->map(fn($g) => $g->pluck('company')->unique()->implode(', '));
        $overdueOpen  = $poOverdue->groupBy('rm_partnumber')->map->sum('open');

        $allItems = $balanceIndex->keys()->merge($overdueOpen->keys())->unique()->values();
        $balanceWithOpen = $allItems->map(function ($item) use ($balanceIndex, $companyIndex, $overdueOpen) {
            return [
                'item'    => $item,
                'company' => $companyIndex[$item] ?? '',
                'balance' => (float) ($balanceIndex[$item] ?? 0.0),
                'open'    => (float) ($overdueOpen[$item] ?? 0.0),
            ];
        })->filter(fn($r) => ($r['balance'] ?? 0) > 0 || ($r['open'] ?? 0) > 0)
            ->sortBy('item')->values()->all();

        $reservationService = app(ReservedStockService::class);
        $stockReport = $reservationService->report($companies, $itemLike);
        $balanceWithOpen = $reservationService->mergeBalances($balanceWithOpen, $stockReport);
        $reservationSheets = [new WirerodReservationSheet($stockReport), new WirerodReservationSheet($stockReport, true)];

        // --- SCALE 1000 เฉพาะจำนวน (KG.) ---
        $SCALE = 1000;

        // Sheet 1: Open (Not-Due) by Item (หัวคอลัมน์ dynamic)
        $sheet1Head = array_merge(['Item', 'Description'], array_map(fn($m) => $monthLabels[$m] ?? 'M' . $m, $monthOrder), ['Total']);
        $sheet1Rows = [];
        foreach ($poByItemMonth as $r) {
            $line = [$r['item'], $r['description'] ?? ''];
            foreach ($monthOrder as $m) $line[] = (($r['m' . $m] ?? 0) * $SCALE);
            $line[] = ($r['total'] ?? 0) * $SCALE;
            $sheet1Rows[] = $line;
        }
        // รวมท้าย (optional)
        $sumLine = ['TOTAL', ''];
        foreach ($monthOrder as $m) $sumLine[] = ($sumMonth['m' . $m] ?? 0) * $SCALE;
        $sumLine[] = ($sumMonth['total'] ?? 0) * $SCALE;
        $sheet1Rows[] = [];
        $sheet1Rows[] = $sumLine;

        // Sheet 2: Balance & Overdue (ค้างส่ง * SCALE, balance ไม่คูณ)
        $sheet2Head = ['Item', 'Company', 'Balance (KG.)', 'Overdue Open (KG.)', 'Reserved Remaining (KG.)', 'Net (KG.)'];
        $sheet2Rows = [];
        foreach ($balanceWithOpen as $r) {
            $sheet2Rows[] = [
                $r['item'],
                $r['company'] ?? '',
                (float) ($r['balance'] ?? 0),
                (float) ($r['open'] ?? 0) * $SCALE,
                (float) $r['reserved'],
                (float) $r['net'],
            ];
        }

        // Sheet 3: PO Not-Due (รายละเอียด) — คูณ 1000 เฉพาะ qty/received/open
        $sheet3Head = [
            'PO No',
            'PO Date',
            'Required Date',
            'Vendor',
            'Part Number',
            'Description',
            'Qty Ordered (KG.)',
            'Received (KG.)',
            'Open (KG.)',
            'Price/Unit (THB)',
            'Open Value (THB)'
        ];
        $sheet3Rows = [];
        foreach ($poNotDue as $l) {
            $sheet3Rows[] = [
                $l['po_no'],
                Carbon::parse($l['po_date'])->format('Y-m-d'),
                Carbon::parse($l['req_date'])->format('Y-m-d'),
                $l['vendor'],
                $l['rm_partnumber'],
                $l['description'],
                (float) ($l['qty'] ?? 0) * $SCALE,
                (float) ($l['received'] ?? 0) * $SCALE,
                (float) ($l['open'] ?? 0) * $SCALE,
                (float) ($l['price_thb'] ?? 0),                          // ไม่คูณ
                (float) ($l['open'] ?? 0) * $SCALE * (float) ($l['price_thb'] ?? 0), // มูลค่า = open(คูณแล้ว) * ราคา
            ];
        }

        // Existing PO sheets plus the current Heat and MFG reservation detail.
        $export = new class($sheet1Head, $sheet1Rows, $sheet2Head, $sheet2Rows, $sheet3Head, $sheet3Rows, $monthOrder, $monthLabels, $reservationSheets) implements WithMultipleSheets {
            public function __construct(
                private array $s1Head,
                private array $s1Rows,
                private array $s2Head,
                private array $s2Rows,
                private array $s3Head,
                private array $s3Rows,
                private array $monthOrder,
                private array $monthLabels,
                private array $reservationSheets
            ) {}

            public function sheets(): array
            {
                return [
                    // Sheet #1
                    new class($this->s1Head, $this->s1Rows, $this->monthOrder, $this->monthLabels) implements FromArray, WithHeadings, WithTitle {
                        public function __construct(private array $head, private array $rows, private array $mo, private array $ml) {}
                        public function title(): string
                        {
                            return 'Open (Not Due) by Item';
                        }
                        public function headings(): array
                        {
                            return $this->head;
                        }
                        public function array(): array
                        {
                            return $this->rows;
                        }
                    },
                    // Sheet #2
                    new class($this->s2Head, $this->s2Rows) implements FromArray, WithHeadings, WithTitle {
                        public function __construct(private array $head, private array $rows) {}
                        public function title(): string
                        {
                            return 'Balance & Overdue';
                        }
                        public function headings(): array
                        {
                            return $this->head;
                        }
                        public function array(): array
                        {
                            return $this->rows;
                        }
                    },
                    // Sheet #3
                    new class($this->s3Head, $this->s3Rows) implements FromArray, WithHeadings, WithTitle {
                        public function __construct(private array $head, private array $rows) {}
                        public function title(): string
                        {
                            return 'PO Not Due (Detail)';
                        }
                        public function headings(): array
                        {
                            return $this->head;
                        }
                        public function array(): array
                        {
                            return $this->rows;
                        }
                    },
                    ...$this->reservationSheets,
                ];
            }
        };

        $filename = 'wirerod_incoming_' . now('Asia/Bangkok')->format('Ymd_His') . '.xlsx';
        return Excel::download($export, $filename);
    }

    // === Autocomplete: Item (เฉพาะ R%) ===
    public function itemsSuggest(Request $req)
    {
        $q = trim((string) $req->query('q', ''));
        if ($q === '' || mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $term = '%' . Str::upper($q) . '%';

        $fetch = function (string $connName) use ($term) {
            $sql = "
            SELECT DISTINCT p.partnumber, p.description
            FROM parts p
            WHERE UPPER(p.partnumber) LIKE 'R%'
              AND (
                   UPPER(p.partnumber) LIKE :q
                OR UPPER(p.description) LIKE :q
              )
            ORDER BY p.partnumber
            LIMIT 50
        ";

            $rows = collect(
                DB::connection($connName)->select($sql, ['q' => $term])
            );

            return $rows->map(fn($r) => [
                'source' => $connName,
                'key'    => strtoupper(trim($r->partnumber)),
                'id'     => $r->partnumber,
                'text'   => strtoupper($r->partnumber) . ' — ' . ($r->description ?? ''),
            ]);
        };

        $wire = $fetch('pgsqlw');
        $plus = $fetch('pgsqlp');

        $out = $wire
            ->concat($plus)
            ->unique('key')          // ตัดซ้ำตาม partnumber
            ->sortBy('key')
            ->values()
            ->take(20)
            ->map(fn($x) => [
                'id'   => $x['id'],
                'text' => $x['text'],
            ])
            ->all();

        return response()->json($out);
    }

    // === Autocomplete: PO Number (เฉพาะใบที่มีรายการ R%) ===
    public function poSuggest(Request $req)
    {
        $q = trim((string) $req->query('q', ''));
        if ($q === '' || mb_strlen($q) < 2) return response()->json([]);

        $sql = "
          SELECT DISTINCT oe.ordnumber
          FROM oe
          JOIN orderitems oi ON oi.trans_id = oe.id
          JOIN parts p       ON p.id = oi.parts_id
          WHERE UPPER(oe.ordnumber) LIKE :q
            AND UPPER(p.partnumber) LIKE 'R%'          -- only R
          ORDER BY oe.ordnumber DESC
          LIMIT 20
        ";
        $rows = collect(DB::connection('pgsqlw')->select($sql, ['q' => '%' . Str::upper($q) . '%']));
        $out = $rows->map(fn($r) => ['id' => $r->ordnumber, 'text' => $r->ordnumber])->all();

        return response()->json($out);
    }
}
