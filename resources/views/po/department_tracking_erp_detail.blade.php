@extends('layouts.layout')

@section('title', 'PO Detail')
@section('page-title', 'PO Detail')

@section('content')
    <div class="container py-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
            <div>
                <h4 class="mb-1">{{ $headerRow->ordnumber }}</h4>
                <div class="text-muted">{{ $headerRow->vendor_name }} | {{ $headerRow->f1 ?: 'ไม่ระบุแผนก' }}</div>
                <span class="badge bg-secondary-subtle text-secondary mt-2">
                    <i class="fa-solid fa-eye me-1"></i> ดูรายละเอียดอย่างเดียว
                </span>
            </div>
            <a href="{{ route('po.departmentTracking') }}" class="btn btn-outline-secondary">กลับหน้ารายการ</a>
        </div>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>ข้อมูลเอกสาร</strong>
                <span class="badge text-bg-warning">ยังไม่เข้า Workflow</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label">PO No.</label><input class="form-control" value="{{ $headerRow->ordnumber }}" readonly></div>
                    <div class="col-md-4"><label class="form-label">Source</label><input class="form-control" value="{{ \App\Services\Po\PoErpService::sourceLabel($source) }}" readonly></div>
                    <div class="col-md-4"><label class="form-label">PO Date</label><input class="form-control" value="{{ $headerRow->transdate ? \Illuminate\Support\Carbon::parse($headerRow->transdate)->format('d-M-Y') : '-' }}" readonly></div>
                    <div class="col-md-6"><label class="form-label">Vendor</label><input class="form-control" value="{{ $headerRow->vendor_name }}" readonly></div>
                    <div class="col-md-6"><label class="form-label">Requester</label><input class="form-control" value="{{ $headerRow->requester_name }}" readonly></div>
                    <div class="col-md-6"><label class="form-label">Department</label><input class="form-control" value="{{ $headerRow->f1 }}" readonly></div>
                    <div class="col-md-3"><label class="form-label">Currency</label><input class="form-control" value="{{ $headerRow->curr }}" readonly></div>
                    <div class="col-md-3"><label class="form-label">Payment Term</label><input class="form-control" value="{{ $headerRow->terms }}" readonly></div>
                    <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" rows="3" readonly>{{ $headerRow->notes }}</textarea></div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>รายการสินค้า</strong>
                <span class="badge text-bg-light">{{ number_format($detailRows->count()) }} lines</span>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center">NO.</th>
                            <th>Description</th>
                            <th class="text-end">Qty</th>
                            <th class="text-center">UOM</th>
                            <th class="text-end">Unit Cost</th>
                            <th class="text-end">Extended Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($detailRows as $index => $row)
                            <tr>
                                <td class="text-center">{{ $index + 1 }}</td>
                                <td>{{ $row->description }}</td>
                                <td class="text-end">{{ number_format((float) $row->qty, 2) }}</td>
                                <td class="text-center">{{ $row->item_unit ?: ($row->item_unit_code ?: '-') }}</td>
                                <td class="text-end">{{ number_format((float) $row->sellprice, 3) }}</td>
                                <td class="text-end">{{ number_format((float) $row->extended_price, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
