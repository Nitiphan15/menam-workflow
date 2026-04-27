<?php

namespace App\Http\Controllers\FormSSC;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class ShotblastSpareController extends Controller
{
    // CPA code ที่ต้องการดึง
    protected array $partnumbers = [
        'IB0002067B',
        'CC0001718D',
        'LN00001579',
        'LN00001580',
        'LN00001581',
        'LN00001584',
        'GP0WA1660B',
        'DT0001730E',
        'GE00002059',
        'BK00003673',
        'AR0003670A',
        'CO00003671',
        'NT0000234A',
        'BO00000286',
        'TBW005V500',
        'TBWSPA1410',
    ];

    // จำนวนที่ต้องมีสำรอง ตามรูป Excel
    protected array $requiredQty = [
        'IB0002067B' => 8,
        'CC0001718D' => 1,
        'LN00001579' => 1,
        'LN00001580' => 1,
        'LN00001581' => 1,
        'LN00001584' => 1,
        'GP0WA1660B' => 1,
        'DT0001730E' => 1,
        'GE00002059' => 1,
        'BK00003673' => 2,
        'AR0003670A' => 1,
        'CO00003671' => 1,
        'NT0000234A' => 4,
        'BO00000286' => 4,
        'TBW005V500' => 3,
        'TBWSPA1410' => 3,
    ];

    protected array $drawingMap = [
        'IB0002067B' => 'WA-2067B',
        'CC0001718D' => 'WA-1718D',
        'LN00001579' => 'SCB-1579',
        'LN00001580' => 'SCB-1580',
        'LN00001581' => 'SCB-1581',
        'LN00001584' => 'SCB-1584',
        'GP0WA1660B' => 'WA-1660B',
        'DT0001730E' => 'WA-1730',
        'GE00002059' => 'WA-2059',
        'BK00003673' => 'WA-3673',
        'AR0003670A' => 'WA-3670A',
        'CO00003671' => 'WA-3671',
        'NT0000234A' => 'WZ-8-0234A',
        'BO00000286' => 'WZ-8-0286',
        'TBW005V500' => '5V-500',
        'TBWSPA1410' => 'SPA-1410Lw',
    ];

    /**
     * query หลัก (ใช้ซ้ำทั้งหน้าเว็บและ export)
     */
    protected function baseQuery()
    {
        $sub = DB::connection('pgsqlp')->table('gl')
            ->join('partsmvmt as ps', 'gl.id', '=', 'ps.trans_id')
            ->join('parts as p', 'p.id', '=', 'ps.parts_id')
            ->join('employee as ei', 'ei.id', '=', 'gl.employee_id')
            ->whereIn('p.partnumber', $this->partnumbers)
            ->selectRaw('
                p.partnumber,
                p.description,
                p.drawing,
                gl.transdate,
                ps.onhand,
                ei."name" as employee_name,
                gl.notes,
                ROW_NUMBER() OVER (
                    PARTITION BY p.id
                    ORDER BY gl.transdate DESC, gl.id DESC
                ) AS rn
            ');

        return DB::connection('pgsqlp')
            ->query()
            ->fromSub($sub, 't')
            ->where('t.rn', 1)
            ->orderByDesc('t.transdate');
    }

    /**
     * หน้าแสดงรายงาน
     */
    public function index()
    {
        $rows = $this->baseQuery()->get();

        // เติมจำนวนที่ต้องมีสำรอง + ส่วนต่าง
        $rows = $rows->map(function ($r) {
            $r->drawing = $this->drawingMap[$r->partnumber] ?? $r->drawing;
            $r->required_qty = $this->requiredQty[$r->partnumber] ?? null;
            $r->shortage = isset($r->required_qty, $r->onhand)
                ? $r->required_qty - $r->onhand : null;
            return $r;
        });

        return view('formssc.shotblast_spares', [
            'rows'        => $rows,
        ]);
    }

    /**
     * Export เป็น Excel
     */
    public function export()
    {
        $rows = $this->baseQuery()->get()->map(function ($r) {
            $r->drawing = $this->drawingMap[$r->partnumber] ?? $r->drawing;
            $required = $this->requiredQty[$r->partnumber] ?? null;
            $shortage = isset($required, $r->onhand) ? $required - $r->onhand : null;
            return [
                'CPA Code' => $r->partnumber,
                'Name' => $r->description,
                'Drawing No.' => $r->drawing,
                'Required (pcs)' => $required,
                'On hand' => $r->onhand,
                'Shortage' => $shortage,
                'Last trans date' => optional($r->transdate)->format('Y-m-d'),
                'Last used by' => $r->employee_name,
                'Notes' => $r->notes,
            ];
        })->toArray();

        $export = new class($rows) implements FromArray, WithHeadings, WithTitle {
            public function __construct(private array $rows) {}
            public function array(): array
            {
                return $this->rows;
            }
            public function headings(): array
            {
                return [
                    'CPA Code',
                    'Name',
                    'Drawing No.',
                    'Required (pcs)',
                    'On hand',
                    'Shortage',
                    'Last trans date',
                    'Last used by',
                    'Notes'
                ];
            }
            public function title(): string
            {
                return 'Shotblast Spare Parts';
            }
        };

        return Excel::download($export, 'shotblast_spares_stock.xlsx');
    }
}
