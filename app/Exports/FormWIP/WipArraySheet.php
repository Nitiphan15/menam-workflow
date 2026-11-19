<?php

namespace App\Exports\FormWIP;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Sheet ทั่วไปจาก array — string ทุกตัวเป็น text (กัน Excel แปลงเลข WO/SO และกันสูตร)
 * WithStrictNullComparison: ให้ค่า 0 แสดงเป็น 0 ไม่ใช่ช่องว่าง
 */
class WipArraySheet extends DefaultValueBinder implements FromArray, WithTitle, WithCustomValueBinder, WithStrictNullComparison, WithStyles, ShouldAutoSize
{
    public function __construct(
        private string $title,
        private array $rows,
        private array $headerRows = [1],
        private array $numberCols = [],
        private ?string $freeze = 'B2',
    ) {
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function bindValue(Cell $cell, $value)
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function styles(Worksheet $sheet)
    {
        $lastRow = max(1, count($this->rows));
        foreach ($this->numberCols as $col) {
            $sheet->getStyle("{$col}2:{$col}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        }

        if ($this->freeze) {
            $sheet->freezePane($this->freeze);
            $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
        }

        $header = ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '155E75']]];
        $styles = [];
        foreach ($this->headerRows as $r) {
            $styles[$r] = $header;
        }

        return $styles;
    }
}
