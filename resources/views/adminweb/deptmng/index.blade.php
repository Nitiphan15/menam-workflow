@extends('layouts.layout')

@section('content')
    <div class="container">
        @if (session('ok'))
            <div class="alert alert-success">{{ session('ok') }}</div>
        @endif

        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <span>หัวหน้าแผนก</span>
                <form method="GET" action="{{ route('adminweb.deptmgr.index') }}" class="ms-auto d-flex gap-2">
                    <label class="mb-0">แผนก:</label>
                    <select name="dept_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        @foreach ($departments as $d)
                            <option value="{{ $d->id }}" {{ $deptId == $d->id ? 'selected' : '' }}>
                                {{ $d->name }} ({{ $d->code }})</option>
                        @endforeach
                    </select>
                </form>
            </div>

            <div class="card-body">
                <form method="POST" action="{{ route('adminweb.deptmgr.store') }}" class="row g-3">
                    @csrf
                    <input type="hidden" name="department_id" value="{{ $deptId }}">
                    <div class="col-md-5">
                        <label class="form-label">ผู้ใช้</label>
                        <select id="user_id" name="user_id" placeholder="ค้นหาผู้ใช้..."></select>
                    </div>

                    <div class="col-md-2"><label>เริ่ม</label><input type="date" name="start_date" class="form-control"
                            required></div>
                    <div class="col-md-2"><label>สิ้นสุด</label><input type="date" name="end_date" class="form-control">
                    </div>
                    <div class="col-md-2"><label>Primary</label>
                        <select name="is_primary" class="form-select">
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    </div>
                    <div class="col-md-1 d-flex align-items-end"><button class="btn btn-primary w-100">เพิ่ม</button></div>
                </form>

                <hr>

                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>ผู้จัดการ</th>
                            <th>ช่วงเวลา</th>
                            <th>Primary</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($list as $m)
                            <tr>
                                <td>{{ $m->name }} ({{ $m->email }} )</td>
                                <td>{{ $m->start_date }} — {{ $m->end_date ?: 'ปัจจุบัน' }}</td>
                                <td>{{ $m->is_primary ? 'Yes' : 'No' }}</td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('adminweb.deptmgr.destroy', $m->id) }}"
                                        onsubmit="return confirm('ลบรายการนี้?')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger">ลบ</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted">ยังไม่มีข้อมูล</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        new TomSelect('#user_id', {
            valueField: 'id',
            labelField: 'text',
            searchField: 'text',
            load: function(query, callback) {
                if (!query.length) return callback();
                fetch("{{ route('api.users.search') }}?q=" + encodeURIComponent(query))
                    .then(res => res.json())
                    .then(json => {
                        callback(json.results || []);
                    }).catch(() => {
                        callback();
                    });
            }
        });
    </script>
@endpush
