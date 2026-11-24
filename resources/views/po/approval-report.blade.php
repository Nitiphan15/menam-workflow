@extends('layouts.layout')

@section('title', 'รายงาน/แดชบอร์ดอนุมัติ PO')
@section('page-title', 'รายงาน/แดชบอร์ดอนุมัติ PO')

@php
    use App\Services\Po\PoApprovalReportService as Report;
    $s = $report['summary'];
    $fmt = fn ($h) => Report::formatHours($h);
    $authUser = auth()->user();
    $detailRoute = (($authUser->is_superadmin ?? false) || $authUser->hasRoleCode(['PO', 'POPUR'])) ? 'po.show' : 'po.departmentTracking.show';
    $exportQuery = array_filter($filters, fn ($v) => $v !== null && $v !== '');
    $statusBadge = [
        'approved' => 'success',
        'pending' => 'warning',
        'rejected' => 'danger',
        'cancelled' => 'secondary',
        'other' => 'light',
    ];
@endphp

@push('styles')
    <style>
        .por-card { border: 0; border-radius: .75rem; }
        .por-card .por-label { color: #6b7280; font-size: .9rem; }
        .por-card .por-value { font-size: 2rem; font-weight: 700; line-height: 1.1; }
        .por-card .por-sub { color: #6b7280; font-size: .8rem; }
        .por-chart { position: relative; height: 280px; }
        .por-table td, .por-table th { font-size: .85rem; vertical-align: top; }
        .por-table .pre { white-space: pre-line; }
        .por-table-wrap { max-height: 70vh; overflow: auto; }
        .por-table-wrap thead th { position: sticky; top: 0; background: #f8fafc; z-index: 1; }
    </style>
@endpush

@section('content')
    <div class="container-fluid py-3 px-0">
        {{-- Filters --}}
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <h4 class="mb-1">รายงาน/แดชบอร์ดการอนุมัติ PO Online</h4>
                        <div class="text-muted small">
                            {{ $scopeLabel }} · นับเวลาจาก Submit (รอบล่าสุด) ถึงอนุมัติครบ เป็นชั่วโมงตามปฏิทิน ·
                            ข้อมูล ณ {{ $report['generated_at'] }} (cache 10 นาที)
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="{{ route('po.approvalReport.excel', $exportQuery) }}" class="btn btn-success btn-sm">
                            <i class="fa-solid fa-file-excel me-1"></i> Excel
                        </a>
                        <a href="{{ route('po.approvalReport.pdf', $exportQuery) }}" class="btn btn-danger btn-sm">
                            <i class="fa-solid fa-file-pdf me-1"></i> PDF
                        </a>
                    </div>
                </div>

                <form method="GET" action="{{ route('po.approvalReport.index') }}" class="row g-2 align-items-end" id="po-report-filter">
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1">Submit ตั้งแต่</label>
                        <input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1">ถึง</label>
                        <input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1">Site</label>
                        <select name="site" class="form-select form-select-sm">
                            <option value="">ทั้งหมด</option>
                            <option value="wire" @selected($filters['site'] === 'wire')>Wire</option>
                            <option value="plus" @selected($filters['site'] === 'plus')>Plus</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1">แผนก</label>
                        <select name="department" class="form-select form-select-sm">
                            <option value="">ทั้งหมด</option>
                            @foreach ($report['department_options'] as $department)
                                <option value="{{ $department }}" @selected($filters['department'] === $department)>{{ $department }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1">สถานะ</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">ทั้งหมด</option>
                            @foreach ($statusLabels as $key => $label)
                                @continue($key === 'other')
                                <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2 d-flex gap-2">
                        <button class="btn btn-primary btn-sm flex-fill"><i class="fa-solid fa-filter me-1"></i> แสดง</button>
                        <a href="{{ route('po.approvalReport.index') }}" class="btn btn-outline-secondary btn-sm">ล้าง</a>
                    </div>
                </form>
            </div>
        </div>

        {{-- Summary cards --}}
        <div class="row g-3 mb-3">
            <div class="col-6 col-lg-2">
                <div class="card por-card shadow-sm h-100"><div class="card-body">
                    <div class="por-label">PO ทั้งหมด</div>
                    <div class="por-value">{{ number_format($s['total']) }}</div>
                    <div class="por-sub">ยกเลิก {{ number_format($s['cancelled']) }}</div>
                </div></div>
            </div>
            <div class="col-6 col-lg-2">
                <div class="card por-card shadow-sm h-100"><div class="card-body">
                    <div class="por-label">อนุมัติครบ</div>
                    <div class="por-value text-success">{{ number_format($s['approved']) }}</div>
                    <div class="por-sub">ส่งกลับแก้ไข {{ number_format($s['rejected']) }}</div>
                </div></div>
            </div>
            <div class="col-6 col-lg-2">
                <div class="card por-card shadow-sm h-100"><div class="card-body">
                    <div class="por-label">รออนุมัติ</div>
                    <div class="por-value text-warning">{{ number_format($s['pending']) }}</div>
                    <div class="por-sub">ดูรายการค้างด้านล่าง</div>
                </div></div>
            </div>
            <div class="col-6 col-lg-2">
                <div class="card por-card shadow-sm h-100"><div class="card-body">
                    <div class="por-label">เวลาอนุมัติเฉลี่ย</div>
                    <div class="por-value">{{ $fmt($s['avg_hours']) }}</div>
                    <div class="por-sub">มัธยฐาน {{ $fmt($s['median_hours']) }} · จาก {{ number_format($s['measured']) }} ใบ</div>
                </div></div>
            </div>
            <div class="col-6 col-lg-2">
                <div class="card por-card shadow-sm h-100"><div class="card-body">
                    <div class="por-label">เฉลี่ยแยกขั้น</div>
                    <div class="fs-6 fw-semibold mt-1">Purchase: {{ $fmt($s['avg_purchase_hours']) }}</div>
                    <div class="fs-6 fw-semibold">หัวหน้าแผนก: {{ $fmt($s['avg_department_hours']) }}</div>
                </div></div>
            </div>
            <div class="col-6 col-lg-2">
                <div class="card por-card shadow-sm h-100"><div class="card-body">
                    <div class="por-label">ทัน SLA ({{ $slaHours }} ชม./ขั้น)</div>
                    <div class="por-value {{ ($s['sla_rate'] ?? 0) >= 80 ? 'text-success' : 'text-danger' }}">
                        {{ $s['sla_rate'] === null ? '-' : $s['sla_rate'] . '%' }}
                    </div>
                    <div class="por-sub">จาก {{ number_format($s['sla_base']) }} ใบที่อนุมัติครบ</div>
                </div></div>
            </div>
        </div>

        {{-- Charts --}}
        <div class="row g-3 mb-3">
            <div class="col-lg-6">
                <div class="card shadow-sm border-0 h-100"><div class="card-body">
                    <h6 class="mb-2">จำนวน PO ต่อเดือน (ตามวันที่ Submit)</h6>
                    <div class="por-chart"><canvas id="por-monthly-count"></canvas></div>
                </div></div>
            </div>
            <div class="col-lg-6">
                <div class="card shadow-sm border-0 h-100"><div class="card-body">
                    <h6 class="mb-2">เวลาอนุมัติเฉลี่ยต่อเดือน (ชม.)</h6>
                    <div class="por-chart"><canvas id="por-monthly-hours"></canvas></div>
                </div></div>
            </div>
            <div class="col-12">
                <div class="card shadow-sm border-0"><div class="card-body">
                    <h6 class="mb-2">เวลาเฉลี่ยแยกตามแผนก (ชม.) — Purchase + หัวหน้าแผนก</h6>
                    <div class="por-chart" style="height: {{ max(220, count($report['departments']) * 30) }}px">
                        <canvas id="por-department-hours"></canvas>
                    </div>
                </div></div>
            </div>
        </div>

        {{-- Pending longest --}}
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <h6 class="mb-2">PO ที่รออนุมัตินานที่สุด (สูงสุด {{ Report::PENDING_LIMIT }} ใบ)</h6>
                @if (empty($report['pending']))
                    <div class="text-muted">ไม่มี PO ค้างอนุมัติในช่วงนี้</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm table-hover por-table mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>PO No</th><th>Site</th><th>แผนก</th><th>รอขั้น</th>
                                    <th>Submit</th><th class="text-end">รอมาแล้ว</th><th>รอใคร</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($report['pending'] as $row)
                                    <tr>
                                        <td><a href="{{ route($detailRoute, $row['po_id']) }}">{{ $row['ordnumber'] }}</a></td>
                                        <td>{{ $row['site'] }}</td>
                                        <td>{{ $row['department'] }}</td>
                                        <td>{{ $row['current_step_label'] }}</td>
                                        <td>{{ $row['submitted_at'] }}</td>
                                        <td class="text-end fw-semibold {{ ($row['waiting_hours'] ?? 0) > $slaHours ? 'text-danger' : '' }}">
                                            {{ $fmt($row['waiting_hours']) }}
                                        </td>
                                        <td>{{ $row['waiting_for'] ?: '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- Detail report --}}
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h6 class="mb-2">รายงานการอนุมัติ ({{ number_format(count($report['rows'])) }} ใบ)</h6>
                <div class="table-responsive por-table-wrap">
                    <table class="table table-sm table-bordered table-hover por-table mb-0">
                        <thead>
                            <tr>
                                <th>PO No</th><th>Site</th><th>แผนก</th><th>สถานะ</th><th>Submit</th>
                                <th>Purchase อนุมัติ</th><th class="text-end">ชม.</th>
                                <th>หัวหน้าแผนก อนุมัติ</th><th class="text-end">ชม.</th>
                                <th>อนุมัติครบ</th><th class="text-end">รวม</th><th class="text-center">ส่งกลับ</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($report['rows'] as $row)
                                <tr>
                                    <td class="text-nowrap"><a href="{{ route($detailRoute, $row['po_id']) }}">{{ $row['ordnumber'] }}</a></td>
                                    <td>{{ $row['site'] }}</td>
                                    <td>{{ $row['department'] }}</td>
                                    <td>
                                        <span class="badge text-bg-{{ $statusBadge[$row['status_key']] ?? 'light' }}">{{ $row['status_label'] }}</span>
                                        @if ($row['current_step_label'])
                                            <div class="small text-muted">{{ $row['current_step_label'] }}</div>
                                        @endif
                                    </td>
                                    <td class="text-nowrap">{{ $row['submitted_at'] }}</td>
                                    <td class="pre">{{ $row['purchase_approvers'] ?: '-' }}</td>
                                    <td class="text-end">{{ $row['purchase_hours'] ?? '-' }}</td>
                                    <td class="pre">{{ $row['department_approvers'] ?: '-' }}</td>
                                    <td class="text-end">{{ $row['department_hours'] ?? '-' }}</td>
                                    <td class="text-nowrap">{{ $row['completed_at'] ?: '-' }}</td>
                                    <td class="text-end text-nowrap {{ $row['within_sla'] === false ? 'text-danger fw-semibold' : '' }}">{{ $fmt($row['total_hours']) }}</td>
                                    <td class="text-center">{{ $row['reject_count'] ?: '' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="12" class="text-center text-muted py-4">ไม่พบ PO ในช่วงที่เลือก</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            if (typeof Chart === 'undefined') return;
            const monthly = @json($report['monthly']);
            const departments = @json($report['departments']);
            const labels = monthly.map(m => m.label);
            const bar = (label, key, color) => ({ label, data: monthly.map(m => m[key]), backgroundColor: color, stack: 's' });

            new Chart(document.getElementById('por-monthly-count'), {
                type: 'bar',
                data: {
                    labels,
                    datasets: [
                        bar('อนุมัติครบ', 'approved', '#16a34a'),
                        bar('รออนุมัติ', 'pending', '#f59e0b'),
                        bar('ส่งกลับแก้ไข', 'rejected', '#dc2626'),
                        bar('ยกเลิก', 'cancelled', '#9ca3af'),
                    ],
                },
                options: { maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } } },
            });

            new Chart(document.getElementById('por-monthly-hours'), {
                type: 'line',
                data: {
                    labels,
                    datasets: [{ label: 'เฉลี่ย (ชม.)', data: monthly.map(m => m.avg_hours), borderColor: '#2563eb', backgroundColor: '#2563eb', tension: .25, spanGaps: true }],
                },
                options: { maintainAspectRatio: false, scales: { y: { beginAtZero: true } } },
            });

            new Chart(document.getElementById('por-department-hours'), {
                type: 'bar',
                data: {
                    labels: departments.map(d => `${d.department} (${d.total})`),
                    datasets: [
                        { label: 'Purchase', data: departments.map(d => d.avg_purchase_hours), backgroundColor: '#60a5fa', stack: 'h' },
                        { label: 'หัวหน้าแผนก', data: departments.map(d => d.avg_department_hours), backgroundColor: '#a78bfa', stack: 'h' },
                    ],
                },
                options: { indexAxis: 'y', maintainAspectRatio: false, scales: { x: { stacked: true, beginAtZero: true }, y: { stacked: true } } },
            });
        })();
    </script>
@endpush
