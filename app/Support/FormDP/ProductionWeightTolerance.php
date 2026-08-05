<?php

namespace App\Support\FormDP;

final class ProductionWeightTolerance
{
    public static function shortageRate(float $targetWeightKg): float
    {
        if ($targetWeightKg < 100) {
            return 0.10;
        }

        if ($targetWeightKg < 500) {
            return 0.05;
        }

        if ($targetWeightKg < 1_000) {
            return 0.04;
        }

        if ($targetWeightKg < 5_000) {
            return 0.03;
        }

        if ($targetWeightKg < 10_000) {
            return 0.025;
        }

        if ($targetWeightKg < 20_000) {
            return 0.02;
        }

        return 0.015;
    }

    public static function allowedShortageKg(float $targetWeightKg): float
    {
        return max(0.0, $targetWeightKg) * self::shortageRate($targetWeightKg);
    }

    public static function minimumReceivedKg(float $targetWeightKg): float
    {
        return max(0.0, $targetWeightKg - self::allowedShortageKg($targetWeightKg));
    }

    public static function isComplete(float $receivedWeightKg, float $targetWeightKg): bool
    {
        if ($receivedWeightKg <= 0) {
            return false;
        }

        if ($targetWeightKg <= 0) {
            return true;
        }

        return $receivedWeightKg >= self::minimumReceivedKg($targetWeightKg);
    }
}
