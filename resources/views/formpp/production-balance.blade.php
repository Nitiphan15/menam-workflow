@extends('layouts.layout')
@section('title', 'PP ขาด-เกิน')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/formpp/production-balance.css') }}">
@endpush
@section('content')
<main class="ppb-page"><div class="ppb-shell">
    <section class="ppb-hero" aria-labelledby="ppb-title">
        <div class="ppb-hero-copy">
            <nav class="ppb-breadcrumb" aria-label="Breadcrumb"><a href="{{ route('pp.index') }}">Production Planning</a><span>/</span><span>รายงานขาด-เกิน</span></nav>
            <h1 id="ppb-title">Production Order<br>Balance Control</h1>
            <p>ติดตามยอดเปิดผลิตเทียบกับความต้องการจริง เพื่อป้องกัน Dead Stock และการส่งมอบไม่ครบ</p>
            <div class="ppb-formula" aria-label="สูตรคำนวณ"><span>SO ค้างส่ง</span><b>−</b><span>FG + WIP + MFG</span><b>=</b><span>จำนวนที่ต้องเปิดเพิ่ม</span></div>
        </div>
        <div class="ppb-hero-signal">
            <span class="ppb-signal-label">รายการเกิน SO</span><strong>{{ number_format($summary['excess']) }}</strong>
            <span class="ppb-signal-unit">รายการ · {{ number_format($summary['excess_qty'], 2) }} หน่วย</span>
            <a href="{{ route('pp.production-balance.index', ['site' => $filters['site'] ?? 'all', 'status' => 'EXCESS']) }}">ดูเฉพาะรายการเสี่ยง <span>→</span></a>
        </div>
    </section>
    <section class="ppb-kpis" aria-label="สรุปสถานะ">
        <a class="ppb-kpi danger @if(($filters['status'] ?? '')==='EXCESS') active @endif" href="{{ route('pp.production-balance.index', ['site' => $filters['site'] ?? 'all', 'status' => 'EXCESS']) }}"><i>!</i><span><small>เกิน SO</small><strong>{{ number_format($summary['excess']) }}</strong><em>{{ number_format($summary['excess_qty'], 2) }} หน่วยเกิน</em></span></a>
        <a class="ppb-kpi warning @if(($filters['status'] ?? '')==='SHORT') active @endif" href="{{ route('pp.production-balance.index', ['site' => $filters['site'] ?? 'all', 'status' => 'SHORT']) }}"><i>+</i><span><small>เปิดยังไม่ครบ</small><strong>{{ number_format($summary['short']) }}</strong><em>ต้องเปิดเพิ่ม {{ number_format($summary['short_qty'], 2) }}</em></span></a>
        <a class="ppb-kpi success @if(($filters['status'] ?? '')==='COMPLETE') active @endif" href="{{ route('pp.production-balance.index', ['site' => $filters['site'] ?? 'all', 'status' => 'COMPLETE']) }}"><i>✓</i><span><small>ครบจำนวน</small><strong>{{ number_format($summary['complete']) }}</strong><em>สมดุลตามแผน</em></span></a>
    </section>
    <section class="ppb-workspace">
        <form method="GET" class="ppb-filters" aria-label="ตัวกรองรายงาน">
            <div class="ppb-filter-title"><i class="fas fa-sliders-h"></i><span><strong>ตัวกรองรายงาน</strong><small>ค้นหาด้วย Customer, Item, SO หรือ MFG</small></span></div>
            <label><span>แหล่งข้อมูล</span><select name="site"><option value="all" @selected(($filters['site'] ?? 'all')==='all')>Wire + Plus</option><option value="wire" @selected(($filters['site'] ?? '')==='wire')>Wire</option><option value="plus" @selected(($filters['site'] ?? '')==='plus')>Plus</option></select></label>
            <label><span>สถานะ</span><select name="status"><option value="">ทุกสถานะ</option><option value="DATA_ISSUE" @selected(($filters['status'] ?? '')==='DATA_ISSUE')>ข้อมูลไม่พร้อม</option><option value="EXCESS" @selected(($filters['status'] ?? '')==='EXCESS')>เกิน SO</option><option value="SHORT" @selected(($filters['status'] ?? '')==='SHORT')>เปิดยังไม่ครบ</option><option value="COMPLETE" @selected(($filters['status'] ?? '')==='COMPLETE')>ครบจำนวน</option></select></label>
            <label class="ppb-search"><span>ค้นหา</span><div><i class="fas fa-search"></i><input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Customer, Item, SO, MFG"></div></label>
            <div class="ppb-filter-actions"><button type="submit">แสดงผล</button><a href="{{ route('pp.production-balance.index') }}">ล้างค่า</a></div>
        </form>
        <header class="ppb-table-head"><div><h2>สถานะการติดตามการเปิดผลิตสินค้า</h2><p>แสดง {{ number_format($rows->firstItem() ?? 0) }}–{{ number_format($rows->lastItem() ?? 0) }} จาก {{ number_format($rows->total()) }} รายการ @if($snapshotAt) · เทียบ snapshot {{ \Illuminate\Support\Carbon::parse($snapshotAt)->format('d/m/Y H:i') }} @endif</p></div><div class="ppb-legend"><span><i class="danger"></i>เกิน SO</span><span><i class="warning"></i>เปิดไม่ครบ</span><span><i class="success"></i>ครบจำนวน</span></div></header>
        <div class="ppb-table-wrap"><table class="ppb-table">
            <thead><tr class="ppb-formula-row"><th></th><th></th><th></th><th class="num">B</th><th class="num">C</th><th class="num">D</th><th class="num">E</th><th class="num">F = B-(C+D+E)</th><th></th><th></th></tr><tr><th class="num">ลำดับ</th><th>Site</th><th>ITEM / CUSTOMER</th><th class="num">จำนวน SO ค้างส่ง</th><th class="num">FG</th><th class="num">WIP</th><th class="num">Mfg</th><th class="num ppb-result-head">จำนวนที่ต้องเปิดเพิ่ม</th><th>หมายเหตุ</th><th>เลข SO / MFG</th></tr></thead>
            <tbody>@forelse($rows as $row)
                <tr class="is-{{ strtolower($row->status) }}"><td class="num ppb-sequence" data-label="ลำดับ">{{ number_format(($rows->firstItem() ?? 1) + $loop->index) }}</td><td data-label="Site"><span class="ppb-site">{{ $row->site }}</span></td><td class="ppb-product" data-label="ITEM / CUSTOMER"><span>{{ $row->item }}</span><strong>{{ $row->customer }}</strong><small>{{ $row->description }}</small></td>
                @foreach(['so_backorder'=>'SO ค้างส่ง','fg'=>'FG','wip'=>'WIP','mfg'=>'MFG'] as $field=>$label)<td class="num" data-label="{{ $label }}">{{ number_format($row->{$field}, 2) }}</td>@endforeach
                <td class="num ppb-delta {{ strtolower($row->status) }}" data-label="ผลต่าง"><strong>{{ $row->need_qty > 0 ? '+' : '' }}{{ number_format($row->need_qty, 2) }}</strong>@if(isset($row->need_change) && $row->need_change !== null)<small>เทียบ snapshot {{ $row->need_change > 0 ? '+' : '' }}{{ number_format($row->need_change, 2) }}</small>@endif</td>
                <td class="ppb-remark" data-label="หมายเหตุ">@if($row->status==='DATA_ISSUE')<strong class="warning">ข้อมูลไม่พร้อม</strong><small>{{ implode(', ', $row->data_issues) }}</small>@elseif($row->status==='EXCESS')<strong class="danger">เกิน SO</strong>@elseif($row->status==='SHORT')<strong class="warning">เปิดยังไม่ครบจำนวน</strong>@else<span class="success">ครบจำนวน</span>@endif<br><small>Tolerance {{ number_format($row->tolerance_qty, 2) }} KG ({{ number_format($row->tolerance_rate * 100, 1) }}%)</small></td>
                <td class="ppb-refs" data-label="SO / MFG"><details><summary>{{ count($row->sales_orders) }} SO · {{ count($row->mfg_numbers) }} MFG</summary><div><b>SO</b><p>{{ implode(', ', $row->sales_orders) ?: '-' }}</p><b>MFG</b><p>{{ implode(', ', $row->mfg_numbers) ?: '-' }}</p>@if($row->missing_so_mfg)<b class="text-danger">MFG ไม่ระบุ SO</b><p>{{ implode(', ', $row->missing_so_mfg) }}</p>@endif</div></details></td></tr>
            @empty<tr><td colspan="10"><div class="ppb-empty"><span>0</span><strong>ไม่พบข้อมูลตามเงื่อนไข</strong><p>ลองเปลี่ยนสถานะ แหล่งข้อมูล หรือคำค้นหา</p></div></td></tr>@endforelse</tbody>
        </table></div><div class="ppb-report-note"><strong>Remark</strong><span>เครื่องหมาย “−” หมายถึง จำนวนที่เปิดเกินยอดรับ Order</span></div><div class="ppb-pagination">{{ $rows->links() }}</div>
    </section>
</div></main>
@endsection
