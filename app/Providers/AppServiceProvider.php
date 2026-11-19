<?php

namespace App\Providers;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->guardDestructiveDatabaseCommands();
    }

    /**
     * Safety guard: บล็อกคำสั่ง artisan ที่ล้าง/ย้อน schema ทั้งระบบ
     * (Laravel 9 ไม่มี DB::prohibitDestructiveCommands())
     *
     * ปลดล็อกชั่วคราวได้โดยตั้ง ALLOW_DESTRUCTIVE_DB=true ใน .env แล้วลบออกหลังใช้งาน
     * ข้อยกเว้น: ตอนรัน test บน sqlite :memory: (เช่น RefreshDatabase) ยังทำงานได้
     */
    private function guardDestructiveDatabaseCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            $blocked = [
                'migrate',
                'migrate:fresh',
                'migrate:refresh',
                'migrate:reset',
                'migrate:rollback',
                'db:wipe',
                'db:seed',
                'schema:dump',
            ];

            // ชี้ฐาน prod อยู่ (DB_PROFILE=prod หรือชื่อ DB ตรงกับ prod) -> ห้ามรัน test ด้วย
            // และไม่ยอมให้ ALLOW_DESTRUCTIVE_DB ปลดล็อก
            if (config('database.targets_prod')) {
                if (! in_array($event->command, array_merge($blocked, ['test']), true)) {
                    return;
                }

                $message = "คำสั่ง [{$event->command}] ถูกบล็อก: ตอนนี้ SQL Server ชี้ฐาน PROD อยู่. "
                    . 'สลับเป็น DB_PROFILE=dev แล้ว php artisan config:clear ก่อน';

                $event->output->writeln("<error>{$message}</error>");

                throw new RuntimeException($message);
            }

            if (! in_array($event->command, $blocked, true)) {
                return;
            }

            if (filter_var(env('ALLOW_DESTRUCTIVE_DB', false), FILTER_VALIDATE_BOOLEAN)) {
                return;
            }

            $default = config('database.default');
            $isInMemorySqlite = config("database.connections.{$default}.driver") === 'sqlite'
                && config("database.connections.{$default}.database") === ':memory:';

            if ($this->app->environment('testing') && $isInMemorySqlite) {
                return;
            }

            $message = "คำสั่ง [{$event->command}] ถูกบล็อกเพื่อป้องกันการล้างฐานข้อมูล. "
                . 'ให้รัน SQL script ด้วยตนเองแทน (หรือตั้ง ALLOW_DESTRUCTIVE_DB=true ใน .env ชั่วคราวถ้าจำเป็นจริงๆ)';

            $event->output->writeln("<error>{$message}</error>");

            throw new RuntimeException($message);
        });
    }
}
