<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\{FromArray, WithHeadings, WithTitle};

class WirerodDetailSheet implements FromArray, WithHeadings, WithTitle
{
    public function __construct(
        protected $lines,       // collection/array ของบรรทัด not-due (qty/received/open ถูกคูณ SCALE แล้ว)
        protected array $totals // สรุปรวม
    ) {}

    public function title(): string
    {
        return 'PO Not Due (Detail)';
    }

    public function headings(): array
    {
        return [
            'PO No',
            'PO Date',
            'Required Date',
            'Vendor',
            'Part Number',
            'Description',
            'Qty Ordered (KG.)',
            'Received (KG.)',
            'Open (KG.)',
            'Price/Unit (THB)',
            'Open Value (THB)'
        ];
    }

    public function array(): array
    {
        $out = [];
        foreach ($this->lines as $l) {
            $out[] = [
                $l['po_no'],
                \Illuminate\Support\Carbon::parse($l['po_date'])->format('Y-m-d'),
                \Illuminate\Support\Carbon::parse($l['req_date'])->format('Y-m-d'),
                $l['vendor'],
                $l['rm_partnumber'],
                $l['description'],
                (float) ($l['qty'] ?? 0),
                (float) ($l['received'] ?? 0),
                (float) ($l['open'] ?? 0),
                (float) ($l['price_thb'] ?? 0),        // ราคา/หน่วย ไม่คูณ SCALE
                (float) ($l['open'] ?? 0) * (float) ($l['price_thb'] ?? 0), // มูลค่าค้างรับ = open(คูณแล้ว) * price
            ];
        }

        // เพิ่มแถวรวมท้ายไฟล์ (optional)
        $out[] = [];
        $out[] = [
            'TOTAL',
            '',
            '',
            '',
            '',
            '',
            (float) ($this->totals['qty'] ?? 0),
            (float) ($this->totals['received'] ?? 0),
            (float) ($this->totals['open'] ?? 0),
            '',
            (float) ($this->totals['open_value_thb'] ?? 0)
        ];

        return $out;
    }
}
