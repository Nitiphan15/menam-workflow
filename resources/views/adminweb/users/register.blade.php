@extends('layouts.layout')


@section('title', 'สมัครสมาชิก')

@section('content')
    <div class="container py-4">
        @if (session('ok'))
            <div class="alert alert-success">{{ session('ok') }}</div>
        @endif

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white fw-bold">สมัครสมาชิก</div>
            <div class="card-body">
                <form method="POST" action="{{ route('adminweb.users.store') }}" class="row g-3">
                    @csrf
                    <div class="col-md-3">
                        <label class="form-label">รหัสพนักงาน</label>
                        <input name="user_code" class="form-control" value="{{ old('user_code') }}">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">ชื่อ-นามสกุล</label>
                        <input name="name" class="form-control" value="{{ old('name') }}" required>
                    </div>

                    <div class="col-md-5">
                        <label class="form-label">อีเมล</label>
                        <input name="email" type="email" value="{{ old('email') }}" class="form-control" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">โทรศัพท์</label>
                        <input name="phone" class="form-control" value="{{ old('phone') }}">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">สถานะ</label>
                        <select name="is_active" class="form-select">
                            <option value="1" {{ old('is_active', '1') == '1' ? 'selected' : '' }}>Active</option>
                            <option value="0" {{ old('is_active') == '0' ? 'selected' : '' }}>Inactive</option>
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <label class="form-label">แผนกหลัก</label>
                            <select id="department_id" name="department_id" class="form-select" required>
                                <option value="">—</option>
                                @foreach ($departments as $d)
                                    <option value="{{ $d->id }}"
                                        {{ old('department_id') == $d->id ? 'selected' : '' }}>
                                        {{ $d->name }} ({{ $d->code }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">ตำแหน่งจริงของแผนก</label>
                            <select id="department_role_id" name="department_role_id" class="form-select"
                                {{ old('department_id') ? '' : 'disabled' }}>
                                <option value="">— เลือกแผนกก่อน —</option>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label">สิทธิ์การใช้งานเว็บ (เลือกได้หลายอัน)</label>
                        <select id="web_roles" name="web_roles[]" class="form-select" multiple size="5"
                            placeholder="Search and select web roles">
                            @foreach ($webRoles as $r)
                                <option value="{{ $r->id }}"
                                    {{ collect(old('web_roles', []))->contains($r->id) ? 'selected' : '' }}>
                                    [{{ $r->code }}] {{ $r->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">กด Ctrl/Command เพื่อเลือกหลายรายการ</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">รหัสผ่าน</label>
                        <input name="password" type="password" class="form-control" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">ยืนยันรหัสผ่าน</label>
                        <input name="password_confirmation" type="password" class="form-control" required>
                    </div>

                    <div class="col-12"><button class="btn btn-primary w-100">เพิ่มผู้ใช้</button></div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-bold">ผู้ใช้ทั้งหมด</span>
                <form class="d-flex" method="GET" action="">
                    <input type="text" name="q" value="{{ $q }}"
                        class="form-control form-control-sm me-2" placeholder="ค้นหา ชื่อ/อีเมล/รหัส">
                    <button class="btn btn-sm btn-outline-secondary">ค้นหา</button>
                </form>
            </div>
            <div class="card-body p-0">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>รหัส</th>
                            <th>ชื่อ</th>
                            <th>อีเมล</th>
                            <th>สถานะ</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    @php
                        $num = 0;
                    @endphp
                    <tbody>
                        @forelse($users as $u)
                            <tr>
                                <td>{{ $num += 1 }}</td>
                                <td>{{ $u->user_code }}</td>
                                <td>{{ $u->name }}</td>
                                <td>{{ $u->email }}</td>
                                <td><span
                                        class="badge bg-{{ $u->is_active ? 'success' : 'secondary' }}">{{ $u->is_active ? 'Active' : 'Inactive' }}</span>
                                </td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('adminweb.users.destroy', $u->id) }}">
                                        @csrf @method('DELETE')
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
            <div class="card-footer">{{ $users->links() }}</div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const deptSel = document.getElementById('department_id'); // ✅ เปลี่ยน id
        const roleSel = document.getElementById('department_role_id');
        const oldRoleId = "{{ old('department_role_id') }}";

        if (window.TomSelect && document.getElementById('web_roles')) {
            new TomSelect('#web_roles', {
                plugins: ['remove_button'],
                maxOptions: 500,
                searchField: ['text'],
                placeholder: 'Search and select web roles'
            });
        }

        function setRoles(opts) {
            roleSel.innerHTML = '';
            if (!opts || !opts.length) {
                roleSel.innerHTML = '<option value="">— ไม่มีตำแหน่งในแผนกนี้ —</option>';
                roleSel.disabled = true;
                return;
            }
            roleSel.disabled = false;
            roleSel.appendChild(new Option('— ไม่ระบุ —', ''));
            opts.forEach(r => roleSel.appendChild(new Option(`${r.name} (${r.code})`, r.id)));

            // ✅ คืนค่าเดิม
            if (oldRoleId) {
                roleSel.value = oldRoleId;
            }
        }

        async function loadDeptRoles() {
            const id = deptSel.value;
            if (!id) {
                setRoles(null);
                return;
            }

            roleSel.disabled = true;
            roleSel.innerHTML = '<option value="">กำลังโหลด...</option>';

            try {
                const url = "{{ route('api.deptroles.bydept', ['dept' => '__ID__']) }}"
                    .replace('__ID__', encodeURIComponent(id));
                const res = await fetch(url, {
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                setRoles(await res.json());
            } catch (e) {
                setRoles(null);
            }
        }

        if (deptSel && roleSel) {
            deptSel.addEventListener('change', loadDeptRoles);
            loadDeptRoles(); // โหลดครั้งแรก
        } else {
            console.warn('ไม่พบ element #department_id หรือ #department_role_id');
        }

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
    });
</script>
@endpush
