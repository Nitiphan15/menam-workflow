@extends('layouts.layout')
@section('title', 'รายละเอียดการเบิก')
@section('page-title', 'รายละเอียดการเบิก')
@section('content')
@php
    $totalIssued = $details->sum(fn($row) => (float) ($row->qty ?? 0));
    $firstIssued = $details->min('requeststamp');
    $lastIssued = $details->max('requeststamp');
    $formatDateTime = fn($value) => $value ? \Carbon\Carbon::parse($value)->format('d/m/Y H:i') : '-';
@endphp
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1"><span class="badge bg-dark fs-6">{{ $context['site'] }}</span><strong class="fs-4">{{ $context['mfg'] ?? '-' }}</strong></div>
            <div class="text-primary fw-semibold fs-5">{{ $context['partnumber'] ?? '-' }}</div>
        </div>
        <a href="{{ url()->previous() }}" class="btn btn-outline-primary">← กลับหน้าติดตาม</a>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">เบิกแล้วรวม</div><div class="fs-3 fw-bold text-primary">{{ number_format($totalIssued, 2) }} <span class="fs-6 text-muted">KG.</span></div></div></div></div>
        <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">จำนวนครั้งที่เบิก</div><div class="fs-3 fw-bold">{{ number_format($details->count()) }} <span class="fs-6 text-muted">รายการ</span></div></div></div></div>
        <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">ช่วงเวลาการเบิก</div><div class="fw-semibold mt-1">{{ $formatDateTime($firstIssued) }}</div>@if($details->count() > 1)<div class="small text-muted">ถึง {{ $formatDateTime($lastIssued) }}</div>@endif</div></div></div>
    </div>

    <div class="card border-0 shadow-sm overflow-hidden">
        <div class="card-header bg-white d-flex justify-content-between align-items-center"><strong>ประวัติการเบิก</strong><span class="text-muted small">เรียงจากเก่าไปใหม่</span></div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th class="text-center" style="width:75px">ครั้งที่</th><th>เลขเอกสาร</th><th class="text-end">จำนวนเบิก (KG.)</th><th>ผู้เบิก</th><th>วันที่เบิก</th><th>แก้ไขล่าสุด</th></tr></thead>
            <tbody>@forelse($details as $detail)<tr>
                <td class="text-center"><span class="badge rounded-pill bg-light text-dark border">{{ $loop->iteration }}</span></td>
                <td><strong>{{ $detail->documentber ?? '-' }}</strong></td>
                <td class="text-end"><strong class="fs-5 text-primary">{{ number_format((float) ($detail->qty ?? 0), 2) }}</strong></td>
                <td>{{ $detail->requestedby ?? '-' }}</td>
                <td class="text-nowrap">{{ $formatDateTime($detail->requeststamp ?? null) }}</td>
                <td class="text-nowrap text-muted">{{ $formatDateTime($detail->lastupdate ?? null) }}</td>
            </tr>@empty<tr><td colspan="6" class="text-center text-muted py-5"><div class="fs-5">ยังไม่มีรายการเบิก</div><div class="small mt-1">ระบบยังไม่พบการตัดยอดของ MFG และ Part นี้</div></td></tr>@endforelse</tbody>
        </table></div>
    </div>
</div>
@endsection
