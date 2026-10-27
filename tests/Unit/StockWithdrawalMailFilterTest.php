<?php

namespace Tests\Unit;

use App\Http\Controllers\FormStock\StockWithdrawalPlanController;
use ReflectionMethod;
use Tests\TestCase;

class StockWithdrawalMailFilterTest extends TestCase
{
    public function test_mail_uses_the_exact_filtered_queue_date_range(): void
    {
        $method = new ReflectionMethod(StockWithdrawalPlanController::class, 'mailQueueDateRange');
        $method->setAccessible(true);

        $this->assertSame(
            ['2026-10-21', '2026-10-23'],
            $method->invoke(new StockWithdrawalPlanController(), [
                'date_from' => '2026-10-21',
                'date_to' => '2026-10-23',
            ])
        );
    }
}
