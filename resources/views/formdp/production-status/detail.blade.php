@extends('layouts.layout')

@section('title', 'Production Status Detail')
@section('page-title', 'Production Status Detail')

@section('content')
    @php
        $fmtDate = fn($value) => $value ? \Carbon\Carbon::parse($value)->format('d/m/Y') : '-';
        $stepClass = fn($status) => match ($status) {
            'Completed' => 'success',
            'In Progress' => 'warning',
            'Current' => 'primary',
            default => 'secondary',
        };
        $movementClass = fn($status) => match ($status) {
            'งานนิ่ง', 'ไม่พบ Routing' => 'danger',
            'ยังไม่เริ่ม' => 'warning',
            'เสร็จแล้ว' => 'success',
            default => 'primary',
        };
    @endphp

    <style>
        .pst-wrap {
            background: #f5f7fa;
            border-radius: 8px;
            padding: 16px;
        }

        .pst-panel {
            background: #fff;
            border: 1px solid #dfe5ec;
            border-radius: 8px;
            overflow: hidden;
        }

        .pst-head {
            padding: 12px 16px;
            border-bottom: 1px solid #e8edf2;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            font-weight: 700;
        }

        .pst-meta {
            color: #667085;
            font-size: .86rem;
        }

        .pst-card {
            background: #fff;
            border: 1px solid #dfe5ec;
            border-radius: 8px;
            padding: 14px;
            height: 100%;
        }

        .pst-card .label {
            color: #667085;
            font-size: .82rem;
        }

        .pst-card .value {
            font-size: 1.05rem;
            font-weight: 700;
            margin-top: 4px;
        }

        .pst-table th {
            background: #edf4ff;
            white-space: nowrap;
        }

        .pst-table td {
            vertical-align: middle;
        }

        .num {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
    </style>

    <div class="pst-wrap">
        @if (!empty($dataError))
            <div class="alert alert-warning">
                <div class="fw-semibold">Live data unavailable</div>
                <div class="small">{{ $dataError }}</div>
            </div>
        @endif

        <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h5 class="mb-1">{{ $mfgNo }}</h5>
                <div class="pst-meta">{{ $site ?: '-' }} | {{ $tracking->source_note ?? '' }}</div>
                @if (in_array($tracking->delivery_mode ?? '', ['ACID', 'SPECIAL'], true))
                    <div class="mt-1">
                        <span class="badge bg-{{ $tracking->delivery_mode_badge_class ?? 'warning text-dark border' }}"
                            title="{{ $tracking->delivery_mode_note ?? 'งานส่งกัดกรด' }}">
                            {{ $tracking->delivery_mode_label ?? 'งานกัดกรด' }}
                        </span>
                    </div>
                @endif
            </div>
            <a class="btn btn-outline-secondary" href="{{ $returnUrl ?? route('dp.production-status') }}">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-2">
                <div class="pst-card">
                    <div class="label">ขั้นตอนปัจจุบัน</div>
                    <div class="value">{{ $tracking->current_process ?: '-' }}</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="pst-card">
                    <div class="label">ความคืบหน้า</div>
                    <div class="value">{{ number_format((float) ($tracking->progress_pct ?? 0), 1) }}%</div>
                    <div class="progress mt-2" style="height:8px;">
                        <div class="progress-bar"
                            style="width: {{ max(0, min(100, (int) ($tracking->progress_pct ?? 0))) }}%"></div>
                    </div>
                    <div class="pst-meta mt-1">{{ $tracking->step_text ?? '' }}</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="pst-card">
                    <div class="label">สถานะ DP</div>
                    <div class="value">{{ $tracking->dp_status ?? '-' }}</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="pst-card">
                    <div class="label">สถานะผลิต</div>
                    <div class="value">{{ $tracking->delivery_status ?? '-' }}</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="pst-card">
                    <div class="label">เคลื่อนไหวล่าสุด</div>
                    <div class="value">
                        <span
                            class="badge bg-{{ $movementClass($tracking->movement_status ?? '') }}">{{ $tracking->movement_status ?? '-' }}</span>
                    </div>
                    <div class="pst-meta mt-1">
                        {{ !empty($tracking->last_receive_at) ? \Carbon\Carbon::parse($tracking->last_receive_at)->format('d/m/Y H:i') : '-' }}
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="pst-card">
                    <div class="label">ความเสี่ยง</div>
                    <div class="value">{{ $tracking->risk_display ?? ($tracking->risk_status ?? '-') }}</div>
                </div>
            </div>
        </div>

        @if (($tracking->risk_status ?? '') === 'NO_ROUTE')
            <div class="alert alert-warning">
                <div class="fw-semibold">ไม่พบ Routing ใน ManuCost</div>
                <div class="small">รายการนี้มาจาก Delivery Plan แต่ยังหา Workorder/Route ฝั่งผลิตไม่เจอ กรุณาตรวจสอบ MFG
                    No. และ site</div>
            </div>
        @endif

        <div class="pst-panel mb-3">
            <div class="pst-head">
                <span>สรุปงานผลิต</span>
                <span class="pst-meta">{{ $tracking->source_note ?? '' }}</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 pst-table">
                    <thead>
                        <tr>
                            <th>Mfg No.</th>
                            <th>Site</th>
                            <th>Customer</th>
                            <th>Item</th>
                            <th class="num">Qty</th>
                            <th>Sale By</th>
                            <th>DP Status</th>
                            <th>วันส่งตามแผน</th>
                            <th>Due Date</th>
                            <th>Last Receive</th>
                            <th>Remaining</th>
                            <th>NCR</th>
                            <th>Machine Down</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>{{ $tracking->mfg_no ?? '-' }}</td>
                            <td>{{ $tracking->site ?? '-' }}</td>
                            <td>{{ $tracking->customer ?? '-' }}</td>
                            <td>{{ $tracking->item ?? '-' }}</td>
                            <td class="num">{{ $tracking->qty_display ?? '-' }}</td>
                            <td>
                                <span class="badge bg-light text-dark border">{{ $tracking->sale_type ?? '-' }}</span>
                                @if (in_array($tracking->delivery_mode ?? '', ['ACID', 'SPECIAL'], true))
                                    <div class="mt-1">
                                        <span class="badge bg-{{ $tracking->delivery_mode_badge_class ?? 'warning text-dark border' }}">
                                            {{ $tracking->delivery_mode_label ?? 'งานกัดกรด' }}
                                        </span>
                                    </div>
                                @endif
                            </td>
                            <td>{{ $tracking->dp_status ?? '-' }}</td>
                            <td>{{ $fmtDate($tracking->ship_date ?? ($tracking->due_date ?? null)) }}</td>
                            <td>{{ $fmtDate($tracking->dp_due_date ?? null) }}</td>
                            <td>
                                <div>
                                    {{ !empty($tracking->last_receive_at) ? \Carbon\Carbon::parse($tracking->last_receive_at)->format('d/m/Y H:i') : '-' }}
                                </div>
                                <div class="pst-meta">{{ $tracking->last_receive_process ?? '-' }}</div>
                            </td>
                            <td>{{ $tracking->remaining_process->isNotEmpty() ? $tracking->remaining_process->implode('->') : '-' }}
                            </td>
                            <td>{{ number_format((int) ($tracking->ncr_count ?? 0)) }}</td>
                            <td>{{ number_format((int) ($tracking->breakdown_count ?? 0)) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="pst-panel">
            <div class="pst-head">
                <span>ขั้นตอนการผลิต</span>
                <span class="pst-meta">{{ $steps->count() }} step(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 pst-table">
                    <thead>
                        <tr>
                            <th>Seq</th>
                            <th>Work Center</th>
                            <th>Size In</th>
                            <th>Size Out</th>
                            <th>Status</th>
                            <th class="num">Received Qty</th>
                            <th>Machine</th>
                            <th>Received Time</th>
                            <th class="num">NCR Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($steps as $step)
                            <tr>
                                <td>{{ $step->seq }}</td>
                                <td>
                                    <div class="fw-semibold">{{ $step->code ?: '-' }}</div>
                                    <div class="pst-meta">{{ $step->name ?: '' }}</div>
                                </td>
                                <td>{{ $step->size_in ?? '-' }}</td>
                                <td>{{ $step->size_out ?? '-' }}</td>
                                <td><span class="badge bg-{{ $stepClass($step->status) }}">{{ $step->status }}</span></td>
                                <td class="num">
                                    <div>{{ number_format((float) $step->received_qty, 2) }}</div>
                                    @if (($step->target_qty ?? 0) > 0)
                                        <div class="pst-meta">/ {{ number_format((float) $step->target_qty, 2) }}</div>
                                    @endif
                                </td>
                                <td>{{ $step->machine ?: '-' }}</td>
                                <td>{{ $step->received_time ? \Carbon\Carbon::parse($step->received_time)->format('d/m/Y H:i') : '-' }}
                                </td>
                                <td class="num">{{ number_format((int) $step->ncr_count) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">ไม่พบ Routing การผลิตสำหรับ MFG นี้
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
@endsection
