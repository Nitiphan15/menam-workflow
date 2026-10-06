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
    <div class="card border-0 shadow-sm overflow-hidden">
        <div class="stock-list-head d-none d-lg-grid px-3 py-2 text-muted small fw-semibold">
            <div>MFG / PART</div><div>กำหนดการ</div><div class="text-end">จำนวน</div><div>สถานะ</div><div>ความเสี่ยง</div><div></div>
        </div>
        <div class="list-group list-group-flush">
        @forelse($rows as $row)
            <div class="list-group-item stock-list-item risk-border-{{ strtolower($row['risk_code']) }} px-3 py-3">
                <div class="stock-list-grid">
                    <div>
                        <div class="d-flex align-items-center gap-2"><span class="badge bg-dark">{{ $row['site'] }}</span><strong>{{ $row['mfg'] }}</strong></div>
                        <div class="fw-semibold text-primary mt-1">{{ $row['partnumber'] }}</div>
                        <div class="text-muted small text-truncate" title="{{ $row['description'] }}">{{ $row['description'] ?: '-' }}</div>
                    </div>
                    <div class="small">
                        <div><span class="text-muted">Due:</span> <strong>{{ $row['due_date'] }}</strong></div>
                        <div><span class="text-muted">ควรเบิก:</span> <strong>{{ $row['withdraw_date'] ?: '-' }}</strong></div>
                        <div class="text-muted">Standard {{ $row['standard_days'] === null ? '-' : $row['standard_days'].' วัน' }}</div>
                    </div>
                    <div class="text-lg-end small">
                        <div><span class="text-muted">ต้องใช้</span> {{ number_format($row['required_qty'],2) }} <span class="badge bg-warning text-dark">MOCK</span></div>
                        <div><span class="text-muted">เบิกแล้ว</span> <a href="{{ route('stock-withdrawal.issues', ['site'=>$row['site'],'workorder_id'=>$row['workorder_id'],'parts_id'=>$row['parts_id'],'mfg'=>$row['mfg'],'partnumber'=>$row['partnumber']]) }}">{{ number_format($row['issued_qty'],2) }}</a></div>
                        <div><span class="text-muted">คงเหลือ</span> <strong>{{ number_format($row['remaining_qty'],2) }}</strong></div>
                    </div>
                    <div><span class="badge bg-light text-dark border">{{ $row['withdrawal_status'] }}</span>@if($row['over_issued_qty'] > 0)<div class="small text-danger mt-1">เบิกเกิน {{ number_format($row['over_issued_qty'],2) }}</div>@endif</div>
                    <div><span class="badge risk-bg-{{ strtolower($row['risk_code']) }}">{{ $row['risk_label'] }}</span></div>
                    <div class="text-lg-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('stock-withdrawal.issues', ['site'=>$row['site'],'workorder_id'=>$row['workorder_id'],'parts_id'=>$row['parts_id'],'mfg'=>$row['mfg'],'partnumber'=>$row['partnumber']]) }}">ดูรายการเบิก</a></div>
                </div>
            </div>
        @empty
            <div class="text-center text-muted py-5">ไม่พบข้อมูล</div>
        @endforelse
        </div>
    </div>
</div>
<style>
.risk-dot{display:inline-block;width:12px;height:12px;border-radius:50%;margin-right:8px}.risk-green,.risk-bg-green{background:#198754}.risk-yellow,.risk-bg-yellow{background:#ffc107;color:#212529}.risk-orange,.risk-bg-orange{background:#fd7e14}.risk-red,.risk-bg-red{background:#dc3545}.risk-gray,.risk-bg-gray{background:#6c757d}.risk-bg-green,.risk-bg-orange,.risk-bg-red,.risk-bg-gray{color:#fff}
.stock-list-head,.stock-list-grid{display:grid;grid-template-columns:minmax(240px,2fr) minmax(155px,1.2fr) minmax(170px,1.2fr) minmax(120px,.8fr) minmax(150px,1fr) minmax(110px,.7fr);gap:1rem;align-items:center}.stock-list-head{background:#f8f9fa}.stock-list-item{border-left:5px solid transparent!important}.risk-border-green{border-left-color:#198754!important}.risk-border-yellow{border-left-color:#ffc107!important}.risk-border-orange{border-left-color:#fd7e14!important}.risk-border-red{border-left-color:#dc3545!important}.risk-border-gray{border-left-color:#6c757d!important}@media(max-width:991.98px){.stock-list-grid{grid-template-columns:1fr 1fr}.stock-list-grid>div:first-child{grid-column:1/-1}}@media(max-width:575.98px){.stock-list-grid{grid-template-columns:1fr}.stock-list-grid>div:first-child{grid-column:auto}}
</style>
<script>window.setTimeout(() => window.location.reload(), 300000);</script>
@endsection
