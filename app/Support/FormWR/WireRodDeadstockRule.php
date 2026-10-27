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
