@extends('layouts.layout')

@section('title', 'ประวัติการใช้งาน (Activity Log)')

@section('content')
    @php
        $actionBadge = [
            'view'          => 'secondary',
            'create'        => 'success',
            'insert'        => 'success', // ข้อมูลก่อนปรับปรุง
            'update'        => 'warning',
            'delete'        => 'danger',
            'approve'       => 'primary',
            'reject'        => 'danger',
            'export'        => 'info',
            'download'      => 'info',
            'email'         => 'info',
            'login'         => 'dark',
            'login_failed'  => 'danger',
            'login_blocked' => 'danger',
            'logout'        => 'dark',
        ];
        $actionLabel = [
            'view'          => 'ดู',
            'create'        => 'เพิ่ม',
            'insert'        => 'เพิ่ม',
            'update'        => 'แก้ไข',
            'delete'        => 'ลบ',
            'approve'       => 'อนุมัติ',
            'reject'        => 'ไม่อนุมัติ',
            'export'        => 'ส่งออก/พิมพ์',
            'download'      => 'ดาวน์โหลด',
            'email'         => 'ส่งเมล',
            'login'         => 'เข้าสู่ระบบ',
            'login_failed'  => 'เข้าสู่ระบบไม่สำเร็จ',
            'login_blocked' => 'ถูกระงับ login ชั่วคราว',
            'logout'        => 'ออกจากระบบ',
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
                            @foreach ($actionLabel as $value => $label)
                                @continue($value === 'insert')
                                <option value="{{ $value }}" @selected($filters['action_type'] === $value)>{{ $label }}</option>
                            @endforeach
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
                <span class="ms-2 text-muted small">
                    ช่วง {{ \Illuminate\Support\Carbon::parse($filters['date_from'])->format('d/m/Y') }}
                    – {{ \Illuminate\Support\Carbon::parse($filters['date_to'])->format('d/m/Y') }}
                    (ไม่ระบุวันที่ = ย้อนหลัง 7 วัน, ค้นได้ครั้งละไม่เกิน 92 วัน)
                </span>
                <a href="{{ route('adminweb.activity-logs.changes') }}" class="btn btn-sm btn-outline-primary ms-auto">
                    ประวัติการแก้ไขข้อมูล (ก่อน/หลัง)
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-sm mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="white-space:nowrap;">เวลา</th>
                                <th>ผู้ใช้</th>
                                <th>แผนก</th>
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
                                        @elseif ($log->user_name && $log->user_name !== 'guest')
                                            <span class="badge bg-light text-dark border">Guest</span>
                                            <small class="text-muted">{{ $log->user_name }}</small>
                                        @else
                                            <span class="badge bg-light text-dark border">Guest</span>
                                        @endif
                                    </td>
                                    <td><small>{{ $log->department_name ?? '-' }}</small></td>
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
                                    <td colspan="9" class="text-center text-muted py-4">ไม่พบข้อมูล</td>
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
