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

class WireRodDeadstockItemSummarySheet implements FromQuery, WithHeadings, WithMapping, WithTitle, WithStyles, ShouldAutoSize
{
    public function __construct(private array $filters) {}

    public function query(): Builder
    {
        return app(WireRodDeadstockQuery::class)->current($this->filters)
            ->select('c.site_code', 'c.material_group', 'c.part_number')
            ->selectRaw('MAX(c.part_description) AS part_description')
            ->selectRaw('COUNT_BIG(*) AS serial_count')
            ->selectRaw("SUM(CASE WHEN c.stock_status = 'DEAD' THEN 1 ELSE 0 END) AS dead_serial_count")
            ->selectRaw("SUM(CASE WHEN c.display_unit = 'KG' THEN c.current_qty ELSE 0 END) AS qty_kg")
            ->selectRaw('SUM(COALESCE(c.current_value_thb, 0)) AS value_thb')
            ->groupBy('c.site_code', 'c.material_group', 'c.part_number')
            ->orderBy('c.site_code')->orderBy('c.material_group')->orderBy('c.part_number');
    }

    public function headings(): array
    {
        return ['Site', 'Material Group', 'Part Number', 'Description', 'Serial Count', 'Dead Serial Count', 'Qty KG', 'Value THB'];
    }

    public function map($row): array
    {
        return CsvExportCell::row([$row->site_code, $row->material_group, $row->part_number, $row->part_description,
            $row->serial_count, $row->dead_serial_count, $row->qty_kg, $row->value_thb]);
    }

    public function title(): string { return 'Item Summary'; }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);
        $sheet->getStyle('E:H')->getNumberFormat()->setFormatCode('#,##0.0000;[Red](#,##0.0000)');
        return [];
    }
}
