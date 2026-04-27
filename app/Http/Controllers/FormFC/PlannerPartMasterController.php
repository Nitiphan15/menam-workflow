<?php

namespace App\Http\Controllers\FormFC;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class PlannerPartMasterController extends Controller
{
    private string $fcConn = 'sqlsrv_menam';

    private function userOr403()
    {
        $u = auth()->user();
        if (!$u && !app()->environment('local')) {
            abort(403, 'ยังไม่ได้เข้าสู่ระบบ');
        }
        return $u;
    }



    public function index(Request $request)
    {
        $this->userOr403();

        $part = strtoupper(trim((string) $request->query('part', '')));

        $q = DB::connection($this->fcConn)
            ->table('fc_rm_planner_part_master');

        if ($part !== '') {
            $q->where(function ($qq) use ($part) {
                $qq->whereRaw('UPPER(ISNULL(rm_partnumber, \'\')) LIKE ?', ["%{$part}%"])
                    ->orWhereRaw('UPPER(ISNULL(rm_description, \'\')) LIKE ?', ["%{$part}%"]);
            });
        }

        $rows = $q->orderBy('rm_partnumber')->get();

        return view('formfc.planner_part_master', [
            'rows' => $rows,
            'part' => $part,
        ]);
    }

    public function save(Request $request)
    {
        $u = $this->userOr403();

        $rows = $request->input('rows', []);
        if (!is_array($rows)) {
            return back()->with('error', 'ข้อมูลไม่ถูกต้อง');
        }

        $duplicateParts = collect($rows)
            ->map(fn($row) => strtoupper(trim((string) ($row['rm_partnumber'] ?? ''))))
            ->filter()
            ->countBy()
            ->filter(fn($count) => $count > 1)
            ->keys()
            ->values()
            ->all();

        if (!empty($duplicateParts)) {
            return back()->withInput()->with('error', 'RM Part ซ้ำ: ' . implode(', ', $duplicateParts));
        }

        DB::connection($this->fcConn)->transaction(function () use ($rows, $u) {
            $partDescMap = $this->fetchRmDescriptions(
                collect($rows)
                    ->map(fn($row) => strtoupper(trim((string) ($row['rm_partnumber'] ?? ''))))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all()
            );

            foreach ($rows as $row) {
                $id = isset($row['id']) && $row['id'] !== '' ? (int) $row['id'] : null;
                $rmPart = strtoupper(trim((string) ($row['rm_partnumber'] ?? '')));
                $rmDesc = trim((string) ($partDescMap[$rmPart] ?? ($row['rm_description'] ?? '')));
                $active = (int) ($row['active'] ?? 0);
                $remark = trim((string) ($row['remark'] ?? ''));

                if ($rmPart === '') {
                    continue;
                }

                if ($id) {
                    DB::connection($this->fcConn)
                        ->table('fc_rm_planner_part_master')
                        ->where('id', $id)
                        ->update([
                            'rm_partnumber'  => $rmPart,
                            'rm_description' => $rmDesc,
                            'active'         => $active,
                            'remark'         => $remark !== '' ? $remark : null,
                            'updated_at'     => now(),
                            'updated_by'     => $u->id ?? null,
                        ]);
                } else {
                    DB::connection($this->fcConn)
                        ->table('fc_rm_planner_part_master')
                        ->insert([
                            'rm_partnumber'  => $rmPart,
                            'rm_description' => $rmDesc,
                            'active'         => $active,
                            'remark'         => $remark !== '' ? $remark : null,
                            'created_at'     => now(),
                            'created_by'     => $u->id ?? null,
                            'updated_at'     => now(),
                            'updated_by'     => $u->id ?? null,
                        ]);
                }
            }
        });

        return back()->with('success', 'บันทึก Planner Part Master เรียบร้อยแล้ว');
    }

    public function rmLookup(Request $request)
    {
        $this->userOr403();

        $term = strtoupper(trim((string) $request->query('q', '')));

        $fetch = function (string $connName) use ($term) {
            return DB::connection($connName)
                ->table('parts')
                ->selectRaw('UPPER(LTRIM(RTRIM(partnumber))) AS partnumber, COALESCE(description, \'\') AS description')
                ->whereNotNull('partnumber')
                ->whereRaw("NULLIF(LTRIM(RTRIM(partnumber)), '') IS NOT NULL")
                ->where(function ($q) {
                    $q->where('active', true)
                        ->orWhere('active', 1)
                        ->orWhere('active', 't');
                })
                ->when($term !== '', function ($q) use ($term) {
                    $like = '%' . $term . '%';
                    $q->whereRaw(
                        "(
                            UPPER(LTRIM(RTRIM(partnumber))) LIKE ?
                            OR UPPER(COALESCE(description, '')) LIKE ?
                        )",
                        [$like, $like]
                    );
                })
                ->orderBy('partnumber')
                ->limit(30)
                ->get()
                ->map(fn($row) => [
                    'id' => (string) ($row->partnumber ?? ''),
                    'text' => trim((string) ($row->partnumber ?? '')),
                    'partnumber' => trim((string) ($row->partnumber ?? '')),
                    'description' => trim((string) ($row->description ?? '')),
                ]);
        };

        $items = collect()
            ->concat($fetch('pgsqlw'))
            ->concat($fetch('pgsqlp'))
            ->filter(fn($row) => ($row['partnumber'] ?? '') !== '')
            ->unique(fn($row) => strtoupper((string) ($row['partnumber'] ?? '')))
            ->values()
            ->take(20)
            ->all();

        return response()->json($items);
    }

    public function import(Request $request)
    {
        $u = $this->userOr403();

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
            'mode' => ['required', 'in:replace,append'],
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $mode = strtolower((string) $request->input('mode'));
        $file = $request->file('file');

        try {
            $rows = $this->parseImportFile($file->getRealPath());

            if (empty($rows)) {
                return back()->with('error', 'ไม่พบข้อมูลในไฟล์');
            }

            DB::connection($this->fcConn)->transaction(function () use ($rows, $mode, $u) {
                if ($mode === 'replace') {
                    DB::connection($this->fcConn)
                        ->table('fc_rm_planner_part_master')
                        ->delete();
                }

                $upserts = [];
                foreach ($rows as $r) {
                    $rmPart = strtoupper(trim((string) ($r['partnumber'] ?? '')));
                    $rmDesc = trim((string) ($r['desc'] ?? ''));

                    if ($rmPart === '') {
                        continue;
                    }

                    $upserts[] = [
                        'rm_partnumber' => $rmPart,
                        'rm_description' => $rmDesc,
                        'active' => 1,
                        'remark' => null,
                        'updated_at' => now(),
                        'updated_by' => $u->id ?? null,
                    ];
                }

                if (!empty($upserts)) {
                    DB::connection($this->fcConn)
                        ->table('fc_rm_planner_part_master')
                        ->upsert(
                            $upserts,
                            ['rm_partnumber'],
                            ['rm_description', 'active', 'updated_at', 'updated_by']
                        );
                }
            });

            return back()->with('success', 'Import Planner Part Master เรียบร้อยแล้ว');
        } catch (Throwable $e) {
            report($e);
            return back()->with('error', 'Import ไม่สำเร็จ กรุณาตรวจสอบไฟล์อีกครั้ง');
        }
    }

    private function parseImportFile(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheet(0);
        $rows = $sheet->toArray(null, true, true, true);

        $headerRowNo = null;
        $headerMap = [];

        foreach ($rows as $rowNo => $row) {
            $normalized = [];
            foreach ($row as $col => $val) {
                $normalized[$col] = strtolower(trim((string) $val));
            }

            if (in_array('partnumber', $normalized, true) && in_array('desc', $normalized, true)) {
                $headerRowNo = $rowNo;
                $headerMap = $normalized;
                break;
            }
        }

        if (!$headerRowNo) {
            throw new \RuntimeException('ไม่พบ header ที่รองรับ ต้องมี partnumber, desc');
        }

        $partCol = array_search('partnumber', $headerMap, true);
        $descCol = array_search('desc', $headerMap, true);

        $out = [];
        foreach ($rows as $rowNo => $row) {
            if ($rowNo <= $headerRowNo) {
                continue;
            }

            $part = trim((string) ($row[$partCol] ?? ''));
            $desc = trim((string) ($row[$descCol] ?? ''));

            if ($part === '' && $desc === '') {
                continue;
            }

            $out[] = [
                'partnumber' => $part,
                'desc' => $desc,
            ];
        }

        return $out;
    }

    private function fetchRmDescriptions(array $rmParts): array
    {
        $rmParts = collect($rmParts)
            ->map(fn($part) => strtoupper(trim((string) $part)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($rmParts)) {
            return [];
        }

        $fetch = function (string $connName) use ($rmParts) {
            $map = [];

            foreach (array_chunk($rmParts, 300) as $chunk) {
                $rows = DB::connection($connName)
                    ->table('parts')
                    ->selectRaw('UPPER(LTRIM(RTRIM(partnumber))) AS partnumber, COALESCE(description, \'\') AS description')
                    ->whereIn(DB::raw('UPPER(LTRIM(RTRIM(partnumber)))'), $chunk)
                    ->get();

                foreach ($rows as $row) {
                    $part = strtoupper(trim((string) ($row->partnumber ?? '')));
                    if ($part !== '' && !array_key_exists($part, $map)) {
                        $map[$part] = trim((string) ($row->description ?? ''));
                    }
                }
            }

            return $map;
        };

        return $fetch('pgsqlw') + $fetch('pgsqlp');
    }
}
