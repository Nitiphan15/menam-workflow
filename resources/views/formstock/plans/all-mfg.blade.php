@extends('layouts.layout')
@section('title','งานที่ผลิตทั้งหมด')
@section('page-title','งานที่ผลิตทั้งหมด')
@section('content')
@php $canPlan=auth()->user()?->can('STOCK_ACTION')||auth()->user()?->can('STOCK_ADMIN'); @endphp
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h4 class="mb-1">งานที่ผลิตทั้งหมด</h4><div class="text-muted">งานเปิด WIRE และ PLUS ที่ยังไม่มีแผนและยังเบิกไม่ครบ</div></div><div class="d-flex gap-2">@if($canPlan)<a href="{{ route('stock-withdrawal.index') }}" class="btn btn-outline-primary">แผนเบิกงาน</a>@endif <a href="{{ route('stock-withdrawal.all-mfg.export',request()->except('planned')) }}" class="btn btn-success">Export CSV</a></div></div>
    <div class="d-flex flex-wrap gap-2 mb-3" aria-label="ตัวกรองด่วน">
        <a class="btn btn-sm {{ empty($filters['site']) && empty($filters['production_type']) ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('stock-withdrawal.all-mfg',request()->except('page','site','planned','production_type')) }}">ทั้งหมด</a>
        <span class="border-start mx-1"></span>
        @foreach(['WIRE','PLUS'] as $site)<a class="btn btn-sm {{ ($filters['site']??'')===$site ? 'btn-dark' : 'btn-outline-dark' }}" href="{{ route('stock-withdrawal.all-mfg',array_merge(request()->except('page','site'),['site'=>$site])) }}">{{ $site }}</a>@endforeach
        <span class="border-start mx-1"></span>
        <a class="btn btn-sm {{ ($filters['production_type']??'')==='NCR' ? 'btn-warning' : 'btn-outline-warning' }}" href="{{ route('stock-withdrawal.all-mfg',array_merge(request()->except('page','production_type'),['production_type'=>'NCR'])) }}">NCR</a>
        <a class="btn btn-sm {{ ($filters['production_type']??'')==='ACID' ? 'btn-warning' : 'btn-outline-warning' }}" href="{{ route('stock-withdrawal.all-mfg',array_merge(request()->except('page','production_type'),['production_type'=>'ACID'])) }}">กัดกรด</a>
    </div>
    <form class="card card-body border-0 shadow-sm mb-3"><div class="row g-2 align-items-end">
        <div class="col-md-2"><label class="form-label">MFG</label><input name="mfg" value="{{ $filters['mfg']??'' }}" class="form-control"></div>
        <div class="col-md-1"><label class="form-label">โรงงาน</label><select name="site" class="form-select"><option value="">ทั้งหมด</option>@foreach(['WIRE','PLUS'] as $site)<option value="{{ $site }}" @selected(($filters['site']??'')===$site)>{{ $site }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="form-label">ประเภทงาน</label><select name="type" class="form-select"><option value="">ทั้งหมด</option>@foreach($jobTypes as $code=>$type)<option value="{{ $code }}" @selected(($filters['type']??'')===$code)>{{ $type['name'] }}</option>@endforeach</select></div>
        <div class="col-md-1"><label class="form-label">กลุ่มงาน</label><select name="production_type" class="form-select"><option value="">ทั้งหมด</option>@foreach(['FG'=>'FG','NCR'=>'NCR','ACID'=>'กัดกรด','RM'=>'RM','S'=>'S'] as $code=>$label)<option value="{{ $code }}" @selected(($filters['production_type']??'')===$code)>{{ $label }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="form-label">ส่งมอบตั้งแต่</label><input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="form-control"></div>
        <div class="col-md-2"><label class="form-label">ถึงวันที่</label><input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="form-control"></div>
        <div class="col-md-1"><label class="form-label">ต่อหน้า</label><select name="per_page" class="form-select">@foreach([10,20,50,100,200] as $n)<option value="{{ $n }}" @selected((int)($filters['per_page']??20)===$n)>{{ $n }}</option>@endforeach</select></div>
        <div class="col-md-1 d-flex gap-1"><button class="btn btn-primary">ค้นหา</button><a href="{{ route('stock-withdrawal.all-mfg') }}" class="btn btn-outline-secondary">ล้าง</a></div>
    </div></form>
    <form method="GET" action="{{ $canPlan?route('stock-withdrawal.create'):'#' }}" id="selection-form">
        <div class="d-flex justify-content-between align-items-center mb-2"><span class="text-muted small">พบ {{ number_format($rows->total()) }} MFG</span>@if($canPlan)<button class="btn btn-primary" id="plan-selected" disabled>วางแผนรายการที่เลือก (<span id="selected-count">0</span>)</button>@endif</div>
        <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th style="width:45px"><input type="checkbox" id="select-all"></th><th>Site / MFG</th><th>กลุ่มงาน</th><th>Step ปัจจุบัน</th><th>Part / ขนาด</th><th class="text-end">จำนวน</th><th>ลูกค้า</th><th>วันส่งมอบ</th><th>หมายเหตุ</th><th></th></tr></thead><tbody>
        @forelse($rows as $r)<tr><td>@if($canPlan)<input class="form-check-input mfg-check" type="checkbox" name="selected[]" value="{{ $r->site }}|{{ $r->workorder_id }}">@endif</td><td><span class="badge bg-dark">{{ $r->site }}</span><div class="fw-bold mt-1">{{ $r->mfg }}</div></td><td><span class="badge {{ $r->production_type_code==='ACID' ? 'bg-warning text-dark' : ($r->production_type_code==='NCR' ? 'bg-danger' : 'bg-secondary') }}">{{ $r->production_type_name }}</span></td><td>{{ $r->current_work_center ?: '-' }}</td><td><div class="text-primary">{{ $r->partnumber }}</div><small>{{ $r->size ?: '-' }}</small></td><td class="text-end">{{ number_format((float)$r->quantity,2) }} {{ $r->unit }}</td><td>{{ $r->customer_name ?: '-' }}</td><td>{{ $r->delivery_date }}</td><td class="small text-truncate" style="max-width:220px" title="{{ $r->mfg_note }}">{{ $r->mfg_note ?: '-' }}</td><td>@if($canPlan)<a href="{{ route('stock-withdrawal.create',['site'=>$r->site,'workorder_id'=>$r->workorder_id]) }}" class="btn btn-sm btn-outline-primary">วางแผน</a>@endif</td></tr>
        @empty<tr><td colspan="10" class="text-center text-muted py-5">ไม่พบ MFG ที่รอวางแผน</td></tr>@endforelse
        </tbody></table></div></div>
    </form>
    @if($rows->hasPages())<div class="d-flex justify-content-center mt-3">{{ $rows->onEachSide(1)->links() }}</div>@endif
</div>
<script>document.addEventListener('DOMContentLoaded',()=>{const boxes=[...document.querySelectorAll('.mfg-check')],all=document.getElementById('select-all'),btn=document.getElementById('plan-selected'),count=document.getElementById('selected-count');const sync=()=>{const n=boxes.filter(x=>x.checked).length;if(count)count.textContent=n;if(btn)btn.disabled=n===0;if(all)all.checked=boxes.length>0&&n===boxes.length;};boxes.forEach(x=>x.addEventListener('change',sync));all?.addEventListener('change',()=>{boxes.forEach(x=>x.checked=all.checked);sync();});});</script>
@endsection
