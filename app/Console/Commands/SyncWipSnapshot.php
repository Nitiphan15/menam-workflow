<?php

namespace App\Console\Commands;

use App\Repositories\FormWIP\WipRepository;
use App\Services\FormWIP\WipSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * sync สถานะ WIP ของ WO ที่เปิดอยู่ (WMSOne + ERP) ลงตาราง snapshot ใน sqlsrv_menam
 *
 *   php artisan wip:snapshot                 ทั้ง 2 site
 *   php artisan wip:snapshot --site=WIRE
 *   php artisan wip:snapshot --dry-run       คำนวณอย่างเดียว ไม่เขียน DB
 */
class SyncWipSnapshot extends Command
{
    protected $signature = 'wip:snapshot
        {--site=ALL : WIRE, PLUS หรือ ALL}
        {--dry-run : คำนวณและแสดงผล แต่ไม่เขียนลงฐานข้อมูล}
        {--by=schedule : บันทึกว่าใครสั่ง (schedule / manual)}';

    protected $description = 'Sync WIP status snapshot (WMSOne + ERP) into sqlsrv_menam wip_* tables';

    public function handle(WipSnapshotService $snapshot): int
    {
        $siteOpt = strtoupper((string) $this->option('site'));
        $sites = $siteOpt === 'ALL' ? array_keys(WipRepository::SITES) : [$siteOpt];
        foreach ($sites as $site) {
            if (!array_key_exists($site, WipRepository::SITES)) {
                $this->error("site ไม่ถูกต้อง: {$site}");

                return self::INVALID;
            }
        }

        $dryRun = (bool) $this->option('dry-run');

        Cache::forget('wip:snapshot:tables-ready');
        if (!$dryRun && !$snapshot->tablesReady()) {
            // ยังไม่ได้รัน database/sql/create_wip_monitoring_snapshot.sql → ข้ามแบบไม่ error (กัน alert ทุก 15 นาที)
            $this->warn('ยังไม่มีตาราง wip_* ใน ' . config('database.workflow_connection') . ' — ข้ามการ sync (รัน database/sql/create_wip_monitoring_snapshot.sql ก่อน)');

            return self::SUCCESS;
        }

        $failed = false;
        $rows = [];
        foreach ($sites as $site) {
            try {
                $r = $snapshot->sync($site, (string) $this->option('by'), $dryRun);
                $rows[] = [
                    $site,
                    $r['wo_count'],
                    $r['wip_wo_count'],
                    number_format($r['total_wip_kg'] / 1000, 1),
                    $r['new_count'],
                    $r['status_changes'],
                    $r['closed_count'],
                    $r['history_rows'],
                    $r['duration_ms'] . ' ms',
                ];
            } catch (\Throwable $e) {
                $failed = true;
                report($e);
                $rows[] = [$site, 'FAILED', '-', '-', '-', '-', '-', '-', mb_substr($e->getMessage(), 0, 60)];
            }
        }

        $this->table(['Site', 'WO เปิด', 'มี WIP', 'WIP (ตัน)', 'ใหม่', 'เปลี่ยนสถานะ', 'ปิด', 'history', 'เวลา'], $rows);
        if ($dryRun) {
            $this->comment('dry-run: ไม่ได้เขียนข้อมูลลงฐานข้อมูล');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
