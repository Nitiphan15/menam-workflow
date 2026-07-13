<?php

namespace App\Repositories\FormDIE;

use Illuminate\Support\Facades\DB;

class WorkorderBlockRepository
{
    public static function connectionFor(string $workordernumber): string
    {
        return str_starts_with(trim($workordernumber), '+') ? 'pgsqlmfgp' : 'pgsqlmfgw';
    }

    public function getBlocksByWorkorder(string $workordernumber): array
    {
        $sql = <<<SQL
SELECT
    wo.workordernumber,
    wo.description       AS wo_description,
    wo.qty               AS wo_qty,
    wo.reqdate,
    wo.plandate,
    wowc.workseq,
    wc.workcenternumber,
    wc.description       AS workcenter_desc,
    wowc.msize,
    wowc.msizein,
    wowc.esthour,
    b.block_no,
    b.block_name,
    b.block_size
FROM workorder wo
JOIN workorderworkcenter wowc ON wowc.workorder_id = wo.id
JOIN workcenter wc            ON wc.id = wowc.workcenter_id
CROSS JOIN LATERAL (VALUES
    (1,  wowc.block1,  wowc.block1size),
    (2,  wowc.block2,  wowc.block2size),
    (3,  wowc.block3,  wowc.block3size),
    (4,  wowc.block4,  wowc.block4size),
    (5,  wowc.block5,  wowc.block5size),
    (6,  wowc.block6,  wowc.block6size),
    (7,  wowc.block7,  wowc.block7size),
    (8,  wowc.block8,  wowc.block8size),
    (9,  wowc.block9,  wowc.block9size),
    (10, wowc.block10, wowc.block10size),
    (11, wowc.block11, wowc.block11size),
    (12, wowc.block12, wowc.block12size)
) AS b(block_no, block_name, block_size)
WHERE wo.workordernumber = :wo
  AND (NULLIF(b.block_name, '') IS NOT NULL OR COALESCE(b.block_size, 0) <> 0)
ORDER BY wowc.workseq, b.block_no
SQL;

        return DB::connection(self::connectionFor($workordernumber))
            ->select($sql, ['wo' => $workordernumber]);
    }

    public function findByMaterial(string $connection, ?string $heatno, ?string $coilno): array
    {
        $where = [];
        $bind  = [];
        if (!empty($heatno)) { $where[] = "wr.heatno = :h";  $bind['h'] = $heatno; }
        if (!empty($coilno)) { $where[] = "wr.coilno = :c";  $bind['c'] = $coilno; }
        if (empty($where)) return [];

        $sql = "
            SELECT
                wo.workordernumber,
                wo.ordnumber,
                wo.brand,
                wo.fsize, wo.flen, wo.fcat,
                wr.workseq,
                wc.workcenternumber,
                wc.description AS workcenter_desc,
                wr.heatno,
                wr.coilno,
                wr.qty           AS receive_qty,
                wr.receivestamp,
                wr.receiveby,
                wm.machinenumber,
                wm.description AS machine_desc,
                wr.wipitemnumber,
                wr.warehousenumber
            FROM workorderreceive wr
            JOIN workorder wo            ON wo.id = wr.workorder_id
            LEFT JOIN workcenter wc      ON wc.id = wr.workcenter_id
            LEFT JOIN workmachine wm     ON wm.id = wr.workmachine_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY wr.receivestamp DESC
            LIMIT 200
        ";

        return DB::connection($connection)->select($sql, $bind);
    }

    public function getWorkcentersForWorkorders(array $workordernumbers, string $connection): array
    {
        if (empty($workordernumbers)) return [];

        $sql = <<<SQL
SELECT
    wo.workordernumber,
    wowc.workseq,
    wc.workcenternumber,
    wc.description AS workcenter_desc
FROM workorder wo
JOIN workorderworkcenter wowc ON wowc.workorder_id = wo.id
JOIN workcenter wc            ON wc.id = wowc.workcenter_id
WHERE wo.workordernumber = ANY(:wos)
SQL;

        return DB::connection($connection)
            ->select($sql, ['wos' => '{' . implode(',', $workordernumbers) . '}']);
    }
}
