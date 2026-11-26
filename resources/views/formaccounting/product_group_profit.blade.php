@extends('layouts.layout')

@section('content')
@php
    $fmt = fn($value, $digits = 2) => $value === null ? '-' : number_format((float) $value, $digits);
    $query = request()->only(['date_from','date_to','sales_site','production_site','product_group','invoice','partnumber','issues_only']);
    $activeFilterCount = collect($query)->filter(fn($value, $key) => filled($value) && !in_array($value, ['ALL'], true))->count();
    $hasAdvancedFilters = filled($filters['invoice']) || filled($filters['partnumber']);
    $today = now();
    $quickUrl = fn(array $replace) => route('accounting.product-group-profit.index', array_merge($query, $replace));
@endphp
<style>
    .pgp-page {
        --pgp-navy:#18334f; --pgp-blue:#245b85; --pgp-blue-soft:#edf5fa;
        --pgp-ink:#243442; --pgp-muted:#657585; --pgp-line:#dce4ea;
        --pgp-green:#16734a; --pgp-green-soft:#eaf7f0;
        --pgp-orange:#b45309; --pgp-orange-soft:#fff7ed;
        color:var(--pgp-ink); max-width:1800px; margin-inline:auto;
    }
    .pgp-panel { background:#fff; border:1px solid var(--pgp-line); border-radius:.75rem; box-shadow:0 4px 16px rgba(24,51,79,.055); }
    .pgp-eyebrow { color:var(--pgp-blue); font-size:.75rem; font-weight:800; letter-spacing:.08em; text-transform:uppercase; }
    .pgp-title { color:var(--pgp-navy); font-size:clamp(1.3rem,2vw,1.75rem); font-weight:750; letter-spacing:-.02em; margin:.1rem 0 .2rem; }
    .pgp-subtitle { color:var(--pgp-muted); font-size:.88rem; }
    .pgp-export { border-radius:.55rem; font-weight:700; padding:.6rem .9rem; }
    .pgp-quick-bar { align-items:center; display:flex; gap:.5rem; overflow-x:auto; padding:.65rem .75rem; scrollbar-width:thin; }
    .pgp-quick-label { color:var(--pgp-muted); flex:none; font-size:.75rem; font-weight:750; padding-inline:.25rem; }
    .pgp-quick { align-items:center; background:#fff; border:1px solid var(--pgp-line); border-radius:999px; color:#40566a; display:inline-flex; flex:none; font-size:.78rem; font-weight:700; gap:.35rem; padding:.42rem .7rem; text-decoration:none; transition:background-color .15s,border-color .15s,color .15s; }
    .pgp-quick:hover { background:var(--pgp-blue-soft); border-color:#a8c8dc; color:var(--pgp-blue); }
    .pgp-quick.active { background:var(--pgp-blue); border-color:var(--pgp-blue); color:#fff; }
    .pgp-quick.issue { color:var(--pgp-orange); }
    .pgp-quick.issue.active { background:var(--pgp-orange); border-color:var(--pgp-orange); color:#fff; }
    .pgp-quick-count { background:rgba(255,255,255,.85); border-radius:999px; color:var(--pgp-orange); font-size:.68rem; padding:.08rem .35rem; }
    .pgp-filter-head { border-bottom:1px solid var(--pgp-line); padding:.8rem 1rem; }
    .pgp-filter-title { color:var(--pgp-navy); font-size:.9rem; font-weight:750; margin:0; }
    .pgp-filter-body { padding:1rem; }
    .pgp-filter-body .form-label { color:#435466; font-size:.75rem; font-weight:750; margin-bottom:.3rem; }
    .pgp-filter-body .form-control,.pgp-filter-body .form-select { border-color:#cfd9e1; font-size:.84rem; min-height:38px; }
    .pgp-filter-body .form-control:focus,.pgp-filter-body .form-select:focus { border-color:#6b9abc; box-shadow:0 0 0 .2rem rgba(36,91,133,.12); }
    .pgp-search-btn { background:var(--pgp-blue); border-color:var(--pgp-blue); font-weight:700; min-width:120px; }
    .pgp-filter-count { background:var(--pgp-blue-soft); color:var(--pgp-blue); font-size:.75rem; }
    .pgp-advanced { border-top:1px solid var(--pgp-line); margin-top:.8rem; padding-top:.7rem; }
    .pgp-advanced summary { color:var(--pgp-blue); cursor:pointer; font-size:.78rem; font-weight:750; list-style:none; width:max-content; }
    .pgp-advanced summary::-webkit-details-marker { display:none; }
    .pgp-advanced summary i { transition:transform .15s; }
    .pgp-advanced[open] summary i { transform:rotate(180deg); }
    .pgp-advanced-grid { display:grid; gap:.5rem; grid-template-columns:minmax(180px,1fr) minmax(180px,1fr); margin-top:.65rem; max-width:720px; }
    .pgp-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.75rem; }
    .pgp-kpi { background:#fff; border:1px solid var(--pgp-line); border-radius:.7rem; min-width:0; padding:.8rem 1rem; position:relative; overflow:hidden; }
    .pgp-kpi::before { background:#7391a8; content:''; inset:0 auto 0 0; position:absolute; width:4px; }
    .pgp-kpi small { color:var(--pgp-muted); display:block; font-size:.75rem; font-weight:700; margin-bottom:.2rem; }
    .pgp-kpi strong { color:var(--pgp-navy); display:block; font-size:clamp(1rem,1.4vw,1.25rem); font-variant-numeric:tabular-nums; line-height:1.3; overflow:hidden; text-overflow:ellipsis; }
    .pgp-kpi-profit { background:var(--pgp-green-soft); border-color:#cde8d9; }
    .pgp-kpi-profit::before { background:var(--pgp-green); }
    .pgp-kpi-issue { background:var(--pgp-orange-soft); border-color:#fed7aa; }
    .pgp-kpi-issue::before { background:var(--pgp-orange); }
    .pgp-limit-note { align-items:center; background:#fffbeb; border-bottom:1px solid #f4df9b; color:#785b12; display:flex; font-size:.8rem; gap:.5rem; padding:.5rem 1rem; }
    .pgp-table-wrap { overflow:auto; }
    .pgp-table { color:var(--pgp-ink); font-size:.79rem; margin:0; min-width:1400px; white-space:nowrap; }
    .pgp-table thead th { background:var(--pgp-navy); border-color:#36516b; color:#fff; font-size:.75rem; font-weight:750; letter-spacing:.015em; padding:.65rem .55rem; position:sticky; text-align:center; top:0; vertical-align:middle; z-index:4; }
    .pgp-table tbody td { border-color:#e7edf1; padding:.55rem; vertical-align:top; }
    .pgp-table tbody tr:not(.group-row):not(.subtotal):not(.grand-total):hover td { background:#f5f9fc; }
    .pgp-table th:first-child,.pgp-table td:first-child { left:0; min-width:86px; position:sticky; z-index:2; }
    .pgp-table th:nth-child(2),.pgp-table td:nth-child(2) { left:86px; min-width:125px; position:sticky; z-index:2; }
    .pgp-table tbody tr:not(.group-row):not(.subtotal):not(.grand-total) td:first-child,
    .pgp-table tbody tr:not(.group-row):not(.subtotal):not(.grand-total) td:nth-child(2) { background:#fff; }
    .pgp-table thead th:first-child,.pgp-table thead th:nth-child(2) { background:var(--pgp-navy); z-index:6; }
    .pgp-table .num { text-align:right; font-variant-numeric:tabular-nums; }
    .pgp-table .group-row td { background:#deedf6; color:var(--pgp-navy); font-size:.8rem; font-weight:800; padding:.6rem .7rem; }
    .pgp-site-chip { background:var(--pgp-navy); border-radius:999px; color:#fff; display:inline-block; font-size:.66rem; letter-spacing:.04em; margin-right:.45rem; padding:.18rem .5rem; }
    .pgp-table .subtotal td { background:#f1f5f8; border-top:2px solid #9cb0bf; color:#20384d; font-weight:800; }
    .pgp-table .grand-total td { background:var(--pgp-navy); border-top:0; color:#fff; font-weight:850; }
    .pgp-table .group-row td,.pgp-table .subtotal td,.pgp-table .grand-total td { position:static; }
    .pgp-table .cost { background:#fff9dc; }
    .pgp-table .loss { color:#b42318; }
    .pgp-row-issue { color:var(--pgp-orange); margin-left:.25rem; }
    .pgp-part { color:var(--pgp-navy); font-weight:750; }
    .pgp-description { color:#45586a; display:inline-block; margin-top:.15rem; max-width:300px; overflow:hidden; text-overflow:ellipsis; }
    .pgp-mfg { color:var(--pgp-muted); font-size:.75rem; }
    .pgp-issue { min-width:180px; white-space:normal; }
    .pgp-issue-badge { align-items:flex-start; background:var(--pgp-orange-soft); border:1px solid #fed7aa; border-radius:.35rem; color:#9a4310; display:inline-flex; gap:.35rem; line-height:1.35; padding:.28rem .42rem; }
    .pgp-no-issue { color:#9aa7b2; }
    .pgp-result-head { align-items:center; border-bottom:1px solid var(--pgp-line); display:flex; justify-content:space-between; padding:.65rem 1rem; }
    .pgp-result-title { color:var(--pgp-navy); font-size:.85rem; font-weight:750; margin:0; }
    .pgp-result-tools { align-items:center; display:flex; gap:.5rem; }
    .pgp-column-btn { border-color:#b9c7d2; color:#40566a; font-size:.78rem; font-weight:700; }
    .pgp-column-menu { border:1px solid var(--pgp-line); border-radius:.65rem; box-shadow:0 10px 28px rgba(24,51,79,.14); min-width:260px; padding:.5rem; }
    .pgp-column-menu-title { color:var(--pgp-muted); font-size:.7rem; font-weight:800; letter-spacing:.05em; padding:.25rem .45rem .4rem; text-transform:uppercase; }
    .pgp-column-option { align-items:center; border-radius:.35rem; cursor:pointer; display:flex; font-size:.8rem; gap:.55rem; padding:.38rem .45rem; }
    .pgp-column-option:hover { background:#f3f7fa; }
    .pgp-column-option input { margin:0; }
    .pgp-scroll-hint { color:var(--pgp-muted); font-size:.75rem; }
    .pgp-empty-icon { background:var(--pgp-blue-soft); border-radius:50%; color:var(--pgp-blue); display:inline-grid; font-size:1.15rem; height:44px; margin-bottom:.6rem; place-items:center; width:44px; }
    .pgp-table:not(.pgp-columns-ready) [data-col="customer"],.pgp-table:not(.pgp-columns-ready) [data-col="unit"],.pgp-table:not(.pgp-columns-ready) [data-col="unit_price"],.pgp-table:not(.pgp-columns-ready) [data-col="unit_cost"],.pgp-col-hidden { display:none!important; }
    @media (min-width: 1200px) {
        .pgp-filter-grid { display:grid; gap:.5rem; grid-template-columns:1fr 1fr .75fr .9fr 1.2fr auto; }
        .pgp-filter-grid > div { width:auto; }
        .pgp-filter-actions { align-items:end; margin-top:0!important; }
        .pgp-filter-actions .btn { align-items:center; display:inline-flex; justify-content:center; min-height:38px; white-space:nowrap; }
    }
    @media (max-width: 991px) {
        .pgp-page { padding-inline:0!important; }
        .pgp-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); }
        .pgp-table { font-size:.78rem; }
        .pgp-table .group-row td,.pgp-table .subtotal td,.pgp-table .grand-total td { position:static!important; }
    }
    @media (max-width: 575px) {
        .pgp-page-head { align-items:stretch!important; flex-direction:column; }
        .pgp-export { width:100%; }
        .pgp-kpis { gap:.5rem; }
        .pgp-kpi { padding:.7rem .75rem; }
        .pgp-filter-body { padding:.8rem; }
        .pgp-filter-actions { display:grid!important; grid-template-columns:1fr auto; }
        .pgp-search-btn { min-width:0; }
        .pgp-result-head { align-items:flex-start; flex-direction:column; gap:.2rem; }
        .pgp-result-tools { justify-content:space-between; width:100%; }
        .pgp-advanced-grid { grid-template-columns:1fr; }
    }
</style>

<div class="container-fluid py-3 pgp-page">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3 pgp-page-head">
        <div>
            <div class="pgp-eyebrow">Accounting Report</div>
            <h1 class="pgp-title">รายงานกำไรตามกลุ่มสินค้า</h1>
            <div class="pgp-subtitle">ยอดขายตาม Sales Site · ต้นทุนจริงแยกตาม Production Site</div>
        </div>
        <a class="btn btn-success text-nowrap pgp-export" href="{{ route('accounting.product-group-profit.export', $query) }}"><i class="fas fa-file-excel me-2"></i>Export Excel</a>
    </div>

    <nav class="pgp-panel pgp-quick-bar mb-3" aria-label="ตัวกรองด่วน">
        <span class="pgp-quick-label"><i class="fas fa-bolt me-1"></i>Quick Action</span>
        <a class="pgp-quick" href="{{ $quickUrl(['date_from'=>$today->copy()->startOfMonth()->toDateString(),'date_to'=>$today->toDateString()]) }}">เดือนนี้</a>
        <a class="pgp-quick" href="{{ $quickUrl(['date_from'=>$today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),'date_to'=>$today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()]) }}">เดือนก่อน</a>
        <a class="pgp-quick" href="{{ $quickUrl(['date_from'=>$today->copy()->subDays(6)->toDateString(),'date_to'=>$today->toDateString()]) }}">7 วันล่าสุด</a>
        <span class="vr mx-1"></span>
        <a class="pgp-quick {{ $filters['production_site']==='WIRE' ? 'active' : '' }}" href="{{ $quickUrl(['production_site'=>'WIRE']) }}"><i class="fas fa-industry"></i>ผลิต WIRE</a>
        <a class="pgp-quick {{ $filters['production_site']==='PLUS' ? 'active' : '' }}" href="{{ $quickUrl(['production_site'=>'PLUS']) }}"><i class="fas fa-industry"></i>ผลิต PLUS</a>
        <a class="pgp-quick issue {{ $filters['issues_only'] ? 'active' : '' }}" href="{{ $quickUrl(['issues_only'=>$filters['issues_only'] ? null : 1]) }}"><i class="fas fa-exclamation-triangle"></i>เฉพาะ Data Issue @if(!$filters['issues_only'] && $summary['issue_count'])<span class="pgp-quick-count">{{ number_format($summary['issue_count']) }}</span>@endif</a>
    </nav>

    <form method="GET" action="{{ route('accounting.product-group-profit.index') }}" class="pgp-panel mb-3">
        <div class="pgp-filter-head d-flex align-items-center justify-content-between">
            <h2 class="pgp-filter-title"><i class="fas fa-sliders-h me-2"></i>ตัวกรองรายงาน</h2>
            @if($activeFilterCount)<span class="badge rounded-pill pgp-filter-count">ใช้งาน {{ $activeFilterCount }} ตัวกรอง</span>@endif
        </div>
        <div class="pgp-filter-body"><div class="row g-2 align-items-end pgp-filter-grid">
            <div class="col-xl-2 col-md-4"><label class="form-label">วันที่เริ่ม</label><input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] }}"></div>
            <div class="col-xl-2 col-md-4"><label class="form-label">วันที่สิ้นสุด</label><input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] }}"></div>
            <div class="col-xl-1 col-md-4"><label class="form-label">Sales Site</label><select name="sales_site" class="form-select">@foreach(['ALL','WIRE','PLUS'] as $site)<option @selected($filters['sales_site']===$site)>{{ $site }}</option>@endforeach</select></div>
            <div class="col-xl-1 col-md-4"><label class="form-label">Production Site</label><select name="production_site" class="form-select">@foreach(['ALL','WIRE','PLUS'] as $site)<option @selected($filters['production_site']===$site)>{{ $site }}</option>@endforeach</select></div>
            <div class="col-xl-2 col-md-4"><label class="form-label">กลุ่มสินค้า</label><input name="product_group" class="form-control" value="{{ $filters['product_group'] }}" list="pgp-groups"><datalist id="pgp-groups">@foreach($groups as $group)<option value="{{ $group }}">@endforeach</datalist></div>
            <div class="col-12 d-flex gap-2 mt-3 pgp-filter-actions"><button class="btn btn-primary pgp-search-btn" type="submit"><i class="fas fa-search me-2"></i>แสดงรายงาน</button><a class="btn btn-outline-secondary" href="{{ route('accounting.product-group-profit.index') }}">ล้างตัวกรอง</a></div>
        </div>
        <details class="pgp-advanced" @if($hasAdvancedFilters) open @endif>
            <summary><i class="fas fa-chevron-down me-1"></i>ตัวกรองเพิ่มเติม @if($hasAdvancedFilters)<span class="badge rounded-pill pgp-filter-count ms-1">ใช้งาน</span>@endif</summary>
            <div class="pgp-advanced-grid">
                <div><label class="form-label">Invoice</label><input name="invoice" class="form-control" value="{{ $filters['invoice'] }}" placeholder="ค้นหาเลขที่เอกสาร"></div>
                <div><label class="form-label">Part Number</label><input name="partnumber" class="form-control" value="{{ $filters['partnumber'] }}" placeholder="ค้นหาบางส่วนของ Part Number"></div>
            </div>
        </details>
        @if($filters['issues_only'])<input type="hidden" name="issues_only" value="1">@endif
        </div>
    </form>

    <div class="pgp-kpis mb-3">
        @foreach([['ยอดขาย',$summary['sales']],['ต้นทุน',$summary['cost']],['กำไรขั้นต้น',$summary['gross_profit'],false,$summary['gross_profit'] !== null ? 'pgp-kpi-profit' : ''],['Margin',$summary['margin'],true,$summary['margin'] !== null ? 'pgp-kpi-profit' : '']] as $kpi)
            <div class="pgp-kpi {{ $kpi[3] ?? '' }}"><small>{{ $kpi[0] }}</small><strong>{{ $fmt($kpi[1]) }}{{ ($kpi[2] ?? false) && $kpi[1] !== null ? '%' : '' }}</strong></div>
        @endforeach
    </div>

    <div class="pgp-panel overflow-hidden">
        @if($limited)<div class="pgp-limit-note"><i class="fas fa-info-circle"></i><span>แสดงสูงสุด {{ number_format($limit) }} แถว — กรองเพิ่มหรือ Export Excel เพื่อดูข้อมูลทั้งหมด</span></div>@endif
        <div class="pgp-result-head">
            <h2 class="pgp-result-title">รายละเอียดรายการ <span class="text-muted fw-normal">({{ number_format($rows->count()) }} รายการ)</span></h2>
            <div class="pgp-result-tools">
                <span class="pgp-scroll-hint"><i class="fas fa-arrows-left-right me-1"></i>เลื่อนแนวนอนเพื่อดูข้อมูลทั้งหมด</span>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle pgp-column-btn" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"><i class="fas fa-columns me-1"></i>จัดการคอลัมน์</button>
                    <div class="dropdown-menu dropdown-menu-end pgp-column-menu">
                        <div class="pgp-column-menu-title">คอลัมน์ที่แสดง</div>
                        @foreach([['date','วันที่',true],['invoice','Invoice',true],['customer','Customer ID',false],['item','รายการ / MFG',true],['unit','หน่วย',false],['quantity','จำนวน',true],['unit_price','ราคาขาย/หน่วย',false],['sales','ยอดขาย',true],['unit_cost','ต้นทุน/หน่วย',false],['cost','ต้นทุน',true],['profit','กำไร',true],['margin','Margin',true],['issue','Data Issue',true]] as [$key,$label,$default])
                            <label class="pgp-column-option"><input class="form-check-input pgp-column-toggle" type="checkbox" value="{{ $key }}" @checked($default) @disabled(in_array($key,['date','invoice','item'],true))><span>{{ $label }}</span>@if(in_array($key,['date','invoice','item'],true))<small class="text-muted ms-auto">หลัก</small>@endif</label>
                        @endforeach
                        <div class="border-top mt-1 pt-1"><button type="button" class="btn btn-link btn-sm text-decoration-none pgp-column-reset">คืนค่าเริ่มต้น</button></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="pgp-table-wrap">
        <table class="table table-sm table-striped mb-0 pgp-table" id="pgp-result-table">
            <thead><tr><th data-col="date">วันที่</th><th data-col="invoice">เลขที่เอกสาร</th><th data-col="customer">Customer ID</th><th data-col="item">รายการ</th><th data-col="unit">หน่วย</th><th data-col="quantity">จำนวน</th><th data-col="unit_price">ราคาขาย/หน่วย</th><th data-col="sales">จำนวนเงิน</th><th data-col="unit_cost">ต้นทุนต่อหน่วย</th><th data-col="cost">ต้นทุน</th><th data-col="profit">กำไรขั้นต้น</th><th data-col="margin">กำไรขั้นต้น (%)</th><th data-col="issue">Data Issue</th></tr></thead>
            <tbody>
            @forelse($rows->groupBy(fn($row) => $row->production_site.'|'.$row->product_group) as $groupRows)
                @php $first=$groupRows->first(); $sales=(float)$groupRows->sum('sales_thb'); $cost=$groupRows->every(fn($row)=>$row->total_cost!==null)?(float)$groupRows->sum('total_cost'):null; $profit=$cost===null?null:$sales-$cost; @endphp
                <tr class="group-row"><td colspan="13" class="pgp-span-all"><span class="pgp-site-chip">{{ $first->production_site }}</span>{{ $first->product_group }}</td></tr>
                @foreach($groupRows as $row)
                    <tr><td data-col="date">{{ \Carbon\Carbon::parse($row->invoice_date)->format('d/m/').(\Carbon\Carbon::parse($row->invoice_date)->year+543) }}</td><td data-col="invoice" class="fw-semibold">{{ $row->invoice_no }}@if($row->data_issue)<i class="fas fa-exclamation-triangle pgp-row-issue" title="มี Data Issue" aria-label="มี Data Issue"></i>@endif</td><td data-col="customer">{{ $row->customer_id }}</td><td data-col="item"><span class="pgp-part">{{ $row->partnumber }}</span><br><span class="pgp-description" title="{{ $row->item_description }}">{{ $row->item_description }}</span><br><span class="pgp-mfg">MFG: {{ $row->mfg ?: '-' }}</span></td><td data-col="unit">{{ $row->unit }}</td><td data-col="quantity" class="num">{{ $fmt($row->quantity) }}</td><td data-col="unit_price" class="num">{{ $fmt($row->unit_price) }}</td><td data-col="sales" class="num fw-semibold">{{ $fmt($row->sales_thb) }}</td><td data-col="unit_cost" class="num cost">{{ $fmt($row->unit_cost) }}</td><td data-col="cost" class="num">{{ $fmt($row->total_cost) }}</td><td data-col="profit" class="num fw-semibold @if(($row->gross_profit ?? 0)<0) loss @endif">{{ $fmt($row->gross_profit) }}</td><td data-col="margin" class="num @if(($row->margin ?? 0)<0) loss @endif">{{ $row->margin === null ? 'N/A' : $fmt($row->margin).'%' }}</td><td data-col="issue" class="pgp-issue">@if($row->data_issue)<span class="pgp-issue-badge"><i class="fas fa-exclamation-triangle mt-1"></i><span>{{ $row->data_issue }}</span></span>@else<span class="pgp-no-issue">—</span>@endif</td></tr>
                @endforeach
                <tr class="subtotal"><td data-col="date">รวม</td><td data-col="invoice"></td><td data-col="customer"></td><td data-col="item">{{ $first->product_group }}</td><td data-col="unit"></td><td data-col="quantity" class="num">{{ $fmt($groupRows->sum('quantity')) }}</td><td data-col="unit_price"></td><td data-col="sales" class="num">{{ $fmt($sales) }}</td><td data-col="unit_cost"></td><td data-col="cost" class="num">{{ $fmt($cost) }}</td><td data-col="profit" class="num">{{ $fmt($profit) }}</td><td data-col="margin" class="num">{{ $sales != 0 && $profit !== null ? $fmt(($profit/$sales)*100).'%' : 'N/A' }}</td><td data-col="issue">{{ $cost===null?'ยอดรวมไม่คำนวณ เพราะมีรายการไม่มีต้นทุน':'' }}</td></tr>
            @empty
                <tr><td colspan="13" class="text-center text-muted py-5 pgp-span-all"><span class="pgp-empty-icon"><i class="fas fa-search"></i></span><div class="fw-semibold text-dark">ไม่พบข้อมูลตามเงื่อนไข</div><small>ลองเปลี่ยนช่วงวันที่หรือล้างตัวกรองบางรายการ</small></td></tr>
            @endforelse
            @if($rows->isNotEmpty())<tr class="grand-total"><td data-col="date">GRAND</td><td data-col="invoice"></td><td data-col="customer"></td><td data-col="item">TOTAL</td><td data-col="unit"></td><td data-col="quantity"></td><td data-col="unit_price"></td><td data-col="sales" class="num">{{ $fmt($summary['sales']) }}</td><td data-col="unit_cost"></td><td data-col="cost" class="num">{{ $fmt($summary['cost']) }}</td><td data-col="profit" class="num">{{ $fmt($summary['gross_profit']) }}</td><td data-col="margin" class="num">{{ $summary['margin']===null?'N/A':$fmt($summary['margin']).'%' }}</td><td data-col="issue"></td></tr>@endif
            </tbody>
        </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const table = document.getElementById('pgp-result-table');
    if (!table) return;

    const storageKey = 'menam.productGroupProfit.visibleColumns.v1';
    const required = ['date', 'invoice', 'item'];
    const defaults = ['date', 'invoice', 'item', 'quantity', 'sales', 'cost', 'profit', 'margin', 'issue'];
    const all = Array.from(table.querySelectorAll('thead [data-col]')).map(cell => cell.dataset.col);
    const toggles = Array.from(document.querySelectorAll('.pgp-column-toggle'));

    function savedColumns() {
        try {
            const saved = JSON.parse(localStorage.getItem(storageKey));
            return Array.isArray(saved) ? saved.filter(key => all.includes(key)) : defaults;
        } catch (error) {
            return defaults;
        }
    }

    function applyColumns(columns, persist = true) {
        const visible = Array.from(new Set([...required, ...columns])).filter(key => all.includes(key));
        all.forEach(key => {
            table.querySelectorAll('[data-col="' + key + '"]').forEach(cell => cell.classList.toggle('pgp-col-hidden', !visible.includes(key)));
        });
        table.querySelectorAll('.pgp-span-all').forEach(cell => cell.colSpan = visible.length);
        toggles.forEach(toggle => toggle.checked = visible.includes(toggle.value));
        table.classList.add('pgp-columns-ready');
        table.style.minWidth = visible.length <= 9 ? '1080px' : '1400px';
        if (persist) {
            try { localStorage.setItem(storageKey, JSON.stringify(visible)); } catch (error) {}
        }
    }

    applyColumns(savedColumns(), false);
    toggles.forEach(toggle => toggle.addEventListener('change', function () {
        applyColumns(toggles.filter(item => item.checked).map(item => item.value));
    }));
    document.querySelector('.pgp-column-reset')?.addEventListener('click', function () {
        applyColumns(defaults);
    });
});
</script>
@endsection
