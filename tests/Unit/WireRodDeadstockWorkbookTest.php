<?php

namespace Tests\Unit;

use App\Exports\FormWR\WireRodDeadstockItemSummarySheet;
use App\Exports\FormWR\WireRodDeadstockSerialDetailSheet;
use App\Exports\FormWR\WireRodDeadstockWorkbook;
use PHPUnit\Framework\TestCase;

class WireRodDeadstockWorkbookTest extends TestCase
{
    public function test_workbook_has_item_and_serial_sheets(): void
    {
        $sheets = (new WireRodDeadstockWorkbook(['stock_status' => 'DEAD']))->sheets();

        $this->assertCount(2, $sheets);
        $this->assertInstanceOf(WireRodDeadstockItemSummarySheet::class, $sheets[0]);
        $this->assertSame('Item Summary', $sheets[0]->title());
        $this->assertInstanceOf(WireRodDeadstockSerialDetailSheet::class, $sheets[1]);
        $this->assertSame('Serial Detail', $sheets[1]->title());
    }

    public function test_item_summary_does_not_export_pcs_quantity(): void
    {
        $headings = (new WireRodDeadstockItemSummarySheet([]))->headings();

        $this->assertContains('Qty KG', $headings);
        $this->assertNotContains('Qty PCS', $headings);
    }
}
