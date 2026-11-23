<?php

namespace Tests\Unit;

use App\Repositories\FormWIP\WipRepository;
use App\Services\FormWIP\WipMonitoringService;
use App\Services\FormWIP\WipSnapshotService;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

/**
 * - แผนการเขียน snapshot (WipSnapshotService::buildSyncPlan) — ส่ง existing/rows เข้าไปเอง ไม่แตะฐานข้อมูล
 * - เงื่อนไข filter (WipMonitoringService::passes)
 */
class WipSnapshotAndFilterTest extends TestCase
{
    private WipMonitoringService $monitoring;
    private WipSnapshotService $snapshot;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-17 12:00:00');
        $this->monitoring = new WipMonitoringService(new WipRepository());
        $this->snapshot = new WipSnapshotService($this->monitoring);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ snapshot

    public function test_first_sync_uses_activity_date_as_status_since_and_logs_first_seen(): void
    {
        $plan = $this->plan([$this->row(1, 'IN_PROCESS', 'D2', activity: '2026-11-10 08:00:00')], collect());

        $this->assertSame(1, $plan['summary']['new_count']);
        $this->assertSame('2026-11-10 08:00:00', $plan['current'][0]['status_since']);
        $this->assertNull($plan['history'][0]['from_status']);
        $this->assertSame('IN_PROCESS', $plan['history'][0]['to_status']);
        $this->assertSame(1, $plan['current'][0]['is_open']);
        // daily snapshot 1 แถว / WO / วัน
        $this->assertSame('2026-11-17', $plan['daily'][0]['snapshot_date']);
    }

    public function test_unchanged_status_keeps_original_status_since_and_writes_no_history(): void
    {
        $existing = $this->existing([[1, 'IN_PROCESS', 'D2', '2026-11-01 09:00:00']]);
        $plan = $this->plan([$this->row(1, 'IN_PROCESS', 'D2')], $existing);

        $this->assertSame('2026-11-01 09:00:00', $plan['current'][0]['status_since']);
        $this->assertSame([], $plan['history']);
        $this->assertSame(0, $plan['summary']['status_changes']);
        // Aging ใน daily นับจาก status_since เดิม = 16 วัน
        $this->assertSame(16, $plan['daily'][0]['aging_days']);
    }

    public function test_status_change_resets_status_since_and_records_days_in_previous_status(): void
    {
        $existing = $this->existing([[1, 'IN_PROCESS', 'D2', '2026-11-07 12:00:00']]);
        $plan = $this->plan([$this->row(1, 'WAITING_PACKAGING', 'PK')], $existing);

        $this->assertSame('2026-11-17 12:00:00', $plan['current'][0]['status_since']);
        $this->assertSame(1, $plan['summary']['status_changes']);
        $h = $plan['history'][0];
        $this->assertSame('IN_PROCESS', $h['from_status']);
        $this->assertSame('WAITING_PACKAGING', $h['to_status']);
        $this->assertSame('D2', $h['from_wc']);
        $this->assertSame('PK', $h['to_wc']);
        $this->assertSame(10, $h['days_in_prev']);
    }

    public function test_work_center_change_within_same_status_is_logged_but_keeps_status_since(): void
    {
        $existing = $this->existing([[1, 'IN_PROCESS', 'D2', '2026-11-05 12:00:00']]);
        $plan = $this->plan([$this->row(1, 'IN_PROCESS', 'AN')], $existing);

        $this->assertSame('2026-11-05 12:00:00', $plan['current'][0]['status_since']);
        $this->assertSame('2026-11-17 12:00:00', $plan['current'][0]['wc_since']);
        $this->assertCount(1, $plan['history']);
        $this->assertNull($plan['history'][0]['days_in_prev']);
        $this->assertSame(0, $plan['summary']['status_changes']);
    }

    public function test_wo_missing_from_open_list_is_closed_not_deleted(): void
    {
        $existing = $this->existing([
            [1, 'IN_PROCESS', 'D2', '2026-11-05 12:00:00'],
            [2, 'WAITING_FG', 'PK', '2026-11-09 12:00:00'],
        ]);
        $plan = $this->plan([$this->row(1, 'IN_PROCESS', 'D2')], $existing);

        $this->assertSame([2], $plan['closed_ids']);
        $closed = collect($plan['history'])->firstWhere('workorder_id', 2);
        $this->assertSame('WAITING_FG', $closed['from_status']);
        $this->assertSame('CLOSED', $closed['to_status']);
        $this->assertSame(8, $closed['days_in_prev']);
    }

    public function test_reopened_wo_is_logged_from_closed(): void
    {
        $existing = $this->existing([[1, 'IN_PROCESS', 'D2', '2026-11-05 12:00:00', false]]);
        $plan = $this->plan([$this->row(1, 'IN_PROCESS', 'D2')], $existing);

        $this->assertSame('CLOSED', $plan['history'][0]['from_status']);
        $this->assertSame('2026-11-17 12:00:00', $plan['current'][0]['status_since']);
        $this->assertSame([], $plan['closed_ids']);
    }

    public function test_upsert_rows_stay_under_sql_server_parameter_limit(): void
    {
        $plan = $this->plan([$this->row(1, 'IN_PROCESS', 'D2')], collect());

        // CURRENT_CHUNK = 50, DAILY_CHUNK = 80 → ต้องไม่เกิน 2,100 parameters ต่อ statement
        $this->assertLessThanOrEqual(2100, count($plan['current'][0]) * 50);
        $this->assertLessThanOrEqual(2100, count($plan['daily'][0]) * 80);
        $this->assertLessThanOrEqual(2100, count($plan['history'][0]) * 100);
    }

    // ------------------------------------------------------------------ filter

    public function test_department_filter_matches_current_or_stuck_step(): void
    {
        $row = $this->filterRow();

        $this->assertTrue($this->passes($row, ['dept' => 'DRAWING']));     // ขั้นปัจจุบัน
        $this->assertTrue($this->passes($row, ['dept' => 'ANNEALING']));   // มีของค้าง
        $this->assertFalse($this->passes($row, ['dept' => 'PACKING']));    // ไม่มีของค้าง
    }

    public function test_work_center_and_machine_filters(): void
    {
        $row = $this->filterRow();

        $this->assertTrue($this->passes($row, ['wc' => 'AN']));
        $this->assertFalse($this->passes($row, ['wc' => 'PK']));
        $this->assertTrue($this->passes($row, ['machine' => 'D2-01']));
        $this->assertFalse($this->passes($row, ['machine' => 'XX-99']));
    }

    public function test_product_customer_grade_size_filters(): void
    {
        $row = $this->filterRow();

        $this->assertTrue($this->passes($row, ['product' => 'ser309']));        // ไม่สนตัวพิมพ์ + บางส่วน
        $this->assertTrue($this->passes($row, ['product' => 'welding']));       // ค้นใน description
        $this->assertFalse($this->passes($row, ['product' => 'S50C']));
        $this->assertTrue($this->passes($row, ['customer' => 'CUSTOMER A']));
        $this->assertFalse($this->passes($row, ['customer' => 'CUSTOMER']));    // customer ต้องตรงทั้งคำ
        $this->assertTrue($this->passes($row, ['grade' => 'MIG', 'size' => '1.200']));
        $this->assertFalse($this->passes($row, ['size' => '1.20']));
    }

    public function test_open_date_range_and_due_filters(): void
    {
        $row = $this->filterRow();   // เปิด 2026-11-01, due 2026-11-20

        $this->assertTrue($this->passes($row, ['date_from' => '2026-11-01', 'date_to' => '2026-11-01']));
        $this->assertFalse($this->passes($row, ['date_from' => '2026-11-02']));
        $this->assertTrue($this->passes($row, ['due' => '7']));
        $this->assertFalse($this->passes($row, ['due' => 'overdue']));
        $this->assertTrue($this->passes(array_merge($row, ['reqdate' => '2026-11-10']), ['due' => 'overdue']));
        $this->assertTrue($this->passes(array_merge($row, ['reqdate' => null]), ['due' => 'none']));
    }

    public function test_quick_search_requires_every_term(): void
    {
        $row = $this->filterRow();

        $this->assertTrue($this->passes($row, ['q' => 'w2600101 mig']));
        $this->assertFalse($this->passes($row, ['q' => 'w2600101 tig']));
    }

    // ------------------------------------------------------------------ helpers

    private function plan(array $rows, $existing): array
    {
        return $this->snapshot->buildSyncPlan('WIRE', now()->startOfSecond(), 99, $existing, $rows);
    }

    /** @param array<int, array{0:int,1:string,2:?string,3:string,4?:bool}> $items */
    private function existing(array $items)
    {
        return collect($items)->mapWithKeys(fn($i) => [$i[0] => (object) [
            'workorder_id' => $i[0],
            'wo_no' => 'W' . $i[0],
            'status' => $i[1],
            'status_since' => $i[3],
            'current_wc' => $i[2],
            'wc_since' => $i[3],
            'is_open' => $i[4] ?? true,
            'wip_kg' => 100,
        ]]);
    }

    private function row(int $id, string $status, string $wc, string $activity = '2026-11-12 08:00:00'): array
    {
        return [
            'site' => 'WIRE',
            'workorder_id' => $id,
            'wo_no' => 'W' . $id,
            'qty' => 1000,
            'dateopen' => '2026-11-01',
            'status' => $status,
            'current_wc' => $wc,
            'buckets' => ['WAITING_PRODUCTION' => 0, 'IN_PROCESS' => 100, 'WAITING_PACKAGING' => 0, 'WAITING_FG' => 0, 'HOLD' => 0, 'COMPLETED' => 0],
            'wip_total' => 100,
            'last_tx' => $activity,
            'ends_with_pk' => true,
            'aging_ref' => $activity,
            'activity_ref' => $activity,
            'aging_source' => 'activity',
            'aging_days' => 0,
            'aging_band' => 'NORMAL',
        ];
    }

    private function filterRow(): array
    {
        return [
            'site' => 'WIRE',
            'workorder_id' => 1,
            'wo_no' => 'W2600101',
            'part_number' => 'FSER309LXX01200',
            'part_desc' => 'Welding wire 309L',
            'customer' => 'CUSTOMER A',
            'size' => '1.200',
            'grade' => 'MIG',
            'ord_number' => 'SO-1',
            'dateopen' => '2026-11-01',
            'reqdate' => '2026-11-20',
            'current_wc' => 'D2',
            'current_dept' => 'DRAWING',
            'current_machine' => 'D2-01',
            'aging_band' => 'NORMAL',
            'wip_total' => 500,
            'steps' => [
                ['wc_code' => 'RM', 'dept' => 'RAWMAT', 'machine' => 'RM-01', 'balance' => 0.0],
                ['wc_code' => 'D2', 'dept' => 'DRAWING', 'machine' => 'D2-01', 'balance' => 200.0],
                ['wc_code' => 'AN', 'dept' => 'ANNEALING', 'machine' => null, 'balance' => 300.0],
                ['wc_code' => 'PK', 'dept' => 'PACKING', 'machine' => null, 'balance' => 0.0],
            ],
        ];
    }

    private function passes(array $row, array $filters): bool
    {
        $defaults = [
            'wc' => '', 'dept' => '', 'machine' => '', 'product' => '', 'customer' => '', 'grade' => '', 'size' => '',
            'date_from' => '', 'date_to' => '', 'due' => '', 'aging' => '', 'only_wip' => true, 'q' => '', 'status' => '',
        ];
        $m = new ReflectionMethod(WipMonitoringService::class, 'passes');
        $m->setAccessible(true);

        return $m->invoke($this->monitoring, $row, array_merge($defaults, $filters));
    }
}
