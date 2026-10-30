@extends('layouts.layout')
@section('title','Wire Rod Export History')
@section('page-title','Wire Rod Export History')
@push('styles')@include('formwr.deadstock._styles')@endpush
@section('content')
<div class="wr-shell">
<header class="wr-page-head"><div><h3>ประวัติ Export Excel</h3><p>ไฟล์ประกอบด้วย Item Summary และ Serial Detail ตาม Filter ที่เลือก</p></div><div class="wr-actions"><a href="{{ route('wr.deadstock.dashboard') }}" class="btn btn-outline-primary">กลับหน้าภาพรวม</a></div></header>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('warning'))<div class="alert alert-warning">{{ session('warning') }}</div>@endif
<div class="wr-panel"><div class="wr-panel-head"><h4>ไฟล์ของฉัน</h4><span class="small text-muted">Refresh หน้านี้เพื่อตรวจสถานะล่าสุด</span></div><div class="table-responsive"><table class="table wr-table"><thead><tr><th>เวลาขอ</th><th>สถานะ</th><th>Filter</th><th class="text-end">Rows</th><th>เสร็จเมื่อ</th><th></th></tr></thead><tbody>
@forelse($rows as $row)<tr><td>{{ $row->requested_at }}</td><td><span class="badge {{ $row->status==='COMPLETED'?'bg-success':($row->status==='FAILED'?'bg-danger':'bg-warning text-dark') }}">{{ $row->status }}</span>@if($row->error_message)<div class="small text-danger">{{ $row->error_message }}</div>@endif</td><td><code class="small">{{ $row->filter_json ?: '{}' }}</code></td><td class="text-end">{{ is_null($row->row_count)?'-':number_format($row->row_count) }}</td><td>{{ $row->completed_at ?: '-' }}</td><td>@if($row->status==='COMPLETED')<a class="btn btn-sm btn-success" href="{{ route('wr.deadstock.exports.download',$row->token) }}"><i class="fas fa-download"></i> Download</a>@endif</td></tr>
@empty<tr><td colspan="6" class="text-center text-muted py-4">ยังไม่มีประวัติ Export</td></tr>@endforelse
</tbody></table></div><div class="p-3 border-top">{{ $rows->links() }}</div></div>
</div>
@endsection
@include('formwr.deadstock._qol_scripts')
