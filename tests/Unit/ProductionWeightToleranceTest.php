<?php

namespace Tests\Unit;

use App\Support\FormDP\ProductionWeightTolerance;
use PHPUnit\Framework\TestCase;

class ProductionWeightToleranceTest extends TestCase
{
    /** @dataProvider weightBands */
    public function test_shortage_rate_follows_target_weight_band(float $targetKg, float $expectedRate): void
    {
        $this->assertSame($expectedRate, ProductionWeightTolerance::shortageRate($targetKg));
    }

    public function weightBands(): array
    {
        return [
            '5 kg' => [5, 0.10],
            '99 kg' => [99, 0.10],
            '100 kg' => [100, 0.05],
            '499 kg' => [499, 0.05],
            '500 kg' => [500, 0.04],
            '999 kg' => [999, 0.04],
            '1,000 kg' => [1_000, 0.03],
            '4,999 kg' => [4_999, 0.03],
            '5,000 kg' => [5_000, 0.025],
            '9,999 kg' => [9_999, 0.025],
            '10,000 kg' => [10_000, 0.02],
            '19,999 kg' => [19_999, 0.02],
            '20,000 kg' => [20_000, 0.015],
            '30,000 kg' => [30_000, 0.015],
        ];
    }

    /** @dataProvider examples */
    public function test_allowed_shortage_matches_business_examples(float $targetKg, float $allowedShortageKg): void
    {
        $this->assertEqualsWithDelta(
            $allowedShortageKg,
            ProductionWeightTolerance::allowedShortageKg($targetKg),
            0.00001
        );
    }

    public function examples(): array
    {
        return [
            [50, 5],
            [300, 15],
            [800, 32],
            [2_000, 60],
            [8_000, 200],
            [15_000, 300],
            [30_000, 450],
        ];
    }

    public function test_completion_uses_the_minimum_received_weight_for_the_band(): void
    {
        $this->assertTrue(ProductionWeightTolerance::isComplete(285, 300));
        $this->assertFalse(ProductionWeightTolerance::isComplete(284.99, 300));
        $this->assertFalse(ProductionWeightTolerance::isComplete(0, 300));
        $this->assertTrue(ProductionWeightTolerance::isComplete(1, 0));
    }
}
