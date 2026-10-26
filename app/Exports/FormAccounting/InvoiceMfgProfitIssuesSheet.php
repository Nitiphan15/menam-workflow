<?php

namespace App\Exports\FormAccounting;

use Illuminate\Support\Collection;

class InvoiceMfgProfitIssuesSheet extends InvoiceMfgProfitYearSheet
{
    public function __construct(Collection $rows) { parent::__construct(0, $rows); }
    public function title(): string { return 'Data Issues'; }
}
