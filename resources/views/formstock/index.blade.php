@extends('layouts.layout')

@section('title', 'Stock Withdrawal Alert')
@section('page-title', 'Stock Withdrawal Alert')
@push('head')<meta http-equiv="refresh" content="300">@endpush

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h4 class="mb-1">ติดตามการเบิก Stock ตาม MFG + Part</h4><div class="text-muted">วันที่ควรเบิก = MFG Due Date - Standard Time (จันทร์–เสาร์) · อัปเดตล่าสุด {{ $refreshedAt->format('d/m/Y H:i:s') }}</div></div>
        <div>@if(auth()->user()?->hasRoleCode('STOCK_ADMIN'))<a href="{{ route('stock-withdrawal.master.index') }}" class="btn btn-outline-secondary">Standard Part Master</a>@endif
            <a href="{{ route('stock-withdrawal.export', request()->query()) }}" class="btn btn-success">Export CSV</a></div>
    </div>
    <div class="row g-2 mb-3">
        @foreach(['GREEN'=>'เขียว','YELLOW'=>'เหลือง','ORANGE'=>'ส้ม','RED'=>'แดง','GRAY'=>'ไม่มี Standard'] as $code=>$label)
        <div class="col"><div class="card border-0 shadow-sm"><div class="card-body"><span class="risk-dot risk-{{ strtolower($code) }}"></span>{{ $label }}<div class="fs-3 fw-bold">{{ number_format($summary[$code]) }}</div></div></div></div>
        @endforeach
    </div>
    <form method="GET" class="card card-body shadow-sm mb-3"><div class="row g-2 align-items-end">
        <div class="col-md-2"><label class="form-label">Site</label><select name="site" class="form-select"><option value="">ทั้งหมด</option>@foreach(['WIRE','PLUS'] as $site)<option @selected(($filters['site'] ?? '')===$site)>{{ $site }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="form-label">Due Date From</label><input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="form-control"></div>
        <div class="col-md-2"><label class="form-label">Due Date To</label><input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="form-control"></div>
        <div class="col-md-2"><label class="form-label">สถานะเบิก</label><select name="status" class="form-select"><option value="">ทั้งหมด</option>@foreach(['ยังไม่เบิก','เบิกบางส่วน','เบิกครบแล้ว'] as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? '')===$s)>{{ $s }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="form-label">ความเสี่ยง</label><select name="risk" class="form-select"><option value="">ทั้งหมด</option>@foreach(['GREEN'=>'เขียว','YELLOW'=>'เหลือง','ORANGE'=>'ส้ม','RED'=>'แดง','GRAY'=>'ไม่มี Standard'] as $v=>$t)<option value="{{ $v }}" @selected(($filters['risk'] ?? '')===$v)>{{ $t }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="form-label">MFG / Part</label><input name="keyword" value="{{ $filters['keyword'] ?? '' }}" class="form-control"><button class="btn btn-primary w-100 mt-2">ค้นหา</button></div>
    </div></form>
    <div class="card shadow-sm"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Site</th><th>MFG</th><th>Part</th><th>Description</th><th>Due Date</th><th class="text-end">Required</th><th class="text-end">Issued</th><th class="text-end">Remaining</th><th>Standard</th><th>วันที่ควรเบิก</th><th>สถานะเบิก</th><th>ความเสี่ยง</th></tr></thead>
        <tbody>@forelse($rows as $row)<tr>
            <td>{{ $row['site'] }}</td><td class="fw-semibold">{{ $row['mfg'] }}</td><td>{{ $row['partnumber'] }}</td><td>{{ $row['description'] }}</td>
            <td>{{ $row['due_date'] }}</td><td class="text-end">{{ number_format($row['required_qty'],2) }} <span class="badge bg-warning text-dark">MOCK</span></td><td class="text-end"><a href="{{ route('stock-withdrawal.issues', ['site'=>$row['site'],'workorder_id'=>$row['workorder_id'],'parts_id'=>$row['parts_id'],'mfg'=>$row['mfg'],'partnumber'=>$row['partnumber']]) }}">{{ number_format($row['issued_qty'],2) }}</a></td><td class="text-end">{{ number_format($row['remaining_qty'],2) }} @if($row['over_issued_qty'] > 0)<small class="text-danger">เกิน {{ number_format($row['over_issued_qty'],2) }}</small>@endif</td>
            <td>{{ $row['standard_days'] === null ? '-' : $row['standard_days'].' วัน' }}</td><td>{{ $row['withdraw_date'] ?: '-' }}</td><td>{{ $row['withdrawal_status'] }}</td>
            <td><span class="badge risk-bg-{{ strtolower($row['risk_code']) }}">{{ $row['risk_label'] }}</span></td>
        </tr>@empty<tr><td colspan="12" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr>@endforelse</tbody>
    </table></div></div>
</div>
<style>
.risk-dot{display:inline-block;width:12px;height:12px;border-radius:50%;margin-right:8px}.risk-green,.risk-bg-green{background:#198754}.risk-yellow,.risk-bg-yellow{background:#ffc107;color:#212529}.risk-orange,.risk-bg-orange{background:#fd7e14}.risk-red,.risk-bg-red{background:#dc3545}.risk-gray,.risk-bg-gray{background:#6c757d}.risk-bg-green,.risk-bg-orange,.risk-bg-red,.risk-bg-gray{color:#fff}
</style>
<script>window.setTimeout(() => window.location.reload(), 300000);</script>
@endsection
