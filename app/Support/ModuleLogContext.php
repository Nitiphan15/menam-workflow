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
