@extends('layouts.layout')

@section('content')
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">Edit #{{ $row->id }}</h4>
            <a class="btn btn-outline-secondary"
                href="{{ route('dp.day', ['date' => $row->plan_date->toDateString()]) }}">Back</a>
        </div>

        @if ($errors->has('conflict'))
            <div class="alert alert-warning">{{ $errors->first('conflict') }}</div>
        @endif

        <form method="POST" action="{{ route('dp.update', $row->id) }}" class="card">
            @csrf
            <div class="card-body row g-2">
                <input type="hidden" name="row_version" value="{{ old('row_version', $row->row_version) }}">

                <div class="col-md-4">
                    <label class="form-label">Customer *</label>
                    <input name="customer_name" class="form-control" required
                        value="{{ old('customer_name', $row->customer_name) }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Package</label>
                    <input name="package_name" class="form-control" value="{{ old('package_name', $row->package_name) }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Type</label>
                    <input name="product_type" class="form-control" value="{{ old('product_type', $row->product_type) }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Size</label>
                    <input name="size_length" class="form-control" value="{{ old('size_length', $row->size_length) }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">MFG No</label>
                    <input name="mfg_no" class="form-control" value="{{ old('mfg_no', $row->mfg_no) }}">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Qty (Marketing)</label>
                    <input name="qty_marketing" class="form-control" inputmode="decimal"
                        value="{{ old('qty_marketing', $row->qty_marketing) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Qty (Inventory)</label>
                    <input name="qty_inventory" class="form-control" inputmode="decimal"
                        value="{{ old('qty_inventory', $row->qty_inventory) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Qty (Production)</label>
                    <input name="qty_production" class="form-control" inputmode="decimal"
                        value="{{ old('qty_production', $row->qty_production) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Qty (Delivery)</label>
                    <input name="qty_delivery" class="form-control" inputmode="decimal"
                        value="{{ old('qty_delivery', $row->qty_delivery) }}">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Address</label>
                    <input name="address" class="form-control" value="{{ old('address', $row->address) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Sales Order</label>
                    <input name="sales_order_no" class="form-control"
                        value="{{ old('sales_order_no', $row->sales_order_no) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tel</label>
                    <input name="tel" class="form-control" value="{{ old('tel', $row->tel) }}">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Logistic</label>
                    <input name="logistic" class="form-control" value="{{ old('logistic', $row->logistic) }}">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Remark / Problem</label>
                    <input name="remark_problem" class="form-control"
                        value="{{ old('remark_problem', $row->remark_problem) }}">
                </div>
            </div>

            <div class="card-footer d-flex justify-content-between">
                <div class="text-muted small">
                    Rev: {{ $row->revise_count }} | Version: {{ $row->row_version }}
                </div>
                <button class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
@endsection
