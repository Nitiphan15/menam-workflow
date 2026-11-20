@extends('layouts.layout')

@section('title', 'GPS Trip Tracking')
@section('page-title', 'GPS Trip Tracking')

@push('styles')
    <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}?v={{ filemtime(public_path('vendor/leaflet/leaflet.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/formdp/gps-trip-tracking.css') }}?v={{ filemtime(public_path('css/formdp/gps-trip-tracking.css')) }}">
@endpush

@section('content')
    <div id="gps-trip-tracking" class="gps-demo-shell"
         data-vehicles='@json($vehicles)'
         data-snapshot='@json($liveSnapshot)'
         data-matches='@json($matchReport)'
         data-queue-date="{{ $queueDate }}"
         data-is-today="{{ $isToday ? '1' : '0' }}"
         data-sync-minutes="{{ $syncIntervalMinutes }}"
         data-live-url="{{ route('dp.gps-trip-tracking.live') }}"
         data-route-url="{{ route('dp.gps-trip-tracking.route', ['gpsId' => '__GPS__']) }}">
        <section class="gps-source-console" aria-label="สถานะแหล่งข้อมูล GPS">
            <div class="gps-source-signal"><span id="gps-source-dot" class="gps-source-dot is-checking"></span><span id="gps-source-badge" class="gps-source-badge">กำลังโหลด</span></div>
            <div class="gps-source-copy">
                <div id="gps-source-title" class="gps-source-title">กำลังดึงตำแหน่งรถจาก DTC</div>
                <div id="gps-source-note" class="gps-source-note">ตำแหน่ง/สถานะจาก DTC GPS · คิวและรายการส่งจาก FormDP · อัปเดตอัตโนมัติทุก {{ $syncIntervalMinutes }} นาทีขณะเปิดหน้านี้</div>
                <div class="gps-source-facts">
                    <span id="gps-source-freshness"><i class="fas fa-clock"></i> -</span>
                    <span id="gps-last-success"><i class="fas fa-cloud-arrow-down"></i> -</span>
                    <span id="gps-match-summary"><i class="fas fa-link"></i> -</span>
                    @isset($dtcUsage)
                        <span id="gps-dtc-usage" class="{{ $dtcUsage['percent'] >= 80 || $dtcUsage['locked_until'] ? 'is-stale' : '' }}" title="งบใช้ได้ {{ number_format($dtcUsage['budget']) }} (สำรอง {{ number_format($dtcUsage['quota'] - $dtcUsage['budget']) }})">
                            <i class="fas fa-gauge-high"></i> DTC เดือนนี้ {{ number_format($dtcUsage['month_total']) }} / {{ number_format($dtcUsage['quota']) }} · วันนี้ {{ number_format($dtcUsage['today']) }}
                        </span>
                    @endisset
                </div>
            </div>
            <div class="gps-toolbar-actions">
                <button type="button" id="gps-mapping-toggle" class="btn btn-outline-secondary btn-sm" aria-expanded="false">
                    <i class="fas fa-list-check me-1"></i> ดูการจับคู่รถ
                </button>
                @can('ADMINWEB')
                    <button type="button" id="gps-dtc-test" class="btn btn-outline-success btn-sm" data-url="{{ route('dp.gps-trip-tracking.live') }}?force=1" title="ดึงตำแหน่งใหม่ทันที (1 request)">
                        <i class="fas fa-satellite-dish me-1"></i> ดึงตำแหน่งตอนนี้
                    </button>
                    <button type="button" id="gps-dtc-refresh-master" class="btn btn-outline-secondary btn-sm" data-url="{{ route('dp.gps-trip-tracking.live') }}?refresh_master=1" title="ดึงรายชื่อรถจาก DTC ใหม่ (2 requests)">
                        <i class="fas fa-arrows-rotate me-1"></i> รีเฟรชรายชื่อรถ
                    </button>
                @endcan
            </div>
        </section>
        <section id="gps-mapping-panel" class="gps-mapping-panel" hidden>
            <header><div><strong>การจับคู่รถ DTC กับ Truck Master / คิว FormDP</strong><span>ทะเบียนจาก DTC (vehicle_name) เทียบกับทะเบียนใน FormDP · แสดงรถที่ขาดจากทั้งสองฝั่ง</span></div><span id="gps-mapping-count" class="gps-match-pill">-</span></header>
            <div class="table-responsive"><table class="table gps-mapping-table"><thead><tr><th>DTC GPS ID</th><th>ทะเบียนจาก DTC</th><th>ทะเบียน FormDP</th><th>เที่ยว/คนขับ</th><th>ข้อมูลล่าสุด</th><th>ผล</th></tr></thead><tbody id="gps-mapping-rows"></tbody></table></div>
        </section>
        <section id="gps-dtc-test-result" class="gps-dtc-result" aria-live="polite" hidden></section>

        <section class="gps-kpis" aria-label="ภาพรวมสถานะรถจาก DTC">
            <div class="gps-kpi"><div class="gps-kpi-label">วิ่งอยู่</div><div id="gps-moving-count" class="gps-kpi-value">-</div></div>
            <div class="gps-kpi"><div class="gps-kpi-label">จอดนอกบริษัท</div><div id="gps-stopped-count" class="gps-kpi-value">-</div></div>
            <div class="gps-kpi"><div class="gps-kpi-label">อยู่บริษัท</div><div id="gps-company-count" class="gps-kpi-value">-</div></div>
            <div class="gps-kpi"><div class="gps-kpi-label">ข้อมูลเก่า &gt; 10 นาที</div><div id="gps-stale-count" class="gps-kpi-value is-warning">-</div></div>
        </section>

        <section class="gps-workspace" aria-label="แผนที่และรายการรถ">
            <div class="gps-map-stage">
                <div id="gps-map" role="application" aria-label="แผนที่ตำแหน่งรถจาก DTC"></div>
                <button type="button" id="gps-view-all" class="gps-map-action"><i class="fas fa-expand me-1"></i> ดูรถทั้งหมด</button>
            </div>
            <aside class="gps-vehicle-panel">
                <div class="gps-panel-head">
                    <div class="gps-panel-head-row">
                        <strong title="Truck Master (ACTIVE) + ทะเบียน manual ที่มีคิวในวันที่เลือก">รถ {{ count($vehicles) }} คัน · มีคิว {{ $queuedVehicleCount }}</strong>
                        <form method="get" class="gps-queue-date-form" aria-label="เลือกวันที่คิว">
                            <label for="gps-queue-date" class="visually-hidden">วันที่คิว FormDP</label>
                            <input id="gps-queue-date" type="date" name="date" value="{{ $queueDate }}" max="{{ now('Asia/Bangkok')->toDateString() }}" class="form-control form-control-sm" onchange="this.form.submit()">
                        </form>
                    </div>
                    <div class="gps-filter-chips" role="group" aria-label="กรองรายการรถ">
                        <button type="button" class="gps-chip is-active" data-filter="all">ทั้งหมด</button>
                        <button type="button" class="gps-chip" data-filter="gps">มี GPS</button>
                        <button type="button" class="gps-chip" data-filter="queue">มีคิว</button>
                        <button type="button" class="gps-chip" data-filter="issue">ต้องตรวจ</button>
                    </div>
                    @unless($isToday)
                        <span class="gps-past-note"><i class="fas fa-circle-info"></i> ตำแหน่งบนแผนที่เป็นปัจจุบัน · คลิกรถเพื่อดูเส้นทางของวันที่ {{ $queueDate }}</span>
                    @endunless
                </div>
                <div id="gps-vehicle-list" class="gps-vehicle-list"></div>
            </aside>
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}?v={{ filemtime(public_path('vendor/leaflet/leaflet.js')) }}"></script>
    <script src="{{ asset('js/formdp/gps-trip-tracking.js') }}?v={{ filemtime(public_path('js/formdp/gps-trip-tracking.js')) }}"></script>
@endpush
