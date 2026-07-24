<?php

namespace App\Services\FormMFGD;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class MfgDefectAnalysisService
{
    public function report(array $filters): array
    {
        $connections = match ($filters['site'] ?? 'all') {
            'wire' => [['mfg' => 'pgsqlmfgw', 'erp' => 'pgsqlw', 'site' => 'Wire']],
            'plus' => [['mfg' => 'pgsqlmfgp', 'erp' => 'pgsqlp', 'site' => 'Plus']],
            default => [
                ['mfg' => 'pgsqlmfgw', 'erp' => 'pgsqlw', 'site' => 'Wire'],
                ['mfg' => 'pgsqlmfgp', 'erp' => 'pgsqlp', 'site' => 'Plus'],
            ],
        };

        $rows = collect();
        $errors = [];

        foreach ($connections as $connection) {
            try {
                $rows = $rows->concat($this->rowsForConnection(
                    $connection['mfg'],
                    $connection['erp'],
                    $connection['site'],
                    $filters
                ));
            } catch (Throwable $e) {
                report($e);
                $errors[] = "{$connection['site']}: ไม่สามารถโหลดข้อมูลได้ กรุณาตรวจสอบ Laravel log";
            }
        }

        $rows = $rows
            ->sort(function ($a, $b) {
                $rateCompare = ((float) $b->defect_pct) <=> ((float) $a->defect_pct);
                if ($rateCompare !== 0) {
                    return $rateCompare;
                }

                $defectCompare = ((float) $b->defect_qty) <=> ((float) $a->defect_qty);
                if ($defectCompare !== 0) {
                    return $defectCompare;
                }

                return strcmp((string) $a->workorder_no, (string) $b->workorder_no);
            })
            ->values();

        return [
            'rows' => $rows,
            'summary' => $this->summary($rows),
            'prefix_summary' => $this->prefixSummary($rows),
            'errors' => $errors,
        ];
    }

    private function rowsForConnection(
        string $mfgConnection,
        string $erpConnection,
        string $site,
        array $filters
    ): Collection {
        $db = DB::connection($erpConnection);

        $usage = $db->table('workorderusage as wu')
            ->leftJoin('gl as usage_gl', 'usage_gl.id', '=', 'wu.trans_id')
            ->selectRaw('
                wu.workorder_id,
                SUM(wu.qty) AS issued_qty,
                MAX(usage_gl.transdate) AS issued_at
            ')
            ->groupBy('wu.workorder_id');

        $receives = $db->table('workorderreceive as wor')
            ->join('parts as rp', 'rp.id', '=', 'wor.parts_id')
            ->leftJoin('partstype as rpt', 'rpt.id', '=', 'rp.partstype_id')
            ->selectRaw('
                wor.workorder_id,
                SUM(CASE WHEN rpt.id IN (61, 62) THEN wor.qty ELSE 0 END) AS defect_qty,
                SUM(CASE WHEN rpt.id IN (69, 70, 71, 95, 91) THEN wor.qty ELSE 0 END) AS return_rm_qty,
                SUM(
                    CASE
                        WHEN rpt.id NOT IN (69, 70, 71, 95, 61, 62, 91, 0, 56)
                        THEN wor.qty
                        ELSE 0
                    END
                ) AS good_qty
            ')
            ->groupBy('wor.workorder_id');

        $query = $db->table('workorder as w')
            ->join('parts as p', 'p.id', '=', 'w.parts_id')
            ->leftJoin('partstype as pt', 'pt.id', '=', 'p.partstype_id')
            ->leftJoin('partsgroup as pg', 'pg.id', '=', 'p.partsgroup_id')
            ->leftJoin('partscategory as pc', 'pc.id', '=', 'p.partscategory_id')
            ->leftJoin('customer as c', 'c.id', '=', 'w.customer_id')
            ->leftJoinSub($usage, 'usage_summary', function ($join) {
                $join->on('usage_summary.workorder_id', '=', 'w.id');
            })
            ->leftJoinSub($receives, 'receive_summary', function ($join) {
                $join->on('receive_summary.workorder_id', '=', 'w.id');
            })
            ->selectRaw("
                '{$site}' AS site,
                w.id AS workorder_id,
                w.dateopen AS document_date,
                w.workordernumber AS workorder_no,
                p.partnumber AS part_no,
                p.description AS part_name,
                ROUND(COALESCE(w.qty, 0)::numeric, 2) AS order_qty,
                ROUND(COALESCE(usage_summary.issued_qty, 0)::numeric, 2) AS issued_qty,
                ROUND(COALESCE(receive_summary.good_qty, 0)::numeric, 2) AS good_qty,
                ROUND(COALESCE(receive_summary.defect_qty, 0)::numeric, 2) AS defect_qty,
                ROUND(
                    CASE
                        WHEN COALESCE(receive_summary.good_qty, 0) + COALESCE(receive_summary.defect_qty, 0) > 0
                        THEN (
                            COALESCE(receive_summary.defect_qty, 0) * 100.0
                            / (COALESCE(receive_summary.good_qty, 0) + COALESCE(receive_summary.defect_qty, 0))
                        )
                        ELSE 0
                    END::numeric,
                    2
                ) AS defect_pct,
                ROUND(COALESCE(receive_summary.return_rm_qty, 0)::numeric, 2) AS return_rm_qty,
                ROUND(
                    (COALESCE(usage_summary.issued_qty, 0) - COALESCE(w.received, 0))::numeric,
                    2
                ) AS balance_qty,
                pt.description AS part_type,
                pg.groupnumber AS group_code,
                pg.description AS group_name,
                pc.categorynumber AS category_code,
                pc.description AS category_name,
                c.customernumber AS customer_code,
                c.name AS customer_name,
                w.reqdate AS due_date,
                usage_summary.issued_at
            ");

        if (!empty($filters['date_from'])) {
            $query->whereDate('w.dateopen', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('w.dateopen', '<=', $filters['date_to']);
        }

        if (!empty($filters['mfg'])) {
            $query->where('w.workordernumber', 'ilike', '%' . $filters['mfg'] . '%');
        }

        if (!empty($filters['prefix'])) {
            $prefix = ltrim((string) $filters['prefix'], '+');
            $query->where(function ($builder) use ($prefix) {
                $builder
                    ->where('w.workordernumber', 'ilike', $prefix . '%')
                    ->orWhere('w.workordernumber', 'ilike', '+' . $prefix . '%');
            });
        }

        $rows = $query->get();
        $mfgMap = $this->mfgMap(
            $mfgConnection,
            $rows->pluck('workorder_no')->filter()->unique()->values()->all()
        );

        return $rows->map(function ($row) use ($mfgMap) {
            $mfg = $mfgMap->get(strtoupper(trim((string) $row->workorder_no)));
            $row->sales_order_no = $mfg->sales_order_no ?? null;
            $row->packaging = $mfg->packaging ?? null;
            $row->mfg_prefix = $this->mfgPrefix((string) $row->workorder_no);

            return $row;
        });
    }

    private function mfgMap(string $connection, array $workorders): Collection
    {
        if ($workorders === []) {
            return collect();
        }

        return DB::connection($connection)
            ->table('workorder as w')
            ->whereIn('w.workordernumber', $workorders)
            ->select([
                'w.workordernumber',
                'w.ordnumber as sales_order_no',
                'w.fpack as packaging',
            ])
            ->get()
            ->keyBy(fn($row) => strtoupper(trim((string) $row->workordernumber)));
    }

    private function summary(Collection $rows): array
    {
        $good = (float) $rows->sum('good_qty');
        $defect = (float) $rows->sum('defect_qty');
        $denominator = $good + $defect;

        return [
            'workorders' => $rows->count(),
            'good_qty' => $good,
            'defect_qty' => $defect,
            'defect_pct' => $denominator > 0 ? round(($defect / $denominator) * 100, 2) : 0.0,
            'balance_qty' => (float) $rows->sum('balance_qty'),
        ];
    }

    private function prefixSummary(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn($row) => $row->mfg_prefix ?: 'อื่น ๆ')
            ->map(function (Collection $items, string $prefix) {
                $good = (float) $items->sum('good_qty');
                $defect = (float) $items->sum('defect_qty');
                $denominator = $good + $defect;

                return (object) [
                    'prefix' => $prefix,
                    'workorders' => $items->count(),
                    'good_qty' => $good,
                    'defect_qty' => $defect,
                    'defect_pct' => $denominator > 0 ? round(($defect / $denominator) * 100, 2) : 0.0,
                    'output_qty' => $denominator,
                ];
            })
            ->filter(fn($item) => $item->output_qty > 0)
            ->sort(function ($a, $b) {
                $rateCompare = ((float) $b->defect_pct) <=> ((float) $a->defect_pct);

                return $rateCompare !== 0
                    ? $rateCompare
                    : ((float) $b->defect_qty <=> (float) $a->defect_qty);
            })
            ->values();
    }

    private function mfgPrefix(string $workorder): string
    {
        $workorder = ltrim(strtoupper(trim($workorder)), '+');

        return preg_match('/^[A-Z]+/', $workorder, $matches) ? $matches[0] : '';
    }
}
