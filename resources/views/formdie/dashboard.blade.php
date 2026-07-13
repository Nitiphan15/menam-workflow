@extends('layouts.layout')

@section('title', 'DIE Tracking Dashboard')
@section('page-title', 'DIE Tracking Dashboard')

@section('content')
    @include('formdie.partials.styles')
    <style>
        /* Page-specific extras */
        #dieChartsGrid .row > div { display:flex; }
        .heatmap-table { border-collapse:collapse; font-size:12px; }
        .heatmap-table th, .heatmap-table td { border:1px solid #e2e8f0; padding:6px 8px; text-align:center; }
        .heatmap-table th { background:#eef3f6; color:#1f2937; font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:.03em; }
        .heatmap-table td.cell { font-weight:600; min-width:36px; }
        .heatmap-table td.cell.clickable { cursor:pointer; }
        .heatmap-table td.cell.clickable:hover { outline:2px solid #2563eb; outline-offset:-2px; }
        .heatmap-table td.zero { color:#cbd5e1; }
        .heatmap-table td.row-label { background:#f8fafc; text-align:left; font-weight:700; color:#1f2937; }
        .badge-status { display:inline-block; padding:2px 8px; border-radius:999px; background:#dcfce7; color:#166534; font-size:11px; font-weight:600; }
        .badge-status.bad { background:#fee2e2; color:#991b1b; }
        .die-risk-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:10px; }
        .die-risk-item { border:1px solid #e5e7eb; border-left:4px solid #94a3b8; border-radius:8px; padding:10px 12px; background:#fff; }
        .die-risk-item.danger { border-left-color:#dc2626; background:#fff7f7; }
        .die-risk-item.warning { border-left-color:#f59e0b; background:#fffbeb; }
        .die-risk-item.info { border-left-color:#2563eb; background:#eff6ff; }
        .die-risk-item .title { font-weight:800; color:#1f2937; font-size:13px; }
        .die-risk-item .detail { color:#475569; font-size:12px; margin-top:3px; }
        .die-readiness { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:8px; }
        .die-ready-block { border:1px solid #dfe5ec; border-radius:8px; padding:9px; background:#fff; }
        .die-ready-block.ready { border-color:#86efac; background:#f0fdf4; }
        .die-ready-block.check { border-color:#fde68a; background:#fffbeb; }
        .die-ready-block.missing { border-color:#fecaca; background:#fff7f7; }
        .die-ready-block .block { font-weight:800; color:#111827; }
        .die-ready-block .meta { font-size:11px; color:#64748b; }
        .die-compare-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:10px; }
        .die-temporarily-hidden { display:none !important; }
        .die-modal {
            position:fixed; inset:0; z-index:10040; display:none; align-items:center; justify-content:center;
            background:rgba(15,23,42,.48); padding:20px;
        }
        .die-modal-panel { width:min(900px,96vw); max-height:82vh; overflow:auto; background:#fff; border-radius:10px; box-shadow:0 20px 60px rgba(15,23,42,.25); }
        /* Customer Claim banner */
        .die-claim-banner {
            display:flex; align-items:center; gap:12px; padding:11px 16px; margin-bottom:16px;
            border:1px solid #e2e8f0; border-left:4px solid #94a3b8; border-radius:10px;
            background:#fff; cursor:pointer; transition:box-shadow .15s, transform .05s;
        }
        .die-claim-banner:hover { box-shadow:0 6px 18px rgba(15,23,42,.10); }
        .die-claim-banner:active { transform:translateY(1px); }
        .die-claim-banner .ic { font-size:18px; width:34px; height:34px; display:flex; align-items:center; justify-content:center; border-radius:8px; background:#f1f5f9; color:#64748b; }
        .die-claim-banner .txt { flex:1; font-weight:700; color:#334155; font-size:14px; }
        .die-claim-banner .txt small { display:block; font-weight:500; color:#94a3b8; font-size:11px; margin-top:1px; }
        .die-claim-banner .cta { color:#94a3b8; font-size:13px; }
        .die-claim-banner.has-claim { border-left-color:#dc2626; background:#fff7f7; }
        .die-claim-banner.has-claim .ic { background:#fee2e2; color:#b91c1c; }
        .die-claim-banner.has-claim .txt { color:#991b1b; }
        .die-claim-banner.has-claim .cta { color:#dc2626; }
        .die-claim-banner.no-claim { border-left-color:#86efac; }
        .die-claim-banner.no-claim .ic { background:#dcfce7; color:#166534; }
        .die-claim-banner.loading { cursor:default; }
        .die-claim-banner.loading .cta { display:none; }
        .die-claim-wo-link { display:inline-block; margin:2px 4px 2px 0; padding:2px 8px; border-radius:6px;
            background:#eff6ff; color:#1d4ed8; font-weight:600; font-size:12px; text-decoration:none; border:1px solid #bfdbfe; }
        .die-claim-wo-link:hover { background:#dbeafe; text-decoration:none; }
        /* ไดร์บนเครื่อง ณ ปัจจุบัน */
        .die-machine-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:12px; }
        .die-machine-box { border:1px solid #e5e7eb; border-radius:10px; background:#fff; overflow:hidden; }
        .die-machine-box.unknown { border-style:dashed; background:#fbfbfc; }
        .die-machine-box .mhead { display:flex; align-items:center; justify-content:space-between; gap:8px;
            padding:8px 12px; background:#f1f5f9; border-bottom:1px solid #e5e7eb; cursor:pointer; user-select:none; }
        .die-machine-box .mhead:hover { background:#e8eef6; }
        .die-machine-box .mhead-right { display:flex; align-items:center; gap:8px; }
        .die-machine-box .mtoggle-icon { color:#64748b; font-size:11px; transition:transform .15s; }
        .die-machine-box.card-collapsed .mtoggle-icon { transform:rotate(-90deg); }
        .die-machine-box.card-collapsed .mbody,
        .die-machine-box.card-collapsed .die-machine-expand { display:none; }
        .die-machine-box .mname { font-weight:800; color:#1f2937; font-size:13px; }
        .die-machine-box .mname small { display:block; font-weight:500; color:#94a3b8; font-size:11px; }
        .die-machine-box .mcount { font-size:11px; font-weight:700; color:#1d4ed8; background:#eff6ff; border:1px solid #bfdbfe; border-radius:999px; padding:1px 9px; white-space:nowrap; }
        .die-machine-box .mbody { padding:9px 12px; display:flex; flex-direction:column; gap:11px; }
        .die-machine-box .wo-group { }
        .die-machine-box .wo-subhead { display:flex; align-items:center; justify-content:space-between; gap:8px;
            font-size:11px; font-weight:700; color:#475569; margin-bottom:5px; padding-bottom:3px; border-bottom:1px dashed #e2e8f0; }
        .die-machine-box .wo-subhead .wo-count { font-weight:600; color:#94a3b8; white-space:nowrap; }
        .die-machine-box .wo-chips { display:flex; flex-wrap:wrap; gap:6px; }
        .die-machine-chip { display:inline-flex; flex-direction:column; gap:1px; padding:4px 9px; border-radius:7px;
            background:#f8fafc; border:1px solid #e2e8f0; cursor:pointer; text-decoration:none; }
        .die-machine-chip:hover { background:#eff6ff; border-color:#93c5fd; }
        .die-machine-chip { max-width:240px; }
        .die-machine-chip .dno { font-weight:700; color:#0f172a; font-size:12px; }
        .die-machine-chip .dmeta { font-size:10px; color:#64748b; max-width:230px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .wo-group.collapsed .die-machine-chip.extra { display:none; }
        .die-machine-expand { margin:0 12px 10px; border:1px solid #cbd5e1; background:#f8fafc; color:#334155;
            font-size:11px; font-weight:700; border-radius:6px; padding:3px 10px; cursor:pointer; }
        .die-machine-box .wo-expand { margin:6px 0 0; }
        .die-machine-expand:hover { background:#eef2f7; border-color:#94a3b8; }
    </style>

    <div class="de-wrap">
        @include('formdie.partials.nav')

        {{-- Filter card --}}
        <div class="de-card">
            <div class="de-card-header">
                <span><i class="fa fa-magnifying-glass me-2 text-primary"></i>ค้นหา</span>
                <span style="font-size:11px; color:#667085; font-weight:500;">
                    ระบบเลือกโหมดอัตโนมัติ: WO# > Date > Year+Week
                </span>
            </div>
            <div class="de-card-body">
                <div class="de-filter" id="dieFilters">
                    <label>
                        <span style="color:#0d6efd;">🔍 Workorder #</span>
                        <input type="text" id="filterWo" placeholder="เช่น N2600136" value=""
                               style="text-transform:uppercase;" autocapitalize="characters">
                    </label>
                    <label>Description
                        <input type="text" id="filterDescription" placeholder="กลุ่ม description" autocomplete="off">
                    </label>
                    <div class="divider"></div>
                    <label>From<input type="date" id="filterDateFrom" value="{{ now()->toDateString() }}"></label>
                    <label>To<input type="date" id="filterDateTo" value="{{ now()->toDateString() }}"></label>
                    <label>Year<input type="number" id="filterYear" min="2000" max="2100" value="{{ now()->year }}" style="min-width:90px;"></label>
                    <label>Week<input type="number" id="filterWeek" min="1" max="53" value="" placeholder="W#" style="min-width:80px;"></label>
                    <div class="divider"></div>
                    <label>Site
                        <select id="filterConn">
                            <option value="pgsqlpcmw">W (Wire)</option>
                            <option value="pgsqlpcmp">P (Plus)</option>
                            <option value="ALL">ทั้งหมด (W+P)</option>
                        </select>
                    </label>
                    <label>Die Category
                        <select id="filterCategory">
                            <option value="">All categories</option>
                        </select>
                    </label>
                    <div class="actions">
                        <button type="button" class="btn btn-primary btn-sm" id="btnSearch">
                            <i class="fa fa-search me-1"></i>ค้นหา
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnReset" title="ล้างตัวกรอง">
                            <i class="fa fa-rotate-left me-1"></i>Reset
                        </button>
                        <button type="button" class="btn btn-success btn-sm" id="btnExport">
                            <i class="fa fa-file-excel me-1"></i>Export
                        </button>
                        <a id="btnWoDetail" href="#" class="btn btn-outline-primary btn-sm">
                            <i class="fa fa-file-lines me-1"></i>Detail Sheet
                        </a>
                    </div>
                </div>
            </div>
        </div>

        {{-- Customer Claim banner (รอบ 30 วัน) --}}
        <div class="die-claim-banner loading" id="dieClaimBanner" role="button" tabindex="0"
             aria-label="ดู Customer Claim ในรอบ 30 วัน">
            <span class="ic"><i class="fa fa-box-open"></i></span>
            <span class="txt" id="dieClaimBannerText">กำลังตรวจสอบ Customer Claim ในรอบ 30 วัน…</span>
            <span class="cta"><i class="fa fa-chevron-right"></i></span>
        </div>

        {{-- Completeness alert --}}
        <div id="dieCompleteness" style="display:none;"></div>

        {{-- Summary KPI cards --}}
        <div id="dieSummary" class="row g-3 mb-3"></div>

        {{-- Risk / readiness / compare --}}
        <div class="row g-3 mb-3 die-temporarily-hidden">
            <div class="col-lg-5">
                <div class="de-card h-100" id="dieExceptionCard" style="display:none;">
                    <div class="de-card-header">
                        <span><i class="fa fa-triangle-exclamation me-2 text-warning"></i>Risk / Exception Panel</span>
                        <span id="dieExceptionCounts" class="small text-muted">-</span>
                    </div>
                    <div class="de-card-body" id="dieExceptions"></div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="de-card h-100" id="dieReadinessCard" style="display:none;">
                    <div class="de-card-header">
                        <span><i class="fa fa-list-check me-2 text-success"></i>WO Readiness</span>
                        <span id="dieReadinessSummary" class="small text-muted">-</span>
                    </div>
                    <div class="de-card-body" id="dieReadiness"></div>
                </div>
                <div class="de-card h-100" id="dieCompareCard" style="display:none;">
                    <div class="de-card-header">
                        <span><i class="fa fa-code-compare me-2 text-primary"></i>Date Range Compare</span>
                        <span id="dieCompareRange" class="small text-muted">-</span>
                    </div>
                    <div class="de-card-body" id="dieCompare"></div>
                </div>
            </div>
        </div>

        {{-- Side widgets (top/idle) --}}
        <div class="row g-3 mb-3 die-temporarily-hidden" id="dieSideGrid" style="display:none;">
            <div class="col-md-6">
                <div class="de-card h-100">
                    <div class="de-card-header"><span><i class="fa fa-trophy me-2 text-warning"></i>Top Consumers (7 วัน)</span></div>
                    <div class="de-card-body" id="topConsumers">-</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="de-card h-100">
                    <div class="de-card-header"><span><i class="fa fa-medal me-2 text-warning"></i>ไดร์ Output สูงสุด (1 ปี)</span></div>
                    <div class="de-card-body" id="topOutput">-</div>
                </div>
            </div>
        </div>

        {{-- ไดร์ที่อยู่บนเครื่อง ณ ปัจจุบัน --}}
        <div class="de-card" id="dieMachineCard">
            <div class="de-card-header" style="flex-wrap:wrap; gap:8px;">
                <span><i class="fa fa-industry me-2 text-primary"></i>ไดร์ที่อยู่บนเครื่องรีด (DRAWING) ณ ปัจจุบัน</span>
                <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                    <span class="small text-muted">ขึ้นเครื่องวันที่</span>
                    <input type="date" id="dieMachineFrom" class="form-control form-control-sm" style="width:auto;" value="{{ now()->toDateString() }}">
                    <span class="small text-muted">ถึง</span>
                    <input type="date" id="dieMachineTo" class="form-control form-control-sm" style="width:auto;" value="{{ now()->toDateString() }}">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="dieMachineClear" title="แสดงทั้งหมด (ล้างวันที่)">
                        <i class="fa fa-list me-1"></i>ทั้งหมด
                    </button>
                    <span id="dieMachineMeta" class="small text-muted">-</span>
                </div>
            </div>
            <div class="de-card-body" id="dieMachineBody">
                <div class="de-loading"><i class="fa fa-spinner fa-spin me-2"></i>กำลังโหลด…</div>
            </div>
        </div>

        {{-- Heatmap --}}
        <div class="de-card" id="heatmapWrap" style="display:none;">
            <div class="de-card-header"><span><i class="fa fa-fire me-2 text-danger"></i>DRAWING Block Heatmap <small class="text-muted ms-2">คลิกตัวเลขเพื่อดู DIE ที่ใช้ได้</small></span></div>
            <div class="de-card-body" id="heatmapBody" style="overflow:auto;"></div>
        </div>

        {{-- Charts --}}
        <div id="dieChartsGrid" class="row g-3 mb-3" style="display:none;">
            <div class="col-md-4">
                <div class="de-card h-100">
                    <div class="de-card-header"><span id="chart1Title">Chart 1</span></div>
                    <div class="de-chart-box"><canvas id="chart1"></canvas></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="de-card h-100">
                    <div class="de-card-header"><span id="chart2Title">Chart 2</span></div>
                    <div class="de-chart-box"><canvas id="chart2"></canvas></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="de-card h-100">
                    <div class="de-card-header"><span id="chart3Title">Chart 3</span></div>
                    <div class="de-chart-box"><canvas id="chart3"></canvas></div>
                </div>
            </div>
        </div>

        {{-- Result table --}}
        <div class="de-card">
            <div class="de-card-header">
                <span><i class="fa fa-table me-2 text-primary"></i>ผลการค้นหา</span>
                <span id="dieResultMeta" class="small text-muted">-</span>
            </div>
            <div class="de-table-wrap" id="dieTableContainer">
                <div class="de-empty">
                    <div class="icon"><i class="fa fa-magnifying-glass"></i></div>
                    <div class="text">ระบุเงื่อนไขด้านบนแล้วกดค้นหา</div>
                </div>
            </div>
        </div>
    </div>

    <div class="die-modal" id="dieHeatmapModal" role="dialog" aria-modal="true">
        <div class="die-modal-panel">
            <div class="de-card-header">
                <span id="dieHeatmapModalTitle">Block detail</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="dieHeatmapModalClose">
                    <i class="fa fa-xmark"></i>
                </button>
            </div>
            <div class="de-card-body" id="dieHeatmapModalBody"></div>
        </div>
    </div>

    <div class="die-modal" id="dieClaimModal" role="dialog" aria-modal="true">
        <div class="die-modal-panel">
            <div class="de-card-header">
                <span id="dieClaimModalTitle"><i class="fa fa-box-open me-2 text-danger"></i>Customer Claim</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="dieClaimModalClose">
                    <i class="fa fa-xmark"></i>
                </button>
            </div>
            <div class="de-card-body" id="dieClaimModalBody"></div>
        </div>
    </div>

    <script>
        window.DIE_INITIAL_MODE = @json($initialMode ?? 'workorder');
        window.DIE_ROUTES = {
            byWorkorder:  "{{ route('die.by-workorder') }}",
            byDate:       "{{ route('die.by-date') }}",
            byWeek:       "{{ route('die.by-week') }}",
            compare:      "{{ route('die.compare') }}",
            export:       "{{ route('die.export') }}",
            topConsumers: "{{ route('die.top-consumers') }}",
            topOutput:    "{{ route('die.top-output') }}",
            idleDies:     "{{ route('die.idle-dies') }}",
            categories:   "{{ route('die.api.categories') }}",
            master:       "{{ route('die.master') }}",
            woDetail:     "{{ route('die.wo-detail') }}",
            recentClaims: "{{ route('die.recent-claims') }}",
            currentMachines: "{{ route('die.current-machines') }}",
            suggestWo:    "{{ url('/die-tracking/api/suggest/wo') }}",
            suggestDesc:  "{{ url('/die-tracking/api/suggest/desc') }}",
        };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="{{ asset('js/formdie/autocomplete.js') }}?v={{ time() }}"></script>
    <script src="{{ asset('js/formdie/dashboard.js') }}?v={{ time() }}"></script>
    <script>
        (() => {
            const modal = document.getElementById('dieHeatmapModal');
            const close = () => { modal.style.display = 'none'; };
            document.getElementById('dieHeatmapModalClose')?.addEventListener('click', close);
            modal?.addEventListener('click', event => { if (event.target === modal) close(); });
        })();
    </script>
@endsection
