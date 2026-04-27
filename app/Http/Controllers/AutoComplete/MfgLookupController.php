<?php

namespace App\Http\Controllers\AutoComplete;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class MfgLookupController extends Controller
{

    public function byMFG_bk(Request $r)
    {

        $conn = DB::connection('pgsqlw'); // หรือปรับตาม connection ของคุณ
        $term = trim((string) $r->query('q', ''));   // คำที่พิมพ์หา เช่น "W2501"
        $limit = (int) $r->query('limit', 15);

        $base = $conn->table('workorder as wo')
            ->join('parts as p', 'wo.parts_id', '=', 'p.id')
            ->join('partstype as pt', 'p.partstype_id', '=', 'pt.id')
            ->join('partscategory as pc', 'p.partscategory_id', '=', 'pc.id');

        // เลือกคอลัมน์แบบชัดเจน (เลี่ยง p.* เพื่อไม่ให้ซ้ำชื่อ description/id)
        $select = [
            'wo.workordernumber',
            DB::raw('wo.qty AS wo_qty'),
            DB::raw('p.id AS part_id'),
            'p.partnumber',
            DB::raw('p.description AS part_desc'),
            DB::raw('p.f1 AS size'),
            DB::raw('p.f2 AS length'),
            DB::raw('p.f3 AS grade'),
            'p.f4',
            'p.f5',
            'wo.id',
            DB::raw('pt.description AS partstype_desc'),
            DB::raw('pc.description AS partscategory_desc'),
        ];

        $rows = $base->select($select)
            ->when($term !== '', function ($q) use ($term) {
                // PostgreSQL: ใช้ ILIKE ให้ค้นแบบ case-insensitive + prefix match
                $q->where('wo.workordernumber', 'ilike', $term . '%');
            })
            ->orderBy('wo.workordernumber')
            ->limit($limit)
            ->get();

        // แปลงเป็น payload ที่เหมาะกับ autocomplete (เช่น Select2)
        $results = $rows->map(function ($r) {
            $label = sprintf(
                '%s | %s — %s (size: %s, len: %s, grade: %s)',
                $r->workordernumber,
                $r->partnumber,
                $r->part_desc,
                $r->size,
                $r->length,
                $r->grade
            );

            return [
                'id'   => $r->workordernumber,
                'text' => $label,
                'meta' => [
                    'wo_qty'    => (float) $r->wo_qty,
                    'part_id'   => (int) $r->part_id,
                    'part_desc' => $r->part_desc,
                    'size'      => $r->size,
                    'length'    => $r->length,
                    'grade'     => $r->grade,
                    'f4'        => $r->f4,
                    'f5'        => $r->f5,
                    'type'      => $r->partstype_desc,
                    'category'  => $r->partscategory_desc,
                ],
            ];
        });

        return response()->json([
            'results' => $results,
            // 'pagination' => ['more' => true], // ถ้ามีทำ paging
        ]);
    }

    public function byMFG(Request $r)
    {

        $term  = trim((string) $r->query('q', ''));   // เช่น "W2501"
        $limit = (int) $r->query('limit', 15);

        // เลือกคอลัมน์แบบชัดเจน (กันชนกันชื่อคอลัมน์ซ้ำ)
        $select = [
            'wo.workordernumber',
            DB::raw('wo.qty AS wo_qty'),
            DB::raw('p.id AS part_id'),
            'p.partnumber',
            DB::raw('p.description AS part_desc'),
            DB::raw('p.f1 AS size'),
            DB::raw('p.f2 AS length'),
            DB::raw('p.f3 AS grade'),
            'p.f4',
            'p.f5',
            'wo.id',
            DB::raw('pt.description AS partstype_desc'),
            DB::raw('pc.description AS partscategory_desc'),
        ];

        // ฟังก์ชันช่วยยิง query ต่อ DB ใด ๆ แล้วผนวกคอลัมน์ site
        $fetchFrom = function (\Illuminate\Database\ConnectionInterface $conn, string $siteLabel) use ($select, $term, $limit) {
            return $conn->table('workorder as wo')
                ->join('parts as p', 'wo.parts_id', '=', 'p.id')
                ->join('partstype as pt', 'p.partstype_id', '=', 'pt.id')
                ->join('partscategory as pc', 'p.partscategory_id', '=', 'pc.id')
                ->select($select)
                ->selectRaw('? as site', [$siteLabel])        // เพิ่มคอลัมน์ site ให้รู้ว่าแหล่งไหน
                ->when($term !== '', function ($q) use ($term) {
                    $q->where('wo.workordernumber', 'ilike', $term . '%'); // ค้น prefix case-insensitive (Postgres)
                })
                ->orderBy('wo.workordernumber')
                ->limit($limit)   // ตัดยอดต่อแหล่งก่อน
                ->get();
        };

        // ยิงทั้งสองแหล่ง
        $rowsW = $fetchFrom(DB::connection('pgsqlw'), 'Wire');
        $rowsP = $fetchFrom(DB::connection('pgsqlp'), 'Plus');

        // รวมผล, จัดเรียงโดย workordernumber อีกรอบ แล้วคัด limit อีกครั้งหลังรวม
        $rows = $rowsW
            ->concat($rowsP)
            ->sortBy('workordernumber')
            ->values()
            ->take($limit);

        // map เป็น payload สำหรับ autocomplete (เช่น Select2)
        $results = $rows->map(function ($r) {
            $label = sprintf(
                '[%s] %s | %s — %s (size: %s, len: %s, grade: %s)',
                $r->site,
                $r->workordernumber,
                $r->partnumber,
                $r->part_desc,
                $r->size,
                $r->length,
                $r->grade
            );

            return [
                'id'   => $r->workordernumber, // ถ้าต้องการไม่ชนกันระหว่าง site อาจใช้ "{$r->site}|{$r->workordernumber}"
                'text' => $label,
                'meta' => [
                    'site'       => $r->site,
                    'wo_qty'     => (float) $r->wo_qty,
                    'part_id'    => (int) $r->part_id,
                    'part_desc'  => $r->part_desc,
                    'size'       => $r->size,
                    'length'     => $r->length,
                    'grade'      => $r->grade,
                    'f4'         => $r->f4,
                    'f5'         => $r->f5,
                    'type'       => $r->partstype_desc,
                    'category'   => $r->partscategory_desc,
                ],
            ];
        });

        return response()->json([
            'results' => $results,
            // 'pagination' => ['more' => false], // ถ้ามีทำ infinite scroll ค่อยใส่
        ]);
    }
}
