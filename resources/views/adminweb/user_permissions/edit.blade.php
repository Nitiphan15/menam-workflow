@extends('layouts.layout')

@section('title', 'Manage Permission')

@section('content')
    @php
        $checkedRoleCodes = old('role_codes', $selectedRoleCodes);
    @endphp

    <div class="container">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <div>

                <h5 class="mb-1">{{ $user->name }}</h5>
                <div class="text-muted">
                    {{ $user->email }} |
                    Department:
                    <b>
                        @if ($department)
                            {{ $department->name }} ({{ $department->code }})
                        @else
                            -
                        @endif
                    </b>
                </div>
            </div>
            <a class="btn btn-outline-secondary" href="{{ route('adminweb.user-permissions.index') }}">Back</a>
        </div>

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        <form method="POST" action="{{ route('adminweb.user-permissions.update', $user->id) }}">
            @csrf
            @method('PUT')

            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div class="fw-semibold">Roles (dept_roles.is_active = 1)</div>
                    <div class="d-flex gap-2 align-items-center">
                        <span class="badge bg-primary" id="selectedRoleCount">{{ count($checkedRoleCodes) }} selected</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAll(true)">Select
                            all</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                            onclick="toggleAll(false)">Clear</button>
                    </div>
                </div>

                <div class="card-body">
                    @if ($errors->has('role_ids') || $errors->has('role_codes') || $errors->has('role_codes.*'))
                        <div class="alert alert-danger py-2">
                            {{ $errors->first('role_ids') ?: $errors->first('role_codes') ?: $errors->first('role_codes.*') }}
                        </div>
                    @endif

                    <input type="text" class="form-control mb-3" id="roleFilter"
                        placeholder="Search role code or name">

                    <div class="row">
                        @foreach ($roles as $r)
                            <div class="col-md-4 col-lg-3 mb-2 role-option"
                                data-search="{{ strtolower($r->code . ' ' . $r->name) }}">
                                <label class="form-check">
                                    <input class="form-check-input role-checkbox" type="checkbox" name="role_codes[]"
                                        value="{{ $r->code }}"
                                        {{ in_array($r->code, $checkedRoleCodes) ? 'checked' : '' }}>
                                    <span class="form-check-label">
                                        <b>{{ $r->code }}</b> — {{ $r->name }}
                                    </span>
                                </label>
                            </div>
                        @endforeach
                    </div>

                    <div class="text-muted mt-2">
                        * บันทึกจะเขียนลงตาราง <code>user_dept_roles</code> โดยใช้ <code>department_id</code> จาก users
                    </div>
                </div>

                <div class="card-footer text-end">
                    <button class="btn btn-primary">Save</button>
                </div>
            </div>
        </form>
    </div>

    <script>
        function updateSelectedCount() {
            const total = document.querySelectorAll('.role-checkbox:checked').length;
            const label = document.getElementById('selectedRoleCount');
            if (label) {
                label.textContent = `${total} selected`;
            }
        }

        function toggleAll(checked) {
            document.querySelectorAll('.role-option:not(.d-none) .role-checkbox').forEach(cb => cb.checked = checked);
            updateSelectedCount();
        }

        document.querySelectorAll('.role-checkbox').forEach(cb => cb.addEventListener('change', updateSelectedCount));
        document.getElementById('roleFilter')?.addEventListener('input', function() {
            const q = this.value.trim().toLowerCase();
            document.querySelectorAll('.role-option').forEach(item => {
                item.classList.toggle('d-none', q && !item.dataset.search.includes(q));
            });
        });
    </script>
@endsection
