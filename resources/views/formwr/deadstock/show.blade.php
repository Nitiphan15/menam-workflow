@extends('layouts.layout')
@section('title','Wire Rod Lot Detail')
@section('page-title','Wire Rod Dead Stock Detail')
@push('styles')
    @include('formwr.deadstock._styles')
@endpush
@section('content')
<div class="wr-shell">
@php
    $returnTo = (string) request('return_to', '');
    $basePath = request()->getBaseUrl().'/wr/deadstock';
    $safeReturnTo = str_starts_with($returnTo, $basePath) ? $returnTo : route('wr.deadstock.review');
@endphp
<header class="wr-page-head"><div><h3>{{ $item->part_number }}</h3><p>{{ $item->site_code }} · {{ $item->serial_number }} · Cycle {{ $item->cycle_no }}</p></div><div class="wr-actions"><a href="{{ $safeReturnTo }}" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> กลับ Review ตำแหน่งเดิม</a></div></header>
@if($warning)<div class="alert alert-warning">{{ $warning }} — แสดง cache ล่าสุดแทน</div>@endif
<div class="alert alert-light border">Data retrieved at: {{ $live ? now('Asia/Bangkok')->format('Y-m-d H:i:s') : $item->source_retrieved_at }}</div>
<div class="row g-3 mb-3">
@foreach([['Stock Status',$item->stock_status],['Purchase Date',$item->purchase_date ?: 'Unknown'],['Age Days',$item->stock_age_days ?? 'Unknown'],['Quantity',number_format($item->current_qty,4).' '.$item->display_unit],['Value',number_format($item->current_value_thb,2).' THB'],['Trace',$item->trace_status],['Sales',$item->effective_sales_name ?: '-'],['Division',$item->effective_division_code ?: '-']] as $v)<div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><small class="text-muted">{{ $v[0] }}</small><div class="fw-bold">{{ $v[1] }}</div></div></div></div>@endforeach
</div>
<div class="row g-3"><div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-header bg-white fw-bold">ERP/MFG Trace ล่าสุด</div><div class="card-body"><div><strong>MFG:</strong> {{ collect(json_decode($item->mfg_json ?: '[]',true))->pluck('mfg')->filter()->unique()->implode(', ') ?: '-' }}</div><div><strong>Sales Order:</strong> {{ collect(json_decode($item->sales_order_json ?: '[]',true))->implode(', ') ?: '-' }}</div><div><strong>Customer จาก CPA:</strong> {{ collect(json_decode($item->customer_json ?: '[]',true))->implode(', ') ?: 'Stock' }}</div><div><strong>Sales ที่เกี่ยวข้อง:</strong> {{ collect(json_decode($item->sales_json ?: '[]',true))->implode(', ') ?: '-' }}</div>@if($live)<hr><small class="text-success">อ่าน Lot นี้จาก ERP โดยตรงสำเร็จ</small>@endif</div></div></div>
<div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-header bg-white fw-bold">Override History</div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Sales</th><th>Division</th><th>Reason</th><th>Status</th></tr></thead><tbody>@forelse($overrides as $o)<tr><td>{{ $o->sales_name }}</td><td>{{ $o->division_code }}</td><td>{{ $o->reason_code }}<div class="small">{{ $o->remark }}</div></td><td>{{ $o->is_active?'Active':'Revoked' }}</td></tr>@empty<tr><td colspan="4" class="text-muted">ไม่มี Override</td></tr>@endforelse</tbody></table></div></div></div></div>
<div class="card border-0 shadow-sm mt-3"><div class="card-header bg-white fw-bold">Audit Log</div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>เวลา</th><th>Action</th><th>ผู้แก้</th><th>Fields</th></tr></thead><tbody>@forelse($reviews as $log)<tr><td>{{ $log->changed_at }}</td><td>{{ $log->action }}</td><td>{{ $log->changed_by_name }}</td><td>{{ $log->changed_fields }}</td></tr>@empty<tr><td colspan="4" class="text-muted">ยังไม่มีประวัติ</td></tr>@endforelse</tbody></table></div></div>
<div class="card border-0 shadow-sm mt-3"><div class="card-header bg-white fw-bold">Customer เพิ่มเติมและ Revision History</div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Revision</th><th>Customer</th><th>เหตุผล / คำอธิบาย</th><th>ผู้บันทึก</th><th>สถานะ</th><th></th></tr></thead><tbody>@forelse($additionalCustomers as $customer)<tr><td>{{ $customer->revision_no }}</td><td>{{ $customer->customer_name }}</td><td>{{ $customer->reason }}<div class="small text-muted">{{ $customer->explanation }}</div></td><td>{{ $customer->created_by_name }}<div class="small text-muted">{{ $customer->created_at }}</div></td><td>{{ $customer->is_active ? 'Active' : ($customer->revoked_at ? 'Revoked' : 'Superseded') }}</td><td>@can('WR_DS_ADMIN')@if($customer->is_active)<form method="POST" action="{{ route('wr.deadstock.customer.revoke',$customer->id) }}" class="d-flex gap-1">@csrf<input name="revoke_reason" required class="form-control form-control-sm" placeholder="เหตุผลยกเลิก"><button class="btn btn-sm btn-outline-danger">ยกเลิก</button></form>@endif @endcan</td></tr>@empty<tr><td colspan="6" class="text-muted">ยังไม่มี Customer เพิ่มเติม</td></tr>@endforelse</tbody></table></div></div>
</div>
@endsection
@include('formwr.deadstock._qol_scripts')
