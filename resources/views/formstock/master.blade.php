@extends('layouts.layout')
@section('title', 'Standard Part Master')
@section('page-title', 'Standard Part Master')
@section('content')
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between mb-3"><div><h4>Standard Part Master</h4><div class="text-muted">กำหนดจำนวนวันก่อน MFG Due Date ที่ควรเบิก</div></div><a href="{{ route('stock-withdrawal.index') }}" class="btn btn-outline-primary">กลับหน้าติดตาม</a></div>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('stock-withdrawal.master.store') }}" class="card card-body shadow-sm mb-3">@csrf
        <h6>เพิ่ม Standard</h6><div class="row g-2">
        <div class="col-md-1"><label>Site</label><select name="site" class="form-select" required>@foreach(['WIRE','PLUS','ALL'] as $v)<option>{{ $v }}</option>@endforeach</select></div>
        <div class="col-md-2"><label>Part</label><input name="partnumber" class="form-control" required></div><div class="col-md-2"><label>Description</label><input name="description" class="form-control"></div>
        <div class="col-md-1"><label>Standard</label><input type="number" min="0" name="standard_days" class="form-control" required></div><div class="col-md-1"><label>Warning</label><input type="number" min="0" name="warning_days" value="2" class="form-control" required></div>
        <div class="col-md-1"><label>ประเภทวัน</label><select name="day_type" class="form-select"><option value="WORKING">จันทร์–เสาร์</option></select></div>
        <div class="col-md-2"><label>ผู้รับผิดชอบ</label><input name="responsible_name" class="form-control"></div><div class="col-md-2"><label>Email</label><input type="email" name="responsible_email" class="form-control"></div>
        <div class="col-md-2"><label>เริ่มใช้</label><input type="date" name="effective_from" value="{{ now('Asia/Bangkok')->toDateString() }}" class="form-control" required></div><div class="col-md-2"><label>สิ้นสุด</label><input type="date" name="effective_to" class="form-control"></div>
        <div class="col-md-1"><label>Active</label><select name="is_active" class="form-select"><option value="1">Yes</option><option value="0">No</option></select></div><div class="col-md-5"><label>Remark</label><input name="remark" class="form-control"></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">เพิ่ม Standard</button></div>
        </div>
    </form>
    <form method="GET" class="row g-2 mb-3"><div class="col-md-2"><select name="site" class="form-select"><option value="">ทุก Site</option>@foreach(['WIRE','PLUS','ALL'] as $v)<option @selected(request('site')===$v)>{{ $v }}</option>@endforeach</select></div><div class="col-md-4"><input name="keyword" value="{{ request('keyword') }}" class="form-control" placeholder="Part / Description"></div><div class="col-md-2"><button class="btn btn-secondary">ค้นหา</button></div></form>
    <div class="card shadow-sm"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Site</th><th>Part</th><th>Description</th><th>Standard</th><th>Warning</th><th>Day Type</th><th>Responsible</th><th>Effective</th><th>Active</th><th>แก้ไข</th></tr></thead><tbody>
    @forelse($standards as $s)<tr><form method="POST" action="{{ route('stock-withdrawal.master.update',$s) }}">@csrf @method('PUT')
        <td><select name="site" class="form-select form-select-sm">@foreach(['WIRE','PLUS','ALL'] as $v)<option @selected($s->site===$v)>{{ $v }}</option>@endforeach</select></td>
        <td><input name="partnumber" value="{{ $s->partnumber }}" class="form-control form-control-sm" required></td><td><input name="description" value="{{ $s->description }}" class="form-control form-control-sm"></td>
        <td><input type="number" name="standard_days" value="{{ $s->standard_days }}" class="form-control form-control-sm" required></td><td><input type="number" name="warning_days" value="{{ $s->warning_days }}" class="form-control form-control-sm" required></td>
        <td><select name="day_type" class="form-select form-select-sm"><option value="WORKING" @selected($s->day_type==='WORKING')>จันทร์–เสาร์</option></select></td>
        <td><input name="responsible_name" value="{{ $s->responsible_name }}" class="form-control form-control-sm"><input type="email" name="responsible_email" value="{{ $s->responsible_email }}" class="form-control form-control-sm mt-1"></td>
        <td><input type="date" name="effective_from" value="{{ optional($s->effective_from)->format('Y-m-d') }}" class="form-control form-control-sm"><input type="date" name="effective_to" value="{{ optional($s->effective_to)->format('Y-m-d') }}" class="form-control form-control-sm mt-1"></td>
        <td><select name="is_active" class="form-select form-select-sm"><option value="1" @selected($s->is_active)>Yes</option><option value="0" @selected(!$s->is_active)>No</option></select><input type="hidden" name="remark" value="{{ $s->remark }}"></td><td><button class="btn btn-sm btn-primary">บันทึก</button></td>
    </form></tr>@empty<tr><td colspan="10" class="text-center text-muted">ยังไม่มี Standard</td></tr>@endforelse</tbody></table></div></div>
    <div class="mt-3">{{ $standards->links() }}</div>
</div>
@endsection
