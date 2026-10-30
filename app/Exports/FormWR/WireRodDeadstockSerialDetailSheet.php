<?php

namespace App\Exports\FormWR;

use App\Services\FormWR\WireRodDeadstockQuery;
use App\Support\FormWR\CsvExportCell;
use Illuminate\Database\Query\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class WireRodDeadstockSerialDetailSheet implements FromQuery, WithHeadings, WithMapping, WithTitle, WithStyles, ShouldAutoSize
{
    public function __construct(private array $filters) {}

    public function query(): Builder
    {
        return app(WireRodDeadstockQuery::class)->current($this->filters)
            ->leftJoin('wr_ds_item_reviews as export_review', 'export_review.lifecycle_id', '=', 'c.lifecycle_id')
            ->addSelect('lifecycle.cycle_no', 'export_review.review_status', 'export_review.next_follow_up_date',
                'export_review.review_detail', 'export_review.corrective_action', 'export_review.preventive_action', 'export_review.sales_remark')
            ->selectSub(function ($query) {
                $query->from('wr_ds_customer_additions as extra_customer')
                    ->whereColumn('extra_customer.lifecycle_id', 'c.lifecycle_id')->where('extra_customer.is_active', 1)
                    ->selectRaw("STRING_AGG(CONVERT(NVARCHAR(MAX), extra_customer.customer_name), N' | ')");
            }, 'additional_customers')
            ->orderBy('c.site_code')->orderBy('c.material_group')->orderBy('c.part_number')->orderBy('c.serial_number');
    }

    public function headings(): array
    {
        return ['Site', 'Material Group', 'Part Number', 'Description', 'Serial / Heat', 'Cycle', 'Purchase Date',
            'Age Days', 'Stock Status', 'Qty', 'Unit', 'Unit Cost THB', 'Value THB', 'Customer from CPA',
            'Additional Customer', 'Sales', 'Division', 'Trace Status', 'Mapping Source', 'Review Status',
            'Next Follow-up', 'Review Detail', 'Corrective Action', 'Preventive Action', 'Sales Remark', 'Data Retrieved At'];
    }

    public function map($row): array
    {
        $jsonList = static fn ($json) => collect(json_decode($json ?: '[]', true))->filter()->unique()->implode(' | ');
        return CsvExportCell::row([
            $row->site_code, $row->material_group, $row->part_number, $row->part_description, $row->serial_number,
            $row->cycle_no, $row->purchase_date, $row->stock_age_days, $row->stock_status, $row->current_qty,
            $row->display_unit, $row->unitcost_thb, $row->current_value_thb, $jsonList($row->customer_json) ?: 'Stock',
            $row->additional_customers, $jsonList($row->sales_json), $row->effective_division_code, $row->trace_status,
            $row->mapping_source, $row->review_status ?: 'open', $row->next_follow_up_date, $row->review_detail,
            $row->corrective_action, $row->preventive_action, $row->sales_remark, $row->source_retrieved_at,
        ]);
    }

    public function title(): string { return 'Serial Detail'; }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
        $sheet->getStyle('A1:Z1')->getFont()->setBold(true);
        $sheet->getStyle('J:M')->getNumberFormat()->setFormatCode('#,##0.0000;[Red](#,##0.0000)');
        return [];
    }
}
