<?php

namespace Tests\Unit;

use App\Http\Controllers\FormFC\ForecastRmDivisionController;
use ReflectionClass;
use Tests\TestCase;

class FormFcSalesForecastHorizonTest extends TestCase
{
    public function test_division_sales_forecast_uses_four_month_trial_horizon(): void
    {
        $controller = new ReflectionClass(ForecastRmDivisionController::class);

        $this->assertSame(4, $controller->getConstant('FORECAST_HORIZON_MONTHS'));

        $source = file_get_contents(app_path('Http/Controllers/FormFC/ForecastRmDivisionController.php'));
        $this->assertStringContainsString('database columns are still named forecast_6m', strtolower($source));
        $this->assertStringContainsString('$forecast1m * self::FORECAST_HORIZON_MONTHS', $source);
        $this->assertStringContainsString('$qty * self::FORECAST_HORIZON_MONTHS', $source);
    }

    public function test_manual_forecast_table_has_excel_style_column_filters(): void
    {
        $blade = file_get_contents(resource_path('views/formfc/division_forecast.blade.php'));

        $this->assertStringContainsString('id="manualForecastTable"', $blade);
        $this->assertSame(5, substr_count($blade, 'class="form-control form-control-sm manual-col-filter"'));
        $this->assertStringContainsString('function applyManualFilters()', $blade);
        $this->assertStringContainsString('function sortManualRows(', $blade);
        $this->assertStringContainsString('id="columnFilterGroupMenu"', $blade);
        $this->assertStringContainsString('function groupedColumnValues(', $blade);
        $this->assertStringContainsString('count.textContent = `(${group.count})`;', $blade);
        $this->assertStringContainsString("if (tr.style.display === 'none') return;", $blade);
    }
}
