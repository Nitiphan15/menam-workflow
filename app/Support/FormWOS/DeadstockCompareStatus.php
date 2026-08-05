<?php

namespace App\Support\FormWOS;

final class DeadstockCompareStatus
{
    public const ALL = 'all';

    public const FOLLOW_UP = 'review';

    public const OUTSTANDING = 'active';

    public const CLEARED = 'cleared';

    public const DEFAULT = self::OUTSTANDING;

    public static function filterOptions(): array
    {
        return [
            self::ALL => 'ทั้งหมด',
            self::FOLLOW_UP => 'ต้องติดตาม',
            self::OUTSTANDING => 'คงค้าง',
            self::CLEARED => 'เคลียร์แล้ว',
        ];
    }

    public static function rawLabels(): array
    {
        return [
            'pending' => 'รอเทียบข้อมูล',
            'active' => 'คงค้าง',
            'changed' => 'เปลี่ยนแปลง',
            'cleared' => 'เคลียร์แล้ว',
        ];
    }

    public static function normalizePublic($status): string
    {
        $status = trim((string) $status);

        return array_key_exists($status, self::filterOptions())
            ? $status
            : self::DEFAULT;
    }

    public static function valuesForFilter(string $status): array
    {
        return match ($status) {
            self::FOLLOW_UP => ['pending', 'changed'],
            self::OUTSTANDING => ['active'],
            self::CLEARED => ['cleared'],
            default => [],
        };
    }
}
