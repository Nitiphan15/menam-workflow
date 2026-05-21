@extends('layouts.layout')

@section('content')
    @php
        $from = $filters['from'] ?? '2026-01-01';
        $to = $filters['to'] ?? now()->toDateString();
        $partPrefix = $filters['part_prefix'] ?? 'F%';
        $month = $filters['month'] ?? '';

        // D8 แสดงบาท, อื่นๆ แสดง qty เป็นหลัก (แต่เราก็โชว์ทั้ง qty และ bath ให้ดู)
        $fmtNum = fn($v) => number_format((float) $v, 2);
    @endphp

    <div class="container-fluid py-3">

        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-2">
            <div>
                <h4 class="mb-0">Sales Detail: {{ $divisionName ?? $salesName }}</h4>
                <div class="text-muted small">
                    Sales ID: {{ $salesId }}
                    • Division: {{ $divisionName ?? '-' }}
                </div>
            </div>

            <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary"
                    href="{{ route('wos.sales_weekly') }}?from={{ urlencode($from) }}&to={{ urlencode($to) }}&part_prefix={{ urlencode($partPrefix) }}">
                    ← Back
                </a>
            </div>
        </div>

        {{-- Filter --}}
        <form class="card card-body mb-3" method="GET"
            action="{{ route('wos.sales_weekly.detail', ['salesId' => $salesId]) }}">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-5">
                    <label class="form-label mb-1">From</label>
                    <input type="date" class="form-control" name="from" value="{{ $filters['from'] ?? '' }}">
                </div>
                <div class="col-12 col-md-5">
                    <label class="form-label mb-1">To</label>
                    <input type="date" class="form-control" name="to" value="{{ $filters['to'] ?? '' }}">
                </div>

                <input type="hidden" name="month" value="{{ $filters['month'] ?? '' }}">

                <div class="col-12 col-md-2 d-grid">
                    <button class="btn btn-primary">Search</button>
                </div>
            </div>
        </form>

        <div class="card">
            <div class="table-responsive" style="max-height: calc(100vh - 320px); overflow:auto;">
                <table class="table table-sm table-hover table-bordered align-middle mb-0">
                    <thead class="table-light" style="position: sticky; top:0; z-index:5;">
                        <tr class="text-center">
                            <th style="min-width:120px;">วันที่ขาย</th>
                            <th style="min-width:140px;">เลขที่ SO</th>
                            <th style="min-width:190px;">ลูกค้า</th>
                            <th style="min-width:140px;">รหัสสินค้า</th>
                            <th style="min-width:260px;">รายละเอียดสินค้า</th>
                            <th style="min-width:160px;">ชนิดงาน</th>
                            <th style="min-width:80px;">หน่วย</th>
                            <th style="min-width:110px;">ปริมาณ</th>
                            <th style="min-width:110px;">ราคาขาย</th>
                            <th style="min-width:130px;">มูลค่า (บาท)</th>
                            <th style="min-width:120px;">กำหนดส่ง</th>
                            <th style="min-width:140px;">เลขที่ PO ลูกค้า</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($items as $it)
                            <tr>
                                <td class="text-center">
                                    {{ $it->transdate ? \Carbon\Carbon::parse($it->transdate)->format('Y-m-d') : '-' }}
                                </td>
                                <td class="text-center fw-semibold">{{ $it->ordnumber }}</td>
                                <td>
                                    <div class="fw-semibold">{{ $it->customer_name }}</div>
                                    <div class="text-muted small">{{ $it->customernumber }}</div>
                                </td>
                                <td class="text-center">{{ $it->partnumber }}</td>

                                <td>{{ $it->description }}</td>
                                <td class="text-center">{{ $it->partstype ?? '-' }}</td>
                                <td class="text-center">{{ $it->unit2 }}</td>
                                <td class="text-end">{{ $fmtNum($it->qty) }}</td>
                                <td class="text-end">{{ $fmtNum($it->sellprice) }}</td>
                                <td class="text-end fw-semibold">{{ $fmtNum($it->bath) }}</td>

                                <td class="text-center">
                                    {{ $it->reqdate ? \Carbon\Carbon::parse($it->reqdate)->format('Y-m-d') : '-' }}
                                </td>
                                <td class="text-center">{{ $it->custponumber }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="text-center text-muted py-4">No items</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
@endsection
