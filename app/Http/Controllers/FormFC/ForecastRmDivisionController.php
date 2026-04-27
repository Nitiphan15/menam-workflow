<?php

namespace App\Http\Controllers\FormFC;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ForecastRmDivisionController extends Controller
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
        528615586 => 'D9',
    ];

    private function userOr403()
    {
        $u = auth()->user();
        /*if (!$u && !app()->environment('local')) {
            abort(403, 'ยังไม่ได้เข้าสู่ระบบ');
        }*/
        //return $u;

        if (!$u) {
            abort(403, 'ยังไม่ได้เข้าสู่ระบบ');
        }
        return $u;
    }

    private function isSuperAdmin(): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        $u = auth()->user();
        if (!$u) return false;

        return (int) ($u->superadmin ?? 0) === 1;
    }

    private function allSalesCodes(): array
    {
        return ['D1', 'D2', 'D3', 'D4', 'D5', 'D6', 'D7', 'D8', 'D9'];
    }

    private function normalizeSalesCode(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));
        $code = str_replace(['.', ' '], '', $code);

        if (preg_match('/^D([1-9])$/', $code, $m)) {
            return 'D' . $m[1];
        }

        return null;
    }

    private array $divisionLabels = [
        'D1' => 'D1',
        'D2' => 'D2',
        'D3' => 'D3',
        'D4' => 'D4',
        'D5' => 'D5',
        'D6' => 'D6',
        'D7' => 'D7',
        'D8' => 'D8',
        'D9' => 'D9',
    ];

    private function availableSalesCodesForUser(): array
    {
        $u = auth()->user();
        if (!$u) {
            return [];
        }

        $allowed = [];
        foreach ($this->allSalesCodes() as $code) {
            if (method_exists($u, 'hasRoleCode') && $u->hasRoleCode($code)) {
                $allowed[] = $code;
            }
        }

        return array_values(array_unique($allowed));
    }

    private function resolveUserSalesCode(): ?string
    {
        $allowed = $this->availableSalesCodesForUser();
        return $allowed[0] ?? null;
    }

    private function availableForecastDivisions(): array
    {
        if (app()->environment('local')) {
            return ['D1'];
        }

        $u = auth()->user();
        if (!$u) {
            return [];
        }

        $allowed = [];
        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $i) {
            $code = 'D' . $i;
            if (method_exists($u, 'hasRoleCode') && $u->hasRoleCode($code)) {
                $allowed[] = $code;
            }
        }

        return $allowed;
    }

    private function currentForecastDivision(Request $request): ?string
    {
        $allowed = $this->availableForecastDivisions();
        if (empty($allowed)) {
            return null;
        }

        $requested = strtoupper(trim((string) $request->query('division', $request->input('division', ''))));

        if ($requested !== '' && in_array($requested, $allowed, true)) {
            return $requested;
        }

        return $allowed[0];
    }

    private function hasMultiForecastDivisions(): bool
    {
        return count($this->availableForecastDivisions()) > 1;
    }

    /*private function requestedOrResolvedSalesCode(Request $request): ?string
    {
        $requested = $this->normalizeSalesCode(
            $request->query('sales_code', $request->input('sales_code'))
        );

        if ($requested) {
            return $requested;
        }

        return $this->resolveUserSalesCode();
    }*/

    private function requestedOrResolvedSalesCode(Request $request): ?string
    {
        $requested = $this->normalizeSalesCode(
            $request->query(
                'division',
                $request->input(
                    'division',
                    $request->query('sales_code', $request->input('sales_code'))
                )
            )
        );

        $allowed = $this->availableSalesCodesForUser();

        if ($requested && in_array($requested, $allowed, true)) {
            return $requested;
        }

        return $allowed[0] ?? null;
    }

    private function canUseSalesCode(string $salesCode): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }

        $u = auth()->user();
        if (!$u) {
            return false;
        }

        return method_exists($u, 'hasRoleCode') && $u->hasRoleCode($salesCode);
    }

    private function divisionSalespersonIds(string $division): array
    {
        return collect($this->salespersonDivisionMap)
            ->filter(fn($d) => $d === strtoupper($division))
            ->keys()
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();
    }

    private function parseRmKeywords(string $text): array
    {
        return collect(preg_split('/[\s,|;]+/', strtoupper(trim($text))))
            ->filter(fn($v) => $v !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function fetchDivisionPartMasterConfig(string $salesCode)
    {
        return DB::connection($this->fcConn)
            ->table('fc_rm_division_part_master')
            ->where('sales_code', $salesCode)
            ->get()
            ->keyBy(fn($r) => strtoupper(trim((string) $r->rm_partnumber)));
    }

    private function fetchSupplierOptions()
    {
        return DB::connection($this->fcConn)
            ->table('fc_supplier_master')
            ->where('is_active', 1)
            ->orderBy('supplier_short_name')
            ->get(['supplier_code', 'supplier_short_name'])
            ->map(fn($r) => [
                'supplier_code' => (string) $r->supplier_code,
                'supplier_short_name' => (string) ($r->supplier_short_name ?? ''),
                'label' => (string) ($r->supplier_short_name ?? ''),
            ])
            ->values();
    }

    private function parseMultiKeywords(?string $text): array
    {
        return collect(preg_split('/[\s,|;]+/', strtoupper(trim((string) $text))))
            ->filter(fn($v) => $v !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function fetchSupplierMapByRmParts(array $rmParts)
    {
        $rmParts = collect($rmParts)
            ->map(fn($v) => strtoupper(trim((string) $v)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($rmParts)) {
            return collect();
        }

        return DB::connection($this->fcConn)
            ->table('fc_item_supplier_map as ism')
            ->join('parts as p', 'p.id', '=', 'ism.part_id')
            ->join('fc_supplier_master as sm', 'sm.id', '=', 'ism.supplier_id')
            ->whereIn(DB::raw('UPPER(LTRIM(RTRIM(p.partnumber)))'), $rmParts)
            ->where('sm.is_active', 1)
            ->orderBy('sm.supplier_short_name')
            ->get([
                DB::raw('UPPER(LTRIM(RTRIM(p.partnumber))) as rm_partnumber'),
                'sm.supplier_code',
                'sm.supplier_short_name',
            ])
            ->groupBy('rm_partnumber')
            ->map(function ($rows) {
                $first = collect($rows)->first();

                return [
                    'supplier_code' => (string) ($first->supplier_code ?? ''),
                    'supplier_short_name' => (string) ($first->supplier_short_name ?? ''),
                ];
            });
    }

    private function fetchFgHistoryGrouped(
        string $salesCode,
        array $rmLikes,
        array $customerNameLikes,
        Carbon $start,
        Carbon $end,
        string $companyMode = 'ALL'
    ) {
        $salespersonIds = $this->divisionSalespersonIds($salesCode);

        if (empty($salespersonIds)) {
            return collect();
        }

        $fetch = function (string $conn) use ($rmLikes, $customerNameLikes, $start, $end, $salespersonIds) {
            $spPlaceholders = implode(',', array_fill(0, count($salespersonIds), '?'));

            $rmSql = '';
            $rmBindings = [];
            if (!empty($rmLikes)) {
                $rmSql .= ' AND (';
                foreach ($rmLikes as $i => $kw) {
                    if ($i > 0) $rmSql .= ' OR ';
                    $rmSql .= 'UPPER(LTRIM(RTRIM(COALESCE(p_fg.f4, \'\')))) LIKE ?';
                    $rmBindings[] = $kw . '%';
                }
                $rmSql .= ') ';
            }

            $custNameSql = '';
            $custNameBindings = [];
            if (!empty($customerNameLikes)) {
                $custNameSql .= ' AND (';
                foreach ($customerNameLikes as $i => $kw) {
                    if ($i > 0) $custNameSql .= ' OR ';
                    $custNameSql .= '(UPPER(TRIM(COALESCE(c.name, \'\'))) LIKE ? OR UPPER(TRIM(COALESCE(c.customernumber, \'\'))) LIKE ?)';
                    $custNameBindings[] = '%' . $kw . '%';
                    $custNameBindings[] = '%' . $kw . '%';
                }
                $custNameSql .= ') ';
            }

            $sql = <<<SQL
                SELECT
                    COALESCE(c.id, 0) AS customer_id,
                    COALESCE(c.name, '-') AS customer_name,
                    UPPER(TRIM(p_fg.partnumber)) AS fg_partnumber,
                    p_fg.description AS fg_description,
                    UPPER(LTRIM(RTRIM(COALESCE(p_fg.f4, '')))) AS rm_partnumber,
                    DATE_TRUNC('month', gl.transdate)::date AS month,
                    SUM(ROUND(COALESCE(su.qty, 0), 2)) AS qty_sum
                FROM serializeunitsmvmt sus
                LEFT JOIN serializeunits su ON su.id = sus.su_id
                LEFT JOIN gl ON gl.id = sus.trans_id
                LEFT JOIN parts p ON p.id = su.parts_id
                JOIN workorder wo ON wo.workordernumber = gl.reference
                JOIN customer c ON c.id = wo.customer_id
                LEFT JOIN parts p_fg ON p_fg.id = wo.parts_id
                WHERE gl.transnumber LIKE ?
                AND gl.transdate >= ?
                AND gl.transdate <= ?
                AND c.saleperson_id IN ($spPlaceholders)
                AND p_fg.partnumber IS NOT NULL
                {$rmSql}
                {$custNameSql}
                GROUP BY
                    COALESCE(c.id, 0),
                    COALESCE(c.name, '-'),
                    UPPER(TRIM(p_fg.partnumber)),
                    p_fg.description,
                    UPPER(LTRIM(RTRIM(COALESCE(p_fg.f4, '')))),
                    DATE_TRUNC('month', gl.transdate)::date
                ORDER BY customer_name, fg_partnumber, month
                SQL;

            $bindings = array_merge(
                ['IUB%', $start->toDateString(), $end->toDateString()],
                $salespersonIds,
                $rmBindings,
                $custNameBindings
            );

            return collect(DB::connection($conn)->select($sql, $bindings))
                ->map(fn($r) => (array) $r);
        };

        $rows = collect();

        if (in_array($companyMode, ['ALL', 'WIRE'], true)) {
            $rows = $rows->merge($fetch('pgsqlw'));
        }
        if (in_array($companyMode, ['ALL', 'PLUS'], true)) {
            $rows = $rows->merge($fetch('pgsqlp'));
        }

        return $rows;
    }

    private function fetchLatestManualSeedRows(string $salesCode, string $forecastBaseMonth)
    {
        return DB::connection($this->fcConn)
            ->table('fc_rm_division_part_setting')
            ->where('sales_code', $salesCode)
            ->whereDate('forecast_base_month', '<=', $forecastBaseMonth)
            ->whereDate(
                'forecast_base_month',
                '>=',
                Carbon::parse($forecastBaseMonth)->subMonths(12)->startOfMonth()->toDateString()
            )
            ->whereNotNull('customer_id')
            ->where('customer_id', '>', 0)
            ->whereNotNull('fg_partnumber')
            ->whereRaw("LTRIM(RTRIM(COALESCE(fg_partnumber, ''))) <> ''")
            ->where(function ($q) {
                $q->where('is_selected', 1)
                    ->orWhereNotNull('manual_forecast_1m');
            })
            ->orderByDesc('forecast_base_month')
            ->orderByDesc('updated_at')
            ->get([
                'forecast_base_month',
                'customer_id',
                'customer_name',
                'fg_partnumber',
                'fg_description',
                'is_selected',
                'k_factor',
                'manual_forecast_1m',
                'row_remark',
                'supplier_code',
                'supplier_name',
            ])
            ->map(function ($r) {
                $fgPartnumber = strtoupper(trim((string) ($r->fg_partnumber ?? '')));
                $customerId = (int) ($r->customer_id ?? 0);

                return [
                    'row_key' => $customerId . '|' . $fgPartnumber,
                    'forecast_base_month' => (string) ($r->forecast_base_month ?? ''),
                    'customer_id' => $customerId,
                    'customer_name' => (string) ($r->customer_name ?? '-'),
                    'fg_partnumber' => $fgPartnumber,
                    'fg_description' => (string) ($r->fg_description ?? ''),
                    'is_selected' => (int) ($r->is_selected ?? 0),
                    'k_factor' => $r->k_factor !== null ? round((float) $r->k_factor, 1) : null,
                    'manual_forecast_1m' => $r->manual_forecast_1m !== null
                        ? round((float) $r->manual_forecast_1m, 2)
                        : null,
                    'row_remark' => (string) ($r->row_remark ?? ''),
                    'supplier_code' => (string) ($r->supplier_code ?? ''),
                    'supplier_name' => (string) ($r->supplier_name ?? ''),
                ];
            })
            ->groupBy('row_key')
            ->map(fn($rows) => $rows->first());
    }

    private function fetchFgPartDetails(array $fgParts, string $companyMode = 'ALL')
    {
        $fgParts = collect($fgParts)
            ->map(fn($v) => strtoupper(trim((string) $v)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($fgParts)) {
            return collect();
        }

        $fetch = function (string $conn) use ($fgParts) {
            return DB::connection($conn)
                ->table('parts')
                ->whereIn(DB::raw('UPPER(TRIM(partnumber))'), $fgParts)
                ->get([
                    DB::raw('UPPER(TRIM(partnumber)) as fg_partnumber'),
                    'description as fg_description',
                    DB::raw("UPPER(LTRIM(RTRIM(COALESCE(f4, '')))) as rm_partnumber"),
                ])
                ->map(fn($r) => (array) $r);
        };

        $rows = collect();

        if (in_array($companyMode, ['ALL', 'WIRE'], true)) {
            $rows = $rows->merge($fetch('pgsqlw'));
        }
        if (in_array($companyMode, ['ALL', 'PLUS'], true)) {
            $rows = $rows->merge($fetch('pgsqlp'));
        }

        return $rows
            ->groupBy(fn($r) => strtoupper(trim((string) ($r['fg_partnumber'] ?? ''))))
            ->map(function ($group) {
                $first = collect($group)->first();

                return [
                    'fg_description' => (string) ($first['fg_description'] ?? ''),
                    'rm_partnumber' => (string) ($first['rm_partnumber'] ?? ''),
                ];
            });
    }

    private function fetchOlderCustomerFgCandidates(
        string $salesCode,
        array $rmLikes,
        array $customerNameLikes,
        Carbon $start,
        Carbon $end,
        string $companyMode = 'ALL'
    ) {
        $salespersonIds = $this->divisionSalespersonIds($salesCode);

        if (empty($salespersonIds)) {
            return collect();
        }

        $fetch = function (string $conn) use ($rmLikes, $customerNameLikes, $start, $end, $salespersonIds) {
            $spPlaceholders = implode(',', array_fill(0, count($salespersonIds), '?'));

            $rmSql = '';
            $rmBindings = [];
            if (!empty($rmLikes)) {
                $rmSql .= ' AND (';
                foreach ($rmLikes as $i => $kw) {
                    if ($i > 0) {
                        $rmSql .= ' OR ';
                    }
                    $rmSql .= 'UPPER(LTRIM(RTRIM(COALESCE(p_fg.f4, \'\')))) LIKE ?';
                    $rmBindings[] = $kw . '%';
                }
                $rmSql .= ') ';
            }

            $custSql = '';
            $custBindings = [];
            if (!empty($customerNameLikes)) {
                $custSql .= ' AND (';
                foreach ($customerNameLikes as $i => $kw) {
                    if ($i > 0) {
                        $custSql .= ' OR ';
                    }
                    $custSql .= '(UPPER(TRIM(COALESCE(c.name, \'\'))) LIKE ? OR UPPER(TRIM(COALESCE(c.customernumber, \'\'))) LIKE ?)';
                    $custBindings[] = '%' . $kw . '%';
                    $custBindings[] = '%' . $kw . '%';
                }
                $custSql .= ') ';
            }

            $sql = <<<SQL
                SELECT
                    COALESCE(c.id, 0) AS customer_id,
                    COALESCE(c.name, '-') AS customer_name,
                    UPPER(TRIM(p_fg.partnumber)) AS fg_partnumber,
                    MAX(p_fg.description) AS fg_description,
                    UPPER(LTRIM(RTRIM(COALESCE(MAX(p_fg.f4), '')))) AS rm_partnumber
                FROM serializeunitsmvmt sus
                LEFT JOIN serializeunits su ON su.id = sus.su_id
                LEFT JOIN gl ON gl.id = sus.trans_id
                JOIN workorder wo ON wo.workordernumber = gl.reference
                JOIN customer c ON c.id = wo.customer_id
                LEFT JOIN parts p_fg ON p_fg.id = wo.parts_id
                WHERE gl.transnumber LIKE ?
                AND gl.transdate >= ?
                AND gl.transdate <= ?
                AND c.saleperson_id IN ($spPlaceholders)
                AND p_fg.partnumber IS NOT NULL
                {$rmSql}
                {$custSql}
                GROUP BY
                    COALESCE(c.id, 0),
                    COALESCE(c.name, '-'),
                    UPPER(TRIM(p_fg.partnumber))
                ORDER BY customer_name, fg_partnumber
                SQL;

            $bindings = array_merge(
                ['IUB%', $start->toDateString(), $end->toDateString()],
                $salespersonIds,
                $rmBindings,
                $custBindings
            );

            return collect(DB::connection($conn)->select($sql, $bindings))
                ->map(fn($r) => (array) $r);
        };

        $rows = collect();

        if (in_array($companyMode, ['ALL', 'WIRE'], true)) {
            $rows = $rows->merge($fetch('pgsqlw'));
        }
        if (in_array($companyMode, ['ALL', 'PLUS'], true)) {
            $rows = $rows->merge($fetch('pgsqlp'));
        }

        return $rows
            ->groupBy(fn($r) => ($r['customer_id'] ?? 0) . '|' . ($r['fg_partnumber'] ?? ''))
            ->map(fn($g) => collect($g)->first())
            ->values();
    }

    private function fetchDivisionCustomerFgCatalog(
        string $salesCode,
        array $rmLikes,
        array $customerNameLikes,
        string $companyMode = 'ALL'
    ) {
        $salespersonIds = $this->divisionSalespersonIds($salesCode);

        if (empty($salespersonIds)) {
            return collect();
        }

        $fetch = function (string $conn) use ($rmLikes, $customerNameLikes, $salespersonIds) {
            $spPlaceholders = implode(',', array_fill(0, count($salespersonIds), '?'));

            $rmSql = '';
            $rmBindings = [];
            if (!empty($rmLikes)) {
                $rmSql .= ' AND (';
                foreach ($rmLikes as $i => $kw) {
                    if ($i > 0) {
                        $rmSql .= ' OR ';
                    }
                    $rmSql .= 'UPPER(LTRIM(RTRIM(COALESCE(p_fg.f4, \'\')))) LIKE ?';
                    $rmBindings[] = $kw . '%';
                }
                $rmSql .= ') ';
            }

            $custSql = '';
            $custBindings = [];
            if (!empty($customerNameLikes)) {
                $custSql .= ' AND (';
                foreach ($customerNameLikes as $i => $kw) {
                    if ($i > 0) {
                        $custSql .= ' OR ';
                    }
                    $custSql .= '(UPPER(TRIM(COALESCE(c.name, \'\'))) LIKE ? OR UPPER(TRIM(COALESCE(c.customernumber, \'\'))) LIKE ?)';
                    $custBindings[] = '%' . $kw . '%';
                    $custBindings[] = '%' . $kw . '%';
                }
                $custSql .= ') ';
            }

            $sql = <<<SQL
                SELECT
                    COALESCE(c.id, 0) AS customer_id,
                    COALESCE(c.name, '-') AS customer_name,
                    UPPER(TRIM(p_fg.partnumber)) AS fg_partnumber,
                    MAX(p_fg.description) AS fg_description,
                    UPPER(LTRIM(RTRIM(COALESCE(MAX(p_fg.f4), '')))) AS rm_partnumber
                FROM workorder wo
                JOIN customer c ON c.id = wo.customer_id
                LEFT JOIN parts p_fg ON p_fg.id = wo.parts_id
                WHERE c.saleperson_id IN ($spPlaceholders)
                AND p_fg.partnumber IS NOT NULL
                AND LTRIM(RTRIM(COALESCE(p_fg.f4, ''))) <> ''
                {$rmSql}
                {$custSql}
                GROUP BY
                    COALESCE(c.id, 0),
                    COALESCE(c.name, '-'),
                    UPPER(TRIM(p_fg.partnumber))
                ORDER BY customer_name, fg_partnumber
                SQL;

            $bindings = array_merge(
                $salespersonIds,
                $rmBindings,
                $custBindings
            );

            return collect(DB::connection($conn)->select($sql, $bindings))
                ->map(fn($r) => (array) $r);
        };

        $rows = collect();

        if (in_array($companyMode, ['ALL', 'WIRE'], true)) {
            $rows = $rows->merge($fetch('pgsqlw'));
        }
        if (in_array($companyMode, ['ALL', 'PLUS'], true)) {
            $rows = $rows->merge($fetch('pgsqlp'));
        }

        return $rows
            ->groupBy(fn($r) => ($r['customer_id'] ?? 0) . '|' . ($r['fg_partnumber'] ?? ''))
            ->map(fn($g) => collect($g)->first())
            ->values();
    }

    public function customerLookup(Request $request)
    {
        $this->userOr403();

        $salesCode = $this->requestedOrResolvedSalesCode($request);
        if (!$salesCode || !$this->canUseSalesCode($salesCode)) {
            return response()->json(['items' => []]);
        }

        $q = trim((string) $request->query('q', ''));
        if ($q === '' || mb_strlen($q) < 2) {
            return response()->json(['items' => []]);
        }

        $salespersonIds = $this->divisionSalespersonIds($salesCode);
        if (empty($salespersonIds)) {
            return response()->json(['items' => []]);
        }

        $fetch = function (string $conn) use ($q, $salespersonIds) {
            return DB::connection($conn)
                ->table('customer')
                ->select('id', 'name', 'customernumber')
                ->whereIn('saleperson_id', $salespersonIds)
                ->where(function ($w) use ($q) {
                    $w->whereRaw("UPPER(TRIM(COALESCE(name, ''))) LIKE ?", ['%' . strtoupper($q) . '%'])
                        ->orWhereRaw("UPPER(TRIM(COALESCE(customernumber, ''))) LIKE ?", ['%' . strtoupper($q) . '%'])
                        ->orWhereRaw("CAST(id AS TEXT) LIKE ?", ['%' . $q . '%']);
                })
                ->orderBy('name')
                ->limit(20)
                ->get()
                ->map(fn($r) => [
                    'id' => (int) ($r->id ?? 0),
                    'text' => trim((string) ($r->customernumber ?? '') . ' - ' . (string) ($r->name ?? '')),
                    'customer_name' => (string) ($r->name ?? ''),
                    'customernumber' => (string) ($r->customernumber ?? ''),
                ]);
        };

        $items = collect()
            ->merge($fetch('pgsqlw'))
            ->merge($fetch('pgsqlp'))
            ->unique('id')
            ->values()
            ->take(20);

        return response()->json(['items' => $items]);
    }

    public function partLookup(Request $request)
    {
        $this->userOr403();

        $q = trim((string) $request->query('q', ''));
        if ($q === '' || mb_strlen($q) < 2) {
            return response()->json(['items' => []]);
        }

        $fetch = function (string $conn) use ($q) {
            return DB::connection($conn)
                ->table('parts')
                ->where(function ($w) use ($q) {
                    $w->whereRaw("UPPER(TRIM(partnumber)) LIKE ?", [strtoupper($q) . '%'])
                        ->orWhereRaw("UPPER(TRIM(COALESCE(description, ''))) LIKE ?", ['%' . strtoupper($q) . '%']);
                })
                ->orderBy('partnumber')
                ->limit(20)
                ->get([
                    DB::raw('UPPER(TRIM(partnumber)) as fg_partnumber'),
                    'description as fg_description',
                    DB::raw("UPPER(LTRIM(RTRIM(COALESCE(f4, '')))) as rm_partnumber"),
                ])
                ->map(fn($r) => [
                    'fg_partnumber' => (string) ($r->fg_partnumber ?? ''),
                    'fg_description' => (string) ($r->fg_description ?? ''),
                    'rm_partnumber' => (string) ($r->rm_partnumber ?? ''),
                    'text' => trim((string) ($r->fg_partnumber ?? '') . ' - ' . (string) ($r->fg_description ?? '')),
                ]);
        };

        $items = collect()
            ->merge($fetch('pgsqlw'))
            ->merge($fetch('pgsqlp'))
            ->unique('fg_partnumber')
            ->values()
            ->take(20);

        return response()->json(['items' => $items]);
    }

    private function fetchFgPartSettings(string $salesCode, string $forecastBaseMonth, array $keys)
    {
        if (empty($keys)) return collect();

        $pairs = collect($keys)->map(function ($k) {
            [$customerId, $fgPartnumber] = explode('|', $k, 2);
            return [
                'customer_id' => (int) $customerId,
                'fg_partnumber' => (string) $fgPartnumber,
            ];
        })->values();

        $rows = collect();

        foreach ($pairs->chunk(500) as $chunk) {
            $rows = $rows->merge(
                DB::connection($this->fcConn)
                    ->table('fc_rm_division_part_setting')
                    ->where('sales_code', $salesCode)
                    ->where('forecast_base_month', $forecastBaseMonth)
                    ->where(function ($q) use ($chunk) {
                        foreach ($chunk as $p) {
                            $q->orWhere(function ($sub) use ($p) {
                                $sub->where('customer_id', $p['customer_id'])
                                    ->where('fg_partnumber', $p['fg_partnumber']);
                            });
                        }
                    })
                    ->get()
            );
        }

        return $rows->keyBy(fn($r) => (int) $r->customer_id . '|' . (string) $r->fg_partnumber);
    }

    private function fetchSavedForecastHistory(string $salesCode, string $forecastBaseMonth, array $keys)
    {
        if (empty($keys)) return collect();

        $pairs = collect($keys)->map(function ($k) {
            [$customerId, $fgPartnumber] = explode('|', $k, 2);
            return [
                'customer_id' => (int) $customerId,
                'fg_partnumber' => (string) $fgPartnumber,
            ];
        })->values();

        $rows = collect();

        foreach ($pairs->chunk(500) as $chunk) {
            $rows = $rows->merge(
                DB::connection($this->fcConn)
                    ->table('fc_rm_division_forecast')
                    ->select([
                        'forecast_base_month',
                        'customer_id',
                        'customer_name',
                        'fg_partnumber',
                        'k_factor',
                        'forecast_1m',
                        'forecast_6m',
                        'updated_at',
                        'created_at',
                    ])
                    ->where('sales_code', $salesCode)
                    ->where('forecast_base_month', $forecastBaseMonth)
                    ->where(function ($q) use ($chunk) {
                        foreach ($chunk as $p) {
                            $q->orWhere(function ($sub) use ($p) {
                                $sub->where('customer_id', $p['customer_id'])
                                    ->where('fg_partnumber', $p['fg_partnumber']);
                            });
                        }
                    })
                    ->get()
            );
        }

        return $rows
            ->keyBy(fn($r) => (int) $r->customer_id . '|' . (string) $r->fg_partnumber)
            ->map(function ($r) {
                return [[
                    'base_month'  => Carbon::parse($r->forecast_base_month)->format('Y-m'),
                    'k_factor'    => (float) ($r->k_factor ?? 0),
                    'forecast_1m' => (float) ($r->forecast_1m ?? 0),
                    'forecast_6m' => (float) ($r->forecast_6m ?? 0),
                    'saved_at'    => Carbon::parse($r->updated_at ?? $r->created_at)->format('Y-m-d H:i:s'),
                ]];
            });
    }

    private function fetchSalesOrderSummaryByFg(array $fgParts, string $companyMode = 'ALL')
    {
        $fgParts = collect($fgParts)
            ->map(fn($v) => strtoupper(trim((string) $v)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($fgParts)) {
            return collect();
        }

        $fetch = function (string $conn) use ($fgParts) {
            $ob = DB::connection($conn)->table('orderitems as oi')
                ->join('oe', 'oi.trans_id', '=', 'oe.id')
                ->leftJoin('oedm', 'oe.id', '=', 'oedm.ord_id')
                ->leftJoin('dm', 'oedm.dm_id', '=', 'dm.id')
                ->leftJoin('dmpredm', 'dm.id', '=', 'dmpredm.dm_id')
                ->leftJoin('dmitems as dmi', function ($join) {
                    $join->on('dmpredm.dmitems_id', '=', 'dmi.id')
                        ->on('dmi.parts_id', '=', 'oi.parts_id');
                })
                ->join('customer as cus', 'oe.customer_id', '=', 'cus.id')
                ->leftJoin('workorder as wo', 'oe.ordnumber', '=', 'wo.workordernumber')
                ->whereNotNull('oe.ordnumber')
                ->where('oe.shipped_or_received', false)
                ->where('oe.cancelled', false)
                ->where('oe.invoiced', false)
                ->groupBy([
                    'oe.ordnumber',
                    'oi.parts_id',
                    'oe.customer_id',
                    'cus.saleperson_id',
                    'oe.shipped_or_received',
                    'oe.invoiced',
                    'oi.qty',
                    'oe.transdate',
                    'oi.reqdate',
                    'wo.reqdate'
                ])
                ->selectRaw("
                MAX(oe.custponumber) AS po,
                oe.ordnumber AS ordnumber,
                oi.parts_id AS parts_id,
                oi.qty AS ordered_qty,
                oe.transdate AS order_date,
                oe.customer_id AS customer_id,
                cus.saleperson_id AS saleperson_id,
                COALESCE(oi.reqdate, wo.reqdate) AS due_date,
                oe.shipped_or_received,
                oe.invoiced,
                SUM(dmi.qty) AS shipped_qty
            ");

            $sb = DB::connection($conn)->table('invoice as inv')
                ->join('ar', 'inv.trans_id', '=', 'ar.id')
                ->whereNotNull('ar.ordnumber')
                ->groupBy(['ar.ordnumber', 'inv.parts_id'])
                ->selectRaw("
                ar.ordnumber AS ordnumber,
                inv.parts_id AS parts_id,
                SUM(inv.qty) AS shipped_qty,
                MAX(ar.transdate) AS last_invoice_date
            ");

            $rb = DB::connection($conn)->table('returnitems as rei')
                ->join('return as ret', 'rei.trans_id', '=', 'ret.id')
                ->join('invoice as inv', 'inv.id', '=', 'rei.invoice_id')
                ->join('ar', 'inv.trans_id', '=', 'ar.id')
                ->whereNotNull('ar.ordnumber')
                ->where('ret.hasitems', true)
                ->groupBy(['ar.ordnumber', 'inv.parts_id'])
                ->selectRaw("
                ar.ordnumber AS ordnumber,
                inv.parts_id AS parts_id,
                SUM(CASE WHEN ret.hasitems = TRUE THEN COALESCE(rei.qty,0) ELSE 0 END) AS return_qty
            ");

            return collect(
                DB::connection($conn)->query()
                    ->fromSub($ob, 'ob')
                    ->leftJoinSub($sb, 'sb', function ($j) {
                        $j->on('sb.ordnumber', '=', 'ob.ordnumber')
                            ->on('sb.parts_id', '=', 'ob.parts_id');
                    })
                    ->leftJoinSub($rb, 'rb', function ($j) {
                        $j->on('rb.ordnumber', '=', 'ob.ordnumber')
                            ->on('rb.parts_id', '=', 'ob.parts_id');
                    })
                    ->leftJoin('parts as p', 'p.id', '=', 'ob.parts_id')
                    ->whereIn(DB::raw('UPPER(TRIM(p.partnumber))'), $fgParts)
                    ->where('ob.shipped_or_received', false)
                    ->where('ob.invoiced', false)
                    ->whereRaw("ob.ordered_qty - (COALESCE(sb.shipped_qty,ob.shipped_qty,0) - COALESCE(rb.return_qty,0)) > 0")
                    ->selectRaw("
                    UPPER(TRIM(p.partnumber)) AS fg_partnumber,
                    (ob.ordered_qty - (COALESCE(sb.shipped_qty,ob.shipped_qty,0) - COALESCE(rb.return_qty,0))) AS backorder_qty
                ")
                    ->get()
            )->map(fn($r) => (array) $r);
        };

        $rows = $fetch('pgsqlw');

        return $rows
            ->groupBy(fn($r) => strtoupper(trim((string) ($r['fg_partnumber'] ?? ''))))
            ->map(fn($g) => round((float) collect($g)->sum('backorder_qty'), 2));
    }

    public function soDetail(Request $request)
    {
        $this->userOr403();

        $fgPart = strtoupper(trim((string) $request->query('fg_partnumber', '')));

        if ($fgPart === '') {
            return response()->json([]);
        }

        $fetch = function (string $conn, string $companyLabel) use ($fgPart) {
            $ob = DB::connection($conn)->table('orderitems as oi')
                ->join('oe', 'oi.trans_id', '=', 'oe.id')
                ->leftJoin('oedm', 'oe.id', '=', 'oedm.ord_id')
                ->leftJoin('dm', 'oedm.dm_id', '=', 'dm.id')
                ->leftJoin('dmpredm', 'dm.id', '=', 'dmpredm.dm_id')
                ->leftJoin('dmitems as dmi', function ($join) {
                    $join->on('dmpredm.dmitems_id', '=', 'dmi.id')
                        ->on('dmi.parts_id', '=', 'oi.parts_id');
                })
                ->join('customer as cus', 'oe.customer_id', '=', 'cus.id')
                ->leftJoin('workorder as wo', 'oe.ordnumber', '=', 'wo.workordernumber')
                ->whereNotNull('oe.ordnumber')
                ->where('oe.shipped_or_received', false)
                ->where('oe.cancelled', false)
                ->where('oe.invoiced', false)
                ->groupBy([
                    'oe.ordnumber',
                    'oi.parts_id',
                    'oe.customer_id',
                    'oe.shipped_or_received',
                    'oe.invoiced',
                    'oi.qty',
                    'oe.transdate',
                    'oi.reqdate',
                    'wo.reqdate',
                    'cus.name'
                ])
                ->selectRaw("
                MAX(oe.custponumber) AS po,
                oe.ordnumber AS ordnumber,
                oi.parts_id AS parts_id,
                oi.qty AS ordered_qty,
                oe.transdate AS order_date,
                oe.customer_id AS customer_id,
                COALESCE(oi.reqdate, wo.reqdate) AS due_date,
                oe.shipped_or_received,
                oe.invoiced,
                SUM(dmi.qty) AS shipped_qty,
                cus.name AS customer_name
            ");

            $sb = DB::connection($conn)->table('invoice as inv')
                ->join('ar', 'inv.trans_id', '=', 'ar.id')
                ->whereNotNull('ar.ordnumber')
                ->groupBy(['ar.ordnumber', 'inv.parts_id'])
                ->selectRaw("
                ar.ordnumber AS ordnumber,
                inv.parts_id AS parts_id,
                SUM(inv.qty) AS shipped_qty
            ");

            $rb = DB::connection($conn)->table('returnitems as rei')
                ->join('return as ret', 'rei.trans_id', '=', 'ret.id')
                ->join('invoice as inv', 'inv.id', '=', 'rei.invoice_id')
                ->join('ar', 'inv.trans_id', '=', 'ar.id')
                ->whereNotNull('ar.ordnumber')
                ->where('ret.hasitems', true)
                ->groupBy(['ar.ordnumber', 'inv.parts_id'])
                ->selectRaw("
                ar.ordnumber AS ordnumber,
                inv.parts_id AS parts_id,
                SUM(CASE WHEN ret.hasitems = TRUE THEN COALESCE(rei.qty,0) ELSE 0 END) AS return_qty
            ");

            return collect(
                DB::connection($conn)->query()
                    ->fromSub($ob, 'ob')
                    ->leftJoinSub($sb, 'sb', function ($j) {
                        $j->on('sb.ordnumber', '=', 'ob.ordnumber')
                            ->on('sb.parts_id', '=', 'ob.parts_id');
                    })
                    ->leftJoinSub($rb, 'rb', function ($j) {
                        $j->on('rb.ordnumber', '=', 'ob.ordnumber')
                            ->on('rb.parts_id', '=', 'ob.parts_id');
                    })
                    ->leftJoin('parts as p', 'p.id', '=', 'ob.parts_id')
                    ->whereRaw('UPPER(TRIM(p.partnumber)) = ?', [$fgPart])
                    ->where('ob.shipped_or_received', false)
                    ->where('ob.invoiced', false)
                    ->whereRaw("ob.ordered_qty - (COALESCE(sb.shipped_qty,ob.shipped_qty,0) - COALESCE(rb.return_qty,0)) > 0")
                    ->orderBy('ob.order_date')
                    ->selectRaw("
                    UPPER(TRIM(p.partnumber)) AS fg_partnumber,
                    p.description,
                    ob.po,
                    ob.ordnumber,
                    ob.order_date,
                    ob.due_date,
                    ob.customer_name,
                    (ob.ordered_qty - (COALESCE(sb.shipped_qty,ob.shipped_qty,0) - COALESCE(rb.return_qty,0))) AS backorder_qty,
                    ? AS company
                ", [$companyLabel])
                    ->get()
            )->map(function ($r) {
                $r = (array) $r;
                $r['backorder_qty'] = max((float) ($r['backorder_qty'] ?? 0), 0);
                return $r;
            })->filter(fn($r) => (float) $r['backorder_qty'] > 0);
        };

        $out = $fetch('pgsqlw', 'MENAM WIRE')
            ->sortBy('order_date')
            ->values();

        return response()->json($out);
    }

    public function index(Request $request)
    {
        $this->userOr403();

        $salesCode = $this->requestedOrResolvedSalesCode($request);
        if (!$salesCode || !$this->canUseSalesCode($salesCode)) {
            abort(403, 'ไม่มีสิทธิ์เข้าถึง division นี้');
        }

        $rmLike = strtoupper(trim((string) $request->query('sku', '')));
        $fgLike = strtoupper(trim((string) $request->query('fg', '')));
        $customerNameText = strtoupper(trim((string) $request->query('customer_name', '')));
        $selectedK = (float) $request->query('k_factor', 0);
        $companyMode = 'ALL';

        $rmKeywords = $this->parseMultiKeywords($rmLike);
        $fgKeywords = $this->parseMultiKeywords($fgLike);
        $customerNameKeywords = $this->parseMultiKeywords($customerNameText);

        $baseMonth = now('Asia/Bangkok')->startOfMonth();
        $forecastBaseMonth = $baseMonth->toDateString();

        $defaultK = (float) optional(
            DB::connection($this->fcConn)
                ->table('fc_rm_division_setting')
                ->where('sales_code', $salesCode)
                ->first()
        )->default_k_factor;

        if ($defaultK <= 0) {
            $defaultK = 1.0;
        }
        if ($selectedK <= 0) {
            $selectedK = $defaultK;
        }

        $historyMonths = collect(range(1, 6))
            ->map(fn($i) => (clone $baseMonth)->subMonths($i))
            ->reverse()
            ->values();

        $historyYm = $historyMonths->map(fn($d) => $d->format('Y-m'))->all();
        $historyLabels = $historyMonths->map(fn($d) => $d->format('M-y'))->all();
        $futureMonths = collect(range(1, 6))
            ->map(fn($i) => (clone $baseMonth)->addMonths($i));
        $futureYm = $futureMonths->map(fn($d) => $d->format('Y-m'))->all();
        $futureLabels = $futureMonths->map(fn($d) => $d->format('M-y'))->all();

        $raw = $this->fetchFgHistoryGrouped(
            $salesCode,
            $rmKeywords,
            $customerNameKeywords,
            (clone $baseMonth)->subMonths(6)->startOfMonth(),
            (clone $baseMonth)->subMonths(1)->endOfMonth(),
            $companyMode
        );

        $grouped = $raw
            ->groupBy(fn($r) => ($r['customer_id'] ?? 0) . '|' . ($r['fg_partnumber'] ?? ''))
            ->map(function ($rows, $key) use ($historyYm) {
                $first = collect($rows)->first();
                $monthMap = [];

                foreach ($rows as $r) {
                    $ym = Carbon::parse($r['month'])->format('Y-m');
                    $monthMap[$ym] = ($monthMap[$ym] ?? 0) + (float) ($r['qty_sum'] ?? 0);
                }

                $filled = collect($historyYm)->map(fn($ym) => (float) ($monthMap[$ym] ?? 0));
                $avg6 = round((float) $filled->avg(), 2);

                return [
                    'row_key' => $key,
                    'customer_id' => (int) ($first['customer_id'] ?? 0),
                    'customer_name' => (string) ($first['customer_name'] ?? '-'),
                    'fg_partnumber' => (string) ($first['fg_partnumber'] ?? ''),
                    'fg_description' => (string) ($first['fg_description'] ?? ''),
                    'rm_partnumber' => (string) ($first['rm_partnumber'] ?? ''),
                    'avg6' => $avg6,
                    'history_detail' => collect($historyYm)->map(fn($ym) => [
                        'ym' => $ym,
                        'qty' => (float) ($monthMap[$ym] ?? 0),
                    ])->values()->all(),
                ];
            })
            ->values();

        // FG filter เป็นตัวเสริม ไม่กระทบ flow หลัก RM -> FG
        if (!empty($fgKeywords)) {
            $grouped = $grouped->filter(function ($r) use ($fgKeywords) {
                $fg = strtoupper(trim((string) ($r['fg_partnumber'] ?? '')));

                foreach ($fgKeywords as $kw) {
                    if (str_contains($fg, $kw)) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        $existingGroupedKeys = $grouped->pluck('row_key')->map(fn($key) => (string) $key)->all();
        $olderCandidateRows = $this->fetchOlderCustomerFgCandidates(
            $salesCode,
            $rmKeywords,
            $customerNameKeywords,
            (clone $baseMonth)->subMonths(24)->startOfMonth(),
            (clone $baseMonth)->subMonths(7)->endOfMonth(),
            $companyMode
        );
        $catalogCandidateRows = $this->fetchDivisionCustomerFgCatalog(
            $salesCode,
            $rmKeywords,
            $customerNameKeywords,
            $companyMode
        );

        $seedCandidateRows = $olderCandidateRows->concat($catalogCandidateRows)
            ->groupBy(fn($r) => ($r['customer_id'] ?? 0) . '|' . ($r['fg_partnumber'] ?? ''))
            ->map(fn($g) => collect($g)->first())
            ->values();

        if ($seedCandidateRows->isNotEmpty()) {
            $grouped = $grouped->concat(
                $seedCandidateRows
                    ->reject(fn($r) => in_array((string) (($r['customer_id'] ?? 0) . '|' . ($r['fg_partnumber'] ?? '')), $existingGroupedKeys, true))
                    ->map(function ($r) use ($historyYm) {
                        return [
                            'row_key' => (string) (($r['customer_id'] ?? 0) . '|' . ($r['fg_partnumber'] ?? '')),
                            'customer_id' => (int) ($r['customer_id'] ?? 0),
                            'customer_name' => (string) ($r['customer_name'] ?? '-'),
                            'fg_partnumber' => (string) ($r['fg_partnumber'] ?? ''),
                            'fg_description' => (string) ($r['fg_description'] ?? ''),
                            'rm_partnumber' => (string) ($r['rm_partnumber'] ?? ''),
                            'avg6' => 0.00,
                            'history_detail' => collect($historyYm)->map(fn($ym) => [
                                'ym' => $ym,
                                'qty' => 0.00,
                            ])->values()->all(),
                        ];
                    })
            )->values();
        }

        $manualSeedRows = $this->fetchLatestManualSeedRows($salesCode, $forecastBaseMonth);
        $groupedKeys = $grouped->pluck('row_key')->map(fn($key) => (string) $key)->all();

        $missingManualSeeds = $manualSeedRows
            ->reject(fn($seed, $rowKey) => in_array((string) $rowKey, $groupedKeys, true));

        if (!empty($fgKeywords)) {
            $missingManualSeeds = $missingManualSeeds->filter(function ($seed) use ($fgKeywords) {
                $fg = strtoupper(trim((string) ($seed['fg_partnumber'] ?? '')));

                foreach ($fgKeywords as $kw) {
                    if (str_contains($fg, $kw)) {
                        return true;
                    }
                }

                return false;
            });
        }

        if (!empty($customerNameKeywords)) {
            $missingManualSeeds = $missingManualSeeds->filter(function ($seed) use ($customerNameKeywords) {
                $customerName = strtoupper(trim((string) ($seed['customer_name'] ?? '')));

                foreach ($customerNameKeywords as $kw) {
                    if (str_contains($customerName, $kw)) {
                        return true;
                    }
                }

                return false;
            });
        }

        $manualFgDetails = $this->fetchFgPartDetails(
            $missingManualSeeds->pluck('fg_partnumber')->filter()->unique()->values()->all(),
            $companyMode
        );

        if ($missingManualSeeds->isNotEmpty()) {
            $grouped = $grouped->concat(
                $missingManualSeeds->map(function ($seed) use ($historyYm, $manualFgDetails) {
                    $fgPartnumber = strtoupper(trim((string) ($seed['fg_partnumber'] ?? '')));
                    $fgDetail = $manualFgDetails->get($fgPartnumber, []);

                    return [
                        'row_key' => (string) ($seed['row_key'] ?? ''),
                        'customer_id' => (int) ($seed['customer_id'] ?? 0),
                        'customer_name' => (string) ($seed['customer_name'] ?? '-'),
                        'fg_partnumber' => $fgPartnumber,
                        'fg_description' => (string) (($seed['fg_description'] ?? '') !== ''
                            ? $seed['fg_description']
                            : ($fgDetail['fg_description'] ?? '')),
                        'rm_partnumber' => (string) ($fgDetail['rm_partnumber'] ?? ''),
                        'avg6' => 0.00,
                        'history_detail' => collect($historyYm)->map(fn($ym) => [
                            'ym' => $ym,
                            'qty' => 0.00,
                        ])->values()->all(),
                        'seed_is_selected' => (int) ($seed['is_selected'] ?? 0),
                        'seed_k_factor' => $seed['k_factor'] ?? null,
                        'seed_manual_forecast_1m' => $seed['manual_forecast_1m'] ?? null,
                        'seed_supplier_code' => (string) ($seed['supplier_code'] ?? ''),
                        'seed_supplier_name' => (string) ($seed['supplier_name'] ?? ''),
                        'seed_row_remark' => (string) ($seed['row_remark'] ?? ''),
                    ];
                })
            )->values();
        }

        $keys = $grouped->pluck('row_key')->all();

        $settings = $this->fetchFgPartSettings($salesCode, $forecastBaseMonth, $keys);
        $savedHistory = $this->fetchSavedForecastHistory($salesCode, $forecastBaseMonth, $keys);
        $divisionMasterConfig = $this->fetchDivisionPartMasterConfig($salesCode);

        $rmParts = $grouped->pluck('rm_partnumber')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $rmSupplierMap = $this->fetchSupplierMapByRmParts($rmParts);

        $hasKInput = $request->filled('k_factor');

        $rows = $grouped->map(function ($r) use (
            $settings,
            $selectedK,
            $savedHistory,
            $divisionMasterConfig,
            $rmSupplierMap,
            $hasKInput
        ) {
            $setting = $settings->get($r['row_key']);

            $rm = strtoupper(trim((string) ($r['rm_partnumber'] ?? '')));
            $cfg = $divisionMasterConfig->get($rm);

            $defaultSelected = array_key_exists('seed_is_selected', $r)
                ? (int) ($r['seed_is_selected'] ?? 0) === 1
                : (int) ($cfg->is_forecast ?? 0) === 1;

            $isSelected = $setting
                ? ((int) ($setting->is_selected ?? 0) === 1)
                : $defaultSelected;

            $savedRowK = $setting && $setting->k_factor !== null
                ? (float) $setting->k_factor
                : (isset($r['seed_k_factor']) && $r['seed_k_factor'] !== null ? (float) $r['seed_k_factor'] : null);

            // ถ้ามีการกรอก K แล้วกดโหลดข้อมูล ให้ใช้ K ใหม่
            // ถ้าไม่ได้กดโหลดด้วย K ใหม่ ค่อย fallback ไปค่า save เดิม
            $kUsed = $hasKInput
                ? $selectedK
                : ($savedRowK !== null ? $savedRowK : $selectedK);

            $defaultSupplier = $rmSupplierMap->get($rm, [
                'supplier_code' => '',
                'supplier_short_name' => '',
            ]);

            $manualSaved = $setting && $setting->manual_forecast_1m !== null
                ? (float) $setting->manual_forecast_1m
                : (isset($r['seed_manual_forecast_1m']) && $r['seed_manual_forecast_1m'] !== null
                    ? (float) $r['seed_manual_forecast_1m']
                    : null);

            $r['is_selected'] = $isSelected ? 1 : 0;
            $r['row_k_factor'] = $kUsed;
            $r['k_used'] = $kUsed;
            $r['save_history'] = $savedHistory->get($r['row_key'], []);
            $r['manual_forecast_saved'] = $manualSaved !== null ? 1 : 0;
            $r['row_remark'] = $setting
                ? (string) ($setting->row_remark ?? '')
                : (string) ($r['seed_row_remark'] ?? '');

            $r['supplier_code'] = $setting && !empty($setting->supplier_code)
                ? (string) $setting->supplier_code
                : (string) (($r['seed_supplier_code'] ?? '') !== '' ? $r['seed_supplier_code'] : ($defaultSupplier['supplier_code'] ?? ''));

            $r['supplier_name'] = $setting && !empty($setting->supplier_name)
                ? (string) $setting->supplier_name
                : (string) (($r['seed_supplier_name'] ?? '') !== '' ? $r['seed_supplier_name'] : ($defaultSupplier['supplier_short_name'] ?? ''));

            $calcForecast1m = $r['is_selected']
                ? round((float) $r['avg6'] * $kUsed, 2)
                : 0.00;

            // ช่อง input ให้มีค่าไว้แก้ได้
            // ถ้าเคย save manual จริง ค่อยใช้ manual นั้น
            $r['manual_forecast_1m'] = $manualSaved !== null ? $manualSaved : $calcForecast1m;

            $r['forecast_1m'] = $r['is_selected']
                ? round((float) $r['manual_forecast_1m'], 2)
                : 0.00;

            $r['forecast_6m'] = $r['is_selected']
                ? round((float) $r['forecast_1m'] * 6, 2)
                : 0.00;

            return $r;
        })->values();

        $fgParts = $rows->pluck('fg_partnumber')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $soMap = $this->fetchSalesOrderSummaryByFg($fgParts, $companyMode);

        $rows = $rows->map(function ($r) use ($soMap) {
            $fg = strtoupper(trim((string) ($r['fg_partnumber'] ?? '')));
            $r['sales_order_qty'] = (float) ($soMap->get($fg, 0) ?? 0);
            return $r;
        })->values();

        $manualOnlyRows = $rows
            ->filter(function ($r) {
                $rmPart = strtoupper(trim((string) ($r['rm_partnumber'] ?? '')));
                return (float) ($r['avg6'] ?? 0) <= 0 && $rmPart !== '';
            })
            ->groupBy(function ($r) {
                $customerId = (int) ($r['customer_id'] ?? 0);
                $rmPart = strtoupper(trim((string) ($r['rm_partnumber'] ?? '')));
                $fgPart = strtoupper(trim((string) ($r['fg_partnumber'] ?? '')));

                return $customerId . '|' . ($rmPart !== '' ? $rmPart : $fgPart);
            })
            ->map(function ($group) {
                return collect($group)
                    ->sortByDesc(function ($r) {
                        $manual = (float) ($r['manual_forecast_1m'] ?? 0);
                        $hasSupplier = !empty($r['supplier_code']) ? 1 : 0;
                        return ($manual > 0 ? 1000000 : 0) + $hasSupplier;
                    })
                    ->first();
            })
            ->values();
        $displayRows = $rows->filter(fn($r) => (float) ($r['avg6'] ?? 0) > 0)->values();

        $kpi = [
            'items' => $displayRows->count(),
            'avg6_sum' => (float) $displayRows->sum('avg6'),
            'forecast_1m_sum' => (float) $displayRows->sum('forecast_1m'),
            'forecast_6m_sum' => (float) $displayRows->sum('forecast_6m'),
        ];

        $allowedDivisions = $this->availableSalesCodesForUser();
        $showDivisionDropdown = count($allowedDivisions) > 1;

        return view('formfc.division_forecast', [
            'salesCode' => $salesCode,
            'rmLike' => $rmLike,
            'fgLike' => $fgLike,
            'customerNameText' => $customerNameText,
            'selectedK' => $selectedK,
            'historyYm' => $historyYm,
            'historyLabels' => $historyLabels,
            'futureYm' => $futureYm,
            'futureLabels' => $futureLabels,
            'rows' => $displayRows,
            'manualOnlyRows' => $manualOnlyRows,
            'kpi' => $kpi,
            'suppliers' => $this->fetchSupplierOptions(),
            'allowedDivisions' => $allowedDivisions,
            'showDivisionDropdown' => $showDivisionDropdown,
            'divisionLabels' => $this->divisionLabels,
        ]);
    }
    private function createForecastBatch(array $data): int
    {
        return (int) DB::connection($this->fcConn)
            ->table('fc_rm_division_forecast_batch')
            ->insertGetId($data);
    }

    private function sortRowsForSqlServer(array $rows): array
    {
        return array_map(function ($row) {
            ksort($row);
            return $row;
        }, $rows);
    }

    private function upsertRowsByChunk(string $table, array $rows, array $uniqueBy, array $updateColumns, int $maxParameters = 2000): void
    {
        if (empty($rows)) {
            return;
        }

        $rows = $this->sortRowsForSqlServer($rows);
        $columnCount = max(1, count($rows[0]));

        // SQL Server upsert uses MERGE; keep a safe buffer below the 2100-parameter limit.
        $chunkSize = max(1, (int) floor($maxParameters / ($columnCount * 2)));

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            DB::connection($this->fcConn)
                ->table($table)
                ->upsert($chunk, $uniqueBy, $updateColumns);
        }
    }

    public function generateFgForecast(Request $request)
    {
        $u = $this->userOr403();

        $validated = $request->validate([
            'sales_code' => ['required', 'string'],
            'customer_id' => ['nullable', 'string'],
            'customer_name' => ['nullable', 'string'],
            'k_factor' => ['required', 'regex:/^\d+(\.\d{1})$/'],
            'forecast_flag' => ['nullable', 'array'],
            'row_k_factor' => ['nullable', 'array'],
            'row_meta' => ['nullable', 'array'],
            'manual_forecast_1m' => ['nullable', 'array'],
            'row_remark' => ['nullable', 'array'],
            'supplier_code' => ['nullable', 'array'],
        ], [
            'k_factor.regex' => 'K Factor Default ต้องเป็นเลขทศนิยม 1 ตำแหน่ง เช่น 2.0',
        ]);


        foreach ((array) $request->input('row_k_factor', []) as $rk) {
            if ($rk !== null && $rk !== '' && !preg_match('/^\d+(\.\d{1})$/', (string) $rk)) {
                return back()->with('error', 'K รายแถวต้องเป็นเลขทศนิยม 1 ตำแหน่ง เช่น 1.5')->withInput();
            }
        }

        $salesCode = $this->normalizeSalesCode($validated['sales_code']);
        if (!$salesCode || !$this->canUseSalesCode($salesCode)) {
            abort(403, 'ไม่มีสิทธิ์บันทึก division นี้');
        }

        $companyMode = 'ALL';
        $defaultK = round((float) $validated['k_factor'], 1);
        $customerId = trim((string) ($validated['customer_id'] ?? ''));
        $customerName = trim((string) ($validated['customer_name'] ?? ''));
        $baseMonth = now('Asia/Bangkok')->startOfMonth()->toDateString();

        $manualRows = $request->input('manual_forecast_1m', []);
        $rowRemarks = $request->input('row_remark', []);
        $supplierCodes = $request->input('supplier_code', []);
        $suppliers = $this->fetchSupplierOptions()->keyBy('supplier_code');
        $forecastFlag = $request->input('forecast_flag', []);
        $rowKFactor = $request->input('row_k_factor', []);
        $rowMeta = $request->input('row_meta', []);

        $settingRows = [];
        $snapshotRows = [];
        $historyRows = [];

        foreach ($rowMeta as $rowKey => $metaJson) {
            $meta = json_decode((string) $metaJson, true);
            if (!is_array($meta)) continue;

            $custId = (int) ($meta['customer_id'] ?? 0);
            $custName = (string) ($meta['customer_name'] ?? '-');
            $fgPartnumber = strtoupper(trim((string) ($meta['fg_partnumber'] ?? '')));
            $fgDesc = (string) ($meta['fg_description'] ?? '');
            $rmPart = strtoupper(trim((string) ($meta['rm_partnumber'] ?? '')));
            $avg6 = round((float) ($meta['avg6'] ?? 0), 2);
            $salesOrderQty = round((float) ($meta['sales_order_qty'] ?? 0), 2);

            $manual1m = isset($manualRows[$rowKey]) && $manualRows[$rowKey] !== ''
                ? round((float) $manualRows[$rowKey], 2)
                : null;

            $rowRemark = trim((string) ($rowRemarks[$rowKey] ?? ''));
            $supplierCode = trim((string) ($supplierCodes[$rowKey] ?? ''));
            $supplier = $suppliers->get($supplierCode);
            $supplierName = $supplier
                ? (string) ($supplier['supplier_short_name'] ?? '')
                : null;

            if ($fgPartnumber === '') continue;

            $isSelected = isset($forecastFlag[$rowKey]) && (string) $forecastFlag[$rowKey] === '1';

            $kUsed = isset($rowKFactor[$rowKey]) && is_numeric($rowKFactor[$rowKey])
                ? round((float) $rowKFactor[$rowKey], 1)
                : $defaultK;

            $manualInput = isset($manualRows[$rowKey]) && $manualRows[$rowKey] !== ''
                ? round((float) $manualRows[$rowKey], 2)
                : null;

            $autoForecast1m = round($avg6 * $kUsed, 2);

            // manual เฉพาะตอนค่าที่ส่งมา "ต่าง" จาก auto ของ K รอบนี้
            $isManual = $manualInput !== null && abs($manualInput - $autoForecast1m) > 0.0001;

            $forecast1m = $isSelected
                ? ($isManual ? $manualInput : $autoForecast1m)
                : 0.00;

            $forecast6m = $isSelected ? round($forecast1m * 6, 2) : 0;

            $settingRows[] = [
                'sales_code' => $salesCode,
                'forecast_base_month' => $baseMonth,
                'customer_id' => $custId,
                'customer_name' => $custName,
                'fg_partnumber' => $fgPartnumber,
                'fg_description' => $fgDesc,
                'is_selected' => $isSelected ? 1 : 0,
                'k_factor' => $kUsed,
                'manual_forecast_1m' => $isManual ? $manualInput : null,
                'row_remark' => $rowRemark !== '' ? $rowRemark : null,
                'supplier_code' => $supplierCode !== '' ? $supplierCode : null,
                'supplier_name' => $supplierName,
                'updated_at' => now(),
                'updated_by' => $u->id ?? null,
            ];

            if ($isSelected) {
                $common = [
                    'sales_code' => $salesCode,
                    'forecast_base_month' => $baseMonth,
                    'company_mode' => $companyMode,
                    'customer_id' => $custId,
                    'customer_name' => $custName !== '' ? $custName : ($customerName !== '' ? $customerName : null),
                    'fg_partnumber' => $fgPartnumber,
                    'fg_description' => $fgDesc,
                    'rm_partnumber' => $rmPart !== '' ? $rmPart : null,
                    'history_avg6' => $avg6,
                    'k_factor' => $kUsed,
                    'forecast_month' => $baseMonth,
                    'forecast_qty' => $forecast1m,
                    'forecast_1m' => $forecast1m,
                    'forecast_6m' => $forecast6m,
                    'manual_forecast_1m' => $isManual ? $manualInput : null,
                    'source_type' => $isManual ? 'MANUAL_1M' : 'AUTO_K',
                    'is_selected' => 1,
                    'row_remark' => $rowRemark !== '' ? $rowRemark : null,
                    'supplier_code' => $supplierCode !== '' ? $supplierCode : null,
                    'supplier_name' => $supplierName,
                    'sales_order_qty' => $salesOrderQty,
                    'created_at' => now(),
                    'created_by' => $u->id ?? null,
                ];

                $snapshotRows[] = $common + [
                    'updated_at' => now(),
                    'updated_by' => $u->id ?? null,
                ];

                $historyRows[] = $common;
            }
        }

        if (empty($settingRows)) {
            return back()->with('error', 'ไม่พบข้อมูลสำหรับบันทึก');
        }

        DB::connection($this->fcConn)->transaction(function () use (
            $salesCode,
            $baseMonth,
            $companyMode,
            $defaultK,
            $settingRows,
            $snapshotRows,
            $historyRows,
            $customerId,
            $customerName,
            $u
        ) {
            $this->upsertRowsByChunk(
                'fc_rm_division_part_setting',
                $settingRows,
                ['sales_code', 'forecast_base_month', 'customer_id', 'fg_partnumber'],
                [
                    'customer_name',
                    'fg_description',
                    'is_selected',
                    'k_factor',
                    'manual_forecast_1m',
                    'row_remark',
                    'supplier_code',
                    'supplier_name',
                    'updated_at',
                    'updated_by'
                ]
            );

            $batchId = $this->createForecastBatch([
                'sales_code' => $salesCode,
                'forecast_base_month' => $baseMonth,
                'company_mode' => $companyMode,
                'customer_id' => $customerId !== '' ? $customerId : null,
                'customer_name' => $customerName !== '' ? $customerName : null,
                'default_k_factor' => $defaultK,
                'created_at' => now(),
                'created_by' => $u->id ?? null,
            ]);

            DB::connection($this->fcConn)
                ->table('fc_rm_division_forecast')
                ->where('sales_code', $salesCode)
                ->where('forecast_base_month', $baseMonth)
                ->when($customerId !== '', fn($q) => $q->where('customer_id', $customerId))
                ->delete();

            // สำคัญมาก: จัดลำดับ key ให้ตรงกันก่อน insert/upsert
            $this->upsertRowsByChunk(
                'fc_rm_division_part_setting',
                $settingRows,
                ['sales_code', 'forecast_base_month', 'customer_id', 'fg_partnumber'],
                [
                    'customer_name',
                    'fg_description',
                    'is_selected',
                    'k_factor',
                    'manual_forecast_1m',
                    'row_remark',
                    'supplier_code',
                    'supplier_name',
                    'updated_at',
                    'updated_by'
                ]
            );

            $snapshotRows = array_map(function ($r) use ($batchId) {
                $r['batch_id'] = $batchId;
                return $this->normalizeForecastRowForSqlServer($r);
            }, $snapshotRows);

            $historyRows = array_map(function ($r) use ($batchId) {
                $r['batch_id'] = $batchId;
                $r = $this->normalizeForecastRowForSqlServer($r);

                unset($r['forecast_1m'], $r['forecast_6m'], $r['updated_at'], $r['updated_by']);

                return $r;
            }, $historyRows);

            if (!empty($snapshotRows)) {
                $this->insertRowsByChunk('fc_rm_division_forecast', $snapshotRows, 50);
            }

            if (!empty($historyRows)) {
                $this->insertRowsByChunk('fc_rm_division_forecast_item_history', $historyRows, 50);
            }

            DB::connection($this->fcConn)
                ->table('fc_rm_division_setting')
                ->updateOrInsert(
                    ['sales_code' => $salesCode],
                    [
                        'default_k_factor' => $defaultK,
                        'updated_at' => now(),
                        'updated_by' => $u->id ?? null,
                    ]
                );
        });

        return back()->with('success', 'บันทึก Forecast เรียบร้อยแล้ว');
    }

    public function saveManual(Request $request)
    {
        $u = $this->userOr403();

        $salesCode = $this->normalizeSalesCode(
            (string) $request->input('sales_code', $request->input('division', ''))
        );

        if (!$salesCode || !$this->canUseSalesCode($salesCode)) {
            abort(403, 'ไม่มีสิทธิ์บันทึก division นี้');
        }

        $rows = $request->input('manual_rows', []);
        $metaRows = $request->input('manual_meta', []);
        if (!is_array($rows) || empty($rows)) {
            return back()->with('error', 'ไม่มีข้อมูล Manual Forecast สำหรับบันทึก');
        }

        $baseMonth = now('Asia/Bangkok')->startOfMonth()->toDateString();
        $settingRows = [];
        $snapshotRows = [];
        $historyRows = [];

        foreach ($rows as $rowKey => $qty) {
            $rowKey = (string) $rowKey;
            if ($rowKey === '') {
                continue;
            }

            $meta = json_decode((string) ($metaRows[$rowKey] ?? ''), true);
            if (!is_array($meta)) {
                continue;
            }

            $customerId = (int) ($meta['customer_id'] ?? 0);
            $customerName = trim((string) ($meta['customer_name'] ?? ''));
            $fgPartnumber = strtoupper(trim((string) ($meta['fg_partnumber'] ?? '')));
            $fgDescription = trim((string) ($meta['fg_description'] ?? ''));
            $rmPartnumber = strtoupper(trim((string) ($meta['rm_partnumber'] ?? '')));

            if ($customerId <= 0 || $fgPartnumber === '') {
                continue;
            }

            if ($qty === '' || $qty === null) {
                continue;
            }

            if (!is_numeric($qty)) {
                return back()->with('error', "ค่า Manual ของ {$fgPartnumber} ต้องเป็นตัวเลข")->withInput();
            }

            $qty = round((float) $qty, 2);
            if ($qty < 0) {
                return back()->with('error', "ค่า Manual ของ {$fgPartnumber} ต้องไม่ติดลบ")->withInput();
            }

            $settingRows[] = [
                'sales_code' => $salesCode,
                'forecast_base_month' => $baseMonth,
                'customer_id' => $customerId,
                'customer_name' => $customerName !== '' ? $customerName : null,
                'fg_partnumber' => $fgPartnumber,
                'fg_description' => $fgDescription !== '' ? $fgDescription : null,
                'is_selected' => 1,
                'k_factor' => 0,
                'manual_forecast_1m' => $qty,
                'row_remark' => null,
                'supplier_code' => null,
                'supplier_name' => null,
                'updated_at' => now(),
                'updated_by' => $u->id ?? null,
            ];

            $common = [
                'sales_code' => $salesCode,
                'forecast_base_month' => $baseMonth,
                'company_mode' => 'ALL',
                'customer_id' => $customerId,
                'customer_name' => $customerName !== '' ? $customerName : null,
                'fg_partnumber' => $fgPartnumber,
                'fg_description' => $fgDescription !== '' ? $fgDescription : null,
                'rm_partnumber' => $rmPartnumber !== '' ? $rmPartnumber : null,
                'history_avg6' => 0,
                'k_factor' => 0,
                'manual_forecast_1m' => $qty,
                'row_remark' => null,
                'supplier_code' => null,
                'supplier_name' => null,
                'sales_order_qty' => null,
                'forecast_month' => $baseMonth,
                'forecast_qty' => $qty,
                'forecast_1m' => $qty,
                'forecast_6m' => round($qty * 6, 2),
                'source_type' => 'MANUAL',
                'is_selected' => 1,
                'created_at' => now(),
                'created_by' => $u->id ?? null,
            ];

            $snapshotRows[] = $common + [
                'updated_at' => now(),
                'updated_by' => $u->id ?? null,
            ];

            $historyRows[] = $common;
        }

        if (empty($snapshotRows)) {
            return back()->with('error', 'ไม่มีข้อมูล Manual Forecast ที่กรอกไว้')->withInput();
        }

        DB::connection($this->fcConn)->transaction(function () use ($salesCode, $baseMonth, $settingRows, $snapshotRows, $historyRows, $u) {
            $this->upsertRowsByChunk(
                'fc_rm_division_part_setting',
                $settingRows,
                ['sales_code', 'forecast_base_month', 'customer_id', 'fg_partnumber'],
                ['customer_name', 'fg_description', 'is_selected', 'k_factor', 'manual_forecast_1m', 'row_remark', 'supplier_code', 'supplier_name', 'updated_at', 'updated_by']
            );

            $batchId = $this->createForecastBatch([
                'sales_code' => $salesCode,
                'forecast_base_month' => $baseMonth,
                'company_mode' => 'ALL',
                'customer_id' => null,
                'customer_name' => null,
                'default_k_factor' => 0,
                'created_at' => now(),
                'created_by' => $u->id ?? null,
            ]);

            $snapshotRows = array_map(function ($r) use ($batchId) {
                $r['batch_id'] = $batchId;
                return $this->normalizeForecastRowForSqlServer($r);
            }, $snapshotRows);

            $historyRows = array_map(function ($r) use ($batchId) {
                $r['batch_id'] = $batchId;
                $r = $this->normalizeForecastRowForSqlServer($r);
                unset($r['forecast_1m'], $r['forecast_6m'], $r['updated_at'], $r['updated_by']);
                return $r;
            }, $historyRows);

            $this->upsertRowsByChunk(
                'fc_rm_division_forecast',
                $snapshotRows,
                ['sales_code', 'forecast_base_month', 'customer_id', 'fg_partnumber', 'forecast_month'],
                ['forecast_qty', 'manual_forecast_1m', 'forecast_1m', 'forecast_6m', 'row_remark', 'source_type', 'updated_at', 'updated_by', 'batch_id']
            );

            $this->insertRowsByChunk('fc_rm_division_forecast_item_history', $historyRows, 50);
        });

        return back()->with('success', 'บันทึก Manual Forecast เรียบร้อยแล้ว');
    }

    private function normalizeForecastRowForSqlServer(array $r): array
    {
        return [
            'batch_id'            => isset($r['batch_id']) ? (int) $r['batch_id'] : null,
            'company_mode'        => isset($r['company_mode']) ? (string) $r['company_mode'] : null,
            'created_at'          => $r['created_at'] ?? now(),
            'created_by'          => isset($r['created_by']) ? (int) $r['created_by'] : null,
            'customer_id'         => isset($r['customer_id']) && $r['customer_id'] !== '' ? (int) $r['customer_id'] : null,
            'customer_name'       => isset($r['customer_name']) ? (string) $r['customer_name'] : null,
            'fg_description'      => isset($r['fg_description']) ? (string) $r['fg_description'] : null,
            'fg_partnumber'       => isset($r['fg_partnumber']) ? (string) $r['fg_partnumber'] : null,
            'forecast_1m'         => isset($r['forecast_1m']) ? round((float) $r['forecast_1m'], 2) : null,
            'forecast_6m'         => isset($r['forecast_6m']) ? round((float) $r['forecast_6m'], 2) : null,
            'forecast_base_month' => $r['forecast_base_month'] ?? null,
            'forecast_month'      => $r['forecast_month'] ?? null,
            'forecast_qty'        => isset($r['forecast_qty']) ? round((float) $r['forecast_qty'], 2) : null,
            'history_avg6'        => isset($r['history_avg6']) ? round((float) $r['history_avg6'], 2) : null,
            'is_selected'         => !empty($r['is_selected']) ? 1 : 0,
            'k_factor'            => isset($r['k_factor']) ? round((float) $r['k_factor'], 1) : null,
            'manual_forecast_1m'  => isset($r['manual_forecast_1m']) ? round((float) $r['manual_forecast_1m'], 2) : null,
            'rm_partnumber'       => isset($r['rm_partnumber']) && $r['rm_partnumber'] !== '' ? (string) $r['rm_partnumber'] : null,
            'row_remark'          => isset($r['row_remark']) && $r['row_remark'] !== '' ? (string) $r['row_remark'] : null,
            'sales_code'          => isset($r['sales_code']) ? (string) $r['sales_code'] : null,
            'sales_order_qty'     => isset($r['sales_order_qty']) ? round((float) $r['sales_order_qty'], 2) : null,
            'source_type'         => isset($r['source_type']) ? (string) $r['source_type'] : null,
            'supplier_code'       => isset($r['supplier_code']) && $r['supplier_code'] !== '' ? (string) $r['supplier_code'] : null,
            'supplier_name'       => isset($r['supplier_name']) && $r['supplier_name'] !== '' ? (string) $r['supplier_name'] : null,
            'updated_at'          => $r['updated_at'] ?? now(),
            'updated_by'          => isset($r['updated_by']) ? (int) $r['updated_by'] : null,
        ];
    }

    private function insertRowsByChunk(string $table, array $rows, int $chunkSize = 50): void
    {
        $rows = array_map(function ($row) {
            ksort($row);
            return $row;
        }, $rows);

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            DB::connection($this->fcConn)
                ->table($table)
                ->insert($chunk);
        }
    }
}
