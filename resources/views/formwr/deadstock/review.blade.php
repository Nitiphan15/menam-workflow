@extends('layouts.layout')
@section('title','Wire Rod Dead Stock Review')
@section('page-title','Wire Rod Dead Stock Review')
@push('styles')
    @include('formwr.deadstock._styles')
@endpush
@section('content')
<div class="wr-shell">
<header class="wr-page-head"><div><h3>ติดตามรายการ Wire Rod</h3><p>ดูรายละเอียดและบันทึกการติดตามของแต่ละ Lot ได้จากปุ่ม Action</p></div><div class="wr-actions">@include('formwr.deadstock._export_actions')<a href="{{ route('wr.deadstock.dashboard',request()->query()) }}" class="btn btn-outline-primary"><i class="fas fa-gauge-high"></i> กลับหน้าภาพรวม</a></div></header>
@include('formwr.deadstock._filters')
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@error('review_updated_at')<div class="alert alert-warning">{{ $message }}</div>@enderror
<div class="wr-panel"><div class="wr-panel-head"><h4>รายการวัตถุดิบ</h4><div class="d-flex flex-wrap align-items-center gap-3"><span class="text-muted small"><i class="fas fa-circle-info me-1"></i>วางเมาส์ที่ <span class="wr-help-icon">?</span> หรือสถานะ Trace เพื่อดูคำอธิบาย · {{ number_format($items->total()) }} Lots</span>@include('formwr.deadstock._table_tools',['columns'=>['status'=>'สถานะ','purchase'=>'วันที่รับเข้า','item'=>'รายการ','serial'=>'Serial / Heat','age'=>'อายุ Stock','qty'=>'Qty','value'=>'มูลค่า','parties'=>'ลูกค้า / Sales','action'=>'Action']])</div></div><div class="table-responsive wr-table-scroll"><table class="table table-sm table-bordered table-hover align-middle wr-table"><thead><tr><th>#</th><th data-wr-col="status">สถานะ <span class="wr-help-icon" data-bs-toggle="tooltip" title="Incoming: อายุไม่เกิน 180 วัน · Dead Stock: มากกว่า 180 วัน · Unknown: ไม่มีวันที่รับเข้า">?</span></th><th data-wr-col="purchase" class="wr-date-col">วันที่รับเข้า <span class="wr-help-icon" data-bs-toggle="tooltip" title="วันที่รับวัตถุดิบเข้า Stock จาก ERP ใช้เป็นวันเริ่มคำนวณอายุ Stock">?</span></th><th data-wr-col="item">รายการ</th><th data-wr-col="serial">Serial / Heat</th><th data-wr-col="age">อายุ Stock <span class="wr-help-icon" data-bs-toggle="tooltip" title="จำนวนวันนับจากวันที่รับเข้า Stock ถึงวันที่ดึงข้อมูลล่าสุด">?</span></th><th data-wr-col="qty">Qty</th><th data-wr-col="value">มูลค่า <span class="wr-help-icon" data-bs-toggle="tooltip" title="Qty คงเหลือ × Unit Cost; แสดงเมื่อ ERP มีข้อมูลต้นทุน">?</span></th><th data-wr-col="parties">ลูกค้า / Sales <span class="wr-help-icon" data-bs-toggle="tooltip" title="อาจมีหลายรายใน Lot เดียว ระบบแสดงผู้เกี่ยวข้องทั้งหมดและไม่แบ่ง Qty จนกว่าจะมีกติกาจัดสรร">?</span></th><th data-wr-col="action">Action <span class="wr-help-icon" data-bs-toggle="tooltip" title="บันทึกสถานะติดตาม Remark แนวทางแก้ไข ป้องกัน และวันติดตามครั้งถัดไป">?</span></th></tr></thead><tbody>
@forelse($items as $r)
@php
    $canEditReview = (bool) ($r->can_edit_review ?? false);
    $reviewStatus = $r->review_status ?? 'open';
    $reviewStatusLabel = ['open'=>'ยังไม่ได้ติดตาม','waiting_follow_up'=>'กำลังติดตาม รอคำตอบ','in_progress'=>'มีกำหนดส่งมอบแล้ว'][$reviewStatus] ?? $reviewStatus;
    $reviewStatusClass = ['open'=>'bg-light text-primary','waiting_follow_up'=>'bg-warning-subtle text-warning-emphasis','in_progress'=>'bg-success-subtle text-success-emphasis'][$reviewStatus] ?? 'bg-light text-dark';
    $traceLabel = ['MAPPED'=>'Mapped','UNMAPPED'=>'Unmapped','MULTIPLE_ALLOCATION'=>'Multiple','SALES_MAPPING_CONFLICT'=>'Conflict'][$r->trace_status] ?? $r->trace_status;
    $traceHelp = ['MAPPED'=>'พบข้อมูลเชื่อมโยง Customer และ Sales แล้ว','UNMAPPED'=>'ยังไม่พบ Customer หรือ Sales จาก MFG/PO','MULTIPLE_ALLOCATION'=>'Lot นี้เกี่ยวข้องกับหลาย Customer หรือหลาย Sales จึงยังไม่แบ่ง Qty ให้รายใด','SALES_MAPPING_CONFLICT'=>'ข้อมูล Sales จาก MFG และ PO ไม่ตรงกัน ต้องให้ Planning/Procurement ตรวจสอบ'][$r->trace_status] ?? 'สถานะการเชื่อมโยงข้อมูลจาก ERP/MFG';
    $customers = collect(json_decode($r->customer_json ?: '[]', true))->filter()->unique()->values();
    $salesPeople = collect(json_decode($r->sales_json ?: '[]', true))->filter()->unique()->values();
    $followUp = $r->next_follow_up_date ? \Carbon\Carbon::parse($r->next_follow_up_date, 'Asia/Bangkok')->startOfDay() : null;
    $today = now('Asia/Bangkok')->startOfDay();
    $followUpState = !$followUp ? null : ($followUp->lt($today) ? 'overdue' : ($followUp->equalTo($today) ? 'today' : ($followUp->lte($today->copy()->addDays(3)) ? 'soon' : null)));
    $returnTo = request()->getRequestUri();
@endphp
<tr>
<td>{{ $items->firstItem()+$loop->index }}</td>
<td data-wr-col="status" class="wr-status-cell"><span class="badge {{ $r->stock_status==='DEAD'?'bg-danger':($r->stock_status==='UNKNOWN'?'bg-warning text-dark':'bg-success') }}">{{ $r->stock_status }}</span><div class="small text-muted mt-1">{{ $r->site_code }} · {{ $r->material_group }}</div><div class="small text-muted mt-1">Trace: <span class="wr-trace-tip" data-bs-toggle="tooltip" title="{{ $traceHelp }}">{{ $traceLabel }} <i class="fas fa-circle-info"></i></span></div></td>
<td data-wr-col="purchase" class="wr-date-col">{{ $r->purchase_date ?: '-' }}</td>
<td data-wr-col="item"><div class="d-flex align-items-center gap-1"><a href="{{ route('wr.deadstock.show',['lifecycle'=>$r->lifecycle_id,'return_to'=>$returnTo]) }}"><strong>{{ $r->part_number }}</strong></a><a class="wr-icon-link" target="_blank" rel="noopener" href="{{ route('wr.deadstock.show',$r->lifecycle_id) }}" title="เปิดรายละเอียดในแท็บใหม่"><i class="fas fa-arrow-up-right-from-square"></i></a><button type="button" class="wr-copy-btn" data-wr-copy="{{ $r->part_number }}" title="คัดลอก Part Number"><i class="far fa-copy"></i></button></div><div class="small text-muted">{{ $r->part_description ?: '-' }}</div></td>
<td data-wr-col="serial"><div class="d-flex align-items-center gap-1"><strong>{{ $r->serial_number }}</strong><button type="button" class="wr-copy-btn" data-wr-copy="{{ $r->serial_number }}" title="คัดลอก Serial"><i class="far fa-copy"></i></button></div></td>
<td data-wr-col="age">{{ is_null($r->stock_age_days)?'Unknown':number_format($r->stock_age_days).' วัน' }}</td>
<td data-wr-col="qty" class="text-end text-nowrap"><strong>{{ number_format($r->current_qty,4) }}</strong><div class="small text-muted">{{ $r->display_unit }}</div></td>
<td data-wr-col="value" class="text-end text-nowrap">{{ number_format($r->current_value_thb,2) }}<div class="small text-muted">THB</div></td>
<td data-wr-col="parties" class="wr-party-cell">
    <div class="wr-party-label">Customer</div>
    <div class="wr-party-list">@forelse($customers as $customer)<span class="wr-party-tag">{{ $customer }}</span>@empty<span class="wr-party-tag">Stock</span>@endforelse</div>
    <div class="wr-party-label mt-2">Sales</div>
    <div class="wr-party-list">@forelse($salesPeople as $sales)<span class="wr-party-tag wr-party-tag--sales">{{ $sales }}</span>@empty<span class="text-muted">-</span>@endforelse</div>
    @if($r->effective_division_code)<div class="small text-muted mt-1">Division: {{ $r->effective_division_code }}</div>@endif
    @if($r->override_id)<div class="mt-1"><span class="badge bg-info text-dark">Manual Override: {{ $r->effective_sales_name }}</span></div>@endif
</td>
<td data-wr-col="action" style="min-width:175px">
<button type="button" class="btn btn-sm btn-outline-primary w-100 js-wr-open-review" data-modal-url="{{ route('wr.deadstock.modal-data',$r->lifecycle_id) }}" data-lifecycle="{{ $r->lifecycle_id }}"><i class="fas fa-pen-to-square me-1"></i>{{ $canEditReview ? 'บันทึกงาน' : 'ดูรายละเอียด' }}</button>
<div class="mt-2"><span class="badge {{ $reviewStatusClass }}" data-review-status>{{ $reviewStatusLabel }}</span></div>
<div data-review-follow-up class="small mt-1 {{ $followUpState ? 'wr-follow-up wr-follow-up--'.$followUpState : 'text-muted' }}">@if($followUpState==='overdue')<i class="fas fa-triangle-exclamation me-1"></i>เลยกำหนด {{ $r->next_follow_up_date }}@elseif($followUpState==='today')<i class="fas fa-bell me-1"></i>ติดตามวันนี้@elseif($followUpState==='soon')<i class="fas fa-clock me-1"></i>ติดตามภายใน 3 วัน · {{ $r->next_follow_up_date }}@else{{ $r->next_follow_up_date ? 'ตามต่อ '.$r->next_follow_up_date : 'ยังไม่ได้กำหนดวันติดตาม' }}@endif</div>
<button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-2 js-wr-open-customer" data-modal-url="{{ route('wr.deadstock.modal-data',$r->lifecycle_id) }}" data-lifecycle="{{ $r->lifecycle_id }}"><i class="fas fa-user-plus me-1"></i>Customer เพิ่มเติม (<span class="js-wr-customer-count">{{ (int) $r->additional_customer_count }}</span>)</button>
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
@empty<tr><td colspan="10" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr>@endforelse
</tbody></table></div><div class="p-3 border-top">{{ $items->links() }}</div></div>

<div class="modal fade wr-central-modal" id="wr-review-modal" tabindex="-1" aria-labelledby="wr-review-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable"><form id="wr-review-form" class="modal-content" novalidate>
        @csrf<input type="hidden" name="review_updated_at">
        <div class="modal-header"><div><h5 class="modal-title" id="wr-review-modal-title">การติดตาม Lot</h5><div class="wr-modal-context js-wr-modal-context"></div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div>
        <div class="modal-body"><div class="js-wr-modal-loading text-center py-5"><span class="spinner-border text-primary" aria-hidden="true"></span><div class="mt-2 text-muted">กำลังโหลดข้อมูล Lot…</div></div><div class="js-wr-modal-content d-none"><div class="alert d-none js-wr-modal-message" role="alert"></div><div class="alert alert-secondary d-none js-wr-permission-message" role="status"></div><div class="row g-3">
            <div class="col-12 col-md-6"><label class="form-label">สถานะการติดตาม</label><select name="review_status" class="form-select"><option value="open">ยังไม่ได้ติดตาม</option><option value="waiting_follow_up">กำลังติดตาม รอคำตอบ</option><option value="in_progress">มีกำหนดส่งมอบแล้ว</option></select></div>
            <div class="col-12 col-md-6"><label class="form-label">วันติดตามครั้งถัดไป</label><input type="date" name="next_follow_up_date" class="form-control"></div>
            <div class="col-12"><label class="form-label">Customer และ Sales ที่เกี่ยวข้อง</label><div class="wr-related-box"><div class="wr-party-label">Customer จาก CPA</div><div class="wr-party-list js-wr-cpa-customers"></div><div class="wr-party-label mt-2">Sales</div><div class="wr-party-list js-wr-sales"></div></div></div>
            <div class="col-12"><label class="form-label">รายละเอียด</label><textarea name="review_detail" class="form-control" rows="4"></textarea></div>
            <div class="col-12"><label class="form-label">แนวทางแก้ไข</label><textarea name="corrective_action" class="form-control" rows="4"></textarea></div>
            <div class="col-12"><label class="form-label">แนวทางป้องกันการเกิดซ้ำ</label><textarea name="preventive_action" class="form-control" rows="4"></textarea></div>
            <div class="col-12"><label class="form-label">Remark</label><textarea name="sales_remark" class="form-control" rows="3"></textarea></div>
        </div></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button><button type="submit" class="btn btn-primary js-wr-save-review"><i class="fas fa-floppy-disk me-1"></i>บันทึกงาน</button></div>
    </form></div>
</div>

<div class="modal fade wr-central-modal" id="wr-customer-modal" tabindex="-1" aria-labelledby="wr-customer-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><div><h5 class="modal-title" id="wr-customer-modal-title">Customer เพิ่มเติม</h5><div class="wr-modal-context js-wr-modal-context"></div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div>
        <div class="modal-body"><div class="js-wr-modal-loading text-center py-5"><span class="spinner-border text-primary" aria-hidden="true"></span><div class="mt-2 text-muted">กำลังโหลดข้อมูล Lot…</div></div><div class="js-wr-modal-content d-none"><div class="alert d-none js-wr-modal-message" role="alert"></div><div class="alert alert-info mb-3"><i class="fas fa-circle-info me-1"></i>Customer เพิ่มเติมเป็นข้อมูลติดตามเท่านั้น ไม่เปลี่ยน Customer จาก CPA หรือ Sales Mapping</div><div class="alert alert-secondary d-none js-wr-permission-message" role="status"></div><div class="mb-3"><div class="wr-party-label">Customer จาก CPA</div><div class="wr-party-list js-wr-cpa-customers"></div></div><div class="mb-3"><div class="wr-party-label">Customer เพิ่มเติม</div><div class="js-wr-additional-list"></div></div>
        <form id="wr-customer-form" class="row g-3 border-top pt-3" novalidate>@csrf<input type="hidden" name="supersedes_id"><div class="col-12"><div class="d-flex align-items-center justify-content-between"><strong class="js-wr-customer-form-title">เพิ่ม Customer</strong><button type="button" class="btn btn-sm btn-link d-none js-wr-cancel-revision">ยกเลิกการแก้ไข</button></div></div><div class="col-md-6"><label class="form-label">ชื่อลูกค้า</label><input name="customer_name" class="form-control" required maxlength="255"></div><div class="col-md-6"><label class="form-label">เหตุผล</label><input name="reason" class="form-control" required maxlength="255"></div><div class="col-12"><label class="form-label">คำอธิบาย</label><textarea name="explanation" class="form-control" rows="3" required maxlength="2000"></textarea></div><div class="col-12 text-end"><button type="submit" class="btn btn-primary js-wr-save-customer"><i class="fas fa-floppy-disk me-1"></i>เพิ่ม Customer</button></div></form></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button></div>
    </div></div>
</div>
</div>
@endsection
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.bootstrap?.Tooltip) return;
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (element) {
        bootstrap.Tooltip.getOrCreateInstance(element, { container: 'body', trigger: 'hover focus' });
    });
});
</script>
@endpush
@include('formwr.deadstock._qol_scripts')
@include('formwr.deadstock._review_modals_scripts')
