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
    </style>
@endpush

@section('content')
    @php
        $year = (int) ($filters['year'] ?? now()->year);
        $month = (int) ($filters['month'] ?? now()->month);
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
                <div class="col-12 col-md-3">
                    <label class="form-label mb-1">เดือน Due Date</label>
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
                    <button class="btn btn-primary">ค้นหา</button>
                </div>
                <div class="col-12 col-md-2 d-grid">
                    <a class="btn btn-outline-primary"
                        href="{{ route('wos.order_due_date.dashboard', ['year' => $year, 'month' => $month]) }}">Dashboard</a>
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

    </div>
@endsection
