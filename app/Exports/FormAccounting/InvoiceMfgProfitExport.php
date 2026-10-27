<?php

namespace App\Exports\FormAccounting;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class InvoiceMfgProfitExport implements WithMultipleSheets
{
    public function __construct(private array $data) {}

    public function sheets(): array
    {
        $sheets = [new InvoiceMfgProfitSummarySheet($this->data['summary'], $this->data['filters'])];
        foreach ($this->data['byYear'] as $year => $rows) {
            $sheets[] = new InvoiceMfgProfitYearSheet((int) $year, $rows);
        }
        $sheets[] = new InvoiceMfgProfitIssuesSheet($this->data['issues']);
        return $sheets;
    }
}
