@extends('layouts.layout')

@section('title', 'GPS Trip Tracking')
@section('page-title', 'GPS Trip Tracking')

@push('styles')
    <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}?v={{ filemtime(public_path('vendor/leaflet/leaflet.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/formdp/gps-trip-tracking.css') }}?v={{ filemtime(public_path('css/formdp/gps-trip-tracking.css')) }}">
@endpush

@section('content')
    <div id="gps-trip-tracking" class="gps-demo-shell" data-vehicles='@json($demoVehicles)' data-dtc-mock='@json($dtcMockSnapshot)' data-dtc-matches='@json($dtcMatchReport)' data-last-success="{{ $lastSuccessfulSync }}" data-queue-date="{{ $queueDate }}">
        <section class="gps-source-console" aria-label="สถานะแหล่งข้อมูล GPS">
            <div class="gps-source-signal"><span id="gps-source-dot" class="gps-source-dot is-demo"></span><span id="gps-source-badge" class="gps-source-badge">DEMO</span></div>
            <div class="gps-source-copy">
                <div id="gps-source-title" class="gps-source-title">กำลังใช้ตำแหน่งจำลอง</div>
                <div id="gps-source-note" class="gps-source-note">คิวและรายการส่งมาจาก FormDP จริง · ตำแหน่งรถจำลองในรูปแบบเดียวกับ DTC</div>
                <div class="gps-source-facts">
                    <span id="gps-source-freshness"><i class="fas fa-clock"></i> กำลังอ่านเวลาล่าสุด</span>
                    <span id="gps-last-success"><i class="fas fa-cloud-arrow-down"></i> ยังไม่เคยเชื่อมต่อสำเร็จ</span>
                    <span id="gps-match-summary"><i class="fas fa-link"></i> จับคู่ {{ $dtcMatchReport['matched_count'] }}/{{ $dtcMatchReport['dtc_count'] }} คัน</span>
                    <span><i class="fas fa-rotate"></i> Live sync ทุก {{ $dtcSyncMinutes }} นาทีเมื่อเปิดใช้งาน</span>
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
                    <button type="button" id="gps-dtc-test" class="btn btn-outline-success btn-sm" data-url="{{ route('dp.gps-trip-tracking.test-dtc') }}">
                        <i class="fas fa-plug me-1"></i> ทดสอบ DTC API
                    </button>
                    <button type="button" id="gps-dtc-refresh-master" class="btn btn-outline-secondary btn-sm" data-url="{{ route('dp.gps-trip-tracking.test-dtc') }}?refresh_master=1" title="ดึงรายชื่อรถจาก DTC ใหม่ (ใช้ 2 requests)">
                        <i class="fas fa-arrows-rotate me-1"></i> รีเฟรชรายชื่อรถ
                    </button>
                @endcan
            </div>
        </section>
        <section id="gps-mapping-panel" class="gps-mapping-panel" hidden>
            <header><div><strong>การจับคู่รถ DTC กับคิว FormDP</strong><span>ใช้ทะเบียนจาก Vehicle Master เป็นหลัก และแสดงรถที่ขาดจากทั้งสองฝั่ง</span></div><span id="gps-mapping-count" class="gps-match-pill">{{ $dtcMatchReport['matched_count'] }} จับคู่แล้ว</span></header>
            <div class="table-responsive"><table class="table gps-mapping-table"><thead><tr><th>DTC GPS ID</th><th>ทะเบียน Vehicle Master</th><th>ทะเบียน FormDP</th><th>เที่ยว/คนขับ</th><th>ข้อมูลล่าสุด</th><th>ผล</th></tr></thead><tbody id="gps-mapping-rows"></tbody></table></div>
        </section>
        <section class="gps-demo-toolbar" aria-label="ตัวควบคุม Simulation">
            <span id="gps-data-badge" class="gps-demo-badge"><span class="gps-live-dot"></span> <span id="gps-data-badge-text">DEMO DATA</span></span>
            <div class="gps-toolbar-copy"><div id="gps-toolbar-title" class="gps-toolbar-title">Simulation สำหรับทดสอบหน้าจอ</div><div id="gps-toolbar-note" class="gps-toolbar-note">ตำแหน่งรถบนแผนที่เป็นข้อมูลจำลอง · หยุดหรือเริ่มการเคลื่อนที่ได้ โดยไม่เรียก DTC API</div></div>
            <div class="gps-toolbar-actions">
                <button type="button" id="gps-simulation-toggle" class="btn btn-primary btn-sm" aria-pressed="false">
                    <i class="fas fa-play me-1"></i> เริ่ม Simulation
                </button>
                <button type="button" id="gps-simulation-reset" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-rotate-left me-1"></i> เริ่มใหม่
                </button>
            </div>
        </section>
        @can('ADMINWEB')
            <section id="gps-dtc-test-result" class="gps-dtc-result" aria-live="polite" hidden></section>
        @endcan

        <nav class="gps-view-tabs" aria-label="มุมมอง GPS Trip Tracking">
            <button type="button" class="gps-view-tab is-active" data-gps-view="live" aria-selected="true"><i class="fas fa-location-dot"></i><span>ติดตามวันนี้</span></button>
            <button type="button" class="gps-view-tab" data-gps-view="history" aria-selected="false"><i class="fas fa-clock-rotate-left"></i><span>ย้อนหลัง</span></button>
            <button type="button" class="gps-view-tab" data-gps-view="reports" aria-selected="false"><i class="fas fa-chart-column"></i><span>รายงาน</span></button>
        </nav>

        <div class="gps-view-panel is-active" data-gps-panel="live">
        <section class="gps-kpis" aria-label="ภาพรวมสถานะรถ">
            <div class="gps-kpi"><div class="gps-kpi-label">กำลังเดินทาง</div><div id="gps-moving-count" class="gps-kpi-value">0</div></div>
            <div class="gps-kpi"><div class="gps-kpi-label">อยู่บริษัท</div><div id="gps-company-count" class="gps-kpi-value">0</div></div>
            <div class="gps-kpi"><div class="gps-kpi-label">ผิดเวลา</div><div id="gps-delayed-count" class="gps-kpi-value is-danger">0</div></div>
            <div class="gps-kpi"><div class="gps-kpi-label">อยู่จุดส่ง</div><div id="gps-destination-count" class="gps-kpi-value">0</div></div>
        </section>

        <section class="gps-workspace" aria-label="แผนที่และรายการรถ">
            <div class="gps-map-stage">
                <div id="gps-demo-map" role="application" aria-label="แผนที่ตำแหน่งรถจำลอง"></div>
                <button type="button" id="gps-view-all" class="gps-map-action"><i class="fas fa-expand me-1"></i> ดูรถทั้งหมด</button>
            </div>
            <aside class="gps-vehicle-panel">
                <div class="gps-panel-head">
                    <strong>รถในเที่ยววันนี้</strong>
                    <span>คลิกรถเพื่อดู Timeline · อัปเดต <span id="gps-updated-at">-</span></span>
                </div>
                <div id="gps-vehicle-list" class="gps-vehicle-list"></div>
            </aside>
        </section>

        <section class="gps-events" aria-label="เหตุการณ์ล่าสุด">
            <div class="gps-events-head"><strong>เหตุการณ์ที่ต้องตรวจสอบ</strong><span>เวลาในตัวอย่างเป็นข้อมูลจำลอง</span></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light"><tr><th>เวลา</th><th>รถ</th><th>เที่ยว</th><th>เหตุการณ์</th><th>เทียบแผน</th><th>สถานะ</th></tr></thead>
                    <tbody>
                        <tr><td>08:47</td><td>2ฒถ-5450</td><td>DP-691113-02</td><td>ออกจากบริษัท</td><td class="text-danger fw-semibold">ช้า 17 นาที</td><td><span class="badge text-bg-danger">ออกช้า</span></td></tr>
                        <tr><td>10:36</td><td>83-5505</td><td>DP-691113-04</td><td>ออกจากปลายทาง</td><td class="text-warning fw-semibold">ก่อนแผน 24 นาที</td><td><span class="badge text-bg-warning">กลับก่อน</span></td></tr>
                        <tr><td>10:42</td><td>1ฒณ-1307</td><td>DP-691113-01</td><td>ถึงปลายทาง</td><td class="text-success fw-semibold">ตรงเวลา</td><td><span class="badge text-bg-success">ปกติ</span></td></tr>
                    </tbody>
                </table>
            </div>
        </section>
        </div>

        <div class="gps-view-panel" data-gps-panel="history" hidden>
            <section class="gps-filter-bar" aria-label="ตัวกรองข้อมูลย้อนหลัง">
                <div><label for="gps-history-date">วันที่</label><input id="gps-history-date" type="date" class="form-control" value="{{ $queueDate }}"></div>
                <div><label for="gps-history-vehicle">ทะเบียนรถ</label><select id="gps-history-vehicle" class="form-select"><option value="all">รถทั้งหมด</option>@foreach($demoVehicles as $vehicle)<option value="{{ $vehicle['gps_id'] }}">{{ $vehicle['plate_no'] }}</option>@endforeach</select></div>
                <div class="gps-filter-summary"><span>ข้อมูลที่แสดง</span><strong id="gps-history-result-count">{{ count($demoVehicles) }} เที่ยว</strong></div>
            </section>

            <section class="gps-history-layout">
                <div class="gps-history-list" id="gps-history-list" aria-label="รายการเที่ยวย้อนหลัง"></div>
                <article class="gps-history-detail" id="gps-history-detail" aria-live="polite"></article>
            </section>
        </div>

        <div class="gps-view-panel" data-gps-panel="reports" hidden>
            <section class="gps-report-hero">
                <div><span class="gps-section-kicker">สรุปการจัดส่ง</span><h2>เห็นปัญหาก่อนดูรายละเอียดรายเที่ยว</h2><p>รวมข้อมูลคิว FormDP เวลา GPS และเหตุการณ์จากคนขับในมุมเดียว</p></div>
                <div class="gps-report-period"><span>ช่วงรายงาน</span><strong>{{ $reportDate }}</strong></div>
            </section>

            <section class="gps-report-kpis" aria-label="ตัวชี้วัดการจัดส่ง">
                <div><span>เที่ยวทั้งหมด</span><strong>{{ count($demoVehicles) }}</strong><small>จากคิวที่จับคู่ทะเบียนได้</small></div>
                <div><span>ออกตรงเวลา</span><strong>{{ max(count($demoVehicles) - 1, 0) }}</strong><small>คลาดเคลื่อนไม่เกิน 10 นาที</small></div>
                <div><span>ออกช้า</span><strong class="is-danger">1</strong><small>ต้องตรวจสอบสาเหตุ</small></div>
                <div><span>หลักฐานไม่ครบ</span><strong class="is-warning">1</strong><small>รอ Planner ตรวจย้อนหลัง</small></div>
            </section>

            <section class="gps-report-grid">
                <article class="gps-report-block">
                    <header><div><span class="gps-section-kicker">ประสิทธิภาพรายรถ</span><h3>เวลาออกและเวลารวม</h3></div><span class="gps-demo-label">DEMO DATA</span></header>
                    <div class="table-responsive"><table class="table gps-report-table"><thead><tr><th>รถ</th><th>คนขับ</th><th>ออกตามแผน</th><th>ออกจริง</th><th>ผล</th><th>คาดกลับ</th></tr></thead><tbody id="gps-report-rows"></tbody></table></div>
                </article>
                <aside class="gps-report-block gps-alert-summary">
                    <header><div><span class="gps-section-kicker">เรื่องที่ต้องติดตาม</span><h3>Exception summary</h3></div></header>
                    <div class="gps-alert-line"><span class="gps-alert-dot is-danger"></span><span><strong>ออกช้า 1 เที่ยว</strong><small>2ฒถ5450 · ช้า 17 นาที</small></span></div>
                    <div class="gps-alert-line"><span class="gps-alert-dot is-warning"></span><span><strong>กลับก่อนแผน 1 เที่ยว</strong><small>83-5505 · ก่อนแผน 24 นาที</small></span></div>
                    <div class="gps-alert-line"><span class="gps-alert-dot"></span><span><strong>หลักฐานไม่ครบ 1 จุด</strong><small>รอ Planner ตรวจเหตุผลจาก Telegram</small></span></div>
                </aside>
            </section>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}?v={{ filemtime(public_path('vendor/leaflet/leaflet.js')) }}"></script>
    <script src="{{ asset('js/formdp/gps-trip-tracking.js') }}?v={{ filemtime(public_path('js/formdp/gps-trip-tracking.js')) }}"></script>
@endpush
