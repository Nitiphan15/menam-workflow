@extends('layouts.layout')
@section('title','Wire Rod Dead Stock Review')
@section('page-title','Wire Rod Dead Stock Review')
@push('styles')
    @include('formwr.deadstock._styles')
@endpush
@section('content')
<div class="wr-shell">
<header class="wr-page-head"><div><h3>ติดตามและจัดการ Dead Stock</h3><p>แต่ละรายการแยกประวัติตาม Site + Serial + Cycle</p></div><div class="wr-actions"><a href="{{ route('wr.deadstock.dashboard',request()->query()) }}" class="btn btn-outline-primary"><i class="fas fa-gauge-high"></i> กลับ Dashboard</a></div></header>
@include('formwr.deadstock._filters')
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
<div class="wr-panel"><div class="wr-panel-head"><h4>รายการวัตถุดิบ</h4><span class="text-muted small">{{ number_format($items->total()) }} Lots</span></div><div class="table-responsive"><table class="table table-sm table-bordered table-hover align-middle wr-table"><thead><tr><th>#</th><th>สถานะ</th><th>วันที่รับเข้า</th><th>รายการ</th><th>Serial / Heat</th><th>อายุ Stock</th><th>Qty</th><th>มูลค่า</th><th>ลูกค้า / Sales</th><th>Trace</th><th>Action</th></tr></thead><tbody>
@forelse($items as $r)
@php
    $canEditReview = Illuminate\Support\Facades\Gate::any(['WR_DS_EDIT_OWN','WR_DS_MANAGE_ALL','WR_DS_ADMIN']);
    $reviewStatus = $r->review_status ?? 'open';
    $reviewStatusLabel = ['open'=>'ยังไม่ได้ติดตาม','waiting_follow_up'=>'กำลังติดตาม / รอคำตอบ','in_progress'=>'มีกำหนดดำเนินการแล้ว'][$reviewStatus] ?? $reviewStatus;
    $reviewStatusClass = ['open'=>'bg-light text-primary','waiting_follow_up'=>'bg-warning-subtle text-warning-emphasis','in_progress'=>'bg-success-subtle text-success-emphasis'][$reviewStatus] ?? 'bg-light text-dark';
@endphp
<tr>
<td>{{ $items->firstItem()+$loop->index }}</td>
<td><span class="badge {{ $r->stock_status==='DEAD'?'bg-danger':($r->stock_status==='UNKNOWN'?'bg-warning text-dark':'bg-success') }}">{{ $r->stock_status }}</span><div class="small text-muted mt-1">{{ $r->site_code }} · {{ $r->material_group }}</div></td>
<td>{{ $r->purchase_date ?: '-' }}</td>
<td><a href="{{ route('wr.deadstock.show',$r->lifecycle_id) }}"><strong>{{ $r->part_number }}</strong></a><div class="small text-muted">{{ $r->part_description ?: '-' }}</div></td>
<td><strong>{{ $r->serial_number }}</strong></td>
<td>{{ is_null($r->stock_age_days)?'Unknown':number_format($r->stock_age_days).' วัน' }}</td>
<td class="text-end text-nowrap"><strong>{{ number_format($r->current_qty,4) }}</strong><div class="small text-muted">{{ $r->display_unit }}</div></td>
<td class="text-end text-nowrap">{{ number_format($r->current_value_thb,2) }}<div class="small text-muted">THB</div></td>
<td><div>{{ collect(json_decode($r->customer_json ?: '[]',true))->implode(', ') ?: '-' }}</div><small>{{ $r->effective_sales_name ?: '-' }} / {{ $r->effective_division_code ?: '-' }}</small>@if($r->override_id)<div><span class="badge bg-info text-dark">Manual Override</span></div>@endif</td>
<td><span class="badge bg-secondary">{{ $r->trace_status }}</span><div class="small text-muted">{{ $r->mapping_source }}</div></td>
<td style="min-width:175px">
<button type="button" class="btn btn-sm btn-outline-primary w-100" data-bs-toggle="modal" data-bs-target="#wr-review-modal-{{ $r->lifecycle_id }}"><i class="fas fa-pen-to-square me-1"></i>{{ $canEditReview ? 'บันทึกงาน' : 'ดูรายละเอียด' }}</button>
<div class="mt-2"><span class="badge {{ $reviewStatusClass }}">{{ $reviewStatusLabel }}</span></div>
<div class="small text-muted mt-1">{{ $r->next_follow_up_date ? 'ตามต่อ '.$r->next_follow_up_date : 'ยังไม่ได้กำหนดวันติดตาม' }}</div>
@canany(['WR_DS_MANAGE_ALL','WR_DS_ADMIN'])<details class="mt-2"><summary class="small text-primary">Manual Override</summary><form method="POST" action="{{ route('wr.deadstock.override.store',$r->lifecycle_id) }}" class="row g-1 mt-1">@csrf<div class="col-6"><input name="sales_name" required class="form-control form-control-sm" placeholder="Sales"></div><div class="col-3"><input name="division_code" required class="form-control form-control-sm" placeholder="D1-D9"></div><div class="col-3"><input name="reason_code" required class="form-control form-control-sm" placeholder="Reason"></div><div class="col-9"><input name="remark" required class="form-control form-control-sm" placeholder="Remark"></div><div class="col-3"><button class="btn btn-sm btn-warning">Override</button></div></form></details>@endcanany
@can('WR_DS_ADMIN')
    @if($r->override_id)
        <form method="POST" action="{{ route('wr.deadstock.override.revoke',$r->override_id) }}" class="d-flex gap-1 mt-1">
            @csrf
            <input name="revoke_reason" required class="form-control form-control-sm" placeholder="เหตุผลยกเลิก">
            <button class="btn btn-sm btn-outline-danger">Revoke</button>
        </form>
    @endif
@endcan
</td></tr>
<div class="modal fade" id="wr-review-modal-{{ $r->lifecycle_id }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content" method="POST" action="{{ route('wr.deadstock.review.save',$r->lifecycle_id) }}">
            @csrf
            <div class="modal-header"><div><h5 class="modal-title mb-1">{{ $canEditReview ? 'บันทึกการติดตาม' : 'รายละเอียดการติดตาม' }}</h5><div class="text-muted small">{{ $r->part_number }} · {{ $r->serial_number }} · {{ $r->site_code }}</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><div class="row g-3">
                <div class="col-12 col-md-6"><label class="form-label">สถานะการติดตาม</label><select name="review_status" class="form-select" @disabled(!$canEditReview)><option value="open" @selected($reviewStatus==='open')>ยังไม่ได้ติดตาม</option><option value="waiting_follow_up" @selected($reviewStatus==='waiting_follow_up')>กำลังติดตาม / รอคำตอบ</option><option value="in_progress" @selected($reviewStatus==='in_progress')>มีกำหนดดำเนินการแล้ว</option></select></div>
                <div class="col-12 col-md-6"><label class="form-label">วันติดตามครั้งถัดไป</label><input type="date" name="next_follow_up_date" class="form-control" value="{{ $r->next_follow_up_date }}" @disabled(!$canEditReview)></div>
                <div class="col-12"><label class="form-label">ลูกค้า / Sales</label><div class="form-control bg-light">{{ collect(json_decode($r->customer_json ?: '[]',true))->implode(', ') ?: '-' }} / {{ $r->effective_sales_name ?: '-' }} ({{ $r->effective_division_code ?: '-' }})</div></div>
                <div class="col-12"><label class="form-label">รายละเอียด</label><textarea name="review_detail" class="form-control" rows="4" @disabled(!$canEditReview)>{{ $r->review_detail }}</textarea></div>
                <div class="col-12"><label class="form-label">แนวทางแก้ไข</label><textarea name="corrective_action" class="form-control" rows="4" @disabled(!$canEditReview)>{{ $r->corrective_action }}</textarea></div>
                <div class="col-12"><label class="form-label">แนวทางป้องกันการเกิดซ้ำ</label><textarea name="preventive_action" class="form-control" rows="4" @disabled(!$canEditReview)>{{ $r->preventive_action }}</textarea></div>
                <div class="col-12"><label class="form-label">Remark</label><textarea name="sales_remark" class="form-control" rows="3" @disabled(!$canEditReview)>{{ $r->sales_remark }}</textarea></div>
            </div></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button>@if($canEditReview)<button class="btn btn-primary"><i class="fas fa-floppy-disk me-1"></i>บันทึกงาน</button>@endif</div>
        </form>
    </div>
</div>
@empty<tr><td colspan="11" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr>@endforelse
</tbody></table></div><div class="p-3 border-top">{{ $items->links() }}</div></div>
</div>
@endsection
