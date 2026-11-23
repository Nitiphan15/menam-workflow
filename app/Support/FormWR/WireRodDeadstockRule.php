<?php

namespace App\Support\FormWR;

use App\Support\FormWOS\DeadstockSalesMap;
use Carbon\CarbonInterface;

final class WireRodDeadstockRule
{
    public const DEAD_AFTER_DAYS = 180;

    public static function stockStatus(?CarbonInterface $purchaseDate, CarbonInterface $asOf): string
    {
        if (! $purchaseDate) {
            return 'UNKNOWN';
        }

        return $purchaseDate->diffInDays($asOf, false) > self::DEAD_AFTER_DAYS
            ? 'DEAD'
            : 'INCOMING';
    }

    /** Display label only; the stored value stays INCOMING/DEAD/UNKNOWN (DB CHECK constraint). */
    public static function stockStatusLabel(?string $status): string
    {
        return match (strtoupper(trim((string) $status))) {
            'INCOMING' => 'Normal Stock',
            'DEAD' => 'Dead Stock',
            'UNKNOWN' => 'Unknown',
            default => (string) $status,
        };
    }

    public const AGING_BUCKETS = [
        '0_90' => ['label' => '0-90 Days', 'min' => 0, 'max' => 90],
        '91_180' => ['label' => '91-180 Days', 'min' => 91, 'max' => 180],
        '181_365' => ['label' => '181-365 Days', 'min' => 181, 'max' => 365],
        'over_365' => ['label' => '>365 Days', 'min' => 366, 'max' => null],
    ];

    public static function agingBucket(?int $ageDays): ?string
    {
        if ($ageDays === null) {
            return null;
        }

        foreach (self::AGING_BUCKETS as $key => $bucket) {
            if ($ageDays >= $bucket['min'] && ($bucket['max'] === null || $ageDays <= $bucket['max'])) {
                return $key;
            }
        }

        return '0_90'; // negative age (future purchase date) counts as newest bucket
    }

    public const DIVISION_SUMMARY_ROWS = [
        'D1' => 'D1 - คุณดิลก',
        'D2' => 'D2 - คุณปรียาพรรณ',
        'D3' => 'D3 - คุณภควดี',
        'D5' => 'D5 - คุณธัธลิญา',
        'D6' => 'D6 - คุณสุรศักดิ์',
        'D7' => 'D7 - คุณศิรินภา',
        'D9' => 'D9 - คุณวรเดชา',
        'PLANNING' => 'Planning (ไม่มีชื่อลูกค้า, Stock)',
        'NO_SALES' => 'ไม่ระบุ Sales (มีลูกค้า)',
        'OTHER' => 'อื่น ๆ',
    ];

    /** Summary row key for the division table (KG only; D8 is PCS and excluded by the caller). */
    public static function divisionSummaryKey(?string $divisionCode, bool $hasCustomer): string
    {
        $code = strtoupper(trim((string) $divisionCode));
        if ($code !== '' && isset(self::DIVISION_SUMMARY_ROWS[$code]) && ! in_array($code, ['PLANNING', 'NO_SALES', 'OTHER'], true)) {
            return $code;
        }
        if (! $hasCustomer) {
            return 'PLANNING';
        }

        return $code === '' ? 'NO_SALES' : 'OTHER';
    }

    public static function materialGroup(?string $description, ?int $partTypeId): string
    {
        if (preg_match('/^\s*slit\s*coil/i', (string) $description)) {
            return 'Slit Coil';
        }

        return match ($partTypeId) {
            70 => 'Stainless Steel',
            71 => 'Steel',
            95 => 'Grating',
            default => 'Other',
        };
    }

    public static function division(?string $salesName): ?string
    {
        $name = trim((string) $salesName);

        return $name === '' ? null : DeadstockSalesMap::accessKey($name);
    }

    public static function displayUnit(?string $division, ?string $erpUnit): string
    {
        if (strtoupper(trim((string) $division)) === 'D8') {
            return 'PCS';
        }

        if (trim((string) $division) !== '') {
            return 'KG';
        }

        return match (strtoupper(trim((string) $erpUnit))) {
            '01', 'KG' => 'KG',
            '03', 'PCS', 'PC', 'PIECE' => 'PCS',
            default => 'UNKNOWN',
        };
    }
}
