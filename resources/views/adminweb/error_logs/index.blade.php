@extends('layouts.layout')

@section('title', 'Error Log')

@push('styles')
    <style>
        .errlog-detail pre {
            max-height: 420px;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
            font-size: .78rem;
            background: #f8f9fa;
            border: 1px solid #e5e7eb;
            border-radius: .375rem;
            padding: .75rem;
            margin: 0;
        }
        .errlog-msg { word-break: break-word; }
        /* badge ของ context (เช่น user-agent) ยาวมาก — ให้ตัดบรรทัดแทนการดันตารางให้เลื่อนแนวนอน */
        .errlog-msg .badge { white-space: normal; text-align: left; word-break: break-all; max-width: 100%; }
        .errlog-count { font-size: 1.6rem; font-weight: 700; line-height: 1; }
        /* ใช้ class ของหน้านี้เอง เพราะ layout-base.css override d-none / d-md-none ของ Bootstrap ไว้ */
        .errlog-inline-meta { display: none; }
        @media (max-width: 767.98px) {
            .errlog-col-wide { display: none; }
            .errlog-inline-meta { display: block; }
        }
    </style>
@endpush

@section('content')
    @php
        $levelLabel = [
            'error'   => 'Error ขึ้นไป',
            'warning' => 'Warning',
            'info'    => 'Info / Notice',
            'debug'   => 'Debug',
        ];
        $levelBadge = [
            'error'   => 'danger',
            'warning' => 'warning',
            'info'    => 'info',
            'debug'   => 'secondary',
        ];
        $fmtSize = function (int $bytes): string {
            return $bytes >= 1048576
                ? number_format($bytes / 1048576, 1) . ' MB'
                : number_format($bytes / 1024, 0) . ' KB';
        };
        $isClientFile = str_starts_with($filters['file'], 'client-errors');
    @endphp

    <div class="container-fluid py-4">

        <div class="card shadow-sm mb-3">
            <div class="card-header bg-danger text-white fw-bold d-flex align-items-center">
                <i class="fa-solid fa-bug me-2"></i> Error Log
                <a href="{{ route('adminweb.activity-logs.index') }}" class="btn btn-sm btn-light ms-auto">
                    ประวัติการใช้งาน (Activity Log)
                </a>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('adminweb.error-logs.index') }}" class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label mb-1">ไฟล์</label>
                        <select name="file" class="form-select form-select-sm">
                            @forelse ($files as $file)
                                <option value="{{ $file['name'] }}" @selected($filters['file'] === $file['name'])>
                                    {{ str_starts_with($file['name'], 'client-errors') ? '[JS] ' : '' }}{{ $file['name'] }}
                                    ({{ $fmtSize($file['size']) }}, {{ date('d/m/Y H:i', $file['modified']) }})
                                </option>
                            @empty
                                <option value="">ไม่พบไฟล์ log</option>
                            @endforelse
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label mb-1">ระดับ</label>
                        <select name="level" class="form-select form-select-sm">
                            @foreach ($levelLabel as $value => $label)
                                <option value="{{ $value }}" @selected($filters['level'] === $value)>{{ $label }}</option>
                            @endforeach
                            <option value="all" @selected($filters['level'] === 'all')>ทั้งหมด</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label mb-1">ค้นหา</label>
                        <input type="text" name="q" value="{{ $filters['q'] }}" class="form-control form-control-sm"
                            placeholder="ข้อความ / route / ชื่อผู้ใช้ / ชื่อไฟล์ (เช่น FormVC, SQLSTATE)">
                    </div>

                    <div class="col-md-2 d-flex gap-1">
                        <button class="btn btn-sm btn-primary flex-fill">ค้นหา</button>
                        <a href="{{ route('adminweb.error-logs.index') }}" class="btn btn-sm btn-outline-secondary flex-fill">ล้าง</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-3 mb-3">
            @foreach ($levelLabel as $group => $label)
                <div class="col-6 col-md-3">
                    <a href="{{ route('adminweb.error-logs.index', ['file' => $filters['file'], 'level' => $group]) }}"
                        class="card shadow-sm text-decoration-none h-100 {{ $filters['level'] === $group ? 'border-' . $levelBadge[$group] . ' border-2' : '' }}">
                        <div class="card-body py-3">
                            <div class="text-muted small">{{ $label }}</div>
                            <div class="errlog-count text-{{ $levelBadge[$group] }}">{{ number_format($counts[$group] ?? 0) }}</div>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>

        <div class="card shadow-sm">
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <span class="fw-bold">รายการ (ใหม่สุดก่อน)</span>
                <span class="text-muted small">
                    แสดงไม่เกิน 300 รายการ
                    @if ($truncated)
                        · ไฟล์ใหญ่: อ่านเฉพาะช่วงท้าย {{ $fmtSize($readBytes) }} ล่าสุด
                    @endif
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0 align-top">
                        <thead class="table-light">
                            <tr>
                                <th class="errlog-col-wide" style="white-space:nowrap; width: 150px;">เวลา</th>
                                <th class="errlog-col-wide" style="width: 90px;">ระดับ</th>
                                <th>ข้อความ</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($entries as $i => $entry)
                                <tr>
                                    <td class="errlog-col-wide" style="white-space:nowrap;"><small>{{ $entry['time'] }}</small></td>
                                    <td class="errlog-col-wide">
                                        <span class="badge bg-{{ $levelBadge[$entry['group']] ?? 'secondary' }}">{{ $entry['level'] }}</span>
                                    </td>
                                    <td class="errlog-msg">
                                        {{-- จอเล็ก: แสดงเวลา + ระดับในช่องเดียวกับข้อความ --}}
                                        <div class="errlog-inline-meta small text-muted mb-1">
                                            <span class="badge bg-{{ $levelBadge[$entry['group']] ?? 'secondary' }}">{{ $entry['level'] }}</span>
                                            {{ $entry['time'] }}
                                        </div>
                                        <div class="fw-semibold">{{ $entry['message'] }}</div>

                                        @if (! empty($entry['context']))
                                            <div class="mt-1 d-flex flex-wrap gap-1">
                                                @foreach ($entry['context'] as $key => $value)
                                                    <span class="badge bg-light text-dark border fw-normal">
                                                        <span class="text-muted">{{ $key }}:</span> {{ $value }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif

                                        @if ($entry['exception'] || $entry['detail'] !== '')
                                            <details class="errlog-detail mt-1">
                                                <summary class="small text-primary" style="cursor:pointer;">
                                                    {{ $isClientFile ? 'รายละเอียด' : 'Exception / Stack trace' }}
                                                </summary>
                                                <pre>{{ trim(($entry['exception'] ?? '') . "\n" . $entry['detail']) }}</pre>
                                            </details>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center text-muted py-4">
                                        ไม่พบรายการตามเงื่อนไข
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
