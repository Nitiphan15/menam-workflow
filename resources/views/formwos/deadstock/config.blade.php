@extends('layouts.layout')

@section('title', 'Deadstock Config')
@section('page-title', 'Deadstock Config')

@push('styles')
    <style>
        .ds-config-shell {
            display: grid;
            gap: 14px;
        }

        .ds-config-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
        }

        .ds-config-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        }

        .ds-config-kpi {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
        }

        .ds-config-label {
            color: #64748b;
            font-size: .76rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .ds-config-value {
            color: #0f172a;
            font-size: 1.15rem;
            font-weight: 700;
            margin-top: 6px;
        }

        .ds-baseline-form {
            align-items: end;
            display: grid;
            gap: 12px;
            grid-template-columns: minmax(180px, 240px) auto;
            max-width: 440px;
        }

        @media (max-width: 575.98px) {
            .ds-baseline-form {
                grid-template-columns: 1fr;
                max-width: none;
            }
        }
    </style>
@endpush

@section('content')
    <div class="ds-config-shell">
        @if (session('success'))
            <div class="alert alert-success mb-0">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger mb-0">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger mb-0">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <section class="ds-config-card">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                <div>
                    <h5 class="mb-1">Snapshot Tools</h5>
                    <div class="text-muted small">
                        สำหรับ admin ใช้สร้าง/import snapshot เข้า Deadstock Monthly Review
                    </div>
                </div>
                <a class="btn btn-outline-secondary" href="{{ route('deadstock.review') }}">
                    <i class="fa fa-clipboard-check me-1"></i> กลับไป Monthly Review
                </a>
            </div>

            <div class="ds-config-grid mt-3">
                <div class="ds-config-kpi">
                    <div class="ds-config-label">Item snapshots</div>
                    <div class="ds-config-value">{{ number_format((int) ($itemSnapshotCount ?? 0)) }}</div>
                    <div class="text-muted small">ล่าสุด {{ $latestItemSnapshotDate ?: '-' }}</div>
                </div>
                <div class="ds-config-kpi">
                    <div class="ds-config-label">Dashboard snapshots</div>
                    <div class="ds-config-value">{{ number_format((int) ($rawSnapshotCount ?? 0)) }}</div>
                    <div class="text-muted small">ล่าสุด {{ $latestRawSnapshotDate ?: '-' }}</div>
                </div>
                <div class="ds-config-kpi">
                    <div class="ds-config-label">Excel backfill files</div>
                    <div class="ds-config-value">{{ number_format((int) ($excelBackfillCount ?? 0)) }}</div>
                    <div class="text-muted small">ใช้ command backfill จาก mail-daily public reports</div>
                </div>
            </div>
        </section>

        <section class="ds-config-card">
            <h5 class="mb-2">สร้าง baseline snapshot</h5>
            <p class="text-muted mb-3">
                รัน <code>report:deadstock --snapshot-only</code> ที่ <code>mail-daily</code> เพื่อสร้าง
                <code>deadstock_items_YYYY-MM-DD.json</code> แล้ว import เข้า Monthly Review โดยไม่ส่งเมล
            </p>
            <form class="ds-baseline-form" method="post" action="{{ route('adminweb.deadstock.create_baseline') }}">
                @csrf
                <div>
                    <label class="form-label">วันที่รายงาน</label>
                    <input
                        class="form-control js-deadstock-date"
                        type="text"
                        name="date"
                        value="{{ old('date', now('Asia/Bangkok')->toDateString()) }}"
                        autocomplete="off"
                    >
                </div>
                <button class="btn btn-warning" type="submit">
                    <i class="fa fa-camera me-1"></i> สร้าง baseline
                </button>
            </form>
        </section>

        <section class="ds-config-card">
            <h5 class="mb-2">Import snapshot ล่าสุด</h5>
            <p class="text-muted mb-3">
                ใช้เมื่อมีไฟล์ <code>deadstock_items_*.json</code> อยู่แล้วและต้องการ import ซ้ำแบบ idempotent
            </p>
            <form method="post" action="{{ route('adminweb.deadstock.import_latest') }}">
                @csrf
                <button class="btn btn-outline-primary" type="submit">
                    <i class="fa fa-file-import me-1"></i> นำเข้าล่าสุด
                </button>
            </form>
        </section>

        <section class="ds-config-card">
            <h5 class="mb-2">Paths</h5>
            <div class="small">
                <div><strong>mail-daily:</strong> <code>{{ $mailDailyPath }}</code></div>
                <div><strong>snapshots:</strong> <code>{{ $sourcePath }}</code></div>
            </div>
        </section>

        @if (session('deadstock_output'))
            <details class="ds-config-card">
                <summary class="fw-semibold">ผลลัพธ์จาก mail-daily</summary>
                <pre class="bg-dark text-light p-3 rounded small mt-3 mb-0" style="max-height: 360px; overflow:auto;">{{ session('deadstock_output') }}</pre>
            </details>
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof flatpickr === 'undefined') {
                return;
            }

            flatpickr('.js-deadstock-date', {
                dateFormat: 'Y-m-d',
                altInput: true,
                altFormat: 'd M Y',
                allowInput: true,
                disableMobile: true
            });
        });
    </script>
@endpush
