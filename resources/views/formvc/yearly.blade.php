@extends('layouts.layout')

@section('title', 'Variable Cost - Yearly Class')
@section('page-title', 'Variable Cost')

@section('content')
    @php
        $yearlyRows = collect($yearlyRows ?? []);
        $classOptions = collect($classOptions ?? []);
        $yearlyTotals = $yearlyTotals ?? (object) ['previous_avg' => 0, 'months' => [], 'total_amount' => 0];
        $fmt = fn($value, $decimals = 2) => ((float) $value) == 0.0 ? '-' : number_format((float) $value, $decimals);
        $monthLabels = [1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.', 5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.', 9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.'];
    @endphp

    @include('formvc.partials.styles')

    <div class="vc-wrap">
        @include('formvc.partials.header')

        <div class="vc-card mb-3">
            <div class="vc-card-header">Filter รายปีตาม Class</div>
            <form method="GET" action="{{ route('variable-cost.yearly') }}" class="p-3">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">Year</label>
                        <input type="number" name="year" class="form-control" value="{{ $year }}" min="2000" max="2100">
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">Site</label>
                        <select name="site" class="form-select">
                            <option value="ALL" {{ ($filters['site'] ?? 'ALL') === 'ALL' ? 'selected' : '' }}>ALL</option>
                            <option value="WIRE" {{ ($filters['site'] ?? '') === 'WIRE' ? 'selected' : '' }}>WIRE</option>
                            <option value="PLUS" {{ ($filters['site'] ?? '') === 'PLUS' ? 'selected' : '' }}>PLUS</option>
                        </select>
                    </div>
                    <div class="col-lg-5 col-md-8">
                        <label class="form-label">Class (เลือกได้หลาย)</label>
                        @php
                            $selectedClassKeys = collect((array) ($filters['class'] ?? []))
                                ->map(fn($v) => (string) $v)
                                ->filter(fn($v) => $v !== '')
                                ->values();
                        @endphp
                        <select name="class[]" class="form-select vc-yearly-class" multiple>
                            @foreach ($classOptions as $option)
                                <option value="{{ $option->key }}" {{ $selectedClassKeys->contains($option->key) ? 'selected' : '' }}>
                                    {{ $option->label }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">Account</label>
                        <input type="text" name="account" class="form-control" value="{{ $filters['account'] ?? '' }}" list="vcYearlyAccounts">
                        <datalist id="vcYearlyAccounts">
                            @foreach ($accountOptions ?? [] as $option)
                                <option value="{{ $option }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                    <div class="col-lg-1 col-md-4">
                        <button type="submit" class="btn btn-primary w-100">Search</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="vc-card">
            <div class="vc-card-header d-flex justify-content-between align-items-center">
                <span>
                    @if (!empty($selectedClasses) && count($selectedClasses))
                        {{ collect($selectedClasses)->pluck('label')->implode(', ') }}
                    @else
                        ทุก Class
                    @endif
                </span>
                <span class="text-muted small">เฉลี่ย {{ $previousYear }} เทียบกับรายเดือน {{ $year }}</span>
            </div>
            <div class="vc-table-wrap">
                <table class="table table-bordered table-sm vc-table vc-matrix-table mb-0">
                    <thead>
                        <tr>
                            <th class="dept-col">บัญชี</th>
                            <th class="num">เฉลี่ย {{ $previousYear }}</th>
                            @foreach ($monthLabels as $label)
                                <th class="num">{{ $label }}</th>
                            @endforeach
                            <th class="num">รวม</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($yearlyRows as $row)
                            <tr>
                                <td class="dept-col">
                                    <div class="fw-bold">{{ $row->account_code }}</div>
                                    <div>{{ $row->account_name }}</div>
                                </td>
                                <td class="num">{{ $fmt($row->previous_avg) }}</td>
                                @foreach ($monthLabels as $month => $label)
                                    <td class="num">{{ $fmt($row->months[$month] ?? 0) }}</td>
                                @endforeach
                                <td class="num fw-bold">{{ $fmt($row->total_amount) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="15" class="text-center text-muted py-4">No data</td></tr>
                        @endforelse
                    </tbody>
                    @if ($yearlyRows->isNotEmpty())
                        <tfoot>
                            <tr>
                                <td class="dept-col">Total</td>
                                <td class="num">{{ $fmt($yearlyTotals->previous_avg ?? 0) }}</td>
                                @foreach ($monthLabels as $month => $label)
                                    <td class="num">{{ $fmt($yearlyTotals->months[$month] ?? 0) }}</td>
                                @endforeach
                                <td class="num">{{ $fmt($yearlyTotals->total_amount ?? 0) }}</td>
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
            if (!window.TomSelect) return;
            document.querySelectorAll('.vc-yearly-class').forEach(function (el) {
                if (el.tomselect) return;
                new TomSelect(el, {
                    plugins: ['remove_button'],
                    persist: false,
                    placeholder: '-- เลือก class --',
                    maxOptions: 1000,
                    dropdownParent: 'body',
                });
            });
        });
    </script>
@endpush
