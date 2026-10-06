@extends('layouts.layout')
@section('title', 'Stock Withdrawal Issue Detail')
@section('page-title', 'Stock Withdrawal Issue Detail')
@section('content')
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h4>รายละเอียดการเบิก</h4><div class="text-muted">{{ $context['site'] }} · {{ $context['mfg'] ?? '' }} · {{ $context['partnumber'] ?? '' }}</div></div>
        <a href="{{ url()->previous() }}" class="btn btn-outline-primary">กลับ</a>
    </div>
    <div class="card shadow-sm"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
        <thead class="table-light"><tr>@foreach(array_keys((array) ($details->first() ?? [])) as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
        <tbody>@forelse($details as $detail)<tr>@foreach((array) $detail as $value)<td>{{ is_scalar($value) ? $value : json_encode($value) }}</td>@endforeach</tr>@empty<tr><td class="text-center text-muted py-4">ยังไม่มีรายการเบิก</td></tr>@endforelse</tbody>
    </table></div></div>
</div>
@endsection
