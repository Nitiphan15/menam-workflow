<?php

namespace App\Http\Controllers\AutoComplete;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PartnumberLookupController extends Controller
{
    public function byPartnumber(Request $r)
    {
        $term = trim((string) $r->query('q', ''));

        try {
            // ฟังก์ชัน query ซ้ำๆ สำหรับแต่ละ connection
            $fetch = function (string $connName) use ($term) {
                $conn   = DB::connection($connName);
                $driver = $conn->getDriverName();
                $isPg   = ($driver === 'pgsql');

                $q = $conn->table('parts')
                    ->selectRaw("id, partnumber, description")
                    ->where(function ($qq) {
                        $qq->where('active', true)
                            ->orWhere('active', 1)
                            ->orWhere('active', 't');
                    })
                    ->when($term !== '', function ($qq) use ($term, $isPg) {
                        if ($isPg) {
                            $qq->whereRaw('partnumber ILIKE ?', ["%{$term}%"]);
                        } else {
                            $qq->where('partnumber', 'like', "%{$term}%");
                        }
                    })
                    ->orderBy('partnumber')
                    ->limit(50); // ดึงมากกว่า 20 เผื่อ merge แล้วค่อยตัด

                $rows = $q->get();

                // ใส่ source ไว้รู้ว่ามาจาก DB ไหน (optional)
                return $rows->map(function ($p) use ($connName) {
                    return [
                        'source' => $connName, // 'pgsqlw' / 'pgsqlp'
                        'id'     => $p->id,
                        'value'  => $p->partnumber,
                        'label'  => trim(($p->partnumber ?? '') . ' :: ' . ($p->description ?? '')),
                        'desc'   => $p->description,
                    ];
                });
            };

            // ยิง 2 DB
            $wire = $fetch('pgsqlw');
            $plus = $fetch('pgsqlp');

            // รวม + ตัดซ้ำ (ยึด partnumber เป็น key) + sort + limit 20
            $merged = $wire
                ->concat($plus)
                ->unique(fn($x) => strtoupper(trim($x['value'] ?? ''))) // กัน partnumber ซ้ำข้าม DB
                ->sortBy(fn($x) => strtoupper(trim($x['value'] ?? '')))
                ->values()
                ->take(20);

            return response()->json($merged);
        } catch (\Throwable $e) {
            Log::error('api.parts.search failed', ['err' => $e->getMessage()]);
            return response()->json(['message' => 'Server Error'], 500);
        }
    }
}
