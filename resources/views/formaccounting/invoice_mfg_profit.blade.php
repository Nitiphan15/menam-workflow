@extends('layouts.layout')

@section('title', 'Invoice-MFG Gross Profit')
@section('page-title', 'Invoice-MFG Gross Profit')

@section('content')
@php
    $fmt = fn($v, $d = 2) => $v === null ? '-' : number_format((float) $v, $d);
    $query = array_filter($filters, fn($v) => $v !== '' && $v !== null);
@endphp
<style>
    .gp-wrap{background:#f6f8fb;padding:16px;border-radius:10px}.gp-card{background:#fff;border:1px solid #dde4ed;border-radius:10px;overflow:hidden}.gp-head{padding:12px 16px;border-bottom:1px solid #dde4ed;font-weight:700}.gp-body{padding:16px}.gp-kpi{background:#fff;border:1px solid #dde4ed;border-top:3px solid #2563eb;border-radius:9px;padding:13px;height:100%}.gp-kpi .label{font-size:.75rem;color:#667085;font-weight:700}.gp-kpi .value{font-size:1.25rem;font-weight:800;font-variant-numeric:tabular-nums}.gp-table-wrap{max-height:650px;overflow:auto}.gp-table{min-width:1900px;margin:0}.gp-table th{position:sticky;top:0;z-index:2;background:#334155;color:#fff;white-space:nowrap;font-size:.75rem}.gp-table td{vertical-align:top}.num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}.loss{color:#b91c1c;font-weight:700}.issue{color:#b45309;max-width:260px}.desc{min-width:300px;white-space:pre-line}
</style>
<div class="gp-wrap">
    <div class="gp-card mb-3">
        <div class="gp-head"><i class="fas fa-filter text-primary me-1"></i> ตัวกรอง Invoice</div>
        <form method="GET" action="{{ route('accounting.invoice-mfg-profit.index') }}" class="gp-body">
            <div class="row g-3 align-items-end">
                <div class="col-lg-2 col-md-4"><label class="form-label">Invoice Date From</label><input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] }}"></div>
                <div class="col-lg-2 col-md-4"><label class="form-label">Invoice Date To</label><input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] }}"></div>
                <div class="col-lg-1 col-md-4"><label class="form-label">Site</label><select name="site" class="form-select">@foreach(['ALL','WIRE','PLUS'] as $site)<option @selected($filters['site']===$site)>{{ $site }}</option>@endforeach</select></div>
                @foreach(['invoice'=>'Invoice','sales_order'=>'Sales Order','mfg'=>'MFG','customer'=>'Customer','partnumber'=>'Part Number'] as $name=>$label)
                    <div class="col-lg-{{ $name==='customer' ? 2 : 1 }} col-md-4"><label class="form-label">{{ $label }}</label><input name="{{ $name }}" class="form-control" value="{{ $filters[$name] }}"></div>
                @endforeach
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary"><i class="fas fa-search me-1"></i>ค้นหา</button>
                    <a class="btn btn-outline-secondary" href="{{ route('accounting.invoice-mfg-profit.index') }}">ล้าง</a>
                    <a class="btn btn-success ms-auto" href="{{ route('accounting.invoice-mfg-profit.export', $query) }}"><i class="fas fa-file-excel me-1"></i>Export Excel</a>
                </div>
            </div>
        </form>
    </div>

    <div class="row g-3 mb-3">
        @foreach([
            ['ยอดขาย (THB)', $fmt($summary['sales'])], ['ต้นทุน', $fmt($summary['cost'])],
            ['กำไรขั้นต้น', $fmt($summary['gross_profit'])], ['Margin', $fmt($summary['margin']).'%'],
            ['Invoice', number_format($summary['invoice_count'])], ['MFG', number_format($summary['mfg_count'])],
            ['Data Issues', number_format($summary['issue_count'])]
        ] as [$label,$value])
        <div class="col-xl col-md-3 col-6"><div class="gp-kpi"><div class="label">{{ $label }}</div><div class="value">{{ $value }}</div></div></div>
        @endforeach
    </div>

    @if($summary['years']->isNotEmpty())
    <div class="gp-card mb-3"><div class="gp-head">Summary รายปี</div><div class="table-responsive"><table class="table table-sm table-striped mb-0"><thead><tr><th>ปี</th><th class="num">Rows</th><th class="num">Sales</th><th class="num">Cost</th><th class="num">Gross Profit</th><th class="num">Margin</th></tr></thead><tbody>
        @foreach($summary['years'] as $y)<tr><td>{{ $y['year']+543 }}</td><td class="num">{{ number_format($y['rows']) }}</td><td class="num">{{ $fmt($y['sales']) }}</td><td class="num">{{ $fmt($y['cost']) }}</td><td class="num @if($y['gross_profit']<0) loss @endif">{{ $fmt($y['gross_profit']) }}</td><td class="num">{{ $fmt($y['margin']) }}%</td></tr>@endforeach
    </tbody></table></div></div>
    @endif

    <div class="gp-card">
        <div class="gp-head d-flex justify-content-between"><span>Invoice-MFG Detail</span><span class="text-muted small">แสดงสูงสุด {{ number_format($limit) }} แถวบนหน้าจอ; Excel ส่งออกข้อมูลครบแยกปี</span></div>
        @if($limited)<div class="alert alert-warning rounded-0 mb-0">ผลลัพธ์เกิน {{ number_format($limit) }} แถว กรุณาใช้ตัวกรองหรือ Export Excel</div>@endif
        <div class="gp-table-wrap"><table class="table table-sm table-striped gp-table"><thead><tr>
            <th>Site</th><th>วันที่</th><th>Invoice</th><th>SO</th><th>MFG</th><th>ลูกค้า</th><th>Ship-to</th><th>รายการ</th><th>หน่วย</th><th class="num">จำนวน</th><th class="num">ราคาขาย/หน่วย</th><th class="num">จำนวนเงิน THB</th><th class="num">ต้นทุน/หน่วย</th><th class="num">ต้นทุน</th><th class="num">กำไรขั้นต้น</th><th class="num">Margin</th><th>Data Issue</th>
        </tr></thead><tbody>
        @forelse($rows as $r)<tr>
            <td>{{ $r->site }}</td><td>{{ $r->invoice_date }}</td><td>{{ $r->invoice_no }}</td><td>{{ $r->sales_order }}</td><td>{{ $r->mfg ?: '-' }}</td><td>{{ $r->customer_name }}</td><td>{{ $r->ship_to }}</td><td class="desc"><strong>{{ $r->partnumber }}</strong><br>{{ $r->item_description }}</td><td>{{ $r->unit }}</td><td class="num">{{ $fmt($r->quantity) }}</td><td class="num">{{ $fmt($r->unit_price) }}</td><td class="num">{{ $fmt($r->sales_thb) }}</td><td class="num">{{ $fmt($r->unit_cost) }}</td><td class="num">{{ $fmt($r->total_cost) }}</td><td class="num @if($r->gross_profit<0) loss @endif">{{ $fmt($r->gross_profit) }}</td><td class="num @if($r->margin<0) loss @endif">{{ $fmt($r->margin) }}%</td><td class="issue">{{ $r->data_issue ?: '-' }}</td>
        </tr>@empty<tr><td colspan="17" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr>@endforelse
        </tbody></table></div>
    </div>
</div>
@endsection
