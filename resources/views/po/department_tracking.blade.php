@extends('layouts.layout')

@section('title', 'PO Department Tracking')
@section('page-title', 'PO Department Tracking')

@section('content')
    <div class="container-fluid py-3 px-0">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <h4 class="mb-1">ติดตาม PO ของแผนก</h4>
                        @if ($department)
                            <div class="text-muted">
                                {{ $department->name }} ({{ $department->code }})
                            </div>
                        @endif
                    </div>
                    <span class="badge bg-secondary-subtle text-secondary px-3 py-2">
                        <i class="fa-solid fa-eye me-1"></i> ดูอย่างเดียว ไม่มี Action
                    </span>
                </div>

                @if (!$department)
                    <div class="alert alert-warning mb-0">
                        ไม่พบแผนกของผู้ใช้ปัจจุบัน กรุณาติดต่อผู้ดูแลเพื่อกำหนด Department ให้บัญชีนี้
                    </div>
                @else
                    <form method="GET" action="{{ route('po.departmentTracking') }}" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small text-muted">คำค้นหา</label>
                            <input type="text" name="search" value="{{ request('search') }}" class="form-control"
                                placeholder="PO / Vendor / แผนก">
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
                            <a href="{{ route('po.departmentTracking') }}" class="btn btn-outline-secondary">ล้าง</a>
                        </div>
                    </form>
                @endif
            </div>
        </div>

        @if ($department)
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>รายการ PO ในแผนก</strong>
                    <span class="badge text-bg-light">{{ number_format($rows->count()) }} รายการ</span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>PO No.</th>
                                <th>Source</th>
                                <th>วันที่</th>
                                <th>แผนกใน ERP</th>
                                <th class="text-end">Qty/Amount</th>
                                <th>Flow ปัจจุบัน</th>
                                <th>กำลังรอ</th>
                                <th>ไฟล์แนบ</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                @php
                                    $status = strtoupper((string) $row->status_code);
                                    $statusMeta = match ($status) {
                                        'PURCHASE_SUBMITTED' => ['label' => 'Purchase Submitted', 'class' => 'bg-primary-subtle text-primary'],
                                        'PURCHASE_APPROVAL' => ['label' => 'Waiting Purchase Approval', 'class' => 'bg-info-subtle text-info'],
                                        'DEPT_MANAGER_APPROVAL' => ['label' => 'Waiting Department Manager', 'class' => 'bg-secondary-subtle text-secondary'],
                                        'CLOSED', 'APPROVED' => ['label' => 'Completed', 'class' => 'bg-success-subtle text-success'],
                                        'REJECTED' => ['label' => 'Rejected', 'class' => 'bg-danger-subtle text-danger'],
                                        'CANCELLED' => ['label' => 'Cancelled', 'class' => 'bg-dark-subtle text-dark'],
                                        'DRAFT' => ['label' => 'Draft', 'class' => 'bg-warning-subtle text-warning-emphasis'],
                                        'NEW' => ['label' => 'New', 'class' => 'bg-warning-subtle text-warning-emphasis'],
                                        default => ['label' => str_replace('_', ' ', $status), 'class' => 'bg-light text-dark'],
                                    };
                                    $pendingRows = $row->workflow_id
                                        ? $pendingApproversByWorkflow->get((int) $row->workflow_id, collect())
                                        : collect();
                                @endphp
                                <tr>
                                    <td class="fw-semibold">{{ $row->ordnumber }}</td>
                                    <td><span class="badge text-bg-light">{{ $row->source_label }}</span></td>
                                    <td>{{ $row->transdate ? \Illuminate\Support\Carbon::parse($row->transdate)->format('d-M-Y') : '-' }}</td>
                                    <td>{{ $row->department }}</td>
                                    <td class="text-end">{{ number_format($row->qty, 2) }}</td>
                                    <td><span class="badge {{ $statusMeta['class'] }}">{{ $statusMeta['label'] }}</span></td>
                                    <td>
                                        @if ($pendingRows->isNotEmpty())
                                            <div>{{ $pendingRows->pluck('name')->unique()->implode(', ') }}</div>
                                            <div class="small text-muted">Step {{ $pendingRows->first()->current_step_no }}</div>
                                        @elseif (in_array($status, ['CLOSED', 'APPROVED'], true))
                                            <span class="text-success">อนุมัติครบแล้ว</span>
                                        @elseif (in_array($status, ['DRAFT', 'NEW'], true))
                                            <span class="text-muted">ยังไม่เข้า Workflow</span>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge {{ $row->has_attachment ? 'text-bg-success' : 'text-bg-light' }}">
                                            {{ $row->has_attachment ? 'Attached' : 'No File' }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-5">
                                        ไม่พบ PO ของแผนกตามเงื่อนไขที่เลือก
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
@endsection
