@extends('layouts.layout')

@section('title', 'Variable Cost - Accounts')
@section('page-title', 'Variable Cost')

@section('content')
    @php
        $accountSummary = collect($accountSummary ?? []);
        $fmt = fn($value, $decimals = 0) => number_format((float) $value, $decimals);
    @endphp

    @include('formvc.partials.styles')

    <div class="vc-wrap">
        @include('formvc.partials.header')

        <div class="vc-card mb-3">
            <div class="vc-card-header">ค่าใช้จ่ายแยกตามบัญชี</div>
            <div class="chart-box tall"><canvas id="vcAccountChart"></canvas></div>
        </div>

        <div class="vc-card">
            <div class="vc-card-header">ตารางบัญชีทั้งหมด</div>
            <div class="vc-table-wrap">
                <table class="table table-bordered table-sm vc-table mb-0">
                    <thead><tr><th>รหัสบัญชี</th><th>ชื่อบัญชี</th><th class="num">รายการ</th><th class="num">ยอดรวม</th><th class="num">เฉลี่ย</th></tr></thead>
                    <tbody>
                        @forelse ($accountSummary as $row)
                            <tr>
                                <td>{{ $row->account_code }}</td>
                                <td class="text-tight">{{ $row->account_name }}</td>
                                <td class="num">{{ $fmt($row->line_count) }}</td>
                                <td class="num fw-bold">{{ $fmt($row->total_amount, 2) }}</td>
                                <td class="num">{{ $fmt($row->line_count > 0 ? $row->total_amount / $row->line_count : 0, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">No data</td></tr>
                        @endforelse
                    </tbody>
                    @if ($accountSummary->isNotEmpty())
                        <tfoot>
                            <tr>
                                <td colspan="2">Total</td>
                                <td class="num">{{ $fmt($accountSummary->sum('line_count')) }}</td>
                                <td class="num">{{ $fmt($accountSummary->sum('total_amount'), 2) }}</td>
                                <td class="num">{{ $fmt($accountSummary->sum('line_count') > 0 ? $accountSummary->sum('total_amount') / $accountSummary->sum('line_count') : 0, 2) }}</td>
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

            const sourceRows = @json($accountSummary->values());
            const topRows = sourceRows.slice(0, 15);
            const otherRows = sourceRows.slice(15);
            const otherTotal = otherRows.reduce((sum, row) => sum + Number(row.total_amount || 0), 0);
            const rows = otherTotal > 0
                ? topRows.concat([{ account_code: 'Other', account_name: 'บัญชีอื่น ๆ', total_amount: otherTotal }])
                : topRows;
            const money = value => Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

            new Chart(document.getElementById('vcAccountChart'), {
                type: 'bar',
                data: {
                    labels: rows.map(row => `${row.account_code} ${row.account_name}`),
                    datasets: [{ data: rows.map(row => row.total_amount), backgroundColor: '#b8421f' }]
                },
                options: {
                    indexAxis: 'y',
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => money(ctx.raw) } } },
                    scales: { x: { ticks: { callback: money } }, y: { ticks: { font: { size: 10 } }, grid: { display: false } } }
                }
            });
        });
    </script>
@endpush
