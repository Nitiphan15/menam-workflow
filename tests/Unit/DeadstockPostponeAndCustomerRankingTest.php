<?php

namespace Tests\Unit;

use App\Exports\FormWOS\DeadstockReviewExport;
use App\Models\FormWOS\DeadstockItemReview;
use App\Models\FormWOS\DeadstockItemReviewLog;
use App\Models\FormWOS\DeadstockSnapshotItem;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class DeadstockPostponeAndCustomerRankingTest extends TestCase
{
    public function test_postpone_count_mixes_snapshot_erp_log_and_review_history(): void
    {
        $item = $this->item(['id' => 77, 'due_date' => '2026-08-01', 'current_due_date' => '2026-08-20']);
        $review = new DeadstockItemReview(['revised_due_date' => '2026-10-15']);
        $review->setRelation('logs', new Collection([
            new DeadstockItemReviewLog([
                'id' => 1,
                'changed_fields' => ['revised_due_date'],
                'after_values' => ['revised_due_date' => '2026-10-15'],
                'changed_at' => '2026-09-05 10:00:00',
            ]),
        ]));
        $item->setRelation('review', $review);

        $export = new DeadstockReviewExport();
        $this->setDueHistory($export, [
            // Snapshot ก.ค. เห็นวันส่ง 15/07 → Snapshot ส.ค. เห็น 01/08 (ERP เลื่อน 1)
            'snapshot' => ['MENAM WIRE|SN-1' => [
                ['at' => '2026-07-01 00:00:00', 'due' => '2026-07-15'],
                ['at' => '2026-08-01 00:00:00', 'due' => '2026-08-01'],
            ]],
            // compare รายชั่วโมงเห็น ERP เปลี่ยนเป็น 20/08 (ERP เลื่อน 2)
            'erp' => [77 => [['at' => '2026-08-10 09:00:00', 'due' => '2026-08-20']]],
        ]);

        $row = $this->invokePrivate($export, 'rescheduledItemRow', [$item]);

        $this->assertSame('2026-07-15', $row['original_due_date']);
        $this->assertSame('2026-10-15', $row['latest_due_date']);
        $this->assertSame(3, $row['postpone_count']);
        $this->assertSame(2, $row['postpone_count_erp']);
        $this->assertSame(1, $row['postpone_count_review']);
    }

    public function test_moving_due_date_earlier_is_not_counted_as_postpone(): void
    {
        $item = $this->item(['id' => 5, 'due_date' => '2026-09-01', 'current_due_date' => '2026-09-30']);
        $item->setRelation('review', null);

        $export = new DeadstockReviewExport();
        $this->setDueHistory($export, [
            'snapshot' => [],
            'erp' => [5 => [
                ['at' => '2026-09-02 08:00:00', 'due' => '2026-08-25'],
                ['at' => '2026-09-03 08:00:00', 'due' => '2026-09-30'],
            ]],
        ]);

        $row = $this->invokePrivate($export, 'rescheduledItemRow', [$item]);

        $this->assertSame(1, $row['postpone_count']);
        $this->assertSame(1, $row['postpone_count_erp']);
    }

    public function test_customer_ranking_sorts_by_open_qty_and_tracks_owner_and_postponed(): void
    {
        $export = new DeadstockReviewExport();
        $totals = [];
        $add = function (array $attrs, float $qty, ?string $owner, ?array $resched = null) use ($export, &$totals) {
            $this->invokePrivateByRef($export, 'addCustomerRankingItem', $totals, $this->item($attrs), $qty, $owner, $resched);
        };

        $add(['customer_id' => 10, 'customer_name' => 'A'], 100.0, 'sales');
        $add(['customer_id' => 10, 'customer_name' => 'A'], 50.0, 'factory', ['postpone_count' => 3]);
        $add(['customer_id' => 20, 'customer_name' => 'B'], 400.0, null);

        $ranking = $this->invokePrivate($export, 'finalizeCustomerRanking', [$totals]);

        $this->assertSame('B', $ranking['rows'][0]['customer']);
        $this->assertSame(400.0, $ranking['rows'][0]['uncoded_qty']);
        $this->assertSame('A', $ranking['rows'][1]['customer']);
        $this->assertSame(2, $ranking['rows'][1]['item_count']);
        $this->assertSame(100.0, $ranking['rows'][1]['sales_qty']);
        $this->assertSame(50.0, $ranking['rows'][1]['factory_qty']);
        $this->assertSame(1, $ranking['rows'][1]['rescheduled_count']);
        $this->assertSame(3, $ranking['rows'][1]['postpone_count']);
        $this->assertEqualsWithDelta(0.2727, $ranking['rows'][1]['share'], 0.0001);
        $this->assertSame(550.0, $ranking['total_qty']);
    }

    public function test_top_postponed_groups_serials_of_the_same_order_line(): void
    {
        $base = [
            'division' => 'D2', 'salesperson' => 'S', 'partnumber' => 'P-1', 'part_description' => 'Bar',
            'customer' => 'INNO', 'original_due_date' => '2026-10-01', 'latest_due_date' => '2026-12-01',
            'postponed_days' => 61, 'postpone_count' => 1, 'postpone_count_erp' => 0, 'postpone_count_review' => 1,
            'site' => 'WIRE', 'reason_code' => 'SS01',
        ];
        $rows = [
            [...$base, 'serialnumber' => 'S-003', 'qty' => 500.0],
            [...$base, 'serialnumber' => 'S-001', 'qty' => 510.0, 'postpone_count' => 2, 'postpone_count_review' => 2],
            [...$base, 'serialnumber' => 'S-002', 'qty' => 520.0, 'reason_code' => 'SS02'],
            // ลูกค้าเดียวกัน part เดียวกัน แต่กำหนดส่งต่าง = คนละงาน
            [...$base, 'serialnumber' => 'S-009', 'qty' => 700.0, 'latest_due_date' => '2027-01-15'],
        ];

        $groups = collect($this->invokePrivate(new DeadstockReviewExport(), 'groupRescheduledByOrderLine', [$rows]))
            ->keyBy('latest_due_date');

        $this->assertCount(2, $groups);
        $line = $groups['2026-12-01'];
        $this->assertSame(3, $line['serial_count']);
        $this->assertSame(1530.0, $line['qty']);
        $this->assertSame('S-001 … S-003', $line['serial_sample']);
        $this->assertSame(2, $line['postpone_count']);
        $this->assertSame('SS01, SS02', $line['reason_code']);
        $this->assertSame(1, $groups['2027-01-15']['serial_count']);
        $this->assertSame('S-009', $groups['2027-01-15']['serial_sample']);
    }

    public function test_customer_division_shows_only_real_divisions(): void
    {
        $export = new DeadstockReviewExport();
        $totals = [];
        $this->invokePrivateByRef($export, 'addCustomerRankingItem', $totals,
            $this->item(['customer_id' => 1, 'customer_name' => 'A', 'salesperson_name' => 'ไม่ระบุ Sales']), 10.0, 'sales', null);
        $this->invokePrivateByRef($export, 'addCustomerRankingItem', $totals,
            $this->item(['customer_id' => 2, 'customer_name' => 'B', 'salesperson_name' => 'Sales Person 05']), 5.0, 'sales', null);

        $ranking = collect($this->invokePrivate($export, 'finalizeCustomerRanking', [$totals])['rows'])->keyBy('customer');

        $this->assertSame('-', $ranking['A']['divisions']);
        $this->assertSame('D5', $ranking['B']['divisions']);
    }

    public function test_top_sheets_are_built_with_top_and_full_lists(): void
    {
        $spreadsheet = new Spreadsheet();
        $export = new DeadstockReviewExport();

        $rescheduledRows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rescheduledRows[] = [
                'division' => 'D1', 'salesperson' => 'S', 'partnumber' => '00123', 'part_description' => 'P',
                'serialnumber' => '000' . $i, 'customer' => 'C' . $i, 'qty' => (float) $i,
                'original_due_date' => '2026-07-01', 'latest_due_date' => '2026-08-01', 'postponed_days' => 31,
                'postpone_count' => 26 - $i, 'postpone_count_erp' => 0, 'postpone_count_review' => 26 - $i,
                'site' => 'WIRE', 'reason_code' => 'SS01',
            ];
        }
        $postponedSheet = $spreadsheet->createSheet();
        $this->invokePrivate($export, 'buildTopPostponedSheet', [$postponedSheet, [
            'range_label' => 'ทั้งหมด', 'division_summary' => [], 'rows' => $rescheduledRows,
        ]]);

        $this->assertSame('Top Postponed', $postponedSheet->getTitle());
        $this->assertSame('Qty รวม (KGS)', $postponedSheet->getCell('I5')->getValue());
        // ตารางแรก: เลื่อนบ่อยสุด → ลูกค้า C1 (25 ครั้ง), serial เดียว
        $this->assertSame('C1', $postponedSheet->getCell('F6')->getValue());
        $this->assertSame('0001', $postponedSheet->getCell('H6')->getValue());
        $this->assertSame(25, $postponedSheet->getCell('M6')->getValue());
        // ตารางที่ 2: title 29, header 30: แถวแรกเป็น qty มากสุด (25)
        $this->assertSame(25.0, $postponedSheet->getCell('I31')->getValue());

        $customerRows = [];
        for ($i = 1; $i <= 22; $i++) {
            $customerRows[] = [
                'customer_id' => (string) $i, 'customer' => 'C' . $i, 'item_count' => 1, 'qty' => 100.0 - $i,
                'share' => 0.01, 'sales_qty' => 0.0, 'factory_qty' => 0.0, 'uncoded_qty' => 0.0,
                'rescheduled_count' => 0, 'rescheduled_qty' => 0.0, 'postpone_count' => 0, 'divisions' => 'D1',
                'oldest_purchase_date' => '2026-01-01',
            ];
        }
        $customerSheet = $spreadsheet->createSheet();
        $this->invokePrivate($export, 'buildTopCustomersSheet', [$customerSheet, [
            'rows' => $customerRows, 'total_qty' => 1000.0, 'total_items' => 22,
        ]]);

        $this->assertSame('Top Customers', $customerSheet->getTitle());
        $this->assertSame('C1', $customerSheet->getCell('C6')->getValue());
        $this->assertSame('C20', $customerSheet->getCell('C25')->getValue());
        // ส่วน "ทั้งหมด" ต้องมีครบ 22 ราย: title 28, header 29, ข้อมูล 30-51
        $this->assertSame('C22', $customerSheet->getCell('C51')->getValue());
        $this->assertSame('A29:N51', $customerSheet->getAutoFilter()->getRange());
    }

    private function item(array $attrs): DeadstockSnapshotItem
    {
        $item = new DeadstockSnapshotItem(array_merge([
            'company' => 'MENAM WIRE',
            'serialnumber' => 'SN-1',
            'snapshot_qty' => 10,
            'partnumber' => 'P1',
            'part_description' => 'Part',
            'customer_name' => 'Customer',
            'salesperson_name' => 'Sales Person 05',
            'deadstock_code' => 'SS01',
            'unitcost' => 1,
        ], $attrs));
        if (isset($attrs['id'])) {
            $item->id = $attrs['id'];
        }
        $item->setRelation('latestCompareLog', null);

        return $item;
    }

    private function setDueHistory(DeadstockReviewExport $export, array $history): void
    {
        (new ReflectionProperty($export, 'dueHistory'))->setValue($export, $history);
    }

    private function invokePrivate(object $target, string $method, array $arguments = []): mixed
    {
        return (new ReflectionMethod($target, $method))->invokeArgs($target, $arguments);
    }

    private function invokePrivateByRef(object $target, string $method, array &$totals, ...$rest): mixed
    {
        return (new ReflectionMethod($target, $method))->invokeArgs($target, [&$totals, ...$rest]);
    }
}
