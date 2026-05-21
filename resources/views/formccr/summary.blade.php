@extends('layouts.layout')

@section('title', 'Cost Center - สรุปทั้งปี')
@section('page-title', 'Cost Center Report')

@section('content')
    @php
        $byClass = collect($byClass ?? []);
        $kpis = $kpis ?? [];
        $chart = $chart ?? ['monthly' => ['labels' => [], 'total' => [], 'sites' => []], 'topAccounts' => ['labels' => [], 'data' => []]];
        $fmt = fn($v, $d = 2) => ((float) $v) == 0.0 ? '-' : number_format((float) $v, $d);
        $fmtInt = fn($v) => number_format((int) $v);
    @endphp

    @include('formccr.partials.styles')

    <div class="ccr-wrap">

        <div class="ccr-card mb-3">
            <div class="ccr-card-header">
                <span><i class="fas fa-filter me-1 text-primary"></i> ตัวกรอง — สรุปทั้งปี</span>
                <span class="ccr-meta">ดึงจาก SQL aggregate · โหลดเร็ว</span>
            </div>
            <form method="GET" action="{{ route('cost-center.summary') }}" class="ccr-card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-2 col-md-3">
                        <label class="form-label">ปี</label>
                        <input type="number" name="year" class="form-control" value="{{ $year }}" min="2000" max="2100">
                    </div>
                    <div class="col-lg-2 col-md-3">
                        <label class="form-label">Site</label>
                        <select name="site" class="form-select">
                            <option value="ALL" {{ $site === 'ALL' ? 'selected' : '' }}>ALL</option>
                            <option value="WIRE" {{ $site === 'WIRE' ? 'selected' : '' }}>WIRE</option>
                            <option value="PLUS" {{ $site === 'PLUS' ? 'selected' : '' }}>PLUS</option>
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-4">
                        <label class="form-label">Class (เลือกเฉพาะ — เว้นว่าง = ทุก class)</label>
                        <input type="text" name="classnumber" class="form-control" value="{{ $classnumber }}" list="ccrClassOptions" placeholder="เช่น 1001">
                        <datalist id="ccrClassOptions">
                            @foreach ($classOptions ?? [] as $opt)
                                <option value="{{ $opt->value }}">{{ $opt->label }}</option>
                            @endforeach
                        </datalist>
                    </div>
                    <div class="col-lg-5 col-md-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i> Search</button>
                        <a href="{{ route('cost-center.summary') }}" class="btn btn-outline-secondary"><i class="fas fa-rotate-left me-1"></i> Reset</a>
                    </div>
                </div>
            </form>
        </div>

        @include('formccr.partials.header')

        <div class="row g-3 mb-3">
            <div class="col-xl-3 col-md-6">
                <div class="ccr-kpi">
                    <div class="label">ยอดรวมทั้งปี</div>
                    <div class="value">{{ $fmt($kpis['total_amount'] ?? 0) }}</div>
                    <div class="sub">บาท · ปี {{ $year }}</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="ccr-kpi kpi-amber">
                    <div class="label">จำนวน Class</div>
                    <div class="value">{{ $fmtInt($kpis['class_count'] ?? 0) }}</div>
                    <div class="sub">cost center</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="ccr-kpi kpi-green">
                    <div class="label">จำนวนบัญชี</div>
                    <div class="value">{{ $fmtInt($kpis['account_count'] ?? 0) }}</div>
                    <div class="sub">accounts</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="ccr-kpi kpi-rose">
                    <div class="label">จำนวน Allocation</div>
                    <div class="value">{{ $fmtInt($kpis['allocation_rows'] ?? 0) }}</div>
                    <div class="sub">รายการ</div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-7">
                <div class="ccr-card h-100">
                    <div class="ccr-card-header">
                        <span><i class="fas fa-chart-line me-1 text-primary"></i> แนวโน้มรายเดือน — ปี {{ $year }}</span>
                        <span class="ccr-meta">รวมต่อเดือน{{ $site === 'ALL' ? ' · แยกตาม site' : '' }}</span>
                    </div>
                    <div class="ccr-chart-box tall">
                        <canvas id="ccrMonthlyChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="ccr-card h-100">
                    <div class="ccr-card-header">
                        <span><i class="fas fa-chart-bar me-1 text-primary"></i> Top 10 บัญชี ตามยอดรวม</span>
                    </div>
                    <div class="ccr-chart-box tall">
                        <canvas id="ccrAccountChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="ccr-card">
            <div class="ccr-card-header">
                <span><i class="fas fa-table me-1 text-primary"></i> สรุปแยกตาม Class → บัญชี</span>
                <span class="ccr-meta">คลิกแถว class เพื่อขยาย/ยุบบัญชี · คลิกชื่อ class เพื่อดู detail</span>
            </div>
            <div class="ccr-table-wrap">
                <table class="table table-bordered ccr-table">
                    <thead>
                        <tr>
                            <th style="min-width:280px">Class / บัญชี</th>
                            <th class="num" style="width:140px">Allocation Rows</th>
                            <th class="num" style="width:180px">ยอดรวม (บาท)</th>
                            <th class="num" style="width:120px">% ของรวม</th>
                            <th style="width:120px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $grandTotal = (float) ($kpis['total_amount'] ?? 0); @endphp
                        @forelse ($byClass as $classIndex => $class)
                            <tr class="ccr-class-row" data-target="ccrAcc{{ $classIndex }}">
                                <td><span class="toggle-ic"></span>{{ $class->class_group }}</td>
                                <td class="num">{{ $fmtInt($class->allocation_rows) }}</td>
                                <td class="num">{{ $fmt($class->total) }}</td>
                                <td class="num">
                                    {{ $grandTotal > 0 ? number_format($class->total / $grandTotal * 100, 1) . '%' : '-' }}
                                </td>
                                <td>
                                    <a class="ccr-drill-link"
                                       href="{{ route('cost-center.detail', ['year' => $year, 'site' => $site, 'classnumber' => $class->classnumber]) }}"
                                       onclick="event.stopPropagation();">
                                        <i class="fas fa-eye"></i> Detail
                                    </a>
                                </td>
                            </tr>
                            @foreach ($class->accounts as $acc)
                                <tr class="ccr-account-row d-none" data-group="ccrAcc{{ $classIndex }}">
                                    <td style="padding-left:48px">{{ $acc->account_label }}</td>
                                    <td class="num">{{ $fmtInt($acc->allocation_rows) }}</td>
                                    <td class="num">{{ $fmt($acc->ccalloc_total) }}</td>
                                    <td class="num">
                                        {{ $class->total > 0 ? number_format($acc->ccalloc_total / $class->total * 100, 1) . '%' : '-' }}
                                    </td>
                                    <td></td>
                                </tr>
                            @endforeach
                        @empty
                            <tr><td colspan="5" class="ccr-empty">ไม่มีข้อมูลในช่วงปีที่เลือก</td></tr>
                        @endforelse
                    </tbody>
                    @if ($byClass->isNotEmpty())
                        <tfoot>
                            <tr>
                                <td>รวมทั้งหมด</td>
                                <td class="num">{{ $fmtInt($kpis['allocation_rows'] ?? 0) }}</td>
                                <td class="num">{{ $fmt($grandTotal) }}</td>
                                <td class="num">100%</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('.ccr-class-row').forEach(function (row) {
                    row.addEventListener('click', function () {
                        var target = row.dataset.target;
                        if (!target) return;
                        row.classList.toggle('is-open');
                        document.querySelectorAll('.ccr-account-row[data-group="' + target + '"]').forEach(function (sub) {
                            sub.classList.toggle('d-none');
                        });
                    });
                });

                if (!window.Chart) return;

                var monthlyData = @json($chart['monthly']);
                var accountData = @json($chart['topAccounts']);

                var palette = ['#2563eb','#0ea5e9','#10b981','#f59e0b','#f43f5e','#8b5cf6','#14b8a6','#eab308','#ef4444','#6366f1'];
                var moneyFmt = function (v) {
                    return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                };

                var monthlyCanvas = document.getElementById('ccrMonthlyChart');
                if (monthlyCanvas && monthlyData.labels.length) {
                    var siteSeries = (monthlyData.sites || []);
                    var datasets;
                    if (siteSeries.length > 1) {
                        datasets = siteSeries.map(function (s, i) {
                            return {
                                label: s.site,
                                data: s.data,
                                borderColor: palette[i % palette.length],
                                backgroundColor: palette[i % palette.length] + '22',
                                tension: 0.3,
                                fill: false,
                                pointRadius: 3,
                                pointHoverRadius: 5,
                                borderWidth: 2,
                            };
                        });
                        datasets.push({
                            label: 'รวม',
                            data: monthlyData.total,
                            borderColor: '#0f172a',
                            backgroundColor: 'rgba(15,23,42,.06)',
                            tension: 0.3,
                            fill: true,
                            borderDash: [4, 4],
                            pointRadius: 2,
                            borderWidth: 1.5,
                        });
                    } else {
                        datasets = [{
                            label: 'ยอดรวม',
                            data: monthlyData.total,
                            borderColor: '#2563eb',
                            backgroundColor: 'rgba(37,99,235,.12)',
                            tension: 0.3,
                            fill: true,
                            pointRadius: 3,
                            pointHoverRadius: 5,
                            borderWidth: 2,
                        }];
                    }

                    new Chart(monthlyCanvas, {
                        type: 'line',
                        data: { labels: monthlyData.labels, datasets: datasets },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: { mode: 'index', intersect: false },
                            plugins: {
                                legend: { position: 'bottom' },
                                tooltip: { callbacks: { label: function (ctx) { return ' ' + ctx.dataset.label + ': ' + moneyFmt(ctx.raw); } } }
                            },
                            scales: {
                                y: { ticks: { callback: function (v) { return Number(v).toLocaleString(); } } }
                            }
                        }
                    });
                }

                var accountCanvas = document.getElementById('ccrAccountChart');
                if (accountCanvas && accountData.labels.length) {
                    new Chart(accountCanvas, {
                        type: 'bar',
                        data: {
                            labels: accountData.labels,
                            datasets: [{
                                label: 'ยอดรวม',
                                data: accountData.data,
                                backgroundColor: accountData.labels.map(function (_, i) { return palette[i % palette.length]; }),
                                borderRadius: 4,
                            }]
                        },
                        options: {
                            indexAxis: 'y',
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false },
                                tooltip: { callbacks: { label: function (ctx) { return ' ' + moneyFmt(ctx.raw); } } }
                            },
                            scales: {
                                x: { ticks: { callback: function (v) { return Number(v).toLocaleString(); } } }
                            }
                        }
                    });
                }
            });
        </script>
    @endpush
@endsection
