@extends('layouts.layout')

@section('title', 'Customer SO vs Invoice')
@section('page-title', 'Customer SO vs Invoice')

@push('styles')
    <style>
        .comparison-kpi { border: 0; box-shadow: 0 .25rem 1rem rgba(15, 23, 42, .08); }
        .comparison-kpi .value { font-size: 1.45rem; font-weight: 700; color: #0f172a; }
        .comparison-table thead th { position: sticky; top: 0; z-index: 5; white-space: nowrap; }
        .comparison-table td { vertical-align: middle; }
        .comparison-table .customer-name { min-width: 260px; }
    </style>
@endpush

@section('content')
    @php
        $fmt = fn ($value) => number_format((float) $value, 2);
        $fmtPercent = fn ($value) => $value === null ? '-' : number_format((float) $value, 1) . '%';
        $tierClasses = ['A' => 'success', 'B' => 'primary', 'C' => 'secondary'];
    @endphp

    <div class="container-fluid py-3">
        <div class="mb-3">
            <h4 class="mb-1">Customer Ranking & Tier</h4>
            <div class="text-muted">
                Tier A ต้องมีน้ำหนัก Invoice อยู่ใน Top 20% และสั่งอย่างน้อย 6 เดือน
                จากช่วง 12 เดือนล่าสุด ส่วน Tier C คือไม่ได้สั่งเกิน 3 ปี
            </div>
        </div>

        <form method="GET" action="{{ route('wos.customer_order_invoice.index') }}" class="card card-body mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-3">
                    <label class="form-label">Tier</label>
                    <select name="tier" class="form-select">
                        <option value="">ทุก Tier</option>
                        <option value="A" @selected($filters['tier'] === 'A')>A — Top 20% + สั่ง ≥ 6 เดือน</option>
                        <option value="B" @selected($filters['tier'] === 'B')>B — Active ที่เหลือ</option>
                        <option value="C" @selected($filters['tier'] === 'C')>C — ไม่ได้สั่งเกิน 3 ปี</option>
                    </select>
                </div>
                <div class="col-12 col-md-7">
                    <label class="form-label">ค้นหาลูกค้า</label>
                    <input type="text" name="customer" class="form-control"
                        value="{{ $filters['customer'] }}" placeholder="ชื่อหรือรหัสลูกค้า">
                </div>
                <div class="col-12 col-md-2 d-grid">
                    <button class="btn btn-primary">
                        <i class="fa-solid fa-magnifying-glass me-1"></i> แสดงผล
                    </button>
                </div>
            </div>
        </form>

        <div class="row g-3 mb-3">
            <div class="col-6 col-xl-2">
                <div class="card comparison-kpi h-100"><div class="card-body">
                    <div class="text-muted small">ลูกค้าที่แสดง</div>
                    <div class="value">{{ number_format($totals['customers']) }}</div>
                </div></div>
            </div>
            @foreach(['A', 'B', 'C'] as $tierCode)
                <div class="col-6 col-xl-2">
                    <div class="card comparison-kpi h-100 border-start border-4 border-{{ $tierClasses[$tierCode] }}">
                        <div class="card-body">
                            <div class="text-muted small">Tier {{ $tierCode }}</div>
                            <div class="value text-{{ $tierClasses[$tierCode] }}">
                                {{ number_format($tierCounts[$tierCode]) }}
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
            <div class="col-12 col-md-6 col-xl-2">
                <div class="card comparison-kpi h-100"><div class="card-body">
                    <div class="text-muted small">น้ำหนัก SO 12 เดือน</div>
                    <div class="value">{{ $fmt($totals['order_weight_12m']) }} kg</div>
                </div></div>
            </div>
            <div class="col-12 col-md-6 col-xl-2">
                <div class="card comparison-kpi h-100"><div class="card-body">
                    <div class="text-muted small">น้ำหนัก Invoice 12 เดือน</div>
                    <div class="value text-success">{{ $fmt($totals['invoice_weight_12m']) }} kg</div>
                </div></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between gap-2">
                <strong>อันดับจาก Invoice 12 เดือนล่าสุด</strong>
                <span class="text-muted small">
                    ช่วง {{ \Carbon\Carbon::parse($rollingFrom)->format('d/m/Y') }}
                    – {{ \Carbon\Carbon::parse($rollingTo)->format('d/m/Y') }}
                    · Tier C ก่อน {{ \Carbon\Carbon::parse($cutoffDate)->format('d/m/Y') }}
                </span>
            </div>
            <div class="table-responsive" style="max-height: calc(100vh - 390px);">
                <table class="table table-sm table-hover table-bordered mb-0 comparison-table">
                    <thead class="table-light text-center">
                        <tr>
                            <th>อันดับ</th>
                            <th>Tier</th>
                            <th>รหัสลูกค้า</th>
                            <th class="customer-name">ลูกค้า</th>
                            <th>Purchase Grade</th>
                            <th>Payment Grade</th>
                            <th>เดือนที่สั่ง / 12</th>
                            <th>SO 12 เดือน</th>
                            <th>Invoice 12 เดือน (kg)</th>
                            <th>Invoice ทั้งหมด (kg)</th>
                            <th>สั่งครั้งแรก</th>
                            <th>สั่งล่าสุด</th>
                            <th>Invoice ล่าสุด</th>
                            <th>รายละเอียด</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            @php
                                $remaining = (float) $row->remaining_weight;
                                $percent = $row->invoice_percent === null ? null : (float) $row->invoice_percent;
                            @endphp
                            <tr>
                                <td class="text-center fw-semibold">{{ $row->rank ?? '-' }}</td>
                                <td class="text-center">
                                    <span class="badge bg-{{ $tierClasses[$row->tier] }}">Tier {{ $row->tier }}</span>
                                </td>
                                <td class="text-center">{{ $row->customernumber }}</td>
                                <td class="fw-semibold">{{ $row->customer_name }}</td>
                                <td class="text-center">{{ trim((string) $row->purchase_grade) ?: '-' }}</td>
                                <td class="text-center">{{ trim((string) $row->payment_grade) ?: '-' }}</td>
                                <td class="text-center fw-semibold">{{ number_format((int) $row->active_order_months_12m) }} / 12</td>
                                <td class="text-end">{{ number_format((int) $row->sales_order_count_12m) }}</td>
                                <td class="text-end text-success fw-semibold">{{ $fmt($row->invoice_weight_12m) }}</td>
                                <td class="text-end">{{ $fmt($row->invoice_weight) }}</td>
                                <td class="text-center">
                                    {{ $row->first_order_date ? \Carbon\Carbon::parse($row->first_order_date)->format('d/m/Y') : '-' }}
                                </td>
                                <td class="text-center {{ $row->tier === 'C' ? 'text-danger fw-semibold' : '' }}">
                                    {{ $row->last_order_date ? \Carbon\Carbon::parse($row->last_order_date)->format('d/m/Y') : '-' }}
                                </td>
                                <td class="text-center">
                                    {{ $row->latest_invoice_date ? \Carbon\Carbon::parse($row->latest_invoice_date)->format('d/m/Y') : '-' }}
                                </td>
                                <td class="text-center">
                                    <a class="btn btn-sm btn-outline-primary"
                                        href="{{ route('wos.customer_order_invoice.detail', [
                                            'customerId' => $row->customer_id,
                                            'customer' => $filters['customer'],
                                            'tier' => $filters['tier'],
                                        ]) }}">
                                        ดู SO / Invoice
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="14" class="text-center text-muted py-5">ไม่พบข้อมูลตามเงื่อนไข</td></tr>
                        @endforelse
                    </tbody>
                    @if($rows)
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td colspan="7">รวมรายการที่แสดง</td>
                                <td class="text-end">{{ number_format($totals['sales_orders_12m']) }}</td>
                                <td class="text-end">{{ $fmt($totals['invoice_weight_12m']) }}</td>
                                <td class="text-end">{{ $fmt($totals['invoice_weight']) }}</td>
                                <td colspan="4"></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>

        @if($rows->hasPages())
            <div class="mt-3">
                {{ $rows->links() }}
            </div>
        @endif
    </div>
@endsection
