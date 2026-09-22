@extends('layouts.layout')

@section('title', 'Order Due Date Detail')
@section('page-title', 'Order Due Date Detail')

@section('content')
    @php
        $year = (int) ($filters['year'] ?? now()->year);
        $month = (int) ($filters['month'] ?? now()->month);
        $groupCode = $filters['groupCode'] ?? null;
        $typeName = $filters['typeName'] ?? '';
        $mode = $filters['mode'] ?? 'order';
        $duePeriod = $filters['duePeriod'] ?? null;
        $selectedDivisions = $filters['selectedDivisions'] ?? [];
        $isActual = $mode === 'actual';
        $items = $detail['items'] ?? [];
        $fmt = fn($v) => number_format((float) $v, 2);
    @endphp

    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
            <div>
                <h4 class="mb-1">{{ $isActual ? 'Actual Delivery Detail' : 'Order Due Detail' }}: {{ $detail['title'] ?? '-' }}</h4>
                <div class="text-muted small">
                    {{ $isActual ? 'Actual Delivery Month' : 'Due Month' }}: {{ $detail['period_label'] ?? '-' }}
                    @if (!empty($typeName))
                        | Type: {{ $typeName }}
                    @endif
                    @if ($isActual && !empty($duePeriod))
                        | Original Due: {{ $duePeriod }}
                    @endif
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                @unless ($isActual)
                    <a class="btn btn-outline-danger"
                        href="{{ route('wos.order_due_date.detail.pdf', [
                            'year' => $year,
                            'month' => $month,
                            'group_code' => $groupCode,
                            'type_name' => $typeName,
                        ]) }}"
                        target="_blank">Export PDF</a>
                @endunless
                <a class="btn btn-outline-secondary"
                    href="{{ route('wos.order_due_date', ['year' => $year, 'month' => $month, 'divisions' => $selectedDivisions]) }}">Back</a>
            </div>
        </div>

        <form class="card card-body mb-3" method="GET" action="{{ route('wos.order_due_date.detail') }}">
            <input type="hidden" name="mode" value="{{ $mode }}">
            @foreach ($selectedDivisions as $division)
                <input type="hidden" name="divisions[]" value="{{ $division }}">
            @endforeach
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
                @if ($isActual)
                    <div class="col-12 col-md-3">
                        <label class="form-label mb-1">Original Due Month</label>
                        <input type="text" class="form-control" name="due_period" value="{{ $duePeriod }}"
                            placeholder="YYYY-MM / SAME_DUE_MONTH / OTHER_DUE_MONTH">
                    </div>
                @endif
                <div class="col-12 col-md-2 d-grid">
                    <button class="btn btn-primary">Search</button>
                </div>
            </div>
        </form>

        <div class="row g-3 mb-3">
            <div class="col-12 col-md-4">
                <div class="card card-body h-100">
                    <div class="text-muted small">{{ $isActual ? 'Shipment Lines' : 'Summary Metric' }}</div>
                    <div class="fs-4 fw-bold">{{ $isActual ? number_format($detail['line_count'] ?? 0) : $fmt($detail['total_metric'] ?? 0) }}</div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card card-body h-100">
                    <div class="text-muted small">{{ $isActual ? 'Actual Qty (ไม่รวม D8)' : 'Total Qty' }}</div>
                    <div class="fs-4 fw-bold">{{ $fmt($detail['total_qty'] ?? 0) }}</div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card card-body h-100">
                    <div class="text-muted small">{{ $isActual ? 'D8 Actual Baht' : 'Total Amount' }}</div>
                    <div class="fs-4 fw-bold">{{ $fmt($detail['total_bath'] ?? 0) }}</div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive" style="max-height: calc(100vh - 390px); overflow:auto;">
                <table class="table table-sm table-hover table-bordered align-middle mb-0">
                    <thead class="table-light" style="position: sticky; top:0; z-index:5;">
                        @if ($isActual)
                            <tr class="text-center">
                                <th>Product</th>
                                <th>Division</th>
                                <th>SO No.</th>
                                <th>Delivery No.</th>
                                <th>Customer</th>
                                <th>Part No.</th>
                                <th>Order Qty</th>
                                <th>Original Due Date</th>
                                <th>Actual Delivery Date</th>
                                <th>Actual Delivery Qty</th>
                                <th>Metric</th>
                                <th>Source</th>
                            </tr>
                        @else
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
                        @endif
                    </thead>
                    <tbody>
                        @forelse ($items as $item)
                            @if ($isActual)
                                <tr>
                                    <td>{{ $item->product_type ?? '-' }}</td>
                                    <td class="text-center">{{ $item->group_code ?? '-' }}</td>
                                    <td class="text-center fw-semibold">{{ $item->ordnumber ?? '-' }}</td>
                                    <td class="text-center">{{ $item->dmnumber ?? '-' }}</td>
                                    <td>{{ $item->customer_name ?? '-' }}</td>
                                    <td class="text-center">{{ $item->partnumber ?? '-' }}</td>
                                    <td class="text-end">{{ $fmt($item->order_qty ?? 0) }}</td>
                                    <td class="text-center">{{ $item->original_due_date ? \Carbon\Carbon::parse($item->original_due_date)->format('Y-m-d') : '-' }}</td>
                                    <td class="text-center">{{ $item->actual_delivery_date ? \Carbon\Carbon::parse($item->actual_delivery_date)->format('Y-m-d') : '-' }}</td>
                                    <td class="text-end">{{ $fmt($item->actual_qty ?? 0) }}</td>
                                    <td class="text-end fw-semibold">{{ $fmt($item->metric_value ?? 0) }} {{ $item->metric_unit ?? '' }}</td>
                                    <td class="text-center">{{ $item->origin_type ?? '-' }}</td>
                                </tr>
                            @else
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
                            @endif
                        @empty
                            <tr>
                                <td colspan="{{ $isActual ? 12 : 11 }}" class="text-center text-muted py-4">No data</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
