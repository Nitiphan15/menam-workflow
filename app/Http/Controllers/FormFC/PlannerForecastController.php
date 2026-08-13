<?php

namespace App\Http\Controllers\FormFC;

use App\Http\Controllers\Controller;
use App\Support\FormFcPeriod;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PlannerForecastController extends Controller
{
    private string $fcConn = 'sqlsrv_menam';
    private string $plannerCode = 'PLN';

    private function bumpForecastIndexCacheVersion(): void
    {
        Cache::forever('forecast_rm:index:version', (int) Cache::get('forecast_rm:index:version', 1) + 1);
    }

    private function userOr403()
    {
        $u = auth()->user();
        if (!$u && !app()->environment('local')) {
            abort(403, 'ยังไม่ได้เข้าสู่ระบบ');
        }
        return $u;
    }

    private function parseMultiKeywords($value): array
    {
        if (is_array($value)) {
            return collect($value)
                ->map(fn($v) => strtoupper(trim((string) $v)))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        return collect(preg_split('/[\s,|;]+/', strtoupper(trim((string) $value))))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function fetchPlannerMasterParts()
    {
        return DB::connection($this->fcConn)
            ->table('fc_rm_planner_part_master')
            ->where('active', 1)
            ->orderBy('rm_partnumber')
            ->get();
    }

    private function fetchPlannerHistoryByMasterRm(
        array $rmParts,
        Carbon $start,
        Carbon $end
    ): Collection {
        if (empty($rmParts)) {
            return collect();
        }

        $fetch = function (string $conn) use ($rmParts, $start, $end) {
            $placeholders = implode(',', array_fill(0, count($rmParts), '?'));

            $sql = <<<SQL
            SELECT
                UPPER(TRIM(p.partnumber)) AS rm_partnumber,
                UPPER(TRIM(p_fg.partnumber)) AS fg_partnumber,
                p_fg.description AS fg_description,
                DATE_TRUNC('month', gl.transdate)::date AS month,
                SUM(ROUND(COALESCE(su.qty, 0), 2)) AS qty_sum
            FROM serializeunitsmvmt sus
            LEFT JOIN serializeunits su
                ON su.id = sus.su_id
            LEFT JOIN gl
                ON gl.id = sus.trans_id
            LEFT JOIN parts p
                ON p.id = su.parts_id
            JOIN workorder wo
                ON wo.workordernumber = gl.reference
            LEFT JOIN parts p_fg
                ON p_fg.id = wo.parts_id
            WHERE gl.transnumber LIKE ?
              AND UPPER(TRIM(p.partnumber)) IN ($placeholders)
              AND gl.transdate >= ?
              AND gl.transdate <= ?
              AND p_fg.partnumber IS NOT NULL
            GROUP BY
                UPPER(TRIM(p.partnumber)),
                UPPER(TRIM(p_fg.partnumber)),
                p_fg.description,
                DATE_TRUNC('month', gl.transdate)::date
            ORDER BY rm_partnumber, month
            SQL;

            $bindings = array_merge(
                ['IUB%'],
                $rmParts,
                [$start->toDateString(), $end->toDateString()]
            );

            return collect(DB::connection($conn)->select($sql, $bindings))
                ->map(fn($r) => (array) $r);
        };

        return collect()
            ->merge($fetch('pgsqlw'))
            ->merge($fetch('pgsqlp'));
    }

    private function fetchPlannerSoCustomerByFg(array $fgParts): Collection
    {
        if (empty($fgParts)) {
            return collect();
        }

        $fetch = function (string $conn, string $companyLabel) use ($fgParts) {
            $sb = DB::connection($conn)->table('ordershipbatch as osb')
                ->selectRaw('osb.ordnumber, osb.parts_id, SUM(COALESCE(osb.shipped_qty,0)) AS shipped_qty')
                ->groupBy('osb.ordnumber', 'osb.parts_id');

            $rb = DB::connection($conn)->table('orderreturnbatch as orb')
                ->selectRaw('orb.ordnumber, orb.parts_id, SUM(COALESCE(orb.return_qty,0)) AS return_qty')
                ->groupBy('orb.ordnumber', 'orb.parts_id');

            return collect(
                DB::connection($conn)->table('orderbatch as ob')
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
                    ->whereRaw('ob.ordered_qty - (COALESCE(sb.shipped_qty, ob.shipped_qty, 0) - COALESCE(rb.return_qty, 0)) > 0')
                    ->selectRaw("
                        UPPER(TRIM(p.partnumber)) AS fg_partnumber,
                        UPPER(TRIM(ob.ordnumber)) AS ordnumber,
                        UPPER(TRIM(COALESCE(ob.customer_name, ''))) AS customer_name,
                        ? AS company
                    ", [$companyLabel])
                    ->get()
            )->map(function ($r) {
                return [
                    'fg_partnumber' => strtoupper(trim((string) ($r->fg_partnumber ?? ''))),
                    'ordnumber' => strtoupper(trim((string) ($r->ordnumber ?? ''))),
                    'customer_name' => strtoupper(trim((string) ($r->customer_name ?? ''))),
                    'company' => (string) ($r->company ?? ''),
                ];
            });
        };

        return collect()
            ->merge($fetch('pgsqlw', 'MENAM WIRE'))
            ->merge($fetch('pgsqlp', 'MENAM PLUS'))
            ->filter(fn($r) => !empty($r['fg_partnumber']))
            ->values();
    }

    /*private function buildPlannerFilterOptions(Collection $rows): array
    {
        return [
            'fgOptions' => $rows->pluck('fg_partnumber')->filter()->unique()->sort()->values()->all(),
            'soOptions' => $rows->pluck('ordnumber')->filter()->unique()->sort()->values()->all(),
            'customerOptions' => $rows->pluck('customer_name')->filter()->unique()->sort()->values()->all(),
        ];
    }*/

    private function createForecastBatch(array $data): int
    {
        if (empty($data['company_mode'])) {
            $data['company_mode'] = 'ALL';
        }

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

    private function normalizeForecastRowForSqlServer(array $r): array
    {
        return [
            'batch_id'            => isset($r['batch_id']) ? (int) $r['batch_id'] : null,
            'company_mode'        => isset($r['company_mode']) ? (string) $r['company_mode'] : null,
            'created_at'          => $r['created_at'] ?? now(),
            'created_by'          => isset($r['created_by']) ? (int) $r['created_by'] : null,
            'customer_id'         => null,
            'customer_name'       => null,
            'fg_description'      => isset($r['fg_description']) ? (string) $r['fg_description'] : null,
            'fg_partnumber'       => isset($r['fg_partnumber']) ? (string) $r['fg_partnumber'] : null,
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
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            DB::connection($this->fcConn)->table($table)->insert($chunk);
        }
    }

    private function buildPlannerFilterOptions(\Illuminate\Support\Collection $rows): array
    {
        return [
            'fgOptions' => $rows->pluck('fg_partnumber')->filter()->unique()->sort()->values()->all(),
            'soOptions' => $rows->pluck('so_text')->filter()->unique()->sort()->values()->all(),
            'customerOptions' => $rows->pluck('customer_text')->filter()->unique()->sort()->values()->all(),
        ];
    }

    public function index(Request $request)
    {
        $this->userOr403();

        $tz = 'Asia/Bangkok';
        $base = now($tz)->startOfMonth();
        $forecastBaseMonth = $base->toDateString();

        $fgKeywordText = strtoupper(trim((string) $request->query('sku', '')));
        $selectedFg = $this->parseMultiKeywords($request->query('fg', []));
        $selectedSo = $this->parseMultiKeywords($request->query('so', []));
        $selectedCustomer = $this->parseMultiKeywords($request->query('customer', []));
        $companyMode = 'ALL';

        $settingHead = DB::connection($this->fcConn)
            ->table('fc_rm_division_setting')
            ->where('sales_code', $this->plannerCode)
            ->first();

        $defaultK = round((float) ($settingHead->default_k_factor ?? 0), 1);
        if ($defaultK <= 0) {
            $defaultK = 1.2;
        }

        $selectedKInput = $request->query('k_factor', null);
        $selectedK = is_null($selectedKInput) || $selectedKInput === ''
            ? $defaultK
            : round((float) $selectedKInput, 1);

        if ($selectedK <= 0) {
            $selectedK = $defaultK;
        }

        if (!preg_match('/^\d+(\.\d{1})$/', number_format((float) $defaultK, 1, '.', ''))) {
            $defaultK = 1.2;
        }

        if (!preg_match('/^\d+(\.\d{1})$/', number_format((float) $selectedK, 1, '.', ''))) {
            $selectedK = $defaultK;
        }

        $autoForecastEnabled = (int) ($settingHead->auto_forecast_enabled ?? 0) === 1;
        $autoForecastDay = (int) ($settingHead->auto_forecast_day ?? 1);
        if ($autoForecastDay < 1 || $autoForecastDay > 31) {
            $autoForecastDay = 1;
        }

        $historyMonths = collect(range(0, FormFcPeriod::MONTHS - 1))
            ->map(fn($i) => (clone $base)->subMonths($i))
            ->reverse()
            ->values();

        $historyYm = $historyMonths->map(fn($d) => $d->format('Y-m'))->all();
        $historyLabels = $historyMonths->map(fn($d) => $d->format('M-y'))->all();

        $futureMonths = collect(range(1, FormFcPeriod::MONTHS))
            ->map(fn($i) => (clone $base)->addMonths($i))
            ->values();

        $futureYm = $futureMonths->map(fn($d) => $d->format('Y-m'))->all();
        $futureLabels = $futureMonths->map(fn($d) => $d->format('M-y'))->all();

        $masterParts = $this->fetchPlannerMasterParts();

        if ($fgKeywordText !== '') {
            $masterParts = $masterParts->filter(function ($m) use ($fgKeywordText) {
                return str_contains(strtoupper((string) ($m->rm_partnumber ?? '')), $fgKeywordText)
                    || str_contains(strtoupper((string) ($m->rm_description ?? '')), $fgKeywordText);
            })->values();
        }

        $settings = DB::connection($this->fcConn)
            ->table('fc_rm_division_part_setting')
            ->where('sales_code', $this->plannerCode)
            ->where('forecast_base_month', $forecastBaseMonth)
            ->whereNull('customer_id')
            ->get()
            ->keyBy('fg_partnumber');

        $savedHistory = DB::connection($this->fcConn)
            ->table('fc_rm_division_forecast_item_history')
            ->where('sales_code', $this->plannerCode)
            ->where('forecast_base_month', $forecastBaseMonth)
            ->whereNull('customer_id')
            ->orderByDesc('id')
            ->get()
            ->groupBy('fg_partnumber')
            ->map(function ($g) {
                return $g->take(FormFcPeriod::MONTHS)->map(function ($r) {
                    return [
                        'forecast_month' => (string) $r->forecast_month,
                        'forecast_qty' => (float) $r->forecast_qty,
                        'source_type' => (string) ($r->source_type ?? ''),
                    ];
                })->values()->all();
            });

        $rmParts = $masterParts->pluck('rm_partnumber')
            ->map(fn($p) => strtoupper(trim((string) $p)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $historyRaw = $this->fetchPlannerHistoryByMasterRm(
            $rmParts,
            (clone $base)->subMonths(FormFcPeriod::MONTHS - 1)->startOfMonth(),
            (clone $base)->endOfMonth()
        );

        $historyMap = $historyRaw
            ->groupBy(fn($r) => strtoupper(trim((string) ($r['rm_partnumber'] ?? ''))))
            ->map(function ($rows, $rmPart) use ($historyYm) {
                $first = collect($rows)->first();
                $monthMap = [];

                foreach ($rows as $r) {
                    $ym = Carbon::parse($r['month'])->format('Y-m');
                    $monthMap[$ym] = ($monthMap[$ym] ?? 0) + (float) $r['qty_sum'];
                }

                $filled = collect($historyYm)->map(fn($ym) => (float) ($monthMap[$ym] ?? 0));
                $avg6 = round((float) $filled->avg(), 2);

                return [
                    'rm_partnumber' => $rmPart,
                    'fg_partnumber' => (string) ($first['fg_partnumber'] ?? ''),
                    'fg_description' => (string) ($first['fg_description'] ?? ''),
                    'avg6' => $avg6,
                    'history_detail' => collect($historyYm)->map(fn($ym) => [
                        'ym' => $ym,
                        'qty' => (float) ($monthMap[$ym] ?? 0),
                    ])->values()->all(),
                ];
            });

        $rows = $masterParts->map(function ($m) use ($historyMap, $settings, $selectedK, $savedHistory) {
            $rm = strtoupper(trim((string) ($m->rm_partnumber ?? '')));

            $his = $historyMap->get($rm, [
                'rm_partnumber' => $rm,
                'fg_partnumber' => '',
                'fg_description' => '',
                'avg6' => 0,
                'history_detail' => [],
            ]);

            $fgPart = strtoupper(trim((string) ($his['fg_partnumber'] ?? '')));
            if ($fgPart === '') {
                return null;
            }

            $setting = $settings->get($fgPart);
            $avg6 = (float) ($his['avg6'] ?? 0);

            $savedRowK = $setting && $setting->k_factor !== null
                ? round((float) $setting->k_factor, 1)
                : null;

            $isSelected = $setting
                ? ((int) ($setting->is_selected ?? 0) === 1)
                : true;

            $kUsed = $savedRowK !== null ? $savedRowK : $selectedK;

            $manualForecast1m = $setting && $setting->manual_forecast_1m !== null
                ? (float) $setting->manual_forecast_1m
                : null;

            $rowRemark = (string) ($setting->row_remark ?? '');

            $calcForecast1m = round((float) $avg6 * $kUsed, 2);

            if ($manualForecast1m === null) {
                $manualForecast1m = $calcForecast1m;
            }

            $forecast1m = $isSelected ? round((float) $manualForecast1m, 2) : 0;
            $forecast6m = $isSelected ? round((float) $forecast1m * FormFcPeriod::MONTHS, 2) : 0;

            return [
                'row_key' => $fgPart,
                'fg_partnumber' => $fgPart,
                'fg_description' => (string) ($his['fg_description'] ?? ''),
                'rm_partnumber' => $rm,
                'rm_description' => (string) ($m->rm_description ?? ''),
                'avg6' => $avg6,
                'is_selected' => $isSelected ? 1 : 0,
                'row_k_factor' => $kUsed,
                'k_used' => $kUsed,
                'manual_forecast_1m' => $manualForecast1m,
                'row_remark' => $rowRemark,
                'forecast_1m' => $forecast1m,
                'forecast_6m' => $forecast6m,
                'history_detail' => $his['history_detail'] ?? [],
                'save_history' => $savedHistory->get($fgPart, []),
                'has_6m_history' => $avg6 > 0,
                'so_list' => [],
                'customer_list' => [],
                'so_text' => '',
                'customer_text' => '',
            ];
        })->filter()->values();

        $rows = $rows->map(function ($r) {
            $r['so_list'] = $r['so_list'] ?? [];
            $r['customer_list'] = $r['customer_list'] ?? [];
            $r['so_text'] = $r['so_text'] ?? '';
            $r['customer_text'] = $r['customer_text'] ?? '';
            return $r;
        })->values();

        $filterOptions = $this->buildPlannerFilterOptions($rows);

        if (!empty($selectedFg)) {
            $rows = $rows->filter(function ($r) use ($selectedFg) {
                return in_array(strtoupper((string) ($r['fg_partnumber'] ?? '')), $selectedFg, true);
            })->values();
        }

        if (!empty($selectedSo)) {
            $rows = $rows->filter(function ($r) use ($selectedSo) {
                $soList = collect($r['so_list'] ?? [])->map(fn($v) => strtoupper((string) $v))->all();
                return count(array_intersect($soList, $selectedSo)) > 0;
            })->values();
        }

        if (!empty($selectedCustomer)) {
            $rows = $rows->filter(function ($r) use ($selectedCustomer) {
                $customerList = collect($r['customer_list'] ?? [])->map(fn($v) => strtoupper((string) $v))->all();
                return count(array_intersect($customerList, $selectedCustomer)) > 0;
            })->values();
        }

        $manualOnlyRows = $rows->filter(fn($r) => (float) ($r['avg6'] ?? 0) <= 0)->values();

        $kpi = [
            'items' => $rows->count(),
            'avg6_sum' => (float) $rows->sum('avg6'),
            'forecast_1m_sum' => (float) $rows->sum('forecast_1m'),
            'forecast_6m_sum' => (float) $rows->sum('forecast_6m'),
        ];

        return view('formfc.planner_forecast', [
            'fgLike' => $fgKeywordText,
            'selectedFg' => $selectedFg,
            'selectedSo' => $selectedSo,
            'selectedCustomer' => $selectedCustomer,
            'fgOptions' => $filterOptions['fgOptions'] ?? [],
            'soOptions' => $filterOptions['soOptions'] ?? [],
            'customerOptions' => $filterOptions['customerOptions'] ?? [],
            'forecastPeriodMonths' => FormFcPeriod::MONTHS,
            'selectedK' => (float) $selectedK,
            'historyYm' => $historyYm,
            'historyLabels' => $historyLabels,
            'futureYm' => $futureYm,
            'futureLabels' => $futureLabels,
            'rows' => $rows,
            'manualOnlyRows' => $manualOnlyRows,
            'kpi' => $kpi,
            'autoForecastEnabled' => $autoForecastEnabled,
            'autoForecastDay' => $autoForecastDay,
            'companyMode' => $companyMode,
        ]);
    }

    public function generate(Request $request)
    {
        $u = $this->userOr403();

        $validated = $request->validate([
            'k_factor' => ['required', 'regex:/^\d+(\.\d{1})$/'],
            'forecast_flag' => ['nullable', 'array'],
            'row_k_factor' => ['nullable', 'array'],
            'row_meta' => ['nullable', 'array'],
            'manual_forecast_1m' => ['nullable', 'array'],
            'row_remark' => ['nullable', 'array'],
            'auto_forecast_enabled' => ['nullable', 'in:0,1'],
            'auto_forecast_day' => ['nullable', 'integer', 'min:1', 'max:31'],
        ], [
            'k_factor.regex' => 'K Factor Default ต้องเป็นเลขทศนิยม 1 ตำแหน่ง เช่น 2.0',
        ]);

        foreach ((array) $request->input('row_k_factor', []) as $rk) {
            if ($rk !== null && $rk !== '' && !preg_match('/^\d+(\.\d{1})$/', (string) $rk)) {
                return back()->with('error', 'K รายแถวต้องเป็นเลขทศนิยม 1 ตำแหน่ง เช่น 1.5')->withInput();
            }
        }

        $defaultK = round((float) $validated['k_factor'], 1);
        $baseMonth = now('Asia/Bangkok')->startOfMonth()->toDateString();

        $manualRows = $request->input('manual_forecast_1m', []);
        $rowRemarks = $request->input('row_remark', []);
        $forecastFlag = $request->input('forecast_flag', []);
        $rowKFactor = $request->input('row_k_factor', []);
        $rowMeta = $request->input('row_meta', []);
        $autoEnabled = (string) $request->input('auto_forecast_enabled', '0') === '1';
        $autoDay = (int) $request->input('auto_forecast_day', 1);

        if ($autoDay < 1 || $autoDay > 31) {
            $autoDay = 1;
        }

        $settingRows = [];
        $snapshotRows = [];
        $historyRows = [];

        $futureMonths = collect(range(1, FormFcPeriod::MONTHS))
            ->map(fn($i) => now('Asia/Bangkok')->startOfMonth()->addMonths($i)->toDateString())
            ->values();

        foreach ($rowMeta as $rowKey => $metaJson) {
            $meta = json_decode((string) $metaJson, true);
            if (!is_array($meta)) {
                continue;
            }

            $fgPart = strtoupper(trim((string) ($meta['fg_partnumber'] ?? $rowKey)));
            if ($fgPart === '') {
                continue;
            }

            $fgDesc = trim((string) ($meta['fg_description'] ?? $meta['rm_description'] ?? ''));
            $rmPart = strtoupper(trim((string) ($meta['rm_partnumber'] ?? '')));
            $avg6 = round((float) ($meta['avg6'] ?? 0), 2);

            $isSelected = isset($forecastFlag[$rowKey]) && (string) $forecastFlag[$rowKey] === '1';
            $kUsed = isset($rowKFactor[$rowKey]) && $rowKFactor[$rowKey] !== ''
                ? round((float) $rowKFactor[$rowKey], 1)
                : $defaultK;

            $manualInput = isset($manualRows[$rowKey]) && $manualRows[$rowKey] !== ''
                ? round((float) $manualRows[$rowKey], 2)
                : null;

            $rowRemark = trim((string) ($rowRemarks[$rowKey] ?? ''));

            $calcForecast1m = round((float) $avg6 * $kUsed, 2);

            if ($manualInput === null) {
                $manualInput = $calcForecast1m;
            }

            $forecast1m = $isSelected ? round((float) $manualInput, 2) : 0;
            $forecast6m = $isSelected ? round((float) $forecast1m * FormFcPeriod::MONTHS, 2) : 0;

            $sourceType = ($avg6 <= 0)
                ? 'MANUAL'
                : ((abs($forecast1m - $calcForecast1m) > 0.0001) ? 'MANUAL_1M' : 'AUTO_K');

            $settingRows[] = [
                'sales_code' => $this->plannerCode,
                'forecast_base_month' => $baseMonth,
                'customer_id' => null,
                'customer_name' => null,
                'fg_partnumber' => $fgPart,
                'fg_description' => $fgDesc,
                'is_selected' => $isSelected ? 1 : 0,
                'k_factor' => $kUsed,
                'manual_forecast_1m' => $manualInput,
                'row_remark' => $rowRemark !== '' ? $rowRemark : null,
                'updated_at' => now(),
                'updated_by' => $u->id ?? null,
            ];

            if (!$isSelected) {
                continue;
            }

            $common = [
                'sales_code' => $this->plannerCode,
                'forecast_base_month' => $baseMonth,
                'company_mode' => 'ALL',
                'customer_id' => null,
                'customer_name' => null,
                'fg_partnumber' => $fgPart,
                'fg_description' => $fgDesc,
                'rm_partnumber' => $rmPart !== '' ? $rmPart : null,
                'history_avg6' => $avg6,
                'k_factor' => $kUsed,
                'manual_forecast_1m' => $manualInput,
                'row_remark' => $rowRemark !== '' ? $rowRemark : null,
                'supplier_code' => null,
                'supplier_name' => null,
                'sales_order_qty' => null,
                'forecast_month' => $baseMonth,
                'forecast_qty' => $forecast1m,
                'forecast_1m' => $forecast1m,
                'forecast_6m' => $forecast6m,
                'source_type' => $sourceType,
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

        if (empty($settingRows)) {
            return back()->with('error', 'ไม่พบข้อมูลสำหรับบันทึก');
        }

        $settingRows = collect($settingRows)
            ->unique(function ($r) {
                return implode('|', [
                    (string) ($r['sales_code'] ?? ''),
                    (string) ($r['forecast_base_month'] ?? ''),
                    is_null($r['customer_id'] ?? null) ? 'NULL' : (string) $r['customer_id'],
                    strtoupper(trim((string) ($r['fg_partnumber'] ?? ''))),
                ]);
            })
            ->values()
            ->all();

        DB::connection($this->fcConn)->transaction(function () use (
            $baseMonth,
            $defaultK,
            $settingRows,
            $snapshotRows,
            $historyRows,
            $u,
            $autoEnabled,
            $autoDay
        ) {
            DB::connection($this->fcConn)
                ->table('fc_rm_division_part_setting')
                ->where('sales_code', $this->plannerCode)
                ->where('forecast_base_month', $baseMonth)
                ->whereNull('customer_id')
                ->delete();

            if (!empty($settingRows)) {
                $settingRows = collect($settingRows)
                    ->unique(function ($r) {
                        return implode('|', [
                            (string) ($r['sales_code'] ?? ''),
                            (string) ($r['forecast_base_month'] ?? ''),
                            is_null($r['customer_id'] ?? null) ? 'NULL' : (string) $r['customer_id'],
                            strtoupper(trim((string) ($r['fg_partnumber'] ?? ''))),
                        ]);
                    })
                    ->values()
                    ->all();

                DB::connection($this->fcConn)
                    ->table('fc_rm_division_part_setting')
                    ->insert($settingRows);
            }

            $batchId = $this->createForecastBatch([
                'sales_code' => $this->plannerCode,
                'forecast_base_month' => $baseMonth,
                'company_mode' => 'ALL',
                'customer_id' => null,
                'customer_name' => null,
                'default_k_factor' => $defaultK,
                'created_at' => now(),
                'created_by' => $u->id ?? null,
            ]);

            DB::connection($this->fcConn)
                ->table('fc_rm_division_forecast')
                ->where('sales_code', $this->plannerCode)
                ->where('forecast_base_month', $baseMonth)
                ->whereNull('customer_id')
                ->delete();

            if (!empty($snapshotRows)) {
                $snapshotRows = array_map(function ($r) use ($batchId) {
                    $r['batch_id'] = $batchId;
                    return $this->normalizeForecastSnapshotRowForSqlServer($r);
                }, $snapshotRows);

                $snapshotRows = $this->sortRowsForSqlServer($snapshotRows);
                $this->insertRowsByChunk('fc_rm_division_forecast', $snapshotRows, 50);
            }

            if (!empty($historyRows)) {
                $historyRows = array_map(function ($r) use ($batchId) {
                    $r['batch_id'] = $batchId;
                    return $this->normalizeForecastHistoryRowForSqlServer($r);
                }, $historyRows);

                $historyRows = $this->sortRowsForSqlServer($historyRows);
                $this->insertRowsByChunk('fc_rm_division_forecast_item_history', $historyRows, 50);
            }

            DB::connection($this->fcConn)
                ->table('fc_rm_division_setting')
                ->updateOrInsert(
                    ['sales_code' => $this->plannerCode],
                    [
                        'default_k_factor' => $defaultK,
                        'auto_forecast_enabled' => $autoEnabled ? 1 : 0,
                        'auto_forecast_day' => $autoDay,
                        'updated_at' => now(),
                        'updated_by' => $u->id ?? null,
                    ]
                );
        });

        $this->bumpForecastIndexCacheVersion();

        return back()->with('success', 'บันทึก Planner Forecast เรียบร้อยแล้ว');
    }

    public function saveManual(Request $request)
    {
        $u = $this->userOr403();

        $baseMonth = now('Asia/Bangkok')->startOfMonth()->toDateString();
        $rows = $request->input('manual_rows', []);
        $metaRows = $request->input('manual_meta', []);

        if (!is_array($rows) || empty($rows)) {
            return back()->with('error', 'ไม่มีข้อมูล Manual Forecast สำหรับบันทึก');
        }

        $settingRows = [];
        $snapshotRows = [];
        $historyRows = [];

        foreach ($rows as $fgPart => $qty) {
            $fgPart = strtoupper(trim((string) $fgPart));
            if ($fgPart === '') {
                continue;
            }

            if ($qty === '' || $qty === null) {
                continue;
            }

            if (!is_numeric($qty)) {
                return back()->with('error', "ค่า Manual ของ {$fgPart} ต้องเป็นตัวเลข")->withInput();
            }

            $qty = round((float) $qty, 2);
            if ($qty < 0) {
                return back()->with('error', "ค่า Manual ของ {$fgPart} ต้องไม่ติดลบ")->withInput();
            }

            $meta = json_decode((string) ($metaRows[$fgPart] ?? ''), true);
            $rmPart = strtoupper(trim((string) ($meta['rm_partnumber'] ?? '')));
            $fgDescription = trim((string) ($meta['fg_description'] ?? ''));

            $settingRows[] = [
                'sales_code' => $this->plannerCode,
                'forecast_base_month' => $baseMonth,
                'customer_id' => null,
                'customer_name' => null,
                'fg_partnumber' => $fgPart,
                'fg_description' => $fgDescription !== '' ? $fgDescription : null,
                'is_selected' => 1,
                'k_factor' => 0,
                'manual_forecast_1m' => $qty,
                'row_remark' => null,
                'updated_at' => now(),
                'updated_by' => $u->id ?? null,
            ];

            $common = [
                'sales_code' => $this->plannerCode,
                'forecast_base_month' => $baseMonth,
                'company_mode' => 'ALL',
                'customer_id' => null,
                'customer_name' => null,
                'fg_partnumber' => $fgPart,
                'fg_description' => $fgDescription !== '' ? $fgDescription : null,
                'rm_partnumber' => $rmPart !== '' ? $rmPart : null,
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
                'forecast_6m' => round($qty * FormFcPeriod::MONTHS, 2),
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

        DB::connection($this->fcConn)->transaction(function () use ($baseMonth, $settingRows, $snapshotRows, $historyRows, $u) {
            DB::connection($this->fcConn)
                ->table('fc_rm_division_part_setting')
                ->where('sales_code', $this->plannerCode)
                ->where('forecast_base_month', $baseMonth)
                ->whereNull('customer_id')
                ->whereIn('fg_partnumber', collect($settingRows)->pluck('fg_partnumber')->all())
                ->delete();

            if (!empty($settingRows)) {
                DB::connection($this->fcConn)
                    ->table('fc_rm_division_part_setting')
                    ->insert($settingRows);
            }

            $batchId = $this->createForecastBatch([
                'sales_code' => $this->plannerCode,
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
                return $this->normalizeForecastSnapshotRowForSqlServer($r);
            }, $snapshotRows);

            $historyRows = array_map(function ($r) use ($batchId) {
                $r['batch_id'] = $batchId;
                return $this->normalizeForecastHistoryRowForSqlServer($r);
            }, $historyRows);

            $snapshotRows = $this->sortRowsForSqlServer($snapshotRows);
            $historyRows = $this->sortRowsForSqlServer($historyRows);

            DB::connection($this->fcConn)
                ->table('fc_rm_division_forecast')
                ->upsert(
                    $snapshotRows,
                    ['sales_code', 'forecast_base_month', 'customer_id', 'fg_partnumber', 'forecast_month'],
                    ['forecast_qty', 'manual_forecast_1m', 'forecast_1m', 'forecast_6m', 'row_remark', 'source_type', 'updated_at', 'updated_by', 'batch_id']
                );

            $this->insertRowsByChunk('fc_rm_division_forecast_item_history', $historyRows, 50);
        });

        $this->bumpForecastIndexCacheVersion();

        return back()->with('success', 'บันทึก Manual Forecast เรียบร้อยแล้ว');
    }

    private function normalizeForecastSnapshotRowForSqlServer(array $r): array
    {
        return [
            'batch_id'            => isset($r['batch_id']) ? (int) $r['batch_id'] : null,
            'company_mode'        => isset($r['company_mode']) ? (string) $r['company_mode'] : 'ALL',
            'created_at'          => $r['created_at'] ?? now(),
            'created_by'          => isset($r['created_by']) ? (int) $r['created_by'] : null,
            'customer_id'         => null,
            'customer_name'       => null,
            'fg_description'      => isset($r['fg_description']) ? (string) $r['fg_description'] : null,
            'fg_partnumber'       => isset($r['fg_partnumber']) ? (string) $r['fg_partnumber'] : null,
            'forecast_1m'         => isset($r['forecast_1m']) ? round((float) $r['forecast_1m'], 2) : null,
            'forecast_6m'         => isset($r['forecast_1m'])
                ? round((float) $r['forecast_1m'] * FormFcPeriod::MONTHS, 2)
                : null,
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

    private function normalizeForecastHistoryRowForSqlServer(array $r): array
    {
        return [
            'batch_id'            => isset($r['batch_id']) ? (int) $r['batch_id'] : null,
            'company_mode'        => isset($r['company_mode']) ? (string) $r['company_mode'] : 'ALL',
            'created_at'          => $r['created_at'] ?? now(),
            'created_by'          => isset($r['created_by']) ? (int) $r['created_by'] : null,
            'customer_id'         => null,
            'customer_name'       => null,
            'fg_description'      => isset($r['fg_description']) ? (string) $r['fg_description'] : null,
            'fg_partnumber'       => isset($r['fg_partnumber']) ? (string) $r['fg_partnumber'] : null,
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
        ];
    }
}
