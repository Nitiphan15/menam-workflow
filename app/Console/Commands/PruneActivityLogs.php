<?php

namespace App\Console\Commands;

use App\Models\UserActivityLog;
use Illuminate\Console\Command;

/**
 * ลบ activity log ที่เก่ากว่าระยะเก็บรักษา (ค่าเริ่มต้น config app.activity_log_retention_days = 365 วัน)
 * ลบทีละ batch เพื่อไม่ให้ lock ตารางนานและไม่ทำให้ transaction log โตทีเดียว
 *
 *   php artisan activity-log:prune --dry-run   ดูจำนวนที่จะถูกลบ (ไม่ลบจริง)
 *   php artisan activity-log:prune             ลบจริง
 */
class PruneActivityLogs extends Command
{
    protected $signature = 'activity-log:prune
        {--days= : จำนวนวันที่เก็บไว้ (ค่าเริ่มต้นจาก config app.activity_log_retention_days)}
        {--batch=5000 : จำนวนแถวที่ลบต่อรอบ}
        {--dry-run : นับจำนวนอย่างเดียว ไม่ลบ}';

    protected $description = 'ลบ user_activity_logs ที่เก่ากว่าระยะเก็บรักษา';

    /** ป้องกันพิมพ์ค่าผิดแล้วลบ log ใหม่ทิ้ง */
    private const MIN_DAYS = 90;

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('app.activity_log_retention_days', 365));
        $batch = max(100, min(20000, (int) $this->option('batch')));

        if ($days < self::MIN_DAYS) {
            $this->error('ต้องเก็บ log อย่างน้อย ' . self::MIN_DAYS . " วัน (ได้รับ {$days})");

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days)->startOfDay();
        $query = fn () => UserActivityLog::query()->where('accessed_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $this->info("พบ {$query()->count()} แถวที่เก่ากว่า {$cutoff->format('Y-m-d')} (dry-run ไม่ได้ลบ)");

            return self::SUCCESS;
        }

        $deleted = 0;
        do {
            $ids = $query()->orderBy('id')->limit($batch)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }

            $deleted += UserActivityLog::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === $batch);

        $this->info("ลบ activity log แล้ว {$deleted} แถว (เก่ากว่า {$cutoff->format('Y-m-d')})");

        return self::SUCCESS;
    }
}
