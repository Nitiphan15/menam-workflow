<?php

namespace Tests\Unit;

use App\Http\Controllers\FormFC\ForecastRmDivisionController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class FormFcManualForecastValidationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (! class_exists(ForecastRmDivisionController::class, false)) {
            require_once dirname(__DIR__, 2).'/app/Http/Controllers/FormFC/ForecastRmDivisionController.php';
        }
    }

    public function test_positive_manual_rows_require_customer_and_fg_identity(): void
    {
        $controller = new ForecastRmDivisionController();
        $method = (new ReflectionClass($controller))->getMethod('manualForecastInputErrors');
        $method->setAccessible(true);

        $errors = $method->invoke($controller, [
            'missing-customer' => '125',
            'missing-fg' => '250',
            'ignored-zero' => '0',
        ], [
            'missing-customer' => json_encode([
                'customer_id' => 0,
                'customer_name' => 'Customer A',
                'fg_partnumber' => 'FG-A',
            ]),
            'missing-fg' => json_encode([
                'customer_id' => 123,
                'customer_name' => 'Customer B',
                'fg_partnumber' => '',
            ]),
        ]);

        $this->assertCount(2, $errors);
        $this->assertStringContainsString('Customer A / FG-A', $errors[0]);
        $this->assertStringContainsString('Customer B', $errors[1]);
    }

    public function test_sales_forecast_view_alerts_and_marks_incomplete_manual_rows(): void
    {
        $blade = file_get_contents(dirname(__DIR__, 2).'/resources/views/formfc/division_forecast.blade.php');

        $this->assertStringContainsString("[manualFormEl, document.getElementById('gen-form')]", $blade);
        $this->assertStringContainsString("tr.classList.add('table-danger')", $blade);
        $this->assertStringContainsString('identity.key !==', $blade);
        $this->assertStringContainsString("collect(\$errors->all())", $blade);
        $this->assertStringContainsString('window.alert(@json(', $blade);
    }
}
