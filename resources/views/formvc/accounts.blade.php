@extends('layouts.layout')

@section('title', 'Variable Cost - Accounts')
@section('page-title', 'Variable Cost')

@section('content')
    @php
        $accountSummary = collect($accountSummary ?? []);
        $fmt = fn($value, $decimals = 0) => number_format((float) $value, $decimals);
        $accountGrandTotal = max((float) $accountSummary->sum('total_amount'), 1);
    @endphp

    @include('formvc.partials.styles')

    <div class="vc-wrap">
        @include('formvc.partials.header')
        @include('formvc.partials.year-comparison')

        <div class="vc-card">
            <div class="vc-card-header">ตารางบัญชีทั้งหมด</div>
            <div class="vc-table-wrap">
                <table class="table table-bordered table-sm vc-table mb-0">
                    <thead><tr><th>รหัสบัญชี</th><th>ชื่อบัญชี</th><th class="num">รายการ</th><th class="num">ยอดรวม</th><th class="num">สัดส่วน %</th></tr></thead>
                    <tbody>
                        @forelse ($accountSummary as $row)
                            <tr>
                                <td>{{ $row->account_code }}</td>
                                <td class="text-tight">{{ $row->account_name }}</td>
                                <td class="num">{{ $fmt($row->line_count) }}</td>
                                <td class="num fw-bold">{{ $fmt($row->total_amount, 2) }}</td>
                                <td class="num">{{ $fmt(($row->total_amount / $accountGrandTotal) * 100, 1) }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">No data</td></tr>
                        @endforelse
                    </tbody>
                    @if ($accountSummary->isNotEmpty())
                        <tfoot>
                            <tr>
                                <td colspan="2">Total</td>
                                <td class="num">{{ $fmt($accountSummary->sum('line_count')) }}</td>
                                <td class="num">{{ $fmt($accountSummary->sum('total_amount'), 2) }}</td>
                                <td class="num">100.0%</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
@endsection
