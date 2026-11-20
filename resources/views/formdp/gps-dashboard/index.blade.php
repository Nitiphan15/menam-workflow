@extends('layouts.layout')

@section('title', 'GPS Dashboard (วางแผน)')
@section('page-title', 'GPS Dashboard (วางแผน)')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/formdp/gps-dashboard.css') }}?v={{ filemtime(public_path('css/formdp/gps-dashboard.css')) }}">
@endpush

@php
    $statusLabel = [
        'visited' => 'เข้าจุด', 'at_stop' => 'อยู่ที่จุด', 'passed' => 'ผ่านไม่จอด',
        'not_visited' => 'ไม่ได้เข้า', 'no_location' => 'ไม่มีพิกัด', 'no_route' => 'ไม่มีเส้นทาง',
    ];
    $stopClass = function (array $stop) {
        if (in_array($stop['status'], ['visited', 'at_stop'], true)) {
            return $stop['is_late'] ? 'is-late' : 'is-ok';
        }

        return in_array($stop['status'], ['not_visited', 'passed'], true) ? 'is-miss' : 'is-none';
    };
    $fmtPercent = fn ($value) => $value === null ? '-' : number_format($value, 1) . '%';
@endphp

@section('content')
    <div id="gps-dashboard" class="gdash"
         data-refresh-url="{{ route('dp.gps-dashboard.refresh') }}"
         data-date="{{ $date }}"
         data-weeks='@json($trends['weeks'] ?? [])'
         data-by-truck='@json($trends['by_truck'] ?? [])'>

        @unless ($tablesReady)
            <section class="gdash-setup" role="alert">
                <strong><i class="fas fa-database me-1"></i> ยังไม่ได้สร้างตารางสรุป GPS</strong>
                <span>ให้ผู้ดูแลฐานข้อมูลรัน <code>database/sql/formdp_gps_dashboard.sql</code> บนฐาน menam_workflow (DEV ก่อน แล้วจึง PROD) — หลังจากนั้นระบบจะสรุปข้อมูลของเมื่อวานทุกคืนเวลา 00:30</span>
            </section>
        @else
            {{-- ---------- Day board ---------- --}}
            <section class="gdash-head">
                <form method="get" class="gdash-filter">
                    <label>วันที่ส่ง <input type="date" name="date" value="{{ $date }}" class="form-control form-control-sm" max="{{ now('Asia/Bangkok')->toDateString() }}"></label>
                    <input type="hidden" name="weeks" value="{{ $weeks }}">
                    <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-magnifying-glass me-1"></i> ดู</button>
                </form>
                <div class="gdash-rules">
                    <span><b>ถึงช้า</b> = เข้าหมุดลูกค้าหลังเวลา "ช่วงเวลารับส่ง" ของรายการ{{ $graceMinutes ? " (+{$graceMinutes} นาที)" : '' }}</span>
                    <span><b>กลับช้า</b> = กลับเข้าบริษัทหลัง {{ $lateReturnTime }} หรือไม่กลับภายในวัน</span>
                </div>
                @if ($canRefresh)
                    <button type="button" class="btn btn-outline-primary btn-sm" id="gdash-refresh" title="สรุปวันที่เลือกใหม่จาก GPS (ใช้ DTC สูงสุด 3 request ต่อครั้ง)"><i class="fas fa-rotate me-1"></i> สรุปวันที่นี้ใหม่</button>
                @endif
            </section>
            <div id="gdash-flash" class="gdash-flash" hidden aria-live="polite"></div>

            @php $s = $board['summary']; @endphp
            <section class="gdash-cards" aria-label="สรุปวันที่ {{ $date }}">
                <div><span>รถออกส่ง</span><strong>{{ $s['trucks'] }}</strong><small>มีเส้นทาง GPS {{ $s['trucks_with_route'] }} คัน</small></div>
                <div class="is-ok"><span>ถึงตรงเวลา</span><strong>{{ $fmtPercent($s['on_time_percent']) }}</strong><small>{{ $s['stops_on_time'] }} จุด</small></div>
                <div class="is-late"><span>ถึงช้า</span><strong>{{ $s['stops_late'] }}</strong><small>จุดส่ง</small></div>
                <div class="is-miss"><span>ไม่ได้เข้าจุด</span><strong>{{ $s['stops_not_visited'] }}</strong><small>มีหมุดแต่ GPS ไม่เข้า</small></div>
                <div class="is-late"><span>กลับช้า</span><strong>{{ $s['late_returns'] }}</strong><small>คัน</small></div>
                <div><span>จุดที่มีหมุด</span><strong>{{ $s['stops_with_pin'] }}/{{ $s['stops_planned'] }}</strong><small>จุดไม่มีหมุดไม่นับช้า</small></div>
            </section>

            <section class="gdash-board">
                @if ($board['trucks'] === [])
                    <div class="gdash-empty">ยังไม่มีข้อมูลสรุปของวันที่ {{ $date }} — ระบบสรุปทุกคืนเวลา 00:30{{ $canRefresh ? ' หรือกด "สรุปวันที่นี้ใหม่"' : '' }}</div>
                @else
                    <table class="table table-sm align-middle gdash-table">
                        <thead>
                            <tr><th>รถ / คนขับ</th><th>ออก</th><th>จุดส่ง (เวลาถึง · ช่วงเวลารับส่ง)</th><th>กลับ</th><th class="text-end">กม.</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($board['trucks'] as $truck)
                                <tr class="{{ $truck['late_return'] || collect($truck['stops'])->contains('is_late', true) ? 'has-issue' : '' }}">
                                    <td><b>{{ $truck['plate_no'] }}</b><small>{{ $truck['driver_name'] ?: '-' }} · เที่ยว {{ $truck['trip_nos'] }}</small>
                                        @if ($truck['note'])<small class="gdash-note">{{ $truck['note'] }}</small>@endif</td>
                                    <td>{{ $truck['left_company_at'] ?? '-' }}</td>
                                    <td>
                                        <div class="gdash-stops">
                                            @foreach ($truck['stops'] as $stop)
                                                <span class="gdash-stop {{ $stopClass($stop) }}" title="{{ $stop['customer_name'] }}{{ $stop['dwell_minutes'] !== null ? ' · จอด ' . $stop['dwell_minutes'] . ' นาที' : '' }}{{ $stop['left_at'] ? ' · ออก ' . $stop['left_at'] : '' }}">
                                                    <b>{{ $stop['stop_no'] }}. {{ \Illuminate\Support\Str::limit($stop['customer_name'], 26) }}</b>
                                                    <small>
                                                        {{ $stop['arrived_at'] ?? ($statusLabel[$stop['status']] ?? $stop['status']) }}
                                                        @if ($stop['window_time']) · นัด {{ $stop['window_time'] }} @endif
                                                        @if ($stop['is_late']) · ช้า {{ $stop['late_minutes'] }} นาที @endif
                                                    </small>
                                                </span>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td class="{{ $truck['late_return'] ? 'gdash-late' : '' }}">{{ $truck['returned_at'] ?? '-' }}@if ($truck['late_return'])<small>กลับช้า</small>@endif</td>
                                    <td class="text-end">{{ $truck['km'] !== null ? number_format($truck['km'], 1) : '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="gdash-legend"><span class="gdash-stop is-ok">ตรงเวลา</span><span class="gdash-stop is-late">ถึงช้า</span><span class="gdash-stop is-miss">ไม่ได้เข้า / ผ่านไม่จอด</span><span class="gdash-stop is-none">ไม่มีหมุด / ไม่มีเส้นทาง</span></div>
                @endif
            </section>

            {{-- ---------- Trends ---------- --}}
            <section class="gdash-trends">
                <div class="gdash-trends-head">
                    <h2>แนวโน้มย้อนหลัง</h2>
                    <form method="get" class="gdash-filter">
                        <input type="hidden" name="date" value="{{ $date }}">
                        <select name="weeks" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="ช่วงเวลา">
                            @foreach ($weekOptions as $option)
                                <option value="{{ $option }}" @selected($option === $weeks)>{{ $option }} สัปดาห์</option>
                            @endforeach
                        </select>
                    </form>
                </div>
                @php $t = $trends['summary']; @endphp
                <div class="gdash-cards is-compact">
                    <div><span>รถ-วัน</span><strong>{{ $t['truck_days'] }}</strong></div>
                    <div class="is-ok"><span>ถึงตรงเวลา</span><strong>{{ $fmtPercent($t['on_time_percent']) }}</strong></div>
                    <div class="is-late"><span>ถึงช้า</span><strong>{{ $t['late_arrivals'] }}</strong></div>
                    <div class="is-late"><span>กลับช้า</span><strong>{{ $t['late_returns'] }}</strong></div>
                    <div class="is-miss"><span>ไม่ได้เข้าจุด</span><strong>{{ $t['not_visited'] }}</strong></div>
                </div>
                @if ($trends['weeks'] === [])
                    <div class="gdash-empty">ยังไม่มีข้อมูลในช่วงนี้</div>
                @else
                    <div class="gdash-charts">
                        <figure><figcaption>% ถึงตรงเวลา และจำนวนที่ช้า รายสัปดาห์</figcaption><div class="gdash-chart"><canvas id="gdash-week-chart" aria-label="กราฟรายสัปดาห์"></canvas></div></figure>
                        <figure><figcaption>ถึงช้า / กลับช้า แยกตามรถ</figcaption><div class="gdash-chart"><canvas id="gdash-truck-chart" aria-label="กราฟแยกตามรถ"></canvas></div></figure>
                    </div>
                    <h3 class="gdash-sub">ลูกค้าที่รถถึงช้าบ่อย</h3>
                    @if ($trends['late_customers'] === [])
                        <div class="gdash-empty">ไม่มีลูกค้าที่ถึงช้าในช่วงนี้</div>
                    @else
                        <table class="table table-sm gdash-table">
                            <thead><tr><th>ลูกค้า</th><th class="text-end">ครั้งที่ไปถึง</th><th class="text-end">ถึงช้า</th><th class="text-end">จอดเฉลี่ย (นาที)</th></tr></thead>
                            <tbody>
                                @foreach ($trends['late_customers'] as $customer)
                                    <tr><td>{{ $customer['customer_name'] }}</td><td class="text-end">{{ $customer['visits'] }}</td><td class="text-end gdash-late">{{ $customer['late_count'] }}</td><td class="text-end">{{ $customer['avg_dwell'] ?? '-' }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                @endif
            </section>
        @endunless
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/formdp/gps-dashboard.js') }}?v={{ filemtime(public_path('js/formdp/gps-dashboard.js')) }}"></script>
@endpush
