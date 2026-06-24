@extends('layouts.layout')


@section('title', 'ผูกผู้ใช้กับแผนก')

@section('content')
    <div class="container py-4">
        @if (session('ok'))
            <div class="alert alert-success">{{ session('ok') }}</div>
        @endif

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white fw-bold">ผูกผู้ใช้กับตำแหน่งของแผนก</div>
            <div class="card-body">
                <form method="POST" action="{{ route('adminweb.udr.store') }}" class="row g-3">
                    @csrf
                    <div class="col-md-3">
                        <label class="form-label">แผนก</label>
                        <select id="dept_id" name="department_id" class="form-select" required onchange="loadRoles()">
                            @foreach ($departments as $d)
                                <option value="{{ $d->id }}" @selected($d->id == $deptId)>{{ $d->name }} ({{ $d->code }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">ผู้ใช้ (พิมพ์เพื่อค้นหา)</label>
                        <input id="user_text" class="form-control" list="userList" placeholder="ชื่อ/อีเมล/รหัสพนักงาน"
                            autocomplete="off">
                        <datalist id="userList"></datalist>
                        <input type="hidden" id="user_id" name="user_id" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">ตำแหน่ง (ในแผนก)</label>
                        <select id="department_role_id" name="department_role_id" class="form-select" required>
                            @foreach ($roles as $r)
                                <option value="{{ $r->id }}">{{ $r->name }} ({{ $r->code }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">Primary</label>
                        <select name="is_primary" class="form-select">
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <button class="btn btn-primary w-100">เพิ่ม</button>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">เริ่ม</label>
                        <input type="date" name="start_date" class="form-control" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">สิ้นสุด</label>
                        <input type="date" name="end_date" class="form-control">
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header d-flex align-items-center gap-2">
                <span class="fw-bold">รายการผูกผู้ใช้ ({{ optional($departments->firstWhere('id', $deptId))->code }})</span>
                <form class="ms-auto d-flex" method="GET" action="">
                    <input type="hidden" name="dept_id" value="{{ $deptId }}">
                    <input type="text" name="q" value="{{ $q }}"
                        class="form-control form-control-sm me-2" placeholder="ค้นหา ชื่อ/อีเมล/รหัส/ตำแหน่ง">
                    <button class="btn btn-sm btn-outline-secondary">ค้นหา</button>
                </form>
            </div>
            <div class="card-body p-0">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ผู้ใช้</th>
                            <th>อีเมล</th>
                            <th>ตำแหน่ง</th>
                            <th>ช่วงเวลา</th>
                            <th>Primary</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($items as $it)
                            <tr>
                                <td>{{ $it->name }} ({{ $it->user_code }})</td>
                                <td>{{ $it->email }}</td>
                                <td>{{ $it->role_name }} <small class="text-muted">({{ $it->role_code }})</small></td>
                                <td>{{ $it->start_date ?: '-' }} – {{ $it->end_date ?: 'ปัจจุบัน' }}</td>
                                <td><span
                                        class="badge bg-{{ $it->is_primary ? 'success' : 'secondary' }}">{{ $it->is_primary ? 'Yes' : 'No' }}</span>
                                </td>
                                <td class="text-end">
                                    <form class="d-inline" method="POST"
                                        action="{{ route('adminweb.udr.destroy', $it->id) }}">
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
            <div class="card-footer">{{ $items->links() }}</div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    const txt = document.getElementById('user_text');
    const list = document.getElementById('userList');
    const hid = document.getElementById('user_id');
    const deptSel = document.getElementById('dept_id');
    const roleSel = document.getElementById('department_role_id');

    function fillUsers(items) {
        list.innerHTML = '';
        items.forEach(u => {
            const o = document.createElement('option');
            o.value = u.text;
            o.dataset.id = u.id;
            list.appendChild(o);
        });
    }
    let t = null;
    txt.addEventListener('input', () => {
        clearTimeout(t);
        t = setTimeout(async () => {
            const res = await fetch(
                `{{ route('api.users.search') }}?q=${encodeURIComponent(txt.value)}`, {
                    headers: {
                        'Accept': 'application/json'
                    }
                });
            const data = await res.json();
            fillUsers(data.results || data);
        }, 250);
    });
    const sync = () => {
        const o = [...list.options].find(x => x.value === txt.value);
        hid.value = o ? o.dataset.id : '';
    };
    txt.addEventListener('change', sync);
    txt.addEventListener('blur', sync);

    async function loadRoles() {
        const dept = deptSel.value;
        const res = await fetch(`{{ route('api.deptroles.bydept', ['dept' => ':id']) }}`.replace(':id',
            dept), {
            headers: {
                'Accept': 'application/json'
            }
        });
        const roles = await res.json();
        roleSel.innerHTML = '';
        roles.forEach(r => {
            const o = document.createElement('option');
            o.value = r.id;
            o.textContent = `${r.name} (${r.code})`;
            roleSel.appendChild(o);
        });
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
</script>
@endpush
