<?php

namespace Tests\Unit;

use App\Models\FormStock\StockWithdrawalPlan;
use App\Models\FormStock\StockWithdrawalPlanItem;
use App\Services\FormStock\StockWithdrawalPlanningService;
use Carbon\Carbon;
use Tests\TestCase;

class StockWithdrawalPlanningServiceTest extends TestCase
{
    public function test_withdrawal_is_complete_at_ten_percent_shortage(): void
    {
        $service = new StockWithdrawalPlanningService();

        $this->assertFalse($service->isWithdrawalComplete(100, 89.9999));
        $this->assertTrue($service->isWithdrawalComplete(100, 90));
        $this->assertTrue($service->isWithdrawalComplete(100, 110));
        $this->assertFalse($service->isWithdrawalComplete(0, 0));
    }

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

    public function test_completion_date_counts_request_date_as_day_one_and_skips_sunday(): void
    {
        $service = new StockWithdrawalPlanningService();
        $this->assertSame('2026-10-15', $service->completionDate(Carbon::parse('2026-10-14'), 2)->toDateString());
        $this->assertSame('2026-10-19', $service->completionDate(Carbon::parse('2026-10-17'), 2)->toDateString());
    }

    public function test_next_working_day_moves_saturday_to_monday(): void
    {
        $service = new StockWithdrawalPlanningService();

        $this->assertSame('2026-10-17', $service->nextWorkingDay(Carbon::parse('2026-10-16'))->toDateString());
        $this->assertSame('2026-10-19', $service->nextWorkingDay(Carbon::parse('2026-10-17'))->toDateString());
    }

    public function test_alert_risk_counts_are_saved_by_due_date_band(): void
    {
        $service = new StockWithdrawalPlanningService();
        $items = collect([
            (object) ['planned_withdraw_date' => '2026-10-14'],
            (object) ['planned_withdraw_date' => '2026-10-15'],
            (object) ['planned_withdraw_date' => '2026-10-16'],
            (object) ['planned_withdraw_date' => '2026-10-17'],
        ]);

        $this->assertSame([
            'red_count' => 1,
            'orange_count' => 1,
            'yellow_count' => 1,
            'gray_count' => 1,
        ], $service->alertRiskCounts($items, Carbon::parse('2026-10-15')));
    }

    public function test_snapshot_values_are_attached_without_calling_erp(): void
    {
        $service = new StockWithdrawalPlanningService();
        $item = new StockWithdrawalPlanItem([
            'erp_withdrawn_qty' => 40, 'erp_remaining_qty' => 60, 'erp_status_code' => 'PARTIAL',
            'erp_first_work_center_number' => 'WC10', 'erp_first_work_center_name' => 'Drawing',
        ]);
        $item->setRelation('plan', new StockWithdrawalPlan());

        $service->attachSnapshotBalances(collect([$item]));

        $this->assertSame(40.0, $item->withdrawn_qty);
        $this->assertSame(60.0, $item->remaining_qty);
        $this->assertSame('PARTIAL', $item->withdrawal_status_code);
        $this->assertSame('WC10 — Drawing', $item->first_work_center);
    }

    public function test_planner_close_overrides_erp_snapshot_status(): void
    {
        $service = new StockWithdrawalPlanningService();
        $item = new StockWithdrawalPlanItem([
            'erp_status_code' => 'PARTIAL', 'erp_withdrawn_qty' => 10, 'erp_remaining_qty' => 90,
            'planner_closed_at' => '2026-10-19 10:00:00',
        ]);
        $item->setRelation('plan', new StockWithdrawalPlan());

        $service->attachSnapshotBalances(collect([$item]));

        $this->assertSame('PLANNER_CLOSED', $item->withdrawal_status_code);
        $this->assertSame('ปิดโดย Planner', $item->withdrawal_status);
    }

    public function test_snapshot_partial_status_becomes_complete_at_ninety_percent(): void
    {
        $service = new StockWithdrawalPlanningService();
        $item = new StockWithdrawalPlanItem([
            'quantity' => 100, 'withdraw_quantity' => 100,
            'erp_status_code' => 'PARTIAL', 'erp_withdrawn_qty' => 90, 'erp_remaining_qty' => 10,
        ]);
        $item->setRelation('plan', new StockWithdrawalPlan());

        $service->attachSnapshotBalances(collect([$item]));

        $this->assertSame('COMPLETED', $item->withdrawal_status_code);
        $this->assertSame('เบิกครบแล้ว', $item->withdrawal_status);
        $this->assertSame(10.0, $item->remaining_qty);
    }

    public function test_only_nce_prefix_is_classified_as_ncr(): void
    {
        $service = new StockWithdrawalPlanningService();

        $this->assertSame('NCR', $service->withdrawalClassification('NCE12345')['code']);
        $this->assertSame(2, $service->withdrawalClassification('NCE12345')['section']);
        $this->assertSame('FG', $service->withdrawalClassification('NAB12345')['code']);
    }

    public function test_current_acid_work_center_overrides_part_prefix(): void
    {
        $service = new StockWithdrawalPlanningService();

        $result = $service->withdrawalClassification('F12345', 'WC20', 'แผนกกัดกรด');

        $this->assertSame('ACID', $result['code']);
        $this->assertSame('กัดกรด', $result['name']);
        $this->assertSame(1, $result['section']);
        $this->assertSame('RM', $result['standard_code']);
    }
}
