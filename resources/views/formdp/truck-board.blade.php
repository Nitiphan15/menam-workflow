@extends('layouts.layout')
@section('page-title', 'Truck Dashboard')
@section('title', 'Truck Dashboard')

@section('content')
    @php
        $refreshUrl = route('dp.dashboard.truck-board', ['ship_date' => $shipDate]);
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
            padding: 4px 10px;
            border-radius: 999px;
            font-size: .78rem;
            font-weight: 700;
            background: rgba(255, 255, 255, .16);
            border: 1px solid rgba(255, 255, 255, .18);
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
                        <h2 class="mb-1 fw-bold">Dashboard Loading by Truck</h2>
                        <div class="text-muted">
                            วันที่ส่งสินค้า: <strong>{{ \Carbon\Carbon::parse($shipDate)->format('d/m/Y') }}</strong>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-2">
                        <span class="small text-muted">
                            <span class="refresh-dot"></span>
                            Refresh ทุก 60 วินาที
                        </span>
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
                                <div class="summary-label">QTY รวม (KG)</div>
                                <div class="summary-value">{{ number_format($summary['total_qty'], 3) }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-6 col-md-4 col-xl">
                        <div class="card summary-card h-100">
                            <div class="card-body">
                                <div class="summary-label">น้ำหนักขึ้นรถรวม (KG)</div>
                                <div class="summary-value">{{ number_format($summary['total_weight'], 3) }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @forelse ($truckGroups as $truck)
                <div class="card truck-card {{ $truck->is_unassigned ? 'unassigned' : '' }} mb-4">
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
                                    @endif

                                    @if (!$truck->is_unassigned && $truck->remark !== '')
                                        <span><strong>หมายเหตุรถ:</strong> {{ $truck->remark }}</span>
                                    @endif
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-2">
                                <span class="truck-badge">รายการ {{ number_format($truck->item_count) }}</span>
                                <span class="truck-badge">QTY {{ number_format($truck->total_qty, 3) }} KG</span>
                                <span class="truck-badge">ขึ้นรถ {{ number_format($truck->total_assigned, 3) }} KG</span>
                                @if (!$truck->is_unassigned && $truck->max_load > 0)
                                    <span class="truck-badge">Max {{ number_format($truck->max_load, 3) }} KG</span>
                                    <span class="truck-badge">คงเหลือ {{ number_format($truck->remaining_load, 3) }}
                                        KG</span>
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
                                <span class="value">{{ number_format($truck->total_qty, 3) }} KG</span>
                            </div>
                            <div class="truck-stat-box">
                                <span class="label">ขึ้นรถรวม</span>
                                <span class="value">{{ number_format($truck->total_assigned, 3) }} KG</span>
                            </div>
                            <div class="truck-stat-box">
                                <span class="label">คงเหลือความจุ</span>
                                <span class="value">
                                    {{ !$truck->is_unassigned && !is_null($truck->remaining_load) ? number_format($truck->remaining_load, 3) . ' KG' : '-' }}
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
                                    <th style="width: 130px;">Qty / Assigned</th>
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
                                            <div class="value-strong">{{ number_format((float) ($row->qty ?? 0), 3) }}
                                            </div>
                                            <div class="mini-text">Assigned:
                                                {{ number_format((float) ($row->display_weight ?? 0), 3) }}</div>
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
        setTimeout(() => {
            window.location.reload();
        }, 60000);
    </script>
@endsection
