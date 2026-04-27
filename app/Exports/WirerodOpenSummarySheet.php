<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\{FromArray, WithHeadings, WithTitle};

class WirerodOpenSummarySheet implements FromArray, WithHeadings, WithTitle
{
    public function __construct(
        protected array $rows,
        protected array $monthOrder,
        protected array $monthLabels
    ) {}

    public function title(): string
    {
        return 'Open (Not Due) by Item';
    }

    public function headings(): array
    {
        $heads = ['Item', 'Description'];
        foreach ($this->monthOrder as $m) {
            $heads[] = $this->monthLabels[$m] ?? 'M' . $m;
        }
        $heads[] = 'Total';
        return $heads;
    }

    public function array(): array
    {
        $out = [];
        foreach ($this->rows as $r) {
            $line = [$r['item'], $r['description'] ?? ''];
            foreach ($this->monthOrder as $m) {
                $line[] = $r['by_month'][$m] ?? 0;
            }
            $line[] = $r['total'] ?? 0;
            $out[] = $line;
        }
        return $out;
    }
}
