@extends('layouts.app')

@section('content')
@php
    $fmt = fn($value, $digits = 2) => $value === null ? '-' : number_format((float) $value, $digits);
    $query = request()->only(['date_from','date_to','sales_site','production_site','product_group','invoice','partnumber']);
@endphp
<style>
    .pgp-page { --pgp-ink:#263238; --pgp-muted:#667085; --pgp-line:#d8ddd9; --pgp-head:#aaa99f; --pgp-band:#dceff0; color:var(--pgp-ink); }
    .pgp-panel { background:#fff; border:1px solid var(--pgp-line); border-radius:.5rem; box-shadow:0 2px 8px rgba(16,24,40,.06); }
    .pgp-title { font-size:1.25rem; font-weight:700; margin:0; }
    .pgp-kpi { border-left:3px solid #5c8f93; padding:.35rem .75rem; min-width:130px; }
    .pgp-kpi small { color:var(--pgp-muted); display:block; }
    .pgp-kpi strong { font-size:1.05rem; font-variant-numeric:tabular-nums; }
    .pgp-kpi-profit { border-left-color:#177245; }
    .pgp-kpi-issue { border-left-color:#d97706; }
    .pgp-table { font-size:.82rem; white-space:nowrap; }
    .pgp-table thead th { background:var(--pgp-head); color:#111; border-color:#fff; text-align:center; vertical-align:middle; position:sticky; top:0; z-index:2; }
    .pgp-table td { vertical-align:top; }
    .pgp-table .num { text-align:right; font-variant-numeric:tabular-nums; }
    .pgp-table .group-row td { background:var(--pgp-band); font-weight:700; }
    .pgp-table .subtotal td { background:#f2f3ec; border-top:2px solid #252525; font-weight:700; }
    .pgp-table .grand-total td { background:#d6ecee; border-top:3px double #252525; font-weight:800; }
    .pgp-table .cost { background:#fff4b8; }
    .pgp-table .loss { color:#b42318; }
    .pgp-issue { white-space:normal; min-width:210px; color:#9a3412; }
    .pgp-scroll-hint { display:none; }
    @media (max-width: 991px) {
        .pgp-kpis { display:grid!important; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.8rem!important; }
        .pgp-kpi { min-width:0; }
        .pgp-scroll-hint { display:block; padding:.55rem .75rem; background:#f8fafc; color:var(--pgp-muted); font-size:.78rem; }
        .pgp-table { font-size:.78rem; }
        .pgp-table th:first-child,.pgp-table td:first-child { position:sticky; left:0; z-index:1; background:#fff; }
        .pgp-table th:nth-child(2),.pgp-table td:nth-child(2) { position:sticky; left:76px; z-index:1; background:#fff; }
        .pgp-table thead th:first-child,.pgp-table thead th:nth-child(2) { z-index:3; background:var(--pgp-head); }
        .pgp-table .group-row td:first-child,.pgp-table .subtotal td:first-child,.pgp-table .grand-total td:first-child { position:static; }
    }
    @media (max-width: 575px) { .pgp-page-head { align-items:flex-start!important; flex-direction:column; } }
</style>

<div class="container-fluid py-3 pgp-page">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 pgp-page-head">
        <div><h1 class="pgp-title">รายงานกำไรตามกลุ่มสินค้า</h1><div class="text-muted small">ยอดขายตาม Sales Site · ต้นทุนจริงตาม Production Site</div></div>
        <a class="btn btn-success text-nowrap" href="{{ route('accounting.product-group-profit.export', $query) }}"><i class="fas fa-file-excel me-1"></i>Export Excel</a>
    </div>

    <form method="GET" action="{{ route('accounting.product-group-profit.index') }}" class="pgp-panel p-3 mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-xl-2 col-md-4"><label class="form-label">วันที่เริ่ม</label><input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] }}"></div>
            <div class="col-xl-2 col-md-4"><label class="form-label">วันที่สิ้นสุด</label><input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] }}"></div>
            <div class="col-xl-1 col-md-4"><label class="form-label">Sales Site</label><select name="sales_site" class="form-select">@foreach(['ALL','WIRE','PLUS'] as $site)<option @selected($filters['sales_site']===$site)>{{ $site }}</option>@endforeach</select></div>
            <div class="col-xl-1 col-md-4"><label class="form-label">Production</label><select name="production_site" class="form-select">@foreach(['ALL','WIRE','PLUS'] as $site)<option @selected($filters['production_site']===$site)>{{ $site }}</option>@endforeach</select></div>
            <div class="col-xl-2 col-md-4"><label class="form-label">กลุ่มสินค้า</label><input name="product_group" class="form-control" value="{{ $filters['product_group'] }}" list="pgp-groups"><datalist id="pgp-groups">@foreach($groups as $group)<option value="{{ $group }}">@endforeach</datalist></div>
            <div class="col-xl-2 col-md-4"><label class="form-label">Invoice</label><input name="invoice" class="form-control" value="{{ $filters['invoice'] }}"></div>
            <div class="col-xl-2 col-md-4"><label class="form-label">Part Number</label><input name="partnumber" class="form-control" value="{{ $filters['partnumber'] }}"></div>
            <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit"><i class="fas fa-search me-1"></i>ค้นหา</button><a class="btn btn-outline-secondary" href="{{ route('accounting.product-group-profit.index') }}">ล้าง</a></div>
        </div>
    </form>

    <div class="pgp-panel p-3 mb-3 d-flex gap-4 pgp-kpis">
        @foreach([['ยอดขาย',$summary['sales']],['ต้นทุน',$summary['cost']],['กำไรขั้นต้น',$summary['gross_profit'],false,'pgp-kpi-profit'],['Margin',$summary['margin'],true,'pgp-kpi-profit'],['Data Issues',$summary['issue_count'],false,'pgp-kpi-issue']] as $kpi)
            <div class="pgp-kpi {{ $kpi[3] ?? '' }}"><small>{{ $kpi[0] }}</small><strong>{{ $fmt($kpi[1]) }}{{ ($kpi[2] ?? false) && $kpi[1] !== null ? '%' : '' }}</strong></div>
        @endforeach
    </div>

    <div class="pgp-panel overflow-auto">
        @if($limited)<div class="alert alert-warning rounded-0 mb-0">แสดงสูงสุด {{ number_format($limit) }} แถว กรุณากรองเพิ่มหรือ Export Excel</div>@endif
        <div class="pgp-scroll-hint"><i class="fas fa-arrows-left-right me-1"></i>เลื่อนซ้าย–ขวาเพื่อดูต้นทุน กำไร และ Data Issue</div>
        <table class="table table-sm table-striped mb-0 pgp-table">
            <thead><tr><th>วันที่</th><th>เลขที่เอกสาร</th><th>Customer ID</th><th>รายการ</th><th>หน่วย</th><th>จำนวน</th><th>ราคาขาย/หน่วย</th><th>จำนวนเงิน</th><th>ต้นทุนต่อหน่วย</th><th>ต้นทุน</th><th>กำไรขั้นต้น</th><th>กำไรขั้นต้น (%)</th><th>Data Issue</th></tr></thead>
            <tbody>
            @forelse($rows->groupBy(fn($row) => $row->production_site.'|'.$row->product_group) as $groupRows)
                @php $first=$groupRows->first(); $sales=(float)$groupRows->sum('sales_thb'); $cost=$groupRows->every(fn($row)=>$row->total_cost!==null)?(float)$groupRows->sum('total_cost'):null; $profit=$cost===null?null:$sales-$cost; @endphp
                <tr class="group-row"><td colspan="13">{{ $first->production_site }} · {{ $first->product_group }}</td></tr>
                @foreach($groupRows as $row)
                    <tr><td>{{ \Carbon\Carbon::parse($row->invoice_date)->format('d/m/').(\Carbon\Carbon::parse($row->invoice_date)->year+543) }}</td><td>{{ $row->invoice_no }}</td><td>{{ $row->customer_id }}</td><td><strong>{{ $row->partnumber }}</strong><br>{{ $row->item_description }}<br><span class="text-muted">MFG: {{ $row->mfg ?: '-' }}</span></td><td>{{ $row->unit }}</td><td class="num">{{ $fmt($row->quantity) }}</td><td class="num">{{ $fmt($row->unit_price) }}</td><td class="num">{{ $fmt($row->sales_thb) }}</td><td class="num cost">{{ $fmt($row->unit_cost) }}</td><td class="num">{{ $fmt($row->total_cost) }}</td><td class="num @if(($row->gross_profit ?? 0)<0) loss @endif">{{ $fmt($row->gross_profit) }}</td><td class="num @if(($row->margin ?? 0)<0) loss @endif">{{ $row->margin === null ? 'N/A' : $fmt($row->margin).'%' }}</td><td class="pgp-issue">{{ $row->data_issue ?: '-' }}</td></tr>
                @endforeach
                <tr class="subtotal"><td colspan="5">รวม {{ $first->product_group }}</td><td class="num">{{ $fmt($groupRows->sum('quantity')) }}</td><td></td><td class="num">{{ $fmt($sales) }}</td><td></td><td class="num">{{ $fmt($cost) }}</td><td class="num">{{ $fmt($profit) }}</td><td class="num">{{ $sales != 0 && $profit !== null ? $fmt(($profit/$sales)*100).'%' : 'N/A' }}</td><td>{{ $cost===null?'ยอดรวมไม่คำนวณ เพราะมีรายการไม่มีต้นทุน':'' }}</td></tr>
            @empty
                <tr><td colspan="13" class="text-center text-muted py-5">ไม่พบข้อมูลตามเงื่อนไข</td></tr>
            @endforelse
            @if($rows->isNotEmpty())<tr class="grand-total"><td colspan="7">GRAND TOTAL</td><td class="num">{{ $fmt($summary['sales']) }}</td><td></td><td class="num">{{ $fmt($summary['cost']) }}</td><td class="num">{{ $fmt($summary['gross_profit']) }}</td><td class="num">{{ $summary['margin']===null?'N/A':$fmt($summary['margin']).'%' }}</td><td></td></tr>@endif
            </tbody>
        </table>
    </div>
</div>
@endsection
