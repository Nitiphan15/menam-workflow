@extends('layouts.layout')

@section('title', 'แดชบอร์ดงานเสี่ยงการผลิต')
@section('page-title', 'แดชบอร์ดงานเสี่ยงการผลิต')

@section('content')
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
        .metric-notstarted::before{ background: #94a3b8; }
        .metric-notstarted .ic    { background: #f1f5f9; color: #475569; }
        .metric-unmapped::before  { background: #6366f1; }
        .metric-unmapped .ic      { background: #eef2ff; color: #4338ca; }

        .section-card {
            border: 1px solid #eef2f7;
            border-radius: 16px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
            overflow: hidden;
            background: #fff;
        }
        .section-card .card-header {
            background: #fff;
            border-bottom: 1px solid #eef2f7;
            padding: .9rem 1.1rem;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            color: #0f172a;
        }
        .section-card .card-header .hdr-bar {
            width: 4px; height: 18px; border-radius: 3px;
            background: linear-gradient(180deg, #3b82f6, #6366f1);
        }
        .section-card .card-header.head-risk .hdr-bar { background: linear-gradient(180deg, #ef4444, #f59e0b); }
        .section-card .card-header.head-trend .hdr-bar { background: linear-gradient(180deg, #0ea5e9, #6366f1); }

        .data-table {
            margin-bottom: 0;
        }
        .data-table thead th {
            background: #f8fafc;
            color: #475569;
            font-weight: 600;
            font-size: .78rem;
            letter-spacing: .3px;
            text-transform: uppercase;
            border-bottom: 1px solid #e2e8f0 !important;
            border-top: 0;
            padding: .65rem .8rem;
        }
        .data-table tbody td {
            padding: .65rem .8rem;
            border-color: #f1f5f9;
            vertical-align: middle;
        }
        .data-table tbody tr:hover { background: #f8fafc; }

        .bar-wrap {
            min-width: 130px;
        }

        .bar-track {
            width: 100%;
            height: 8px;
            background: #eef2f7;
            border-radius: 999px;
            overflow: hidden;
        }

        .bar-fill {
            height: 8px;
            background: linear-gradient(90deg, #3b82f6, #60a5fa);
            border-radius: 999px;
            transition: width .4s ease;
        }

        .rank-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px; height: 28px;
            border-radius: 50%;
            background: #f1f5f9;
            color: #475569;
            font-weight: 700;
            font-size: .82rem;
        }
        tr:nth-child(1) .rank-badge { background: linear-gradient(135deg, #fbbf24, #f59e0b); color: #fff; }
        tr:nth-child(2) .rank-badge { background: linear-gradient(135deg, #cbd5e1, #94a3b8); color: #fff; }
        tr:nth-child(3) .rank-badge { background: linear-gradient(135deg, #fb923c, #ea580c); color: #fff; }

        .station-link, .planner-link {
            color: #1d4ed8;
            text-decoration: none;
            font-weight: 600;
        }
        .station-link:hover, .planner-link:hover { text-decoration: underline; }

        .pct-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 999px;
            font-size: .78rem;
            font-weight: 700;
            background: #f1f5f9;
            color: #334155;
        }
        .pct-high { background: #fee2e2; color: #b91c1c; }
        .pct-mid  { background: #fef3c7; color: #92400e; }
        .pct-low  { background: #ecfdf5; color: #047857; }

        .mini-trend {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(24px, 1fr));
            gap: 5px;
            align-items: end;
            min-height: 140px;
            overflow: visible;
            padding: 4px 0;
        }

        .mini-trend .col-box {
            display: flex;
            flex-direction: column;
            justify-content: end;
            align-items: stretch;
            gap: 2px;
            height: 140px;
            position: relative;
            cursor: pointer;
            transition: transform .15s ease;
        }
        .mini-trend .col-box:hover {
            transform: translateY(-2px);
        }

        .risk-tip::after {
            content: attr(data-tip);
            position: absolute;
            left: 50%;
            bottom: calc(100% + 8px);
            transform: translateX(-50%);
            background: rgba(17, 24, 39, 0.96);
            color: #fff;
            padding: 6px 10px;
            border-radius: 8px;
            font-size: 12px;
            line-height: 1.35;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: all .15s ease;
            z-index: 50;
            box-shadow: 0 6px 18px rgba(0, 0, 0, .18);
        }

        .risk-tip::before {
            content: "";
            position: absolute;
            left: 50%;
            bottom: 100%;
            transform: translateX(-50%);
            border: 6px solid transparent;
            border-top: 0;
            border-bottom-color: rgba(17, 24, 39, 0.96);
            margin-bottom: 2px;
            opacity: 0;
            visibility: hidden;
            transition: all .15s ease;
            z-index: 49;
        }

        .risk-tip:hover::after,
        .risk-tip:hover::before {
            opacity: 1;
            visibility: visible;
        }

        .mini-trend .seg {
            width: 100%;
            min-height: 2px;
            border-radius: 4px 4px 0 0;
            box-shadow: inset 0 -1px 0 rgba(0,0,0,.05);
        }

        .seg-warning {
            background: linear-gradient(180deg, #fcd34d, #f59e0b);
        }

        .seg-critical {
            background: linear-gradient(180deg, #f87171, #ef4444);
        }

        .seg-overdue {
            background: linear-gradient(180deg, #b91c1c, #7f1d1d);
        }

        .station-chip {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 8px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: .82rem;
            font-weight: 600;
        }

        .site-chip {
            display: inline-block;
            min-width: 50px;
            padding: 2px 12px;
            border-radius: 8px;
            font-size: .8rem;
            font-weight: 700;
            text-align: center;
        }
        .site-wire { background: #ecfeff; color: #0e7490; border: 1px solid #a5f3fc; }
        .site-plus { background: #fdf4ff; color: #86198f; border: 1px solid #f5d0fe; }

        .trend-legend {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            font-size: .78rem;
            color: #64748b;
            margin-top: 6px;
        }
        .trend-legend .lg-dot {
            display: inline-block;
            width: 10px; height: 10px;
            border-radius: 3px;
            margin-right: 5px;
            vertical-align: middle;
        }

        .small-labels {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(24px, 1fr));
            gap: 4px;
            margin-top: 8px;
            font-size: .72rem;
            color: #6b7280;
            text-align: center;
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

        .card,
        .card-body,
        .mini-trend {
            overflow: visible !important;
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
            <h4><i class="fa-solid fa-chart-line me-2 text-primary"></i>แดชบอร์ดงานเสี่ยงการผลิต</h4>
        </div>

        <form class="card card-body mb-3 filter-card" method="GET" action="{{ route('risk.dashboard') }}">
            <div class="row g-3">
                <div class="col-md-6 col-xl-3">
                    <label class="form-label">เริ่มดูข้อมูลตั้งแต่</label>
                    <input type="date" class="form-control" name="since" value="{{ $filters['since'] }}">
                </div>

                <div class="col-md-6 col-xl-3">
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
                    <label class="form-label">สถานะ</label>
                    <select class="form-select" name="status">
                        <option value="">ทั้งหมด</option>
                        <option value="แจ้งเตือน" @selected($filters['status'] === 'แจ้งเตือน')>แจ้งเตือน</option>
                        <option value="วิกฤต" @selected($filters['status'] === 'วิกฤต')>วิกฤต</option>
                        <option value="เลยกำหนด" @selected($filters['status'] === 'เลยกำหนด')>เลยกำหนด</option>
                    </select>
                </div>

                <div class="col-12 filter-actions-row">
                    <div class="filter-actions">
                        <button class="btn risk-btn risk-btn-primary" type="submit">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <span>ค้นหา</span>
                        </button>

                        <a href="{{ route('risk.dashboard') }}" class="btn risk-btn risk-btn-light">
                            <i class="fa-solid fa-rotate-left"></i>
                            <span>ล้างตัวกรอง</span>
                        </a>

                        <a href="{{ route('risk.index', request()->except('page')) }}" class="btn risk-btn risk-btn-dark">
                            <i class="fa-solid fa-table-list"></i>
                            <span>ไปหน้ารายการ</span>
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
                <div class="metric-card metric-notstarted">
                    <div class="card-body">
                        <div>
                            <div class="label">ยังไม่เริ่ม</div>
                            <div class="value">{{ number_format($summary['not_started']) }}</div>
                        </div>
                        <div class="ic"><i class="fa-solid fa-hourglass-start"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-lg-2">
                <div class="metric-card metric-unmapped">
                    <div class="card-body">
                        <div>
                            <div class="label">Unmapped</div>
                            <div class="value">{{ number_format($summary['unmapped']) }}</div>
                        </div>
                        <div class="ic"><i class="fa-solid fa-user-slash"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="card-header head-trend"><span class="hdr-bar"></span>แนวโน้มงานเสี่ยง 7 วันตามวันครบกำหนด</div>
                    <div class="card-body">
                        @php
                            $max7 = max(
                                1,
                                collect($trend7)->map(fn($r) => $r['warning'] + $r['critical'] + $r['overdue'])->max(),
                            );
                        @endphp
                        <div class="mini-trend">
                            @foreach ($trend7 as $r)
                                @php
                                    $warningH = ($r['warning'] / $max7) * 100;
                                    $criticalH = ($r['critical'] / $max7) * 100;
                                    $overdueH = ($r['overdue'] / $max7) * 100;
                                @endphp
                                <div class="col-box risk-tip"
                                    data-tip="{{ \Carbon\Carbon::parse($r['date'])->format('d/m/Y') }} | แจ้งเตือน: {{ $r['warning'] }} | วิกฤต: {{ $r['critical'] }} | เลยกำหนด: {{ $r['overdue'] }}">
                                    <div class="seg seg-warning" style="height: {{ $warningH }}%;"></div>
                                    <div class="seg seg-critical" style="height: {{ $criticalH }}%;"></div>
                                    <div class="seg seg-overdue" style="height: {{ $overdueH }}%;"></div>
                                </div>
                            @endforeach
                        </div>
                        <div class="small-labels">
                            @foreach ($trend7 as $r)
                                <div>{{ \Carbon\Carbon::parse($r['date'])->format('d/m') }}</div>
                            @endforeach
                        </div>
                        <div class="trend-legend">
                            <span><span class="lg-dot seg-warning"></span>แจ้งเตือน</span>
                            <span><span class="lg-dot seg-critical"></span>วิกฤต</span>
                            <span><span class="lg-dot seg-overdue"></span>เลยกำหนด</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="section-card">
                    <div class="card-header head-trend"><span class="hdr-bar"></span>แนวโน้มงานเสี่ยง 30 วันตามวันครบกำหนด</div>
                    <div class="card-body">
                        @php
                            $max30 = max(
                                1,
                                collect($trend30)->map(fn($r) => $r['warning'] + $r['critical'] + $r['overdue'])->max(),
                            );
                        @endphp
                        <div class="mini-trend">
                            @foreach ($trend30 as $r)
                                @php
                                    $warningH = ($r['warning'] / $max30) * 100;
                                    $criticalH = ($r['critical'] / $max30) * 100;
                                    $overdueH = ($r['overdue'] / $max30) * 100;
                                @endphp
                                <div class="col-box risk-tip"
                                    data-tip="{{ \Carbon\Carbon::parse($r['date'])->format('d/m/Y') }} | แจ้งเตือน: {{ $r['warning'] }} | วิกฤต: {{ $r['critical'] }} | เลยกำหนด: {{ $r['overdue'] }}">
                                    <div class="seg seg-warning" style="height: {{ $warningH }}%;"></div>
                                    <div class="seg seg-critical" style="height: {{ $criticalH }}%;"></div>
                                    <div class="seg seg-overdue" style="height: {{ $overdueH }}%;"></div>
                                </div>
                            @endforeach
                        </div>
                        <div class="small-labels">
                            @foreach ($trend30 as $r)
                                <div>{{ \Carbon\Carbon::parse($r['date'])->format('d') }}</div>
                            @endforeach
                        </div>
                        <div class="trend-legend">
                            <span><span class="lg-dot seg-warning"></span>แจ้งเตือน</span>
                            <span><span class="lg-dot seg-critical"></span>วิกฤต</span>
                            <span><span class="lg-dot seg-overdue"></span>เลยกำหนด</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="card-header head-risk"><span class="hdr-bar"></span>สรุปตามโรงงาน</div>
                    <div class="table-responsive">
                        <table class="table table-sm data-table">
                            <thead>
                                <tr>
                                    <th>Site</th>
                                    <th class="text-center">รวม</th>
                                    <th class="text-center">Warning</th>
                                    <th class="text-center">Critical</th>
                                    <th class="text-center">Overdue</th>
                                    <th class="text-center">% Overdue</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($siteSummary as $r)
                                    @php
                                        $pct = (float) $r['overdue_pct'];
                                        $pctClass = $pct >= 40 ? 'pct-high' : ($pct >= 20 ? 'pct-mid' : 'pct-low');
                                        $siteClass = strtolower($r['site']) === 'wire' ? 'site-wire' : 'site-plus';
                                    @endphp
                                    <tr>
                                        <td><span class="site-chip {{ $siteClass }}">{{ $r['site'] }}</span></td>
                                        <td class="text-center fw-bold">{{ number_format($r['total']) }}</td>
                                        <td class="text-center text-warning fw-semibold">{{ $r['warning'] }}</td>
                                        <td class="text-center text-danger fw-semibold">{{ $r['critical'] }}</td>
                                        <td class="text-center fw-semibold" style="color:#7f1d1d">{{ $r['overdue'] }}</td>
                                        <td class="text-center"><span class="pct-badge {{ $pctClass }}">{{ $r['overdue_pct'] }}%</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="section-card">
                    <div class="card-header head-risk"><span class="hdr-bar"></span>Planner ที่มีงานเสี่ยงมากสุด</div>
                    <div class="table-responsive">
                        <table class="table table-sm data-table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Planner</th>
                                    <th class="text-center">รวม</th>
                                    <th class="text-center">Overdue</th>
                                    <th class="text-center">% Overdue</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($topPlanners as $r)
                                    @php
                                        $width = min(100, $r['count'] * 100 / max(1, $topPlanners->max('count')));
                                        $pct = (float) $r['overdue_pct'];
                                        $pctClass = $pct >= 40 ? 'pct-high' : ($pct >= 20 ? 'pct-mid' : 'pct-low');
                                    @endphp
                                    <tr>
                                        <td class="text-center"><span class="rank-badge">{{ $r['rank'] }}</span></td>
                                        <td>
                                            <a class="planner-link"
                                                href="{{ route('risk.index', array_merge(request()->query(), ['planner' => $r['planner'] === 'Unmapped' ? '' : $r['planner']])) }}">
                                                {{ $r['planner'] }}
                                            </a>
                                        </td>
                                        <td class="text-center fw-bold">
                                            {{ number_format($r['count']) }}
                                            <div class="bar-wrap mt-1">
                                                <div class="bar-track">
                                                    <div class="bar-fill" style="width: {{ $width }}%;"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center fw-semibold" style="color:#7f1d1d">{{ $r['overdue'] }}</td>
                                        <td class="text-center"><span class="pct-badge {{ $pctClass }}">{{ $r['overdue_pct'] }}%</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="card-header head-risk"><span class="hdr-bar"></span>สถานีงานที่เสี่ยงบ่อยสุด</div>
                    <div class="table-responsive">
                        <table class="table table-sm data-table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Station</th>
                                    <th class="text-center">รวม</th>
                                    <th class="text-center">Overdue</th>
                                    <th class="text-center">Critical</th>
                                    <th class="text-center">Warning</th>
                                    <th class="text-center">% Overdue</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($topRiskStations as $r)
                                    @php
                                        $width = min(100, $r['count'] * 100 / max(1, $topRiskStations->max('count')));
                                        $pct = (float) $r['overdue_pct'];
                                        $pctClass = $pct >= 40 ? 'pct-high' : ($pct >= 20 ? 'pct-mid' : 'pct-low');
                                    @endphp
                                    <tr>
                                        <td class="text-center"><span class="rank-badge">{{ $r['rank'] }}</span></td>
                                        <td>
                                            <a class="station-link"
                                                href="{{ route('risk.index', array_merge(request()->query(), ['station' => $r['station']])) }}">
                                                {{ $r['station'] }}
                                            </a>
                                        </td>
                                        <td class="text-center fw-bold">
                                            {{ number_format($r['count']) }}
                                            <div class="bar-wrap mt-1">
                                                <div class="bar-track">
                                                    <div class="bar-fill" style="width: {{ $width }}%;"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center fw-semibold" style="color:#7f1d1d">{{ $r['overdue'] }}</td>
                                        <td class="text-center text-danger fw-semibold">{{ $r['critical'] }}</td>
                                        <td class="text-center text-warning fw-semibold">{{ $r['warning'] }}</td>
                                        <td class="text-center"><span class="pct-badge {{ $pctClass }}">{{ $r['overdue_pct'] }}%</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="section-card">
                    <div class="card-header head-risk"><span class="hdr-bar"></span>สถานีงานที่ดีเลย์บ่อยสุด</div>
                    <div class="table-responsive">
                        <table class="table table-sm data-table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Station</th>
                                    <th class="text-center">Overdue Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($topOverdueStations as $r)
                                    @php $width = min(100, $r['count'] * 100 / max(1, $topOverdueStations->max('count'))); @endphp
                                    <tr>
                                        <td class="text-center"><span class="rank-badge">{{ $r['rank'] }}</span></td>
                                        <td>
                                            <a class="station-link"
                                                href="{{ route('risk.index', array_merge(request()->query(), ['station' => $r['station'], 'status' => 'เลยกำหนด'])) }}">
                                                {{ $r['station'] }}
                                            </a>
                                        </td>
                                        <td class="text-center fw-bold">
                                            <span style="color:#7f1d1d">{{ number_format($r['count']) }}</span>
                                            <div class="bar-wrap mt-1">
                                                <div class="bar-track">
                                                    <div class="bar-fill" style="width: {{ $width }}%; background: linear-gradient(90deg, #ef4444, #f87171);"></div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="card-header"><span class="hdr-bar"></span>สถานีงานที่ยังไม่เริ่มผลิต</div>
                    <div class="table-responsive">
                        <table class="table table-sm data-table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Station</th>
                                    <th class="text-center">Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($notStartedStations as $r)
                                    <tr>
                                        <td class="text-center"><span class="rank-badge">{{ $r['rank'] }}</span></td>
                                        <td>
                                            <a class="station-link"
                                                href="{{ route('risk.index', array_merge(request()->query(), ['station' => $r['station']])) }}">
                                                {{ $r['station'] }}
                                            </a>
                                        </td>
                                        <td class="text-center fw-bold">{{ number_format($r['count']) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-3">
                                            <i class="fa-regular fa-folder-open me-1"></i>No data
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="section-card">
                    <div class="card-header"><span class="hdr-bar"></span>สถานีงานที่ยังไม่แมพ Planner</div>
                    <div class="table-responsive">
                        <table class="table table-sm data-table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Station</th>
                                    <th class="text-center">Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($unmappedStations as $r)
                                    <tr>
                                        <td class="text-center"><span class="rank-badge">{{ $r['rank'] }}</span></td>
                                        <td><span class="station-chip">{{ $r['station'] }}</span></td>
                                        <td class="text-center fw-bold">{{ number_format($r['count']) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-3">
                                            <i class="fa-regular fa-folder-open me-1"></i>No data
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="section-card">
            <div class="card-header head-risk"><span class="hdr-bar"></span>งานเสี่ยงล่าสุด</div>
            <div class="table-responsive">
                <table class="table table-sm data-table">
                    <thead>
                        <tr>
                            <th>MFG</th>
                            <th>Site</th>
                            <th>Due</th>
                            <th>Days</th>
                            <th>Status</th>
                            <th>Notify</th>
                            <th>Planner</th>
                            <th>Progress</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($latestRows as $r)
                            @php
                                $statusClass = match ($r->risk_label) {
                                    'เลยกำหนด' => 'pct-high',
                                    'วิกฤต' => 'pct-high',
                                    'แจ้งเตือน' => 'pct-mid',
                                    default => 'pct-low',
                                };
                                $siteClass = strtolower($r->site) === 'wire' ? 'site-wire' : 'site-plus';
                            @endphp
                            <tr>
                                <td>
                                    <a class="station-link"
                                        href="{{ route('risk.index', array_merge(request()->query(), ['keyword' => $r->workordernumber])) }}">
                                        {{ $r->workordernumber }}
                                    </a>
                                </td>
                                <td><span class="site-chip {{ $siteClass }}">{{ $r->site }}</span></td>
                                <td>{{ $r->duedate }}</td>
                                <td class="text-center fw-bold">{{ $r->days_to_due }}</td>
                                <td class="text-center"><span class="pct-badge {{ $statusClass }}">{{ $r->risk_label }}</span></td>
                                <td class="text-center">
                                    @if ($r->notify_work_center_code)
                                        <span class="station-chip">{{ $r->notify_work_center_code }}</span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td class="text-center">{{ $r->planner_name ?: '-' }}</td>
                                <td class="text-center">{{ $r->step_progress_display }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
