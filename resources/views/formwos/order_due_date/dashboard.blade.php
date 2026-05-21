@extends('layouts.layout')

@section('title', 'Order Due Date Dashboard')
@section('page-title', 'Order Due Date Dashboard')

@push('styles')
    <style>
        .due-dashboard-card {
            border: 0;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .08);
        }

        .due-kpi-label {
            color: #64748b;
            font-size: .78rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .due-kpi-value {
            color: #0f172a;
            font-size: 1.45rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .due-chart-box {
            height: 320px;
            position: relative;
        }

        .due-mini-table td,
        .due-mini-table th {
            vertical-align: middle;
        }
    </style>
@endpush

@section('content')
    @php
        $dash = $dashboard ?? [];
        $year = (int) ($filters['year'] ?? now()->year);
        $month = (int) ($filters['month'] ?? now()->month);
        $thaiMonths = [1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน', 5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม', 9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'];
        $fmt = fn($v) => number_format((float) $v, 0);
        $fmtPercent = fn($v) => $v === null ? '-' : number_format((float) $v, 1) . '%';
        $tone = fn($v) => (float) ($v ?? 0) < 0 ? 'text-danger' : 'text-success';
    @endphp

    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h3 class="mb-1">Order Due Date Dashboard</h3>
                <div class="text-muted small">Due Date จาก `reqdate` · {{ $thaiMonths[$month] ?? $month }} {{ $year }}</div>
            </div>
        </div>

        <form class="card card-body mb-3" method="GET" action="{{ route('wos.order_due_date.dashboard') }}">
            <div class="d-flex flex-wrap justify-content-end gap-2 mb-2">
                <button class="btn btn-outline-danger btn-sm" type="submit" formaction="{{ route('wos.order_due_date.dashboard.pdf') }}" formtarget="_blank">Export PDF</button>
                <button class="btn btn-outline-success btn-sm" type="submit" formaction="{{ route('wos.order_due_date.dashboard.excel') }}">Export Excel</button>
            </div>
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-3">
                    <label class="form-label mb-1">เดือน</label>
                    <select class="form-select" name="month">
                        @foreach ($thaiMonths as $no => $label)
                            <option value="{{ $no }}" @selected($month === $no)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label mb-1">ปี</label>
                    <input type="number" class="form-control" name="year" value="{{ $year }}">
                </div>
                <div class="col-12 col-md-2 d-grid">
                    <button class="btn btn-primary">รีเฟรช</button>
                </div>
                <div class="col-12 col-md-2 d-grid">
                    <a class="btn btn-outline-secondary" href="{{ route('wos.order_due_date', ['year' => $year, 'month' => $month]) }}">กลับรายงาน</a>
                </div>
            </div>
        </form>

        <div class="row g-3 mb-3">
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card due-dashboard-card h-100">
                    <div class="card-body">
                        <div class="due-kpi-label">Order เดือนที่เลือก</div>
                        <div class="due-kpi-value">{{ $fmt($dash['current_total'] ?? 0) }}</div>
                        <div class="text-muted small">{{ $dash['labels']['selected_month'] ?? '' }}</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card due-dashboard-card h-100">
                    <div class="card-body">
                        <div class="due-kpi-label">เทียบเดือนเดียวกันปีก่อน</div>
                        <div class="due-kpi-value {{ $tone($dash['change_percent'] ?? 0) }}">{{ $fmtPercent($dash['change_percent'] ?? null) }}</div>
                        <div class="text-muted small">{{ $fmt($dash['previous_total'] ?? 0) }} ใน {{ $dash['labels']['previous_month'] ?? '' }}</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card due-dashboard-card h-100">
                    <div class="card-body">
                        <div class="due-kpi-label">Achievement เทียบเป้า</div>
                        <div class="due-kpi-value">{{ $fmtPercent($dash['target_achievement'] ?? null) }}</div>
                        <div class="text-muted small">{{ $fmt($dash['current_total'] ?? 0) }} / {{ $fmt($dash['target_total'] ?? 0) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card due-dashboard-card h-100">
                    <div class="card-body">
                        <div class="due-kpi-label">YTD YoY</div>
                        <div class="due-kpi-value {{ $tone($dash['ytd_change_percent'] ?? 0) }}">{{ $fmtPercent($dash['ytd_change_percent'] ?? null) }}</div>
                        <div class="text-muted small">{{ $fmt($dash['ytd_total'] ?? 0) }} vs {{ $fmt($dash['previous_ytd_total'] ?? 0) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-12 col-xl-7">
                <div class="card due-dashboard-card h-100">
                    <div class="card-body">
                        <h5 class="mb-2">Target vs Order by Product Type</h5>
                        <div class="due-chart-box">
                            <canvas id="dueTargetOrderChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-5">
                <div class="card due-dashboard-card h-100">
                    <div class="card-body">
                        <h5 class="mb-2">Product Type Achievement</h5>
                        <div class="table-responsive" style="max-height:320px;">
                            <table class="table table-sm due-mini-table mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Type</th>
                                        <th class="text-end">Order</th>
                                        <th class="text-end">Target</th>
                                        <th class="text-end">Ach.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($dash['type_compare'] ?? [] as $row)
                                        <tr>
                                            <td>
                                                @if (!empty($row['detail_url']))
                                                    <a href="{{ $row['detail_url'] }}" class="text-decoration-none">{{ $row['label'] }}</a>
                                                @else
                                                    {{ $row['label'] }}
                                                @endif
                                            </td>
                                            <td class="text-end">{{ $fmt($row['current'] ?? 0) }}</td>
                                            <td class="text-end">{{ $fmt($row['target'] ?? 0) }}</td>
                                            <td class="text-end">{{ $fmtPercent($row['achievement'] ?? null) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-12 col-xl-6">
                <div class="card due-dashboard-card h-100">
                    <div class="card-body">
                        <h5 class="mb-2">Division Comparison</h5>
                        <div class="table-responsive">
                            <table class="table table-sm due-mini-table mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Division</th>
                                        <th class="text-end">Current</th>
                                        <th class="text-end">Prev.</th>
                                        <th class="text-end">YoY</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($dash['division_compare'] ?? [] as $row)
                                        <tr>
                                            <td>
                                                @if (!empty($row['detail_url']))
                                                    <a href="{{ $row['detail_url'] }}" class="text-decoration-none">{{ $row['code'] }} · {{ $row['label'] }}</a>
                                                @else
                                                    {{ $row['code'] }} · {{ $row['label'] }}
                                                @endif
                                            </td>
                                            <td class="text-end">{{ $fmt($row['current'] ?? 0) }}</td>
                                            <td class="text-end">{{ $fmt($row['previous'] ?? 0) }}</td>
                                            <td class="text-end {{ $tone($row['change_percent'] ?? 0) }}">{{ $fmtPercent($row['change_percent'] ?? null) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-6">
                <div class="card due-dashboard-card h-100">
                    <div class="card-body">
                        <h5 class="mb-2">Monthly Trend</h5>
                        <div class="due-chart-box">
                            <canvas id="dueMonthlyTrendChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            if (!window.Chart) return;

            const targetOrderEl = document.getElementById('dueTargetOrderChart');
            if (targetOrderEl) {
                new Chart(targetOrderEl, {
                    type: 'bar',
                    data: {
                        labels: @json($dash['chart']['labels'] ?? []),
                        datasets: [
                            { label: 'Target', data: @json($dash['chart']['targets'] ?? []), backgroundColor: '#2563eb' },
                            { label: 'Order', data: @json($dash['chart']['orders'] ?? []), backgroundColor: '#f97316' }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: false,
                        plugins: { legend: { position: 'bottom' } },
                        scales: { y: { beginAtZero: true } }
                    }
                });
            }

            const trendEl = document.getElementById('dueMonthlyTrendChart');
            if (trendEl) {
                new Chart(trendEl, {
                    type: 'line',
                    data: {
                        labels: @json(collect($dash['monthly_trend'] ?? [])->pluck('label')->values()),
                        datasets: [
                            {
                                label: @json((string) $year),
                                data: @json(collect($dash['monthly_trend'] ?? [])->pluck('current')->values()),
                                borderColor: '#16a34a',
                                backgroundColor: 'transparent',
                                tension: .3,
                                fill: false
                            },
                            {
                                label: @json((string) ($year - 1)),
                                data: @json(collect($dash['monthly_trend'] ?? [])->pluck('previous')->values()),
                                borderColor: '#7c3aed',
                                backgroundColor: 'transparent',
                                borderDash: [6, 4],
                                tension: .3,
                                fill: false
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: false,
                        plugins: { legend: { position: 'bottom' } },
                        scales: { y: { beginAtZero: true } }
                    }
                });
            }
        })();
    </script>
@endpush
