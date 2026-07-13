@extends('layouts.layout')

@section('title', 'งานเสี่ยงการผลิต')
@section('page-title', 'งานเสี่ยงการผลิต')

@section('content')
    @php
        $fmtDate = fn($v) => $v ? \Carbon\Carbon::parse($v)->format('d/m/Y') : '-';
    @endphp

    <style>
        .risk-page {
            background: linear-gradient(180deg, #f7f9fc 0%, #ffffff 320px);
            min-height: 100vh;
            animation: riskFade .35s ease;
        }

        @keyframes riskFade {
            from { opacity: 0; transform: translateY(4px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .risk-page-title {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
        }
        .risk-page-title .title-bar {
            width: 5px;
            height: 28px;
            border-radius: 4px;
            background: linear-gradient(180deg, #ef4444, #f59e0b);
        }
        .risk-page-title h4 {
            margin: 0;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: .2px;
        }

        .risk-table-wrap {
            max-height: calc(100vh - 320px);
            overflow: auto;
            position: relative;
            border-radius: 14px;
        }

        .risk-table {
            margin-bottom: 0;
        }

        .risk-table thead th {
            position: sticky;
            top: 0;
            z-index: 20;
            background: #f1f5f9;
            color: #334155;
            text-align: center;
            vertical-align: middle;
            white-space: nowrap;
            border-bottom: 2px solid #e2e8f0 !important;
            font-weight: 600;
            font-size: .82rem;
            letter-spacing: .3px;
            text-transform: uppercase;
            padding: .65rem .75rem;
        }

        .risk-table tbody td {
            padding: .65rem .75rem;
            border-color: #eef2f7;
            font-size: .92rem;
        }

        .risk-table tbody tr {
            transition: background-color .15s ease;
        }

        .risk-table tbody tr:nth-child(even) {
            background: #fafbfd;
        }

        .risk-table tbody tr:hover {
            background: #eff6ff;
        }

        .risk-table .sticky-col {
            position: sticky;
            left: 0;
            z-index: 15;
            background: inherit;
            white-space: nowrap;
        }

        .risk-table tbody tr:nth-child(even) .sticky-col {
            background: #fafbfd;
        }
        .risk-table tbody tr:hover .sticky-col {
            background: #eff6ff;
        }
        .risk-table tbody tr:nth-child(odd) .sticky-col {
            background: #fff;
        }

        .risk-table thead .sticky-col {
            z-index: 30;
            background: #f1f5f9;
        }

        .days-pill {
            display: inline-block;
            min-width: 56px;
            padding: 4px 12px;
            border-radius: 999px;
            font-weight: 700;
            font-size: .9rem;
        }
        .days-overdue {
            background: linear-gradient(135deg, #7f1d1d, #b91c1c);
            color: #fff;
            box-shadow: 0 2px 6px rgba(127, 29, 29, .25);
        }
        .days-critical {
            background: linear-gradient(135deg, #dc2626, #f87171);
            color: #fff;
            box-shadow: 0 2px 6px rgba(220, 38, 38, .22);
        }
        .days-warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        .days-normal {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: .8rem;
            font-weight: 600;
        }
        .status-badge i { font-size: .68rem; }
        .status-overdue {
            background: #fee2e2;
            color: #991b1b;
        }
        .status-critical {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        .status-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .filter-card {
            border-radius: 18px;
            padding: 1.25rem;
            border: 1px solid #e5e7eb;
            box-shadow: 0 4px 14px rgba(15, 23, 42, .04);
        }

        .filter-card .form-label {
            font-weight: 600;
            margin-bottom: .4rem;
            color: #374151;
        }

        .filter-card .form-control,
        .filter-card .form-select {
            height: 46px;
            border-radius: 10px;
        }

        .filter-actions-row {
            margin-top: 4px;
        }

        .summary-card {
            border-radius: 14px;
        }

        .summary-card .card-body {
            padding: 1.25rem 1rem;
        }

        .summary-card .value {
            font-size: 1.75rem;
            font-weight: 700;
            line-height: 1;
        }

        /* Metric cards */
        .metric-card {
            border: 1px solid #eef2f7;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
            overflow: hidden;
            position: relative;
            transition: transform .18s ease, box-shadow .18s ease;
        }
        .metric-card::before {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            background: #cbd5e1;
        }
        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(15, 23, 42, .08);
        }
        .metric-card .card-body {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.1rem 1rem 1.25rem;
        }
        .metric-card .label {
            font-size: .82rem;
            color: #64748b;
            font-weight: 500;
            letter-spacing: .2px;
        }
        .metric-card .value {
            font-size: 1.7rem;
            font-weight: 800;
            line-height: 1.1;
            margin-top: 2px;
            color: #0f172a;
        }
        .metric-card .ic {
            width: 42px; height: 42px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
            background: #f1f5f9;
            color: #475569;
        }

        .metric-total::before     { background: #64748b; }
        .metric-warning::before   { background: #f59e0b; }
        .metric-warning .ic       { background: #fef3c7; color: #b45309; }
        .metric-warning .value    { color: #b45309; }
        .metric-critical::before  { background: #ef4444; }
        .metric-critical .ic      { background: #fee2e2; color: #b91c1c; }
        .metric-critical .value   { color: #b91c1c; }
        .metric-overdue::before   { background: #7f1d1d; }
        .metric-overdue .ic       { background: #fef2f2; color: #7f1d1d; }
        .metric-overdue .value    { color: #7f1d1d; }
        .metric-unmapped::before  { background: #6366f1; }
        .metric-unmapped .ic      { background: #eef2ff; color: #4338ca; }
        .metric-shown::before     { background: #0ea5e9; }
        .metric-shown .ic         { background: #e0f2fe; color: #0369a1; }

        .section-card {
            border: 1px solid #eef2f7;
            border-radius: 16px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
            overflow: hidden;
        }

        .step-progress {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
            color: #334155;
            font-size: .85rem;
        }
        .step-progress .dot {
            width: 7px; height: 7px;
            border-radius: 50%;
            background: #94a3b8;
        }
        .step-progress.not-started { color: #6b7280; }
        .step-progress.not-started .dot { background: #cbd5e1; }
        .step-progress.in-progress .dot { background: #0ea5e9; }
        .step-progress.done .dot { background: #16a34a; }

        .planner-chip {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 999px;
            background: #f1f5f9;
            color: #334155;
            font-size: .82rem;
            font-weight: 500;
        }

        .station-chip {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 8px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: .82rem;
            font-weight: 600;
            font-family: 'Sarabun', 'Inter', monospace;
        }

        .site-chip {
            display: inline-block;
            min-width: 50px;
            padding: 2px 10px;
            border-radius: 8px;
            font-size: .78rem;
            font-weight: 700;
        }
        .site-wire { background: #ecfeff; color: #0e7490; border: 1px solid #a5f3fc; }
        .site-plus { background: #fdf4ff; color: #86198f; border: 1px solid #f5d0fe; }

        .mfg-link {
            font-weight: 700;
            color: #0f172a;
            text-decoration: none;
        }
        .mfg-link:hover { color: #1d4ed8; }

        .empty-state {
            padding: 3rem 1rem !important;
            color: #94a3b8;
        }
        .empty-state i {
            font-size: 2.2rem;
            display: block;
            margin-bottom: .6rem;
            color: #cbd5e1;
        }

        .filter-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .risk-btn {
            height: 44px;
            min-width: 168px;
            padding: 0 18px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-weight: 600;
            font-size: .96rem;
            text-decoration: none;
            border: 1px solid #d0d5dd;
            background: #fff;
            color: #1f2937;
            box-shadow: 0 1px 2px rgba(16, 24, 40, .04);
            transition: all .18s ease;
        }

        .risk-btn i {
            font-size: .92rem;
        }

        .risk-btn:hover,
        .risk-btn:focus {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(16, 24, 40, .08);
            text-decoration: none;
        }

        .risk-btn-primary {
            background: #1f6feb;
            border-color: #1f6feb;
            color: #fff;
        }

        .risk-btn-primary:hover,
        .risk-btn-primary:focus {
            background: #1859bd;
            border-color: #1859bd;
            color: #fff;
        }

        .risk-btn-light {
            background: #fff;
            border-color: #d0d5dd;
            color: #344054;
        }

        .risk-btn-light:hover,
        .risk-btn-light:focus {
            background: #f9fafb;
            border-color: #bfc6d4;
            color: #1f2937;
        }

        .risk-btn-dark {
            background: #fff;
            border-color: #98a2b3;
            color: #111827;
        }

        .risk-btn-dark:hover,
        .risk-btn-dark:focus {
            background: #f3f4f6;
            border-color: #6b7280;
            color: #111827;
        }

        .risk-btn-success {
            background: #fff;
            border-color: #16a34a;
            color: #166534;
        }

        .risk-btn-success:hover,
        .risk-btn-success:focus {
            background: #16a34a;
            border-color: #16a34a;
            color: #fff;
        }

        @media (max-width: 991.98px) {
            .filter-actions {
                display: grid;
                grid-template-columns: 1fr;
            }

            .risk-btn {
                width: 100%;
                min-width: 0;
            }
        }
    </style>

    <div class="container-fluid py-3 risk-page">
        <div class="risk-page-title">
            <span class="title-bar"></span>
            <h4><i class="fa-solid fa-triangle-exclamation me-2 text-warning"></i>งานเสี่ยงการผลิต</h4>
        </div>

        <form class="card card-body mb-3 filter-card" method="GET" action="{{ route('risk.index') }}">
            <div class="row g-3">
                <div class="col-md-6 col-xl-2">
                    <label class="form-label">เริ่มดูข้อมูลตั้งแต่</label>
                    <input type="date" class="form-control" name="since" value="{{ $filters['since'] }}">
                </div>

                <div class="col-md-6 col-xl-2">
                    <label class="form-label">กำหนดส่งภายใน (วัน)</label>
                    <input type="number" class="form-control" name="dueWithin" value="{{ $filters['dueWithin'] }}">
                </div>

                <div class="col-md-6 col-xl-2">
                    <label class="form-label">โรงงาน</label>
                    <select class="form-select" name="site">
                        <option value="">ทั้งหมด</option>
                        <option value="Wire" @selected($filters['site'] === 'Wire')>Wire</option>
                        <option value="Plus" @selected($filters['site'] === 'Plus')>Plus</option>
                    </select>
                </div>

                <div class="col-md-6 col-xl-2">
                    <label class="form-label">Planner</label>
                    <select class="form-select" name="planner">
                        <option value="">ทั้งหมด</option>
                        @foreach ($planners as $planner)
                            <option value="{{ $planner }}" @selected($filters['planner'] === $planner)>{{ $planner }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-6 col-xl-2">
                    <label class="form-label">สถานีงาน</label>
                    <select class="form-select" name="station">
                        <option value="">ทั้งหมด</option>
                        @foreach ($stations as $station)
                            <option value="{{ $station }}" @selected($filters['station'] === $station)>{{ $station }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-6 col-xl-2">
                    <label class="form-label">สถานะ</label>
                    <select class="form-select" name="status">
                        <option value="">ทั้งหมด</option>
                        <option value="แจ้งเตือน" @selected($filters['status'] === 'แจ้งเตือน')>แจ้งเตือน</option>
                        <option value="วิกฤต" @selected($filters['status'] === 'วิกฤต')>วิกฤต</option>
                        <option value="เลยกำหนด" @selected($filters['status'] === 'เลยกำหนด')>เลยกำหนด</option>
                    </select>
                </div>

                <div class="col-lg-7">
                    <label class="form-label">คำค้นหา</label>
                    <input type="text" class="form-control" name="keyword" value="{{ $filters['keyword'] }}"
                        placeholder="MFG / Part / Customer">
                </div>

                <div class="col-md-4 col-lg-2">
                    <label class="form-label">จำนวนแถวต่อหน้า</label>
                    <select class="form-select" name="per_page">
                        @foreach ([50, 100, 150, 200] as $size)
                            <option value="{{ $size }}" @selected($perPage == $size)>{{ $size }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 filter-actions-row">
                    <div class="filter-actions">
                        <button class="btn risk-btn risk-btn-primary" type="submit">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <span>ค้นหา</span>
                        </button>

                        <a href="{{ route('risk.index') }}" class="btn risk-btn risk-btn-light">
                            <i class="fa-solid fa-rotate-left"></i>
                            <span>ล้างตัวกรอง</span>
                        </a>

                        <a href="{{ route('risk.dashboard', request()->except('page')) }}"
                            class="btn risk-btn risk-btn-dark">
                            <i class="fa-solid fa-chart-line"></i>
                            <span>ไปหน้า Dashboard</span>
                        </a>

                        <a href="{{ route('risk.exportExcel', request()->except('page')) }}"
                            class="btn risk-btn risk-btn-success">
                            <i class="fa-solid fa-file-excel"></i>
                            <span>Export Excel</span>
                        </a>
                    </div>
                </div>
            </div>
        </form>

        <div class="row g-3 mb-3">
            <div class="col-md-4 col-lg-2">
                <div class="metric-card metric-total">
                    <div class="card-body">
                        <div>
                            <div class="label">ทั้งหมด</div>
                            <div class="value">{{ number_format($summary['total']) }}</div>
                        </div>
                        <div class="ic"><i class="fa-solid fa-layer-group"></i></div>
                    </div>
                </div>
            </div>

            <div class="col-md-4 col-lg-2">
                <div class="metric-card metric-warning">
                    <div class="card-body">
                        <div>
                            <div class="label">แจ้งเตือน</div>
                            <div class="value">{{ number_format($summary['warning']) }}</div>
                        </div>
                        <div class="ic"><i class="fa-solid fa-bell"></i></div>
                    </div>
                </div>
            </div>

            <div class="col-md-4 col-lg-2">
                <div class="metric-card metric-critical">
                    <div class="card-body">
                        <div>
                            <div class="label">วิกฤต</div>
                            <div class="value">{{ number_format($summary['critical']) }}</div>
                        </div>
                        <div class="ic"><i class="fa-solid fa-fire"></i></div>
                    </div>
                </div>
            </div>

            <div class="col-md-4 col-lg-2">
                <div class="metric-card metric-overdue">
                    <div class="card-body">
                        <div>
                            <div class="label">เลยกำหนด</div>
                            <div class="value">{{ number_format($summary['overdue']) }}</div>
                        </div>
                        <div class="ic"><i class="fa-solid fa-clock-rotate-left"></i></div>
                    </div>
                </div>
            </div>

            <div class="col-md-4 col-lg-2">
                <div class="metric-card metric-unmapped">
                    <div class="card-body">
                        <div>
                            <div class="label">ยังไม่แมพ Planner</div>
                            <div class="value">{{ number_format($summary['unmapped']) }}</div>
                        </div>
                        <div class="ic"><i class="fa-solid fa-user-slash"></i></div>
                    </div>
                </div>
            </div>

            <div class="col-md-4 col-lg-2">
                <div class="metric-card metric-shown">
                    <div class="card-body">
                        <div>
                            <div class="label">แสดงผล</div>
                            <div class="value">{{ number_format($rows->count()) }}</div>
                        </div>
                        <div class="ic"><i class="fa-solid fa-eye"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="text-muted small">
                ทั้งหมด {{ number_format($allRowsCount) }} รายการ
            </div>
            <div>
                {{ $rows->links() }}
            </div>
        </div>

        <div class="section-card">
            <div class="risk-table-wrap">
                <table class="table table-sm align-middle mb-0 risk-table">
                    <thead>
                        <tr>
                            <th class="sticky-col text-start">เลขที่ MFG</th>
                            <th>โรงงาน</th>
                            <th>กำหนดส่ง</th>
                            <th>คงเหลือ (วัน)</th>
                            <th>สถานะ</th>
                            <th>สถานีที่ใช้แจ้งเตือน</th>
                            <th>Planner</th>
                            <th>ความคืบหน้าขั้นตอน</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $r)
                            @php
                                $daysClass = match ($r->risk_label) {
                                    'เลยกำหนด' => 'days-overdue',
                                    'วิกฤต' => 'days-critical',
                                    'แจ้งเตือน' => 'days-warning',
                                    default => 'days-normal',
                                };
                                $statusClass = match ($r->risk_label) {
                                    'เลยกำหนด' => 'status-overdue',
                                    'วิกฤต' => 'status-critical',
                                    'แจ้งเตือน' => 'status-warning',
                                    default => 'status-warning',
                                };
                                $statusIcon = match ($r->risk_label) {
                                    'เลยกำหนด' => 'fa-clock-rotate-left',
                                    'วิกฤต' => 'fa-fire',
                                    'แจ้งเตือน' => 'fa-bell',
                                    default => 'fa-circle-info',
                                };
                                $progress = (string) ($r->step_progress_display ?? '');
                                $progressClass = 'in-progress';
                                if (str_starts_with($progress, '0/')) {
                                    $progressClass = 'not-started';
                                } elseif (preg_match('#^(\d+)/(\d+)#', $progress, $m) && $m[1] === $m[2]) {
                                    $progressClass = 'done';
                                }
                            @endphp
                            <tr>
                                <td class="sticky-col">
                                    <a href="#" class="mfg-link">{{ $r->workordernumber }}</a>
                                </td>
                                <td class="text-center">
                                    <span class="site-chip site-{{ strtolower($r->site) }}">{{ $r->site }}</span>
                                </td>
                                <td class="text-center">{{ $fmtDate($r->duedate) }}</td>
                                <td class="text-center">
                                    <span class="days-pill {{ $daysClass }}">{{ $r->days_to_due }}</span>
                                </td>
                                <td class="text-center">
                                    <span class="status-badge {{ $statusClass }}">
                                        <i class="fa-solid {{ $statusIcon }}"></i>
                                        {{ $r->risk_label }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    @if ($r->notify_work_center_code)
                                        <span class="station-chip">{{ $r->notify_work_center_code }}</span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if ($r->planner_name)
                                        <span class="planner-chip">{{ $r->planner_name }}</span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <span class="step-progress {{ $progressClass }}">
                                        <span class="dot"></span>{{ $r->step_progress_display }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center empty-state">
                                    <i class="fa-regular fa-folder-open"></i>
                                    ไม่พบข้อมูลตามเงื่อนไขที่เลือก
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-2 text-muted small">
            * ถ้าขึ้น <strong>0/x*</strong> หมายถึงงานนี้ยังไม่เริ่มผลิต และ x คือจำนวนขั้นตอนทั้งหมด
        </div>

        <div class="mt-1 text-muted small">
            * <strong>สถานีที่ใช้แจ้งเตือน</strong> คือสถานีงานที่ระบบใช้เป็นตัวอ้างอิงในการแจ้งเตือนและระบุผู้รับผิดชอบ
        </div>

        <div class="mt-3">
            {{ $rows->links() }}
        </div>
    </div>
@endsection
