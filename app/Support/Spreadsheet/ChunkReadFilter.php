<?php

namespace App\Support\Spreadsheet;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class ChunkReadFilter implements IReadFilter
{
    public function __construct(
        private int $startRow,
        private int $endRow
    ) {
    }

    public function readCell($column, $row, $worksheetName = ''): bool
    {
        return $row === 1 || ($row >= $this->startRow && $row <= $this->endRow);
    }
}
