@extends('layouts.layout')

@section('title', 'Variable Cost - Summary')
@section('page-title', 'Variable Cost')

@section('content')
    @php
        $departmentSummary = collect($departmentSummary ?? []);
        $fmt = fn($value, $decimals = 0) => number_format((float) $value, $decimals);
        $grandTotal = max((float) $departmentSummary->sum('total_amount'), 1);
        $extra = $extraSummary ?? ['fg' => null, 'grating' => null, 'sales' => null, 'transport' => null];
        $fgQty = data_get($extra, 'fg.qty');
        $rowsByCode = $departmentSummary->keyBy(fn($row) => strtoupper(trim((string) ($row->department_code ?? ''))));
        $summaryGroups = [
            [
                'key' => 'die',
                'label' => 'DIE & TOOLING',
                'description' => 'ค่าใช้จ่ายแผนกไดร์',
                'icon' => 'fa-tools',
                'rows' => [
                    ['code' => 'PD01', 'name' => 'DIE'],
                ],
            ],
            [
                'key' => 'production',
                'label' => 'PRODUCTION',
                'description' => 'ค่าใช้จ่ายกลุ่มกระบวนการผลิต',
                'icon' => 'fa-industry',
                'rows' => [
                    ['code' => 'PD02', 'name' => 'ANNEALING'],
                    ['code' => 'PD03', 'name' => 'BAR 1'],
                    ['code' => 'PD04', 'name' => 'BAR 2'],
                    ['code' => 'PD05', 'name' => 'COATING'],
                    ['code' => 'PD06', 'name' => 'TREATMENT'],
                    ['code' => 'PD07', 'name' => 'CO2'],
                    ['code' => 'PD08', 'name' => 'CG'],
                    ['code' => 'PD09', 'name' => 'CLEANING'],
                    ['code' => 'PD10', 'name' => 'DRAWING'],
                    ['code' => 'PD11', 'name' => 'PROFILE'],
                    ['code' => 'PD13', 'name' => 'SHOTBLAST'],
                ],
            ],
            [
                'key' => 'logistic',
                'label' => 'LOGISTIC & DELIVERY',
                'description' => 'ค่าใช้จ่ายคลังสินค้า บรรจุ และขนส่ง',
                'icon' => 'fa-truck',
                'rows' => [
                    ['code' => 'WH01', 'name' => 'LOGISTIC'],
                    ['code' => '', 'name' => 'DELIVERY', 'is_delivery' => true, 'exclude_from_total' => true],
                    ['code' => 'WH02', 'name' => 'PACKING'],
                    ['code' => 'WH03', 'name' => 'RAWMAT'],
                    ['code' => 'TS01', 'name' => 'TRANSPORTATION'],
                ],
            ],
        ];
        $groupRows = collect($summaryGroups)->map(function ($group) use ($rowsByCode) {
            $group['rows'] = collect($group['rows'])->map(function ($item) use ($rowsByCode) {
                $item['row'] = $item['row'] ?? $rowsByCode->get($item['code']);
                return $item;
            });
            return $group;
        });
        $mainRows = $groupRows->flatMap(fn($group) => $group['rows'])->pluck('row')->filter();
        $weldingRow = $rowsByCode->get('PD14');
        $totalSalesCostPerKg = (float) $mainRows->sum(fn($row) => (float) ($row->sales_cost_per_kg ?? 0));
        $totalProductionCostPerKg = (float) $mainRows->sum(fn($row) => (float) ($row->production_cost_per_kg ?? 0));
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

        <div class="vc-card vc-summary-card">
            <div class="vc-card-header d-flex justify-content-between align-items-center">
                <span>ตารางสรุปค่าใช้จ่ายรายแผนก</span>
                <small class="text-muted fw-normal">หน่วย: บาท และ บาท/กก.</small>
            </div>
            <div class="vc-table-wrap vc-excel-wrap">
                <table class="table table-bordered table-sm vc-table vc-excel-summary mb-0">
                    <colgroup>
                        <col class="vc-code-col">
                        <col class="vc-dept-col">
                        <col class="vc-money-col">
                        <col class="vc-rate-col">
                        <col class="vc-rate-col">
                    </colgroup>
                    <thead>
                        <tr>
                            <th rowspan="2">รหัส</th>
                            <th rowspan="2">แผนก</th>
                            <th rowspan="2" class="num">จำนวนเงิน</th>
                            <th colspan="2" class="text-center vc-rate-group">อัตราค่าใช้จ่าย (บาท/กก.)</th>
                        </tr>
                        <tr>
                            <th class="num">ยอดขาย</th>
                            <th class="num">ยอดผลิต</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($groupRows as $group)
                            <tr class="vc-section-row vc-section-{{ $group['key'] }}">
                                <td colspan="5">
                                    <span class="vc-section-icon"><i class="fas {{ $group['icon'] }}"></i></span>
                                    <span class="vc-section-title">{{ $group['label'] }}</span>
                                    <span class="vc-section-description">{{ $group['description'] }}</span>
                                </td>
                            </tr>
                            @foreach ($group['rows'] as $item)
                                @php $row = $item['row'] ?? null; @endphp
                                <tr>
                                    <td>{{ $item['code'] }}</td>
                                    <td>{{ $item['name'] }}</td>
                                    @if (!empty($item['is_delivery']))
                                        <td class="num">{{ data_get($extra, 'transport.amount') !== null ? $fmt(data_get($extra, 'transport.amount'), 2) : '-' }}</td>
                                        <td class="num fw-bold">{{ data_get($extra, 'transport.cost_per_kg') !== null ? $fmt(data_get($extra, 'transport.cost_per_kg'), 2) : '-' }}</td>
                                        <td class="num">-</td>
                                    @else
                                        <td class="num">{{ $row ? $fmt($row->total_amount, 2) : '-' }}</td>
                                        <td class="num">{{ $row && $row->sales_cost_per_kg !== null ? $fmt($row->sales_cost_per_kg, 2) : '-' }}</td>
                                        <td class="num">{{ $row && $row->production_cost_per_kg !== null ? $fmt($row->production_cost_per_kg, 2) : '-' }}</td>
                                    @endif
                                </tr>
                            @endforeach
                            @php
                                $subtotalRows = $group['rows']
                                    ->reject(fn($item) => !empty($item['exclude_from_total']))
                                    ->pluck('row')
                                    ->filter();
                            @endphp
                            <tr class="vc-group-total">
                                <td></td>
                                <td class="text-center">รวม</td>
                                <td class="num">{{ $fmt($subtotalRows->sum('total_amount'), 2) }}</td>
                                <td class="num">{{ $fmt($subtotalRows->sum(fn($row) => (float) ($row->sales_cost_per_kg ?? 0)), 2) }}</td>
                                <td class="num">{{ $fmt($subtotalRows->sum(fn($row) => (float) ($row->production_cost_per_kg ?? 0)), 2) }}</td>
                            </tr>
                        @endforeach
                        <tr class="vc-grand-total">
                            <td></td>
                            <td class="text-center">รวม</td>
                            <td class="num">{{ $fmt($mainRows->sum('total_amount'), 2) }}</td>
                            <td class="num">{{ $fmt($totalSalesCostPerKg, 2) }}</td>
                            <td class="num">{{ $fmt($totalProductionCostPerKg, 2) }}</td>
                        </tr>
                        <tr class="vc-section-row vc-section-grating">
                            <td colspan="5">
                                <span class="vc-section-icon"><i class="fas fa-th"></i></span>
                                <span class="vc-section-title">PRODUCTION</span>
                                <span class="vc-section-description">ค่าใช้จ่ายการผลิต Grating</span>
                            </td>
                        </tr>
                        <tr class="vc-welding-row">
                            <td>PD14</td>
                            <td>WELDING</td>
                            <td class="num">{{ $weldingRow ? $fmt($weldingRow->total_amount, 2) : '-' }}</td>
                            <td class="num">{{ $weldingRow && $weldingRow->sales_cost_per_kg !== null ? $fmt($weldingRow->sales_cost_per_kg, 2) : '-' }}</td>
                            <td class="num">
                                {{ $weldingRow && $weldingRow->production_cost_per_kg !== null ? $fmt($weldingRow->production_cost_per_kg, 2) : '-' }}
                                <small class="vc-basis-tag">Grating</small>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="vc-basis-panel">
                <div class="vc-basis-heading">
                    <div>
                        <div class="fw-bold">ฐานคำนวณประจำงวด</div>
                        <div class="text-muted small">ตัวหารที่ใช้คำนวณอัตราค่าใช้จ่ายของแต่ละกลุ่ม</div>
                    </div>
                    <span class="vc-basis-unit">หน่วย กก.</span>
                </div>
                <div class="vc-basis-grid">
                    <div class="vc-basis-card vc-basis-fg">
                        <span class="vc-basis-icon"><i class="fas fa-boxes"></i></span>
                        <div>
                            <div class="vc-basis-label">ยอดผลิต FG</div>
                            <div class="vc-basis-value">{{ $fgQty !== null ? $fmt($fgQty, 2) : '-' }}</div>
                        </div>
                    </div>
                    <div class="vc-basis-card vc-basis-grating">
                        <span class="vc-basis-icon"><i class="fas fa-th"></i></span>
                        <div>
                            <div class="vc-basis-label">ยอดผลิต Grating</div>
                            <div class="vc-basis-value">{{ data_get($extra, 'grating.qty') !== null ? $fmt(data_get($extra, 'grating.qty'), 2) : '-' }}</div>
                        </div>
                    </div>
                    <div class="vc-basis-card vc-basis-sales">
                        <span class="vc-basis-icon"><i class="fas fa-chart-line"></i></span>
                        <div>
                            <div class="vc-basis-label">ยอดขาย</div>
                            <div class="vc-basis-value">{{ data_get($extra, 'sales.qty') !== null ? $fmt(data_get($extra, 'sales.qty'), 2) : '-' }}</div>
                        </div>
                    </div>
                    <div class="vc-basis-card vc-basis-truck">
                        <span class="vc-basis-icon"><i class="fas fa-truck-loading"></i></span>
                        <div>
                            <div class="vc-basis-label">น้ำหนักรถบรรทุก</div>
                            <div class="vc-basis-value">{{ $fmt(data_get($extra, 'truck.qty', 0), 2) }}</div>
                        </div>
                    </div>
                </div>
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
