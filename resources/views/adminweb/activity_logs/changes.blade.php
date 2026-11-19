@extends('layouts.layout')

@section('title', 'ประวัติการแก้ไขข้อมูล (Audit Trail)')

@section('content')
    @php
        $entityLabel = [
            'user'            => 'ผู้ใช้',
            'user_permission' => 'สิทธิ์ผู้ใช้',
            'user_department' => 'แผนกของผู้ใช้',
            'role'            => 'Role',
            'department'      => 'แผนก',
            'dept_role'       => 'Role ของแผนก',
            'user_dept_role'  => 'Role ผู้ใช้ในแผนก',
            'dept_manager'    => 'หัวหน้าแผนก',
        ];
        $eventBadge = ['created' => 'success', 'updated' => 'warning', 'deleted' => 'danger'];
        $eventLabel = ['created' => 'เพิ่ม', 'updated' => 'แก้ไข', 'deleted' => 'ลบ'];
        $show = function ($v) {
            if (is_null($v)) {
                return '—';
            }
            if (is_bool($v)) {
                return $v ? 'true' : 'false';
            }
            if (is_array($v)) {
                return implode(', ', array_map(fn ($x) => is_scalar($x) ? (string) $x : json_encode($x, JSON_UNESCAPED_UNICODE), $v)) ?: '—';
            }

            return (string) $v;
        };
    @endphp

    <div class="container-fluid py-4">

        <div class="card shadow-sm mb-3">
            <div class="card-header bg-primary text-white fw-bold d-flex align-items-center">
                ประวัติการแก้ไขข้อมูล (ก่อน/หลัง)
                <a href="{{ route('adminweb.activity-logs.index') }}" class="btn btn-sm btn-light ms-auto">
                    กลับไปหน้า Activity Log
                </a>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('adminweb.activity-logs.changes') }}" class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label mb-1">ค้นหา</label>
                        <input type="text" name="q" value="{{ $filters['q'] }}"
                            class="form-control form-control-sm" placeholder="ผู้แก้ไข / ชื่อรายการ / ID">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label mb-1">ประเภทข้อมูล</label>
                        <select name="entity_type" class="form-select form-select-sm">
                            <option value="">ทั้งหมด</option>
                            @foreach ($entityLabel as $value => $label)
                                <option value="{{ $value }}" @selected($filters['entity_type'] === $value)>{{ $label }}</option>
                            @endforeach
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

                    <div class="col-md-2 d-grid gap-1">
                        <button class="btn btn-sm btn-primary">ค้นหา</button>
                        <a href="{{ route('adminweb.activity-logs.changes') }}"
                            class="btn btn-sm btn-outline-secondary">ล้าง</a>
                    </div>
                </form>
            </div>
        </div>

        @if (!empty($tableMissing))
            <div class="alert alert-warning">
                ยังไม่ได้สร้างตาราง <code>user_audit_changes</code> —
                ให้รัน <code>database/sql/activity-log/02_create_user_audit_changes.sql</code> แล้วเคลียร์ cache
            </div>
        @endif

        <div class="card shadow-sm">
            <div class="card-header">
                <span class="fw-bold">รายการ</span>
                <span class="ms-2 text-muted small">
                    ช่วง {{ \Illuminate\Support\Carbon::parse($filters['date_from'])->format('d/m/Y') }}
                    – {{ \Illuminate\Support\Carbon::parse($filters['date_to'])->format('d/m/Y') }}
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-top">
                        <thead class="table-light">
                            <tr>
                                <th style="white-space:nowrap;">เวลา</th>
                                <th>ผู้แก้ไข</th>
                                <th>ประเภท</th>
                                <th>รายการ</th>
                                <th>การกระทำ</th>
                                <th style="min-width: 420px;">การเปลี่ยนแปลง</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($changes as $c)
                                <tr>
                                    <td style="white-space:nowrap;">{{ optional($c->changed_at)->format('Y-m-d H:i:s') }}</td>
                                    <td>
                                        {{ $c->user_name ?? '-' }}
                                        @if ($c->user_id)
                                            <small class="text-muted">#{{ $c->user_id }}</small>
                                        @endif
                                        <div><small class="text-muted">{{ $c->ip_address }}</small></div>
                                    </td>
                                    <td>{{ $entityLabel[$c->entity_type] ?? $c->entity_type }}</td>
                                    <td>
                                        {{ $c->entity_label ?? '-' }}
                                        <small class="text-muted">#{{ $c->entity_id }}</small>
                                    </td>
                                    <td>
                                        <span class="badge bg-{{ $eventBadge[$c->event] ?? 'secondary' }}">
                                            {{ $eventLabel[$c->event] ?? $c->event }}
                                        </span>
                                    </td>
                                    <td>
                                        <table class="table table-sm table-borderless mb-0 small">
                                            @foreach ((array) $c->changes as $field => $diff)
                                                <tr>
                                                    <td class="text-muted" style="width: 30%;">{{ $field }}</td>
                                                    <td class="text-danger text-decoration-line-through" style="width: 35%;">{{ $show($diff['old'] ?? null) }}</td>
                                                    <td class="text-success" style="width: 35%;">{{ $show($diff['new'] ?? null) }}</td>
                                                </tr>
                                            @endforeach
                                        </table>
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
            <div class="card-footer">
                {{ $changes->links() }}
            </div>
        </div>
    </div>
@endsection
