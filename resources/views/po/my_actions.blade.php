@extends('layouts.layout')

@section('title', 'PO My Actions')
@section('page-title', 'PO My Actions')

@section('content')
    <div class="container-fluid py-3 px-0">
        @if (session('ok'))
            <div class="alert alert-success border-0 shadow-sm">{{ session('ok') }}</div>
        @endif

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                    <div>
                        <h4 class="mb-1">เอกสารที่ต้องดำเนินการ</h4>
                        <div class="text-muted small">แสดงเฉพาะ PO ที่ผู้ใช้ปัจจุบันมีสิทธิ์ action ใน step ปัจจุบัน</div>
                    </div>
                    <a href="{{ route('po.index') }}" class="btn btn-outline-secondary">กลับหน้ารายการ PO</a>
                </div>

                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small text-muted">คำค้นหา</label>
                        <input type="text" name="search" value="{{ request('search') }}" class="form-control"
                            placeholder="PO / Invoice / Vendor / แผนก">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small text-muted">Status</label>
                        <select name="status" class="form-select">
                            <option value="">ทั้งหมด</option>
                            @foreach ($statusOptions as $value => $label)
                                <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small text-muted">Source</label>
                        <select name="source" class="form-select">
                            <option value="">ทั้งหมด</option>
                            @foreach ($sourceOptions as $value => $label)
                                <option value="{{ $value }}" @selected(request('source') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small text-muted">วันที่เริ่มต้น</label>
                        <input type="date" name="date_from" value="{{ request('date_from') }}" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small text-muted">วันที่สิ้นสุด</label>
                        <input type="date" name="date_to" value="{{ request('date_to') }}" class="form-control">
                    </div>
                    <div class="col-md-1 d-flex align-items-end gap-2">
                        <button class="btn btn-primary flex-fill">กรอง</button>
                        <a href="{{ route('po.myActions') }}" class="btn btn-outline-secondary">ล้าง</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>PO No.</th>
                            <th>Source</th>
                            <th>Invoice</th>
                            <th>Department</th>
                            <th>Date</th>
                            <th class="text-end">Qty/Amount</th>
                            <th>Status</th>
                            <th>Attached</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            @php
                                $status = strtoupper((string) $row->status_code);
                                $statusMeta = match ($status) {
                                    'PURCHASE_SUBMITTED' => ['label' => 'Purchase Submitted', 'class' => 'bg-primary-subtle text-primary'],
                                    'PURCHASE_APPROVAL' => ['label' => 'Waiting Purchase Approval', 'class' => 'bg-info-subtle text-info'],
                                    'DEPT_MANAGER_APPROVAL' => ['label' => 'Waiting Department Approval', 'class' => 'bg-secondary-subtle text-secondary'],
                                    'CLOSED' => ['label' => 'Closed', 'class' => 'bg-success-subtle text-success'],
                                    'REJECTED' => ['label' => 'Rejected', 'class' => 'bg-danger-subtle text-danger'],
                                    'CANCELLED' => ['label' => 'Cancelled', 'class' => 'bg-dark-subtle text-dark'],
                                    'DRAFT' => ['label' => 'Draft', 'class' => 'bg-warning-subtle text-warning-emphasis'],
                                    'NEW' => ['label' => 'New', 'class' => 'bg-warning-subtle text-warning-emphasis'],
                                    default => ['label' => str_replace('_', ' ', $status), 'class' => 'bg-light text-dark'],
                                };
                            @endphp
                            <tr>
                                <td class="fw-semibold">{{ $row->ordnumber }}</td>
                                <td><span class="badge text-bg-light">{{ $row->source_label }}</span></td>
                                <td>{{ $row->invnumber ?: '-' }}</td>
                                <td>{{ $row->department }}</td>
                                <td>{{ \Illuminate\Support\Carbon::parse($row->transdate)->format('d-M-Y') }}</td>
                                <td class="text-end">{{ number_format($row->qty, 2) }}</td>
                                <td><span class="badge {{ $statusMeta['class'] }}">{{ $statusMeta['label'] }}</span></td>
                                <td>
                                    @if ($row->has_attachment)
                                        <span class="badge text-bg-success">Attached</span>
                                    @else
                                        <span class="badge text-bg-light">No File</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if ($row->po_header_id)
                                        <a href="{{ route('po.show', $row->po_header_id) }}" class="btn btn-sm btn-primary">เปิด</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-5">ไม่พบเอกสารที่ต้องดำเนินการตามเงื่อนไขที่เลือก</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
