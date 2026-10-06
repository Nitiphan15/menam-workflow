<?php

namespace Tests\Unit;

require_once __DIR__.'/../../app/Services/FormStock/StockWithdrawalService.php';

use App\Services\FormStock\StockWithdrawalService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class StockWithdrawalServiceTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return $overrides + ['site' => 'WIRE', 'mfg' => 'G2600001', 'partnumber' => 'RM-001', 'description' => 'RM', 'due_date' => '2026-10-20', 'required_qty' => 100, 'issued_qty' => 0];
    }

    private function standard(array $overrides = []): array
    {
        return $overrides + ['standard_days' => 5, 'warning_days' => 2, 'day_type' => 'CALENDAR', 'responsible_name' => 'Stock', 'responsible_email' => 'stock@example.com'];
    }

    public function test_calculates_red_risk_per_mfg_and_part_when_not_issued(): void
    {
        $result = (new StockWithdrawalService)->calculateRow($this->row(), $this->standard(), Carbon::parse('2026-10-17'));
        $this->assertSame('2026-10-15', $result['withdraw_date']);
        $this->assertSame('ยังไม่เบิก', $result['withdrawal_status']);
        $this->assertSame('RED', $result['risk_code']);
    }

    public function test_marks_partial_and_orange_on_withdraw_date(): void
    {
        $result = (new StockWithdrawalService)->calculateRow($this->row(['issued_qty' => 40]), $this->standard(), Carbon::parse('2026-10-15'));
        $this->assertSame('เบิกบางส่วน', $result['withdrawal_status']);
        $this->assertSame(60.0, $result['remaining_qty']);
        $this->assertSame('ORANGE', $result['risk_code']);
    }

    public function test_completed_issue_is_green_even_after_due_date(): void
    {
        $result = (new StockWithdrawalService)->calculateRow($this->row(['issued_qty' => 100]), $this->standard(), Carbon::parse('2026-10-25'));
        $this->assertSame('เบิกครบแล้ว', $result['withdrawal_status']);
        $this->assertSame('GREEN', $result['risk_code']);
    }

    public function test_missing_standard_is_gray(): void
    {
        $result = (new StockWithdrawalService)->calculateRow($this->row(), null, Carbon::parse('2026-10-10'));
        $this->assertSame('GRAY', $result['risk_code']);
        $this->assertNull($result['withdraw_date']);
    }

    public function test_working_days_skip_weekend(): void
    {
        $result = (new StockWithdrawalService)->calculateRow($this->row(['due_date' => '2026-10-19']), $this->standard(['standard_days' => 1, 'warning_days' => 0, 'day_type' => 'WORKING']), Carbon::parse('2026-10-16'));
        $this->assertSame('2026-10-16', $result['withdraw_date']);
    }
}
