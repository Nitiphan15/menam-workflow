<?php

namespace Tests\Unit;

use App\Support\FormWOS\DeadstockSalesMap;
use PHPUnit\Framework\TestCase;

class DeadstockSalesMapTest extends TestCase
{
    public function test_sales_codes_are_mapped_to_names(): void
    {
        $this->assertSame('คุณดิลก สอนแจ้ง', DeadstockSalesMap::label('Export Sales 01'));
        $this->assertSame('คุณวรเดชา วัธนกุล', DeadstockSalesMap::label('Sales Person09'));
        $this->assertSame('Assadaporn Maneechote', DeadstockSalesMap::label('assadaporn_m'));
        $this->assertSame('ไม่ระบุ Sales', DeadstockSalesMap::label(null));
    }

    public function test_sales_options_contain_names_only(): void
    {
        $options = DeadstockSalesMap::salesOptions([
            'Export Sales 01',
            'Sales Person09',
            'คุณดิลก สอนแจ้ง',
            null,
        ]);

        $this->assertSame(['คุณดิลก สอนแจ้ง', 'คุณวรเดชา วัธนกุล'], $options->all());
    }

    public function test_access_key_is_stable_across_erp_alias_and_display_name(): void
    {
        $this->assertSame('D1', DeadstockSalesMap::accessKey('Export Sales 01'));
        $this->assertSame('D1', DeadstockSalesMap::accessKey('คุณดิลก สอนแจ้ง'));
        $this->assertSame('PROCUREMENT', DeadstockSalesMap::accessKey('Procurement'));
    }

    public function test_division_filter_expands_to_raw_and_mapped_sales_values(): void
    {
        $values = DeadstockSalesMap::rawValuesForDivisions(['D2']);

        $this->assertContains('คุณปรียาพรรณ ทิพหา', $values);
        $this->assertContains('Export Sales 02', $values);
        $this->assertContains('Export Sales02', $values);
    }

    public function test_additional_sales_division_options_expand_to_erp_values(): void
    {
        $options = DeadstockSalesMap::divisionOptions();
        $values = DeadstockSalesMap::rawValuesForDivisions([
            'ASSADAPORN',
            'THANIN',
            'PROCUREMENT',
        ]);

        $this->assertSame('Assadaporn', $options['ASSADAPORN']);
        $this->assertSame('Thanin', $options['THANIN']);
        $this->assertSame('Procurement', $options['PROCUREMENT']);
        $this->assertContains('assadaporn_m', $values);
        $this->assertContains('thanin_p', $values);
        $this->assertContains('Procurement', $values);
    }
}
