@extends('layouts.layout')
@section('page-title', 'Master ' . $title)
@section('title', 'Master ' . $title)

@section('content')
    @php
        $storeRoute = $roleType === 'DRIVER' ? 'dp.master.drivers.store' : 'dp.master.helpers.store';
        $updateRoute = $roleType === 'DRIVER' ? 'dp.master.drivers.update' : 'dp.master.helpers.update';
        $deleteRoute = $roleType === 'DRIVER' ? 'dp.master.drivers.delete' : 'dp.master.helpers.delete';
    @endphp

    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h3 class="mb-1 fw-bold">Master {{ $title }}</h3>
                <div class="text-muted">ข้อมูล {{ $title }} สำหรับ FormDP dev</div>
            </div>
            <div class="btn-group">
                <a class="btn btn-outline-primary btn-sm" href="{{ route('dp.master.trucks') }}">รถ</a>
                <a class="btn btn-outline-primary btn-sm {{ $roleType === 'DRIVER' ? 'active' : '' }}" href="{{ route('dp.master.drivers') }}">พนักงานขับรถ</a>
                <a class="btn btn-outline-primary btn-sm {{ $roleType === 'HELPER' ? 'active' : '' }}" href="{{ route('dp.master.helpers') }}">เด็กรถ</a>
            </div>
        </div>

        @if (session('success'))
            <div class="alert alert-success py-2">{{ session('success') }}</div>
        @endif

        <div class="card mb-3">
            <div class="card-header fw-semibold">เพิ่ม{{ $title }}</div>
            <div class="card-body">
                <form method="POST" action="{{ route($storeRoute) }}" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-md-1">
                        <label class="form-label small mb-1">คำนำหน้า</label>
                        <input class="form-control form-control-sm" name="prefix_name">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">ชื่อ</label>
                        <input class="form-control form-control-sm" name="first_name" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">นามสกุล</label>
                        <input class="form-control form-control-sm" name="last_name">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">โทร</label>
                        <input class="form-control form-control-sm" name="phone">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">ตำแหน่ง</label>
                        <input class="form-control form-control-sm" name="position_name">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">แผนก</label>
                        <input class="form-control form-control-sm" name="department_name">
                    </div>
                    <div class="col-md-1 d-grid">
                        <button class="btn btn-primary btn-sm">บันทึก</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:70px;">No</th>
                            <th style="width:110px;">คำนำหน้า</th>
                            <th>ชื่อ</th>
                            <th>นามสกุล</th>
                            <th>โทร</th>
                            <th>ตำแหน่ง</th>
                            <th>แผนก</th>
                            <th style="width:110px;">Active</th>
                            <th style="width:150px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <form method="POST" action="{{ route($updateRoute, $row->id) }}">
                                    @csrf
                                    <td>{{ $row->id }}</td>
                                    <td><input class="form-control form-control-sm" name="prefix_name" value="{{ $row->prefix_name }}"></td>
                                    <td><input class="form-control form-control-sm" name="first_name" value="{{ $row->first_name }}" required></td>
                                    <td><input class="form-control form-control-sm" name="last_name" value="{{ $row->last_name }}"></td>
                                    <td><input class="form-control form-control-sm" name="phone" value="{{ $row->phone }}"></td>
                                    <td><input class="form-control form-control-sm" name="position_name" value="{{ $row->position_name }}"></td>
                                    <td><input class="form-control form-control-sm" name="department_name" value="{{ $row->department_name }}"></td>
                                    <td>
                                        <select class="form-select form-select-sm" name="is_active">
                                            <option value="1" {{ (int) ($row->is_active ?? 1) === 1 ? 'selected' : '' }}>Yes</option>
                                            <option value="0" {{ (int) ($row->is_active ?? 1) === 0 ? 'selected' : '' }}>No</option>
                                        </select>
                                    </td>
                                    <td class="text-nowrap">
                                        <button class="btn btn-outline-primary btn-sm">Save</button>
                                </form>
                                <form method="POST" action="{{ route($deleteRoute, $row->id) }}" class="d-inline" onsubmit="return confirm('ปิดการใช้งานรายการนี้?');">
                                    @csrf
                                    <button class="btn btn-outline-danger btn-sm">Inactive</button>
                                </form>
                                    </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted">ไม่มีข้อมูล</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
