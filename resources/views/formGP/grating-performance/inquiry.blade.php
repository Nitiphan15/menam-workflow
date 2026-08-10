@extends('layouts.layout')

@section('title', 'Grating Performance Inquiry')
@section('page-title', 'Grating Performance Inquiry')

@section('content')
    @php
        $fmt = fn($value, $dec = 1) => number_format((float) $value, $dec);
        $duration = function ($minutes) {
            $minutes = max(0, (int) round((float) $minutes));
            if ($minutes === 0) return '0 นาที';
            if ($minutes < 60) return $minutes . ' นาที';
            $wholeHours = intdiv($minutes, 60);
            $remainingMinutes = $minutes % 60;
            return $remainingMinutes === 0
                ? $wholeHours . ' ชม.'
                : $wholeHours . ' ชม. ' . $remainingMinutes . ' นาที';
        };
        $selectedEmployeeIds = collect($filters['employee_ids'] ?? [])->map(fn($id) => (int) $id);
        $selectedEmployeeNames = collect($employees ?? [])
            ->filter(fn($employee) => $selectedEmployeeIds->contains((int) $employee->id))
            ->map(fn($employee) => $employee->name . (trim((string) ($employee->nickname ?? '')) !== '' ? ' - ' . trim($employee->nickname) : ''))
            ->values();
    @endphp

    <style>
        .gp-wrap { background:#f5f7fa; border-radius:8px; padding:16px; }
        .gp-panel { background:#fff; border:1px solid #dfe5ec; border-radius:8px; overflow:visible; }
        .gp-head { padding:12px 16px; border-bottom:1px solid #e8edf2; display:flex; align-items:center; justify-content:space-between; gap:12px; font-weight:700; }
        .gp-table-wrap { max-height:640px; overflow:auto; }
        .gp-table { min-width:1500px; }
        .gp-table th { position:sticky; top:0; z-index:2; background:#edf4ff; white-space:nowrap; }
        .gp-table td { vertical-align:middle; }
        .gp-table .gp-sticky-date { position:sticky; left:0; z-index:3; background:#fff; min-width:92px; }
        .gp-table th.gp-sticky-date { z-index:5; background:#edf4ff; }
        .gp-table .gp-sticky-mfg { position:sticky; left:92px; z-index:3; background:#fff; min-width:132px; }
        .gp-table th.gp-sticky-mfg { z-index:5; background:#edf4ff; }
        .gp-table .gp-sticky-action { position:sticky; right:0; z-index:3; background:#fff; min-width:88px; }
        .gp-table th.gp-sticky-action { z-index:5; background:#edf4ff; }
        .gp-table tbody tr:hover .gp-sticky-date,
        .gp-table tbody tr:hover .gp-sticky-mfg,
        .gp-table tbody tr:hover .gp-sticky-action { background:#f8fbff; }
        .gp-transaction-project { min-width:190px; white-space:normal; }
        .gp-transaction-step { min-width:180px; white-space:normal; }
        .gp-transaction-employee { min-width:220px; white-space:normal; }
        .gp-summary-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px; }
        .gp-summary-card { border:1px solid #dfe5ec; border-radius:8px; background:#fff; padding:10px 12px; }
        .gp-summary-card .label { color:#6b7280; font-size:.78rem; }
        .gp-summary-card .value { font-weight:800; font-size:1.15rem; line-height:1.2; }
        .gp-subline { display:block; color:#6b7280; font-size:.78rem; line-height:1.35; }
        .gp-mini-table-wrap { max-height:calc(100vh - 260px); min-height:520px; overflow:auto; }
        .gp-mini-table th { position:sticky; top:0; z-index:2; background:#f8fbff; white-space:nowrap; }
        .gp-mini-table td { vertical-align:top; }
        .gp-mini-table { min-width:980px; }
        .gp-employee-cell { min-width:220px; white-space:normal; }
        .gp-work-list { display:grid; gap:4px; }
        .gp-work-item { line-height:1.35; overflow-wrap:anywhere; }
        .gp-detail-row { display:none; }
        .gp-detail-row.is-open { display:table-row; }
        .gp-detail-panel { background:#fff; border:1px solid #dbe4ef; border-radius:6px; padding:10px; }
        .gp-detail-table { min-width:880px; }
        .gp-detail-toggle { white-space:nowrap; }
        .gp-detail-toggle.is-open { color:#fff; background:#0d6efd; border-color:#0d6efd; }
        .gp-group-total { background:#fbfdff; font-weight:700; }
        .gp-group-total.gp-detail-click { cursor:pointer; }
        .gp-group-total.gp-detail-click:hover { background:#eef6ff; }
        .gp-group-total.is-open { background:#dbeafe; color:#0f3d78; }
        .gp-mfg-group { background:#f6f8fb; font-weight:800; }
        .gp-step-cell { min-width:180px; }
        .gp-work-summary { min-width:460px; max-width:820px; white-space:normal; }
        .gp-mode-layout { display:grid; grid-template-columns:minmax(0, 1fr) 280px; gap:12px; align-items:start; }
        .gp-total-panel { background:#fff; border:1px solid #dfe5ec; border-radius:8px; overflow:hidden; position:sticky; top:12px; }
        .gp-total-panel .gp-total-head { padding:10px 12px; background:#f8fbff; border-bottom:1px solid #e8edf2; font-weight:800; }
        .gp-total-head small { display:block; margin-top:3px; color:#64748b; font-weight:500; line-height:1.35; }
        .gp-total-body { padding:10px 12px; }
        .gp-total-row { display:flex; justify-content:space-between; gap:12px; padding:6px 0; border-bottom:1px dashed #e5e7eb; }
        .gp-total-row:last-child { border-bottom:0; }
        .gp-total-label { color:#6b7280; min-width:0; overflow-wrap:anywhere; }
        .gp-total-value { flex:0 0 auto; font-weight:800; text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
        .gp-total-value small { white-space:nowrap; }
        .gp-subtotal-list { max-height:420px; overflow:auto; margin-top:8px; border-top:1px solid #e5e7eb; padding-top:8px; }
        @media (max-width:1199px) { .gp-mode-layout { grid-template-columns:1fr; } .gp-total-panel { position:static; } }
        .gp-project-wrap { position:relative; }
        .gp-mfg-list { position:absolute; z-index:20; background:#fff; border:1px solid #ced4da; border-radius:6px; width:100%; max-height:240px; overflow:auto; display:none; }
        .gp-mfg-list button { display:block; width:100%; border:0; background:#fff; padding:8px 10px; text-align:left; }
        .gp-mfg-list button:hover { background:#eef4ff; }
        .gp-mfg-item-main { font-weight:700; color:#1f2937; }
        .gp-mfg-item-sub { font-size:.78rem; color:#64748b; margin-top:2px; }
        .num { text-align:right; font-variant-numeric:tabular-nums; }
        .gp-autosize { resize:none; overflow:hidden; min-height:38px; line-height:1.4; }
        .gp-active-filters { display:flex; flex-wrap:wrap; align-items:center; gap:6px; padding:0 16px 12px; }
        .gp-active-filter { border:1px solid #bfdbfe; background:#eff6ff; color:#1d4ed8; border-radius:999px; padding:4px 9px; font-size:.78rem; font-weight:700; }
    </style>

    <div class="gp-wrap">
        @if (!empty($setupMissing))
            <div class="alert alert-warning">ยังไม่พบตาราง Grating Performance กรุณารัน migration ก่อนใช้งาน</div>
        @endif

        <div class="gp-panel mb-3">
            <div class="gp-head">
                <span>รายการ Grating Performance</span>
                <div class="d-flex flex-wrap gap-2">
                    @can('GP')
                    <a href="{{ route('grating-performance.entries.create') }}" class="btn btn-sm btn-primary"><i class="fas fa-plus me-1"></i> Input</a>
                    @endcan
                    <a href="{{ route('grating-performance.index') }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-chart-line me-1"></i> Dashboard</a>
                    @can('GPM')
                    <a href="{{ route('grating-performance.masters') }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-sliders me-1"></i> Masters</a>
                    @endcan
                </div>
            </div>
            <form method="GET" class="p-3">
                <div class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label">จากวันที่</label>
                        <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">ถึงวันที่</label>
                        <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">MFG</label>
                        <input type="text" name="mfg_no" class="form-control" value="{{ $filters['mfg_no'] }}">
                    </div>
                    <div class="col-md-2 gp-project-wrap">
                        <label class="form-label">โครงการ</label>
                        <input type="text" name="project" class="form-control gp-project-autocomplete" value="{{ $filters['project'] ?? '' }}" autocomplete="off" placeholder="พิมพ์บางส่วนของชื่อได้" title="ค้นหาแบบมีคำนี้อยู่ในชื่อโครงการ">
                        <div class="gp-project-list gp-mfg-list"></div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">เลข SO</label>
                        <input type="text" name="salesorder" class="form-control" value="{{ $filters['salesorder'] ?? '' }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Step</label>
                        <select name="step_id" class="form-select">
                            <option value="">ทั้งหมด</option>
                            @foreach ($steps as $step)
                                <option value="{{ $step->id }}" @selected((string) $filters['step_id'] === (string) $step->id)>{{ $step->step_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">พนักงาน</label>
                        <select name="employee_ids[]" class="form-select gp-select" multiple data-placeholder="พนักงานทั้งหมด">
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}" @selected(in_array((int) $employee->id, $filters['employee_ids'] ?? [], true))>{{ $employee->name }}{{ trim((string) ($employee->nickname ?? '')) !== '' ? ' - ' . trim($employee->nickname) : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Mode</label>
                        <select name="view_mode" class="form-select">
                            <option value="transactions" @selected(($filters['view_mode'] ?? 'transactions') === 'transactions')>Transaction</option>
                            <option value="employee" @selected(($filters['view_mode'] ?? 'transactions') === 'employee')>By employee / day</option>
                            <option value="mfg" @selected(($filters['view_mode'] ?? 'transactions') === 'mfg')>By MFG / flow</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-grid">
                        <button class="btn btn-outline-primary"><i class="fas fa-filter me-1"></i> Filter</button>
                    </div>
                </div>
            </form>
            @if ($selectedEmployeeNames->isNotEmpty())
                <div class="gp-active-filters">
                    <span class="text-muted small">กำลังกรองพนักงาน:</span>
                    @foreach ($selectedEmployeeNames as $employeeName)
                        <span class="gp-active-filter">{{ $employeeName }}</span>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="gp-summary-grid mb-3">
            <div class="gp-summary-card">
                <div class="label">Records</div>
                <div class="value">{{ number_format($summary['entry_count'] ?? 0) }}</div>
                <span class="gp-subline">MFG {{ number_format($summary['mfg_count'] ?? 0) }}</span>
            </div>
            <div class="gp-summary-card">
                <div class="label">ยอดดี</div>
                <div class="value">{{ $fmt($summary['good_pcs'] ?? 0, 0) }} ชิ้น</div>
                <span class="gp-subline">{{ $fmt($summary['good_kg'] ?? 0) }} กก. · step ล่าสุด/MFG</span>
            </div>
            <div class="gp-summary-card">
                <div class="label">ยอดเสีย</div>
                <div class="value">{{ $fmt($summary['bad_pcs'] ?? 0, 0) }} ชิ้น</div>
                <span class="gp-subline">{{ $fmt($summary['bad_kg'] ?? 0) }} กก. · step ล่าสุด/MFG</span>
            </div>
            <div class="gp-summary-card">
                <div class="label">สถานะงาน</div>
                <div class="value">{{ number_format($summary['finished_count'] ?? 0) }} จบ</div>
                <span class="gp-subline">{{ number_format($summary['open_count'] ?? 0) }} ยังไม่จบ</span>
            </div>
        </div>

        @php
            $viewMode = $filters['view_mode'] ?? 'transactions';
            $employeeDailyRows = $employeeDailyRows ?? collect();
            $employeeElapsedSummary = $employeeElapsedSummary ?? ['minutes' => 0, 'work_days' => 0];
            $mfgFlowRows = $mfgFlowRows ?? collect();
            $employeeDetailRows = $employeeDetailRows ?? collect();
            $mfgDetailRows = $mfgDetailRows ?? collect();
            $mfgLatestOutputs = ($mfgLatestOutputs ?? collect())->keyBy('mfg_no');
            $employeeDetailsByEmployee = $employeeDetailRows->groupBy('detail_employee_id');
            $employeeDetailsByDay = $employeeDetailRows->groupBy(fn($row) => $row->detail_employee_id . '|' . $row->work_day);
            $mfgDetailsByMfg = $mfgDetailRows->groupBy('mfg_no');
            $mfgDetailsByStep = $mfgDetailRows->groupBy(fn($row) => $row->mfg_no . '|' . $row->step_id);
            // One entry may belong to several employees/steps. For MFG elapsed
            // time, count the saved work entry once instead of as person-hours.
            $uniqueMfgDetailRows = $mfgDetailRows->unique('id');
            $employeeTotals = [
                'rows' => $employeeDailyRows->count(),
                'employees' => $employeeDailyRows->pluck('employee_id')->unique()->count(),
                'days' => (int) ($employeeElapsedSummary['work_days'] ?? 0),
                'minutes' => (int) ($employeeElapsedSummary['minutes'] ?? 0),
                'good_pcs' => $employeeDailyRows->sum('allocated_good_pcs'),
                'bad_pcs' => $employeeDailyRows->sum('allocated_bad_pcs'),
            ];
            $employeeSubtotals = $employeeDailyRows->groupBy('employee_name')->map(function ($items) {
                return [
                    'days' => $items->count(),
                    'hours' => $items->sum('minutes') / 60,
                    'good_pcs' => $items->sum('allocated_good_pcs'),
                    'bad_pcs' => $items->sum('allocated_bad_pcs'),
                ];
            });
            $mfgTotals = [
                'rows' => $uniqueMfgDetailRows->count(),
                'mfgs' => $mfgFlowRows->pluck('mfg_no')->unique()->count(),
                'hours' => $uniqueMfgDetailRows->sum('duration_minutes') / 60,
                'good_pcs' => $summary['good_pcs'] ?? 0,
                'bad_pcs' => $summary['bad_pcs'] ?? 0,
            ];
            $mfgSubtotals = $mfgDetailRows->groupBy('mfg_no')->map(function ($items) use ($mfgLatestOutputs) {
                $uniqueEntries = $items->unique('id');
                return [
                    'hours' => $uniqueEntries->sum('duration_minutes') / 60,
                    'good_pcs' => (float) ($mfgLatestOutputs->get($items->first()->mfg_no)->good_pcs ?? 0),
                    'bad_pcs' => (float) ($mfgLatestOutputs->get($items->first()->mfg_no)->bad_pcs ?? 0),
                ];
            });
        @endphp

        @if ($viewMode === 'employee')
        <div class="gp-mode-layout mb-3">
            <div class="gp-panel">
                <div class="gp-head">
                    <span>By employee / day</span>
                    <small class="text-muted">1 วันที่เลือก = 1 วันทำงาน และแยกดูเวลาจริงได้</small>
                </div>
                <div class="gp-mini-table-wrap">
                    <table class="table table-sm table-bordered gp-mini-table mb-0">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Date</th>
                                <th class="gp-work-summary">Work</th>
                                <th class="num">เวลาจริง</th>
                                <th class="num">Good pcs</th>
                                <th class="num">Bad pcs</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($employeeDailyRows->groupBy('employee_id') as $employeeId => $employeeRows)
                                @php
                                    $firstEmployee = $employeeRows->first();
                                    $employeeSubtotal = [
                                        'rows' => $employeeRows->sum('entry_count'),
                                        'hours' => $employeeRows->sum('minutes') / 60,
                                        'good_pcs' => $employeeRows->sum('allocated_good_pcs'),
                                        'bad_pcs' => $employeeRows->sum('allocated_bad_pcs'),
                                    ];
                                    $employeeDetailTarget = 'employee-detail-' . $employeeId;
                                    $employeeDetails = $employeeDetailsByEmployee->get($employeeId, collect());
                                @endphp
                                <tr class="gp-mfg-group">
                                    <td colspan="6">
                                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                            <span>{{ $firstEmployee->employee_name }}</span>
                                            <button type="button" class="btn btn-sm btn-outline-primary gp-detail-toggle" data-detail-target="{{ $employeeDetailTarget }}">
                                                Transactions ({{ number_format($employeeDetails->count()) }})
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                @foreach ($employeeRows as $dailyRow)
                                    @php
                                        $workItems = collect(explode(' | ', (string) $dailyRow->work_summary))
                                            ->map(fn($item) => trim($item))
                                            ->filter()
                                            ->values();
                                        $dayDetailTarget = 'employee-detail-' . $employeeId . '-' . \Carbon\Carbon::parse($dailyRow->work_day)->format('Ymd');
                                        $dayDetails = $employeeDetailsByDay->get($employeeId . '|' . $dailyRow->work_day, collect());
                                    @endphp
                                    <tr>
                                        <td></td>
                                        <td>{{ \Carbon\Carbon::parse($dailyRow->work_day)->format('d/m/Y') }}</td>
                                        <td class="gp-work-summary">
                                            <div class="gp-work-list">
                                                @foreach ($workItems as $workItem)
                                                    <div class="gp-work-item">{{ $workItem }}</div>
                                                @endforeach
                                            </div>
                                            <div class="d-flex flex-wrap align-items-center gap-2 mt-1">
                                                <span class="gp-subline">{{ number_format($dailyRow->entry_count) }} transactions</span>
                                                <button type="button" class="btn btn-sm btn-outline-secondary gp-detail-toggle" data-detail-target="{{ $dayDetailTarget }}">Detail</button>
                                            </div>
                                        </td>
                                        <td class="num">{{ $duration($dailyRow->minutes) }}</td>
                                        <td class="num">{{ $fmt($dailyRow->allocated_good_pcs, 0) }}</td>
                                        <td class="num">{{ $fmt($dailyRow->allocated_bad_pcs, 0) }}</td>
                                    </tr>
                                    <tr class="gp-detail-row" data-detail-row="{{ $dayDetailTarget }}">
                                        <td colspan="6">
                                            <div class="gp-detail-panel">
                                                <div class="table-responsive">
                                                    <table class="table table-sm table-bordered gp-detail-table mb-0">
                                                        <thead><tr><th>Date</th><th>MFG / SO</th><th>Step</th><th>Time</th><th class="num">Duration</th><th class="num">Good pcs</th><th class="num">Bad pcs</th><th>Note</th></tr></thead>
                                                        <tbody>
                                                            @forelse ($dayDetails as $detail)
                                                                <tr>
                                                                    <td>{{ \Carbon\Carbon::parse($detail->work_date)->format('d/m/Y') }}</td>
                                                                    <td>{{ $detail->is_field_work ? (($detail->field_mfgs ?? '') ?: 'FIELD') : $detail->mfg_no }}<span class="gp-subline">{{ $detail->salesorder ?? '' }}</span></td>
                                                                    <td>{{ $detail->step_name }}</td>
                                                                    <td>{{ \Carbon\Carbon::parse($detail->started_at)->format('H:i') }}-{{ $detail->finished_at ? \Carbon\Carbon::parse($detail->finished_at)->format('H:i') : '-' }}</td>
                                                                    <td class="num">{{ $duration($detail->duration_minutes) }}</td>
                                                                    <td class="num">{{ $fmt($detail->allocated_good_pcs, 0) }}</td>
                                                                    <td class="num">{{ $fmt($detail->allocated_bad_pcs, 0) }}</td>
                                                                    <td>{{ $detail->notes }}</td>
                                                                </tr>
                                                            @empty
                                                                <tr><td colspan="8" class="text-center text-muted">No transaction detail</td></tr>
                                                            @endforelse
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                                <tr class="gp-group-total">
                                    <td colspan="3">Subtotal {{ $firstEmployee->employee_name }} <span class="gp-subline">{{ number_format($employeeSubtotal['rows']) }} transactions</span></td>
                                    <td class="num">{{ $fmt($employeeSubtotal['hours'], 1) }}</td>
                                    <td class="num">{{ $fmt($employeeSubtotal['good_pcs'], 0) }}</td>
                                    <td class="num">{{ $fmt($employeeSubtotal['bad_pcs'], 0) }}</td>
                                </tr>
                                <tr class="gp-detail-row" data-detail-row="{{ $employeeDetailTarget }}">
                                    <td colspan="6">
                                        <div class="gp-detail-panel">
                                            <div class="table-responsive">
                                                <table class="table table-sm table-bordered gp-detail-table mb-0">
                                                    <thead><tr><th>Date</th><th>MFG / SO</th><th>Step</th><th>Time</th><th class="num">Duration</th><th class="num">Good pcs</th><th class="num">Bad pcs</th><th>Note</th></tr></thead>
                                                    <tbody>
                                                        @forelse ($employeeDetails as $detail)
                                                            <tr>
                                                                <td>{{ \Carbon\Carbon::parse($detail->work_date)->format('d/m/Y') }}</td>
                                                                <td>{{ $detail->is_field_work ? (($detail->field_mfgs ?? '') ?: 'FIELD') : $detail->mfg_no }}<span class="gp-subline">{{ $detail->salesorder ?? '' }}</span></td>
                                                                <td>{{ $detail->step_name }}</td>
                                                                <td>{{ \Carbon\Carbon::parse($detail->started_at)->format('H:i') }}-{{ $detail->finished_at ? \Carbon\Carbon::parse($detail->finished_at)->format('H:i') : '-' }}</td>
                                                                <td class="num">{{ $duration($detail->duration_minutes) }}</td>
                                                                <td class="num">{{ $fmt($detail->allocated_good_pcs, 0) }}</td>
                                                                <td class="num">{{ $fmt($detail->allocated_bad_pcs, 0) }}</td>
                                                                <td>{{ $detail->notes }}</td>
                                                            </tr>
                                                        @empty
                                                            <tr><td colspan="8" class="text-center text-muted">No transaction detail</td></tr>
                                                        @endforelse
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-3">No employee daily data</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <aside class="gp-total-panel">
                <div class="gp-total-head">
                    Subtotal / Total
                    <small>{{ $selectedEmployeeNames->isNotEmpty() ? 'กรอง: ' . $selectedEmployeeNames->implode(', ') : 'พนักงานทั้งหมด' }}</small>
                </div>
                <div class="gp-total-body">
                    <div class="gp-total-row"><span class="gp-total-label">Employees</span><span class="gp-total-value">{{ number_format($employeeTotals['employees']) }}</span></div>
                    <div class="gp-total-row"><span class="gp-total-label">วันที่มีงาน</span><span class="gp-total-value">{{ number_format($employeeTotals['days']) }}</span></div>
                    <div class="gp-total-row"><span class="gp-total-label">เวลางานจริง</span><span class="gp-total-value">{{ $duration($employeeTotals['minutes']) }}</span></div>
                    <div class="gp-total-row"><span class="gp-total-label">Good pcs</span><span class="gp-total-value">{{ $fmt($employeeTotals['good_pcs'], 0) }}</span></div>
                    <div class="gp-total-row"><span class="gp-total-label">Bad pcs</span><span class="gp-total-value">{{ $fmt($employeeTotals['bad_pcs'], 0) }}</span></div>
                    <div class="gp-subtotal-list">
                        @foreach ($employeeSubtotals as $name => $subtotal)
                            <div class="gp-total-row">
                                <span class="gp-total-label">{{ $name }}</span>
                                <span class="gp-total-value">{{ number_format($subtotal['days']) }} วัน · {{ $fmt($subtotal['good_pcs'], 0) }} pcs<br><small>{{ $duration($subtotal['hours'] * 60) }}</small></span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </aside>
        </div>
        @endif

        @if ($viewMode === 'mfg')
        <div class="gp-mode-layout mb-3">
            <div class="gp-panel">
                <div class="gp-head">
                    <span>By MFG / master flow</span>
                    <small class="text-muted">{{ number_format($mfgFlowRows->count()) }} rows</small>
                </div>
                <div class="gp-mini-table-wrap">
                    <table class="table table-sm table-bordered gp-mini-table mb-0">
                        <thead>
                            <tr>
                                <th>MFG / SO</th>
                                <th class="gp-step-cell">Step</th>
                                <th>Employee</th>
                                <th class="num">เวลา</th>
                                <th class="num">Good pcs</th>
                                <th class="num">Bad pcs</th>
                                <th>Last</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($mfgFlowRows->groupBy('mfg_no') as $mfgNo => $flowRows)
                                @php
                                    $firstFlow = $flowRows->first();
                                    $mfgStepSubtotals = $flowRows->groupBy('step_id')->map(function ($stepRows, $stepId) use ($mfgDetailsByStep, $mfgNo) {
                                        $firstStep = $stepRows->first();
                                        $uniqueStepEntries = $mfgDetailsByStep
                                            ->get($mfgNo . '|' . $stepId, collect())
                                            ->unique('id');
                                        return [
                                            'step_name' => $firstStep->step_name,
                                            'step_code' => $firstStep->step_code,
                                            'rows' => $uniqueStepEntries->count(),
                                            'hours' => $uniqueStepEntries->sum('duration_minutes') / 60,
                                            'good_pcs' => $stepRows->sum('allocated_good_pcs'),
                                            'bad_pcs' => $stepRows->sum('allocated_bad_pcs'),
                                        ];
                                    });
                                    $mfgDetailTarget = 'mfg-detail-' . md5((string) $mfgNo);
                                    $mfgDetails = $mfgDetailsByMfg->get($mfgNo, collect());
                                @endphp
                                <tr class="gp-mfg-group">
                                    <td colspan="7">
                                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                            <span>
                                                {{ $mfgNo }}
                                                @if($firstFlow->project ?? null)
                                                    <span class="text-muted">/ {{ $firstFlow->project }}</span>
                                                @endif
                                                @if($firstFlow->salesorder ?? null)
                                                    <span class="text-muted">/ SO: {{ $firstFlow->salesorder }}</span>
                                                @endif
                                                @if(($firstFlow->plan_qty_pcs ?? null) !== null)
                                                    <span class="gp-subline">กำหนด: {{ $fmt($firstFlow->plan_qty_pcs, 0) }} ชิ้น</span>
                                                @endif
                                            </span>
                                            <button type="button" class="btn btn-sm btn-outline-primary gp-detail-toggle" data-detail-target="{{ $mfgDetailTarget }}">
                                                Transactions ({{ number_format($mfgDetails->count()) }})
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                @foreach ($flowRows->groupBy('step_id') as $stepId => $stepRows)
                                    @foreach ($stepRows as $flowRow)
                                        <tr>
                                            <td></td>
                                            <td class="gp-step-cell">{{ $flowRow->step_name }}<span class="gp-subline">{{ $flowRow->step_code }}</span></td>
                                            <td class="gp-employee-cell">{{ $flowRow->employee_name }}<span class="gp-subline">{{ number_format($flowRow->entry_count) }} transactions</span></td>
                                            <td class="num">{{ $duration($flowRow->minutes) }}</td>
                                            <td class="num">{{ $fmt($flowRow->allocated_good_pcs, 0) }}</td>
                                            <td class="num">{{ $fmt($flowRow->allocated_bad_pcs, 0) }}</td>
                                            <td>{{ $flowRow->last_work_date ? \Carbon\Carbon::parse($flowRow->last_work_date)->format('d/m/Y') : '-' }}</td>
                                        </tr>
                                    @endforeach
                                    @php
                                        $stepSubtotal = $mfgStepSubtotals->get($stepId);
                                        $stepDetailTarget = 'mfg-step-detail-' . md5((string) $mfgNo . '|' . $stepId);
                                        $stepDetails = $mfgDetailsByStep->get($mfgNo . '|' . $stepId, collect());
                                    @endphp
                                    <tr class="gp-group-total gp-detail-click" data-detail-target="{{ $stepDetailTarget }}">
                                        <td></td>
                                        <td>{{ $stepSubtotal['step_name'] }}<span class="gp-subline">{{ $stepSubtotal['step_code'] }}</span></td>
                                        <td>Step subtotal <span class="gp-subline">{{ number_format($stepDetails->count()) }} transactions</span></td>
                                        <td class="num">{{ $duration($stepSubtotal['hours'] * 60) }}</td>
                                        <td class="num">{{ $fmt($stepSubtotal['good_pcs'], 0) }}</td>
                                        <td class="num">{{ $fmt($stepSubtotal['bad_pcs'], 0) }}</td>
                                        <td></td>
                                    </tr>
                                    <tr class="gp-detail-row" data-detail-row="{{ $stepDetailTarget }}">
                                        <td colspan="7">
                                            <div class="gp-detail-panel">
                                                <div class="table-responsive">
                                                    <table class="table table-sm table-bordered gp-detail-table mb-0">
                                                        <thead><tr><th>Date</th><th>Step</th><th>Employee</th><th>Time</th><th class="num">Duration</th><th class="num">Good pcs</th><th class="num">Bad pcs</th><th>Note</th></tr></thead>
                                                        <tbody>
                                                            @forelse ($stepDetails as $detail)
                                                                <tr>
                                                                    <td>{{ \Carbon\Carbon::parse($detail->work_date)->format('d/m/Y') }}</td>
                                                                    <td>{{ $detail->step_name }}<span class="gp-subline">{{ $detail->step_code }}</span></td>
                                                                    <td>{{ $detail->employee_names }}<span class="gp-subline">{{ number_format($detail->team_size) }} คน</span></td>
                                                                    <td>{{ \Carbon\Carbon::parse($detail->started_at)->format('H:i') }}-{{ $detail->finished_at ? \Carbon\Carbon::parse($detail->finished_at)->format('H:i') : '-' }}</td>
                                                                    <td class="num">{{ $duration($detail->duration_minutes) }}</td>
                                                                    <td class="num">{{ $fmt($detail->good_qty_pcs, 0) }}</td>
                                                                    <td class="num">{{ $fmt($detail->bad_qty_pcs, 0) }}</td>
                                                                    <td>{{ $detail->notes }}</td>
                                                                </tr>
                                                            @empty
                                                                <tr><td colspan="8" class="text-center text-muted">No transaction detail</td></tr>
                                                            @endforelse
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                                <tr class="gp-detail-row" data-detail-row="{{ $mfgDetailTarget }}">
                                    <td colspan="7">
                                        <div class="gp-detail-panel">
                                            <div class="table-responsive">
                                                <table class="table table-sm table-bordered gp-detail-table mb-0">
                                                    <thead><tr><th>Date</th><th>Step</th><th>Employee</th><th>Time</th><th class="num">Duration</th><th class="num">Good pcs</th><th class="num">Bad pcs</th><th>Note</th></tr></thead>
                                                    <tbody>
                                                        @forelse ($mfgDetails as $detail)
                                                            <tr>
                                                                <td>{{ \Carbon\Carbon::parse($detail->work_date)->format('d/m/Y') }}</td>
                                                                <td>{{ $detail->step_name }}</td>
                                                                <td>{{ $detail->employee_names }}<span class="gp-subline">{{ number_format($detail->team_size) }} คน</span></td>
                                                                <td>{{ \Carbon\Carbon::parse($detail->started_at)->format('H:i') }}-{{ $detail->finished_at ? \Carbon\Carbon::parse($detail->finished_at)->format('H:i') : '-' }}</td>
                                                                <td class="num">{{ $duration($detail->duration_minutes) }}</td>
                                                                <td class="num">{{ $fmt($detail->good_qty_pcs, 0) }}</td>
                                                                <td class="num">{{ $fmt($detail->bad_qty_pcs, 0) }}</td>
                                                                <td>{{ $detail->notes }}</td>
                                                            </tr>
                                                        @empty
                                                            <tr><td colspan="8" class="text-center text-muted">No transaction detail</td></tr>
                                                        @endforelse
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted py-3">No MFG flow data</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <aside class="gp-total-panel">
                <div class="gp-total-head">
                    Subtotal / Total
                    <small>{{ $selectedEmployeeNames->isNotEmpty() ? 'กรอง: ' . $selectedEmployeeNames->implode(', ') : 'พนักงานทั้งหมด' }}</small>
                </div>
                <div class="gp-total-body">
                    <div class="gp-total-row"><span class="gp-total-label">MFG</span><span class="gp-total-value">{{ number_format($mfgTotals['mfgs']) }}</span></div>
                    <div class="gp-total-row"><span class="gp-total-label">Rows</span><span class="gp-total-value">{{ number_format($mfgTotals['rows']) }}</span></div>
                    <div class="gp-total-row"><span class="gp-total-label">เวลา</span><span class="gp-total-value">{{ $duration($mfgTotals['hours'] * 60) }}</span></div>
                    <div class="gp-total-row"><span class="gp-total-label">Good pcs</span><span class="gp-total-value">{{ $fmt($mfgTotals['good_pcs'], 0) }}</span></div>
                    <div class="gp-total-row"><span class="gp-total-label">Bad pcs</span><span class="gp-total-value">{{ $fmt($mfgTotals['bad_pcs'], 0) }}</span></div>
                    <div class="gp-subtotal-list">
                        @foreach ($mfgSubtotals as $mfgNo => $subtotal)
                            <div class="gp-total-row">
                                <span class="gp-total-label">{{ $mfgNo }}</span>
                                <span class="gp-total-value">{{ $fmt($subtotal['good_pcs'], 0) }} pcs<br><small>{{ $duration($subtotal['hours'] * 60) }}</small></span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </aside>
        </div>
        @endif

        @if ($viewMode === 'transactions')
        <div class="gp-mode-layout mb-3">
            <div class="gp-panel">
            <div class="gp-head"><span>รายการที่บันทึก</span><small class="text-muted">{{ method_exists($rows, 'total') ? number_format($rows->total()) : 0 }} records</small></div>
            <div class="gp-table-wrap">
                <table class="table table-sm table-bordered gp-table mb-0">
                    <thead>
                        <tr>
                            <th class="gp-sticky-date">วันที่</th>
                            <th class="gp-sticky-mfg">MFG</th>
                            <th class="gp-transaction-project">โครงการ / เลข SO</th>
                            <th class="gp-transaction-step">Step</th>
                            <th class="gp-transaction-employee">พนักงาน</th>
                            <th>เริ่ม</th>
                            <th>จบ</th>
                            <th class="num">ชม.</th>
                            <th class="num">ยอดดี</th>
                            <th class="num">ยอดเสีย</th>
                            <th class="num">แผน / เป้า</th>
                            <th>งานหน้างาน</th>
                            <th>จบงาน</th>
                            <th>หมายเหตุ</th>
                            <th>Created / Updated by</th>
                            <th class="gp-sticky-action">{{ auth()->user()?->can('GP') ? 'จัดการ' : 'รายละเอียด' }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            @php
                                $targetPcs = ($row->duration_minutes && ($row->target_pcs_per_hour ?? null)) ? ($row->duration_minutes / 60 * $row->target_pcs_per_hour) : 0;
                                $targetKg = ($row->duration_minutes && $row->target_kg_per_hour) ? ($row->duration_minutes / 60 * $row->target_kg_per_hour) : 0;
                            @endphp
                            <tr>
                                <td class="gp-sticky-date">{{ \Carbon\Carbon::parse($row->work_date)->format('d/m/Y') }}</td>
                                <td class="gp-sticky-mfg">
                                    @if ($row->is_field_work && ($row->field_mfgs ?? ''))
                                        {{ $row->field_mfgs }}
                                        <span class="gp-subline">งานหน้างาน</span>
                                    @else
                                        {{ $row->mfg_no }}
                                    @endif
                                </td>
                                <td class="gp-transaction-project">
                                    {{ ($row->project ?? null) ?: '-' }}
                                    @if($row->salesorder ?? null)
                                        <div class="small text-muted">{{ $row->salesorder }}</div>
                                    @endif
                                </td>
                                <td class="gp-transaction-step">{{ $row->step_name }}</td>
                                <td class="gp-transaction-employee">{{ $row->employee_names }}<div class="small text-muted">{{ number_format($row->team_size) }} คน</div></td>
                                <td>{{ \Carbon\Carbon::parse($row->started_at)->format('H:i') }}</td>
                                <td>{{ $row->finished_at ? \Carbon\Carbon::parse($row->finished_at)->format('H:i') : '-' }}</td>
                                <td class="num">{{ $duration($row->duration_minutes) }}</td>
                                <td class="num">
                                    <strong>{{ $fmt($row->good_qty_pcs, 0) }}</strong>
                                    <span class="gp-subline">{{ $fmt($row->good_qty_kg) }} กก.</span>
                                </td>
                                <td class="num">
                                    <strong>{{ $fmt($row->bad_qty_pcs, 0) }}</strong>
                                    <span class="gp-subline">{{ $fmt($row->bad_qty_kg) }} กก.</span>
                                </td>
                                <td class="num">
                                    <strong>{{ ($row->plan_qty_pcs ?? null) ? $fmt($row->plan_qty_pcs, 0) : '-' }}</strong>
                                    <span class="gp-subline">เป้า {{ $targetPcs > 0 ? $fmt($targetPcs, 0).' ชิ้น' : '-' }}</span>
                                    @if ($targetKg > 0)
                                        <span class="gp-subline">{{ $fmt($targetKg) }} กก.</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($row->is_field_work)
                                        <span class="badge bg-info text-dark">{{ $row->field_activity }}</span>
                                        <div class="small text-muted">{{ $row->field_details }}</div>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td>{!! $row->is_finished ? '<span class="badge bg-success">จบงาน</span>' : '<span class="badge bg-warning text-dark">ยังไม่จบ</span>' !!}</td>
                                <td>{{ $row->notes }}</td>
                                <td>
                                    {{ $row->created_by_name ?? '-' }}
                                    <div class="small text-muted">{{ $row->updated_by_name ?? '-' }}</div>
                                </td>
                                <td class="text-nowrap gp-sticky-action">
                                    <div class="d-grid gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-secondary gp-detail-toggle" data-detail-target="transaction-detail-{{ $row->id }}">Detail</button>
                                    @can('GP')
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#edit-entry-{{ $row->id }}">แก้ไข</button>
                                    <form method="POST" action="{{ route('grating-performance.entries.destroy', $row->id) }}" class="gp-delete-entry-form" data-entry-label="{{ $row->is_field_work ? ($row->project ?? 'Field work') : $row->mfg_no }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger w-100">ยกเลิก</button>
                                    </form>
                                    @endcan
                                    </div>
                                </td>
                            </tr>
                            <tr class="gp-detail-row" data-detail-row="transaction-detail-{{ $row->id }}">
                                <td colspan="16">
                                    <div class="gp-detail-panel">
                                        <div class="row g-2">
                                            <div class="col-md-3"><strong>Date</strong><div>{{ \Carbon\Carbon::parse($row->work_date)->format('d/m/Y') }}</div></div>
                                            <div class="col-md-3"><strong>MFG / SO</strong><div>{{ $row->is_field_work ? (($row->field_mfgs ?? '') ?: 'FIELD') : $row->mfg_no }}</div><span class="gp-subline">{{ $row->salesorder ?? '' }}</span></div>
                                            <div class="col-md-3"><strong>Step</strong><div>{{ $row->step_name }}</div></div>
                                            <div class="col-md-3"><strong>Employee</strong><div>{{ $row->employee_names }}</div><span class="gp-subline">{{ number_format($row->team_size) }} คน</span></div>
                                            <div class="col-md-3"><strong>Time</strong><div>{{ \Carbon\Carbon::parse($row->started_at)->format('H:i') }}-{{ $row->finished_at ? \Carbon\Carbon::parse($row->finished_at)->format('H:i') : '-' }}</div><span class="gp-subline">{{ $duration($row->duration_minutes) }}</span></div>
                                            <div class="col-md-3"><strong>Good</strong><div>{{ $fmt($row->good_qty_pcs, 0) }} pcs</div><span class="gp-subline">{{ $fmt($row->good_qty_kg) }} kg</span></div>
                                            <div class="col-md-3"><strong>Bad</strong><div>{{ $fmt($row->bad_qty_pcs, 0) }} pcs</div><span class="gp-subline">{{ $fmt($row->bad_qty_kg) }} kg</span></div>
                                            <div class="col-md-3"><strong>Plan</strong><div>{{ ($row->plan_qty_pcs ?? null) ? $fmt($row->plan_qty_pcs, 0) . ' pcs' : '-' }}</div></div>
                                            <div class="col-12"><strong>Note</strong><div>{{ $row->notes ?: '-' }}</div></div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="16" class="text-center text-muted py-4">ยังไม่มีข้อมูล</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @can('GP')
            @foreach ($rows as $row)
                @php
                    $startValue = $row->started_at ? \Carbon\Carbon::parse($row->started_at)->format('H:i') : '';
                    $finishValue = $row->finished_at ? \Carbon\Carbon::parse($row->finished_at)->format('H:i') : '';
                    $refUnitQty = ($row->good_qty_pcs ?? 0) > 0
                        ? ((float) $row->good_qty_kg / (float) $row->good_qty_pcs)
                        : ((($row->bad_qty_pcs ?? 0) > 0) ? ((float) $row->bad_qty_kg / (float) $row->bad_qty_pcs) : 0);
                @endphp
                <div class="modal fade" id="edit-entry-{{ $row->id }}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-scrollable">
                        <form method="POST" action="{{ route('grating-performance.entries.update', $row->id) }}" class="modal-content gp-edit-form" data-entry-id="{{ $row->id }}" data-mfg-no="{{ $row->mfg_no }}" data-field-work="{{ $row->is_field_work ? '1' : '0' }}">
                            @csrf
                            @method('PUT')
                            @if ($row->is_field_work && ($row->field_mfgs ?? ''))
                                @foreach (array_values(array_filter(array_map('trim', explode(',', $row->field_mfgs)))) as $fieldMfgIndex => $fieldMfgNo)
                                    <input type="hidden" name="field_mfgs[{{ $fieldMfgIndex }}][mfg_no]" value="{{ $fieldMfgNo }}">
                                @endforeach
                            @endif
                            <div class="modal-header">
                                <h5 class="modal-title">แก้ไขรายการ {{ $row->mfg_no }}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">วันที่</label>
                                        <input type="date" name="work_date" class="form-control" value="{{ \Carbon\Carbon::parse($row->work_date)->toDateString() }}" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Step</label>
                                        <select name="step_id" class="form-select gp-edit-step" required>
                                            @foreach ($steps as $step)
                                                <option value="{{ $step->id }}" @selected((string) $row->step_id === (string) $step->id)>{{ $step->step_name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">เริ่ม</label>
                                        <input type="text" name="start_time" class="form-control gp-edit-time" value="{{ $startValue }}" maxlength="5" inputmode="numeric" pattern="^([01][0-9]|2[0-3]):[0-5][0-9]$" placeholder="08:50" title="รูปแบบเวลา HH:MM เช่น 08:50" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">จบ</label>
                                        <input type="text" name="finish_time" class="form-control gp-edit-time" value="{{ $finishValue }}" maxlength="5" inputmode="numeric" pattern="^([01][0-9]|2[0-3]):[0-5][0-9]$" placeholder="17:30" title="รูปแบบเวลา HH:MM เช่น 17:30">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">ยอดดี (ชิ้น)</label>
                                        <input type="number" name="good_qty_pcs" class="form-control num gp-edit-good-pcs" step="1" min="0" value="{{ (float) $row->good_qty_pcs }}">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">ยอดเสีย (ชิ้น)</label>
                                        <input type="number" name="bad_qty_pcs" class="form-control num gp-edit-bad-pcs" step="1" min="0" value="{{ (float) $row->bad_qty_pcs }}">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">ยอดดี (กก.)</label>
                                        <input type="number" name="good_qty_kg" class="form-control num gp-edit-good-kg" step="0.001" min="0" value="{{ (float) $row->good_qty_kg }}" @if($refUnitQty > 0) readonly @endif>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">ยอดเสีย (กก.)</label>
                                        <input type="number" name="bad_qty_kg" class="form-control num gp-edit-bad-kg" step="0.001" min="0" value="{{ (float) $row->bad_qty_kg }}" @if($refUnitQty > 0) readonly @endif>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">จำนวนแผน (ชิ้น)</label>
                                        <input type="number" name="plan_qty_pcs" class="form-control num gp-edit-plan" step="1" min="0" value="{{ $row->plan_qty_pcs ?? '' }}">
                                        <div class="form-text gp-edit-balance"></div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">ตร.ม./ชิ้น</label>
                                        <input type="number" name="sqm_per_piece" class="form-control num" step="0.000001" min="0" value="{{ $row->sqm_per_piece }}">
                                    </div>
                                    <input type="hidden" name="ref_unit_qty" class="gp-edit-ref" value="{{ $refUnitQty }}">
                                    <div class="col-md-3">
                                        <label class="form-label">โครงการ</label>
                                        <input type="text" name="project" class="form-control" maxlength="500" value="{{ $row->project ?? '' }}" @required($row->is_field_work)>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">เลข SO</label>
                                        <input type="text" name="salesorder" class="form-control" maxlength="80" value="{{ $row->salesorder ?? '' }}" @required($row->is_field_work)>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">กิจกรรมหน้างาน</label>
                                        <input type="text" name="field_activity" class="form-control" maxlength="500" value="{{ $row->field_activity }}">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">MFG หน้างาน</label>
                                        <input type="text" name="field_mfg_text" class="form-control" maxlength="1000" value="{{ $row->field_mfgs ?? '' }}" placeholder="คั่นหลาย MFG ด้วย comma">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">รายละเอียดงานหน้างาน</label>
                                        <textarea name="field_details" class="form-control gp-autosize" maxlength="1000" rows="1">{{ $row->field_details }}</textarea>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label d-block">จบงาน</label>
                                        <div class="form-check mt-2">
                                            <input type="hidden" name="is_finished" value="0">
                                            <input class="form-check-input" type="checkbox" name="is_finished" value="1" @checked($row->is_finished)>
                                            <label class="form-check-label">จบแล้ว</label>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">หมายเหตุ</label>
                                        <textarea name="notes" class="form-control gp-autosize" maxlength="1000" rows="1">{{ $row->notes }}</textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button>
                                <button type="submit" class="btn btn-primary">บันทึกแก้ไข</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endforeach
            @endcan
            @if (method_exists($rows, 'links'))
                <div class="p-3">{{ $rows->links() }}</div>
            @endif
            </div>
            <aside class="gp-total-panel">
                <div class="gp-total-head">
                    Subtotal / Total
                    <small>{{ $selectedEmployeeNames->isNotEmpty() ? 'กรอง: ' . $selectedEmployeeNames->implode(', ') : 'พนักงานทั้งหมด' }}</small>
                </div>
                <div class="gp-total-body">
                    <div class="gp-total-row"><span class="gp-total-label">Records</span><span class="gp-total-value">{{ number_format($summary['entry_count'] ?? 0) }}</span></div>
                    <div class="gp-total-row"><span class="gp-total-label">MFG</span><span class="gp-total-value">{{ number_format($summary['mfg_count'] ?? 0) }}</span></div>
                    <div class="gp-total-row"><span class="gp-total-label">Good pcs</span><span class="gp-total-value">{{ $fmt($summary['good_pcs'] ?? 0, 0) }}</span></div>
                    <div class="gp-total-row"><span class="gp-total-label">Good kg</span><span class="gp-total-value">{{ $fmt($summary['good_kg'] ?? 0) }}</span></div>
                    <div class="gp-total-row"><span class="gp-total-label">Bad pcs</span><span class="gp-total-value">{{ $fmt($summary['bad_pcs'] ?? 0, 0) }}</span></div>
                    <div class="gp-total-row"><span class="gp-total-label">Bad kg</span><span class="gp-total-value">{{ $fmt($summary['bad_kg'] ?? 0) }}</span></div>
                    <div class="gp-total-row"><span class="gp-total-label">Finished</span><span class="gp-total-value">{{ number_format($summary['finished_count'] ?? 0) }}</span></div>
                    <div class="gp-total-row"><span class="gp-total-label">Open</span><span class="gp-total-value">{{ number_format($summary['open_count'] ?? 0) }}</span></div>
                </div>
            </aside>
        </div>
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            const gpStepBalanceUrl = @json(route('grating-performance.step-balance'));

            document.addEventListener('click', function (event) {
                const trigger = event.target.closest('.gp-detail-toggle, .gp-detail-click');
                if (!trigger) return;

                const target = trigger.dataset.detailTarget;
                if (!target) return;

                let isOpen = false;
                document.querySelectorAll(`[data-detail-row="${CSS.escape(target)}"]`).forEach(row => {
                    row.classList.toggle('is-open');
                    isOpen = row.classList.contains('is-open');
                });

                trigger.classList.toggle('is-open', isOpen);
                trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

                if (trigger.classList.contains('gp-detail-toggle')) {
                    trigger.dataset.closedLabel = trigger.dataset.closedLabel || trigger.textContent.trim();
                    trigger.textContent = isOpen ? 'Hide detail' : trigger.dataset.closedLabel;
                }
            });

            function numberValue(value) {
                const parsed = parseFloat(String(value ?? '').replace(/,/g, ''));
                return Number.isFinite(parsed) ? parsed : 0;
            }

            function formatNumber(value, digits = 0) {
                return numberValue(value).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: digits });
            }

            function autoGrow(area) {
                if (!area) return;
                area.style.height = 'auto';
                area.style.height = area.scrollHeight + 'px';
            }

            // เวลา: ใส่ ":" อัตโนมัติ HH:MM
            function formatTime(input) {
                const digits = input.value.replace(/[^0-9]/g, '').slice(0, 4);
                input.value = digits.length >= 3 ? `${digits.slice(0, 2)}:${digits.slice(2)}` : digits;
            }

            // คำนวณ กก. = ชิ้น × กก./ชิ้น (เหมือนหน้า create) เมื่อมี ref_unit_qty
            function recalcKg(form) {
                const ref = numberValue(form.querySelector('.gp-edit-ref')?.value);
                if (ref <= 0) return;
                const goodKg = form.querySelector('.gp-edit-good-kg');
                const badKg = form.querySelector('.gp-edit-bad-kg');
                if (goodKg) goodKg.value = (numberValue(form.querySelector('.gp-edit-good-pcs')?.value) * ref).toFixed(3);
                if (badKg) badKg.value = (numberValue(form.querySelector('.gp-edit-bad-pcs')?.value) * ref).toFixed(3);
            }

            function setBalanceText(form, data) {
                const target = form.querySelector('.gp-edit-balance');
                if (!data) {
                    form.dataset.usedQtyPcs = '0';
                    if (target) target.textContent = '';
                    return;
                }
                form.dataset.usedQtyPcs = String(data.used_qty_pcs || 0);
                if (target) {
                    target.textContent = `แผน ${formatNumber(data.plan_qty_pcs)} / ทำแล้ว (ไม่รวมรายการนี้) ${formatNumber(data.used_qty_pcs)} / คงเหลือ ${formatNumber(data.remaining_qty_pcs)}`;
                }
            }

            // ดึงยอดที่ทำแล้วของ MFG+step โดยไม่นับรายการที่กำลังแก้ (exclude_entry_id)
            function fetchEditBalance(form) {
                const mfgNo = (form.dataset.mfgNo || '').trim();
                const entryId = form.dataset.entryId;
                const stepId = form.querySelector('.gp-edit-step')?.value;
                const planQty = numberValue(form.querySelector('.gp-edit-plan')?.value);

                if (form.dataset.fieldWork === '1' || !mfgNo || !stepId || !planQty) {
                    setBalanceText(form, null);
                    return;
                }

                const params = new URLSearchParams({
                    mfg_no: mfgNo,
                    step_id: stepId,
                    plan_qty_pcs: String(planQty),
                    exclude_entry_id: String(entryId || '')
                });

                fetch(`${gpStepBalanceUrl}?${params.toString()}`, { headers: { 'Accept': 'application/json' } })
                    .then(response => response.ok ? response.json() : null)
                    .then(data => setBalanceText(form, data))
                    .catch(() => setBalanceText(form, null));
            }

            function validateEditForm(form) {
                if (form.dataset.fieldWork === '1') return true;
                const planQty = numberValue(form.querySelector('.gp-edit-plan')?.value);
                if (!planQty) return true;
                const totalPcs = numberValue(form.querySelector('.gp-edit-good-pcs')?.value) + numberValue(form.querySelector('.gp-edit-bad-pcs')?.value);
                const usedPcs = numberValue(form.dataset.usedQtyPcs || 0);
                return (usedPcs + totalPcs) <= (planQty + 0.0001);
            }

            function bindProjectAutocomplete() {
                document.querySelectorAll('.gp-project-autocomplete').forEach(projectInput => {
                    const wrap = projectInput.closest('.gp-project-wrap');
                    const list = wrap?.querySelector('.gp-project-list');
                    const salesorderInput = projectInput.closest('form')?.querySelector('[name="salesorder"]');
                    let timer = null;
                    if (!list) return;

                    projectInput.addEventListener('input', function () {
                        clearTimeout(timer);
                        const q = projectInput.value.trim();
                        if (q.length < 2) {
                            list.style.display = 'none';
                            return;
                        }
                        timer = setTimeout(function () {
                            fetch('{{ route('api.grating-projects.search') }}?q=' + encodeURIComponent(q) + '&limit=8', { headers: { 'Accept': 'application/json' } })
                                .then(response => response.ok ? response.json() : { results: [] })
                                .then(data => {
                                    list.innerHTML = '';
                                    (data.results || []).forEach(item => {
                                        const meta = item.meta || {};
                                        const button = document.createElement('button');
                                        button.type = 'button';
                                        button.innerHTML = `
                                            <div class="gp-mfg-item-main">${item.text || item.id || ''}</div>
                                            <div class="gp-mfg-item-sub">${meta.source === 'master' ? 'Master' : (meta.site || 'WO Notes')} | SO: ${meta.salesorder || '-'}</div>
                                        `;
                                        button.addEventListener('click', function () {
                                            projectInput.value = meta.project || item.text || item.id || '';
                                            if (salesorderInput && !salesorderInput.value && meta.salesorder) {
                                                salesorderInput.value = meta.salesorder;
                                            }
                                            list.style.display = 'none';
                                        });
                                        list.appendChild(button);
                                    });
                                    list.style.display = list.children.length ? 'block' : 'none';
                                });
                        }, 250);
                    });
                });
            }

            document.querySelectorAll('.gp-select').forEach(function (element) {
                if (window.TomSelect && !element.tomselect) {
                    new TomSelect(element, {
                        plugins: element.multiple ? ['remove_button'] : [],
                        dropdownParent: 'body',
                        placeholder: element.dataset.placeholder || '',
                    });
                }
            });

            bindProjectAutocomplete();

            // --- event delegation ---
            document.addEventListener('input', function (event) {
                const t = event.target;
                if (t.classList.contains('gp-autosize')) autoGrow(t);
                if (t.classList.contains('gp-edit-time')) formatTime(t);
                if (t.classList.contains('gp-edit-good-pcs') || t.classList.contains('gp-edit-bad-pcs')) {
                    recalcKg(t.closest('.gp-edit-form'));
                }
                if (t.classList.contains('gp-edit-plan')) {
                    fetchEditBalance(t.closest('.gp-edit-form'));
                }
            });

            document.addEventListener('change', function (event) {
                if (event.target.classList.contains('gp-edit-step')) {
                    fetchEditBalance(event.target.closest('.gp-edit-form'));
                }
            });

            document.addEventListener('shown.bs.modal', function (event) {
                event.target.querySelectorAll('.gp-autosize').forEach(autoGrow);
                const form = event.target.querySelector('.gp-edit-form');
                if (form) {
                    recalcKg(form);
                    fetchEditBalance(form);
                }
            });

            document.addEventListener('submit', function (event) {
                const form = event.target.closest('.gp-edit-form');
                if (!form) return;
                if (!validateEditForm(form)) {
                    event.preventDefault();
                    const planQty = numberValue(form.querySelector('.gp-edit-plan')?.value);
                    if (window.Swal) {
                        Swal.fire({
                            icon: 'error',
                            title: 'จำนวนเกินแผนเปิด',
                            text: `จำนวนชิ้น (ที่ทำแล้ว + ที่กำลังแก้) เกินแผนเปิด ${formatNumber(planQty)} ชิ้นของ step นี้`,
                        });
                    } else {
                        alert('จำนวนชิ้นเกินแผนเปิดของ step นี้');
                    }
                }
            });

            document.addEventListener('submit', function (event) {
                const form = event.target.closest('.gp-delete-entry-form');
                if (!form || form.dataset.confirmed === '1') return;

                event.preventDefault();
                const entryLabel = form.dataset.entryLabel || '';
                const message = entryLabel
                    ? `ยืนยันยกเลิกรายการ ${entryLabel}?`
                    : 'ยืนยันยกเลิกรายการนี้?';

                if (!window.Swal) {
                    if (window.confirm(message)) {
                        form.dataset.confirmed = '1';
                        form.submit();
                    }
                    return;
                }

                const scrollY = window.scrollY;
                Swal.fire({
                    icon: 'warning',
                    title: 'ยืนยันยกเลิกรายการ?',
                    text: message,
                    showCancelButton: true,
                    confirmButtonText: 'ยกเลิกรายการ',
                    cancelButtonText: 'กลับ',
                    confirmButtonColor: '#dc3545',
                    heightAuto: false,
                    returnFocus: false,
                    didOpen: () => window.scrollTo({ top: scrollY }),
                }).then(result => {
                    if (result.isConfirmed) {
                        form.dataset.confirmed = '1';
                        form.submit();
                    }
                });
            });

            document.addEventListener('click', function (event) {
                document.querySelectorAll('.gp-project-list').forEach(list => {
                    const wrap = list.closest('.gp-project-wrap');
                    if (wrap && !wrap.contains(event.target)) {
                        list.style.display = 'none';
                    }
                });
            });
        })();
    </script>
@endpush
