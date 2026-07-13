@extends('layouts.layout')

@section('title', 'User Department Assignment')

@section('content')
    <div class="container py-4">
        @if (session('ok'))
            <div class="alert alert-success">{{ session('ok') }}</div>
        @endif

        <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
                <h5 class="mb-1">User Department Assignment</h5>
                <div class="text-muted small">Change a user's main department and current primary department role.</div>
            </div>
            <form class="d-flex" method="GET" action="{{ route('adminweb.user-department-assignments.index') }}">
                <input class="form-control me-2" name="q" value="{{ $q }}" placeholder="Search name/email/code">
                <button class="btn btn-primary">Search</button>
            </form>
        </div>

        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 24%;">User</th>
                            <th style="width: 17%;">Current Department</th>
                            <th style="width: 19%;">Current Role</th>
                            <th style="width: 16%;">New Department</th>
                            <th style="width: 16%;">New Role</th>
                            <th style="width: 8%;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($users as $user)
                            @php
                                $currentRole = $user->current_role;
                                $selectedDepartmentId = old(
                                    "users.{$user->id}.department_id",
                                    $currentRole->role_department_id ?? $user->department_id,
                                );
                                $selectedRoleId = old(
                                    "users.{$user->id}.department_role_id",
                                    $currentRole->department_role_id ?? '',
                                );
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $user->name }}</div>
                                    <div class="text-muted small">
                                        {{ $user->user_code ?: '-' }} | {{ $user->email }}
                                    </div>
                                </td>
                                <td>
                                    @if ($user->department_id)
                                        {{ $user->department_name ?: '-' }}
                                        <small class="text-muted">({{ $user->department_code ?: '-' }})</small>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($currentRole)
                                        {{ $currentRole->role_name }}
                                        <small class="text-muted">({{ $currentRole->role_code }})</small>
                                    @else
                                        <span class="text-muted">No primary role</span>
                                    @endif
                                </td>
                                <td colspan="3">
                                    <form class="row g-2 align-items-center"
                                        method="POST"
                                        action="{{ route('adminweb.user-department-assignments.update', $user->id) }}">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="start_date" value="{{ $today }}">

                                        <div class="col-md-5">
                                            <select name="department_id"
                                                class="form-select form-select-sm js-department-select"
                                                data-user-id="{{ $user->id }}"
                                                required>
                                                @foreach ($departments as $department)
                                                    <option value="{{ $department->id }}"
                                                        @selected((int) $selectedDepartmentId === (int) $department->id)>
                                                        {{ $department->name }} ({{ $department->code }})
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <div class="col-md-5">
                                            <select name="department_role_id"
                                                class="form-select form-select-sm js-role-select"
                                                data-user-id="{{ $user->id }}"
                                                data-selected-role-id="{{ $selectedRoleId }}"
                                                required>
                                            </select>
                                        </div>

                                        <div class="col-md-2 text-end">
                                            <button class="btn btn-sm btn-primary w-100">Save</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No users found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">
            {{ $users->links() }}
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const rolesByDepartment = @json($roles->groupBy('department_id')->map->values());

            function renderRoles(userId) {
                const departmentSelect = document.querySelector(`.js-department-select[data-user-id="${userId}"]`);
                const roleSelect = document.querySelector(`.js-role-select[data-user-id="${userId}"]`);
                const departmentId = String(departmentSelect.value || '');
                const selectedRoleId = String(roleSelect.dataset.selectedRoleId || '');
                const roles = rolesByDepartment[departmentId] || [];

                roleSelect.innerHTML = '';

                if (!roles.length) {
                    roleSelect.appendChild(new Option('No active roles in this department', ''));
                    roleSelect.disabled = true;
                    return;
                }

                roleSelect.disabled = false;

                roles.forEach(role => {
                    const option = new Option(`${role.name} (${role.code})`, role.id);
                    if (String(role.id) === selectedRoleId) {
                        option.selected = true;
                    }
                    roleSelect.appendChild(option);
                });
            }

            document.querySelectorAll('.js-department-select').forEach(select => {
                renderRoles(select.dataset.userId);
                select.addEventListener('change', function() {
                    const roleSelect = document.querySelector(
                        `.js-role-select[data-user-id="${this.dataset.userId}"]`
                    );
                    roleSelect.dataset.selectedRoleId = '';
                    renderRoles(this.dataset.userId);
                });
            });
        });
    </script>
@endpush
