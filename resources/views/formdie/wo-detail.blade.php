@extends('layouts.layout')

@section('title', 'WO Detail Sheet')
@section('page-title', 'WO Detail Sheet')

@section('content')
    @include('formdie.partials.styles')
    <style>
        .wd-blocks { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:8px; margin:10px 0; }
        .wd-block { background:#fff; border:1px solid #e8edf2; border-radius:6px; padding:8px; }
        .wd-block .no { font-size:11px; font-weight:700; color:#667085; text-transform:uppercase; }
        .wd-block .size { font-size:14px; font-weight:800; color:#1f2937; }
        .wd-block .mat { font-size:11px; color:#475569; }
        .wd-block .dies { margin-top:6px; padding-top:6px; border-top:1px dashed #e8edf2; }
        .wd-block .die-chip {
            display:inline-block; padding:1px 6px; margin:1px; border-radius:4px;
            background:#dbeafe; color:#1e40af; font-size:10px; cursor:pointer;
        }
        .wd-step-head .mini { padding:4px 10px; border-radius:6px; background:#f1f5f9; font-size:11px; font-weight:700; }
        .wd-step-head .mini.fg  { background:#dcfce7; color:#166534; }
        .wd-step-head .mini.ncr { background:#fee2e2; color:#991b1b; }
        .wd-step-head .mini.prog{ background:#dbeafe; color:#1e40af; }
        .wd-ncr-chip { display:inline-block; padding:1px 6px; margin:1px; background:#fee2e2; color:#991b1b; border-radius:4px; font-size:10px; font-weight:600; }
        .wd-q-table td.pass { background:#dcfce7; color:#166534; }
        .wd-q-table td.fail { background:#fee2e2; color:#991b1b; }
        .wd-route-actions { display:flex; flex-wrap:wrap; gap:6px; }
        .wd-route-btn {
            border:1px solid #cbd5e1; background:#fff; color:#1f2937; border-radius:6px;
            padding:5px 9px; font-size:12px; font-weight:800;
        }
        .wd-route-btn:hover { background:#eff6ff; border-color:#93c5fd; color:#1d4ed8; }
        .wd-route-btn.done { border-color:#86efac; background:#f0fdf4; color:#166534; }
        .wd-route-btn.in_progress { border-color:#bfdbfe; background:#eff6ff; color:#1e40af; }
        .wd-route-btn.pending { border-color:#e2e8f0; background:#f8fafc; color:#475569; }
        .wd-route-btn.claim { border-color:#fecaca; background:#fef2f2; color:#b91c1c; }
        .wd-step-target { scroll-margin-top:90px; }
        /* ผลทดสอบคุณภาพ: ย่อ/ขยายเมื่อแถวเยอะ */
        .wd-q-collapsed .wd-row-extra { display:none; }
        .wd-q-toggle {
            margin-top:6px; border:1px solid #cbd5e1; background:#f8fafc; color:#334155;
            font-size:12px; font-weight:600; border-radius:6px; padding:4px 12px; cursor:pointer;
        }
        .wd-q-toggle:hover { background:#eef2f7; border-color:#94a3b8; }
        /* รายชื่อเครื่องใน station */
        .wd-machine-list { display:flex; flex-wrap:wrap; gap:6px; }
        .wd-machine-chip { display:inline-flex; flex-direction:column; gap:1px; padding:3px 9px;
            border:1px solid #bbf7d0; background:#f0fdf4; border-radius:7px; line-height:1.2; }
        .wd-machine-chip b { font-size:12px; color:#065f46; }
        .wd-machine-chip small { font-size:10px; color:#64748b; }
    </style>

    <div class="de-wrap">
        @include('formdie.partials.nav')

        {{-- Toolbar --}}
        <div class="de-card">
            <div class="de-card-body" style="padding:10px 14px;">
                <div class="de-filter">
                    <label>Workorder #
                        <input type="text" id="wdWo" placeholder="N2600136" value="{{ $initialWo }}"
                               style="text-transform:uppercase;" autocapitalize="characters">
                    </label>
                    <div class="actions">
                        <button type="button" class="btn btn-sm btn-primary" id="wdSearch">
                            <i class="fa fa-search me-1"></i>ค้นหา
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="wdReset">
                            <i class="fa fa-rotate-left me-1"></i>Reset
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div id="wdContent">
            <div class="de-empty">
                <div class="icon"><i class="fa fa-file-lines"></i></div>
                <div class="text">ใส่หมายเลข WO ด้านบนแล้วกดค้นหา</div>
            </div>
        </div>
    </div>

    <script>
        window.WD_API = "{{ route('die.api.wo-detail') }}";
        window.WD_MASTER = "{{ url('/die-tracking/master') }}";
        window.WD_DASHBOARD = "{{ route('die.index') }}";
        window.WD_SUGGEST_WO = "{{ url('/die-tracking/api/suggest/wo') }}";
    </script>
    <script src="{{ asset('js/formdie/autocomplete.js') }}?v={{ time() }}"></script>
    <script src="{{ asset('js/formdie/wo-detail.js') }}?v={{ time() }}"></script>
@endsection
