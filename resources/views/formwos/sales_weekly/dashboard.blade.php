@extends('layouts.layout')

@section('title', 'แดชบอร์ดเปรียบเทียบยอดขาย Ton')
@section('page-title', 'แดชบอร์ดเปรียบเทียบยอดขาย Ton')

@push('styles')
    <style>
        .wos-dashboard-card {
            border: 0;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .08);
        }

        .wos-kpi-label {
            color: #64748b;
            font-size: .78rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .wos-kpi-value {
            color: #0f172a;
            font-size: 1.45rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .wos-chart-box {
            height: 300px;
            position: relative;
        }

        .wos-mini-table td,
        .wos-mini-table th {
            vertical-align: middle;
        }

        .wos-unit-badge {
            background: #e0f2fe;
            border: 1px solid #7dd3fc;
            border-radius: 999px;
            color: #075985;
            display: inline-flex;
            font-weight: 700;
            padding: .3rem .7rem;
        }

        .wos-subline {
            display: flex;
            gap: .4rem;
            justify-content: space-between;
            margin-top: .25rem;
        }
    </style>
@endpush

@section('content')
    @php
        $dash = $dashboard ?? [];
        $current = $dash['current'] ?? [];
        $previous = $dash['previous'] ?? [];
        $ytdCurrent = $dash['ytd_current'] ?? [];
        $ytdPrevious = $dash['ytd_previous'] ?? [];
        $pace = $dash['working_day_pace'] ?? [];
        $mix = $dash['customer_mix'] ?? [];
        $kpis = $dash['kpis'] ?? [];
        $unit = $dash['unit'] ?? 'Ton';
        $change = $dash['change_percent'] ?? null;
        $selectedDivisions = (array) ($filters['division'] ?? [$dash['division_code'] ?? 'D1']);
        $comparisonRows = $dash['comparison_rows'] ?? [];
        $trendDatasets = $dash['division_trend_datasets'] ?? [];
        $targetAchievement = $dash['target_achievement_percent'] ?? null;
        $targetPacePercent = $dash['target_pace_percent'] ?? null;
        $progressValue = $targetAchievement === null ? 0 : min(100, max(0, (float) $targetAchievement));
        $progressClass = $targetAchievement !== null && $targetPacePercent !== null && $targetAchievement >= $targetPacePercent ? 'bg-success' : 'bg-warning';
        $decimals = $unit === 'Baht' ? 0 : 2;
        $thaiMonths = [1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน', 5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม', 9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'];
        $fmt = fn($v) => number_format((float) $v, $decimals);
        $fmtSigned = fn($v) => ((float) $v > 0 ? '+' : '') . number_format((float) $v, $decimals);
        $fmtChange = function ($v) {
            if ($v === null) {
                return 'รายการใหม่';
            }

            return ((float) $v > 0 ? '+' : '') . number_format((float) $v, 2) . '%';
        };
        $changeClass = fn($v) => (float) ($v ?? 0) < 0 ? 'text-danger' : 'text-success';
    @endphp

    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h3 class="mb-1">Dashboard เปรียบเทียบยอดขาย Ton</h3>
                <div class="text-muted small">{{ $dash['division_name'] ?? '' }}</div>
            </div>
            <span class="wos-unit-badge">หน่วย: {{ $unit }}</span>
        </div>

        <form class="card card-body mb-3" method="GET" action="{{ route('wos.sales_weekly.dashboard') }}">
            <div class="d-flex justify-content-end mb-2">
                <button class="btn btn-outline-danger btn-sm" type="submit" formaction="{{ route('wos.sales_weekly.dashboard.pdf') }}" formtarget="_blank">Export PDF</button>
            </div>
            <div class="row g-2 align-items-end">
                <div class="col-12 col-xl-4">
                    <label class="form-label mb-1">Division</label>
                    <select class="form-select" name="division[]" multiple size="8">
                        @foreach ($divisionOptions as $code => $label)
                            <option value="{{ $code }}" @selected(in_array($code, $selectedDivisions, true))>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="text-muted small mt-1">เลือกได้หลาย Division โดยกด Ctrl/Shift ค้างไว้</div>
                </div>
                <div class="col-6 col-xl-2">
                    <label class="form-label mb-1">เดือน</label>
                    <select class="form-select" name="month">
                        @for ($m = 1; $m <= 12; $m++)
                            <option value="{{ $m }}" @selected((int) ($filters['month'] ?? 0) === $m)>
                                {{ $thaiMonths[$m] }}
                            </option>
                        @endfor
                    </select>
                </div>
                <div class="col-6 col-xl-2">
                    <label class="form-label mb-1">ปี</label>
                    <input type="number" class="form-control" name="year" value="{{ $filters['year'] ?? now()->year }}">
                </div>
                <div class="col-6 col-xl-2 d-grid">
                    <button class="btn btn-primary">เปรียบเทียบ</button>
                </div>
                <div class="col-6 col-xl-2 d-grid">
                    <a class="btn btn-outline-secondary" href="{{ route('wos.sales_weekly') }}">กลับ</a>
                </div>
            </div>
        </form>

        @if (count($comparisonRows) > 1)
            <div class="card wos-dashboard-card mb-3">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <h5 class="mb-0">Division comparison</h5>
                        <span class="text-muted small">{{ count($comparisonRows) }} divisions selected</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Division</th>
                                    <th class="text-end">Current</th>
                                    <th class="text-end">Previous</th>
                                    <th class="text-end">YoY</th>
                                    <th class="text-end">YTD</th>
                                    <th class="text-end">SO</th>
                                    <th class="text-end">Customer</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($comparisonRows as $row)
                                    @php($rowDecimals = ($row['unit'] ?? $unit) === 'Baht' ? 0 : 2)
                                    <tr>
                                        <td>
                                            <strong>{{ $row['division_code'] ?? '' }}</strong>
                                            <div class="text-muted small">{{ $row['division_name'] ?? '' }} · {{ $row['unit'] ?? $unit }}</div>
                                        </td>
                                        <td class="text-end">{{ number_format((float) ($row['current_total'] ?? 0), $rowDecimals) }}</td>
                                        <td class="text-end">{{ number_format((float) ($row['previous_total'] ?? 0), $rowDecimals) }}</td>
                                        <td class="text-end {{ $changeClass($row['change_percent'] ?? 0) }}">{{ $fmtChange($row['change_percent'] ?? null) }}</td>
                                        <td class="text-end">
                                            {{ number_format((float) ($row['ytd_current_total'] ?? 0), $rowDecimals) }}
                                            <div class="small {{ $changeClass($row['ytd_change_percent'] ?? 0) }}">{{ $fmtChange($row['ytd_change_percent'] ?? null) }}</div>
                                        </td>
                                        <td class="text-end">{{ number_format((int) ($row['so_count'] ?? 0)) }}</td>
                                        <td class="text-end">{{ number_format((int) ($row['customer_count'] ?? 0)) }}</td>
                                        <td class="text-end">
                                            @if (!empty($row['detail_url']))
                                                <a href="{{ $row['detail_url'] }}" class="btn btn-sm btn-outline-primary">Detail</a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif

        <div class="row g-3 mb-3">
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card wos-dashboard-card h-100">
                    <div class="card-body">
                        <div class="wos-kpi-label">ยอดเดือนนี้</div>
                        <div class="wos-kpi-value">{{ $fmt($current['total'] ?? 0) }}</div>
                        <div class="text-muted small">{{ $kpis['selected_label'] ?? '' }} · {{ $unit }}</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card wos-dashboard-card h-100">
                    <div class="card-body">
                        <div class="wos-kpi-label">ยอดเดือนเดียวกันปีก่อน</div>
                        <div class="wos-kpi-value">{{ $fmt($previous['total'] ?? 0) }}</div>
                        <div class="text-muted small">{{ $kpis['previous_label'] ?? '' }} · {{ $unit }}</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card wos-dashboard-card h-100">
                    <div class="card-body">
                        <div class="wos-kpi-label">เปลี่ยนแปลง YoY</div>
                        <div class="wos-kpi-value {{ $changeClass($change) }}">{{ $fmtChange($change) }}</div>
                        <div class="text-muted small">เทียบ division เดิม เดือนเดียวกันของปีก่อน</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card wos-dashboard-card h-100">
                    <div class="card-body">
                        <div class="wos-kpi-label">เทียบเป้าหมาย</div>
                        <div class="wos-kpi-value">{{ $targetAchievement === null ? '-' : number_format($targetAchievement, 2) . '%' }}</div>
                        <div class="progress my-2" style="height: 8px;">
                            <div class="progress-bar {{ $progressClass }}" style="width: {{ $progressValue }}%"></div>
                        </div>
                        <div class="text-muted small">เป้าหมายคงที่: {{ ($dash['target'] ?? 0) > 0 ? $fmt($dash['target']) . ' ' . $unit : '-' }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card wos-dashboard-card h-100">
                    <div class="card-body">
                        <div class="wos-kpi-label">YTD เทียบปีก่อน</div>
                        <div class="wos-kpi-value {{ $changeClass($dash['ytd_change_percent'] ?? 0) }}">{{ $fmtChange($dash['ytd_change_percent'] ?? 0) }}</div>
                        <div class="text-muted small">{{ $fmt($ytdCurrent['total'] ?? 0) }} vs {{ $fmt($ytdPrevious['total'] ?? 0) }} {{ $unit }}</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card wos-dashboard-card h-100">
                    <div class="card-body">
                        <div class="wos-kpi-label">เฉลี่ยต่อวันทำงาน</div>
                        <div class="wos-kpi-value">{{ $fmt($pace['average_per_day'] ?? 0) }}</div>
                        <div class="text-muted small">ผ่านไป {{ number_format((int) ($pace['elapsed_working_days'] ?? 0)) }} / {{ number_format((int) ($pace['total_working_days'] ?? 0)) }} วันทำงาน</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card wos-dashboard-card h-100">
                    <div class="card-body">
                        <div class="wos-kpi-label">คาดการณ์สิ้นเดือน</div>
                        <div class="wos-kpi-value">{{ $fmt($pace['projected_total'] ?? 0) }}</div>
                        <div class="text-muted small">ควรได้ถึงตอนนี้: {{ ($dash['target_pace_value'] ?? null) === null ? '-' : $fmt($dash['target_pace_value']) . ' ' . $unit }}</div>
                        <div class="small {{ $changeClass($dash['target_pace_gap'] ?? 0) }}">สูง/ต่ำกว่า Pace: {{ ($dash['target_pace_gap'] ?? null) === null ? '-' : $fmtSigned($dash['target_pace_gap']) . ' ' . $unit }}</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card wos-dashboard-card h-100">
                    <div class="card-body">
                        <div class="wos-kpi-label">Order / ลูกค้า</div>
                        <div class="wos-subline"><span>Order</span><strong>{{ number_format((int) ($current['so_count'] ?? 0)) }} รายการ</strong></div>
                        <div class="wos-subline"><span>ลูกค้า</span><strong>{{ number_format((int) ($current['customer_count'] ?? 0)) }} ราย</strong></div>
                        <div class="wos-subline text-muted small"><span>ใหม่ / ซื้อซ้ำ / หายไป</span><strong>{{ number_format((int) ($mix['new'] ?? 0)) }} / {{ number_format((int) ($mix['retained'] ?? 0)) }} / {{ number_format((int) ($mix['lost'] ?? 0)) }}</strong></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="alert alert-info border-0 shadow-sm mb-3">
            <strong>Insight:</strong> {{ $dash['insight'] ?? 'ข้อมูลไม่เพียงพอสำหรับสรุป Insight' }}
        </div>

        <div class="row g-3">
            <div class="col-12 col-xl-7">
                <div class="card wos-dashboard-card h-100">
                    <div class="card-body">
                        <h5 class="mb-2">แนวโน้ม 12 เดือน</h5>
                        <div class="text-muted small mb-2">คลิกเดือนในกราฟเพื่อดูรายการของเดือนนั้น</div>
                        @if (!empty($dash['trend_note']))
                            <div class="text-muted small mb-2">{{ $dash['trend_note'] }}</div>
                        @endif
                        <div class="wos-chart-box">
                            <canvas id="weeklyYearTrendChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-5">
                <div class="card wos-dashboard-card h-100">
                    <div class="card-body">
                        <h5 class="mb-2">สรุปรายสัปดาห์</h5>
                        <div class="wos-chart-box">
                            <canvas id="weeklyWeekChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mt-1">
            <div class="col-12 col-xl-6">
                <div class="card wos-dashboard-card h-100">
                    <div class="card-body">
                        <h5 class="mb-2">ลูกค้าที่ทำให้ยอดเปลี่ยนมากสุด</h5>
                        <div class="table-responsive">
                            <table class="table table-sm wos-mini-table mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>ลูกค้า</th>
                                        <th class="text-end">ปีนี้</th>
                                        <th class="text-end">ปีก่อน</th>
                                        <th class="text-end">ส่วนต่าง</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($dash['customer_drivers'] ?? [] as $row)
                                        <tr>
                                            <td>
                                                {{ $row['label'] }}
                                            </td>
                                            <td class="text-end">{{ $fmt($row['current'] ?? 0) }}</td>
                                            <td class="text-end">{{ $fmt($row['previous'] ?? 0) }}</td>
                                            <td class="text-end {{ $changeClass($row['diff'] ?? 0) }}">{{ $fmtSigned($row['diff'] ?? 0) }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="text-center text-muted py-3">ไม่มีข้อมูล</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-6">
                <div class="card wos-dashboard-card h-100">
                    <div class="card-body">
                        <h5 class="mb-2">ประเภทสินค้าที่ทำให้ยอดเปลี่ยนมากสุด</h5>
                        <div class="table-responsive">
                            <table class="table table-sm wos-mini-table mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>ประเภทสินค้า</th>
                                        <th class="text-end">ปีนี้</th>
                                        <th class="text-end">ปีก่อน</th>
                                        <th class="text-end">ส่วนต่าง</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($dash['type_drivers'] ?? [] as $row)
                                        <tr>
                                            <td>
                                                {{ $row['label'] }}
                                            </td>
                                            <td class="text-end">{{ $fmt($row['current'] ?? 0) }}</td>
                                            <td class="text-end">{{ $fmt($row['previous'] ?? 0) }}</td>
                                            <td class="text-end {{ $changeClass($row['diff'] ?? 0) }}">{{ $fmtSigned($row['diff'] ?? 0) }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="text-center text-muted py-3">ไม่มีข้อมูล</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card wos-dashboard-card mt-3">
            <div class="card-body d-flex flex-wrap gap-2 justify-content-between align-items-center">
                <div class="text-muted small">
                    หมายเหตุ: D8 ใช้หน่วยบาท ส่วน division อื่นใช้หน่วย Ton เป้าหมายเป็นค่าคงที่ของแต่ละ division และการคาดการณ์นับวันทำงานจันทร์-ศุกร์โดยยังไม่หักวันหยุดบริษัท
                </div>
                @if (!empty($dash['detail_url']))
                    <a href="{{ $dash['detail_url'] }}" class="btn btn-sm btn-outline-primary">ดูรายละเอียด</a>
                @endif
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            if (!window.Chart) return;

            const trendEl = document.getElementById('weeklyYearTrendChart');
            if (trendEl) {
                const trendDatasets = @json($trendDatasets);
                new Chart(trendEl, {
                    type: 'line',
                    data: {
                        labels: @json($dash['month_labels'] ?? []),
                        datasets: trendDatasets
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        onClick: (event, elements) => {
                            if (!elements.length) return;
                            const dataset = trendDatasets[elements[0].datasetIndex] || {};
                            const url = (dataset.detailUrls || [])[elements[0].index];
                            if (url) window.location.href = url;
                        },
                        plugins: { legend: { position: 'bottom' } },
                        scales: { y: { beginAtZero: true, title: { display: true, text: @json($unit) } } }
                    }
                });
            }

            const weekEl = document.getElementById('weeklyWeekChart');
            if (weekEl) {
                new Chart(weekEl, {
                    type: 'bar',
                    data: {
                        labels: ['W1', 'W2', 'W3', 'W4', 'W5'],
                        datasets: [{
                                label: @json($kpis['selected_label'] ?? 'Selected'),
                                data: @json($current['weeks'] ?? []),
                                backgroundColor: '#2563eb'
                            },
                            {
                                label: @json($kpis['previous_label'] ?? 'Previous'),
                                data: @json($previous['weeks'] ?? []),
                                backgroundColor: '#94a3b8'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom' } },
                        scales: { y: { beginAtZero: true } }
                    }
                });
            }
        })();
    </script>
@endpush
