@extends('layouts.layout')

@section('title', 'แดชบอร์ดเปรียบเทียบยอดขาย Qty')
@section('page-title', 'แดชบอร์ดเปรียบเทียบยอดขาย Qty')

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
            background: #ecfdf5;
            border: 1px solid #86efac;
            border-radius: 999px;
            color: #166534;
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
        $unit = $dash['unit'] ?? 'Qty';
        $change = $dash['change_percent'] ?? null;
        $selectedDivisions = (array) ($filters['division'] ?? [$dash['division_code'] ?? 'D1']);
        $selectedProduct = $filters['product'] ?? '';
        $comparisonRows = $dash['comparison_rows'] ?? [];
        $trendDatasets = $dash['division_trend_datasets'] ?? [];
        $ytdTypeCompare = $dash['ytd_type_compare'] ?? [];
        $decimals = $unit === 'Qty' || $unit === 'Baht' ? 0 : 2;
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
        $compareStartMonth = 1;
        $compareEndMonth = (int) ($filters['month'] ?? now()->month);
    @endphp

    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h3 class="mb-1">Dashboard เปรียบเทียบยอดขาย Qty</h3>
                <div class="text-muted small">
                    {{ $dash['division_name'] ?? '' }}
                    @if ($selectedProduct !== '')
                        | Product: {{ $selectedProduct }}
                    @endif
                </div>
            </div>
            <span class="wos-unit-badge">หน่วย: {{ $unit }}</span>
        </div>

        <form class="card card-body mb-3" method="GET" action="{{ route('wos.sales_unit_summary.dashboard') }}">
            <div class="d-flex justify-content-end mb-2 no-print">
                <button class="btn btn-outline-danger btn-sm" type="submit" formaction="{{ route('wos.sales_unit_summary.dashboard.pdf') }}" formtarget="_blank">Export PDF</button>
            </div>
            <div class="row g-2 align-items-end">
                <div class="col-12 col-xl-3">
                    <label class="form-label mb-1">Division</label>
                    <select class="form-select" name="division[]" multiple size="8">
                        @foreach ($divisionOptions as $code => $label)
                            <option value="{{ $code }}" @selected(in_array($code, $selectedDivisions, true))>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="text-muted small mt-1">เลือกได้หลาย Division โดยกด Ctrl/Shift ค้างไว้</div>
                </div>
                <div class="col-12 col-xl-3">
                    <label class="form-label mb-1">Product</label>
                    <select class="form-select" name="product">
                        <option value="">All Products</option>
                        @foreach ($productOptions as $product)
                            <option value="{{ $product }}" @selected($selectedProduct === $product)>{{ $product }}</option>
                        @endforeach
                    </select>
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
                    <a class="btn btn-outline-secondary" href="{{ route('wos.sales_unit_summary', [
                        'year' => $filters['year'] ?? now()->year,
                        'month' => $filters['month'] ?? now()->month,
                        'product' => $selectedProduct,
                        'division' => count($selectedDivisions) === 1 ? $selectedDivisions[0] : '',
                    ]) }}">กลับ</a>
                </div>
            </div>
        </form>

        <div class="card wos-dashboard-card mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <h5 class="mb-0">Product Champion</h5>
                    <span class="text-muted small">{{ $filters['year'] ?? now()->year }} vs {{ ($filters['year'] ?? now()->year) - 1 }} ({{ $thaiMonths[$compareStartMonth] ?? $compareStartMonth }} - {{ $thaiMonths[$compareEndMonth] ?? $compareEndMonth }})</span>
                </div>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Product</th>
                                <th class="text-end">Year {{ $filters['year'] ?? now()->year }}</th>
                                <th class="text-end">Year {{ ($filters['year'] ?? now()->year) - 1 }}</th>
                                <th class="text-end">Compare</th>
                                <th class="text-end">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($ytdTypeCompare as $row)
                                @php
                                    $diff = (float) ($row['current'] ?? 0) - (float) ($row['previous'] ?? 0);
                                @endphp
                                <tr>
                                    <td>{{ $row['type'] }}</td>
                                    <td class="text-end">{{ $fmt($row['current'] ?? 0) }}</td>
                                    <td class="text-end">{{ $fmt($row['previous'] ?? 0) }}</td>
                                    <td class="text-end {{ $changeClass($diff) }}">{{ $fmtSigned($diff) }}</td>
                                    <td class="text-end {{ $changeClass($row['change_percent'] ?? 0) }}">{{ $fmtChange($row['change_percent'] ?? null) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-3">ไม่มีข้อมูล</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="wos-chart-box">
                    <canvas id="productChampionChart"></canvas>
                </div>
            </div>
        </div>

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
                                    <th class="text-end">Order</th>
                                    <th class="text-end">Customer</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($comparisonRows as $row)
                                    @php
                                        $rowDecimals = in_array(($row['unit'] ?? $unit), ['Qty', 'Baht'], true) ? 0 : 2;
                                    @endphp
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
                                        <td class="text-end">{{ number_format((int) ($row['order_count'] ?? 0)) }}</td>
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
                        <div class="wos-kpi-value">-</div>
                        <div class="progress my-2" style="height: 8px;">
                            <div class="progress-bar bg-secondary" style="width: 0%"></div>
                        </div>
                        <div class="text-muted small">ยังไม่มีเป้าหมาย Qty คงที่ในระบบ</div>
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
                        <div class="text-muted small">คำนวณจากยอดจริงต่อวันทำงาน</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card wos-dashboard-card h-100">
                    <div class="card-body">
                        <div class="wos-kpi-label">Order / ลูกค้า</div>
                        <div class="wos-subline"><span>Order</span><strong>{{ number_format((int) ($current['order_count'] ?? 0)) }} รายการ</strong></div>
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
                            <canvas id="deliveryYearTrendChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-5">
                <div class="card wos-dashboard-card h-100">
                    <div class="card-body">
                        <h5 class="mb-2">สรุปตามประเภทสินค้า</h5>
                        <div class="table-responsive" style="max-height:300px;">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>ประเภทสินค้า</th>
                                        <th class="text-end">{{ $filters['year'] ?? '' }}</th>
                                        <th class="text-end">{{ ($filters['year'] ?? 0) - 1 }}</th>
                                        <th class="text-end">YoY</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($dash['type_compare'] ?? [] as $row)
                                        @php
                                            $typeDiff = (float) ($row['current'] ?? 0) - (float) ($row['previous'] ?? 0);
                                        @endphp
                                        <tr>
                                            <td>
                                                {{ $row['type'] }}
                                            </td>
                                            <td class="text-end">{{ $fmt($row['current'] ?? 0) }}</td>
                                            <td class="text-end">{{ $fmt($row['previous'] ?? 0) }}</td>
                                            <td class="text-end {{ $changeClass($typeDiff) }}">{{ $fmtChange($row['change_percent'] ?? null) }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="text-center text-muted py-4">ไม่มีข้อมูล</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
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
                    หมายเหตุ: D8 ใช้หน่วยบาท ส่วน division อื่นใช้หน่วย Qty หลังแปลงหน่วยอ้างอิง และการคาดการณ์นับวันทำงานจันทร์-ศุกร์โดยยังไม่หักวันหยุดบริษัท
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

            const championEl = document.getElementById('productChampionChart');
            if (championEl) {
                const championRows = @json(array_values($ytdTypeCompare));
                const labels = championRows.map((row) => row.type);
                const currentValues = championRows.map((row) => Number(row.current || 0));
                const previousValues = championRows.map((row) => Number(row.previous || 0));
                const changeValues = championRows.map((row) => row.change_percent === null ? null : Number(row.change_percent || 0));

                new Chart(championEl, {
                    data: {
                        labels,
                        datasets: [
                            {
                                type: 'bar',
                                label: 'Year {{ $filters['year'] ?? now()->year }}',
                                data: currentValues,
                                backgroundColor: '#2f8ca3',
                            },
                            {
                                type: 'bar',
                                label: 'Year {{ ($filters['year'] ?? now()->year) - 1 }}',
                                data: previousValues,
                                backgroundColor: '#f4b400',
                            },
                            {
                                type: 'line',
                                label: 'YoY %',
                                data: changeValues,
                                borderColor: '#7cb342',
                                backgroundColor: '#7cb342',
                                yAxisID: 'percent',
                                tension: .25,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: false,
                        plugins: { legend: { position: 'bottom' } },
                        scales: {
                            y: { beginAtZero: true, title: { display: true, text: @json($unit) } },
                            percent: {
                                beginAtZero: true,
                                position: 'right',
                                grid: { drawOnChartArea: false },
                                ticks: { callback: (value) => `${value}%` },
                            },
                        },
                    },
                });
            }

            const trendEl = document.getElementById('deliveryYearTrendChart');
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
                        animation: false,
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
        })();
    </script>
@endpush
