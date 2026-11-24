<?php

namespace App\Exports\Po;

use App\Services\Po\PoApprovalReportService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** รายงานการอนุมัติ PO: sheet 1 = one row per PO, sheet 2 = summary, sheet 3 = by department. */
class PoApprovalReportExport implements WithMultipleSheets
{
    public function __construct(private array $report, private string $scopeLabel) {}

    public function sheets(): array
    {
        return [
            $this->detailSheet(),
            $this->summarySheet(),
            $this->departmentSheet(),
        ];
    }

    private function detailSheet(): object
    {
        $rows = array_map(fn (array $row) => [
            $row['ordnumber'],
            $row['site'],
            $row['po_date'],
            $row['department'],
            $row['requester'],
            $row['amount'],
            $row['currency'],
            $row['status_label'] . ($row['current_step_label'] !== '' ? " ({$row['current_step_label']})" : ''),
            $row['submitted_at'],
            $row['purchase_approvers'],
            $row['purchase_hours'],
            $row['department_approvers'],
            $row['department_hours'],
            $row['completed_at'],
            $row['total_hours'],
            $row['within_sla'] === null ? '' : ($row['within_sla'] ? 'ทัน' : 'เกิน'),
            $row['reject_count'],
            $row['waiting_hours'],
            $row['waiting_for'],
        ], $this->report['rows']);

        return $this->sheet('รายงานอนุมัติ', [
            'PO No', 'Site', 'วันที่ PO', 'แผนก', 'ผู้ขอ', 'ยอดเงิน', 'สกุลเงิน', 'สถานะ',
            'Submit', 'Purchase อนุมัติ (ชื่อ เวลา)', 'Purchase (ชม.)',
            'หัวหน้าแผนก อนุมัติ (ชื่อ เวลา)', 'หัวหน้าแผนก (ชม.)',
            'อนุมัติครบ', 'รวม (ชม.)', 'SLA', 'ถูกส่งกลับ (ครั้ง)', 'รอมาแล้ว (ชม.)', 'รอใคร',
        ], $rows, [
            'A' => NumberFormat::FORMAT_TEXT,
            'F' => '#,##0.00',
            'K' => '0.0',
            'M' => '0.0',
            'O' => '0.0',
            'R' => '0.0',
        ]);
    }

    private function summarySheet(): object
    {
        $s = $this->report['summary'];
        $f = $this->report['filters'];
        $hours = fn ($v) => $v === null ? '-' : $v;

        $rows = [
            ['ช่วงวันที่ Submit', $f['date_from'] . ' ถึง ' . $f['date_to']],
            ['ขอบเขต', $this->scopeLabel],
            ['ออกรายงานเมื่อ', $this->report['generated_at']],
            ['', ''],
            ['จำนวน PO ทั้งหมด', $s['total']],
            ['อนุมัติครบ', $s['approved']],
            ['รออนุมัติ', $s['pending']],
            ['ส่งกลับแก้ไข', $s['rejected']],
            ['ยกเลิก', $s['cancelled']],
            ['', ''],
            ['เวลาอนุมัติเฉลี่ย (ชม.) Submit → อนุมัติครบ', $hours($s['avg_hours'])],
            ['ค่ามัธยฐาน (ชม.)', $hours($s['median_hours'])],
            ['เฉลี่ยขั้น Purchase (ชม.)', $hours($s['avg_purchase_hours'])],
            ['เฉลี่ยขั้นหัวหน้าแผนก (ชม.)', $hours($s['avg_department_hours'])],
            ['% ทัน SLA (' . PoApprovalReportService::SLA_HOURS_PER_STEP . ' ชม./ขั้น)', $s['sla_rate'] === null ? '-' : $s['sla_rate'] . '%'],
            ['จำนวน PO ที่นำมาคิดเวลา', $s['measured']],
        ];

        return $this->sheet('สรุป', ['หัวข้อ', 'ค่า'], $rows);
    }

    private function departmentSheet(): object
    {
        $rows = array_map(fn (array $d) => [
            $d['department'], $d['total'], $d['approved'], $d['pending'],
            $d['avg_hours'], $d['avg_purchase_hours'], $d['avg_department_hours'],
        ], $this->report['departments']);

        return $this->sheet('แยกตามแผนก', [
            'แผนก', 'จำนวน PO', 'อนุมัติครบ', 'รออนุมัติ',
            'เฉลี่ยรวม (ชม.)', 'เฉลี่ย Purchase (ชม.)', 'เฉลี่ยหัวหน้าแผนก (ชม.)',
        ], $rows, ['E' => '0.0', 'F' => '0.0', 'G' => '0.0']);
    }

    private function sheet(string $title, array $headings, array $rows, array $formats = []): object
    {
        return new class ($title, $headings, $rows, $formats) implements FromArray, WithHeadings, WithTitle, ShouldAutoSize, WithColumnFormatting, WithStyles {
            public function __construct(
                private string $title,
                private array $headings,
                private array $rows,
                private array $formats,
            ) {}

            public function array(): array
            {
                return $this->rows;
            }

            public function headings(): array
            {
                return $this->headings;
            }

            public function title(): string
            {
                return $this->title;
            }

            public function columnFormats(): array
            {
                return $this->formats;
            }

            public function styles(Worksheet $sheet): array
            {
                $sheet->freezePane('A2');
                $sheet->getStyle($sheet->calculateWorksheetDimension())->getAlignment()->setWrapText(true)->setVertical('top');

                return [1 => ['font' => ['bold' => true]]];
            }
        };
    }
}
