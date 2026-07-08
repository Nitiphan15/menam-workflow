@extends('layouts.layout')

@section('title', 'Grating Performance')
@section('page-title', 'Grating Performance')

@section('content')
    @php
        $fmt = fn($value, $dec = 1) => number_format((float) $value, $dec);
        $hours = fn($minutes) => $minutes ? number_format($minutes / 60, 1) : '0.0';
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
                    <a href="{{ route('dp.grating-performance.entries.create') }}" class="btn btn-sm btn-primary"><i class="fas fa-plus me-1"></i> Input</a>
                    <a href="{{ route('dp.grating-performance.inquiry') }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-table me-1"></i> Inquiry</a>
                    <a href="{{ route('dp.grating-performance.masters') }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-sliders me-1"></i> Masters</a>
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
                    <input type="text" name="project" class="form-control form-control-sm gp-project-autocomplete" value="{{ $filters['project'] ?? '' }}" autocomplete="off">
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
                    <select name="employee_id" class="form-select form-select-sm">
                        <option value="">ทั้งหมด</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}" @selected((string) $filters['employee_id'] === (string) $employee->id)>{{ $employee->name }}</option>
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
                    <div class="hint">ชิ้น | {{ $fmt($summary['good_kg'] ?? 0) }} กก. | PACK: {{ $fmt($summary['pack_kg'] ?? 0) }} กก.</div>
                    <div class="hint">{{ $fmt($summary['good_area_sqm'] ?? 0) }} ตร.ม.</div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="gp-kpi kpi-red">
                    <div class="kpi-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="label">ยอดเสีย</div>
                    <div class="value">{{ $fmt($summary['bad_pcs'] ?? 0, 0) }}</div>
                    <div class="hint">ชิ้น | {{ $fmt($summary['bad_kg'] ?? 0) }} กก. | {{ $fmt($summary['bad_area_sqm'] ?? 0) }} ตร.ม.</div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="gp-kpi kpi-purple">
                    <div class="kpi-icon"><i class="fas fa-clock"></i></div>
                    <div class="label">ชั่วโมงใช้จริง</div>
                    <div class="value">{{ $hours($summary['minutes'] ?? 0) }}</div>
                    <div class="hint">จากเวลาเริ่ม-จบ</div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="gp-kpi kpi-indigo">
                    <div class="kpi-icon"><i class="fas fa-calendar-day"></i></div>
                    <div class="label">ชม./วัน</div>
                    <div class="value">{{ $fmt($summary['hours_per_day'] ?? 0) }}</div>
                    <div class="hint">เฉลี่ย {{ number_format($summary['range_days'] ?? 1) }} วัน</div>
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
                <span>MFG ยังไม่ครบ / ยังไม่ Pack</span>
                <small class="text-muted">งานที่ยังไม่มี step PACK หรือยังไม่ถูกจบงาน</small>
            </div>
            @if (($mfgIncompleteSummary ?? collect())->isNotEmpty())
                <div class="gp-table-wrap" style="max-height:320px;">
                    <table class="table table-sm table-bordered table-hover gp-table mb-0">
                        <thead>
                            <tr>
                                <th>MFG</th>
                                <th>โครงการ / เลข SO</th>
                                <th>Step ที่มี</th>
                                <th class="num">จำนวนแผน</th>
                                <th class="num">ยอดดี</th>
                                <th class="num">ยอดเสีย</th>
                                <th>สถานะ</th>
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
                                    <td>{{ ($row->step_names ?? null) ?: (($row->step_codes ?? null) ?: '-') }}</td>
                                    <td class="num">{{ $fmt($row->plan_qty_pcs ?? 0, 0) }}</td>
                                    <td class="num">{{ $fmt($row->good_pcs ?? 0, 0) }}</td>
                                    <td class="num">{{ $fmt($row->bad_pcs ?? 0, 0) }}</td>
                                    <td>
                                        @if (!($row->has_pack ?? false))
                                            <span class="badge bg-warning text-dark">ยังไม่ Pack</span>
                                        @else
                                            <span class="badge bg-secondary">ยังไม่จบงาน</span>
                                        @endif
                                    </td>
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
                    <div class="gp-head"><span>แนวโน้มยอดดี (ชิ้น)</span><small class="text-muted">แสดงชิ้นเป็นหลัก และดู กก. ประกอบในบัตรสรุป</small></div>
                    <div class="p-3" style="height:220px;">
                        <canvas id="gp-trend-chart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="gp-panel h-100">
                    <div class="gp-head">
                        <span>ประสิทธิภาพราย Step</span>
                        <small class="text-muted">งานผลิตดู ชม./MFG ส่วนออกหน้างานดู ชม./งาน</small>
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
                                    <div class="metric"><span>ชม.รวม</span><strong>{{ $fmt($row->total_hours) }}</strong></div>
                                    <div class="metric"><span>{{ $row->work_unit_label ?? 'ชม./MFG' }}</span><strong>{{ $fmt($row->hours_per_work) }}</strong></div>
                                    <div class="metric"><span>ชิ้น/ชม. จริง</span><strong>{{ $fmt($row->actual_pcs_per_hour) }}</strong></div>
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

        {{-- Section 5: Efficiency รายคน x Step heatmap --}}
        <div class="gp-panel mb-3">
            <div class="gp-head">
                <span>Efficiency รายคน x Step</span>
                <small class="text-muted">ชิ้น/ชม. เป็นหลัก และยังเก็บ กก./ชม. ไว้ดูประกอบ</small>
            </div>
            @if ($employeeStepEfficiency->isNotEmpty())
                @php
                    $effSteps = $employeeStepEfficiency->pluck('step_name')->unique()->sort()->values();
                    $effByEmp = $employeeStepEfficiency->groupBy('employee_name');
                @endphp
                <div class="gp-table-wrap" style="max-height:320px;">
                    <table class="table table-sm table-bordered table-hover gp-table mb-0">
                        <thead>
                            <tr>
                                <th>พนักงาน</th>
                                @foreach ($effSteps as $sc)
                                    <th class="num text-center" style="font-size:.75rem;">{{ $sc }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($effByEmp as $empName => $empRows)
                                <tr>
                                    <td class="fw-bold">{{ $empName }}</td>
                                    @foreach ($effSteps as $sc)
                                        @php
                                            $cell = $empRows->firstWhere('step_name', $sc);
                                            $kph = $cell ? $cell->pcs_per_hour : null;
                                        @endphp
                                        <td class="num
                                            @if($kph === null) text-muted
                                            @elseif($kph >= 50) text-success fw-bold
                                            @elseif($kph >= 20) text-dark
                                            @else text-danger
                                            @endif">
                                            {{ $kph !== null ? number_format($kph, 1) : '-' }}
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-3 pb-2 pt-1"><small class="text-muted">ตัวเลขในตาราง = ชิ้น/ชม., "-" = ไม่มีข้อมูล step นี้</small></div>
            @else
                <div class="p-3 text-muted">ยังไม่มีข้อมูล</div>
            @endif
        </div>

        <div class="gp-panel mb-3">
            <div class="gp-head">
                <span>คนทำงานตาม MFG x Step</span>
                <small class="text-muted">แสดงว่า MFG แต่ละ step ทำโดยใครบ้าง</small>
            </div>
            @if (($mfgStepPeopleSummary ?? collect())->isNotEmpty())
                <div class="gp-table-wrap" style="max-height:360px;">
                    <table class="table table-sm table-bordered table-hover gp-table mb-0">
                        <thead>
                            <tr>
                                <th>MFG</th>
                                <th>Step</th>
                                <th>คนทำ</th>
                                <th class="num">ยอดดี (ชิ้น)</th>
                                <th class="num">ยอดดี (กก.)</th>
                                <th class="num">ชม.</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($mfgStepPeopleSummary as $row)
                                <tr>
                                    <td class="fw-bold">{{ $row->mfg_no }}</td>
                                    <td>
                                        <span class="fw-bold">{{ $row->step_name ?: $row->step_code }}</span>
                                        @if($row->step_code && $row->step_name !== $row->step_code)
                                            <div class="small text-muted">{{ $row->step_code }}</div>
                                        @endif
                                    </td>
                                    <td>{{ $row->employee_names }}</td>
                                    <td class="num">{{ $fmt($row->good_pcs ?? 0, 0) }}</td>
                                    <td class="num">{{ $fmt($row->good_kg ?? 0) }}</td>
                                    <td class="num">{{ $hours($row->minutes ?? 0) }}</td>
                                </tr>
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
                        <table class="table table-sm table-bordered table-hover gp-table mb-0">
                            <thead>
                                <tr>
                                    <th>พนักงาน</th>
                                    <th class="num">ชั่วโมงรวม</th>
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
                                    @endphp
                                    <tr>
                                        <td>
                                            {{ $row->name }}
                                            @if($row->responsible_work)
                                                <div class="small text-muted">{{ $row->responsible_work }}</div>
                                            @endif
                                        </td>
                                        <td class="num">{{ $hours($row->minutes) }}</td>
                                        <td class="num">{{ $fmt($row->good_pcs ?? 0, 0) }}</td>
                                        <td class="num">{{ $fmt($row->bad_pcs ?? 0, 0) }}</td>
                                        <td class="num fw-bold {{ $defect === null ? 'text-muted' : ($defect > 5 ? 'text-danger' : ($defect > 2 ? 'text-warning' : 'text-success')) }}">
                                            {{ $defect === null ? '-' : $fmt($defect, 1).'%' }}
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
                                    <th class="num">ชม./วัน</th>
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
                                        <td class="num">{{ $fmt($row->avg_hours_per_day) }}</td>
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
                    const labels    = @json($periodSummary->pluck('period_label'));
                    const goodPcs   = @json($periodSummary->pluck('good_pcs')->map(fn($v) => round((float)$v, 0)));
                    const datasets = [{
                        type: 'bar',
                        label: 'ยอดดี (ชิ้น)',
                        data: goodPcs,
                        backgroundColor: 'rgba(37,99,235,0.7)',
                        borderRadius: 4,
                        order: 2,
                    }];

                    new Chart(ctx, {
                        data: { labels, datasets },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false, position: 'top', labels: { boxWidth: 12, font: { size: 11 } } },
                                tooltip: {
                                    callbacks: {
                                        label: ctx => ctx.dataset.label + ': ' + Number(ctx.parsed.y).toLocaleString()
                                    }
                                }
                            },
                            scales: {
                                y: { beginAtZero: true, grid: { color: '#f0f0f0' } },
                                x: { grid: { display: false }, ticks: { maxRotation: 45 } }
                            }
                        }
                    });
                }
            })();
        </script>
    @endpush
@endsection
