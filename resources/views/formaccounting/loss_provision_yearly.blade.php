@extends('layouts.layout')

@section('title', 'รายการเผื่อผลขาดทุน — สรุปทั้งปี')
@section('page-title', 'รายการเผื่อผลขาดทุน — สรุปทั้งปี')

@section('content')
    @php
        $year = $year ?? now()->year;
        $site = $site ?? 'ALL';
        $monthlyTable = collect($monthlyTable ?? []);
        $customerSummary = collect($customerSummary ?? []);
        $agingSummary = collect($agingSummary ?? []);
        $totals = $totals ?? (object) ['gross' => 0, 'paid' => 0, 'remaining' => 0, 'docs' => 0];
        $fmt = fn($v, $d = 2) => ((float) $v) == 0.0 ? '-' : number_format((float) $v, $d);
        $fmtInt = fn($v) => number_format((int) $v);
    @endphp

    <style>
        .lpy-wrap { background:#f7f8fa; padding:18px; border-radius:10px; }
        .lpy-card { background:#fff; border:1px solid #e3e6eb; border-radius:10px; box-shadow:0 1px 2px rgba(15,23,42,.04); overflow:hidden; }
        .lpy-card + .lpy-card { margin-top:16px; }
        .lpy-card-header { padding:14px 18px; border-bottom:1px solid #eef0f4; background:#fff; font-weight:700; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
        .lpy-meta { color:#667085; font-weight:500; font-size:.82rem; }
        .lpy-card-body { padding:16px 18px; }

        .lpy-kpi { height:100%; padding:16px 18px; border:1px solid #e3e6eb; border-radius:10px; background:#fff; position:relative; overflow:hidden; }
        .lpy-kpi::before { content:''; position:absolute; top:0; left:0; width:100%; height:3px; background:linear-gradient(90deg,#2563eb,#0ea5e9); }
        .lpy-kpi.kpi-amber::before { background:linear-gradient(90deg,#d97706,#f59e0b); }
        .lpy-kpi.kpi-green::before { background:linear-gradient(90deg,#059669,#10b981); }
        .lpy-kpi.kpi-rose::before { background:linear-gradient(90deg,#be123c,#f43f5e); }
        .lpy-kpi .label { color:#667085; font-size:.74rem; margin-bottom:6px; text-transform:uppercase; letter-spacing:.05em; font-weight:600; }
        .lpy-kpi .value { color:#0f172a; font-size:1.55rem; font-weight:800; line-height:1.15; font-variant-numeric:tabular-nums; }
        .lpy-kpi .sub { color:#667085; font-size:.78rem; margin-top:6px; }

        .lpy-table { margin-bottom:0; }
        .lpy-table th { background:#0f172a; color:#fff; white-space:nowrap; font-size:.74rem; text-transform:uppercase; letter-spacing:.04em; font-weight:600; padding:10px 12px; }
        .lpy-table td { vertical-align:middle; padding:8px 12px; border-color:#eef0f4; color:#0f172a; }
        .lpy-table tbody tr:hover { background:#f1f5ff; }
        .lpy-table tfoot td { background:#e0ecff; border-top:2px solid #0f172a; font-weight:700; }
        .lpy-num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }

        .lpy-chart-box { padding:14px 16px; height:340px; }
        .lpy-aging-badge { display:inline-block; padding:2px 10px; border-radius:999px; font-size:.72rem; font-weight:700; color:#fff; min-width:64px; text-align:center; }

        .lpy-loading-overlay { position:fixed; inset:0; background:rgba(15,23,42,.5); display:none; align-items:center; justify-content:center; z-index:2000; }
        .lpy-loading-overlay.active { display:flex; }
        .lpy-loading-overlay .box { position:relative; background:#fff; padding:24px 56px 24px 32px; border-radius:10px; box-shadow:0 6px 20px rgba(0,0,0,.2); display:flex; align-items:center; gap:14px; font-weight:600; color:#0f172a; min-width:240px; }
        .lpy-loading-overlay .spinner { width:24px; height:24px; border:3px solid #e2e8f0; border-top-color:#2563eb; border-radius:50%; animation:lpy-spin 0.7s linear infinite; }
        .lpy-loading-overlay .close-btn { position:absolute; top:6px; right:8px; background:transparent; border:0; font-size:1.2rem; color:#94a3b8; cursor:pointer; line-height:1; padding:4px 8px; }
        .lpy-loading-overlay .close-btn:hover { color:#dc2626; }
        @keyframes lpy-spin { to { transform: rotate(360deg); } }

        @media (max-width: 767.98px) {
            .lpy-wrap { padding:10px; }
            .lpy-kpi .value { font-size:1.2rem; }
            .lpy-chart-box { height:280px; }
        }
    </style>

    <div class="lpy-wrap">

        {{-- Filters --}}
        <div class="lpy-card mb-3">
            <div class="lpy-card-header">
                <span><i class="fas fa-filter me-1 text-primary"></i> ตัวกรอง — สรุปทั้งปี</span>
                <span class="lpy-meta">SQL aggregate · ไม่โหลด detail · เร็ว</span>
            </div>
            <form method="GET" action="{{ route('accounting.loss-provision.yearly') }}" class="lpy-card-body">
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
                    <div class="col-lg-8 col-md-6 d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i> Search</button>
                        <a href="{{ route('accounting.loss-provision.yearly') }}" class="btn btn-outline-secondary"><i class="fas fa-rotate-left me-1"></i> Reset</a>
                        <a href="{{ route('accounting.loss-provision.yearly.export', ['year' => $year, 'site' => $site]) }}" class="btn btn-success lpy-export-link">
                            <i class="fas fa-file-excel me-1"></i> Export Excel
                        </a>
                        <a href="{{ route('accounting.loss-provision.index') }}" class="btn btn-outline-primary ms-auto">
                            <i class="fas fa-list me-1"></i> ดูรายงานละเอียด (รายเดือน)
                        </a>
                    </div>
                </div>
            </form>
        </div>

        {{-- KPIs --}}
        <div class="row g-3 mb-3">
            <div class="col-xl-3 col-md-6">
                <div class="lpy-kpi">
                    <div class="label">ยอดรวมทั้งปี</div>
                    <div class="value">{{ $fmt($totals->gross) }}</div>
                    <div class="sub">{{ $fmtInt($totals->docs) }} เอกสาร · ปี {{ $year }}</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="lpy-kpi kpi-green">
                    <div class="label">รับชำระแล้ว</div>
                    <div class="value">{{ $fmt($totals->paid) }}</div>
                    <div class="sub">{{ $totals->gross > 0 ? number_format($totals->paid / $totals->gross * 100, 1) . '%' : '-' }} ของยอดรวม</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="lpy-kpi kpi-amber">
                    <div class="label">ยอดคงเหลือ</div>
                    <div class="value">{{ $fmt($totals->remaining) }}</div>
                    <div class="sub">ค้างชำระ ณ วันนี้</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="lpy-kpi kpi-rose">
                    <div class="label">ค้างเกิน 90 วัน</div>
                    @php
                        $over90 = (float) $agingSummary->whereIn('key', ['B91_180', 'B180_PLUS'])->sum('remaining');
                        $over180 = (float) ($agingSummary->firstWhere('key', 'B180_PLUS')->remaining ?? 0);
                    @endphp
                    <div class="value text-danger">{{ $fmt($over90) }}</div>
                    <div class="sub">ค้างเกิน 180: <span class="text-danger fw-bold">{{ $fmt($over180) }}</span></div>
                </div>
            </div>
        </div>

        {{-- Monthly chart + aging chart --}}
        <div class="row g-3 mb-3">
            <div class="col-xl-7">
                <div class="lpy-card h-100">
                    <div class="lpy-card-header">
                        <span><i class="fas fa-chart-line me-1 text-primary"></i> แนวโน้มรายเดือน — ปี {{ $year }}</span>
                        <span class="lpy-meta">ยอดออกบิล · รับชำระ · คงเหลือ</span>
                    </div>
                    <div class="lpy-chart-box">
                        <canvas id="lpyMonthlyChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="lpy-card h-100">
                    <div class="lpy-card-header">
                        <span><i class="fas fa-chart-pie me-1 text-primary"></i> สัดส่วนคงเหลือตามอายุหนี้</span>
                    </div>
                    <div class="lpy-chart-box">
                        <canvas id="lpyAgingChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        {{-- Monthly table --}}
        <div class="lpy-card">
            <div class="lpy-card-header">
                <span><i class="fas fa-table me-1 text-primary"></i> สรุปรายเดือน</span>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered lpy-table">
                    <thead>
                        <tr>
                            <th>เดือน</th>
                            <th class="lpy-num">ยอดออกบิล</th>
                            <th class="lpy-num">รับชำระ</th>
                            <th class="lpy-num">คงเหลือ</th>
                            <th class="lpy-num">% รับชำระ</th>
                            <th class="lpy-num">เอกสาร</th>
                            <th>ดู Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($monthlyTable as $m)
                            @php
                                $monthFrom = sprintf('%04d-%02d-01', $year, $m->month);
                                $monthTo = \Carbon\Carbon::parse($monthFrom)->endOfMonth()->toDateString();
                                $pct = $m->gross > 0 ? $m->paid / $m->gross * 100 : 0;
                            @endphp
                            <tr>
                                <td><strong>{{ $m->label }}</strong> {{ $year }}</td>
                                <td class="lpy-num">{{ $fmt($m->gross) }}</td>
                                <td class="lpy-num">{{ $fmt($m->paid) }}</td>
                                <td class="lpy-num fw-bold">{{ $fmt($m->remaining) }}</td>
                                <td class="lpy-num">{{ $m->gross > 0 ? number_format($pct, 1) . '%' : '-' }}</td>
                                <td class="lpy-num">{{ $fmtInt($m->doc_count) }}</td>
                                <td>
                                    <a href="{{ route('accounting.loss-provision.index', ['date_from' => $monthFrom, 'date_to' => $monthTo, 'site' => $site]) }}"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-eye me-1"></i> Detail
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>รวมทั้งปี</td>
                            <td class="lpy-num">{{ $fmt($totals->gross) }}</td>
                            <td class="lpy-num">{{ $fmt($totals->paid) }}</td>
                            <td class="lpy-num">{{ $fmt($totals->remaining) }}</td>
                            <td class="lpy-num">{{ $totals->gross > 0 ? number_format($totals->paid / $totals->gross * 100, 1) . '%' : '-' }}</td>
                            <td class="lpy-num">{{ $fmtInt($totals->docs) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        {{-- Top customers --}}
        <div class="lpy-card">
            <div class="lpy-card-header">
                <span><i class="fas fa-users me-1 text-primary"></i> ลูกค้า Top 30 — ตามยอดคงเหลือ</span>
                <span class="lpy-meta">เรียงตามยอดค้างชำระมากไปน้อย</span>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered lpy-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>ลูกค้า</th>
                            <th class="lpy-num">ยอดออกบิล</th>
                            <th class="lpy-num">รับชำระ</th>
                            <th class="lpy-num">คงเหลือ</th>
                            <th class="lpy-num">% ค้าง</th>
                            <th class="lpy-num">เอกสาร</th>
                            <th>ดู Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($customerSummary as $idx => $cust)
                            @php $pct = $cust->gross > 0 ? $cust->remaining / $cust->gross * 100 : 0; @endphp
                            <tr>
                                <td>{{ $idx + 1 }}</td>
                                <td>{{ $cust->customer_name }}</td>
                                <td class="lpy-num">{{ $fmt($cust->gross) }}</td>
                                <td class="lpy-num">{{ $fmt($cust->paid) }}</td>
                                <td class="lpy-num fw-bold {{ $cust->remaining > 0 ? 'text-danger' : '' }}">{{ $fmt($cust->remaining) }}</td>
                                <td class="lpy-num">{{ $cust->gross > 0 ? number_format($pct, 1) . '%' : '-' }}</td>
                                <td class="lpy-num">{{ $fmtInt($cust->doc_count) }}</td>
                                <td>
                                    <a href="{{ route('accounting.loss-provision.index', ['customer' => $cust->customer_name, 'site' => $site]) }}"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-eye me-1"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">ไม่มีข้อมูล</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Aging snapshot table --}}
        <div class="lpy-card">
            <div class="lpy-card-header">
                <span><i class="fas fa-clock me-1 text-primary"></i> Aging snapshot ณ วันนี้</span>
                <span class="lpy-meta">เฉพาะที่ยังค้างชำระ</span>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered lpy-table">
                    <thead>
                        <tr>
                            <th>ช่วงอายุ</th>
                            <th class="lpy-num">เอกสาร</th>
                            <th class="lpy-num">ยอดคงเหลือ</th>
                            <th class="lpy-num">% ของรวม</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $agingTotal = max((float) $agingSummary->sum('remaining'), 0.0001); @endphp
                        @foreach ($agingSummary as $b)
                            <tr>
                                <td><span class="lpy-aging-badge" style="background:{{ $b->color }}">{{ $b->label }}</span></td>
                                <td class="lpy-num">{{ $fmtInt($b->count) }}</td>
                                <td class="lpy-num fw-bold">{{ $fmt($b->remaining) }}</td>
                                <td class="lpy-num">{{ $b->remaining > 0 ? number_format($b->remaining / $agingTotal * 100, 1) . '%' : '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @php
        $monthlyJs = $monthlyTable->map(function ($m) {
            return [
                'label' => $m->label,
                'gross' => (float) $m->gross,
                'paid' => (float) $m->paid,
                'remaining' => (float) $m->remaining,
            ];
        })->values();
        $agingJs = $agingSummary->map(function ($b) {
            return [
                'label' => $b->label,
                'remaining' => (float) $b->remaining,
                'color' => $b->color,
            ];
        })->values();
    @endphp

    <div class="lpy-loading-overlay" id="lpyLoadingOverlay">
        <div class="box">
            <button type="button" class="close-btn" id="lpyOverlayClose" title="ปิด">&times;</button>
            <div class="spinner"></div>
            <div id="lpyOverlayMsg">กำลังโหลดข้อมูล…</div>
        </div>
    </div>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                // Loading overlay handlers (with close button + auto-hide for download)
                var overlay = document.getElementById('lpyLoadingOverlay');
                var overlayMsg = document.getElementById('lpyOverlayMsg');
                var overlayClose = document.getElementById('lpyOverlayClose');
                function showOverlay(msg) {
                    if (!overlay) return;
                    overlayMsg.textContent = msg || 'กำลังโหลดข้อมูล…';
                    overlay.classList.add('active');
                }
                function hideOverlay() { overlay && overlay.classList.remove('active'); }
                overlayClose && overlayClose.addEventListener('click', hideOverlay);

                // Export Excel: show overlay, poll cookie to detect download completion
                function readCookie(name) {
                    var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
                    return m ? decodeURIComponent(m[1]) : null;
                }
                function clearCookie(name) {
                    document.cookie = name + '=; Path=/; Max-Age=0';
                }
                document.querySelectorAll('.lpy-export-link').forEach(function (a) {
                    a.addEventListener('click', function () {
                        clearCookie('lp_dl_token');
                        showOverlay('กำลังเตรียมไฟล์ Excel… กรุณารอสักครู่');
                        var startedAt = Date.now();
                        var poll = setInterval(function () {
                            if (readCookie('lp_dl_token')) {
                                clearInterval(poll);
                                clearCookie('lp_dl_token');
                                hideOverlay();
                            } else if (Date.now() - startedAt > 30000) {
                                clearInterval(poll);
                                hideOverlay();
                            }
                        }, 400);
                    });
                });

                // Search submit overlay
                var form = document.querySelector('form[action*="loss-provision/yearly"]');
                if (form) {
                    form.addEventListener('submit', function () { showOverlay('กำลังโหลดข้อมูล…'); });
                }

                if (!window.Chart) return;

                var monthly = @json($monthlyJs);
                var aging = @json($agingJs);

                var fmt = function (v) { return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };

                var monthlyCanvas = document.getElementById('lpyMonthlyChart');
                if (monthlyCanvas) {
                    new Chart(monthlyCanvas, {
                        type: 'bar',
                        data: {
                            labels: monthly.map(m => m.label),
                            datasets: [
                                { label: 'ยอดออกบิล', data: monthly.map(m => Math.round(m.gross * 100) / 100), backgroundColor: '#2563eb', borderRadius: 4 },
                                { label: 'รับชำระ', data: monthly.map(m => Math.round(m.paid * 100) / 100), backgroundColor: '#10b981', borderRadius: 4 },
                                { label: 'คงเหลือ', data: monthly.map(m => Math.round(m.remaining * 100) / 100), type: 'line', borderColor: '#dc2626', backgroundColor: 'rgba(220,38,38,.15)', tension: 0.3, fill: false, borderWidth: 2, pointRadius: 3 },
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: { mode: 'index', intersect: false },
                            plugins: {
                                legend: { position: 'bottom' },
                                tooltip: { callbacks: { label: function (ctx) { return ' ' + ctx.dataset.label + ': ' + fmt(ctx.raw); } } }
                            },
                            scales: { y: { ticks: { callback: function (v) { return Number(v).toLocaleString(); } } } }
                        }
                    });
                }

                var agingFiltered = aging.filter(b => Number(b.remaining) > 0.01);
                var agingCanvas = document.getElementById('lpyAgingChart');
                if (agingCanvas && agingFiltered.length) {
                    new Chart(agingCanvas, {
                        type: 'doughnut',
                        data: {
                            labels: agingFiltered.map(b => b.label),
                            datasets: [{
                                data: agingFiltered.map(b => Math.round(b.remaining * 100) / 100),
                                backgroundColor: agingFiltered.map(b => b.color),
                                borderColor: '#fff',
                                borderWidth: 2,
                            }]
                        },
                        options: {
                            responsive: true, maintainAspectRatio: false, cutout: '60%',
                            plugins: {
                                legend: { position: 'bottom' },
                                tooltip: { callbacks: { label: function (ctx) {
                                    var total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                    var pct = total ? (ctx.raw / total * 100).toFixed(1) : 0;
                                    return ' ' + ctx.label + ': ' + fmt(ctx.raw) + ' (' + pct + '%)';
                                } } }
                            }
                        }
                    });
                }
            });
        </script>
    @endpush
@endsection
