<?php

namespace App\Services\FormWIP;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * WIP snapshot — เก็บสถานะ WO ลง SQL Server (sqlsrv_menam) เป็นรอบ ๆ เพื่อ
 *  1) รู้วันที่ WO "เข้าสถานะปัจจุบัน" จริง → Aging ตาม requirement
 *  2) เก็บประวัติการเปลี่ยนสถานะ / Work Center
 *  3) ภาพรวมรายวันไว้ดูแนวโน้ม
 *  4) ให้ dashboard อ่านจากตารางแทน query WMSOne ทุกครั้ง
 *
 * ตารางสร้างด้วย database/sql/create_wip_monitoring_snapshot.sql (ผู้ใช้รันเอง)
 * ถ้ายังไม่มีตาราง ทุก method จะคืนค่าว่าง / ข้ามการทำงาน ไม่ทำให้หน้าจอพัง
 *
 * ไม่มีการลบข้อมูล: WO ที่หายจากรายการเปิด → is_open = 0 + บันทึกประวัติ CLOSED
 */
class WipSnapshotService
{
    public const TABLES = ['wip_sync_runs', 'wip_wo_current', 'wip_status_history', 'wip_daily_snapshots'];
    private const CLOSED = 'CLOSED';

    // SQL Server จำกัด 2100 parameters ต่อ statement
    private const CURRENT_CHUNK = 50;
    private const DAILY_CHUNK = 80;

    public function __construct(private WipMonitoringService $monitoring)
    {
    }

    private function db(): ConnectionInterface
    {
        return DB::connection((string) config('database.workflow_connection', 'sqlsrv_menam'));
    }

    public function tablesReady(): bool
    {
        return Cache::remember('wip:snapshot:tables-ready', 600, function () {
            try {
                $schema = Schema::connection((string) config('database.workflow_connection', 'sqlsrv_menam'));
                foreach (self::TABLES as $table) {
                    if (!$schema->hasTable($table)) {
                        return false;
                    }
                }

                return true;
            } catch (\Throwable $e) {
                Log::warning('WIP snapshot table check failed', ['message' => $e->getMessage()]);

                return false;
            }
        });
    }

    /** sync สำเร็จล่าสุดของ site ถ้ายังไม่เก่าเกินกำหนด */
    public function freshRun(string $site, int $maxAgeMinutes): ?object
    {
        if (!$this->tablesReady()) {
            return null;
        }

        return $this->db()->table('wip_sync_runs')
            ->where('site', $site)
            ->where('status', 'SUCCESS')
            ->where('finished_at', '>=', now()->subMinutes($maxAgeMinutes))
            ->orderByDesc('finished_at')
            ->first(['id', 'finished_at']);
    }

    public function lastRuns(int $limit = 10): Collection
    {
        if (!$this->tablesReady()) {
            return collect();
        }

        return $this->db()->table('wip_sync_runs')->orderByDesc('id')->limit($limit)->get();
    }

    /** แถวสำหรับ dashboard จาก snapshot (WO ที่ยังเปิด และเปิดภายใน $days วัน) */
    public function openRows(string $site, int $days): array
    {
        $since = today()->subDays($days)->toDateString();
        $records = $this->db()->table('wip_wo_current')
            ->where('site', $site)
            ->where('is_open', 1)
            ->where('dateopen', '>=', $since)
            ->get(['workorder_id', 'status', 'status_since', 'payload']);

        $rows = [];
        $map = [];
        foreach ($records as $rec) {
            $row = json_decode((string) $rec->payload, true);
            if (!is_array($row)) {
                continue;
            }
            $rows[] = $row;
            $map[(int) $rec->workorder_id] = ['status' => $rec->status, 'status_since' => $rec->status_since];
        }

        return $this->monitoring->applyStatusSince($rows, $map);
    }

    /** @return array workorder_id => ['status', 'status_since'] */
    public function statusSinceMap(string $site, ?int $workorderId = null): array
    {
        if (!$this->tablesReady()) {
            return [];
        }

        try {
            $q = $this->db()->table('wip_wo_current')->where('site', $site)->where('is_open', 1);
            if ($workorderId !== null) {
                $q->where('workorder_id', $workorderId);
            }

            return $q->get(['workorder_id', 'status', 'status_since'])
                ->mapWithKeys(fn($r) => [(int) $r->workorder_id => ['status' => $r->status, 'status_since' => $r->status_since]])
                ->all();
        } catch (\Throwable $e) {
            Log::warning('WIP statusSinceMap failed', ['site' => $site, 'message' => $e->getMessage()]);

            return [];
        }
    }

    public function history(string $site, int $workorderId): Collection
    {
        if (!$this->tablesReady()) {
            return collect();
        }

        try {
            return $this->db()->table('wip_status_history')
                ->where('site', $site)
                ->where('workorder_id', $workorderId)
                ->orderByDesc('changed_at')
                ->orderByDesc('id')
                ->limit(200)
                ->get();
        } catch (\Throwable $e) {
            Log::warning('WIP status history failed', ['site' => $site, 'wo' => $workorderId, 'message' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * ยอด WIP รวมรายวัน — ใช้ชุด WO เดียวกับหน้าจอ: site, route (ends_with_pk), วันเปิด WO ภายใน $openDays วัน
     * (dateopen เอาจาก wip_wo_current เพราะ daily ไม่ได้เก็บไว้)
     */
    public function dailyTrend(array $sites, bool $pkOnly, int $openDays, int $lookbackDays): Collection
    {
        if (!$this->tablesReady() || empty($sites)) {
            return collect();
        }

        try {
            $q = $this->db()->table('wip_daily_snapshots as d')
                ->join('wip_wo_current as c', function ($j) {
                    $j->on('c.site', '=', 'd.site')->on('c.workorder_id', '=', 'd.workorder_id');
                })
                ->whereIn('d.site', $sites)
                ->where('d.snapshot_date', '>=', today()->subDays($lookbackDays)->toDateString())
                ->where('c.dateopen', '>=', today()->subDays($openDays)->toDateString())
                ->groupBy('d.snapshot_date')
                ->orderBy('d.snapshot_date')
                ->selectRaw('d.snapshot_date,
                    SUM(d.waiting_production_kg) AS wp, SUM(d.in_process_kg) AS ip, SUM(d.waiting_packaging_kg) AS pkg,
                    SUM(d.waiting_fg_kg) AS fg, SUM(d.hold_kg) AS hd, SUM(d.wip_kg) AS wip,
                    SUM(CASE WHEN d.wip_kg > 0 THEN 1 ELSE 0 END) AS wo_count');
            if ($pkOnly) {
                $q->where('d.ends_with_pk', 1);
            }

            return $q->get();
        } catch (\Throwable $e) {
            Log::warning('WIP daily trend failed', ['message' => $e->getMessage()]);

            return collect();
        }
    }

    // ------------------------------------------------------------------ sync

    /**
     * @return array สรุปผล (wo_count, wip_wo_count, total_wip_kg, status_changes, new_count, closed_count, duration_ms, run_id)
     */
    public function sync(string $site, string $triggeredBy = 'schedule', bool $dryRun = false): array
    {
        $started = microtime(true);
        $now = now()->startOfSecond();
        $db = $this->db();
        $runId = null;

        if (!$dryRun) {
            $runId = $db->table('wip_sync_runs')->insertGetId([
                'site' => $site,
                'status' => 'RUNNING',
                'triggered_by' => mb_substr($triggeredBy, 0, 50),
                'started_at' => $now,
            ]);
        }

        try {
            $result = $this->buildSyncPlan($site, $now, $runId);

            if (!$dryRun) {
                $db->transaction(function () use ($db, $result) {
                    foreach (array_chunk($result['current'], self::CURRENT_CHUNK) as $chunk) {
                        $db->table('wip_wo_current')->upsert(
                            $chunk,
                            ['site', 'workorder_id'],
                            array_values(array_diff(array_keys($chunk[0]), ['site', 'workorder_id', 'first_seen_at']))
                        );
                    }
                    foreach (array_chunk($result['closed_ids'], 500) as $ids) {
                        $db->table('wip_wo_current')
                            ->where('site', $result['site'])
                            ->whereIn('workorder_id', $ids)
                            ->update(['is_open' => 0, 'closed_at' => $result['now'], 'last_synced_at' => $result['now'], 'sync_run_id' => $result['run_id']]);
                    }
                    foreach (array_chunk($result['history'], 100) as $chunk) {
                        $db->table('wip_status_history')->insert($chunk);
                    }
                    foreach (array_chunk($result['daily'], self::DAILY_CHUNK) as $chunk) {
                        $db->table('wip_daily_snapshots')->upsert(
                            $chunk,
                            ['snapshot_date', 'site', 'workorder_id'],
                            array_values(array_diff(array_keys($chunk[0]), ['snapshot_date', 'site', 'workorder_id']))
                        );
                    }
                });
            }

            $summary = $result['summary'] + [
                'run_id' => $runId,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'dry_run' => $dryRun,
            ];

            if (!$dryRun) {
                $db->table('wip_sync_runs')->where('id', $runId)->update([
                    'status' => 'SUCCESS',
                    'finished_at' => now()->startOfSecond(),
                    'wo_count' => $summary['wo_count'],
                    'wip_wo_count' => $summary['wip_wo_count'],
                    'total_wip_kg' => $summary['total_wip_kg'],
                    'status_changes' => $summary['status_changes'],
                    'closed_count' => $summary['closed_count'],
                    'duration_ms' => $summary['duration_ms'],
                ]);
            }

            return $summary;
        } catch (\Throwable $e) {
            if ($runId) {
                try {
                    $db->table('wip_sync_runs')->where('id', $runId)->update([
                        'status' => 'FAILED',
                        'finished_at' => now()->startOfSecond(),
                        'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                        'error_message' => mb_substr($e->getMessage(), 0, 1000),
                    ]);
                } catch (\Throwable) {
                    // ignore — ส่ง exception เดิมต่อ
                }
            }
            throw $e;
        }
    }

    /** คำนวณสิ่งที่ต้องเขียน (ไม่แตะ DB ฝั่งเขียน) — ใช้ทั้ง sync จริงและ dry-run */
    public function buildSyncPlan(string $site, Carbon $now, ?int $runId, ?Collection $existing = null, ?array $rows = null): array
    {
        $rows ??= $this->monitoring->computeSiteRows($site, WipMonitoringService::SYNC_DAYS)['rows'];

        $existing ??= $this->tablesReady()
            ? $this->db()->table('wip_wo_current')
                ->where('site', $site)
                ->get(['workorder_id', 'wo_no', 'status', 'status_since', 'current_wc', 'wc_since', 'is_open', 'wip_kg'])
                ->keyBy(fn($r) => (int) $r->workorder_id)
            : collect();

        $nowStr = $now->toDateTimeString();
        $today = $now->toDateString();
        $current = [];
        $history = [];
        $daily = [];
        $seen = [];
        $changes = 0;
        $newCount = 0;

        foreach ($rows as $row) {
            $id = (int) $row['workorder_id'];
            $seen[$id] = true;
            $prev = $existing->get($id);
            $status = $row['status'];
            $wc = $row['current_wc'] !== '-' ? $row['current_wc'] : null;

            if (!$prev) {
                // พบครั้งแรก: ยังไม่รู้วันเข้าสถานะจริง → ใช้วันที่ประมาณจาก transaction ล่าสุด
                $statusSince = $this->dt($row['activity_ref'] ?? null) ?? $nowStr;
                $wcSince = $statusSince;
                $newCount++;
                $history[] = $this->historyRow($site, $row, null, $status, null, $wc, null, $nowStr, $runId);
            } else {
                $wasOpen = (bool) $prev->is_open;
                $prevStatus = $wasOpen ? $prev->status : self::CLOSED;
                $statusChanged = $prevStatus !== $status;
                $wcChanged = (string) ($prev->current_wc ?? '') !== (string) ($wc ?? '');

                $statusSince = $statusChanged ? $nowStr : ($this->dt($prev->status_since) ?? $nowStr);
                $wcSince = ($statusChanged || $wcChanged) ? $nowStr : ($this->dt($prev->wc_since) ?? $statusSince);

                if ($statusChanged || $wcChanged) {
                    $daysInPrev = $prev->status_since ? (int) Carbon::parse($prev->status_since)->diffInDays($now) : null;
                    $history[] = $this->historyRow($site, $row, $prevStatus, $status, $prev->current_wc, $wc, $statusChanged ? $daysInPrev : null, $nowStr, $runId);
                    if ($statusChanged) {
                        $changes++;
                    }
                }
            }

            [$row] = $this->monitoring->applyStatusSince([$row], [$id => ['status' => $status, 'status_since' => $statusSince]]);
            $b = $row['buckets'];

            $current[] = [
                'site' => $site,
                'workorder_id' => $id,
                'wo_no' => mb_substr($row['wo_no'], 0, 40),
                'dateopen' => $row['dateopen'] ? Carbon::parse($row['dateopen'])->toDateString() : null,
                'qty_kg' => round((float) $row['qty'], 3),
                'status' => $status,
                'status_since' => $statusSince,
                'current_wc' => $wc,
                'wc_since' => $wcSince,
                'waiting_production_kg' => $b['WAITING_PRODUCTION'],
                'in_process_kg' => $b['IN_PROCESS'],
                'waiting_packaging_kg' => $b['WAITING_PACKAGING'],
                'waiting_fg_kg' => $b['WAITING_FG'],
                'hold_kg' => $b['HOLD'],
                'completed_kg' => $b['COMPLETED'],
                'wip_kg' => $row['wip_total'],
                'last_tx' => $this->dt($row['last_tx']),
                'is_open' => 1,
                'closed_at' => null,
                'payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                'first_seen_at' => $nowStr,
                'last_synced_at' => $nowStr,
                'sync_run_id' => $runId,
            ];

            $daily[] = [
                'snapshot_date' => $today,
                'site' => $site,
                'workorder_id' => $id,
                'wo_no' => mb_substr($row['wo_no'], 0, 40),
                'status' => $status,
                'current_wc' => $wc,
                'aging_days' => $row['aging_days'],
                'aging_band' => $row['aging_band'],
                'ends_with_pk' => $row['ends_with_pk'] ? 1 : 0,
                'qty_kg' => round((float) $row['qty'], 3),
                'waiting_production_kg' => $b['WAITING_PRODUCTION'],
                'in_process_kg' => $b['IN_PROCESS'],
                'waiting_packaging_kg' => $b['WAITING_PACKAGING'],
                'waiting_fg_kg' => $b['WAITING_FG'],
                'hold_kg' => $b['HOLD'],
                'completed_kg' => $b['COMPLETED'],
                'wip_kg' => $row['wip_total'],
                'updated_at' => $nowStr,
            ];
        }

        // WO ที่เคยเปิดแต่รอบนี้ไม่อยู่ในรายการ = ปิดใน WMSOne (หรือเลยช่วง SYNC_DAYS)
        $closedIds = [];
        foreach ($existing as $id => $prev) {
            if ($prev->is_open && !isset($seen[$id])) {
                $closedIds[] = $id;
                $history[] = [
                    'site' => $site,
                    'workorder_id' => $id,
                    'wo_no' => $prev->wo_no,
                    'from_status' => $prev->status,
                    'to_status' => self::CLOSED,
                    'from_wc' => $prev->current_wc,
                    'to_wc' => null,
                    'wip_kg' => $prev->wip_kg,
                    'days_in_prev' => $prev->status_since ? (int) Carbon::parse($prev->status_since)->diffInDays($now) : null,
                    'changed_at' => $nowStr,
                    'sync_run_id' => $runId,
                ];
            }
        }

        $wipRows = array_filter($current, fn($c) => $c['wip_kg'] > 0);

        return [
            'site' => $site,
            'now' => $nowStr,
            'run_id' => $runId,
            'current' => $current,
            'closed_ids' => $closedIds,
            'history' => $history,
            'daily' => $daily,
            'summary' => [
                'site' => $site,
                'wo_count' => count($current),
                'wip_wo_count' => count($wipRows),
                'total_wip_kg' => round(array_sum(array_column($wipRows, 'wip_kg')), 3),
                'status_changes' => $changes,
                'new_count' => $newCount,
                'closed_count' => count($closedIds),
                'history_rows' => count($history),
                'existing_rows' => $existing->count(),
            ],
        ];
    }

    private function historyRow(string $site, array $row, ?string $from, string $to, ?string $fromWc, ?string $toWc, ?int $daysInPrev, string $now, ?int $runId): array
    {
        return [
            'site' => $site,
            'workorder_id' => (int) $row['workorder_id'],
            'wo_no' => mb_substr($row['wo_no'], 0, 40),
            'from_status' => $from,
            'to_status' => $to,
            'from_wc' => $fromWc,
            'to_wc' => $toWc,
            'wip_kg' => $row['wip_total'],
            'days_in_prev' => $daysInPrev,
            'changed_at' => $now,
            'sync_run_id' => $runId,
        ];
    }

    private function dt($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfSecond()->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}
