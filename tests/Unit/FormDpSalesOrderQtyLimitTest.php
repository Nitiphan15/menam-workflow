<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FormDpSalesOrderQtyLimitTest extends TestCase
{
    public function test_mfg_does_not_override_sales_order_qty_limit_for_any_division(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Http/Controllers/FormDP/DeliveryPlanController.php');
        $blade = file_get_contents($root.'/resources/views/formdp/index.blade.php');

        $this->assertStringContainsString(
            'จำนวนส่งต้องยึด ordered_qty ของ Sales Order',
            $controller
        );
        $this->assertStringNotContainsString(
            "\$maxAllowed = \$mfgValidation['qty']",
            $controller
        );
        $this->assertStringNotContainsString(
            'sellByLineQtyInput.value = String(Math.round(mfgQty))',
            $blade
        );
        $this->assertStringNotContainsString(
            "setQtyLimit(mfgQty, 'MFG')",
            $blade
        );
        $this->assertStringNotContainsString('qtyKg.value = String(mfgQty)', $blade);
    }
}
