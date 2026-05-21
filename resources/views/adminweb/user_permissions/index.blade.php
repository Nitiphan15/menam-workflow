@extends('layouts.layout')
@section('title', 'User Permission')
@section('content')
    <div class="container">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
                <h5 class="mb-1">User Permission</h5>
                <div class="text-muted small">Search a user, then manage permissions for the user's primary department.</div>
            </div>

            <form class="d-flex" method="GET" action="{{ route('adminweb.user-permissions.index') }}">
                <input class="form-control me-2" name="q" value="{{ $q }}" placeholder="ค้นหา name/email/code">
                <button class="btn btn-primary">Search</button>
            </form>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>User Code</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Department</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($users as $u)
                            <tr>
                                <td>{{ $u->id }}</td>
                                <td>{{ $u->user_code }}</td>
                                <td>{{ $u->name }}</td>
                                <td>{{ $u->email }}</td>
                                <td>
                                    @if ($u->department)
                                        {{ $u->department->name }}
                                        <small class="text-muted">({{ $u->department->code }})</small>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary"
                                        href="{{ route('adminweb.user-permissions.edit', $u->id) }}">
                                        Manage
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">ไม่พบข้อมูล</td>
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
