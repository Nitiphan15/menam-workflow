@extends('layouts.layout')

@section('title', 'Sales Delivery Volume Detail')
@section('page-title', 'Sales Delivery Volume Detail')

@section('content')
    @php
        $from = $filters['from'] ?? now()->startOfWeek()->toDateString();
        $to = $filters['to'] ?? now()->endOfWeek()->toDateString();
        $fmtNum = fn($v) => number_format((float) $v, 2);
    @endphp

    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-2">
            <div>
                <h4 class="mb-0">Sales Detail: {{ $divisionName }}</h4>
                <div class="text-muted small">
                    Group: {{ $groupCode }}
                    @if (!empty($typeName))
                        | Type: {{ $typeName }}
                    @endif
                </div>
            </div>

            <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary"
                    href="{{ route('wos.sales_unit_summary', ['from' => $from, 'to' => $to]) }}">
                    Back
                </a>
            </div>
        </div>

        <form class="card card-body mb-3" method="GET" action="{{ route('wos.sales_unit_summary.detail_group') }}">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label class="form-label mb-1">From</label>
                    <input type="date" class="form-control" name="from" value="{{ $from }}">
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label mb-1">To</label>
                    <input type="date" class="form-control" name="to" value="{{ $to }}">
                </div>

                <input type="hidden" name="group_code" value="{{ $groupCode }}">

                <div class="col-12 col-md-2 d-grid">
                    <button class="btn btn-primary">Search</button>
                </div>
            </div>
        </form>

        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-md-4">
                        <strong>Division:</strong> {{ $divisionName }}
                    </div>

                    @if (!empty($typeName))
                        <div class="col-md-4">
                            <strong>Type:</strong> {{ $typeName }}
                        </div>
                        <div class="col-md-4 text-md-end">
                            <strong>Total Qty:</strong> {{ $fmtNum($sumQty) }}
                        </div>
                    @else
                        <div class="col-md-8 text-md-end">
                            <strong>Total Qty:</strong> {{ $fmtNum($sumQty) }}
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive" style="max-height: calc(100vh - 330px); overflow:auto;">
                <table class="table table-sm table-hover table-bordered align-middle mb-0">
                    <thead class="table-light" style="position: sticky; top:0; z-index:5;">
                        <tr class="text-center">
                            <th style="min-width:120px;">Date</th>
                            <th style="min-width:140px;">Order No.</th>
                            <th style="min-width:260px;">Item Description</th>
                            <th style="min-width:110px;">Qty</th>
                            <th style="min-width:140px;">Customer No.</th>
                            <th style="min-width:220px;">Customer Name</th>
                            <th style="min-width:180px;">Type</th>
                            <th style="min-width:120px;">Unit</th>
                            <th style="min-width:100px;">Sales ID</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($items as $it)
                            <tr>
                                <td class="text-center">
                                    {{ $it->transdate ? \Carbon\Carbon::parse($it->transdate)->format('Y-m-d') : '-' }}
                                </td>
                                <td class="text-center fw-semibold">{{ $it->ordnumber }}</td>
                                <td>{{ $it->item_description }}</td>
                                <td class="text-end">{{ $fmtNum($it->qty) }}</td>
                                <td class="text-center">{{ $it->customernumber }}</td>
                                <td>{{ $it->customer_name }}</td>
                                <td class="text-center">{{ $it->partstype_description ?? '-' }}</td>
                                <td class="text-center">
                                    @if (($groupCode ?? '') === 'D8' || (int) ($it->sales_id ?? 0) === 1436)
                                        BATH
                                    @else
                                        {{ $it->unit_description }}
                                    @endif
                                </td>
                                <td class="text-center">{{ $it->sales_id }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">No items</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
