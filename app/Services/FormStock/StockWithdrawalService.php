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

        $rows = $rows->map(function ($row) use ($standards, $asOf, $filters) {
            $key = $row->site.'|'.$row->partnumber;
            $standard = $standards->get($key) ?? $standards->get('ALL|'.$row->partnumber);
            $calculated = $this->calculateRow((array) $row, $standard ? (array) $standard : null, $asOf);
            return ($filters['mode'] ?? 'mock') === 'mock' ? $this->applyMockRisk($calculated, $asOf) : $calculated;
        })->filter(function (array $row) use ($filters) {
            if (!empty($filters['status']) && $row['withdrawal_status'] !== $filters['status']) return false;
            if (!empty($filters['risk']) && $row['risk_code'] !== $filters['risk']) return false;
            if (!empty($filters['mfg']) && !str_contains(mb_strtoupper($row['mfg']), mb_strtoupper(trim($filters['mfg'])))) return false;
            if (!empty($filters['part'])) {
                $partText = mb_strtoupper($row['partnumber'].' '.$row['description']);
                if (!str_contains($partText, mb_strtoupper(trim($filters['part'])))) return false;
            }
            return true;
        });

        $sorts = [
            'risk_desc' => [['risk_rank', 'desc'], ['withdraw_date', 'asc'], ['due_date', 'asc']],
            'due_asc' => [['due_date', 'asc'], ['risk_rank', 'desc']],
            'due_desc' => [['due_date', 'desc'], ['risk_rank', 'desc']],
            'withdraw_asc' => [['withdraw_date', 'asc'], ['risk_rank', 'desc']],
            'mfg_asc' => [['mfg', 'asc'], ['partnumber', 'asc']],
            'part_asc' => [['partnumber', 'asc'], ['due_date', 'asc']],
            'remaining_desc' => [['remaining_qty', 'desc'], ['risk_rank', 'desc']],
        ];
        return $rows->sortBy($sorts[$filters['sort'] ?? 'risk_desc'] ?? $sorts['risk_desc'])->values();
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

    public function suggestions(string $type, string $query, ?string $site = null): Collection
    {
        $query = mb_strtoupper(trim($query));
        if (mb_strlen($query) < 1 || !in_array($type, ['mfg', 'part'], true)) return collect();

        $rows = collect();
        foreach (self::MFG_CONNECTIONS as $siteCode => $connection) {
            if ($site && strtoupper($site) !== $siteCode) continue;
            if ($type === 'mfg') {
                $found = DB::connection($connection)->select("
                    SELECT DISTINCT UPPER(TRIM(workordernumber)) AS value
                    FROM workorder
                    WHERE dateclose IS NULL AND COALESCE(suspended, false) = false
                      AND UPPER(workordernumber) LIKE ?
                    ORDER BY value LIMIT 20
                ", ['%'.$query.'%']);
            } else {
                $found = DB::connection($connection)->select("
                    SELECT DISTINCT UPPER(TRIM(p.partnumber)) AS value, p.description
                    FROM workorder wo
                    JOIN workorderbom b ON b.workorder_id = wo.id
                    JOIN parts p ON p.id = b.parts_id
                    WHERE wo.dateclose IS NULL AND COALESCE(wo.suspended, false) = false
                      AND UPPER(TRIM(p.partnumber)) LIKE 'F%'
                      AND (UPPER(p.partnumber) LIKE ? OR UPPER(COALESCE(p.description, '')) LIKE ?)
                    ORDER BY value LIMIT 20
                ", ['%'.$query.'%', '%'.$query.'%']);
            }
            $rows = $rows->merge(collect($found)->map(fn($row) => [
                'value' => $row->value,
                'text' => $type === 'part' && !empty($row->description) ? $row->value.' — '.$row->description : $row->value,
            ]));
        }
        return $rows->unique('value')->take(20)->values();
    }

    private function applyMockRisk(array $row, Carbon $asOf): array
    {
        $variants = [
            ['RED', 'เกินกำหนดเบิก 2 วัน', 4, -2],
            ['ORANGE', 'ควรเบิกวันนี้', 3, 0],
            ['YELLOW', 'ใกล้ถึงกำหนดเบิก', 2, 1],
            ['GREEN', 'ยังไม่ถึงกำหนด', 0, 7],
        ];
        [$code, $label, $rank, $offset] = $variants[abs(crc32($row['site'].'|'.$row['mfg'].'|'.$row['partnumber'])) % 4];
        return array_merge($row, [
            'risk_code' => $code, 'risk_label' => $label.' (MOCK)', 'risk_rank' => $rank,
            'withdraw_date' => $asOf->copy()->addDays($offset)->toDateString(),
            'standard_days' => $row['standard_days'] ?? 5, 'mock_risk' => true,
        ]);
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
              AND UPPER(TRIM(p.partnumber)) LIKE 'F%'
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
