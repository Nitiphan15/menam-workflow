@extends('layouts.layout')

@section('content')
    <div class="container">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
                <h4 class="mb-0">{{ $day->toDateString() }}</h4>
                <div class="text-muted">Daily plan</div>
            </div>
            <a class="btn btn-outline-secondary" href="{{ route('dp.calendar', ['ym' => $day->format('Y-m')]) }}">
                Back to Calendar
            </a>
        </div>

        @if (session('ok'))
            <div class="alert alert-success">{{ session('ok') }}</div>
        @endif

        <div class="card mb-3">
            <div class="card-header fw-semibold">Add new line</div>
            <div class="card-body">
                <form method="POST" action="{{ route('dp.store', ['date' => $day->toDateString()]) }}" class="row g-2">
                    @csrf

                    <div class="col-md-4">
                        <label class="form-label">Customer *</label>
                        <input name="customer_name" class="form-control" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Package</label>
                        <input name="package_name" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Type</label>
                        <input name="product_type" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Size</label>
                        <input name="size_length" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">MFG No</label>
                        <input name="mfg_no" class="form-control">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Qty (Marketing)</label>
                        <input name="qty_marketing" class="form-control" inputmode="decimal">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Qty (Inventory)</label>
                        <input name="qty_inventory" class="form-control" inputmode="decimal">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Qty (Production)</label>
                        <input name="qty_production" class="form-control" inputmode="decimal">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Qty (Delivery)</label>
                        <input name="qty_delivery" class="form-control" inputmode="decimal">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Address</label>
                        <input name="address" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Sales Order</label>
                        <input name="sales_order_no" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Tel</label>
                        <input name="tel" class="form-control">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Logistic</label>
                        <input name="logistic" class="form-control">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Remark / Problem</label>
                        <input name="remark_problem" class="form-control">
                    </div>

                    <div class="col-12 d-flex justify-content-end">
                        <button class="btn btn-primary">Add</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold">Lines</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Customer</th>
                            <th>Type/Size</th>
                            <th>MFG</th>
                            <th class="text-end">Qty</th>
                            <th>SO</th>
                            <th>Logistic</th>
                            <th>Rev</th>
                            <th>Active</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $r)
                            @php
                                // ตัวอย่างทำสีตาม revise_count
                                $cls =
                                    $r->revise_count >= 3
                                        ? 'text-danger fw-semibold'
                                        : ($r->revise_count >= 1
                                            ? 'text-warning fw-semibold'
                                            : '');
                            @endphp
                            <tr>
                                <td>{{ $r->id }}</td>
                                <td class="{{ $cls }}">{{ $r->customer_name }}</td>
                                <td>{{ $r->product_type }} / {{ $r->size_length }}</td>
                                <td>{{ $r->mfg_no }}</td>
                                <td class="text-end">
                                    {{ $r->qty_delivery ?? ($r->qty_production ?? ($r->qty_inventory ?? $r->qty_marketing)) }}
                                </td>
                                <td>{{ $r->sales_order_no }}</td>
                                <td>{{ $r->logistic }}</td>
                                <td>{{ $r->revise_count }}</td>
                                <td>
                                    <form method="POST" action="{{ route('dp.toggle', $r->id) }}">
                                        @csrf
                                        <button
                                            class="btn btn-sm {{ $r->active ? 'btn-outline-success' : 'btn-outline-secondary' }}">
                                            {{ $r->active ? 'ON' : 'OFF' }}
                                        </button>
                                    </form>
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary"
                                        href="{{ route('dp.edit', $r->id) }}">Edit</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4">No data</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
