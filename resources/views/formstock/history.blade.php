@extends('layouts.layout')
@section('title','ประวัติรายการเบิก')
@section('page-title','ประวัติรายการเบิก')
@section('content')
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3"><div><h4 class="mb-1">ประวัติ {{ $item->plan->mfg }} · {{ $item->partnumber }}</h4><div class="text-muted">การเปลี่ยนแปลงที่ระบบบันทึกไว้</div></div><a href="{{ url()->previous() }}" class="btn btn-outline-primary">กลับ</a></div>
    @php $latestLog=$logs->first();$latestActor=$latestLog?$users->get($latestLog->actor_id):null; @endphp
    @if($latestLog)<div class="alert alert-light border d-flex flex-wrap gap-2 align-items-center py-2"><strong>ผู้แก้ล่าสุด:</strong><span>{{ $latestActor->name??$latestActor->username??($latestLog->actor_id?'User #'.$latestLog->actor_id:'ระบบ') }}</span><span class="badge bg-secondary">{{ $latestLog->action }}</span><span class="text-muted">{{ \Carbon\Carbon::parse($latestLog->acted_at)->format('d/m/Y H:i:s') }}</span>@if($latestLog->reason)<span class="text-muted">· {{ $latestLog->reason }}</span>@endif</div>@endif
    <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><caption class="visually-hidden">ประวัติการเปลี่ยนแปลงรายการเบิก</caption><thead class="table-light"><tr><th scope="col">วันเวลา</th><th scope="col">รายการ</th><th scope="col">ผู้ดำเนินการ</th><th scope="col">เหตุผล/หมายเหตุ</th><th scope="col">ข้อมูลที่เปลี่ยน</th></tr></thead><tbody>
    @forelse($logs as $log)@php $before=json_decode($log->before_data??'{}',true)?:[];$after=json_decode($log->after_data??'{}',true)?:[];$actor=$users->get($log->actor_id); @endphp<tr><td class="text-nowrap">{{ \Carbon\Carbon::parse($log->acted_at)->format('d/m/Y H:i:s') }}</td><td><span class="badge bg-secondary">{{ $log->action }}</span></td><td>{{ $actor->name??$actor->username??($log->actor_id?'User #'.$log->actor_id:'ระบบ') }}</td><td>{{ $log->reason?:'-' }}</td><td><details><summary>ดูรายละเอียด</summary><div class="row g-2 mt-1"><div class="col-md-6"><div class="fw-semibold">ก่อน</div><pre class="small bg-light p-2">{{ json_encode($before,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></div><div class="col-md-6"><div class="fw-semibold">หลัง</div><pre class="small bg-light p-2">{{ json_encode($after,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></div></div></details></td></tr>
    @empty<tr><td colspan="5" class="text-center text-muted py-5">ยังไม่มีประวัติการเปลี่ยนแปลง</td></tr>@endforelse
    </tbody></table></div></div>
</div>
@endsection
