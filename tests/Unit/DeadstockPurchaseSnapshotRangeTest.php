<?php

namespace Tests\Unit;

use App\Http\Controllers\FormWOS\DeadstockReportController;
use App\Models\FormWOS\DeadstockSnapshotMonth;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

class DeadstockPurchaseSnapshotRangeTest extends TestCase
{
    public function test_purchase_range_includes_newer_snapshots_and_is_preserved_for_export(): void
    {
        $controller = new DeadstockReportController();
        $months = collect([
            new DeadstockSnapshotMonth(['id' => 26, 'snapshot_month' => '2026-09-01']),
            new DeadstockSnapshotMonth(['id' => 25, 'snapshot_month' => '2025-02-01']),
        ]);
        foreach ([
            ['month_from' => '2023-01', 'month_to' => '2025-12'],
            ['month_from' => '2023-01'],
            ['month_to' => '2025-12'],
        ] as $range) {
            $request = Request::create('/', 'GET', $range + ['serial' => '+N2500015-7A177-001BBBBBBBBBB']);
            $ids = (new ReflectionMethod($controller, 'selectedMonthIds'))->invoke($controller, $request, $months);
            $this->assertSame([26, 25], $ids);
            [$from, $to] = (new ReflectionMethod($controller, 'selectedMonthRange'))->invoke($controller, $request, $months);
            $this->assertSame($range['month_from'] ?? '', $from);
            $this->assertSame($range['month_to'] ?? '', $to);
            $filters = (new ReflectionMethod($controller, 'deadstockExportFilters'))->invoke($controller, $request, $ids, $from, $to);
            $this->assertSame([26, 25], $filters['month_ids']);
            $this->assertSame($from, $filters['month_from']);
            $this->assertSame($to, $filters['month_to']);
        }
    }

    public function test_explicit_snapshot_selection_without_purchase_range_is_preserved(): void
    {
        $controller = new DeadstockReportController();
        $months = collect([new DeadstockSnapshotMonth(['id' => 26]), new DeadstockSnapshotMonth(['id' => 25])]);
        $request = Request::create('/', 'GET', ['month_id' => 25]);
        $this->assertSame([25], (new ReflectionMethod($controller, 'selectedMonthIds'))->invoke($controller, $request, $months));
    }
}