@extends('layouts.layout')

@section('title', 'งานเสี่ยงการผลิต')
@section('page-title', 'งานเสี่ยงการผลิต')

@section('content')
    @php
        $fmtDate = fn($v) => $v ? \Carbon\Carbon::parse($v)->format('d/m/Y') : '-';
    @endphp

    <style>
        .risk-table-wrap {
            max-height: calc(100vh - 300px);
            overflow: auto;
            position: relative;
        }

        .risk-table thead th {
            position: sticky;
            top: 0;
            z-index: 20;
            background: #f8f9fa;
            text-align: center;
            vertical-align: middle;
            white-space: nowrap;
        }

        .risk-table .sticky-col {
            position: sticky;
            left: 0;
            z-index: 15;
            background: #fff;
            white-space: nowrap;
        }

        .risk-table thead .sticky-col {
            z-index: 30;
            background: #f8f9fa;
        }

        .metric-card .value {
            font-size: 1.6rem;
            font-weight: 700;
        }

        .days-overdue {
            background: #7f0000 !important;
            color: #fff !important;
            font-weight: 700;
        }

        .days-critical {
            background: #dc3545 !important;
            color: #fff !important;
            font-weight: 700;
        }

        .days-warning {
            background: #fff3cd !important;
            color: #7a5a00 !important;
            font-weight: 700;
        }

        .filter-card {
            border-radius: 18px;
            padding: 1.25rem;
            border: 1px solid #e5e7eb;
            box-shadow: 0 4px 14px rgba(15, 23, 42, .04);
        }

        .filter-card .form-label {
            font-weight: 600;
            margin-bottom: .4rem;
            color: #374151;
        }

        .filter-card .form-control,
        .filter-card .form-select {
            height: 46px;
            border-radius: 10px;
        }

        .filter-actions-row {
            margin-top: 4px;
        }

        .summary-card {
            border-radius: 14px;
        }

        .summary-card .card-body {
            padding: 1.25rem 1rem;
        }

        .summary-card .value {
            font-size: 1.75rem;
            font-weight: 700;
            line-height: 1;
        }

        .filter-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .risk-btn {
            height: 44px;
            min-width: 168px;
            padding: 0 18px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-weight: 600;
            font-size: .96rem;
            text-decoration: none;
            border: 1px solid #d0d5dd;
            background: #fff;
            color: #1f2937;
            box-shadow: 0 1px 2px rgba(16, 24, 40, .04);
            transition: all .18s ease;
        }

        .risk-btn i {
            font-size: .92rem;
        }

        .risk-btn:hover,
        .risk-btn:focus {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(16, 24, 40, .08);
            text-decoration: none;
        }

        .risk-btn-primary {
            background: #1f6feb;
            border-color: #1f6feb;
            color: #fff;
        }

        .risk-btn-primary:hover,
        .risk-btn-primary:focus {
            background: #1859bd;
            border-color: #1859bd;
            color: #fff;
        }

        .risk-btn-light {
            background: #fff;
            border-color: #d0d5dd;
            color: #344054;
        }

        .risk-btn-light:hover,
        .risk-btn-light:focus {
            background: #f9fafb;
            border-color: #bfc6d4;
            color: #1f2937;
        }

        .risk-btn-dark {
            background: #fff;
            border-color: #98a2b3;
            color: #111827;
        }

        .risk-btn-dark:hover,
        .risk-btn-dark:focus {
            background: #f3f4f6;
            border-color: #6b7280;
            color: #111827;
        }

        .risk-btn-success {
            background: #fff;
            border-color: #16a34a;
            color: #166534;
        }

        .risk-btn-success:hover,
        .risk-btn-success:focus {
            background: #16a34a;
            border-color: #16a34a;
            color: #fff;
        }

        @media (max-width: 991.98px) {
            .filter-actions {
                display: grid;
                grid-template-columns: 1fr;
            }

            .risk-btn {
                width: 100%;
                min-width: 0;
            }
        }
    </style>

    <div class="container-fluid py-3">
        <form class="card card-body mb-3 filter-card" method="GET" action="{{ route('risk.index') }}">
            <div class="row g-3">
                <div class="col-md-6 col-xl-2">
                    <label class="form-label">เริ่มดูข้อมูลตั้งแต่</label>
                    <input type="date" class="form-control" name="since" value="{{ $filters['since'] }}">
                </div>

                <div class="col-md-6 col-xl-2">
                    <label class="form-label">กำหนดส่งภายใน (วัน)</label>
                    <input type="number" class="form-control" name="dueWithin" value="{{ $filters['dueWithin'] }}">
                </div>

                <div class="col-md-6 col-xl-2">
                    <label class="form-label">โรงงาน</label>
                    <select class="form-select" name="site">
                        <option value="">ทั้งหมด</option>
                        <option value="Wire" @selected($filters['site'] === 'Wire')>Wire</option>
                        <option value="Plus" @selected($filters['site'] === 'Plus')>Plus</option>
                    </select>
                </div>

                <div class="col-md-6 col-xl-2">
                    <label class="form-label">Planner</label>
                    <select class="form-select" name="planner">
                        <option value="">ทั้งหมด</option>
                        @foreach ($planners as $planner)
                            <option value="{{ $planner }}" @selected($filters['planner'] === $planner)>{{ $planner }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-6 col-xl-2">
                    <label class="form-label">สถานีงาน</label>
                    <select class="form-select" name="station">
                        <option value="">ทั้งหมด</option>
                        @foreach ($stations as $station)
                            <option value="{{ $station }}" @selected($filters['station'] === $station)>{{ $station }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-6 col-xl-2">
                    <label class="form-label">สถานะ</label>
                    <select class="form-select" name="status">
                        <option value="">ทั้งหมด</option>
                        <option value="แจ้งเตือน" @selected($filters['status'] === 'แจ้งเตือน')>แจ้งเตือน</option>
                        <option value="วิกฤต" @selected($filters['status'] === 'วิกฤต')>วิกฤต</option>
                        <option value="เลยกำหนด" @selected($filters['status'] === 'เลยกำหนด')>เลยกำหนด</option>
                    </select>
                </div>

                <div class="col-lg-7">
                    <label class="form-label">คำค้นหา</label>
                    <input type="text" class="form-control" name="keyword" value="{{ $filters['keyword'] }}"
                        placeholder="MFG / Part / Customer">
                </div>

                <div class="col-md-4 col-lg-2">
                    <label class="form-label">จำนวนแถวต่อหน้า</label>
                    <select class="form-select" name="per_page">
                        @foreach ([50, 100, 150, 200] as $size)
                            <option value="{{ $size }}" @selected($perPage == $size)>{{ $size }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 filter-actions-row">
                    <div class="filter-actions">
                        <button class="btn risk-btn risk-btn-primary" type="submit">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <span>ค้นหา</span>
                        </button>

                        <a href="{{ route('risk.index') }}" class="btn risk-btn risk-btn-light">
                            <i class="fa-solid fa-rotate-left"></i>
                            <span>ล้างตัวกรอง</span>
                        </a>

                        <a href="{{ route('risk.dashboard', request()->except('page')) }}"
                            class="btn risk-btn risk-btn-dark">
                            <i class="fa-solid fa-chart-line"></i>
                            <span>ไปหน้า Dashboard</span>
                        </a>

                        <a href="{{ route('risk.exportExcel', request()->except('page')) }}"
                            class="btn risk-btn risk-btn-success">
                            <i class="fa-solid fa-file-excel"></i>
                            <span>Export Excel</span>
                        </a>
                    </div>
                </div>
            </div>
        </form>

        <div class="row g-3 mb-3">
            <div class="col-md-3 col-lg-2">
                <div class="card metric-card">
                    <div class="card-body text-center">
                        <div class="text-muted">ทั้งหมด</div>
                        <div class="value">{{ $summary['total'] }}</div>
                    </div>
                </div>
            </div>

            <div class="col-md-3 col-lg-2">
                <div class="card metric-card">
                    <div class="card-body text-center">
                        <div class="text-muted">แจ้งเตือน</div>
                        <div class="value text-warning">{{ $summary['warning'] }}</div>
                    </div>
                </div>
            </div>

            <div class="col-md-3 col-lg-2">
                <div class="card metric-card">
                    <div class="card-body text-center">
                        <div class="text-muted">วิกฤต</div>
                        <div class="value text-danger">{{ $summary['critical'] }}</div>
                    </div>
                </div>
            </div>

            <div class="col-md-3 col-lg-2">
                <div class="card metric-card">
                    <div class="card-body text-center">
                        <div class="text-muted">เลยกำหนด</div>
                        <div class="value text-danger-emphasis">{{ $summary['overdue'] }}</div>
                    </div>
                </div>
            </div>

            <div class="col-md-3 col-lg-2">
                <div class="card metric-card">
                    <div class="card-body text-center">
                        <div class="text-muted">ยังไม่แมพ Planner</div>
                        <div class="value">{{ $summary['unmapped'] }}</div>
                    </div>
                </div>
            </div>

            <div class="col-md-3 col-lg-2">
                <div class="card metric-card">
                    <div class="card-body text-center">
                        <div class="text-muted">แสดงผล</div>
                        <div class="value">{{ $rows->count() }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="text-muted small">
                ทั้งหมด {{ number_format($allRowsCount) }} รายการ
            </div>
            <div>
                {{ $rows->links() }}
            </div>
        </div>

        <div class="card">
            <div class="risk-table-wrap">
                <table class="table table-sm table-bordered align-middle mb-0 risk-table">
                    <thead>
                        <tr>
                            <th class="sticky-col">เลขที่ MFG</th>
                            <th>โรงงาน</th>
                            <th>กำหนดส่ง</th>
                            <th>คงเหลือ (วัน)</th>
                            <th>สถานะ</th>
                            <th>สถานีที่ใช้แจ้งเตือน</th>
                            <th>Planner</th>
                            <th>ความคืบหน้าขั้นตอน</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $r)
                            @php
                                $daysClass = match ($r->risk_label) {
                                    'เลยกำหนด' => 'days-overdue',
                                    'วิกฤต' => 'days-critical',
                                    'แจ้งเตือน' => 'days-warning',
                                    default => '',
                                };
                            @endphp
                            <tr>
                                <td class="sticky-col fw-semibold">{{ $r->workordernumber }}</td>
                                <td class="text-center">{{ $r->site }}</td>
                                <td class="text-center">{{ $fmtDate($r->duedate) }}</td>
                                <td class="text-center {{ $daysClass }}">{{ $r->days_to_due }}</td>
                                <td class="text-center">
                                    @if ($r->risk_label === 'เลยกำหนด')
                                        <span class="badge text-bg-danger">{{ $r->risk_label }}</span>
                                    @elseif($r->risk_label === 'วิกฤต')
                                        <span class="badge text-bg-danger">{{ $r->risk_label }}</span>
                                    @else
                                        <span class="badge text-bg-warning">{{ $r->risk_label }}</span>
                                    @endif
                                </td>
                                <td class="text-center fw-semibold">{{ $r->notify_work_center_code ?: '-' }}</td>
                                <td class="text-center">{{ $r->planner_name ?: '-' }}</td>
                                <td class="text-center">{{ $r->step_progress_display }}</td>
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

        <div class="mt-2 text-muted small">
            * ถ้าขึ้น <strong>0/x*</strong> หมายถึงงานนี้ยังไม่เริ่มผลิต และ x คือจำนวนขั้นตอนทั้งหมด
        </div>

        <div class="mt-1 text-muted small">
            * <strong>สถานีที่ใช้แจ้งเตือน</strong> คือสถานีงานที่ระบบใช้เป็นตัวอ้างอิงในการแจ้งเตือนและระบุผู้รับผิดชอบ
        </div>

        <div class="mt-3">
            {{ $rows->links() }}
        </div>
    </div>
@endsection
