@extends('layouts.layout')

@section('title', 'Variable Cost - Summary')
@section('page-title', 'Variable Cost')

@section('content')
    @php
        $departmentSummary = collect($departmentSummary ?? []);
        $fmt = fn($value, $decimals = 0) => number_format((float) $value, $decimals);
        $grandTotal = max((float) $departmentSummary->sum('total_amount'), 1);
    @endphp

    @include('formvc.partials.styles')

    <div class="vc-wrap">
        @include('formvc.partials.header')

        <div class="row g-3 mb-3">
            <div class="col-xl-7">
                <div class="vc-card h-100">
                    <div class="vc-card-header">Top แผนก ค่าใช้จ่ายสูงสุด</div>
                    <div class="chart-box tall"><canvas id="vcDepartmentChart"></canvas></div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="vc-card h-100">
                    <div class="vc-card-header">สัดส่วนค่าใช้จ่ายตามแผนก</div>
                    <div class="chart-box tall"><canvas id="vcDepartmentPieChart"></canvas></div>
                </div>
            </div>
        </div>

        <div class="vc-card">
            <div class="vc-card-header">ตารางสรุปทุกแผนก</div>
            <div class="vc-table-wrap">
                <table class="table table-bordered table-sm vc-table mb-0">
                    <thead><tr><th>รหัส</th><th>แผนก</th><th class="num">บิล</th><th class="num">รายการ</th><th class="num">ยอดรวม</th><th class="num">เฉลี่ย</th><th class="num">% รวม</th></tr></thead>
                    <tbody>
                        @forelse ($departmentSummary as $row)
                            <tr>
                                <td>{{ $row->department_code ?: '-' }}</td>
                                <td>{{ $row->department }}</td>
                                <td class="num">{{ $fmt($row->bill_count) }}</td>
                                <td class="num">{{ $fmt($row->line_count) }}</td>
                                <td class="num fw-bold">{{ $fmt($row->total_amount, 2) }}</td>
                                <td class="num">{{ $fmt($row->avg_amount, 2) }}</td>
                                <td class="num">{{ $fmt(($row->total_amount / $grandTotal) * 100, 1) }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">No data</td></tr>
                        @endforelse
                    </tbody>
                    @if ($departmentSummary->isNotEmpty())
                        <tfoot>
                            <tr>
                                <td colspan="2">Total</td>
                                <td class="num">{{ $fmt($kpis['bill_count'] ?? $departmentSummary->sum('bill_count')) }}</td>
                                <td class="num">{{ $fmt($departmentSummary->sum('line_count')) }}</td>
                                <td class="num">{{ $fmt($departmentSummary->sum('total_amount'), 2) }}</td>
                                <td class="num">{{ $fmt($departmentSummary->sum('line_count') > 0 ? $departmentSummary->sum('total_amount') / $departmentSummary->sum('line_count') : 0, 2) }}</td>
                                <td class="num">100.0%</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (!window.Chart) return;

            const sourceRows = @json($departmentSummary->values());
            const topRows = sourceRows.slice(0, 10);
            const otherRows = sourceRows.slice(10);
            const otherTotal = otherRows.reduce((sum, row) => sum + Number(row.total_amount || 0), 0);
            const rows = otherTotal > 0
                ? topRows.concat([{ department_code: 'Other', department: 'แผนกอื่น ๆ', total_amount: otherTotal }])
                : topRows;
            const palette = ['#b8421f','#2d6a4f','#b8860b','#5a3a8a','#c9302c','#1a6b85','#7d4f3c','#3a6b2d','#85591a','#6b1a4a','#1a5a85','#854a1a'];
            const money = value => Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
            const deptLabel = row => row.department_code ? `${row.department_code} ${row.department}` : row.department;

            new Chart(document.getElementById('vcDepartmentChart'), {
                type: 'bar',
                data: { labels: rows.map(deptLabel), datasets: [{ data: rows.map(row => row.total_amount), backgroundColor: rows.map((_, i) => palette[i % palette.length]) }] },
                options: {
                    indexAxis: 'y',
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => money(ctx.raw) } } },
                    scales: { x: { ticks: { callback: money } }, y: { grid: { display: false } } }
                }
            });

            new Chart(document.getElementById('vcDepartmentPieChart'), {
                type: 'doughnut',
                data: { labels: rows.map(row => row.department_code || row.department), datasets: [{ data: rows.map(row => row.total_amount), backgroundColor: rows.map((_, i) => palette[i % palette.length]) }] },
                options: { maintainAspectRatio: false, plugins: { tooltip: { callbacks: { label: ctx => `${ctx.label}: ${money(ctx.raw)}` } } } }
            });
        });
    </script>
@endpush
