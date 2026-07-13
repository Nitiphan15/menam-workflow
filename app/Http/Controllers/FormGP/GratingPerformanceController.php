<?php

namespace App\Http\Controllers\FormGP;

use App\Http\Controllers\Controller;
use App\Support\SqlServerDb;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GratingPerformanceController extends Controller
{
    private const D8_FG_GRATING_MONTHLY_TARGET = 1700000.0;
    private const WORK_BREAKS = [
        ['10:00', '10:15'],
        ['12:00', '13:00'],
        ['15:00', '15:15'],
    ];

    private ?array $entryColumnAvailability = null;
    private ?array $stepColumnAvailability = null;
    private ?array $employeeColumnAvailability = null;
    private ?array $tableColumnAvailability = null;

    private const DEFAULT_MASTER_STEPS = [
        ['step_code' => 'WELD_ASSEMBLY', 'step_name' => 'เชื่อมประกอบ', 'target_kg_per_hour' => null, 'sort_order' => 10, 'is_field_work' => false],
        ['step_code' => 'CLEAN_WELD', 'step_name' => 'ล้างรอยเชื่อม', 'target_kg_per_hour' => null, 'sort_order' => 20, 'is_field_work' => false],
        ['step_code' => 'PASSIVATE', 'step_name' => 'แช่ passivate', 'target_kg_per_hour' => null, 'sort_order' => 30, 'is_field_work' => false],
        ['step_code' => 'EPQ', 'step_name' => 'EPQ', 'target_kg_per_hour' => null, 'sort_order' => 40, 'is_field_work' => false],
        ['step_code' => 'SPOT_GRATING', 'step_name' => 'spot แผ่นเกรตติ้ง', 'target_kg_per_hour' => null, 'sort_order' => 50, 'is_field_work' => false],
        ['step_code' => 'CUT_GRIND_GRATING', 'step_name' => 'ตัด-ขัด เกรตติ้ง / ขัด Hair line', 'target_kg_per_hour' => null, 'sort_order' => 60, 'is_field_work' => false],
        ['step_code' => 'CUT_GRIND_FLATBAR', 'step_name' => 'ตัด-ขัด แฟลตบาร์', 'target_kg_per_hour' => null, 'sort_order' => 70, 'is_field_work' => false],
        ['step_code' => 'LASER', 'step_name' => 'เลเซอร์', 'target_kg_per_hour' => null, 'sort_order' => 80, 'is_field_work' => false],
        ['step_code' => 'PACK', 'step_name' => 'แพ็ค', 'target_kg_per_hour' => null, 'sort_order' => 90, 'is_field_work' => false],
        ['step_code' => 'FIELD', 'step_name' => 'ออกหน้างาน', 'target_kg_per_hour' => null, 'sort_order' => 100, 'is_field_work' => true],
    ];

    public function index(Request $request)
    {
        if (!$this->hasRequiredTables()) {
            return view('formGP.grating-performance.index', [
                'setupMissing' => true,
                'filters' => $this->filters($request),
                'employees' => collect(),
                'steps' => collect(),
                'summary' => [
                    'target_bath' => 0,
                    'target_months' => 0,
                    'pack_kg' => 0.0,
                    'avg_price_per_kg' => null,
                    'output_bath' => null,
                ],
                'employeeSummary' => collect(),
                'mfgSummary' => collect(),
                'stepSummary' => collect(),
                'slowStepSummary' => collect(),
                'periodSummary' => collect(),
                'mfgCycleSummary' => collect(),
                'mfgStepPeopleSummary' => collect(),
                'mfgIncompleteSummary' => collect(),
                'recentEntries' => collect(),
                'employeeStepEfficiency' => collect(),
                'workloadByDay' => collect(),
            ]);
        }

        $filters = $this->filters($request);
        $entries = $this->entryBase($filters);

        $range = $this->rangeAverages($filters['date_from'], $filters['date_to']);
        $totalMinutes = (int) (clone $entries)->sum(DB::raw('COALESCE(e.duration_minutes, 0)'));
        $stepSummary = $this->stepSummary($filters);

        $avgPricePerKg = $this->avgFgGratingPricePerKg();
        $packKg = $this->packGoodKg($filters);
        $packBath = $avgPricePerKg !== null ? round($packKg * $avgPricePerKg, 0) : null;
        $outputTotals = $this->mfgOutputTotals($filters);

        $summary = [
            'entries' => (clone $entries)->count(),
            'mfg_count' => (clone $entries)->where('e.mfg_no', '<>', 'FIELD')->count(DB::raw('DISTINCT e.mfg_no')),
            'good_kg' => $outputTotals['good_kg'],
            'good_pcs' => $outputTotals['good_pcs'],
            'good_area_sqm' => $outputTotals['good_area_sqm'],
            'step_good_kg' => (float) (clone $entries)->where('e.mfg_no', '<>', 'FIELD')->sum('e.good_qty_kg'),
            'step_good_pcs' => (float) (clone $entries)->where('e.mfg_no', '<>', 'FIELD')->sum('e.good_qty_pcs'),
            'step_good_area_sqm' => (float) (clone $entries)->where('e.mfg_no', '<>', 'FIELD')->sum('e.good_area_sqm'),
            'bad_kg' => (float) (clone $entries)->sum('e.bad_qty_kg'),
            'bad_pcs' => (float) (clone $entries)->sum('e.bad_qty_pcs'),
            'bad_area_sqm' => (float) (clone $entries)->sum('e.bad_area_sqm'),
            'minutes' => $totalMinutes,
            'range_days' => $range['days'],
            'hours_per_day' => ($totalMinutes / 60) / $range['days'],
            'target_bath' => $this->salesD8TargetForRange($filters['date_from'], $filters['date_to']),
            'target_months' => $this->monthsInRange($filters['date_from'], $filters['date_to']),
            'target_source' => 'Summary Sales D8 / FG GRATING',
            'pack_kg' => $packKg,
            'avg_price_per_kg' => $avgPricePerKg,
            'output_bath' => $packBath,
            'finished' => (clone $entries)->where('e.is_finished', true)->count(),
        ];

        return view('formGP.grating-performance.index', [
            'setupMissing' => false,
            'filters' => $filters,
            'employees' => $this->employeeOptions(),
            'steps' => $this->stepOptions(),
            'summary' => $summary,
            'employeeSummary' => $this->employeeSummary($filters),
            'mfgSummary' => $this->mfgSummary($filters),
            'stepSummary' => $stepSummary,
            'slowStepSummary' => $this->slowStepSummary($stepSummary),
            'periodSummary' => $this->periodSummary($filters),
            'mfgCycleSummary' => $this->mfgCycleSummary($filters),
            'mfgStepPeopleSummary' => $this->mfgStepPeopleSummary($filters),
            'mfgIncompleteSummary' => $this->mfgIncompleteSummary($filters),
            'recentEntries' => $this->recentEntries($filters),
            'employeeStepEfficiency' => $this->employeeStepEfficiency($filters),
            'workloadByDay' => $this->workloadByDay($filters),
        ]);
    }

    public function stepBalance(Request $request)
    {
        $mfgNo = trim((string) $request->query('mfg_no', ''));
        $stepId = (int) $request->query('step_id');
        $planQtyPcs = (float) $request->query('plan_qty_pcs', 0);
        $excludeEntryId = (int) $request->query('exclude_entry_id', 0) ?: null;

        if ($mfgNo === '' || $stepId <= 0 || $planQtyPcs <= 0) {
            return response()->json([
                'plan_qty_pcs' => $planQtyPcs,
                'used_qty_pcs' => 0,
                'remaining_qty_pcs' => max(0, $planQtyPcs),
            ]);
        }

        $usedQtyPcs = $this->entryStepPcsTotal($mfgNo, $stepId, $excludeEntryId);

        return response()->json([
            'plan_qty_pcs' => $planQtyPcs,
            'used_qty_pcs' => $usedQtyPcs,
            'remaining_qty_pcs' => max(0, $planQtyPcs - $usedQtyPcs),
        ]);
    }

    public function inquiry(Request $request)
    {
        if (!$this->hasRequiredTables()) {
            return view('formGP.grating-performance.inquiry', [
                'setupMissing' => true,
                'filters' => $this->filters($request),
                'rows' => collect(),
                'employeeDailyRows' => collect(),
                'mfgFlowRows' => collect(),
                'employeeDetailRows' => collect(),
                'mfgDetailRows' => collect(),
                'steps' => collect(),
                'employees' => collect(),
            ]);
        }

        $filters = $this->filters($request);
        $viewMode = $filters['view_mode'] ?? 'transactions';
        $rows = collect();
        $employeeDailyRows = collect();
        $mfgFlowRows = collect();
        $employeeDetailRows = collect();
        $mfgDetailRows = collect();

        if ($viewMode === 'transactions') {
            $employeeRollup = $this->employeeRollupSubquery();
            $stepRollup = $this->stepRollupSubquery();
            $fieldMfgRollup = $this->fieldMfgRollupSubquery();
            $targetPcsExpr = $this->hasStepColumn('target_pcs_per_hour') ? 's.target_pcs_per_hour' : 'CAST(NULL AS decimal(12, 3))';
            $query = $this->entryBase($filters)
                ->select([
                    'e.*',
                    DB::raw("COALESCE(sr.step_codes, s.step_code) as step_code"),
                    DB::raw("COALESCE(sr.step_names, s.step_name) as step_name"),
                    's.target_kg_per_hour',
                    DB::raw($targetPcsExpr . ' as target_pcs_per_hour'),
                    DB::raw("COALESCE(er.employee_names, '') as employee_names"),
                    DB::raw('COALESCE(er.team_size, 0) as team_size'),
                    DB::raw("COALESCE(fmr.field_mfgs, '') as field_mfgs"),
                ])
                ->leftJoinSub($employeeRollup, 'er', 'er.entry_id', '=', 'e.id')
                ->leftJoinSub($stepRollup, 'sr', 'sr.entry_id', '=', 'e.id')
                ->leftJoinSub($fieldMfgRollup, 'fmr', 'fmr.entry_id', '=', 'e.id');

            if ($this->hasTableColumn('grating_daily_entries', 'created_by')) {
                $query->leftJoin('users as creator', 'creator.id', '=', 'e.created_by')
                    ->addSelect(DB::raw("creator.name as created_by_name"));
            } else {
                $query->addSelect(DB::raw("CAST(NULL AS NVARCHAR(160)) as created_by_name"));
            }

            if ($this->hasTableColumn('grating_daily_entries', 'updated_by')) {
                $query->leftJoin('users as updater', 'updater.id', '=', 'e.updated_by')
                    ->addSelect(DB::raw("updater.name as updated_by_name"));
            } else {
                $query->addSelect(DB::raw("CAST(NULL AS NVARCHAR(160)) as updated_by_name"));
            }

            $rows = $query
                ->orderByDesc('e.work_date')
                ->orderByDesc('e.started_at')
                ->paginate(50)
                ->withQueryString();
        } elseif ($viewMode === 'employee') {
            $employeeDailyRows = $this->employeeDailyInquiry($filters);
            $employeeDetailRows = $this->employeeTransactionDetails($filters);
        } elseif ($viewMode === 'mfg') {
            $mfgFlowRows = $this->mfgFlowInquiry($filters);
            $mfgDetailRows = $this->mfgTransactionDetails($filters);
        }

        return view('formGP.grating-performance.inquiry', [
            'setupMissing' => false,
            'filters' => $filters,
            'rows' => $rows,
            'summary' => $this->inquirySummary($filters),
            'employeeDailyRows' => $employeeDailyRows,
            'mfgFlowRows' => $mfgFlowRows,
            'employeeDetailRows' => $employeeDetailRows,
            'mfgDetailRows' => $mfgDetailRows,
            'steps' => $this->stepOptions(false),
            'employees' => $this->employeeOptions(false),
        ]);
    }

    public function create()
    {
        if (!$this->hasRequiredTables()) {
            return view('formGP.grating-performance.create', [
                'setupMissing' => true,
                'employees' => collect(),
                'steps' => collect(),
                'fieldActivities' => collect(),
            ]);
        }

        return view('formGP.grating-performance.create', [
            'setupMissing' => false,
            'employees' => $this->employeeOptions(),
            'steps' => $this->stepOptions(),
            'fieldActivities' => $this->fieldActivityOptions(),
        ]);
    }

    public function masters()
    {
        if (!$this->hasRequiredTables()) {
            return view('formGP.grating-performance.masters', [
                'setupMissing' => true,
                'employees' => collect(),
                'steps' => collect(),
                'fieldActivities' => collect(),
                'projects' => collect(),
                'defaultSteps' => collect(self::DEFAULT_MASTER_STEPS),
            ]);
        }

        return view('formGP.grating-performance.masters', [
            'setupMissing' => false,
            'employees' => $this->employeeOptions(false),
            'steps' => $this->stepOptions(false),
            'fieldActivities' => $this->fieldActivityRows(false),
            'projects' => $this->projectRows(false),
            'defaultSteps' => collect(self::DEFAULT_MASTER_STEPS),
        ]);
    }

    public function storeEntry(Request $request)
    {
        if (!$this->hasRequiredTables()) {
            return back()->withErrors(['setup' => 'ยังไม่พบตาราง Grating Performance กรุณารัน migration ก่อน']);
        }

        $validated = $request->validate([
            'work_date' => ['required', 'date'],
            'step_id' => ['required', Rule::exists(SqlServerDb::qualifyTable('grating_steps'), 'id')],
            'is_field_work' => ['nullable', 'boolean'],
            'field_activity' => ['nullable', 'array'],
            'field_activity.*' => ['required', $this->fieldActivityExistsRule()],
            'field_details' => ['nullable', 'required_if:is_field_work,1', 'string', 'max:1000'],
            'employee_id' => ['required', Rule::exists(SqlServerDb::qualifyTable('grating_employees'), 'id')],
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.mfg_no' => ['nullable', 'string', 'max:80'],
            'entries.*.start_time' => ['required', 'date_format:H:i'],
            'entries.*.finish_time' => ['nullable', 'date_format:H:i'],
            'entries.*.good_qty_kg' => ['nullable', 'numeric', 'min:0'],
            'entries.*.bad_qty_kg' => ['nullable', 'numeric', 'min:0'],
            'entries.*.good_qty_pcs' => ['nullable', 'numeric', 'min:0'],
            'entries.*.bad_qty_pcs' => ['nullable', 'numeric', 'min:0'],
            'entries.*.good_area_sqm' => ['nullable', 'numeric', 'min:0'],
            'entries.*.bad_area_sqm' => ['nullable', 'numeric', 'min:0'],
            'entries.*.plan_qty_pcs' => ['nullable', 'numeric', 'min:0'],
            'entries.*.sqm_per_piece' => ['nullable', 'numeric', 'min:0'],
            'entries.*.mfg_site' => ['nullable', 'string', 'max:20'],
            'entries.*.partnumber' => ['nullable', 'string', 'max:80'],
            'entries.*.part_description' => ['nullable', 'string', 'max:4000'],
            'entries.*.part_unit' => ['nullable', 'string', 'max:20'],
            'entries.*.ref_unit_qty' => ['nullable', 'numeric', 'min:0'],
            'entries.*.project' => ['nullable', 'string', 'max:500'],
            'entries.*.salesorder' => ['nullable', 'string', 'max:80'],
            'entries.*.field_mfgs' => ['nullable', 'array'],
            'entries.*.field_mfgs.*.mfg_no' => ['required', 'string', 'max:80'],
            'entries.*.field_mfgs.*.workorder_id' => ['nullable', 'integer'],
            'entries.*.field_mfgs.*.project' => ['nullable', 'string', 'max:500'],
            'entries.*.field_mfgs.*.salesorder' => ['nullable', 'string', 'max:80'],
            'entries.*.width_mm' => ['nullable', 'numeric', 'min:0'],
            'entries.*.length_mm' => ['nullable', 'numeric', 'min:0'],
            'entries.*.is_finished' => ['nullable', 'boolean'],
            'entries.*.notes' => ['nullable', 'string', 'max:1000'],
            'entries.*.coworker_ids' => ['nullable', 'array'],
            'entries.*.coworker_ids.*' => ['required', Rule::exists(SqlServerDb::qualifyTable('grating_employees'), 'id')],
        ]);

        $workDate = Carbon::parse($validated['work_date'])->toDateString();

        SqlServerDb::transaction(function () use ($validated, $workDate) {
            $stepIds = $this->resolveEntryStepIds($validated);
            $primaryStepId = $stepIds->first();
            $isFieldWork = SqlServerDb::table('grating_steps')
                ->whereIn('id', $stepIds->all())
                ->where('is_field_work', true)
                ->exists();
            $isPackStep = SqlServerDb::table('grating_steps')
                ->whereIn('id', $stepIds->all())
                ->where('step_code', 'PACK')
                ->exists();

            if ($isFieldWork && empty($validated['field_activity'])) {
                throw ValidationException::withMessages(['field_activity' => 'Please choose a field activity.']);
            }

            if ($isFieldWork && empty($validated['field_details'])) {
                throw ValidationException::withMessages(['field_details' => 'Please fill field work details.']);
            }

            $batchTotals = [];

            foreach ($validated['entries'] as $index => $entry) {
                if (!$isFieldWork && trim((string) ($entry['mfg_no'] ?? '')) === '') {
                    throw ValidationException::withMessages(["entries.$index.mfg_no" => 'Please fill MFG.']);
                }

                if ($isFieldWork && trim((string) ($entry['project'] ?? '')) === '') {
                    throw ValidationException::withMessages(["entries.$index.project" => 'Please fill project for field work.']);
                }

                if ($isFieldWork && trim((string) ($entry['salesorder'] ?? '')) === '') {
                    throw ValidationException::withMessages(["entries.$index.salesorder" => 'Please fill sales order for field work.']);
                }

                if ($isFieldWork && empty($entry['field_mfgs'])) {
                    throw ValidationException::withMessages(["entries.$index.mfg_no" => 'Please choose at least one related MFG for field work.']);
                }

                $startedAt = Carbon::parse($workDate . ' ' . $entry['start_time']);
                $finishedAt = null;
                $durationMinutes = null;

                if (!empty($entry['finish_time'])) {
                    $finishedAt = Carbon::parse($workDate . ' ' . $entry['finish_time']);
                    if ($finishedAt->lt($startedAt)) {
                        $finishedAt->addDay();
                    }
                    $durationMinutes = $this->netDurationMinutes($startedAt, $finishedAt);
                }

                $quantity = $this->entryQuantityPayload($entry, $isFieldWork);
                $mfgNo = $isFieldWork
                    ? 'FIELD'
                    : strtoupper(trim((string) ($entry['mfg_no'] ?? '')));

                if (!$isFieldWork) {
                    $this->assertEntryDoesNotExceedPlan($entry, $quantity, $stepIds, $mfgNo, $index, null, $batchTotals);
                }

                $entryPayload = [
                    'work_date' => $workDate,
                    'mfg_no' => $mfgNo,
                    'step_id' => $primaryStepId,
                    'started_at' => $startedAt,
                    'finished_at' => $finishedAt,
                    'duration_minutes' => $durationMinutes,
                    'good_qty_kg' => $quantity['good_qty_kg'],
                    'bad_qty_kg' => $quantity['bad_qty_kg'],
                    'good_qty_pcs' => $quantity['good_qty_pcs'],
                    'bad_qty_pcs' => $quantity['bad_qty_pcs'],
                    'plan_qty_pcs' => $quantity['plan_qty_pcs'],
                    'sqm_per_piece' => $quantity['sqm_per_piece'],
                    'good_area_sqm' => $quantity['good_area_sqm'],
                    'bad_area_sqm' => $quantity['bad_area_sqm'],
                    'mfg_site' => $quantity['mfg_site'],
                    'partnumber' => $quantity['partnumber'],
                    'part_description' => $quantity['part_description'],
                    'part_unit' => $quantity['part_unit'],
                    'width_mm' => $quantity['width_mm'],
                    'length_mm' => $quantity['length_mm'],
                    'is_finished' => $isPackStep || (bool) ($entry['is_finished'] ?? false),
                    'is_field_work' => $isFieldWork,
                    'field_activity' => $isFieldWork ? implode(', ', $validated['field_activity'] ?? []) : null,
                    'field_details' => $isFieldWork ? ($validated['field_details'] ?? null) : null,
                    'project' => $isFieldWork ? ($entry['project'] ?? null) : ($quantity['project'] ?? null),
                    'salesorder' => $isFieldWork ? ($entry['salesorder'] ?? null) : ($quantity['salesorder'] ?? null),
                    'notes' => $entry['notes'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $entryId = SqlServerDb::table('grating_daily_entries')
                    ->insertGetId($this->withAudit('grating_daily_entries', $entryPayload));

                SqlServerDb::table('grating_entry_steps')->insert(
                    $stepIds->map(fn($stepId) => $this->withAudit('grating_entry_steps', [
                        'entry_id' => $entryId,
                        'step_id' => $stepId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]))->all()
                );

                if ($isFieldWork) {
                    $this->syncFieldMfgs($entryId, $entry['field_mfgs'] ?? []);
                }

                $employeeIds = collect([$validated['employee_id']])
                    ->merge($entry['coworker_ids'] ?? [])
                    ->filter()
                    ->unique()
                    ->values();

                $employeeRows = $employeeIds->map(fn($employeeId) => $this->withAudit('grating_entry_employees', [
                    'entry_id' => $entryId,
                    'employee_id' => $employeeId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]))->all();

                SqlServerDb::table('grating_entry_employees')->insert($employeeRows);
            }
        });

        return back()
            ->with('success', 'Saved grating performance entry.')
            ->with('grating_entry_saved', true);
    }

    private function entryQuantityPayload(array $entry, bool $isFieldWork): array
    {
        if ($isFieldWork) {
            return [
                'good_qty_pcs' => 0,
                'bad_qty_pcs' => 0,
                'good_qty_kg' => 0,
                'bad_qty_kg' => 0,
                'plan_qty_pcs' => null,
                'sqm_per_piece' => null,
                'good_area_sqm' => 0,
                'bad_area_sqm' => 0,
                'mfg_site' => null,
                'partnumber' => null,
                'part_description' => null,
                'part_unit' => null,
                'project' => $entry['project'] ?? null,
                'salesorder' => $entry['salesorder'] ?? null,
                'width_mm' => null,
                'length_mm' => null,
            ];
        }

        $goodPcs = (float) ($entry['good_qty_pcs'] ?? 0);
        $badPcs = (float) ($entry['bad_qty_pcs'] ?? 0);
        $planQtyPcs = isset($entry['plan_qty_pcs']) && $entry['plan_qty_pcs'] !== '' ? (float) $entry['plan_qty_pcs'] : null;
        $refUnitQty = isset($entry['ref_unit_qty']) && $entry['ref_unit_qty'] !== '' ? (float) $entry['ref_unit_qty'] : 0.0;
        $widthMm = isset($entry['width_mm']) && $entry['width_mm'] !== '' ? (float) $entry['width_mm'] : null;
        $lengthMm = isset($entry['length_mm']) && $entry['length_mm'] !== '' ? (float) $entry['length_mm'] : null;
        $sqmPerPiece = isset($entry['sqm_per_piece']) && $entry['sqm_per_piece'] !== '' ? (float) $entry['sqm_per_piece'] : null;

        if ((!$sqmPerPiece || !$widthMm || !$lengthMm) && !empty($entry['part_description'])) {
            $parsed = $this->parseAreaDimension((string) $entry['part_description']);
            $widthMm = $widthMm ?: $parsed['width_mm'];
            $lengthMm = $lengthMm ?: $parsed['length_mm'];
            $sqmPerPiece = $sqmPerPiece ?: $parsed['sqm_per_piece'];
        }

        if (!$sqmPerPiece && $widthMm && $lengthMm) {
            $sqmPerPiece = ($widthMm / 1000) * ($lengthMm / 1000);
        }

        $sqmPerPiece = $sqmPerPiece ?: null;

        return [
            'good_qty_pcs' => $goodPcs,
            'bad_qty_pcs' => $badPcs,
            'good_qty_kg' => $refUnitQty > 0 ? round($goodPcs * $refUnitQty, 3) : (float) ($entry['good_qty_kg'] ?? 0),
            'bad_qty_kg' => $refUnitQty > 0 ? round($badPcs * $refUnitQty, 3) : (float) ($entry['bad_qty_kg'] ?? 0),
            'plan_qty_pcs' => $planQtyPcs,
            'sqm_per_piece' => $sqmPerPiece ? round($sqmPerPiece, 6) : null,
            'good_area_sqm' => $sqmPerPiece ? round($goodPcs * $sqmPerPiece, 3) : 0,
            'bad_area_sqm' => $sqmPerPiece ? round($badPcs * $sqmPerPiece, 3) : 0,
            'mfg_site' => $entry['mfg_site'] ?? null,
            'partnumber' => $entry['partnumber'] ?? null,
            'part_description' => $entry['part_description'] ?? null,
            'part_unit' => $entry['part_unit'] ?? null,
            'project' => $entry['project'] ?? null,
            'salesorder' => $entry['salesorder'] ?? null,
            'width_mm' => $widthMm,
            'length_mm' => $lengthMm,
        ];
    }

    private function parseAreaDimension(string $description): array
    {
        $patterns = [
            '/\bW\s*([0-9]+(?:\.[0-9]+)?)\s*(?:mm\.?)?\s*[xX*]\s*L\s*([0-9]+(?:\.[0-9]+)?)/iu',
            '/\bW\s*([0-9]+(?:\.[0-9]+)?)\s*[xX*]\s*([0-9]+(?:\.[0-9]+)?)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $description, $matches)) {
                $widthMm = (float) $matches[1];
                $lengthMm = (float) $matches[2];

                return [
                    'width_mm' => $widthMm,
                    'length_mm' => $lengthMm,
                    'sqm_per_piece' => round(($widthMm / 1000) * ($lengthMm / 1000), 6),
                ];
            }
        }

        return [
            'width_mm' => null,
            'length_mm' => null,
            'sqm_per_piece' => null,
        ];
    }

    public function updateEntry(Request $request, int $entry)
    {
        if (!$this->hasRequiredTables()) {
            return back()->withErrors(['setup' => 'ยังไม่พบตาราง Grating Performance กรุณารัน migration ก่อน']);
        }

        $current = SqlServerDb::table('grating_daily_entries')->where('id', $entry)->first();
        if (!$current) {
            return back()->withErrors(['entry' => 'ไม่พบรายการที่ต้องการแก้ไข']);
        }

        $validated = $request->validate([
            'work_date' => ['required', 'date'],
            'step_id' => ['required', Rule::exists(SqlServerDb::qualifyTable('grating_steps'), 'id')],
            'start_time' => ['required', 'date_format:H:i'],
            'finish_time' => ['nullable', 'date_format:H:i'],
            'good_qty_pcs' => ['nullable', 'numeric', 'min:0'],
            'bad_qty_pcs' => ['nullable', 'numeric', 'min:0'],
            'good_qty_kg' => ['nullable', 'numeric', 'min:0'],
            'bad_qty_kg' => ['nullable', 'numeric', 'min:0'],
            'plan_qty_pcs' => ['nullable', 'numeric', 'min:0'],
            'sqm_per_piece' => ['nullable', 'numeric', 'min:0'],
            'ref_unit_qty' => ['nullable', 'numeric', 'min:0'],
            'project' => ['nullable', 'string', 'max:500'],
            'salesorder' => ['nullable', 'string', 'max:80'],
            'field_activity' => ['nullable', 'string', 'max:500'],
            'field_details' => ['nullable', 'string', 'max:1000'],
            'field_mfg_text' => ['nullable', 'string', 'max:1000'],
            'field_mfgs' => ['nullable', 'array'],
            'field_mfgs.*.mfg_no' => ['required', 'string', 'max:80'],
            'field_mfgs.*.workorder_id' => ['nullable', 'integer'],
            'field_mfgs.*.project' => ['nullable', 'string', 'max:500'],
            'field_mfgs.*.salesorder' => ['nullable', 'string', 'max:80'],
            'is_finished' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $stepId = (int) $validated['step_id'];
        $step = SqlServerDb::table('grating_steps')->where('id', $stepId)->first();
        if (!$step) {
            return back()->withErrors(['step_id' => 'ไม่พบ step ที่เลือก']);
        }

        $isFieldWork = (bool) ($step->is_field_work ?? false);
        $isPackStep = (string) ($step->step_code ?? '') === 'PACK';

        if (!empty($validated['field_mfg_text'])) {
            $validated['field_mfgs'] = collect(explode(',', (string) $validated['field_mfg_text']))
                ->map(fn($mfgNo) => ['mfg_no' => trim($mfgNo)])
                ->filter(fn($item) => $item['mfg_no'] !== '')
                ->values()
                ->all();
        }

        if ($isFieldWork && trim((string) ($validated['project'] ?? '')) === '') {
            return back()->withErrors(['project' => 'กรุณากรอกโครงการสำหรับงานหน้างาน']);
        }

        if ($isFieldWork && trim((string) ($validated['salesorder'] ?? '')) === '') {
            return back()->withErrors(['salesorder' => 'กรุณากรอกเลข SO สำหรับงานหน้างาน']);
        }

        if ($isFieldWork && empty($validated['field_mfgs'])) {
            return back()->withErrors(['field_mfgs' => 'กรุณาเลือก MFG ที่เกี่ยวข้องสำหรับงานหน้างาน']);
        }

        $workDate = Carbon::parse($validated['work_date'])->toDateString();
        $startedAt = Carbon::parse($workDate . ' ' . $validated['start_time']);
        $finishedAt = null;
        $durationMinutes = null;

        if (!empty($validated['finish_time'])) {
            $finishedAt = Carbon::parse($workDate . ' ' . $validated['finish_time']);
            if ($finishedAt->lt($startedAt)) {
                $finishedAt->addDay();
            }
            $durationMinutes = $this->netDurationMinutes($startedAt, $finishedAt);
        }

        $entryPayload = [
            'good_qty_pcs' => $validated['good_qty_pcs'] ?? 0,
            'bad_qty_pcs' => $validated['bad_qty_pcs'] ?? 0,
            'good_qty_kg' => $validated['good_qty_kg'] ?? 0,
            'bad_qty_kg' => $validated['bad_qty_kg'] ?? 0,
            'plan_qty_pcs' => $validated['plan_qty_pcs'] ?? null,
            'sqm_per_piece' => $validated['sqm_per_piece'] ?? ($current->sqm_per_piece ?? null),
            'ref_unit_qty' => $validated['ref_unit_qty'] ?? null,
            'mfg_site' => $current->mfg_site ?? null,
            'partnumber' => $current->partnumber ?? null,
            'part_description' => $current->part_description ?? null,
            'part_unit' => $current->part_unit ?? null,
            'project' => $validated['project'] ?? null,
            'salesorder' => $validated['salesorder'] ?? null,
            'width_mm' => $current->width_mm ?? null,
            'length_mm' => $current->length_mm ?? null,
        ];
        $quantity = $this->entryQuantityPayload($entryPayload, $isFieldWork);
        $mfgNo = (string) ($current->mfg_no ?? '');

        if (!$isFieldWork) {
            $this->assertEntryDoesNotExceedPlan($entryPayload, $quantity, collect([$stepId]), $mfgNo, 0, $entry);
        }

        SqlServerDb::transaction(function () use ($entry, $validated, $workDate, $startedAt, $finishedAt, $durationMinutes, $quantity, $stepId, $isFieldWork, $isPackStep) {
            SqlServerDb::table('grating_daily_entries')
                ->where('id', $entry)
                ->update($this->withAudit('grating_daily_entries', [
                    'work_date' => $workDate,
                    'step_id' => $stepId,
                    'started_at' => $startedAt,
                    'finished_at' => $finishedAt,
                    'duration_minutes' => $durationMinutes,
                    'good_qty_kg' => $quantity['good_qty_kg'],
                    'bad_qty_kg' => $quantity['bad_qty_kg'],
                    'good_qty_pcs' => $quantity['good_qty_pcs'],
                    'bad_qty_pcs' => $quantity['bad_qty_pcs'],
                    'plan_qty_pcs' => $quantity['plan_qty_pcs'],
                    'sqm_per_piece' => $quantity['sqm_per_piece'],
                    'good_area_sqm' => $quantity['good_area_sqm'],
                    'bad_area_sqm' => $quantity['bad_area_sqm'],
                    'is_finished' => $isPackStep || (bool) ($validated['is_finished'] ?? false),
                    'is_field_work' => $isFieldWork,
                    'field_activity' => $isFieldWork ? ($validated['field_activity'] ?? null) : null,
                    'field_details' => $isFieldWork ? ($validated['field_details'] ?? null) : null,
                    'project' => $quantity['project'],
                    'salesorder' => $quantity['salesorder'],
                    'notes' => $validated['notes'] ?? null,
                    'updated_at' => now(),
                ], true));

            SqlServerDb::table('grating_entry_steps')->where('entry_id', $entry)->delete();
            SqlServerDb::table('grating_entry_steps')->insert($this->withAudit('grating_entry_steps', [
                'entry_id' => $entry,
                'step_id' => $stepId,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            $this->syncFieldMfgs($entry, $isFieldWork ? ($validated['field_mfgs'] ?? []) : []);
        });

        return back()->with('success', 'แก้ไขรายการแล้ว');
    }

    /**
     * เช็คว่าจำนวนชิ้น (ดี + เสีย) ของ MFG ใน step ที่เลือก เมื่อรวมกับยอดที่เคยบันทึกไว้แล้ว
     * และยอดของแถวก่อนหน้าใน submit เดียวกัน จะไม่เกิน "แผนเปิด" (plan_qty_pcs)
     *
     * @param array $batchTotals ตัวสะสมยอดข้ามแถวภายใน submit เดียวกัน (ส่งแบบ by-reference)
     */
    private function assertEntryDoesNotExceedPlan(array $entry, array $quantity, $stepIds, string $mfgNo, int $index, ?int $excludeEntryId = null, array &$batchTotals = []): void
    {
        $planQtyPcs = $quantity['plan_qty_pcs'] ?? null;

        if (!$planQtyPcs || $planQtyPcs <= 0) {
            return;
        }

        $entryQtyPcs = (float) ($quantity['good_qty_pcs'] ?? 0) + (float) ($quantity['bad_qty_pcs'] ?? 0);

        foreach ($stepIds as $stepId) {
            $stepId = (int) $stepId;
            $key = $mfgNo . '|' . $stepId;

            // ยอดที่เคยบันทึกไว้แล้วใน DB สำหรับ MFG + step นี้
            $existingQtyPcs = $this->entryStepPcsTotal($mfgNo, $stepId, $excludeEntryId);
            // ยอดของแถวก่อนหน้าใน submit เดียวกัน (MFG + step เดียวกัน)
            $batchQtyPcs = (float) ($batchTotals[$key] ?? 0);

            if (($existingQtyPcs + $batchQtyPcs + $entryQtyPcs) > ((float) $planQtyPcs + 0.0001)) {
                throw ValidationException::withMessages([
                    "entries.$index.good_qty_pcs" => sprintf(
                        'MFG %s step นี้เกินจำนวนเปิดแผน %s ชิ้น (บันทึกแล้ว %s + แถวก่อนหน้า %s + กำลังกรอก %s)',
                        $mfgNo,
                        number_format((float) $planQtyPcs),
                        number_format($existingQtyPcs),
                        number_format($batchQtyPcs),
                        number_format($entryQtyPcs)
                    ),
                ]);
            }

            // ผ่านแล้ว — สะสมยอดของแถวนี้ไว้ให้แถวถัดไปใน submit เดียวกันนับต่อ
            $batchTotals[$key] = $batchQtyPcs + $entryQtyPcs;
        }
    }

    private function entryStepPcsTotal(string $mfgNo, int $stepId, ?int $excludeEntryId = null): float
    {
        return (float) $this->entryBase([
                'date_from' => null,
                'date_to' => null,
                'mfg_no' => '',
                'project' => '',
                'salesorder' => '',
                'step_id' => null,
                'employee_id' => null,
                'period' => 'day',
            ])
            ->leftJoin('grating_entry_steps as ges_limit', 'ges_limit.entry_id', '=', 'e.id')
            ->where('e.mfg_no', $mfgNo)
            ->whereRaw('COALESCE(ges_limit.step_id, e.step_id) = ?', [$stepId])
            ->when($excludeEntryId, fn($q) => $q->where('e.id', '<>', $excludeEntryId))
            ->sum(DB::raw('COALESCE(e.good_qty_pcs, 0) + COALESCE(e.bad_qty_pcs, 0)'));
    }

    private function netDurationMinutes(Carbon $startedAt, Carbon $finishedAt): int
    {
        $grossMinutes = max(0, $startedAt->diffInMinutes($finishedAt));
        if ($grossMinutes === 0) {
            return 0;
        }

        $breakMinutes = 0;
        $day = $startedAt->copy()->startOfDay();
        $lastDay = $finishedAt->copy()->startOfDay();

        while ($day->lte($lastDay)) {
            foreach (self::WORK_BREAKS as [$breakStartTime, $breakEndTime]) {
                $breakStart = Carbon::parse($day->toDateString() . ' ' . $breakStartTime);
                $breakEnd = Carbon::parse($day->toDateString() . ' ' . $breakEndTime);
                $overlapStart = $startedAt->gt($breakStart) ? $startedAt->copy() : $breakStart;
                $overlapEnd = $finishedAt->lt($breakEnd) ? $finishedAt->copy() : $breakEnd;

                if ($overlapEnd->gt($overlapStart)) {
                    $breakMinutes += $overlapStart->diffInMinutes($overlapEnd);
                }
            }

            $day->addDay();
        }

        return max(0, $grossMinutes - $breakMinutes);
    }

    public function destroyEntry(int $entry)
    {
        SqlServerDb::transaction(function () use ($entry) {
            $entryRow = SqlServerDb::table('grating_daily_entries')->where('id', $entry)->first();
            if (!$entryRow) {
                return;
            }

            $this->logDeleteSnapshot('grating_daily_entries', $entryRow, $entry);

            SqlServerDb::table('grating_entry_employees')
                ->where('entry_id', $entry)
                ->get()
                ->each(fn($row) => $this->logDeleteSnapshot('grating_entry_employees', $row, $entry));

            SqlServerDb::table('grating_entry_steps')
                ->where('entry_id', $entry)
                ->get()
                ->each(fn($row) => $this->logDeleteSnapshot('grating_entry_steps', $row, $entry));

            if ($this->hasFieldMfgTable()) {
                SqlServerDb::table('grating_entry_field_mfgs')
                    ->where('entry_id', $entry)
                    ->get()
                    ->each(fn($row) => $this->logDeleteSnapshot('grating_entry_field_mfgs', $row, $entry));

                SqlServerDb::table('grating_entry_field_mfgs')->where('entry_id', $entry)->delete();
            }

            SqlServerDb::table('grating_daily_entries')->where('id', $entry)->delete();
        });

        return back()->with('success', 'ยกเลิกรายการแล้ว');
    }

    public function storeEmployee(Request $request)
    {
        $validated = $request->validate($this->employeeRules([
            'employee_code' => ['nullable', 'string', 'max:40', Rule::unique(SqlServerDb::qualifyTable('grating_employees'), 'employee_code')],
        ]));
        $this->validateStudentContract($validated);

        SqlServerDb::table('grating_employees')->insert($this->employeePayload($validated));

        return back()->with('success', 'Saved employee master.');
    }

    public function updateEmployee(Request $request, int $employee)
    {
        $validated = $request->validate($this->employeeRules([
            'employee_code' => ['nullable', 'string', 'max:40', Rule::unique(SqlServerDb::qualifyTable('grating_employees'), 'employee_code')->ignore($employee)],
        ]));
        $this->validateStudentContract($validated);

        SqlServerDb::table('grating_employees')->where('id', $employee)->update($this->employeePayload($validated, true));

        return back()->with('success', 'Updated employee master.');
    }

    public function destroyEmployee(int $employee)
    {
        SqlServerDb::transaction(function () use ($employee) {
            $employeeRow = SqlServerDb::table('grating_employees')->where('id', $employee)->first();
            if ($employeeRow) {
                $this->logDeleteSnapshot('grating_employees', $employeeRow);
            }

            SqlServerDb::table('grating_entry_employees')
                ->where('employee_id', $employee)
                ->get()
                ->each(fn($row) => $this->logDeleteSnapshot('grating_entry_employees', $row, $row->entry_id ?? null));

            SqlServerDb::table('grating_entry_employees')->where('employee_id', $employee)->delete();
            SqlServerDb::table('grating_employees')->where('id', $employee)->delete();
        });

        return back()->with('success', 'Deleted employee master.');
    }

    public function storeStep(Request $request)
    {
        $validated = $request->validate($this->stepRules());

        SqlServerDb::table('grating_steps')->insert($this->stepPayload($validated));

        return back()->with('success', 'Saved step master.');
    }

    public function seedDefaultSteps()
    {
        if (!$this->hasRequiredTables()) {
            return back()->withErrors(['steps' => 'ยังไม่พบตาราง Grating Performance กรุณารัน migration ก่อน']);
        }

        $now = now();
        foreach (self::DEFAULT_MASTER_STEPS as $step) {
            $existing = SqlServerDb::table('grating_steps')->where('step_code', $step['step_code'])->first();
            $payload = array_merge($step, [
                    'active' => true,
                    'updated_at' => $now,
                ]);

            if ($existing) {
                SqlServerDb::table('grating_steps')
                    ->where('id', $existing->id)
                    ->update($this->withAudit('grating_steps', $payload, true));
            } else {
                SqlServerDb::table('grating_steps')
                    ->insert($this->withAudit('grating_steps', array_merge($payload, ['created_at' => $now])));
            }
        }

        return back()->with('success', 'เติม Master Step เป็น checklist แล้ว');
    }

    public function updateStep(Request $request, int $step)
    {
        $rules = $this->stepRules();
        $rules['step_code'] = ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/', Rule::unique(SqlServerDb::qualifyTable('grating_steps'), 'step_code')->ignore($step)];
        $validated = $request->validate($rules);

        SqlServerDb::table('grating_steps')->where('id', $step)->update($this->stepPayload($validated, true));

        return back()->with('success', 'Updated step master.');
    }

    public function storeFieldActivity(Request $request)
    {
        $validated = $request->validate($this->fieldActivityRules());

        SqlServerDb::table('grating_field_activities')->insert($this->fieldActivityPayload($validated));

        return back()->with('success', 'Saved field activity master.');
    }

    public function updateFieldActivity(Request $request, int $activity)
    {
        $rules = $this->fieldActivityRules();
        $rules['activity_code'] = ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/', Rule::unique(SqlServerDb::qualifyTable('grating_field_activities'), 'activity_code')->ignore($activity)];
        $validated = $request->validate($rules);

        SqlServerDb::table('grating_field_activities')->where('id', $activity)->update($this->fieldActivityPayload($validated, true));

        return back()->with('success', 'Updated field activity master.');
    }

    public function storeProject(Request $request)
    {
        $validated = $request->validate($this->projectRules());

        SqlServerDb::table('grating_projects')->insert($this->projectPayload($validated));

        return back()->with('success', 'Saved project master.');
    }

    public function updateProject(Request $request, int $project)
    {
        $rules = $this->projectRules();
        $rules['project_name'] = ['required', 'string', 'max:500', Rule::unique(SqlServerDb::qualifyTable('grating_projects'), 'project_name')->ignore($project)];
        $validated = $request->validate($rules);

        SqlServerDb::table('grating_projects')->where('id', $project)->update($this->projectPayload($validated, true));

        return back()->with('success', 'Updated project master.');
    }

    private function filters(Request $request): array
    {
        $period = in_array($request->query('period'), ['day', 'week', 'month'], true)
            ? $request->query('period')
            : 'day';
        $viewMode = in_array($request->query('view_mode'), ['transactions', 'employee', 'mfg'], true)
            ? $request->query('view_mode')
            : 'transactions';
        $today = now('Asia/Bangkok');
        $defaultFrom = match ($period) {
            'week' => $today->copy()->startOfWeek()->toDateString(),
            'month' => $today->copy()->startOfMonth()->toDateString(),
            default => $today->toDateString(),
        };
        $defaultTo = match ($period) {
            'week' => $today->copy()->endOfWeek()->toDateString(),
            'month' => $today->copy()->endOfMonth()->toDateString(),
            default => $today->toDateString(),
        };

        return [
            'date_from' => $request->query('date_from', $defaultFrom),
            'date_to' => $request->query('date_to', $defaultTo),
            'mfg_no' => trim((string) $request->query('mfg_no', '')),
            'project' => trim((string) $request->query('project', '')),
            'salesorder' => trim((string) $request->query('salesorder', '')),
            'step_id' => $request->query('step_id'),
            'employee_id' => $request->query('employee_id'),
            'period' => $period,
            'view_mode' => $viewMode,
        ];
    }

    /**
     * เป้ายอดขาย D8 / FG GRATING — ล็อกไว้ที่ 1.7M "ต่อเดือน" ไม่หารเฉลี่ยรายวัน/รายสัปดาห์
     * ถ้าช่วงวันอยู่ในเดือนเดียว = 1.7M เต็มเสมอ
     * ถ้าข้ามเดือน = นับทุกเดือนที่ช่วงวันคาบเกี่ยว เดือนละ 1.7M เต็ม
     */
    private function salesD8TargetForRange(?string $dateFrom, ?string $dateTo): float
    {
        return self::D8_FG_GRATING_MONTHLY_TARGET * $this->monthsInRange($dateFrom, $dateTo);
    }

    /** จำนวนเดือนตามปฏิทินที่ช่วงวันคาบเกี่ยว (ข้ามเดือน = นับทุกเดือนที่แตะ) */
    private function monthsInRange(?string $dateFrom, ?string $dateTo): int
    {
        if (!$dateFrom || !$dateTo) {
            return 0;
        }

        $from = Carbon::parse($dateFrom)->startOfMonth();
        $to = Carbon::parse($dateTo)->startOfMonth();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        return (int) $from->diffInMonths($to) + 1;
    }

    private function entryBase(array $filters)
    {
        $query = SqlServerDb::table('grating_daily_entries as e')
            ->join('grating_steps as s', 's.id', '=', 'e.step_id')
            ->when($filters['date_from'] ?? null, fn($q, $date) => $q->where('e.work_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn($q, $date) => $q->where('e.work_date', '<=', $date))
            ->when($filters['mfg_no'] ?? '', function ($q, $mfg) {
                $q->where(function ($subQuery) use ($mfg) {
                    $subQuery->where('e.mfg_no', 'like', '%' . $mfg . '%');

                    if ($this->hasFieldMfgTable()) {
                        $subQuery->orWhereExists(function ($exists) use ($mfg) {
                            $exists->select(DB::raw(1))
                                ->from('grating_entry_field_mfgs as gefm')
                                ->whereColumn('gefm.entry_id', 'e.id')
                                ->where('gefm.mfg_no', 'like', '%' . $mfg . '%');
                        });
                    }
                });
            })
            ->when($filters['step_id'] ?? null, function ($q, $stepId) {
                $q->whereExists(function ($sub) use ($stepId) {
                    $sub->select(DB::raw(1))
                        ->from('grating_entry_steps as fes')
                        ->whereColumn('fes.entry_id', 'e.id')
                        ->where('fes.step_id', $stepId);
                });
            })
            ->when($filters['employee_id'] ?? null, function ($q, $employeeId) {
                $q->whereExists(function ($sub) use ($employeeId) {
                    $sub->select(DB::raw(1))
                        ->from('grating_entry_employees as fee')
                        ->whereColumn('fee.entry_id', 'e.id')
                        ->where('fee.employee_id', $employeeId);
                });
            });

        if ($this->hasEntryColumn('project')) {
            $query->when($filters['project'] ?? '', fn($q, $project) => $q->where('e.project', 'like', '%' . $project . '%'));
        }

        if ($this->hasEntryColumn('salesorder')) {
            $query->when($filters['salesorder'] ?? '', fn($q, $salesorder) => $q->where('e.salesorder', 'like', '%' . $salesorder . '%'));
        }

        return $query;
    }

    private function employeeSummary(array $filters)
    {
        return $this->entryBase($filters)
            ->join('grating_entry_employees as gee', 'gee.entry_id', '=', 'e.id')
            ->join('grating_employees as ge', 'ge.id', '=', 'gee.employee_id')
            ->select([
                'ge.id',
                'ge.name',
                'ge.responsible_work',
                DB::raw('COUNT(DISTINCT e.id) as entry_count'),
                DB::raw('COUNT(DISTINCT e.mfg_no) as mfg_count'),
                DB::raw('SUM(e.good_qty_kg) as good_kg'),
                DB::raw('SUM(e.good_qty_pcs) as good_pcs'),
                DB::raw('SUM(e.bad_qty_kg) as bad_kg'),
                DB::raw('SUM(e.bad_qty_pcs) as bad_pcs'),
                DB::raw('SUM(COALESCE(e.duration_minutes, 0)) as minutes'),
            ])
            ->groupBy('ge.id', 'ge.name', 'ge.responsible_work')
            ->orderByDesc('good_pcs')
            ->limit(20)
            ->get();
    }

    private function employeeStepEfficiency(array $filters)
    {
        return $this->entryBase($filters)
            ->join('grating_entry_employees as gee', 'gee.entry_id', '=', 'e.id')
            ->join('grating_employees as ge', 'ge.id', '=', 'gee.employee_id')
            ->join('grating_steps as gs2', 'gs2.id', '=', 'e.step_id')
            ->select([
                'ge.id as employee_id',
                'ge.name as employee_name',
                'gs2.step_code',
                'gs2.step_name',
                DB::raw('SUM(COALESCE(e.duration_minutes, 0)) as minutes'),
                DB::raw('SUM(e.good_qty_kg) as good_kg'),
                DB::raw('SUM(e.good_qty_pcs) as good_pcs'),
                DB::raw('SUM(e.bad_qty_kg) as bad_kg'),
                DB::raw('SUM(e.bad_qty_pcs) as bad_pcs'),
                DB::raw('COUNT(DISTINCT e.id) as entry_count'),
            ])
            ->where('gs2.is_field_work', false)
            ->groupBy('ge.id', 'ge.name', 'gs2.step_code', 'gs2.step_name')
            ->orderBy('ge.name')
            ->orderBy('gs2.step_code')
            ->get()
            ->map(function ($row) {
                $hours = ((float) $row->minutes) / 60;
                $row->hours = $hours;
                $row->kg_per_hour = $hours > 0 ? round((float) $row->good_kg / $hours, 1) : null;
                $row->pcs_per_hour = $hours > 0 ? round((float) $row->good_pcs / $hours, 1) : null;
                return $row;
            });
    }

    private function inquirySummary(array $filters): array
    {
        $row = $this->entryBase($filters)
            ->select([
                DB::raw('COUNT(DISTINCT e.id) as entry_count'),
                DB::raw("COUNT(DISTINCT CASE WHEN e.mfg_no <> 'FIELD' THEN e.mfg_no END) as mfg_count"),
                DB::raw('SUM(COALESCE(e.good_qty_pcs, 0)) as good_pcs'),
                DB::raw('SUM(COALESCE(e.good_qty_kg, 0)) as good_kg'),
                DB::raw('SUM(COALESCE(e.bad_qty_pcs, 0)) as bad_pcs'),
                DB::raw('SUM(COALESCE(e.bad_qty_kg, 0)) as bad_kg'),
                DB::raw('SUM(CASE WHEN e.is_finished = 1 THEN 1 ELSE 0 END) as finished_count'),
                DB::raw('SUM(CASE WHEN e.is_finished = 1 THEN 0 ELSE 1 END) as open_count'),
            ])
            ->first();

        return [
            'entry_count' => (int) ($row->entry_count ?? 0),
            'mfg_count' => (int) ($row->mfg_count ?? 0),
            'good_pcs' => (float) ($row->good_pcs ?? 0),
            'good_kg' => (float) ($row->good_kg ?? 0),
            'bad_pcs' => (float) ($row->bad_pcs ?? 0),
            'bad_kg' => (float) ($row->bad_kg ?? 0),
            'finished_count' => (int) ($row->finished_count ?? 0),
            'open_count' => (int) ($row->open_count ?? 0),
        ];
    }

    private function workloadByDay(array $filters)
    {
        return $this->entryBase($filters)
            ->join('grating_entry_employees as gee', 'gee.entry_id', '=', 'e.id')
            ->join('grating_employees as ge', 'ge.id', '=', 'gee.employee_id')
            ->select([
                'ge.id as employee_id',
                'ge.name as employee_name',
                DB::raw('CONVERT(varchar(10), e.work_date, 120) as work_day'),
                DB::raw('SUM(COALESCE(e.duration_minutes, 0)) as minutes'),
                DB::raw('COUNT(DISTINCT e.id) as entry_count'),
            ])
            ->groupBy('ge.id', 'ge.name', DB::raw('CONVERT(varchar(10), e.work_date, 120)'))
            ->orderBy('work_day')
            ->orderBy('ge.name')
            ->get()
            ->map(function ($row) {
                $row->hours = round(((float) $row->minutes) / 60, 1);
                return $row;
            });
    }

    private function employeeDailyInquiry(array $filters)
    {
        $teamSize = $this->teamSizeSubquery();
        $stepRollup = $this->stepRollupSubquery();
        $projectExpr = $this->hasEntryColumn('project') ? 'e.project' : 'CAST(NULL AS NVARCHAR(MAX))';

        return $this->entryBase($filters)
            ->join('grating_entry_employees as gee', 'gee.entry_id', '=', 'e.id')
            ->join('grating_employees as ge', 'ge.id', '=', 'gee.employee_id')
            ->leftJoinSub($teamSize, 'ts', 'ts.entry_id', '=', 'e.id')
            ->leftJoinSub($stepRollup, 'sr_daily', 'sr_daily.entry_id', '=', 'e.id')
            ->select([
                'ge.id as employee_id',
                'ge.name as employee_name',
                DB::raw('CONVERT(varchar(10), e.work_date, 120) as work_day'),
                DB::raw('COUNT(DISTINCT e.id) as entry_count'),
                DB::raw("STRING_AGG(CAST(CONCAT(CASE WHEN e.is_field_work = 1 THEN COALESCE(NULLIF($projectExpr, ''), N'FIELD') ELSE e.mfg_no END, N' / ', COALESCE(sr_daily.step_names, s.step_name), CASE WHEN e.is_field_work = 1 AND e.field_activity IS NOT NULL THEN CONCAT(N' / ', e.field_activity) ELSE N'' END) AS NVARCHAR(MAX)), N' | ') as work_summary"),
                DB::raw('SUM(COALESCE(e.duration_minutes, 0)) as minutes'),
                DB::raw('SUM(COALESCE(e.good_qty_pcs, 0) / NULLIF(COALESCE(ts.team_size, 1), 0)) as allocated_good_pcs'),
                DB::raw('SUM(COALESCE(e.bad_qty_pcs, 0) / NULLIF(COALESCE(ts.team_size, 1), 0)) as allocated_bad_pcs'),
                DB::raw('SUM(COALESCE(e.good_qty_kg, 0) / NULLIF(COALESCE(ts.team_size, 1), 0)) as allocated_good_kg'),
            ])
            ->groupBy('ge.id', 'ge.name', DB::raw('CONVERT(varchar(10), e.work_date, 120)'))
            ->orderBy('ge.name')
            ->orderBy('work_day')
            ->limit(120)
            ->get();
    }

    private function mfgFlowInquiry(array $filters)
    {
        $teamSize = $this->teamSizeSubquery();
        $projectExpr = $this->hasEntryColumn('project') ? 'MAX(e.project)' : 'CAST(NULL AS NVARCHAR(MAX))';
        $salesorderExpr = $this->hasEntryColumn('salesorder') ? 'MAX(e.salesorder)' : 'CAST(NULL AS NVARCHAR(MAX))';
        $planQtyExpr = $this->hasEntryColumn('plan_qty_pcs') ? 'MAX(e.plan_qty_pcs)' : 'CAST(NULL AS decimal(18, 3))';

        return $this->entryBase($filters)
            ->leftJoin('grating_entry_steps as ges_flow', 'ges_flow.entry_id', '=', 'e.id')
            ->join('grating_steps as flow_step', 'flow_step.id', '=', DB::raw('COALESCE(ges_flow.step_id, e.step_id)'))
            ->join('grating_entry_employees as gee', 'gee.entry_id', '=', 'e.id')
            ->join('grating_employees as ge', 'ge.id', '=', 'gee.employee_id')
            ->leftJoinSub($teamSize, 'ts', 'ts.entry_id', '=', 'e.id')
            ->where('e.mfg_no', '<>', 'FIELD')
            ->select([
                'e.mfg_no',
                DB::raw($projectExpr . ' as project'),
                DB::raw($salesorderExpr . ' as salesorder'),
                DB::raw($planQtyExpr . ' as plan_qty_pcs'),
                'flow_step.id as step_id',
                'flow_step.step_code',
                'flow_step.step_name',
                'flow_step.sort_order',
                'ge.id as employee_id',
                'ge.name as employee_name',
                DB::raw('COUNT(DISTINCT e.id) as entry_count'),
                DB::raw('SUM(COALESCE(e.duration_minutes, 0)) as minutes'),
                DB::raw('SUM(COALESCE(e.good_qty_pcs, 0) / NULLIF(COALESCE(ts.team_size, 1), 0)) as allocated_good_pcs'),
                DB::raw('SUM(COALESCE(e.bad_qty_pcs, 0) / NULLIF(COALESCE(ts.team_size, 1), 0)) as allocated_bad_pcs'),
                DB::raw('SUM(COALESCE(e.good_qty_kg, 0) / NULLIF(COALESCE(ts.team_size, 1), 0)) as allocated_good_kg'),
                DB::raw('MAX(CAST(e.is_finished AS int)) as finished'),
                DB::raw('MAX(e.work_date) as last_work_date'),
            ])
            ->groupBy('e.mfg_no', 'flow_step.id', 'flow_step.step_code', 'flow_step.step_name', 'flow_step.sort_order', 'ge.id', 'ge.name')
            ->orderBy('e.mfg_no')
            ->orderBy('flow_step.sort_order')
            ->orderBy('flow_step.step_code')
            ->orderBy('ge.name')
            ->limit(240)
            ->get();
    }

    private function employeeTransactionDetails(array $filters)
    {
        $teamSize = $this->teamSizeSubquery();
        $stepRollup = $this->stepRollupSubquery();
        $fieldMfgRollup = $this->fieldMfgRollupSubquery();
        $projectExpr = $this->hasEntryColumn('project') ? 'e.project' : 'CAST(NULL AS NVARCHAR(MAX))';
        $salesorderExpr = $this->hasEntryColumn('salesorder') ? 'e.salesorder' : 'CAST(NULL AS NVARCHAR(MAX))';

        return $this->entryBase($filters)
            ->join('grating_entry_employees as gee_detail', 'gee_detail.entry_id', '=', 'e.id')
            ->join('grating_employees as ge_detail', 'ge_detail.id', '=', 'gee_detail.employee_id')
            ->leftJoinSub($teamSize, 'ts_detail', 'ts_detail.entry_id', '=', 'e.id')
            ->leftJoinSub($stepRollup, 'sr_detail', 'sr_detail.entry_id', '=', 'e.id')
            ->leftJoinSub($fieldMfgRollup, 'fmr_detail', 'fmr_detail.entry_id', '=', 'e.id')
            ->select([
                'e.id',
                'e.work_date',
                'e.mfg_no',
                DB::raw($projectExpr . ' as project'),
                DB::raw($salesorderExpr . ' as salesorder'),
                'e.started_at',
                'e.finished_at',
                'e.duration_minutes',
                'e.good_qty_pcs',
                'e.bad_qty_pcs',
                'e.good_qty_kg',
                'e.bad_qty_kg',
                'e.is_field_work',
                'e.field_activity',
                'e.notes',
                'ge_detail.id as detail_employee_id',
                'ge_detail.name as detail_employee_name',
                DB::raw('CONVERT(varchar(10), e.work_date, 120) as work_day'),
                DB::raw("COALESCE(sr_detail.step_names, s.step_name) as step_name"),
                DB::raw("COALESCE(fmr_detail.field_mfgs, '') as field_mfgs"),
                DB::raw('COALESCE(ts_detail.team_size, 1) as team_size'),
                DB::raw('COALESCE(e.good_qty_pcs, 0) / NULLIF(COALESCE(ts_detail.team_size, 1), 0) as allocated_good_pcs'),
                DB::raw('COALESCE(e.bad_qty_pcs, 0) / NULLIF(COALESCE(ts_detail.team_size, 1), 0) as allocated_bad_pcs'),
            ])
            ->orderBy('ge_detail.name')
            ->orderBy('e.work_date')
            ->orderBy('e.started_at')
            ->limit(500)
            ->get();
    }

    private function mfgTransactionDetails(array $filters)
    {
        $employeeRollup = $this->employeeRollupSubquery();
        $projectExpr = $this->hasEntryColumn('project') ? 'e.project' : 'CAST(NULL AS NVARCHAR(MAX))';
        $salesorderExpr = $this->hasEntryColumn('salesorder') ? 'e.salesorder' : 'CAST(NULL AS NVARCHAR(MAX))';

        return $this->entryBase($filters)
            ->leftJoin('grating_entry_steps as ges_detail', 'ges_detail.entry_id', '=', 'e.id')
            ->join('grating_steps as detail_step', 'detail_step.id', '=', DB::raw('COALESCE(ges_detail.step_id, e.step_id)'))
            ->leftJoinSub($employeeRollup, 'er_detail', 'er_detail.entry_id', '=', 'e.id')
            ->where('e.mfg_no', '<>', 'FIELD')
            ->select([
                'e.id',
                'e.work_date',
                'e.mfg_no',
                DB::raw($projectExpr . ' as project'),
                DB::raw($salesorderExpr . ' as salesorder'),
                'e.started_at',
                'e.finished_at',
                'e.duration_minutes',
                'e.good_qty_pcs',
                'e.bad_qty_pcs',
                'e.good_qty_kg',
                'e.bad_qty_kg',
                'e.notes',
                'detail_step.id as step_id',
                'detail_step.step_code',
                'detail_step.step_name',
                DB::raw("COALESCE(er_detail.employee_names, '') as employee_names"),
                DB::raw('COALESCE(er_detail.team_size, 0) as team_size'),
            ])
            ->orderBy('e.mfg_no')
            ->orderBy('detail_step.sort_order')
            ->orderBy('detail_step.step_code')
            ->orderBy('e.work_date')
            ->orderBy('e.started_at')
            ->limit(500)
            ->get();
    }

    private function mfgOutputTotals(array $filters): array
    {
        $rows = $this->entryBase($filters)
            ->where('e.mfg_no', '<>', 'FIELD')
            ->select([
                'e.mfg_no',
                DB::raw('COALESCE(MAX(CASE WHEN e.is_finished = 1 THEN e.good_qty_kg END), MAX(e.good_qty_kg), 0) as good_kg'),
                DB::raw('COALESCE(MAX(CASE WHEN e.is_finished = 1 THEN e.good_qty_pcs END), MAX(e.good_qty_pcs), 0) as good_pcs'),
                DB::raw('COALESCE(MAX(CASE WHEN e.is_finished = 1 THEN e.good_area_sqm END), MAX(e.good_area_sqm), 0) as good_area_sqm'),
            ])
            ->groupBy('e.mfg_no');

        $totals = SqlServerDb::connection()
            ->query()
            ->fromSub($rows, 'mfg_output')
            ->selectRaw('SUM(good_kg) as good_kg, SUM(good_pcs) as good_pcs, SUM(good_area_sqm) as good_area_sqm')
            ->first();

        return [
            'good_kg' => (float) ($totals->good_kg ?? 0),
            'good_pcs' => (float) ($totals->good_pcs ?? 0),
            'good_area_sqm' => (float) ($totals->good_area_sqm ?? 0),
        ];
    }

    private function mfgSummary(array $filters)
    {
        $rows = $this->entryBase($filters)
            ->select([
                'e.mfg_no',
                DB::raw('COUNT(DISTINCT e.id) as entry_count'),
                DB::raw('COUNT(DISTINCT e.step_id) as step_count'),
                DB::raw('COALESCE(MAX(CASE WHEN e.is_finished = 1 THEN e.good_qty_kg END), MAX(e.good_qty_kg), 0) as good_kg'),
                DB::raw('COALESCE(MAX(CASE WHEN e.is_finished = 1 THEN e.good_qty_pcs END), MAX(e.good_qty_pcs), 0) as good_pcs'),
                DB::raw('COALESCE(MAX(CASE WHEN e.is_finished = 1 THEN e.good_area_sqm END), MAX(e.good_area_sqm), 0) as good_area_sqm'),
                DB::raw('SUM(e.good_qty_kg) as step_good_kg'),
                DB::raw('SUM(e.good_qty_pcs) as step_good_pcs'),
                DB::raw('SUM(e.good_area_sqm) as step_good_area_sqm'),
                DB::raw('SUM(e.bad_qty_kg) as bad_kg'),
                DB::raw('SUM(e.bad_qty_pcs) as bad_pcs'),
                DB::raw('SUM(e.bad_area_sqm) as bad_area_sqm'),
                DB::raw('SUM(COALESCE(e.duration_minutes, 0)) as minutes'),
                DB::raw('MAX(CAST(e.is_finished AS int)) as finished'),
            ])
            ->where('e.mfg_no', '<>', 'FIELD')
            ->groupBy('e.mfg_no')
            ->orderByDesc('good_kg')
            ->limit(20)
            ->get();

        if ($rows->isEmpty()) {
            return $rows;
        }

        $peopleCounts = $this->entryBase($filters)
            ->join('grating_entry_employees as gee', 'gee.entry_id', '=', 'e.id')
            ->whereIn('e.mfg_no', $rows->pluck('mfg_no')->all())
            ->select('e.mfg_no', DB::raw('COUNT(DISTINCT gee.employee_id) as people_count'))
            ->groupBy('e.mfg_no')
            ->pluck('people_count', 'mfg_no');

        $stepCounts = $this->entryBase($filters)
            ->leftJoin('grating_entry_steps as ges', 'ges.entry_id', '=', 'e.id')
            ->whereIn('e.mfg_no', $rows->pluck('mfg_no')->all())
            ->select('e.mfg_no', DB::raw('COUNT(DISTINCT COALESCE(ges.step_id, e.step_id)) as step_count'))
            ->groupBy('e.mfg_no')
            ->pluck('step_count', 'mfg_no');

        return $rows->map(function ($row) use ($peopleCounts, $stepCounts) {
            $row->people_count = (int) ($peopleCounts[$row->mfg_no] ?? 0);
            $row->step_count = (int) ($stepCounts[$row->mfg_no] ?? $row->step_count);
            return $row;
        });
    }

    private function mfgStepPeopleSummary(array $filters)
    {
        $outputRows = $this->entryBase($filters)
            ->leftJoin('grating_entry_steps as ges', 'ges.entry_id', '=', 'e.id')
            ->join('grating_steps as ss', 'ss.id', '=', DB::raw('COALESCE(ges.step_id, e.step_id)'))
            ->where('e.mfg_no', '<>', 'FIELD')
            ->select([
                'e.mfg_no',
                'ss.id as step_id',
                'ss.step_code',
                'ss.step_name',
                DB::raw('SUM(e.good_qty_pcs) as good_pcs'),
                DB::raw('SUM(e.good_qty_kg) as good_kg'),
                DB::raw('SUM(COALESCE(e.duration_minutes, 0)) as minutes'),
                DB::raw('MAX(e.work_date) as last_work_date'),
            ])
            ->groupBy('e.mfg_no', 'ss.id', 'ss.step_code', 'ss.step_name');

        $peopleRows = $this->entryBase($filters)
            ->leftJoin('grating_entry_steps as ges', 'ges.entry_id', '=', 'e.id')
            ->join('grating_steps as ss', 'ss.id', '=', DB::raw('COALESCE(ges.step_id, e.step_id)'))
            ->join('grating_entry_employees as gee', 'gee.entry_id', '=', 'e.id')
            ->join('grating_employees as ge', 'ge.id', '=', 'gee.employee_id')
            ->where('e.mfg_no', '<>', 'FIELD')
            ->select([
                'e.mfg_no',
                'ss.id as step_id',
                DB::raw("STRING_AGG(CAST(ge.name AS NVARCHAR(MAX)), N', ') as employee_names"),
            ])
            ->groupBy('e.mfg_no', 'ss.id');

        return SqlServerDb::connection()
            ->query()
            ->fromSub($outputRows, 'ms')
            ->leftJoinSub($peopleRows, 'mp', function ($join) {
                $join->on('mp.mfg_no', '=', 'ms.mfg_no')
                    ->on('mp.step_id', '=', 'ms.step_id');
            })
            ->select([
                'ms.mfg_no',
                'ms.step_code',
                'ms.step_name',
                DB::raw("COALESCE(mp.employee_names, '') as employee_names"),
                'ms.good_pcs',
                'ms.good_kg',
                'ms.minutes',
                'ms.last_work_date',
            ])
            ->orderByDesc('ms.last_work_date')
            ->orderBy('ms.mfg_no')
            ->orderBy('ms.step_code')
            ->limit(80)
            ->get();
    }

    private function mfgIncompleteSummary(array $filters)
    {
        $projectExpr = $this->hasEntryColumn('project') ? 'MAX(e.project)' : 'CAST(NULL AS NVARCHAR(MAX))';
        $salesorderExpr = $this->hasEntryColumn('salesorder') ? 'MAX(e.salesorder)' : 'CAST(NULL AS NVARCHAR(MAX))';
        $planQtyExpr = $this->hasEntryColumn('plan_qty_pcs') ? 'MAX(e.plan_qty_pcs)' : 'CAST(NULL AS decimal(18, 3))';

        return $this->entryBase($filters)
            ->leftJoin('grating_entry_steps as ges', 'ges.entry_id', '=', 'e.id')
            ->join('grating_steps as ss', 'ss.id', '=', DB::raw('COALESCE(ges.step_id, e.step_id)'))
            ->where('e.mfg_no', '<>', 'FIELD')
            ->select([
                'e.mfg_no',
                DB::raw($projectExpr . ' as project'),
                DB::raw($salesorderExpr . ' as salesorder'),
                DB::raw($planQtyExpr . ' as plan_qty_pcs'),
                DB::raw("STRING_AGG(CAST(ss.step_name AS NVARCHAR(MAX)), N', ') as step_names"),
                DB::raw("STRING_AGG(CAST(ss.step_code AS NVARCHAR(MAX)), N', ') as step_codes"),
                DB::raw('SUM(e.good_qty_pcs) as good_pcs'),
                DB::raw('SUM(e.bad_qty_pcs) as bad_pcs'),
                DB::raw('SUM(COALESCE(e.duration_minutes, 0)) as minutes'),
                DB::raw('MAX(CASE WHEN ss.step_code = N\'PACK\' THEN 1 ELSE 0 END) as has_pack'),
                DB::raw('MAX(CAST(e.is_finished AS int)) as finished'),
                DB::raw('MAX(e.work_date) as last_work_date'),
            ])
            ->groupBy('e.mfg_no')
            ->havingRaw("MAX(CASE WHEN ss.step_code = N'PACK' THEN 1 ELSE 0 END) = 0 OR MAX(CAST(e.is_finished AS int)) = 0")
            ->orderByDesc('last_work_date')
            ->limit(30)
            ->get();
    }

    private function stepSummary(array $filters)
    {
        $range = $this->rangeAverages($filters['date_from'] ?? null, $filters['date_to'] ?? null);
        $hasTargetPcs = $this->hasStepColumn('target_pcs_per_hour');
        $targetPcsExpr = $hasTargetPcs ? 'ss.target_pcs_per_hour' : 'CAST(NULL AS decimal(12, 3))';
        $groupBy = ['ss.id', 'ss.step_code', 'ss.step_name', 'ss.is_field_work', 'ss.target_kg_per_hour'];
        if ($hasTargetPcs) {
            $groupBy[] = 'ss.target_pcs_per_hour';
        }

        return $this->entryBase($filters)
            ->leftJoin('grating_entry_steps as ges', 'ges.entry_id', '=', 'e.id')
            ->join('grating_steps as ss', 'ss.id', '=', DB::raw('COALESCE(ges.step_id, e.step_id)'))
            ->select([
                'ss.id',
                'ss.step_code',
                'ss.step_name',
                'ss.is_field_work',
                'ss.target_kg_per_hour',
                DB::raw($targetPcsExpr . ' as target_pcs_per_hour'),
                DB::raw('COUNT(DISTINCT e.id) as entry_count'),
                DB::raw("COUNT(DISTINCT CASE WHEN e.mfg_no <> 'FIELD' THEN e.mfg_no END) as mfg_count"),
                DB::raw('SUM(e.good_qty_kg) as good_kg'),
                DB::raw('SUM(e.good_qty_pcs) as good_pcs'),
                DB::raw('SUM(e.good_area_sqm) as good_area_sqm'),
                DB::raw('SUM(e.bad_qty_kg) as bad_kg'),
                DB::raw('SUM(e.bad_qty_pcs) as bad_pcs'),
                DB::raw('SUM(e.bad_area_sqm) as bad_area_sqm'),
                DB::raw('SUM(COALESCE(e.duration_minutes, 0)) as minutes'),
                DB::raw('SUM(COALESCE(e.duration_minutes, 0) / 60.0 * COALESCE(ss.target_kg_per_hour, 0)) as target_kg'),
                DB::raw('SUM(COALESCE(e.duration_minutes, 0) / 60.0 * COALESCE(' . $targetPcsExpr . ', 0)) as target_pcs'),
            ])
            ->groupBy($groupBy)
            ->orderBy('ss.step_code')
            ->get()
            ->map(function ($row) use ($range) {
                $totalHours = ((float) ($row->minutes ?? 0)) / 60;
                $row->total_hours = $totalHours;
                $row->avg_hours_per_day = $totalHours / $range['days'];
                $row->avg_hours_per_week = $totalHours / $range['weeks'];

                return $row;
            });
    }

    private function rangeAverages(?string $dateFrom, ?string $dateTo): array
    {
        if (!$dateFrom || !$dateTo) {
            return ['days' => 1, 'weeks' => 1];
        }

        $from = Carbon::parse($dateFrom)->startOfDay();
        $to = Carbon::parse($dateTo)->startOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        $days = max(1, $from->diffInDays($to) + 1);

        return [
            'days' => $days,
            'weeks' => max(1, (int) ceil($days / 7)),
        ];
    }

    private function slowStepSummary($stepSummary)
    {
        return $stepSummary
            ->filter(fn ($row) => (float) ($row->total_hours ?? 0) > 0)
            ->map(function ($row) {
                $goodKg = (float) ($row->good_kg ?? 0);
                $goodPcs = (float) ($row->good_pcs ?? 0);
                $totalHours = (float) ($row->total_hours ?? 0);
                $targetKgPerHour = (float) ($row->target_kg_per_hour ?? 0);
                $targetPcsPerHour = (float) ($row->target_pcs_per_hour ?? 0);
                $isFieldWork = (bool) ($row->is_field_work ?? false);
                $mfgCount = (int) ($row->mfg_count ?? 0);
                $entryCount = (int) ($row->entry_count ?? 0);
                $workCount = max(1, $isFieldWork ? $entryCount : ($mfgCount ?: $entryCount));

                $row->actual_kg_per_hour = $totalHours > 0 ? $goodKg / $totalHours : 0;
                $row->actual_pcs_per_hour = $totalHours > 0 ? $goodPcs / $totalHours : 0;
                $row->hours_per_work = $totalHours / $workCount;
                $row->work_unit_label = $isFieldWork ? 'ชม./งาน' : 'ชม./MFG';
                $row->work_count = $workCount;
                $row->delay_percent = null;
                $row->slow_reason = $isFieldWork ? 'ชั่วโมงหน้างานสูง' : 'ชม./MFG สูง';

                if ($targetPcsPerHour > 0) {
                    $row->target_ratio = $row->actual_pcs_per_hour / $targetPcsPerHour;
                    if ($row->actual_pcs_per_hour < $targetPcsPerHour) {
                        $row->delay_percent = (1 - $row->target_ratio) * 100;
                        $row->slow_reason = 'ต่ำกว่า target ชิ้น';
                        $row->slow_score = 10000 + $row->delay_percent;
                    } else {
                        $row->slow_reason = 'ถึง target ชิ้น';
                        $row->slow_score = $row->hours_per_work;
                    }
                } elseif ($targetKgPerHour > 0) {
                    $row->target_ratio = $row->actual_kg_per_hour / $targetKgPerHour;
                    if ($row->actual_kg_per_hour < $targetKgPerHour) {
                        $row->delay_percent = (1 - $row->target_ratio) * 100;
                        $row->slow_reason = 'ต่ำกว่า target กก.';
                        $row->slow_score = 10000 + $row->delay_percent;
                    } else {
                        $row->slow_reason = 'ถึง target กก.';
                        $row->slow_score = $row->hours_per_work;
                    }
                } else {
                    $row->target_ratio = null;
                    $row->slow_score = $row->hours_per_work;
                }

                if (!$isFieldWork && $mfgCount < 3) {
                    $row->slow_score *= 0.35;
                    $row->slow_reason = 'ไม่มีช่วงเวลา';
                }

                return $row;
            })
            ->sortByDesc('slow_score')
            ->take(5)
            ->values();
    }

    private function periodSummary(array $filters)
    {
        $period = $filters['period'] ?? 'day';
        $periodSelect = match ($period) {
            'week' => "CONCAT(YEAR(e.work_date), '-W', RIGHT(CONCAT('0', DATEPART(ISO_WEEK, e.work_date)), 2))",
            'month' => "CONVERT(varchar(7), e.work_date, 120)",
            default => "CONVERT(varchar(10), e.work_date, 120)",
        };

        $periodRows = $this->entryBase($filters)
            ->select([
                DB::raw($periodSelect . ' as period_label'),
                DB::raw('MIN(e.work_date) as date_from'),
                DB::raw('MAX(e.work_date) as date_to'),
                DB::raw('COUNT(DISTINCT e.id) as entry_count'),
                DB::raw("COUNT(DISTINCT CASE WHEN e.mfg_no <> 'FIELD' THEN e.mfg_no END) as mfg_count"),
                DB::raw('SUM(e.bad_qty_kg) as bad_kg'),
                DB::raw('SUM(COALESCE(e.duration_minutes, 0)) as minutes'),
                DB::raw('SUM(CASE WHEN e.is_finished = 1 THEN 1 ELSE 0 END) as finished_count'),
            ])
            ->groupBy(DB::raw($periodSelect))
            ->orderBy('date_from')
            ->get();

        $periodOutput = $this->entryBase($filters)
            ->where('e.mfg_no', '<>', 'FIELD')
            ->select([
                DB::raw($periodSelect . ' as period_label'),
                'e.mfg_no',
                DB::raw('COALESCE(MAX(CASE WHEN e.is_finished = 1 THEN e.good_qty_kg END), MAX(e.good_qty_kg), 0) as good_kg'),
                DB::raw('COALESCE(MAX(CASE WHEN e.is_finished = 1 THEN e.good_qty_pcs END), MAX(e.good_qty_pcs), 0) as good_pcs'),
                DB::raw('COALESCE(MAX(CASE WHEN e.is_finished = 1 THEN e.good_area_sqm END), MAX(e.good_area_sqm), 0) as good_area_sqm'),
            ])
            ->groupBy(DB::raw($periodSelect), 'e.mfg_no');

        $outputByPeriod = SqlServerDb::connection()
            ->query()
            ->fromSub($periodOutput, 'period_output')
            ->select('period_label', DB::raw('SUM(good_kg) as good_kg'), DB::raw('SUM(good_pcs) as good_pcs'), DB::raw('SUM(good_area_sqm) as good_area_sqm'))
            ->groupBy('period_label')
            ->get()
            ->keyBy('period_label');

        return $periodRows->map(function ($row) use ($filters, $periodSelect, $outputByPeriod) {
                $row->people_count = (int) $this->entryBase($filters)
                    ->join('grating_entry_employees as gee', 'gee.entry_id', '=', 'e.id')
                    ->whereRaw($periodSelect . ' = ?', [$row->period_label])
                    ->distinct()
                    ->count('gee.employee_id');
                $output = $outputByPeriod[$row->period_label] ?? null;
                $row->good_kg = (float) ($output->good_kg ?? 0);
                $row->good_pcs = (float) ($output->good_pcs ?? 0);
                $row->good_area_sqm = (float) ($output->good_area_sqm ?? 0);

                return $row;
            });
    }

    private function mfgCycleSummary(array $filters)
    {
        $rows = $this->entryBase($filters)
            ->where('e.mfg_no', '<>', 'FIELD')
            ->select([
                'e.mfg_no',
                DB::raw('MIN(e.work_date) as first_date'),
                DB::raw('MAX(e.work_date) as last_date'),
                DB::raw('MIN(CASE WHEN e.is_finished = 1 THEN e.work_date END) as finished_date'),
                DB::raw('COUNT(DISTINCT e.id) as entry_count'),
                DB::raw('COUNT(DISTINCT e.step_id) as step_count'),
                DB::raw('SUM(COALESCE(e.duration_minutes, 0)) as minutes'),
                DB::raw('COALESCE(MAX(CASE WHEN e.is_finished = 1 THEN e.good_qty_kg END), MAX(e.good_qty_kg), 0) as good_kg'),
                DB::raw('COALESCE(MAX(CASE WHEN e.is_finished = 1 THEN e.good_qty_pcs END), MAX(e.good_qty_pcs), 0) as good_pcs'),
                DB::raw('COALESCE(MAX(CASE WHEN e.is_finished = 1 THEN e.good_area_sqm END), MAX(e.good_area_sqm), 0) as good_area_sqm'),
                DB::raw('SUM(e.good_qty_kg) as step_good_kg'),
                DB::raw('SUM(e.good_qty_pcs) as step_good_pcs'),
                DB::raw('SUM(e.good_area_sqm) as step_good_area_sqm'),
                DB::raw('MAX(CAST(e.is_finished AS int)) as finished'),
            ])
            ->groupBy('e.mfg_no')
            ->orderByDesc('last_date')
            ->limit(30)
            ->get();

        if ($rows->isEmpty()) {
            return $rows;
        }

        $peopleCounts = $this->entryBase($filters)
            ->join('grating_entry_employees as gee', 'gee.entry_id', '=', 'e.id')
            ->whereIn('e.mfg_no', $rows->pluck('mfg_no')->all())
            ->select('e.mfg_no', DB::raw('COUNT(DISTINCT gee.employee_id) as people_count'))
            ->groupBy('e.mfg_no')
            ->pluck('people_count', 'mfg_no');

        return $rows->map(function ($row) use ($peopleCounts) {
            $row->people_count = (int) ($peopleCounts[$row->mfg_no] ?? 0);
            return $row;
        });
    }

    private function recentEntries(array $filters)
    {
        $employeeRollup = $this->employeeRollupSubquery();
        $stepRollup = $this->stepRollupSubquery();

        return $this->entryBase($filters)
            ->select([
                'e.*',
                DB::raw("COALESCE(sr.step_names, s.step_name) as step_name"),
                DB::raw("COALESCE(er.employee_names, '') as employee_names"),
            ])
            ->leftJoinSub($employeeRollup, 'er', 'er.entry_id', '=', 'e.id')
            ->leftJoinSub($stepRollup, 'sr', 'sr.entry_id', '=', 'e.id')
            ->orderByDesc('e.started_at')
            ->limit(12)
            ->get();
    }

    private function employeeOptions(bool $activeOnly = true)
    {
        $this->deactivateExpiredStudentEmployees();

        return $this->tableWithAuditNames('grating_employees')
            ->when($activeOnly, fn($q) => $q->where('grating_employees.active', true))
            ->orderBy('grating_employees.active', 'desc')
            ->orderBy('grating_employees.name')
            ->get();
    }

    private function stepOptions(bool $activeOnly = true)
    {
        return $this->tableWithAuditNames('grating_steps')
            ->when($activeOnly, fn($q) => $q->where('grating_steps.active', true))
            ->orderBy('grating_steps.active', 'desc')
            ->orderBy('grating_steps.sort_order')
            ->orderBy('grating_steps.step_code')
            ->get();
    }

    private function fieldActivityOptions(bool $activeOnly = true)
    {
        return SqlServerDb::table('grating_field_activities')
            ->when($activeOnly, fn($q) => $q->where('active', true))
            ->orderBy('active', 'desc')
            ->orderBy('sort_order')
            ->orderBy('activity_code')
            ->pluck('activity_name');
    }

    private function fieldActivityRows(bool $activeOnly = true)
    {
        return $this->tableWithAuditNames('grating_field_activities')
            ->when($activeOnly, fn($q) => $q->where('grating_field_activities.active', true))
            ->orderBy('grating_field_activities.active', 'desc')
            ->orderBy('grating_field_activities.sort_order')
            ->orderBy('grating_field_activities.activity_code')
            ->get();
    }

    private function projectRows(bool $activeOnly = true)
    {
        if (!$this->hasProjectTable()) {
            return collect();
        }

        return $this->tableWithAuditNames('grating_projects')
            ->when($activeOnly, fn($q) => $q->where('grating_projects.active', true))
            ->orderBy('grating_projects.active', 'desc')
            ->orderBy('grating_projects.sort_order')
            ->orderBy('grating_projects.project_name')
            ->get();
    }

    private function tableWithAuditNames(string $table)
    {
        $query = SqlServerDb::table($table)->select($table . '.*');

        if ($this->hasTableColumn($table, 'created_by')) {
            $query->leftJoin('users as creator_' . $table, 'creator_' . $table . '.id', '=', $table . '.created_by')
                ->addSelect(DB::raw('creator_' . $table . '.name as created_by_name'));
        } else {
            $query->addSelect(DB::raw("CAST(NULL AS NVARCHAR(160)) as created_by_name"));
        }

        if ($this->hasTableColumn($table, 'updated_by')) {
            $query->leftJoin('users as updater_' . $table, 'updater_' . $table . '.id', '=', $table . '.updated_by')
                ->addSelect(DB::raw('updater_' . $table . '.name as updated_by_name'));
        } else {
            $query->addSelect(DB::raw("CAST(NULL AS NVARCHAR(160)) as updated_by_name"));
        }

        return $query;
    }

    private function employeeRollupSubquery()
    {
        return SqlServerDb::table('grating_entry_employees as gee')
            ->join('grating_employees as ge', 'ge.id', '=', 'gee.employee_id')
            ->select([
                'gee.entry_id',
                DB::raw("STRING_AGG(CAST(ge.name AS NVARCHAR(MAX)), N', ') as employee_names"),
                DB::raw('COUNT(gee.employee_id) as team_size'),
            ])
            ->groupBy('gee.entry_id');
    }

    private function teamSizeSubquery()
    {
        return SqlServerDb::table('grating_entry_employees as team_gee')
            ->select([
                'team_gee.entry_id',
                DB::raw('COUNT(team_gee.employee_id) as team_size'),
            ])
            ->groupBy('team_gee.entry_id');
    }

    private function stepRollupSubquery()
    {
        return SqlServerDb::table('grating_entry_steps as ges')
            ->join('grating_steps as gs', 'gs.id', '=', 'ges.step_id')
            ->select([
                'ges.entry_id',
                DB::raw("STRING_AGG(CAST(gs.step_code AS NVARCHAR(MAX)), N', ') as step_codes"),
                DB::raw("STRING_AGG(CAST(gs.step_name AS NVARCHAR(MAX)), N', ') as step_names"),
            ])
            ->groupBy('ges.entry_id');
    }

    private function fieldMfgRollupSubquery()
    {
        if (!$this->hasFieldMfgTable()) {
            return SqlServerDb::connection()
                ->query()
                ->from(DB::raw('(SELECT CAST(NULL AS bigint) as entry_id, CAST(NULL AS NVARCHAR(MAX)) as field_mfgs) as empty_field_mfgs'))
                ->whereRaw('1 = 0');
        }

        return SqlServerDb::table('grating_entry_field_mfgs as gefm')
            ->select([
                'gefm.entry_id',
                DB::raw("STRING_AGG(CAST(gefm.mfg_no AS NVARCHAR(MAX)), N', ') as field_mfgs"),
            ])
            ->groupBy('gefm.entry_id');
    }

    private function syncFieldMfgs(int $entryId, array $items): void
    {
        if (!$this->hasFieldMfgTable()) {
            return;
        }

        $now = now();
        $rows = collect($items)
            ->map(function ($item) {
                $mfgNo = strtoupper(trim((string) ($item['mfg_no'] ?? '')));

                if ($mfgNo === '') {
                    return null;
                }

                return [
                    'mfg_no' => $mfgNo,
                    'workorder_id' => isset($item['workorder_id']) && $item['workorder_id'] !== '' ? (int) $item['workorder_id'] : null,
                    'project' => $item['project'] ?? null,
                    'salesorder' => $item['salesorder'] ?? null,
                ];
            })
            ->filter()
            ->unique('mfg_no')
            ->values()
            ->map(fn($item) => $this->withAudit('grating_entry_field_mfgs', array_merge($item, [
                'entry_id' => $entryId,
                'created_at' => $now,
                'updated_at' => $now,
            ])))
            ->all();

        SqlServerDb::table('grating_entry_field_mfgs')->where('entry_id', $entryId)->delete();

        if ($rows) {
            SqlServerDb::table('grating_entry_field_mfgs')->insert($rows);
        }
    }

    private function resolveEntryStepIds(array $validated)
    {
        $selectedStepId = (int) ($validated['step_id'] ?? 0);

        if ($selectedStepId <= 0) {
            throw ValidationException::withMessages(['step_id' => 'Please choose one step.']);
        }

        return collect([$selectedStepId]);
    }

    private function employeeRules(array $overrides = []): array
    {
        return array_merge([
            'employee_code' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:160'],
            'nickname' => ['nullable', 'string', 'max:80'],
            'responsible_work' => ['nullable', 'string', 'max:255'],
            'is_student' => ['nullable', 'boolean'],
            'student_start_date' => ['nullable', 'date'],
            'student_months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'student_months_custom' => ['nullable', 'integer', 'min:1', 'max:60'],
            'active' => ['nullable', 'boolean'],
        ], $overrides);
    }

    private function studentMonths(array $validated): ?int
    {
        if (empty($validated['is_student'])) {
            return null;
        }

        return (int) ($validated['student_months_custom'] ?? $validated['student_months'] ?? 0) ?: null;
    }

    private function validateStudentContract(array $validated): void
    {
        if (empty($validated['is_student'])) {
            return;
        }

        $errors = [];
        if (empty($validated['student_start_date'])) {
            $errors['student_start_date'] = 'กรุณาใส่วันเริ่มฝึกงาน';
        }
        if (!$this->studentMonths($validated)) {
            $errors['student_months'] = 'กรุณาเลือกระยะเวลา 3/6/12 เดือน หรือกรอกจำนวนเดือนเอง';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function studentEndDate(array $validated, ?int $months): ?string
    {
        if (empty($validated['is_student']) || !$months || empty($validated['student_start_date'])) {
            return null;
        }

        return Carbon::parse($validated['student_start_date'])->addMonthsNoOverflow($months)->subDay()->toDateString();
    }

    private function employeePayload(array $validated, bool $updating = false): array
    {
        $studentMonths = $this->studentMonths($validated);
        $studentEndDate = $this->studentEndDate($validated, $studentMonths);
        $employeeCode = $validated['employee_code'] ?? null;
        if (!empty($validated['is_student']) && trim((string) $employeeCode) === '') {
            $employeeCode = $this->nextStudentEmployeeCode();
        }

        $payload = [
            'employee_code' => $employeeCode,
            'name' => $validated['name'],
            'nickname' => $validated['nickname'] ?? null,
            'responsible_work' => $validated['responsible_work'] ?? null,
            'active' => (bool) ($validated['active'] ?? false),
            'updated_at' => now(),
        ];

        if ($this->hasEmployeeColumn('is_student')) {
            $payload['is_student'] = (bool) ($validated['is_student'] ?? false);
        }
        if ($this->hasEmployeeColumn('student_start_date')) {
            $payload['student_start_date'] = !empty($validated['is_student']) ? ($validated['student_start_date'] ?? null) : null;
        }
        if ($this->hasEmployeeColumn('student_months')) {
            $payload['student_months'] = $studentMonths;
        }
        if ($this->hasEmployeeColumn('student_end_date')) {
            $payload['student_end_date'] = $studentEndDate;
            if ($studentEndDate && Carbon::parse($studentEndDate)->lt(Carbon::today('Asia/Bangkok'))) {
                $payload['active'] = false;
            }
        }

        return $this->withAudit('grating_employees', array_merge($payload, $updating ? [] : ['created_at' => now()]), $updating);
    }

    private function nextStudentEmployeeCode(): string
    {
        $codes = SqlServerDb::table('grating_employees')
            ->where('employee_code', 'like', 'STU%')
            ->pluck('employee_code');

        $next = 1;
        foreach ($codes as $code) {
            if (preg_match('/^STU(\d+)$/i', (string) $code, $matches)) {
                $next = max($next, ((int) $matches[1]) + 1);
            }
        }

        do {
            $candidate = 'STU' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
            $exists = SqlServerDb::table('grating_employees')->where('employee_code', $candidate)->exists();
            $next++;
        } while ($exists);

        return $candidate;
    }

    private function stepRules(): array
    {
        return [
            'step_code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/', Rule::unique(SqlServerDb::qualifyTable('grating_steps'), 'step_code')],
            'step_name' => ['required', 'string', 'max:160'],
            'is_field_work' => ['nullable', 'boolean'],
            'target_pcs_per_hour' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'target_kg_per_hour' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    private function stepPayload(array $validated, bool $updating = false): array
    {
        $payload = [
            'step_code' => strtoupper(trim($validated['step_code'])),
            'step_name' => $validated['step_name'],
            'is_field_work' => (bool) ($validated['is_field_work'] ?? false),
            'target_kg_per_hour' => $validated['target_kg_per_hour'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 100,
            'active' => (bool) ($validated['active'] ?? false),
            'updated_at' => now(),
        ];

        if ($this->hasStepColumn('target_pcs_per_hour')) {
            $payload['target_pcs_per_hour'] = $validated['target_pcs_per_hour'] ?? null;
        }

        return $this->withAudit('grating_steps', array_merge($payload, $updating ? [] : ['created_at' => now()]), $updating);
    }

    private function fieldActivityExistsRule()
    {
        return Rule::exists(SqlServerDb::qualifyTable('grating_field_activities'), 'activity_name')
            ->where(fn($query) => $query->where('active', true));
    }

    private function fieldActivityRules(): array
    {
        return [
            'activity_code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/', Rule::unique(SqlServerDb::qualifyTable('grating_field_activities'), 'activity_code')],
            'activity_name' => ['required', 'string', 'max:160'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    private function fieldActivityPayload(array $validated, bool $updating = false): array
    {
        return $this->withAudit('grating_field_activities', array_merge([
            'activity_code' => strtoupper(trim($validated['activity_code'])),
            'activity_name' => $validated['activity_name'],
            'sort_order' => $validated['sort_order'] ?? 100,
            'active' => (bool) ($validated['active'] ?? false),
            'updated_at' => now(),
        ], $updating ? [] : ['created_at' => now()]), $updating);
    }

    private function projectRules(): array
    {
        return [
            'project_name' => ['required', 'string', 'max:500', Rule::unique(SqlServerDb::qualifyTable('grating_projects'), 'project_name')],
            'salesorder' => ['nullable', 'string', 'max:80'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    private function projectPayload(array $validated, bool $updating = false): array
    {
        return $this->withAudit('grating_projects', array_merge([
            'project_name' => trim((string) $validated['project_name']),
            'salesorder' => $validated['salesorder'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 100,
            'active' => (bool) ($validated['active'] ?? false),
            'updated_at' => now(),
        ], $updating ? [] : ['created_at' => now()]), $updating);
    }

    private function avgFgGratingPricePerKg(): ?float
    {
        $sql = "
            SELECT
                SUM(pi.sellprice * pi.qty) AS total_bath,
                SUM(pi.qty * p.ref_unit_qty) AS total_kg
            FROM predm
            JOIN predmitems pi ON predm.id = pi.trans_id
            JOIN customer ON predm.customer_id = customer.id
            JOIN parts p ON pi.parts_id = p.id
            JOIN partstype ON p.partstype_id = partstype.id
            WHERE predm.ordnumber LIKE 'SO%'
              AND customer.saleperson_id = 1436
              AND partstype.description = 'FG GRATING'
              AND p.ref_unit = '03'
              AND pi.unit != ' '
              AND predm.transdate >= NOW() - INTERVAL '3 months'
        ";

        try {
            $row = DB::connection('pgsqlw')->selectOne($sql);
            $totalKg = (float) ($row->total_kg ?? 0);
            $totalBath = (float) ($row->total_bath ?? 0);
            if ($totalKg <= 0) return null;
            return round($totalBath / $totalKg, 2);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function packGoodKg(array $filters): float
    {
        return (float) $this->entryBase($filters)
            ->where('s.step_code', 'PACK')
            ->sum('e.good_qty_kg');
    }

    private function hasRequiredTables(): bool
    {
        $schema = Schema::connection(SqlServerDb::connectionName());

        return $schema->hasTable('grating_employees')
            && $schema->hasTable('grating_steps')
            && $schema->hasTable('grating_field_activities')
            && $schema->hasTable('grating_daily_entries')
            && $schema->hasTable('grating_entry_employees')
            && $schema->hasTable('grating_entry_steps');
    }

    private function hasFieldMfgTable(): bool
    {
        return Schema::connection(SqlServerDb::connectionName())->hasTable('grating_entry_field_mfgs');
    }

    private function hasDeleteLogTable(): bool
    {
        return Schema::connection(SqlServerDb::connectionName())->hasTable('grating_delete_logs');
    }

    private function logDeleteSnapshot(string $sourceTable, object $row, ?int $entryId = null): void
    {
        if (!$this->hasDeleteLogTable()) {
            return;
        }

        $payload = get_object_vars($row);

        SqlServerDb::table('grating_delete_logs')->insert([
            'source_table' => $sourceTable,
            'source_id' => isset($payload['id']) ? (string) $payload['id'] : null,
            'entry_id' => $entryId,
            'action' => 'DELETE',
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'deleted_by' => auth()->id(),
            'deleted_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function hasProjectTable(): bool
    {
        return Schema::connection(SqlServerDb::connectionName())->hasTable('grating_projects');
    }

    private function hasEntryColumn(string $column): bool
    {
        if ($this->entryColumnAvailability === null) {
            $schema = Schema::connection(SqlServerDb::connectionName());
            $this->entryColumnAvailability = [];
            foreach (['project', 'salesorder', 'plan_qty_pcs', 'created_by', 'updated_by'] as $entryColumn) {
                $this->entryColumnAvailability[$entryColumn] = $schema->hasColumn('grating_daily_entries', $entryColumn);
            }
        }

        return (bool) ($this->entryColumnAvailability[$column] ?? false);
    }

    private function hasTableColumn(string $table, string $column): bool
    {
        if ($this->tableColumnAvailability === null) {
            $this->tableColumnAvailability = [];
        }

        $key = $table . '.' . $column;
        if (!array_key_exists($key, $this->tableColumnAvailability)) {
            $this->tableColumnAvailability[$key] = Schema::connection(SqlServerDb::connectionName())
                ->hasColumn($table, $column);
        }

        return (bool) $this->tableColumnAvailability[$key];
    }

    private function withAudit(string $table, array $payload, bool $updating = false): array
    {
        $userId = auth()->id();

        if (!$updating && $this->hasTableColumn($table, 'created_by')) {
            $payload['created_by'] = $payload['created_by'] ?? $userId;
        }

        if ($this->hasTableColumn($table, 'updated_by')) {
            $payload['updated_by'] = $userId;
        }

        return $payload;
    }

    private function deactivateExpiredStudentEmployees(): void
    {
        if (!$this->hasEmployeeColumn('is_student') || !$this->hasEmployeeColumn('student_end_date')) {
            return;
        }

        SqlServerDb::table('grating_employees')
            ->where('active', true)
            ->where('is_student', true)
            ->whereNotNull('student_end_date')
            ->whereDate('student_end_date', '<', Carbon::today('Asia/Bangkok')->toDateString())
            ->update(['active' => false, 'updated_at' => now()]);
    }

    private function hasEmployeeColumn(string $column): bool
    {
        if ($this->employeeColumnAvailability === null) {
            $schema = Schema::connection(SqlServerDb::connectionName());
            $this->employeeColumnAvailability = [];
            foreach (['is_student', 'student_start_date', 'student_months', 'student_end_date'] as $employeeColumn) {
                $this->employeeColumnAvailability[$employeeColumn] = $schema->hasColumn('grating_employees', $employeeColumn);
            }
        }

        return (bool) ($this->employeeColumnAvailability[$column] ?? false);
    }

    private function hasStepColumn(string $column): bool
    {
        if ($this->stepColumnAvailability === null) {
            $schema = Schema::connection(SqlServerDb::connectionName());
            $this->stepColumnAvailability = [];
            foreach (['target_pcs_per_hour'] as $stepColumn) {
                $this->stepColumnAvailability[$stepColumn] = $schema->hasColumn('grating_steps', $stepColumn);
            }
        }

        return (bool) ($this->stepColumnAvailability[$column] ?? false);
    }
}
