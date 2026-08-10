@extends('layouts.layout')

@section('title', 'Grating Performance')
@section('page-title', 'Grating Performance')

@section('content')
    @php
        $fmt = fn($value, $dec = 1) => number_format((float) $value, $dec);
        $hours = fn($minutes) => $minutes ? number_format($minutes / 60, 1) : '0.0';
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
        $achievement = (($summary['target_bath'] ?? 0) > 0 && ($summary['output_bath'] ?? null) !== null)
            ? (($summary['output_bath'] / $summary['target_bath']) * 100)
            : null;
    @endphp

    <style>
        /* ── Layout ─────────────────────────────────────────────────── */
        .gp-wrap { background:#f5f7fa; border-radius:8px; padding:12px; }
        @media (min-width:768px) { .gp-wrap { padding:16px; } }
        .gp-panel { background:#fff; border:1px solid #dfe5ec; border-radius:8px; overflow:visible; }

        /* ── Section headers ─────────────────────────────────────────── */
        .gp-head {
            padding:12px 16px;
            border-bottom:1px solid #e8edf2;
            border-left:4px solid #4a6cf7;
            background:#f8f9ff;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            font-weight:700;
            border-radius:8px 8px 0 0;
        }
        .gp-panel.is-collapsed > :not(.gp-head) { display:none !important; }
        .gp-panel.is-collapsed > .gp-head { border-bottom:0; border-radius:8px; }
        .gp-collapse-toggle { flex:0 0 auto; line-height:1; }
        .gp-collapse-toggle i { transition:transform .18s ease; }
        .gp-panel.is-collapsed .gp-collapse-toggle i { transform:rotate(-90deg); }

        /* ── KPI cards ───────────────────────────────────────────────── */
        .gp-kpi {
            height:100%;
            background:#fff;
            border:1px solid #dfe5ec;
            border-radius:8px;
            padding:14px 14px 14px 18px;
            position:relative;
            overflow:hidden;
        }
        .gp-kpi::before {
            content:'';
            position:absolute;
            left:0; top:0; bottom:0;
            width:4px;
            border-radius:8px 0 0 8px;
        }
        .gp-kpi.kpi-blue::before   { background:#2563eb; }
        .gp-kpi.kpi-green::before  { background:#16a34a; }
        .gp-kpi.kpi-red::before    { background:#dc2626; }
        .gp-kpi.kpi-purple::before { background:#7c3aed; }
        .gp-kpi.kpi-indigo::before { background:#4338ca; }
        .gp-kpi.kpi-orange::before { background:#ea580c; }
        .gp-kpi.kpi-achv-green::before  { background:#16a34a; }
        .gp-kpi.kpi-achv-amber::before  { background:#d97706; }
        .gp-kpi.kpi-achv-red::before    { background:#dc2626; }

        .gp-kpi .kpi-icon {
            font-size:1.1rem;
            margin-bottom:6px;
            opacity:.6;
        }
        .gp-kpi.kpi-blue   .kpi-icon { color:#2563eb; }
        .gp-kpi.kpi-green  .kpi-icon { color:#16a34a; }
        .gp-kpi.kpi-red    .kpi-icon { color:#dc2626; }
        .gp-kpi.kpi-purple .kpi-icon { color:#7c3aed; }
        .gp-kpi.kpi-indigo .kpi-icon { color:#4338ca; }
        .gp-kpi.kpi-orange .kpi-icon { color:#ea580c; }
        .gp-kpi.kpi-achv-green .kpi-icon { color:#16a34a; }
        .gp-kpi.kpi-achv-amber .kpi-icon { color:#d97706; }
        .gp-kpi.kpi-achv-red   .kpi-icon { color:#dc2626; }

        .gp-kpi .label { color:#667085; font-size:.8rem; }
        .gp-kpi .value { color:#1f2937; font-size:1.35rem; font-weight:800; line-height:1.2; margin-top:4px; }
        .gp-kpi.kpi-achv .value { font-size:1.8rem; }
        .gp-kpi .hint { color:#667085; font-size:.76rem; margin-top:2px; }

        /* mini progress bar inside % Target card */
        .gp-kpi .kpi-progress {
            height:5px;
            border-radius:3px;
            background:#e5e7eb;
            margin-top:8px;
            overflow:hidden;
        }
        .gp-kpi .kpi-progress-fill {
            height:100%;
            border-radius:3px;
            transition:width .4s ease;
        }
        .kpi-achv-green .kpi-progress-fill { background:#16a34a; }
        .kpi-achv-amber .kpi-progress-fill { background:#d97706; }
        .kpi-achv-red   .kpi-progress-fill { background:#dc2626; }

        /* ── Filter panel ────────────────────────────────────────────── */
        form.gp-filter-panel { background:#eef4ff; }

        /* ── Tables ──────────────────────────────────────────────────── */
        .gp-table-wrap { max-height:460px; overflow:auto; }
        .gp-table th { position:sticky; top:0; z-index:2; background:#edf4ff; white-space:nowrap; }
        .gp-table td { vertical-align:middle; }
        .num { text-align:right; font-variant-numeric:tabular-nums; }
        .gp-employee-summary-row { cursor:pointer; }
        .gp-employee-summary-row:hover { background:#f8fbff; }
        .gp-employee-summary-row .gp-row-chevron { transition:transform .18s ease; }
        .gp-employee-summary-row.is-open .gp-row-chevron { transform:rotate(90deg); }
        .gp-employee-step-detail { display:none; background:#f8fafc; }
        .gp-employee-step-detail.is-open { display:table-row; }
        .gp-employee-step-detail > td { padding:10px 14px !important; }
        .gp-employee-step-table th { background:#f1f5f9; position:static; }
        .gp-mfg-summary-row { cursor:pointer; }
        .gp-mfg-summary-row:hover { filter:brightness(.98); }
        .gp-mfg-summary-row .gp-row-chevron { transition:transform .18s ease; }
        .gp-mfg-summary-row.is-open .gp-row-chevron { transform:rotate(90deg); }
        .gp-mfg-step-row { display:none; }
        .gp-mfg-step-row.is-open { display:table-row; }

        /* highlight unfinished/slow rows */
        .gp-table tbody tr.table-warning { background:#fff8e1 !important; }
        .gp-table tbody tr.table-warning:hover { background:#fff3cc !important; }

        /* ── Misc ────────────────────────────────────────────────────── */
        .ts-dropdown { z-index:3000 !important; }
        .gp-mfg-list { position:absolute; z-index:20; background:#fff; border:1px solid #ced4da; border-radius:6px; width:100%; max-height:240px; overflow:auto; display:none; }
        .gp-mfg-list button { display:block; width:100%; border:0; background:#fff; padding:8px 10px; text-align:left; }
        .gp-mfg-list button:hover { background:#eef4ff; }
        .gp-project-wrap { position:relative; }
        .gp-mfg-item-main { font-weight:700; color:#1f2937; }
        .gp-mfg-item-sub { font-size:.78rem; color:#64748b; margin-top:2px; }

        /* ── Slow-step cards ─────────────────────────────────────────── */
        .gp-slow-list { display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:10px; padding:12px 16px 16px; }
        .gp-slow-item {
            border:1px solid #dfe5ec;
            border-left:4px solid #dfe5ec;
            border-radius:8px;
            padding:10px 12px;
            background:#fbfdff;
        }
        .gp-slow-item.slow-red    { border-left-color:#dc2626; }
        .gp-slow-item.slow-yellow { border-left-color:#d97706; }
        .gp-slow-item.slow-green  { border-left-color:#16a34a; }
        .gp-slow-item .name { font-weight:700; margin-bottom:6px; }
        .gp-slow-item.slow-red    .name { color:#dc2626; }
        .gp-slow-item.slow-yellow .name { color:#b45309; }
        .gp-slow-item.slow-green  .name { color:#15803d; }
        .gp-slow-item .meta { display:flex; flex-wrap:wrap; gap:6px; color:#667085; font-size:.78rem; }
        .gp-slow-item .metric { display:flex; justify-content:space-between; gap:10px; font-variant-numeric:tabular-nums; }

        /* ── Responsive ──────────────────────────────────────────────── */
        .gp-head { flex-wrap:wrap; gap:8px; }
        .gp-head small { font-size:.75rem; }
        .gp-table-wrap { -webkit-overflow-scrolling:touch; }
        @media (max-width:767px) {
            .gp-kpi .value { font-size:1.15rem; }
            .gp-kpi.kpi-achv .value { font-size:1.5rem; }
            .gp-slow-list { grid-template-columns:1fr 1fr; gap:8px; padding:10px; }
        }
        @media (max-width:479px) {
            .gp-slow-list { grid-template-columns:1fr; }
        }
    </style>

    <div class="gp-wrap">
        @if (!empty($setupMissing))
            <div class="alert alert-warning">
                ยังไม่พบตาราง Grating Performance กรุณารัน migration ก่อนใช้งาน: <code>php artisan migrate</code>
            </div>
        @endif

        {{-- Section 1: Top bar with nav buttons --}}
        <div class="gp-panel mb-3">
            <div class="gp-head">
                <span>Dashboard</span>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="gp-collapse-all"><i class="fas fa-compress-alt me-1"></i> ย่อทั้งหมด</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="gp-expand-all"><i class="fas fa-expand-alt me-1"></i> ขยายทั้งหมด</button>
                    @can('GP')
                    <a href="{{ route('grating-performance.entries.create') }}" class="btn btn-sm btn-primary"><i class="fas fa-plus me-1"></i> Input</a>
                    @endcan
                    <a href="{{ route('grating-performance.inquiry') }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-table me-1"></i> Inquiry</a>
                    @can('GPM')
                    <a href="{{ route('grating-performance.masters') }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-sliders me-1"></i> Masters</a>
                    @endcan
                </div>
            </div>
        </div>

        {{-- Section 2: Filter form --}}
        <form method="GET" class="gp-panel gp-filter-panel p-3 mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                    <label class="form-label">ช่วงสรุป</label>
                    <select name="period" class="form-select form-select-sm" id="gp-period-select">
                        <option value="day" @selected(($filters['period'] ?? 'day') === 'day')>รายวัน</option>
                        <option value="week" @selected(($filters['period'] ?? 'day') === 'week')>รายสัปดาห์</option>
                        <option value="month" @selected(($filters['period'] ?? 'day') === 'month')>รายเดือน</option>
                    </select>
                </div>
                <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                    <label class="form-label">จากวันที่</label>
                    <input type="date" name="date_from" id="gp-date-from" class="form-control form-control-sm" value="{{ $filters['date_from'] }}">
                </div>
                <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                    <label class="form-label">ถึงวันที่</label>
                    <input type="date" name="date_to" id="gp-date-to" class="form-control form-control-sm" value="{{ $filters['date_to'] }}">
                </div>
                <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                    <label class="form-label">MFG</label>
                    <input type="text" name="mfg_no" class="form-control form-control-sm" value="{{ $filters['mfg_no'] }}">
                </div>
                <div class="col-6 col-sm-4 col-md-3 col-lg-2 gp-project-wrap">
                    <label class="form-label">โครงการ</label>
                    <input type="text" name="project" class="form-control form-control-sm gp-project-autocomplete" value="{{ $filters['project'] ?? '' }}" autocomplete="off" placeholder="พิมพ์บางส่วนของชื่อได้" title="ค้นหาแบบมีคำนี้อยู่ในชื่อโครงการ">
                    <div class="gp-project-list gp-mfg-list"></div>
                </div>
                <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                    <label class="form-label">เลข SO</label>
                    <input type="text" name="salesorder" class="form-control form-control-sm" value="{{ $filters['salesorder'] ?? '' }}">
                </div>
                <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                    <label class="form-label">Step</label>
                    <select name="step_id" class="form-select form-select-sm">
                        <option value="">ทั้งหมด</option>
                        @foreach ($steps as $step)
                            <option value="{{ $step->id }}" @selected((string) $filters['step_id'] === (string) $step->id)>{{ $step->step_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                    <label class="form-label">พนักงาน</label>
                    <select name="employee_ids[]" class="form-select form-select-sm gp-select" multiple data-placeholder="พนักงานทั้งหมด">
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}" @selected(in_array((int) $employee->id, $filters['employee_ids'] ?? [], true))>{{ $employee->name }}{{ trim((string) ($employee->nickname ?? '')) !== '' ? ' - ' . trim($employee->nickname) : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-sm-4 col-md-3 col-lg-2 d-grid">
                    <button class="btn btn-primary btn-sm"><i class="fas fa-filter me-1"></i> Filter</button>
                </div>
            </div>
        </form>

        {{-- Section 3: KPI Cards --}}
        @php
            $achvClass = 'kpi-achv-red';
            if ($achievement !== null) {
                if ($achievement >= 100) $achvClass = 'kpi-achv-green';
                elseif ($achievement >= 80) $achvClass = 'kpi-achv-amber';
            }
            $achvBarWidth = $achievement !== null ? min(100, round($achievement)) : 0;
        @endphp
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-4 col-xl-2">
                <div class="gp-kpi kpi-blue">
                    <div class="kpi-icon"><i class="fas fa-cubes"></i></div>
                    <div class="label">MFG</div>
                    <div class="value">{{ number_format($summary['mfg_count'] ?? 0) }}</div>
                    <div class="hint">จำนวน MFG ในช่วงนี้</div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="gp-kpi kpi-green">
                    <div class="kpi-icon"><i class="fas fa-boxes"></i></div>
                    <div class="label">ยอดดีรวม</div>
                    <div class="value">{{ $fmt($summary['good_pcs'] ?? 0, 0) }}</div>
                    <div class="hint">ชิ้นจาก step ล่าสุด | {{ $fmt($summary['good_kg'] ?? 0) }} กก. | PACK: {{ $fmt($summary['pack_kg'] ?? 0) }} กก.</div>
                    <div class="hint">{{ $fmt($summary['good_area_sqm'] ?? 0) }} ตร.ม.</div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="gp-kpi kpi-red">
                    <div class="kpi-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="label">ยอดเสีย</div>
                    <div class="value">{{ $fmt($summary['bad_pcs'] ?? 0, 0) }}</div>
                    <div class="hint">ชิ้นจาก step ล่าสุด | {{ $fmt($summary['bad_kg'] ?? 0) }} กก. | {{ $fmt($summary['bad_area_sqm'] ?? 0) }} ตร.ม.</div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="gp-kpi kpi-purple">
                    <div class="kpi-icon"><i class="fas fa-clock"></i></div>
                    <div class="label">ชั่วโมงเดินงานจริง</div>
                    <div class="value">{{ $hours($summary['minutes'] ?? 0) }}</div>
                    <div class="hint">ตัดช่วงเวลาซ้อนทุกงานแล้ว</div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="gp-kpi kpi-indigo">
                    <div class="kpi-icon"><i class="fas fa-calendar-day"></i></div>
                    <div class="label">ชม./วัน</div>
                    <div class="value">{{ $fmt($summary['hours_per_day'] ?? 0) }}</div>
                    <div class="hint">เฉลี่ย {{ number_format($summary['work_days'] ?? 0) }} วันที่มีงาน · ช่วง {{ number_format($summary['range_days'] ?? 1) }} วัน</div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="gp-kpi kpi-orange">
                    <div class="kpi-icon"><i class="fas fa-bullseye"></i></div>
                    <div class="label">เป้าหมาย (บาท)</div>
                    <div class="value">{{ number_format($summary['target_bath'] ?? 0, 0) }}</div>
                    @if (($summary['target_months'] ?? 0) > 1)
                        <div class="hint">{{ $summary['target_months'] }} เดือน × 1.7M · D8 / FG GRATING</div>
                    @else
                        <div class="hint">1.7M/เดือน · D8 / FG GRATING</div>
                    @endif
                </div>
            </div>
            <div class="col-12 col-md-8 col-xl-2">
                <div class="gp-kpi kpi-achv {{ $achvClass }}">
                    <div class="kpi-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="label">% เป้าหมาย</div>
                    <div class="value">{{ $achievement === null ? '-' : $fmt($achievement, 0).'%' }}</div>
                    <div class="hint">{{ number_format($summary['finished'] ?? 0) }} งานจบ</div>
                    @if ($achievement !== null)
                        <div class="kpi-progress">
                            <div class="kpi-progress-fill" style="width:{{ $achvBarWidth }}%"></div>
                        </div>
                    @endif
                    @if (($summary['avg_price_per_kg'] ?? null) !== null)
                        <div class="hint mt-1">PACK {{ $fmt($summary['pack_kg'] ?? 0) }} kg x {{ number_format($summary['avg_price_per_kg'], 0) }} บ./kg = {{ number_format($summary['output_bath'] ?? 0, 0) }} บ.</div>
                    @else
                        <div class="hint mt-1 text-warning">ไม่พบราคาเฉลี่ยจาก SO</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="gp-panel mb-3">
            <div class="gp-head">
                <span>เปรียบเทียบยอดดี / ยอดเสีย</span>
                <small class="text-muted">นับผลผลิตเฉพาะ step ล่าสุดของแต่ละ MFG</small>
            </div>
            <div class="row g-2 p-3">
                @foreach (($outputComparison ?? []) as $comparison)
                    <div class="col-12 col-md-4">
                        <div class="border rounded p-3 h-100 bg-light">
                            <div class="fw-bold">{{ $comparison['label'] }}</div>
                            <div class="small text-muted mb-2">
                                ปัจจุบัน {{ \Carbon\Carbon::parse($comparison['current_from'])->format('d/m/Y') }}
                                @if($comparison['current_from'] !== $comparison['current_to'])
                                    - {{ \Carbon\Carbon::parse($comparison['current_to'])->format('d/m/Y') }}
                                @endif
                                · ก่อนหน้า {{ \Carbon\Carbon::parse($comparison['previous_from'])->format('d/m/Y') }}
                                @if($comparison['previous_from'] !== $comparison['previous_to'])
                                    - {{ \Carbon\Carbon::parse($comparison['previous_to'])->format('d/m/Y') }}
                                @endif
                            </div>
                            <div class="d-flex justify-content-between gap-2">
                                <span>ยอดดี {{ $fmt($comparison['current_good_pcs'], 0) }} / {{ $fmt($comparison['previous_good_pcs'], 0) }} ชิ้น</span>
                                <strong class="{{ $comparison['good_delta'] >= 0 ? 'text-success' : 'text-danger' }}">
                                    {{ $comparison['good_delta'] >= 0 ? '+' : '' }}{{ $fmt($comparison['good_delta'], 0) }}
                                </strong>
                            </div>
                            <div class="d-flex justify-content-between gap-2">
                                <span>ยอดเสีย {{ $fmt($comparison['current_bad_pcs'], 0) }} / {{ $fmt($comparison['previous_bad_pcs'], 0) }} ชิ้น</span>
                                <strong class="{{ $comparison['bad_delta'] <= 0 ? 'text-success' : 'text-danger' }}">
                                    {{ $comparison['bad_delta'] >= 0 ? '+' : '' }}{{ $fmt($comparison['bad_delta'], 0) }}
                                </strong>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Employee efficiency follows the good/bad comparison for quick review. --}}
        <div class="gp-panel mb-3">
            <div class="gp-head">
                <span>เปรียบเทียบพนักงานใน Step เดียวกัน</span>
                <small class="text-muted">เวลาเฉลี่ยต่อชิ้น ยิ่งน้อยยิ่งเร็ว พร้อมอันดับใน step เดียวกัน</small>
            </div>
            @if ($employeeStepEfficiency->isNotEmpty())
                @php
                    $effSteps = $employeeStepEfficiency
                        ->sortBy([['sort_order', 'asc'], ['step_code', 'asc']])
                        ->unique('step_id')
                        ->values();
                    $effByEmp = $employeeStepEfficiency->groupBy('employee_name');
                @endphp
                <div class="gp-table-wrap" style="max-height:320px;">
                    <table class="table table-sm table-bordered table-hover gp-table mb-0">
                        <thead>
                            <tr>
                                <th>พนักงาน</th>
                                @foreach ($effSteps as $effStep)
                                    <th class="num text-center" style="font-size:.75rem;">{{ $effStep->step_name }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($effByEmp as $empName => $empRows)
                                <tr>
                                    <td class="fw-bold">{{ $empName }}</td>
                                    @foreach ($effSteps as $effStep)
                                        @php
                                            $cell = $empRows->firstWhere('step_id', $effStep->step_id);
                                            $minutesPerPiece = $cell->minutes_per_piece ?? null;
                                            $rank = $cell->step_rank ?? null;
                                        @endphp
                                        <td class="num
                                            @if($minutesPerPiece === null) text-muted
                                            @elseif($rank === 1) text-success fw-bold
                                            @elseif($rank === ($cell->step_worst_rank ?? $cell->step_employee_count ?? null)) text-danger
                                            @else text-dark
                                            @endif">
                                            @if($minutesPerPiece !== null)
                                                {{ $duration($minutesPerPiece) }}
                                                <div class="small text-muted">
                                                    @if($rank === 1)
                                                        <i class="fas fa-medal" style="color:#d4a017" title="อันดับ 1 เหรียญทอง"></i>
                                                    @elseif($rank === 2)
                                                        <i class="fas fa-medal" style="color:#9ca3af" title="อันดับ 2 เหรียญเงิน"></i>
                                                    @elseif($rank === 3)
                                                        <i class="fas fa-medal" style="color:#b87333" title="อันดับ 3 เหรียญทองแดง"></i>
                                                    @else
                                                        #{{ $rank }}
                                                    @endif
                                                    · ดี {{ $fmt($cell->good_pcs, 0) }} / เสีย {{ $fmt($cell->bad_pcs, 0) }}
                                                </div>
                                            @else
                                                -
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-3 pb-2 pt-1"><small class="text-muted">เทียบอันดับเฉพาะพนักงานที่ทำ step เดียวกัน, "-" = ไม่มีข้อมูล</small></div>
            @else
                <div class="p-3 text-muted">ยังไม่มีข้อมูล</div>
            @endif
        </div>

        <div class="gp-panel mb-3">
            <div class="gp-head">
                <span>MFG ยังไม่จบงาน</span>
                <small class="text-muted">อิง Route No ล่าสุด และจบงานได้แม้กระบวนการไม่ต้องผ่าน PACK</small>
            </div>
            @if (($mfgIncompleteSummary ?? collect())->isNotEmpty())
                <div class="gp-table-wrap" style="max-height:320px;">
                    <table class="table table-sm table-bordered table-hover gp-table mb-0">
                        <thead>
                            <tr>
                                <th>MFG</th>
                                <th>โครงการ / เลข SO</th>
                                <th>ค้างอยู่ที่ Step</th>
                                <th class="num">จำนวนแผน</th>
                                <th class="num">ยอดดี</th>
                                <th class="num">ยอดเสีย</th>
                                <th>สถานะ</th>
                                @can('GP')
                                    <th>ดำเนินการ</th>
                                @endcan
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($mfgIncompleteSummary as $row)
                                <tr>
                                    <td class="fw-bold">{{ $row->mfg_no }}</td>
                                    <td>
                                        <div>{{ $row->project ?: '-' }}</div>
                                        <div class="small text-muted">{{ $row->salesorder ?: '-' }}</div>
                                    </td>
                                    <td>
                                        <span class="fw-bold">{{ ($row->pending_step_name ?? null) ?: (($row->pending_step_code ?? null) ?: '-') }}</span>
                                        @if(($row->pending_route_no ?? null) !== null)
                                            <div class="small text-muted">Route No. {{ number_format($row->pending_route_no) }}</div>
                                        @endif
                                    </td>
                                    <td class="num">{{ $fmt($row->plan_qty_pcs ?? 0, 0) }}</td>
                                    <td class="num">{{ $fmt($row->good_pcs ?? 0, 0) }}</td>
                                    <td class="num">{{ $fmt($row->bad_pcs ?? 0, 0) }}</td>
                                    <td>
                                        <span class="badge bg-warning text-dark">ยังไม่จบงาน</span>
                                        @if($row->has_pack ?? false)
                                            <div class="small text-muted mt-1">มี PACK แล้ว</div>
                                        @endif
                                    </td>
                                    @can('GP')
                                    <td>
                                        <form method="POST" action="{{ route('grating-performance.mfg.finish', ['mfgNo' => $row->mfg_no]) }}" onsubmit="return confirm('ยืนยันจบงาน MFG นี้โดยไม่บังคับ PACK?')">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-success text-nowrap"><i class="fas fa-check me-1"></i>จบงาน</button>
                                        </form>
                                    </td>
                                    @endcan
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="p-3 text-muted">ไม่มี MFG ค้างตามเงื่อนไขนี้</div>
            @endif
        </div>

        {{-- Section 4: Trend chart + ประสิทธิภาพราย Step side by side --}}
        <div class="row g-3 mb-3">
            <div class="col-lg-7">
                <div class="gp-panel h-100">
                    <div class="gp-head"><span>แนวโน้มยอดผลิต (ชิ้น)</span><small class="text-muted">ตัวเลขบนแท่ง = ยอดดี · เส้นแดง = ยอดเสีย · เส้นน้ำเงิน = เฉลี่ย 7 จุด</small></div>
                    <div class="p-3" style="height:280px;">
                        <canvas id="gp-trend-chart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="gp-panel h-100">
                    <div class="gp-head">
                        <span>ประสิทธิภาพราย Step</span>
                        <small class="text-muted">เวลาแสดงเป็นนาที และเปลี่ยนเป็นชั่วโมงเมื่อครบ 60 นาที</small>
                    </div>
                    @if (($slowStepSummary ?? collect())->isNotEmpty())
                        <div class="gp-slow-list">
                            @foreach ($slowStepSummary as $row)
                                @php
                                    $dp = $row->delay_percent ?? null;
                                    $slowClass = $dp === null ? 'slow-green' : ($dp > 30 ? 'slow-red' : ($dp > 10 ? 'slow-yellow' : 'slow-green'));
                                @endphp
                                <div class="gp-slow-item {{ $slowClass }}">
                                    <div class="name">{{ $row->step_name }}</div>
                                    <div class="metric"><span>เวลารวม</span><strong>{{ $duration($row->minutes ?? 0) }}</strong></div>
                                    <div class="metric"><span>{{ ($row->is_field_work ?? false) ? 'เวลา/งาน' : 'เวลา/MFG' }}</span><strong>{{ $duration(($row->hours_per_work ?? 0) * 60) }}</strong></div>
                                    <div class="metric"><span>เวลา/ชิ้น</span><strong>{{ ($row->minutes_per_piece ?? null) !== null ? $duration($row->minutes_per_piece) : '-' }}</strong></div>
                                    <div class="metric"><span>ยอดดี (ชิ้น)</span><strong>{{ $fmt($row->good_pcs ?? 0, 0) }}</strong></div>
                                    <div class="metric"><span>ยอดเสีย (ชิ้น)</span><strong>{{ $fmt($row->bad_pcs ?? 0, 0) }}</strong></div>
                                    <div class="metric"><span>กก./ชม.</span><strong>{{ $fmt($row->actual_kg_per_hour) }}</strong></div>
                                    <div class="meta">
                                        <span class="badge {{ ($row->delay_percent ?? null) !== null ? 'bg-danger' : 'bg-secondary' }}">{{ $row->slow_reason }}</span>
                                        @if (($row->delay_percent ?? null) !== null)
                                            <span>ช้ากว่า target {{ $fmt($row->delay_percent, 0) }}%</span>
                                        @endif
                                        <span>{{ number_format($row->work_count ?? $row->mfg_count) }} {{ ($row->is_field_work ?? false) ? 'งาน' : 'MFG' }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="p-3 text-muted">ยังไม่มีข้อมูลเพียงพอสำหรับสรุป step ที่ช้า</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="gp-panel mb-3">
            <div class="gp-head">
                <span>MFG → Step → ผู้รับผิดชอบ</span>
                <small class="text-muted">เวลารวมของ MFG แล้วไล่เวลาและผู้รับผิดชอบลงมาตาม Step</small>
            </div>
            @if (($mfgStepPeopleSummary ?? collect())->isNotEmpty())
                <div class="gp-table-wrap" style="max-height:360px;">
                    <table class="table table-sm table-bordered table-hover gp-table mb-0">
                        <thead>
                            <tr>
                                <th>MFG / Step</th>
                                <th>ผู้รับผิดชอบ</th>
                                <th class="num">ยอดดี (ชิ้น)</th>
                                <th class="num">ยอดดี (กก.)</th>
                                <th class="num">เวลา</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($mfgStepPeopleSummary->groupBy('mfg_no') as $mfgNo => $mfgRows)
                                @php
                                    $firstMfgRow = $mfgRows->first();
                                    $mfgStepGroupId = 'mfg-step-group-' . md5((string) $mfgNo);
                                @endphp
                                <tr class="gp-mfg-summary-row" data-mfg-step-target="{{ $mfgStepGroupId }}" tabindex="0" role="button" aria-expanded="false">
                                    <td class="fw-bold"><i class="fas fa-chevron-right gp-row-chevron me-2"></i>MFG {{ $mfgNo }}</td>
                                    <td class="text-muted">{{ $mfgRows->pluck('employee_names')->flatMap(fn($names) => collect(explode(',', (string) $names)))->map(fn($name) => trim($name))->filter()->unique()->implode(', ') ?: '-' }}</td>
                                    <td class="num text-muted">-</td>
                                    <td class="num text-muted">-</td>
                                    <td class="num fw-bold">รวม {{ $duration($firstMfgRow->mfg_minutes ?? 0) }}</td>
                                </tr>
                                @foreach ($mfgRows as $row)
                                    <tr class="gp-mfg-step-row" data-mfg-step-group="{{ $mfgStepGroupId }}">
                                        <td class="ps-4">
                                            <span class="fw-bold">↳ {{ $row->step_name ?: $row->step_code }}</span>
                                            @if($row->step_code && $row->step_name !== $row->step_code)
                                                <div class="small text-muted ms-3">{{ $row->step_code }}</div>
                                            @endif
                                        </td>
                                        <td>{{ $row->employee_names ?: '-' }}</td>
                                        <td class="num">{{ $fmt($row->good_pcs ?? 0, 0) }}</td>
                                        <td class="num">{{ $fmt($row->good_kg ?? 0) }}</td>
                                        <td class="num">{{ $duration($row->minutes ?? 0) }}</td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="p-3 text-muted">ยังไม่มีข้อมูล MFG x Step</div>
            @endif
        </div>

        {{-- Section 6: Workload รายวัน --}}
        <div class="gp-panel mb-3">
            <div class="gp-head">
                <span>Workload รายวัน</span>
                <small class="text-muted">ชั่วโมงงานของแต่ละคนต่อวัน - ดูความสมดุลของงาน</small>
            </div>
            @if ($workloadByDay->isNotEmpty())
                @php
                    $wlDays = $workloadByDay->pluck('work_day')->unique()->sort()->values();
                    $wlByEmp = $workloadByDay->groupBy('employee_name');
                    $wlMax = $workloadByDay->max('hours') ?: 1;
                @endphp
                <div class="gp-table-wrap" style="max-height:320px;">
                    <table class="table table-sm table-bordered table-hover gp-table mb-0">
                        <thead>
                            <tr>
                                <th>พนักงาน</th>
                                @foreach ($wlDays as $day)
                                    <th class="num text-center" style="font-size:.75rem;">
                                        {{ \Carbon\Carbon::parse($day)->format('d/m') }}
                                    </th>
                                @endforeach
                                <th class="num">รวม ชม.</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($wlByEmp as $empName => $empRows)
                                @php $empTotal = $empRows->sum('hours'); @endphp
                                <tr>
                                    <td class="fw-bold">{{ $empName }}</td>
                                    @foreach ($wlDays as $day)
                                        @php
                                            $cell = $empRows->firstWhere('work_day', $day);
                                            $hrs = $cell ? $cell->hours : null;
                                            $pct = $hrs ? min(100, ($hrs / $wlMax) * 100) : 0;
                                        @endphp
                                        <td class="num position-relative" style="min-width:52px;">
                                            @if ($hrs !== null)
                                                <div style="position:absolute;bottom:0;left:0;width:{{ $pct }}%;height:3px;background:{{ $hrs >= 8 ? '#dc2626' : ($hrs >= 6 ? '#2563eb' : '#9ca3af') }};border-radius:0 0 0 4px;"></div>
                                                <span class="{{ $hrs >= 8 ? 'text-danger fw-bold' : '' }}">{{ number_format($hrs, 1) }}</span>
                                            @else
                                                <span class="text-muted">-</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="num fw-bold">{{ number_format($empTotal, 1) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-3 pb-2 pt-1"><small class="text-muted">แถบสี: แดง = ≥8 ชม./วัน, น้ำเงิน = 6-8 ชม., เทา = &lt;6 ชม.</small></div>
            @else
                <div class="p-3 text-muted">ยังไม่มีข้อมูลชั่วโมงงาน (กรุณากรอกเวลาเริ่ม-เวลาจบ entry)</div>
            @endif
        </div>

        {{-- Section 7: รายคน summary แบบ compact, top 10, เน้น kg เสีย --}}
        <div class="row g-3 mb-3">
            <div class="col-xl-6">
                <div class="gp-panel">
                    <div class="gp-head"><span>รายคน</span><small class="text-muted">Top 10 ตามจำนวนชิ้น และ % ของเสีย</small></div>
                    <div class="gp-table-wrap">
                        @php
                            $employeeStepsByEmployee = $employeeStepEfficiency->groupBy('employee_id');
                        @endphp
                        <table class="table table-sm table-bordered table-hover gp-table mb-0">
                            <thead>
                                <tr>
                                    <th>พนักงาน</th>
                                    <th class="num">เวลารวม</th>
                                    <th class="num">ยอดดี (ชิ้น)</th>
                                    <th class="num">ยอดเสีย (ชิ้น)</th>
                                    <th class="num">% ของเสีย</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($employeeSummary->take(10) as $row)
                                    @php
                                        $defect = (($row->good_pcs ?? 0) + ($row->bad_pcs ?? 0)) > 0
                                            ? (($row->bad_pcs ?? 0) / (($row->good_pcs ?? 0) + ($row->bad_pcs ?? 0)) * 100)
                                            : null;
                                        $employeeStepRows = $employeeStepsByEmployee
                                            ->get($row->id, collect())
                                            ->sortBy([['sort_order', 'asc'], ['step_code', 'asc']]);
                                        $employeeDetailId = 'employee-step-detail-' . $row->id;
                                    @endphp
                                    <tr class="gp-employee-summary-row" data-employee-step-target="{{ $employeeDetailId }}" tabindex="0" role="button" aria-expanded="false">
                                        <td>
                                            <i class="fas fa-chevron-right gp-row-chevron me-1 text-muted"></i>{{ $row->name }}
                                            @if($row->responsible_work)
                                                <div class="small text-muted">{{ $row->responsible_work }}</div>
                                            @endif
                                        </td>
                                        <td class="num">{{ $duration($row->minutes) }}</td>
                                        <td class="num">{{ $fmt($row->good_pcs ?? 0, 0) }}</td>
                                        <td class="num">{{ $fmt($row->bad_pcs ?? 0, 0) }}</td>
                                        <td class="num fw-bold {{ $defect === null ? 'text-muted' : ($defect > 5 ? 'text-danger' : ($defect > 2 ? 'text-warning' : 'text-success')) }}">
                                            {{ $defect === null ? '-' : $fmt($defect, 1).'%' }}
                                        </td>
                                    </tr>
                                    <tr id="{{ $employeeDetailId }}" class="gp-employee-step-detail">
                                        <td colspan="5">
                                            @if($employeeStepRows->isNotEmpty())
                                                <table class="table table-sm table-bordered gp-employee-step-table mb-1">
                                                    <thead><tr><th>Step</th><th class="num">เวลาของพนักงาน</th><th class="num">รายการ</th></tr></thead>
                                                    <tbody>
                                                        @foreach($employeeStepRows as $stepRow)
                                                            <tr>
                                                                <td>{{ $stepRow->step_name }}<div class="small text-muted">{{ $stepRow->step_code }}</div></td>
                                                                <td class="num">{{ $duration($stepRow->minutes ?? 0) }}</td>
                                                                <td class="num">{{ number_format($stepRow->entry_count ?? 0) }}</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                                <small class="text-muted">เวลาราย Step ตัดช่วงซ้ำภายใน Step แล้ว; หากคีย์หลาย Step ในช่วงเดียวกัน เวลารวมรายคนจะนับช่วงนั้นเพียงครั้งเดียว</small>
                                            @else
                                                <span class="text-muted">ไม่มีรายละเอียดเวลาแยก Step</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีข้อมูล</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Section 8: ราย Step แบบ compact, เทียบ ชม./วัน --}}
            <div class="col-xl-6">
                <div class="gp-panel">
                    <div class="gp-head"><span>ราย Step</span><small class="text-muted">ยอดตาม step สำหรับดู workload</small></div>
                    <div class="gp-table-wrap">
                        <table class="table table-sm table-bordered table-hover gp-table mb-0">
                            <thead>
                                <tr>
                                    <th>Step</th>
                                    <th class="num">MFG</th>
                                    <th class="num">ยอดดี (ชิ้น)</th>
                                    <th class="num">kg ดี</th>
                                    <th class="num">เวลา/วัน</th>
                                    <th class="num">เป้า (ชิ้น)</th>
                                    <th class="num">%</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($stepSummary as $row)
                                    @php
                                        $targetPcs = (float) ($row->target_pcs ?? 0);
                                        $targetKg = (float) ($row->target_kg ?? 0);
                                        $pct = $targetPcs > 0
                                            ? ($row->good_pcs / $targetPcs * 100)
                                            : ($targetKg > 0 ? ($row->good_kg / $targetKg * 100) : null);
                                    @endphp
                                    <tr>
                                        <td>
                                            <span class="fw-bold">{{ $row->step_name ?: $row->step_code }}</span>
                                            @if($row->step_code && $row->step_name !== $row->step_code)
                                                <div class="small text-muted">{{ $row->step_code }}</div>
                                            @endif
                                        </td>
                                        <td class="num">{{ number_format($row->mfg_count) }}</td>
                                        <td class="num">{{ $fmt($row->good_pcs, 0) }}</td>
                                        <td class="num">{{ $fmt($row->good_kg) }}</td>
                                        <td class="num">{{ $duration(($row->avg_hours_per_day ?? 0) * 60) }}</td>
                                        <td class="num">
                                            {{ $targetPcs > 0 ? $fmt($targetPcs, 0) : '-' }}
                                            @if ($targetKg > 0)
                                                <div class="small text-muted">{{ $fmt($targetKg) }} กก.</div>
                                            @endif
                                        </td>
                                        <td class="num fw-bold
                                            @if($pct === null) text-muted
                                            @elseif($pct >= 100) text-success
                                            @elseif($pct >= 80) text-warning
                                            @else text-danger
                                            @endif">
                                            {{ $pct === null ? '-' : $fmt($pct, 0).'%' }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center text-muted py-4">ยังไม่มีข้อมูล</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('.gp-select').forEach(function (el) {
                    if (window.TomSelect) {
                        new TomSelect(el, { plugins: el.multiple ? ['remove_button'] : [], dropdownParent: 'body' });
                    }
                });

                const collapsiblePanels = [];
                document.querySelectorAll('.gp-panel > .gp-head').forEach(function (head, index) {
                    const panel = head.parentElement;
                    const heading = head.querySelector(':scope > span')?.textContent.trim() || `panel-${index}`;
                    if (heading === 'Dashboard') return;

                    const storageKey = `gp-dashboard-collapse:${location.pathname}:${heading}:${index}`;
                    const toggle = document.createElement('button');
                    toggle.type = 'button';
                    toggle.className = 'btn btn-sm btn-outline-secondary gp-collapse-toggle';
                    toggle.innerHTML = '<i class="fas fa-chevron-down"></i>';

                    const setCollapsed = function (collapsed, remember = true) {
                        panel.classList.toggle('is-collapsed', collapsed);
                        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                        toggle.title = collapsed ? 'ขยายส่วนนี้' : 'ย่อส่วนนี้';
                        if (remember) {
                            try { localStorage.setItem(storageKey, collapsed ? '1' : '0'); } catch (error) {}
                        }
                    };

                    let initiallyCollapsed = false;
                    try { initiallyCollapsed = localStorage.getItem(storageKey) === '1'; } catch (error) {}
                    setCollapsed(initiallyCollapsed, false);
                    toggle.addEventListener('click', () => setCollapsed(!panel.classList.contains('is-collapsed')));
                    head.appendChild(toggle);
                    collapsiblePanels.push({ panel, setCollapsed });
                });

                document.getElementById('gp-collapse-all')?.addEventListener('click', function () {
                    collapsiblePanels.forEach(item => item.setCollapsed(true));
                });
                document.getElementById('gp-expand-all')?.addEventListener('click', function () {
                    collapsiblePanels.forEach(item => item.setCollapsed(false));
                });

                document.querySelectorAll('.gp-employee-summary-row').forEach(function (row) {
                    const toggleDetail = function () {
                        const detail = document.getElementById(row.dataset.employeeStepTarget);
                        if (!detail) return;
                        const open = !detail.classList.contains('is-open');
                        detail.classList.toggle('is-open', open);
                        row.classList.toggle('is-open', open);
                        row.setAttribute('aria-expanded', open ? 'true' : 'false');
                    };
                    row.addEventListener('click', toggleDetail);
                    row.addEventListener('keydown', function (event) {
                        if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            toggleDetail();
                        }
                    });
                });

                document.querySelectorAll('.gp-mfg-summary-row').forEach(function (row) {
                    const toggleSteps = function () {
                        const groupId = row.dataset.mfgStepTarget;
                        const stepRows = document.querySelectorAll(`[data-mfg-step-group="${groupId}"]`);
                        const open = !row.classList.contains('is-open');
                        row.classList.toggle('is-open', open);
                        row.setAttribute('aria-expanded', open ? 'true' : 'false');
                        stepRows.forEach(stepRow => stepRow.classList.toggle('is-open', open));
                    };
                    row.addEventListener('click', toggleSteps);
                    row.addEventListener('keydown', function (event) {
                        if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            toggleSteps();
                        }
                    });
                });

                const periodSelect = document.getElementById('gp-period-select');
                const dateFrom = document.getElementById('gp-date-from');
                const dateTo = document.getElementById('gp-date-to');

                function formatDate(date) {
                    const year = date.getFullYear();
                    const month = String(date.getMonth() + 1).padStart(2, '0');
                    const day = String(date.getDate()).padStart(2, '0');
                    return `${year}-${month}-${day}`;
                }

                function parseDate(value) {
                    if (!value) return new Date();
                    const parts = value.split('-').map(Number);
                    if (parts.length !== 3 || parts.some(Number.isNaN)) return new Date();
                    return new Date(parts[0], parts[1] - 1, parts[2]);
                }

                function setPeriodRange() {
                    if (!periodSelect || !dateFrom || !dateTo) return;
                    const anchor = parseDate(dateFrom.value || dateTo.value);
                    const start = new Date(anchor);
                    const end = new Date(anchor);

                    if (periodSelect.value === 'week') {
                        const day = start.getDay();
                        const mondayOffset = day === 0 ? -6 : 1 - day;
                        start.setDate(start.getDate() + mondayOffset);
                        end.setTime(start.getTime());
                        end.setDate(start.getDate() + 6);
                    } else if (periodSelect.value === 'month') {
                        start.setDate(1);
                        end.setFullYear(start.getFullYear(), start.getMonth() + 1, 0);
                    }

                    dateFrom.value = formatDate(start);
                    dateTo.value = formatDate(end);
                }

                periodSelect?.addEventListener('change', setPeriodRange);

                document.querySelectorAll('.gp-project-autocomplete').forEach(projectInput => {
                    const wrap = projectInput.closest('.gp-project-wrap');
                    const projectList = wrap?.querySelector('.gp-project-list');
                    const salesorderInput = projectInput.closest('form')?.querySelector('[name="salesorder"]');
                    let projectTimer = null;
                    if (!projectList) return;

                    projectInput.addEventListener('input', function () {
                        clearTimeout(projectTimer);
                        const q = projectInput.value.trim();
                        if (q.length < 2) {
                            projectList.style.display = 'none';
                            return;
                        }
                        projectTimer = setTimeout(function () {
                            fetch('{{ route('api.grating-projects.search') }}?q=' + encodeURIComponent(q) + '&limit=8', { headers: { 'Accept': 'application/json' } })
                                .then(response => response.ok ? response.json() : { results: [] })
                                .then(data => {
                                    projectList.innerHTML = '';
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
                                            projectList.style.display = 'none';
                                        });
                                        projectList.appendChild(button);
                                    });
                                    projectList.style.display = projectList.children.length ? 'block' : 'none';
                                });
                        }, 250);
                    });
                });

                document.addEventListener('click', function (event) {
                    document.querySelectorAll('.gp-mfg-list').forEach(activeList => {
                        const wrap = activeList.closest('.gp-mfg-wrap, .gp-project-wrap');
                        if (wrap && !wrap.contains(event.target)) {
                            activeList.style.display = 'none';
                        }
                    });
                });
            });

            // Chart.js trend chart
            (function() {
                const ctx = document.getElementById('gp-trend-chart');
                if (!ctx) return;
                if (typeof Chart === 'undefined') {
                    const s = document.createElement('script');
                    s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js';
                    s.onload = drawChart;
                    document.head.appendChild(s);
                } else {
                    drawChart();
                }
                function drawChart() {
                    const rawLabels = @json($periodSummary->pluck('period_label'));
                    const labels = rawLabels.map(label => /^\d{4}-\d{2}-\d{2}$/.test(label)
                        ? `${label.slice(8, 10)}/${label.slice(5, 7)}`
                        : label);
                    const goodPcs   = @json($periodSummary->pluck('good_pcs')->map(fn($v) => round((float)$v, 0)));
                    const badPcs    = @json($periodSummary->pluck('bad_pcs')->map(fn($v) => round((float)$v, 0)));
                    const mfgCounts = @json($periodSummary->pluck('mfg_count')->map(fn($v) => (int)$v));
                    const peopleCounts = @json($periodSummary->pluck('people_count')->map(fn($v) => (int)$v));
                    const movingAverage = goodPcs.map((value, index) => {
                        const windowValues = goodPcs.slice(Math.max(0, index - 6), index + 1);
                        return Math.round((windowValues.reduce((sum, item) => sum + Number(item || 0), 0) / windowValues.length) * 10) / 10;
                    });
                    const datasets = [{
                        type: 'bar',
                        label: 'ยอดดี (ชิ้น)',
                        data: goodPcs,
                        backgroundColor: 'rgba(37,99,235,0.7)',
                        borderRadius: 4,
                        order: 2,
                    }, {
                        type: 'line',
                        label: 'ยอดเสีย (ชิ้น)',
                        data: badPcs,
                        borderColor: '#dc2626',
                        backgroundColor: '#dc2626',
                        borderWidth: 2,
                        pointRadius: badPcs.length > 20 ? 1.5 : 3,
                        pointHoverRadius: 5,
                        tension: .25,
                        order: 1,
                    }, {
                        type: 'line',
                        label: 'เฉลี่ย 7 จุด',
                        data: movingAverage,
                        borderColor: '#1d4ed8',
                        backgroundColor: '#1d4ed8',
                        borderWidth: 2,
                        borderDash: [5, 4],
                        pointRadius: 0,
                        tension: .3,
                        order: 0,
                    }];

                    const valueLabelPlugin = {
                        id: 'gpValueLabels',
                        afterDatasetsDraw(chart) {
                            const meta = chart.getDatasetMeta(0);
                            const ctx = chart.ctx;
                            ctx.save();
                            ctx.fillStyle = '#334155';
                            ctx.font = '600 9px sans-serif';
                            ctx.textAlign = 'center';
                            ctx.textBaseline = 'bottom';
                            meta.data.forEach((bar, index) => {
                                const value = Number(goodPcs[index] || 0);
                                if (value > 0) ctx.fillText(value.toLocaleString(), bar.x, bar.y - 3);
                            });
                            ctx.restore();
                        }
                    };

                    new Chart(ctx, {
                        data: { labels, datasets },
                        plugins: [valueLabelPlugin],
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            layout: { padding: { top: 16 } },
                            plugins: {
                                legend: { display: true, position: 'top', labels: { boxWidth: 12, usePointStyle: true, font: { size: 11 } } },
                                tooltip: {
                                    callbacks: {
                                        title: items => rawLabels[items[0]?.dataIndex] || '',
                                        label: ctx => ctx.dataset.label + ': ' + Number(ctx.parsed.y).toLocaleString(),
                                        afterBody: items => {
                                            const index = items[0]?.dataIndex ?? 0;
                                            return [`MFG: ${Number(mfgCounts[index] || 0).toLocaleString()}`, `พนักงาน: ${Number(peopleCounts[index] || 0).toLocaleString()} คน`];
                                        }
                                    }
                                }
                            },
                            scales: {
                                y: { beginAtZero: true, grid: { color: '#f0f0f0' }, grace: '12%' },
                                x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 16 } }
                            }
                        }
                    });
                }
            })();
        </script>
    @endpush
@endsection
