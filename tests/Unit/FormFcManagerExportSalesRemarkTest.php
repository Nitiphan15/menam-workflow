<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FormFcManagerExportSalesRemarkTest extends TestCase
{
    public function test_manager_excel_export_prefers_displayed_sales_remark_over_approval_input(): void
    {
        $blade = file_get_contents(dirname(__DIR__, 2).'/resources/views/formfc/division_forecast.blade.php');

        $this->assertStringContainsString('text-wrap js-sales-remark', $blade);
        $this->assertStringContainsString("const salesRemark = td.querySelector('.js-sales-remark');", $blade);
        $this->assertStringContainsString('if (salesRemark) {', $blade);
        $this->assertStringContainsString('v = salesRemark.innerText.trim();', $blade);
        $this->assertStringContainsString('} else if (input) {', $blade);

        $remarkLookup = strpos($blade, "const salesRemark = td.querySelector('.js-sales-remark');");
        $inputBranch = strpos($blade, '} else if (input) {', $remarkLookup);

        $this->assertNotFalse($remarkLookup);
        $this->assertNotFalse($inputBranch);
        $this->assertLessThan($inputBranch, $remarkLookup);
    }
}
