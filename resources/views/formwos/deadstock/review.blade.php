@extends('layouts.layout')

@section('title', 'ตรวจสถานะ Deadstock รายเดือน')
@section('page-title', 'ตรวจสถานะ Deadstock รายเดือน')

@push('styles')
    <style>
        .ds-shell {
            display: grid;
            gap: 14px;
        }

        .ds-toolbar,
        .ds-summary,
        .ds-table {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
        }

        .ds-toolbar,
        .ds-summary {
            padding: 14px 16px 12px;
        }

        .ds-toolbar-head {
            align-items: flex-start;
            display: flex;
            gap: 12px;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .ds-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: flex-end;
            max-width: 620px;
        }

        .ds-actions .btn {
            min-width: 132px;
        }

        .ds-filter-title {
            align-items: center;
            border-top: 1px solid #e2e8f0;
            display: flex;
            font-weight: 700;
            justify-content: space-between;
            margin-top: 4px;
            padding-top: 12px;
        }

        .ds-month-range {
            display: grid;
            gap: 8px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .ds-summary-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        }

        .ds-kpi {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            min-height: 82px;
            padding: 12px;
        }

        .ds-kpi:nth-child(2) {
            background: #eff6ff;
        }

        .ds-kpi:nth-child(3) {
            background: #fffbeb;
        }

        .ds-kpi:nth-child(4) {
            background: #f0fdf4;
        }

        .ds-kpi-label {
            color: #64748b;
            font-size: .76rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .ds-kpi-value {
            color: #0f172a;
            font-size: 1.2rem;
            font-weight: 700;
            line-height: 1.25;
            margin-top: 6px;
        }

        .ds-status {
            border-radius: 999px;
            display: inline-flex;
            font-size: .76rem;
            font-weight: 700;
            padding: .26rem .55rem;
            white-space: nowrap;
        }

        .ds-status.pending {
            background: #e2e8f0;
            color: #334155;
        }

        .ds-status.active {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .ds-status.changed {
            background: #fef3c7;
            color: #92400e;
        }

        .ds-status.cleared {
            background: #dcfce7;
            color: #166534;
        }

        .ds-change-list {
            display: grid;
            gap: 4px;
            margin-top: 8px;
        }

        .ds-change {
            background: #fffbeb;
            border: 1px solid #fcd34d;
            border-radius: 6px;
            color: #78350f;
            font-size: .76rem;
            font-weight: 700;
            line-height: 1.3;
            padding: 6px 8px;
        }

        .ds-change-value {
            background: #fff7ed;
            border-left: 3px solid #f59e0b;
            border-radius: 4px;
            margin-top: 6px;
            padding: 6px 8px;
        }

        .ds-change-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 6px;
        }

        .ds-change-tag {
            background: #fef3c7;
            border-radius: 999px;
            color: #92400e;
            font-size: .72rem;
            font-weight: 700;
            padding: .18rem .45rem;
        }

        .ds-action-status {
            background: #eef2ff;
            border-radius: 999px;
            color: #3730a3;
            display: inline-flex;
            font-size: .72rem;
            font-weight: 700;
            margin-top: 6px;
            padding: .2rem .5rem;
        }

        .ds-table-wrap {
            max-height: 68vh;
            overflow: auto;
        }

        .ds-grid {
            font-size: .92rem;
            min-width: 1480px;
            table-layout: fixed;
        }

        .ds-grid th {
            background: #f8fafc;
            color: #334155;
            font-size: .9rem;
            font-weight: 800;
            position: sticky;
            top: 0;
            z-index: 4;
        }

        .ds-grid th,
        .ds-grid td {
            padding: .42rem .48rem;
        }

        .ds-grid th:nth-child(1),
        .ds-grid td:nth-child(1) {
            width: 130px;
        }

        .ds-grid th:nth-child(2),
        .ds-grid td:nth-child(2),
        .ds-grid th:nth-child(5),
        .ds-grid td:nth-child(5),
        .ds-grid th:nth-child(6),
        .ds-grid td:nth-child(6) {
            width: 104px;
        }

        .ds-grid th:nth-child(4),
        .ds-grid td:nth-child(4) {
            width: 128px;
        }

        .ds-grid th:nth-child(3),
        .ds-grid td:nth-child(3) {
            width: 220px;
        }

        .ds-grid th:nth-child(8),
        .ds-grid td:nth-child(8) {
            width: 260px;
        }

        .ds-grid th:nth-child(9),
        .ds-grid td:nth-child(9) {
            width: 92px;
        }

        .ds-grid th:nth-child(10),
        .ds-grid td:nth-child(10) {
            width: 160px;
        }

        .ds-grid th:nth-child(7),
        .ds-grid td:nth-child(7),
        .ds-grid th:nth-child(11),
        .ds-grid td:nth-child(11),
        .ds-grid th:nth-child(12),
        .ds-grid td:nth-child(12),
        .ds-grid th:nth-child(13),
        .ds-grid td:nth-child(13) {
            display: none;
        }

        .ds-grid th:nth-child(14),
        .ds-grid td:nth-child(14) {
            width: 116px;
        }

        .ds-grid td {
            vertical-align: top;
            word-break: break-word;
        }

        .ds-item-meta {
            color: #64748b;
            font-size: .78rem;
            margin-top: 4px;
        }

        .ds-review-btn {
            min-width: 100px;
        }

        .ds-note {
            color: #64748b;
            font-size: .78rem;
        }

        .ds-cell-strong {
            color: #0f172a;
            font-weight: 700;
        }

        .ds-customer-cell {
            width: 230px;
        }

        .ds-toolbar .form-label {
            font-size: .82rem;
            font-weight: 700;
        }

        .ds-toolbar form.row {
            border-top: 1px solid #e2e8f0;
            margin-top: 10px !important;
            padding-top: 12px;
        }

        .ds-month-picker {
            background: #fff;
            border: 1px solid #dbe4ef;
            border-radius: 6px;
            display: grid;
            gap: 4px;
            max-height: 138px;
            overflow: auto;
            padding: 8px 10px;
        }

        .ds-month-picker .form-check {
            margin: 0;
            min-height: 0;
        }

        .ds-month-picker .form-check-label {
            font-size: .9rem;
        }

        @media (max-width: 1200px) {
            .ds-actions {
                justify-content: flex-start;
                max-width: none;
            }

            .ds-toolbar-head {
                flex-direction: column;
            }

            .ds-summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>
@endpush

@section('content')
    @php
        $selectedMonthIds = $selectedMonthIds ?? ($selectedMonth ? [$selectedMonth->id] : []);
        $allMonthsSelected = $months->isNotEmpty() && count($selectedMonthIds) === $months->count();
        $monthLabel = $allMonthsSelected
            ? 'ทุกเดือน'
            : ($selectedMonths ?? collect())
                ->map(fn($month) => $month->snapshot_month?->format('M Y') ?? '-')
                ->implode(', ');
        $monthLabel = $monthLabel !== '' ? $monthLabel : '-';
        $statusTabs = [
            'review' => 'ต้องติดตาม',
            'all' => 'ทั้งหมด',
            'pending' => 'รอเทียบข้อมูล',
            'active' => 'ยังค้าง',
            'changed' => 'เปลี่ยนแปลง',
            'cleared' => 'เคลียร์แล้ว',
        ];
        $actionStatusLabels = [
            'open' => 'ยังไม่เริ่ม',
            'waiting_sales' => 'รอ Sales',
            'waiting_customer' => 'รอลูกค้า',
            'waiting_delivery' => 'รอจัดส่ง',
            'follow_up' => 'ต้องติดตามต่อ',
            'closed' => 'ปิดแล้ว',
        ];
        $actionStatusOptions = ['all' => 'ทุกสถานะการติดตาม', 'no_action' => 'ยังไม่มีงานติดตาม', 'due_follow_up' => 'ถึงวันติดตาม'] + $actionStatusLabels;
        $sortOptions = [
            'qty_desc' => 'Qty มากสุด',
            'purchase_oldest' => 'วันที่รับเข้าเก่าสุด',
            'due_soon' => 'กำหนดส่งใกล้สุด',
        ];
        $plusCompanyFilter = collect($companyOptions ?? [])->first(fn($companyName) => stripos((string) $companyName, 'PLUS') !== false);
        $monthBaseQuery = request()->except(['month_ids', 'month_id', 'month_from', 'month_to']);
        $latestMonth = $months->first()?->snapshot_month;
        $thirdMonth = $months->skip(2)->first()?->snapshot_month ?? $latestMonth;
        $oldestMonth = $months->last()?->snapshot_month;
        $latestMonthQuery = $monthBaseQuery + ['month_from' => $latestMonth?->format('Y-m'), 'month_to' => $latestMonth?->format('Y-m')];
        $recentThreeMonthQuery = $monthBaseQuery + ['month_from' => $thirdMonth?->format('Y-m'), 'month_to' => $latestMonth?->format('Y-m')];
        $allMonthQuery = $monthBaseQuery + ['month_from' => $oldestMonth?->format('Y-m'), 'month_to' => $latestMonth?->format('Y-m')];
    @endphp

    <div class="container-fluid py-3 ds-shell">
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
        <section class="ds-toolbar">
            <div class="ds-toolbar-head">
                <div>
                    <div class="text-muted small">
                        รอบที่เลือก: {{ $monthLabel }}
                        @if (!$allMonthsSelected && $selectedMonth?->recv_date)
                            · snapshot {{ $selectedMonth->recv_date->format('Y-m-d') }}
                        @endif
                    </div>
                    <div class="text-muted small mt-1">
                        ใช้ข้อมูล Deadstock ที่ส่งเมลรายวันเป็น snapshot แล้วเทียบกับข้อมูลปัจจุบัน
                        เพื่อดูความต่อเนื่องของรายการว่ายังค้างอยู่ เคลียร์แล้ว หรือมีการเปลี่ยนแปลงระหว่างทาง
                    </div>
                </div>

                <div class="ds-actions">
                    <a class="btn btn-outline-secondary" href="{{ route('deadstock.dashboard') }}">
                        <i class="fa fa-chart-column me-1"></i> ภาพรวม
                    </a>
                    @if ($selectedMonth)
                        <form method="post" action="{{ route('deadstock.review.compare', $selectedMonth) }}">
                            @csrf
                            <button class="btn btn-primary" type="submit">
                                <i class="fa fa-rotate me-1"></i> เทียบข้อมูลปัจจุบัน
                            </button>
                        </form>
                    @endif
                    <a class="btn btn-success" href="{{ route('deadstock.review.export', request()->query()) }}">
                        <i class="fa fa-file-excel me-1"></i> Export Excel
                    </a>
                </div>
            </div>

            <div class="ds-filter-title">
                <span>ตัวกรองข้อมูลที่ import แล้ว</span>
                <span class="text-muted small">เลือกเดือน / สถานะ / บริษัท / Sales เพื่อดูข้อมูลเก่า</span>
            </div>

            <form class="row g-3 align-items-start mt-2" method="get" action="{{ route('deadstock.review') }}">
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                        <label class="form-label mb-0">เลือกเดือน</label>
                        <div class="btn-group btn-group-sm" role="group" aria-label="เลือกช่วงเดือน">
                            <a class="btn btn-outline-secondary" href="{{ route('deadstock.review', $latestMonthQuery) }}">ล่าสุด</a>
                            <a class="btn btn-outline-secondary" href="{{ route('deadstock.review', $recentThreeMonthQuery) }}">3 เดือน</a>
                            <a class="btn btn-outline-secondary" href="{{ route('deadstock.review', $allMonthQuery) }}">ทั้งหมด</a>
                        </div>
                    </div>
                    <div class="ds-month-range">
                        <div>
                            <div class="text-muted small mb-1">จาก</div>
                            <input class="form-control" type="month" name="month_from" value="{{ $monthFrom }}" aria-label="จากเดือน">
                        </div>
                        <div>
                            <div class="text-muted small mb-1">ถึง</div>
                            <input class="form-control" type="month" name="month_to" value="{{ $monthTo }}" aria-label="ถึงเดือน">
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <label class="form-label">สถานะ</label>
                    <select class="form-select" name="status">
                        @foreach ($statusTabs as $value => $label)
                            <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <label class="form-label">สถานะการติดตาม</label>
                    <select class="form-select" name="action_status">
                        @foreach ($actionStatusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($actionStatus === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <label class="form-label">บริษัท</label>
                    <select class="form-select" name="company">
                        <option value="all" @selected($companyFilter === 'all')>ทุกบริษัท</option>
                        @foreach ($companyOptions as $companyName)
                            <option value="{{ $companyName }}" @selected($companyFilter === $companyName)>{{ $companyName }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <label class="form-label">รหัสสาเหตุ</label>
                    <select class="form-select" name="reason_code">
                        <option value="all" @selected($reasonFilter === 'all')>ทุกสาเหตุ</option>
                        @foreach ($reasonOptions as $reasonOption)
                            @php
                                $reasonCode = $reasonOption->deadstock_code;
                                $reasonName = trim((string) $reasonOption->deadstock_desc);
                                $reasonLabel = $reasonName !== '' ? $reasonCode . ' - ' . $reasonName : $reasonCode;
                            @endphp
                            <option value="{{ $reasonCode }}" @selected($reasonFilter === $reasonCode)>{{ $reasonLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <label class="form-label">Sales</label>
                    <select class="form-select" name="sales">
                        <option value="all" @selected($salesFilter === 'all')>ทุก Sales</option>
                        @foreach ($salesOptions as $salesName)
                            <option value="{{ $salesName }}" @selected($salesFilter === $salesName)>{{ $salesName }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <label class="form-label">Serial no.</label>
                    <input class="form-control" type="search" name="serial" value="{{ $serialFilter }}" placeholder="ค้นหา Serial">
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <label class="form-label">เรียงตาม</label>
                    <select class="form-select" name="sort">
                        @foreach ($sortOptions as $value => $label)
                            <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <button class="btn btn-outline-primary w-100" type="submit">
                        <i class="fa fa-filter me-1"></i> ค้นหา
                    </button>
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <a class="btn btn-outline-secondary w-100" href="{{ route('deadstock.review') }}">
                        <i class="fa fa-eraser me-1"></i> ล้างตัวกรอง
                    </a>
                </div>
            </form>
            <div class="d-flex flex-wrap gap-2 mt-3">
                @if ($plusCompanyFilter)
                    <a class="btn btn-sm {{ $companyFilter === $plusCompanyFilter ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('deadstock.review', request()->query() + ['company' => $plusCompanyFilter]) }}">
                        PLUS
                    </a>
                @endif
                <a class="btn btn-sm btn-outline-warning" href="{{ route('deadstock.review', request()->query() + ['action_status' => 'no_action']) }}">
                    ยังไม่มีงานติดตาม
                </a>
                <a class="btn btn-sm btn-outline-primary" href="{{ route('deadstock.review', request()->query() + ['action_status' => 'follow_up']) }}">
                    ต้องติดตามต่อ
                </a>
                <a class="btn btn-sm btn-outline-danger" href="{{ route('deadstock.review', request()->query() + ['action_status' => 'due_follow_up']) }}">
                    ถึงวันติดตาม
                </a>
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('deadstock.review', request()->query() + ['sort' => 'due_soon']) }}">
                    กำหนดส่งใกล้สุด
                </a>
            </div>
        </section>

        @if (($rawSnapshotCount ?? 0) > 0 && $months->isEmpty())
            <div class="alert alert-warning mb-0">
                พบข้อมูล Dashboard แล้ว แต่ยังไม่มีรายการสำหรับ Monthly Review
            </div>
        @endif

        <section class="ds-summary">
            <div class="fw-semibold mb-2">สรุปตาม Parameter ที่เลือก</div>
            <div class="ds-summary-grid">
                <div class="ds-kpi">
                    <div class="ds-kpi-label">รายการ</div>
                    <div class="ds-kpi-value">{{ number_format((int) ($summary->total_items ?? 0)) }}</div>
                </div>
                <div class="ds-kpi">
                    <div class="ds-kpi-label">ยังค้าง</div>
                    <div class="ds-kpi-value">{{ number_format((int) ($summary->active_items ?? 0)) }}</div>
                </div>
                <div class="ds-kpi">
                    <div class="ds-kpi-label">เปลี่ยนแปลง</div>
                    <div class="ds-kpi-value">{{ number_format((int) ($summary->changed_items ?? 0)) }}</div>
                </div>
                <div class="ds-kpi">
                    <div class="ds-kpi-label">เคลียร์แล้ว</div>
                    <div class="ds-kpi-value">{{ number_format((int) ($summary->cleared_items ?? 0)) }}</div>
                </div>
                <div class="ds-kpi">
                    <div class="ds-kpi-label">Qty จาก snapshot</div>
                    <div class="ds-kpi-value">{{ number_format((float) ($summary->total_qty ?? 0), 2) }}</div>
                </div>
                <div class="ds-kpi">
                    <div class="ds-kpi-label">Qty หลังเทียบล่าสุด</div>
                    <div class="ds-kpi-value">{{ number_format((float) ($summary->current_total_qty ?? 0), 2) }}</div>
                </div>
                <div class="ds-kpi">
                    <div class="ds-kpi-label">ยังไม่มีงานติดตาม</div>
                    <div class="ds-kpi-value">{{ number_format((int) ($summary->no_action_items ?? 0)) }}</div>
                </div>
                <div class="ds-kpi">
                    <div class="ds-kpi-label">ถึงวันติดตาม</div>
                    <div class="ds-kpi-value">{{ number_format((int) ($summary->due_follow_up_items ?? 0)) }}</div>
                </div>
                <div class="ds-kpi">
                    <div class="ds-kpi-label">ปิดการติดตามแล้ว</div>
                    <div class="ds-kpi-value">{{ number_format((int) ($summary->closed_action_items ?? 0)) }}</div>
                </div>
            </div>
        </section>

        <section class="ds-table">
            <div class="ds-table-wrap">
                <table class="table table-bordered table-hover mb-0 ds-grid">
                    <thead>
                        <tr>
                            <th>สถานะ</th>
                            <th>วันที่รับเข้า</th>
                            <th>รายการ</th>
                            <th>Serial no.</th>
                            <th>Qty</th>
                            <th>กำหนดส่งเดิม</th>
                            <th>กำหนดส่งใหม่</th>
                            <th>ลูกค้า / Sales</th>
                            <th>รหัสสาเหตุ</th>
                            <th>รายละเอียด</th>
                            <th>แนวทางแก้ไข</th>
                            <th>แนวทางป้องกัน</th>
                            <th>Sales remark</th>
                            <th>บันทึก</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $item)
                            @php
                                $review = $item->review;
                                $formId = 'review-' . $item->id;
                                $snapshotQty = (float) $item->snapshot_qty;
                                $currentQty = $item->current_qty !== null ? (float) $item->current_qty : null;
                                $snapshotQtyRounded = round($snapshotQty, 2);
                                $currentQtyRounded = $currentQty !== null ? round($currentQty, 2) : null;
                                $snapshotDue = $item->due_date?->format('Y-m-d');
                                $currentDue = $item->current_due_date?->format('Y-m-d');
                                $qtyChanged = $item->compare_status === 'changed'
                                    && $currentQtyRounded !== null
                                    && $currentQtyRounded !== $snapshotQtyRounded;
                                $dueChanged = $item->compare_status === 'changed'
                                    && $currentDue !== null
                                    && $currentDue !== $snapshotDue;
                                $changeDetails = [];
                                if ($qtyChanged) {
                                    $changeDetails[] = 'Qty: ' . number_format($snapshotQtyRounded, 2) . ' -> ' . number_format($currentQtyRounded, 2);
                                }
                                if ($dueChanged) {
                                    $changeDetails[] = 'กำหนดส่ง: ' . ($snapshotDue ?: '-') . ' -> ' . $currentDue;
                                }
                                if ($item->compare_status === 'changed' && empty($changeDetails)) {
                                    $changeDetails[] = 'ข้อมูลปัจจุบันต่างจาก snapshot';
                                }
                                $changeTags = [];
                                if ($qtyChanged) {
                                    $changeTags[] = 'Qty เปลี่ยน';
                                }
                                if ($dueChanged) {
                                    $changeTags[] = 'Due date เปลี่ยน';
                                }
                                $reviewStatus = $review?->review_status ?? 'open';
                                $serialNumber = trim((string) $item->serialnumber);
                                $transactionNumber = trim((string) $item->transaction_number);
                                $showTransactionNumber = $transactionNumber !== '' && strcasecmp($transactionNumber, $serialNumber) !== 0;
                                $liveReasonCode = trim((string) ($item->latestCompareLog?->matched_deadstock_code ?? ''));
                                $reasonCode = $liveReasonCode !== '' ? $liveReasonCode : $item->deadstock_code;
                                $reasonDescription = \App\Support\FormWOS\DeadstockReasonMap::description($reasonCode, $item->deadstock_desc);
                                $reasonIsFromLive = $liveReasonCode !== '' && $liveReasonCode !== (string) $item->deadstock_code;
                            @endphp
                            <tr>
                                <td>
                                    <span class="ds-status {{ $item->compare_status }}">
                                        {{ $statusTabs[$item->compare_status] ?? ucfirst($item->compare_status) }}
                                    </span>
                                    <div class="ds-note mt-2">
                                        @if ($item->last_checked_at)
                                            เทียบล่าสุด {{ $item->last_checked_at->format('Y-m-d H:i') }}
                                        @else
                                            ยังไม่เทียบปัจจุบัน
                                        @endif
                                    </div>
                                    @if (!empty($changeDetails))
                                        <div class="ds-change-list">
                                            @foreach ($changeDetails as $changeDetail)
                                                <div class="ds-change">{{ $changeDetail }}</div>
                                            @endforeach
                                        </div>
                                    @endif
                                    @if (!empty($changeTags))
                                        <div class="ds-change-tags">
                                            @foreach ($changeTags as $changeTag)
                                                <span class="ds-change-tag">{{ $changeTag }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td>{{ $item->purchase_date?->format('Y-m-d') ?? '-' }}</td>
                                <td>
                                    <div class="ds-cell-strong">{{ $item->part_description ?: $item->partnumber ?: '-' }}</div>
                                    <div class="ds-item-meta">
                                        {{ $item->partnumber ?: '-' }}
                                    </div>
                                </td>
                                <td>
                                    <div class="ds-cell-strong">{{ $item->serialnumber ?: '-' }}</div>
                                    @if ($showTransactionNumber)
                                        <div class="ds-item-meta">{{ $transactionNumber }}</div>
                                    @endif
                                </td>
                                <td>
                                    <div class="ds-cell-strong">{{ number_format((float) $item->snapshot_qty, 2) }}</div>
                                    @if ($item->current_qty !== null)
                                        <div @class(['ds-note', 'ds-change-value' => $qtyChanged])>
                                            ปัจจุบัน {{ number_format((float) $item->current_qty, 2) }}
                                            @if ($qtyChanged)
                                                <div>ต่าง {{ number_format($currentQtyRounded - $snapshotQtyRounded, 2) }}</div>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div class="ds-cell-strong">{{ $item->due_date?->format('Y-m-d') ?? '-' }}</div>
                                    @if ($item->current_due_date)
                                        <div @class(['ds-note', 'ds-change-value' => $dueChanged])>
                                            ปัจจุบัน {{ $item->current_due_date->format('Y-m-d') }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <input
                                        form="{{ $formId }}"
                                        class="form-control ds-input"
                                        type="date"
                                        name="revised_due_date"
                                        value="{{ old('revised_due_date', $review?->revised_due_date?->format('Y-m-d')) }}">
                                </td>
                                <td class="ds-customer-cell">
                                    <div class="ds-cell-strong">{{ $item->customer_name ?: '-' }}</div>
                                    <div class="ds-item-meta">{{ $item->salesperson_name ?: '-' }}</div>
                                </td>
                                <td>
                                    <div class="fw-semibold">{{ $reasonCode ?: '-' }}</div>
                                    <div class="ds-item-meta">
                                        {{ $item->company ?: '-' }}
                                        @if ($reasonIsFromLive)
                                            · ปัจจุบัน
                                        @endif
                                    </div>
                                </td>
                                <td>{{ $reasonDescription ?: '-' }}</td>
                                <td>
                                    <textarea
                                        form="{{ $formId }}"
                                        class="form-control ds-textarea"
                                        name="corrective_action"
                                        rows="3">{{ old('corrective_action', $review?->corrective_action) }}</textarea>
                                </td>
                                <td>
                                    <textarea
                                        form="{{ $formId }}"
                                        class="form-control ds-textarea"
                                        name="preventive_action"
                                        rows="3">{{ old('preventive_action', $review?->preventive_action) }}</textarea>
                                </td>
                                <td>
                                    <textarea
                                        form="{{ $formId }}"
                                        class="form-control ds-textarea"
                                        name="sales_remark"
                                        rows="3">{{ old('sales_remark', $review?->sales_remark) }}</textarea>
                                </td>
                                <td>
                                    <button
                                        type="button"
                                        class="btn btn-outline-primary ds-review-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#review-modal-{{ $item->id }}">
                                        <i class="fa fa-pen-to-square me-1"></i> บันทึกงาน
                                    </button>
                                    <div class="ds-note mt-2">
                                        <span class="ds-action-status">{{ $actionStatusLabels[$reviewStatus] ?? $reviewStatus }}</span>
                                    </div>
                                    <div class="ds-note mt-2">
                                        @if ($review?->next_follow_up_date)
                                            ตามต่อ {{ $review->next_follow_up_date->format('Y-m-d') }}
                                        @elseif ($review?->reviewed_at)
                                            บันทึก {{ $review->reviewed_at->format('Y-m-d H:i') }}
                                        @else
                                            ยังไม่มีงานติดตาม
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            <div class="modal fade" id="review-modal-{{ $item->id }}" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                    <div class="modal-content">
                                        <form method="post" action="{{ route('deadstock.review.save', $item) }}">
                                            @csrf
                                            <div class="modal-header">
                                                <div>
                                                    <h5 class="modal-title mb-1">บันทึกการติดตาม</h5>
                                                    <div class="text-muted small">
                                                        {{ $item->part_description ?: $item->partnumber ?: '-' }}
                                                        · {{ $item->serialnumber ?: $item->transaction_number ?: '-' }}
                                                    </div>
                                                </div>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body">
                                                @if (!empty($changeDetails))
                                                    <div class="alert alert-warning py-2">
                                                        <div class="fw-semibold mb-1">เปลี่ยนจาก snapshot</div>
                                                        @foreach ($changeDetails as $changeDetail)
                                                            <div>{{ $changeDetail }}</div>
                                                        @endforeach
                                                    </div>
                                                @endif
                                                <div class="row g-3">
                                                    <div class="col-12 col-md-4">
                                                        <label class="form-label">กำหนดส่งใหม่</label>
                                                        <input class="form-control" type="date" name="revised_due_date" value="{{ old('revised_due_date', $review?->revised_due_date?->format('Y-m-d')) }}">
                                                    </div>
                                                    <div class="col-12 col-md-4">
                                                        <label class="form-label">สถานะการติดตาม</label>
                                                        <select class="form-select" name="review_status">
                                                            @foreach ($actionStatusLabels as $value => $label)
                                                                <option value="{{ $value }}" @selected($reviewStatus === $value)>{{ $label }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    <div class="col-12 col-md-4">
                                                        <label class="form-label">วันติดตามถัดไป</label>
                                                        <input class="form-control" type="date" name="next_follow_up_date" value="{{ old('next_follow_up_date', $review?->next_follow_up_date?->format('Y-m-d')) }}">
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label">ลูกค้า / Sales</label>
                                                        <div class="form-control bg-light">{{ $item->customer_name ?: '-' }} / {{ $item->salesperson_name ?: '-' }}</div>
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label">แนวทางแก้ไข</label>
                                                        <textarea class="form-control" name="corrective_action" rows="4">{{ old('corrective_action', $review?->corrective_action) }}</textarea>
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label">แนวทางป้องกัน</label>
                                                        <textarea class="form-control" name="preventive_action" rows="4">{{ old('preventive_action', $review?->preventive_action) }}</textarea>
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label">Sales remark</label>
                                                        <textarea class="form-control" name="sales_remark" rows="3">{{ old('sales_remark', $review?->sales_remark) }}</textarea>
                                                    </div>
                                                    <div class="col-12">
                                                        <div class="text-muted small">
                                                            @if ($review?->reviewed_at)
                                                                บันทึกล่าสุด {{ $review->reviewed_at->format('Y-m-d H:i') }}
                                                                @if ($review?->reviewer)
                                                                    โดย {{ $review->reviewer->name }}
                                                                @endif
                                                            @else
                                                                ยังไม่มีประวัติการติดตาม
                                                            @endif
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button>
                                                <button class="btn btn-success" type="submit">
                                                    <i class="fa fa-floppy-disk me-1"></i> บันทึกการติดตาม
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
                                    ยังไม่มี snapshot item สำหรับเดือนนี้
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        @if ($items->hasPages())
            <div>{{ $items->links() }}</div>
        @endif
    </div>
@endsection
