@extends('layouts.layout')
@section('title', $viewMode === 'sales' ? 'Sales Forecast - Read Only' : 'Division Forecast - Planning View')
@section('page-title', $viewMode === 'sales' ? 'Sales Forecast - Read Only' : 'Division Forecast - Planning View')

@section('content')
    @php
        $statusStyles = [
            'APPROVED' => ['อนุมัติแล้ว', 'success'],
            'PENDING_APPROVAL' => ['รออนุมัติ', 'warning'],
            'SUBMITTED' => ['ส่งแล้ว', 'info'],
            'REJECTED' => ['ตีกลับ', 'danger'],
        ];
    @endphp

    <div class="container-fluid">
        <style>
            .planning-card {
                border: 0;
                border-radius: 14px;
                box-shadow: 0 6px 18px rgba(0, 0, 0, .06);
            }

            .division-picker {
                display: flex;
                flex-wrap: wrap;
                gap: .55rem;
            }

            .division-option {
                display: inline-flex;
                align-items: center;
                gap: .4rem;
                padding: .45rem .7rem;
                border: 1px solid #dfe3e8;
                border-radius: 9px;
                background: #fff;
                cursor: pointer;
            }

            .planning-table-wrap {
                max-height: calc(100vh - 370px);
                overflow: auto;
                border: 1px solid #e7eaee;
                border-radius: 12px;
            }

            .planning-table {
                min-width: 1700px;
                margin: 0;
                font-size: 13px;
            }

            .planning-table th,
            .planning-table td {
                padding: .45rem .55rem;
                white-space: nowrap;
                vertical-align: middle;
            }

            .planning-table thead th {
                position: sticky;
                top: 0;
                z-index: 3;
                background: #f7f8fa;
                text-align: center;
            }

            .planning-table .num {
                text-align: right;
                font-variant-numeric: tabular-nums;
            }

            .kpi-label {
                color: #6c757d;
                font-size: 12px;
            }

            .kpi-value {
                font-size: 22px;
                font-weight: 700;
            }
        </style>

        <div class="alert alert-info py-2">
            มุมมองสำหรับแผนกวางแผนแบบอ่านอย่างเดียว แสดง Forecast เดือน
            <b>{{ \Carbon\Carbon::parse($forecastBaseMonth)->format('m/Y') }}</b>
            และเลือกดูหลาย Division พร้อมกันได้
            <span class="float-end d-flex gap-2">
                <a href="{{ route('fc.division', array_merge(request()->query(), ['view' => null, 'page' => null])) }}"
                    class="btn btn-sm {{ $viewMode === 'planner' ? 'btn-primary' : 'btn-outline-primary' }}">Planner View</a>
                <a href="{{ route('fc.division', array_merge(request()->query(), ['view' => 'sales', 'page' => null])) }}"
                    class="btn btn-sm {{ $viewMode === 'sales' ? 'btn-primary' : 'btn-outline-primary' }}">Sales Forecast View</a>
            </span>
        </div>

        <div class="card planning-card mb-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">ตัวกรอง Division</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="toggleAllDivisions">เลือก/ยกเลิกทั้งหมด</button>
            </div>
            <div class="card-body">
                <form method="get" action="{{ route('fc.division') }}">
                    @if ($viewMode === 'sales')
                        <input type="hidden" name="view" value="sales">
                    @endif
                    <div class="division-picker mb-3">
                        @foreach ($allDivisions as $division)
                            <label class="division-option">
                                <input type="checkbox" name="divisions[]" value="{{ $division }}"
                                    @checked(in_array($division, $selectedDivisions, true))>
                                <span class="fw-semibold">{{ $divisionLabels[$division] ?? $division }}</span>
                                @php
                                    $status = strtoupper((string) ($submissionStatuses->get($division) ?? ''));
                                    [$statusLabel, $statusColor] = $statusStyles[$status] ?? ['ยังไม่ส่ง', 'secondary'];
                                @endphp
                                <span class="badge bg-{{ $statusColor }}">{{ $statusLabel }}</span>
                            </label>
                        @endforeach
                    </div>

                    <div class="row g-2 align-items-end">
                        <div class="col-lg-2">
                            <label class="form-label">เดือน Forecast</label>
                            <input type="month" name="month" value="{{ \Carbon\Carbon::parse($forecastBaseMonth)->format('Y-m') }}"
                                class="form-control form-control-sm">
                        </div>
                        <div class="col-lg-6">
                            <label class="form-label">ค้นหา</label>
                            <input type="text" name="q" value="{{ $search }}" class="form-control form-control-sm"
                                placeholder="Division, ลูกค้า, FG, RM, Supplier หรือหมายเหตุ">
                        </div>
                        <div class="col-lg-4 d-flex gap-2">
                            <button type="submit" class="btn btn-sm btn-primary flex-fill">แสดงข้อมูล</button>
                            <a href="{{ route('fc.division', $viewMode === 'sales' ? ['view' => 'sales'] : []) }}"
                                class="btn btn-sm btn-outline-secondary flex-fill">ล้างตัวกรอง</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-md-4">
                <div class="card planning-card h-100">
                    <div class="card-body py-2">
                        <div class="kpi-label">จำนวนรายการ</div>
                        <div class="kpi-value">{{ number_format($kpi['items']) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card planning-card h-100">
                    <div class="card-body py-2">
                        <div class="kpi-label">Forecast เดือนแรก รวม</div>
                        <div class="kpi-value">{{ number_format($kpi['forecast_1m_sum'], 2) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card planning-card h-100">
                    <div class="card-body py-2">
                        <div class="kpi-label">Approved Forecast เดือนแรก รวม</div>
                        <div class="kpi-value">{{ number_format($kpi['approved_1m_sum'], 2) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card planning-card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">{{ $viewMode === 'sales' ? 'Sales Forecast ราย Division / Customer / FG' : 'Forecast ราย Division / Customer / FG' }}</span>
                <span class="text-muted small">แสดงหน้าละ 100 รายการ</span>
            </div>
            <div class="card-body p-0">
                <div class="planning-table-wrap">
                    <table class="table table-bordered table-hover planning-table">
                        <thead>
                            <tr>
                                <th>Division</th>
                                <th>Customer</th>
                                <th>FG Part</th>
                                <th>FG Description</th>
                                <th>RM Part</th>
                                @if ($viewMode === 'sales')
                                    <th>Sales Order</th>
                                @endif
                                <th>Avg {{ $forecastHorizonMonths }}M</th>
                                @if ($viewMode === 'sales')
                                    <th>Forecast?</th>
                                @endif
                                <th>K</th>
                                @if ($viewMode === 'sales')
                                    <th>Forecast 1M</th>
                                    <th>Forecast {{ $forecastHorizonMonths }}M</th>
                                @else
                                    @foreach ($futureLabels as $label)
                                        <th>{{ $label }}</th>
                                    @endforeach
                                    <th>Approved 1M</th>
                                    <th>Approved {{ $forecastHorizonMonths }}M</th>
                                @endif
                                <th>Supplier</th>
                                <th>Sales Remark</th>
                                @if ($viewMode === 'planner')
                                    <th>Approval Remark</th>
                                @endif
                                <th>History</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                <tr>
                                    <td class="text-center"><span class="badge bg-primary">{{ $row['sales_code'] }}</span></td>
                                    <td>
                                        <div>{{ $row['customer_name'] }}</div>
                                        <div class="small text-muted">ID: {{ $row['customer_id'] }}</div>
                                    </td>
                                    <td class="fw-semibold">{{ $row['fg_partnumber'] }}</td>
                                    <td>{{ $row['fg_description'] ?: '-' }}</td>
                                    <td>{{ $row['rm_partnumber'] ?: '-' }}</td>
                                    @if ($viewMode === 'sales')
                                        <td class="num">{{ number_format($row['sales_order_qty'], 2) }}</td>
                                    @endif
                                    <td class="num">{{ number_format($row['history_avg6'], 2) }}</td>
                                    @if ($viewMode === 'sales')
                                        <td class="text-center"><i class="fa-solid fa-check text-success" aria-label="เลือก Forecast"></i></td>
                                    @endif
                                    <td class="num">{{ number_format($row['k_factor'], 1) }}</td>
                                    @if ($viewMode === 'sales')
                                        <td class="num">{{ number_format($row['forecast_by_month'][$futureYm[0]] ?? 0, 2) }}</td>
                                        <td class="num">{{ number_format(collect($row['forecast_by_month'])->sum(), 2) }}</td>
                                    @else
                                        @foreach ($futureYm as $ym)
                                            <td class="num">{{ number_format($row['forecast_by_month'][$ym] ?? 0, 2) }}</td>
                                        @endforeach
                                        <td class="num">
                                            {{ $row['approval_forecast_1m'] === null ? '-' : number_format($row['approval_forecast_1m'], 2) }}
                                        </td>
                                        <td class="num">
                                            {{ $row['approval_forecast_6m'] === null ? '-' : number_format($row['approval_forecast_6m'], 2) }}
                                        </td>
                                    @endif
                                    <td>
                                        {{ $row['supplier_code'] ?: '-' }}
                                        @if ($row['supplier_name'])
                                            <div class="small text-muted">{{ $row['supplier_name'] }}</div>
                                        @endif
                                    </td>
                                    <td>{{ $row['row_remark'] ?: '-' }}</td>
                                    @if ($viewMode === 'planner')
                                        <td>{{ $row['approval_remark'] ?: '-' }}</td>
                                    @endif
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-secondary btn-history"
                                            data-customer="{{ $row['customer_name'] }}"
                                            data-fg="{{ $row['fg_partnumber'] }}"
                                            data-history='@json($row['forecast_history'] ?? [])'>ดู</button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $viewMode === 'sales' ? 15 : 16 + count($futureYm) }}" class="text-center text-muted py-4">
                                        ไม่พบข้อมูล Forecast ของ Division ที่เลือกในเดือนนี้
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if ($rows->hasPages())
                <div class="card-footer bg-white">
                    {{ $rows->links() }}
                </div>
            @endif
        </div>
    </div>

    <div class="modal fade" id="forecastHistoryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <div class="fw-semibold" id="forecastHistoryTitle">Forecast History</div>
                        <div class="small text-muted">ประวัติที่ Sales บันทึกหรือ Submit แยกตาม batch</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Batch ID</th>
                                    <th>วันเวลาบันทึก</th>
                                    <th>ผู้ดำเนินการ</th>
                                    <th>Division</th>
                                    <th class="text-end">K</th>
                                    <th class="text-end">Forecast 1M</th>
                                    <th class="text-end">Forecast {{ $forecastHorizonMonths }}M</th>
                                    <th>Source</th>
                                </tr>
                            </thead>
                            <tbody id="forecastHistoryBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('toggleAllDivisions')?.addEventListener('click', function () {
            const checkboxes = Array.from(document.querySelectorAll('input[name="divisions[]"]'));
            const shouldCheck = checkboxes.some((checkbox) => !checkbox.checked);
            checkboxes.forEach((checkbox) => checkbox.checked = shouldCheck);
        });

        const escapeHtml = (value) => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
        const formatNumber = (value, digits = 2) => Number(value || 0).toLocaleString('th-TH', {
            minimumFractionDigits: digits,
            maximumFractionDigits: digits,
        });

        document.addEventListener('click', function (event) {
            const button = event.target.closest('.btn-history');
            if (!button) return;

            let history = [];
            try {
                history = JSON.parse(button.dataset.history || '[]');
            } catch (error) {
                history = [];
            }

            document.getElementById('forecastHistoryTitle').textContent =
                `Forecast History | ${button.dataset.customer || '-'} | ${button.dataset.fg || '-'}`;
            document.getElementById('forecastHistoryBody').innerHTML = history.length
                ? history.map((row) => `
                    <tr>
                        <td>${escapeHtml(row.batch_id)}</td>
                        <td>${escapeHtml(row.saved_at || '-')}</td>
                        <td>${escapeHtml(row.actor || '-')}</td>
                        <td>${escapeHtml(row.sales_code || '-')}</td>
                        <td class="text-end">${formatNumber(row.k_factor, 1)}</td>
                        <td class="text-end">${formatNumber(row.forecast_1m)}</td>
                        <td class="text-end">${formatNumber(row.forecast_horizon)}</td>
                        <td>${escapeHtml(row.source_type || '-')}</td>
                    </tr>`).join('')
                : '<tr><td colspan="8" class="text-center text-muted py-3">ยังไม่มีประวัติ Forecast</td></tr>';

            new bootstrap.Modal(document.getElementById('forecastHistoryModal')).show();
        });
    </script>
@endsection
