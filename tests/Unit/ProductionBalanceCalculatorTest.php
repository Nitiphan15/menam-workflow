<?php

namespace Tests\Unit;

use App\Services\FormPP\ProductionBalanceCalculator;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class ProductionBalanceCalculatorTest extends TestCase
{
    public function test_allocates_fg_by_due_date_then_order_date_without_duplication(): void
    {
        $rows = $this->calculate([
            $this->order('SO-LATE', 100, '2026-12-20', '2026-11-01'),
            $this->order('SO-FIRST', 80, '2026-12-10', '2026-11-05'),
            $this->order('SO-SECOND', 70, '2026-12-10', '2026-11-06'),
        ], [], [['item' => 'FG-1', 'site' => 'WIRE', 'qty' => 120]]);

        $this->assertSame(80.0, $rows->firstWhere('so', 'SO-FIRST')->fg);
        $this->assertSame(40.0, $rows->firstWhere('so', 'SO-SECOND')->fg);
        $this->assertSame(0.0, $rows->firstWhere('so', 'SO-LATE')->fg);
        $this->assertSame(120.0, (float) $rows->sum('fg'));
    }

    public function test_maps_waiting_production_to_mfg_and_started_buckets_to_wip(): void
    {
        $rows = $this->calculate([$this->order('SO-1', 1000)], [[
            'so' => 'SO-1', 'item' => 'FG-1', 'site' => 'WIRE', 'mfg' => 'W1',
            'mfg_qty' => 300, 'wip_qty' => 500,
        ]]);

        $row = $rows->first();
        $this->assertSame(300.0, $row->mfg);
        $this->assertSame(500.0, $row->wip);
        $this->assertSame('SHORT', $row->status);
    }

    public function test_uses_tracking_percentage_tolerance_on_both_sides(): void
    {
        $completeShort = $this->calculate([$this->order('SO-1', 1000)], [[
            'so' => 'SO-1', 'item' => 'FG-1', 'site' => 'WIRE', 'mfg' => 'W1', 'mfg_qty' => 970, 'wip_qty' => 0,
        ]])->first();
        $completeExcess = $this->calculate([$this->order('SO-1', 1000)], [[
            'so' => 'SO-1', 'item' => 'FG-1', 'site' => 'WIRE', 'mfg' => 'W1', 'mfg_qty' => 1030, 'wip_qty' => 0,
        ]])->first();
        $excess = $this->calculate([$this->order('SO-1', 1000)], [[
            'so' => 'SO-1', 'item' => 'FG-1', 'site' => 'WIRE', 'mfg' => 'W1', 'mfg_qty' => 1030.01, 'wip_qty' => 0,
        ]])->first();

        $this->assertSame('COMPLETE', $completeShort->status);
        $this->assertSame('COMPLETE', $completeExcess->status);
        $this->assertSame('EXCESS', $excess->status);
    }

    public function test_unconvertible_order_and_mfg_without_so_are_data_issues(): void
    {
        $order = $this->order('SO-PIECE', 0);
        $order['data_issues'] = ['ไม่สามารถแปลงหน่วย PCS เป็น KG'];
        $rows = $this->calculate([$order], [[
            'so' => '', 'item' => 'FG-1', 'site' => 'PLUS', 'mfg' => 'P1', 'customer' => 'C1',
            'mfg_qty' => 100, 'wip_qty' => 25,
        ]]);

        $this->assertCount(2, $rows);
        $this->assertSame(['DATA_ISSUE'], $rows->pluck('status')->unique()->values()->all());
        $this->assertSame(0.0, (float) $rows->sum('excess_qty'));
    }

    public function test_unallocated_fg_becomes_excess_per_item_and_site(): void
    {
        $rows = $this->calculate(
            [$this->order('SO-1', 100)],
            [],
            [['item' => 'FG-1', 'site' => 'WIRE', 'qty' => 130], ['item' => 'FG-1', 'site' => 'PLUS', 'qty' => 20]],
        );

        $this->assertSame(100.0, $rows->firstWhere('so', 'SO-1')->fg);
        $excess = $rows->where('status', 'EXCESS')->keyBy('site');
        $this->assertSame(30.0, $excess['WIRE']->fg);
        $this->assertSame(20.0, $excess['PLUS']->fg);
        $this->assertSame('FG ไม่มี SO รองรับ', $excess['WIRE']->customer);
    }

    private function calculate(array $orders, array $production = [], array $fg = []): Collection
    {
        return (new ProductionBalanceCalculator())->calculate(
            collect($orders)->map(fn ($row) => (object) $row),
            collect($production)->map(fn ($row) => (object) $row),
            collect($fg)->map(fn ($row) => (object) $row),
        );
    }

    private function order(string $so, float $qty, string $due = '2026-12-01', string $order = '2026-11-01'): array
    {
        return [
            'so' => $so, 'item' => 'FG-1', 'customer' => 'CUSTOMER', 'description' => 'Product',
            'backorder_kg' => $qty, 'due_date' => $due, 'order_date' => $order, 'data_issues' => [],
        ];
    }
}
