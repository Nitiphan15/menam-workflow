<?php

namespace Tests\Unit;

require_once dirname(__DIR__, 2) . '/app/Http/Controllers/FormFC/ForecastRmController.php';

use App\Http\Controllers\FormFC\ForecastRmController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class FormFcSoPieceWeightTest extends TestCase
{
    private function calculate(float $qty, string $part, string $unit, $diameter, $length): float
    {
        $method = new ReflectionMethod(ForecastRmController::class, 'calculateSoWeightKg');
        $method->setAccessible(true);

        return $method->invoke(null, $qty, $part, $unit, $diameter, $length);
    }

    public function test_e_part_unit_03_converts_so_pieces_to_kg_with_requested_formula(): void
    {
        $weight = $this->calculate(1200, 'FBS45CXXXX161500366E', '03', 16.15, 366);

        $this->assertEqualsWithDelta(713.0996, $weight, 0.0001);
    }

    public function test_non_e_or_non_03_so_quantity_stays_unchanged(): void
    {
        $this->assertSame(1200.0, $this->calculate(1200, 'FBS45CXXXX161500366', '03', 16.15, 366));
        $this->assertSame(1200.0, $this->calculate(1200, 'FBS45CXXXX161500366E', '01', 16.15, 366));
        $this->assertSame(1200.0, $this->calculate(1200, 'FBS45CXXXX161500366E', '03', null, 366));
    }

    public function test_index_and_excel_label_so_as_kg(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root . '/app/Http/Controllers/FormFC/ForecastRmController.php');
        $blade = file_get_contents($root . '/resources/views/formfc/index.blade.php');

        $this->assertGreaterThanOrEqual(3, substr_count($controller, "'SO (KG)'"));
        $this->assertStringContainsString('SO (KG)</th>', $blade);
        $this->assertStringContainsString("detailCacheKey('so-kg-v1'", $controller);
        $this->assertStringContainsString("'backorder_pcs'", $controller);
        $this->assertStringContainsString("label: 'SO Qty (KG)'", $blade);
        $this->assertStringContainsString("label: 'SO Qty (เส้น)'", $blade);
    }
}
