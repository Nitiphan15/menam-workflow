<?php

namespace Tests\Unit;

use Tests\TestCase;

class FormDpCustomerCompanyContractTest extends TestCase
{
    public function test_customer_autocomplete_and_form_preserve_company(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/FormDP/DeliveryPlanController.php'));
        $view = file_get_contents(resource_path('views/formdp/index.blade.php'));

        $this->assertStringContainsString("'company_label'", $controller);
        $this->assertStringContainsString("'lines.*.company'", $controller);
        $this->assertStringContainsString("'company'            => \$company", $controller);
        $this->assertStringContainsString("where('company', \$company)", $controller);
        $this->assertStringContainsString('id="customerCompany"', $view);
        $this->assertStringContainsString('data-company="${esc(it.company', $view);
        $this->assertStringContainsString("it.company_label === 'PLUS'", $view);
        $this->assertStringContainsString("it.ordnumber || ''", $view);
        $this->assertStringContainsString("UPPER(so.company COLLATE DATABASE_DEFAULT) LIKE '%PLUS%'", $controller);
        $this->assertStringNotContainsString('COALESCE(c.company, so.company) AS company', $controller);
    }

    public function test_delivery_plan_customer_joins_are_scoped_by_company(): void
    {
        $files = [
            app_path('Http/Controllers/FormDP/DeliveryPlanController.php'),
            app_path('Http/Controllers/FormDP/DeliveryPlanInquiryController.php'),
            app_path('Exports/FormOTD/InquiryByShipDateExport.php'),
            app_path('Exports/FormOTD/InquiryShipDateSheet.php'),
            app_path('Services/FormDP/ProductionStatusTrackingService.php'),
            app_path('Services/FormDP/GpsTripQueueService.php'),
        ];

        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertStringNotContainsString("leftJoin('customer as c', 'c.id', '=', 'd.customer_id')", $source, $file);
            $this->assertStringNotContainsString("leftJoin('customer as c', 'c.id', '=', 'dp.customer_id')", $source, $file);
        }

        $inquiry = file_get_contents($files[1]);
        $this->assertStringContainsString("c.company = COALESCE(NULLIF(d.company, ''), 'MSW')", $inquiry);
        $this->assertStringContainsString("c.company = COALESCE(NULLIF(dp.company, ''), 'MSW')", $inquiry);

        foreach (array_slice($files, 2, 2) as $exportFile) {
            $source = file_get_contents($exportFile);
            $this->assertStringContainsString("c.company = COALESCE(NULLIF(d.company, ''), 'MSW')", $source, $exportFile);
        }

        $sheetExport = file_get_contents(app_path('Exports/FormOTD/InquiryShipDateSheet.php'));
        $this->assertStringContainsString('d.ord_id,', $sheetExport);
        $this->assertStringContainsString("->unique(fn (\$row) => (string) \$row->ord_id)", $sheetExport);

        $this->assertStringContainsString("->unique(fn (\$row) => (string) \$row->ord_id)", $inquiry);
        $this->assertStringContainsString("(string) (\$row->truck_assign_id ?? 'none')", $inquiry);
        $this->assertStringContainsString("->unique(fn (\$row) => (string) \$row->id)->values()", $inquiry);
        $this->assertStringContainsString("->unique(fn (\$row) => (string) \$row->truck_assign_id)->values()", $inquiry);

        $tracking = file_get_contents(app_path('Services/FormDP/ProductionStatusTrackingService.php'));
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($tracking, "->unique(fn (\$row) => (string) \$row->ord_id)"),
            'Tracking list and detail lookups must deduplicate delivery-plan rows.'
        );
    }
}
