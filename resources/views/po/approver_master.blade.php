@extends('layouts.layout')

@section('title', 'PO Approver Master')
@section('page-title', 'PO Approver Master')

@section('content')
    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h3 class="mb-1 fw-bold">PO Approver Master</h3>
                <div class="text-muted">กำหนดผู้อนุมัติพิเศษลำดับ 2, 3, 4 และลำดับถัดไป แยกตามแผนก</div>
            </div>
            @can('POPUR')
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('po.index') }}">กลับรายการ PO</a>
            @endcan
        </div>

        <div class="alert alert-info">
            <strong>พฤติกรรมปัจจุบัน:</strong>
            ผู้อนุมัติพิเศษจะถูกเพิ่มต่อจากกติกาหลักของแผนกในขั้นเดียวกัน และผู้อนุมัติคนใดคนหนึ่งอนุมัติก็ผ่าน
            ลำดับใช้สำหรับจัดเรียงรายชื่อ ไม่ใช่การอนุมัติต่อกันทีละคน
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
                    <div class="col-lg-3">
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
                    <div class="col-lg-5">
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
                    <div class="col-lg-2">
                        <label class="form-label">ลำดับ</label>
                        <input type="number" class="form-control" name="sequence_no" min="2" max="20"
                            value="{{ old('sequence_no', 2) }}" required>
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
            <div class="card-header fw-semibold">กติกาหลักลำดับ 1 (ดูอย่างเดียว)</div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>แผนก</th><th>ผู้อนุมัติหลัก</th><th>สถานะ</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($baseRows as $row)
                            <tr>
                                <td>{{ $row->department_name }} ({{ $row->department_code }})</td>
                                <td>{{ $row->approver_name }} — {{ $row->email ?: $row->username }}</td>
                                <td>{{ (int) $row->is_active === 1 ? 'Active' : 'Inactive' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted">แผนกที่เลือกใช้ query ตามตำแหน่ง ไม่มี fixed slot 1</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold">ผู้อนุมัติพิเศษลำดับ 2–20</div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width:220px">แผนก</th>
                            <th style="min-width:420px">ผู้อนุมัติ</th>
                            <th style="width:100px">ลำดับ</th>
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
                                    <input type="number" class="form-control form-control-sm" name="sequence_no"
                                        form="{{ $formId }}" min="2" max="20" value="{{ $row->sequence_no }}" required>
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
                            <tr><td colspan="5" class="text-center text-muted">ยังไม่มีผู้อนุมัติพิเศษ</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
