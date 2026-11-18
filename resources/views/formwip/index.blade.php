@extends('layouts.layout')

@section('title', 'WIP Monitoring')
@section('page-title', 'WIP & Stock Pending Monitoring')

@push('styles')
    @include('formwip.partials._styles')
@endpush

@section('content')
    @php
        $colors = [
            'WAITING_PRODUCTION' => '#6c757d',
            'IN_PROCESS' => '#0d6efd',
            'WAITING_PACKAGING' => '#0dcaf0',
            'WAITING_QC' => '#adb5bd',
            'WAITING_FG' => '#f0ad00',
            'HOLD' => '#dc3545',
            'COMPLETED' => '#198754',
        ];
        $bandColors = ['NORMAL' => '#198754', 'MONITOR' => '#0dcaf0', 'WARNING' => '#f0ad00', 'CRITICAL' => '#dc3545'];
        $ton = fn($kg) => number_format(($kg ?? 0) / 1000, 1);
        // drill-down ทุกจุดบน Dashboard → หน้ารายการ WO พร้อม filter ปัจจุบัน
        $toList = fn(array $over) => route('wip.list', array_merge(request()->except(['page', 'refresh']), $over));

        $flowKeys = ['WAITING_PRODUCTION', 'IN_PROCESS', 'WAITING_PACKAGING', 'WAITING_FG'];
        $sideKeys = ['HOLD', 'WAITING_QC', 'COMPLETED'];
        $wipKeys = ['WAITING_PRODUCTION', 'IN_PROCESS', 'WAITING_PACKAGING', 'WAITING_FG', 'HOLD'];
        $totalKg = max(1, $totalWipKg);
        $woWithWip = collect($agingSummary)->sum('wo_count');
        $criticalWo = $agingSummary['CRITICAL']['wo_count'] ?? 0;
        $topMachines = $machineSummary['top'];
        $maxMc = max(1, $topMachines->max('kg') ?? 1);
        $siteLabels = ['WIRE' => 'Wire', 'PLUS' => 'Plus', 'ALL' => 'ทั้งหมด'];
    @endphp

    <div class="wip">
        @include('formwip.partials._tabs')
        @include('formwip.partials._filter', ['mode' => 'dashboard'])

        @foreach ($dataErrors as $err)
            <div class="alert alert-danger py-2"><i class="fa-solid fa-circle-exclamation"></i> {{ $err }}</div>
        @endforeach

        {{-- ============ Hero + Pipeline ============ --}}
        <div class="row g-3 mb-3">
            <div class="col-xl-4">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <div class="muted-label">WIP คงค้างรวม · {{ $siteLabels[$filters['site']] }}</div>
                        <div class="d-flex align-items-baseline gap-2 mt-1">
                            <span class="wip-hero-num">{{ $ton($totalWipKg) }}</span>
                            <span class="fs-5 text-secondary">ตัน</span>
                        </div>
                        <div class="small text-secondary mb-3">
                            {{ number_format($woWithWip) }} WO มีงานค้าง
                            @if ($criticalWo > 0)
                                · <a href="{{ $toList(['aging' => 'CRITICAL', 'status' => null]) }}" class="text-danger fw-semibold text-decoration-none">ค้างเกิน 30 วัน {{ $criticalWo }} WO</a>
                            @endif
                        </div>

                        <div class="wip-stack mb-2" role="img" aria-label="สัดส่วน WIP ตามสถานะ">
                            @foreach ($wipKeys as $k)
                                @php $pct = ($statusCards[$k]['kg'] ?? 0) / $totalKg * 100; @endphp
                                @if ($pct > 0)
                                    <div style="width: {{ $pct }}%; background: {{ $colors[$k] }}" title="{{ $statuses[$k]['label'] }} {{ number_format($pct, 1) }}%"></div>
                                @endif
                            @endforeach
                        </div>
                        <div class="wip-legend">
                            @foreach ($wipKeys as $k)
                                @php $c = $statusCards[$k]; @endphp
                                <span class="wip-dot" style="background: {{ $colors[$k] }}"></span>
                                <span>{{ $c['th'] }}</span>
                                <span class="text-end"><strong>{{ $ton($c['kg']) }}</strong> <span class="text-secondary">ตัน · {{ number_format(($c['kg'] ?? 0) / $totalKg * 100, 0) }}%</span></span>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-8 d-flex flex-column gap-3">
                <div class="wip-flow">
                    @foreach ($flowKeys as $k)
                        @php $c = $statusCards[$k]; $active = $filters['status'] === $k; @endphp
                        <a href="{{ $toList(['status' => $k, 'aging' => null]) }}" class="wip-stage {{ $active ? 'active' : '' }}" style="--stage-color: {{ $colors[$k] }}">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="title">{{ $c['th'] }}</span>
                                <i class="fa-solid {{ $c['icon'] }}" style="color: {{ $colors[$k] }}"></i>
                            </div>
                            <div class="sub mb-1">{{ $c['label'] }}</div>
                            <div class="ton">{{ $ton($c['kg']) }} <small>ตัน</small></div>
                            <div class="sub mt-1">
                                {{ number_format($c['wo_count']) }} WO
                                @if ($c['critical'] > 0)
                                    · <span class="text-danger fw-semibold">{{ $c['critical'] }} Critical</span>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>
                <div class="wip-side">
                    @foreach ($sideKeys as $k)
                        @php $c = $statusCards[$k]; $active = $filters['status'] === $k; $isQc = $k === 'WAITING_QC'; @endphp
                        <a href="{{ $isQc ? '#' : $toList(['status' => $k, 'aging' => null]) }}"
                            class="wip-stage {{ $active ? 'active' : '' }} {{ $isQc ? 'disabled' : '' }}" style="--stage-color: {{ $colors[$k] }}">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="title"><i class="fa-solid {{ $c['icon'] }} me-1" style="color: {{ $colors[$k] }}"></i>{{ $c['th'] }}</div>
                                    <div class="sub">{{ $isQc ? 'ยังไม่มีข้อมูลใน WMSOne' : number_format($c['wo_count']) . ' WO' }}</div>
                                </div>
                                <div class="ton text-end">{{ $isQc ? 'N/A' : $ton($c['kg']) }} @unless ($isQc)<small>ตัน</small>@endunless</div>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- ============ Trend + Aging ============ --}}
        <div class="row g-3 mb-3">
            <div class="col-xl-8">
                <div class="card shadow-sm h-100">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span><i class="fa-solid fa-chart-area me-1 text-secondary"></i> แนวโน้ม WIP รายวัน <small class="text-secondary fw-normal">(ตัน)</small></span>
                        <span class="small fw-normal text-secondary">ย้อนหลัง 90 วัน · ตาม Site / Route / ช่วงวันเปิด WO</span>
                    </div>
                    <div class="card-body">
                        @if ($trend['days'] === 0)
                            <div class="wip-empty">
                                <i class="fa-solid fa-chart-area"></i>
                                <div>ยังไม่มีข้อมูลรายวัน</div>
                                <small>ระบบจะเริ่มบันทึกเมื่อ sync อัตโนมัติทำงาน</small>
                            </div>
                        @else
                            <div style="position: relative; height: 260px">
                                <canvas id="wipTrendChart" aria-label="แนวโน้ม WIP รายวัน" role="img"></canvas>
                            </div>
                            @if ($trend['days'] < 7)
                                <div class="small text-secondary mt-2">
                                    <i class="fa-solid fa-circle-info"></i>
                                    เริ่มเก็บข้อมูลเมื่อ {{ \Carbon\Carbon::parse($trend['first'])->format('d/m/Y') }} ({{ $trend['days'] }} วัน) — กราฟจะสมบูรณ์ขึ้นทุกวัน
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fa-regular fa-hourglass-half me-1 text-secondary"></i> Aging</span>
                        <span class="small fw-normal text-secondary">วันที่อยู่ในสถานะปัจจุบัน</span>
                    </div>
                    <div class="card-body">
                        <div class="wip-aging">
                            @foreach ($agingSummary as $k => $a)
                                @php $active = $filters['aging'] === $k; @endphp
                                <a href="{{ $toList(['aging' => $k, 'status' => null]) }}" class="{{ $active ? 'active' : '' }}" style="--band-color: {{ $bandColors[$k] }}">
                                    <div class="small fw-semibold" style="color: {{ $bandColors[$k] }}">{{ $a['label'] }}</div>
                                    <div class="n">{{ $a['wo_count'] }} <small class="fs-6 fw-normal text-secondary">WO</small></div>
                                    <div class="small text-secondary">{{ $ton($a['kg']) }} ตัน</div>
                                </a>
                            @endforeach
                        </div>
                        <div class="small text-secondary mt-2">0–7 วัน Normal · 8–15 Monitor · 16–30 Warning · มากกว่า 30 Critical</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============ Heatmap Work Center × Aging + Machine ============ --}}
        @php
            $bandKeys = array_keys($agingBands);
            $maxCell = max(1, $wcSummary->flatMap(fn($w) => array_values($w['bands']))->max() ?? 1,
                collect($postProduction)->flatMap(fn($p) => array_values($p['bands']))->max() ?? 1);
            // ความเข้มของสี: sqrt ให้ช่องค่าน้อยยังมองเห็น
            $cellStyle = function ($kg, $band) use ($maxCell, $bandColors) {
                if ($kg <= 0) return '';
                $alpha = 0.12 + 0.78 * sqrt($kg / $maxCell);
                $hex = str_pad(dechex((int) round($alpha * 255)), 2, '0', STR_PAD_LEFT);
                $text = $alpha > 0.55 ? '#fff' : 'inherit';
                return "background: {$bandColors[$band]}{$hex}; color: {$text};";
            };
        @endphp
        <div class="row g-3 mb-3">
            <div class="col-xl-8">
                <div class="card shadow-sm h-100">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span><i class="fa-solid fa-table-cells me-1 text-secondary"></i> งานค้าง: Work Center × Aging <small class="text-secondary fw-normal">(ตัน)</small></span>
                        <span class="small fw-normal text-secondary">สีเข้ม = ค้างมาก · คลิกช่องเพื่อดูรายการ</span>
                    </div>
                    <div class="card-body p-0 wip-scroll" style="max-height: 520px">
                        <table class="table table-sm mb-0 wip-table wip-heat">
                            <thead>
                                <tr>
                                    <th class="ps-3">Work Center</th>
                                    <th class="d-none d-lg-table-cell">แผนก</th>
                                    <th style="width: 18%" class="d-none d-md-table-cell">สถานะงาน</th>
                                    @foreach ($bandKeys as $b)
                                        <th class="text-center" style="color: {{ $bandColors[$b] }}">{{ $agingBands[$b]['label'] }}</th>
                                    @endforeach
                                    <th class="text-end">รวม</th>
                                    <th class="text-end pe-3">WO</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($wcSummary as $w)
                                    <tr class="{{ $filters['wc'] === $w['wc_code'] ? 'table-active' : '' }}">
                                        <td class="ps-3">
                                            <a href="{{ $toList(['wc' => $w['wc_code'], 'aging' => null, 'status' => null]) }}" class="fw-semibold text-decoration-none">{{ $w['wc_code'] }}</a>
                                            <div class="small text-secondary text-truncate" style="max-width: 170px">{{ $w['wc_name'] }}</div>
                                        </td>
                                        <td class="small text-secondary d-none d-lg-table-cell">{{ $w['dept'] }}</td>
                                        <td class="d-none d-md-table-cell">
                                            <div class="wip-bar" title="รอเริ่ม {{ $ton($w['waiting_prod']) }} · กำลังผลิต {{ $ton($w['in_process']) }} · รอแพ็ค {{ $ton($w['waiting_pack']) }} ตัน">
                                                <div style="width: {{ $w['waiting_prod'] / max(1, $w['total']) * 100 }}%; background: {{ $colors['WAITING_PRODUCTION'] }}"></div>
                                                <div style="width: {{ $w['in_process'] / max(1, $w['total']) * 100 }}%; background: {{ $colors['IN_PROCESS'] }}"></div>
                                                <div style="width: {{ $w['waiting_pack'] / max(1, $w['total']) * 100 }}%; background: {{ $colors['WAITING_PACKAGING'] }}"></div>
                                            </div>
                                        </td>
                                        @foreach ($bandKeys as $b)
                                            @php $v = $w['bands'][$b]; @endphp
                                            <td class="text-center p-1">
                                                @if ($v > 0)
                                                    <a href="{{ $toList(['wc' => $w['wc_code'], 'aging' => $b, 'status' => null]) }}" class="wip-cell" style="{{ $cellStyle($v, $b) }}" title="{{ number_format($v, 0) }} kg">{{ $v < 50 ? "<0.1" : $ton($v) }}</a>
                                                @else
                                                    <span class="wip-cell empty">–</span>
                                                @endif
                                            </td>
                                        @endforeach
                                        <td class="text-end fw-semibold">{{ $ton($w['total']) }}</td>
                                        <td class="text-end pe-3 text-secondary">{{ $w['wo_count'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="{{ 5 + count($bandKeys) }}" class="text-center text-secondary py-4">ไม่มีงานค้างที่ Work Center</td></tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                @foreach (['WAITING_FG' => 'รอรับเข้า FG', 'HOLD' => 'Hold / Rework'] as $st => $label)
                                    @php $p = $postProduction[$st]; @endphp
                                    @continue($p['total'] <= 0)
                                    <tr>
                                        <td class="ps-3" colspan="1">
                                            <a href="{{ $toList(['status' => $st, 'aging' => null, 'wc' => null]) }}" class="fw-semibold text-decoration-none" style="color: {{ $colors[$st] }}">
                                                <span class="wip-dot" style="background: {{ $colors[$st] }}"></span> {{ $label }}
                                            </a>
                                            <div class="small text-secondary">หลังผลิต / ไม่ผูกกับ WC</div>
                                        </td>
                                        <td class="d-none d-lg-table-cell"></td>
                                        <td class="d-none d-md-table-cell"></td>
                                        @foreach ($bandKeys as $b)
                                            @php $v = $p['bands'][$b]; @endphp
                                            <td class="text-center p-1">
                                                @if ($v > 0)
                                                    <a href="{{ $toList(['status' => $st, 'aging' => $b, 'wc' => null]) }}" class="wip-cell" style="{{ $cellStyle($v, $b) }}" title="{{ number_format($v, 0) }} kg">{{ $v < 50 ? "<0.1" : $ton($v) }}</a>
                                                @else
                                                    <span class="wip-cell empty">–</span>
                                                @endif
                                            </td>
                                        @endforeach
                                        <td class="text-end fw-semibold">{{ $ton($p['total']) }}</td>
                                        <td class="text-end pe-3 text-secondary">{{ $p['wo_count'] }}</td>
                                    </tr>
                                @endforeach
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fa-solid fa-gears me-1 text-secondary"></i> เครื่องจักรที่มีงานค้างมากสุด</span>
                        <span class="small fw-normal text-secondary">Top 15</span>
                    </div>
                    <div class="card-body p-0 wip-scroll" style="max-height: 470px">
                        <table class="table table-sm mb-0 wip-table">
                            <tbody>
                                @forelse ($topMachines as $m)
                                    <tr>
                                        <td class="ps-3">
                                            <a href="{{ $toList(['machine' => $m['machine']]) }}" class="fw-semibold text-decoration-none">{{ $m['machine'] }}</a>
                                            <span class="text-secondary small">{{ $m['wc_code'] }}</span>
                                        </td>
                                        <td style="width: 35%"><div class="wip-bar"><div style="width: {{ $m['kg'] / $maxMc * 100 }}%; background: {{ $colors['IN_PROCESS'] }}"></div></div></td>
                                        <td class="text-end fw-semibold">{{ $ton($m['kg']) }}</td>
                                        <td class="text-end pe-3 text-secondary small">{{ $m['wo_count'] }} WO</td>
                                    </tr>
                                @empty
                                    <tr><td class="text-center text-secondary py-4">ไม่มีข้อมูล</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if ($machineSummary['unassigned_kg'] > 0)
                        <div class="card-footer bg-transparent small text-secondary">
                            <i class="fa-solid fa-circle-info"></i>
                            งานที่รอเข้าขั้นถัดไปแต่ยังไม่ได้ลงเครื่อง {{ $ton($machineSummary['unassigned_kg']) }} ตัน ({{ $machineSummary['unassigned_wo'] }} รายการ) ไม่รวมในตารางนี้
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- ============ Top 10 ค้างนานสุด ============ --}}
        <div class="card shadow-sm">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><i class="fa-solid fa-triangle-exclamation me-1 text-danger"></i> 10 รายการที่ค้างนานสุด <small class="text-secondary fw-normal">(มี WIP ค้าง)</small></span>
                <a href="{{ $toList(['sort' => null]) }}" class="btn btn-sm btn-outline-primary py-0">
                    ดูรายการทั้งหมด {{ number_format($woWithWip) }} WO <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
            <div class="card-body p-0 table-responsive">
                @include('formwip.partials._wo_table', ['woRows' => $topCritical, 'compact' => true])
            </div>
        </div>


        <details class="small text-secondary mt-3">
            <summary>วิธีคำนวณ (Prototype)</summary>
            <ul class="mt-2 mb-0">
                <li>น้ำหนักคิดจากการไหลของงานแต่ละขั้น ไม่บวกยอดซ้ำข้ามขั้น — ค้างที่ขั้น i = output ของดีขั้นก่อนหน้า − output ขั้นนี้ − NCR</li>
                <li>รอรับเข้า FG = output ขั้นสุดท้ายใน WMSOne − ยอดรับเข้าใน ERP</li>
                <li>Hold / Rework = ยอด NCR (receivetype 3) ของ WO ที่ยังผลิตไม่ครบ + WIP ของ WO ที่ Suspended</li>
                <li>Waiting QC ยังไม่มีข้อมูลให้คำนวณ</li>
                <li>Aging = จำนวนวันที่อยู่ในสถานะปัจจุบัน นับจากวันที่ระบบ sync พบว่าเปลี่ยนสถานะ · ตัวเลขที่มี * = ยังไม่มีประวัติ จึงประมาณจาก transaction ล่าสุดใน WMSOne</li>
                <li>ขั้นที่ "ครบ" ใช้เกณฑ์ % น้ำหนักเดียวกับ Production Status Tracking</li>
            </ul>
        </details>
    </div>
@endsection

@push('scripts')
    <script>
        // ---------- กราฟแนวโน้ม WIP รายวัน (Chart.js โหลดใน layout แล้ว) ----------
        (function () {
            const el = document.getElementById('wipTrendChart');
            if (!el || !window.Chart) return;
            const trend = @json($trend);
            const meta = {
                WAITING_PRODUCTION: ['รอเข้าผลิต', '#6c757d'],
                IN_PROCESS: ['กำลังผลิต', '#0d6efd'],
                WAITING_PACKAGING: ['รอแพ็ค', '#0dcaf0'],
                WAITING_FG: ['รอรับเข้า FG', '#f0ad00'],
                HOLD: ['Hold / Rework', '#dc3545'],
            };
            const single = trend.labels.length === 1;
            new Chart(el, {
                type: single ? 'bar' : 'line',
                data: {
                    labels: trend.labels,
                    datasets: Object.keys(meta).map(function (k) {
                        return {
                            label: meta[k][0],
                            data: trend.series[k],
                            borderColor: meta[k][1],
                            backgroundColor: meta[k][1] + (single ? 'cc' : '55'),
                            fill: true,
                            tension: .25,
                            pointRadius: trend.labels.length > 30 ? 0 : 2,
                            borderWidth: 1.5,
                            maxBarThickness: 80,
                        };
                    }),
                },
                options: {
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        x: { stacked: true, grid: { display: false } },
                        y: { stacked: true, beginAtZero: true, title: { display: true, text: 'ตัน' } },
                    },
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, usePointStyle: true } },
                        datalabels: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (c) { return ' ' + c.dataset.label + ': ' + c.parsed.y.toLocaleString(undefined, { maximumFractionDigits: 1 }) + ' ตัน'; },
                                footer: function (items) {
                                    const t = items.reduce(function (s, i) { return s + i.parsed.y; }, 0);
                                    return 'รวม ' + t.toLocaleString(undefined, { maximumFractionDigits: 1 }) + ' ตัน';
                                },
                            },
                        },
                    },
                },
            });
        })();
    </script>
@endpush
