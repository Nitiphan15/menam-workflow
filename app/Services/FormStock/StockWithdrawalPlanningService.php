<?php

namespace App\Services\FormStock;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockWithdrawalPlanningService
{
    private const CONNECTIONS = ['WIRE' => 'pgsqlmfgw', 'PLUS' => 'pgsqlmfgp'];

    public function allMfg(array $filters = []): Collection
    {
        $rows = collect();
        foreach (self::CONNECTIONS as $site => $connection) {
            if (!empty($filters['site']) && strtoupper($filters['site']) !== $site) continue;
            $bindings = [];
            $where = '';
            if (!empty($filters['date_from'])) { $where .= ' AND wo.reqdate::date >= ?::date'; $bindings[] = $filters['date_from']; }
            if (!empty($filters['date_to'])) { $where .= ' AND wo.reqdate::date <= ?::date'; $bindings[] = $filters['date_to']; }
            if (!empty($filters['mfg'])) { $where .= ' AND UPPER(wo.workordernumber) LIKE ?'; $bindings[] = '%'.mb_strtoupper(trim($filters['mfg'])).'%'; }

            $sql = "SELECT ?::text site, wo.id workorder_id, UPPER(TRIM(wo.workordernumber)) mfg,
                           UPPER(TRIM(p.partnumber)) partnumber, p.description part_description,
                           COALESCE(NULLIF(p.f1,''), p.description) size, wo.qty quantity, p.unit,
                           c.name customer_name, wo.reqdate::date delivery_date, wo.notes mfg_note
                    FROM workorder wo
                    LEFT JOIN parts p ON p.id=wo.parts_id
                    LEFT JOIN customer c ON c.id=wo.customer_id
                    WHERE wo.dateclose IS NULL AND COALESCE(wo.suspended,false)=false
                      AND wo.reqdate IS NOT NULL
                      AND wo.workordernumber !~* '^(\\+)?(EX|S)'
                      AND wo.workordernumber !~* '\\(C\\)$' {$where}
                    ORDER BY wo.reqdate, wo.workordernumber";
            array_unshift($bindings, $site);
            $rows = $rows->merge(DB::connection($connection)->select($sql, $bindings));
        }
        return $rows->values();
    }

    public function findMfg(string $site, int $workorderId): ?object
    {
        $connection = self::CONNECTIONS[strtoupper($site)] ?? null;
        if (!$connection) return null;
        return DB::connection($connection)->table('workorder as wo')
            ->leftJoin('parts as p', 'p.id', '=', 'wo.parts_id')
            ->leftJoin('customer as c', 'c.id', '=', 'wo.customer_id')
            ->where('wo.id', $workorderId)
            ->selectRaw("wo.id workorder_id, UPPER(TRIM(wo.workordernumber)) mfg, UPPER(TRIM(p.partnumber)) partnumber,
                p.description part_description, COALESCE(NULLIF(p.f1,''),p.description) size, wo.qty quantity,
                p.unit, c.name customer_name, wo.reqdate::date delivery_date, wo.notes mfg_note")
            ->first();
    }

    public function subtractWorkingDays(Carbon $date, int $days): Carbon
    {
        $result = $date->copy()->startOfDay();
        while ($days > 0) { $result->subDay(); if (!$result->isSunday()) $days--; }
        return $result;
    }

    public function overdueDays($plannedDate, ?Carbon $asOf = null): int
    {
        $date = Carbon::parse($plannedDate)->startOfDay();
        $asOf ??= now('Asia/Bangkok')->startOfDay();
        return $date->lt($asOf) ? -$date->diffInDays($asOf) : $asOf->diffInDays($date);
    }
}
