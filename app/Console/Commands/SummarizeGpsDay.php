<?php

namespace App\Console\Commands;

use App\Services\FormDP\GpsDailySummaryService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * สรุป GPS รายวันสำหรับ Dashboard ฝ่ายวางแผน (gps_trip_daily / gps_stop_visit)
 *
 *   php artisan gps:summarize-day --yesterday         เมื่อวาน (ใช้ใน schedule 00:30 — ได้ข้อมูลครบทั้งวัน)
 *   php artisan gps:summarize-day                     วันนี้ (ปุ่มอัปเดตระหว่างวัน)
 *   php artisan gps:summarize-day 2026-11-19
 *   php artisan gps:summarize-day 2026-11-01 --to=2026-11-19   ย้อนหลังหลายวัน
 *   php artisan gps:summarize-day 2026-11-19 --force  สรุปใหม่แม้เคยสรุปแล้ว
 *
 * DTC getHistory ≈ 1 request ต่อรถต่อวัน (เส้นทางที่ cache ไว้ไม่นับ). เมื่อชนเพดาน 3 ครั้ง/นาที
 * คำสั่งจะรอแล้วทำต่อเอง. หยุดทันทีเมื่อโควตาถูกล็อก (423) หรือใกล้หมดโควตาเดือน.
 */
class SummarizeGpsDay extends Command
{
    protected $signature = 'gps:summarize-day
        {date? : วันที่ส่ง Y-m-d (ค่าเริ่มต้น วันนี้)}
        {--yesterday : สรุปเมื่อวาน}
        {--to= : สรุปถึงวันที่นี้ (ย้อนหลังหลายวัน)}
        {--force : สรุปใหม่แม้เคยสรุปแล้ว}
        {--max-wait=600 : รอรวมได้นานสุดกี่วินาทีเมื่อชนเพดาน request ต่อนาที}';

    protected $description = 'Summarize DTC GPS routes per truck/day for the planner dashboard';

    public function handle(GpsDailySummaryService $summary): int
    {
        $summary->forgetSchemaCache();
        if (!$summary->tablesReady()) {
            // Not an error for the scheduler: the feature simply is not installed yet.
            $this->warn('ยังไม่มีตาราง gps_trip_daily / gps_stop_visit — รัน database/sql/formdp_gps_dashboard.sql ก่อน (ข้าม)');

            return self::SUCCESS;
        }

        try {
            $default = $this->option('yesterday') ? now('Asia/Bangkok')->subDay()->toDateString() : now('Asia/Bangkok')->toDateString();
            $from = Carbon::createFromFormat('Y-m-d', (string) ($this->argument('date') ?: $default), 'Asia/Bangkok')->startOfDay();
            $to = $this->option('to') ? Carbon::createFromFormat('Y-m-d', (string) $this->option('to'), 'Asia/Bangkok')->startOfDay() : $from->copy();
        } catch (Throwable) {
            $this->error('รูปแบบวันที่ต้องเป็น Y-m-d');

            return self::INVALID;
        }
        if ($to->lt($from) || $from->diffInDays($to) > 92) {
            $this->error('ช่วงวันที่ไม่ถูกต้อง (ไม่เกิน 92 วัน)');

            return self::INVALID;
        }

        $maxWait = max(0, (int) $this->option('max-wait'));
        $waited = 0;
        $exit = self::SUCCESS;

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $date = $day->toDateString();
            while (true) {
                $result = $summary->summarizeDay($date, (bool) $this->option('force'), PHP_INT_MAX, fn (string $line) => $this->line("  {$line}"));
                $this->info("{$date}: รถ {$result['trucks']} คัน · สรุป {$result['summarized']} · ข้าม {$result['skipped']} · ค้าง {$result['pending']} · {$result['status']}");
                foreach ($result['errors'] as $error) {
                    $this->warn("  {$error['plate_no']}: {$error['title']}");
                }
                if ($result['status'] === 'rate_limited' && $waited < $maxWait) {
                    $sleep = min(max(5, (int) ($result['retry_after'] ?? 60)), $maxWait - $waited);
                    $this->line("  รอ {$sleep} วินาที (DTC จำกัด 3 ครั้ง/นาที)");
                    $this->sleep($sleep);
                    $waited += $sleep;
                    continue; // already-summarized trucks are skipped on the next pass (past days)
                }
                if (in_array($result['status'], ['rate_limited', 'stopped'], true)) {
                    $this->warn('หยุดก่อนครบ — สั่งใหม่ภายหลังเพื่อสรุปส่วนที่ค้าง');

                    return self::FAILURE;
                }
                break;
            }
        }

        return $exit;
    }

    /** Separate method so tests can skip real waiting. */
    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}
