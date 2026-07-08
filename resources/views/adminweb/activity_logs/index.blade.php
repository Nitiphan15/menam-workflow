@extends('layouts.layout')

@section('title', 'ประวัติการใช้งาน (Activity Log)')

@section('content')
    @php
        $actionBadge = [
            'view'   => 'secondary',
            'insert' => 'success',
            'update' => 'warning',
            'delete' => 'danger',
        ];
        $actionLabel = [
            'view'   => 'ดู',
            'insert' => 'เพิ่ม',
            'update' => 'แก้ไข',
            'delete' => 'ลบ',
        ];
    @endphp

    <div class="container-fluid py-4">

        <div class="card shadow-sm mb-3">
            <div class="card-header bg-primary text-white fw-bold">
                ประวัติการใช้งาน (Activity Log)
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('adminweb.activity-logs.index') }}" class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label mb-1">ค้นหา</label>
                        <input type="text" name="q" value="{{ $filters['q'] }}"
                            class="form-control form-control-sm" placeholder="ชื่อ user / เมนู / route / IP">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label mb-1">การกระทำ</label>
                        <select name="action_type" class="form-select form-select-sm">
                            <option value="">ทั้งหมด</option>
                            <option value="view"   @selected($filters['action_type'] === 'view')>ดู</option>
                            <option value="insert" @selected($filters['action_type'] === 'insert')>เพิ่ม</option>
                            <option value="update" @selected($filters['action_type'] === 'update')>แก้ไข</option>
                            <option value="delete" @selected($filters['action_type'] === 'delete')>ลบ</option>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label mb-1">ประเภทผู้ใช้</label>
                        <select name="auth" class="form-select form-select-sm">
                            <option value="">ทั้งหมด</option>
                            <option value="1" @selected($filters['auth'] === '1')>สมาชิก (login)</option>
                            <option value="0" @selected($filters['auth'] === '0')>Guest</option>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label mb-1">ตั้งแต่วันที่</label>
                        <input type="date" name="date_from" value="{{ $filters['date_from'] }}"
                            class="form-control form-control-sm">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label mb-1">ถึงวันที่</label>
                        <input type="date" name="date_to" value="{{ $filters['date_to'] }}"
                            class="form-control form-control-sm">
                    </div>

                    <div class="col-md-1 d-grid gap-1">
                        <button class="btn btn-sm btn-primary">ค้นหา</button>
                        <a href="{{ route('adminweb.activity-logs.index') }}"
                            class="btn btn-sm btn-outline-secondary">ล้าง</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header d-flex align-items-center">
                <span class="fw-bold">รายการ</span>
                <span class="ms-2 text-muted small">ทั้งหมด {{ number_format($logs->total()) }} รายการ</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-sm mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="white-space:nowrap;">เวลา</th>
                                <th>ผู้ใช้</th>
                                <th>เมนู</th>
                                <th>การกระทำ</th>
                                <th>Method</th>
                                <th>Path</th>
                                <th>Status</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($logs as $log)
                                <tr>
                                    <td style="white-space:nowrap;">
                                        {{ optional($log->accessed_at)->format('Y-m-d H:i:s') }}
                                    </td>
                                    <td>
                                        @if ($log->is_authenticated)
                                            {{ $log->user_name }}
                                            @if ($log->user_id)
                                                <small class="text-muted">#{{ $log->user_id }}</small>
                                            @endif
                                        @else
                                            <span class="badge bg-light text-dark border">Guest</span>
                                        @endif
                                    </td>
                                    <td>{{ $log->menu_name ?? $log->route_name }}</td>
                                    <td>
                                        <span class="badge bg-{{ $actionBadge[$log->action_type] ?? 'secondary' }}">
                                            {{ $actionLabel[$log->action_type] ?? $log->action_type }}
                                        </span>
                                    </td>
                                    <td><code>{{ $log->method }}</code></td>
                                    <td><small class="text-muted">{{ $log->url_path }}</small></td>
                                    <td>
                                        @php $sc = (int) $log->status_code; @endphp
                                        <span class="badge bg-{{ $sc >= 500 ? 'danger' : ($sc >= 400 ? 'warning' : 'success') }}">
                                            {{ $log->status_code }}
                                        </span>
                                    </td>
                                    <td><small>{{ $log->ip_address }}</small></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">ไม่พบข้อมูล</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer">
                {{ $logs->links() }}
            </div>
        </div>
    </div>
@endsection
