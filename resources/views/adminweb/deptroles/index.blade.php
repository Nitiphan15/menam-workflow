@extends('layouts.layout')


@section('title', 'เพิ่มตำแหน่ง')

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
                @if ($isEdit)
                    แก้ไขตำแหน่งของแผนก
                @else
                    เพิ่มตำแหน่งของแผนก
                @endif
            </div>
            <div class="card-body">
                <form method="POST"
                    action="{{ $isEdit ? route('adminweb.deptroles.update', $row->id) : route('adminweb.deptroles.store') }}"
                    class="row g-3">
                    @csrf
                    @if ($isEdit)
                        @method('PUT')
                    @endif

                    {{-- preserve filters when coming back --}}
                    <input type="hidden" name="back_dept_id" value="{{ $deptId ?? '' }}">
                    <input type="hidden" name="back_q" value="{{ $q ?? '' }}">

                    <div class="col-md-4">
                        <label class="form-label">แผนก</label>
                        <select name="department_id" class="form-select" required>
                            @foreach ($departments as $d)
                                <option value="{{ $d->id }}" @selected(old('department_id', $row->department_id ?? ($deptId ?? 0)) == $d->id)>
                                    {{ $d->name }} ({{ $d->code }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">รหัสตำแหน่ง</label>
                        <input name="code" class="form-control" required value="{{ old('code', $row->code ?? '') }}">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">ชื่อตำแหน่ง</label>
                        <input name="name" class="form-control" required value="{{ old('name', $row->name ?? '') }}">
                    </div>

                    <div class="col-md-1">
                        <label class="form-label">ระดับ</label>
                        <input name="level_no" type="number" class="form-control" placeholder="1.."
                            value="{{ old('level_no', $row->level_no ?? '') }}">
                    </div>

                    <div class="col-md-1">
                        <label class="form-label">Active</label>
                        <select name="is_active" class="form-select">
                            <option value="1" @selected(old('is_active', $row->is_active ?? 1) == 1)>Yes</option>
                            <option value="0" @selected(old('is_active', $row->is_active ?? 1) == 0)>No</option>
                        </select>
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-primary flex-fill">
                            {{ $isEdit ? 'อัปเดตตำแหน่ง' : 'เพิ่มตำแหน่ง' }}
                        </button>
                        @if ($isEdit)
                            <a href="{{ route('adminweb.deptroles.index', ['dept_id' => $deptId, 'q' => $q]) }}"
                                class="btn btn-outline-secondary">กลับหน้ารายการ</a>
                        @endif
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header d-flex align-items-center gap-2">
                <span class="fw-bold">ตำแหน่งทั้งหมด</span>
                <form class="ms-auto d-flex" method="GET" action="">
                    <select name="dept_id" class="form-select form-select-sm me-2" onchange="this.form.submit()">
                        @foreach ($departments as $d)
                            <option value="{{ $d->id }}" @selected($d->id == $deptId)>{{ $d->name }} ({{ $d->code }})</option>
                        @endforeach
                    </select>
                    <input type="text" name="q" value="{{ $q }}"
                        class="form-control form-control-sm me-2" placeholder="ค้นหา code/name">
                    <button class="btn btn-sm btn-outline-secondary">ค้นหา</button>
                    @if ($q)
                        <a class="btn btn-sm btn-link"
                            href="{{ route('adminweb.deptroles.index', ['dept_id' => $deptId]) }}">ล้าง</a>
                    @endif
                </form>
            </div>
            <div class="card-body p-0">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Dept</th>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Level</th>
                            <th>Active</th>
                            <th>Edit</th>
                            <th class="text-end">Del</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $no = 0;
                        @endphp
                        @forelse($items as $it)
                            @php
                                $no += 1;
                            @endphp
                            <tr>
                                <td>{{ $no }}</td>
                                <td>{{ $it->dept_name }} <small class="text-muted">({{ $it->dept_code }})</small></td>
                                <td>{{ $it->code }}</td>
                                <td>{{ $it->name }}</td>
                                <td>{{ $it->level_no }}</td>
                                <td><span
                                        class="badge bg-{{ $it->is_active ? 'success' : 'secondary' }}">{{ $it->is_active ? 'Yes' : 'No' }}</span>
                                </td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary"
                                        href="{{ route('adminweb.deptroles.edit', $it->id) }}">แก้ไข</a>
                                </td>
                                <td class="text-end">

                                    <form class="d-inline" method="POST"
                                        action="{{ route('adminweb.deptroles.destroy', $it->id) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="button" class="btn btn-sm btn-danger btn-delete">ลบ</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted">ไม่พบข้อมูล</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">{{ $items->links() }}</div>
        </div>
    </div>
@endsection
@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const deleteButtons = document.querySelectorAll('.btn-delete');
        deleteButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                const form = this.closest('form');

                if (!window.Swal) {
                    form.submit();
                    return;
                }

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
@endpush
