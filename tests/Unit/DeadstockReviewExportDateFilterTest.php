<?php

namespace Tests\Unit;

use App\Exports\FormWOS\DeadstockReviewExport;
use App\Models\FormWOS\DeadstockItemReview;
use App\Models\FormWOS\DeadstockSnapshotItem;
use App\Models\Users\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class DeadstockReviewExportDateFilterTest extends TestCase
{
    public function test_detail_export_uses_the_requested_columns_in_order(): void
    {
        $export = new DeadstockReviewExport();

        $this->assertSame([
            'วันที่รับเข้า',
            'Part',
            'รายละเอียด Part',
            'Serial no.',
            'Qty ปัจจุบัน',
            'กำหนดส่งเดิม',
            'กำหนดส่งปัจจุบัน',
            'กำหนดส่งใหม่',
            'ลูกค้า',
            'Sales',
            'Site',
            'รหัสสาเหตุ',
            'รายละเอียดสาเหตุ',
            'สถานะการติดตาม',
            'รายละเอียด',
            'แนวทางแก้ไข',
            'แนวทางป้องกันการเกิดซ้ำ',
            'Remark',
            'บันทึกล่าสุด',
            'ผู้บันทึก',
        ], $export->headings());
    }

    public function test_detail_export_row_matches_the_twenty_column_contract(): void
    {
        $review = new DeadstockItemReview([
            'review_status' => 'waiting_follow_up',
            'revised_due_date' => '2026-08-31',
            'review_detail' => 'รายละเอียดติดตาม',
            'corrective_action' => 'แก้ไข',
            'preventive_action' => 'ป้องกัน',
            'sales_remark' => 'หมายเหตุ',
            'reviewed_at' => '2026-07-29 10:00:00',
        ]);
        $review->setRelation('reviewer', (new User())->forceFill(['name' => 'ผู้ทดสอบ']));

        $item = new DeadstockSnapshotItem([
            'purchase_date' => '2026-07-01',
            'partnumber' => 'PART-01',
            'part_description' => 'Part description',
            'serialnumber' => 'SERIAL-01',
            'current_qty' => 12.5,
            'due_date' => '2026-07-15',
            'current_due_date' => '2026-07-20',
            'customer_name' => 'Customer A',
            'salesperson_name' => 'Sales A',
            'company' => 'MENAM PLUS',
            'deadstock_code' => 'FF01',
            'deadstock_desc' => 'Reason',
        ]);
        $item->setRelation('review', $review);
        $item->setRelation('latestCompareLog', null);

        $export = new DeadstockReviewExport();
        (new ReflectionProperty($export, 'items'))->setValue($export, new Collection([$item]));

        $row = $export->collection()->first();

        $this->assertCount(20, $row);
        $this->assertSame('2026-07-01', $row[0]);
        $this->assertSame(12.5, $row[4]);
        $this->assertSame('2026-07-15', $row[5]);
        $this->assertSame('2026-08-31', $row[6]);
        $this->assertSame('', $row[7]);
        $this->assertSame('กำลังติดตาม รอคำตอบจากลูกค้า', $row[13]);
        $this->assertSame('ผู้ทดสอบ', $row[19]);
    }

    public function test_month_filter_becomes_stock_received_date_range(): void
    {
        $export = new DeadstockReviewExport([
            'month_from' => '2026-01',
            'month_to' => '2026-06',
        ]);

        [$from, $to] = $this->invokePrivate($export, 'purchaseRange');

        $this->assertSame('2026-01-01', $from?->toDateString());
        $this->assertSame('2026-06-30', $to?->toDateString());
    }

    public function test_summary_report_date_uses_selected_end_month(): void
    {
        $export = new DeadstockReviewExport([
            'month_from' => '2026-01',
            'month_to' => '2026-06',
        ]);

        /** @var Carbon|null $reportDate */
        $reportDate = $this->invokePrivate($export, 'reportDateFromFilters');

        $this->assertSame('2026-06-30', $reportDate?->toDateString());
    }

    public function test_summary_target_month_uses_current_bangkok_month_instead_of_selected_end_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00', 'Asia/Bangkok'));

        try {
            $export = new DeadstockReviewExport();

            /** @var Carbon $targetMonth */
            $targetMonth = $this->invokePrivate($export, 'summaryTargetMonth');

            $this->assertSame('2026-07-01', $targetMonth->toDateString());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_delivery_performance_compares_promised_month_with_cleared_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-29 12:00:00', 'Asia/Bangkok'));

        try {
            $items = new Collection([
                $this->performanceItem('SS01', 10, '2026-07-31', '2026-07-20 08:00:00', 'SERIAL-01'),
                $this->performanceItem('FF01', 20, '2026-07-15', '2026-08-01 08:00:00', 'SERIAL-02'),
                $this->performanceItem('FF02', 5, '2026-07-25', null, 'SERIAL-03'),
                $this->performanceItem('SS03', 7, '2026-07-31', null, 'SERIAL-05'),
                $this->performanceItem('SS02', 99, '2026-08-31', null, 'SERIAL-04'),
            ]);

            $report = $this->invokePrivate(
                new DeadstockReviewExport(),
                'calculateDeliveryPerformance',
                [$items, Carbon::parse('2026-07-01')]
            );

            $this->assertSame(4, $report['totals']['promised_items']);
            $this->assertSame(42.0, $report['totals']['promised_qty']);
            $this->assertSame(1, $report['totals']['cleared_items']);
            $this->assertSame(10.0, $report['totals']['cleared_qty']);
            $this->assertSame(2, $report['totals']['not_cleared_items']);
            $this->assertSame(25.0, $report['totals']['not_cleared_qty']);
            $this->assertSame(1, $report['totals']['pending_items']);
            $this->assertSame(7.0, $report['totals']['pending_qty']);
            $this->assertEqualsWithDelta(33.3333, $report['totals']['item_success_percent'], 0.001);
            $this->assertEqualsWithDelta(28.5714, $report['totals']['qty_success_percent'], 0.001);
            $this->assertCount(1, $report['cleared_item_list']);
            $this->assertSame('SERIAL-01', $report['cleared_item_list'][0]['serialnumber']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_delivery_performance_and_cleared_item_sheets_are_built(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-29 12:00:00', 'Asia/Bangkok'));

        try {
            $export = new DeadstockReviewExport();
            $report = $this->invokePrivate($export, 'calculateDeliveryPerformance', [
                new Collection([
                    $this->performanceItem('SS01', 10, '2026-07-31', '2026-07-20 08:00:00', 'SERIAL-01'),
                ]),
                Carbon::parse('2026-07-01'),
            ]);
            $spreadsheet = new Spreadsheet();
            $performanceSheet = $spreadsheet->getActiveSheet();
            $clearedSheet = $spreadsheet->createSheet();

            $this->invokePrivate($export, 'buildDeliveryPerformanceSheet', [$performanceSheet, $report]);
            $this->invokePrivate($export, 'buildClearedItemsSheet', [$clearedSheet, $report]);

            $this->assertSame('Delivery Performance', $performanceSheet->getTitle());
            $this->assertSame('Qty ที่แจ้งว่าจะส่ง (KGS)', $performanceSheet->getCell('B4')->getValue());
            $this->assertSame(10.0, $performanceSheet->getCell('B7')->getValue());
            $this->assertSame('ยังไม่ถึงกำหนด (KGS)', $performanceSheet->getCell('E4')->getValue());
            $this->assertSame('% ส่งได้จริงของรายการที่ถึงกำหนด (KGS)', $performanceSheet->getCell('F4')->getValue());
            $this->assertSame('F', $performanceSheet->getHighestDataColumn());
            $this->assertSame('Cleared Items', $clearedSheet->getTitle());
            $this->assertSame('Serial no.', $clearedSheet->getCell('F4')->getValue());
            $this->assertSame('SERIAL-01', $clearedSheet->getCell('F5')->getValue());

            $spreadsheet->disconnectWorksheets();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_sales_received_part_summary_groups_by_salesperson_received_date_and_part(): void
    {
        $export = new DeadstockReviewExport();
        $groups = $this->invokePrivate($export, 'salesReceivedPartSummary', [[
            [
                'salesperson' => 'คุณธัธลิญา',
                'purchase_date' => '2026-06-23',
                'partnumber' => 'PART-01',
                'qty' => 20,
                'due_date' => '2026-06-29',
                'revised_due_date' => '2026-08-31',
                'customer' => 'Customer A',
                'reason_code' => 'SS17-2',
                'review_detail' => '',
                'corrective_action' => '',
                'preventive_action' => '',
                'slot' => 'within_month',
            ],
            [
                'salesperson' => 'คุณธัธลิญา',
                'purchase_date' => '2026-06-23',
                'partnumber' => 'PART-01',
                'qty' => 5,
                'due_date' => '2026-06-30',
                'revised_due_date' => '2026-09-30',
                'customer' => 'Customer B',
                'reason_code' => 'SS17-2',
                'review_detail' => 'ติดตาม',
                'corrective_action' => 'แก้ไข',
                'preventive_action' => 'ป้องกัน',
                'slot' => 'problem',
            ],
            [
                'salesperson' => 'คุณธัธลิญา',
                'purchase_date' => '2026-06-24',
                'partnumber' => 'PART-02',
                'qty' => 15,
                'due_date' => '2026-06-30',
                'revised_due_date' => '2026-09-30',
                'customer' => 'Customer B',
                'reason_code' => 'SS17-2',
                'review_detail' => '',
                'corrective_action' => '',
                'preventive_action' => '',
                'slot' => 'problem',
            ],
        ]]);

        $this->assertCount(1, $groups);
        $this->assertSame('คุณธัธลิญา', $groups[0]['salesperson']);
        $this->assertSame(40.0, $groups[0]['total_qty']);
        $this->assertSame(20.0, $groups[0]['within_month_qty']);
        $this->assertSame(20.0, $groups[0]['problem_qty']);
        $this->assertCount(2, $groups[0]['rows']);
        $this->assertSame(25.0, $groups[0]['rows'][0]['qty']);
        $this->assertSame("29/06/2026\n30/06/2026", $groups[0]['rows'][0]['due_date']);
        $this->assertSame("31/08/2026\n30/09/2026", $groups[0]['rows'][0]['revised_due_date']);
        $this->assertSame("Customer A\nCustomer B", $groups[0]['rows'][0]['customer']);
    }

    public function test_sales_received_part_sheet_matches_requested_summary_layout(): void
    {
        $export = new DeadstockReviewExport();
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $groups = $this->invokePrivate($export, 'salesReceivedPartSummary', [[
            [
                'salesperson' => 'คุณธัธลิญา',
                'purchase_date' => '2026-06-23',
                'partnumber' => 'PART-01',
                'qty' => 954.7,
                'due_date' => '2026-06-29',
                'revised_due_date' => '2026-08-31',
                'customer' => '',
                'reason_code' => 'SS17-2',
                'review_detail' => '',
                'corrective_action' => '',
                'preventive_action' => '',
                'slot' => 'within_month',
            ],
            [
                'salesperson' => 'คุณธัธลิญา',
                'purchase_date' => '2026-06-26',
                'partnumber' => 'PART-02',
                'qty' => 508.2,
                'due_date' => '2026-05-30',
                'revised_due_date' => '2026-09-30',
                'customer' => '',
                'reason_code' => 'SS17-2',
                'review_detail' => '',
                'corrective_action' => '',
                'preventive_action' => '',
                'slot' => 'problem',
            ],
        ]]);

        $this->invokePrivate($export, 'buildSalesReceivedPartSheet', [$sheet, [
            'report_date' => Carbon::parse('2026-06-30'),
            'target_month' => Carbon::parse('2026-08-01'),
            'report_range_label' => 'ช่วงวันที่รับเข้า 01/06/2026 - 30/06/2026',
            'sales_received_part_summary' => $groups,
        ]]);

        $this->assertSame('Sales by Received Part', $sheet->getTitle());
        $this->assertStringContainsString('01/06/2026 - 30/06/2026', $sheet->getCell('A1')->getValue());
        $this->assertSame('Update', $sheet->getCell('A3')->getValue());
        $this->assertSame('พนักงานขาย', $sheet->getCell('B3')->getValue());
        $this->assertSame('คุณธัธลิญา', $sheet->getCell('B4')->getValue());
        $this->assertSame(1462.9, $sheet->getCell('C4')->getValue());
        $this->assertSame(508.2, $sheet->getCell('D4')->getValue());
        $this->assertSame(954.7, $sheet->getCell('G4')->getValue());
        $this->assertSame('วันที่รับเข้า', $sheet->getCell('A6')->getValue());
        $this->assertSame('รายการ (Part)', $sheet->getCell('B6')->getValue());
        $this->assertSame('23/06/2026', $sheet->getCell('A7')->getValue());
        $this->assertSame('PART-01', $sheet->getCell('B7')->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    private function performanceItem(
        string $reasonCode,
        float $qty,
        string $promisedDueDate,
        ?string $clearedAt,
        string $serial
    ): DeadstockSnapshotItem {
        $review = new DeadstockItemReview([
            'revised_due_date' => $promisedDueDate,
        ]);
        $item = new DeadstockSnapshotItem([
            'partnumber' => 'PART-' . $serial,
            'part_description' => 'Part description',
            'serialnumber' => $serial,
            'snapshot_qty' => $qty,
            'cleared_at' => $clearedAt,
            'customer_name' => 'Customer',
            'salesperson_name' => 'Sales',
            'company' => 'MENAM',
            'deadstock_code' => $reasonCode,
            'deadstock_desc' => 'Reason',
        ]);
        $item->setRelation('review', $review);
        $item->setRelation('latestCompareLog', null);
        $item->setRelation('snapshotMonth', null);

        return $item;
    }

    private function invokePrivate(object $target, string $method, array $arguments = []): mixed
    {
        return (new ReflectionMethod($target, $method))->invokeArgs($target, $arguments);
    }
}
