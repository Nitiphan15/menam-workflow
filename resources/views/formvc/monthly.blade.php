@extends('layouts.layout')

@section('title', 'Variable Cost - Monthly')
@section('page-title', 'Variable Cost')

@section('content')
    @php
        $monthlySummary = collect($monthlySummary ?? []);
        $departmentMonthly = collect($departmentMonthly ?? []);
        $fmt = fn($value, $decimals = 0) => $value === null ? '-' : number_format((float) $value, $decimals);
        $pct = fn($value) => $value === null ? '-' : (($value >= 0 ? '+' : '') . number_format((float) $value, 1) . '%');
        $monthLabels = [1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.', 5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.', 9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.'];
        $previousYear = $previousYear ?? (($year ?? date('Y')) - 1);
        $comparePrevious = !empty($comparePrevious);
        $toggleQuery = request()->except('compare_previous');
        if (!$comparePrevious) {
            $toggleQuery['compare_previous'] = 1;
        }
        $toggleUrl = route('variable-cost.monthly', $toggleQuery);
    @endphp

    @include('formvc.partials.styles')

    <div class="vc-wrap">
        @include('formvc.partials.header')

        <div class="vc-card mb-3">
            <div class="vc-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>
                    ค่าใช้จ่ายแต่ละแผนก รายเดือน
                    @if ($comparePrevious)
                        <span class="text-muted small">(เทียบกับปี {{ $previousYear }})</span>
                    @endif
                </span>
                <a href="{{ $toggleUrl }}" class="btn btn-sm {{ $comparePrevious ? 'btn-outline-secondary' : 'btn-outline-primary' }}">
                    @if ($comparePrevious)
                        <i class="fas fa-times me-1"></i> ยกเลิกการเปรียบเทียบ
                    @else
                        <i class="fas fa-chart-line me-1"></i> เปรียบเทียบกับปี {{ $previousYear }}
                    @endif
                </a>
            </div>
            <div class="vc-table-wrap">
                <table class="table table-bordered table-sm vc-table mb-0">
                    <thead>
                        <tr>
                            <th>รหัส</th>
                            <th>แผนก</th>
                            @foreach ($monthLabels as $label)
                                <th class="num">{{ $label }}</th>
                            @endforeach
                            <th class="num">รวมทั้งปี</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($departmentMonthly as $row)
                            <tr>
                                <td>{{ $row->department_code ?: '-' }}</td>
                                <td>{{ $row->department }}</td>
                                @foreach ($monthLabels as $month => $label)
                                    @php
                                        $amount = $row->months[$month] ?? null;
                                        $previous = $row->previous_months[$month] ?? null;
                                        $diffPercent = $amount !== null && $previous != 0.0 ? (((float) $amount - (float) $previous) / (float) $previous) * 100 : null;
                                    @endphp
                                    <td class="num vc-yoy-cell">
                                        <div>{{ $fmt($amount, 2) }}</div>
                                        @if ($comparePrevious && $amount !== null && $previous != 0.0)
                                            <div class="vc-yoy-sub {{ ((float) $amount - (float) $previous) >= 0 ? 'up' : 'down' }}">
                                                {{ $fmt($previous, 0) }} / {{ $pct($diffPercent) }}
                                            </div>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="num fw-bold vc-yoy-cell">
                                    <div>{{ $fmt($row->total_amount, 2) }}</div>
                                    @if ($comparePrevious && ($row->previous_total_amount ?? 0) != 0.0)
                                        <div class="vc-yoy-sub {{ ($row->diff_amount ?? 0) >= 0 ? 'up' : 'down' }}">
                                            {{ $fmt($row->previous_total_amount, 0) }} / {{ $pct($row->diff_percent) }}
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="15" class="text-center text-muted py-4">No data</td></tr>
                        @endforelse
                    </tbody>
                    @if ($departmentMonthly->isNotEmpty())
                        <tfoot>
                            <tr>
                                <td colspan="2">Total</td>
                                @foreach ($monthLabels as $month => $label)
                                    @php
                                        $hasMonth = $departmentMonthly->contains(fn($row) => array_key_exists($month, $row->months ?? []) && $row->months[$month] !== null);
                                        $amount = $hasMonth ? (float) $departmentMonthly->sum(fn($row) => $row->months[$month] ?? 0) : null;
                                        $previous = $hasMonth ? (float) $departmentMonthly->sum(fn($row) => $row->previous_months[$month] ?? 0) : null;
                                        $diffPercent = $amount !== null && $previous != 0.0 ? (($amount - $previous) / $previous) * 100 : null;
                                    @endphp
                                    <td class="num vc-yoy-cell">
                                        <div>{{ $fmt($amount, 2) }}</div>
                                        @if ($comparePrevious && $amount !== null && $previous != 0.0)
                                            <div class="vc-yoy-sub {{ ($amount - $previous) >= 0 ? 'up' : 'down' }}">
                                                {{ $fmt($previous, 0) }} / {{ $pct($diffPercent) }}
                                            </div>
                                        @endif
                                    </td>
                                @endforeach
                                @php
                                    $total = (float) $departmentMonthly->sum('total_amount');
                                    $previousTotal = (float) $departmentMonthly->sum('previous_total_amount');
                                    $totalDiffPercent = $previousTotal != 0.0 ? (($total - $previousTotal) / $previousTotal) * 100 : null;
                                @endphp
                                <td class="num vc-yoy-cell">
                                    <div>{{ $fmt($total, 2) }}</div>
                                    @if ($comparePrevious && $previousTotal != 0.0)
                                        <div class="vc-yoy-sub {{ ($total - $previousTotal) >= 0 ? 'up' : 'down' }}">
                                            {{ $fmt($previousTotal, 0) }} / {{ $pct($totalDiffPercent) }}
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>

        <div class="vc-card">
            <div class="vc-card-header">แนวโน้มค่าใช้จ่ายรวมรายเดือน</div>
            <div class="chart-box"><canvas id="vcMonthChart"></canvas></div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (!window.Chart) return;

            const rows = @json($monthlySummary->values());
            const comparePrevious = @json($comparePrevious);
            const money = value => Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

            const datasets = [
                { label: '{{ $year ?? '' }}', data: rows.map(row => row.total_amount), borderColor: '#2d6a4f', backgroundColor: 'rgba(45,106,79,.12)', tension: .25, fill: true }
            ];
            if (comparePrevious) {
                datasets.push({ label: '{{ $previousYear }}', data: rows.map(row => row.previous_total_amount), borderColor: '#b8421f', backgroundColor: 'rgba(184,66,31,.08)', borderDash: [6, 4], tension: .25, fill: false });
            }

            new Chart(document.getElementById('vcMonthChart'), {
                type: 'line',
                data: {
                    labels: rows.map(row => row.month),
                    datasets: datasets
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: { tooltip: { callbacks: { label: ctx => `${ctx.dataset.label}: ${money(ctx.raw)}` } } },
                    scales: { y: { ticks: { callback: money } } }
                }
            });
        });
    </script>
@endpush
