<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')->hourly();

        // แจ้งเตือน Planner เรื่องงาน WOCR ที่ยังไม่ได้ดำเนินการ ทุกเช้าวันทำการ 08:00
        $schedule->command('wocr:pending-digest')
            ->weekdays()
            ->dailyAt('08:00')
            ->timezone('Asia/Bangkok')
            ->onOneServer()
            ->withoutOverlapping();

        $schedule->command('stock-withdrawal:rollover-queue')
            ->days([1, 2, 3, 4, 5, 6])
            ->dailyAt('00:05')
            ->timezone('Asia/Bangkok')
            ->onOneServer()
            ->withoutOverlapping();

        // Deadstock schedules are owned by mail-daily/routes/deadstock-schedule.php.
        // Keep the commands here: they use this application's review services/database.

        $schedule->command('wr-deadstock:sync')
            ->hourlyAt(0)
            ->timezone('Asia/Bangkok')
            ->onOneServer()
            ->withoutOverlapping(55);

        $schedule->command('wr-deadstock:sync --snapshot=DAILY')
            ->dailyAt('08:03')
            ->timezone('Asia/Bangkok')
            ->onOneServer()
            ->withoutOverlapping(55);

        $schedule->command('wr-deadstock:sync --snapshot=MONTH_END')
            ->dailyAt('23:30')
            ->when(fn () => now('Asia/Bangkok')->isLastOfMonth())
            ->timezone('Asia/Bangkok')
            ->onOneServer()
            ->withoutOverlapping(55);
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
