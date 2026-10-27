<?php

namespace Tests\Unit;

use App\Exports\FormAccounting\InvoiceMfgProfitExport;
use App\Services\FormAccounting\InvoiceMfgProfitService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class InvoiceMfgProfitServiceTest extends TestCase
{
    public function test_summary_uses_allocated_mfg_rows_without_assigning_all_sales_to_one_mfg(): void
    {
        $rows = collect([
            (object) ['site_invoice_key'=>'WIRE|D2026010506','site_mfg_key'=>'WIRE|G2500479','invoice_date'=>'2026-01-27','sales_thb'=>878.50,'total_cost'=>692.510871771072,'data_issue'=>''],
            (object) ['site_invoice_key'=>'WIRE|D2026010506','site_mfg_key'=>'WIRE|G2500578','invoice_date'=>'2026-01-27','sales_thb'=>5710.25,'total_cost'=>4074.342336729101,'data_issue'=>''],
        ]);

        $summary = (new InvoiceMfgProfitService())->summarize($rows);

        $this->assertSame(1, $summary['invoice_count']);
        $this->assertSame(2, $summary['mfg_count']);
        $this->assertEqualsWithDelta(6588.75, $summary['sales'], 0.001);
        $this->assertEqualsWithDelta(4766.8532085, $summary['cost'], 0.001);
        $this->assertEqualsWithDelta(27.65163, $summary['margin'], 0.001);
    }

    public function test_export_has_summary_buddhist_year_tabs_and_data_issues_last(): void
    {
        $summary = ['invoice_count'=>0,'line_count'=>0,'mfg_count'=>0,'sales'=>0,'cost'=>0,'gross_profit'=>0,'margin'=>null,'issue_count'=>0,'years'=>collect()];
        $export = new InvoiceMfgProfitExport([
            'summary'=>$summary,
            'filters'=>['date_from'=>'2025-01-01','date_to'=>'2026-12-31','site'=>'ALL'],
            'byYear'=>collect([2025=>collect(),2026=>collect()]),
            'issues'=>collect(),
        ]);

        $this->assertSame(['Summary','2568','2569','Data Issues'], array_map(fn($sheet) => $sheet->title(), $export->sheets()));
    }

    public function test_filters_normalize_reversed_dates_and_site(): void
    {
        $filters = (new InvoiceMfgProfitService())->filters(['date_from'=>'2026-12-31','date_to'=>'2025-01-01','site'=>'plus']);
        $this->assertSame('2025-01-01', $filters['date_from']);
        $this->assertSame('2026-12-31', $filters['date_to']);
        $this->assertSame('PLUS', $filters['site']);
    }
}
