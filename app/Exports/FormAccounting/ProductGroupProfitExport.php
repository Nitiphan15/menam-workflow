<?php

namespace App\Exports\FormAccounting;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProductGroupProfitExport implements FromCollection, WithHeadings, WithStyles, ShouldAutoSize
{
    public function __construct(private array $data) {}

    public function headings(): array
    {
        return ['Production Site','กลุ่มสินค้า','วันที่','เลขที่เอกสาร','Customer ID','MFG','Part Number','รายการ','หน่วย','จำนวน','ราคาขาย/หน่วย (THB)','จำนวนเงิน (THB)','ต้นทุนต่อหน่วย','ต้นทุน (THB)','กำไรขั้นต้น (THB)','กำไรขั้นต้น (%)','Data Issue'];
    }

    public function collection(): Collection
    {
        $output = collect();
        foreach ($this->data['rows']->groupBy(fn ($row) => $row->production_site.'|'.$row->product_group) as $rows) {
            foreach ($rows as $row) {
                $output->push([$row->production_site,$row->product_group,$row->invoice_date,$row->invoice_no,$row->customer_id,$row->mfg,$row->partnumber,$row->item_description,$row->unit,$row->quantity,$row->unit_price,$row->sales_thb,$row->unit_cost,$row->total_cost,$row->gross_profit,$row->margin,$row->data_issue]);
            }
            $sales = (float) $rows->sum('sales_thb');
            $cost = $rows->every(fn ($row) => $row->total_cost !== null) ? (float) $rows->sum('total_cost') : null;
            $profit = $cost === null ? null : $sales - $cost;
            $output->push([$rows->first()->production_site,'รวม '.$rows->first()->product_group,'','','','','','','',(float)$rows->sum('quantity'),'',$sales,'',$cost,$profit,$sales != 0.0 && $profit !== null ? ($profit/$sales)*100 : null,'']);
        }
        $summary = $this->data['summary'];
        $output->push(['GRAND TOTAL','','','','','','','','','', '',$summary['sales'],'',$summary['cost'],$summary['gross_profit'],$summary['margin'],'']);
        return $output;
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
        $sheet->getStyle('A1:Q1')->getFont()->setBold(true);
        $sheet->getStyle('J:P')->getNumberFormat()->setFormatCode('#,##0.00;[Red](#,##0.00)');
        return [];
    }
}
