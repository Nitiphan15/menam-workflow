<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\{FromArray, WithHeadings, WithTitle};

class WirerodBalanceOverdueSheet implements FromArray, WithHeadings, WithTitle
{
    public function __construct(
        protected array $rows,
        protected int|float $scale = 1
    ) {}

    public function title(): string
    {
        return 'Balance & Overdue';
    }

    public function headings(): array
    {
        return ['Item', 'Company', 'Balance (KG.)', 'Overdue Open (KG.)'];
    }

    public function array(): array
    {
        $out = [];
        foreach ($this->rows as $r) {
            $out[] = [
                $r['item'],
                $r['company'] ?? '',
                (float) ($r['balance'] ?? 0),
                (float) ($r['open'] ?? 0) * $this->scale, // ค้างส่ง * SCALE
            ];
        }
        return $out;
    }
}
