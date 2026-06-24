<?php

namespace App\Repositories\FormDIE;

use Illuminate\Support\Facades\DB;

class WoDetailRepository
{
    public static function connectionFor(string $wo): string
    {
        return str_starts_with(trim($wo), '+') ? 'pgsqlmfgp' : 'pgsqlmfgw';
    }

    public function getHeader(string $wo, string $conn): ?object
    {
        $sql = "
            SELECT id, workordernumber, ordnumber, customer_id, brand, fsize, flen, fcat, fpack,
                   qty AS plan_qty, dateopen, reqdate, plandate, approvedate, dateclose,
                   approved, submit, suspended, priority, description, notes
            FROM workorder
            WHERE workordernumber = :wo
            LIMIT 1
        ";
        $rows = DB::connection($conn)->select($sql, ['wo' => $wo]);
        return $rows[0] ?? null;
    }

    public function getBom(int $woId, string $conn): array
    {
        return DB::connection($conn)->select("
            SELECT b.id, b.parts_id, p.partnumber, p.description AS part_description,
                   b.qty, b.fsize, b.fsupplier, b.fgrade, b.fheatno, b.fcoilno, b.lastupdate
            FROM workorderbom b
            LEFT JOIN parts p ON p.id = b.parts_id
            WHERE b.workorder_id = :id
            ORDER BY b.id
        ", ['id' => $woId]);
    }

    public function getMatUsage(int $woId, string $conn): array
    {
        return DB::connection($conn)->select("
            SELECT w.id, w.parts_id, p.partnumber, p.description AS part_description, p.unit,
                   w.qty, w.unitcost, w.extcost, w.warehousenumber, w.docnumber, w.requestedby, w.requeststamp
            FROM workordermatusage w
            LEFT JOIN parts p ON p.id = w.parts_id
            WHERE w.workorder_id = :id
            ORDER BY w.requeststamp
        ", ['id' => $woId]);
    }

    public function getWorkcenters(int $woId, string $conn): array
    {
        $sql = "
            SELECT wowc.id, wowc.workseq, wowc.workcenter_id, wc.workcenternumber, wc.description AS wc_desc,
                   wowc.msize, wowc.msizein, wowc.msizetolp, wowc.msizetolm, wowc.msizeintolp, wowc.msizeintolm,
                   wowc.description AS step_desc, wowc.notes, wowc.esthour,
                   wowc.block1size, wowc.block2size, wowc.block3size, wowc.block4size, wowc.block5size,
                   wowc.block6size, wowc.block7size, wowc.block8size, wowc.block9size, wowc.block10size,
                   wowc.block11size, wowc.block12size,
                   wowc.block1, wowc.block2, wowc.block3, wowc.block4, wowc.block5,
                   wowc.block6, wowc.block7, wowc.block8, wowc.block9, wowc.block10, wowc.block11, wowc.block12
            FROM workorderworkcenter wowc
            JOIN workcenter wc ON wc.id = wowc.workcenter_id
            WHERE wowc.workorder_id = :id
            ORDER BY wowc.workseq
        ";
        return DB::connection($conn)->select($sql, ['id' => $woId]);
    }

    public function getReceives(int $woId, string $conn): array
    {
        return DB::connection($conn)->select("
            SELECT wr.id, wr.workseq, wr.workcenter_id, wr.workmachine_id, wm.machinenumber, wm.description AS machine_desc,
                   wr.wipitemnumber,
                   wr.heatno, wr.coilno, wr.qty, wr.msize, wr.receivestamp, wr.receiveby,
                   wr.receivetype, wr.reworkreason, wr.warehousenumber
            FROM workorderreceive wr
            LEFT JOIN workmachine wm ON wm.id = wr.workmachine_id
            WHERE wr.workorder_id = :id
            ORDER BY wr.workseq, wr.receivestamp
        ", ['id' => $woId]);
    }

    /**
     * ยอด FG (kg) ที่ผลิตได้จริงต่อ workordernumber = "ยอดดีของ step สุดท้าย"
     * (workseq สูงสุดที่มีการรับ) ตัด NCR/rework (receivetype = 3) ออก
     *
     * เหตุผล: ถ้าบวก FG ทุก step จะนับซ้ำ (ของชิ้นเดียวไหลผ่านทุกสถานี เช่นแผน 1,000
     * แต่รวมได้ 6,733). receivetype = 0 = WIP, 1 = รับของดี, 3 = NCR
     * คืนค่าเป็น array ของ object {workordernumber, fg_kg}
     */
    public function getFgKgByWorkorders(array $workordernumbers, string $conn): array
    {
        $workordernumbers = array_values(array_unique(array_filter($workordernumbers)));
        if (empty($workordernumbers)) return [];

        // เลข WO ใน die history เป็นตัวพิมพ์เล็ก/ปน แต่ MFG เก็บตัวพิมพ์ใหญ่ — เทียบแบบ case-insensitive
        $upper = array_map('strtoupper', $workordernumbers);

        $sql = "
            SELECT UPPER(wo.workordernumber) AS workordernumber, COALESCE(SUM(wr.qty), 0) AS fg_kg
            FROM workorder wo
            JOIN workorderreceive wr ON wr.workorder_id = wo.id
            WHERE UPPER(wo.workordernumber) = ANY(:wos)
              AND wr.receivetype <> 3
              AND wr.workseq = (
                  SELECT MAX(wr2.workseq)
                  FROM workorderreceive wr2
                  WHERE wr2.workorder_id = wo.id
              )
            GROUP BY UPPER(wo.workordernumber)
        ";

        return DB::connection($conn)->select($sql, [
            'wos' => DieUsageEquipmentRepository::pgTextArray($upper),
        ]);
    }

    /**
     * เครื่องผลิตจริงล่าสุดต่อ workordernumber (จากฐาน MFG) — รับ batch ของ WO
     * คืน 1 แถวต่อ WO โดยเอา receive ล่าสุดที่ระบุเครื่อง
     */
    public function getLatestMachineByWorkorders(array $workordernumbers, string $conn, bool $drawingOnly = false): array
    {
        $upper = array_values(array_unique(array_filter(array_map(
            fn ($w) => strtoupper(trim((string) $w)),
            $workordernumbers
        ))));
        if (empty($upper)) return [];

        // เมื่อ $drawingOnly = หยิบเฉพาะเครื่องของ workcenter ที่เป็น step รีด (DRAW/รีด)
        // เพื่อให้ "ไดร์ที่อยู่บนเครื่องรีด" map กับเครื่องรีดจริง ไม่ใช่เครื่องล่าสุดของ WO (ที่อาจเป็นบ่อชุบ/ล้าง)
        $drawingJoin = $drawingOnly ? "JOIN workcenter wc ON wc.id = wr.workcenter_id" : "";
        $drawingWhere = $drawingOnly
            ? "AND (wc.description ILIKE '%รีด%' OR wc.description ILIKE '%draw%')"
            : "";

        $sql = "
            SELECT DISTINCT ON (UPPER(TRIM(wo.workordernumber)))
                   UPPER(TRIM(wo.workordernumber)) AS workordernumber,
                   wm.machinenumber AS machine_number,
                   wm.description   AS machine_desc,
                   wr.receivestamp
            FROM workorder wo
            JOIN workorderreceive wr ON wr.workorder_id = wo.id
            JOIN workmachine wm      ON wm.id = wr.workmachine_id
            {$drawingJoin}
            WHERE UPPER(TRIM(wo.workordernumber)) = ANY(:wos)
              AND wr.workmachine_id IS NOT NULL
              {$drawingWhere}
            ORDER BY UPPER(TRIM(wo.workordernumber)), wr.receivestamp DESC NULLS LAST, wr.id DESC
        ";

        return DB::connection($conn)->select($sql, [
            'wos' => DieUsageEquipmentRepository::pgTextArray($upper),
        ]);
    }

    public function getMachineTests(int $woId, string $conn): array
    {
        return DB::connection($conn)->select("
            SELECT wct.id, wct.workseq, wct.workcenter_id, wct.workmachine_id, wm.machinenumber, wm.description AS machine_desc,
                   wct.transdate,
                   wct.speed, wct.resinperc, wct.temp, wct.heater1, wct.heater2, wct.heater3,
                   wct.b1powder, wct.b2powder, wct.b3powder, wct.b4powder, wct.b5powder,
                   wct.b6powder, wct.b7powder, wct.b8powder, wct.b9powder, wct.b10powder, wct.b11powder,
                   wct.b1oil, wct.b2oil, wct.b3oil, wct.b4oil, wct.b5oil,
                   wct.b6oil, wct.b7oil, wct.b8oil, wct.b9oil, wct.b10oil, wct.b11oil,
                   wct.osize1, wct.osize2, wct.osize3, wct.osize4, wct.osize5, wct.osize6,
                   wct.osize7, wct.osize8, wct.osize9, wct.osize10, wct.osize11,
                   wct.approved, wct.docnumber
            FROM workcentertest wct
            LEFT JOIN workmachine wm ON wm.id = wct.workmachine_id
            WHERE wct.workorder_id = :id
            ORDER BY wct.workseq, wct.transdate
        ", ['id' => $woId]);
    }

    public function getQualityTests(int $woId, string $conn): array
    {
        return DB::connection($conn)->select("
            SELECT wot.id, wot.workseq, wot.workcenter_id, wot.wipitemnumber, wot.workorderreceive_id,
                   wot.v1, wot.v2, wot.v3, wot.v4, wot.v5, wot.v6, wot.v7, wot.v8, wot.v9, wot.v10,
                   wot.v11, wot.v12, wot.v13, wot.v14, wot.v15, wot.v16, wot.v17, wot.v18, wot.v19, wot.v20,
                   wot.qv1, wot.qv2, wot.qv3, wot.qv4, wot.qv5, wot.qv6, wot.qv7, wot.qv8, wot.qv9, wot.qv10,
                   wot.qv11, wot.qv12, wot.qv13, wot.qv14, wot.qv15, wot.qv16, wot.qv17, wot.qv18, wot.qv19, wot.qv20,
                   wot.b1, wot.b2, wot.b3, wot.b4, wot.b5,
                   wot.qb1, wot.qb2, wot.qb3, wot.qb4, wot.qb5,
                   wot.t1, wot.t2, wot.t3, wot.t4, wot.t5, wot.t6, wot.t7, wot.t8, wot.t9, wot.t10,
                   wot.qt1, wot.qt2, wot.qt3, wot.qt4, wot.qt5, wot.qt6, wot.qt7, wot.qt8, wot.qt9, wot.qt10,
                   wot.docnumber, wot.transdate, wot.inspectedtime, wot.qctime, wot.inspector_id, wot.qc_id,
                   qc.name AS qc_name, wot.approved, wot.notes
            FROM workordertest wot
            LEFT JOIN employee qc ON qc.id = wot.qc_id
            WHERE wot.workorder_id = :id
            ORDER BY wot.workseq, wot.transdate
        ", ['id' => $woId]);
    }

    public function getTestSpecs(array $workcenterIds, string $conn): array
    {
        if (empty($workcenterIds)) return [];
        $sql = "
            SELECT wctv.workcenter_id,
                   wctv.reqf,
                   wctv.v1text, wctv.v2text, wctv.v3text, wctv.v4text, wctv.v5text,
                   wctv.v6text, wctv.v7text, wctv.v8text, wctv.v9text, wctv.v10text,
                   wctv.v1oper, wctv.v1upper, wctv.v1lower,
                   wctv.v2oper, wctv.v2upper, wctv.v2lower,
                   wctv.v3oper, wctv.v3upper, wctv.v3lower,
                   wctv.v4oper, wctv.v4upper, wctv.v4lower,
                   wctv.v5oper, wctv.v5upper, wctv.v5lower,
                   wctv.v6oper, wctv.v6upper, wctv.v6lower,
                   wctv.v7oper, wctv.v7upper, wctv.v7lower,
                   wctv.qv1text, wctv.qv2text, wctv.qv3text, wctv.qv4text, wctv.qv5text,
                   wctv.qv6text, wctv.qv7text, wctv.qv8text, wctv.qv9text, wctv.qv10text,
                   wctv.qv1oper, wctv.qv1upper, wctv.qv1lower,
                   wctv.qv2oper, wctv.qv2upper, wctv.qv2lower,
                   wctv.qv3oper, wctv.qv3upper, wctv.qv3lower,
                   wctv.qv4oper, wctv.qv4upper, wctv.qv4lower,
                   wctv.qv5oper, wctv.qv5upper, wctv.qv5lower,
                   wctv.qv6oper, wctv.qv6upper, wctv.qv6lower,
                   wctv.qv7oper, wctv.qv7upper, wctv.qv7lower,
                   wctv.t1text, wctv.t2text, wctv.t3text, wctv.t4text, wctv.t5text,
                   wctv.qt1text, wctv.qt2text, wctv.qt3text, wctv.qt4text, wctv.qt5text
            FROM workcentertestval wctv
            WHERE wctv.workcenter_id = ANY(:ids)
        ";
        return DB::connection($conn)->select($sql, [
            'ids' => '{' . implode(',', $workcenterIds) . '}',
        ]);
    }

    public function getCustomerClaims(string $salesOrder, string $conn): array
    {
        $salesOrder = strtoupper(trim($salesOrder));
        if ($salesOrder === '') {
            return [];
        }

        return DB::connection($conn)->select("
            SELECT ar.ordnumber,
                   ar.duedate,
                   r.transdate,
                   r.returnnumber,
                   r.invnumber,
                   r.amount,
                   r.returnpaid,
                   r.returnpaiddate,
                   r.notes,
                   ri.description AS item_description,
                   ri.qty,
                   ri.allocated,
                   ri.sellprice,
                   c.customernumber,
                   c.name AS customer_name
            FROM \"return\" r
            LEFT JOIN returnitems ri ON r.id = ri.trans_id
            JOIN ar ON ar.invnumber = r.invnumber
            LEFT JOIN customer c ON c.id = r.customer_id
            WHERE UPPER(TRIM(ar.ordnumber)) = :sales_order
              AND UPPER(TRIM(r.returnnumber)) LIKE 'EC%'
            ORDER BY r.id DESC, ri.id
        ", ['sales_order' => $salesOrder]);
    }

    /**
     * Customer Claim ทั้งหมดในช่วงวันที่ (จากฐานบัญชี pgsqlw/pgsqlp)
     * รวม qty ต่อ 1 ใบ claim และดึง Sales Order (ar.ordnumber) เพื่อนำไป map กับ WO ต่อ
     */
    public function getRecentClaims(string $from, string $to, string $conn): array
    {
        return DB::connection($conn)->select("
            SELECT r.id AS return_id,
                   r.transdate,
                   r.returnnumber,
                   r.invnumber,
                   r.amount,
                   r.notes,
                   ar.ordnumber,
                   c.customernumber,
                   c.name AS customer_name,
                   COALESCE(SUM(ABS(ri.qty)), 0) AS qty
            FROM \"return\" r
            LEFT JOIN returnitems ri ON r.id = ri.trans_id
            LEFT JOIN ar ON ar.invnumber = r.invnumber
            LEFT JOIN customer c ON c.id = r.customer_id
            WHERE r.transdate >= :from AND r.transdate <= :to
            GROUP BY r.id, r.transdate, r.returnnumber, r.invnumber, r.amount, r.notes,
                     ar.ordnumber, c.customernumber, c.name
            ORDER BY r.transdate DESC, r.returnnumber
        ", ['from' => $from, 'to' => $to]);
    }

    /**
     * map Sales Order (ordnumber) → workordernumber จากฐาน MFG (pgsqlmfgw/pgsqlmfgp)
     * เทียบแบบ case-insensitive/trim เพราะ ordnumber อาจมีช่องว่าง/ตัวพิมพ์ต่างกัน
     */
    public function getWorkordersBySalesOrders(array $salesOrders, string $conn): array
    {
        $orders = array_values(array_unique(array_filter(array_map(
            fn ($s) => strtoupper(trim((string) $s)),
            $salesOrders
        ))));
        if (empty($orders)) {
            return [];
        }

        $sql = "
            SELECT DISTINCT UPPER(TRIM(wo.ordnumber)) AS ordnumber, wo.workordernumber
            FROM workorder wo
            WHERE UPPER(TRIM(wo.ordnumber)) = ANY(:orders)
            ORDER BY wo.workordernumber
        ";

        return DB::connection($conn)->select($sql, [
            'orders' => DieUsageEquipmentRepository::pgTextArray($orders),
        ]);
    }
}
