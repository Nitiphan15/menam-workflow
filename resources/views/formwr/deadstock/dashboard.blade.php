@extends('layouts.layout')
@section('title','Wire Rod Dead Stock')
@section('page-title','Wire Rod Dead Stock Dashboard')
@push('styles')
    @include('formwr.deadstock._styles')
@endpush
@section('content')
<div class="wr-shell">
    <header class="wr-page-head">
        <div><h3>ภาพรวมสต็อก Wire Rod</h3><p>ดูวัตถุดิบคงเหลือได้ในหน้าเดียว · ไม่เกิน 180 วันเป็น Normal Stock และเกิน 180 วันเป็น Dead Stock</p></div>
        <div class="wr-actions">
            <a class="btn btn-outline-primary" href="{{ route('wr.deadstock.review', request()->query()) }}"><i class="fas fa-clipboard-check"></i> เปิด Review</a>
            @include('formwr.deadstock._import_actions')@include('formwr.deadstock._export_actions')
            @canany(['WR_DS_MANAGE_ALL','WR_DS_ADMIN'])<form method="POST" action="{{ route('wr.deadstock.refresh') }}">@csrf<button class="btn btn-primary"><i class="fas fa-rotate"></i> Refresh</button></form>@endcanany
        </div>
    </header>
    @include('formwr.deadstock._filters')
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('warning'))<div class="alert alert-warning">{{ session('warning') }}</div>@endif
    <div class="wr-freshness">
        <span><span class="wr-live-dot"></span>อัปเดตข้อมูลล่าสุด <strong>{{ optional($latestRun)->finished_at ?? 'ยังไม่มีข้อมูล' }}</strong></span>
        <span>สถานะ <strong>{{ optional($latestRun)->status ?? 'WAITING' }}</strong> · หาก Refresh ไม่สำเร็จ ระบบจะแสดงข้อมูลล่าสุดที่มีอยู่</span>
    </div>
    <section class="wr-risk-grid" aria-label="Dead Stock exposure">
        <a class="wr-risk-card wr-risk-card--hero wr-kpi-link" href="{{ route('wr.deadstock.review', array_merge(request()->except(['page','stock_status']), ['stock_status'=>'DEAD'])) }}"><div class="wr-kpi-label">มูลค่าวัตถุดิบค้างเกิน 180 วัน</div><div class="wr-kpi-value">{{ number_format($kpi['dead_value'], 2) }} <span class="wr-kpi-unit">THB</span></div><div class="wr-kpi-note">รวมมูลค่า Dead Stock ทุกหน่วยที่ยังคงเหลืออยู่</div>@if($monthEndComparison)<div class="wr-trend {{ $monthEndComparison['difference'] > 0 ? 'wr-trend--up' : ($monthEndComparison['difference'] < 0 ? 'wr-trend--down' : '') }}">{{ $monthEndComparison['difference'] > 0 ? 'เพิ่ม' : ($monthEndComparison['difference'] < 0 ? 'ลด' : 'ไม่เปลี่ยนแปลง') }} {{ number_format(abs($monthEndComparison['difference']),2) }} THB @if(!is_null($monthEndComparison['percent']))({{ number_format(abs($monthEndComparison['percent']),1) }}%)@endif จาก Snapshot {{ \Carbon\Carbon::parse($monthEndComparison['as_of_at'])->format('d/m/Y') }}</div>@else<div class="wr-trend">ยังไม่มี Month-end Snapshot สำหรับเปรียบเทียบ</div>@endif<div class="wr-dead-count"><strong>{{ number_format($kpi['dead_lots']) }}</strong><span>รายการ Dead Stock</span><i class="fas fa-arrow-right"></i></div></a>
        <a class="wr-risk-card wr-risk-card--alert wr-risk-card--kg wr-kpi-link" href="{{ route('wr.deadstock.review', array_merge(request()->except(['page','stock_status','unit']), ['stock_status'=>'DEAD','unit'=>'KG'])) }}"><div class="wr-kpi-label">ปริมาณ Dead Stock · KG</div><div class="wr-kpi-value">{{ number_format($kpi['dead_kg'], 2) }} <span class="wr-kpi-unit">KG</span></div><div class="wr-kpi-note">ไม่รวม D8 และรายการหน่วย PCS · คลิกเพื่อดูรายการ</div></a>
        <a class="wr-risk-card wr-risk-card--compact wr-risk-card--warning wr-kpi-link" href="{{ route('wr.deadstock.review', array_merge(request()->except(['page','became_dead']), ['became_dead'=>1])) }}"><div class="wr-kpi-label">เพิ่งเกิน 180 วัน</div><div class="wr-kpi-value">{{ number_format($kpi['became_dead_lots']) }} <span class="wr-kpi-unit">รายการ</span></div><div class="wr-kpi-note">เปลี่ยนเป็น Dead Stock ในรอบล่าสุด</div></a>
        <a class="wr-risk-card wr-risk-card--compact {{ $kpi['unknown_lots'] > 0 ? 'wr-risk-card--warning' : '' }} wr-kpi-link" href="{{ route('wr.deadstock.review', array_merge(request()->except(['page','stock_status']), ['stock_status'=>'UNKNOWN'])) }}"><div class="wr-kpi-label">ตรวจสอบอายุไม่ได้</div><div class="wr-kpi-value">{{ number_format($kpi['unknown_lots']) }} <span class="wr-kpi-unit">รายการ</span></div><div class="wr-kpi-note">ไม่พบวันที่รับเข้า · คลิกเพื่อตรวจข้อมูล</div></a>
    </section>
    <section class="wr-incoming-strip wr-incoming-strip--two" aria-label="Normal stock age 180 days or less">
        <a class="wr-incoming-card" href="{{ route('wr.deadstock.review', array_merge(request()->except(['page','stock_status']), ['stock_status'=>'INCOMING'])) }}"><div class="wr-kpi-label">Normal Stock (ไม่เกิน 180 วัน)</div><div class="wr-kpi-value">{{ number_format($kpi['incoming_lots']) }} <span class="wr-kpi-unit">รายการ</span></div><div class="wr-kpi-note">คลิกเพื่อดูรายการทั้งหมด</div></a>
        <a class="wr-incoming-card" href="{{ route('wr.deadstock.review', array_merge(request()->except(['page','stock_status','unit']), ['stock_status'=>'INCOMING','unit'=>'KG'])) }}"><div class="wr-kpi-label">ปริมาณ Normal Stock · KG</div><div class="wr-kpi-value">{{ number_format($kpi['incoming_kg'], 2) }} <span class="wr-kpi-unit">KG</span></div><div class="wr-kpi-note">ไม่รวม D8 และรายการหน่วย PCS</div></a>
    </section>
    <section class="wr-stock-strip" aria-label="Total stock">
        <div class="wr-stock-metric"><div class="wr-kpi-label">จำนวนรายการทั้งหมด</div><div class="wr-kpi-value">{{ number_format($kpi['lots']) }} <span class="wr-kpi-unit">รายการ</span></div></div>
        <div class="wr-stock-metric"><div class="wr-kpi-label">ปริมาณคงเหลือ · KG (ไม่รวม D8)</div><div class="wr-kpi-value">{{ number_format($kpi['stock_kg'], 2) }} <span class="wr-kpi-unit">KG</span></div></div>
        <div class="wr-stock-metric"><div class="wr-kpi-label">สัดส่วนจำนวนรายการที่เป็น Dead Stock</div><div class="wr-kpi-value">{{ $kpi['lots'] > 0 ? number_format(($kpi['dead_lots'] / $kpi['lots']) * 100, 1) : '0.0' }} <span class="wr-kpi-unit">%</span></div></div>
    </section>
    <div class="wr-summary-grid">
        <section class="wr-panel">
            <div class="wr-panel-head"><h4>Stock Wire Rod แยกตาม Division</h4><span class="text-muted small">Q'TY (KGS) · คลิกยอดเพื่อดูรายการ</span></div>
            <div class="table-responsive"><table class="table wr-table wr-summary-table"><thead><tr><th>Sales Person</th><th class="text-end">Dead Stock</th><th class="text-end">Normal Stock</th><th class="text-end">Total</th></tr></thead><tbody>
            @foreach($byDivision['rows'] as $d)
                @php $isDivision = str_starts_with($d['key'], 'D'); $divisionLink = fn ($status = null) => route('wr.deadstock.review', array_filter(array_merge(request()->except(['page','division','stock_status','unit']), ['division'=>$d['key'],'unit'=>'KG','stock_status'=>$status]), fn ($v) => filled($v))); @endphp
                <tr>
                    <td>{{ $d['label'] }}</td>
                    <td class="text-end wr-number">@if($isDivision && $d['dead'] > 0)<a href="{{ $divisionLink('DEAD') }}">{{ number_format($d['dead'], 2) }}</a>@else{{ $d['dead'] > 0 ? number_format($d['dead'], 2) : '-' }}@endif</td>
                    <td class="text-end wr-number">@if($isDivision && $d['normal'] > 0)<a href="{{ $divisionLink('INCOMING') }}">{{ number_format($d['normal'], 2) }}</a>@else{{ $d['normal'] > 0 ? number_format($d['normal'], 2) : '-' }}@endif</td>
                    <td class="text-end wr-number"><strong>@if($isDivision && $d['total'] > 0)<a href="{{ $divisionLink() }}">{{ number_format($d['total'], 2) }}</a>@else{{ $d['total'] > 0 ? number_format($d['total'], 2) : '-' }}@endif</strong></td>
                </tr>
            @endforeach
            </tbody><tfoot><tr class="wr-summary-total"><th>TOTAL</th><th class="text-end wr-number">{{ number_format($byDivision['totals']['dead'], 2) }}</th><th class="text-end wr-number">{{ number_format($byDivision['totals']['normal'], 2) }}</th><th class="text-end wr-number">{{ number_format($byDivision['totals']['total'], 2) }}</th></tr></tfoot></table></div>
            <div class="wr-summary-note small text-muted">
                Planning = Lot ที่ยังไม่มีชื่อลูกค้า (Stock) · ไม่ระบุ Sales = มีลูกค้าแต่ยังผูก Division ไม่ได้ · อื่น ๆ = D4 และรหัส Sales อื่น
                @if($byDivision['totals']['unknown'] > 0)<br>Total รวม {{ number_format($byDivision['totals']['unknown'], 2) }} KG ที่ไม่ทราบอายุ (ไม่อยู่ทั้ง Dead และ Normal)@endif
                @if($byDivision['excluded']['lots'] > 0)<br>ไม่รวม {{ number_format($byDivision['excluded']['lots']) }} รายการที่ไม่ใช่หน่วย KG (D8 = {{ number_format($byDivision['excluded']['pcs'], 0) }} PCS)@endif
            </div>
        </section>
        <section class="wr-panel">
            <div class="wr-panel-head"><h4>สรุปตาม Aging</h4><span class="text-muted small">นับจากวันที่รับเข้า Stock · คลิกช่วงอายุเพื่อดูรายการ</span></div>
            <div class="table-responsive"><table class="table wr-table wr-summary-table"><thead><tr><th>Aging</th><th class="text-end">รายการ</th><th class="text-end">Qty · KG</th><th class="text-end">มูลค่า · THB</th></tr></thead><tbody>
            @foreach($byAging['rows'] as $a)
                <tr>
                    <td><a href="{{ route('wr.deadstock.review', array_merge(request()->except(['page','aging']), ['aging'=>$a['key']])) }}">{{ $a['label'] }} <span aria-hidden="true">→</span></a></td>
                    <td class="text-end wr-number">{{ number_format($a['lots']) }}</td>
                    <td class="text-end wr-number">{{ $a['kg'] > 0 ? number_format($a['kg'], 2) : '-' }}</td>
                    <td class="text-end wr-number">{{ $a['value'] > 0 ? number_format($a['value'], 2) : '-' }}</td>
                </tr>
            @endforeach
            </tbody><tfoot><tr class="wr-summary-total"><th>TOTAL</th><th class="text-end wr-number">{{ number_format($byAging['totals']['lots']) }}</th><th class="text-end wr-number">{{ number_format($byAging['totals']['kg'], 2) }}</th><th class="text-end wr-number">{{ number_format($byAging['totals']['value'], 2) }}</th></tr></tfoot></table></div>
            <div class="wr-summary-note small text-muted">0-180 วัน = Normal Stock · เกิน 180 วัน = Dead Stock @if($byAging['totals']['pcs'] > 0)· Qty KG ไม่รวม {{ number_format($byAging['totals']['pcs'], 0) }} PCS (D8)@endif</div>
        </section>
    </div>
    <section class="wr-panel">
        <div class="wr-panel-head"><h4>Dead Stock แยกตาม Material Group</h4><span class="text-muted small">คลิก Material Group เพื่อดูยอดรวมราย Item</span></div>
        <div class="table-responsive"><table class="table wr-table"><thead><tr><th>Material Group</th><th class="text-end">รายการทั้งหมด</th><th class="text-end">รายการ Dead Stock</th><th class="text-end">ปริมาณ Dead · KG</th><th class="text-end">มูลค่า · THB</th></tr></thead><tbody>
        @forelse($byGroup as $r)<tr><td><a href="{{ route('wr.deadstock.items', array_merge(request()->query(), ['material_group'=>$r['group'],'stock_status'=>'DEAD'])) }}">{{ $r['group'] }} <span aria-hidden="true">→</span></a></td><td class="text-end wr-number">{{ number_format($r['lots']) }}</td><td class="text-end wr-number"><strong>{{ number_format($r['dead_lots']) }}</strong></td><td class="text-end wr-number">{{ number_format($r['dead_kg'],2) }}</td><td class="text-end wr-number">{{ number_format($r['dead_value'],2) }}</td></tr>
        @empty<tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีข้อมูล กรุณา Refresh</td></tr>@endforelse
        </tbody></table></div>
    </section>
</div>
@endsection
@include('formwr.deadstock._qol_scripts')
