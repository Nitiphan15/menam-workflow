@extends('layouts.layout')

@section('title', 'DIE Master')
@section('page-title', 'DIE Master')

@section('content')
    @include('formdie.partials.styles')
    <style>
        .dm-pager { display:flex; gap:8px; justify-content:space-between; align-items:center;
            padding:8px 14px; border-top:1px solid #e8edf2; background:#f8fafc; font-size:12px; color:#475569; }
        .dm-pager .pages { display:flex; gap:4px; align-items:center; }
        .dm-pager .pages select { border:1px solid #cbd5e1; padding:3px 8px; border-radius:6px; }
        .dm-profile-summary { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:10px; margin-bottom:14px; }
    </style>

    <div class="de-wrap">
        @include('formdie.partials.nav')

        {{-- Sub-tabs (Master / Location / Material Trace) --}}
        <div class="de-card" style="overflow:visible;">
            <div class="de-tabs">
                <button type="button" data-mode="master" class="active">Master List</button>
                <button type="button" data-mode="location">Current Location</button>
                <button type="button" data-mode="material" style="display:none;">🔍 Material Trace</button>
            </div>
            <div class="de-card-body">
                <div class="de-filter">
                    <label>Site
                        <select id="dmSite">
                            <option value="pgsqlpcmw">W (Wire)</option>
                            <option value="pgsqlpcmp">P (Plus)</option>
                            <option value="ALL">ทั้งหมด (W+P)</option>
                        </select>
                    </label>
                    <label data-show="master,location">Search
                        <input type="text" id="dmQuery" placeholder="Die# / Description">
                    </label>
                    <label data-show="master">Description
                        <input type="text" id="dmDesc" placeholder="กลุ่ม description" autocomplete="off">
                    </label>
                    <label data-show="location" style="display:none;">ย้อนหลัง (วัน)
                        <input type="number" id="dmDays" value="30" min="1" max="365" style="min-width:90px;">
                    </label>
                    <label data-show="master">Status
                        <select id="dmStatus">
                            <option value="">ทั้งหมด</option>
                        </select>
                    </label>
                    <label data-show="master,location">ประเภท (Type)
                        <select id="dmType">
                            <option value="ไดร์">ไดร์</option>
                            <option value="ALL">ทั้งหมด</option>
                        </select>
                    </label>
                    <label data-show="master,location,material">Category
                        <select id="dmCategory">
                            <option value="">All categories</option>
                        </select>
                    </label>
                    <label data-show="master">Supplier
                        <select id="dmSupplier">
                            <option value="">All suppliers</option>
                        </select>
                    </label>
                    <label data-show="master">เรียงลำดับ
                        <select id="dmSort">
                            <option value="last">ใช้ล่าสุด</option>
                            <option value="kg_desc">ยอดผลิต (kg) มาก→น้อย</option>
                            <option value="meter_desc">Meter มาก→น้อย</option>
                            <option value="used_desc">จำนวนเบิก มาก→น้อย</option>
                        </select>
                    </label>
                    <label data-show="material" style="display:none;">Heat No
                        <input type="text" id="dmHeatno" placeholder="H12345">
                    </label>
                    <label data-show="material" style="display:none;">Coil No
                        <input type="text" id="dmCoilno" placeholder="C12345">
                    </label>
                    <div class="actions">
                        <button type="button" class="btn btn-primary btn-sm" id="dmSearch">
                            <i class="fa fa-search me-1"></i>ค้นหา
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="dmReset">
                            <i class="fa fa-rotate-left me-1"></i>Reset
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Result table --}}
        <div class="de-card">
            <div class="de-card-header" id="dmCardTitle">
                <span><i class="fa fa-database me-2 text-primary"></i>Master List</span>
                <span id="dmResultMeta" class="small text-muted">-</span>
            </div>
            <div class="de-table-wrap" id="dmResult" style="max-height:calc(100vh - 360px); min-height:300px;">
                <div class="de-empty">
                    <div class="icon"><i class="fa fa-database"></i></div>
                    <div class="text">กดค้นหาเพื่อดูข้อมูล</div>
                </div>
            </div>
            <div class="dm-pager" id="dmPager" style="display:none;">
                <div id="dmPagerInfo">-</div>
                <div class="pages">
                    <label style="display:flex; gap:6px; align-items:center;">แสดง
                        <select id="dmPageSize">
                            <option value="25">25</option>
                            <option value="50" selected>50</option>
                            <option value="100">100</option>
                            <option value="200">200</option>
                        </select>
                    </label>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="dmFirst">«</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="dmPrev">‹</button>
                    <span id="dmPageInfo" style="min-width:80px; text-align:center;">-</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="dmNext">›</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="dmLast">»</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Profile modal --}}
    <div class="dm-modal-bg" id="dmModalBg" style="position:fixed; inset:0; background:rgba(15,23,42,.5); display:none; align-items:center; justify-content:center; z-index:1050;">
        <div style="background:#fff; width:90%; max-width:1100px; max-height:90vh; overflow:auto; border-radius:8px; padding:0;">
            <div class="de-card-header">
                <span id="dmModalTitle"><i class="fa fa-circle-info me-2 text-primary"></i>Die Profile</span>
                <button class="btn btn-sm btn-outline-secondary" id="dmClose"><i class="fa fa-xmark"></i></button>
            </div>
            <div class="de-card-body" id="dmModalBody"></div>
        </div>
    </div>

    <script>
        window.DM_ROUTES = {
            master:      "{{ route('die.api.master') }}",
            profile:     "{{ url('/die-tracking/api/profile') }}",
            location:    "{{ route('die.api.location') }}",
            material:    "{{ route('die.api.material') }}",
            categories:  "{{ route('die.api.categories') }}",
            suppliers:   "{{ route('die.api.suppliers') }}",
            equiptypes:  "{{ route('die.api.equiptypes') }}",
            statuses:    "{{ route('die.api.statuses') }}",
            woDetail:    "{{ route('die.wo-detail') }}",
            dashboard:   "{{ route('die.index') }}",
            suggestDie:  "{{ url('/die-tracking/api/suggest/die') }}",
            suggestDesc: "{{ url('/die-tracking/api/suggest/desc') }}",
            suggestHeat: "{{ url('/die-tracking/api/suggest/heat') }}",
            suggestCoil: "{{ url('/die-tracking/api/suggest/coil') }}",
        };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="{{ asset('js/formdie/autocomplete.js') }}?v={{ time() }}"></script>
    <script src="{{ asset('js/formdie/master.js') }}?v={{ time() }}"></script>
@endsection
