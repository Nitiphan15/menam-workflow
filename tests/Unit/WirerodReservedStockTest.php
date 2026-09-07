<?php

namespace Tests\Unit;

use App\Exports\WirerodReservationSheet;
use App\Services\FormWR\ReservedStockService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WirerodReservedStockTest extends TestCase
{
    private function order(array $overrides = []): array
    {
        return array_replace([
            'company' => 'MENAM WIRE', 'item' => 'R1', 'description' => 'Raw', 'heat' => 'H1',
            'allocation_count' => 1, 'planned' => 5000, 'issued' => 2000, 'mfg' => 'W1',
            'order_date' => '2026-09-01', 'due_date' => '2026-09-10', 'sales_order' => 'SO1',
            'customer' => 'Customer', 'product' => 'FG1', 'product_description' => 'Finished',
        ], $overrides);
    }

    public function test_partial_issue_is_deducted_once_and_negative_net_is_preserved(): void
    {
        $report = (new ReservedStockService)->assemble(
            [['company' => 'MENAM WIRE', 'item' => 'R1', 'balance' => 2500]],
            [['company' => 'MENAM WIRE', 'item' => 'R1', 'heat' => 'H1', 'balance' => 2500, 'received_date' => '2026-08-01']],
            [$this->order()]
        );
        $item = $report['items'][0];
        $this->assertSame(3000.0, $item['reserved']);
        $this->assertSame(-500.0, $item['net']);
        $this->assertSame(2000.0, $item['heats'][0]['orders'][0]['issued']);
        $this->assertSame(-500.0, $item['heats'][0]['net']);
    }

    public function test_fully_issued_and_overissued_orders_do_not_reserve_again(): void
    {
        $report = (new ReservedStockService)->assemble([], [], [
            $this->order(['issued' => 5000]), $this->order(['issued' => 5100]),
        ]);
        $this->assertSame([], $report['items']);
    }

    public function test_unassigned_waiting_order_is_visible_without_stock(): void
    {
        $report = (new ReservedStockService)->assemble([], [], [$this->order(['heat' => '-', 'issued' => 0])]);
        $item = $report['items'][0];
        $this->assertSame(-5000.0, $item['net']);
        $this->assertSame('waiting', $item['heats'][0]['kind']);
        $this->assertSame('รอวัตถุดิบ', $item['heats'][0]['orders'][0]['status']);
    }

    public function test_company_and_heat_balances_stay_separate_and_reconcile(): void
    {
        $service = new ReservedStockService;
        $report = $service->assemble([
            ['company' => 'MENAM WIRE', 'item' => 'R1', 'balance' => 4000],
            ['company' => 'MENAM PLUS', 'item' => 'R1', 'balance' => 6000],
        ], [
            ['company' => 'MENAM WIRE', 'item' => 'R1', 'heat' => 'H1', 'balance' => 3500],
            ['company' => 'MENAM PLUS', 'item' => 'R1', 'heat' => 'H1', 'balance' => 6000],
        ], [$this->order()]);
        foreach ($report['items'] as $item) {
            $this->assertEquals($item['balance'], array_sum(array_column($item['heats'], 'balance')));
            $this->assertEquals($item['net'], array_sum(array_column($item['heats'], 'net')));
            if ($item['company'] === 'MENAM PLUS') $this->assertSame(0.0, $item['reserved']);
        }
        $merged = $service->mergeBalances([], $report)[0];
        $this->assertSame(10000.0, $merged['balance']);
        $this->assertSame(3000.0, $merged['reserved']);
        $this->assertSame(7000.0, $merged['net']);
        $this->assertCount(2, $merged['stock_details']);
    }

    public function test_multiple_material_allocations_cannot_duplicate_mfg_quantity(): void
    {
        $this->expectException(RuntimeException::class);
        (new ReservedStockService)->assemble([], [], [$this->order(['allocation_count' => 2])]);
    }

    public function test_multiple_receipt_dates_do_not_duplicate_reservations(): void
    {
        $report = (new ReservedStockService)->assemble(
            [['company' => 'MENAM WIRE', 'item' => 'R1', 'balance' => 4000]],
            [
                ['company' => 'MENAM WIRE', 'item' => 'R1', 'heat' => 'H1', 'balance' => 1000, 'received_date' => '2026-08-01'],
                ['company' => 'MENAM WIRE', 'item' => 'R1', 'heat' => 'H1', 'balance' => 3000, 'received_date' => '2026-08-02'],
            ], [$this->order()]
        );
        $heat = $report['items'][0]['heats'][0];
        $this->assertCount(1, $report['items'][0]['heats']);
        $this->assertCount(2, $heat['received_dates']);
        $this->assertSame(3000.0, $heat['reserved']);
        $this->assertSame(1000.0, $heat['net']);
    }

    public function test_export_uses_report_quantities_and_treats_mfg_as_text(): void
    {
        $report = (new ReservedStockService)->assemble([], [], [$this->order(['mfg' => '+W1'])]);
        $heats = new WirerodReservationSheet($report);
        $orders = new WirerodReservationSheet($report, true);
        $this->assertSame(3000.0, $heats->array()[0][6]);
        $this->assertSame(-3000.0, $heats->array()[0][7]);
        $this->assertSame(5000.0, $orders->array()[0][12]);
        $this->assertSame(2000.0, $orders->array()[0][13]);
        $this->assertSame(3000.0, $orders->array()[0][14]);
        $book = new Spreadsheet;
        $cell = $book->getActiveSheet()->getCell('A1');
        $orders->bindValue($cell, '+W1');
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $this->assertSame('+W1', $cell->getValue());
    }
}
