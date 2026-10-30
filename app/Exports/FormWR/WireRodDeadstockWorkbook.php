<?php

namespace App\Exports\FormWR;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class WireRodDeadstockWorkbook implements WithMultipleSheets
{
    public function __construct(private array $filters) {}

    public function sheets(): array
    {
        return [
            new WireRodDeadstockItemSummarySheet($this->filters),
            new WireRodDeadstockSerialDetailSheet($this->filters),
        ];
    }
}
