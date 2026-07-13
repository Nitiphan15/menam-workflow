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

        $schedule->command('deadstock:import-snapshot')
            ->dailyAt('08:03')
            ->timezone('Asia/Bangkok')
            ->onOneServer()
            ->withoutOverlapping();

        $schedule->command('deadstock:compare-current')
            ->hourly()
            ->timezone('Asia/Bangkok')
            ->onOneServer()
            ->withoutOverlapping();

        $schedule->command('deadstock:capture-month-end')
            ->lastDayOfMonth('23:30')
            ->timezone('Asia/Bangkok')
            ->onOneServer()
            ->withoutOverlapping();
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
