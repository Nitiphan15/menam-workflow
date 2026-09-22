<?php

namespace Tests\Unit;

use Tests\TestCase;

class FormFcDivisionForecastExportTest extends TestCase
{
    public function test_export_contains_the_requested_division_forecast_detail_columns(): void
    {
        $root = dirname(__DIR__, 2);
        $blade = file_get_contents($root . '/resources/views/formfc/division/forecast.blade.php');

        $this->assertStringContainsString("'Sales Div.'", $blade);
        $this->assertStringContainsString("'Customer Name'", $blade);
        $this->assertStringContainsString("'FG Part'", $blade);
        $this->assertStringContainsString("'FG Description'", $blade);
        $this->assertStringContainsString("'RM Part'", $blade);
        $this->assertStringContainsString("'Sales Forecast 1 Month'", $blade);
        $this->assertStringContainsString('`Sales Forecast ${FORECAST_HORIZON_MONTHS} Months`', $blade);
        $this->assertStringContainsString("'Product Type'", $blade);
        $this->assertStringContainsString('tr.dataset.productType', $blade);
    }

    public function test_product_type_comes_from_the_erp_part_type_master(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root . '/app/Http/Controllers/FormFC/ForecastRmDivisionController.php');

        $this->assertStringContainsString("->leftJoin('partstype', 'parts.partstype_id', '=', 'partstype.id')", $controller);
        $this->assertStringContainsString("COALESCE(partstype.description, '') as product_type", $controller);
        $this->assertStringContainsString("\$r['product_type']", $controller);
    }
}
