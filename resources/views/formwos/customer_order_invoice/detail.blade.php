@extends('layouts.layout')

@section('title', 'Customer SO vs Invoice Detail')
@section('page-title', 'Customer SO vs Invoice Detail')

@section('content')
    @php
        $fmt = fn ($value) => number_format((float) $value, 2);
        $fmtPercent = fn ($value) => $value === null ? '-' : number_format((float) $value, 1) . '%';
    @endphp

    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
            <div>
                <h4 class="mb-1">{{ $customer->name }}</h4>
                <div class="text-muted">
                    {{ $customer->customernumber }} · ประวัติ Sales Order และ Invoice ทั้งหมด
                </div>
                <div class="mt-2 d-flex flex-wrap gap-2">
                    <span class="badge bg-light text-dark border">
                        Purchase Grade: {{ trim((string) $customer->purchase_grade) ?: '-' }}
                    </span>
                    <span class="badge bg-light text-dark border">
                        Payment Grade: {{ trim((string) $customer->payment_grade) ?: '-' }}
                    </span>
                </div>
            </div>
            <a class="btn btn-outline-secondary" href="{{ route('wos.customer_order_invoice.index', $filters) }}">
                <i class="fa-solid fa-arrow-left me-1"></i> กลับหน้าสรุป
            </a>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
                <div class="text-muted small">Sales Order</div>
                <div class="fs-4 fw-bold">{{ number_format($totals['sales_orders']) }}</div>
            </div></div></div>
            <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
                <div class="text-muted small">น้ำหนัก SO</div>
                <div class="fs-4 fw-bold">{{ $fmt($totals['order_weight']) }} kg</div>
            </div></div></div>
            <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
                <div class="text-muted small">น้ำหนัก Invoice</div>
                <div class="fs-4 fw-bold text-success">{{ $fmt($totals['invoice_weight']) }} kg</div>
            </div></div></div>
            <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
                <div class="text-muted small">ออก Invoice แล้ว</div>
                <div class="fs-4 fw-bold text-primary">{{ $fmtPercent($totals['invoice_percent']) }}</div>
            </div></div></div>
        </div>

        <div class="accordion" id="salesOrderAccordion">
            @forelse($orders as $index => $order)
                @php
                    $invoiceItems = $invoicesByOrder->get($order['ordnumber'], collect());
                    $percent = $order['invoice_percent'] === null ? null : (float) $order['invoice_percent'];
                @endphp
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button {{ $index > 0 ? 'collapsed' : '' }}" type="button"
                            data-bs-toggle="collapse" data-bs-target="#order-{{ $index }}">
                            <div class="row g-2 align-items-center w-100 me-3">
                                <div class="col-md-3">
                                    <strong>{{ $order['ordnumber'] }}</strong>
                                    <div class="small text-muted">
                                        {{ \Carbon\Carbon::parse($order['order_date'])->format('d/m/Y') }}
                                    </div>
                                </div>
                                <div class="col-md-3 text-md-end">SO: <strong>{{ $fmt($order['order_weight']) }} kg</strong></div>
                                <div class="col-md-3 text-md-end">Invoice: <strong class="text-success">{{ $fmt($order['invoice_weight']) }} kg</strong></div>
                                <div class="col-md-3 text-md-end">
                                    <span class="badge {{ $percent !== null && $percent >= 100 ? 'bg-success' : 'bg-primary' }}">
                                        {{ $fmtPercent($percent) }}
                                    </span>
                                </div>
                            </div>
                        </button>
                    </h2>
                    <div id="order-{{ $index }}" class="accordion-collapse collapse {{ $index === 0 ? 'show' : '' }}"
                        data-bs-parent="#salesOrderAccordion">
                        <div class="accordion-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered mb-0">
                                    <thead class="table-light">
                                        <tr class="text-center">
                                            <th>Invoice No.</th>
                                            <th>วันที่ Invoice</th>
                                            <th>น้ำหนัก Invoice (kg)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($invoiceItems as $invoice)
                                            <tr>
                                                <td class="text-center fw-semibold">{{ $invoice->invnumber }}</td>
                                                <td class="text-center">{{ \Carbon\Carbon::parse($invoice->invoice_date)->format('d/m/Y') }}</td>
                                                <td class="text-end">{{ $fmt($invoice->invoice_weight) }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="3" class="text-center text-muted py-3">Sales Order นี้ยังไม่มี Invoice</td></tr>
                                        @endforelse
                                    </tbody>
                                    <tfoot class="table-light fw-bold">
                                        <tr>
                                            <td colspan="2">รวม Invoice ของ {{ $order['ordnumber'] }}</td>
                                            <td class="text-end">{{ $fmt($order['invoice_weight']) }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="card card-body text-center text-muted py-5">ไม่พบ Sales Order ของลูกค้าในเดือนนี้</div>
            @endforelse
        </div>

        @if($orders->hasPages())
            <div class="mt-3">
                {{ $orders->links() }}
            </div>
        @endif
    </div>
@endsection
