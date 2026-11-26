<?php

namespace Tests\Unit;

use App\Exports\FormAccounting\ProductGroupProfitExport;
use App\Services\FormAccounting\ProductGroupProfitService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class ProductGroupProfitServiceTest extends TestCase
{
    public function test_summary_reconciles_sales_cost_profit_and_margin(): void
    {
        $rows = collect([
            (object) ['production_site'=>'PLUS','product_group'=>'MM BAR','quantity'=>100,'sales_thb'=>1000,'total_cost'=>700,'data_issue'=>null,'sales_site_invoice_key'=>'WIRE|INV1'],
            (object) ['production_site'=>'PLUS','product_group'=>'MM BAR','quantity'=>-10,'sales_thb'=>-100,'total_cost'=>-70,'data_issue'=>'Credit','sales_site_invoice_key'=>'WIRE|CN1'],
        ]);

        $summary = (new ProductGroupProfitService())->summarize($rows);

        $this->assertSame(900.0, $summary['sales']);
        $this->assertSame(630.0, $summary['cost']);
        $this->assertSame(270.0, $summary['gross_profit']);
        $this->assertSame(30.0, $summary['margin']);
        $this->assertSame(1, $summary['issue_count']);
        $this->assertSame('PLUS', $summary['by_site_group']->first()->production_site);
    }

    public function test_zero_sales_has_no_margin(): void
    {
        $rows = collect([(object) ['production_site'=>'WIRE','product_group'=>'SAMPLE','quantity'=>1,'sales_thb'=>0,'total_cost'=>25,'data_issue'=>null,'sales_site_invoice_key'=>'WIRE|INV1']]);
        $this->assertNull((new ProductGroupProfitService())->summarize($rows)['margin']);
    }

    public function test_issue_only_filter_accepts_boolean_query_values(): void
    {
        $service = new ProductGroupProfitService();

        $this->assertTrue($service->filters(['issues_only' => '1'])['issues_only']);
        $this->assertFalse($service->filters(['issues_only' => '0'])['issues_only']);
        $this->assertFalse($service->filters([])['issues_only']);
    }

    public function test_missing_cost_makes_aggregate_profit_unknown_instead_of_guessing_zero_cost(): void
    {
        $rows = collect([(object) ['production_site'=>'WIRE','product_group'=>'CUT WIRE','quantity'=>10,'sales_thb'=>1000,'total_cost'=>null,'data_issue'=>'ไม่พบต้นทุน','sales_site_invoice_key'=>'WIRE|INV1']]);
        $summary = (new ProductGroupProfitService())->summarize($rows);
        $this->assertNull($summary['cost']);
        $this->assertNull($summary['gross_profit']);
        $this->assertNull($summary['margin']);
        $this->assertFalse($summary['cost_complete']);
    }

    public function test_export_has_no_customer_name_column(): void
    {
        $export = new ProductGroupProfitExport(['rows'=>new Collection(), 'summary'=>['sales'=>0,'cost'=>0,'gross_profit'=>0,'margin'=>null]]);
        $headings = $export->headings();
        $this->assertContains('Customer ID', $headings);
        $this->assertNotContains('ลูกค้า', $headings);
        $this->assertNotContains('Customer Name', $headings);
    }

    public function test_query_contract_uses_master_group_direct_sales_amount_and_cross_site_mfg_key(): void
    {
        $source = file_get_contents(__DIR__.'/../../app/Services/FormAccounting/ProductGroupProfitService.php');

        $this->assertStringContainsString('LEFT JOIN partsgroup pg', $source);
        $this->assertStringContainsString('LEFT JOIN partscategory pc', $source);
        $this->assertStringContainsString('i.qty * i.sellprice sales_thb', $source);
        $this->assertStringContainsString("str_starts_with(trim(\$row->mfg), '+') ? 'PLUS' : 'WIRE'", $source);
        $this->assertStringContainsString("abs(abs(\$cost->quantity) - abs((float) \$row->quantity)) < 0.0001", $source);
        $this->assertStringNotContainsString('c.name customer_name', $source);
        $this->assertStringNotContainsString('dm.transdate = ar.transdate', $source);
    }

    public function test_excel_export_can_be_generated(): void
    {
        $rows = collect([(object) [
            'production_site'=>'PLUS','product_group'=>'MM BAR','invoice_date'=>'2026-01-29','invoice_no'=>'INV-1',
            'customer_id'=>123,'mfg'=>'+N2600006','partnumber'=>'PART-1','item_description'=>'Item','unit'=>'KG.',
            'quantity'=>10.0,'unit_price'=>100.0,'sales_thb'=>1000.0,'unit_cost'=>70.0,'total_cost'=>700.0,
            'gross_profit'=>300.0,'margin'=>30.0,'data_issue'=>null,'sales_site_invoice_key'=>'WIRE|INV-1',
        ]]);
        $summary = (new ProductGroupProfitService())->summarize($rows);
        $bytes = Excel::raw(new ProductGroupProfitExport(compact('rows', 'summary')), ExcelWriter::XLSX);

        $this->assertStringStartsWith('PK', $bytes);
    }
}
