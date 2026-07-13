<?php

namespace App\Exports\FormDIE;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class DieTrackingExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $rows) {}

    public function headings(): array
    {
        return [
            'Workorder', 'WorkSeq', 'Workcenter', 'Workcenter Desc',
            'Block No', 'Block Name', 'Block Size',
            'Trans#', 'Trans Date', 'Block Desc',
            'Machine', 'Issued By', 'Requested By', 'Qty', 'FG kg (WO)',
            'Die#', 'Die Description', 'Equip Type', 'Equip Category',
            'Size In', 'Size Out', 'Extra F3', 'Extra F4',
            'Status', 'From Dept', 'To Dept',
        ];
    }

    public function array(): array
    {
        return array_map(fn($r) => [
            $r['workordernumber'] ?? '',
            $r['workseq']         ?? '',
            $r['workcenter']      ?? '',
            $r['workcenter_desc'] ?? '',
            $r['block_no']        ?? '',
            $r['block_name']      ?? '',
            $r['block_size']      ?? '',
            $r['transnumber']     ?? '',
            $r['transdate']       ?? '',
            $r['block_desc']      ?? '',
            $r['used_machine']     ?? ($r['machine_number'] ?? ''),
            $r['issued_by_name']   ?? ($r['updated_by_name'] ?? ''),
            $r['requester_name']   ?? '',
            $r['qty']             ?? '',
            $r['wo_fg_kg']        ?? '',
            $r['equipnumber']     ?? '',
            $r['die_description'] ?? '',
            $r['equiptype']       ?? '',
            $r['equipcategory']   ?? '',
            $r['size_in']         ?? '',
            $r['size_out']        ?? '',
            $r['extra_f3']        ?? '',
            $r['extra_f4']        ?? '',
            $r['status']          ?? '',
            $r['from_class']      ?? '',
            $r['to_class']        ?? '',
        ], $this->rows);
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
