<?php

namespace App\Http\Controllers\FormFC;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DivisionPartMasterController extends Controller
{
    private string $fcConn = 'sqlsrv_menam';

    private array $salespersonDivisionMap = [
        1506 => 'D1',
        1507 => 'D2',
        1431 => 'D3',
        1433 => 'D5',
        1434 => 'D6',
        1435 => 'D7',
        1436 => 'D8',
        478468285 => 'D9',
        528615586 => 'D9',
    ];

    private array $divisionLabels = [
        'D1' => 'D1',
        'D2' => 'D2',
        'D3' => 'D3',
        'D4' => 'D4',
        'D5' => 'D5',
        'D6' => 'D6',
        'D7' => 'D7',
        'D8' => 'D8',
    ];

    private function userOr403()
    {
        $u = auth()->user();
        if (!$u && !app()->environment('local')) {
            abort(403, 'ยังไม่ได้เข้าสู่ระบบ');
        }
        return $u;
    }

    private function normalizeDivision(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));
        $code = str_replace(['.', ' '], '', $code);

        if (preg_match('/^D([1-8])$/', $code, $m)) {
            return 'D' . $m[1];
        }

        return null;
    }

    private function availableForecastDivisions(): array
    {
        $u = auth()->user();

        if (!$u) {
            return [];
        }

        $allowed = [];
        foreach (range(1, 8) as $i) {
            $code = 'D' . $i;
            if (method_exists($u, 'hasRoleCode') && $u->hasRoleCode($code)) {
                $allowed[] = $code;
            }
        }

        return array_values(array_unique($allowed));
    }

    private function currentForecastDivision(Request $request): ?string
    {
        $allowed = $this->availableForecastDivisions();
        if (empty($allowed)) {
            return null;
        }

        $requested = $this->normalizeDivision(
            $request->query('division', $request->input('division'))
        );

        if ($requested && in_array($requested, $allowed, true)) {
            return $requested;
        }

        return $allowed[0];
    }

    private function divisionSalespersonIds(string $division): array
    {
        return collect($this->salespersonDivisionMap)
            ->filter(fn($d) => strtoupper($d) === strtoupper($division))
            ->keys()
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();
    }

    private function fetchDivisionFgRowsFromForecastSource(
        string $salesCode,
        string $partKeyword = '',
        ?Carbon $start = null,
        ?Carbon $end = null
    ) {
        $salespersonIds = $this->divisionSalespersonIds($salesCode);

        if (empty($salespersonIds)) {
            return collect();
        }

        $start = $start ?: now('Asia/Bangkok')->startOfMonth()->subMonths(6)->startOfMonth();
        $end   = $end   ?: now('Asia/Bangkok')->startOfMonth()->subMonths(1)->endOfMonth();

        $fetch = function (string $conn) use ($salespersonIds, $start, $end, $partKeyword) {
            $spPlaceholders = implode(',', array_fill(0, count($salespersonIds), '?'));

            $searchSql = '';
            $bindings = [
                'IUB%',
                $start->toDateString(),
                $end->toDateString(),
                ...$salespersonIds,
            ];

            if ($partKeyword !== '') {
                $kw = '%' . strtoupper($partKeyword) . '%';

                $searchSql = "
                    AND (
                        UPPER(TRIM(COALESCE(p_fg.partnumber, ''))) LIKE ?
                        OR UPPER(TRIM(COALESCE(p_fg.description, ''))) LIKE ?
                        OR UPPER(TRIM(COALESCE(p_fg.f4, ''))) LIKE ?
                        OR UPPER(TRIM(COALESCE(rm.description, ''))) LIKE ?
                    )
                ";

                $bindings[] = $kw;
                $bindings[] = $kw;
                $bindings[] = $kw;
                $bindings[] = $kw;
            }

            $sql = <<<SQL
                SELECT DISTINCT
                    UPPER(TRIM(COALESCE(p_fg.partnumber, ''))) AS fg_partnumber,
                    COALESCE(p_fg.description, '') AS fg_description,
                    UPPER(LTRIM(RTRIM(COALESCE(p_fg.f4, '')))) AS rm_partnumber,
                    COALESCE(rm.description, '') AS rm_description
                FROM serializeunitsmvmt sus
                LEFT JOIN serializeunits su ON su.id = sus.su_id
                LEFT JOIN gl ON gl.id = sus.trans_id
                LEFT JOIN parts p ON p.id = su.parts_id
                JOIN workorder wo ON wo.workordernumber = gl.reference
                JOIN customer c ON c.id = wo.customer_id
                LEFT JOIN parts p_fg ON p_fg.id = wo.parts_id
                LEFT JOIN parts rm
                    ON UPPER(LTRIM(RTRIM(rm.partnumber))) = UPPER(LTRIM(RTRIM(COALESCE(p_fg.f4, ''))))
                WHERE gl.transnumber LIKE ?
                  AND gl.transdate >= ?
                  AND gl.transdate <= ?
                  AND c.saleperson_id IN ($spPlaceholders)
                  AND p_fg.partnumber IS NOT NULL
                  AND NULLIF(TRIM(COALESCE(p_fg.f4, '')), '') IS NOT NULL
                  AND TRIM(COALESCE(p_fg.f4, '')) <> '0'
                  $searchSql
                ORDER BY fg_partnumber
            SQL;

            return collect(DB::connection($conn)->select($sql, $bindings))
                ->map(fn($r) => [
                    'fg_partnumber'  => strtoupper(trim((string)($r->fg_partnumber ?? ''))),
                    'fg_description' => trim((string)($r->fg_description ?? '')),
                    'rm_partnumber'  => strtoupper(trim((string)($r->rm_partnumber ?? ''))),
                    'rm_description' => trim((string)($r->rm_description ?? '')),
                ]);
        };

        $rows = collect()
            ->concat($fetch('pgsqlw'))
            ->concat($fetch('pgsqlp'));

        return $rows
            ->filter(
                fn($r) => ($r['fg_partnumber'] ?? '') !== '' &&
                    ($r['rm_partnumber'] ?? '') !== '' &&
                    ($r['rm_partnumber'] ?? '') !== '0'
            )
            ->unique(fn($r) => ($r['fg_partnumber'] ?? '') . '|' . ($r['rm_partnumber'] ?? ''))
            ->values();
    }

    public function index(Request $request)
    {
        $this->userOr403();

        $allowedDivisions = $this->availableForecastDivisions();
        abort_if(empty($allowedDivisions), 403, 'คุณไม่มีสิทธิ์เข้าใช้งานหน้านี้');

        $division = $this->currentForecastDivision($request);
        abort_if(!$division, 403, 'คุณไม่มีสิทธิ์เข้าใช้งานหน้านี้');

        $part = strtoupper(trim((string) $request->query('part', '')));
        $baseMonth = now('Asia/Bangkok')->startOfMonth();

        $forecastRows = $this->fetchDivisionFgRowsFromForecastSource(
            $division,
            $part,
            (clone $baseMonth)->subMonths(6)->startOfMonth(),
            (clone $baseMonth)->subMonths(1)->endOfMonth()
        );

        $configMap = DB::connection($this->fcConn)
            ->table('fc_rm_division_part_master')
            ->where('sales_code', $division)
            ->get()
            ->keyBy(fn($r) => strtoupper(trim((string) $r->rm_partnumber)));

        $rows = $forecastRows->map(function ($r) use ($configMap) {
            $rm = strtoupper(trim((string) ($r['rm_partnumber'] ?? '')));
            $cfg = $configMap->get($rm);

            return (object) [
                'fg_partnumber'  => (string) ($r['fg_partnumber'] ?? ''),
                'fg_description' => (string) ($r['fg_description'] ?? ''),
                'rm_partnumber'  => $rm,
                'rm_description' => (string) ($r['rm_description'] ?? ''),
                'is_forecast'    => $cfg ? (int) ($cfg->is_forecast ?? 1) : 1,
                'remark'         => (string) ($cfg->remark ?? ''),
            ];
        })->values();

        return view('formfc.division_part_master', [
            'division'             => $division,
            'part'                 => $part,
            'rows'                 => $rows,
            'allowedDivisions'     => $allowedDivisions,
            'showDivisionDropdown' => count($allowedDivisions) > 1,
            'divisionLabels'       => $this->divisionLabels,
        ]);
    }

    public function save(Request $request)
    {
        $u = $this->userOr403();

        $validated = $request->validate([
            'rows' => ['required', 'array'],
        ]);

        $division = $this->currentForecastDivision($request);
        abort_if(!$division, 403, 'คุณไม่มีสิทธิ์เข้าใช้งานหน้านี้');

        $rows = $validated['rows'] ?? [];

        DB::connection($this->fcConn)->transaction(function () use ($division, $rows, $u) {
            $upserts = [];
            $seen = [];

            foreach ($rows as $row) {
                $rmPart = strtoupper(trim((string) ($row['rm_partnumber'] ?? '')));
                $rmDesc = trim((string) ($row['rm_description'] ?? ''));
                $remark = trim((string) ($row['remark'] ?? ''));
                $isForecast = isset($row['is_forecast']) ? 1 : 0;

                if ($rmPart === '' || isset($seen[$rmPart])) {
                    continue;
                }

                $seen[$rmPart] = true;

                $upserts[] = [
                    'sales_code'      => $division,
                    'rm_partnumber'   => $rmPart,
                    'rm_description'  => $rmDesc,
                    'is_forecast'     => $isForecast,
                    'remark'          => $remark !== '' ? $remark : null,
                    'updated_at'      => now(),
                    'updated_by'      => $u->id ?? null,
                ];
            }

            if (!empty($upserts)) {
                DB::connection($this->fcConn)
                    ->table('fc_rm_division_part_master')
                    ->upsert(
                        $upserts,
                        ['sales_code', 'rm_partnumber'],
                        ['rm_description', 'is_forecast', 'remark', 'updated_at', 'updated_by']
                    );
            }
        });

        return redirect()
            ->route('fc.divisionPartMaster.index', ['division' => $division])
            ->with('success', 'บันทึก Division Part Master เรียบร้อยแล้ว');
    }
}
