<?php

namespace App\Services\FormVC;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class VariableCostService
{
    private const UNASSIGNED_DEPARTMENT = 'ไม่ระบุแผนก';

    private const SITES = [
        'WIRE' => 'pgsqlw',
        'PLUS' => 'pgsqlp',
    ];

    private const EXCLUDED_ACCOUNT_CODES = [
        '1110203',
        '2020500',
    ];

    private const WIRE_CHARGED_TO_PLUS_ACCOUNT_CODES = [
        '5210310',
        '5210330',
        '5210340',
        '5210350',
        '5210700',
        '6010100',
        '6010200',
        '6120000',
    ];

    public const ACCOUNT_DISPLAY_CACHE_KEY = 'vc_account_display_codes';

    public const DEFAULT_ACCOUNT_OPTION_CODES = [
        '5210100',
        '5210310',
        '5210330',
        '5210340',
        '5210350',
        '5210370',
        '5210401',
        '5210602',
        '5210700',
        '5211000',
        '5211100',
        '5211200',
        '5211700',
        '5211800',
        '5220200',
        '5220600',
        '6030001',
        '6040000',
        '6050000',
        '6060100',
        '6120201',
        '6120401',
        '7050000',
        '7060200',
        '7070000',
        '7080000',
    ];

    private string $fcConn = 'sqlsrv_menam';
    private string $accountDisplayTable = 'vc_account_display_accounts';

    private ?array $classInfoBySite = null;

    private function getClassInfoBySite(): array
    {
        if ($this->classInfoBySite !== null) {
            return $this->classInfoBySite;
        }

        $this->classInfoBySite = [];
        foreach (self::SITES as $site => $connection) {
            try {
                $rows = DB::connection($connection)
                    ->table('classinfo')
                    ->select('classnumber', 'description')
                    ->get();

                $this->classInfoBySite[$site] = collect($rows)
                    ->filter(fn($r) => trim((string) $r->classnumber) !== '')
                    ->mapWithKeys(fn($r) => [trim((string) $r->classnumber) => trim((string) $r->description)])
                    ->all();
            } catch (\Throwable $e) {
                $this->classInfoBySite[$site] = [];
            }
        }

        return $this->classInfoBySite;
    }

    private function resolveDepartment(string $site, string $code, string $ownDescription): array
    {
        if ($code === '') {
            return [$code, $ownDescription];
        }

        $maps = $this->getClassInfoBySite();
        $masterDesc = $maps[$site][$code] ?? $ownDescription;
        if ($masterDesc === '') {
            $masterDesc = $ownDescription;
        }

        $otherSites = array_diff(array_keys(self::SITES), [$site]);
        $hasMismatch = false;
        $existsElsewhere = false;
        foreach ($otherSites as $otherSite) {
            if (!isset($maps[$otherSite][$code])) {
                continue;
            }
            $existsElsewhere = true;
            if ($maps[$otherSite][$code] !== $masterDesc) {
                $hasMismatch = true;
                break;
            }
        }

        if ($hasMismatch || !$existsElsewhere) {
            $masterDesc = trim($masterDesc) . ' (' . $site . ')';
        }

        return [$code, trim($masterDesc) ?: self::UNASSIGNED_DEPARTMENT];
    }

    public function getSummaryData(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        $combined = $this->fetchCombinedAggregates($filters, ['overall', 'dept', 'acc']);

        $departmentSummary = $this->postProcessDepartmentTotals($combined['department'], $filters['site']);
        $accountSummary = $this->postProcessAccountTotals($combined['account']);
        $accountOptions = $this->accountOptionsFromSummary($accountSummary);

        return [
            'filters' => $filters,
            'departmentSummary' => $departmentSummary,
            'accountSummary' => collect(),
            'monthlySummary' => collect(),
            'departmentMonthly' => collect(),
            'expenseMatrix' => ['accounts' => collect(), 'rows' => collect(), 'columnTotals' => [], 'grandTotal' => 0],
            'rows' => collect(),
            'detailRows' => collect(),
            'divisionGroupOptions' => $this->divisionGroupOptions(),
            'departmentOptions' => $this->buildDepartmentOptionsFromMaster($filters['site']),
            'accountOptions' => $accountOptions,
            'kpis' => $this->buildKpis($combined['overall']),
        ];
    }

    public function getAccountsData(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        $combined = $this->fetchCombinedAggregates($filters, ['overall', 'acc']);

        $accountSummary = $this->postProcessAccountTotals($combined['account']);
        $accountOptions = $this->accountOptionsFromSummary($accountSummary);

        return [
            'filters' => $filters,
            'departmentSummary' => collect(),
            'accountSummary' => $accountSummary,
            'monthlySummary' => collect(),
            'departmentMonthly' => collect(),
            'expenseMatrix' => ['accounts' => collect(), 'rows' => collect(), 'columnTotals' => [], 'grandTotal' => 0],
            'rows' => collect(),
            'detailRows' => collect(),
            'divisionGroupOptions' => $this->divisionGroupOptions(),
            'departmentOptions' => $this->buildDepartmentOptionsFromMaster($filters['site']),
            'accountOptions' => $accountOptions,
            'kpis' => $this->buildKpis($combined['overall']),
        ];
    }

    public function getMatrixData(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        $combined = $this->fetchCombinedAggregates($filters, ['overall', 'matrix']);

        $expenseMatrix = $this->postProcessMatrix($combined['matrix'], $filters['site']);
        $accountOptions = $expenseMatrix['accounts']
            ->filter(fn($a) => $this->isAccountOptionCode($a->code ?? ''))
            ->map(fn($a) => trim($a->code . ' ' . $a->name))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return [
            'filters' => $filters,
            'departmentSummary' => collect(),
            'accountSummary' => collect(),
            'monthlySummary' => collect(),
            'departmentMonthly' => collect(),
            'expenseMatrix' => $expenseMatrix,
            'rows' => collect(),
            'detailRows' => collect(),
            'divisionGroupOptions' => $this->divisionGroupOptions(),
            'departmentOptions' => $this->buildDepartmentOptionsFromMaster($filters['site']),
            'accountOptions' => $accountOptions,
            'kpis' => $this->buildKpis($combined['overall']),
        ];
    }

    private function accountOptionsFromSummary(Collection $accountRows): Collection
    {
        return $accountRows
            ->filter(fn($row) => $this->isAccountOptionCode($row->account_code ?? ''))
            ->map(fn($row) => trim(($row->account_code ?? '') . ' ' . ($row->account_name ?? '')))
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    private function filterAccountOptionLabels(Collection $options): Collection
    {
        return $options
            ->map(fn($option) => $this->cleanText($option ?? ''))
            ->filter(fn($option) => $option !== '' && $this->isAccountOptionCode($this->splitAccountLabel($option)['code']))
            ->unique()
            ->sort()
            ->values();
    }

    private function isAccountOptionCode(?string $code): bool
    {
        return in_array(trim((string) $code), $this->activeAccountOptionCodes(), true);
    }

    private function activeAccountOptionCodes(): array
    {
        return Cache::remember(self::ACCOUNT_DISPLAY_CACHE_KEY, 300, function () {
            try {
                $exists = DB::connection($this->fcConn)
                    ->table('sys.objects')
                    ->where('object_id', DB::raw("OBJECT_ID(N'dbo.{$this->accountDisplayTable}')"))
                    ->where('type', 'U')
                    ->exists();

                if (!$exists) {
                    return self::DEFAULT_ACCOUNT_OPTION_CODES;
                }

                $rows = DB::connection($this->fcConn)
                    ->table($this->accountDisplayTable)
                    ->select('account_code', 'is_active')
                    ->get();

                if ($rows->isEmpty()) {
                    return self::DEFAULT_ACCOUNT_OPTION_CODES;
                }

                return $rows
                    ->filter(fn($row) => (int) ($row->is_active ?? 0) === 1)
                    ->pluck('account_code')
                    ->map(fn($code) => trim((string) $code))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
            } catch (\Throwable $e) {
                return self::DEFAULT_ACCOUNT_OPTION_CODES;
            }
        });
    }

    private function accountDisplaySqlClause(): array
    {
        $codes = array_values(array_filter($this->activeAccountOptionCodes(), fn($code) => trim((string) $code) !== ''));
        if (empty($codes)) {
            return ['FALSE', []];
        }

        $placeholders = implode(',', array_fill(0, count($codes), '?'));

        return ["split_part(COALESCE(account, ''), ' ', 1) IN ({$placeholders})", $codes];
    }

    private function buildKpis(array $overall): array
    {
        return [
            'total_amount' => (float) $overall['total_amount'],
            'bill_count' => (int) $overall['bill_count'],
            'line_count' => (int) $overall['line_count'],
            'avg_per_bill' => $overall['bill_count'] > 0 ? $overall['total_amount'] / $overall['bill_count'] : 0,
            'account_count' => (int) $overall['account_count'],
            'department_count' => (int) $overall['dept_count'],
        ];
    }

    public function getData(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);

        $combined = $this->fetchCombinedAggregates($filters);
        $departmentRaw = $combined['department'];
        $accountRaw = $combined['account'];
        $matrixRaw = $combined['matrix'];
        $monthlyRaw = $combined['monthly'];
        $overall = $combined['overall'];

        $departmentSummary = $this->postProcessDepartmentTotals($departmentRaw, $filters['site']);
        $accountSummary = $this->postProcessAccountTotals($accountRaw);
        $expenseMatrix = $this->postProcessMatrix($matrixRaw, $filters['site']);
        $monthlySummary = $this->postProcessMonthlySummary($monthlyRaw);

        $departmentCount = $departmentSummary
            ->filter(fn($r) => $r->department !== self::UNASSIGNED_DEPARTMENT)
            ->count();
        $accountCount = $accountSummary->pluck('account_code')->filter()->unique()->count();

        $optionFilters = $filters;
        $optionFilters['department'] = [];
        $optionFilters['account'] = [];
        $accountOptions = (!empty($filters['department']) || !empty($filters['account']))
            ? $this->fetchAccountOptionsAggregate($optionFilters)
            : $this->accountOptionsFromSummary($accountSummary);

        return [
            'filters' => $filters,
            'rows' => collect(),
            'detailRows' => collect(),
            'departmentSummary' => $departmentSummary,
            'accountSummary' => $accountSummary,
            'monthlySummary' => $monthlySummary,
            'departmentMonthly' => collect(),
            'expenseMatrix' => $expenseMatrix,
            'divisionGroupOptions' => $this->divisionGroupOptions(),
            'departmentOptions' => $this->buildDepartmentOptionsFromMaster($filters['site']),
            'accountOptions' => $accountOptions,
            'kpis' => [
                'total_amount' => (float) $overall['total_amount'],
                'bill_count' => (int) $overall['bill_count'],
                'line_count' => (int) $overall['line_count'],
                'avg_per_bill' => $overall['bill_count'] > 0 ? $overall['total_amount'] / $overall['bill_count'] : 0,
                'account_count' => $accountCount,
                'department_count' => $departmentCount,
            ],
        ];
    }

    public function getMonthlyData(array $filters, bool $comparePrevious = false): array
    {
        $filters = $this->normalizeFilters($filters);
        $year = (int) Carbon::parse($filters['date_from'], 'Asia/Bangkok')->year;
        $filters['date_from'] = Carbon::create($year, 1, 1, 0, 0, 0, 'Asia/Bangkok')->toDateString();
        $filters['date_to'] = Carbon::create($year, 12, 31, 0, 0, 0, 'Asia/Bangkok')->toDateString();
        $previousYear = $year - 1;
        $today = Carbon::today('Asia/Bangkok');
        $currentMonthLimit = match (true) {
            $year < (int) $today->year => 12,
            $year === (int) $today->year => (int) $today->month,
            default => 0,
        };

        $fetchFilters = $filters;
        if ($comparePrevious) {
            $fetchFilters['date_from'] = Carbon::create($previousYear, 1, 1, 0, 0, 0, 'Asia/Bangkok')->toDateString();
            $fetchFilters['date_to'] = Carbon::create($year, 12, 31, 0, 0, 0, 'Asia/Bangkok')->toDateString();
        }

        $combined = $this->fetchMonthlyTwoYearsAggregate($fetchFilters);
        $rowsCurrentRaw = $combined->filter(fn($r) => $r->year_no === $year)->values();
        $rowsPreviousRaw = $comparePrevious
            ? $combined->filter(fn($r) => $r->year_no === $previousYear)->values()
            : collect();

        $rows = $this->applyDepartmentDisplayLabels($rowsCurrentRaw, $filters['site']);
        $previousRows = $this->applyDepartmentDisplayLabels($rowsPreviousRaw, $filters['site']);
        $accountOptions = $this->fetchAccountOptionsAggregate($filters);

        $currentGroups = $rows->groupBy(fn($row) => ($row->department_code ?: '') . '|' . $row->department);
        $previousGroups = $previousRows->groupBy(fn($row) => ($row->department_code ?: '') . '|' . $row->department);
        $departmentMonthly = $currentGroups
            ->keys()
            ->merge($previousGroups->keys())
            ->unique()
            ->map(function (string $key) use ($currentGroups, $previousGroups, $currentMonthLimit) {
                $group = $currentGroups->get($key, collect());
                $previousGroup = $previousGroups->get($key, collect());
                $first = $group->first() ?: $previousGroup->first();
                $months = $this->monthlyAmountMap($group, $currentMonthLimit);
                $previousMonths = $this->monthlyAmountMap($previousGroup, $currentMonthLimit);
                $totalAmount = array_sum($months);
                $previousTotalAmount = array_sum($previousMonths);

                return (object) [
                    'department_code' => $first->department_code,
                    'department' => $first->department,
                    'months' => $months,
                    'previous_months' => $previousMonths,
                    'total_amount' => $totalAmount,
                    'previous_total_amount' => $previousTotalAmount,
                    'diff_amount' => $totalAmount - $previousTotalAmount,
                    'diff_percent' => $previousTotalAmount != 0.0 ? (($totalAmount - $previousTotalAmount) / $previousTotalAmount) * 100 : null,
                    'line_count' => (int) $group->sum('line_count'),
                    'bill_count' => (int) $group->sum('bill_count'),
                ];
            })
            ->sortByDesc('total_amount')
            ->values();

        $currentMonthlyGroups = $rows->groupBy('month_no');
        $previousMonthlyGroups = $previousRows->groupBy('month_no');
        $monthlySummary = collect(range(1, 12))
            ->map(function (int $month) use ($year, $currentMonthLimit, $currentMonthlyGroups, $previousMonthlyGroups) {
                $group = $currentMonthlyGroups->get($month, collect());
                $previousGroup = $previousMonthlyGroups->get($month, collect());
                $isFutureMonth = $month > $currentMonthLimit;
                $totalAmount = $isFutureMonth ? null : (float) $group->sum('total_amount');
                $previousTotalAmount = $isFutureMonth ? null : (float) $previousGroup->sum('total_amount');

                return (object) [
                    'month' => Carbon::create($year, $month, 1)->format('Y-m'),
                    'month_no' => $month,
                    'bill_count' => $isFutureMonth ? 0 : (int) $group->sum('bill_count'),
                    'line_count' => $isFutureMonth ? 0 : (int) $group->sum('line_count'),
                    'total_amount' => $totalAmount,
                    'previous_total_amount' => $previousTotalAmount,
                    'diff_amount' => $isFutureMonth ? null : ($totalAmount - $previousTotalAmount),
                    'diff_percent' => !$isFutureMonth && $previousTotalAmount != 0.0 ? (($totalAmount - $previousTotalAmount) / $previousTotalAmount) * 100 : null,
                ];
            })
            ->sortBy('month_no')
            ->values();

        $total = (float) $departmentMonthly->sum('total_amount');
        $billCount = (int) $monthlySummary->sum('bill_count');
        $lineCount = (int) $monthlySummary->sum('line_count');

        return [
            'filters' => $filters,
            'year' => $year,
            'previousYear' => $previousYear,
            'currentMonthLimit' => $currentMonthLimit,
            'comparePrevious' => $comparePrevious,
            'rows' => collect(),
            'detailRows' => collect(),
            'departmentSummary' => collect(),
            'accountSummary' => collect(),
            'monthlySummary' => $monthlySummary,
            'departmentMonthly' => $departmentMonthly,
            'expenseMatrix' => ['accounts' => collect(), 'rows' => collect(), 'columnTotals' => [], 'grandTotal' => $total],
            'divisionGroupOptions' => $this->divisionGroupOptions(),
            'departmentOptions' => $this->buildDepartmentOptionsFromMaster($filters['site']),
            'accountOptions' => $accountOptions,
            'kpis' => [
                'total_amount' => $total,
                'bill_count' => $billCount,
                'line_count' => $lineCount,
                'avg_per_bill' => $billCount > 0 ? $total / $billCount : 0,
                'account_count' => (int) $rows->sum('account_count'),
                'department_count' => $departmentMonthly->count(),
            ],
        ];
    }

    public function getYearlyData(array $filters): array
    {
        $yearlyFilters = $this->normalizeYearlyFilters($filters);
        $year = (int) $yearlyFilters['year'];
        $previousYear = $year - 1;

        $accountInput = $filters['account'] ?? '';
        $accountArray = $this->normalizeFilterArray($accountInput);
        $accountDisplay = is_array($accountInput)
            ? implode(', ', $accountArray)
            : trim((string) $accountInput);

        $baseFilters = [
            'site' => $yearlyFilters['site'],
            'department' => [],
            'account' => $accountArray,
            'invoice' => '',
            'notes' => '',
        ];

        $twoYearFilters = $this->normalizeFilters($baseFilters + [
            'date_from' => "{$previousYear}-01-01",
            'date_to' => "{$year}-12-31",
        ]);
        $combined = $this->fetchYearlyTwoYearsAggregate($twoYearFilters);

        $currentRaw = $combined->filter(fn($r) => (int) $r->year_no === $year)->values();
        $previousRaw = $combined->filter(fn($r) => (int) $r->year_no === $previousYear)->values();

        $currentNormalized = $this->normalizeYearlyRows($currentRaw, $yearlyFilters['site']);
        $previousNormalized = $this->normalizeYearlyRows($previousRaw, $yearlyFilters['site']);

        $classOptions = $currentNormalized
            ->concat($previousNormalized)
            ->groupBy(fn($r) => $this->classKey($r))
            ->map(function (Collection $group, string $key) {
                $first = $group->first();
                return (object) [
                    'key' => $key,
                    'code' => $first->department_code,
                    'name' => $first->department,
                    'label' => trim(($first->department_code ? $first->department_code . ' - ' : '') . $first->department),
                    'total_amount' => (float) $group->sum('total_amount'),
                ];
            })
            ->sortBy('label')
            ->values();

        $selectedClassKeys = collect($yearlyFilters['class'])->filter()->values();
        if ($selectedClassKeys->isEmpty()) {
            $currentFiltered = $currentNormalized;
            $previousFiltered = $previousNormalized;
        } else {
            $keySet = $selectedClassKeys->flip();
            $currentFiltered = $currentNormalized
                ->filter(fn($r) => $keySet->has($this->classKey($r)))
                ->values();
            $previousFiltered = $previousNormalized
                ->filter(fn($r) => $keySet->has($this->classKey($r)))
                ->values();
        }

        $previousByAccount = $previousFiltered
            ->groupBy(fn($r) => $r->account_code . '|' . $r->account_name)
            ->map(fn(Collection $g) => (float) $g->sum('total_amount'));

        $yearlyRows = $currentFiltered
            ->groupBy(fn($r) => $r->account_code . '|' . $r->account_name)
            ->map(function (Collection $group, string $key) use ($previousByAccount) {
                $first = $group->first();
                $months = array_fill(1, 12, 0.0);
                foreach ($group as $r) {
                    $months[(int) $r->month_no] = ($months[(int) $r->month_no] ?? 0) + (float) $r->total_amount;
                }
                return (object) [
                    'account_code' => $first->account_code,
                    'account_name' => $first->account_name,
                    'previous_avg' => (($previousByAccount[$key] ?? 0) / 12),
                    'months' => $months,
                    'total_amount' => array_sum($months),
                ];
            })
            ->sortByDesc('total_amount')
            ->values();

        $monthTotals = [];
        for ($month = 1; $month <= 12; $month++) {
            $monthTotals[$month] = (float) $yearlyRows->sum(fn($row) => $row->months[$month] ?? 0);
        }

        $selectedClasses = $selectedClassKeys->isEmpty()
            ? collect()
            : $classOptions->whereIn('key', $selectedClassKeys->all())->values();
        $selectedClass = $selectedClasses->first();

        $currentClassTotals = $currentFiltered->reduce(function ($carry, $r) {
            $carry['total'] += (float) $r->total_amount;
            $carry['lines'] += (int) ($r->line_count ?? 0);
            $carry['bills'] += (int) ($r->bill_count ?? 0);
            return $carry;
        }, ['total' => 0.0, 'lines' => 0, 'bills' => 0]);

        return [
            'filters' => [
                'date_from' => "{$year}-01-01",
                'date_to' => "{$year}-12-31",
                'site' => $yearlyFilters['site'],
                'department' => $selectedClasses->isNotEmpty()
                    ? $selectedClasses->pluck('label')->implode(', ')
                    : '',
                'account' => $accountDisplay,
                'invoice' => '',
                'notes' => '',
                'year' => $year,
                'class' => $yearlyFilters['class'],
            ],
            'year' => $year,
            'previousYear' => $previousYear,
            'classOptions' => $classOptions,
            'selectedClass' => $selectedClass,
            'selectedClasses' => $selectedClasses,
            'yearlyRows' => $yearlyRows,
            'yearlyTotals' => (object) [
                'previous_avg' => (float) $yearlyRows->sum('previous_avg'),
                'months' => $monthTotals,
                'total_amount' => array_sum($monthTotals),
            ],
            'divisionGroupOptions' => $this->divisionGroupOptions(),
            'departmentOptions' => $classOptions->pluck('label')->values(),
            'accountOptions' => $this->filterAccountOptionLabels(
                $yearlyRows->map(fn($row) => $row->account_code . ' ' . $row->account_name)
            ),
            'kpis' => [
                'total_amount' => (float) $yearlyRows->sum('total_amount'),
                'bill_count' => (int) $currentClassTotals['bills'],
                'line_count' => (int) $currentClassTotals['lines'],
                'avg_per_bill' => $currentClassTotals['bills'] > 0
                    ? (float) $yearlyRows->sum('total_amount') / $currentClassTotals['bills']
                    : 0,
                'account_count' => $yearlyRows->pluck('account_code')->filter()->unique()->count(),
                'department_count' => $selectedClass ? 1 : 0,
            ],
        ];
    }

    public function export(array $filters, ?string $downloadToken = null)
    {
        $this->prepareLongExport();

        $data = $this->getData($filters);
        $filters = $data['filters'];

        $detailRows = $this->recalculateDetailBalances(
            $this->applyDepartmentDisplayLabels($this->fetchRows($filters), $filters['site'])
        );
        $data['rows'] = $detailRows;
        $data['detailRows'] = $this->sortDetailRows($detailRows)->values();
        $data['departmentMonthly'] = $detailRows
            ->groupBy(fn($row) => ($row->department_code ?: '') . '|' . $row->department)
            ->map(function (Collection $group) {
                $first = $group->first();
                $months = [];
                for ($month = 1; $month <= 12; $month++) {
                    $months[$month] = (float) $group
                        ->filter(fn($row) => (int) Carbon::parse($row->transdate)->month === $month)
                        ->sum('amount');
                }
                return (object) [
                    'department_code' => $first->department_code,
                    'department' => $first->department,
                    'months' => $months,
                    'total_amount' => array_sum($months),
                ];
            })
            ->sortByDesc('total_amount')
            ->values();

        $fileName = 'variable_cost_' . str_replace('-', '', $filters['date_from']) . '_' . str_replace('-', '', $filters['date_to']) . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $this->buildSummarySheet($spreadsheet, $data, $filters);
        $this->buildAccountsSheet($spreadsheet, $data);
        $this->buildMonthlySheet($spreadsheet, $data);
        $this->buildMatrixSheet($spreadsheet, $data);
        $this->buildDetailsSheet($spreadsheet, $data);

        $spreadsheet->setActiveSheetIndex(0);

        return $this->downloadSpreadsheet($spreadsheet, $fileName, $downloadToken);
    }

    public function exportMonthly(array $filters, ?string $downloadToken = null)
    {
        $this->prepareLongExport();

        $data = $this->getMonthlyData($filters);
        $filters = $data['filters'];
        $fileName = 'variable_cost_monthly_yoy_' . ($data['year'] ?? Carbon::parse($filters['date_from'])->year) . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);
        $this->buildMonthlySheet($spreadsheet, $data);
        $spreadsheet->setActiveSheetIndex(0);

        return $this->downloadSpreadsheet($spreadsheet, $fileName, $downloadToken);
    }

    private function downloadSpreadsheet(Spreadsheet $spreadsheet, string $fileName, ?string $downloadToken = null)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'vc_xlsx_');
        $writer = new Xlsx($spreadsheet);
        $writer->save($tmp);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $response = response()->streamDownload(function () use ($tmp) {
            $handle = fopen($tmp, 'rb');
            try {
                while ($handle !== false && !feof($handle)) {
                    echo fread($handle, 1024 * 1024);
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }
                    flush();
                }
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
                @unlink($tmp);
            }
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);

        if ($downloadToken !== null && $downloadToken !== '') {
            $response->headers->setCookie(Cookie::make(
                'vc_download_token',
                $downloadToken,
                5,
                '/',
                null,
                false,
                false,
                false,
                'Lax'
            ));
        }

        return $response;
    }

    private function prepareLongExport(): void
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '2048M');
    }

    private function styleHeader(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('2D6A4F');
        $sheet->getStyle($range)->getFont()->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    private function autoSize(Worksheet $sheet, int $columns): void
    {
        for ($i = 1; $i <= $columns; $i++) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
    }

    private function buildSummarySheet(Spreadsheet $spreadsheet, array $data, array $filters): void
    {
        $sheet = new Worksheet($spreadsheet, 'Summary');
        $spreadsheet->addSheet($sheet);

        $sheet->fromArray(['Variable Cost - Summary'], null, 'A1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->fromArray([
            ['Date From', $filters['date_from']],
            ['Date To', $filters['date_to']],
            ['Site', $filters['site']],
            ['Division Group Filter', is_array($filters['division_group']) ? implode(', ', $filters['division_group']) : $filters['division_group']],
            ['Division/Department Mode', $filters['division_department_mode']],
            ['Department Filter', is_array($filters['department']) ? implode(', ', $filters['department']) : $filters['department']],
            ['Account Filter', is_array($filters['account']) ? implode(', ', $filters['account']) : $filters['account']],
            ['Invoice Filter', $filters['invoice']],
            ['Notes Filter', $filters['notes']],
        ], null, 'A3');
        $sheet->getStyle('A3:A9')->getFont()->setBold(true);

        $kpis = $data['kpis'];
        $sheet->fromArray([['KPI']], null, 'A11');
        $sheet->getStyle('A11')->getFont()->setBold(true)->setSize(12);
        $sheet->fromArray([
            ['Total Amount', $kpis['total_amount']],
            ['Bill Count', $kpis['bill_count']],
            ['Line Count', $kpis['line_count']],
            ['Avg per Bill', $kpis['avg_per_bill']],
            ['Account Count', $kpis['account_count']],
            ['Department Count', $kpis['department_count']],
        ], null, 'A12');
        $sheet->getStyle('A12:A17')->getFont()->setBold(true);
        $sheet->getStyle('B12:B17')->getNumberFormat()->setFormatCode('#,##0.00');

        $startRow = 20;
        $sheet->setCellValue('A' . $startRow, 'Department Summary');
        $sheet->getStyle('A' . $startRow)->getFont()->setBold(true)->setSize(12);
        $headerRow = $startRow + 1;
        $sheet->fromArray(
            ['Department Code', 'Department', 'Bills', 'Lines', 'Total Amount', 'Average'],
            null,
            'A' . $headerRow
        );
        $this->styleHeader($sheet, 'A' . $headerRow . ':F' . $headerRow);

        $rowIdx = $headerRow + 1;
        foreach ($data['departmentSummary'] as $row) {
            $sheet->fromArray([
                $row->department_code,
                $row->department,
                $row->bill_count,
                $row->line_count,
                $row->total_amount,
                $row->avg_amount,
            ], null, 'A' . $rowIdx);
            $rowIdx++;
        }
        if ($rowIdx > $headerRow + 1) {
            $sheet->fromArray([
                '',
                'Total',
                '=SUM(C' . ($headerRow + 1) . ':C' . ($rowIdx - 1) . ')',
                '=SUM(D' . ($headerRow + 1) . ':D' . ($rowIdx - 1) . ')',
                '=SUM(E' . ($headerRow + 1) . ':E' . ($rowIdx - 1) . ')',
                null,
            ], null, 'A' . $rowIdx);
            $sheet->getStyle('A' . $rowIdx . ':F' . $rowIdx)->getFont()->setBold(true);
            $sheet->getStyle('E' . ($headerRow + 1) . ':F' . ($rowIdx - 1))
                ->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('E' . $rowIdx . ':F' . $rowIdx)
                ->getNumberFormat()->setFormatCode('#,##0.00');
        }

        $this->autoSize($sheet, 6);
        $sheet->freezePane('A' . ($headerRow + 1));
    }

    private function buildAccountsSheet(Spreadsheet $spreadsheet, array $data): void
    {
        $sheet = new Worksheet($spreadsheet, 'Accounts');
        $spreadsheet->addSheet($sheet);

        $sheet->fromArray(
            ['Account Code', 'Account Name', 'Lines', 'Total Amount'],
            null,
            'A1'
        );
        $this->styleHeader($sheet, 'A1:D1');

        $rowIdx = 2;
        foreach ($data['accountSummary'] as $row) {
            $sheet->fromArray([
                $row->account_code,
                $row->account_name,
                $row->line_count,
                $row->total_amount,
            ], null, 'A' . $rowIdx);
            $rowIdx++;
        }
        if ($rowIdx > 2) {
            $sheet->fromArray([
                '',
                'Total',
                '=SUM(C2:C' . ($rowIdx - 1) . ')',
                '=SUM(D2:D' . ($rowIdx - 1) . ')',
            ], null, 'A' . $rowIdx);
            $sheet->getStyle('A' . $rowIdx . ':D' . $rowIdx)->getFont()->setBold(true);
            $sheet->getStyle('D2:D' . ($rowIdx - 1))->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('D' . $rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');
        }

        $this->autoSize($sheet, 4);
        $sheet->freezePaneByColumnAndRow(1, 2);
    }

    private function buildMonthlySheet(Spreadsheet $spreadsheet, array $data): void
    {
        $sheet = new Worksheet($spreadsheet, 'Monthly');
        $spreadsheet->addSheet($sheet);

        $monthLabels = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
        $hasPrevious = collect($data['departmentMonthly'] ?? [])->contains(fn($row) => isset($row->previous_months));
        $header = ['รหัส', 'แผนก'];
        foreach ($monthLabels as $label) {
            $header[] = $label;
            if ($hasPrevious) {
                $header[] = $label . ' ปีก่อน';
                $header[] = $label . ' %';
            }
        }
        $header[] = 'รวมทั้งปี';
        if ($hasPrevious) {
            $header[] = 'รวมปีก่อน';
            $header[] = 'รวม %';
        }
        $sheet->fromArray($header, null, 'A1');
        $endCol = $this->columnLetter(count($header));
        $this->styleHeader($sheet, 'A1:' . $endCol . '1');

        $rowIdx = 2;
        foreach ($data['departmentMonthly'] as $row) {
            $line = [$row->department_code, $row->department];
            for ($m = 1; $m <= 12; $m++) {
                $line[] = $row->months[$m] ?? 0;
                if ($hasPrevious) {
                    $amount = $row->months[$m] ?? null;
                    $previous = $row->previous_months[$m] ?? null;
                    $line[] = $amount === null ? null : $previous;
                    $line[] = $amount !== null && $previous != 0.0 ? (((float) $amount - (float) $previous) / (float) $previous) : null;
                }
            }
            $line[] = $row->total_amount;
            if ($hasPrevious) {
                $line[] = $row->previous_total_amount ?? 0;
                $line[] = isset($row->diff_percent) && $row->diff_percent !== null ? $row->diff_percent / 100 : null;
            }
            $sheet->fromArray($line, null, 'A' . $rowIdx);
            $rowIdx++;
        }
        if ($rowIdx > 2) {
            $totalLine = ['', 'Total'];
            for ($col = 3; $col <= count($header); $col++) {
                $letter = $this->columnLetter($col);
                $totalLine[] = str_ends_with((string) ($header[$col - 1] ?? ''), '%')
                    ? null
                    : '=SUM(' . $letter . '2:' . $letter . ($rowIdx - 1) . ')';
            }
            $sheet->fromArray($totalLine, null, 'A' . $rowIdx);
            $sheet->getStyle('A' . $rowIdx . ':' . $endCol . $rowIdx)->getFont()->setBold(true);
            $sheet->getStyle('C2:' . $endCol . ($rowIdx - 1))->getNumberFormat()->setFormatCode('#,##0.00');
            if ($hasPrevious) {
                for ($col = 5; $col <= count($header); $col += 3) {
                    $sheet->getStyle($this->columnLetter($col) . '2:' . $this->columnLetter($col) . ($rowIdx - 1))
                        ->getNumberFormat()->setFormatCode('0.0%');
                }
                $sheet->getStyle($endCol . '2:' . $endCol . ($rowIdx - 1))->getNumberFormat()->setFormatCode('0.0%');
            }
            $sheet->getStyle('C' . $rowIdx . ':' . $endCol . $rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');
        }

        $this->autoSize($sheet, count($header));
        $sheet->freezePaneByColumnAndRow(3, 2);
    }

    private function buildMatrixSheet(Spreadsheet $spreadsheet, array $data): void
    {
        $sheet = new Worksheet($spreadsheet, 'Matrix');
        $spreadsheet->addSheet($sheet);

        $matrix = $data['expenseMatrix'];
        $accounts = collect($matrix['accounts']);

        $header = ['รหัสแผนก', 'แผนก'];
        foreach ($accounts as $account) {
            $header[] = trim(($account->code ? $account->code . ' ' : '') . $account->name);
        }
        $header[] = 'รวม';
        $sheet->fromArray($header, null, 'A1');
        $endCol = $this->columnLetter(count($header));
        $this->styleHeader($sheet, 'A1:' . $endCol . '1');

        $rowIdx = 2;
        foreach ($matrix['rows'] as $row) {
            $line = [$row->department_code, $row->department];
            foreach ($accounts as $account) {
                $line[] = $row->amounts[$account->key] ?? 0;
            }
            $line[] = $row->total_amount;
            $sheet->fromArray($line, null, 'A' . $rowIdx);
            $rowIdx++;
        }

        if ($rowIdx > 2) {
            $totalLine = ['', 'Total'];
            foreach ($accounts as $account) {
                $totalLine[] = $matrix['columnTotals'][$account->key] ?? 0;
            }
            $totalLine[] = $matrix['grandTotal'];
            $sheet->fromArray($totalLine, null, 'A' . $rowIdx);
            $sheet->getStyle('A' . $rowIdx . ':' . $endCol . $rowIdx)->getFont()->setBold(true);
        }

        if (count($accounts) > 0) {
            $sheet->getStyle('C2:' . $endCol . $rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');
        }

        $this->autoSize($sheet, count($header));
        $sheet->freezePaneByColumnAndRow(3, 2);
    }

    private function buildDetailsSheet(Spreadsheet $spreadsheet, array $data): void
    {
        $sheet = new Worksheet($spreadsheet, 'Details');
        $spreadsheet->addSheet($sheet);

        $header = [
            'Site',
            'Date',
            'PV No',
            'Invoice No',
            'Order No',
            'Account Code',
            'Account Name',
            'Department Code',
            'Department',
            'Qty',
            'Unit Price',
            'Amount',
            'Balance',
            'Line No',
            'Part Description',
            'Invoice Description',
            'Invoice Class No',
            'Invoice Class',
            'AccTrans Class No',
            'AccTrans Class',
            'Class Match',
            'Notes',
            'F1',
            'F2',
            'F3',
            'F4',
            'F5',
            'Employee',
            'Requester',
            'Currency',
            'Vendor ID',
        ];
        $sheet->fromArray($header, null, 'A1');
        $endCol = $this->columnLetter(count($header));
        $this->styleHeader($sheet, 'A1:' . $endCol . '1');

        $rowIdx = 2;
        $currentDepartmentKey = null;
        $currentDepartmentLabel = '';
        $currentAccountKey = null;
        $currentAccountLabel = '';
        $departmentAmountTotal = 0.0;
        $accountAmountTotal = 0.0;
        $runningBalance = 0.0;
        $grandAmountTotal = 0.0;
        $sortedRows = $this->sortDetailRows(collect($data['rows'] ?? []));

        $writeAccountSubtotal = function () use ($sheet, $endCol, &$rowIdx, &$accountAmountTotal, &$currentAccountLabel, &$runningBalance) {
            if ($currentAccountLabel === '') {
                return;
            }

            $sheet->fromArray([
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                'Subtotal - ' . $currentAccountLabel,
                '',
                '',
                $accountAmountTotal,
                $runningBalance,
            ], null, 'A' . $rowIdx);
            $sheet->getStyle('A' . $rowIdx . ':' . $endCol . $rowIdx)->getFont()->setBold(true);
            $sheet->getStyle('A' . $rowIdx . ':' . $endCol . $rowIdx)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('EAF4EF');
            $rowIdx++;
            $accountAmountTotal = 0.0;
        };

        $writeDepartmentSubtotal = function () use ($sheet, $endCol, &$rowIdx, &$departmentAmountTotal, &$currentDepartmentLabel, &$runningBalance) {
            if ($currentDepartmentLabel === '') {
                return;
            }

            $sheet->fromArray([
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                'Department Total - ' . $currentDepartmentLabel,
                '',
                '',
                $departmentAmountTotal,
                $runningBalance,
            ], null, 'A' . $rowIdx);
            $sheet->getStyle('A' . $rowIdx . ':' . $endCol . $rowIdx)->getFont()->setBold(true);
            $sheet->getStyle('A' . $rowIdx . ':' . $endCol . $rowIdx)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('D9EAD3');
            $rowIdx++;
            $departmentAmountTotal = 0.0;
        };

        $writeDepartmentHeader = function () use ($sheet, $endCol, &$rowIdx, &$currentDepartmentLabel) {
            if ($currentDepartmentLabel === '') {
                return;
            }

            $sheet->fromArray([$currentDepartmentLabel], null, 'A' . $rowIdx);
            $sheet->mergeCells('A' . $rowIdx . ':' . $endCol . $rowIdx);
            $sheet->getStyle('A' . $rowIdx . ':' . $endCol . $rowIdx)->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle('A' . $rowIdx . ':' . $endCol . $rowIdx)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('F2F7F4');
            $rowIdx++;
        };

        foreach ($sortedRows as $row) {
            $departmentKey = trim((string) ($row->department_code ?? '')) . '|' . trim((string) ($row->department ?? ''));
            $accountKey = trim((string) ($row->account_code ?? '')) . '|' . trim((string) ($row->account_name ?? ''));

            if ($currentDepartmentKey !== null && $departmentKey !== $currentDepartmentKey) {
                $writeAccountSubtotal();
                $writeDepartmentSubtotal();
                $currentAccountKey = null;
                $currentAccountLabel = '';
            } elseif ($currentAccountKey !== null && $accountKey !== $currentAccountKey) {
                $writeAccountSubtotal();
            }

            if ($departmentKey !== $currentDepartmentKey) {
                $currentDepartmentKey = $departmentKey;
                $currentDepartmentLabel = trim((string) ($row->department_code ?? '') . ' ' . (string) ($row->department ?? ''));
                $writeDepartmentHeader();
            }

            if ($accountKey !== $currentAccountKey) {
                $currentAccountKey = $accountKey;
                $currentAccountLabel = trim((string) ($row->account_code ?? '') . ' ' . (string) ($row->account_name ?? ''));
            }

            $lines = $this->detailDisplayLines($row);
            foreach ($lines as $index => $detailLine) {
                $lineAmount = (float) ($detailLine['total'] ?? 0);
                $runningBalance += $lineAmount;
                $accountAmountTotal += $lineAmount;
                $departmentAmountTotal += $lineAmount;
                $grandAmountTotal += $lineAmount;

                $line = [
                    $row->site,
                    $row->transdate,
                    $row->apnumber,
                    $row->invnumber,
                    $row->ordnumber,
                    $row->account_code,
                    $row->account_name,
                    $row->department_code,
                    $row->department,
                    $detailLine['qty'],
                    $detailLine['unit'],
                    $lineAmount,
                    $runningBalance,
                    $index + 1,
                    $detailLine['part_description'],
                    $detailLine['invoice_description'],
                    $row->invoice_classnumber,
                    $row->invoice_class_description,
                    $row->acc_classnumber,
                    $row->acc_class_description,
                    $row->class_match_status,
                    $index === 0 ? $row->notes : '',
                    $index === 0 ? $row->f1 : '',
                    $index === 0 ? $row->f2 : '',
                    $index === 0 ? $row->f3 : '',
                    $index === 0 ? $row->f4 : '',
                    $index === 0 ? $row->f5 : '',
                    $row->employee_name,
                    $row->requester_name,
                    $row->currency,
                    $row->vendor_id,
                ];
                $sheet->fromArray($line, null, 'A' . $rowIdx);
                $rowIdx++;
            }
        }
        $writeAccountSubtotal();
        $writeDepartmentSubtotal();

        if ($rowIdx > 2) {
            $sheet->fromArray([
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                'Total',
                '',
                '',
                $grandAmountTotal,
                '=MAX(M2:M' . ($rowIdx - 1) . ')',
            ], null, 'A' . $rowIdx);
            $sheet->getStyle('A' . $rowIdx . ':' . $endCol . $rowIdx)->getFont()->setBold(true);
            $sheet->getStyle('J2:M' . ($rowIdx - 1))->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('J' . $rowIdx . ':M' . $rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');
        }

        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePaneByColumnAndRow(1, 2);
    }

    private function columnLetter(int $columnIndex): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
    }

    private function normalizeFilters(array $filters): array
    {
        $today = Carbon::today('Asia/Bangkok');
        $dateFrom = $this->dateOrDefault($filters['date_from'] ?? null, $today->copy()->startOfMonth()->toDateString());
        $dateTo = $this->dateOrDefault($filters['date_to'] ?? null, $today->copy()->endOfMonth()->toDateString());

        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $site = Str::upper(trim((string) ($filters['site'] ?? '')));
        if (!array_key_exists($site, self::SITES)) {
            $site = 'ALL';
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'site' => $site,
            'division_group' => $this->normalizeFilterArray($filters['division_group'] ?? []),
            'division_department_mode' => Str::upper(trim((string) ($filters['division_department_mode'] ?? 'AND'))) === 'OR' ? 'OR' : 'AND',
            'department' => $this->normalizeFilterArray($filters['department'] ?? []),
            'account' => $this->normalizeFilterArray($filters['account'] ?? []),
            'invoice' => trim((string) ($filters['invoice'] ?? '')),
            'notes' => trim((string) ($filters['notes'] ?? '')),
        ];
    }

    public function normalizeFiltersPublic(array $filters): array
    {
        return $this->normalizeFilters($filters);
    }

    public function departmentOptionsPublic(string $selectedSite): Collection
    {
        return $this->buildDepartmentOptionsFromMaster($selectedSite);
    }

    public function divisionGroupOptionsPublic(): Collection
    {
        return $this->divisionGroupOptions();
    }

    private function normalizeFilterArray($value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn($v) => trim((string) $v), $value),
            fn($v) => $v !== ''
        )));
    }

    private function divisionGroupOptions(): Collection
    {
        return Cache::remember('vc_division_group_options', 300, function () {
            try {
                return DB::connection($this->fcConn)
                    ->table('vc_department_division_groups')
                    ->where('is_active', 1)
                    ->orderBy('division_group')
                    ->pluck('division_group')
                    ->map(fn($value) => trim((string) $value))
                    ->filter()
                    ->unique()
                    ->values();
            } catch (\Throwable $e) {
                return collect();
            }
        });
    }

    private function departmentCodesForDivisionGroups(array $groups, string $site): array
    {
        $groups = $this->normalizeFilterArray($groups);
        if (empty($groups)) {
            return [];
        }

        $site = Str::upper(trim($site));
        $sites = $site === 'ALL' ? array_keys(self::SITES) : [$site];
        $key = 'vc_department_division_group_codes:' . $site . ':' . md5(implode('|', $groups));

        return Cache::remember($key, 300, function () use ($groups, $sites) {
            try {
                return DB::connection($this->fcConn)
                    ->table('vc_department_division_groups')
                    ->whereIn('site', $sites)
                    ->whereIn('division_group', $groups)
                    ->where('is_active', 1)
                    ->pluck('department_code')
                    ->map(fn($value) => trim((string) $value))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
            } catch (\Throwable $e) {
                return ['__NO_MATCH__'];
            }
        }) ?: ['__NO_MATCH__'];
    }

    private function applyDivisionGroupDepartmentFilter($query, array $filters, string $site, string $columnExpression): void
    {
        $codes = $this->departmentCodesForDivisionGroups((array) ($filters['division_group'] ?? []), $site);
        if (empty($codes)) {
            return;
        }

        if (in_array('__NO_MATCH__', $codes, true)) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereIn(DB::raw($columnExpression), $codes);
    }

    private function applyDepartmentAndDivisionGroupFilters($query, array $filters, string $site, string $codeExpression, string $nameExpression): void
    {
        $deptFilters = $this->parseDepartmentFilters((array) ($filters['department'] ?? []));
        $divisionCodes = $this->departmentCodesForDivisionGroups((array) ($filters['division_group'] ?? []), $site);
        $hasDepartmentFilter = !empty($deptFilters);
        $hasDivisionGroupFilter = !empty($divisionCodes);

        if (!$hasDepartmentFilter && !$hasDivisionGroupFilter) {
            return;
        }

        $mode = (string) ($filters['division_department_mode'] ?? 'AND');

        $query->where(function ($outer) use ($deptFilters, $divisionCodes, $hasDepartmentFilter, $hasDivisionGroupFilter, $mode, $site, $codeExpression, $nameExpression) {
            $applyDepartments = function ($q) use ($deptFilters, $site, $codeExpression, $nameExpression) {
                $q->where(function ($deptQuery) use ($deptFilters, $site, $codeExpression, $nameExpression) {
                    $matchedAny = false;
                    foreach ($deptFilters as [$term, $siteFilter]) {
                        if ($siteFilter !== null && $siteFilter !== $site) {
                            continue;
                        }
                        if ($term === '') {
                            continue;
                        }

                        $matchedAny = true;
                        $deptQuery->orWhereRaw("(UPPER(TRIM({$nameExpression})) = UPPER(?) OR UPPER(TRIM({$codeExpression})) = UPPER(?))", [
                            $term,
                            $term,
                        ]);
                    }

                    if (!$matchedAny) {
                        $deptQuery->whereRaw('1 = 0');
                    }
                });
            };

            $applyDivisionGroups = function ($q) use ($divisionCodes, $codeExpression) {
                if (in_array('__NO_MATCH__', $divisionCodes, true)) {
                    $q->whereRaw('1 = 0');
                    return;
                }

                $q->whereIn(DB::raw($codeExpression), $divisionCodes);
            };

            if ($mode === 'OR' && $hasDepartmentFilter && $hasDivisionGroupFilter) {
                $outer->where(function ($orQuery) use ($applyDepartments) {
                    $applyDepartments($orQuery);
                })->orWhere(function ($orQuery) use ($applyDivisionGroups) {
                    $applyDivisionGroups($orQuery);
                });
                return;
            }

            if ($hasDepartmentFilter) {
                $applyDepartments($outer);
            }
            if ($hasDivisionGroupFilter) {
                $applyDivisionGroups($outer);
            }
        });
    }

    private function applyAccountTextFilter($query, array $filters, string $codeColumn = 'c.accno', string $nameColumn = 'c.description'): void
    {
        $accountValues = array_values(array_filter(
            array_map(fn($v) => trim((string) $v), (array) ($filters['account'] ?? [])),
            fn($v) => $v !== ''
        ));

        if (empty($accountValues)) {
            return;
        }

        $query->where(function ($q) use ($accountValues, $codeColumn, $nameColumn) {
            foreach ($accountValues as $accountValue) {
                $q->orWhere($codeColumn, 'ILIKE', '%' . $accountValue . '%')
                    ->orWhere($nameColumn, 'ILIKE', '%' . $accountValue . '%');
            }
        });
    }

    private function shouldExcludeWireChargedToPlus(array $filters, string $site): bool
    {
        return Str::upper(trim($site)) === 'WIRE'
            && Str::upper(trim((string) ($filters['site'] ?? ''))) === 'ALL';
    }

    private function wireChargedToPlusSqlClause(array $filters, string $site): array
    {
        if (!$this->shouldExcludeWireChargedToPlus($filters, $site)) {
            return ['TRUE', []];
        }

        $placeholders = implode(',', array_fill(0, count(self::WIRE_CHARGED_TO_PLUS_ACCOUNT_CODES), '?'));

        return [
            "split_part(COALESCE(account, ''), ' ', 1) NOT IN ({$placeholders})",
            self::WIRE_CHARGED_TO_PLUS_ACCOUNT_CODES,
        ];
    }

    private function isWireChargedToPlusRow($row, array $filters, string $site): bool
    {
        return $this->shouldExcludeWireChargedToPlus($filters, $site)
            && in_array(trim((string) ($row->account_code ?? '')), self::WIRE_CHARGED_TO_PLUS_ACCOUNT_CODES, true);
    }

    private function parseDepartmentFilters(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $value = (string) $value;
            if ($value === '') {
                continue;
            }
            $result[] = $this->parseDepartmentFilter($value);
        }

        return $result;
    }

    private function sortDetailRows(Collection $rows): Collection
    {
        return $rows
            ->sortBy([
                ['site', 'asc'],
                ['department_code', 'asc'],
                ['department', 'asc'],
                ['account_code', 'asc'],
                ['account_name', 'asc'],
                ['transdate', 'asc'],
                ['invnumber', 'asc'],
                ['ordnumber', 'asc'],
                ['ap_id', 'asc'],
            ])
            ->values();
    }

    private function recalculateDetailBalances(Collection $rows): Collection
    {
        $runningBalance = 0.0;

        return $this->sortDetailRows($rows)
            ->map(function ($row) use (&$runningBalance) {
                $runningBalance += (float) ($row->amount ?? 0);
                $row->balance = $runningBalance;

                return $row;
            })
            ->values();
    }

    private function departmentOptions(array $filters, Collection $rows): Collection
    {
        $options = $rows
            ->map(fn($row) => $this->departmentOptionLabel($filters['site'], $row->site ?? '', $row->department ?? ''))
            ->filter();
        $classInfo = $this->getClassInfoBySite();
        $sites = $filters['site'] === 'ALL' ? array_keys(self::SITES) : [$filters['site']];

        foreach ($sites as $site) {
            foreach (($classInfo[$site] ?? []) as $description) {
                $label = $this->departmentOptionLabel($filters['site'], $site, $description);
                if ($label !== '') {
                    $options->push($label);
                }
            }
        }

        return $options
            ->unique()
            ->sort()
            ->values();
    }

    private function departmentOptionLabel(string $selectedSite, string $rowSite, string $department): string
    {
        return $this->stripSiteSuffix($this->cleanText($department));
    }

    private function stripSiteSuffix(string $value): string
    {
        return trim(preg_replace('/\s*\((WIRE|PLUS)\)\s*$/i', '', $value));
    }

    private function parseDepartmentFilter($value): array
    {
        if (is_array($value)) {
            $value = $value[0] ?? '';
        }
        $value = $this->cleanText((string) $value);
        if (preg_match('/^(.*?)\s*\((WIRE|PLUS)\)\s*$/i', $value, $matches)) {
            return [$this->cleanText($matches[1]), Str::upper($matches[2])];
        }

        return [$value, null];
    }

    private function applyDepartmentDisplayLabels(Collection $rows, string $selectedSite): Collection
    {
        return $rows
            ->map(function ($row) use ($selectedSite) {
                $row->department = $this->departmentOptionLabel($selectedSite, (string) ($row->site ?? ''), (string) ($row->department ?? ''));
                if ($row->department === '') {
                    $row->department = self::UNASSIGNED_DEPARTMENT;
                }

                return $row;
            })
            ->values();
    }

    private function normalizeYearlyFilters(array $filters): array
    {
        $year = (int) ($filters['year'] ?? Carbon::today('Asia/Bangkok')->year);
        if ($year < 2000 || $year > 2100) {
            $year = Carbon::today('Asia/Bangkok')->year;
        }

        $site = Str::upper(trim((string) ($filters['site'] ?? '')));
        if (!array_key_exists($site, self::SITES)) {
            $site = 'ALL';
        }

        return [
            'year' => $year,
            'site' => $site,
            'class' => $this->normalizeFilterArray($filters['class'] ?? []),
        ];
    }

    private function dateOrDefault(?string $value, string $default): string
    {
        if (!$value) {
            return $default;
        }

        try {
            return Carbon::parse($value, 'Asia/Bangkok')->toDateString();
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private function fetchRows(array $filters): Collection
    {
        $sites = $filters['site'] === 'ALL'
            ? self::SITES
            : [$filters['site'] => self::SITES[$filters['site']]];

        $rows = collect();
        foreach ($sites as $site => $connection) {
            $rows = $rows->concat($this->fetchCostCenterRowsForConnection($connection, $site, $filters));
        }

        return $rows
            ->filter(fn($row) => !in_array(trim((string) ($row->account_code ?? '')), self::EXCLUDED_ACCOUNT_CODES, true))
            ->filter(fn($row) => !$this->isWireChargedToPlusRow($row, $filters, (string) ($row->site ?? '')))
            ->filter(fn($row) => $this->isAccountOptionCode($row->account_code ?? ''))
            ->sortBy([
                ['site', 'asc'],
                ['department_code', 'asc'],
                ['department', 'asc'],
                ['account_code', 'asc'],
                ['account_name', 'asc'],
                ['transdate', 'asc'],
                ['invnumber', 'asc'],
            ])
            ->values();
    }

    private function fetchMonthlyRows(array $filters): Collection
    {
        $sites = $filters['site'] === 'ALL'
            ? self::SITES
            : [$filters['site'] => self::SITES[$filters['site']]];

        $rows = collect();
        foreach ($sites as $site => $connection) {
            $rows = $rows->concat($this->fetchMonthlyRowsForConnection($connection, $site, $filters));
        }

        $deptFilters = $this->parseDepartmentFilters((array) ($filters['department'] ?? []));
        if (!empty($deptFilters)) {
            $rows = $rows->filter(function ($row) use ($deptFilters) {
                $rowSite = (string) ($row->site ?? '');
                $dept = Str::upper($this->cleanText($row->department ?? ''));
                $code = Str::upper($this->cleanText($row->department_code ?? ''));
                foreach ($deptFilters as [$term, $siteFilter]) {
                    if ($siteFilter !== null && $siteFilter !== $rowSite) {
                        continue;
                    }
                    $needle = Str::upper($term);
                    if ($needle === '') {
                        if ($siteFilter !== null) {
                            return true;
                        }
                        continue;
                    }
                    if ($dept === $needle || $code === $needle) {
                        return true;
                    }
                }
                return false;
            });
        }

        return $rows->values();
    }

    private function fetchMonthlyRowsForConnection(string $connection, string $site, array $filters): Collection
    {
        [$sql, $bindings] = $this->monthlyAggregateSql($filters, $site);
        $rows = $this->runTunedSelect($connection, $sql, $bindings);

        return $rows
            ->map(function ($row) use ($site) {
                $row->site = $site;
                $row->department_code = $this->cleanText($row->classnumber ?? '');
                $row->department = $this->cleanText($row->dept_desc ?? '') ?: self::UNASSIGNED_DEPARTMENT;
                [$resolvedCode, $resolvedName] = $this->resolveDepartment($site, $row->department_code, $row->department);
                if ($resolvedCode !== '') {
                    $row->department_code = $resolvedCode;
                    $row->department = $resolvedName;
                }
                $row->month_no = (int) $row->month_no;
                $row->total_amount = (float) $row->total_amount;
                $row->line_count = (int) $row->line_count;
                $row->bill_count = (int) $row->bill_count;
                $row->account_count = (int) $row->account_count;

                return $row;
            })
            ->values();
    }

    private function monthlyAccountOptions(array $filters): Collection
    {
        $sites = $filters['site'] === 'ALL'
            ? self::SITES
            : [$filters['site'] => self::SITES[$filters['site']]];

        $options = collect();
        foreach ($sites as $site => $connection) {
            [$sql, $bindings] = $this->monthlyAccountOptionsSql($filters, $site);
            $options = $options->concat($this->runTunedSelect($connection, $sql, $bindings)
                ->map(fn($row) => $this->cleanText($row->account ?? '')));
        }

        return $options
            ->pipe(fn(Collection $rows) => $this->filterAccountOptionLabels($rows));
    }

    private function monthlyFilterClause(array $filters, string $site): array
    {
        [$displayAccountSql, $displayAccountBindings] = $this->accountDisplaySqlClause();
        [$wireChargeSql, $wireChargeBindings] = $this->wireChargedToPlusSqlClause($filters, $site);
        $accounts = array_values(array_filter(
            array_map(fn($v) => trim((string) $v), (array) ($filters['account'] ?? [])),
            fn($v) => $v !== ''
        ));

        if (empty($accounts)) {
            $accountSql = 'TRUE';
            $accountBindings = [];
        } else {
            $parts = array_fill(0, count($accounts), 'account ILIKE ?');
            $accountSql = '(' . implode(' OR ', $parts) . ')';
            $accountBindings = array_map(fn($a) => '%' . $a . '%', $accounts);
        }

        $invoice = (string) ($filters['invoice'] ?? '');
        $notes = (string) ($filters['notes'] ?? '');

        $where = $wireChargeSql . " AND " . $displayAccountSql . " AND " . $accountSql
            . " AND (? = '' OR invnumber ILIKE '%' || ? || '%' OR apnumber ILIKE '%' || ? || '%' OR COALESCE(ordnumber, '') ILIKE '%' || ? || '%')"
            . " AND (? = '' OR COALESCE(notes, '') ILIKE '%' || ? || '%' OR COALESCE(item_desc, '') ILIKE '%' || ? || '%')";

        $bindings = array_merge(
            [$filters['date_from'], $filters['date_to']],
            $wireChargeBindings,
            $displayAccountBindings,
            $accountBindings,
            [$invoice, $invoice, $invoice, $invoice],
            [$notes, $notes, $notes]
        );

        return [$where, $bindings];
    }

    private function monthlyAmountMap(Collection $rows, int $monthLimit = 12): array
    {
        $months = [];
        for ($month = 1; $month <= 12; $month++) {
            $months[$month] = $month > $monthLimit ? null : (float) $rows
                ->where('month_no', $month)
                ->sum('total_amount');
        }

        return $months;
    }

    private function costCenterSql(): string
    {
        return $this->costCenterCteSql() . <<<'SQL'

        SELECT
            source,
            source_id,
            line_seq,
            classnumber,
            dept_desc,
            transdate,
            invnumber,
            ordnumber,
            apnumber,
            account,
            item_desc,
            qty,
            unit_price,
            amount,
            f1,
            f2,
            f3,
            f4,
            f5,
            SUM(amount) OVER (
                PARTITION BY classnumber
                ORDER BY transdate, invnumber
                ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
            ) AS running_total,
            NULL::numeric AS subtotal,
            1 AS sort_order,
            notes
        FROM detail
        ORDER BY classnumber, sort_order, transdate NULLS LAST, invnumber NULLS LAST
        SQL;
    }

    private function fetchCostCenterRowsForConnection(string $connection, string $site, array $filters): Collection
    {
        $db = DB::connection($connection);
        $rows = collect($db->select($this->costCenterSql(), [
            $filters['date_from'],
            $filters['date_to'],
        ]))->map(function ($row) use ($site) {
            $accountParts = $this->splitAccountLabel($row->account ?? '');

            $row->site = $site;
            $row->source = $this->cleanText($row->source ?? 'VC');
            $row->ap_id = $row->source . '-' . ($row->source_id ?? '') . '-' . ($row->line_seq ?? '');
            $row->site_ap_id = $site . '-' . $row->source . '-' . ($row->source_id ?? $row->invnumber ?? '');
            $row->transdate = $row->transdate ? Carbon::parse($row->transdate)->toDateString() : null;
            $row->apnumber = $this->cleanText($row->apnumber ?? '');
            $row->invnumber = $this->cleanText($row->invnumber ?? '');
            $row->ordnumber = $this->cleanText($row->ordnumber ?? '');
            $row->bill_amount = (float) ($row->amount ?? 0);
            $row->paid = null;
            $row->notes = $this->cleanText($row->notes ?? '');
            $row->f1 = $this->cleanText($row->f1 ?? '');
            $row->f2 = $this->cleanText($row->f2 ?? '');
            $row->f3 = $this->cleanText($row->f3 ?? '');
            $row->f4 = $this->cleanText($row->f4 ?? '');
            $row->f5 = $this->cleanText($row->f5 ?? '');
            $row->currency = '';
            $row->vendor_id = null;
            $row->account_code = $accountParts['code'];
            $row->account_name = $accountParts['name'];
            $row->account_chart_id = null;
            $row->acc_class_id = null;
            $row->acc_classnumber = $this->cleanText($row->classnumber ?? '');
            $row->acc_class_description = $this->cleanText($row->dept_desc ?? '');
            $row->department_code = $this->cleanText($row->classnumber ?? '');
            $row->department = $this->cleanText($row->dept_desc ?? '') ?: self::UNASSIGNED_DEPARTMENT;
            $row->invoice_class_id = null;
            $row->invoice_classnumber = $row->department_code;
            $row->invoice_class_description = $row->department;
            $row->part_descriptions = '';
            $row->invoice_descriptions = $this->cleanText($row->item_desc ?? '');
            $row->item_qty = is_null($row->qty) ? null : (float) $row->qty;
            $row->item_allocated = null;
            $row->min_unit_price = is_null($row->unit_price) ? null : abs((float) $row->unit_price);
            $row->max_unit_price = $row->min_unit_price;
            $row->item_line_count = 1;
            $row->unit_price_display = $this->unitPriceDisplay($row->min_unit_price, $row->max_unit_price);
            $row->amount = (float) ($row->amount ?? 0);
            $row->balance = (float) ($row->running_total ?? 0);
            $row->preserve_display_qty = true;
            $row->employee_name = '';
            $row->requester_name = '';
            $row->class_match_status = $row->source;
            $row->items = [];

            [$resolvedCode, $resolvedName] = $this->resolveDepartment($site, $row->department_code, $row->department);
            if ($resolvedCode !== '') {
                $row->department_code = $resolvedCode;
                $row->department = $resolvedName;
                $row->invoice_classnumber = $resolvedCode;
                $row->invoice_class_description = $resolvedName;
            }

            return $row;
        });

        return $this->filterCostCenterRows($rows, $filters, $site);
    }

    private function filterCostCenterRows(Collection $rows, array $filters, string $site): Collection
    {
        $deptFilters = $this->parseDepartmentFilters((array) ($filters['department'] ?? []));
        if (!empty($deptFilters)) {
            $applicable = array_values(array_filter(
                $deptFilters,
                fn($pair) => $pair[1] === null || $pair[1] === $site
            ));
            if (empty($applicable)) {
                return collect();
            }
            $needles = array_map(fn($pair) => Str::upper($pair[0]), $applicable);
            $needles = array_values(array_filter($needles, fn($n) => $n !== ''));
            if (!empty($needles)) {
                $rows = $rows->filter(function ($row) use ($needles) {
                    $dept = Str::upper($this->cleanText($row->department ?? ''));
                    $code = Str::upper($this->cleanText($row->department_code ?? ''));
                    foreach ($needles as $n) {
                        if ($dept === $n || $code === $n) {
                            return true;
                        }
                    }
                    return false;
                });
            }
        }

        $accountFilters = (array) ($filters['account'] ?? []);
        if (!empty($accountFilters)) {
            $needles = array_values(array_filter(
                array_map(fn($v) => Str::lower(trim((string) $v)), $accountFilters),
                fn($v) => $v !== ''
            ));
            if (!empty($needles)) {
                $rows = $rows->filter(function ($row) use ($needles) {
                    $haystack = Str::lower($row->account_code . ' ' . $row->account_name);
                    foreach ($needles as $n) {
                        if (Str::contains($haystack, $n)) {
                            return true;
                        }
                    }
                    return false;
                });
            }
        }

        if ($filters['invoice'] !== '') {
            $needle = Str::lower($filters['invoice']);
            $rows = $rows->filter(function ($row) use ($needle) {
                return Str::contains(Str::lower($row->invnumber . ' ' . $row->apnumber), $needle);
            });
        }

        if ($filters['notes'] !== '') {
            $needle = Str::lower($filters['notes']);
            $rows = $rows->filter(function ($row) use ($needle) {
                return Str::contains(Str::lower($row->notes . ' ' . $row->invoice_descriptions), $needle);
            });
        }

        return $rows->values();
    }

    private function splitAccountLabel(?string $label): array
    {
        $label = $this->cleanText($label);
        if (preg_match('/^(\S+)\s+(.*)$/u', $label, $matches)) {
            return [
                'code' => $matches[1],
                'name' => trim($matches[2]),
            ];
        }

        return [
            'code' => $label,
            'name' => '',
        ];
    }

    private function costCenterCteSql(): string
    {
        return <<<'SQL'
        WITH params AS (
            SELECT ?::date AS date_from, ?::date AS date_to
        ),
        ap_scope AS (
            SELECT id
            FROM ap
            WHERE transdate BETWEEN (SELECT date_from FROM params) AND (SELECT date_to FROM params)
        ),
        acc AS (
            SELECT DISTINCT ON (at.trans_id)
                at.trans_id,
                ch.accno || ' ' || ch.description AS account
            FROM acc_trans at
            JOIN chart ch ON ch.id = at.chart_id
            WHERE at.amount < 0
            AND ch.accno NOT LIKE '2%'
            AND at.trans_id IN (SELECT id FROM ap_scope)
            ORDER BY at.trans_id,
                CASE WHEN ch.accno NOT LIKE '1%' THEN 0 ELSE 1 END,
                CASE WHEN ch.accno LIKE '111%' THEN 1 ELSE 0 END,
                ch.accno
        ),
        acc_line AS (
            SELECT DISTINCT ON (at.trans_id, at.amount)
                at.trans_id,
                at.amount,
                ch.accno || ' ' || ch.description AS account
            FROM acc_trans at
            JOIN chart ch ON ch.id = at.chart_id
            WHERE at.amount < 0
            AND ch.accno NOT LIKE '2%'
            AND at.trans_id IN (SELECT id FROM ap_scope)
            ORDER BY at.trans_id, at.amount,
                CASE WHEN ch.accno NOT LIKE '1%' THEN 0 ELSE 1 END,
                CASE WHEN ch.accno LIKE '111%'   THEN 1 ELSE 0 END,
                ch.accno
        ),
        cc_at AS (
            SELECT
                cc.trans_id AS ap_id,
                cc.class_id,
                at.id AS at_id,
                at.amount,
                at.chart_id,
                ROW_NUMBER() OVER (PARTITION BY cc.trans_id ORDER BY at.id) AS rn
            FROM ccalloc cc
            JOIN acc_trans at ON at.id = cc.acc_trans_id
            WHERE cc.class_id > 0
            AND at.amount < 0
            AND cc.trans_id IN (SELECT id FROM ap_scope)
        ),
        inv_ranked AS (
            SELECT
                trans_id,
                description,
                qty,
                sellprice,
                ROW_NUMBER() OVER (PARTITION BY trans_id ORDER BY id) AS rn
            FROM invoice
            WHERE trans_id IN (SELECT id FROM ap_scope)
        ),
        detail AS (
            SELECT
                'AP_INVOICE'::text AS source,
                ap.id AS source_id,
                inv.id AS line_seq,
                ci.classnumber,
                ci.description AS dept_desc,
                ap.transdate,
                ap.invnumber,
                ap.ordnumber,
                ap.apnumber,
                COALESCE(
                    al.account,
                    acc.account,
                    ch.accno || ' ' || ch.description
                ) AS account,
                inv.description AS item_desc,
                inv.qty * -1 AS qty,
                inv.sellprice * -1 AS unit_price,
                inv.sellprice * inv.qty * -1 AS amount,
                ap.notes,
                ap.f1,
                ap.f2,
                ap.f3,
                ap.f4,
                ap.f5
            FROM ap
            JOIN invoice inv ON inv.trans_id = ap.id
            JOIN classinfo ci ON ci.id = inv.class_id
            LEFT JOIN chart ch ON ch.id = inv.chart_id
            LEFT JOIN acc ON acc.trans_id = ap.id
            LEFT JOIN acc_line al ON al.trans_id = ap.id
                AND al.amount = inv.sellprice * inv.qty
            WHERE ap.transdate BETWEEN (SELECT date_from FROM params) AND (SELECT date_to FROM params)
            AND inv.class_id > 0

            UNION ALL

            SELECT
                'AP_CC'::text AS source,
                ap.id AS source_id,
                ca.at_id AS line_seq,
                ci.classnumber,
                ci.description,
                ap.transdate,
                ap.invnumber,
                ap.ordnumber,
                ap.apnumber,
                ch.accno || ' ' || ch.description,
                COALESCE(ir.description, ch.description),
                COALESCE(ir.qty, -1),
                ca.amount * -1,
                ca.amount * -1,
                ap.notes,
                ap.f1,
                ap.f2,
                ap.f3,
                ap.f4,
                ap.f5
            FROM ap
            JOIN cc_at ca ON ca.ap_id = ap.id
            JOIN classinfo ci ON ci.id = ca.class_id
            LEFT JOIN inv_ranked ir ON ir.trans_id = ap.id AND ir.rn = ca.rn
            LEFT JOIN chart ch ON ch.id = ca.chart_id
            WHERE ap.transdate BETWEEN (SELECT date_from FROM params) AND (SELECT date_to FROM params)
            AND NOT EXISTS (
                SELECT 1 FROM invoice inv2
                WHERE inv2.trans_id = ap.id AND inv2.class_id > 0
            )

            UNION ALL

            SELECT DISTINCT
                'AP_CASH_INVOICE'::text AS source,
                ap.id AS source_id,
                inv.id AS line_seq,
                ci.classnumber,
                ci.description,
                ap.transdate,
                ap.invnumber,
                ap.ordnumber,
                ap.apnumber,
                ch.accno || ' ' || ch.description,
                COALESCE(inv.description, ap.notes),
                inv.qty,
                inv.sellprice,
                inv.sellprice * inv.qty,
                ap.notes,
                ap.f1,
                ap.f2,
                ap.f3,
                ap.f4,
                ap.f5
            FROM ap
            JOIN cashtrans ct ON ct.ap_ar_id = ap.id
            JOIN cashout co ON co.id = ct.trans_id
            JOIN ccalloc cc ON cc.trans_id = co.id
            JOIN classinfo ci ON ci.id = cc.class_id
            JOIN invoice inv ON inv.trans_id = ap.id
            LEFT JOIN chart ch ON ch.id = inv.chart_id
            WHERE ap.transdate BETWEEN (SELECT date_from FROM params) AND (SELECT date_to FROM params)
            AND cc.class_id > 0
            AND NOT EXISTS (
                SELECT 1 FROM invoice inv2
                WHERE inv2.trans_id = ap.id AND inv2.class_id > 0
            )
            AND NOT EXISTS (
                SELECT 1 FROM ccalloc cc2
                WHERE cc2.trans_id = ap.id AND cc2.class_id > 0
            )
            AND NOT EXISTS (
                SELECT 1 FROM invoice inv3
                JOIN chart ch3 ON ch3.id = inv3.chart_id
                WHERE inv3.trans_id = ap.id
                    AND ch3.accno LIKE '1%'
            )
            AND (
                SELECT SUM(inv4.sellprice * inv4.qty)
                FROM invoice inv4
                WHERE inv4.trans_id = ap.id
            ) = ap.amount
            AND (
                SELECT COUNT(DISTINCT cc3.class_id)
                FROM cashtrans ct3
                JOIN cashout co3 ON co3.id = ct3.trans_id
                JOIN ccalloc cc3 ON cc3.trans_id = co3.id
                WHERE ct3.ap_ar_id = ap.id
                    AND cc3.class_id > 0
            ) = 1
            AND EXISTS (
                SELECT 1 FROM invoice inv_check
                WHERE inv_check.trans_id = ap.id
                    AND inv_check.description IS NOT NULL
                    AND inv_check.description != ''
            )

            UNION ALL

            SELECT DISTINCT
                'AP_CASH_ALLOC'::text AS source,
                ap.id AS source_id,
                cc.id AS line_seq,
                ci.classnumber,
                ci.description,
                ap.transdate,
                ap.invnumber,
                ap.ordnumber,
                ap.apnumber,
                ch.accno || ' ' || ch.description,
                ap.notes,
                -1,
                cc.amount * -1,
                cc.amount * -1,
                ap.notes,
                ap.f1,
                ap.f2,
                ap.f3,
                ap.f4,
                ap.f5
            FROM ap
            JOIN cashtrans ct ON ct.ap_ar_id = ap.id
            JOIN cashout co ON co.id = ct.trans_id
            JOIN ccalloc cc ON cc.trans_id = co.id
            JOIN classinfo ci ON ci.id = cc.class_id
            LEFT JOIN chart ch ON ch.id = cc.chart_id
            WHERE ap.transdate BETWEEN (SELECT date_from FROM params) AND (SELECT date_to FROM params)
            AND cc.class_id > 0
            AND NOT EXISTS (
                SELECT 1 FROM invoice inv2
                WHERE inv2.trans_id = ap.id AND inv2.class_id > 0
            )
            AND NOT EXISTS (
                SELECT 1 FROM ccalloc cc2
                WHERE cc2.trans_id = ap.id AND cc2.class_id > 0
            )
            AND (
                EXISTS (
                    SELECT 1 FROM invoice inv3
                    JOIN chart ch3 ON ch3.id = inv3.chart_id
                    WHERE inv3.trans_id = ap.id
                        AND ch3.accno LIKE '1%'
                )
                OR (
                    SELECT COALESCE(SUM(inv4.sellprice * inv4.qty), 0)
                    FROM invoice inv4
                    WHERE inv4.trans_id = ap.id
                ) != ap.amount
                OR NOT EXISTS (
                    SELECT 1 FROM invoice inv5
                    WHERE inv5.trans_id = ap.id
                )
                OR (
                    SELECT COUNT(DISTINCT cc3.class_id)
                    FROM cashtrans ct3
                    JOIN cashout co3 ON co3.id = ct3.trans_id
                    JOIN ccalloc cc3 ON cc3.trans_id = co3.id
                    WHERE ct3.ap_ar_id = ap.id
                        AND cc3.class_id > 0
                ) > 1
                OR NOT EXISTS (
                    SELECT 1 FROM invoice inv_desc
                    WHERE inv_desc.trans_id = ap.id
                        AND inv_desc.description IS NOT NULL
                        AND inv_desc.description != ''
                )
            )
            AND NOT (
                NOT EXISTS (
                    SELECT 1 FROM invoice inv6
                    JOIN chart ch6 ON ch6.id = inv6.chart_id
                    WHERE inv6.trans_id = ap.id
                        AND ch6.accno LIKE '1%'
                )
                AND (
                    SELECT SUM(inv7.sellprice * inv7.qty)
                    FROM invoice inv7
                    WHERE inv7.trans_id = ap.id
                ) = ap.amount
                AND (
                    SELECT COUNT(DISTINCT cc4.class_id)
                    FROM cashtrans ct4
                    JOIN cashout co4 ON co4.id = ct4.trans_id
                    JOIN ccalloc cc4 ON cc4.trans_id = co4.id
                    WHERE ct4.ap_ar_id = ap.id
                        AND cc4.class_id > 0
                ) = 1
                AND EXISTS (
                    SELECT 1 FROM invoice inv8
                    WHERE inv8.trans_id = ap.id
                        AND inv8.description IS NOT NULL
                        AND inv8.description != ''
                )
            )

            UNION ALL

            SELECT
                'GL_IU'::text AS source,
                g.id AS source_id,
                pm.id AS line_seq,
                ci.classnumber,
                ci.description,
                g.transdate,
                g.transnumber,
                NULL AS ordnumber,
                g.transnumber,
                ch.accno || ' ' || ch.description,
                g.description,
                pm.qty * -1,
                pm.unitcost,
                pm.qty * pm.unitcost * -1,
                g.notes,
                NULL,
                NULL,
                NULL,
                NULL,
                NULL
            FROM gl g
            JOIN partsmvmt pm ON pm.trans_id = g.id
            JOIN classinfo ci ON ci.id = pm.class_id
            LEFT JOIN parts p ON p.id = pm.parts_id
            LEFT JOIN chart ch ON ch.id = p.expense_accno_id
            WHERE g.transdate BETWEEN (SELECT date_from FROM params) AND (SELECT date_to FROM params)
            AND g.transnumber ILIKE 'IU%'
            AND NOT g.transnumber ILIKE 'IUB%'
            AND pm.class_id > 0

            UNION ALL

            SELECT
                'GL_RECLASS'::text AS source,
                g.id AS source_id,
                cc.id AS line_seq,
                ci.classnumber,
                ci.description,
                g.transdate,
                g.transnumber,
                NULL AS ordnumber,
                g.transnumber,
                ch.accno || ' ' || ch.description,
                g.description,
                -1,
                cc.amount * -1,
                cc.amount * -1,
                g.notes,
                NULL,
                NULL,
                NULL,
                NULL,
                NULL
            FROM gl g
            JOIN ccalloc cc ON cc.trans_id = g.id
            JOIN classinfo ci ON ci.id = cc.class_id
            LEFT JOIN acc_trans at ON at.id = cc.acc_trans_id
            LEFT JOIN chart ch ON ch.id = at.chart_id
            WHERE g.transdate BETWEEN (SELECT date_from FROM params) AND (SELECT date_to FROM params)
            AND g.transnumber ILIKE 'GL%'
            AND cc.class_id > 0
        )
        SQL;
    }

    private function monthlyAggregateSql(array $filters, string $site): array
    {
        [$where, $bindings] = $this->monthlyFilterClause($filters, $site);
        $sql = $this->costCenterCteSql() . "\n, filtered AS (\n    SELECT *\n    FROM detail\n    WHERE {$where}\n)\nSELECT\n    classnumber,\n    dept_desc,\n    EXTRACT(MONTH FROM transdate)::int AS month_no,\n    SUM(amount) AS total_amount,\n    COUNT(*) AS line_count,\n    COUNT(DISTINCT source || '-' || source_id::text) AS bill_count,\n    COUNT(DISTINCT account) AS account_count\nFROM filtered\nGROUP BY classnumber, dept_desc, EXTRACT(MONTH FROM transdate)::int\nORDER BY classnumber, month_no";

        return [$sql, $bindings];
    }

    private function monthlyAccountOptionsSql(array $filters, string $site): array
    {
        [$where, $bindings] = $this->monthlyFilterClause($filters, $site);
        $sql = $this->costCenterCteSql() . "\n, filtered AS (\n    SELECT *\n    FROM detail\n    WHERE {$where}\n)\nSELECT DISTINCT account\nFROM filtered\nWHERE COALESCE(account, '') <> ''\nORDER BY account";

        return [$sql, $bindings];
    }

    private function aggregateFilterClause(array $filters, string $site): array
    {
        [$displayAccountSql, $displayAccountBindings] = $this->accountDisplaySqlClause();
        $accounts = array_values(array_filter(
            array_map(fn($v) => trim((string) $v), (array) ($filters['account'] ?? [])),
            fn($v) => $v !== ''
        ));
        if (empty($accounts)) {
            $accountSql = 'TRUE';
            $accountBindings = [];
        } else {
            $parts = array_fill(0, count($accounts), 'account ILIKE ?');
            $accountSql = '(' . implode(' OR ', $parts) . ')';
            $accountBindings = array_map(fn($v) => '%' . $v . '%', $accounts);
        }

        $divisionGroupDepartmentCodes = $this->departmentCodesForDivisionGroups((array) ($filters['division_group'] ?? []), $site);
        if (empty($divisionGroupDepartmentCodes)) {
            $divisionGroupSql = 'TRUE';
            $divisionGroupBindings = [];
        } elseif (in_array('__NO_MATCH__', $divisionGroupDepartmentCodes, true)) {
            $divisionGroupSql = 'FALSE';
            $divisionGroupBindings = [];
        } else {
            $divisionGroupSql = 'COALESCE(classnumber, \'\') IN (' . implode(',', array_fill(0, count($divisionGroupDepartmentCodes), '?')) . ')';
            $divisionGroupBindings = $divisionGroupDepartmentCodes;
        }

        $invoice = (string) ($filters['invoice'] ?? '');
        $invoiceSql = "(? = '' OR invnumber ILIKE '%' || ? || '%' OR apnumber ILIKE '%' || ? || '%' OR COALESCE(ordnumber, '') ILIKE '%' || ? || '%')";
        $invoiceBindings = [$invoice, $invoice, $invoice, $invoice];

        $notes = (string) ($filters['notes'] ?? '');
        $notesSql = "(? = '' OR COALESCE(notes, '') ILIKE '%' || ? || '%' OR COALESCE(item_desc, '') ILIKE '%' || ? || '%')";
        $notesBindings = [$notes, $notes, $notes];

        $deptFilters = $this->parseDepartmentFilters((array) ($filters['department'] ?? []));
        $deptBindings = [];
        if (empty($deptFilters)) {
            $deptSql = 'TRUE';
        } else {
            $applicable = array_values(array_filter(
                $deptFilters,
                fn($pair) => $pair[1] === null || $pair[1] === $site
            ));
            if (empty($applicable)) {
                $deptSql = 'FALSE';
            } else {
                $clauseParts = [];
                foreach ($applicable as [$term, $_]) {
                    if ($term === '') {
                        continue;
                    }
                    $clauseParts[] = '(UPPER(TRIM(classnumber)) = UPPER(?) OR UPPER(TRIM(dept_desc)) = UPPER(?))';
                    $deptBindings[] = $term;
                    $deptBindings[] = $term;
                }
                $deptSql = empty($clauseParts) ? 'TRUE' : '(' . implode(' OR ', $clauseParts) . ')';
            }
        }

        $excludeList = implode(',', array_map(fn($v) => "'" . $v . "'", self::EXCLUDED_ACCOUNT_CODES));
        $excludeSql = "split_part(COALESCE(account, ''), ' ', 1) NOT IN ({$excludeList})";
        [$wireChargeSql, $wireChargeBindings] = $this->wireChargedToPlusSqlClause($filters, $site);

        $hasDivisionGroupFilter = !empty((array) ($filters['division_group'] ?? []));
        $hasDepartmentFilter = !empty((array) ($filters['department'] ?? []));
        $useDepartmentOrDivisionGroup = ($filters['division_department_mode'] ?? 'AND') === 'OR'
            && $hasDivisionGroupFilter
            && $hasDepartmentFilter;

        if ($useDepartmentOrDivisionGroup) {
            $departmentDivisionSql = "(({$divisionGroupSql}) OR ({$deptSql}))";
            $departmentDivisionBindings = array_merge($divisionGroupBindings, $deptBindings);
        } else {
            $departmentDivisionSql = "{$divisionGroupSql} AND {$deptSql}";
            $departmentDivisionBindings = array_merge($divisionGroupBindings, $deptBindings);
        }

        $where = "{$excludeSql} AND {$wireChargeSql} AND {$displayAccountSql} AND {$departmentDivisionSql} AND {$accountSql} AND {$invoiceSql} AND {$notesSql}";
        $bindings = array_merge($wireChargeBindings, $displayAccountBindings, $departmentDivisionBindings, $accountBindings, $invoiceBindings, $notesBindings);

        return [$where, $bindings];
    }

    private function buildAggregateSql(array $filters, string $site, string $bodySql): array
    {
        [$where, $extraBindings] = $this->aggregateFilterClause($filters, $site);
        $sql = $this->costCenterCteSql()
            . "\n, filtered AS (\n    SELECT * FROM detail\n    WHERE {$where}\n)\n"
            . $bodySql;
        $bindings = array_merge([$filters['date_from'], $filters['date_to']], $extraBindings);

        return [$sql, $bindings];
    }

    private function runTunedSelect(string $connection, string $sql, array $bindings, int $cacheTtl = 300): Collection
    {
        $key = 'vc_agg:' . $connection . ':' . md5($sql . '|' . serialize($bindings));

        $rows = Cache::remember($key, $cacheTtl, function () use ($connection, $sql, $bindings) {
            $db = DB::connection($connection);
            return $db->transaction(function () use ($db, $sql, $bindings) {
                $db->statement("SET LOCAL jit = off");
                $db->statement("SET LOCAL work_mem = '128MB'");
                return $db->select($sql, $bindings);
            });
        });

        return collect($rows);
    }

    private function aggregateSitesMap(string $selectedSite): array
    {
        return $selectedSite === 'ALL'
            ? self::SITES
            : [$selectedSite => self::SITES[$selectedSite]];
    }

    private function runAggregateAcrossSites(array $filters, string $bodySql): Collection
    {
        $rows = collect();
        foreach ($this->aggregateSitesMap($filters['site']) as $site => $connection) {
            [$sql, $bindings] = $this->buildAggregateSql($filters, $site, $bodySql);
            $siteRows = $this->runTunedSelect($connection, $sql, $bindings)
                ->map(function ($row) use ($site) {
                    $row->site = $site;
                    return $row;
                });
            $rows = $rows->concat($siteRows);
        }
        return $rows;
    }

    private function combinedAggregateBody(array $kinds): string
    {
        $unions = [];

        if (in_array('overall', $kinds, true)) {
            $unions[] = <<<'SQL'
SELECT
    'overall'::text AS kind,
    ''::text AS classnumber,
    ''::text AS dept_desc,
    ''::text AS account,
    NULL::int AS month_no,
    ''::text AS month_key,
    COALESCE(SUM(amount), 0) AS total_amount,
    COUNT(*) AS line_count,
    COUNT(DISTINCT source || '-' || source_id::text) AS bill_count,
    COUNT(DISTINCT NULLIF(COALESCE(classnumber, '') || '|' || COALESCE(dept_desc, ''), '|')) AS dept_count,
    COUNT(DISTINCT NULLIF(account, '')) AS account_count
FROM filtered
SQL;
        }

        if (in_array('dept', $kinds, true)) {
            $unions[] = <<<'SQL'
SELECT
    'dept'::text,
    COALESCE(classnumber, ''),
    COALESCE(dept_desc, ''),
    '',
    NULL::int,
    '',
    SUM(amount),
    COUNT(*),
    COUNT(DISTINCT source || '-' || source_id::text),
    0,
    0
FROM filtered
GROUP BY classnumber, dept_desc
SQL;
        }

        if (in_array('acc', $kinds, true)) {
            $unions[] = <<<'SQL'
SELECT
    'acc'::text,
    '',
    '',
    COALESCE(account, ''),
    NULL::int,
    '',
    SUM(amount),
    COUNT(*),
    0,
    0,
    0
FROM filtered
WHERE COALESCE(account, '') <> ''
GROUP BY account
SQL;
        }

        if (in_array('matrix', $kinds, true)) {
            $unions[] = <<<'SQL'
SELECT
    'matrix'::text,
    COALESCE(classnumber, ''),
    COALESCE(dept_desc, ''),
    COALESCE(account, ''),
    NULL::int,
    '',
    SUM(amount),
    0,
    0,
    0,
    0
FROM filtered
WHERE COALESCE(account, '') <> ''
GROUP BY classnumber, dept_desc, account
SQL;
        }

        if (in_array('month', $kinds, true)) {
            $unions[] = <<<'SQL'
SELECT
    'month'::text,
    '',
    '',
    '',
    EXTRACT(MONTH FROM transdate)::int,
    TO_CHAR(transdate, 'YYYY-MM'),
    SUM(amount),
    COUNT(*),
    COUNT(DISTINCT source || '-' || source_id::text),
    0,
    0
FROM filtered
GROUP BY EXTRACT(MONTH FROM transdate)::int, TO_CHAR(transdate, 'YYYY-MM')
SQL;
        }

        return implode("\nUNION ALL\n", $unions);
    }

    private function fetchCombinedAggregates(array $filters, array $kinds = ['overall', 'dept', 'acc', 'matrix', 'month']): array
    {
        $result = [
            'department' => collect(),
            'account' => collect(),
            'matrix' => collect(),
            'monthly' => collect(),
            'overall' => [
                'total_amount' => 0.0,
                'line_count' => 0,
                'bill_count' => 0,
                'dept_count' => 0,
                'account_count' => 0,
            ],
        ];

        $body = $this->combinedAggregateBody($kinds);
        if ($body === '') {
            return $result;
        }

        foreach ($this->aggregateSitesMap($filters['site']) as $site => $connection) {
            [$where, $extraBindings] = $this->aggregateFilterClause($filters, $site);
            $sql = $this->costCenterCteSql()
                . "\n, filtered AS (\n    SELECT * FROM detail\n    WHERE {$where}\n)\n"
                . $body;
            $bindings = array_merge([$filters['date_from'], $filters['date_to']], $extraBindings);

            $rows = $this->runTunedSelect($connection, $sql, $bindings);

            foreach ($rows as $row) {
                $kind = $row->kind ?? '';
                $row->site = $site;
                if ($kind === 'overall') {
                    $result['overall']['total_amount'] += (float) ($row->total_amount ?? 0);
                    $result['overall']['line_count'] += (int) ($row->line_count ?? 0);
                    $result['overall']['bill_count'] += (int) ($row->bill_count ?? 0);
                    $result['overall']['dept_count'] += (int) ($row->dept_count ?? 0);
                    $result['overall']['account_count'] += (int) ($row->account_count ?? 0);
                } elseif ($kind === 'dept') {
                    $result['department']->push($row);
                } elseif ($kind === 'acc') {
                    $result['account']->push($row);
                } elseif ($kind === 'matrix') {
                    $result['matrix']->push($row);
                } elseif ($kind === 'month') {
                    $result['monthly']->push($row);
                }
            }
        }

        return $result;
    }

    public function debugAggregateSql(array $filters, array $kinds = ['overall', 'dept', 'acc', 'matrix', 'month']): array
    {
        $filters = $this->normalizeFilters($filters);
        $body = $this->combinedAggregateBody($kinds);
        $output = [];
        foreach ($this->aggregateSitesMap($filters['site']) as $site => $connection) {
            [$where, $extraBindings] = $this->aggregateFilterClause($filters, $site);
            $sql = $this->costCenterCteSql()
                . "\n, filtered AS (\n    SELECT * FROM detail\n    WHERE {$where}\n)\n"
                . $body;
            $bindings = array_merge([$filters['date_from'], $filters['date_to']], $extraBindings);
            $output[$site] = [
                'connection' => $connection,
                'sql' => $sql,
                'bindings' => $bindings,
            ];
        }
        return $output;
    }

    private function fetchDepartmentTotalsAggregate(array $filters): Collection
    {
        return $this->runAggregateAcrossSites(
            $filters,
            <<<'SQL'
SELECT
    classnumber,
    dept_desc,
    SUM(amount) AS total_amount,
    COUNT(*) AS line_count,
    COUNT(DISTINCT source || '-' || source_id::text) AS bill_count
FROM filtered
GROUP BY classnumber, dept_desc
SQL
        );
    }

    private function fetchAccountTotalsAggregate(array $filters): Collection
    {
        return $this->runAggregateAcrossSites(
            $filters,
            <<<'SQL'
SELECT
    account,
    SUM(amount) AS total_amount,
    COUNT(*) AS line_count
FROM filtered
WHERE COALESCE(account, '') <> ''
GROUP BY account
SQL
        );
    }

    private function fetchMatrixTotalsAggregate(array $filters): Collection
    {
        return $this->runAggregateAcrossSites(
            $filters,
            <<<'SQL'
SELECT
    classnumber,
    dept_desc,
    account,
    SUM(amount) AS total_amount
FROM filtered
WHERE COALESCE(account, '') <> ''
GROUP BY classnumber, dept_desc, account
SQL
        );
    }

    private function fetchOverallTotalsAggregate(array $filters): array
    {
        $rows = $this->runAggregateAcrossSites(
            $filters,
            <<<'SQL'
SELECT
    SUM(amount) AS total_amount,
    COUNT(*) AS line_count,
    COUNT(DISTINCT source || '-' || source_id::text) AS bill_count
FROM filtered
SQL
        );

        return [
            'total_amount' => (float) $rows->sum(fn($r) => (float) ($r->total_amount ?? 0)),
            'line_count' => (int) $rows->sum(fn($r) => (int) ($r->line_count ?? 0)),
            'bill_count' => (int) $rows->sum(fn($r) => (int) ($r->bill_count ?? 0)),
        ];
    }

    private function fetchMonthlySummaryAggregate(array $filters): Collection
    {
        return $this->runAggregateAcrossSites(
            $filters,
            <<<'SQL'
SELECT
    EXTRACT(MONTH FROM transdate)::int AS month_no,
    TO_CHAR(transdate, 'YYYY-MM') AS month_key,
    SUM(amount) AS total_amount,
    COUNT(*) AS line_count,
    COUNT(DISTINCT source || '-' || source_id::text) AS bill_count
FROM filtered
GROUP BY EXTRACT(MONTH FROM transdate)::int, TO_CHAR(transdate, 'YYYY-MM')
SQL
        );
    }

    private function fetchYearlyAggregate(array $filters): Collection
    {
        return $this->runAggregateAcrossSites(
            $filters,
            <<<'SQL'
SELECT
    classnumber,
    dept_desc,
    account,
    EXTRACT(MONTH FROM transdate)::int AS month_no,
    SUM(amount) AS total_amount,
    COUNT(*) AS line_count,
    COUNT(DISTINCT source || '-' || source_id::text) AS bill_count
FROM filtered
WHERE COALESCE(account, '') <> ''
GROUP BY classnumber, dept_desc, account, EXTRACT(MONTH FROM transdate)::int
SQL
        );
    }

    private function fetchYearlyTwoYearsAggregate(array $filters): Collection
    {
        return $this->runAggregateAcrossSites(
            $filters,
            <<<'SQL'
SELECT
    classnumber,
    dept_desc,
    account,
    EXTRACT(YEAR FROM transdate)::int AS year_no,
    EXTRACT(MONTH FROM transdate)::int AS month_no,
    SUM(amount) AS total_amount,
    COUNT(*) AS line_count,
    COUNT(DISTINCT source || '-' || source_id::text) AS bill_count
FROM filtered
WHERE COALESCE(account, '') <> ''
GROUP BY classnumber, dept_desc, account, EXTRACT(YEAR FROM transdate)::int, EXTRACT(MONTH FROM transdate)::int
SQL
        );
    }

    private function fetchMonthlyTwoYearsAggregate(array $filters): Collection
    {
        $rows = $this->runAggregateAcrossSites(
            $filters,
            <<<'SQL'
SELECT
    classnumber,
    dept_desc,
    EXTRACT(YEAR FROM transdate)::int AS year_no,
    EXTRACT(MONTH FROM transdate)::int AS month_no,
    SUM(amount) AS total_amount,
    COUNT(*) AS line_count,
    COUNT(DISTINCT source || '-' || source_id::text) AS bill_count,
    COUNT(DISTINCT account) AS account_count
FROM filtered
GROUP BY classnumber, dept_desc, EXTRACT(YEAR FROM transdate)::int, EXTRACT(MONTH FROM transdate)::int
SQL
        );

        return $rows->map(function ($row) {
            $site = (string) ($row->site ?? '');
            $row->department_code = $this->cleanText($row->classnumber ?? '');
            $row->department = $this->cleanText($row->dept_desc ?? '') ?: self::UNASSIGNED_DEPARTMENT;
            [$resolvedCode, $resolvedName] = $this->resolveDepartment($site, $row->department_code, $row->department);
            if ($resolvedCode !== '') {
                $row->department_code = $resolvedCode;
                $row->department = $resolvedName;
            }
            $row->year_no = (int) $row->year_no;
            $row->month_no = (int) $row->month_no;
            $row->total_amount = (float) $row->total_amount;
            $row->line_count = (int) $row->line_count;
            $row->bill_count = (int) $row->bill_count;
            $row->account_count = (int) $row->account_count;
            return $row;
        })->values();
    }

    private function normalizeYearlyRows(Collection $rawRows, string $selectedSite): Collection
    {
        return $rawRows->map(function ($row) use ($selectedSite) {
            $site = (string) ($row->site ?? '');
            $rawCode = $this->cleanText($row->classnumber ?? '');
            $rawName = $this->cleanText($row->dept_desc ?? '') ?: self::UNASSIGNED_DEPARTMENT;
            [$resolvedCode, $resolvedName] = $this->resolveDepartment($site, $rawCode, $rawName);
            if ($resolvedCode === '') {
                $resolvedCode = $rawCode;
                $resolvedName = $rawName;
            }
            $displayName = $this->departmentOptionLabel($selectedSite, $site, $resolvedName);
            if ($displayName === '') {
                $displayName = self::UNASSIGNED_DEPARTMENT;
            }
            $parts = $this->splitAccountLabel($row->account ?? '');

            return (object) [
                'department_code' => $resolvedCode,
                'department' => $displayName,
                'account_code' => $parts['code'],
                'account_name' => $parts['name'],
                'month_no' => (int) $row->month_no,
                'total_amount' => (float) $row->total_amount,
                'line_count' => (int) ($row->line_count ?? 0),
                'bill_count' => (int) ($row->bill_count ?? 0),
            ];
        })->values();
    }

    private function fetchAccountOptionsAggregate(array $filters): Collection
    {
        $rows = $this->runAggregateAcrossSites(
            $filters,
            <<<'SQL'
SELECT DISTINCT account
FROM filtered
WHERE COALESCE(account, '') <> ''
ORDER BY account
SQL
        );

        return $rows
            ->map(fn($r) => $this->cleanText($r->account ?? ''))
            ->pipe(fn(Collection $rows) => $this->filterAccountOptionLabels($rows));
    }

    private function postProcessDepartmentTotals(Collection $rawRows, string $selectedSite): Collection
    {
        return $rawRows
            ->map(function ($row) use ($selectedSite) {
                $site = (string) ($row->site ?? '');
                $rawCode = $this->cleanText($row->classnumber ?? '');
                $rawName = $this->cleanText($row->dept_desc ?? '') ?: self::UNASSIGNED_DEPARTMENT;
                [$resolvedCode, $resolvedName] = $this->resolveDepartment($site, $rawCode, $rawName);
                if ($resolvedCode === '') {
                    $resolvedCode = $rawCode;
                    $resolvedName = $rawName;
                }
                $displayName = $this->departmentOptionLabel($selectedSite, $site, $resolvedName);
                if ($displayName === '') {
                    $displayName = self::UNASSIGNED_DEPARTMENT;
                }

                return (object) [
                    'department_code' => $resolvedCode,
                    'department' => $displayName,
                    'total_amount' => (float) $row->total_amount,
                    'line_count' => (int) $row->line_count,
                    'bill_count' => (int) $row->bill_count,
                ];
            })
            ->groupBy(fn($r) => ($r->department_code ?: '') . '|' . $r->department)
            ->map(function (Collection $group) {
                $first = $group->first();
                $total = (float) $group->sum('total_amount');
                $lines = (int) $group->sum('line_count');
                $bills = (int) $group->sum('bill_count');

                return (object) [
                    'department_code' => $first->department_code,
                    'department' => $first->department,
                    'bill_count' => $bills,
                    'line_count' => $lines,
                    'total_amount' => $total,
                    'avg_amount' => $lines > 0 ? $total / $lines : 0,
                ];
            })
            ->sortByDesc('total_amount')
            ->values();
    }

    private function postProcessAccountTotals(Collection $rawRows): Collection
    {
        return $rawRows
            ->map(function ($row) {
                $parts = $this->splitAccountLabel($row->account ?? '');
                return (object) [
                    'account_code' => $parts['code'],
                    'account_name' => $parts['name'],
                    'total_amount' => (float) $row->total_amount,
                    'line_count' => (int) $row->line_count,
                ];
            })
            ->filter(fn($row) => $this->isAccountOptionCode($row->account_code))
            ->groupBy(fn($r) => $r->account_code . '|' . $r->account_name)
            ->map(function (Collection $group) {
                $first = $group->first();
                return (object) [
                    'account_code' => $first->account_code,
                    'account_name' => $first->account_name,
                    'line_count' => (int) $group->sum('line_count'),
                    'total_amount' => (float) $group->sum('total_amount'),
                ];
            })
            ->sortBy([
                ['account_code', 'asc'],
                ['account_name', 'asc'],
            ])
            ->values();
    }

    private function postProcessMatrix(Collection $rawRows, string $selectedSite): array
    {
        $normalized = $rawRows->map(function ($row) use ($selectedSite) {
            $site = (string) ($row->site ?? '');
            $rawCode = $this->cleanText($row->classnumber ?? '');
            $rawName = $this->cleanText($row->dept_desc ?? '') ?: self::UNASSIGNED_DEPARTMENT;
            [$resolvedCode, $resolvedName] = $this->resolveDepartment($site, $rawCode, $rawName);
            if ($resolvedCode === '') {
                $resolvedCode = $rawCode;
                $resolvedName = $rawName;
            }
            $displayName = $this->departmentOptionLabel($selectedSite, $site, $resolvedName);
            if ($displayName === '') {
                $displayName = self::UNASSIGNED_DEPARTMENT;
            }

            $parts = $this->splitAccountLabel($row->account ?? '');

            return (object) [
                'department_code' => $resolvedCode,
                'department' => $displayName,
                'account_code' => $parts['code'],
                'account_name' => $parts['name'],
                'account_key' => $parts['code'] . '|' . $parts['name'],
                'total_amount' => (float) $row->total_amount,
            ];
        })->filter(fn($row) => $this->isAccountOptionCode($row->account_code))->values();

        $accounts = $normalized
            ->groupBy('account_key')
            ->map(function (Collection $group, string $key) {
                $first = $group->first();
                return (object) [
                    'key' => $key,
                    'code' => $first->account_code,
                    'name' => $first->account_name,
                    'total_amount' => (float) $group->sum('total_amount'),
                ];
            })
            ->sortBy('code')
            ->values();

        $matrixRows = $normalized
            ->groupBy(fn($r) => ($r->department_code ?: '') . '|' . $r->department)
            ->map(function (Collection $group) {
                $first = $group->first();
                $amounts = $group
                    ->groupBy('account_key')
                    ->map(fn(Collection $g) => (float) $g->sum('total_amount'))
                    ->all();
                return (object) [
                    'department_code' => $first->department_code,
                    'department' => $first->department,
                    'amounts' => $amounts,
                    'total_amount' => (float) $group->sum('total_amount'),
                ];
            })
            ->sortBy('department_code')
            ->values();

        $columnTotals = $accounts
            ->mapWithKeys(fn($a) => [$a->key => (float) $a->total_amount])
            ->all();

        return [
            'accounts' => $accounts,
            'rows' => $matrixRows,
            'columnTotals' => $columnTotals,
            'grandTotal' => (float) $normalized->sum('total_amount'),
        ];
    }

    private function postProcessMonthlySummary(Collection $rawRows): Collection
    {
        return $rawRows
            ->groupBy('month_key')
            ->map(function (Collection $group, string $month) {
                $first = $group->first();
                return (object) [
                    'month' => $month,
                    'month_no' => (int) $first->month_no,
                    'bill_count' => (int) $group->sum('bill_count'),
                    'line_count' => (int) $group->sum('line_count'),
                    'total_amount' => (float) $group->sum('total_amount'),
                ];
            })
            ->sortBy('month')
            ->values();
    }

    private function buildDepartmentOptionsFromMaster(string $selectedSite): Collection
    {
        $classInfo = $this->getClassInfoBySite();
        $sites = $selectedSite === 'ALL' ? array_keys(self::SITES) : [$selectedSite];

        $options = collect();
        foreach ($sites as $site) {
            foreach (($classInfo[$site] ?? []) as $description) {
                $label = $this->stripSiteSuffix($this->cleanText($description));
                if ($label !== '') {
                    $options->push($label);
                }
            }
        }

        return $options->unique()->sort()->values();
    }

    private function fetchCashoutForConnection(string $connection, string $site, array $filters): Collection
    {
        $db = DB::connection($connection);
        $deptNameExpr = "
            COALESCE(
                NULLIF(TRIM(cls.description), ''),
                NULLIF(TRIM(empcls.description), ''),
                '" . self::UNASSIGNED_DEPARTMENT . "'
            )
        ";
        $deptCodeExpr = "
            COALESCE(
                NULLIF(TRIM(cls.classnumber), ''),
                NULLIF(TRIM(empcls.classnumber), ''),
                ''
            )
        ";

        $query = $db->table('cashout as co')
            ->join('acc_trans as at', 'at.trans_id', '=', 'co.id')
            ->leftJoin('ccalloc as ca', 'ca.acc_trans_id', '=', 'at.id')
            ->join('chart as c', function ($join) {
                $join->on('c.id', '=', DB::raw('COALESCE(ca.chart_id, at.chart_id)'));
            })
            ->leftJoin('classinfo as cls', function ($join) {
                $join->on('cls.id', '=', DB::raw('COALESCE(ca.class_id, at.class_id)'));
            })
            ->leftJoin('employee as e_emp', 'e_emp.id', '=', 'co.employee_id')
            ->leftJoin('classinfo as empcls', 'empcls.id', '=', 'e_emp.class_id')
            ->whereBetween('co.transdate', [$filters['date_from'], $filters['date_to']])
            ->select([
                'co.id as ap_id',
                'co.transdate',
                'co.vouchernumber as apnumber',
                DB::raw("co.vouchernumber as invnumber"),
                DB::raw("''::text as ordnumber"),
                'co.amount as bill_amount',
                'co.notes',
                DB::raw("NULL::text as f1"),
                DB::raw("NULL::text as f2"),
                DB::raw("NULL::text as f3"),
                DB::raw("NULL::text as f4"),
                DB::raw("NULL::text as f5"),
                'co.curr as currency',
                DB::raw("NULL::int as vendor_id"),
                'c.accno as account_code',
                'c.description as account_name',
                DB::raw('COALESCE(ca.chart_id, at.chart_id) as account_chart_id'),
                'cls.id as acc_class_id',
                'cls.classnumber as acc_classnumber',
                'cls.description as acc_class_description',
                DB::raw("NULL::text as part_descriptions"),
                DB::raw("co.description as invoice_descriptions"),
                DB::raw("NULL::numeric as item_qty"),
                DB::raw("NULL::numeric as item_allocated"),
                DB::raw("NULL::numeric as min_unit_price"),
                DB::raw("NULL::numeric as max_unit_price"),
                DB::raw("0 as item_line_count"),
                'cls.id as invoice_class_id',
                'cls.classnumber as invoice_classnumber',
                'cls.description as invoice_class_description',
                'e_emp.name as employee_name',
                DB::raw("co.name as requester_name"),
            ])
            ->selectRaw("{$deptNameExpr} as department")
            ->selectRaw("{$deptCodeExpr} as department_code")
            ->selectRaw('ABS(COALESCE(ca.amount, at.amount)) as amount')
            ->selectRaw('0 as balance');

        $this->applyDepartmentAndDivisionGroupFilters($query, $filters, $site, $deptCodeExpr, $deptNameExpr);
        $this->applyAccountTextFilter($query, $filters);
        if ($filters['invoice'] !== '') {
            $query->where('co.vouchernumber', 'ILIKE', '%' . $filters['invoice'] . '%');
        }
        if ($filters['notes'] !== '') {
            $query->where('co.notes', 'ILIKE', '%' . $filters['notes'] . '%');
        }

        return collect($query->orderBy('co.transdate')->orderBy('co.vouchernumber')->orderBy('c.accno')->get())
            ->map(function ($row) use ($site) {
                $row->site = $site;
                $row->source = 'CO';
                $row->site_ap_id = $site . '-CO-' . $row->ap_id;
                $row->department = $this->cleanText($row->department) ?: self::UNASSIGNED_DEPARTMENT;
                $row->department_code = $this->cleanText($row->department_code);
                $row->invoice_classnumber = $this->cleanText($row->invoice_classnumber);
                $row->invoice_class_description = $this->cleanText($row->invoice_class_description);
                $row->acc_classnumber = $this->cleanText($row->acc_classnumber);
                $row->acc_class_description = $this->cleanText($row->acc_class_description);
                $row->account_chart_id = is_null($row->account_chart_id) ? null : (int) $row->account_chart_id;

                [$resolvedCode, $resolvedName] = $this->resolveDepartment(
                    $site,
                    $row->invoice_classnumber,
                    $row->invoice_class_description
                );
                if ($resolvedCode !== '') {
                    $row->department_code = $resolvedCode;
                    $row->department = $resolvedName;
                    $row->invoice_class_description = $resolvedName;
                }

                $row->class_match_status = 'cashout';
                $row->amount = (float) $row->amount;
                $row->bill_amount = (float) $row->bill_amount;
                $row->balance = 0.0;
                $row->part_descriptions = '';
                $row->invoice_descriptions = $this->cleanText($row->invoice_descriptions);
                $row->item_qty = null;
                $row->item_allocated = null;
                $row->min_unit_price = null;
                $row->max_unit_price = null;
                $row->item_line_count = 0;
                $row->unit_price_display = '';
                $row->items = [];

                return $row;
            });
    }

    private function fetchGlForConnection(string $connection, string $site, array $filters): Collection
    {
        $db = DB::connection($connection);
        $deptNameExpr = "
            COALESCE(
                NULLIF(TRIM(cls.description), ''),
                NULLIF(TRIM(empcls.description), ''),
                '" . self::UNASSIGNED_DEPARTMENT . "'
            )
        ";
        $deptCodeExpr = "
            COALESCE(
                NULLIF(TRIM(cls.classnumber), ''),
                NULLIF(TRIM(empcls.classnumber), ''),
                ''
            )
        ";

        $query = $db->table('gl')
            ->join('acc_trans as at', 'at.trans_id', '=', 'gl.id')
            ->join('chart as c', 'c.id', '=', 'at.chart_id')
            ->leftJoin('classinfo as cls', 'cls.id', '=', 'at.class_id')
            ->leftJoin('employee as e_emp', 'e_emp.id', '=', 'gl.employee_id')
            ->leftJoin('classinfo as empcls', 'empcls.id', '=', 'e_emp.class_id')
            ->whereBetween('gl.transdate', [$filters['date_from'], $filters['date_to']])
            ->select([
                'gl.id as ap_id',
                'gl.transdate',
                'gl.transnumber as apnumber',
                DB::raw("gl.transnumber as invnumber"),
                DB::raw("COALESCE(gl.reference, '') as ordnumber"),
                DB::raw('0 as bill_amount'),
                'gl.notes',
                DB::raw("NULL::text as f1"),
                DB::raw("NULL::text as f2"),
                DB::raw("NULL::text as f3"),
                DB::raw("NULL::text as f4"),
                DB::raw("NULL::text as f5"),
                'gl.curr as currency',
                DB::raw("NULL::int as vendor_id"),
                'c.accno as account_code',
                'c.description as account_name',
                'cls.id as acc_class_id',
                'cls.classnumber as acc_classnumber',
                'cls.description as acc_class_description',
                DB::raw("NULL::text as part_descriptions"),
                DB::raw("gl.description as invoice_descriptions"),
                DB::raw("NULL::numeric as item_qty"),
                DB::raw("NULL::numeric as item_allocated"),
                DB::raw("NULL::numeric as min_unit_price"),
                DB::raw("NULL::numeric as max_unit_price"),
                DB::raw("0 as item_line_count"),
                'cls.id as invoice_class_id',
                'cls.classnumber as invoice_classnumber',
                'cls.description as invoice_class_description',
                'e_emp.name as employee_name',
                DB::raw("NULL::text as requester_name"),
            ])
            ->selectRaw("{$deptNameExpr} as department")
            ->selectRaw("{$deptCodeExpr} as department_code")
            ->selectRaw('ABS(at.amount) as amount')
            ->selectRaw('0 as balance');

        $this->applyDepartmentAndDivisionGroupFilters($query, $filters, $site, $deptCodeExpr, $deptNameExpr);
        $this->applyAccountTextFilter($query, $filters);
        if ($filters['invoice'] !== '') {
            $query->where(function ($q) use ($filters) {
                $q->where('gl.transnumber', 'ILIKE', '%' . $filters['invoice'] . '%')
                    ->orWhere('gl.reference', 'ILIKE', '%' . $filters['invoice'] . '%');
            });
        }
        if ($filters['notes'] !== '') {
            $query->where(function ($q) use ($filters) {
                $q->where('gl.notes', 'ILIKE', '%' . $filters['notes'] . '%')
                    ->orWhere('gl.description', 'ILIKE', '%' . $filters['notes'] . '%');
            });
        }

        return collect($query->orderBy('gl.transdate')->orderBy('gl.transnumber')->orderBy('c.accno')->get())
            ->map(function ($row) use ($site) {
                $row->site = $site;
                $row->source = 'GL';
                $row->site_ap_id = $site . '-GL-' . $row->ap_id;
                $row->department = $this->cleanText($row->department) ?: self::UNASSIGNED_DEPARTMENT;
                $row->department_code = $this->cleanText($row->department_code);
                $row->invoice_classnumber = $this->cleanText($row->invoice_classnumber);
                $row->invoice_class_description = $this->cleanText($row->invoice_class_description);
                $row->acc_classnumber = $this->cleanText($row->acc_classnumber);
                $row->acc_class_description = $this->cleanText($row->acc_class_description);

                [$resolvedCode, $resolvedName] = $this->resolveDepartment(
                    $site,
                    $row->invoice_classnumber,
                    $row->invoice_class_description
                );
                if ($resolvedCode !== '') {
                    $row->department_code = $resolvedCode;
                    $row->department = $resolvedName;
                    $row->invoice_class_description = $resolvedName;
                }

                $row->class_match_status = 'gl';
                $row->amount = (float) $row->amount;
                $row->bill_amount = (float) $row->bill_amount;
                $row->balance = 0.0;
                $row->part_descriptions = '';
                $row->invoice_descriptions = $this->cleanText($row->invoice_descriptions);
                $row->item_qty = null;
                $row->item_allocated = null;
                $row->min_unit_price = null;
                $row->max_unit_price = null;
                $row->item_line_count = 0;
                $row->unit_price_display = '';
                $row->items = [];

                return $row;
            });
    }

    private function fetchRowsForConnection(string $connection, string $site, array $filters): Collection
    {
        $db = DB::connection($connection);
        $departmentNameExpr = "
            COALESCE(
                NULLIF(TRIM(cls.description), ''),
                NULLIF(TRIM(addcost_cls.description), ''),
                NULLIF(TRIM(item_cls.description), ''),
                NULLIF(TRIM(inv.invoice_class_description), ''),
                NULLIF(TRIM(emp_cls.description), ''),
                NULLIF(TRIM(req_cls.description), ''),
                '" . self::UNASSIGNED_DEPARTMENT . "'
            )
        ";
        $departmentCodeExpr = "
            COALESCE(
                NULLIF(TRIM(cls.classnumber), ''),
                NULLIF(TRIM(addcost_cls.classnumber), ''),
                NULLIF(TRIM(item_cls.classnumber), ''),
                NULLIF(TRIM(inv.invoice_classnumber), ''),
                NULLIF(TRIM(emp_cls.classnumber), ''),
                NULLIF(TRIM(req_cls.classnumber), ''),
                ''
            )
        ";

        $invoiceSub = $db->table('invoice as i')
            ->leftJoin('parts as p', 'p.id', '=', 'i.parts_id')
            ->leftJoin('classinfo as cls_inv', 'cls_inv.id', '=', 'i.class_id')
            ->groupBy('i.trans_id')
            ->selectRaw("
                i.trans_id,
                string_agg(
                    DISTINCT COALESCE(NULLIF(TRIM(p.description), ''), '')
                    , ', ' ORDER BY COALESCE(NULLIF(TRIM(p.description), ''), '')
                ) as part_descriptions,
                string_agg(
                    DISTINCT COALESCE(NULLIF(TRIM(i.description), ''), '')
                    , ', ' ORDER BY COALESCE(NULLIF(TRIM(i.description), ''), '')
                ) as invoice_descriptions,
                SUM(COALESCE(i.qty, 0)) as item_qty,
                SUM(COALESCE(i.allocated, 0)) as item_allocated,
                MIN(i.sellprice) as min_unit_price,
                MAX(i.sellprice) as max_unit_price,
                COUNT(*) as item_line_count,
                MIN(cls_inv.id) as invoice_class_id,
                (array_agg(NULLIF(TRIM(cls_inv.classnumber), '') ORDER BY cls_inv.id))[1] as invoice_classnumber,
                (array_agg(NULLIF(TRIM(cls_inv.description), '') ORDER BY cls_inv.id))[1] as invoice_class_description
            ");

        $addcostClassSub = $db->table('addcost')
            ->whereRaw('COALESCE(class_id, -1) <> -1')
            ->groupBy('trans_id', 'chart_id')
            ->selectRaw('trans_id, chart_id, MIN(class_id) as class_id');

        $itemClassSub = $db->table('invoice as i')
            ->leftJoin('receiveitems as ri', 'ri.invoice_id', '=', 'i.id')
            ->leftJoin('orderitems as oi', 'oi.id', '=', 'ri.orderitems_id')
            ->leftJoin('partsmvmt as pm', 'pm.invoice_id', '=', 'i.id')
            ->whereRaw('COALESCE(NULLIF(pm.class_id, -1), NULLIF(oi.class_id, -1), NULLIF(i.class_id, -1)) IS NOT NULL')
            ->groupBy('i.trans_id')
            ->selectRaw('
                i.trans_id,
                MIN(COALESCE(NULLIF(pm.class_id, -1), NULLIF(oi.class_id, -1), NULLIF(i.class_id, -1))) as class_id
            ');

        $query = $db->table('ap')
            ->join('acc_trans as at', 'at.trans_id', '=', 'ap.id')
            ->leftJoin('ccalloc as ca', 'ca.acc_trans_id', '=', 'at.id')
            ->join('chart as c', function ($join) {
                $join->on('c.id', '=', DB::raw('COALESCE(ca.chart_id, at.chart_id)'));
            })
            ->leftJoin('classinfo as cls', function ($join) {
                $join->on('cls.id', '=', DB::raw('COALESCE(ca.class_id, at.class_id)'));
            })
            ->leftJoinSub($invoiceSub, 'inv', 'inv.trans_id', '=', 'ap.id')
            ->leftJoin('employee as e_emp', 'e_emp.id', '=', 'ap.employee_id')
            ->leftJoin('employee as e_req', 'e_req.id', '=', 'ap.requester_id')
            ->leftJoinSub($addcostClassSub, 'addcost_src', function ($join) {
                $join->on('addcost_src.trans_id', '=', 'ap.id')
                    ->on('addcost_src.chart_id', '=', DB::raw('COALESCE(ca.chart_id, at.chart_id)'));
            })
            ->leftJoin('classinfo as addcost_cls', 'addcost_cls.id', '=', 'addcost_src.class_id')
            ->leftJoinSub($itemClassSub, 'item_src', 'item_src.trans_id', '=', 'ap.id')
            ->leftJoin('classinfo as item_cls', 'item_cls.id', '=', 'item_src.class_id')
            ->leftJoin('classinfo as emp_cls', 'emp_cls.id', '=', 'e_emp.class_id')
            ->leftJoin('classinfo as req_cls', 'req_cls.id', '=', 'e_req.class_id')
            ->whereBetween('ap.transdate', [$filters['date_from'], $filters['date_to']])
            ->select([
                'ap.id as ap_id',
                'ap.transdate',
                'ap.apnumber',
                'ap.invnumber',
                'ap.ordnumber',
                'ap.amount as bill_amount',
                'ap.paid',
                'ap.notes',
                'ap.f1',
                'ap.f2',
                'ap.f3',
                'ap.f4',
                'ap.f5',
                'ap.curr as currency',
                'ap.vendor_id',
                'c.accno as account_code',
                'c.description as account_name',
                DB::raw('COALESCE(ca.chart_id, at.chart_id) as account_chart_id'),
                'cls.id as acc_class_id',
                'cls.classnumber as acc_classnumber',
                'cls.description as acc_class_description',
                'inv.part_descriptions',
                'inv.invoice_descriptions',
                'inv.item_qty',
                'inv.item_allocated',
                'inv.min_unit_price',
                'inv.max_unit_price',
                'inv.item_line_count',
                'inv.invoice_class_id',
                'inv.invoice_classnumber',
                'inv.invoice_class_description',
                'e_emp.name as employee_name',
                'e_req.name as requester_name',
            ])
            ->selectRaw("{$departmentNameExpr} as department")
            ->selectRaw("{$departmentCodeExpr} as department_code")
            ->selectRaw('ABS(COALESCE(ca.amount, at.amount)) as amount')
            ->selectRaw('(ap.amount - ap.paid) as balance');

        $this->applyDepartmentAndDivisionGroupFilters($query, $filters, $site, $departmentCodeExpr, $departmentNameExpr);
        $this->applyAccountTextFilter($query, $filters);

        if ($filters['invoice'] !== '') {
            $query->where(function ($q) use ($filters) {
                $q->where('ap.invnumber', 'ILIKE', '%' . $filters['invoice'] . '%')
                    ->orWhere('ap.ordnumber', 'ILIKE', '%' . $filters['invoice'] . '%');
            });
        }

        if ($filters['notes'] !== '') {
            $query->where('ap.notes', 'ILIKE', '%' . $filters['notes'] . '%');
        }

        $rows = collect($query->orderBy('ap.transdate')->orderBy('ap.invnumber')->orderBy('c.accno')->get())
            ->map(function ($row) use ($site) {
                $row->site = $site;
                $row->source = 'AP';
                $row->site_ap_id = $site . '-' . $row->ap_id;
                $row->department = $this->cleanText($row->department) ?: self::UNASSIGNED_DEPARTMENT;
                $row->department_code = $this->cleanText($row->department_code);
                $row->invoice_classnumber = $this->cleanText($row->invoice_classnumber);
                $row->invoice_class_description = $this->cleanText($row->invoice_class_description);
                $row->acc_classnumber = $this->cleanText($row->acc_classnumber);
                $row->acc_class_description = $this->cleanText($row->acc_class_description);
                $row->account_chart_id = is_null($row->account_chart_id) ? null : (int) $row->account_chart_id;

                [$resolvedCode, $resolvedName] = $this->resolveDepartment(
                    $site,
                    $row->invoice_classnumber,
                    $row->invoice_class_description
                );
                if ($resolvedCode !== '') {
                    $row->department_code = $resolvedCode;
                    $row->department = $resolvedName;
                    $row->invoice_class_description = $resolvedName;
                }

                $row->class_match_status = $this->classMatchStatus($row->acc_class_id, $row->invoice_class_id);
                $row->amount = (float) $row->amount;
                $row->bill_amount = (float) $row->bill_amount;
                $row->balance = (float) $row->balance;
                $row->part_descriptions = $this->cleanText($row->part_descriptions);
                $row->invoice_descriptions = $this->cleanText($row->invoice_descriptions);
                $row->item_qty = is_null($row->item_qty) ? null : (float) $row->item_qty;
                $row->item_allocated = is_null($row->item_allocated) ? null : (float) $row->item_allocated;
                $row->min_unit_price = is_null($row->min_unit_price) ? null : (float) $row->min_unit_price;
                $row->max_unit_price = is_null($row->max_unit_price) ? null : (float) $row->max_unit_price;
                $row->item_line_count = (int) ($row->item_line_count ?? 0);
                $row->unit_price_display = $this->unitPriceDisplay($row->min_unit_price, $row->max_unit_price);
                $row->items = [];

                return $row;
            });

        $this->attachInvoiceItems($db, $rows);

        return $rows;
    }

    private function fetchApInvoiceClassFallbackForConnection(string $connection, string $site, array $filters): Collection
    {
        $db = DB::connection($connection);
        $deptNameExpr = "COALESCE(NULLIF(TRIM(cls_inv.description), ''), '" . self::UNASSIGNED_DEPARTMENT . "')";
        $deptCodeExpr = "COALESCE(NULLIF(TRIM(cls_inv.classnumber), ''), '')";

        $query = $db->table('ap')
            ->join('invoice as i', 'i.trans_id', '=', 'ap.id')
            ->join('chart as c', 'c.id', '=', 'i.chart_id')
            ->join('classinfo as cls_inv', 'cls_inv.id', '=', 'i.class_id')
            ->leftJoin('employee as e_emp', 'e_emp.id', '=', 'ap.employee_id')
            ->leftJoin('employee as e_req', 'e_req.id', '=', 'ap.requester_id')
            ->whereBetween('ap.transdate', [$filters['date_from'], $filters['date_to']])
            ->where('c.category', 'E')
            ->whereRaw('ABS(COALESCE(i.qty, 0)) > 0')
            ->whereRaw('ABS(COALESCE(i.sellprice, i.unitcost, 0)) > 0')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('acc_trans as at2')
                    ->leftJoin('ccalloc as ca2', 'ca2.acc_trans_id', '=', 'at2.id')
                    ->whereColumn('at2.trans_id', 'ap.id')
                    ->whereColumn(DB::raw('COALESCE(ca2.chart_id, at2.chart_id)'), 'i.chart_id')
                    ->whereColumn(DB::raw('COALESCE(NULLIF(ca2.class_id, -1), NULLIF(at2.class_id, -1))'), 'i.class_id');
            })
            ->select([
                'ap.id as ap_id',
                'ap.transdate',
                'ap.apnumber',
                'ap.invnumber',
                'ap.ordnumber',
                'ap.amount as bill_amount',
                'ap.paid',
                'ap.notes',
                'ap.f1',
                'ap.f2',
                'ap.f3',
                'ap.f4',
                'ap.f5',
                'ap.curr as currency',
                'ap.vendor_id',
                'c.accno as account_code',
                'c.description as account_name',
                'i.chart_id as account_chart_id',
                'cls_inv.id as acc_class_id',
                'cls_inv.classnumber as acc_classnumber',
                'cls_inv.description as acc_class_description',
                DB::raw("''::text as part_descriptions"),
                'i.description as invoice_descriptions',
                DB::raw('ABS(COALESCE(i.qty, 0)) as item_qty'),
                DB::raw("NULL::numeric as item_allocated"),
                DB::raw('ABS(COALESCE(i.sellprice, i.unitcost, 0)) as min_unit_price'),
                DB::raw('ABS(COALESCE(i.sellprice, i.unitcost, 0)) as max_unit_price'),
                DB::raw("1 as item_line_count"),
                'cls_inv.id as invoice_class_id',
                'cls_inv.classnumber as invoice_classnumber',
                'cls_inv.description as invoice_class_description',
                'e_emp.name as employee_name',
                'e_req.name as requester_name',
            ])
            ->selectRaw("{$deptNameExpr} as department")
            ->selectRaw("{$deptCodeExpr} as department_code")
            ->selectRaw('ABS(COALESCE(i.qty, 0)) * ABS(COALESCE(i.sellprice, i.unitcost, 0)) as amount')
            ->selectRaw('(ap.amount - ap.paid) as balance');

        $this->applyDepartmentAndDivisionGroupFilters($query, $filters, $site, $deptCodeExpr, $deptNameExpr);
        $this->applyAccountTextFilter($query, $filters);
        if ($filters['invoice'] !== '') {
            $query->where(function ($q) use ($filters) {
                $q->where('ap.invnumber', 'ILIKE', '%' . $filters['invoice'] . '%')
                    ->orWhere('ap.ordnumber', 'ILIKE', '%' . $filters['invoice'] . '%');
            });
        }
        if ($filters['notes'] !== '') {
            $query->where('ap.notes', 'ILIKE', '%' . $filters['notes'] . '%');
        }

        return collect($query->orderBy('ap.transdate')->orderBy('ap.invnumber')->orderBy('c.accno')->orderBy('i.id')->get())
            ->map(function ($row) use ($site) {
                $row->site = $site;
                $row->source = 'AP_INVOICE_CLASS';
                $row->site_ap_id = $site . '-' . $row->ap_id;
                $row->department = $this->cleanText($row->department) ?: self::UNASSIGNED_DEPARTMENT;
                $row->department_code = $this->cleanText($row->department_code);
                $row->invoice_classnumber = $this->cleanText($row->invoice_classnumber);
                $row->invoice_class_description = $this->cleanText($row->invoice_class_description);
                $row->acc_classnumber = $this->cleanText($row->acc_classnumber);
                $row->acc_class_description = $this->cleanText($row->acc_class_description);
                $row->account_chart_id = is_null($row->account_chart_id) ? null : (int) $row->account_chart_id;

                [$resolvedCode, $resolvedName] = $this->resolveDepartment(
                    $site,
                    $row->invoice_classnumber,
                    $row->invoice_class_description
                );
                if ($resolvedCode !== '') {
                    $row->department_code = $resolvedCode;
                    $row->department = $resolvedName;
                    $row->invoice_class_description = $resolvedName;
                }

                $row->class_match_status = 'invoice class fallback';
                $row->amount = (float) $row->amount;
                $row->bill_amount = (float) $row->bill_amount;
                $row->balance = (float) $row->balance;
                $row->part_descriptions = $this->cleanText($row->part_descriptions);
                $row->invoice_descriptions = $this->cleanText($row->invoice_descriptions);
                $row->item_qty = is_null($row->item_qty) ? null : $this->displayQuantity($row->item_qty);
                $row->item_allocated = null;
                $row->min_unit_price = is_null($row->min_unit_price) ? null : abs((float) $row->min_unit_price);
                $row->max_unit_price = is_null($row->max_unit_price) ? null : abs((float) $row->max_unit_price);
                $row->item_line_count = 1;
                $row->unit_price_display = $this->unitPriceDisplay($row->min_unit_price, $row->max_unit_price);
                $row->items = [];

                return $row;
            });
    }

    private function attachInvoiceItems(\Illuminate\Database\Connection $db, Collection $rows): void
    {
        $apRows = $rows->filter(fn($row) => ($row->source ?? 'AP') === 'AP');
        $transIds = $apRows->pluck('ap_id')->filter()->unique()->values()->all();
        if (empty($transIds)) {
            return;
        }

        $itemsByTrans = collect($db->table('invoice as i')
            ->leftJoin('parts as p', 'p.id', '=', 'i.parts_id')
            ->whereIn('i.trans_id', $transIds)
            ->orderBy('i.trans_id')
            ->orderBy('i.id')
            ->select([
                'i.trans_id',
                'i.id as line_id',
                'p.description as part_description',
                'i.description as invoice_description',
                'i.chart_id',
                'i.class_id',
                'i.qty',
                'i.allocated',
                'i.sellprice',
            ])
            ->get())
            ->map(function ($item) {
                $item->part_description = $this->cleanText($item->part_description);
                $item->invoice_description = $this->cleanText($item->invoice_description);
                $item->chart_id = is_null($item->chart_id) ? null : (int) $item->chart_id;
                $item->class_id = is_null($item->class_id) ? null : (int) $item->class_id;
                $item->qty = is_null($item->qty) ? null : $this->displayQuantity($item->qty);
                $item->allocated = is_null($item->allocated) ? null : $this->displayQuantity($item->allocated);
                $item->sellprice = is_null($item->sellprice) ? null : abs((float) $item->sellprice);
                return $item;
            })
            ->groupBy('trans_id');

        foreach ($apRows as $row) {
            $items = $itemsByTrans->get($row->ap_id, collect());
            if ($items->isEmpty()) {
                $row->items = [];
                continue;
            }

            $usableItems = $items
                ->filter(fn($item) => $this->hasUsableItemText($item))
                ->values();

            $matchedItems = $usableItems
                ->filter(fn($item) => $item->class_id !== null && $row->acc_class_id !== null && (int) $item->class_id === (int) $row->acc_class_id)
                ->values();

            if ($matchedItems->isEmpty()) {
                $matchedItems = $usableItems
                    ->filter(fn($item) => $item->chart_id !== null && $row->account_chart_id !== null && (int) $item->chart_id === (int) $row->account_chart_id)
                    ->values();
            }

            if ($matchedItems->isEmpty()) {
                $matchedItems = $usableItems;
            }

            if ($matchedItems->isEmpty()) {
                $row->items = [];
                $row->item_qty = null;
                $row->item_allocated = null;
                $row->item_line_count = 0;
                continue;
            }

            $row->items = $matchedItems->all();
            $row->part_descriptions = $this->joinUniqueItemText($matchedItems, 'part_description') ?: $row->part_descriptions;
            $row->invoice_descriptions = $this->joinUniqueItemText($matchedItems, 'invoice_description') ?: $row->invoice_descriptions;
            $row->item_qty = (float) $matchedItems->sum(fn($item) => $this->displayQuantity($item->qty ?? null));
            if ($row->item_qty == 0.0) {
                $row->item_qty = null;
            }
            $prices = $matchedItems->pluck('sellprice')->filter(fn($value) => $value !== null);
            if ($prices->isNotEmpty()) {
                $row->min_unit_price = (float) $prices->min();
                $row->max_unit_price = (float) $prices->max();
                $row->unit_price_display = $this->unitPriceDisplay($row->min_unit_price, $row->max_unit_price);
            }
            $row->item_line_count = $matchedItems->count();
        }
    }

    private function joinUniqueItemText(Collection $items, string $property): string
    {
        return $items
            ->map(fn($item) => $this->cleanText($item->{$property} ?? ''))
            ->filter()
            ->unique()
            ->implode(', ');
    }

    private function hasUsableItemText($item): bool
    {
        return $this->cleanText($item->part_description ?? '') !== ''
            || $this->cleanText($item->invoice_description ?? '') !== '';
    }

    private function displayQuantity($value): float
    {
        if ($value === null || $value === '') {
            return 1.0;
        }

        $quantity = abs((float) $value);
        if ($quantity == 0.0) {
            return 1.0;
        }

        return (float) round($quantity);
    }

    private function detailDisplayLines($row): array
    {
        $items = collect($row->items ?? []);
        $itemLines = [];
        $rawTotal = 0.0;

        foreach ($items as $item) {
            $qty = $this->displayQuantity($item->qty ?? null);
            $unit = is_null($item->sellprice ?? null) ? 0.0 : abs((float) $item->sellprice);
            $total = $qty * $unit;
            $rawTotal += $total;
            $itemLines[] = [
                'part_description' => $this->cleanText($item->part_description ?? ''),
                'invoice_description' => $this->cleanText($item->invoice_description ?? ''),
                'qty' => $qty,
                'unit' => $unit,
                'total' => $total,
            ];
        }

        if (count($itemLines) > 1) {
            $lastIndex = count($itemLines) - 1;
            $itemLines[$lastIndex]['total'] += (float) $row->amount - $rawTotal;
            $itemLines[$lastIndex]['unit'] = $itemLines[$lastIndex]['qty'] != 0.0
                ? $itemLines[$lastIndex]['total'] / $itemLines[$lastIndex]['qty']
                : $itemLines[$lastIndex]['total'];

            return $itemLines;
        }

        $qty = !empty($row->preserve_display_qty)
            ? (float) ($row->item_qty ?? 1)
            : $this->displayQuantity($row->item_qty ?? null);
        $total = (float) $row->amount;
        $unit = !empty($row->preserve_display_qty) && $row->min_unit_price !== null
            ? abs((float) $row->min_unit_price)
            : ($qty != 0.0 ? $total / $qty : $total);

        return [[
            'part_description' => $this->cleanText($row->part_descriptions ?? ''),
            'invoice_description' => $this->cleanText($row->invoice_descriptions ?? ''),
            'qty' => $qty,
            'unit' => $unit,
            'total' => $total,
        ]];
    }

    private function buildExpenseMatrix(Collection $rows): array
    {
        $accounts = $rows
            ->groupBy(fn($row) => $row->account_code . '|' . $row->account_name)
            ->map(function (Collection $group, string $key) {
                $first = $group->first();

                return (object) [
                    'key' => $key,
                    'code' => $first->account_code,
                    'name' => $first->account_name,
                    'total_amount' => (float) $group->sum('amount'),
                ];
            })
            ->sortBy('code')
            ->values();

        $matrixRows = $rows
            ->groupBy(fn($row) => ($row->department_code ?: '') . '|' . $row->department)
            ->map(function (Collection $group) {
                $first = $group->first();
                $amounts = $group
                    ->groupBy(fn($row) => $row->account_code . '|' . $row->account_name)
                    ->map(fn(Collection $accountRows) => (float) $accountRows->sum('amount'))
                    ->all();

                return (object) [
                    'department_code' => $first->department_code,
                    'department' => $first->department,
                    'amounts' => $amounts,
                    'total_amount' => (float) $group->sum('amount'),
                ];
            })
            ->sortBy('department_code')
            ->values();

        $columnTotals = $accounts
            ->mapWithKeys(fn($account) => [$account->key => (float) $rows
                ->filter(fn($row) => ($row->account_code . '|' . $row->account_name) === $account->key)
                ->sum('amount')])
            ->all();

        return [
            'accounts' => $accounts,
            'rows' => $matrixRows,
            'columnTotals' => $columnTotals,
            'grandTotal' => (float) $rows->sum('amount'),
        ];
    }

    private function classKey($row): string
    {
        return ($row->department_code ?: '') . '|' . ($row->department ?: self::UNASSIGNED_DEPARTMENT);
    }

    private function unitPriceDisplay(?float $min, ?float $max): string
    {
        if ($min === null && $max === null) {
            return '';
        }

        if ($min === $max || $max === null) {
            return number_format((float) $min, 2);
        }

        return number_format((float) $min, 2) . ' - ' . number_format((float) $max, 2);
    }

    private function classMatchStatus($accClassId, $invoiceClassId): string
    {
        if ($accClassId === null && $invoiceClassId === null) {
            return 'ไม่มี';
        }

        if ($accClassId === null) {
            return 'ไม่มี acc';
        }

        if ($invoiceClassId === null) {
            return 'ไม่มี item';
        }

        return (int) $accClassId === (int) $invoiceClassId ? 'ตรงกัน' : 'ต่างกัน';
    }

    private function cleanText(?string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', (string) $value);

        return trim(preg_replace('/\s+/', ' ', $value));
    }
}
