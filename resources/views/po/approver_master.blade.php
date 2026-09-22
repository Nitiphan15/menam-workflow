@extends('layouts.layout')

@section('title', 'PO Approver Master')
@section('page-title', 'PO Approver Master')

@section('content')
    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h3 class="mb-1 fw-bold">PO Approver Master</h3>
                <div class="text-muted">กำหนดผู้อนุมัติพิเศษเพิ่มเติม แยกตามแผนก</div>
            </div>
            @can('POPUR')
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('po.index') }}">กลับรายการ PO</a>
            @endcan
        </div>

        <div class="alert alert-info">
            <strong>พฤติกรรมปัจจุบัน:</strong>
            ผู้อนุมัติหลักและผู้อนุมัติพิเศษอยู่ในขั้นเดียวกัน ผู้อนุมัติคนใดคนหนึ่งอนุมัติก็ผ่าน
        </div>

        @if (session('ok'))
            <div class="alert alert-success">{{ session('ok') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger mb-3">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="card mb-3">
            <div class="card-header fw-semibold">เพิ่มผู้อนุมัติพิเศษ</div>
            <div class="card-body">
                <form method="POST" action="{{ route('po.approver-master.store') }}" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-lg-4">
                        <label class="form-label">แผนก</label>
                        <select class="form-select" name="department_id" required>
                            <option value="">-- เลือกแผนก --</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}" @selected((int) old('department_id', $departmentId) === (int) $department->id)>
                                    {{ $department->name }} ({{ $department->code }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-6">
                        <label class="form-label">ผู้อนุมัติ</label>
                        <select class="form-select" name="approver_user_id" required>
                            <option value="">-- เลือกผู้ใช้ --</option>
                            @foreach ($users as $user)
                                <option value="{{ $user->id }}" @selected((int) old('approver_user_id') === (int) $user->id)>
                                    {{ $user->name }} — {{ $user->email ?: $user->username }} — {{ $user->department_name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-1">
                        <input type="hidden" name="is_active" value="0">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="newApproverActive" checked>
                            <label class="form-check-label" for="newApproverActive">Active</label>
                        </div>
                    </div>
                    <div class="col-lg-1 d-grid">
                        <button class="btn btn-primary">เพิ่ม</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header bg-white">
                <form method="GET" action="{{ route('po.approver-master.index') }}" class="row g-2 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label">กรองแผนก</label>
                        <select class="form-select form-select-sm" name="department_id">
                            <option value="0">ทุกแผนก</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}" @selected($departmentId === (int) $department->id)>
                                    {{ $department->name }} ({{ $department->code }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-outline-primary btn-sm">กรอง</button>
                        <a class="btn btn-outline-secondary btn-sm" href="{{ route('po.approver-master.index') }}">ล้าง</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">ผู้อนุมัติหลักตามแผนก (ดูอย่างเดียว)</div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>แผนก</th><th>ผู้อนุมัติหลัก</th><th>สถานะ</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($baseRows as $row)
                            <tr>
                                <td>{{ $row->department_name }} ({{ $row->department_code }})</td>
                                <td>
                                    @forelse ($row->approvers as $approver)
                                        <div>{{ $approver->name }} — {{ $approver->email ?: $approver->username }}</div>
                                    @empty
                                        <span class="text-danger">ยังไม่พบผู้อนุมัติ</span>
                                    @endforelse
                                </td>
                                <td>{{ $row->approvers->isNotEmpty() ? 'พร้อมใช้งาน' : 'ต้องตรวจตำแหน่ง' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted">ไม่พบแผนกที่เลือก</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold">ผู้อนุมัติพิเศษ</div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width:220px">แผนก</th>
                            <th style="min-width:420px">ผู้อนุมัติ</th>
                            <th style="width:100px">Active</th>
                            <th style="width:170px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($specialRows as $row)
                            @php($formId = 'approverMapping' . (int) $row->id)
                            <tr>
                                <td>
                                    <form id="{{ $formId }}" method="POST" action="{{ route('po.approver-master.update', $row->id) }}">
                                        @csrf
                                        @method('PUT')
                                    </form>
                                    <select class="form-select form-select-sm" name="department_id" form="{{ $formId }}" required>
                                        @foreach ($departments as $department)
                                            <option value="{{ $department->id }}" @selected((int) $row->department_id === (int) $department->id)>
                                                {{ $department->name }} ({{ $department->code }})
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select class="form-select form-select-sm" name="approver_user_id" form="{{ $formId }}" required>
                                        @foreach ($users as $user)
                                            <option value="{{ $user->id }}" @selected((int) $row->approver_user_id === (int) $user->id)>
                                                {{ $user->name }} — {{ $user->email ?: $user->username }} — {{ $user->department_name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <input type="hidden" name="is_active" value="0" form="{{ $formId }}">
                                    <input class="form-check-input" type="checkbox" name="is_active" value="1"
                                        form="{{ $formId }}" @checked((int) $row->is_active === 1)>
                                </td>
                                <td class="text-nowrap">
                                    <button class="btn btn-outline-primary btn-sm" form="{{ $formId }}">บันทึก</button>
                                    <form method="POST" action="{{ route('po.approver-master.destroy', $row->id) }}" class="d-inline"
                                        onsubmit="return confirm('ปิดผู้อนุมัติพิเศษรายการนี้?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-outline-danger btn-sm">ปิด</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted">ยังไม่มีผู้อนุมัติพิเศษ</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
