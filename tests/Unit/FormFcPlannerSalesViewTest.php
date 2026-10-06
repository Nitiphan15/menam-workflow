<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FormFcPlannerSalesViewTest extends TestCase
{
    public function test_planner_can_switch_to_read_only_sales_view_without_losing_filters(): void
    {
        $blade = file_get_contents(dirname(__DIR__, 2) . '/resources/views/formfc/division_forecast_planning.blade.php');

        $this->assertStringContainsString('Sales Forecast - Read Only', $blade);
        $this->assertStringContainsString("['view' => 'sales', 'page' => null]", $blade);
        $this->assertStringContainsString('name="divisions[]"', $blade);
        $this->assertStringContainsString('name="month"', $blade);
        $this->assertStringContainsString('name="q"', $blade);
        $this->assertStringContainsString('<th>Sales Order</th>', $blade);
        $this->assertStringContainsString('<th>Forecast?</th>', $blade);
        $this->assertStringNotContainsString('name="submit_action"', $blade);
        $this->assertStringNotContainsString('<form method="post"', strtolower($blade));
    }

    public function test_history_uses_sales_batches_and_displays_actor_and_previous_values(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/FormFC/ForecastRmDivisionController.php');
        $blade = file_get_contents(dirname(__DIR__, 2) . '/resources/views/formfc/division_forecast_planning.blade.php');

        $this->assertStringContainsString("table('fc_rm_division_forecast_item_history as h')", $controller);
        $this->assertStringContainsString("join('fc_rm_division_forecast_batch as b'", $controller);
        $this->assertStringContainsString("leftJoin('users as actor'", $controller);
        $this->assertStringContainsString("'batch_id' => (int) \$batchId", $controller);
        $this->assertStringContainsString('Forecast History', $blade);
        $this->assertStringContainsString('ผู้ดำเนินการ', $blade);
        $this->assertStringContainsString('forecast_horizon', $blade);
    }
}
