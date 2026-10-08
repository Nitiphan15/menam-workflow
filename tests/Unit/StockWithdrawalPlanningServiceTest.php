<?php

namespace Tests\Unit;

use App\Services\FormStock\StockWithdrawalPlanningService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class StockWithdrawalPlanningServiceTest extends TestCase
{
    public function test_subtract_working_days_counts_monday_through_saturday(): void
    {
        $service = new StockWithdrawalPlanningService();

        $result = $service->subtractWorkingDays(Carbon::parse('2026-10-12'), 2);

        $this->assertSame('2026-10-09', $result->toDateString());
    }

    public function test_overdue_days_returns_negative_days_when_late(): void
    {
        $service = new StockWithdrawalPlanningService();

        $this->assertSame(-2, $service->overdueDays('2026-10-07', Carbon::parse('2026-10-09')));
        $this->assertSame(3, $service->overdueDays('2026-10-12', Carbon::parse('2026-10-09')));
    }
}
