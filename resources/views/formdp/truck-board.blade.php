@extends('layouts.layout')
@section('page-title', 'จัดรถส่งสินค้า')
@section('title', 'จัดรถส่งสินค้า')

@section('content')
    @php
        $refreshUrl = route('dp.dashboard.truck-board', ['ship_date' => $shipDate]);
        $fmtTon = fn($kg) => number_format(((float) ($kg ?? 0)) / 1000, 3);
    @endphp

    <style>
        .truck-board-page {
            background: #f4f7fb;
            min-height: calc(100vh - 70px);
        }

        .summary-card {
            border: 0;
            border-radius: 18px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .08);
        }

        .summary-label {
            font-size: .82rem;
            color: #64748b;
            margin-bottom: 4px;
        }

        .summary-value {
            font-size: 1.6rem;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.1;
        }

        .truck-card {
            border: 0;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 28px rgba(15, 23, 42, .10);
        }

        .truck-card.unassigned .truck-header {
            background: linear-gradient(135deg, #7c2d12, #b45309);
        }

        .truck-header {
            background: linear-gradient(135deg, #0f172a, #1d4ed8);
            color: #fff;
            padding: 18px 20px;
        }

        .truck-title {
            font-size: 1.45rem;
            font-weight: 700;
            margin: 0;
        }

        .truck-subtitle {
            font-size: .92rem;
            opacity: .92;
            margin-top: 4px;
        }

        .truck-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 18px;
            margin-top: 10px;
            font-size: .9rem;
        }

        .truck-badge {
            display: inline-block;
            padding: 5px 11px;
            border-radius: 999px;
            font-size: .78rem;
            font-weight: 700;
            color: #0f172a;
            background: rgba(255, 255, 255, .92);
            border: 1px solid rgba(255, 255, 255, .72);
            box-shadow: 0 4px 14px rgba(15, 23, 42, .10);
        }

        .truck-badge.status-open {
            color: #065f46;
            background: #dcfce7;
            border-color: #86efac;
        }

        .truck-badge.status-closed {
            color: #334155;
            background: #e2e8f0;
            border-color: #cbd5e1;
        }

        .truck-badge.metric {
            color: #1e3a8a;
        }

        .truck-badge.metric-muted {
            color: #475569;
        }

        .truck-badge.metric-warning {
            color: #854d0e;
            background: #fef3c7;
            border-color: #fbbf24;
        }

        .truck-badge.metric-danger {
            color: #991b1b;
            background: #fee2e2;
            border-color: #fca5a5;
        }

        .truck-close-btn {
            color: #065f46;
            background: #fff;
            border: 1px solid #86efac;
            box-shadow: 0 6px 18px rgba(15, 23, 42, .16);
        }

        .truck-close-btn:hover {
            color: #fff;
            background: #16a34a;
            border-color: #16a34a;
        }

        .truck-stat {
            padding: 14px 16px;
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
        }

        .truck-stat-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
        }

        .truck-stat-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 10px 12px;
        }

        .truck-stat-box .label {
            display: block;
            font-size: .78rem;
            color: #64748b;
            margin-bottom: 4px;
        }

        .truck-stat-box .value {
            font-size: 1.05rem;
            font-weight: 700;
            color: #0f172a;
        }

        .truck-table-wrap {
            padding: 0;
            background: #fff;
        }

        .truck-table {
            margin-bottom: 0;
            font-size: .9rem;
        }

        .truck-table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #e2e8f0;
            color: #0f172a;
            font-size: .8rem;
            white-space: nowrap;
            vertical-align: middle;
        }

        .truck-table td {
            vertical-align: top;
        }

        .col-main {
            min-width: 280px;
        }

        .mini-text {
            font-size: .8rem;
            color: #64748b;
        }

        .value-strong {
            font-weight: 700;
            color: #1d4ed8;
        }

        .stock-text {
            color: #0369a1;
            font-weight: 700;
        }

        .docs-text {
            font-size: .82rem;
            color: #334155;
        }

        .address-text {
            min-width: 240px;
        }

        .top-bar {
            position: sticky;
            top: 0;
            z-index: 10;
            background: rgba(244, 247, 251, 0.94);
            backdrop-filter: blur(8px);
        }

        .refresh-dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: #22c55e;
            display: inline-block;
            margin-right: 6px;
        }

        .board-filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            padding: 10px 12px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 6px 18px rgba(15, 23, 42, .06);
        }

        .board-filter-bar .btn.active {
            color: #fff;
            background: #1d4ed8;
            border-color: #1d4ed8;
        }

        .truck-print-btn {
            color: #1e3a8a;
            background: rgba(255, 255, 255, .94);
            border: 1px solid rgba(255, 255, 255, .75);
            box-shadow: 0 6px 18px rgba(15, 23, 42, .14);
        }

        .truck-print-btn:hover {
            color: #fff;
            background: #1d4ed8;
            border-color: #1d4ed8;
        }

        @media (max-width: 1199px) {
            .truck-stat-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 767px) {
            .summary-value {
                font-size: 1.25rem;
            }

            .truck-title {
                font-size: 1.15rem;
            }

            .truck-stat-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="truck-board-page py-3">
        <div class="container-fluid">

            <div class="top-bar pb-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div>
                        <h2 class="mb-1 fw-bold">Dashboard จัดรถส่งสินค้า</h2>
                        <div class="text-muted">
                            วันที่ส่งสินค้า: <strong>{{ \Carbon\Carbon::parse($shipDate)->format('d/m/Y') }}</strong>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <form method="GET" action="{{ route('dp.dashboard.truck-board') }}"
                            class="d-flex align-items-center gap-1 flex-nowrap" id="truckBoardDateForm">
                            <label for="truckBoardShipDate" class="small fw-semibold mb-0 text-nowrap">วันที่:</label>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnBoardDatePrev"
                                title="วันก่อนหน้า">&laquo;</button>
                            <input type="date" class="form-control form-control-sm" name="ship_date"
                                id="truckBoardShipDate" value="{{ $shipDate }}" style="min-width:150px;"
                                onchange="document.getElementById('truckBoardDateForm').submit();">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnBoardDateNext"
                                title="วันถัดไป">&raquo;</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm text-nowrap" id="btnBoardDateToday"
                                title="วันนี้">วันนี้</button>
                        </form>
                        <span class="small text-muted">
                            <span class="refresh-dot"></span>
                            Refresh ทุก 60 วินาที
                        </span>
                        <div class="btn-group btn-group-sm">
                            <a href="{{ route('dp.dashboard.truck-board.export.print', ['ship_date' => $shipDate]) }}"
                                class="btn btn-outline-primary" target="_blank">
                                <i class="fas fa-print me-1"></i> Export จัดรถทั้งหมด
                            </a>
                            <a href="{{ route('dp.dashboard.truck-board.export.pdf', ['ship_date' => $shipDate]) }}"
                                class="btn btn-outline-primary">PDF</a>
                            <a href="{{ route('dp.dashboard.truck-board.export.excel', ['ship_date' => $shipDate]) }}"
                                class="btn btn-outline-primary">Excel</a>
                        </div>
                        <a href="{{ $refreshUrl }}" class="btn btn-primary btn-sm">
                            <i class="fas fa-rotate-right me-1"></i> Refresh
                        </a>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6 col-md-4 col-xl">
                        <div class="card summary-card h-100">
                            <div class="card-body">
                                <div class="summary-label">จำนวนรถ</div>
                                <div class="summary-value">{{ number_format($summary['truck_count']) }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-6 col-md-4 col-xl">
                        <div class="card summary-card h-100">
                            <div class="card-body">
                                <div class="summary-label">งานทั้งหมด</div>
                                <div class="summary-value">{{ number_format($summary['item_count']) }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-6 col-md-4 col-xl">
                        <div class="card summary-card h-100">
                            <div class="card-body">
                                <div class="summary-label">ขึ้นรถแล้ว</div>
                                <div class="summary-value text-success">{{ number_format($summary['assign_count']) }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-6 col-md-4 col-xl">
                        <div class="card summary-card h-100">
                            <div class="card-body">
                                <div class="summary-label">ยังไม่ขึ้นรถ</div>
                                <div class="summary-value text-warning">{{ number_format($summary['new_count']) }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-6 col-md-4 col-xl">
                        <div class="card summary-card h-100">
                            <div class="card-body">
                                <div class="summary-label">QTY รวม (ตัน)</div>
                                <div class="summary-value">{{ $fmtTon($summary['total_qty']) }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-6 col-md-4 col-xl">
                        <div class="card summary-card h-100">
                            <div class="card-body">
                                <div class="summary-label">น้ำหนักขึ้นรถรวม (ตัน)</div>
                                <div class="summary-value">{{ $fmtTon($summary['total_weight']) }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="board-filter-bar mb-3" id="truckBoardFilters">
                    <div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="truck board filters">
                        <button type="button" class="btn btn-outline-primary active" data-board-filter="all">ทั้งหมด</button>
                        <button type="button" class="btn btn-outline-primary" data-board-filter="open">รอบเปิด</button>
                        <button type="button" class="btn btn-outline-primary" data-board-filter="closed">ปิดแล้ว</button>
                        <button type="button" class="btn btn-outline-primary" data-board-filter="unassigned">ยังไม่จัดรถ</button>
                        <button type="button" class="btn btn-outline-primary" data-board-filter="special">งานพิเศษ</button>
                    </div>
                    <div class="ms-auto" style="min-width:260px;">
                        <input type="search" class="form-control form-control-sm" id="truckBoardSearch"
                            placeholder="ค้นหาทะเบียน / SO / MFG / ลูกค้า / สถานที่ส่ง">
                    </div>
                </div>
            </div>

            @if (($specialGroups ?? collect())->isNotEmpty())
                @php
                    $specialSearchText = collect($specialGroups ?? [])
                        ->flatten(1)
                        ->flatMap(function ($row) {
                            return [
                                $row->dispatch_label ?? '',
                                $row->so_number ?? '',
                                $row->mfg_no ?? '',
                                $row->customer_name ?? '',
                                $row->sales_name ?? '',
                                $row->address ?? '',
                                $row->part_number ?? '',
                                $row->part_desc ?? '',
                            ];
                        })
                        ->filter()
                        ->implode(' ');
                @endphp
                <div class="card mb-4 jsBoardSpecialSection" data-board-status="special"
                    data-board-search="{{ e(mb_strtolower($specialSearchText)) }}">
                    <div class="card-header bg-warning-subtle fw-bold">
                        งานพิเศษวันนี้
                    </div>
                    <div class="card-body">
                        @foreach ($specialGroups as $dispatchType => $specialRows)
                            @php
                                $firstSpecial = $specialRows->first();
                            @endphp
                            <div class="mb-3">
                                <div class="fw-semibold mb-2">
                                    {{ $firstSpecial->dispatch_label ?? $dispatchType }}
                                    <span class="text-muted">({{ number_format($specialRows->count()) }} รายการ)</span>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width:70px;">#</th>
                                                <th style="width:130px;">เวลา</th>
                                                <th style="width:140px;">SO</th>
                                                <th style="width:160px;">MFG</th>
                                                <th>สินค้า</th>
                                                <th style="width:180px;">ลูกค้า / Sales</th>
                                                <th>สถานที่ส่ง</th>
                                                <th style="width:160px;">สถานะ</th>
                                                <th style="width:150px;"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($specialRows as $i => $row)
                                                <tr>
                                                    <td class="text-center fw-bold">{{ $i + 1 }}</td>
                                                    <td>
                                                        {{ !empty($row->window_at) ? \Carbon\Carbon::parse($row->window_at)->format('H:i') : '-' }}
                                                    </td>
                                                    <td>{{ $row->so_number ?: '-' }}</td>
                                                    <td>{{ $row->mfg_no ?: '-' }}</td>
                                                    <td>
                                                        <div class="fw-semibold">{{ $row->part_number ?: '-' }}</div>
                                                        <div class="small">{{ $row->part_desc ?: '-' }}</div>
                                                        <div class="small text-muted">QTY {{ $fmtTon($row->qty ?? 0) }} ตัน</div>
                                                    </td>
                                                    <td>
                                                        <div class="fw-semibold">{{ $row->customer_name ?: '-' }}</div>
                                                        <div class="small text-muted">{{ $row->sales_name ?: '-' }}</div>
                                                    </td>
                                                    <td>{{ $row->address ?: '-' }}</td>
                                                    <td>
                                                        <div>{{ $row->dp_status ?: '-' }}</div>
                                                        @if (!empty($row->special_remark))
                                                            <div class="small text-muted">{{ $row->special_remark }}</div>
                                                        @endif
                                                    </td>
                                                    <td class="text-center">
                                                        @if ($row->is_open && strtoupper((string) $row->dispatch_type) !== 'POSTPONED')
                                                            <form method="POST"
                                                                action="{{ route('dp.inquiry.special-dispatch.close', ['ordId' => $row->ord_id]) }}"
                                                                onsubmit="return confirm('ยืนยันปิดงานพิเศษนี้?');">
                                                                @csrf
                                                                <button class="btn btn-sm btn-outline-success">ปิดงาน</button>
                                                            </form>
                                                        @else
                                                            <span class="text-muted small">-</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @forelse ($truckGroups as $truck)
                @php
                    $truckSearchText = collect([
                        $truck->truck_label,
                        $truck->driver_name,
                        $truck->driver_phone,
                        $truck->helper_names,
                        $truck->remark,
                    ])->merge($truck->rows->flatMap(function ($row) {
                        return [
                            $row->so_number ?? '',
                            $row->mfg_no ?? '',
                            $row->customer_name ?? '',
                            $row->sales_name ?? '',
                            $row->address ?? '',
                            $row->part_number ?? '',
                            $row->part_desc ?? '',
                        ];
                    }))->filter()->implode(' ');
                    $boardStatus = $truck->is_unassigned ? 'unassigned' : ($truck->is_closed ? 'closed' : 'open');
                @endphp
                <div class="card truck-card {{ $truck->is_unassigned ? 'unassigned' : '' }} mb-4 jsBoardTruckCard"
                    data-board-status="{{ $boardStatus }}"
                    data-board-search="{{ e(mb_strtolower($truckSearchText)) }}">
                    <div class="truck-header">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                            <div>
                                <h3 class="truck-title mb-0">
                                    <i class="fas fa-truck me-2"></i>{{ $truck->truck_label }}
                                </h3>

                                <div class="truck-subtitle">
                                    {{ $truck->is_unassigned ? 'กลุ่มงานที่ยังไม่ assign รถ' : 'ข้อมูลรถและรายการส่งของ' }}
                                </div>

                                <div class="truck-meta">
                                    @if (!$truck->is_unassigned)
                                        <span><strong>คนขับ:</strong>
                                            {{ $truck->driver_name !== '' ? $truck->driver_name : '-' }}</span>
                                        <span><strong>โทร:</strong>
                                            {{ $truck->driver_phone !== '' ? $truck->driver_phone : '-' }}</span>
                                        <span><strong>ความยาวรถ:</strong>
                                            {{ filled($truck->car_length) ? $truck->car_length : '-' }}</span>
                                        <span><strong>เด็กรถ:</strong>
                                            {{ $truck->helper_names !== '' ? $truck->helper_names : '-' }}</span>
                                    @endif

                                    @if (!$truck->is_unassigned && $truck->remark !== '')
                                        <span><strong>หมายเหตุรถ:</strong> {{ $truck->remark }}</span>
                                    @endif
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-2">
                                @if (!$truck->is_unassigned)
                                    <span class="truck-badge {{ $truck->is_closed ? 'status-closed' : 'status-open' }}">
                                        {{ $truck->is_closed ? 'ปิดรอบแล้ว' : 'รอบเปิดอยู่' }}
                                    </span>
                                @endif
                                <span class="truck-badge metric-muted">รายการ {{ number_format($truck->item_count) }}</span>
                                <span class="truck-badge metric">QTY {{ $fmtTon($truck->total_qty) }} ตัน</span>
                                <span class="truck-badge metric">ขึ้นรถ {{ $fmtTon($truck->total_assigned) }} ตัน</span>
                                @if (!$truck->is_unassigned && $truck->max_load > 0)
                                    @php
                                        $remainingRatio = (float) $truck->max_load > 0
                                            ? ((float) $truck->remaining_load / (float) $truck->max_load)
                                            : 1;
                                        $remainingClass = $remainingRatio < 0.1
                                            ? 'metric-danger'
                                            : ($remainingRatio < 0.25 ? 'metric-warning' : 'metric');
                                    @endphp
                                    <span class="truck-badge metric-muted">Max {{ $fmtTon($truck->max_load) }} ตัน</span>
                                    <span class="truck-badge {{ $remainingClass }}">คงเหลือ {{ $fmtTon($truck->remaining_load) }} ตัน</span>
                                @endif
                                @if (!$truck->is_unassigned && !$truck->is_closed)
                                    <form method="POST" action="{{ route('dp.dashboard.truck-board.trip.close') }}"
                                        onsubmit="return confirm('ยืนยันปิดรอบรถนี้?');">
                                        @csrf
                                        <input type="hidden" name="truck_source" value="{{ $truck->truck_source }}">
                                        <input type="hidden" name="truck_id" value="{{ $truck->truck_id }}">
                                        <input type="hidden" name="manual_plate_no" value="{{ $truck->manual_plate_no }}">
                                        <input type="hidden" name="ship_date" value="{{ $shipDate }}">
                                        <input type="hidden" name="trip_no" value="{{ $truck->trip_no }}">
                                        <button type="submit" class="btn btn-sm fw-semibold truck-close-btn">
                                            <i class="fas fa-check me-1"></i> ปิดรอบรถ
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="truck-stat">
                        <div class="truck-stat-grid">
                            <div class="truck-stat-box">
                                <span class="label">จำนวนรายการ</span>
                                <span class="value">{{ number_format($truck->item_count) }}</span>
                            </div>
                            <div class="truck-stat-box">
                                <span class="label">QTY รวม</span>
                                <span class="value">{{ $fmtTon($truck->total_qty) }} ตัน</span>
                            </div>
                            <div class="truck-stat-box">
                                <span class="label">ขึ้นรถรวม</span>
                                <span class="value">{{ $fmtTon($truck->total_assigned) }} ตัน</span>
                            </div>
                            <div class="truck-stat-box">
                                <span class="label">คงเหลือความจุ</span>
                                <span class="value">
                                    {{ !$truck->is_unassigned && !is_null($truck->remaining_load) ? $fmtTon($truck->remaining_load) . ' ตัน' : '-' }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="truck-table-wrap table-responsive">
                        <table class="table table-sm table-bordered align-middle truck-table">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">#</th>
                                    <th style="width: 120px;">วันที่ / เวลา</th>
                                    <th style="width: 140px;">SO</th>
                                    <th style="width: 120px;">Type</th>
                                    <th class="col-main">สินค้า</th>
                                    <th style="width: 140px;">MFG</th>
                                    <th style="width: 130px;">ระบุเส้น/ชิ้น</th>
                                    <th style="width: 130px;">Qty / ขึ้นรถ (ตัน)</th>
                                    <th style="width: 130px;">Stock FG</th>
                                    <th style="width: 160px;">ลูกค้า / Sales</th>
                                    <th class="address-text">สถานที่ส่ง</th>
                                    <th style="width: 180px;">เอกสารแนบ</th>
                                    <th style="width: 180px;">หมายเหตุ</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($truck->rows as $i => $row)
                                    <tr>
                                        <td class="text-center fw-bold">{{ $i + 1 }}</td>

                                        <td>
                                            <div>
                                                {{ !empty($row->ship_posted_at) ? \Carbon\Carbon::parse($row->ship_posted_at)->format('d/m/Y') : '-' }}
                                            </div>
                                            <div class="mini-text">
                                                {{ !empty($row->window_at) ? \Carbon\Carbon::parse($row->window_at)->format('H:i') : '-' }}
                                            </div>
                                        </td>

                                        <td>
                                            <div class="fw-bold">{{ $row->so_number ?: '-' }}</div>
                                            <div class="mini-text">Rev {{ (int) ($row->revision_number ?? 0) }}</div>
                                        </td>

                                        <td>
                                            <span class="fw-bold">{{ $row->type_display ?: '-' }}</span>
                                        </td>

                                        <td>
                                            <div class="fw-bold">{{ $row->part_number ?: '-' }}</div>
                                            <div>{{ $row->part_desc ?: '-' }}</div>


                                        </td>
                                        <td>{{ $row->mfg_no ?: '-' }}</td>

                                        <td>
                                            @if (!is_null($row->line_qty_display) && (float) $row->line_qty_display > 0)
                                                <div class="mini-text fw-bold text-danger">
                                                    {{ ($row->line_qty_unit ?? 'เส้น') === 'ชิ้น' ? 'ชิ้น' : 'ระบุเส้น' }} : {{ number_format((float) $row->line_qty_display, 0) }} {{ $row->line_qty_unit ?? 'เส้น' }}
                                                </div>
                                            @else
                                                <span class="text-muted">-</span>
                                            @endif
                                        </td>

                                        <td>
                                            <div class="value-strong">{{ $fmtTon($row->qty ?? 0) }}
                                            </div>
                                            <div class="mini-text">Assigned:
                                                {{ $fmtTon($row->display_weight ?? 0) }}</div>
                                        </td>

                                        <td class="stock-text">
                                            {{ number_format((float) ($row->stock_fg ?? 0), 3) }}
                                        </td>

                                        <td>
                                            <div class="fw-bold">{{ $row->customer_name ?: '-' }}</div>
                                            <div class="mini-text">{{ $row->sales_name ?: '-' }}</div>
                                        </td>

                                        <td>{{ $row->address ?: '-' }}</td>

                                        <td class="docs-text">
                                            {{ $row->attach_docs_text !== '' ? $row->attach_docs_text : '-' }}
                                        </td>

                                        <td>
                                            {{ trim((string) ($row->edit_remark ?: $row->remark ?: '-')) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @empty
                <div class="alert alert-warning">
                    ไม่พบข้อมูลสำหรับวันที่ {{ \Carbon\Carbon::parse($shipDate)->format('d/m/Y') }}
                </div>
            @endforelse
        </div>
    </div>

    <script>
        (function truckBoardFilters() {
            const filterButtons = Array.from(document.querySelectorAll('[data-board-filter]'));
            const searchInput = document.getElementById('truckBoardSearch');
            const cards = Array.from(document.querySelectorAll('.jsBoardTruckCard'));
            const specialSection = document.querySelector('.jsBoardSpecialSection');
            let activeFilter = 'all';

            function applyFilters() {
                const q = String(searchInput?.value || '').trim().toLowerCase();

                cards.forEach((card) => {
                    const status = card.dataset.boardStatus || '';
                    const text = card.dataset.boardSearch || '';
                    const matchFilter = activeFilter === 'all' || activeFilter === status;
                    const matchSearch = q === '' || text.includes(q);
                    card.classList.toggle('d-none', !(matchFilter && matchSearch));
                });

                if (specialSection) {
                    const showSpecial = activeFilter === 'all' || activeFilter === 'special';
                    const specialText = specialSection.dataset.boardSearch || '';
                    const matchSpecialSearch = q === '' || specialText.includes(q);
                    specialSection.classList.toggle('d-none', !(showSpecial && matchSpecialSearch));
                }
            }

            filterButtons.forEach((btn) => {
                btn.addEventListener('click', () => {
                    activeFilter = btn.dataset.boardFilter || 'all';
                    filterButtons.forEach((x) => x.classList.toggle('active', x === btn));
                    applyFilters();
                });
            });

            if (searchInput) {
                searchInput.addEventListener('input', applyFilters);
            }
        })();

        (function truckBoardDateNav() {
            const form = document.getElementById('truckBoardDateForm');
            const input = document.getElementById('truckBoardShipDate');
            const btnPrev = document.getElementById('btnBoardDatePrev');
            const btnNext = document.getElementById('btnBoardDateNext');
            const btnToday = document.getElementById('btnBoardDateToday');

            if (!form || !input) return;

            function shift(days) {
                const cur = input.value ? new Date(input.value + 'T00:00:00') : new Date();
                cur.setDate(cur.getDate() + days);
                const y = cur.getFullYear();
                const m = String(cur.getMonth() + 1).padStart(2, '0');
                const d = String(cur.getDate()).padStart(2, '0');
                input.value = `${y}-${m}-${d}`;
                form.submit();
            }

            function today() {
                const t = new Date();
                const y = t.getFullYear();
                const m = String(t.getMonth() + 1).padStart(2, '0');
                const d = String(t.getDate()).padStart(2, '0');
                input.value = `${y}-${m}-${d}`;
                form.submit();
            }

            if (btnPrev) btnPrev.addEventListener('click', () => shift(-1));
            if (btnNext) btnNext.addEventListener('click', () => shift(1));
            if (btnToday) btnToday.addEventListener('click', today);
        })();

        setTimeout(() => {
            window.location.reload();
        }, 60000);
    </script>
@endsection
