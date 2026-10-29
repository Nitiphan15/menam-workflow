<?php

namespace App\Exports\FormWOS;

use App\Models\FormWOS\DeadstockSnapshotItem;
use App\Models\FormWOS\DeadstockSnapshotMonth;
use App\Support\FormWOS\DeadstockCompareStatus;
use App\Support\FormWOS\DeadstockReasonMap;
use App\Support\FormWOS\DeadstockReviewStatus;
use App\Support\FormWOS\DeadstockSalesMap;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Generator;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class DeadstockReviewExport implements FromGenerator, WithHeadings, WithStyles, WithEvents
{
    private ?Collection $items = null;

    public function __construct(private array $filters = [])
    {
    }

    public function headings(): array
    {
        return [
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
        ];
    }

    public function collection()
    {
        return $this->items()
            ->map(fn(DeadstockSnapshotItem $item) => $this->detailRow($item));
    }

    public function generator(): Generator
    {
        foreach ($this->iterateItems() as $item) {
            yield $this->detailRow($item);
        }
    }

    private function detailRow(DeadstockSnapshotItem $item): array
    {
        $review = $item->review;
        $reasonCode = $item->latestCompareLog?->matched_deadstock_code ?: $item->deadstock_code;
        $currentQty = $item->current_qty !== null ? (float) $item->current_qty : null;

        return [
            $item->purchase_date?->format('Y-m-d') ?? '',
            $item->partnumber,
            $item->part_description,
            $item->serialnumber,
            $currentQty,
            $item->due_date?->format('Y-m-d') ?? '',
            $review?->revised_due_date?->format('Y-m-d') ?? '',
            '',
            $item->customer_name,
            DeadstockSalesMap::label($item->salesperson_name),
            $this->siteLabel((string) $item->company),
            $reasonCode,
            DeadstockReasonMap::description($reasonCode, $item->deadstock_desc),
            DeadstockReviewStatus::label((string) ($review?->review_status ?? 'open')),
            $review?->review_detail,
            $review?->corrective_action,
            $review?->preventive_action,
            $review?->sales_remark,
            $review?->reviewed_at?->format('Y-m-d H:i') ?? '',
            $review?->reviewer?->name ?? '',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());

        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FF1F2937']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFEDF4FF'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $spreadsheet = $sheet->getParent();
                $range = $sheet->calculateWorksheetDimension();

                $sheet->setTitle('Details');
                $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
                $sheet->getStyle('C:C')->getAlignment()->setWrapText(true);
                $sheet->getStyle('M:R')->getAlignment()->setWrapText(true);
                $sheet->getStyle('E:E')->getNumberFormat()->setFormatCode('#,##0.00');
                $this->setDetailColumnWidths($sheet);

                $report = $this->summaryReport();
                $this->buildSummarySheet($spreadsheet->createSheet(0), $report);
                $this->buildPhotoSummarySheet($spreadsheet->createSheet(1), $report);
                $this->buildDeliveryPerformanceSheet($spreadsheet->createSheet(2), $report['delivery_performance']);
                $this->buildOverdueItemsSheet($spreadsheet->createSheet(3), $report['delivery_performance']);
                $this->buildRescheduledItemsSheet($spreadsheet->createSheet(4), $report['rescheduled_items']);
                $this->buildClearedItemsSheet($spreadsheet->createSheet(5), $report['delivery_performance']);
                $this->buildSalesItemsSheet($spreadsheet->createSheet(6), $report);
                $this->buildSalesReceivedPartSheet($spreadsheet->createSheet(7), $report);
                $this->buildSalesDeliveryStatusSheet($spreadsheet->createSheet(8), $report);
                $spreadsheet->setActiveSheetIndex(0);
            },
        ];
    }

    private function setDetailColumnWidths(Worksheet $sheet): void
    {
        $widths = [
            'A' => 14, 'B' => 18, 'C' => 34, 'D' => 22, 'E' => 14,
            'F' => 16, 'G' => 18, 'H' => 16, 'I' => 24, 'J' => 20,
            'K' => 12, 'L' => 14, 'M' => 32, 'N' => 22, 'O' => 32,
            'P' => 32, 'Q' => 32, 'R' => 32, 'S' => 18, 'T' => 20,
        ];

        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function buildSummarySheet(Worksheet $sheet, array $report): void
    {
        $reportDate = $report['report_date'];
        $targetMonth = $report['target_month_label'];

        $sheet->setTitle('Summary');
        $sheet->setCellValue('A1', 'Deadstock Summary Report');
        $sheet->setCellValue('A2', 'Update ' . $reportDate->format('d/m/y') . ' · ' . $report['report_range_label']);
        $sheet->mergeCells('A1:J1');
        $sheet->mergeCells('A2:J2');

        $sheet->setCellValue('A4', 'วันรับเข้า Stock');
        $sheet->setCellValue('B4', $report['start_label']);
        $sheet->setCellValue('C4', 'Update ' . $reportDate->format('d/m/y'));
        $sheet->setCellValue('J4', '% ที่ลดลง');
        $sheet->mergeCells('A4:A6');
        $sheet->mergeCells('B4:B6');
        $sheet->mergeCells('C4:I4');
        $sheet->mergeCells('J4:J6');

        $sheet->setCellValue('C5', 'Sales Problem(SS)');
        $sheet->setCellValue('F5', 'Factory Problem(FF)');
        $sheet->setCellValue('I5', 'Grand Total(KGS)');
        $sheet->mergeCells('C5:E5');
        $sheet->mergeCells('F5:H5');
        $sheet->mergeCells('I5:I6');

        $sheet->fromArray([
            [
                null,
                null,
                'ส่งได้ภายในเดือน ' . $targetMonth,
                'มีปัญหาไม่ได้ส่งภายใน ' . $targetMonth,
                'Total',
                'ส่งได้ภายในเดือน ' . $targetMonth,
                'มีปัญหาไม่ได้ส่งภายใน ' . $targetMonth,
                'Total',
                null,
                null,
            ],
        ], null, 'A6');

        $rowNo = 7;
        foreach ($report['rows'] as $row) {
            $this->writeSummaryRow($sheet, $rowNo, $row);
            $rowNo++;
        }

        $this->writeSummaryRow($sheet, $rowNo, $report['totals']);
        $sheet->getStyle("A{$rowNo}:J{$rowNo}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFF00');

        $noteRow = $rowNo + 2;
        $sheet->setCellValue("A{$noteRow}", '*งานปกติฝั่ง Sales หมายถึง WIP, Stock Grating, สินค้าที่ได้ส่งภายในเดือน');
        $sheet->setCellValue('A' . ($noteRow + 1), '**งานปกติฝั่ง Factory หมายถึงงาน Stock FG, WIP, Stock Grating, สินค้าที่ได้ส่งภายในเดือน');
        $sheet->setCellValue(
            'A' . ($noteRow + 2),
            $report['start_note'] ?? '% ที่ลดลง = (Start TOTAL - Grand Total) / Start TOTAL'
        );
        $sheet->mergeCells("A{$noteRow}:J{$noteRow}");
        $sheet->mergeCells('A' . ($noteRow + 1) . ':J' . ($noteRow + 1));
        $sheet->mergeCells('A' . ($noteRow + 2) . ':J' . ($noteRow + 2));
        $sheet->getStyle("A{$noteRow}:A" . ($noteRow + 2))->getFont()->getColor()->setARGB('FFFF0000');

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(18);
        $sheet->getStyle('A4:J6')->getFont()->setBold(true);
        $sheet->getStyle('A4:J6')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2F0D9');
        $sheet->getStyle("A4:J{$rowNo}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A4:J{$rowNo}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getStyle("B7:I{$rowNo}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("D7:D{$rowNo}")->getFont()->getColor()->setARGB('FFFF0000');
        $sheet->getStyle("G7:G{$rowNo}")->getFont()->getColor()->setARGB('FFFF0000');
        $sheet->getStyle("B7:I{$rowNo}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("J7:J{$rowNo}")->getNumberFormat()->setFormatCode('0.00%');
        $sheet->freezePane('A7');

        foreach (range('A', 'J') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

    }

    private function buildPhotoSummarySheet(Worksheet $sheet, array $report): void
    {
        $sheet->setTitle('FG Stock by Due Month');
        $layout = $report['photo_summary'];
        $month = $layout['target_month'];
        $rowNo = 1;
        foreach (['รวม' => $layout, 'FF' => $layout['by_owner']['FF'], 'SS' => $layout['by_owner']['SS']] as $title => $table) {
            $rowNo = $this->writePhotoSummaryTable($sheet, $rowNo, $title, $table, $month) + 3;
        }
        $rowNo -= 3;
        $sheet->setCellValue('A' . ($rowNo + 2), '** ข้อมูลอ้างอิงจากเอกสาร Detail Dead Stock ที่อัพเดทในระบบ Menam Online');
        $sheet->mergeCells('A' . ($rowNo + 2) . ':J' . ($rowNo + 2));
        $sheet->getStyle('A' . ($rowNo + 2))->getFont()->getColor()->setARGB('FFFF0000');
        $sheet->setCellValue('A' . ($rowNo + 3), '* ยังไม่ระบุกำหนดส่ง หมายถึงไม่มีวันที่กำหนดส่งใน Detail Dead Stock และข้อมูลติดตาม');
        $sheet->mergeCells('A' . ($rowNo + 3) . ':J' . ($rowNo + 3));
        $sheet->setCellValue('A' . ($rowNo + 4), 'ข้อมูลจาก Snapshot วันที่ ' . $layout['source_date'] . ' (ไม่รวมยอดซ้ำจากชุดก่อนหน้า)');
        $sheet->mergeCells('A' . ($rowNo + 4) . ':J' . ($rowNo + 4));
        $sheet->getColumnDimension('A')->setWidth(22);
        foreach (range('B', 'J') as $column) $sheet->getColumnDimension($column)->setWidth(18);
        $sheet->freezePane('A4');
    }

    private function writePhotoSummaryTable(Worksheet $sheet, int $titleRow, string $title, array $table, Carbon $month): int
    {
        $headerRow = $titleRow + 1;
        $subHeaderRow = $titleRow + 2;
        $sheet->setCellValue("A{$titleRow}", $title);
        $sheet->mergeCells("A{$titleRow}:J{$titleRow}");
        $sheet->setCellValue("A{$headerRow}", 'วันรับเข้า Stock');
        $sheet->setCellValue("B{$headerRow}", 'งานที่ส่งภายในเดือน ' . $month->month . ' และ WIP');
        $sheet->setCellValue("C{$headerRow}", 'มีปัญหาไม่ได้ส่งภายในเดือน ' . $month->month);
        $sheet->setCellValue("J{$headerRow}", 'Total');
        $sheet->mergeCells("A{$headerRow}:A{$subHeaderRow}");
        $sheet->mergeCells("B{$headerRow}:B{$subHeaderRow}");
        $sheet->mergeCells("C{$headerRow}:I{$headerRow}");
        $sheet->mergeCells("J{$headerRow}:J{$subHeaderRow}");
        $sheet->fromArray([[
            null, null, 'ยังไม่ระบุกำหนดส่ง', 'เลยกำหนดส่ง', 'ส่งปี ' . ($month->year + 1),
            'ส่งเดือน ' . $month->copy()->addMonths(3)->month,
            'ส่งเดือน ' . $month->copy()->addMonths(2)->month,
            'ส่งเดือน ' . $month->copy()->addMonth()->month, 'Total', null,
        ]], null, "A{$subHeaderRow}");
        $rowNo = $subHeaderRow + 1;
        foreach ($table['rows'] as $row) {
            $this->writePhotoSummaryRow($sheet, $rowNo++, $row);
        }
        $this->writePhotoSummaryRow($sheet, $rowNo, $table['totals']);
        $sheet->getStyle("A{$titleRow}:J{$titleRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F4E78');
        $sheet->getStyle("A{$titleRow}:J{$titleRow}")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle("A{$headerRow}:J{$subHeaderRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD3D3D3');
        $sheet->getStyle("A{$rowNo}:J{$rowNo}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFF00');
        $sheet->getStyle("A{$titleRow}:J{$rowNo}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A{$titleRow}:J{$rowNo}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getStyle("A{$titleRow}:J{$rowNo}")->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('C' . ($subHeaderRow + 1) . ":I{$rowNo}")->getFont()->getColor()->setARGB('FFFF0000');
        $sheet->getStyle('B' . ($subHeaderRow + 1) . ":J{$rowNo}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getRowDimension($titleRow)->setRowHeight(28);
        $sheet->getRowDimension($headerRow)->setRowHeight(34);
        $sheet->getRowDimension($subHeaderRow)->setRowHeight(40);
        foreach (range($subHeaderRow + 1, $rowNo) as $row) $sheet->getRowDimension($row)->setRowHeight(32);

        return $rowNo;
    }

    private function writePhotoSummaryRow(Worksheet $sheet, int $rowNo, array $row): void
    {
        $sheet->fromArray([[
            $row['label'], $row['within_month'], $row['unscheduled'], $row['overdue'],
            $row['next_year'], $row['month_3'], $row['month_2'], $row['month_1'],
            $row['problem_total'], $row['total'],
        ]], null, "A{$rowNo}");
    }

    private function buildOverdueItemsSheet(Worksheet $sheet, array $performance): void
    {
        $sheet->setTitle('Overdue Items');
        $sheet->setCellValue('A1', 'รายการที่ระบุวันส่งแล้วแต่เลยกำหนด · ' . $performance['target_month_label']);
        $sheet->mergeCells('A1:L1');
        $sheet->getStyle('A1:L1')->getFont()->setBold(true)->setSize(16)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:L1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFC00000');
        $sheet->fromArray([[
            'ลำดับ', 'Div.', 'Sales', 'Part', 'รายละเอียด Part', 'Serial no.', 'ลูกค้า',
            'Qty (KGS)', 'วันที่แจ้งส่งล่าสุด', 'เลยกำหนด (วัน)', 'Site', 'รหัสสาเหตุ',
        ]], null, 'A3');

        $rowNo = 4;
        foreach ($performance['overdue_item_list'] as $index => $item) {
            $sheet->fromArray([[
                $index + 1, $item['division'], $item['salesperson'], $item['partnumber'],
                $item['part_description'], $item['serialnumber'], $item['customer'], $item['qty'],
                $item['promised_due_date'], $item['overdue_days'], $item['site'], $item['reason_code'],
            ]], null, "A{$rowNo}");
            $rowNo++;
        }
        if ($performance['overdue_item_list'] === []) {
            $sheet->setCellValue('A4', 'ไม่มีรายการตาม Filter ที่เลือก');
            $sheet->mergeCells('A4:L4');
        }

        $lastRow = max(4, $rowNo - 1);
        $sheet->getStyle("A3:L{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A3:L{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        $sheet->getStyle("H4:H{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('A3:L3')->getFont()->setBold(true);
        $sheet->getStyle('A3:L3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF4CCCC');
        $sheet->setAutoFilter("A3:L{$lastRow}");
        $sheet->freezePane('A4');
        foreach (['A' => 9, 'B' => 12, 'C' => 22, 'D' => 20, 'E' => 34, 'F' => 22, 'G' => 26, 'H' => 14, 'I' => 18, 'J' => 16, 'K' => 12, 'L' => 14] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function buildRescheduledItemsSheet(Worksheet $sheet, array $report): void
    {
        $sheet->setTitle('Rescheduled Items');
        $sheet->setCellValue('A1', 'รายการเลื่อนกำหนดส่ง · ' . $report['range_label']);
        $sheet->mergeCells('A1:N1');
        $sheet->getStyle('A1:N1')->getFont()->setBold(true)->setSize(16)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:N1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF9C5700');
        $sheet->fromArray([['Div.', 'จำนวนรายการ', 'Qty (KGS)', 'จำนวนวันที่เลื่อนรวม', 'จำนวนครั้งที่เลื่อนรวม']], null, 'A3');

        $summaryRow = 4;
        foreach ($report['division_summary'] as $summary) {
            $sheet->fromArray([[
                $summary['division'], $summary['item_count'], $summary['qty'],
                $summary['postponed_days'], $summary['postpone_count'],
            ]], null, "A{$summaryRow}");
            $summaryRow++;
        }
        if ($report['division_summary'] === []) {
            $sheet->setCellValue('A4', 'ไม่มีรายการตาม Filter ที่เลือก');
            $sheet->mergeCells('A4:E4');
            $summaryRow = 5;
        }

        $headerRow = $summaryRow + 1;
        $sheet->fromArray([[
            'ลำดับ', 'Div.', 'Sales', 'Part', 'รายละเอียด Part', 'Serial no.', 'ลูกค้า', 'Qty (KGS)',
            'กำหนดส่งเดิม', 'กำหนดส่งใหม่ล่าสุด', 'เลื่อน (วัน)', 'เลื่อน (ครั้ง)', 'Site', 'รหัสสาเหตุ',
        ]], null, "A{$headerRow}");
        $rowNo = $headerRow + 1;
        foreach ($report['rows'] as $index => $item) {
            $sheet->fromArray([[
                $index + 1, $item['division'], $item['salesperson'], $item['partnumber'],
                $item['part_description'], $item['serialnumber'], $item['customer'], $item['qty'],
                $item['original_due_date'], $item['latest_due_date'], $item['postponed_days'],
                $item['postpone_count'], $item['site'], $item['reason_code'],
            ]], null, "A{$rowNo}");
            $rowNo++;
        }
        if ($report['rows'] === []) {
            $sheet->setCellValue("A{$rowNo}", 'ไม่มีรายการตาม Filter ที่เลือก');
            $sheet->mergeCells("A{$rowNo}:N{$rowNo}");
            $rowNo++;
        }

        $lastSummaryRow = max(4, $summaryRow - 1);
        $lastRow = $rowNo - 1;
        $sheet->getStyle("A3:E{$lastSummaryRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('A3:E3')->getFont()->setBold(true);
        $sheet->getStyle('A3:E3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFCE4D6');
        $sheet->getStyle("A{$headerRow}:N{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A{$headerRow}:N{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        $sheet->getStyle("A{$headerRow}:N{$headerRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headerRow}:N{$headerRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFCE4D6');
        $sheet->getStyle('H' . ($headerRow + 1) . ":H{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->setAutoFilter("A{$headerRow}:N{$lastRow}");
        $sheet->freezePane('A' . ($headerRow + 1));
        foreach (['A' => 9, 'B' => 12, 'C' => 22, 'D' => 20, 'E' => 34, 'F' => 22, 'G' => 26, 'H' => 14, 'I' => 18, 'J' => 20, 'K' => 14, 'L' => 14, 'M' => 12, 'N' => 14] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function buildSalesItemsSheet(Worksheet $sheet, array $report): void
    {
        $targetMonth = $report['target_month_label'];

        $sheet->setTitle('Sales Items');
        $lastRow = $this->writeSalesItemList(
            $sheet,
            1,
            'Sales ที่ส่งได้ภายในเดือน ' . $targetMonth,
            $report['sales_item_lists']['within_month'],
            'FFE2F0D9'
        );
        $this->writeSalesItemList(
            $sheet,
            $lastRow + 2,
            'Sales ที่ไม่ได้ส่งภายในเดือน ' . $targetMonth,
            $report['sales_item_lists']['problem'],
            'FFFCE4D6'
        );

        $sheet->freezePane('A3');
        $widths = [
            'A' => 10, 'B' => 22, 'C' => 20, 'D' => 34, 'E' => 22,
            'F' => 28, 'G' => 14, 'H' => 16, 'I' => 14, 'J' => 34,
            'K' => 16,
        ];
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }


    private function buildSalesReceivedPartSheet(Worksheet $sheet, array $report): void
    {
        $targetMonth = $report['target_month']->format('F Y');
        $groups = $report['sales_received_part_summary'];

        $sheet->setTitle('Sales by Received Part');
        $sheet->setCellValue('A1', 'สรุป Deadstock ตามวันรับเข้าและ Part · ' . $report['report_range_label']);
        $sheet->mergeCells('A1:K1');
        $sheet->getStyle('A1:K1')->getFont()->setBold(true)->setSize(16)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:K1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F4E78');

        $rowNo = 3;
        if ($groups === []) {
            $sheet->setCellValue("A{$rowNo}", 'ไม่มีรายการ Sales ตาม Filter ที่เลือก');
            $sheet->mergeCells("A{$rowNo}:K{$rowNo}");
        } else {
            foreach ($groups as $group) {
                $sheet->fromArray([[
                    'Update',
                    'พนักงานขาย',
                    'จำนวน (KGS)',
                    'จบเดือน ' . $targetMonth . ' ค้างส่ง',
                    null,
                    null,
                    'ส่งแล้ว/เตรียมส่งมอบเดือน ' . $targetMonth,
                    null,
                    null,
                    null,
                    null,
                ]], null, "A{$rowNo}");
                $sheet->mergeCells("D{$rowNo}:F{$rowNo}");
                $sheet->mergeCells("G{$rowNo}:K{$rowNo}");
                $sheet->getStyle("A{$rowNo}:K{$rowNo}")->getFont()->setBold(true);
                $sheet->getStyle("A{$rowNo}:K{$rowNo}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9EAF7');

                $valueRow = $rowNo + 1;
                $sheet->fromArray([[
                    $report['report_date']->format('d/m/Y'),
                    $group['salesperson'],
                    $group['total_qty'],
                    $group['problem_qty'],
                    null,
                    null,
                    $group['within_month_qty'],
                    null,
                    null,
                    null,
                    null,
                ]], null, "A{$valueRow}");
                $sheet->mergeCells("D{$valueRow}:F{$valueRow}");
                $sheet->mergeCells("G{$valueRow}:K{$valueRow}");
                $sheet->getStyle("C{$valueRow}:K{$valueRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle("D{$valueRow}:F{$valueRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFCE4D6');
                $sheet->getStyle("G{$valueRow}:K{$valueRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2F0D9');

                $headerRow = $rowNo + 3;
                $sheet->fromArray([[
                    'วันที่รับเข้า',
                    'รายการ (Part)',
                    'รายละเอียด Part',
                    'จำนวน (KG.)',
                    'กำหนดส่ง (S/O)',
                    'กำหนดส่งใหม่',
                    'ลูกค้า',
                    'รหัสสาเหตุ',
                    'รายละเอียด',
                    'แนวทางแก้ไข',
                    'แนวทางป้องกัน',
                ]], null, "A{$headerRow}");
                $sheet->getStyle("A{$headerRow}:K{$headerRow}")->getFont()->setBold(true);
                $sheet->getStyle("A{$headerRow}:K{$headerRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2F0D9');

                $dataRow = $headerRow + 1;
                foreach ($group['rows'] as $row) {
                    $sheet->fromArray([[
                        $row['purchase_date'],
                        $row['partnumber'],
                        $row['part_description'],
                        $row['qty'],
                        $row['due_date'],
                        $row['revised_due_date'],
                        $row['customer'],
                        $row['reason_code'],
                        $row['review_detail'],
                        $row['corrective_action'],
                        $row['preventive_action'],
                    ]], null, "A{$dataRow}");
                    $dataRow++;
                }

                $lastDataRow = $dataRow - 1;
                $sheet->getStyle("A{$rowNo}:K{$lastDataRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                $sheet->getStyle("A{$rowNo}:K{$lastDataRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
                $sheet->getStyle("D" . ($headerRow + 1) . ":D{$lastDataRow}")->getNumberFormat()->setFormatCode('#,##0.00');

                $rowNo = $lastDataRow + 3;
            }
        }

        $sheet->freezePane('A2');
        $widths = [
            'A' => 15, 'B' => 24, 'C' => 34, 'D' => 15, 'E' => 18,
            'F' => 18, 'G' => 30, 'H' => 15, 'I' => 32, 'J' => 32,
            'K' => 32,
        ];
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function buildSalesDeliveryStatusSheet(Worksheet $sheet, array $report): void
    {
        $groups = $report['sales_delivery_status_summary'];

        $sheet->setTitle('Sales by Delivery Status');
        $sheet->setCellValue('A1', 'สรุป Deadstock แยกตามสถานะการส่ง · ' . $report['report_range_label']);
        $sheet->mergeCells('A1:J1');
        $sheet->getStyle('A1:J1')->getFont()->setBold(true)->setSize(16)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:J1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F4E78');

        $rowNo = 3;
        if ($groups === []) {
            $sheet->setCellValue("A{$rowNo}", 'ไม่มีรายการ Sales ตาม Filter ที่เลือก');
            $sheet->mergeCells("A{$rowNo}:J{$rowNo}");
        } else {
            foreach ($groups as $group) {
                $sheet->fromArray([[
                    'Update', 'พนักงานขาย', 'จำนวนรวม (KGS)', 'ยังไม่ส่ง (KGS)', 'ส่งแล้ว (KGS)',
                ]], null, "A{$rowNo}");
                $sheet->mergeCells("E{$rowNo}:J{$rowNo}");
                $sheet->getStyle("A{$rowNo}:J{$rowNo}")->getFont()->setBold(true);
                $sheet->getStyle("A{$rowNo}:J{$rowNo}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9EAF7');

                $valueRow = $rowNo + 1;
                $sheet->fromArray([[
                    $report['report_date']->format('d/m/Y'),
                    $group['salesperson'],
                    $group['total_qty'],
                    $group['pending_qty'],
                    $group['cleared_qty'],
                ]], null, "A{$valueRow}");
                $sheet->mergeCells("E{$valueRow}:J{$valueRow}");
                $sheet->getStyle("C{$valueRow}:E{$valueRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle("D{$valueRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFCE4D6');
                $sheet->getStyle("E{$valueRow}:J{$valueRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2F0D9');

                $headerRow = $rowNo + 3;
                $sheet->fromArray([[
                    'วันที่รับเข้า', 'รายการ (Part)', 'รายละเอียด Part', 'จำนวน (KG.)', 'สถานะการส่ง',
                    'กำหนดส่ง (S/O)', 'กำหนดส่งใหม่', 'ลูกค้า', 'รหัสสาเหตุ', 'รายละเอียด',
                ]], null, "A{$headerRow}");
                $sheet->getStyle("A{$headerRow}:J{$headerRow}")->getFont()->setBold(true);
                $sheet->getStyle("A{$headerRow}:J{$headerRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2F0D9');

                $dataRow = $headerRow + 1;
                foreach ($group['rows'] as $row) {
                    $sheet->fromArray([[
                        $row['purchase_date'],
                        $row['partnumber'],
                        $row['part_description'],
                        $row['qty'],
                        $row['delivery_status'],
                        $row['due_date'],
                        $row['revised_due_date'],
                        $row['customer'],
                        $row['reason_code'],
                        $row['review_detail'],
                    ]], null, "A{$dataRow}");
                    if ($row['delivery_status'] === 'ส่งแล้ว') {
                        $sheet->getStyle("A{$dataRow}:J{$dataRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2F0D9');
                    }
                    $dataRow++;
                }

                $lastDataRow = $dataRow - 1;
                $sheet->getStyle("A{$rowNo}:J{$lastDataRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                $sheet->getStyle("A{$rowNo}:J{$lastDataRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
                $sheet->getStyle('D' . ($headerRow + 1) . ":D{$lastDataRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                $rowNo = $lastDataRow + 3;
            }
        }

        $sheet->freezePane('A2');
        foreach ([
            'A' => 15, 'B' => 24, 'C' => 34, 'D' => 15, 'E' => 16,
            'F' => 18, 'G' => 18, 'H' => 30, 'I' => 15, 'J' => 32,
        ] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function buildDeliveryPerformanceSheet(Worksheet $sheet, array $performance): void
    {
        $sheet->setTitle('Delivery Performance');
        $sheet->setCellValue('A1', 'สรุปผลการส่งตามกำหนดภายในเดือน ' . $performance['target_month_label']);
        $sheet->setCellValue(
            'A2',
            'เทียบรายการที่กำหนดส่งปัจจุบันอยู่ในเดือนเป้าหมาย กับรายการที่เคลียร์ภายใน '
                . $performance['cutoff_date']->format('d/m/Y H:i')
                . ' โดย Due Date หลังวันตัดยอดจะแยกเป็นยังไม่ถึงกำหนด'
        );
        $sheet->mergeCells('A1:F1');
        $sheet->mergeCells('A2:F2');
        $sheet->fromArray([[
            'ผู้รับผิดชอบ',
            'Qty ที่แจ้งว่าจะส่ง (KGS)',
            'ส่งได้จริง (KGS)',
            'ส่งไม่ได้/ไม่ทัน (KGS)',
            'ยังไม่ถึงกำหนด (KGS)',
            '% ส่งได้จริงของรายการที่ถึงกำหนด (KGS)',
        ]], null, 'A4');

        $rowNo = 5;
        foreach ([$performance['rows']['sales'], $performance['rows']['factory'], $performance['totals']] as $row) {
            $sheet->fromArray([[
                $row['label'],
                $row['promised_qty'],
                $row['cleared_qty'],
                $row['not_cleared_qty'],
                $row['pending_qty'],
                $row['qty_success_percent'] === null ? '-' : $row['qty_success_percent'] / 100,
            ]], null, "A{$rowNo}");
            $rowNo++;
        }

        $totalRow = $rowNo - 1;
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A4:F4')->getFont()->setBold(true);
        $sheet->getStyle('A4:F4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9EAF7');
        $sheet->getStyle("A{$totalRow}:F{$totalRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$totalRow}:F{$totalRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFF00');
        $sheet->getStyle("A4:F{$totalRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A4:F{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getStyle("B5:E{$totalRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("F5:F{$totalRow}")->getNumberFormat()->setFormatCode('0.00%');
        $sheet->freezePane('A5');

        $widths = [
            'A' => 22,
            'B' => 26,
            'C' => 22,
            'D' => 26,
            'E' => 24,
            'F' => 30,
        ];
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function buildClearedItemsSheet(Worksheet $sheet, array $performance): void
    {
        $items = $performance['cleared_item_list'];

        $sheet->setTitle('Cleared Items');
        $sheet->setCellValue('A1', 'รายการที่ส่งได้จริงภายในเดือน ' . $performance['target_month_label']);
        $sheet->setCellValue('A2', 'รายการที่กำหนดส่งปัจจุบันอยู่ในเดือนเป้าหมาย และเคลียร์ไม่เกินวันตัดยอด');
        $sheet->mergeCells('A1:M1');
        $sheet->mergeCells('A2:M2');
        $sheet->fromArray([[
            'ลำดับ',
            'กำหนดส่งที่แจ้ง',
            'วันที่เคลียร์',
            'Part',
            'รายละเอียด Part',
            'Serial no.',
            'Qty ตอนที่แจ้ง (KGS)',
            'ลูกค้า',
            'Sales',
            'Site',
            'รหัสสาเหตุ',
            'รายละเอียดสาเหตุ',
            'เดือน Snapshot',
        ]], null, 'A4');

        $rowNo = 5;
        if ($items === []) {
            $sheet->setCellValue("A{$rowNo}", 'ไม่มีรายการ');
            $sheet->mergeCells("A{$rowNo}:M{$rowNo}");
        } else {
            foreach ($items as $index => $item) {
                $sheet->fromArray([[
                    $index + 1,
                    $item['promised_due_date'],
                    $item['cleared_at'],
                    $item['partnumber'],
                    $item['part_description'],
                    $item['serialnumber'],
                    $item['qty'],
                    $item['customer'],
                    $item['salesperson'],
                    $item['site'],
                    $item['reason_code'],
                    $item['reason_description'],
                    $item['snapshot_month'],
                ]], null, "A{$rowNo}");
                $rowNo++;
            }
        }

        $lastRow = $items === [] ? $rowNo : $rowNo - 1;
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A4:M4')->getFont()->setBold(true);
        $sheet->getStyle('A4:M4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2F0D9');
        $sheet->getStyle("A4:M{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A4:M{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        $sheet->getStyle("G5:G{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->setAutoFilter("A4:M{$lastRow}");
        $sheet->freezePane('A5');

        $widths = [
            'A' => 9, 'B' => 17, 'C' => 18, 'D' => 20, 'E' => 34,
            'F' => 22, 'G' => 20, 'H' => 26, 'I' => 20, 'J' => 12,
            'K' => 14, 'L' => 34, 'M' => 16,
        ];
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function writeSalesItemList(
        Worksheet $sheet,
        int $titleRow,
        string $title,
        array $items,
        string $titleColor
    ): int {
        $sheet->setCellValue("A{$titleRow}", $title);
        $sheet->mergeCells("A{$titleRow}:K{$titleRow}");
        $sheet->getStyle("A{$titleRow}:K{$titleRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$titleRow}:K{$titleRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($titleColor);

        $headerRow = $titleRow + 1;
        $sheet->fromArray([[
            'ลำดับ',
            'Sales',
            'Part',
            'รายละเอียด Part',
            'Serial no.',
            'ลูกค้า',
            'Qty (KGS)',
            'กำหนดส่งใหม่',
            'รหัสสาเหตุ',
            'รายละเอียดสาเหตุ',
            'เดือน Snapshot',
        ]], null, "A{$headerRow}");
        $sheet->getStyle("A{$headerRow}:K{$headerRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headerRow}:K{$headerRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9EAF7');

        $dataRow = $headerRow + 1;
        if ($items === []) {
            $sheet->setCellValue("A{$dataRow}", 'ไม่มีรายการ');
            $sheet->mergeCells("A{$dataRow}:K{$dataRow}");
        } else {
            foreach ($items as $index => $item) {
                $sheet->fromArray([[
                    $index + 1,
                    $item['salesperson'],
                    $item['partnumber'],
                    $item['part_description'],
                    $item['serialnumber'],
                    $item['customer'],
                    $item['qty'],
                    $item['revised_due_date'],
                    $item['reason_code'],
                    $item['reason_description'],
                    $item['snapshot_month'],
                ]], null, "A{$dataRow}");
                $dataRow++;
            }
        }

        $lastRow = $items === [] ? $dataRow : $dataRow - 1;
        $sheet->getStyle("A{$headerRow}:K{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A{$headerRow}:K{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        $sheet->getStyle("G" . ($headerRow + 1) . ":G{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');

        return $lastRow;
    }

    private function writeSummaryRow(Worksheet $sheet, int $rowNo, array $row): void
    {
        $sheet->fromArray([[
            $row['label'],
            $row['start_total'] === null ? '-' : $row['start_total'],
            $row['sales']['within_month'],
            $row['sales']['problem'],
            $row['sales']['total'],
            $row['factory']['within_month'],
            $row['factory']['problem'],
            $row['factory']['total'],
            $row['grand_total'],
            $row['decrease_percent'] === null ? '-' : $row['decrease_percent'] / 100,
        ]], null, 'A' . $rowNo);
    }

    public function summaryReport(bool $includeDetailLists = true, bool $includePdfLists = false): array
    {
        $reportDate = $this->reportDateFromFilters()
            ?? $this->latestReportDate()
            ?? now('Asia/Bangkok');
        $reportDate = $reportDate instanceof Carbon ? $reportDate->copy() : Carbon::parse($reportDate);
        $reportYear = (int) $reportDate->year;
        $targetMonth = $reportDate->copy()->startOfMonth();
        $historicalStart = Carbon::create($reportYear - 3, 1, 1)->startOfDay();
        $yearStart = Carbon::create($reportYear, 1, 1)->startOfDay();
        $currentMonthStart = $reportDate->copy()->startOfMonth();
        $previousMonthEnd = $currentMonthStart->copy()->subDay();
        $photoSourceMonth = $this->latestPhotoSnapshotMonth($this->iterateItems());
        $photoReportDate = $photoSourceMonth?->snapshot_month ?? $reportDate;
        $photoReportDate = $photoReportDate instanceof Carbon ? $photoReportDate->copy() : Carbon::parse($photoReportDate);
        $photoCurrentMonthStart = $photoReportDate->copy()->startOfMonth();
        $photoPreviousMonthEnd = $photoCurrentMonthStart->copy()->subDay();
        $yearStartSnapshotMonth = $this->yearStartSnapshotMonth($reportDate);
        // Snapshot metadata has no completeness flag. Do not present an
        // incomplete historical import as a verified year-start baseline.
        $hasCompleteStartSnapshot = false;

        $rows = [
            'older' => $this->blankSummaryRow('<= ' . $yearStart->copy()->subYears(3)->subDay()->format('d/m/Y')),
            'history' => $this->blankSummaryRow(($reportYear - 3) . '-' . ($reportYear - 1)),
            'year_to_previous' => $this->blankSummaryRow(
                $currentMonthStart->month > 1
                    ? 'Jan-' . $previousMonthEnd->format('M Y')
                    : 'Jan ' . $reportYear
            ),
            'current_month' => $this->blankSummaryRow($reportDate->format('F-Y')),
        ];
        $photoRows = [
            'year_to_previous' => $this->blankPhotoSummaryRow('Jan-' . $photoPreviousMonthEnd->format('M Y')),
            'current_month' => $this->blankPhotoSummaryRow($photoReportDate->format('F-Y')),
        ];
        $photoOwnerRows = ['FF' => $photoRows, 'SS' => $photoRows];

        if ($hasCompleteStartSnapshot && $yearStartSnapshotMonth) {
            $baselineQuery = $yearStartSnapshotMonth->items()->with('latestCompareLog');
            $this->applyReportFilters($baselineQuery);
            $baselineQuery->lazy(500)
                ->each(function (DeadstockSnapshotItem $item) use (&$rows, $historicalStart, $yearStart, $currentMonthStart) {
                    $bucket = $this->summaryBucket($item->purchase_date instanceof Carbon ? $item->purchase_date : null, $historicalStart, $yearStart, $currentMonthStart);
                    $code = strtoupper(trim((string) ($item->latestCompareLog?->matched_deadstock_code ?: $item->deadstock_code)));

                    if (!str_starts_with($code, 'SS') && !str_starts_with($code, 'FF')) {
                        return;
                    }

                    $rows[$bucket]['start_total'] += (float) $item->snapshot_qty;
                });
        }

        $salesItemLists = [
            'within_month' => [],
            'problem' => [],
        ];
        $salesReceivedPartItems = [];
        $salesDeliveryStatusItems = [];

        foreach ($this->iterateItems() as $item) {
            $purchaseDate = $item->purchase_date instanceof Carbon ? $item->purchase_date : null;
            $bucket = $this->summaryBucket($purchaseDate, $historicalStart, $yearStart, $currentMonthStart);
            $snapshotQty = (float) $item->snapshot_qty;
            $openQty = (string) $item->compare_status === 'cleared'
                ? 0.0
                : (float) ($item->current_qty ?? $item->snapshot_qty);

            if ($hasCompleteStartSnapshot && !$yearStartSnapshotMonth && $purchaseDate && $purchaseDate->lt($yearStart)) {
                $rows[$bucket]['start_total'] += $snapshotQty;
            }

            $code = strtoupper(trim((string) ($item->latestCompareLog?->matched_deadstock_code ?: $item->deadstock_code)));
            $owner = str_starts_with($code, 'SS') ? 'sales' : (str_starts_with($code, 'FF') ? 'factory' : null);

            if ($owner === null) {
                continue;
            }

            $slot = $this->summarySlot($item->review?->revised_due_date, $targetMonth);
            $rows[$bucket][$owner][$slot] += $openQty;

            if ($owner === 'sales' && $includeDetailLists) {
                $isCleared = (string) $item->compare_status === 'cleared';
                $deliveryQty = $isCleared ? $snapshotQty : $openQty;
                if ($deliveryQty > 0) {
                    $salesDeliveryStatusItems[] = [
                        'salesperson' => DeadstockSalesMap::label($item->salesperson_name),
                        'purchase_date' => $item->purchase_date?->format('Y-m-d') ?? '',
                        'partnumber' => (string) $item->partnumber,
                        'part_description' => (string) $item->part_description,
                        'qty' => $deliveryQty,
                        'delivery_status' => $isCleared ? 'ส่งแล้ว' : 'ยังไม่ส่ง',
                        'due_date' => $item->due_date?->format('Y-m-d') ?? '',
                        'revised_due_date' => $item->review?->revised_due_date?->format('Y-m-d') ?? '',
                        'customer' => (string) $item->customer_name,
                        'reason_code' => $code,
                        'review_detail' => (string) ($item->review?->review_detail ?? ''),
                    ];
                }

                if ($openQty > 0) {
                    $salesReceivedPartItems[] = [
                        'salesperson' => DeadstockSalesMap::label($item->salesperson_name),
                        'purchase_date' => $item->purchase_date?->format('Y-m-d') ?? '',
                        'partnumber' => (string) $item->partnumber,
                        'part_description' => (string) $item->part_description,
                        'qty' => $openQty,
                        'due_date' => $item->due_date?->format('Y-m-d') ?? '',
                        'revised_due_date' => $item->review?->revised_due_date?->format('Y-m-d') ?? '',
                        'customer' => (string) $item->customer_name,
                        'reason_code' => $code,
                        'review_detail' => (string) ($item->review?->review_detail ?? ''),
                        'corrective_action' => (string) ($item->review?->corrective_action ?? ''),
                        'preventive_action' => (string) ($item->review?->preventive_action ?? ''),
                        'slot' => $slot,
                    ];
                }

                $salesItemLists[$slot][] = [
                    'salesperson' => DeadstockSalesMap::label($item->salesperson_name),
                    'partnumber' => (string) $item->partnumber,
                    'part_description' => (string) $item->part_description,
                    'serialnumber' => (string) $item->serialnumber,
                    'customer' => (string) $item->customer_name,
                    'qty' => $openQty,
                    'revised_due_date' => $item->review?->revised_due_date?->format('Y-m-d') ?? '',
                    'reason_code' => $code,
                    'reason_description' => DeadstockReasonMap::description($code, $item->deadstock_desc),
                    'snapshot_month' => $item->snapshotMonth?->snapshot_month?->format('Y-m') ?? '',
                ];
            }
        }

        foreach ($salesItemLists as &$salesItems) {
            usort($salesItems, fn(array $left, array $right) => [
                $left['salesperson'],
                $left['partnumber'],
                $left['serialnumber'],
            ] <=> [
                $right['salesperson'],
                $right['partnumber'],
                $right['serialnumber'],
            ]);
        }
        unset($salesItems);

        [$purchaseFrom, $purchaseTo] = $this->purchaseRange();
        $rangeLabel = $purchaseFrom || $purchaseTo
            ? 'ช่วงวันที่รับเข้า '
                . ($purchaseFrom?->format('d/m/Y') ?? 'เริ่มต้น')
                . ' - '
                . ($purchaseTo?->format('d/m/Y') ?? 'ปัจจุบัน')
            : 'ตาม Filter รายละเอียด';

        $totals = $this->blankSummaryRow('TOTAL(KGS)');

        foreach ($rows as $bucket => &$row) {
            $row = $this->finalizeSummaryRow($row, in_array($bucket, ['older', 'history'], true));

            foreach (['start_total', 'grand_total'] as $key) {
                $totals[$key] += $row[$key];
            }

            foreach (['sales', 'factory'] as $owner) {
                foreach (['within_month', 'problem', 'total'] as $key) {
                    $totals[$owner][$key] += $row[$owner][$key];
                }
            }
        }
        unset($row);

        if (!$hasCompleteStartSnapshot) {
            foreach ($rows as &$row) {
                $row['start_total'] = null;
                $row['decrease_percent'] = null;
            }
            unset($row);
            $totals['start_total'] = null;
            $totals['decrease_percent'] = null;
        }

        foreach ($this->iterateItems() as $item) {
            if ((int) $item->snapshot_month_id !== (int) $photoSourceMonth?->id) {
                continue;
            }
            $purchaseDate = $item->purchase_date instanceof Carbon ? $item->purchase_date : null;
            $photoBucket = $this->summaryBucket($purchaseDate, $historicalStart, $yearStart, $photoCurrentMonthStart);
            if (!isset($photoRows[$photoBucket])) {
                continue;
            }
            $code = strtoupper(trim((string) ($item->latestCompareLog?->matched_deadstock_code ?: $item->deadstock_code)));
            if (!str_starts_with($code, 'SS') && !str_starts_with($code, 'FF')) {
                continue;
            }
            $clearedThisMonth = $item->compare_status === 'cleared'
                && $item->cleared_at
                && Carbon::parse($item->cleared_at)->isSameMonth($photoCurrentMonthStart);
            if (!in_array($item->compare_status, ['active', 'changed'], true) && !$clearedThisMonth) {
                continue;
            }
            $due = $item->review?->revised_due_date ?? $item->current_due_date ?? $item->due_date;
            $due = $due ? Carbon::parse($due) : null;
            $isWip = preg_match('/^(SS|FF)02(?:-|$)/', $code) === 1;
            $photoQty = $clearedThisMonth
                ? (float) $item->snapshot_qty
                : (float) ($item->current_qty ?? $item->snapshot_qty);
            $photoKey = $this->photoSummaryCategory($due, $isWip, (bool) $clearedThisMonth, $photoCurrentMonthStart);
            $photoRows[$photoBucket][$photoKey] += $photoQty;
            $photoOwnerRows[substr($code, 0, 2)][$photoBucket][$photoKey] += $photoQty;
        }

        $photoSummary = $this->finalizePhotoSummaryRows($photoRows);
        $photoSummary['by_owner'] = [
            'FF' => $this->finalizePhotoSummaryRows($photoOwnerRows['FF']),
            'SS' => $this->finalizePhotoSummaryRows($photoOwnerRows['SS']),
        ];
        $photoSummary['source_date'] = $photoSourceMonth?->recv_date?->format('d/m/Y')
            ?? $photoSourceMonth?->as_of_date?->format('d/m/Y')
            ?? $photoReportDate->format('d/m/Y');
        $photoSummary['target_month'] = $photoCurrentMonthStart;

        return [
            'rows' => array_values($rows),
            'totals' => $this->finalizeSummaryRow($totals, false),
            'photo_summary' => $photoSummary,
            'report_date' => $reportDate,
            'target_month' => $targetMonth,
            'target_month_label' => $targetMonth->format('F'),
            'sales_item_lists' => $salesItemLists,
            'sales_received_part_summary' => $includeDetailLists
                ? $this->salesReceivedPartSummary($salesReceivedPartItems)
                : [],
            'sales_delivery_status_summary' => $includeDetailLists
                ? $this->salesDeliveryStatusSummary($salesDeliveryStatusItems)
                : [],
            'report_range_label' => $rangeLabel,
            'start_label' => 'Start 1/1/' . $reportDate->format('y') . ' TOTAL (KGS)',
            'start_note' => 'ยังไม่แสดง Start TOTAL และ % ที่ลดลง เนื่องจากข้อมูล Snapshot ต้นปีไม่ครบถ้วน',
            'delivery_performance' => $this->deliveryPerformanceReport(
                $targetMonth,
                $includeDetailLists,
                $includeDetailLists || $includePdfLists
            ),
            'rescheduled_items' => $includeDetailLists || $includePdfLists
                ? $this->rescheduledItemsReport($this->iterateItems(), $rangeLabel)
                : ['range_label' => $rangeLabel, 'division_summary' => [], 'rows' => []],
        ];
    }

    private function rescheduledItemsReport(iterable $items, string $rangeLabel): array
    {
        $rows = [];
        foreach ($items as $item) {
            $originalDue = $item->due_date;
            $latestDue = $item->review?->revised_due_date ?? $item->current_due_date;
            if (!$originalDue || !$latestDue) {
                continue;
            }
            $originalDue = $originalDue instanceof Carbon ? $originalDue->copy() : Carbon::parse($originalDue);
            $latestDue = $latestDue instanceof Carbon ? $latestDue->copy() : Carbon::parse($latestDue);
            if (!$latestDue->gt($originalDue)) {
                continue;
            }

            $reasonCode = strtoupper(trim((string) ($item->latestCompareLog?->matched_deadstock_code ?: $item->deadstock_code)));
            $rows[] = [
                'division' => DeadstockSalesMap::accessKey($item->salesperson_name),
                'salesperson' => DeadstockSalesMap::label($item->salesperson_name),
                'partnumber' => (string) $item->partnumber,
                'part_description' => (string) $item->part_description,
                'serialnumber' => (string) $item->serialnumber,
                'customer' => (string) $item->customer_name,
                'qty' => (float) ($item->current_qty ?? $item->snapshot_qty),
                'original_due_date' => $originalDue->format('Y-m-d'),
                'latest_due_date' => $latestDue->format('Y-m-d'),
                'postponed_days' => $originalDue->diffInDays($latestDue),
                'postpone_count' => $this->postponeCount($item, $originalDue, $latestDue),
                'site' => $this->siteLabel((string) $item->company),
                'reason_code' => $reasonCode,
            ];
        }

        $rows = collect($rows)
            ->sortBy(fn(array $row) => [$row['division'], $row['latest_due_date'], $row['salesperson'], $row['partnumber']])
            ->values();

        $divisionSummary = $rows
            ->groupBy('division')
            ->map(fn(Collection $divisionRows, string $division) => [
                'division' => $division,
                'item_count' => $divisionRows->count(),
                'qty' => $divisionRows->sum('qty'),
                'postponed_days' => $divisionRows->sum('postponed_days'),
                'postpone_count' => $divisionRows->sum('postpone_count'),
            ])
            ->values()
            ->all();

        return ['range_label' => $rangeLabel, 'division_summary' => $divisionSummary, 'rows' => $rows->all()];
    }

    private function postponeCount(DeadstockSnapshotItem $item, Carbon $originalDue, Carbon $latestDue): int
    {
        $cursor = $originalDue->copy();
        $count = 0;
        $logs = $item->review?->logs?->sortBy(fn($log) => [$log->changed_at?->timestamp ?? 0, $log->id ?? 0]) ?? collect();

        foreach ($logs as $log) {
            if (!in_array('revised_due_date', (array) $log->changed_fields, true)) {
                continue;
            }
            $value = $log->after_values['revised_due_date'] ?? null;
            if (!$value) {
                continue;
            }
            $next = Carbon::parse($value);
            if ($next->gt($cursor)) {
                $count++;
            }
            $cursor = $next;
        }

        if ($latestDue->gt($cursor)) {
            $count++;
        }

        return max(1, $count);
    }

    public function deliveryPerformanceReport(
        ?Carbon $targetMonth = null,
        bool $includeClearedItems = true,
        bool $includeOverdueItems = true
    ): array
    {
        return $this->calculateDeliveryPerformance(
            $this->deliveryPerformanceItems(),
            ($targetMonth ?? $this->summaryTargetMonth())->copy()->startOfMonth(),
            $includeClearedItems,
            $includeOverdueItems
        );
    }

    private function deliveryPerformanceItems(): iterable
    {
        $filters = array_merge($this->filters, [
            'status' => 'all',
            'action_status' => 'all',
        ]);

        return (new self($filters))->iterateItems();
    }

    private function calculateDeliveryPerformance(
        iterable $items,
        Carbon $targetMonth,
        bool $includeClearedItems = true,
        bool $includeOverdueItems = true
    ): array
    {
        $targetMonth = $targetMonth->copy()->startOfMonth();
        $now = now('Asia/Bangkok');
        $monthEnd = $targetMonth->copy()->endOfMonth();
        $cutoffDate = $monthEnd->lte($now) ? $monthEnd : $now->copy();
        $rows = [
            'sales' => $this->blankDeliveryPerformanceRow('Sales Problem (SS)'),
            'factory' => $this->blankDeliveryPerformanceRow('Factory Problem (FF)'),
        ];
        $clearedItemList = [];
        $overdueItemList = [];

        foreach ($items as $item) {
            $promisedDueDate = $item->review?->revised_due_date;
            if (!$promisedDueDate) {
                continue;
            }

            $promisedDueDate = $promisedDueDate instanceof Carbon
                ? $promisedDueDate->copy()
                : Carbon::parse($promisedDueDate);
            if (!$promisedDueDate->isSameMonth($targetMonth)) {
                continue;
            }

            $reasonCode = strtoupper(trim((string) ($item->latestCompareLog?->matched_deadstock_code ?: $item->deadstock_code)));
            $owner = str_starts_with($reasonCode, 'SS') ? 'sales' : (str_starts_with($reasonCode, 'FF') ? 'factory' : null);
            if ($owner === null) {
                continue;
            }

            $qty = (float) $item->snapshot_qty;
            $clearedAt = $item->cleared_at
                ? ($item->cleared_at instanceof Carbon ? $item->cleared_at->copy() : Carbon::parse($item->cleared_at))
                : null;
            $wasClearedInTime = $clearedAt !== null && $clearedAt->lte($cutoffDate);

            $rows[$owner]['promised_items']++;
            $rows[$owner]['promised_qty'] += $qty;

            if (!$wasClearedInTime && $promisedDueDate->gt($cutoffDate)) {
                $rows[$owner]['pending_items']++;
                $rows[$owner]['pending_qty'] += $qty;
                continue;
            }

            if (!$wasClearedInTime) {
                $rows[$owner]['not_cleared_items']++;
                $rows[$owner]['not_cleared_qty'] += $qty;
                if ($includeOverdueItems) {
                    $overdueItemList[] = [
                        'division' => DeadstockSalesMap::accessKey($item->salesperson_name),
                        'salesperson' => DeadstockSalesMap::label($item->salesperson_name),
                        'partnumber' => (string) $item->partnumber,
                        'part_description' => (string) $item->part_description,
                        'serialnumber' => (string) $item->serialnumber,
                        'customer' => (string) $item->customer_name,
                        'qty' => $qty,
                        'promised_due_date' => $promisedDueDate->format('Y-m-d'),
                        'overdue_days' => $promisedDueDate->diffInDays($cutoffDate),
                        'site' => $this->siteLabel((string) $item->company),
                        'reason_code' => $reasonCode,
                    ];
                }
                continue;
            }

            $rows[$owner]['cleared_items']++;
            $rows[$owner]['cleared_qty'] += $qty;
            if ($includeClearedItems) {
                $clearedItemList[] = [
                    'promised_due_date' => $promisedDueDate->format('Y-m-d'),
                    'cleared_at' => $clearedAt->format('Y-m-d H:i'),
                    'partnumber' => (string) $item->partnumber,
                    'part_description' => (string) $item->part_description,
                    'serialnumber' => (string) $item->serialnumber,
                    'qty' => $qty,
                    'customer' => (string) $item->customer_name,
                    'salesperson' => DeadstockSalesMap::label($item->salesperson_name),
                    'site' => $this->siteLabel((string) $item->company),
                    'reason_code' => $reasonCode,
                    'reason_description' => DeadstockReasonMap::description($reasonCode, $item->deadstock_desc),
                    'snapshot_month' => $item->snapshotMonth?->snapshot_month?->format('Y-m') ?? '',
                ];
            }
        }

        foreach ($rows as &$row) {
            $row = $this->finalizeDeliveryPerformanceRow($row);
        }
        unset($row);

        $totals = $this->blankDeliveryPerformanceRow('รวม');
        foreach ($rows as $row) {
            foreach ([
                'promised_items',
                'promised_qty',
                'cleared_items',
                'cleared_qty',
                'not_cleared_items',
                'not_cleared_qty',
                'pending_items',
                'pending_qty',
            ] as $key) {
                $totals[$key] += $row[$key];
            }
        }

        usort($clearedItemList, fn(array $left, array $right) => [
            $left['cleared_at'],
            $left['salesperson'],
            $left['partnumber'],
            $left['serialnumber'],
        ] <=> [
            $right['cleared_at'],
            $right['salesperson'],
            $right['partnumber'],
            $right['serialnumber'],
        ]);
        usort($overdueItemList, fn(array $left, array $right) => [
            $left['division'], $left['promised_due_date'], $left['salesperson'], $left['partnumber'],
        ] <=> [
            $right['division'], $right['promised_due_date'], $right['salesperson'], $right['partnumber'],
        ]);

        return [
            'target_month' => $targetMonth,
            'target_month_label' => $targetMonth->format('F Y'),
            'cutoff_date' => $cutoffDate,
            'rows' => $rows,
            'totals' => $this->finalizeDeliveryPerformanceRow($totals),
            'cleared_item_list' => $clearedItemList,
            'overdue_item_list' => $overdueItemList,
        ];
    }

    private function blankDeliveryPerformanceRow(string $label): array
    {
        return [
            'label' => $label,
            'promised_items' => 0,
            'promised_qty' => 0.0,
            'cleared_items' => 0,
            'cleared_qty' => 0.0,
            'not_cleared_items' => 0,
            'not_cleared_qty' => 0.0,
            'pending_items' => 0,
            'pending_qty' => 0.0,
            'item_success_percent' => null,
            'qty_success_percent' => null,
        ];
    }

    private function finalizeDeliveryPerformanceRow(array $row): array
    {
        $dueItems = $row['cleared_items'] + $row['not_cleared_items'];
        $dueQty = $row['cleared_qty'] + $row['not_cleared_qty'];
        $row['item_success_percent'] = $dueItems > 0
            ? ($row['cleared_items'] / $dueItems) * 100
            : null;
        $row['qty_success_percent'] = $dueQty > 0
            ? ($row['cleared_qty'] / $dueQty) * 100
            : null;

        return $row;
    }

    private function yearStartSnapshotMonth(Carbon $reportDate): ?\App\Models\FormWOS\DeadstockSnapshotMonth
    {
        return \App\Models\FormWOS\DeadstockSnapshotMonth::query()
            ->where('item_count', '>', 0)
            ->whereBetween('snapshot_month', [
                $reportDate->copy()->startOfYear()->toDateString(),
                $reportDate->copy()->endOfYear()->toDateString(),
            ])
            ->orderBy('snapshot_month')
            ->orderBy('recv_date')
            ->first();
    }

    private function blankSummaryRow(string $label): array
    {
        return [
            'label' => $label,
            'start_total' => 0.0,
            'sales' => ['within_month' => 0.0, 'problem' => 0.0, 'total' => 0.0],
            'factory' => ['within_month' => 0.0, 'problem' => 0.0, 'total' => 0.0],
            'grand_total' => 0.0,
            'decrease_percent' => null,
        ];
    }

    private function blankPhotoSummaryRow(string $label): array
    {
        return ['label' => $label] + array_fill_keys(['within_month', 'unscheduled', 'overdue', 'next_year', 'month_3', 'month_2', 'month_1', 'problem_total', 'total'], 0.0);
    }

    private function finalizePhotoSummaryRows(array $rows): array
    {
        $totals = $this->blankPhotoSummaryRow('TOTAL(KGS)');
        foreach ($rows as &$row) {
            $row['problem_total'] = array_sum(array_intersect_key($row, array_flip(['unscheduled', 'overdue', 'next_year', 'month_1', 'month_2', 'month_3'])));
            $row['total'] = $row['within_month'] + $row['problem_total'];
            foreach (array_keys($totals) as $key) {
                if ($key !== 'label') $totals[$key] += $row[$key];
            }
        }
        unset($row);

        return ['rows' => array_values($rows), 'totals' => $totals];
    }

    private function latestPhotoSnapshotMonth(iterable $items): ?DeadstockSnapshotMonth
    {
        $latest = null;
        $latestKey = null;

        foreach ($items as $item) {
            $month = $item->snapshotMonth;
            if (!$month) {
                continue;
            }

            $key = [
                ($month->recv_date ?? $month->as_of_date ?? $month->snapshot_month)?->timestamp ?? 0,
                (int) $month->id,
            ];
            if ($latestKey === null || $key > $latestKey) {
                $latest = $month;
                $latestKey = $key;
            }
        }

        return $latest;
    }

    private function latestReportDate(): ?Carbon
    {
        $latest = null;

        foreach ($this->iterateItems() as $item) {
            $date = $item->snapshotMonth?->recv_date
                ?? $item->snapshotMonth?->as_of_date
                ?? $item->snapshotMonth?->snapshot_month;
            if ($date && ($latest === null || $date->gt($latest))) {
                $latest = $date->copy();
            }
        }

        return $latest;
    }

    private function photoSummaryCategory(?Carbon $due, bool $isWip, bool $clearedThisMonth, Carbon $targetMonth): string
    {
        if ($isWip || $clearedThisMonth || ($due && $due->isSameMonth($targetMonth))) {
            return 'within_month';
        }
        if (!$due) {
            return 'unscheduled';
        }
        if ($due->lt($targetMonth)) {
            return 'overdue';
        }
        if ($due->year > $targetMonth->year) {
            return 'next_year';
        }

        $monthOffset = $targetMonth->diffInMonths($due->copy()->startOfMonth());
        return 'month_' . min(3, max(1, (int) $monthOffset));
    }

    private function finalizeSummaryRow(array $row, bool $calculateDecrease = true): array
    {
        $row['sales']['total'] = $row['sales']['within_month'] + $row['sales']['problem'];
        $row['factory']['total'] = $row['factory']['within_month'] + $row['factory']['problem'];
        $row['grand_total'] = $row['sales']['total'] + $row['factory']['total'];
        $row['decrease_percent'] = $calculateDecrease && $row['start_total'] > 0
            ? (($row['start_total'] - $row['grand_total']) / $row['start_total']) * 100
            : null;

        return $row;
    }

    private function summaryBucket(?Carbon $purchaseDate, Carbon $historicalStart, Carbon $yearStart, Carbon $currentMonthStart): string
    {
        if (!$purchaseDate) {
            return 'older';
        }

        if ($purchaseDate->lt($historicalStart)) {
            return 'older';
        }

        if ($purchaseDate->lt($yearStart)) {
            return 'history';
        }

        if ($purchaseDate->lt($currentMonthStart)) {
            return 'year_to_previous';
        }

        return 'current_month';
    }

    private function summarySlot($revisedDueDate, Carbon $targetMonth): string
    {
        if (!$revisedDueDate) {
            return 'problem';
        }

        $date = $revisedDueDate instanceof Carbon
            ? $revisedDueDate->copy()
            : Carbon::parse($revisedDueDate);

        return $date->isSameMonth($targetMonth) ? 'within_month' : 'problem';
    }

    private function salesReceivedPartSummary(array $items): array
    {
        $groups = [];

        foreach ($items as $item) {
            $salesperson = trim((string) $item['salesperson']) ?: 'ไม่ระบุ Sales';
            $groups[$salesperson] ??= [
                'salesperson' => $salesperson,
                'total_qty' => 0.0,
                'within_month_qty' => 0.0,
                'problem_qty' => 0.0,
                'rows' => [],
            ];

            $qty = (float) $item['qty'];
            $groups[$salesperson]['total_qty'] += $qty;
            $groups[$salesperson][$item['slot'] . '_qty'] += $qty;

            $rowKey = ($item['purchase_date'] ?: '-') . '|' . ($item['partnumber'] ?: '-');
            $groups[$salesperson]['rows'][$rowKey] ??= [
                'purchase_date' => $item['purchase_date'],
                'partnumber' => $item['partnumber'],
                'part_description' => [],
                'qty' => 0.0,
                'due_date' => [],
                'revised_due_date' => [],
                'customer' => [],
                'reason_code' => [],
                'review_detail' => [],
                'corrective_action' => [],
                'preventive_action' => [],
            ];

            $groups[$salesperson]['rows'][$rowKey]['qty'] += $qty;
            foreach ([
                'due_date',
                'revised_due_date',
                'part_description',
                'customer',
                'reason_code',
                'review_detail',
                'corrective_action',
                'preventive_action',
            ] as $field) {
                $value = trim((string) $item[$field]);
                if ($value !== '') {
                    $groups[$salesperson]['rows'][$rowKey][$field][$value] = true;
                }
            }
        }

        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($groups as &$group) {
            ksort($group['rows']);
            $group['rows'] = array_values(array_map(function (array $row) {
                $row['purchase_date'] = $this->formatSummaryDate($row['purchase_date']);
                foreach (['due_date', 'revised_due_date'] as $field) {
                    $row[$field] = implode("\n", array_map(fn(string $date) => $this->formatSummaryDate($date), array_keys($row[$field])));
                }
                foreach (['part_description', 'customer', 'reason_code', 'review_detail', 'corrective_action', 'preventive_action'] as $field) {
                    $row[$field] = implode("\n", array_keys($row[$field]));
                }

                return $row;
            }, $group['rows']));
        }
        unset($group);

        return array_values($groups);
    }

    private function salesDeliveryStatusSummary(array $items): array
    {
        $groups = [];

        foreach ($items as $item) {
            $salesperson = trim((string) $item['salesperson']) ?: 'ไม่ระบุ Sales';
            $groups[$salesperson] ??= [
                'salesperson' => $salesperson,
                'total_qty' => 0.0,
                'pending_qty' => 0.0,
                'cleared_qty' => 0.0,
                'rows' => [],
            ];

            $qty = (float) $item['qty'];
            $isCleared = $item['delivery_status'] === 'ส่งแล้ว';
            $groups[$salesperson]['total_qty'] += $qty;
            $groups[$salesperson][$isCleared ? 'cleared_qty' : 'pending_qty'] += $qty;

            $rowKey = ($isCleared ? '1' : '0') . '|' . ($item['purchase_date'] ?: '-') . '|' . ($item['partnumber'] ?: '-');
            $groups[$salesperson]['rows'][$rowKey] ??= [
                'purchase_date' => $item['purchase_date'],
                'partnumber' => $item['partnumber'],
                'part_description' => [],
                'qty' => 0.0,
                'delivery_status' => $item['delivery_status'],
                'due_date' => [],
                'revised_due_date' => [],
                'customer' => [],
                'reason_code' => [],
                'review_detail' => [],
            ];

            $groups[$salesperson]['rows'][$rowKey]['qty'] += $qty;
            foreach (['due_date', 'revised_due_date', 'part_description', 'customer', 'reason_code', 'review_detail'] as $field) {
                $value = trim((string) $item[$field]);
                if ($value !== '') {
                    $groups[$salesperson]['rows'][$rowKey][$field][$value] = true;
                }
            }
        }

        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($groups as &$group) {
            $group['rows'] = array_values(array_map(function (array $row) {
                $row['purchase_date'] = $this->formatSummaryDate($row['purchase_date']);
                foreach (['due_date', 'revised_due_date'] as $field) {
                    $row[$field] = implode("\n", array_map(fn(string $date) => $this->formatSummaryDate($date), array_keys($row[$field])));
                }
                foreach (['part_description', 'customer', 'reason_code', 'review_detail'] as $field) {
                    $row[$field] = implode("\n", array_keys($row[$field]));
                }

                return $row;
            }, $group['rows']));

            usort($group['rows'], fn(array $left, array $right) => [
                $left['delivery_status'] === 'ส่งแล้ว' ? 1 : 0,
                -$left['qty'],
                $left['purchase_date'],
                $left['partnumber'],
            ] <=> [
                $right['delivery_status'] === 'ส่งแล้ว' ? 1 : 0,
                -$right['qty'],
                $right['purchase_date'],
                $right['partnumber'],
            ]);
        }
        unset($group);

        return array_values($groups);
    }

    private function formatSummaryDate(string $date): string
    {
        return $date === '' ? '' : Carbon::parse($date)->format('d/m/Y');
    }

    private function summaryTargetMonth(): Carbon
    {
        return now('Asia/Bangkok')->startOfMonth();
    }

    private function reportDateFromFilters(): ?Carbon
    {
        $monthValue = trim((string) (($this->filters['month_to'] ?? '') ?: ($this->filters['month_from'] ?? '')));

        return preg_match('/^\d{4}-\d{2}$/', $monthValue) === 1
            ? Carbon::parse($monthValue . '-01')->endOfMonth()
            : null;
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private function purchaseRange(): array
    {
        $from = trim((string) ($this->filters['month_from'] ?? ''));
        $to = trim((string) ($this->filters['month_to'] ?? ''));

        return [
            preg_match('/^\d{4}-\d{2}$/', $from) === 1 ? Carbon::parse($from . '-01')->startOfMonth() : null,
            preg_match('/^\d{4}-\d{2}$/', $to) === 1 ? Carbon::parse($to . '-01')->endOfMonth() : null,
        ];
    }

    private function query()
    {
        $monthIds = collect($this->filters['month_ids'] ?? [])
            ->filter(fn($id) => is_numeric($id))
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->values()
            ->all();

        $sort = trim((string) ($this->filters['sort'] ?? 'qty_desc'));

        $query = DeadstockSnapshotItem::query()
            ->with(['snapshotMonth', 'review.reviewer', 'review.logs', 'latestCompareLog'])
            ->when($monthIds !== [], fn($q) => $q->whereIn('snapshot_month_id', $monthIds), fn($q) => $q->whereRaw('1 = 0'));

        $this->applyReportFilters($query);

        return $query
            ->when($sort === 'purchase_oldest', fn($q) => $q->orderByRaw('CASE WHEN purchase_date IS NULL THEN 1 ELSE 0 END')->orderBy('purchase_date'))
            ->when($sort === 'due_soon', fn($q) => $q->orderByRaw('CASE WHEN COALESCE(current_due_date, due_date) IS NULL THEN 1 ELSE 0 END')->orderByRaw('COALESCE(current_due_date, due_date) ASC'))
            ->when(!in_array($sort, ['purchase_oldest', 'due_soon'], true), fn($q) => $q->orderByDesc(DB::raw('COALESCE(current_qty, snapshot_qty)')))
            ->orderByDesc('snapshot_month_id')
            ->orderByRaw("CASE compare_status WHEN 'changed' THEN 1 WHEN 'active' THEN 2 WHEN 'pending' THEN 3 WHEN 'cleared' THEN 4 ELSE 5 END");
    }

    private function applyReportFilters($query): void
    {
        $status = trim((string) ($this->filters['status'] ?? DeadstockCompareStatus::DEFAULT));
        $compareStatuses = DeadstockCompareStatus::valuesForFilter($status);
        $rawActionStatus = trim((string) ($this->filters['action_status'] ?? 'all'));
        $actionStatus = $rawActionStatus === 'all'
            ? 'all'
            : (DeadstockReviewStatus::normalize($rawActionStatus) ?? 'all');
        $site = trim((string) ($this->filters['site'] ?? $this->filters['company'] ?? 'all'));
        $customer = $this->normalizeMultiFilter($this->filters['customer'] ?? []);
        $salesDivisions = $this->normalizeMultiFilter($this->filters['sales_division'] ?? []);
        $reasonCode = $this->normalizeMultiFilter($this->filters['reason_code'] ?? []);
        $codeGroup = strtoupper(trim((string) ($this->filters['code_group'] ?? '')));
        $serial = trim((string) ($this->filters['serial'] ?? ''));
        [$purchaseFrom, $purchaseTo] = $this->purchaseRange();

        $query
            ->when($purchaseFrom, fn($q) => $q->whereDate('purchase_date', '>=', $purchaseFrom->toDateString()))
            ->when($purchaseTo, fn($q) => $q->whereDate('purchase_date', '<=', $purchaseTo->toDateString()))
            ->when($customer !== [], fn($q) => $q->whereIn('customer_name', $customer))
            ->when($salesDivisions !== [], fn($q) => $q->whereIn('salesperson_name', DeadstockSalesMap::rawValuesForDivisions($salesDivisions)))
            ->when($reasonCode !== [], fn($q) => $this->applyReasonFilter($q, $reasonCode))
            ->when(in_array($codeGroup, ['FF', 'SS'], true), fn($q) => $q->where('deadstock_code', 'like', $codeGroup . '%'))
            ->when($serial !== '', function ($q) use ($serial) {
                $keyword = '%' . $serial . '%';

                $q->where(function ($nested) use ($keyword) {
                    $nested->where('serialnumber', 'like', $keyword)
                        ->orWhere('transaction_number', 'like', $keyword);
                });
            })
            ->when($compareStatuses !== [], fn($q) => $q->whereIn('compare_status', $compareStatuses));

        $this->applySiteFilter($query, $site);

        $reviewStatuses = DeadstockReviewStatus::valuesForFilter($actionStatus);
        if ($reviewStatuses !== []) {
            $query->where(function ($nested) use ($actionStatus, $reviewStatuses) {
                if ($actionStatus === DeadstockReviewStatus::NOT_FOLLOWED_UP) {
                    $nested->whereDoesntHave('review')
                        ->orWhereHas('review', fn($reviewQuery) => $reviewQuery->where('review_status', 'open'));
                    return;
                }

                $nested->whereHas('review', fn($reviewQuery) => $reviewQuery->whereIn('review_status', $reviewStatuses));
            });
        }

    }

    private function items(): Collection
    {
        return $this->items ??= $this->query()->get();
    }

    private function iterateItems(): iterable
    {
        return $this->items ?? $this->query()->lazy(500);
    }

    private function applySiteFilter($query, string $siteFilter): void
    {
        $site = strtoupper(trim($siteFilter));

        if ($site === 'PLUS') {
            $query->where('company', 'like', '%PLUS%');
            return;
        }

        if ($site === 'WIRE') {
            $query->where(function ($nested) {
                $nested->whereNull('company')
                    ->orWhere('company', 'not like', '%PLUS%');
            });
            return;
        }

        if (!in_array(strtolower(trim($siteFilter)), ['all', ''], true)) {
            $query->where('company', $siteFilter);
        }
    }

    private function applyReasonFilter($query, array $reasonFilter): void
    {
        $reasonCodes = $this->reasonCodesForFilter($reasonFilter);

        $query->where(function ($nested) use ($reasonFilter, $reasonCodes) {
            if ($reasonCodes !== []) {
                $nested->whereIn('deadstock_code', $reasonCodes);
            }

            $nested->orWhereIn('deadstock_desc', $reasonFilter);
        });
    }

    private function reasonCodesForFilter(array $reasonFilter): array
    {
        $reasonMap = DeadstockReasonMap::all();
        $codes = [];

        foreach ($reasonFilter as $filter) {
            $filter = trim((string) $filter);
            if ($filter === '') {
                continue;
            }

            $codeCandidate = trim((string) preg_split('/\s+-\s+/', $filter, 2)[0]);
            foreach ([$filter, $codeCandidate] as $candidate) {
                $candidate = strtoupper(trim((string) $candidate));
                if ($candidate !== '' && array_key_exists($candidate, $reasonMap)) {
                    $codes[] = $candidate;
                }
            }

            foreach ($reasonMap as $code => $description) {
                if ($filter === $description) {
                    $codes[] = $code;
                }
            }
        }

        return array_values(array_unique($codes));
    }

    private function siteLabel(string $company): string
    {
        return stripos($company, 'PLUS') !== false ? 'PLUS' : 'WIRE';
    }

    private function normalizeMultiFilter($value): array
    {
        return collect(is_array($value) ? $value : [$value])
            ->flatten()
            ->map(fn($item) => trim((string) $item))
            ->reject(fn($item) => $item === '' || strtolower($item) === 'all')
            ->unique()
            ->values()
            ->all();
    }

}
