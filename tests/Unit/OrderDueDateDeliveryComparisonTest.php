<?php

namespace Tests\Unit;

use App\Http\Controllers\FormWOS\OrderDueDateController;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once dirname(__DIR__, 2) . '/app/Http/Controllers/FormWOS/OrderDueDateController.php';

class OrderDueDateDeliveryComparisonTest extends TestCase
{
    public function test_comparison_separates_same_and_other_due_month_actuals(): void
    {
        $orders = [
            $this->row(['orderitems_id' => 1, 'group_code' => 'D1', 'group_name' => 'D1 Sales', 'product_type' => 'MM BAR (CG)', 'metric_unit' => 'Qty', 'metric_value' => 800]),
        ];
        $actuals = [
            $this->row(['orderitems_id' => 1, 'group_code' => 'D1', 'group_name' => 'D1 Sales', 'product_type' => 'MM BAR (CG)', 'metric_unit' => 'Qty', 'metric_value' => 600, 'original_due_date' => '2026-08-20']),
            $this->row(['orderitems_id' => 2, 'group_code' => 'D1', 'group_name' => 'D1 Sales', 'product_type' => 'MM BAR (CG)', 'metric_unit' => 'Qty', 'metric_value' => 100, 'original_due_date' => '2026-09-10']),
            $this->row(['orderitems_id' => 3, 'group_code' => 'D2', 'group_name' => 'D2 Sales', 'product_type' => 'MM WIRE', 'metric_unit' => 'Qty', 'metric_value' => 50, 'original_due_date' => '2026-07-15']),
        ];

        $result = $this->invoke('summarizeDeliveryComparison', [$orders, $actuals, 2026, 8]);
        $d1 = collect($result['rows'])->firstWhere('group_code', 'D1');

        $this->assertSame(800.0, $d1['order_due']);
        $this->assertSame(600.0, $d1['actual_same_due_month']);
        $this->assertSame(100.0, $d1['actual_other_due_month']);
        $this->assertSame(700.0, $d1['actual_total']);
        $this->assertSame(-200.0, $d1['due_performance_variance']);
        $this->assertSame(-100.0, $d1['monthly_variance']);
        $this->assertSame(750.0, $result['totals']['qty']['actual_total']);
        $this->assertSame(600.0, $orders[0]->actual_delivery_metric);
        $this->assertSame(-200.0, $orders[0]->variance_metric);
        $this->assertCount(3, $result['due_origins']);
    }

    public function test_d8_baht_is_not_added_to_quantity_totals(): void
    {
        $orders = [
            $this->row(['orderitems_id' => 1, 'group_code' => 'D1', 'group_name' => 'D1', 'product_type' => 'WIRE', 'metric_unit' => 'Qty', 'metric_value' => 500]),
            $this->row(['orderitems_id' => 8, 'group_code' => 'D8', 'group_name' => 'D8', 'product_type' => 'FG GRATING', 'metric_unit' => 'Baht', 'metric_value' => 100000]),
        ];
        $actuals = [
            $this->row(['orderitems_id' => 1, 'group_code' => 'D1', 'group_name' => 'D1', 'product_type' => 'WIRE', 'metric_unit' => 'Qty', 'metric_value' => 450, 'original_due_date' => '2026-08-01']),
            $this->row(['orderitems_id' => 8, 'group_code' => 'D8', 'group_name' => 'D8', 'product_type' => 'FG GRATING', 'metric_unit' => 'Baht', 'metric_value' => 90000, 'original_due_date' => '2026-08-01']),
        ];

        $result = $this->invoke('summarizeDeliveryComparison', [$orders, $actuals, 2026, 8]);

        $this->assertSame(500.0, $result['totals']['qty']['order_due']);
        $this->assertSame(450.0, $result['totals']['qty']['actual_total']);
        $this->assertSame(100000.0, $result['totals']['baht']['order_due']);
        $this->assertSame(90000.0, $result['totals']['baht']['actual_total']);
    }

    public function test_excel_contract_has_three_requested_sheets_and_detail_columns(): void
    {
        $comparison = [
            'rows' => [],
            'totals' => [
                'qty' => $this->zeroTotals(),
                'baht' => $this->zeroTotals(),
            ],
            'due_origins' => [],
            'order_details' => [],
            'actual_details' => [],
        ];
        $spreadsheet = new Spreadsheet();

        $this->invoke('writeComparisonSheet', [$spreadsheet->getActiveSheet(), $comparison, 2026, 8, ['D1', 'D2']]);
        $this->invoke('writeOrderDetailsSheet', [$spreadsheet->createSheet(), []]);
        $this->invoke('writeActualDetailsSheet', [$spreadsheet->createSheet(), []]);

        $this->assertSame(
            ['Comparison Summary', 'Order Details', 'Actual Delivery Details'],
            $spreadsheet->getSheetNames()
        );
        $this->assertSame('Original Due Date', $spreadsheet->getSheet(2)->getCell('J1')->getValue());
        $this->assertSame('Actual Delivery Date', $spreadsheet->getSheet(2)->getCell('L1')->getValue());
        $this->assertSame('Metric Unit', $spreadsheet->getSheet(2)->getCell('U1')->getValue());
    }

    public function test_division_filter_accepts_multiple_or_all(): void
    {
        $this->assertSame(['D1', 'D9'], $this->invoke('normalizedDivisionCodes', [['D9', 'D1', 'INVALID']]));
        $this->assertCount(8, $this->invoke('normalizedDivisionCodes', [['ALL']]));
        $this->assertCount(8, $this->invoke('normalizedDivisionCodes', [[]]));
    }

    public function test_delivery_analysis_is_rendered_after_both_existing_order_tables(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/resources/views/formwos/order_due_date/index.blade.php');
        $partial = file_get_contents(dirname(__DIR__, 2) . '/resources/views/formwos/order_due_date/_delivery_comparison.blade.php');

        $this->assertLessThan(strpos($view, "@include('formwos.order_due_date._delivery_comparison')"), strpos($view, 'Order Volume Report (by Due Date)'));
        $this->assertLessThan(strpos($view, "@include('formwos.order_due_date._delivery_comparison')"), strpos($view, 'Orders Due for Delivery by Month'));
        $this->assertStringContainsString('id="dueComparisonSearch"', $partial);
        $this->assertStringContainsString('<caption class="visually-hidden">', $partial);
    }

    private function invoke(string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod(OrderDueDateController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(new OrderDueDateController(), $arguments);
    }

    private function row(array $values): object
    {
        return (object) $values;
    }

    private function zeroTotals(): array
    {
        return [
            'order_due' => 0.0,
            'actual_same_due_month' => 0.0,
            'due_performance_variance' => 0.0,
            'actual_other_due_month' => 0.0,
            'actual_total' => 0.0,
            'monthly_variance' => 0.0,
        ];
    }
}
