<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        // กันเทสต์ (เช่น RefreshDatabase) วิ่งใส่ฐาน PROD — เช็กก่อน trait ใด ๆ จะทำงาน
        if ($app['config']->get('database.targets_prod')) {
            throw new \RuntimeException(
                'หยุดเทสต์: SQL Server ชี้ฐาน PROD อยู่ (DB_PROFILE=prod หรือชื่อ DB ตรงกับ DB_PROFILE_PROD_DATABASE). '
                . 'สลับเป็น DB_PROFILE=dev แล้ว php artisan config:clear ก่อน'
            );
        }

        return $app;
    }
}
