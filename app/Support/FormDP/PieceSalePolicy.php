<?php

namespace App\Support\FormDP;

class PieceSalePolicy
{
    public const PART_NUMBERS = ['SP-001'];

    public static function appliesTo(?string $partNumber): bool
    {
        return in_array(strtoupper(trim((string) $partNumber)), self::PART_NUMBERS, true);
    }
}
