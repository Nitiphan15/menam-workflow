<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FormDpD8PieceQtyLimitTest extends TestCase
{
    public function test_d8_piece_qty_keeps_so_limit_when_mfg_is_selected(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Http/Controllers/FormDP/DeliveryPlanController.php');
        $blade = file_get_contents($root.'/resources/views/formdp/index.blade.php');

        $this->assertStringContainsString(
            "if (\$mfgValidation['missing'] === [] && !\$isPieceSale)",
            $controller
        );
        $this->assertStringNotContainsString(
            'sellByLineQtyInput.value = String(Math.round(mfgQty))',
            $blade
        );
        $this->assertMatchesRegularExpression(
            "/if \(isPieceSale\(\)\) \{.*?qtyKg\.value = '0';.*?\} else \{.*?setQtyLimit\(mfgQty, 'MFG'\);/s",
            $blade
        );
    }
}
