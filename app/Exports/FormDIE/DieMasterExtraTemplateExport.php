<?php

namespace App\Exports\FormDIE;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class DieMasterExtraTemplateExport implements FromArray, WithHeadings, WithColumnFormatting
{
    public function headings(): array
    {
        return ['Site', 'Die#', 'วันที่รับเข้า', 'หมายเหตุ'];
    }

    public function array(): array
    {
        return [];
    }

    public function columnFormats(): array
    {
        return [
            'B' => NumberFormat::FORMAT_TEXT,
            'C' => NumberFormat::FORMAT_DATE_YYYYMMDD,
        ];
    }
}
