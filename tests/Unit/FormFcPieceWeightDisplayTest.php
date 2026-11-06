<?php

namespace Tests\Unit;

require_once dirname(__DIR__, 2) . '/app/Http/Controllers/FormFC/ForecastRmDivisionController.php';

use App\Http\Controllers\FormFC\ForecastRmDivisionController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class FormFcPieceWeightDisplayTest extends TestCase
{
    private function calculate(string $part, string $unit, $diameter, $length): ?float
    {
        $method = new ReflectionMethod(ForecastRmDivisionController::class, 'calculatePieceWeightKg');
        $method->setAccessible(true);

        return $method->invoke(null, $part, $unit, $diameter, $length);
    }

    public function test_unit_03_part_ending_e_uses_requested_round_bar_formula(): void
    {
        $kgPerPiece = $this->calculate('FBS45CXXXX161500366E', '03', 16.15, 366);

        $this->assertNotNull($kgPerPiece);
        $this->assertEqualsWithDelta(713.0996, 1200 * $kgPerPiece, 0.0001);
    }

    public function test_other_units_or_part_suffixes_do_not_convert(): void
    {
        $this->assertNull($this->calculate('FBS45CXXXX161500366E', '01', 16.15, 366));
        $this->assertNull($this->calculate('FBS45CXXXX161500366', '03', 16.15, 366));
        $this->assertNull($this->calculate('FBS45CXXXX161500366E', '03', null, 366));
    }

    public function test_index_keeps_piece_inputs_and_adds_kg_display_only(): void
    {
        $blade = file_get_contents(dirname(__DIR__, 2) . '/resources/views/formfc/division_forecast.blade.php');

        $this->assertStringContainsString('data-piece-weight-kg=', $blade);
        $this->assertStringContainsString('js-f1-kg', $blade);
        $this->assertStringContainsString('js-f6-kg', $blade);
        $this->assertStringContainsString('เส้น (ค่าที่บันทึก)', $blade);
        $this->assertStringContainsString('name="{{ !empty($isApprovalMode) ? \'approval_forecast_1m\' : \'manual_forecast_1m\' }}', $blade);
        $this->assertStringNotContainsString('name="forecast_weight_kg', $blade);
    }
}
