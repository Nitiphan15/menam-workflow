@extends('layouts.layout')

@section('title', 'พิกัดลูกค้า (GPS)')
@section('page-title', 'พิกัดลูกค้า (GPS)')

@push('styles')
    <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}?v={{ filemtime(public_path('vendor/leaflet/leaflet.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/formdp/gps-locations.css') }}?v={{ filemtime(public_path('css/formdp/gps-locations.css')) }}">
@endpush

@section('content')
    @php
        $statusCount = collect($customers)->countBy('status');
    @endphp
    <div id="gps-locations" class="gloc-shell"
         data-customers='@json($customers)'
         data-can-edit="{{ $canEdit ? '1' : '0' }}"
         data-default-radius="{{ $defaultRadius }}"
         data-show-url="{{ route('dp.gps-locations.show', ['company' => '__COMPANY__', 'customerId' => '__CUSTOMER__']) }}"
         data-store-url="{{ route('dp.gps-locations.store') }}"
         data-deactivate-url="{{ route('dp.gps-locations.deactivate', ['id' => '__ID__']) }}"
         data-import-url="{{ route('dp.gps-locations.import-poi') }}"
         data-schema-url="{{ route('dp.gps-locations.refresh-schema') }}">

        @unless ($tablesReady)
            <section class="gloc-setup" role="alert">
                <strong><i class="fas fa-database me-1"></i> ยังไม่ได้สร้างตารางพิกัด</strong>
                <span>ให้ผู้ดูแลฐานข้อมูลรันไฟล์ <code>database/sql/formdp_gps_phase1_locations.sql</code> บนฐาน menam_workflow (DEV ก่อน แล้วจึง PROD) จากนั้นกดตรวจอีกครั้ง</span>
                @if ($canEdit)
                    <button type="button" class="btn btn-outline-primary btn-sm" id="gloc-schema-check"><i class="fas fa-rotate me-1"></i> ตรวจอีกครั้ง</button>
                @endif
            </section>
        @endunless

        <section class="gloc-summary" aria-label="สรุปพิกัดลูกค้า">
            <div><span>ลูกค้าที่ส่งใน 90 วัน</span><strong>{{ number_format(count($customers)) }}</strong></div>
            <div><span>ปักพิกัดแล้ว</span><strong class="is-ok">{{ number_format($statusCount['pinned'] ?? 0) }}</strong></div>
            <div><span>มีจุด DTC ที่ชื่อคล้าย</span><strong class="is-warn">{{ number_format($statusCount['suggested'] ?? 0) }}</strong></div>
            <div><span>ยังไม่มีพิกัด</span><strong class="is-bad">{{ number_format($statusCount['missing'] ?? 0) }}</strong></div>
            <div class="gloc-poi">
                <span>จุดจาก DTC (POI)</span>
                <strong>{{ number_format($poiStats['count']) }}</strong>
                <small>{{ $poiStats['synced_at'] ? 'นำเข้าล่าสุด ' . substr((string) $poiStats['synced_at'], 0, 16) : 'ยังไม่เคยนำเข้า' }}</small>
                @if ($canEdit && $tablesReady)
                    <button type="button" class="btn btn-outline-success btn-sm" id="gloc-import" title="ดึงจุดที่บันทึกไว้ในระบบ DTC (1 request)"><i class="fas fa-cloud-arrow-down me-1"></i> นำเข้าจาก DTC</button>
                @endif
            </div>
        </section>
        <div id="gloc-flash" class="gloc-flash" aria-live="polite" hidden></div>

        <section class="gloc-workspace">
            <aside class="gloc-list-panel" aria-label="รายชื่อลูกค้า">
                <div class="gloc-list-head">
                    <input type="search" id="gloc-search" class="form-control form-control-sm" placeholder="ค้นหาชื่อลูกค้า / รหัส">
                    <div class="gloc-chips" role="group" aria-label="กรองสถานะ">
                        <button type="button" class="gloc-chip is-active" data-status="all">ทั้งหมด</button>
                        <button type="button" class="gloc-chip" data-status="missing">ยังไม่มี</button>
                        <button type="button" class="gloc-chip" data-status="suggested">มีคำแนะนำ</button>
                        <button type="button" class="gloc-chip" data-status="pinned">ปักแล้ว</button>
                    </div>
                </div>
                <div id="gloc-list" class="gloc-list"></div>
            </aside>

            <article class="gloc-editor" id="gloc-editor" aria-live="polite">
                <div class="gloc-empty" id="gloc-empty"><i class="fas fa-map-location-dot fa-2x mb-2"></i><br>เลือกลูกค้าทางซ้ายเพื่อดูหรือปักพิกัด</div>
                <div id="gloc-detail" hidden>
                    <header class="gloc-detail-head">
                        <div><h2 id="gloc-name">-</h2><span id="gloc-meta">-</span></div>
                    </header>
                    <div id="gloc-map" class="gloc-map" role="application" aria-label="แผนที่ปักพิกัดลูกค้า"></div>
                    <div class="gloc-hint">{{ $canEdit ? 'คลิกบนแผนที่หรือลากหมุดเพื่อกำหนดตำแหน่ง · วงกลมคือรัศมีที่ถือว่า "ถึงลูกค้า"' : 'ดูอย่างเดียว · ต้องมีสิทธิ์ DPA เพื่อแก้ไข' }}</div>

                    <section class="gloc-block" id="gloc-suggest-block">
                        <h3>จุดจาก DTC ที่ชื่อคล้ายกัน</h3>
                        <div id="gloc-suggestions" class="gloc-suggestions"></div>
                    </section>

                    @if ($canEdit)
                        <section class="gloc-block">
                            <h3>ปักพิกัด</h3>
                            <form id="gloc-form" class="gloc-form" novalidate>
                                <label class="gloc-span2">ที่อยู่
                                    <select id="gloc-address" class="form-select form-select-sm"></select>
                                </label>
                                <label class="gloc-span2">วางลิงก์ Google Maps หรือพิกัด "lat, lon"
                                    <span class="gloc-inline"><input id="gloc-paste" class="form-control form-control-sm" placeholder="https://maps.google.com/...@13.75,100.50 หรือ 13.75, 100.50"><button type="button" class="btn btn-outline-secondary btn-sm" id="gloc-paste-apply">ใช้พิกัดนี้</button></span>
                                </label>
                                <label>Latitude<input id="gloc-lat" class="form-control form-control-sm" inputmode="decimal" required></label>
                                <label>Longitude<input id="gloc-lon" class="form-control form-control-sm" inputmode="decimal" required></label>
                                <label>รัศมี (เมตร)<input id="gloc-radius" type="number" min="20" max="2000" step="10" class="form-control form-control-sm" value="{{ $defaultRadius }}" required></label>
                                <label>ชื่อจุด (ถ้ามี)<input id="gloc-label" class="form-control form-control-sm" maxlength="255"></label>
                                <div class="gloc-span2 gloc-form-foot">
                                    <span id="gloc-source" class="gloc-source">ที่มา: ปักเอง</span>
                                    <button type="submit" class="btn btn-primary btn-sm" id="gloc-save"><i class="fas fa-floppy-disk me-1"></i> บันทึกพิกัด</button>
                                </div>
                            </form>
                        </section>
                    @endif

                    <section class="gloc-block">
                        <h3>พิกัดที่บันทึกแล้ว</h3>
                        <div id="gloc-saved" class="gloc-saved"></div>
                    </section>
                </div>
            </article>
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}?v={{ filemtime(public_path('vendor/leaflet/leaflet.js')) }}"></script>
    <script src="{{ asset('js/formdp/gps-locations.js') }}?v={{ filemtime(public_path('js/formdp/gps-locations.js')) }}"></script>
@endpush
