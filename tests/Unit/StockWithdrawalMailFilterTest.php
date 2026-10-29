<?php

namespace Tests\Unit;

use App\Http\Controllers\FormStock\StockWithdrawalPlanController;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class StockWithdrawalMailFilterTest extends TestCase
{
    public function test_daily_mail_due_date_cutoff_is_today_in_bangkok(): void
    {
        Carbon::setTestNow('2026-10-27 23:30:00 Asia/Bangkok');
        $method = new ReflectionMethod(StockWithdrawalPlanController::class, 'mailDueDateCutoff');
        $method->setAccessible(true);

        $this->assertSame('2026-10-27', $method->invoke(new StockWithdrawalPlanController()));

        Carbon::setTestNow();
    }

    public function test_mail_recipients_accept_valid_comma_separated_addresses(): void
    {
        config()->set('stock-withdrawal.fallback_email', ' first@example.com, invalid,second@example.com ');
        $method = new ReflectionMethod(StockWithdrawalPlanController::class, 'mailRecipients');
        $method->setAccessible(true);

        $this->assertSame(
            ['first@example.com', 'second@example.com'],
            $method->invoke(new StockWithdrawalPlanController())
        );
    }
}
