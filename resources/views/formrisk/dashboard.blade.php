@extends('layouts.layout')

@section('title', 'แดชบอร์ดงานเสี่ยงการผลิต')
@section('page-title', 'แดชบอร์ดงานเสี่ยงการผลิต')

@section('content')
    <style>
        .metric-card .value {
            font-size: 1.7rem;
            font-weight: 700;
        }

        .metric-total .value {
            color: #1f2937;
        }

        .metric-warning .value {
            color: #d97706;
        }

        .metric-critical .value {
            color: #dc2626;
        }

        .metric-overdue .value {
            color: #7f1d1d;
        }

        .metric-notstarted .value {
            color: #334155;
        }

        .metric-unmapped .value {
            color: #0f172a;
        }

        .bar-wrap {
            min-width: 130px;
        }

        .bar-track {
            width: 100%;
            height: 10px;
            background: #e9ecef;
            border-radius: 999px;
            overflow: hidden;
        }

        .bar-fill {
            height: 10px;
            background: #0d6efd;
            border-radius: 999px;
        }

        .mini-trend {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(24px, 1fr));
            gap: 4px;
            align-items: end;
            min-height: 120px;
            overflow: visible;
        }

        .mini-trend .col-box {
            display: flex;
            flex-direction: column;
            justify-content: end;
            align-items: stretch;
            gap: 2px;
            height: 120px;
            position: relative;
            cursor: pointer;
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
            border-radius: 3px 3px 0 0;
        }

        .seg-warning {
            background: #f59e0b;
        }

        .seg-critical {
            background: #ef4444;
        }

        .seg-overdue {
            background: #7f1d1d;
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

    <div class="container-fluid py-3">
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
                <div class="card metric-card metric-total">
                    <div class="card-body text-center">
                        <div class="text-muted">ทั้งหมด</div>
                        <div class="value">{{ $summary['total'] }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-lg-2">
                <div class="card metric-card metric-warning">
                    <div class="card-body text-center">
                        <div class="text-muted">แจ้งเตือน</div>
                        <div class="value">{{ $summary['warning'] }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-lg-2">
                <div class="card metric-card metric-critical">
                    <div class="card-body text-center">
                        <div class="text-muted">วิกฤต</div>
                        <div class="value">{{ $summary['critical'] }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-lg-2">
                <div class="card metric-card metric-overdue">
                    <div class="card-body text-center">
                        <div class="text-muted">เลยกำหนด</div>
                        <div class="value">{{ $summary['overdue'] }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-lg-2">
                <div class="card metric-card metric-notstarted">
                    <div class="card-body text-center">
                        <div class="text-muted">ยังไม่เริ่ม</div>
                        <div class="value">{{ $summary['not_started'] }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-lg-2">
                <div class="card metric-card metric-unmapped">
                    <div class="card-body text-center">
                        <div class="text-muted">Unmapped</div>
                        <div class="value">{{ $summary['unmapped'] }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header fw-bold">แนวโน้มงานเสี่ยง 7 วันตามวันครบกำหนด</div>
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
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header fw-bold">แนวโน้มงานเสี่ยง 30 วันตามวันครบกำหนด</div>
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
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header fw-bold">สรุปตามโรงงาน</div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
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
                                    <tr>
                                        <td>{{ $r['site'] }}</td>
                                        <td class="text-center fw-bold">{{ $r['total'] }}</td>
                                        <td class="text-center">{{ $r['warning'] }}</td>
                                        <td class="text-center">{{ $r['critical'] }}</td>
                                        <td class="text-center">{{ $r['overdue'] }}</td>
                                        <td class="text-center">{{ $r['overdue_pct'] }}%</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header fw-bold">Planner ที่มีงานเสี่ยงมากสุด</div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
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
                                    @php $width = min(100, $r['count'] * 100 / max(1, $topPlanners->max('count'))); @endphp
                                    <tr>
                                        <td class="text-center fw-bold">{{ $r['rank'] }}</td>
                                        <td>
                                            <a
                                                href="{{ route('risk.index', array_merge(request()->query(), ['planner' => $r['planner'] === 'Unmapped' ? '' : $r['planner']])) }}">
                                                {{ $r['planner'] }}
                                            </a>
                                        </td>
                                        <td class="text-center fw-bold">
                                            {{ $r['count'] }}
                                            <div class="bar-wrap mt-1">
                                                <div class="bar-track">
                                                    <div class="bar-fill" style="width: {{ $width }}%;"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">{{ $r['overdue'] }}</td>
                                        <td class="text-center">{{ $r['overdue_pct'] }}%</td>
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
                <div class="card">
                    <div class="card-header fw-bold">สถานีงานที่เสี่ยงบ่อยสุด</div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
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
                                    @php $width = min(100, $r['count'] * 100 / max(1, $topRiskStations->max('count'))); @endphp
                                    <tr>
                                        <td class="text-center fw-bold">{{ $r['rank'] }}</td>
                                        <td>
                                            <a
                                                href="{{ route('risk.index', array_merge(request()->query(), ['station' => $r['station']])) }}">
                                                {{ $r['station'] }}
                                            </a>
                                        </td>
                                        <td class="text-center fw-bold">
                                            {{ $r['count'] }}
                                            <div class="bar-wrap mt-1">
                                                <div class="bar-track">
                                                    <div class="bar-fill" style="width: {{ $width }}%;"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">{{ $r['overdue'] }}</td>
                                        <td class="text-center">{{ $r['critical'] }}</td>
                                        <td class="text-center">{{ $r['warning'] }}</td>
                                        <td class="text-center">{{ $r['overdue_pct'] }}%</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header fw-bold">สถานีงานที่ดีเลย์บ่อยสุด</div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
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
                                        <td class="text-center fw-bold">{{ $r['rank'] }}</td>
                                        <td>
                                            <a
                                                href="{{ route('risk.index', array_merge(request()->query(), ['station' => $r['station'], 'status' => 'เลยกำหนด'])) }}">
                                                {{ $r['station'] }}
                                            </a>
                                        </td>
                                        <td class="text-center fw-bold">
                                            {{ $r['count'] }}
                                            <div class="bar-wrap mt-1">
                                                <div class="bar-track">
                                                    <div class="bar-fill" style="width: {{ $width }}%;"></div>
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
                <div class="card">
                    <div class="card-header fw-bold">สถานีงานที่ยังไม่เริ่มผลิต</div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
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
                                        <td class="text-center fw-bold">{{ $r['rank'] }}</td>
                                        <td>
                                            <a
                                                href="{{ route('risk.index', array_merge(request()->query(), ['station' => $r['station']])) }}">
                                                {{ $r['station'] }}
                                            </a>
                                        </td>
                                        <td class="text-center fw-bold">{{ $r['count'] }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center text-muted">No data</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header fw-bold">สถานีงานที่ยังไม่แมพ Planner</div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
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
                                        <td class="text-center fw-bold">{{ $r['rank'] }}</td>
                                        <td>{{ $r['station'] }}</td>
                                        <td class="text-center fw-bold">{{ $r['count'] }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center text-muted">No data</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-bold">งานเสี่ยงล่าสุด</div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
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
                            <tr>
                                <td>
                                    <a
                                        href="{{ route('risk.index', array_merge(request()->query(), ['keyword' => $r->workordernumber])) }}">
                                        {{ $r->workordernumber }}
                                    </a>
                                </td>
                                <td>{{ $r->site }}</td>
                                <td>{{ $r->duedate }}</td>
                                <td class="text-center fw-bold">{{ $r->days_to_due }}</td>
                                <td class="text-center">{{ $r->risk_label }}</td>
                                <td class="text-center">{{ $r->notify_work_center_code }}</td>
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
