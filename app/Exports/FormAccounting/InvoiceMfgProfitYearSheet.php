<?php

namespace App\Exports\FormAccounting;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InvoiceMfgProfitYearSheet implements FromCollection, WithHeadings, WithMapping, WithTitle, WithStyles, ShouldAutoSize
{
    public function __construct(private int $year, private Collection $rows) {}
    public function title(): string { return (string) ($this->year + 543); }
    public function collection(): Collection { return $this->rows; }
    public function headings(): array
    {
        return ['Site','วันที่','Invoice','Sales Order','SO Item ID','MFG','Customer Code','ลูกค้า','Ship-to','Part Number','รายการ','หน่วย','จำนวน','สกุลเงิน','ราคาขาย/หน่วย','จำนวนเงินเดิม','Exchange Rate','จำนวนเงิน THB','ต้นทุน/หน่วย','ต้นทุน','กำไรขั้นต้น','Margin %','Data Issue'];
    }
    public function map($r): array
    {
        return [$r->site,$r->invoice_date,$r->invoice_no,$r->sales_order,$r->sales_order_item_id,$r->mfg,$r->customer_code,$r->customer_name,$r->ship_to,$r->partnumber,$r->item_description,$r->unit,$r->quantity,$r->currency,$r->unit_price,$r->sales_original,$r->exchange_rate,$r->sales_thb,$r->unit_cost,$r->total_cost,$r->gross_profit,$r->margin,$r->data_issue];
    }
    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2'); $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
        $sheet->getStyle('A1:W1')->getFont()->setBold(true);
        $sheet->getStyle('M:V')->getNumberFormat()->setFormatCode('#,##0.00;[Red](#,##0.00)');
        return [];
    }
}
