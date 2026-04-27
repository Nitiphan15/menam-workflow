<?php

namespace App\Http\Controllers\FormFC;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ForecastRmController extends Controller
{
    private string $fcConn = 'sqlsrv_menam';

    private function conn(string $name)
    {
        return DB::connection($name);
    }

    private function userOr403()
    {
        $u = auth()->user();
        if (!$u && !app()->environment('local')) {
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

        return (int)($u->superadmin ?? 0) === 1;
    }

    private function normalizeSku(?string $sku): string
    {
        $sku = strtoupper(trim((string) $sku));
        $sku = preg_replace('/\s+/', '', $sku);
        return (string) $sku;
    }

    private function normalizeSalesCode(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));
        $code = str_replace(['.', ' '], '', $code); // D.2 => D2
        if (preg_match('/^D([1-9])$/', $code, $m)) {
            return 'D' . $m[1];
        }
        return null;
    }

    private function allSalesCodes(): array
    {
        return ['D1', 'D2', 'D3', 'D4', 'D5', 'D6', 'D7', 'D8', 'D9'];
    }

    private function supplierTooltipSalesCodes(): array
    {
        return ['D1', 'D2', 'D3', 'D5', 'D6', 'D7', 'D9'];
    }

    private function resolveUserSalesCode(): ?string
    {
        $u = auth()->user();
        if (!$u) return null;

        foreach ($this->allSalesCodes() as $code) {
            if (method_exists($u, 'hasRoleCode') && $u->hasRoleCode($code)) {
                return $code;
            }
        }

        $fromField = $this->normalizeSalesCode($u->sales_code ?? null);
        if ($fromField) {
            return $fromField;
        }

        return null;
    }

    private function canUseSalesCode(string $salesCode): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->resolveUserSalesCode() === $salesCode;
    }
    private function canEditSalesCode(string $salesCode): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $mine = $this->resolveUserSalesCode();
        return $mine !== null && $mine === $salesCode;
    }

    public function divisionForecast(Request $request)
    {
        $this->userOr403();

        $tz = 'Asia/Bangkok';
        $salesCode = $this->normalizeSalesCode($request->query('sales_code'));

        if (!$salesCode) {
            $salesCode = $this->resolveUserSalesCode();
        }

        if (!$salesCode) {
            abort(403, 'ไม่พบ division ของผู้ใช้งาน');
        }

        if (!$this->canUseSalesCode($salesCode)) {
            abort(403, 'ไม่มีสิทธิ์เข้าถึง division นี้');
        }

        $skuLike = $this->normalizeSkuFilter($request->query('sku', ''));


        $companyMode = strtoupper(trim((string) $request->query('company', 'ALL')));
        if (!in_array($companyMode, ['ALL', 'WIRE', 'PLUS'], true)) {
            $companyMode = 'ALL';
        }

        $useWire = in_array($companyMode, ['ALL', 'WIRE'], true);
        $usePlus = in_array($companyMode, ['ALL', 'PLUS'], true);

        $base = now($tz)->startOfMonth();

        // ย้อนหลัง 6 เดือน ไม่รวมเดือนปัจจุบัน
        $historyMonths = collect(range(1, 6))
            ->map(fn($i) => (clone $base)->subMonths($i))
            ->reverse()
            ->values();

        $historyYm = $historyMonths->map(fn($d) => $d->format('Y-m'))->all();
        $historyLabels = $historyMonths->map(fn($d) => $d->format('M-y'))->all();

        // อนาคต 6 เดือน
        $futureMonths = collect(range(1, 6))
            ->map(fn($i) => (clone $base)->addMonths($i))
            ->values();

        $futureYm = $futureMonths->map(fn($d) => $d->format('Y-m'))->all();
        $futureLabels = $futureMonths->map(fn($d) => $d->format('M-y'))->all();

        $rangeStart = (clone $base)->subMonths(6)->startOfMonth();
        $rangeEnd   = (clone $base)->subMonths(1)->endOfMonth();

        $historyBySku = collect();

        if ($useWire) {
            $historyBySku = $historyBySku->merge(
                $this->fetchDivisionHistoryMonthRange('pgsqlw', $salesCode, $skuLike, $rangeStart, $rangeEnd)
            );
        }

        if ($usePlus) {
            $historyBySku = $historyBySku->merge(
                $this->fetchDivisionHistoryMonthRange('pgsqlp', $salesCode, $skuLike, $rangeStart, $rangeEnd)
            );
        }

        $historyBySku = $historyBySku
            ->groupBy(fn($v, $k) => $k)
            ->map(function ($group) {
                $out = [];
                foreach ($group as $row) {
                    foreach ($row as $ym => $val) {
                        $out[$ym] = ($out[$ym] ?? 0) + (float) $val;
                    }
                }
                return $out;
            });

        $avgIndex = $historyBySku->map(function ($map) use ($historyYm) {
            $filled = collect($historyYm)->map(fn($ym) => (float) ($map[$ym] ?? 0));
            return [
                'avg6' => round((float) $filled->avg(), 2),
            ];
        });

        $stocks = collect();
        if ($useWire) {
            $stocks = $stocks->merge($this->fetchOnhandSafe('pgsqlw', 'WIRE', $skuLike));
        }
        if ($usePlus) {
            $stocks = $stocks->merge($this->fetchOnhandSafe('pgsqlp', 'PLUS', $skuLike));
        }

        $stockIndex = $stocks->groupBy('partnumber')->map(function ($g) {
            return [
                'description' => (string) ($g->first()['description'] ?? ''),
                'unit'        => (string) ($g->first()['unit'] ?? ''),
                'onhand'      => (float) $g->sum('onhand'),
            ];
        });

        $poLinesAll = collect();
        if ($useWire) {
            $poLinesAll = $poLinesAll->merge($this->fetchOpenPOR('pgsqlw', 'WIRE', $skuLike));
        }
        if ($usePlus) {
            $poLinesAll = $poLinesAll->merge($this->fetchOpenPOR('pgsqlp', 'PLUS', $skuLike));
        }

        $poTotalIndex = $poLinesAll
            ->groupBy('rm_partnumber')
            ->map(fn($g) => (float) $g->sum('open'));

        $skuSet = collect($avgIndex->keys())
            ->unique()
            ->filter(fn($x) => Str::startsWith(strtoupper((string) $x), 'R'))
            ->sort()
            ->values();



        $rows = $skuSet->map(function ($sku) use ($historyBySku, $historyYm, $avgIndex, $stockIndex, $poTotalIndex) {
            $map = $historyBySku->get($sku, []);
            $avg = $avgIndex->get($sku, ['avg6' => 0]);
            $stock = $stockIndex->get($sku, []);

            $row = [
                'sku'         => $sku,
                'description' => $stock['description'] ?? '',
                'avg6'        => (float) ($avg['avg6'] ?? 0),
                'onhand'      => (float) ($stock['onhand'] ?? 0),
                'fg'          => (float) ($fgIndex[$sku] ?? 0),
                'po_total'    => (float) ($poTotalIndex[$sku] ?? 0),
            ];

            foreach ($historyYm as $ym) {
                $row['h_' . str_replace('-', '_', $ym)] = (float) ($map[$ym] ?? 0);
            }

            return $row;
        })->values();

        return view('formfc.division_forecast', [
            'salesCode'     => $salesCode,
            'skuLike'       => $skuLike,
            'companyMode'   => $companyMode,
            'historyYm'     => $historyYm,
            'historyLabels' => $historyLabels,
            'futureYm'      => $futureYm,
            'futureLabels'  => $futureLabels,
            'rows'          => $rows,
            'kOptions'      => [1.2, 1.5, 1.8, 2.0],
        ]);
    }

    public function generateDivisionForecast(Request $request)
    {
        $u = $this->userOr403();

        $salesCode = $this->normalizeSalesCode($request->input('sales_code'));
        if (!$salesCode || !$this->canUseSalesCode($salesCode)) {
            abort(403, 'ไม่มีสิทธิ์บันทึก division นี้');
        }

        $kFactor = (float) $request->input('k_factor', 1.2);
        if (!in_array($kFactor, [1.2, 1.5, 1.8, 2.0], true)) {
            return back()->with('error', 'ค่า K ไม่ถูกต้อง');
        }

        $rows = $request->input('rows', []);
        if (empty($rows)) {
            return back()->with('error', 'ไม่พบข้อมูลที่ต้องบันทึก');
        }

        $base = now('Asia/Bangkok')->startOfMonth();
        $futureMonths = collect(range(1, 6))
            ->map(fn($i) => (clone $base)->addMonths($i)->toDateString())
            ->values();

        DB::connection($this->fcConn)->transaction(function () use ($rows, $salesCode, $kFactor, $futureMonths, $u) {
            foreach ($rows as $sku => $data) {
                $sku = strtoupper(trim((string) $sku));
                if ($sku === '' || !Str::startsWith($sku, 'R')) {
                    continue;
                }

                $avg6 = (float) ($data['avg6'] ?? 0);
                $forecastQty = round($avg6 * $kFactor, 2);

                foreach ($futureMonths as $month) {
                    DB::connection($this->fcConn)->table('fc_rm_division_forecast')->updateOrInsert(
                        [
                            'sales_code'     => $salesCode,
                            'sku'            => $sku,
                            'forecast_month' => $month,
                        ],
                        [
                            'base_avg6'    => $avg6,
                            'k_factor'     => $kFactor,
                            'forecast_qty' => $forecastQty,
                            'source_type'  => 'MANUAL_K',
                            'updated_at'   => now(),
                            'updated_by'   => $u->id ?? null,
                            'created_at'   => now(),
                            'created_by'   => $u->id ?? null,
                        ]
                    );
                }
            }

            DB::connection($this->fcConn)->table('fc_rm_division_setting')->updateOrInsert(
                ['sales_code' => $salesCode],
                [
                    'default_k_factor' => $kFactor,
                    'updated_at'       => now(),
                    'updated_by'       => $u->id ?? null,
                ]
            );
        });

        return back()->with('success', 'สร้าง Forecast 6 เดือนล่วงหน้าเรียบร้อยแล้ว');
    }

    private function fetchDivisionHistoryMonthRange(string $conn, string $salesCode, string $skuLike, Carbon $start, Carbon $end)
    {
        $salespersonIdsByCode = [
            'D1' => [1506],
            'D2' => [1507],
            'D3' => [1431],
            'D4' => [1432],
            'D5' => [1433],
            'D6' => [1434],
            'D7' => [1435],
            'D8' => [1436],
            'D9' => [528615586],
        ];

        $salespersonIds = $salespersonIdsByCode[strtoupper($salesCode)] ?? [];
        if (empty($salespersonIds)) {
            return collect();
        }

        $idList = implode(',', array_map('intval', $salespersonIds));

        $sql = <<<SQL
        SELECT
            UPPER(TRIM(rm.partnumber)) AS partnumber,
            DATE_TRUNC('month', wou.usagestamp)::date AS month,
            SUM(ROUND(COALESCE(wou.qty, 0), 2)) AS qty_sum
        FROM workorderusage wou
        JOIN workorder wo ON wo.id = wou.workorder_id
        JOIN parts rm ON rm.id = wou.parts_id
        JOIN customer cus ON cus.id = wo.customer_id
        WHERE UPPER(TRIM(rm.partnumber)) LIKE :item
        AND cus.saleperson_id IN ({$idList})
        AND wou.usagestamp >= :start
        AND wou.usagestamp <= :end
        GROUP BY UPPER(TRIM(rm.partnumber)), DATE_TRUNC('month', wou.usagestamp)::date
        ORDER BY partnumber, month
        SQL;

        $rows = collect(DB::connection($conn)->select($sql, [
            'item'  => strtoupper($skuLike) . '%',
            'start' => $start->toDateString(),
            'end'   => $end->toDateString(),
        ]))->map(fn($r) => (array) $r);

        return $rows->groupBy('partnumber')->map(function ($g) {
            return $g->mapWithKeys(function ($r) {
                $k = Carbon::parse($r['month'])->format('Y-m');
                return [$k => (float) $r['qty_sum']];
            });
        });
    }

    private function forecastIndexCacheKey(
        string $skuLike,
        string $companyMode,
        string $today,
        string $sinceAvg,
        string $planMonth,
        array $selectedSuppliers = [],
        array $selectedGrades = []
    ): string {
        return 'forecast_rm:index:' . md5(json_encode([
            $skuLike,
            $companyMode,
            $today,
            $sinceAvg,
            $planMonth,
            array_values($selectedSuppliers),
            array_values($selectedGrades),
        ]));
    }

    private function clearForecastIndexCache(?string $skuLike = null, ?string $companyMode = null, ?string $planMonth = null, ?string $sinceAvg = null): void
    {
        $tz = 'Asia/Bangkok';
        $today = now($tz)->startOfDay()->toDateString();

        $skuList = array_values(array_unique(array_filter([
            strtoupper(trim((string) $skuLike)),
            '',
        ], fn($v) => $v !== null)));

        $companyList = array_values(array_unique(array_filter([
            strtoupper(trim((string) $companyMode)) ?: 'ALL',
            'ALL',
        ])));

        $planList = array_values(array_unique(array_filter([
            $planMonth ? Carbon::parse($planMonth, $tz)->startOfMonth()->toDateString() : null,
            now($tz)->startOfMonth()->toDateString(),
        ])));

        $sinceList = array_values(array_unique(array_filter([
            $sinceAvg ? Carbon::parse($sinceAvg, $tz)->startOfDay()->toDateString() : null,
            now($tz)->subMonths(12)->startOfDay()->toDateString(),
        ])));

        foreach ($skuList as $sku) {
            foreach ($companyList as $company) {
                foreach ($planList as $plan) {
                    foreach ($sinceList as $since) {
                        Cache::forget($this->forecastIndexCacheKey($sku, $company, $today, $since, $plan));
                    }
                }
            }
        }
    }

    private function buildIndexDataset(
        string $skuLike,
        array $skuTerms,
        string $companyMode,
        string $planMonth,
        string $sinceAvg,
        array $selectedSuppliers = [],
        array $selectedGrades = [],
        string $tz = 'Asia/Bangkok'
    ): array {
        $useWire = in_array($companyMode, ['ALL', 'WIRE'], true);
        $usePlus = in_array($companyMode, ['ALL', 'PLUS'], true);

        $base = now($tz)->startOfMonth();

        $months = collect(range(1, 6))
            ->map(fn($i) => (clone $base)->subMonths($i))
            ->reverse()
            ->values();

        $cpa13Months = $months->map(fn($d) => $d->format('Y-m'))->all();
        $cpa13Labels = $months->map(fn($d) => $d->format('M-y'))->all();

        $rangeStart = (clone $base)->subMonths(6)->startOfMonth();
        $rangeEnd   = (clone $base)->subMonths(1)->endOfMonth();

        $cpa13BySkuMonth = collect();

        if ($useWire) {
            $cpa13BySkuMonth = $cpa13BySkuMonth->merge(
                $this->fetchCpa13MonthRange('pgsqlw', $skuTerms, $rangeStart, $rangeEnd)
            );
        }

        if ($usePlus) {
            $cpa13BySkuMonth = $cpa13BySkuMonth->merge(
                $this->fetchCpa13MonthRange('pgsqlp', $skuTerms, $rangeStart, $rangeEnd)
            );
        }

        $cpa13BySkuMonth = $cpa13BySkuMonth
            ->groupBy(fn($v, $k) => $k)
            ->map(function ($group) {
                $out = [];
                foreach ($group as $row) {
                    foreach ($row as $ym => $val) {
                        $out[$ym] = ($out[$ym] ?? 0) + (float) $val;
                    }
                }
                return $out;
            });

        $avgIndex = $cpa13BySkuMonth->map(function ($map) use ($cpa13Months) {
            $filled = collect($cpa13Months)->map(fn($ym) => (float) ($map[$ym] ?? 0));

            return [
                'avg3' => round((float) $filled->slice(-3)->avg(), 2),
                'avg6' => round((float) $filled->slice(-6)->avg(), 2),
            ];
        });

        $masterParts = collect();

        if ($useWire) {
            $masterParts = $masterParts->merge($this->fetchRmMasterParts('pgsqlw', $skuTerms));
        }

        if ($usePlus) {
            $masterParts = $masterParts->merge($this->fetchRmMasterParts('pgsqlp', $skuTerms));
        }

        $stockIndex = $masterParts->groupBy('partnumber')->map(function ($g) {
            return [
                'description' => (string) ($g->first()['description'] ?? ''),
                'unit'        => (string) ($g->first()['unit'] ?? ''),
                'grade'       => (string) ($g->first()['grade'] ?? 'OTHER'),
                'onhand'      => (float) $g->sum('onhand'),
            ];
        });

        $poLinesAll = collect();

        if ($useWire) {
            $poLinesAll = $poLinesAll->merge($this->fetchOpenPOR('pgsqlw', 'MENAM WIRE', $skuLike));
        }

        if ($usePlus) {
            $poLinesAll = $poLinesAll->merge($this->fetchOpenPOR('pgsqlp', 'MENAM PLUS', $skuLike));
        }

        $poDescIndex = $poLinesAll
            ->groupBy('rm_partnumber')
            ->map(fn($g) => (string) ($g->first()['description'] ?? ''));

        $poTotalIndex = $poLinesAll
            ->groupBy('rm_partnumber')
            ->map(fn($g) => (float) $g->sum('open'));

        $fgIndex = collect();

        if ($useWire) {
            $fgIndex = $fgIndex->merge(
                $this->fetchFgIndex('pgsqlw', $skuLike)->map(fn($v, $k) => ['sku' => $k, 'qty' => $v])
            );
        }

        if ($usePlus) {
            $fgIndex = $fgIndex->merge(
                $this->fetchFgIndex('pgsqlp', $skuLike)->map(fn($v, $k) => ['sku' => $k, 'qty' => $v])
            );
        }

        $fgIndex = $fgIndex
            ->groupBy('sku')
            ->map(fn($g) => round((float) $g->sum('qty'), 2));

        $wipIndex = collect();
        if ($useWire) {
            $wipIndex = $wipIndex->merge(
                $this->fetchWipIndex('pgsqlw', $skuLike)->map(fn($v, $k) => ['sku' => $k, 'qty' => $v])
            );
        }
        if ($usePlus) {
            $wipIndex = $wipIndex->merge(
                $this->fetchWipIndex('pgsqlp', $skuLike)->map(fn($v, $k) => ['sku' => $k, 'qty' => $v])
            );
        }
        $wipIndex = $wipIndex
            ->groupBy('sku')
            ->map(fn($g) => (float) $g->sum('qty'));

        $soIndex = collect();
        if ($useWire) {
            $soIndex = $soIndex->merge(
                $this->fetchSoIndex('pgsqlw', $skuLike)->map(fn($v, $k) => ['sku' => $k, 'qty' => $v])
            );
        }
        $soIndex = $soIndex
            ->groupBy('sku')
            ->map(fn($g) => (float) $g->sum('qty'));

        $salesInput = $this->fetchSavedDivisionForecastIndex($planMonth, $skuTerms, $companyMode);

        $skuSet = collect()
            ->merge($stockIndex->keys())
            ->merge($poTotalIndex->keys())
            ->merge($fgIndex->keys())
            ->merge($wipIndex->keys())
            ->merge($soIndex->keys())
            ->merge($salesInput->keys())
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $supplierIndex = $this->fetchSupplierMapIndex($skuSet->all(), $selectedSuppliers);

        if (!empty($selectedSuppliers)) {
            $skuSet = $skuSet->filter(fn($sku) => $supplierIndex->has($sku))->values();
        }

        $rows = $skuSet->map(function ($sku) use (
            $stockIndex,
            $poDescIndex,
            $poTotalIndex,
            $avgIndex,
            $salesInput,
            $fgIndex,
            $wipIndex,
            $soIndex,
            $supplierIndex,
            $selectedGrades
        ) {
            $stock = $stockIndex->get($sku, []);
            $avg   = $avgIndex->get($sku, ['avg3' => 0, 'avg6' => 0]);

            $salesWrap = $salesInput->get($sku, [
                'forecast_by_sales' => [],
                'supplier_name_by_sales' => [],
            ]);

            $sales = $salesWrap['forecast_by_sales'] ?? [];
            $forecastSupplierBySales = $salesWrap['supplier_name_by_sales'] ?? [];

            $description = trim((string) ($stock['description'] ?? ($poDescIndex[$sku] ?? '')));
            if ($description === '') {
                return null;
            }

            $supplier = $supplierIndex->get($sku, []);
            $autoSupplierName = trim((string) ($supplier['primary_supplier_name'] ?? ''));

            $supplierByDivision = [];
            foreach ($this->supplierTooltipSalesCodes() as $d) {
                $divSupplier = trim((string) ($forecastSupplierBySales[$d] ?? ''));
                if ($divSupplier !== '') {
                    $supplierByDivision[$d] = $divSupplier;
                }
            }

            $materialGrade = strtoupper(trim((string) ($stock['grade'] ?? '')));
            if ($materialGrade === '') {
                $materialGrade = 'OTHER';
            }

            $row['grade'] = $materialGrade;

            $row = [
                'sku'                   => $sku,
                'description'           => $description,
                'grade'                 => $materialGrade,
                'unit'                  => $stock['unit'] ?? '',
                'avg3'                  => (float) ($avg['avg3'] ?? 0),
                'avg6'                  => (float) ($avg['avg6'] ?? 0),
                'onhand'                => (float) ($stock['onhand'] ?? 0),
                'fg'                    => (float) ($fgIndex[$sku] ?? 0),
                'po_total'              => (float) ($poTotalIndex[$sku] ?? 0),
                'wip'                   => (float) ($wipIndex[$sku] ?? 0),
                'so'                    => (float) ($soIndex[$sku] ?? 0),
                'primary_supplier_code' => (string) ($supplier['primary_supplier_code'] ?? ''),
                'primary_supplier_name' => $autoSupplierName,
                'supplier_count'        => (int) ($supplier['supplier_count'] ?? 0),
                'suppliers'             => $supplier['suppliers'] ?? [],
                'supplier_by_division'  => $supplierByDivision,
            ];

            foreach ($this->allSalesCodes() as $d) {
                $row[strtolower($d)] = (float) ($sales[$d] ?? 0);
            }

            $salesWrap = $salesInput->get($sku, [
                'forecast_by_sales' => [],
                'planner_forecast' => 0,
                'supplier_name_by_sales' => [],
            ]);

            $sales = $salesWrap['forecast_by_sales'] ?? [];
            $plannerForecast = (float) ($salesWrap['planner_forecast'] ?? 0);
            $forecastSupplierBySales = $salesWrap['supplier_name_by_sales'] ?? [];

            // total forecast จากฝ่ายขาย D1-D9
            $row['total_forecast'] = collect($this->allSalesCodes())
                ->sum(fn($d) => (float) ($sales[$d] ?? 0));

            // safety forecast จาก planner (PLN)
            $row['safety_forecast_planner'] = $plannerForecast;

            // Demand ที่ใช้ซื้อ = SO + Forecast ฝ่ายขาย
            // ถ้าไม่มี forecast แต่มี SO ก็จะได้ SO ตามรูป
            $row['total_forecast_so'] = (float) $row['so'] + (float) $row['total_forecast'];

            // supply ที่มีอยู่
            $row['available_supply'] =
                (float) $row['onhand']
                + (float) $row['fg']
                + (float) $row['po_total']
                + (float) $row['wip'];

            // ต้องสั่งเพิ่ม = Demand - Supply
            $row['need_to_order'] = (float) $row['total_forecast_so'] - (float) $row['available_supply'];

            return $row;
        })->filter(function ($row) use ($selectedGrades) {
            if (!$row) return false;

            $hasData =
                (float) ($row['onhand'] ?? 0) != 0 ||
                (float) ($row['fg'] ?? 0) != 0 ||
                (float) ($row['po_total'] ?? 0) != 0 ||
                (float) ($row['wip'] ?? 0) != 0 ||
                (float) ($row['so'] ?? 0) != 0 ||
                (float) ($row['total_forecast'] ?? 0) != 0;

            if (!$hasData) return false;

            if (!empty($selectedGrades) && !in_array(strtoupper((string) ($row['grade'] ?? '')), $selectedGrades, true)) {
                return false;
            }

            return true;
        })->values();

        if (!empty($selectedSuppliers)) {
            $selectedSuppliersUpper = collect($selectedSuppliers)
                ->map(fn($x) => strtoupper(trim((string) $x)))
                ->filter()
                ->values()
                ->all();

            $rows = $rows->filter(function ($row) use ($selectedSuppliersUpper) {
                $name = strtoupper(trim((string) ($row['primary_supplier_name'] ?? '')));
                $code = strtoupper(trim((string) ($row['primary_supplier_code'] ?? '')));

                foreach ($selectedSuppliersUpper as $selected) {
                    if ($selected === $code || $selected === $name) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        return [
            'rows'            => $rows,
            'sinceAvg'        => $sinceAvg,
            'cpa13Months'     => $cpa13Months,
            'cpa13Labels'     => $cpa13Labels,
            'cpa13BySkuMonth' => $cpa13BySkuMonth,
        ];
    }

    private function normalizeGradeValue(?string $grade): string
    {
        $grade = strtoupper(trim((string) $grade));
        return $grade !== '' ? $grade : 'OTHER';
    }

    private function normalizeGradeFilters($values): array
    {
        $arr = is_array($values) ? $values : [$values];

        return collect($arr)
            ->map(fn($x) => strtoupper(trim((string) $x)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeSkuTerms(?string $value): array
    {
        $raw = strtoupper((string) $value);
        $parts = preg_split('/[\s,;\n\r\t]+/', $raw);

        return collect($parts)
            ->map(fn($x) => preg_replace('/\s+/', '', trim((string) $x)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function applySkuLikeFilter($query, string $columnExpr, array $skuTerms)
    {
        if (empty($skuTerms)) {
            return $query;
        }

        return $query->where(function ($q) use ($columnExpr, $skuTerms) {
            foreach ($skuTerms as $term) {
                $q->orWhereRaw("{$columnExpr} LIKE ?", [$term . '%']);
            }
        });
    }

    private function buildSkuLikeSql(string $columnExpr, array $skuTerms, array &$params): string
    {
        if (empty($skuTerms)) {
            return '';
        }

        $chunks = [];
        foreach ($skuTerms as $i => $term) {
            $key = "sku_like_{$i}";
            $chunks[] = "{$columnExpr} LIKE :{$key}";
            $params[$key] = $term . '%';
        }

        return ' AND (' . implode(' OR ', $chunks) . ')';
    }

    private function getGradeOptions(): array
    {
        return DB::connection('sqlsrv_menam')
            ->table('parts')
            ->where('active', 1)
            ->whereNotNull('f3')
            ->selectRaw("UPPER(LTRIM(RTRIM(CAST(f3 AS varchar(100))))) AS grade")
            ->distinct()
            ->orderBy('grade')
            ->pluck('grade')
            ->filter()
            ->values()
            ->push('OTHER')
            ->unique()
            ->all();
    }

    private function parsePlanMonth(?string $value, string $tz = 'Asia/Bangkok'): string
    {
        $dt = $value
            ? Carbon::parse($value, $tz)->startOfMonth()
            : now($tz)->startOfMonth();

        return $dt->toDateString();
    }

    private function availableDivisionOptions(): array
    {
        if ($this->isSuperAdmin()) {
            return $this->allSalesCodes();
        }

        $mine = $this->resolveUserSalesCode();
        return $mine ? [$mine] : [];
    }

    private function normalizeSupplierCodes($values): array
    {
        $arr = is_array($values) ? $values : [$values];

        return collect($arr)
            ->map(fn($x) => strtoupper(trim((string) $x)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function getSupplierOptions(): array
    {
        return DB::connection($this->fcConn)
            ->table('fc_supplier_master')
            ->where('is_active', 1)
            ->orderBy('supplier_name')
            ->get(['supplier_code', 'supplier_name'])
            ->map(fn($r) => [
                'supplier_code' => (string) $r->supplier_code,
                'supplier_name' => (string) $r->supplier_name,
            ])
            ->all();
    }

    public function index(Request $request)
    {
        $this->userOr403();

        $tz = 'Asia/Bangkok';

        $skuLike = strtoupper(trim((string) $request->query('sku', '')));
        $skuTerms = $this->normalizeSkuTerms($skuLike);

        $companyMode = strtoupper(trim((string) $request->query('company', 'ALL')));
        if (!in_array($companyMode, ['ALL', 'WIRE', 'PLUS'], true)) {
            $companyMode = 'ALL';
        }

        $planMonth = $this->parsePlanMonth($request->query('plan_month'), $tz);
        $today = now($tz)->startOfDay()->toDateString();
        $sinceAvg = $request->query('since_avg', now($tz)->subMonths(12)->toDateString());
        $sinceAvgDate = Carbon::parse($sinceAvg, $tz)->startOfDay()->toDateString();

        $selectedSuppliers = $this->normalizeSupplierCodes($request->query('suppliers', []));
        $selectedGrades = $this->normalizeGradeFilters($request->query('grades', []));

        $cacheKey = $this->forecastIndexCacheKey(
            $skuLike,
            $companyMode,
            $today,
            $sinceAvgDate,
            $planMonth,
            $selectedSuppliers,
            $selectedGrades,
        );

        $data = Cache::remember($cacheKey, 300, function () use (
            $skuLike,
            $skuTerms,
            $companyMode,
            $planMonth,
            $sinceAvgDate,
            $selectedSuppliers,
            $selectedGrades,
            $tz
        ) {
            return $this->buildIndexDataset(
                $skuLike,
                $skuTerms,
                $companyMode,
                $planMonth,
                $sinceAvgDate,
                $selectedSuppliers,
                $selectedGrades,
                $tz
            );
        });

        $rows = collect($data['rows'])->values();

        $kpi = [
            'items'                 => $rows->count(),
            'avg3_sum'              => (float) $rows->sum('avg3'),
            'avg6_sum'              => (float) $rows->sum('avg6'),
            'onhand_sum'            => (float) $rows->sum('onhand'),
            'fg_sum'                => (float) $rows->sum('fg'),
            'po_total_sum'          => (float) $rows->sum('po_total'),
            'wip_sum'               => (float) $rows->sum('wip'),
            'so_sum'                => (float) $rows->sum('so'),
            'total_forecast_sum'    => (float) $rows->sum('total_forecast'),
            'planner_forecast_sum'  => (float) $rows->sum('safety_forecast_planner'),
            'total_forecast_so_sum' => (float) $rows->sum('total_forecast_so'),
            'need_to_order_sum'     => (float) $rows->sum('need_to_order'),
        ];

        return view('formfc.index', [
            'skuLike'           => $skuLike,
            'companyMode'       => $companyMode,
            'sinceAvg'          => $data['sinceAvg'],
            'planMonth'         => $planMonth,
            'rows'              => $rows,
            'cpa13Months'       => $data['cpa13Months'],
            'cpa13Labels'       => $data['cpa13Labels'],
            'cpa13BySkuMonth'   => $data['cpa13BySkuMonth'],
            'kpi'               => $kpi,
            'supplierOptions'   => $this->getSupplierOptions(),
            'selectedSuppliers' => $selectedSuppliers,
            'gradeOptions'      => $this->getGradeOptions(),
            'selectedGrades'    => $selectedGrades,
        ]);
    }

    private function normalizeSkuFilter(?string $sku): string
    {
        $sku = strtoupper(trim((string) $sku));
        $sku = preg_replace('/\s+/', '', $sku);
        $sku = rtrim($sku, ',;');
        return (string) $sku;
    }

    public function historyDetail(Request $request)
    {
        $sku = strtoupper(trim((string) $request->query('sku', '')));
        $companyMode = strtoupper(trim((string) $request->query('company', 'ALL')));

        if ($sku === '') {
            return response()->json([]);
        }

        $tz = 'Asia/Bangkok';
        $base = now($tz)->startOfMonth();
        $start = (clone $base)->subMonths(6)->startOfMonth();
        $end   = (clone $base)->subMonths(1)->endOfMonth();

        $cacheKey = $this->detailCacheKey('history', [
            'sku' => $sku,
            'company' => $companyMode,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
        ]);

        $rows = Cache::remember($cacheKey, 300, function () use ($sku, $companyMode, $start, $end) {
            $rows = collect();

            if (in_array($companyMode, ['ALL', 'WIRE'], true)) {
                $rows = $rows->merge(
                    $this->fetchCpa13HistoryDetailRows('pgsqlw', 'MENAM WIRE', $sku, $start, $end)
                );
            }

            if (in_array($companyMode, ['ALL', 'PLUS'], true)) {
                $rows = $rows->merge(
                    $this->fetchCpa13HistoryDetailRows('pgsqlp', 'MENAM PLUS', $sku, $start, $end)
                );
            }

            return $rows->values()->all();
        });

        return response()->json($rows);
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
                    $custNameSql .= 'UPPER(TRIM(COALESCE(c.name, \'\'))) LIKE ?';
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

    private function plannerSalesCode(): string
    {
        return 'PLN';
    }

    private function fetchCpa13HistoryDetailRows(string $conn, string $companyLabel, string $skuLike, Carbon $start, Carbon $end)
    {
        $skuLike = strtoupper(trim((string) $skuLike));

        $sql = <<<SQL
            SELECT
                '{$companyLabel}' AS company,
                UPPER(TRIM(p.partnumber)) AS rm_partnumber,
                p.description AS rm_description,
                DATE_TRUNC('month', gl.transdate)::date AS month,
                gl.transdate::date AS transdate,
                UPPER(TRIM(wo.workordernumber)) AS workordernumber,
                UPPER(TRIM(fg.partnumber)) AS fg_partnumber,
                fg.description AS fg_description,
                c.name AS customer_name,
                ROUND(COALESCE(su.qty, 0), 2) AS qty
            FROM serializeunitsmvmt sus
            LEFT JOIN serializeunits su
                ON su.id = sus.su_id
            LEFT JOIN gl
                ON gl.id = sus.trans_id
            LEFT JOIN parts p
                ON p.id = su.parts_id
            JOIN partstype pt
                ON p.partstype_id = pt.id
            JOIN workorder wo
                ON UPPER(TRIM(wo.workordernumber)) = UPPER(TRIM(gl.reference))
            LEFT JOIN parts fg
                ON fg.id = wo.parts_id
            JOIN customer c
                ON c.id = wo.customer_id
            WHERE gl.transnumber LIKE ?
            AND pt.id IN (69, 70, 71, 91, 95)
            AND UPPER(TRIM(p.partnumber)) LIKE ?
            AND gl.transdate >= ?
            AND gl.transdate <= ?
            ORDER BY gl.transdate DESC, workordernumber
        SQL;

        return collect(DB::connection($conn)->select($sql, [
            'IUB%',
            $skuLike . '%',
            $start->toDateString(),
            $end->toDateString(),
        ]))->map(function ($r) {
            $a = (array) $r;
            $a['qty'] = round((float) ($a['qty'] ?? 0), 2);
            $a['month_label'] = !empty($a['month'])
                ? Carbon::parse($a['month'])->format('M-y')
                : '';
            return $a;
        });
    }

    public function input(Request $request)
    {
        $this->userOr403();

        $tz = 'Asia/Bangkok';
        $planMonth = $this->parsePlanMonth($request->query('plan_month'), $tz);

        $skuLike = $this->normalizeSkuFilter($request->query('sku', ''));


        $companyMode = strtoupper(trim((string) $request->query('company', 'ALL')));
        if (!in_array($companyMode, ['ALL', 'WIRE', 'PLUS'], true)) {
            $companyMode = 'ALL';
        }

        $mine = $this->resolveUserSalesCode();

        $sinceAvg = $request->query('since_avg', now($tz)->subMonths(12)->toDateString());
        $sinceAvg = Carbon::parse($sinceAvg, $tz)->startOfDay()->toDateString();

        $data = $this->buildIndexDataset($skuLike, $companyMode, $planMonth, $sinceAvg, $tz);

        return view('formfc.input', [
            'skuLike'       => $skuLike,
            'companyMode'   => $companyMode,
            'sinceAvg'      => $sinceAvg,
            'planMonth'     => $planMonth,
            'rows'          => $data['rows'],
            'mySalesCode'   => $mine,
            'allSalesCodes' => $this->allSalesCodes(),
        ]);
    }

    public function skuAutocomplete(Request $request)
    {
        $term = strtoupper(trim((string) $request->query('term', '')));
        $companyMode = strtoupper(trim((string) $request->query('company', 'ALL')));

        $out = collect();

        foreach (['WIRE' => 'pgsqlw', 'PLUS' => 'pgsqlp'] as $mode => $conn) {
            if (!in_array($companyMode, ['ALL', $mode], true)) {
                continue;
            }

            $query = DB::connection($conn)
                ->table('parts as p')
                ->join('partstype as pt', 'pt.id', '=', 'p.partstype_id')
                ->selectRaw("
                UPPER(TRIM(p.partnumber)) AS sku,
                MAX(p.description) AS description
            ")
                ->whereIn('pt.id', [69, 70, 71, 91, 95])
                ->where('p.active', true)
                ->whereRaw("UPPER(TRIM(p.partnumber)) LIKE 'R%'");

            if ($term !== '') {
                $query->whereRaw("UPPER(TRIM(p.partnumber)) LIKE ?", [$term . '%']);
            }

            $rows = $query
                ->groupBy(DB::raw("UPPER(TRIM(p.partnumber))"))
                ->orderBy(DB::raw("UPPER(TRIM(p.partnumber))"))
                ->limit(20)
                ->get();

            $out = $out->merge($rows->map(fn($r) => (array) $r));
        }

        return response()->json(
            $out->groupBy('sku')->map(function ($g) {
                return [
                    'sku' => $g->first()['sku'] ?? '',
                    'description' => $g->first()['description'] ?? '',
                ];
            })->values()
        );
    }

    public function exportExcel(Request $request)
    {
        $tz = 'Asia/Bangkok';

        $skuLike = $this->normalizeSkuFilter($request->query('sku', ''));


        $companyMode = strtoupper(trim((string) $request->query('company', 'ALL')));
        if (!in_array($companyMode, ['ALL', 'WIRE', 'PLUS'], true)) {
            $companyMode = 'ALL';
        }

        $planMonth = $this->parsePlanMonth($request->query('plan_month'), $tz);

        $data = $this->buildIndexDataset($skuLike, $companyMode, $planMonth, now($tz)->toDateString(), $tz);
        $rows = collect($data['rows'])->values();

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Forecast RM');

        $headers = [
            'RM Part',
            'Description',
            'Avg 3M',
            'Avg 6M',
            'Onhand',
            'FG',
            'Total PO',
            'WIP',
            'SO',
            'D1',
            'D2',
            'D3',
            'D4',
            'D5',
            'D6',
            'D7',
            'D8',
            'D9',
            'Total Forecast',
            'Forecast + SO',
            'Need To Order',
        ];

        $sheet->fromArray([$headers], null, 'A1');

        $rowNo = 2;
        foreach ($rows as $r) {
            $sheet->fromArray([[
                $r['sku'] ?? '',
                $r['description'] ?? '',
                (float) ($r['avg3'] ?? 0),
                (float) ($r['avg6'] ?? 0),
                (float) ($r['onhand'] ?? 0),
                (float) ($r['fg'] ?? 0),
                (float) ($r['po_total'] ?? 0),
                (float) ($r['wip'] ?? 0),
                (float) ($r['so'] ?? 0),
                (float) ($r['d1'] ?? 0),
                (float) ($r['d2'] ?? 0),
                (float) ($r['d3'] ?? 0),
                (float) ($r['d4'] ?? 0),
                (float) ($r['d5'] ?? 0),
                (float) ($r['d6'] ?? 0),
                (float) ($r['d7'] ?? 0),
                (float) ($r['d8'] ?? 0),
                (float) ($r['d9'] ?? 0),
                (float) ($r['total_forecast'] ?? 0),
                (float) ($r['total_forecast_so'] ?? 0),
                (float) ($r['need_to_order'] ?? 0),
            ]], null, 'A' . $rowNo);

            $rowNo++;
        }

        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $fileName = 'forecast_rm_' . Carbon::parse($planMonth)->format('Ym') . '_' . now($tz)->format('Ymd_His') . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function saveInput(Request $request)
    {
        $u = $this->userOr403();

        $validator = Validator::make($request->all(), [
            'plan_month' => ['required', 'date'],
            'rows' => ['required', 'array'],
        ], [
            'rows.required' => 'ไม่พบข้อมูลที่ต้องการบันทึก',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $planMonth = Carbon::parse($request->input('plan_month'))->startOfMonth()->toDateString();
        $rows = $request->input('rows', []);

        $mySalesCode = $this->resolveUserSalesCode();
        $editableSales = $this->isSuperAdmin()
            ? $this->allSalesCodes()
            : ($mySalesCode ? [$mySalesCode] : []);

        if (empty($editableSales)) {
            return back()->with('error', 'ไม่พบสิทธิ์ D ของผู้ใช้งาน');
        }

        $now = now();

        DB::connection($this->fcConn)->transaction(function () use ($rows, $planMonth, $editableSales, $u, $now) {
            foreach ($rows as $sku => $salesCols) {
                $sku = $this->normalizeSku($sku);
                if ($sku === '' || !Str::startsWith($sku, 'R')) {
                    continue;
                }

                foreach ($editableSales as $salesCode) {
                    $key = strtolower($salesCode);
                    $qty = isset($salesCols[$key]) ? (float) $salesCols[$key] : 0;

                    $table = DB::connection($this->fcConn)->table('fc_rm_sales_forecast');

                    $match = [
                        'plan_month' => $planMonth,
                        'sku' => $sku,
                        'sales_code' => $salesCode,
                    ];

                    $exists = $table->where($match)->exists();

                    if ($exists) {
                        $table->where($match)->update([
                            'qty_kg' => $qty,
                            'source_type' => 'MANUAL',
                            'updated_at' => $now,
                            'updated_by' => $u->id ?? null,
                        ]);
                    } else {
                        $table->insert($match + [
                            'qty_kg' => $qty,
                            'source_type' => 'MANUAL',
                            'updated_at' => $now,
                            'updated_by' => $u->id ?? null,
                            'created_at' => $now,
                            'created_by' => $u->id ?? null,
                        ]);
                    }
                }
            }
        });

        $this->clearForecastIndexCache(
            $request->input('sku', 'R'),
            $request->input('company', 'ALL'),
            $planMonth,
            $request->input('since_avg')
        );

        return redirect()
            ->route('fc.input', [
                'plan_month' => $planMonth,
                'company' => $request->input('company', 'ALL'),
                'sku' => $request->input('sku', 'R'),
            ])
            ->with('success', 'บันทึกข้อมูลเรียบร้อยแล้ว');
    }

    private function fetchSavedDivisionForecastIndex(
        string $forecastMonth,
        array $skuTerms,
        string $companyMode = 'ALL'
    ) {
        $q = DB::connection($this->fcConn)
            ->table('fc_rm_division_forecast')
            ->selectRaw("
            rm_partnumber,
            sales_code,
            MAX(NULLIF(LTRIM(RTRIM(supplier_name)), '')) AS supplier_name,
            SUM(forecast_6m) AS forecast_qty
        ")
            ->whereDate('forecast_base_month', $forecastMonth);

        if (!empty($skuTerms)) {
            $q->where(function ($sub) use ($skuTerms) {
                foreach ($skuTerms as $term) {
                    $sub->orWhere('rm_partnumber', 'like', strtoupper($term) . '%');
                }
            });
        }

        if ($companyMode !== 'ALL') {
            $q->where('company_mode', $companyMode);
        }

        $rows = collect($q->groupBy('rm_partnumber', 'sales_code')->get())
            ->map(fn($r) => (array) $r);

        return $rows
            ->groupBy('rm_partnumber')
            ->map(function ($g) {
                $out = [
                    'forecast_by_sales' => [],
                    'planner_forecast' => 0,
                    'supplier_name_by_sales' => [],
                ];

                foreach ($g as $r) {
                    $salesCode = strtoupper((string) ($r['sales_code'] ?? ''));
                    $qty = (float) ($r['forecast_qty'] ?? 0);

                    if ($salesCode === 'PLN') {
                        $out['planner_forecast'] += $qty;
                    } else {
                        $out['forecast_by_sales'][$salesCode] = $qty;
                    }

                    $supplierName = trim((string) ($r['supplier_name'] ?? ''));
                    if ($supplierName !== '') {
                        $out['supplier_name_by_sales'][$salesCode] = $supplierName;
                    }
                }

                return $out;
            });
    }

    private function fetchCpa13MonthRange(string $conn, array $skuTerms, Carbon $start, Carbon $end)
    {
        $params = [
            'trans_no' => 'IUB%',
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
        ];

        $skuSql = $this->buildSkuLikeSql("UPPER(TRIM(p.partnumber))", $skuTerms, $params);

        $sql = "
                SELECT
                    UPPER(TRIM(p.partnumber)) AS partnumber,
                    DATE_TRUNC('month', gl.transdate)::date AS month,
                    SUM(ROUND(COALESCE(su.qty, 0), 2)) AS qty_sum
                FROM serializeunitsmvmt sus
                LEFT JOIN serializeunits su ON su.id = sus.su_id
                LEFT JOIN gl ON gl.id = sus.trans_id
                LEFT JOIN parts p ON p.id = su.parts_id
                JOIN partstype pt ON p.partstype_id = pt.id
                JOIN workorder wo ON UPPER(TRIM(wo.workordernumber)) = UPPER(TRIM(gl.reference))
                JOIN customer c ON c.id = wo.customer_id
                WHERE gl.transnumber LIKE :trans_no
                AND pt.id IN (69, 70, 71, 91, 95)
                {$skuSql}
                AND gl.transdate >= :start
                AND gl.transdate <= :end
                GROUP BY UPPER(TRIM(p.partnumber)), DATE_TRUNC('month', gl.transdate)::date
                ORDER BY partnumber, month
            ";

        $rows = collect(DB::connection($conn)->select($sql, $params))
            ->map(fn($r) => (array) $r);

        return $rows
            ->groupBy('partnumber')
            ->map(function ($g) {
                $sum = [];
                foreach ($g as $r) {
                    $k = Carbon::parse($r['month'])->format('Y-m');
                    $sum[$k] = ($sum[$k] ?? 0) + (float) $r['qty_sum'];
                }
                return $sum;
            });
    }

    private function fetchOnhandSafe(string $conn, string $companyLabel, string $skuLike)
    {
        try {
            $rows = $this->conn($conn)
                ->table('parts')
                ->select([
                    DB::raw("'" . addslashes($companyLabel) . "' AS company"),
                    DB::raw("UPPER(TRIM(partnumber)) AS partnumber"),
                    'description',
                    'unit',
                    DB::raw('ROUND(COALESCE(onhand,0),2) AS onhand'),
                ])
                ->where('active', true)
                ->whereRaw("UPPER(TRIM(partnumber)) LIKE ?", [strtoupper($skuLike) . '%'])
                ->orderBy(DB::raw("UPPER(TRIM(partnumber))"))
                ->get();

            return $rows->map(fn($r) => (array) $r);
        } catch (Throwable $e) {
            $rows = $this->conn($conn)
                ->table('parts')
                ->select([
                    DB::raw("'" . addslashes($companyLabel) . "' AS company"),
                    DB::raw("UPPER(TRIM(partnumber)) AS partnumber"),
                    'description',
                    DB::raw("''::text AS unit"),
                    DB::raw('ROUND(COALESCE(onhand,0),2) AS onhand'),
                ])
                ->where('active', true)
                ->whereRaw("UPPER(TRIM(partnumber)) LIKE ?", [strtoupper($skuLike) . '%'])
                ->orderBy(DB::raw("UPPER(TRIM(partnumber))"))
                ->get();

            return $rows->map(fn($r) => (array) $r);
        }
    }

    private function fetchOpenPOR(string $conn, string $companyLabel, string $skuOrLike)
    {
        $where = "oe.ordnumber LIKE 'POR%' AND oe.shipped_or_received = false";
        $params = [];

        $like = strtoupper(trim((string) $skuOrLike));

        $tz = 'Asia/Bangkok';
        $start = now($tz)->startOfMonth()->subMonths(6)->toDateString();
        $end   = now($tz)->endOfDay()->toDateString();

        $where .= " AND oe.transdate >= :start_date";
        $where .= " AND oe.transdate <= :end_date";
        $where .= " AND parts.active = true";
        $where .= " AND UPPER(TRIM(parts.partnumber)) LIKE :item";

        $params['start_date'] = $start;
        $params['end_date']   = $end;
        $params['item']       = ($like === '' ? 'R' : $like) . '%';

        $sql = <<<SQL
        SELECT
            oe.ordnumber AS po_no,
            oe.transdate AS po_date,
            oe.reqdate   AS req_date,
            orderitems.sellprice,
            ROUND(
                orderitems.sellprice /
                CASE
                    WHEN oe.curr = 'USD' THEN 35.00
                    WHEN oe.curr = 'EUR' THEN 38.00
                    WHEN oe.curr = 'THB' THEN 1
                    ELSE 1
                END
            , 2) AS price_thb,
            orderitems.qty AS qty_order,
            ROUND(COALESCE(SUM(receiveitems.qty), 0), 2) AS qty_received,
            ROUND((orderitems.qty - COALESCE(SUM(receiveitems.qty), 0)), 2) AS qty_open,
            vendor.name      AS vendor_name,
            parts.partnumber AS rm_partnumber,
            parts.description AS description
        FROM oe
        JOIN orderitems ON oe.id = orderitems.trans_id
        JOIN vendor     ON oe.vendor_id = vendor.id
        JOIN parts      ON orderitems.parts_id = parts.id
        LEFT JOIN receiveitems ON orderitems.id = receiveitems.orderitems_id
        WHERE {$where}
        GROUP BY
            oe.ordnumber, oe.transdate, oe.reqdate,
            orderitems.sellprice, oe.curr,
            orderitems.qty,
            vendor.name,
            parts.partnumber, parts.description
        ORDER BY parts.partnumber
        SQL;

        return collect(DB::connection($conn)->select($sql, $params))
            ->map(function ($r) use ($companyLabel) {
                $a = (array) $r;
                $price = (float) $a['price_thb'];

                // คูณ 1000 เป็น KG
                $qtyKg      = (float) $a['qty_order'] * 1000;
                $receivedKg = (float) $a['qty_received'] * 1000;
                $openKg     = (float) $a['qty_open'] * 1000;

                return [
                    'company'       => $companyLabel,
                    'po_no'         => $a['po_no'],
                    'po_date'       => $a['po_date'],
                    'req_date'      => $a['req_date'],
                    'vendor'        => $a['vendor_name'],
                    'rm_partnumber' => strtoupper($a['rm_partnumber']),
                    'description'   => $a['description'],
                    'qty'           => $qtyKg,
                    'received'      => $receivedKg,
                    'open'          => $openKg,
                    'price_thb'     => $price,
                    'open_value'    => $openKg * $price,
                ];
            })
            ->filter(fn($x) => ($x['open'] ?? 0) > 0)
            ->values();
    }

    private function fetchRmMasterParts(string $conn, array $skuTerms = [])
    {
        $query = DB::connection($conn)
            ->table('parts as p')
            ->join('partstype as pt', 'pt.id', '=', 'p.partstype_id')
            ->selectRaw("
                UPPER(TRIM(p.partnumber)) AS partnumber,
                p.description,
                COALESCE(p.unit, '') AS unit,
                COALESCE(NULLIF(LTRIM(RTRIM(CAST(p.f3 AS varchar(100)))), ''), 'OTHER') AS grade,
                ROUND(COALESCE(p.onhand, 0), 2) AS onhand
            ")
            ->whereIn('pt.id', [70, 71])
            ->where('p.active', true)
            ->whereRaw("UPPER(TRIM(p.partnumber)) LIKE 'R%'");

        $this->applySkuLikeFilter($query, "UPPER(TRIM(p.partnumber))", $skuTerms);

        return collect($query->orderByRaw("UPPER(TRIM(p.partnumber))")->get())
            ->map(fn($r) => (array) $r);
    }

    private function fetchWipIndex(string $conn, string $skuLike)
    {
        $fgMapByRm = $this->fetchFgMapByRm($conn, $skuLike);

        if (empty($fgMapByRm)) {
            return collect();
        }

        $allFgParts = collect($fgMapByRm)->flatten()->unique()->values()->all();
        if (empty($allFgParts)) {
            return collect();
        }

        // reverse map: FG -> RM
        $fgToRm = [];
        foreach ($fgMapByRm as $rmPart => $fgParts) {
            foreach ($fgParts as $fg) {
                $fgToRm[strtoupper(trim((string) $fg))] = strtoupper(trim((string) $rmPart));
            }
        }

        $usageRm = DB::connection($conn)
            ->table('workorderusage')
            ->selectRaw('workorder_id, SUM(qty) AS qty')
            ->groupBy('workorder_id');

        $startOfYear = now('Asia/Bangkok')->startOfYear()->toDateString();
        $today = now('Asia/Bangkok')->toDateString();

        $rows = collect(
            DB::connection($conn)
                ->table('workorder')
                ->join('parts', 'workorder.parts_id', '=', 'parts.id')
                ->leftJoin('partstype', 'parts.partstype_id', '=', 'partstype.id')
                ->leftJoin('customer', 'workorder.customer_id', '=', 'customer.id')
                ->leftJoinSub($usageRm, 'usage_rm', function ($j) {
                    $j->on('usage_rm.workorder_id', '=', 'workorder.id');
                })
                ->whereIn(DB::raw('UPPER(TRIM(parts.partnumber))'), $allFgParts)
                ->whereBetween('workorder.dateopen', [$startOfYear, $today])
                ->select(
                    'workorder.id',
                    'workorder.parts_id',
                    'workorder.dateopen',
                    'workorder.reqdate',
                    'workorder.workordernumber',
                    DB::raw('UPPER(TRIM(parts.partnumber)) AS fg_partnumber'),
                    'parts.description',
                    DB::raw('ROUND(workorder.qty, 2) AS qty_order'),
                    DB::raw('ROUND(COALESCE(usage_rm.qty, 0), 2) AS usage_rm_qty'),
                )
                ->selectSub(function ($s) use ($conn) {
                    $s->from('workorderreceive as wor')
                        ->join('parts as p2', 'p2.id', '=', 'wor.parts_id')
                        ->leftJoin('partstype as pt2', 'pt2.id', '=', 'p2.partstype_id')
                        ->whereColumn('wor.workorder_id', 'workorder.id')
                        ->whereIn('pt2.id', [61, 62])
                        ->selectRaw('ROUND(SUM(wor.qty),2)');
                }, 'scrap_qty')
                ->selectSub(function ($s) use ($conn) {
                    $s->from('workorderreceive as wor')
                        ->join('parts as p2', 'p2.id', '=', 'wor.parts_id')
                        ->leftJoin('partstype as pt2', 'pt2.id', '=', 'p2.partstype_id')
                        ->whereColumn('wor.workorder_id', 'workorder.id')
                        ->whereIn('pt2.id', [69, 70, 71, 95, 91])
                        ->selectRaw('ROUND(SUM(wor.qty),2)');
                }, 'return_rm_qty')
                ->selectSub(function ($s) use ($conn) {
                    $s->from('workorderreceive as wor')
                        ->join('parts as p2', 'p2.id', '=', 'wor.parts_id')
                        ->leftJoin('partstype as pt2', 'pt2.id', '=', 'p2.partstype_id')
                        ->whereColumn('wor.workorder_id', 'workorder.id')
                        ->whereNotIn('pt2.id', [69, 70, 71, 95, 61, 62, 91, 0, 56])
                        ->selectRaw('ROUND(SUM(wor.qty),2)');
                }, 'good_qty')
                ->addSelect([
                    DB::raw('ROUND(COALESCE(usage_rm.qty, 0) - COALESCE(workorder.received, 0), 2) AS balance_qty'),
                    'workorder.received',
                    'parts.unit',
                    DB::raw('ROUND(COALESCE(parts.onhand, 0), 2) AS fg_onhand'),
                    'customer.name',
                ])
                ->groupBy(
                    'workorder.id',
                    'workorder.parts_id',
                    'workorder.dateopen',
                    'workorder.reqdate',
                    'workorder.workordernumber',
                    'parts.partnumber',
                    'parts.description',
                    'workorder.qty',
                    'usage_rm.qty',
                    'workorder.received',
                    'parts.unit',
                    'parts.onhand',
                    'customer.name'
                )
                ->orderByDesc('workorder.dateopen')
                ->orderBy('workorder.workordernumber')
                ->get()
        )->map(fn($r) => (array) $r);

        $rmTotals = [];

        foreach ($rows as $r) {
            $fg = strtoupper(trim((string) ($r['fg_partnumber'] ?? '')));
            $rm = $fgToRm[$fg] ?? null;

            if (!$rm) {
                continue;
            }

            $balance = (float) ($r['balance_qty'] ?? 0);

            // ตัดค่าติดลบออก
            if ($balance < 0) {
                $balance = 0;
            }

            $rmTotals[$rm] = ($rmTotals[$rm] ?? 0) + $balance;
        }

        return collect($rmTotals)->map(fn($v) => round(max((float) $v, 0), 2));
    }


    private function fetchFgIndex(string $conn, string $skuLike)
    {
        $skuLike = strtoupper(trim((string) $skuLike));

        $query = DB::connection($conn)
            ->table('parts')
            ->selectRaw("
            UPPER(TRIM(f4)) AS rm_partnumber,
            ROUND(SUM(COALESCE(onhand, 0)), 2) AS fg_onhand
        ")
            ->where('active', true)
            ->whereNotNull('f4')
            ->whereRaw("UPPER(TRIM(f4)) LIKE 'R%'")
            ->whereRaw("COALESCE(TRIM(partnumber), '') <> ''");

        if ($skuLike !== '') {
            $query->whereRaw("UPPER(TRIM(f4)) LIKE ?", [$skuLike . '%']);
        }

        return collect($query
            ->groupBy(DB::raw("UPPER(TRIM(f4))"))
            ->get())
            ->mapWithKeys(function ($r) {
                $r = (array) $r;

                return [
                    strtoupper(trim((string) ($r['rm_partnumber'] ?? ''))) => (float) ($r['fg_onhand'] ?? 0),
                ];
            });
    }

    private function fetchSoIndex(string $conn, string $skuLike)
    {
        // SO ใช้ WIRE เท่านั้น
        $conn = 'pgsqlw';

        $fgMapByRm = $this->fetchFgMapByRm($conn, $skuLike);

        if (empty($fgMapByRm)) {
            return collect();
        }

        $allFgParts = collect($fgMapByRm)->flatten()->unique()->values()->all();
        if (empty($allFgParts)) {
            return collect();
        }

        // reverse map: FG -> RM
        $fgToRm = [];
        foreach ($fgMapByRm as $rmPart => $fgParts) {
            foreach ($fgParts as $fg) {
                $fgToRm[strtoupper(trim((string) $fg))] = strtoupper(trim((string) $rmPart));
            }
        }

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
            AVG(oi.sellprice) AS unit_price,
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

        $rows = collect(
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
                ->whereIn(DB::raw('UPPER(TRIM(p.partnumber))'), $allFgParts)
                ->where('ob.shipped_or_received', false)
                ->where('ob.invoiced', false)
                ->whereRaw("ob.ordered_qty - (COALESCE(sb.shipped_qty,ob.shipped_qty,0) - COALESCE(rb.return_qty,0)) > 0")
                ->orderBy('ob.order_date')
                ->selectRaw("
                UPPER(TRIM(p.partnumber)) AS fg_partnumber,
                (ob.ordered_qty - (COALESCE(sb.shipped_qty,ob.shipped_qty,0) - COALESCE(rb.return_qty,0))) AS backorder_qty
            ")
                ->get()
        )->map(fn($r) => (array) $r);

        $rmTotals = [];

        foreach ($rows as $r) {
            $fg = strtoupper(trim((string) ($r['fg_partnumber'] ?? '')));
            $rm = $fgToRm[$fg] ?? null;

            if (!$rm) {
                continue;
            }

            $backorder = (float) ($r['backorder_qty'] ?? 0);

            if ($backorder < 0) {
                $backorder = 0;
            }

            $rmTotals[$rm] = ($rmTotals[$rm] ?? 0) + $backorder;
        }

        return collect($rmTotals)->map(fn($v) => round(max((float) $v, 0), 2));
    }

    private function fetchFgMapByRm(string $conn, string $skuLike): array
    {
        $rows = collect(
            DB::connection($conn)
                ->table('parts')
                ->selectRaw("
                UPPER(TRIM(f4)) AS rm_partnumber,
                UPPER(TRIM(partnumber)) AS fg_partnumber
            ")
                ->where('active', true)
                ->whereNotNull('f4')
                ->whereRaw("UPPER(TRIM(f4)) LIKE ?", [strtoupper($skuLike) . '%'])
                ->whereRaw("COALESCE(TRIM(partnumber), '') <> ''")
                ->get()
        )->map(fn($r) => (array) $r);

        $map = [];
        foreach ($rows as $r) {
            $rm = strtoupper(trim((string) ($r['rm_partnumber'] ?? '')));
            $fg = strtoupper(trim((string) ($r['fg_partnumber'] ?? '')));
            if ($rm === '' || $fg === '') {
                continue;
            }
            $map[$rm][] = $fg;
        }

        foreach ($map as $rm => $fgs) {
            $map[$rm] = array_values(array_unique($fgs));
        }

        return $map;
    }

    public function poDetail(Request $request)
    {
        $sku = strtoupper(trim((string) $request->query('sku', '')));
        $companyMode = strtoupper(trim((string) $request->query('company', 'ALL')));

        if ($sku === '') {
            return response()->json([]);
        }

        $cacheKey = $this->detailCacheKey('po', [
            'sku' => $sku,
            'company' => $companyMode,
        ]);

        $rows = Cache::remember($cacheKey, 300, function () use ($sku, $companyMode) {
            $rows = collect();

            if (in_array($companyMode, ['ALL', 'WIRE'], true)) {
                $rows = $rows->merge($this->fetchOpenPOR('pgsqlw', 'MENAM WIRE', $sku));
            }

            if (in_array($companyMode, ['ALL', 'PLUS'], true)) {
                $rows = $rows->merge($this->fetchOpenPOR('pgsqlp', 'MENAM PLUS', $sku));
            }

            return $rows->values()->all();
        });

        return response()->json($rows);
    }

    public function wipDetail(Request $request)
    {
        $sku = strtoupper(trim((string) $request->query('sku', '')));
        $companyMode = strtoupper(trim((string) $request->query('company', 'ALL')));

        if ($sku === '') {
            return response()->json([]);
        }

        $cacheKey = $this->detailCacheKey('wip', [
            'sku' => $sku,
            'company' => $companyMode,
            'year' => now('Asia/Bangkok')->year,
        ]);

        $rows = Cache::remember($cacheKey, 300, function () use ($sku, $companyMode) {
            $out = collect();

            foreach (['WIRE' => 'pgsqlw', 'PLUS' => 'pgsqlp'] as $mode => $conn) {
                if (!in_array($companyMode, ['ALL', $mode], true)) {
                    continue;
                }

                $fgMap = $this->fetchFgMapByRm($conn, $sku);
                $fgParts = $fgMap[$sku] ?? [];

                if (empty($fgParts)) {
                    continue;
                }

                $usageRm = DB::connection($conn)
                    ->table('workorderusage')
                    ->selectRaw('workorder_id, SUM(qty) AS qty')
                    ->groupBy('workorder_id');

                $startOfYear = now('Asia/Bangkok')->startOfYear()->toDateString();
                $today = now('Asia/Bangkok')->toDateString();

                $rows = DB::connection($conn)
                    ->table('workorder')
                    ->join('parts', 'workorder.parts_id', '=', 'parts.id')
                    ->leftJoin('customer', 'workorder.customer_id', '=', 'customer.id')
                    ->leftJoinSub($usageRm, 'usage_rm', function ($j) {
                        $j->on('usage_rm.workorder_id', '=', 'workorder.id');
                    })
                    ->whereIn(DB::raw('UPPER(TRIM(parts.partnumber))'), $fgParts)
                    ->whereBetween('workorder.dateopen', [$startOfYear, $today])
                    ->selectRaw("
                    UPPER(TRIM(parts.partnumber)) AS fg_partnumber,
                    parts.description,
                    workorder.workordernumber,
                    workorder.dateopen,
                    workorder.reqdate,
                    customer.name AS customer_name,
                    ROUND(COALESCE(usage_rm.qty, 0), 2) AS usage_rm_qty,
                    ROUND(COALESCE(workorder.received, 0), 2) AS received_qty,
                    ROUND(COALESCE(usage_rm.qty, 0) - COALESCE(workorder.received, 0), 2) AS balance_qty,
                    '" . ($mode === 'WIRE' ? 'MENAM WIRE' : 'MENAM PLUS') . "' AS company
                ")
                    ->orderByDesc('workorder.dateopen')
                    ->get();

                $out = $out->merge($rows);
            }

            return $out
                ->map(function ($r) {
                    $r = (array) $r;
                    $r['balance_qty'] = max((float) ($r['balance_qty'] ?? 0), 0);
                    return $r;
                })
                ->filter(fn($r) => (float) $r['balance_qty'] > 0)
                ->values()
                ->all();
        });

        return response()->json($rows);
    }

    public function soDetail(Request $request)
    {
        $sku = strtoupper(trim((string) $request->query('sku', '')));

        if ($sku === '') {
            return response()->json([]);
        }

        $cacheKey = $this->detailCacheKey('so', [
            'sku' => $sku,
            'company' => 'WIRE',
        ]);

        $rows = Cache::remember($cacheKey, 300, function () use ($sku) {
            $conn = 'pgsqlw';
            $out = collect();

            $fgMap = $this->fetchFgMapByRm($conn, $sku);
            $fgParts = $fgMap[$sku] ?? [];

            if (empty($fgParts)) {
                return [];
            }

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

            $rows = DB::connection($conn)->query()
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
                ->leftJoin('customer as cus', 'cus.id', '=', 'ob.customer_id')
                ->whereIn(DB::raw('UPPER(TRIM(p.partnumber))'), $fgParts)
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
                cus.name AS customer_name,
                (ob.ordered_qty - (COALESCE(sb.shipped_qty,ob.shipped_qty,0) - COALESCE(rb.return_qty,0))) AS backorder_qty,
                'MENAM WIRE' AS company
            ")
                ->get();

            return collect($rows)
                ->map(function ($r) {
                    $r = (array) $r;
                    $r['backorder_qty'] = max((float) ($r['backorder_qty'] ?? 0), 0);
                    return $r;
                })
                ->filter(fn($r) => (float) $r['backorder_qty'] > 0)
                ->values()
                ->all();
        });

        return response()->json($rows);
    }
    private function fetchFgMapByRmBulk(string $connName, array $rmParts): array
    {
        $rmParts = collect($rmParts)
            ->map(fn($x) => strtoupper(trim((string) $x)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($rmParts)) {
            return [];
        }

        $rows = DB::connection($connName)
            ->table('parts')
            ->where('active', true)
            ->whereNotNull('f4')
            ->whereIn(DB::raw('UPPER(TRIM(f4))'), $rmParts)
            ->whereRaw("COALESCE(TRIM(partnumber), '') <> ''")
            ->selectRaw("UPPER(TRIM(f4)) AS rm_partnumber, UPPER(TRIM(partnumber)) AS fg_partnumber")
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $rm = strtoupper(trim((string) $r->rm_partnumber));
            $fg = strtoupper(trim((string) $r->fg_partnumber));
            if ($rm !== '' && $fg !== '') {
                $map[$rm][] = $fg;
            }
        }

        foreach ($map as $rm => $fgs) {
            $map[$rm] = array_values(array_unique($fgs));
        }

        return $map;
    }


    private function sumFgBackToRm(\Illuminate\Support\Collection $fgRows, array $fgMap): \Illuminate\Support\Collection
    {
        $fgToRm = [];
        foreach ($fgMap as $rm => $fgParts) {
            foreach ($fgParts as $fg) {
                $fgToRm[strtoupper(trim((string) $fg))] = strtoupper(trim((string) $rm));
            }
        }

        $rmTotals = [];
        foreach ($fgRows as $row) {
            $part = strtoupper(trim((string) ($row['partnumber'] ?? '')));
            $rm = $fgToRm[$part] ?? null;
            if (!$rm) {
                continue;
            }

            $rmTotals[$rm] = ($rmTotals[$rm] ?? 0) + (float) ($row['balance_qty'] ?? 0);
        }

        return collect($rmTotals)->map(fn($v) => round((float) $v, 2));
    }


    public function onhandDetail(Request $request)
    {
        $sku = strtoupper(trim((string) $request->query('sku', '')));
        $companyMode = strtoupper(trim((string) $request->query('company', 'ALL')));

        if ($sku === '') {
            return response()->json([]);
        }

        $sinceDate = now('Asia/Bangkok')->subMonths(6)->startOfMonth()->toDateString();
        $out = collect();

        if (in_array($companyMode, ['ALL', 'WIRE'], true)) {
            $out = $out->merge($this->fetchCpa7DetailBatch('pgsqlw', [$sku], 'MENAM WIRE', $sinceDate));
        }

        if (in_array($companyMode, ['ALL', 'PLUS'], true)) {
            $out = $out->merge($this->fetchCpa7DetailBatch('pgsqlp', [$sku], 'MENAM PLUS', $sinceDate));
        }

        return response()->json(
            $out->map(function ($r) {
                $r['receive_qty'] = round((float) ($r['receive_qty'] ?? 0), 2);
                $r['issue_qty']   = round((float) ($r['issue_qty'] ?? 0), 2);
                $r['balance_qty'] = round((float) ($r['balance_qty'] ?? 0), 2);
                return $r;
            })->filter(fn($r) => (float) ($r['balance_qty'] ?? 0) > 0)->values()
        );
    }

    public function fgDetail(Request $request)
    {
        $sku = strtoupper(trim((string) $request->query('sku', '')));
        $companyMode = strtoupper(trim((string) $request->query('company', 'ALL')));

        if ($sku === '') {
            return response()->json([]);
        }

        $cacheKey = $this->detailCacheKey('fg_summary', [
            'sku' => $sku,
            'company' => $companyMode,
        ]);

        $rows = Cache::remember($cacheKey, 300, function () use ($sku, $companyMode) {
            $out = collect();

            if (in_array($companyMode, ['ALL', 'WIRE'], true)) {
                $wireFgMap = $this->getCachedFgMapByRmBulk('pgsqlw', [$sku]);
                $wireFgParts = $wireFgMap[$sku] ?? [];

                $out = $out->merge(
                    $this->fetchFgSummaryDetail('pgsqlw', 'MENAM WIRE', $wireFgParts)
                );
            }

            if (in_array($companyMode, ['ALL', 'PLUS'], true)) {
                $plusFgMap = $this->getCachedFgMapByRmBulk('pgsqlp', [$sku]);
                $plusFgParts = $plusFgMap[$sku] ?? [];

                $out = $out->merge(
                    $this->fetchFgSummaryDetail('pgsqlp', 'MENAM PLUS', $plusFgParts)
                );
            }

            return $out->values()->all();
        });

        return response()->json($rows);
    }

    private function fetchFgSummaryDetail(string $conn, string $companyLabel, array $fgParts)
    {
        $fgParts = collect($fgParts)
            ->map(fn($x) => strtoupper(trim((string) $x)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($fgParts)) {
            return collect();
        }

        return collect(
            DB::connection($conn)
                ->table('parts')
                ->selectRaw("
                '{$companyLabel}' AS company,
                UPPER(TRIM(partnumber)) AS partnumber,
                description,
                ROUND(COALESCE(onhand, 0), 2) AS balance_qty
            ")
                ->where('active', true)
                ->whereIn(DB::raw('UPPER(TRIM(partnumber))'), $fgParts)
                ->orderBy(DB::raw('UPPER(TRIM(partnumber))'))
                ->get()
        )->map(function ($r) {
            $r = (array) $r;

            return [
                'company'         => $r['company'] ?? '',
                'partnumber'      => $r['partnumber'] ?? '',
                'description'     => $r['description'] ?? '',
                'customer'        => '-',
                'workordernumber' => '-',
                'transdate_max'   => '-',
                'receive_qty'     => 0,
                'issue_qty'       => 0,
                'balance_qty'     => round((float) ($r['balance_qty'] ?? 0), 2),
            ];
        })->filter(fn($r) => (float) ($r['balance_qty'] ?? 0) > 0)->values();
    }

    private function fetchCpa7DetailBatch(string $connName, array $partnumbers, string $siteLabel, ?string $sinceDate = null)
    {
        $partnumbers = collect($partnumbers)
            ->map(fn($x) => strtoupper(trim((string) $x)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($partnumbers)) {
            return collect();
        }

        $sinceDate = $sinceDate ?: now('Asia/Bangkok')->subMonths(6)->startOfMonth()->toDateString();

        return $connName === 'pgsqlw'
            ? $this->fetchCpa7DetailBatchWire($partnumbers, $siteLabel, $sinceDate)
            : $this->fetchCpa7DetailBatchPlus($partnumbers, $siteLabel, $sinceDate);
    }

    private function fetchCpa7DetailBatchWire(array $partnumbers, string $siteLabel, string $sinceDate)
    {
        $conn = DB::connection('pgsqlw');

        $conn->statement("SET enable_nestloop = off");
        $conn->statement("SET work_mem = '64MB'");

        $partRows = $conn->table('parts')
            ->selectRaw("id, UPPER(TRIM(partnumber)) AS partnumber, description")
            ->where('active', true)
            ->whereIn(DB::raw('UPPER(TRIM(partnumber))'), $partnumbers)
            ->get();

        $partIds = $partRows->pluck('id')->map(fn($v) => (int) $v)->values()->all();

        if (empty($partIds)) {
            return collect();
        }

        $susF = $conn->table('serializeunitsmvmt as sus')
            ->select(['sus.su_id', 'sus.transdate', 'sus.invoice_id', 'sus.trans_id', 'sus.qty'])
            ->where('sus.transdate', '>=', $sinceDate);

        $suByCust = $conn->query()
            ->fromSub($susF, 'sus')
            ->join('serializeunits as su', 'su.id', '=', 'sus.su_id')
            ->whereIn('su.parts_id', $partIds)
            ->leftJoin('workorderreceive as wr', function ($join) {
                $join->on('wr.invoice_id', '=', 'sus.invoice_id')
                    ->on('wr.parts_id', '=', 'su.parts_id');
            })
            ->leftJoin('workorder as wo', 'wo.id', '=', 'wr.workorder_id')
            ->leftJoin(DB::raw('"return" as r'), 'r.id', '=', 'sus.trans_id')
            ->leftJoin('customer as cwo', 'cwo.id', '=', 'wo.customer_id')
            ->leftJoin('customer as cr', 'cr.id', '=', DB::raw('r.customer_id'))
            ->selectRaw("
            su.parts_id AS parts_id,
            COALESCE(NULLIF(TRIM(COALESCE(cwo.name, cr.name)), ''), 'Menam Plus') AS customer_name,
            wo.workordernumber,
            sus.transdate,
            sus.qty,
            su.onhand
        ");

        return $conn->query()
            ->fromSub($suByCust, 's')
            ->join('parts as p', 'p.id', '=', 's.parts_id')
            ->groupBy([
                'p.partnumber',
                'p.description',
                's.customer_name',
                's.workordernumber',
            ])
            ->selectRaw("
            '{$siteLabel}' AS company,
            UPPER(TRIM(p.partnumber)) AS partnumber,
            p.description,
            s.customer_name AS customer,
            s.workordernumber,
            MAX(s.transdate) AS transdate_max,
            ROUND(SUM(CASE WHEN s.qty > 0 THEN s.qty ELSE 0 END), 2) AS receive_qty,
            ROUND(SUM(CASE WHEN s.onhand THEN s.qty ELSE 0 END), 2) AS balance_qty,
            ROUND(
                SUM(CASE WHEN s.qty > 0 THEN s.qty ELSE 0 END)
                - SUM(CASE WHEN s.onhand THEN s.qty ELSE 0 END)
            , 2) AS issue_qty
        ")
            ->orderBy(DB::raw('UPPER(TRIM(p.partnumber))'))
            ->get()
            ->map(fn($r) => (array) $r);
    }

    private function fetchCpa7DetailBatchPlus(array $partnumbers, string $siteLabel, string $sinceDate)
    {
        $conn = DB::connection('pgsqlp');

        $partBase = $conn->table('parts as p')
            ->select([
                'p.id',
                'p.partnumber',
                'p.description',
            ])
            ->where('p.active', true)
            ->whereIn(DB::raw('UPPER(TRIM(p.partnumber))'), $partnumbers);

        $partIds = (clone $partBase)->pluck('id')->map(fn($v) => (int) $v)->values()->all();

        if (empty($partIds)) {
            return collect();
        }

        $wr2 = $conn->table('workorderreceive as wr')
            ->select(['wr.invoice_id', 'wr.workorder_id', 'wr.parts_id'])
            ->whereIn('wr.parts_id', $partIds);

        $susF = $conn->table('serializeunitsmvmt as sus')
            ->select(['sus.su_id', 'sus.transdate', 'sus.invoice_id', 'sus.trans_id', 'sus.qty'])
            ->where('sus.transdate', '>=', $sinceDate);

        $suByCust = $conn->query()
            ->fromSub($partBase, 'p1')
            ->join('serializeunits as su', 'su.parts_id', '=', 'p1.id')
            ->joinSub($susF, 'sus', function ($j) {
                $j->on('sus.su_id', '=', 'su.id');
            })
            ->joinSub($wr2, 'wr', function ($j) {
                $j->on('wr.invoice_id', '=', 'sus.invoice_id')
                    ->on('wr.parts_id', '=', 'p1.id');
            })
            ->join('workorder as wo', function ($j) {
                $j->on('wo.id', '=', 'wr.workorder_id')
                    ->on('wo.parts_id', '=', 'p1.id');
            })
            ->join('customer as c', 'c.id', '=', 'wo.customer_id')
            ->selectRaw("
                p1.id AS parts_id,
                c.name AS customer_name,
                wo.workordernumber,
                sus.transdate,
                sus.qty,
                su.onhand
            ");

        return $conn->query()
            ->fromSub($suByCust, 's')
            ->join('parts as p', 'p.id', '=', 's.parts_id')
            ->groupBy([
                'p.partnumber',
                'p.description',
                's.customer_name',
                's.workordernumber',
            ])
            ->selectRaw("
                '{$siteLabel}' AS company,
                UPPER(TRIM(p.partnumber)) AS partnumber,
                p.description,
                s.customer_name AS customer,
                s.workordernumber,
                MAX(s.transdate) AS transdate_max,
                ROUND(SUM(CASE WHEN s.qty > 0 THEN s.qty ELSE 0 END), 2) AS receive_qty,
                ROUND(SUM(CASE WHEN s.onhand THEN s.qty ELSE 0 END), 2) AS balance_qty,
                ROUND(
                    SUM(CASE WHEN s.qty > 0 THEN s.qty ELSE 0 END)
                    - SUM(CASE WHEN s.onhand THEN s.qty ELSE 0 END)
                , 2) AS issue_qty
            ")
            ->orderBy(DB::raw('UPPER(TRIM(p.partnumber))'))
            ->get()
            ->map(fn($r) => (array) $r);
    }

    private function detailCacheKey(string $name, array $payload): string
    {
        return 'forecast_rm:detail:' . $name . ':' . md5(json_encode($payload));
    }

    private function fetchSupplierMapIndex(array $partnumbers, array $selectedSuppliers = [])
    {
        $partnumbers = collect($partnumbers)
            ->map(fn($x) => strtoupper(trim((string) $x)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $selectedSuppliers = collect($selectedSuppliers)
            ->map(fn($x) => strtoupper(trim((string) $x)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($partnumbers)) {
            return collect();
        }

        $query = DB::connection($this->fcConn)
            ->table('parts as p')
            ->join('fc_item_supplier_map as ism', function ($join) {
                $join->on('ism.part_id', '=', 'p.id')
                    ->where('ism.is_active', 1);
            })
            ->join('fc_supplier_master as sm', function ($join) {
                $join->on('sm.id', '=', 'ism.supplier_id')
                    ->where('sm.is_active', 1);
            })
            ->whereIn(DB::raw('UPPER(LTRIM(RTRIM(p.partnumber)))'), $partnumbers);

        if (!empty($selectedSuppliers)) {
            $query->whereIn(DB::raw('UPPER(LTRIM(RTRIM(sm.supplier_code)))'), $selectedSuppliers);
        }

        $rows = collect(
            $query->selectRaw("
            UPPER(LTRIM(RTRIM(p.partnumber))) AS partnumber,
            sm.id AS supplier_id,
            sm.supplier_code,
            sm.supplier_name,
            sm.supplier_short_name,
            ISNULL(ism.is_primary, 0) AS is_primary,
            ISNULL(ism.priority_no, 9999) AS priority_no
        ")
                ->orderBy(DB::raw('UPPER(LTRIM(RTRIM(p.partnumber)))'))
                ->orderByDesc('ism.is_primary')
                ->orderBy('ism.priority_no')
                ->orderBy('sm.supplier_name')
                ->get()
        )->map(fn($r) => (array) $r);

        return $rows->groupBy('partnumber')->map(function ($group) {
            $first = collect($group)->sortBy([
                ['is_primary', 'desc'],
                ['priority_no', 'asc'],
                ['supplier_name', 'asc'],
            ])->first();

            return [
                'primary_supplier_code' => $first['supplier_code'] ?? '',
                'primary_supplier_name' => $first['supplier_short_name'] ?: ($first['supplier_name'] ?? ''),
                'supplier_count'        => collect($group)->pluck('supplier_id')->unique()->count(),
                'suppliers'             => collect($group)->values()->all(),
            ];
        });
    }
}
