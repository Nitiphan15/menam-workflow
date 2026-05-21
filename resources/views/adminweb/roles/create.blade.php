@extends('layouts.layout')


@section('title', 'เพิ่มสิทธิ์')

@section('content')
    @php
        $isEdit = isset($row) && !empty($row->id);

    @endphp
    @if (session('ok'))
        <div class="alert alert-success">{{ session('ok') }}</div>
    @endif
    <div class="container py-4">

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white fw-bold">
                เพิ่มสิทธิ์
            </div>
            <div class="card-body">

                <form method="POST"
                    action="{{ $isEdit ? route('adminweb.roles.update', $row->id) : route('adminweb.roles.store') }}"
                    class="row g-3">
                    @csrf
                    @if ($isEdit)
                        @method('PUT')
                    @endif

                    <input type="hidden" name="back_dept_id" value="{{ $row->id ?? '' }}">
                    <input type="hidden" name="back_q" value="{{ $q ?? '' }}">


                    <div class="col-md-4">
                        <label class="form-label">รหัสสิทธิ์</label>
                        <input type="hidden" value="{{ old('code', $row->id ?? '') }}">
                        <input name="code" class="form-control" required value="{{ old('code', $row->code ?? '') }}">
                    </div>
                    <div class="col-md-7">
                        <label class="form-label">ชื่อสิทธิ์</label>
                        <input name="name" class="form-control" required value="{{ old('name', $row->name ?? '') }}">
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
                        <button class="btn btn-primary flex-fill"> {{ $isEdit ? 'อัปเดตสิทธิ์' : 'เพิ่มสิทธิ์' }}</button>
                        @if ($isEdit)
                            <a href="{{ route('adminweb.roles.create') }}"
                                class="btn btn-outline-secondary">กลับหน้ารายการ</a>
                        @endif
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-bold">สิทธิ์ทั้งหมด</span>
                <form class="d-flex" method="GET" action="">
                    <input type="text" name="q" value="{{ request('q') }}"
                        class="form-control form-control-sm me-2" placeholder="ค้นหา code/name">
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
                            <th>Edit</th>
                            <th class="text-end">Del</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $num = 0;
                        @endphp
                        @forelse($roles as $role)
                            @php
                                $num += 1;
                            @endphp
                            <tr>
                                <td>{{ $num }}</td>
                                <td>{{ $role->code }}</td>
                                <td>{{ $role->name }}</td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary"
                                        href="{{ route('adminweb.roles.edit', $role->id) }}">แก้ไข</a>
                                </td>
                                <td class="text-end">

                                    <form class="d-inline" method="POST"
                                        action="{{ route('adminweb.roles.destroy', $role->id) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="button" class="btn btn-sm btn-danger btn-delete">ลบ</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted">ไม่พบข้อมูล</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">{{ $roles->links() }}</div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('.btn-delete').forEach(btn => {
            btn.addEventListener('click', function() {
                const form = this.closest('form');

                if (!window.Swal) {
                    form.submit();
                    return;
                }

                Swal.fire({
                    title: 'ยืนยันการลบ?',
                    text: 'ลบแล้วจะไม่สามารถกู้คืนได้',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'ลบ',
                    cancelButtonText: 'ยกเลิก'
                }).then((result) => {
                    if (result.isConfirmed) {
                        form.submit();
                    }
                });
            });
        });
    </script>
@endpush
