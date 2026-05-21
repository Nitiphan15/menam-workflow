@extends('layouts.layout')

@section('title', 'Sales Delivery Volume')
@section('page-title', 'Sales Delivery Volume')

@push('styles')
    <style>
        .summary-table-wrap {
            max-height: calc(100vh - 240px);
            overflow: auto;
            position: relative;
        }

        .summary-table {
            font-size: .84rem;
            min-width: 1200px;
        }

        .summary-table thead th {
            position: sticky;
            top: 0;
            z-index: 20;
            background: #f8f9fa;
            vertical-align: middle;
            text-align: center;
            white-space: nowrap;
        }

        .summary-table .sticky-col {
            position: sticky;
            left: 0;
            z-index: 15;
            background: #fff;
            white-space: nowrap;
        }

        .summary-table thead .sticky-col {
            z-index: 30;
            background: #f8f9fa;
        }

        .summary-table .sticky-action {
            position: sticky;
            right: 0;
            z-index: 15;
            background: #fff;
            text-align: center;
        }

        .summary-table thead .sticky-action {
            z-index: 30;
            background: #f8f9fa;
        }

        .summary-table tr.table-warning .sticky-col,
        .summary-table tr.table-warning .sticky-action {
            background: #fff3cd !important;
        }

        .summary-year-current td {
            color: #1d4ed8;
            font-weight: 700;
        }
    </style>
@endpush

@section('content')
    @php
        $from = $filters['from'] ?? now()->startOfWeek()->toDateString();
        $to = $filters['to'] ?? now()->endOfWeek()->toDateString();
        $year = (int) ($filters['year'] ?? now()->year);
        $month = (int) ($filters['month'] ?? now()->month);
        $selectedProduct = $filters['product'] ?? '';
        $selectedDivision = $filters['division'] ?? '';
        $months = [
            1 => 'January',
            2 => 'February',
            3 => 'March',
            4 => 'April',
            5 => 'May',
            6 => 'June',
            7 => 'July',
            8 => 'August',
            9 => 'September',
            10 => 'October',
            11 => 'November',
            12 => 'December',
        ];
        $fmt = function ($v) {
            $v = (float) $v;
            return $v == 0.0 ? '-' : number_format($v, 0);
        };
        $fmtPercent = fn($value) => $value === null ? '-' : number_format((float) $value, 1) . '%';
        $yearTotalCells = [];
        foreach ($typeColumns as $typeColumn) {
            $yearTotalCells[$typeColumn] = collect($yearRows ?? [])->sum(fn($row) => (float) ($row['cells'][$typeColumn] ?? 0));
        }
        $yearGrandTotal = collect($yearRows ?? [])->sum(fn($row) => (float) ($row['grand_total'] ?? 0));
        $yearFgGrating = collect($yearRows ?? [])->sum(fn($row) => (float) ($row['fg_grating'] ?? 0));
        $targetGrandTotal = (float) collect($targets ?? [])->except(['FG GRATING'])->sum();
        $targetFgGrating = (float) ($targets['FG GRATING'] ?? 0);
        $yearAchievement = $targetGrandTotal > 0 ? round(($yearGrandTotal / ($targetGrandTotal * 12)) * 100, 1) : null;
        $yearFgGratingAchievement = $targetFgGrating > 0 ? round(($yearFgGrating / ($targetFgGrating * 12)) * 100, 1) : null;
        $yearTypeAchievement = [];
        foreach ($typeColumns as $typeColumn) {
            $target = (float) ($targets[$typeColumn] ?? 0) * 12;
            $yearTypeAchievement[$typeColumn] = $target > 0 ? round(((float) ($yearTotalCells[$typeColumn] ?? 0) / $target) * 100, 1) : null;
        }
    @endphp

    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-2">
            <div>
                <h4 class="mb-0">Delivery Volume Filter</h4>
            </div>
        </div>

        <form class="card card-body mb-3" method="GET" action="{{ route('wos.sales_unit_summary') }}">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-2 col-xl-1">
                    <label class="form-label mb-1">Year</label>
                    <input type="number" class="form-control js-period-control" name="year" value="{{ $year }}">
                </div>

                <div class="col-6 col-md-2 col-xl-2">
                    <label class="form-label mb-1">Month</label>
                    <select class="form-select js-period-control" name="month">
                        @foreach ($months as $no => $label)
                            <option value="{{ $no }}" @selected($month === $no)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 col-md-3 col-xl-2">
                    <label class="form-label mb-1">From</label>
                    <input type="date" class="form-control" name="from" value="{{ $from }}" data-period-from>
                </div>

                <div class="col-12 col-md-3 col-xl-2">
                    <label class="form-label mb-1">To</label>
                    <input type="date" class="form-control" name="to" value="{{ $to }}" data-period-to>
                </div>

                <div class="col-12 col-md-4 col-xl-2">
                    <label class="form-label mb-1">Product</label>
                    <select class="form-select" name="product">
                        <option value="">All Products</option>
                        @foreach ($productOptions as $product)
                            <option value="{{ $product }}" @selected($selectedProduct === $product)>{{ $product }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 col-md-4 col-xl-2">
                    <label class="form-label mb-1">Division</label>
                    <select class="form-select" name="division">
                        <option value="">All Divisions</option>
                        @foreach ($divisionOptions as $code => $label)
                            <option value="{{ $code }}" @selected($selectedDivision === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 col-md-4 col-xl-1 d-grid">
                    <button class="btn btn-primary">Search</button>
                </div>

                <div class="col-12 col-md-4 col-xl-1 d-grid">
                    <a href="{{ route('wos.sales_unit_summary', [
                        'from' => $from,
                        'to' => $to,
                        'year' => $year,
                        'month' => $month,
                        'product' => $selectedProduct,
                        'division' => $selectedDivision,
                        'export' => 1,
                    ]) }}" class="btn btn-success">
                        CSV
                    </a>
                </div>

                <div class="col-12 col-md-4 col-xl-1 d-grid">
                    <a href="{{ route('wos.sales_unit_summary.dashboard', [
                        'year' => $year,
                        'month' => $month,
                        'product' => $selectedProduct,
                        'division' => $selectedDivision !== '' ? [$selectedDivision] : [],
                    ]) }}" class="btn btn-outline-primary">Dashboard</a>
                </div>
            </div>
        </form>

        <section class="mb-4">
            <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-2">
                <div>
                    <h4 class="mb-0">Delivery Volume by Division</h4>
                    <div class="text-muted small mt-1">
                        From <strong>{{ \Carbon\Carbon::parse($from)->format('d/m/Y') }}</strong>
                        to <strong>{{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}</strong>
                        @if ($selectedProduct !== '')
                            | Product: <strong>{{ $selectedProduct }}</strong>
                        @endif
                        @if ($selectedDivision !== '')
                            | Division: <strong>{{ $selectedDivision }}</strong>
                        @endif
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="summary-table-wrap">
                    <table class="table table-sm table-bordered align-middle mb-0 summary-table">
                        <thead>
                            <tr>
                                <th class="sticky-col" style="min-width:260px;">Division</th>
                                @foreach ($typeColumns as $col)
                                    <th style="min-width:110px;">{{ $col }}</th>
                                @endforeach
                                <th style="min-width:120px;">Grand Total</th>
                                <th style="min-width:120px;">FG GRATING</th>
                                <th class="sticky-action" style="min-width:90px;">Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $r)
                                @php
                                    $isTotal = $r['group_code'] === 'TOTAL';
                                    $hasData = (float) ($r['grand_total'] ?? 0) > 0;
                                @endphp
                                <tr class="{{ $isTotal ? 'table-warning' : '' }}">
                                    <td class="sticky-col fw-semibold">{{ $r['group_name'] }}</td>
                                    @foreach ($typeColumns as $col)
                                        @php
                                            $cellValue = (float) ($r['cells'][$col] ?? 0);
                                        @endphp
                                        <td class="text-end">
                                            @if (!$isTotal && $cellValue > 0)
                                                <a href="{{ route('wos.sales_unit_summary.detail', [
                                                    'group_code' => $r['group_code'],
                                                    'type_name' => $col,
                                                    'from' => $from,
                                                    'to' => $to,
                                                ]) }}" class="text-decoration-none">{{ $fmt($cellValue) }}</a>
                                            @else
                                                {{ $fmt($cellValue) }}
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="text-end fw-bold">{{ $fmt($r['grand_total'] ?? 0) }}</td>
                                    @php $fgValue = (float) ($r['fg_grating'] ?? 0); @endphp
                                    <td class="text-end fw-bold">
                                        @if (!$isTotal && $fgValue > 0)
                                            <a href="{{ route('wos.sales_unit_summary.detail', [
                                                'group_code' => $r['group_code'],
                                                'type_name' => 'FG GRATING',
                                                'from' => $from,
                                                'to' => $to,
                                            ]) }}" class="text-decoration-none">{{ $fmt($fgValue) }}</a>
                                        @else
                                            {{ $fmt($fgValue) }}
                                        @endif
                                    </td>
                                    <td class="sticky-action">
                                        @if (!$isTotal && $hasData)
                                            <a href="{{ route('wos.sales_unit_summary.detail_group', [
                                                'group_code' => $r['group_code'],
                                                'type_name' => $selectedProduct,
                                                'from' => $from,
                                                'to' => $to,
                                            ]) }}" class="btn btn-sm btn-outline-primary">View</a>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ count($typeColumns) + 4 }}" class="text-center text-muted py-4">
                                        No data
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="mb-4">
            <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-2">
                <div>
                    <h4 class="mb-0">Delivery Volume Year {{ $year }}</h4>
                    <div class="text-muted small mt-1">Summary by Product / Month</div>
                </div>
            </div>

            <div class="card">
                <div class="summary-table-wrap">
                    <table class="table table-sm table-bordered align-middle mb-0 summary-table">
                        <thead>
                            <tr>
                                <th class="sticky-col" style="min-width:180px;">Month</th>
                                @foreach ($typeColumns as $col)
                                    <th style="min-width:110px;">{{ $col }}</th>
                                @endforeach
                                <th style="min-width:120px;">Grand Total</th>
                                <th style="min-width:120px;">FG GRATING</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="table-success">
                                <td class="sticky-col fw-bold">Total Target</td>
                                @foreach ($typeColumns as $col)
                                    <td class="text-end fw-bold">{{ $fmt($targets[$col] ?? 0) }}</td>
                                @endforeach
                                <td class="text-end fw-bold">{{ $fmt($targetGrandTotal) }}</td>
                                <td class="text-end fw-bold">{{ $fmt($targetFgGrating) }}</td>
                            </tr>
                            @foreach ($yearRows as $row)
                                @php
                                    $rowMonth = (int) ($row['month'] ?? 0);
                                    $rangeFrom = \Carbon\Carbon::create($year, $rowMonth, 1)->startOfMonth()->toDateString();
                                    $rangeTo = \Carbon\Carbon::create($year, $rowMonth, 1)->endOfMonth()->toDateString();
                                @endphp
                                <tr class="{{ $rowMonth === $month ? 'summary-year-current' : '' }}">
                                    <td class="sticky-col fw-semibold">Delivery {{ $row['month_label'] }}</td>
                                    @foreach ($typeColumns as $col)
                                        @php
                                            $monthCell = (float) ($row['cells'][$col] ?? 0);
                                        @endphp
                                        <td class="text-end">
                                            @if ($monthCell > 0 && $selectedDivision !== '')
                                                <a href="{{ route('wos.sales_unit_summary.detail', [
                                                    'group_code' => $selectedDivision,
                                                    'type_name' => $col,
                                                    'from' => $rangeFrom,
                                                    'to' => $rangeTo,
                                                ]) }}" class="text-decoration-none">{{ $fmt($monthCell) }}</a>
                                            @else
                                                {{ $fmt($monthCell) }}
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="text-end fw-bold">
                                        @if ((float) ($row['grand_total'] ?? 0) > 0 && $selectedDivision !== '')
                                            <a href="{{ route('wos.sales_unit_summary.detail_group', [
                                                'group_code' => $selectedDivision,
                                                'type_name' => $selectedProduct,
                                                'from' => $rangeFrom,
                                                'to' => $rangeTo,
                                            ]) }}" class="text-decoration-none">{{ $fmt($row['grand_total'] ?? 0) }}</a>
                                        @else
                                            {{ $fmt($row['grand_total'] ?? 0) }}
                                        @endif
                                    </td>
                                    @php $rowFg = (float) ($row['fg_grating'] ?? 0); @endphp
                                    <td class="text-end fw-bold">
                                        @if ($rowFg > 0 && $selectedDivision !== '')
                                            <a href="{{ route('wos.sales_unit_summary.detail', [
                                                'group_code' => $selectedDivision,
                                                'type_name' => 'FG GRATING',
                                                'from' => $rangeFrom,
                                                'to' => $rangeTo,
                                            ]) }}" class="text-decoration-none">{{ $fmt($rowFg) }}</a>
                                        @else
                                            {{ $fmt($rowFg) }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            <tr class="table-warning">
                                <td class="sticky-col fw-bold">Total Delivery</td>
                                @foreach ($typeColumns as $col)
                                    <td class="text-end fw-bold">{{ $fmt($yearTotalCells[$col] ?? 0) }}</td>
                                @endforeach
                                <td class="text-end fw-bold">{{ $fmt($yearGrandTotal) }}</td>
                                <td class="text-end fw-bold">{{ $fmt($yearFgGrating) }}</td>
                            </tr>
                            <tr class="table-warning">
                                <td class="sticky-col fw-bold">% Achievement</td>
                                @foreach ($typeColumns as $col)
                                    <td class="text-end fw-bold">{{ $fmtPercent($yearTypeAchievement[$col] ?? null) }}</td>
                                @endforeach
                                <td class="text-end fw-bold">{{ $fmtPercent($yearAchievement) }}</td>
                                <td class="text-end fw-bold">{{ $fmtPercent($yearFgGratingAchievement) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const yearInput = document.querySelector('[name="year"].js-period-control');
            const monthInput = document.querySelector('[name="month"].js-period-control');
            const fromInput = document.querySelector('[data-period-from]');
            const toInput = document.querySelector('[data-period-to]');
            if (!yearInput || !monthInput || !fromInput || !toInput) return;

            const pad = (value) => String(value).padStart(2, '0');
            const updateDateRange = () => {
                const year = Number(yearInput.value);
                const month = Number(monthInput.value);
                if (!year || !month) return;
                const lastDay = new Date(year, month, 0).getDate();
                fromInput.value = `${year}-${pad(month)}-01`;
                toInput.value = `${year}-${pad(month)}-${pad(lastDay)}`;
            };

            yearInput.addEventListener('change', updateDateRange);
            monthInput.addEventListener('change', updateDateRange);
        })();
    </script>
@endpush
