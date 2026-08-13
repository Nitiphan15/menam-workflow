<?php

namespace Tests\Unit;

use App\Support\FormFcOrderBalance;
use PHPUnit\Framework\TestCase;

class FormFcOrderBalanceTest extends TestCase
{
    public function test_balance_is_supply_minus_safety_forecast_and_sales_order(): void
    {
        $result = FormFcOrderBalance::calculate(
            onhand: 100,
            fg: 20,
            totalPo: 30,
            wip: 10,
            safetyForecast: 40,
            forecast: 90,
            salesOrder: 50
        );

        $this->assertSame(160.0, $result['supply']);
        $this->assertSame(140.0, $result['forecast_and_sales_order']);
        $this->assertSame(180.0, $result['demand']);
        $this->assertSame(-20.0, $result['balance']);
        $this->assertSame(20.0, $result['shortage']);
    }

    public function test_positive_balance_has_no_order_shortage(): void
    {
        $result = FormFcOrderBalance::calculate(100, 20, 30, 10, 5, 60, 25);

        $this->assertSame(70.0, $result['balance']);
        $this->assertSame(0.0, $result['shortage']);
    }
}
