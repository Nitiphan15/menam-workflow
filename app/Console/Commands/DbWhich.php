<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * แสดงว่าตอนนี้ SQL Server connection ชี้ฐานไหน (ไม่แสดงรหัสผ่าน)
 *
 * php artisan db:which          -> ดูค่าจาก config
 * php artisan db:which --ping   -> ลองต่อจริงและถาม DB_NAME() จากเซิร์ฟเวอร์
 */
class DbWhich extends Command
{
    protected $signature = 'db:which {--ping : ลองเชื่อมต่อจริงแล้วถามชื่อ DB จากเซิร์ฟเวอร์}';

    protected $description = 'แสดงโปรไฟล์ DB (dev/prod) และชื่อฐานที่ sqlsrv / sqlsrv_menam ชี้อยู่';

    public function handle(): int
    {
        $profile = config('database.profile') ?? '(ไม่ได้ตั้ง — ใช้ DB_DATABASE / SQLSRV_DATABASE เดิม)';
        $targetsProd = (bool) config('database.targets_prod');

        $this->line('DB_PROFILE : <info>' . $profile . '</info>');
        $this->line('ชี้ PROD   : ' . ($targetsProd ? '<error> YES </error>' : '<info>no</info>'));
        $this->newLine();

        $rows = [];
        foreach (['sqlsrv', 'sqlsrv_menam'] as $name) {
            $cfg = config("database.connections.{$name}", []);
            $row = [
                $name,
                $cfg['host'] ?? '-',
                $cfg['database'] ?? '-',
                $name === config('database.default') ? 'default' : '',
            ];

            if ($this->option('ping')) {
                try {
                    $result = DB::connection($name)->selectOne('SELECT DB_NAME() AS db');
                    $row[] = '<info>' . ($result->db ?? '?') . '</info>';
                } catch (Throwable $e) {
                    $row[] = '<error>ต่อไม่ได้</error>';
                }
            }

            $rows[] = $row;
        }

        $headers = ['connection', 'host', 'database (config)', ''];
        if ($this->option('ping')) {
            $headers[] = 'DB_NAME() จริง';
        }

        $this->table($headers, $rows);

        if (str_contains(implode(' ', array_column($rows, 2)), '__missing_')) {
            $this->warn('ตั้ง DB_PROFILE แล้วแต่ยังไม่ได้ใส่ DB_PROFILE_' . strtoupper((string) config('database.profile')) . '_DATABASE ใน .env');
        }

        return self::SUCCESS;
    }
}
