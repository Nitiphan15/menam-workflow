@extends('layouts.layout')
@section('title','Wire Rod Dead Stock')
@section('page-title','Wire Rod Dead Stock Dashboard')
@push('styles')
    @include('formwr.deadstock._styles')
@endpush
@section('content')
<div class="wr-shell">
    <header class="wr-page-head">
        <div><h3>ภาพรวมวัตถุดิบคงเหลือ</h3><p>Wire Rod · อายุไม่เกิน 180 วันเป็น Incoming และมากกว่า 180 วันเป็น Dead Stock</p></div>
        <div class="wr-actions">
            <a class="btn btn-outline-primary" href="{{ route('wr.deadstock.review', request()->query()) }}"><i class="fas fa-clipboard-check"></i> เปิด Review</a>
            <a class="btn btn-outline-success" href="{{ route('wr.deadstock.export', request()->query()) }}"><i class="fas fa-file-csv"></i> Export CSV</a>
            @canany(['WR_DS_MANAGE_ALL','WR_DS_ADMIN'])<form method="POST" action="{{ route('wr.deadstock.refresh') }}">@csrf<button class="btn btn-primary"><i class="fas fa-rotate"></i> Refresh</button></form>@endcanany
        </div>
    </header>
    @include('formwr.deadstock._filters')
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('warning'))<div class="alert alert-warning">{{ session('warning') }}</div>@endif
    <div class="wr-freshness">
        <span><span class="wr-live-dot"></span>ข้อมูลล่าสุด <strong>{{ optional($latestRun)->finished_at ?? 'ยังไม่มีข้อมูล' }}</strong></span>
        <span>สถานะ <strong>{{ optional($latestRun)->status ?? 'WAITING' }}</strong> · Cache จะไม่ถูกแทนด้วยศูนย์เมื่อ Refresh ไม่สำเร็จ</span>
    </div>
    <section class="wr-risk-grid" aria-label="Dead Stock exposure">
        <article class="wr-risk-card wr-risk-card--hero"><div class="wr-kpi-label">มูลค่า Dead Stock</div><div class="wr-kpi-value">{{ number_format($kpi['dead_value'], 2) }} <span class="wr-kpi-unit">THB</span></div><div class="wr-kpi-note">มูลค่าคงเหลือของ Lot ที่มีอายุมากกว่า 180 วัน</div><div class="wr-dead-count"><strong>{{ number_format($kpi['dead_lots']) }}</strong><span>Dead Lots</span></div></article>
        <article class="wr-risk-card wr-risk-card--alert"><div class="wr-kpi-label">Dead Stock · KG</div><div class="wr-kpi-value">{{ number_format($kpi['dead_kg'], 2) }} <span class="wr-kpi-unit">KG</span></div><div class="wr-kpi-note">ไม่รวมหน่วย PCS</div></article>
        <article class="wr-risk-card wr-risk-card--alert"><div class="wr-kpi-label">Dead Stock · PCS</div><div class="wr-kpi-value">{{ number_format($kpi['dead_pcs'], 2) }} <span class="wr-kpi-unit">PCS</span></div><div class="wr-kpi-note">D8 และรายการที่ ERP ระบุเป็นชิ้น</div></article>
        <article class="wr-risk-card wr-risk-card--warning"><div class="wr-kpi-label">เพิ่งข้าม 180 วัน</div><div class="wr-kpi-value">{{ number_format($kpi['became_dead_lots']) }} <span class="wr-kpi-unit">Lots</span></div><div class="wr-kpi-note">เปลี่ยนจาก Incoming เป็น Dead ในรอบล่าสุด</div></article>
        <article class="wr-risk-card {{ $kpi['unknown_lots'] > 0 ? 'wr-risk-card--warning' : '' }}"><div class="wr-kpi-label">Unknown Age / Data Issue</div><div class="wr-kpi-value">{{ number_format($kpi['unknown_lots']) }} <span class="wr-kpi-unit">Lots</span></div><div class="wr-kpi-note">ไม่มี Purchase Date และไม่รวมใน Incoming/Dead</div></article>
    </section>
    <section class="wr-stock-strip" aria-label="Total stock">
        <div class="wr-stock-metric"><div class="wr-kpi-label">Total Lots</div><div class="wr-kpi-value">{{ number_format($kpi['lots']) }}</div></div>
        <div class="wr-stock-metric"><div class="wr-kpi-label">Stock · KG</div><div class="wr-kpi-value">{{ number_format($kpi['stock_kg'], 2) }} <span class="wr-kpi-unit">KG</span></div></div>
        <div class="wr-stock-metric"><div class="wr-kpi-label">Stock · PCS</div><div class="wr-kpi-value">{{ number_format($kpi['stock_pcs'], 2) }} <span class="wr-kpi-unit">PCS</span></div></div>
        <div class="wr-stock-metric"><div class="wr-kpi-label">Dead share</div><div class="wr-kpi-value">{{ $kpi['lots'] > 0 ? number_format(($kpi['dead_lots'] / $kpi['lots']) * 100, 1) : '0.0' }} <span class="wr-kpi-unit">%</span></div></div>
    </section>
    <section class="wr-panel">
        <div class="wr-panel-head"><h4>Dead Stock แยกตาม Material Group</h4><span class="text-muted small">คลิก Material Group เพื่อเปิดรายการ</span></div>
        <div class="table-responsive"><table class="table wr-table"><thead><tr><th>Material Group</th><th class="text-end">All Lots</th><th class="text-end">Dead Lots</th><th class="text-end">Dead KG</th><th class="text-end">Dead PCS</th><th class="text-end">Value THB</th></tr></thead><tbody>
        @forelse($byGroup as $r)<tr><td><a href="{{ route('wr.deadstock.review', array_merge(request()->query(), ['material_group'=>$r['group'],'stock_status'=>'DEAD'])) }}">{{ $r['group'] }} <span aria-hidden="true">→</span></a></td><td class="text-end wr-number">{{ number_format($r['lots']) }}</td><td class="text-end wr-number"><strong>{{ number_format($r['dead_lots']) }}</strong></td><td class="text-end wr-number">{{ number_format($r['dead_kg'],2) }}</td><td class="text-end wr-number">{{ number_format($r['dead_pcs'],2) }}</td><td class="text-end wr-number">{{ number_format($r['dead_value'],2) }}</td></tr>
        @empty<tr><td colspan="6" class="text-center text-muted py-4">ยังไม่มีข้อมูล กรุณา Refresh</td></tr>@endforelse
        </tbody></table></div>
    </section>
</div>
@endsection
