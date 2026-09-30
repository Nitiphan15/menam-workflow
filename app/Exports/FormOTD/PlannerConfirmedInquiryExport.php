<?php

namespace App\Exports\FormOTD;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class PlannerConfirmedInquiryExport implements WithMultipleSheets
{
    public function __construct(private array $filters = []) {}

    public function sheets(): array
    {
        return [
            new InquiryShipDateSheet(
                InquiryShipDateSheet::CONFIRM_BY_PLANNER_SHEET,
                $this->filters
            ),
        ];
    }
}
