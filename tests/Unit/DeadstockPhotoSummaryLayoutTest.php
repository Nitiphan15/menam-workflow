<?php

namespace Tests\Unit;

use App\Exports\FormWOS\DeadstockReviewExport;
use App\Models\FormWOS\DeadstockSnapshotItem;
use App\Models\FormWOS\DeadstockSnapshotMonth;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class DeadstockPhotoSummaryLayoutTest extends TestCase
{
    public function test_summary_identifies_latest_snapshot_date(): void
    {
        $older = new DeadstockSnapshotMonth(['id' => 10179, 'recv_date' => '2026-08-31']);
        $newer = new DeadstockSnapshotMonth(['id' => 10180, 'recv_date' => '2026-09-16']);
        $olderItem = new DeadstockSnapshotItem(['snapshot_month_id' => 10179]);
        $olderItem->setRelation('snapshotMonth', $older);
        $newerItem = new DeadstockSnapshotItem(['snapshot_month_id' => 10180]);
        $newerItem->setRelation('snapshotMonth', $newer);

        $method = new \ReflectionMethod(DeadstockReviewExport::class, 'latestPhotoSnapshotMonth');
        $selected = $method->invoke(new DeadstockReviewExport(), collect([$olderItem, $newerItem]));

        $this->assertSame(10180, $selected->id);
    }

    public function test_only_items_without_any_due_date_are_unscheduled(): void
    {
        $method = new \ReflectionMethod(DeadstockReviewExport::class, 'photoSummaryCategory');
        $export = new DeadstockReviewExport();
        $target = Carbon::parse('2026-09-01');

        $this->assertSame('unscheduled', $method->invoke($export, null, false, false, $target));
        $this->assertSame('overdue', $method->invoke($export, Carbon::parse('2026-08-31'), false, false, $target));
        $this->assertSame('within_month', $method->invoke($export, Carbon::parse('2026-09-20'), false, false, $target));
        $this->assertSame('month_1', $method->invoke($export, Carbon::parse('2026-10-20'), false, false, $target));
        $this->assertSame('within_month', $method->invoke($export, null, true, false, $target));
    }

    public function test_summary_sheet_separates_overdue_from_missing_due_dates(): void
    {
        $row = [
            'label' => 'Jan-Jul 2026', 'within_month' => 31561, 'unscheduled' => 1278,
            'overdue' => 0, 'next_year' => 15017, 'month_3' => 19638, 'month_2' => 1597,
            'month_1' => 25583, 'problem_total' => 63112, 'total' => 94673,
        ];
        $spreadsheet = new Spreadsheet();
        $method = new \ReflectionMethod(DeadstockReviewExport::class, 'buildPhotoSummarySheet');
        $method->invoke(new DeadstockReviewExport(), $spreadsheet->getActiveSheet(), [
            'target_month' => Carbon::parse('2026-09-01'),
            'photo_summary' => [
                'rows' => [$row], 'totals' => ['label' => 'TOTAL(KGS)'] + array_slice($row, 1),
                'by_owner' => [
                    'FF' => ['rows' => [$row], 'totals' => ['label' => 'TOTAL(KGS)'] + array_slice($row, 1)],
                    'SS' => ['rows' => [$row], 'totals' => ['label' => 'TOTAL(KGS)'] + array_slice($row, 1)],
                ],
                'source_date' => '02/09/2026',
                'target_month' => Carbon::parse('2026-09-01'),
            ],
        ]);

        $sheet = $spreadsheet->getActiveSheet();
        $this->assertSame('FG Stock by Due Month', $sheet->getTitle());
        $this->assertSame('รวม', $sheet->getCell('A1')->getValue());
        $this->assertSame('FF', $sheet->getCell('A8')->getValue());
        $this->assertSame('SS', $sheet->getCell('A15')->getValue());
        $this->assertSame('C2:I2', $sheet->getMergeCells()['C2:I2']);
        $this->assertSame('เลยกำหนดส่ง', $sheet->getCell('D3')->getValue());
        $this->assertSame('ส่งปี 2027', $sheet->getCell('E3')->getValue());
        $this->assertSame('ส่งเดือน 12', $sheet->getCell('F3')->getValue());
        $this->assertSame('ส่งเดือน 10', $sheet->getCell('H3')->getValue());
        $this->assertSame(63112, $sheet->getCell('I4')->getValue());
        $this->assertSame(94673, $sheet->getCell('J4')->getValue());
        $this->assertSame('J', $sheet->getHighestDataColumn());
        $this->assertNull($sheet->getFreezePane());
        $spreadsheet->disconnectWorksheets();
    }

    public function test_due_month_sheet_uses_snapshot_month_instead_of_filter_end_month(): void
    {
        $row = [
            'label' => 'Jan-Sep 2026', 'within_month' => 0, 'unscheduled' => 0,
            'overdue' => 0, 'next_year' => 0, 'month_3' => 0, 'month_2' => 0,
            'month_1' => 100, 'problem_total' => 100, 'total' => 100,
        ];
        $spreadsheet = new Spreadsheet();

        (new \ReflectionMethod(DeadstockReviewExport::class, 'buildPhotoSummarySheet'))->invoke(
            new DeadstockReviewExport(),
            $spreadsheet->getActiveSheet(),
            [
                // The purchase-date filter can end in December while the latest
                // available snapshot that supplies Sheet 2 is still October.
                'target_month' => Carbon::parse('2026-12-01'),
                'photo_summary' => [
                    'rows' => [$row],
                    'totals' => ['label' => 'TOTAL(KGS)'] + array_slice($row, 1),
                    'by_owner' => [
                        'FF' => ['rows' => [$row], 'totals' => ['label' => 'TOTAL(KGS)'] + array_slice($row, 1)],
                        'SS' => ['rows' => [$row], 'totals' => ['label' => 'TOTAL(KGS)'] + array_slice($row, 1)],
                    ],
                    'source_date' => '26/10/2026',
                    'target_month' => Carbon::parse('2026-10-01'),
                ],
            ]
        );

        $sheet = $spreadsheet->getActiveSheet();
        $this->assertSame('งานที่ส่งภายในเดือน 10 และ WIP', $sheet->getCell('B2')->getValue());
        $this->assertSame('ส่งเดือน 11', $sheet->getCell('H3')->getValue());
        $this->assertSame(100, $sheet->getCell('H4')->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_original_summary_sheet_remains_available(): void
    {
        $export = new DeadstockReviewExport();
        $spreadsheet = new Spreadsheet();
        $method = new \ReflectionMethod(DeadstockReviewExport::class, 'buildSummarySheet');
        $row = [
            'label' => 'August-2026', 'start_total' => 100,
            'sales' => ['within_month' => 20, 'problem' => 10, 'total' => 30],
            'factory' => ['within_month' => 40, 'problem' => 5, 'total' => 45],
            'grand_total' => 75, 'decrease_percent' => 25,
        ];
        $method->invoke($export, $spreadsheet->getActiveSheet(), [
            'report_date' => Carbon::parse('2026-08-31'),
            'report_range_label' => 'ช่วงวันที่รับเข้า 01/01/2026 - 31/08/2026',
            'target_month_label' => 'September',
            'start_label' => 'Start 1/1/26 TOTAL (KGS)',
            'rows' => [$row], 'totals' => ['label' => 'TOTAL(KGS)'] + array_slice($row, 1),
        ]);

        $sheet = $spreadsheet->getActiveSheet();
        $this->assertSame('Summary', $sheet->getTitle());
        $this->assertSame('Deadstock Summary Report', $sheet->getCell('A1')->getValue());
        $this->assertSame('Sales Problem(SS)', $sheet->getCell('C5')->getValue());
        $this->assertSame('Factory Problem(FF)', $sheet->getCell('F5')->getValue());
        $this->assertSame(75, $sheet->getCell('I7')->getValue());
        $spreadsheet->disconnectWorksheets();
    }

    public function test_summary_marks_unverified_start_snapshot_as_not_available(): void
    {
        $export = new DeadstockReviewExport();
        $spreadsheet = new Spreadsheet();
        $method = new \ReflectionMethod(DeadstockReviewExport::class, 'buildSummarySheet');
        $row = [
            'label' => 'October-2026', 'start_total' => null,
            'sales' => ['within_month' => 10, 'problem' => 20, 'total' => 30],
            'factory' => ['within_month' => 5, 'problem' => 15, 'total' => 20],
            'grand_total' => 50, 'decrease_percent' => null,
        ];
        $method->invoke($export, $spreadsheet->getActiveSheet(), [
            'report_date' => Carbon::parse('2026-10-31'),
            'report_range_label' => 'ช่วงวันที่รับเข้า 01/01/2026 - 31/10/2026',
            'target_month_label' => 'October',
            'start_label' => 'Start 1/1/26 TOTAL (KGS)',
            'start_note' => 'ยังไม่แสดง Start TOTAL และ % ที่ลดลง เนื่องจากข้อมูล Snapshot ต้นปีไม่ครบถ้วน',
            'rows' => [$row],
            'totals' => ['label' => 'TOTAL(KGS)'] + array_slice($row, 1),
        ]);

        $sheet = $spreadsheet->getActiveSheet();
        $this->assertSame('-', $sheet->getCell('B7')->getValue());
        $this->assertSame('-', $sheet->getCell('J7')->getValue());
        $this->assertSame('-', $sheet->getCell('B8')->getValue());
        $this->assertStringContainsString('ข้อมูล Snapshot ต้นปีไม่ครบถ้วน', $sheet->getCell('A12')->getValue());
        $spreadsheet->disconnectWorksheets();
    }

    public function test_due_month_overdue_and_rescheduled_sheets_can_coexist(): void
    {
        $export = new DeadstockReviewExport();
        $spreadsheet = new Spreadsheet();
        $overdueSheet = $spreadsheet->getActiveSheet();
        $rescheduledSheet = $spreadsheet->createSheet();

        (new \ReflectionMethod(DeadstockReviewExport::class, 'buildOverdueItemsSheet'))->invoke(
            $export,
            $overdueSheet,
            ['target_month_label' => 'October 2026', 'overdue_item_list' => []]
        );
        (new \ReflectionMethod(DeadstockReviewExport::class, 'buildRescheduledItemsSheet'))->invoke(
            $export,
            $rescheduledSheet,
            ['range_label' => 'ช่วงวันที่รับเข้า 01/01/2026 - 31/12/2026', 'division_summary' => [], 'rows' => []]
        );

        $this->assertSame('Overdue Items', $overdueSheet->getTitle());
        $this->assertSame('Rescheduled Items', $rescheduledSheet->getTitle());
        $this->assertSame('ไม่มีรายการตาม Filter ที่เลือก', $overdueSheet->getCell('A4')->getValue());
        $this->assertSame('ไม่มีรายการตาม Filter ที่เลือก', $rescheduledSheet->getCell('A4')->getValue());
        $this->assertTrue(method_exists($export, 'registerEvents'));

        $spreadsheet->disconnectWorksheets();
    }
}
