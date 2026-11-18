<?php

namespace Tests\Unit;

use App\Repositories\FormWIP\WipRepository;
use App\Services\FormWIP\WipMonitoringService;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

/**
 * การคำนวณ WIP ต่อ WO (WipMonitoringService::buildRow) — ไม่แตะฐานข้อมูล
 *
 * Qty WO 1,000 kg → เกณฑ์ Tracking ยอมรับขาด 3% → ขั้นนับว่า "ครบ" เมื่อรับ ≥ 970 kg
 */
class WipMonitoringCalculationTest extends TestCase
{
    private WipMonitoringService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-17 12:00:00');
        $this->service = new WipMonitoringService(new WipRepository());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ สถานะพื้นฐาน

    public function test_not_started_wo_is_waiting_production_for_full_qty(): void
    {
        $row = $this->build(1000, [
            $this->step(1, 'RM', 0),
            $this->step(2, 'D2', 0),
            $this->step(3, 'PK', 0),
        ]);

        $this->assertSame('WAITING_PRODUCTION', $row['status']);
        $this->assertEquals(1000, $row['buckets']['WAITING_PRODUCTION']);
        $this->assertEquals(1000, $row['wip_total']);
        $this->assertSame('RM', $row['current_wc']);
        // ยังไม่เริ่ม → Aging นับจากวันเปิด WO (2026-11-01)
        $this->assertSame(16, $row['aging_days']);
        $this->assertSame('WARNING', $row['aging_band']);
    }

    public function test_output_flowing_through_every_step_is_not_double_counted(): void
    {
        // ของ 1,000 kg ผ่านทุกขั้นครบ แต่ ERP ยังไม่รับเข้า → ต้องเป็น 1,000 ไม่ใช่ 3,000
        $row = $this->build(1000, [
            $this->step(1, 'RM', 1000),
            $this->step(2, 'D2', 1000),
            $this->step(3, 'PK', 1000),
        ]);

        $this->assertSame('WAITING_FG', $row['status']);
        $this->assertEquals(1000, $row['buckets']['WAITING_FG']);
        $this->assertEquals(0, $row['buckets']['IN_PROCESS']);
        $this->assertEquals(0, $row['buckets']['WAITING_PACKAGING']);
        $this->assertEquals(1000, $row['wip_total']);
    }

    public function test_fully_received_into_fg_is_completed_with_no_wip(): void
    {
        $row = $this->build(1000, [
            $this->step(1, 'RM', 1000),
            $this->step(2, 'D2', 1000),
            $this->step(3, 'PK', 1000),
        ], $this->erp(1000, '2026-11-15'));

        $this->assertSame('COMPLETED', $row['status']);
        $this->assertEquals(0, $row['wip_total']);
        $this->assertEquals(1000, $row['buckets']['COMPLETED']);
    }

    public function test_partial_production_splits_weight_between_in_process_and_packaging(): void
    {
        // RM ส่งต่อ 1,000 → D2 ทำได้ 600 (ค้าง 400) → PK ยังไม่แพ็ค (รอแพ็ค 600)
        $row = $this->build(1000, [
            $this->step(1, 'RM', 1000, input: 1000),
            $this->step(2, 'D2', 600),
            $this->step(3, 'PK', 0),
        ]);

        $this->assertEquals(400, $row['buckets']['IN_PROCESS']);
        $this->assertEquals(600, $row['buckets']['WAITING_PACKAGING']);
        $this->assertEquals(0, $row['buckets']['WAITING_PRODUCTION']);
        $this->assertEquals(1000, $row['wip_total']);
        // สถานะหลัก = กองที่น้ำหนักมากสุด
        $this->assertSame('WAITING_PACKAGING', $row['status']);
        $this->assertEquals(400, $this->stepBySeq($row, 2)['balance']);
    }

    public function test_route_not_ending_with_pk_has_no_packaging_bucket(): void
    {
        $row = $this->build(1000, [
            $this->step(1, 'RM', 1000),
            $this->step(2, 'D2', 500),
        ]);

        $this->assertFalse($row['ends_with_pk']);
        $this->assertEquals(0, $row['buckets']['WAITING_PACKAGING']);
        $this->assertEquals(500, $row['buckets']['IN_PROCESS']);
        $this->assertEquals(500, $row['buckets']['WAITING_FG']);
    }

    public function test_wo_without_routing_is_waiting_production(): void
    {
        $row = $this->build(800, []);

        $this->assertSame('WAITING_PRODUCTION', $row['status']);
        $this->assertEquals(800, $row['buckets']['WAITING_PRODUCTION']);
        $this->assertSame('-', $row['current_wc']);
    }

    // ------------------------------------------------------------------ เกณฑ์เดียวกับ Production Status Tracking

    public function test_step_is_complete_when_received_meets_weight_tolerance(): void
    {
        // D2 รับ 975 ≥ 970 → ครบ ส่วนต่าง 25 kg = yield loss ไม่ใช่ WIP
        $row = $this->build(1000, [
            $this->step(1, 'RM', 1000),
            $this->step(2, 'D2', 975),
            $this->step(3, 'PK', 0),
        ]);

        $d2 = $this->stepBySeq($row, 2);
        $this->assertTrue($d2['done']);
        $this->assertSame('weight', $d2['done_by']);
        $this->assertEquals(0, $d2['balance']);
        $this->assertEquals(0, $row['buckets']['IN_PROCESS']);
        $this->assertEquals(975, $row['buckets']['WAITING_PACKAGING']);
    }

    public function test_step_below_tolerance_is_not_complete(): void
    {
        $row = $this->build(1000, [
            $this->step(1, 'RM', 1000),
            $this->step(2, 'D2', 960),   // < 970
            $this->step(3, 'PK', 0),
        ]);

        $this->assertFalse($this->stepBySeq($row, 2)['done']);
    }

    public function test_ncr_counts_toward_step_completion_like_tracking(): void
    {
        // Tracking รวมทุก receivetype: ของดี 940 + NCR 40 = 980 ≥ 970 → ครบ
        $row = $this->build(1000, [
            $this->step(1, 'RM', 1000),
            $this->step(2, 'D2', 940, ncr: 40),
            $this->step(3, 'PK', 0),
        ]);

        $this->assertTrue($this->stepBySeq($row, 2)['done']);
    }

    public function test_later_complete_step_marks_earlier_steps_complete(): void
    {
        // D2 รับแค่ 600 แต่ AN (ขั้นถัดไป) รับครบ 990 → D2 ถือว่าครบ (completePassedSteps)
        $row = $this->build(1000, [
            $this->step(1, 'RM', 1000),
            $this->step(2, 'D2', 600),
            $this->step(3, 'AN', 990),
            $this->step(4, 'PK', 0),
        ]);

        $d2 = $this->stepBySeq($row, 2);
        $this->assertTrue($d2['done']);
        $this->assertSame('passed', $d2['done_by']);
        $this->assertEquals(0, $d2['balance']);
        $this->assertEquals(0, $row['buckets']['IN_PROCESS']);
        $this->assertEquals(990, $row['buckets']['WAITING_PACKAGING']);
    }

    // ------------------------------------------------------------------ NCR / Hold / ERP

    public function test_ncr_of_unfinished_wo_goes_to_hold(): void
    {
        // D2: ของดี 900 + NCR 50 = 950 (< 970 ยังไม่ครบ) → ค้าง 50, Hold 50, รอแพ็ค 900
        $row = $this->build(1000, [
            $this->step(1, 'RM', 1000),
            $this->step(2, 'D2', 900, ncr: 50),
            $this->step(3, 'PK', 0),
        ]);

        $this->assertEquals(50, $row['buckets']['HOLD']);
        $this->assertEquals(50, $row['buckets']['IN_PROCESS']);
        $this->assertEquals(900, $row['buckets']['WAITING_PACKAGING']);
        $this->assertEquals(1000, $row['wip_total']);
        $this->assertEquals(50, $row['ncr_total']);
    }

    public function test_ncr_of_finished_wo_is_history_not_hold(): void
    {
        $row = $this->build(1000, [
            $this->step(1, 'RM', 1000),
            $this->step(2, 'D2', 990, ncr: 10),
            $this->step(3, 'PK', 990),
        ], $this->erp(990, '2026-11-16'));

        $this->assertEquals(0, $row['buckets']['HOLD']);
        $this->assertEquals(10, $row['ncr_total']);
        $this->assertSame('COMPLETED', $row['status']);
    }

    public function test_suspended_wo_moves_all_wip_to_hold(): void
    {
        $row = $this->build(1000, [
            $this->step(1, 'RM', 1000, input: 1000),
            $this->step(2, 'D2', 600),
            $this->step(3, 'PK', 0),
        ], null, ['suspended' => true, 'suspenddate' => '2026-11-10']);

        $this->assertSame('HOLD', $row['status']);
        $this->assertEquals(1000, $row['buckets']['HOLD']);
        $this->assertEquals(0, $row['buckets']['IN_PROCESS']);
        $this->assertEquals(0, $row['buckets']['WAITING_PACKAGING']);
        // Aging นับจากวันที่ suspend
        $this->assertSame(7, $row['aging_days']);
    }

    public function test_erp_receipt_meeting_tolerance_completes_wo_even_if_wmsone_short(): void
    {
        // WMSOne ขั้นสุดท้ายรับแค่ 900 แต่ ERP รับเข้าครบ 1,000 → ไม่มี WIP ค้าง (เคส W2601603)
        $row = $this->build(1000, [
            $this->step(1, 'RM', 1000),
            $this->step(2, 'D2', 950),
            $this->step(3, 'PK', 900),
        ], $this->erp(1000, '2026-11-14'));

        $this->assertEquals(0, $row['buckets']['IN_PROCESS']);
        $this->assertEquals(0, $row['buckets']['WAITING_PACKAGING']);
        $this->assertSame('COMPLETED', $row['status']);
    }

    public function test_total_never_exceeds_wo_qty_when_erp_receives_more_than_wmsone_output(): void
    {
        // Qty 950 (เกณฑ์ 4% → ครบที่ 912) PK แพ็ค 767 แต่ ERP รับเข้า 817 (เคส W2601582)
        $row = $this->build(950, [
            $this->step(1, 'RM', 950),
            $this->step(2, 'D2', 950),
            $this->step(3, 'PK', 767),
        ], $this->erp(817, '2026-11-12'));

        $sum = array_sum($row['buckets']);
        $this->assertLessThanOrEqual(950 + 1, $sum);
        $this->assertEquals(0, $row['buckets']['WAITING_FG']);
        $this->assertEquals(817, $row['buckets']['COMPLETED']);
        $this->assertEquals(133, $row['buckets']['WAITING_PACKAGING']);   // 183 − ส่วนเกิน 50
    }

    public function test_tiny_remainders_below_one_kg_are_ignored(): void
    {
        $row = $this->build(1000, [
            $this->step(1, 'RM', 1000),
            $this->step(2, 'D2', 1000),
            $this->step(3, 'PK', 1000),
        ], $this->erp(999.6, '2026-11-15'));

        $this->assertEquals(0, $row['buckets']['WAITING_FG']);   // 0.4 kg ถูกตัดทิ้ง
    }

    // ------------------------------------------------------------------ Aging

    public function test_aging_band_thresholds(): void
    {
        $cases = [0 => 'NORMAL', 7 => 'NORMAL', 8 => 'MONITOR', 15 => 'MONITOR', 16 => 'WARNING', 30 => 'WARNING', 31 => 'CRITICAL', 200 => 'CRITICAL'];
        foreach ($cases as $days => $band) {
            $this->assertSame($band, $this->service->agingBand($days, 'D2'), "{$days} วัน");
        }
    }

    public function test_in_process_aging_counts_from_last_movement_at_stuck_step(): void
    {
        $row = $this->build(1000, [
            $this->step(1, 'RM', 1000, last: '2026-11-03 08:00:00'),
            $this->step(2, 'D2', 300, last: '2026-11-07 09:00:00'),
        ]);

        $this->assertSame('IN_PROCESS', $row['status']);
        $this->assertSame('D2', $row['current_wc']);
        $this->assertSame(10, $row['aging_days']);   // 07/11 → 17/11
        $this->assertSame('activity', $row['aging_source']);
    }

    public function test_status_since_from_snapshot_overrides_estimated_aging(): void
    {
        $row = $this->build(1000, [
            $this->step(1, 'RM', 1000),
            $this->step(2, 'D2', 300),
        ]);

        $same = $this->service->applyStatusSince([$row], [101 => ['status' => 'IN_PROCESS', 'status_since' => '2026-11-15 06:00:00']])[0];
        $this->assertSame(2, $same['aging_days']);
        $this->assertSame('status', $same['aging_source']);

        // สถานะใน snapshot ไม่ตรงกับปัจจุบัน → ไม่ใช้ (ใช้ค่าประมาณเดิม)
        $diff = $this->service->applyStatusSince([$row], [101 => ['status' => 'WAITING_FG', 'status_since' => '2026-11-15 06:00:00']])[0];
        $this->assertSame('activity', $diff['aging_source']);
    }

    // ------------------------------------------------------------------ helpers

    private function build(float $qty, array $steps, ?object $erp = null, array $wo = []): array
    {
        $m = new ReflectionMethod(WipMonitoringService::class, 'buildRow');
        $m->setAccessible(true);

        return $m->invoke($this->service, 'WIRE', $this->wo($qty, $wo), collect($steps), $erp);
    }

    private function wo(float $qty, array $over = []): object
    {
        return (object) array_merge([
            'id' => 101,
            'workordernumber' => 'W2600101',
            'qty' => $qty,
            'dateopen' => '2026-11-01',
            'reqdate' => '2026-11-30',
            'dateclose' => null,
            'suspended' => false,
            'suspenddate' => null,
            'ordnumber' => 'SO-1',
            'fsize' => '1.200',
            'fcat' => 'WIRE',
            'wo_desc' => '',
            'partnumber' => 'PART-1',
            'part_desc' => 'Test part',
            'customer' => 'CUSTOMER A',
        ], $over);
    }

    private function step(int $seq, string $wc, float $good, float $ncr = 0, float $input = 0, ?string $last = '2026-11-10 10:00:00', ?string $machine = null): object
    {
        $hasReceive = $good + $ncr > 0;

        return (object) [
            'workorder_id' => 101,
            'workseq' => $seq,
            'wc_code' => $wc,
            'wc_name' => $wc . ' name',
            'msizein' => null,
            'msize' => null,
            'good_qty' => $good,
            'ncr_qty' => $ncr,
            'receive_count' => $hasReceive ? 1 : 0,
            'first_stamp' => $hasReceive ? $last : null,
            'last_stamp' => $hasReceive ? $last : null,
            'machine' => $hasReceive ? ($machine ?? $wc . '-01') : null,
            'input_qty' => $input,
            'last_usage' => null,
        ];
    }

    private function erp(float $received, ?string $lastFg): object
    {
        return (object) ['erp_received' => $received, 'first_fg_date' => $lastFg, 'last_fg_date' => $lastFg, 'receipt_count' => 1];
    }

    private function stepBySeq(array $row, int $seq): array
    {
        return collect($row['steps'])->firstWhere('seq', $seq);
    }
}
