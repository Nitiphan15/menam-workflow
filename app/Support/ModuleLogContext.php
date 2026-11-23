<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ModuleLogContext
{
    private const ROUTE_MODULES = [
        'fc.' => 'FC',
        'pp.' => 'PP',
        'wocr.' => 'WOCR',
        'dp.' => 'DP',
    ];

    private const SAFE_KEYS = [
        'id',
        'form_id',
        'ord_id',
        'ordId',
        'docu_no',
        'document_no',
        'mfg_no',
        'so_number',
        'plan_month',
        'site',
        'confirmation_status',
    ];

    public static function moduleForRouteName(?string $routeName): ?string
    {
        foreach (self::ROUTE_MODULES as $prefix => $module) {
            if (Str::startsWith((string) $routeName, $prefix)) {
                return $module;
            }
        }

        return null;
    }

    /**
     * ชื่อ module สั้น ๆ สำหรับติดป้ายใน log / หน้า Error Log
     * ต่างจาก moduleForRouteName() ที่ใช้เปิด audit log เฉพาะ 4 module หลัก
     */
    private const LABEL_ALIASES = [
        // route name prefix
        'stock-withdrawal'    => 'STOCK',
        'die-reports'         => 'DIE',
        'grating-performance' => 'GP',
        'paadmin'             => 'PA',
        'variable-cost'       => 'VC',
        'machine-load'        => 'MLA',
        'deadstock'           => 'WOS',
        'cost-center'         => 'CCR',
        'adminweb'            => 'ADMIN',
        'organization'        => 'ADMIN',
        'login'               => 'AUTH',
        'logout'              => 'AUTH',
        'profile'             => 'AUTH',
        'request-form'        => 'AUTH',
        'client-errors'       => 'JS',
        // artisan command prefix
        'wr-deadstock'        => 'WR',
        'activity-log'        => 'ADMIN',
        'schedule'            => 'SCHEDULER',
        'queue'               => 'QUEUE',
    ];

    public static function labelForRouteName(?string $routeName): ?string
    {
        $explicit = self::moduleForRouteName($routeName);
        if ($explicit !== null) {
            return $explicit;
        }

        $name = trim((string) $routeName);
        if ($name === '') {
            return null;
        }

        return self::aliasOrUpper(strtok($name, '.'));
    }

    /**
     * ชื่อ module จากคำสั่ง artisan เช่น "wr-deadstock:sync --snapshot=DAILY" -> WR
     */
    public static function labelForCommand(?string $command): ?string
    {
        $name = trim((string) $command);
        if ($name === '') {
            return null;
        }

        $name = strtok($name, ' ');

        return self::aliasOrUpper(str_contains($name, ':') ? strtok($name, ':') : $name);
    }

    /**
     * ชื่อ module จากข้อความ log เก่าที่ขึ้นต้นด้วย "FormVC: ..." / "FormMLA: ..."
     */
    public static function labelFromMessage(?string $message): ?string
    {
        return preg_match('/^Form([A-Za-z]+):/', (string) $message, $m) ? strtoupper($m[1]) : null;
    }

    private static function aliasOrUpper(string $prefix): ?string
    {
        $prefix = strtolower(trim($prefix));
        if ($prefix === '' || !preg_match('/^[a-z0-9_-]{1,40}$/', $prefix)) {
            return null;
        }

        return self::LABEL_ALIASES[$prefix] ?? strtoupper($prefix);
    }

    public static function requestId(Request $request): string
    {
        $incoming = trim((string) $request->header('X-Request-ID', ''));

        return $incoming !== '' && preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $incoming)
            ? $incoming
            : (string) Str::uuid();
    }

    public static function identifiers(Request $request): array
    {
        $values = array_merge($request->route()?->parameters() ?? [], $request->only(self::SAFE_KEYS));
        $safe = [];

        foreach (self::SAFE_KEYS as $key) {
            $value = $values[$key] ?? null;
            if (!is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $safe[$key === 'ordId' ? 'ord_id' : $key] = Str::limit(trim((string) $value), 120, '');
        }

        return $safe;
    }

    public static function exceptionMessage(\Throwable $e): string
    {
        $message = $e->getMessage();
        $message = preg_replace(
            '/\b(password|passwd|pwd|token|secret|authorization)\b\s*[:=]\s*([^\s,;]+)/i',
            '$1=[REDACTED]',
            $message
        ) ?? $message;

        return Str::limit($message, 1000, '...');
    }
}
