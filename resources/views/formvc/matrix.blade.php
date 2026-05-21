@extends('layouts.layout')

@section('title', 'Variable Cost - Department Matrix')
@section('page-title', 'Variable Cost')

@section('content')
    @php
        $matrix = $expenseMatrix ?? ['accounts' => collect(), 'rows' => collect(), 'columnTotals' => [], 'grandTotal' => 0];
        $accounts = collect($matrix['accounts'] ?? []);
        $matrixRows = collect($matrix['rows'] ?? []);
        $columnTotals = $matrix['columnTotals'] ?? [];
        $fmt = fn($value, $decimals = 2) => ((float) $value) == 0.0 ? '-' : number_format((float) $value, $decimals);
        $matrixFilters = collect($filters ?? [])->only(['date_from', 'date_to', 'site', 'invoice', 'notes'])->filter(fn($v) => is_array($v) ? !empty($v) : (string) $v !== '')->all();
        $returnParams = ['return_url' => request()->fullUrl(), 'return_label' => 'กลับหน้าแผนก x ค่าใช้จ่าย'];
    @endphp

    @include('formvc.partials.styles')

    <div class="vc-wrap">
        @include('formvc.partials.header')

        <div class="vc-card">
            <div class="vc-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>เปรียบเทียบแผนก x รายการค่าใช้จ่าย</span>
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <label class="form-check form-switch small m-0">
                        <input class="form-check-input" type="checkbox" id="vcHideZeroRows" checked>
                        <span class="form-check-label">ซ่อนแผนกที่ยอดเป็น 0</span>
                    </label>
                    <label class="form-check form-switch small m-0">
                        <input class="form-check-input" type="checkbox" id="vcHideZeroColumns" checked>
                        <span class="form-check-label">ซ่อนบัญชีที่ยอดเป็น 0</span>
                    </label>
                    <span class="text-muted small">{{ $matrixRows->count() }} แผนก / {{ $accounts->count() }} บัญชี</span>
                </div>
            </div>
            <div class="vc-table-wrap">
                <table class="table table-bordered table-sm vc-table vc-matrix-table mb-0">
                    <thead>
                        <tr>
                            <th class="dept-col">แผนก</th>
                            @foreach ($accounts as $account)
                                @php $columnTotal = (float) ($columnTotals[$account->key] ?? 0); @endphp
                                <th class="num account-col" data-vc-zero="{{ $columnTotal == 0.0 ? '1' : '0' }}">
                                    <div>{{ $account->name }}</div>
                                    <small class="d-block text-white-50">{{ $account->code }}</small>
                                </th>
                            @endforeach
                            <th class="num">รวม</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($matrixRows as $row)
                            <tr data-vc-row-zero="{{ (float) $row->total_amount == 0.0 ? '1' : '0' }}">
                                <td class="dept-col">
                                    <div class="fw-bold">{{ $row->department_code ?: '-' }}</div>
                                    <div>{{ $row->department }}</div>
                                </td>
                                @foreach ($accounts as $account)
                                    @php
                                        $amount = (float) ($row->amounts[$account->key] ?? 0);
                                        $columnTotal = (float) ($columnTotals[$account->key] ?? 0);
                                    @endphp
                                    <td class="num" data-vc-zero="{{ $columnTotal == 0.0 ? '1' : '0' }}">
                                        @if ($amount != 0.0)
                                            <a class="vc-drill-link" href="{{ route('variable-cost.details', $matrixFilters + ['department' => $row->department, 'account' => trim($account->code . ' ' . $account->name)] + $returnParams) }}">
                                                {{ $fmt($amount) }}
                                            </a>
                                        @else
                                            {{ $fmt($amount) }}
                                        @endif
                                    </td>
                                @endforeach
                                <td class="num fw-bold">{{ $fmt($row->total_amount) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $accounts->count() + 2 }}" class="text-center text-muted py-4">No data</td></tr>
                        @endforelse
                    </tbody>
                    @if ($matrixRows->isNotEmpty())
                        <tfoot>
                            <tr>
                                <td class="dept-col">Total</td>
                                @foreach ($accounts as $account)
                                    @php $columnTotal = (float) ($columnTotals[$account->key] ?? 0); @endphp
                                    <td class="num" data-vc-zero="{{ $columnTotal == 0.0 ? '1' : '0' }}">{{ $fmt($columnTotal) }}</td>
                                @endforeach
                                <td class="num">{{ $fmt($matrix['grandTotal'] ?? 0) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const columnToggle = document.getElementById('vcHideZeroColumns');
            const rowToggle = document.getElementById('vcHideZeroRows');
            const apply = () => {
                document.querySelectorAll('[data-vc-zero="1"]').forEach(cell => {
                    cell.classList.toggle('d-none', columnToggle && columnToggle.checked);
                });
                document.querySelectorAll('[data-vc-row-zero="1"]').forEach(row => {
                    row.classList.toggle('d-none', rowToggle && rowToggle.checked);
                });
            };
            if (columnToggle) columnToggle.addEventListener('change', apply);
            if (rowToggle) rowToggle.addEventListener('change', apply);
            apply();
        });
    </script>
@endpush
