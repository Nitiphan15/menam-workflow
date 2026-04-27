@extends('layouts.layout')

@section('title', 'Manage Permission')

@section('content')
    <div class="container">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <div>

                <div class="text-muted">
                    {{ $user->name }} ({{ $user->email }}) | department_id: <b>{{ $departmentId }}</b>
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
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAll(true)">Select
                            all</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                            onclick="toggleAll(false)">Clear</button>
                    </div>
                </div>

                <div class="card-body">
                    @error('role_ids')
                        <div class="text-danger mb-2">{{ $message }}</div>
                    @enderror

                    <div class="row">
                        @foreach ($roles as $r)
                            <div class="col-md-4 col-lg-3 mb-2">
                                <label class="form-check">
                                    <input class="form-check-input role-checkbox" type="checkbox" name="role_ids[]"
                                        value="{{ $r->id }}"
                                        {{ in_array($r->id, $selectedRoleIds) ? 'checked' : '' }}>
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
        function toggleAll(checked) {
            document.querySelectorAll('.role-checkbox').forEach(cb => cb.checked = checked);
        }
    </script>
@endsection
