@extends('layouts.layout')

@section('content')
    @php

        $isEdit = isset($row) && !empty($row->id);
    @endphp
    <div class="container py-4">
        @if (session('ok'))
            <div class="alert alert-success">{{ session('ok') }}</div>
        @endif

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white fw-bold">
                เพิ่มแผนก
            </div>
            <div class="card-body">
                <form method="POST"
                    action="{{ $isEdit ? route('adminweb.dept.update', $row->id) : route('adminweb.dept.store') }}"
                    class="row g-3">
                    @csrf
                    @if ($isEdit)
                        @method('PUT')
                    @endif

                    <input type="hidden" name="back_dept_id" value="{{ $deptId ?? '' }}">
                    <input type="hidden" name="back_q" value="{{ $q ?? '' }}">

                    <div class="col-md-4">
                        <label class="form-label">รหัส</label>
                        <input type="hidden" name="department_id" value="{{ old('department_id', $row->id ?? '') }}">
                        <input name="code" class="form-control" required value="{{ old('code', $row->code ?? '') }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">ชื่อแผนก</label>
                        <input name="name" class="form-control" required value="{{ old('name', $row->name ?? '') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Site</label>
                        <input name="site_code" class="form-control" value="{{ old('site_code', $row->site_code ?? '') }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Cost Center</label>
                        <input name="cost_center" class="form-control"
                            value="{{ old('cost_center', $row->cost_center ?? '') }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">แผนกแม่</label>
                        <select name="parent_id" class="form-control">
                            <option value="">-- แผนกแม่ --</option>
                            @foreach ($headDepartment as $d)
                                <option value="{{ $d->id }}" @selected(old('parent_id', $row->parent_id ?? '') == $d->id)>{{ $d->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($isEdit)
                        <div class="col-md-1">
                            <label class="form-label">Active</label>
                            <select name="is_active" class="form-select">
                                <option value="1" @selected(old('is_active', $row->is_active ?? 1) == 1)>Yes</option>
                                <option value="0" @selected(old('is_active', $row->is_active ?? 1) == 0)>No</option>
                            </select>
                        </div>
                    @endif
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-primary flex-fill">
                            {{ $isEdit ? 'อัปเดตแผนก' : 'เพิ่มแผนก' }}
                        </button>
                        @if ($isEdit)
                            <a href="{{ route('adminweb.dept.create', ['id' => $deptId, 'q' => $q]) }}"
                                class="btn btn-outline-secondary">กลับหน้ารายการ</a>
                        @endif
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-bold">แผนกทั้งหมด</span>
                <form class="d-flex" method="GET" action="">
                    <input type="text" name="q" value="{{ $q }}"
                        class="form-control form-control-sm me-2" placeholder="ค้นหา code/name/site/cc">
                    <button class="btn btn-sm btn-outline-secondary">ค้นหา</button>
                </form>
            </div>
            <div class="card-body p-0">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Site</th>
                            <th>แผนกแม่</th>
                            <th>Cost Center</th>
                            <th>Active</th>
                            <th>Edit</th>
                            <th class="text-end">Del</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $no = 0;
                        @endphp
                        @forelse($items as $d)
                            @php
                                $no = $no + 1;
                            @endphp
                            <tr>
                                <td>{{ $no }}</td>
                                <td>{{ $d->code }}</td>
                                <td>{{ $d->name }}</td>
                                <td>{{ $d->site_code }}</td>
                                <td>
                                    @if (is_null($d->parent_id))
                                        <span class="text-primary fw-bold">[แผนกหลัก]</span>
                                    @else
                                        {{ $parents[$d->parent_id] ?? '-' }}
                                    @endif
                                </td>
                                <td>{{ $d->cost_center }}</td>
                                <td>
                                    <span class="badge bg-{{ $d->is_active ? 'success' : 'secondary' }}">
                                        {{ $d->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary"
                                        href="{{ route('adminweb.dept.edit', ['id' => $d->id, 'q' => $q]) }}">แก้ไข</a>
                                </td>
                                <td class="text-end">

                                    <form class="d-inline" method="POST"
                                        action="{{ route('adminweb.dept.destroy', $d->id) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="button" class="btn btn-sm btn-danger btn-delete">ลบ</button>
                                    </form>
                                </td>
                            </tr>

                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted">ไม่พบข้อมูล</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const deleteButtons = document.querySelectorAll('.btn-delete');
        deleteButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                const form = this.closest('form');

                Swal.fire({
                    title: 'ยืนยันการลบ?',
                    text: "ลบแล้วจะไม่สามารถกู้คืนได้",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'ลบ',
                    cancelButtonText: 'ยกเลิก'
                }).then((result) => {
                    if (result.isConfirmed) {
                        form.submit();
                    }
                });
            });
        });
    });
</script>
