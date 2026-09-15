<?php

namespace Tests\Unit;

use Tests\TestCase;

class FormFcPlanningExportTest extends TestCase
{
    public function test_planning_view_has_excel_export_with_requested_columns(): void
    {
        $root = dirname(__DIR__, 2);
        $blade = file_get_contents($root . '/resources/views/formfc/division/forecast_planning.blade.php');
        $controller = file_get_contents($root . '/app/Http/Controllers/FormFC/ForecastRmDivisionController.php');

        $this->assertStringContainsString('btnExportPlanningExcel', $blade);
        $this->assertStringContainsString("'export_excel' => 1", $blade);
        foreach ([
            'Sales Div.',
            'Customer Name',
            'FG Part',
            'FG Description',
            'RM Part',
            'Sales Forecast 1 Month',
            'Product Type',
        ] as $heading) {
            $this->assertStringContainsString("'{$heading}'", $controller);
        }
        $this->assertStringContainsString('$forecast1m * self::FORECAST_HORIZON_MONTHS', $controller);
        $this->assertStringContainsString("['product_type']", $controller);
    }

}
