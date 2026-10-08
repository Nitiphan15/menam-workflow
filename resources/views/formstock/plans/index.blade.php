@extends('layouts.layout')
@section('title','แผนรายการเบิก')
@section('page-title','แผนรายการเบิก')
@section('content')
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h4 class="mb-1">รายการที่ต้องเบิก</h4><div class="text-muted">แสดงแยกตาม BOM Part และเรียงงานเสี่ยงสุดก่อน</div></div>
        <div><a href="{{ route('stock-withdrawal.all-mfg') }}" class="btn btn-outline-primary">ดู MFG ทั้งหมด</a> <a href="{{ route('stock-withdrawal.create') }}" class="btn btn-primary">+ เพิ่มแผน</a></div>
    </div>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    <form class="card card-body border-0 shadow-sm mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-2"><label>วันต้องเบิก</label><input type="date" name="date" value="{{ $filters['date']??'' }}" class="form-control"></div>
            <div class="col-md-2"><label>โรงงาน</label><select name="site" class="form-select"><option value="">ทั้งหมด</option>@foreach(['WIRE','PLUS'] as $value)<option @selected(($filters['site']??'')===$value)>{{ $value }}</option>@endforeach</select></div>
            <div class="col-md-2"><label>กลุ่ม Part</label><select name="type" class="form-select"><option value="">ทั้งหมด</option>@foreach($types as $type)<option value="{{ $type->code }}" @selected(($filters['type']??'')===$type->code)>{{ $type->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><label>MFG</label><input name="mfg" value="{{ $filters['mfg']??'' }}" class="form-control"></div>
            <div class="col-md-2"><label>เรียงตาม</label><select name="sort" class="form-select"><option value="risk" @selected(($filters['sort']??'risk')==='risk')>เสี่ยงสุดก่อน</option><option value="withdraw_date" @selected(($filters['sort']??'')==='withdraw_date')>วันที่ต้องเบิก</option><option value="delivery_date" @selected(($filters['sort']??'')==='delivery_date')>วันส่งมอบ</option><option value="mfg" @selected(($filters['sort']??'')==='mfg')>เลขที่ MFG</option></select></div>
            <div class="col-md-2"><button class="btn btn-primary">ค้นหา</button> <a href="{{ route('stock-withdrawal.index') }}" class="btn btn-outline-secondary">ล้าง</a></div>
        </div>
    </form>
    <div class="d-flex justify-content-between small text-muted mb-2"><span>ทั้งหมด {{ number_format($items->total()) }} รายการ</span><span>แดง = เลยกำหนด · ส้ม = วันนี้ · เหลือง = ภายใน 2 วัน · เขียว = ยังไม่ถึงกำหนด</span></div>
    <div class="card border-0 shadow-sm"><div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>MFG</th><th>โรงงาน</th><th>Part Number</th><th>กลุ่ม</th><th>ลูกค้า</th><th>วันส่งมอบ</th><th>วันขึ้นผลิต</th><th>วันต้องเบิก</th><th class="text-center">คงเหลือ (วัน)</th><th class="text-center">สถานะ</th><th class="text-end">จำนวน</th></tr></thead>
            <tbody>
            @forelse($items as $item)
                @php
                    $days = $item->day_offset;
                    [$rowClass, $badgeClass, $status] = $days < 0
                        ? ['table-danger', 'bg-danger', 'เลยกำหนด']
                        : ($days === 0 ? ['table-warning', 'bg-warning text-dark', 'ต้องเบิกวันนี้']
                        : ($days <= 2 ? ['', 'bg-warning text-dark', 'ใกล้ถึงกำหนด'] : ['', 'bg-success', 'ยังไม่ถึงกำหนด']));
                @endphp
                <tr class="{{ $rowClass }}">
                    <td><strong>{{ $item->plan->mfg }}</strong></td><td>{{ ucfirst(strtolower($item->plan->site)) }}</td>
                    <td><div class="text-primary fw-semibold">{{ $item->partnumber ?: '-' }}</div><small class="text-muted">{{ $item->part_description }}</small></td>
                    <td><span class="badge {{ $item->type_code==='FG' ? 'bg-primary' : 'bg-success' }}">{{ $item->type_code }}</span></td>
                    <td>{{ $item->plan->customer_name ?: '-' }}</td><td>{{ $item->plan->delivery_date?->format('d/m/Y') ?: '-' }}</td>
                    <td>{{ $item->plan->production_date?->format('d/m/Y') }}</td><td><strong>{{ $item->planned_withdraw_date?->format('d/m/Y') }}</strong></td>
                    <td class="text-center fw-bold {{ $days < 0 ? 'text-danger' : '' }}">{{ $days }}</td><td class="text-center"><span class="badge {{ $badgeClass }}">{{ $status }}</span></td>
                    <td class="text-end">{{ $item->quantity === null ? '-' : number_format((float)$item->quantity,2) }} {{ $item->unit }}</td>
                </tr>
            @empty
                <tr><td colspan="11" class="text-center text-muted py-5">ยังไม่มีแผนรายการเบิก</td></tr>
            @endforelse
            </tbody>
        </table>
    </div></div>
    <div class="mt-3">{{ $items->links() }}</div>
</div>
@endsection
