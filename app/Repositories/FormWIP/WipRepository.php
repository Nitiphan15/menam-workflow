<?php

namespace App\Repositories\FormWIP;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * WIP Monitoring — query ข้อมูลจาก WMSOne (pgsqlmfg*) และ ERP (pgsql w/p) แบบอ่านอย่างเดียว
 *
 * WMSOne.workorderreceive.receivetype: 0 = WIP (PK ใช้ type นี้), 1 = ของดี, 3 = NCR/Rework, 4 = ไม่ทราบ (ถือเป็นของดี)
 * ERP.workorderreceive = การรับเข้า FG Stock, วันที่/เลขที่เอกสารอยู่ที่ gl (gl.id = trans_id)
 */
class WipRepository
{
    public const SITES = [
        'WIRE' => ['mfg' => 'pgsqlmfgw', 'erp' => 'pgsqlw'],
        'PLUS' => ['mfg' => 'pgsqlmfgp', 'erp' => 'pgsqlp'],
    ];

    private const CHUNK = 500;

    /** WO ที่ยังเปิดอยู่ใน WMSOne (approved, ยังไม่ dateclose) ภายในช่วง dateopen ที่กำหนด */
    public function openWorkorders(string $site, int $days): Collection
    {
        return collect(DB::connection($this->mfg($site))->select(
            $this->workorderSelect() . "\nWHERE wo.approved = true\n  AND wo.dateclose IS NULL\n  AND wo.dateopen >= CURRENT_DATE - (?::int)\nORDER BY wo.dateopen",
            [$days]
        ));
    }

    /** WO เดียว (รวมที่ปิดแล้ว) สำหรับหน้า detail */
    public function workorderById(string $site, int $id): ?object
    {
        $rows = DB::connection($this->mfg($site))->select($this->workorderSelect() . "\nWHERE wo.id = ?", [$id]);

        return $rows[0] ?? null;
    }

    private function workorderSelect(): string
    {
        return <<<'SQL'
SELECT
  wo.id,
  wo.workordernumber,
  COALESCE(wo.qty, 0)              AS qty,
  wo.dateopen,
  wo.reqdate,
  wo.dateclose,
  COALESCE(wo.suspended, false)    AS suspended,
  wo.suspenddate,
  wo.ordnumber,
  wo.fsize,
  wo.fcat,
  COALESCE(wo.description, '')     AS wo_desc,
  p.partnumber,
  COALESCE(p.description, '')      AS part_desc,
  COALESCE(NULLIF(TRIM(c.name), ''), NULLIF(TRIM(wo.customer), ''), '-') AS customer
FROM workorder wo
JOIN parts p ON p.id = wo.parts_id
LEFT JOIN customer c ON c.id = wo.customer_id
SQL;
    }

    /** Routing + ยอดรับ/เบิกต่อขั้น (1 แถวต่อ workorder_id + workseq) */
    public function steps(string $site, array $workorderIds): Collection
    {
        $rows = collect();

        foreach (array_chunk($workorderIds, self::CHUNK) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));

            $sql = <<<SQL
SELECT
  wowc.workorder_id,
  wowc.workseq,
  wc.workcenternumber           AS wc_code,
  COALESCE(wc.description, '')  AS wc_name,
  wowc.msizein,
  wowc.msize,
  COALESCE(r.good_qty, 0)       AS good_qty,
  COALESCE(r.ncr_qty, 0)        AS ncr_qty,
  COALESCE(r.receive_count, 0)  AS receive_count,
  r.first_stamp,
  r.last_stamp,
  r.machine,
  COALESCE(u.input_qty, 0)      AS input_qty,
  u.last_usage
FROM workorderworkcenter wowc
JOIN workcenter wc ON wc.id = wowc.workcenter_id
LEFT JOIN (
  SELECT wr.workorder_id, wr.workseq, wr.workcenter_id,
         SUM(CASE WHEN wr.receivetype <> 3 THEN wr.qty ELSE 0 END) AS good_qty,
         SUM(CASE WHEN wr.receivetype = 3 THEN wr.qty ELSE 0 END)  AS ncr_qty,
         COUNT(*)                                                  AS receive_count,
         MIN(wr.receivestamp)                                      AS first_stamp,
         MAX(wr.receivestamp)                                      AS last_stamp,
         (ARRAY_AGG(wm.machinenumber ORDER BY wr.receivestamp DESC))[1] AS machine
  FROM workorderreceive wr
  LEFT JOIN workmachine wm ON wm.id = wr.workmachine_id
  WHERE wr.workorder_id IN ($ph)
  GROUP BY wr.workorder_id, wr.workseq, wr.workcenter_id
) r ON r.workorder_id = wowc.workorder_id AND r.workseq = wowc.workseq AND r.workcenter_id = wowc.workcenter_id
LEFT JOIN (
  SELECT wu.workorder_id, wu.workseq, wu.workcenter_id,
         SUM(wu.qty) AS input_qty, MAX(wu.usagestamp) AS last_usage
  FROM workorderusage wu
  WHERE wu.workorder_id IN ($ph)
  GROUP BY wu.workorder_id, wu.workseq, wu.workcenter_id
) u ON u.workorder_id = wowc.workorder_id AND u.workseq = wowc.workseq AND u.workcenter_id = wowc.workcenter_id
WHERE wowc.workorder_id IN ($ph)
ORDER BY wowc.workorder_id, wowc.workseq
SQL;

            $rows = $rows->merge(DB::connection($this->mfg($site))->select($sql, array_merge($chunk, $chunk, $chunk)));
        }

        return $rows;
    }

    /** ยอดรับเข้า FG จาก ERP ต่อเลข WO (key = UPPER(TRIM(workordernumber))) */
    public function erpReceipts(string $site, array $workorderNumbers): Collection
    {
        $rows = collect();

        foreach (array_chunk(array_values(array_unique($workorderNumbers)), self::CHUNK) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));

            $sql = <<<SQL
SELECT
  UPPER(TRIM(wo.workordernumber)) AS wo_key,
  COALESCE(wo.received, 0)        AS erp_received,
  fg.first_fg_date,
  fg.last_fg_date,
  COALESCE(fg.receipt_count, 0)   AS receipt_count
FROM workorder wo
LEFT JOIN LATERAL (
  SELECT MIN(g.transdate) AS first_fg_date,
         MAX(g.transdate) AS last_fg_date,
         COUNT(DISTINCT wr.trans_id) AS receipt_count
  FROM workorderreceive wr
  JOIN gl g ON g.id = wr.trans_id
  WHERE wr.workorder_id = wo.id
) fg ON true
WHERE UPPER(TRIM(wo.workordernumber)) IN ($ph)
SQL;

            $rows = $rows->merge(DB::connection($this->erp($site))->select($sql, $chunk));
        }

        return $rows->keyBy('wo_key');
    }

    /** รายการรับเข้า FG ของ WO เดียว (ใช้ในหน้า detail) */
    public function erpReceiptLines(string $site, string $workorderNumber): Collection
    {
        $sql = <<<'SQL'
SELECT g.transnumber, g.transdate, SUM(wr.qty) AS qty, COUNT(*) AS lines
FROM workorder wo
JOIN workorderreceive wr ON wr.workorder_id = wo.id
JOIN gl g ON g.id = wr.trans_id
WHERE UPPER(TRIM(wo.workordernumber)) = ?
GROUP BY g.transnumber, g.transdate
ORDER BY g.transdate, g.transnumber
SQL;

        return collect(DB::connection($this->erp($site))->select($sql, [strtoupper(trim($workorderNumber))]));
    }

    /** ประวัติการรับในแต่ละขั้นของ WO เดียว (ใช้ในหน้า detail) */
    public function receiveHistory(string $site, int $workorderId): Collection
    {
        $sql = <<<'SQL'
SELECT wr.workseq, wc.workcenternumber AS wc_code, wm.machinenumber AS machine,
       wr.receivetype, wr.qty, wr.heatno, wr.coilno, wr.msize, wr.receivestamp, wr.reworkreason
FROM workorderreceive wr
JOIN workcenter wc ON wc.id = wr.workcenter_id
LEFT JOIN workmachine wm ON wm.id = wr.workmachine_id
WHERE wr.workorder_id = ?
ORDER BY wr.receivestamp DESC
LIMIT 500
SQL;

        return collect(DB::connection($this->mfg($site))->select($sql, [$workorderId]));
    }

    private function mfg(string $site): string
    {
        return self::SITES[$site]['mfg'];
    }

    private function erp(string $site): string
    {
        return self::SITES[$site]['erp'];
    }
}
