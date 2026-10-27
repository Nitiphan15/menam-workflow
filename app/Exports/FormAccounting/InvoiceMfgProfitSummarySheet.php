<?php

namespace App\Exports\FormAccounting;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InvoiceMfgProfitSummarySheet implements FromArray, WithTitle, WithStyles, ShouldAutoSize
{
    public function __construct(private array $summary, private array $filters) {}
    public function title(): string { return 'Summary'; }
    public function array(): array
    {
        $rows = [
            ['Invoice-MFG Gross Profit'],
            ['ช่วง Invoice Date', $this->filters['date_from'].' ถึง '.$this->filters['date_to']],
            ['Site', $this->filters['site']], [],
            ['Invoices', $this->summary['invoice_count']], ['Rows', $this->summary['line_count']],
            ['MFG', $this->summary['mfg_count']], ['Sales (THB)', $this->summary['sales']],
            ['Cost (THB)', $this->summary['cost']], ['Gross Profit (THB)', $this->summary['gross_profit']],
            ['Margin %', $this->summary['margin']], ['Data Issues', $this->summary['issue_count']], [],
            ['ปี พ.ศ.', 'Rows', 'Sales (THB)', 'Cost (THB)', 'Gross Profit (THB)', 'Margin %'],
        ];
        foreach ($this->summary['years'] as $year) {
            $rows[] = [$year['year'] + 543, $year['rows'], $year['sales'], $year['cost'], $year['gross_profit'], $year['margin']];
        }
        return $rows;
    }
    public function styles(Worksheet $sheet): array
    {
        $sheet->getStyle('A1:F1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A14:F14')->getFont()->setBold(true);
        $sheet->getStyle('B5:B12')->getNumberFormat()->setFormatCode('#,##0.00;[Red](#,##0.00)');
        return [];
    }
}
