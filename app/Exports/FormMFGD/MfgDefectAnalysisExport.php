<?php

namespace App\Exports\FormMFGD;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;

class MfgDefectAnalysisExport implements FromArray, WithHeadings, ShouldAutoSize, WithEvents
{
    public function __construct(private Collection $rows)
    {
    }

    public function headings(): array
    {
        return [
            'โรงงาน',
            'วันที่',
            'รายการเลขที่',
            'จำนวนสั่งผลิต',
            'เบิกวัตถุดิบ',
            'ของดี',
            'ของเสีย',
            'เปอร์เซ็นต์ของเสีย',
            'คืนวัตถุดิบ',
            'Balance',
            'รหัสสินค้า',
            'ชื่อสินค้า',
            'ประเภทสินค้า',
            'รหัสกลุ่มสินค้า',
            'กลุ่มสินค้า',
            'รหัสหมวดสินค้า',
            'หมวดสินค้า',
            'เลขที่คำสั่งขาย',
            'รหัสลูกค้า',
            'ชื่อลูกค้า',
            'กำหนดส่ง',
            'วันที่เบิก',
            'Packaging',
        ];
    }

    public function array(): array
    {
        return $this->rows
            ->map(fn($row) => [
                $row->site ?? '',
                $this->dateText($row->document_date ?? null),
                $row->workorder_no ?? '',
                (float) ($row->order_qty ?? 0),
                (float) ($row->issued_qty ?? 0),
                (float) ($row->good_qty ?? 0),
                (float) ($row->defect_qty ?? 0),
                (float) ($row->defect_pct ?? 0),
                (float) ($row->return_rm_qty ?? 0),
                (float) ($row->balance_qty ?? 0),
                $row->part_no ?? '',
                $row->part_name ?? '',
                $row->part_type ?? '',
                $row->group_code ?? '',
                $row->group_name ?? '',
                $row->category_code ?? '',
                $row->category_name ?? '',
                $row->sales_order_no ?? '',
                $row->customer_code ?? '',
                $row->customer_name ?? '',
                $this->dateText($row->due_date ?? null),
                $this->dateText($row->issued_at ?? null, true),
                $row->packaging ?? '',
            ])
            ->all();
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastRow = max(1, $sheet->getHighestRow());

                $sheet->freezePane('A2');
                $sheet->setAutoFilter("A1:W{$lastRow}");
                $sheet->getStyle('A1:W1')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['ARGB' => 'FFFFFFFF']],
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['ARGB' => 'FF1E293B'],
                    ],
                ]);
                $sheet->getStyle("D2:J{$lastRow}")
                    ->getNumberFormat()
                    ->setFormatCode('#,##0.00');
            },
        ];
    }

    private function dateText($value, bool $withTime = false): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '';
        }

        try {
            return \Carbon\Carbon::parse($value)->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d');
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
