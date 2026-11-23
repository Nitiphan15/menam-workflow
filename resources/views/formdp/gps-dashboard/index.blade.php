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
    $fmtMinutes = fn ($value) => $value === null ? '-' : ($value >= 60 ? intdiv((int) $value, 60) . ' ชม. ' . ((int) $value % 60) . ' นาที' : $value . ' นาที');
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
                    <div class="gdash-refresh-group">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="gdash-refresh" title="สรุปวันที่เลือกใหม่จาก GPS (ใช้ DTC สูงสุด 3 request ต่อครั้ง)"><i class="fas fa-rotate me-1"></i> สรุปวันที่นี้ใหม่</button>
                        <small>DTC เดือนนี้ {{ number_format($dtcUsage['month_total']) }}/{{ number_format($dtcUsage['quota']) }} · นาทีนี้ {{ $dtcUsage['minute_used'] }}/{{ $dtcUsage['per_minute_limit'] }}</small>
                    </div>
                @endif
            </section>
            <div id="gdash-flash" class="gdash-flash" hidden aria-live="polite"></div>

            @php
                $s = $board['summary'];
                $evaluatedStops = $s['stops_on_time'] + $s['stops_late'];
                $coveragePercent = $s['stops_planned'] > 0 ? round($s['stops_with_pin'] * 100 / $s['stops_planned']) : null;
                $coverageLow = $coveragePercent !== null && $coveragePercent < 80;
                $latestComputedAt = collect($board['trucks'])->pluck('computed_at')->filter()->max();
                $latestLabel = $latestComputedAt ? \Carbon\Carbon::parse($latestComputedAt, 'Asia/Bangkok')->format('d/m/Y H:i') : '-';
            @endphp
            <section class="gdash-quality {{ $coverageLow ? 'is-low' : 'is-ready' }}" role="status">
                <div><strong>คุณภาพข้อมูล: ประเมินได้ {{ $coveragePercent === null ? '-' : number_format($coveragePercent, 0) . '%' }}</strong>
                    <span>{{ $s['stops_with_pin'] }}/{{ $s['stops_planned'] }} จุดมีพิกัด{{ $coverageLow ? ' — ยังไม่ควรใช้ตัดสินผลงาน' : ' — พร้อมใช้ประกอบการติดตาม' }}</span></div>
                <small><i class="far fa-clock me-1"></i>สรุปล่าสุด {{ $latestLabel }}</small>
            </section>
            <section class="gdash-cards" aria-label="สรุปวันที่ {{ $date }}">
                <div><span>รถออกส่ง</span><strong>{{ $s['trucks'] }}</strong><small>มีเส้นทาง GPS {{ $s['trucks_with_route'] }} คัน</small></div>
                <div class="{{ $coverageLow ? 'is-caution' : 'is-ok' }}"><span>ถึงตรงเวลา</span><strong>{{ $fmtPercent($s['on_time_percent']) }}</strong><small>จาก {{ $evaluatedStops }} จุดที่ประเมินได้</small></div>
                <div class="is-late"><span>ถึงช้า</span><strong>{{ $s['stops_late'] }}</strong><small>จุดส่ง</small></div>
                <div class="is-miss"><span>ไม่ได้เข้าจุด</span><strong>{{ $s['stops_not_visited'] }}</strong><small>มีหมุดแต่ GPS ไม่เข้า</small></div>
                <div class="is-late"><span>กลับช้า</span><strong>{{ $s['late_returns'] }}</strong><small>คัน</small></div>
                <div><span>จุดที่มีหมุด</span><strong>{{ $s['stops_with_pin'] }}/{{ $s['stops_planned'] }}</strong><small>จุดไม่มีหมุดไม่นับช้า</small></div>
                @if (!empty($s['timeline_ready']))
                    <div class="is-unplanned"><span>จอดนอกแผน</span><strong>{{ $s['unplanned_count'] }}</strong><small>รวม {{ number_format($s['unplanned_minutes']) }} นาที (จอด 10 นาทีขึ้นไป){{ $s['unknown_count'] ? ' · ยังระบุไม่ได้ ' . $s['unknown_count'] . ' จุด (ลูกค้ายังไม่มีหมุด)' : '' }}</small></div>
                @endif
            </section>

            <section class="gdash-board">
                @if ($board['trucks'] === [])
                    <div class="gdash-empty">ยังไม่มีข้อมูลสรุปของวันที่ {{ $date }} — ระบบสรุปทุกคืนเวลา 00:30{{ $canRefresh ? ' หรือกด "สรุปวันที่นี้ใหม่"' : '' }}</div>
                @else
                    <div class="gdash-board-tools" aria-label="กรองรายการรถ">
                        <div class="gdash-filter-chips" role="group" aria-label="สถานะรถ">
                            <button type="button" class="is-active" data-gdash-filter="all" aria-pressed="true">ทั้งหมด</button>
                            <button type="button" data-gdash-filter="late" aria-pressed="false">ช้า</button>
                            <button type="button" data-gdash-filter="no-pin" aria-pressed="false">ไม่มีหมุด</button>
                            <button type="button" data-gdash-filter="no-return" aria-pressed="false">ไม่กลับ</button>
                            <button type="button" data-gdash-filter="unplanned" aria-pressed="false">จอดนอกแผน</button>
                        </div>
                        <label><input type="checkbox" id="gdash-hide-normal"> ซ่อนรถปกติ</label>
                        <small id="gdash-filter-count">แสดง {{ count($board['trucks']) }} คัน</small>
                    </div>
                    <table class="table table-sm align-middle gdash-table">
                        <thead>
                            <tr><th>รถ / คนขับ</th><th>ออก</th><th>จุดส่ง (เวลาถึง · ช่วงเวลารับส่ง)</th><th>กลับ</th><th class="text-end">กม.</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($board['trucks'] as $truck)
                                @php
                                    $hasLate = $truck['late_return'] || collect($truck['stops'])->contains('is_late', true);
                                    $hasNoPin = collect($truck['stops'])->contains('status', 'no_location');
                                    $hasNoReturn = $truck['late_return'] && !$truck['returned_at'];
                                    $hasUnplanned = collect($truck['unplanned'])->contains('kind', 'unplanned');
                                @endphp
                                <tr class="{{ $hasLate ? 'has-issue' : '' }}" data-late="{{ $hasLate ? 1 : 0 }}" data-no-pin="{{ $hasNoPin ? 1 : 0 }}" data-no-return="{{ $hasNoReturn ? 1 : 0 }}" data-unplanned="{{ $hasUnplanned ? 1 : 0 }}" data-issue="{{ ($hasLate || $hasNoPin || $hasNoReturn || $hasUnplanned) ? 1 : 0 }}">
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
                                        @if (!empty($truck['unplanned']))
                                            <div class="gdash-stops gdash-unplanned" aria-label="จอดนอกแผน">
                                                @foreach ($truck['unplanned'] as $stay)
                                                    <a class="gdash-stop {{ $stay['kind'] === 'unknown' ? 'is-unknown' : 'is-unplanned' }}" href="https://www.google.com/maps/search/?api=1&query={{ number_format($stay['lat'], 6, '.', '') }},{{ number_format($stay['lon'], 6, '.', '') }}" target="_blank" rel="noopener noreferrer" title="เปิดใน Google Maps{{ $stay['place_detail'] ? ' · ' . $stay['place_detail'] : '' }}">
                                                        <b><i class="fas fa-circle-pause"></i> {{ $stay['arrived_at'] }}–{{ $stay['left_at'] }} · {{ $stay['minutes'] }} นาที</b>
                                                        <small>{{ $stay['kind'] === 'unknown' ? 'ยังระบุไม่ได้ · ' : '' }}{{ \Illuminate\Support\Str::limit($stay['place_label'] ?: 'ไม่ทราบชื่อสถานที่', 34) }}</small>
                                                    </a>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                    <td class="{{ $truck['late_return'] ? 'gdash-late' : '' }}">{{ $truck['returned_at'] ?? '-' }}@if ($truck['late_return'])<small>กลับช้า</small>@endif</td>
                                    <td class="text-end">{{ $truck['km'] !== null ? number_format($truck['km'], 1) : '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="gdash-empty" id="gdash-filter-empty" hidden>ไม่พบรถตามตัวกรองนี้</div>
                    <div class="gdash-legend"><span class="gdash-stop is-ok">ตรงเวลา</span><span class="gdash-stop is-late">ถึงช้า</span><span class="gdash-stop is-miss">ไม่ได้เข้า / ผ่านไม่จอด</span><span class="gdash-stop is-none">ไม่มีหมุด / ไม่มีเส้นทาง</span><span class="gdash-stop is-unplanned">จอดนอกแผน (กดเปิดแผนที่)</span><span class="gdash-stop is-unknown">จอดที่ยังระบุไม่ได้ (ลูกค้ายังไม่มีหมุด)</span></div>
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
                    @isset($t['unplanned_minutes'])
                        <div class="is-unplanned"><span>จอดนอกแผน</span><strong>{{ number_format($t['unplanned_minutes']) }}</strong><small>นาที · {{ $t['unplanned_stops'] }} ครั้ง</small></div>
                    @endisset
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

                    @php $unplannedTrucks = collect($trends['by_truck'])->filter(fn ($row) => ($row['unplanned_stops'] ?? 0) > 0)->sortByDesc('unplanned_minutes')->values(); @endphp
                    @if ($unplannedTrucks->isNotEmpty())
                        <h3 class="gdash-sub">จอดนอกแผนแยกตามรถ</h3>
                        <table class="table table-sm gdash-table">
                            <thead><tr><th>รถ</th><th class="text-end">วัน</th><th class="text-end">ครั้ง</th><th class="text-end">รวม (นาที)</th><th class="text-end">เฉลี่ยต่อวัน (นาที)</th></tr></thead>
                            <tbody>
                                @foreach ($unplannedTrucks as $row)
                                    <tr><td>{{ $row['plate_no'] }}</td><td class="text-end">{{ $row['days'] }}</td><td class="text-end">{{ $row['unplanned_stops'] }}</td><td class="text-end">{{ number_format($row['unplanned_minutes']) }}</td><td class="text-end">{{ $row['days'] ? number_format($row['unplanned_minutes'] / $row['days']) : '-' }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                @endif
            </section>

            {{-- ---------- Real travel time company -> customer ---------- --}}
            <section class="gdash-trends">
                <div class="gdash-trends-head">
                    <h2>เวลาเดินทางจริง (บริษัท → ลูกค้า)</h2>
                    <small class="gdash-muted">ช่วง {{ $weeks }} สัปดาห์ · ค่ากลาง (median) จากเที่ยวจริง · เวลาวิ่งสุทธิ = หักเวลาที่จอดที่อื่นระหว่างทาง (นอกแผน / ยังระบุไม่ได้)</small>
                </div>
                @if ($travelTimes === [])
                    <div class="gdash-empty">{{ !empty($s['timeline_ready']) ? 'ยังไม่มีข้อมูล — ต้องมีหมุดลูกค้าและสรุปเส้นทางย้อนหลังก่อน' : 'ยังไม่ได้สร้างตาราง — รัน database/sql/formdp_gps_stops_legs.sql' }}</div>
                @else
                    <table class="table table-sm gdash-table gdash-travel">
                        <thead><tr><th>ลูกค้า</th><th class="text-end">เที่ยว</th><th class="text-end">ใช้เวลา</th><th class="text-end">วิ่งสุทธิ</th><th class="text-end">ระยะทาง</th><th class="text-end">มักถึงราว</th><th class="text-end">จอดลงของ</th></tr></thead>
                        <tbody>
                            @foreach ($travelTimes as $row)
                                <tr>
                                    <td>{{ $row['customer_name'] }}@if ($row['with_unplanned'])<small>{{ $row['with_unplanned'] }} เที่ยวแวะจอดที่อื่นก่อนถึง</small>@endif</td>
                                    <td class="text-end">{{ $row['trips'] }}</td>
                                    <td class="text-end">{{ $fmtMinutes($row['median_minutes']) }}</td>
                                    <td class="text-end"><b>{{ $fmtMinutes($row['median_drive_minutes']) }}</b></td>
                                    <td class="text-end">{{ $row['median_km'] !== null ? number_format($row['median_km'], 1) . ' กม.' : '-' }}</td>
                                    <td class="text-end">{{ $row['typical_arrival'] ?? '-' }}</td>
                                    <td class="text-end">{{ $row['avg_dwell'] !== null ? $row['avg_dwell'] . ' นาที' : '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
        @endunless
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/formdp/gps-dashboard.js') }}?v={{ filemtime(public_path('js/formdp/gps-dashboard.js')) }}"></script>
@endpush
