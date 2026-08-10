<?php

namespace App\Http\Controllers\AutoComplete;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Support\SqlServerDb;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MfgLookupController extends Controller
{

    public function byMFG_bk(Request $r)
    {

        $conn = DB::connection($this->firstAvailableConnection(['pgmfgsqlw', 'pgmgfsqlw', 'pgsqlmfgw', 'pgsqlw']));
        $term = trim((string) $r->query('q', ''));   // คำที่พิมพ์หา เช่น "W2501"
        $limit = (int) $r->query('limit', 15);

        $base = $conn->table('workorder as wo')
            ->join('parts as p', 'wo.parts_id', '=', 'p.id');

        // เลือกคอลัมน์แบบชัดเจน (เลี่ยง p.* เพื่อไม่ให้ซ้ำชื่อ description/id)
        $select = [
            'wo.workordernumber',
            DB::raw('wo.qty AS wo_qty'),
            DB::raw('p.id AS part_id'),
            'p.partnumber',
            'p.unit',
            DB::raw('NULL AS ref_unit_qty'),
            DB::raw('p.description AS part_desc'),
            DB::raw('p.f1 AS size'),
            DB::raw('p.f2 AS length'),
            DB::raw('p.f3 AS grade'),
            'p.f4',
            'p.f5',
            DB::raw('wo.id AS workorder_id'),
            DB::raw('wo.notes AS wo_notes'),
            DB::raw('NULL AS partstype_desc'),
            DB::raw('NULL AS partscategory_desc'),
        ];

        $rows = $base->select($select)
            ->where('wo.workordernumber', 'ilike', 'G%')
            ->when($term !== '', function ($q) use ($term) {
                // PostgreSQL: ใช้ ILIKE ให้ค้นแบบ case-insensitive + prefix match
                    $q->where(function ($sub) use ($term) {
                        $sub->where('wo.workordernumber', 'ilike', $term . '%')
                            ->orWhere('p.partnumber', 'ilike', $term . '%');
                    });
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
                    'workorder_id' => (int) $r->workorder_id,
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
        return $this->searchMfg($r, ['G'], true);
    }

    public function byWocrMFG(Request $r)
    {
        return $this->searchMfg($r, [], false);
    }

    private function searchMfg(Request $r, array $prefixes, bool $searchPartnumber)
    {

        $term  = trim((string) $r->query('q', ''));   // เช่น "W2501"
        $limit = (int) $r->query('limit', 15);

        // เลือกคอลัมน์แบบชัดเจน (กันชนกันชื่อคอลัมน์ซ้ำ)
        $select = [
            'wo.workordernumber',
            DB::raw('wo.qty AS wo_qty'),
            DB::raw('p.id AS part_id'),
            'p.partnumber',
            'p.unit',
            DB::raw('NULL AS ref_unit_qty'),
            DB::raw('p.description AS part_desc'),
            DB::raw('p.f1 AS size'),
            DB::raw('p.f2 AS length'),
            DB::raw('p.f3 AS grade'),
            'p.f4',
            'p.f5',
            DB::raw('wo.id AS workorder_id'),
            DB::raw('wo.notes AS wo_notes'),
            DB::raw('NULL AS partstype_desc'),
            DB::raw('NULL AS partscategory_desc'),
        ];

        // ฟังก์ชันช่วยยิง query ต่อ DB ใด ๆ แล้วผนวกคอลัมน์ site
        $fetchFrom = function (\Illuminate\Database\ConnectionInterface $conn, string $siteLabel) use ($select, $term, $limit, $prefixes, $searchPartnumber) {
            return $conn->table('workorder as wo')
                ->join('parts as p', 'wo.parts_id', '=', 'p.id')
                ->select($select)
                ->addSelect(DB::raw('NULL AS plan_description'))
                ->selectRaw('? as site', [$siteLabel])        // เพิ่มคอลัมน์ site ให้รู้ว่าแหล่งไหน
                ->when($prefixes !== [], function ($q) use ($prefixes) {
                    $q->where(function ($sub) use ($prefixes) {
                        foreach ($prefixes as $prefix) {
                            $sub->orWhere('wo.workordernumber', 'ilike', $prefix . '%');
                        }
                    });
                })
                ->when($term !== '', function ($q) use ($term, $searchPartnumber) {
                    $q->where(function ($sub) use ($term, $searchPartnumber) {
                        $sub->where('wo.workordernumber', 'ilike', $term . '%');

                        if ($searchPartnumber) {
                            $sub->orWhere('p.partnumber', 'ilike', $term . '%');
                        }
                    });
                })
                ->orderBy('wo.workordernumber')
                ->limit($limit)   // ตัดยอดต่อแหล่งก่อน
                ->get();
        };

        $safeFetchFrom = function (array $connectionNames, string $siteLabel) use ($fetchFrom) {
            try {
                return $fetchFrom(DB::connection($this->firstAvailableConnection($connectionNames)), $siteLabel);
            } catch (\Throwable $e) {
                report($e);

                return collect();
            }
        };

        // ยิงทั้งสองแหล่ง โดยใช้ชื่อ connection ใหม่ก่อน และ fallback ชื่อเดิมใน dev
        $rowsW = $safeFetchFrom(['pgsqlmfgw', 'pgmfgsqlw', 'pgmgfsqlw', 'pgsqlw'], 'Wire');
        $rowsP = $safeFetchFrom(['pgsqlmfgp', 'pgmgfsqlp', 'pgmfgsqlp', 'pgsqlp'], 'Plus');

        // รวมผล, จัดเรียงโดย workordernumber อีกรอบ แล้วคัด limit อีกครั้งหลังรวม
        $rows = $rowsW
            ->concat($rowsP)
            ->sortBy('workordernumber')
            ->values()
            ->take($limit);

        $planDescriptions = $this->fetchPlanDescriptions($rows);

        // map เป็น payload สำหรับ autocomplete (เช่น Select2)
        $results = $rows->map(function ($r) use ($planDescriptions) {
            $dimension = $this->parseAreaDimension($r->part_desc);
            $planDescription = $planDescriptions[$this->planLookupKey($r->site, $r->workorder_id)] ?? ($r->plan_description ?? null);
            $notesMeta = $this->parseWorkorderNotes(trim((string) $r->wo_notes . "\n" . (string) $planDescription));
            $planQtyPcs = $this->parsePlanQtyPcs($planDescription);
            $label = sprintf(
                '[%s] %s | %s | Qty %s | %s',
                $r->site,
                $r->workordernumber,
                $r->partnumber,
                number_format((float) $r->wo_qty, 3),
                $r->part_desc
            );

            return [
                'id'   => $r->workordernumber, // ถ้าต้องการไม่ชนกันระหว่าง site อาจใช้ "{$r->site}|{$r->workordernumber}"
                'text' => $label,
                'meta' => [
                    'site'       => $r->site,
                    'wo_qty'     => (float) $r->wo_qty,
                    'part_id'    => (int) $r->part_id,
                    'workorder_id' => (int) $r->workorder_id,
                    'partnumber' => $r->partnumber,
                    'part_desc'  => $r->part_desc,
                    'unit'       => $r->unit,
                    'ref_unit_qty' => $r->ref_unit_qty === null ? null : (float) $r->ref_unit_qty,
                    'plan_qty_pcs' => $planQtyPcs,
                    'project'    => $notesMeta['project'],
                    'salesorder' => $notesMeta['salesorder'],
                    'wo_notes'   => $r->wo_notes,
                    'plan_description' => $planDescription,
                    'size'       => $r->size,
                    'length'     => $r->length,
                    'grade'      => $r->grade,
                    'width_mm'   => $dimension['width_mm'],
                    'length_mm'  => $dimension['length_mm'],
                    'sqm_per_piece' => $dimension['sqm_per_piece'],
                    'area_label' => $dimension['area_label'],
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

    public function gratingProjects(Request $r)
    {
        $term = trim((string) $r->query('q', ''));
        $limit = max(1, min((int) $r->query('limit', 15), 50));

        $masterRows = $this->fetchMasterProjects($term, $limit);

        $rows = collect()
            ->concat($this->fetchProjectRows(['pgsqlmfgw', 'pgmfgsqlw', 'pgmgfsqlw', 'pgsqlw'], 'Wire', $term, $limit))
            ->concat($this->fetchProjectRows(['pgsqlmfgp', 'pgmgfsqlp', 'pgmfgsqlp', 'pgsqlp'], 'Plus', $term, $limit));

        $noteResults = $rows
            ->map(function ($row) {
                $notesMeta = $this->parseWorkorderNotes($row->wo_notes ?? null);
                $project = trim((string) ($notesMeta['project'] ?? ''));

                if ($project === '') {
                    return null;
                }

                return [
                    'id' => $project,
                    'text' => $project,
                    'meta' => [
                        'project' => $project,
                        'salesorder' => $notesMeta['salesorder'],
                        'mfg_no' => $row->workordernumber,
                        'site' => $row->site,
                    ],
                ];
            })
            ->filter()
            ->values();

        $results = $masterRows
            ->concat($noteResults)
            ->unique(fn($item) => mb_strtolower($item['id']) . '|' . ($item['meta']['salesorder'] ?? ''))
            ->values()
            ->take($limit);

        return response()->json(['results' => $results]);
    }

    private function fetchMasterProjects(string $term, int $limit)
    {
        try {
            $schema = Schema::connection(SqlServerDb::connectionName());
            if (!$schema->hasTable('grating_projects')) {
                return collect();
            }

            return SqlServerDb::table('grating_projects')
                ->where('active', true)
                ->when($term !== '', function ($q) use ($term) {
                    $q->where(function ($sub) use ($term) {
                        $sub->where('project_name', 'like', '%' . $term . '%')
                            ->orWhere('salesorder', 'like', '%' . $term . '%');
                    });
                })
                ->orderBy('sort_order')
                ->orderBy('project_name')
                ->limit($limit)
                ->get()
                ->map(fn($row) => [
                    'id' => $row->project_name,
                    'text' => $row->project_name,
                    'meta' => [
                        'project' => $row->project_name,
                        'salesorder' => $row->salesorder,
                        'mfg_no' => null,
                        'site' => 'Master',
                        'source' => 'master',
                    ],
                ]);
        } catch (\Throwable $e) {
            report($e);

            return collect();
        }
    }

    private function parseAreaDimension(?string $description): array
    {
        $description = (string) $description;
        $patterns = [
            '/\bW\s*([0-9]+(?:\.[0-9]+)?)\s*(?:mm\.?)?\s*[xX*]\s*L\s*([0-9]+(?:\.[0-9]+)?)/iu',
            '/\bW\s*([0-9]+(?:\.[0-9]+)?)\s*[xX*]\s*([0-9]+(?:\.[0-9]+)?)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $description, $matches)) {
                $widthMm = (float) $matches[1];
                $lengthMm = (float) $matches[2];
                $sqmPerPiece = ($widthMm / 1000) * ($lengthMm / 1000);

                return [
                    'width_mm' => $widthMm,
                    'length_mm' => $lengthMm,
                    'sqm_per_piece' => round($sqmPerPiece, 6),
                    'area_label' => sprintf(
                        'W%s x L%s mm = %s sqm/pcs',
                        rtrim(rtrim(number_format($widthMm, 3, '.', ''), '0'), '.'),
                        rtrim(rtrim(number_format($lengthMm, 3, '.', ''), '0'), '.'),
                        rtrim(rtrim(number_format($sqmPerPiece, 6, '.', ''), '0'), '.')
                    ),
                ];
            }
        }

        return [
            'width_mm' => null,
            'length_mm' => null,
            'sqm_per_piece' => null,
            'area_label' => null,
        ];
    }

    private function parseWorkorderNotes(?string $notes): array
    {
        $notes = (string) $notes;

        $salesorder = null;
        if (preg_match('/Sale\s*Order\s*:\s*([A-Z0-9\-]+)/iu', $notes, $matches)) {
            $salesorder = trim($matches[1]);
        } elseif (preg_match('/\b(SOD[A-Z0-9\-]+)\b/iu', $notes, $matches)) {
            $salesorder = trim($matches[1]);
        } elseif (preg_match('/\bSOD\b\s*[:=]?\s*([A-Z0-9\-]+)/iu', $notes, $matches)) {
            $salesorder = trim($matches[1]);
        }

        $project = null;
        if (preg_match('/Project\s*:\s*([^\r\n]+)/iu', $notes, $matches)) {
            $project = trim($matches[1]);
        }

        return [
            'salesorder' => $salesorder,
            'project' => $project,
        ];
    }

    private function parsePlanQtyPcs(?string $description): ?float
    {
        $description = (string) $description;

        if (preg_match('/\*\*\s*[^0-9]*(\d+(?:\.\d+)?)\s*(?:ชิ้น|pcs?|pc)\s*\*\*/iu', $description, $matches)) {
            return (float) $matches[1];
        }

        if (preg_match('/(?:จำนวน|qty)\s*(\d+(?:\.\d+)?)\s*(?:ชิ้น|pcs?|pc)/iu', $description, $matches)) {
            return (float) $matches[1];
        }

        return null;
    }

    private function fetchPlanDescriptions($rows): array
    {
        $result = [];

        $groups = collect($rows)
            ->filter(fn($row) => isset($row->workorder_id, $row->site))
            ->groupBy(fn($row) => (string) $row->site);

        foreach ($groups as $site => $siteRows) {
            $connectionNames = $site === 'Plus'
                ? ['pgsqlmfgp', 'pgmgfsqlp', 'pgmfgsqlp', 'pgsqlp']
                : ['pgsqlmfgw', 'pgmfgsqlw', 'pgmgfsqlw', 'pgsqlw'];

            $ids = $siteRows->pluck('workorder_id')->filter()->unique()->values()->all();
            if (!$ids) {
                continue;
            }

            foreach ($connectionNames as $connectionName) {
                if (!array_key_exists($connectionName, config('database.connections', []))) {
                    continue;
                }

                try {
                    if (!Schema::connection($connectionName)->hasTable('workorderworkcenter')) {
                        continue;
                    }

                    $rowsByPlan = DB::connection($connectionName)
                        ->table('workorderworkcenter')
                        ->select('workorder_id', DB::raw('MAX(description) AS plan_description'))
                        ->whereIn('workorder_id', $ids)
                        ->where('workseq', 2)
                        ->groupBy('workorder_id')
                        ->get();

                    foreach ($rowsByPlan as $planRow) {
                        $result[$this->planLookupKey($site, $planRow->workorder_id)] = $planRow->plan_description;
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        return $result;
    }

    private function fetchProjectRows(array $connectionNames, string $siteLabel, string $term, int $limit)
    {
        foreach ($connectionNames as $connectionName) {
            if (!array_key_exists($connectionName, config('database.connections', []))) {
                continue;
            }

            try {
                if (!Schema::connection($connectionName)->hasTable('workorder')) {
                    continue;
                }

                return DB::connection($connectionName)
                    ->table('workorder as wo')
                    ->select([
                        'wo.workordernumber',
                        DB::raw('wo.notes AS wo_notes'),
                    ])
                    ->selectRaw('? as site', [$siteLabel])
                    ->whereNotNull('wo.notes')
                    ->where('wo.notes', 'ilike', '%Project%')
                    ->when($term !== '', function ($q) use ($term) {
                        $q->where(function ($sub) use ($term) {
                            $sub->where('wo.notes', 'ilike', '%' . $term . '%')
                                ->orWhere('wo.workordernumber', 'ilike', $term . '%');
                        });
                    })
                    ->orderByDesc('wo.id')
                    ->limit($limit * 3)
                    ->get();
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return collect();
    }

    private function planLookupKey(string $site, $workorderId): string
    {
        return $site . ':' . (string) $workorderId;
    }

    private function firstAvailableConnection(array $names): string
    {
        $connections = config('database.connections', []);

        foreach ($names as $name) {
            if (array_key_exists($name, $connections)) {
                return $name;
            }
        }

        return $names[0];
    }
}
