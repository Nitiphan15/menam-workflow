@php
    $activePage = $activePage ?? 'summary';
    $targetRoute = 'variable-cost.' . $activePage;
    $divisionGroupVals = collect((array) ($filters['division_group'] ?? []))
        ->map(fn($v) => (string) $v)
        ->filter(fn($v) => $v !== '')
        ->values();
    $divisionDepartmentMode = strtoupper((string) ($filters['division_department_mode'] ?? 'AND')) === 'OR' ? 'OR' : 'AND';
    $deptVals = collect((array) ($filters['department'] ?? []))
        ->map(fn($v) => (string) $v)
        ->filter(fn($v) => $v !== '')
        ->values();
    $accVals = collect((array) ($filters['account'] ?? []))
        ->map(fn($v) => (string) $v)
        ->filter(fn($v) => $v !== '')
        ->values();
    $divisionGroupOptionList = collect($divisionGroupOptions ?? [])->map(fn($v) => (string) $v);
    $departmentOptionList = collect($departmentOptions ?? [])->map(fn($v) => (string) $v);
    $accountOptionList = collect($accountOptions ?? [])->map(fn($v) => (string) $v);
@endphp

<div class="vc-card mb-3">
    <div class="vc-card-header d-flex justify-content-between align-items-center">
        <span>Filters</span>
        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#vcFilters" aria-expanded="true">
            <i class="fas fa-sliders-h me-1"></i> Toggle
        </button>
    </div>
    <div id="vcFilters" class="collapse show">
        <form method="GET" action="{{ route($targetRoute) }}" class="p-3">
            <div class="row g-3 align-items-end">
                <div class="col-lg-2 col-md-4">
                    <label class="form-label">From Date</label>
                    <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
                </div>
                <div class="col-lg-2 col-md-4">
                    <label class="form-label">To Date</label>
                    <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
                </div>
                <div class="col-lg-1 col-md-4">
                    <label class="form-label">Site</label>
                    <select name="site" class="form-select">
                        <option value="ALL" {{ ($filters['site'] ?? 'ALL') === 'ALL' ? 'selected' : '' }}>ALL</option>
                        <option value="WIRE" {{ ($filters['site'] ?? '') === 'WIRE' ? 'selected' : '' }}>WIRE</option>
                        <option value="PLUS" {{ ($filters['site'] ?? '') === 'PLUS' ? 'selected' : '' }}>PLUS</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-4">
                    <label class="form-label">Division Group</label>
                    <select name="division_group[]" class="form-select vc-tomselect-multi" multiple data-placeholder="-- ทั้งหมด --" data-create="0">
                        @foreach ($divisionGroupOptionList as $option)
                            <option value="{{ $option }}" {{ $divisionGroupVals->contains($option) ? 'selected' : '' }}>{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-1 col-md-4">
                    <label class="form-label">Mode</label>
                    <select name="division_department_mode" class="form-select">
                        <option value="AND" {{ $divisionDepartmentMode === 'AND' ? 'selected' : '' }}>AND</option>
                        <option value="OR" {{ $divisionDepartmentMode === 'OR' ? 'selected' : '' }}>OR</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-4">
                    <label class="form-label">Department</label>
                    <select name="department[]" class="form-select vc-tomselect-multi" multiple data-placeholder="-- ทั้งหมด --">
                        @foreach ($deptVals as $v)
                            @if (!$departmentOptionList->contains($v))
                                <option value="{{ $v }}" selected>{{ $v }}</option>
                            @endif
                        @endforeach
                        @foreach ($departmentOptionList as $option)
                            <option value="{{ $option }}" {{ $deptVals->contains($option) ? 'selected' : '' }}>{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-2 col-md-4">
                    <label class="form-label">Account</label>
                    <select name="account[]" class="form-select vc-tomselect-multi" multiple data-placeholder="-- ทั้งหมด --" data-create="0">
                        @foreach ($accVals as $v)
                            @if (!$accountOptionList->contains($v))
                                <option value="{{ $v }}" selected>{{ $v }}</option>
                            @endif
                        @endforeach
                        @foreach ($accountOptionList as $option)
                            <option value="{{ $option }}" {{ $accVals->contains($option) ? 'selected' : '' }}>{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-1 col-md-4">
                    <label class="form-label">Invoice</label>
                    <input type="text" name="invoice" class="form-control" value="{{ $filters['invoice'] ?? '' }}">
                </div>
                <div class="col-lg-2 col-md-4">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-control" value="{{ $filters['notes'] ?? '' }}">
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-3 quick-actions">
                <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i> Search</button>
                <a href="{{ route($targetRoute) }}" class="btn btn-outline-secondary"><i class="fas fa-rotate-left me-1"></i> Reset</a>
                <a href="{{ route('variable-cost.export', request()->query() + ['page' => $activePage]) }}" class="btn btn-success"><i class="fas fa-file-excel me-1"></i> Export Excel</a>
            </div>
        </form>
    </div>
</div>

@push('styles')
    <style>
        .ts-dropdown { z-index: 1080 !important; }
        body > .ts-dropdown { position: absolute; }
        .vc-tomselect-multi + .ts-wrapper .ts-control { min-height: 38px; }
    </style>
@endpush

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (!window.TomSelect) return;
            document.querySelectorAll('.vc-tomselect-multi').forEach(function (el) {
                if (el.tomselect) return;
                new TomSelect(el, {
                    plugins: ['remove_button'],
                    create: el.dataset.create !== '0',
                    persist: false,
                    placeholder: el.dataset.placeholder || '',
                    maxOptions: 1000,
                    dropdownParent: 'body',
                });
            });
        });
    </script>
@endpush
