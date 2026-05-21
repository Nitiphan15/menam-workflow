@extends('layouts.layout')

@section('title', 'Order Due Date Detail')
@section('page-title', 'Order Due Date Detail')

@section('content')
    @php
        $year = (int) ($filters['year'] ?? now()->year);
        $month = (int) ($filters['month'] ?? now()->month);
        $groupCode = $filters['groupCode'] ?? null;
        $typeName = $filters['typeName'] ?? '';
        $items = $detail['items'] ?? [];
        $fmt = fn($v) => number_format((float) $v, 2);
    @endphp

    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
            <div>
                <h4 class="mb-1">Order Due Detail: {{ $detail['title'] ?? '-' }}</h4>
                <div class="text-muted small">
                    Due Month: {{ $detail['period_label'] ?? '-' }}
                    @if (!empty($typeName))
                        | Type: {{ $typeName }}
                    @endif
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-danger"
                    href="{{ route('wos.order_due_date.detail.pdf', [
                        'year' => $year,
                        'month' => $month,
                        'group_code' => $groupCode,
                        'type_name' => $typeName,
                    ]) }}"
                    target="_blank">Export PDF</a>
                <a class="btn btn-outline-secondary"
                    href="{{ route('wos.order_due_date', ['year' => $year, 'month' => $month]) }}">Back</a>
            </div>
        </div>

        <form class="card card-body mb-3" method="GET" action="{{ route('wos.order_due_date.detail') }}">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-2">
                    <label class="form-label mb-1">Month</label>
                    <input type="number" min="1" max="12" class="form-control" name="month" value="{{ $month }}">
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label mb-1">Year</label>
                    <input type="number" class="form-control" name="year" value="{{ $year }}">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label mb-1">Group</label>
                    <input type="text" class="form-control" name="group_code" value="{{ $groupCode }}">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label mb-1">Type</label>
                    <input type="text" class="form-control" name="type_name" value="{{ $typeName }}">
                </div>
                <div class="col-12 col-md-2 d-grid">
                    <button class="btn btn-primary">Search</button>
                </div>
            </div>
        </form>

        <div class="row g-3 mb-3">
            <div class="col-12 col-md-4">
                <div class="card card-body h-100">
                    <div class="text-muted small">Summary Metric</div>
                    <div class="fs-4 fw-bold">{{ $fmt($detail['total_metric'] ?? 0) }}</div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card card-body h-100">
                    <div class="text-muted small">Total Qty</div>
                    <div class="fs-4 fw-bold">{{ $fmt($detail['total_qty'] ?? 0) }}</div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card card-body h-100">
                    <div class="text-muted small">Total Amount</div>
                    <div class="fs-4 fw-bold">{{ $fmt($detail['total_bath'] ?? 0) }}</div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive" style="max-height: calc(100vh - 390px); overflow:auto;">
                <table class="table table-sm table-hover table-bordered align-middle mb-0">
                    <thead class="table-light" style="position: sticky; top:0; z-index:5;">
                        <tr class="text-center">
                            <th>Due Date</th>
                            <th>SO No.</th>
                            <th>Division</th>
                            <th>Customer</th>
                            <th>Part No.</th>
                            <th>Description</th>
                            <th>Type</th>
                            <th>Qty</th>
                            <th>Amount</th>
                            <th>Metric</th>
                            <th>PO Customer</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $item)
                            <tr>
                                <td class="text-center">{{ $item->reqdate ? \Carbon\Carbon::parse($item->reqdate)->format('Y-m-d') : '-' }}</td>
                                <td class="text-center fw-semibold">{{ $item->ordnumber }}</td>
                                <td>{{ $item->group_name ?? '-' }}</td>
                                <td>
                                    <div class="fw-semibold">{{ $item->customer_name ?? '-' }}</div>
                                    <div class="text-muted small">{{ $item->customernumber ?? '-' }}</div>
                                </td>
                                <td class="text-center">{{ $item->partnumber ?? '-' }}</td>
                                <td>{{ $item->description ?? '-' }}</td>
                                <td class="text-center">{{ $item->partstype ?? '-' }}</td>
                                <td class="text-end">{{ $fmt($item->qty ?? 0) }}</td>
                                <td class="text-end">{{ $fmt($item->bath ?? 0) }}</td>
                                <td class="text-end fw-semibold">{{ $fmt($item->metric_value ?? 0) }} {{ $item->metric_unit ?? '' }}</td>
                                <td class="text-center">{{ $item->custponumber ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="text-center text-muted py-4">No data</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
