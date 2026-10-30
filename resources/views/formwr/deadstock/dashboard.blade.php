@extends('layouts.layout')
@section('title','Wire Rod Dead Stock')
@section('page-title','Wire Rod Dead Stock Dashboard')
@push('styles')
    @include('formwr.deadstock._styles')
@endpush
@section('content')
<div class="wr-shell">
    <header class="wr-page-head">
        <div><h3>ภาพรวมสต็อก Wire Rod</h3><p>ดูวัตถุดิบคงเหลือได้ในหน้าเดียว · ไม่เกิน 180 วันเป็น Incoming และเกิน 180 วันเป็น Dead Stock</p></div>
        <div class="wr-actions">
            <a class="btn btn-outline-primary" href="{{ route('wr.deadstock.review', request()->query()) }}"><i class="fas fa-clipboard-check"></i> เปิด Review</a>
            @include('formwr.deadstock._export_actions')
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
    <section class="wr-incoming-strip wr-incoming-strip--two" aria-label="Incoming stock age 180 days or less">
        <a class="wr-incoming-card" href="{{ route('wr.deadstock.review', array_merge(request()->except(['page','stock_status']), ['stock_status'=>'INCOMING'])) }}"><div class="wr-kpi-label">Incoming ที่ยังไม่เกิน 180 วัน</div><div class="wr-kpi-value">{{ number_format($kpi['incoming_lots']) }} <span class="wr-kpi-unit">รายการ</span></div><div class="wr-kpi-note">คลิกเพื่อดูรายการทั้งหมด</div></a>
        <a class="wr-incoming-card" href="{{ route('wr.deadstock.review', array_merge(request()->except(['page','stock_status','unit']), ['stock_status'=>'INCOMING','unit'=>'KG'])) }}"><div class="wr-kpi-label">ปริมาณ Incoming · KG</div><div class="wr-kpi-value">{{ number_format($kpi['incoming_kg'], 2) }} <span class="wr-kpi-unit">KG</span></div><div class="wr-kpi-note">ไม่รวม D8 และรายการหน่วย PCS</div></a>
    </section>
    <section class="wr-stock-strip" aria-label="Total stock">
        <div class="wr-stock-metric"><div class="wr-kpi-label">จำนวนรายการทั้งหมด</div><div class="wr-kpi-value">{{ number_format($kpi['lots']) }} <span class="wr-kpi-unit">รายการ</span></div></div>
        <div class="wr-stock-metric"><div class="wr-kpi-label">ปริมาณคงเหลือ · KG (ไม่รวม D8)</div><div class="wr-kpi-value">{{ number_format($kpi['stock_kg'], 2) }} <span class="wr-kpi-unit">KG</span></div></div>
        <div class="wr-stock-metric"><div class="wr-kpi-label">สัดส่วนจำนวนรายการที่เป็น Dead Stock</div><div class="wr-kpi-value">{{ $kpi['lots'] > 0 ? number_format(($kpi['dead_lots'] / $kpi['lots']) * 100, 1) : '0.0' }} <span class="wr-kpi-unit">%</span></div></div>
    </section>
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
