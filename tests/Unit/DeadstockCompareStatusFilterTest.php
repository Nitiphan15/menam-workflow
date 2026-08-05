<?php

namespace Tests\Unit;

use App\Http\Controllers\FormWOS\DeadstockReportController;
use App\Support\FormWOS\DeadstockCompareStatus;
use ReflectionMethod;
use Tests\TestCase;

class DeadstockCompareStatusFilterTest extends TestCase
{
    public function test_all_and_three_compare_status_filters_are_publicly_selectable(): void
    {
        $controller = new DeadstockReportController();
        $method = new ReflectionMethod($controller, 'deadstockListStatus');

        $this->assertSame('active', DeadstockCompareStatus::DEFAULT);
        $this->assertSame('review', $method->invoke($controller, 'review'));
        $this->assertSame('active', $method->invoke($controller, 'active'));
        $this->assertSame('cleared', $method->invoke($controller, 'cleared'));
        $this->assertSame('all', $method->invoke($controller, 'all'));
        $this->assertSame('active', $method->invoke($controller, 'pending'));
        $this->assertSame('active', $method->invoke($controller, 'changed'));

        $this->assertSame([
            'all' => 'ทั้งหมด',
            'review' => 'ต้องติดตาม',
            'active' => 'คงค้าง',
            'cleared' => 'เคลียร์แล้ว',
        ], DeadstockCompareStatus::filterOptions());
    }

    public function test_old_database_statuses_are_grouped_without_changing_stored_values(): void
    {
        $this->assertSame(['pending', 'changed'], DeadstockCompareStatus::valuesForFilter('review'));
        $this->assertSame(['active'], DeadstockCompareStatus::valuesForFilter('active'));
        $this->assertSame(['cleared'], DeadstockCompareStatus::valuesForFilter('cleared'));
        $this->assertSame([], DeadstockCompareStatus::valuesForFilter('all'));
    }
}
