@extends('layouts.layout')

@section('title', 'Variable Cost Account Master')
@section('page-title', 'Variable Cost Account Master')

@section('content')
    <div class="container-fluid py-3">
        @if (session('success'))
            <div class="alert alert-success shadow-sm">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger shadow-sm">
                <div class="fw-bold mb-1">Save failed</div>
                <ul class="mb-0 ps-3">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (empty($tableReady))
            <div class="alert alert-warning shadow-sm">
                Please run <code>database/sql/create_vc_account_display_accounts.sql</code> before saving this page.
                Until then, Variable Cost uses the built-in default account list.
            </div>
        @elseif (empty($hasSavedRows))
            <div class="alert alert-info shadow-sm">
                No saved master rows yet. The checked accounts below are the current default display set.
            </div>
        @endif

        <div class="card shadow-sm border-0 mb-3 vc-master-filter">
            <div class="card-body">
                <form method="GET" action="{{ route('variable-cost.account-master') }}" class="row g-3 align-items-end">
                    <div class="col-lg-5 col-md-6">
                        <label class="form-label fw-semibold">Search</label>
                        <input type="search" name="q" class="form-control" value="{{ $filters['q'] ?? '' }}"
                            placeholder="Account code / name">
                    </div>
                    <div class="col-lg-2 col-md-3">
                        <label class="form-label fw-semibold">Status</label>
                        <select name="status" class="form-select">
                            <option value="all" {{ ($filters['status'] ?? '') === 'all' ? 'selected' : '' }}>All</option>
                            <option value="active" {{ ($filters['status'] ?? '') === 'active' ? 'selected' : '' }}>Shown</option>
                            <option value="inactive" {{ ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' }}>Hidden</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-3">
                        <label class="form-label fw-semibold">Group</label>
                        <select name="prefix" class="form-select">
                            <option value="all" {{ ($filters['prefix'] ?? 'all') === 'all' ? 'selected' : '' }}>All groups</option>
                            @foreach ($prefixOptions ?? [] as $option)
                                <option value="{{ $option['prefix'] }}" {{ ($filters['prefix'] ?? '') === $option['prefix'] ? 'selected' : '' }}>
                                    {{ $option['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fa-solid fa-magnifying-glass me-1"></i> Search
                        </button>
                        <a href="{{ route('variable-cost.account-master') }}" class="btn btn-outline-secondary w-100">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-3">
            @php
                $baseQuery = collect($filters ?? [])->except('prefix')->filter(fn($value) => (string) $value !== '')->all();
                $allGroupUrl = route('variable-cost.account-master', $baseQuery + ['prefix' => 'all']);
            @endphp
            <a href="{{ $allGroupUrl }}" class="btn btn-sm {{ ($filters['prefix'] ?? 'all') === 'all' ? 'btn-primary' : 'btn-outline-primary' }}">
                All groups
            </a>
            @foreach ($prefixOptions ?? [] as $option)
                @php $url = route('variable-cost.account-master', $baseQuery + ['prefix' => $option['prefix']]); @endphp
                <a href="{{ $url }}"
                    class="btn btn-sm {{ ($filters['prefix'] ?? '') === $option['prefix'] ? 'btn-primary' : 'btn-outline-primary' }}">
                    {{ $option['label'] }}
                    <span class="badge {{ ($filters['prefix'] ?? '') === $option['prefix'] ? 'bg-light text-primary' : 'bg-primary' }} ms-1">{{ $option['count'] }}</span>
                </a>
            @endforeach
        </div>

        <form method="POST" action="{{ route('variable-cost.account-master.update') }}">
            @csrf
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white vc-master-toolbar">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <div class="fw-semibold">Account Display List</div>
                            <div class="text-muted small">เลือก account ที่ต้องการให้โชว์ใน FormVC Summary / Matrix / Accounts</div>
                        </div>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span class="badge bg-success"><span id="vcVisibleSelected">{{ collect($rows ?? [])->where('is_active', 1)->count() }}</span> selected here</span>
                            <span class="badge bg-secondary">{{ $activeCount ?? 0 }} shown total</span>
                            <span class="badge bg-light text-dark">{{ $filteredCount ?? 0 }} listed</span>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-vc-check="all">Select visible</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-vc-check="none">Clear visible</button>
                            <button type="submit" class="btn btn-success" {{ empty($tableReady) ? 'disabled' : '' }}>
                                <i class="fa-solid fa-floppy-disk me-1"></i> Save
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    @if (collect($rows ?? [])->isEmpty())
                        <div class="text-center py-5 text-muted">
                            <i class="fa-regular fa-folder-open fa-2x mb-2"></i>
                            <div>No accounts found.</div>
                        </div>
                    @else
                        <div class="table-responsive vc-master-table-wrap">
                            <table class="table table-hover align-middle mb-0 vc-master-table">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 70px;" class="text-center">Show</th>
                                        <th style="width: 130px;">Account</th>
                                        <th>Name</th>
                                        <th style="width: 150px;">Source</th>
                                        <th style="width: 170px;">Updated</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows as $row)
                                        <tr class="vc-master-row {{ (int) ($row->is_active ?? 0) === 1 ? 'vc-selected-row' : 'table-light text-muted' }}">
                                            <td class="text-center">
                                                <input type="hidden" name="visible_accounts[]" value="{{ $row->account_code }}">
                                                <input type="checkbox" class="form-check-input vc-account-check" name="accounts[]"
                                                    value="{{ $row->account_code }}"
                                                    {{ (int) ($row->is_active ?? 0) === 1 ? 'checked' : '' }}>
                                            </td>
                                            <td class="fw-semibold vc-code">{{ $row->account_code }}</td>
                                            <td>
                                                <div class="fw-semibold">{{ $row->account_name }}</div>
                                            </td>
                                            <td><span class="badge bg-light text-dark">{{ $row->source_sites ?: '-' }}</span></td>
                                            <td class="text-muted small">{{ $row->updated_at ?? '-' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
                <div class="card-footer bg-white d-flex justify-content-end">
                    <button type="submit" class="btn btn-success" {{ empty($tableReady) ? 'disabled' : '' }}>
                        <i class="fa-solid fa-floppy-disk me-1"></i> Save
                    </button>
                </div>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const updateRows = function () {
                let selected = 0;
                document.querySelectorAll('.vc-account-check').forEach(function (input) {
                    const row = input.closest('.vc-master-row');
                    if (input.checked) {
                        selected++;
                        row?.classList.add('vc-selected-row');
                        row?.classList.remove('table-light', 'text-muted');
                    } else {
                        row?.classList.remove('vc-selected-row');
                        row?.classList.add('table-light', 'text-muted');
                    }
                });
                const selectedBadge = document.getElementById('vcVisibleSelected');
                if (selectedBadge) selectedBadge.textContent = selected;
            };

            document.querySelectorAll('.vc-master-row').forEach(function (row) {
                row.addEventListener('click', function (event) {
                    if (event.target.closest('input, button, a, label')) return;
                    const checkbox = row.querySelector('.vc-account-check');
                    if (!checkbox) return;
                    checkbox.checked = !checkbox.checked;
                    updateRows();
                });
            });

            document.querySelectorAll('.vc-account-check').forEach(function (input) {
                input.addEventListener('change', updateRows);
            });

            document.querySelectorAll('[data-vc-check]').forEach(function (button) {
                button.addEventListener('click', function () {
                    const checked = button.dataset.vcCheck === 'all';
                    document.querySelectorAll('.vc-account-check').forEach(function (input) {
                        input.checked = checked;
                    });
                    updateRows();
                });
            });

            updateRows();
        });
    </script>
@endpush

@push('styles')
    <style>
        .vc-master-filter { border-radius: 8px; }
        .vc-master-toolbar {
            position: sticky;
            top: 0;
            z-index: 12;
            border-bottom: 1px solid #d8dee6;
        }
        .vc-master-table-wrap { max-height: calc(100vh - 260px); overflow: auto; }
        .vc-master-table thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: #f8f9fa;
        }
        .vc-master-row { cursor: pointer; }
        .vc-master-row .form-check-input { width: 1.15rem; height: 1.15rem; cursor: pointer; }
        .vc-selected-row td { background: #eef8f3; }
        .vc-selected-row .vc-code { color: #0f6b3e; }
    </style>
@endpush
