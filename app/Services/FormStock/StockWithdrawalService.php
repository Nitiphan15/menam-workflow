<?php

namespace App\Services\FormStock;

use App\Models\FormStock\StockWithdrawalStandard;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockWithdrawalService
{
    private const MFG_CONNECTIONS = ['WIRE' => 'pgsqlmfgw', 'PLUS' => 'pgsqlmfgp'];

    public function report(array $filters = []): Collection
    {
        $asOf = Carbon::parse($filters['as_of'] ?? now('Asia/Bangkok')->toDateString())->startOfDay();
        $rows = collect();
        foreach (self::MFG_CONNECTIONS as $site => $connection) {
            if (!empty($filters['site']) && strtoupper($filters['site']) !== $site) continue;
            $rows = $rows->merge($this->fetchMfgRows($connection, $site, $filters));
        }
        $standards = $this->standardsFor($rows->pluck('partnumber')->all(), $asOf);

        return $rows->map(function ($row) use ($standards, $asOf) {
            $key = $row->site.'|'.$row->partnumber;
            $standard = $standards->get($key) ?? $standards->get('ALL|'.$row->partnumber);
            return $this->calculateRow((array) $row, $standard ? (array) $standard : null, $asOf);
        })->filter(function (array $row) use ($filters) {
            if (!empty($filters['status']) && $row['withdrawal_status'] !== $filters['status']) return false;
            if (!empty($filters['risk']) && $row['risk_code'] !== $filters['risk']) return false;
            if (!empty($filters['keyword'])) {
                $haystack = mb_strtoupper($row['mfg'].' '.$row['partnumber'].' '.$row['description']);
                if (!str_contains($haystack, mb_strtoupper($filters['keyword']))) return false;
            }
            return true;
        })->sortBy([['risk_rank', 'desc'], ['withdraw_date', 'asc'], ['due_date', 'asc']])->values();
    }

    public function calculateRow(array $row, ?array $standard, Carbon $asOf): array
    {
        $required = round((float) ($row['required_qty'] ?? 0), 4);
        $issued = round((float) ($row['issued_qty'] ?? 0), 4);
        $remaining = max(0, round($required - $issued, 4));
        $withdrawalStatus = $issued <= 0 ? 'ยังไม่เบิก' : ($remaining > 0 ? 'เบิกบางส่วน' : 'เบิกครบแล้ว');
        $dueDate = !empty($row['due_date']) ? Carbon::parse($row['due_date'])->startOfDay() : null;
        $standardDays = $standard ? max(0, (int) $standard['standard_days']) : null;
        $warningDays = $standard ? max(0, (int) $standard['warning_days']) : null;
        $dayType = $standard['day_type'] ?? 'CALENDAR';
        $withdrawDate = $dueDate && $standardDays !== null ? $this->subtractDays($dueDate, $standardDays, $dayType) : null;
        $warningDate = $withdrawDate && $warningDays !== null ? $this->subtractDays($withdrawDate, $warningDays, $dayType) : null;

        if ($withdrawalStatus === 'เบิกครบแล้ว') {
            [$riskCode, $riskLabel, $riskRank] = ['GREEN', 'เบิกครบแล้ว', 0];
        } elseif (!$standard || !$dueDate) {
            [$riskCode, $riskLabel, $riskRank] = ['GRAY', !$standard ? 'ยังไม่กำหนด Standard' : 'ไม่มี Due Date', 1];
        } elseif ($asOf->gt($withdrawDate)) {
            [$riskCode, $riskLabel, $riskRank] = ['RED', 'เลยกำหนดเบิก '.$withdrawDate->diffInDays($asOf).' วัน', 4];
        } elseif ($asOf->equalTo($withdrawDate)) {
            [$riskCode, $riskLabel, $riskRank] = ['ORANGE', 'ควรเบิกวันนี้', 3];
        } elseif ($warningDate && $asOf->gte($warningDate)) {
            [$riskCode, $riskLabel, $riskRank] = ['YELLOW', 'ใกล้ถึงกำหนดเบิก', 2];
        } else {
            [$riskCode, $riskLabel, $riskRank] = ['GREEN', 'ยังไม่ถึงกำหนด', 0];
        }

        return $row + [
            'required_qty' => $required, 'issued_qty' => $issued, 'remaining_qty' => $remaining,
            'withdrawal_status' => $withdrawalStatus, 'standard_days' => $standardDays,
            'warning_days' => $warningDays, 'day_type' => $dayType,
            'withdraw_date' => $withdrawDate?->toDateString(), 'warning_date' => $warningDate?->toDateString(),
            'risk_code' => $riskCode, 'risk_label' => $riskLabel, 'risk_rank' => $riskRank,
            'responsible_name' => $standard['responsible_name'] ?? null,
            'responsible_email' => $standard['responsible_email'] ?? null,
            'required_qty_source' => $row['required_qty_source'] ?? 'MOCK',
            'over_issued_qty' => max(0, round($issued - $required, 4)),
        ];
    }

    public function issueDetails(string $site, int $workorderId, int $partsId): Collection
    {
        $connection = self::MFG_CONNECTIONS[strtoupper($site)] ?? null;
        abort_unless($connection, 404);

        return collect(DB::connection($connection)->select("
            SELECT mu.*
            FROM workordermatusage mu
            WHERE mu.workorder_id = ? AND mu.parts_id = ?
            ORDER BY mu.requeststamp
        ", [$workorderId, $partsId]));
    }

    private function fetchMfgRows(string $connection, string $site, array $filters): Collection
    {
        $bindings = ['site' => $site];
        $where = '';
        if (!empty($filters['date_from'])) { $where .= ' AND wo.reqdate::date >= :date_from::date'; $bindings['date_from'] = $filters['date_from']; }
        if (!empty($filters['date_to'])) { $where .= ' AND wo.reqdate::date <= :date_to::date'; $bindings['date_to'] = $filters['date_to']; }

        $sql = "
            WITH bom AS (
                SELECT b.workorder_id, b.parts_id, SUM(b.qty) AS required_qty
                FROM workorderbom b GROUP BY b.workorder_id, b.parts_id
            ), issued AS (
                SELECT workorder_id, parts_id, SUM(qty) AS issued_qty,
                       MIN(requeststamp) AS first_issued_at, MAX(requeststamp) AS last_issued_at
                FROM workordermatusage GROUP BY workorder_id, parts_id
            )
            SELECT :site::text AS site, wo.id AS workorder_id,
                   UPPER(TRIM(wo.workordernumber)) AS mfg,
                   UPPER(TRIM(p.partnumber)) AS partnumber, p.description,
                   wo.reqdate::date AS due_date,
                   COALESCE(NULLIF(bom.required_qty, 0), NULLIF(wo.qty, 0), 1) AS required_qty,
                   'MOCK'::text AS required_qty_source,
                   bom.parts_id,
                   COALESCE(issued.issued_qty, 0) AS issued_qty,
                   issued.first_issued_at, issued.last_issued_at
            FROM workorder wo
            JOIN bom ON bom.workorder_id = wo.id
            JOIN parts p ON p.id = bom.parts_id
            LEFT JOIN issued ON issued.workorder_id = bom.workorder_id AND issued.parts_id = bom.parts_id
            WHERE wo.dateclose IS NULL AND COALESCE(wo.suspended, false) = false
              AND wo.reqdate IS NOT NULL
              AND wo.workordernumber !~* '^(\\+)?(EX|S)'
              AND wo.workordernumber !~* '\\(C\\)$' {$where}
            ORDER BY wo.reqdate, wo.workordernumber, p.partnumber
        ";
        return collect(DB::connection($connection)->select($sql, $bindings));
    }

    private function standardsFor(array $parts, Carbon $asOf): Collection
    {
        if (!$parts) return collect();
        return StockWithdrawalStandard::query()->where('is_active', 1)
            ->whereIn('partnumber', array_values(array_unique($parts)))
            ->whereDate('effective_from', '<=', $asOf->toDateString())
            ->where(fn($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $asOf->toDateString()))
            ->orderByDesc('effective_from')->get()
            ->unique(fn($row) => strtoupper(trim($row->site)).'|'.strtoupper(trim($row->partnumber)))
            ->keyBy(fn($row) => strtoupper(trim($row->site)).'|'.strtoupper(trim($row->partnumber)));
    }

    private function subtractDays(Carbon $date, int $days, string $dayType): Carbon
    {
        $result = $date->copy();
        if (strtoupper($dayType) !== 'WORKING') return $result->subDays($days);
        while ($days > 0) { $result->subDay(); if (!$result->isSunday()) $days--; }
        return $result;
    }
}
