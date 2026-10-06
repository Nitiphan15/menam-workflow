@extends('layouts.layout')

@section('title', 'Order Due Date Report')
@section('page-title', 'Order Due Date Report')

@push('styles')
    <style>
        .due-report-table-wrap {
            overflow: auto;
        }

        .due-report-table {
            font-size: .82rem;
            min-width: 1440px;
        }

        .due-report-table th,
        .due-report-table td {
            white-space: nowrap;
            vertical-align: middle;
        }

        .due-report-table thead th {
            background: #f8fafc;
            position: sticky;
            top: 0;
            z-index: 5;
        }

        .due-report-table .sticky-col {
            background: #fff;
            left: 0;
            position: sticky;
            z-index: 4;
        }

        .due-report-table thead .sticky-col {
            background: #f8fafc;
            z-index: 6;
        }

        .due-row-order td {
            background: #fff3cd;
            color: #dc2626;
            font-weight: 700;
        }

        .due-row-target td {
            background: #dcfce7;
            font-weight: 700;
        }

        .due-row-achievement td {
            background: #fef3c7;
            font-weight: 700;
        }

        .due-month-current td {
            color: #1d4ed8;
            font-weight: 700;
        }

        .due-chart-shell {
            height: 360px;
        }

        .due-comparison-table {
            min-width: 1180px;
        }

        .delivery-analysis {
            border-top: 3px solid #0d6efd;
            padding-top: 1rem;
        }

        .delivery-section-heading {
            background: linear-gradient(135deg, #f8fbff 0%, #eef6ff 100%);
            border: 1px solid #cfe2ff;
            border-radius: .65rem .65rem 0 0;
            padding: 1rem 1.15rem;
        }

        .delivery-section-eyebrow {
            color: #0d6efd;
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .08em;
        }

        .delivery-division-badge {
            max-width: 100%;
            white-space: normal;
        }

        .delivery-definition-bar {
            align-items: center;
            background: #fff;
            border: 1px solid #dee2e6;
            border-top: 0;
            border-radius: 0 0 .65rem .65rem;
            color: #59636e;
            display: flex;
            flex-wrap: wrap;
            font-size: .78rem;
            gap: .45rem 1.25rem;
            padding: .7rem 1.15rem;
        }

        .delivery-summary-card,
        .delivery-data-card {
            border-color: #dfe4ea;
            box-shadow: 0 .125rem .35rem rgba(15, 23, 42, .05);
        }

        .delivery-kpi {
            border-left: 3px solid transparent;
            min-height: 66px;
        }

        .delivery-kpi-order { border-color: #495057; }
        .delivery-kpi-same { border-color: #198754; }
        .delivery-kpi-other { border-color: #f59f00; }
        .delivery-kpi-total { border-color: #0d6efd; }

        .delivery-kpi-label {
            color: #6c757d;
            font-size: .78rem;
            margin-bottom: .2rem;
        }

        .delivery-kpi-value {
            font-size: 1.3rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .delivery-kpi-same .delivery-kpi-value { color: #198754; }
        .delivery-kpi-other .delivery-kpi-value { color: #d98400; }
        .delivery-kpi-total .delivery-kpi-value { color: #0d6efd; }

        .delivery-stat-chip {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 999px;
            color: #495057;
            font-size: .75rem;
            padding: .25rem .6rem;
        }

        .delivery-table-toolbar {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
            justify-content: space-between;
        }

        .delivery-search {
            max-width: 310px;
        }

        .delivery-comparison-wrap {
            max-height: 560px;
        }

        .due-comparison-table thead th {
            background: #f8fafc;
            position: sticky;
            top: 0;
            z-index: 3;
        }

        .delivery-origin-current > * {
            background-color: #eaf3ff !important;
        }

        @media (max-width: 767.98px) {
            .delivery-search {
                max-width: none;
                width: 100%;
            }

            .delivery-kpi-value {
                font-size: 1.1rem;
            }
        }

        .due-filter-divisions {
            max-height: 132px;
            overflow-y: auto;
        }
    </style>
@endpush

@section('content')
    @php
        $year = (int) ($filters['year'] ?? now()->year);
        $month = (int) ($filters['month'] ?? now()->month);
        $selectedDivisions = $filters['divisions'] ?? array_keys($divisionOptions ?? []);
        $allDivisionsSelected = count($selectedDivisions) === count($divisionOptions ?? []);
        $comparison = $deliveryComparison ?? ['rows' => [], 'totals' => ['qty' => [], 'baht' => []], 'due_origins' => []];
        $thaiMonths = [
            1 => 'มกราคม',
            2 => 'กุมภาพันธ์',
            3 => 'มีนาคม',
            4 => 'เมษายน',
            5 => 'พฤษภาคม',
            6 => 'มิถุนายน',
            7 => 'กรกฎาคม',
            8 => 'สิงหาคม',
            9 => 'กันยายน',
            10 => 'ตุลาคม',
            11 => 'พฤศจิกายน',
            12 => 'ธันวาคม',
        ];
        $fmt = fn($value) => (float) $value === 0.0 ? '-' : number_format((float) $value, 0);
        $fmtPercent = fn($value) => $value === null ? '#VALUE!' : number_format((float) $value, 1) . '%';
        $yearTotalCells = [];
        foreach ($typeColumns as $type) {
            $yearTotalCells[$type] = collect($yearRows)->sum(fn($row) => (float) ($row['cells'][$type] ?? 0));
        }
        $yearGrandTotal = collect($yearRows)->sum(fn($row) => (float) ($row['grand_total'] ?? 0));
        $yearFgGratingTotal = collect($yearRows)->sum(fn($row) => (float) ($row['fg_grating'] ?? 0));
        $annualTargetMultiplier = 12;
        $targetGrandTotal = (float) collect($targets)->except(['FG GRATING'])->sum() * $annualTargetMultiplier;
        $yearAchievement = $targetGrandTotal > 0 ? round(($yearGrandTotal / $targetGrandTotal) * 100, 1) : null;
        $yearTypeAchievement = [];
        foreach ($typeColumns as $type) {
            $target = (float) ($targets[$type] ?? 0) * $annualTargetMultiplier;
            $yearTypeAchievement[$type] = $target > 0
                ? round((((float) ($yearTotalCells[$type] ?? 0)) / $target) * 100, 1)
                : null;
        }
        $yearFgTarget = (float) ($targets['FG GRATING'] ?? 0) * $annualTargetMultiplier;
        $yearFgAchievement = $yearFgTarget > 0
            ? round(($yearFgGratingTotal / $yearFgTarget) * 100, 1)
            : null;
    @endphp

    <div class="container-fluid py-3">
        <form class="card card-body mb-3" method="GET" action="{{ route('wos.order_due_date') }}">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-2">
                    <label class="form-label mb-1">เดือน Due Date</label>
                    <select class="form-select" name="month">
                        @foreach ($thaiMonths as $no => $label)
                            <option value="{{ $no }}" @selected($month === $no)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label mb-1">ปี</label>
                    <input type="number" class="form-control" name="year" value="{{ $year }}">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label mb-1">Division สำหรับ Actual Delivery / Excel</label>
                    <div class="border rounded p-2 due-filter-divisions">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="divisions[]" value="ALL"
                                id="dueDivisionAll" @checked($allDivisionsSelected)>
                            <label class="form-check-label fw-semibold" for="dueDivisionAll">เลือกทั้งหมด</label>
                        </div>
                        <div class="d-flex flex-wrap gap-x-3 gap-1">
                            @foreach (($divisionOptions ?? []) as $code => $label)
                                <div class="form-check me-3">
                                    <input class="form-check-input due-division-option" type="checkbox"
                                        name="divisions[]" value="{{ $code }}" id="dueDivision{{ $code }}"
                                        @checked(in_array($code, $selectedDivisions, true))>
                                    <label class="form-check-label" for="dueDivision{{ $code }}">{{ $code }}</label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-5">
                    <div class="d-flex flex-nowrap gap-2">
                        <button class="btn btn-primary flex-fill" type="submit">ค้นหา</button>
                        <a class="btn btn-outline-primary flex-fill"
                            href="{{ route('wos.order_due_date.dashboard', ['year' => $year, 'month' => $month]) }}">Dashboard</a>
                        <button class="btn btn-success flex-fill" type="submit"
                            formaction="{{ route('wos.order_due_date.dashboard.excel') }}">Export Excel</button>
                    </div>
                </div>
            </div>
        </form>

        <section class="mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <h4 class="mb-0 text-danger">{{ $thaiMonths[$month] ?? $month }} Order Volume Report (by Due Date)</h4>
                <span class="text-muted small">อ้างอิงวันที่ duedate <span>
            </div>
            <div class="card">
                <div class="due-report-table-wrap">
                    <table class="table table-sm table-bordered mb-0 due-report-table">
                        <thead>
                            <tr>
                                <th class="sticky-col">Group Sales</th>
                                @foreach ($typeColumns as $type)
                                    <th class="text-center">{{ $type }}</th>
                                @endforeach
                                <th class="text-center">Grand Total</th>
                                <th class="text-center">FG Grating</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($monthRows as $row)
                                @php
                                    $class = match ($row['group_code']) {
                                        'TOTAL' => 'due-row-order',
                                        'TARGET' => 'due-row-target',
                                        'ACH' => 'due-row-achievement',
                                        default => '',
                                    };
                                @endphp
                                <tr class="{{ $class }}">
                                    <td class="sticky-col fw-semibold">{{ $row['group_name'] }}</td>
                                    @foreach ($typeColumns as $type)
                                        <td class="text-end">
                                            @if (!in_array($row['group_code'], ['TOTAL', 'TARGET', 'ACH'], true) && (float) ($row['cells'][$type] ?? 0) > 0)
                                                <a href="{{ route('wos.order_due_date.detail', [
                                                    'year' => $year,
                                                    'month' => $month,
                                                    'group_code' => $row['group_code'],
                                                    'type_name' => $type,
                                                ]) }}"
                                                    class="text-decoration-none">
                                                    {{ $fmt($row['cells'][$type] ?? 0) }}
                                                </a>
                                            @else
                                                {{ $row['group_code'] === 'ACH' ? $fmtPercent($row['cells'][$type] ?? null) : $fmt($row['cells'][$type] ?? 0) }}
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="text-end">
                                        @if (!in_array($row['group_code'], ['TOTAL', 'TARGET', 'ACH'], true) && (float) ($row['grand_total'] ?? 0) > 0)
                                            <a href="{{ route('wos.order_due_date.detail', [
                                                'year' => $year,
                                                'month' => $month,
                                                'group_code' => $row['group_code'],
                                            ]) }}"
                                                class="text-decoration-none">
                                                {{ $fmt($row['grand_total'] ?? 0) }}
                                            </a>
                                        @else
                                            {{ $row['group_code'] === 'ACH' ? $fmtPercent($row['grand_total'] ?? null) : $fmt($row['grand_total'] ?? 0) }}
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        {{ $row['group_code'] === 'ACH' ? $fmtPercent($row['fg_grating'] ?? null) : $fmt($row['fg_grating'] ?? 0) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="mb-4">
            <h4 class="mb-2 text-danger">Orders Due for Delivery by Month</h4>
            <div class="card">
                <div class="due-report-table-wrap">
                    <table class="table table-sm table-bordered mb-0 due-report-table">
                        <thead>
                            <tr>
                                <th class="sticky-col">Order Year {{ $year }}</th>
                                @foreach ($typeColumns as $type)
                                    <th class="text-center">{{ $type }}</th>
                                @endforeach
                                <th class="text-center">Grand Total</th>
                                <th class="text-center">% Achievement</th>
                                <th class="text-center">FG Grating</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="due-row-target">
                                <td class="sticky-col">Total Target</td>
                                @foreach ($typeColumns as $type)
                                    <td class="text-end">{{ $fmt($targets[$type] ?? 0) }}</td>
                                @endforeach
                                <td class="text-end">{{ $fmt(collect($targets)->except(['FG GRATING'])->sum()) }}</td>
                                <td class="text-center">-</td>
                                <td class="text-end">{{ $fmt($targets['FG GRATING'] ?? 0) }}</td>
                            </tr>
                            @foreach ($yearRows as $row)
                                <tr class="{{ (int) $row['month'] === $month ? 'due-month-current' : '' }}">
                                    <td class="sticky-col">Order {{ $row['month_label'] }}</td>
                                    @foreach ($typeColumns as $type)
                                        <td class="text-end">{{ $fmt($row['cells'][$type] ?? 0) }}</td>
                                    @endforeach
                                    <td class="text-end">
                                        @if ((float) ($row['grand_total'] ?? 0) > 0)
                                            <a href="{{ route('wos.order_due_date.detail', [
                                                'year' => $year,
                                                'month' => $row['month'],
                                            ]) }}"
                                                class="text-decoration-none">
                                                {{ $fmt($row['grand_total'] ?? 0) }}
                                            </a>
                                        @else
                                            {{ $fmt($row['grand_total'] ?? 0) }}
                                        @endif
                                    </td>
                                    <td class="text-end">{{ $fmtPercent($row['achievement'] ?? null) }}</td>
                                    <td class="text-end">{{ $fmt($row['fg_grating'] ?? 0) }}</td>
                                </tr>
                            @endforeach
                            <tr class="due-row-order">
                                <td class="sticky-col">Total Order</td>
                                @foreach ($typeColumns as $type)
                                    <td class="text-end">{{ $fmt($yearTotalCells[$type] ?? 0) }}</td>
                                @endforeach
                                <td class="text-end">{{ $fmt($yearGrandTotal) }}</td>
                                <td class="text-end">-</td>
                                <td class="text-end">{{ $fmt($yearFgGratingTotal) }}</td>
                            </tr>
                            <tr class="due-row-achievement">
                                <td class="sticky-col">% Achievement</td>
                                @foreach ($typeColumns as $type)
                                    <td class="text-end">{{ $fmtPercent($yearTypeAchievement[$type] ?? null) }}</td>
                                @endforeach
                                <td class="text-end">{{ $fmtPercent($yearAchievement) }}</td>
                                <td class="text-end">-</td>
                                <td class="text-end">{{ $fmtPercent($yearFgAchievement) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        @include('formwos.order_due_date._delivery_comparison')

    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const all = document.getElementById('dueDivisionAll');
            const options = Array.from(document.querySelectorAll('.due-division-option'));
            if (all && options.length > 0) {
                all.addEventListener('change', () => options.forEach(option => option.checked = all.checked));
                options.forEach(option => option.addEventListener('change', () => {
                    all.checked = options.every(item => item.checked);
                }));
            }

            const search = document.getElementById('dueComparisonSearch');
            const rows = Array.from(document.querySelectorAll('[data-comparison-row]'));
            const noMatch = document.getElementById('dueComparisonNoMatch');
            if (search && rows.length > 0 && noMatch) {
                search.addEventListener('input', () => {
                    const keyword = search.value.trim().toLowerCase();
                    let visibleCount = 0;
                    rows.forEach(row => {
                        const visible = keyword === '' || (row.dataset.search || '').includes(keyword);
                        row.classList.toggle('d-none', !visible);
                        if (visible) visibleCount++;
                    });
                    noMatch.classList.toggle('d-none', visibleCount !== 0);
                });
            }
        })();
    </script>
@endpush
