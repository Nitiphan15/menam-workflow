@extends('layouts.layout')
@section('title', 'FormFC Group')
@section('page-title', 'FormFC Group')

@section('content')
    <div class="container-fluid py-3">
        @if (empty($tableReady))
            <div class="alert alert-warning shadow-sm">
                Please run <code>database/sql/create_fc_division_account_groups.sql</code> before saving this page.
            </div>
        @endif

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('fc.divisionGroup.index') }}">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Division</label>
                            @if (!empty($showDivisionDropdown))
                                <select name="division" class="form-select">
                                    @foreach ($allowedDivisions as $div)
                                        <option value="{{ $div }}" {{ ($division ?? '') === $div ? 'selected' : '' }}>
                                            {{ $divisionLabels[$div] ?? $div }}
                                        </option>
                                    @endforeach
                                </select>
                            @else
                                <input type="text" class="form-control"
                                    value="{{ $divisionLabels[$division] ?? ($division ?? '-') }}" readonly>
                                <input type="hidden" name="division" value="{{ $division ?? '' }}">
                            @endif
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Group filter</label>
                            <select id="group-filter" class="form-select">
                                <option value="">All groups</option>
                                <option value="__blank">No group</option>
                                @foreach ($groups ?? [] as $group)
                                    <option value="{{ $group }}">{{ $group }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Account search</label>
                            <input type="search" id="account-search" class="form-control" placeholder="Account code / name">
                        </div>

                        <div class="col-md-3 d-flex gap-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fa-solid fa-filter me-1"></i> Apply
                            </button>
                            <a href="{{ route('fc.divisionGroup.index', ['division' => $division]) }}"
                                class="btn btn-outline-secondary w-100">Reset</a>
                            <a href="{{ route('accounting.divisionGroup.master') }}"
                                class="btn btn-outline-dark w-100">Master</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <form method="POST" action="{{ route('fc.divisionGroup.save') }}">
            @csrf
            <input type="hidden" name="division" value="{{ $division ?? '' }}">

            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-0 pt-3 pb-0">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <h5 class="mb-1">Division Account Groups</h5>
                            <div class="text-muted small">
                                {{ collect($rows ?? [])->count() }} accounts
                                @if (!empty($division))
                                    | Division: <span class="fw-semibold">{{ $divisionLabels[$division] ?? $division }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="active-all-btn">
                                Active all
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="inactive-all-btn">
                                Inactive all
                            </button>
                            <button type="submit" class="btn btn-success btn-sm" {{ empty($tableReady) ? 'disabled' : '' }}>
                                <i class="fa-solid fa-floppy-disk me-1"></i> Save
                            </button>
                        </div>
                    </div>
                </div>

                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="group-table">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 70px;" class="text-center">#</th>
                                    <th style="width: 150px;">Account</th>
                                    <th>Account name</th>
                                    <th style="width: 260px;">Group</th>
                                    <th style="width: 120px;" class="text-center">Show</th>
                                    <th style="width: 280px;">Remark</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows ?? [] as $i => $row)
                                    <tr data-account="{{ strtolower(($row->account_code ?? '') . ' ' . ($row->account_name ?? '')) }}"
                                        data-group="{{ strtolower(trim((string) ($row->group_name ?? ''))) }}">
                                        <td class="text-center text-muted">{{ $i + 1 }}</td>
                                        <td>
                                            <div class="fw-semibold">{{ $row->account_code ?? '' }}</div>
                                            <input type="hidden" name="rows[{{ $i }}][account_code]"
                                                value="{{ $row->account_code ?? '' }}">
                                        </td>
                                        <td>{{ $row->account_name ?: '-' }}</td>
                                        <td>
                                            @if (collect($groups ?? [])->isNotEmpty())
                                                <select class="form-select form-select-sm js-group-input"
                                                    name="rows[{{ $i }}][group_name]">
                                                    <option value="">No group</option>
                                                    @if (($row->group_name ?? '') !== '' && !collect($groups ?? [])->contains($row->group_name))
                                                        <option value="{{ $row->group_name }}" selected>
                                                            {{ $row->group_name }} (inactive)
                                                        </option>
                                                    @endif
                                                    @foreach ($groups ?? [] as $group)
                                                        <option value="{{ $group }}"
                                                            {{ ($row->group_name ?? '') === $group ? 'selected' : '' }}>
                                                            {{ $group }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <input type="text" class="form-control form-control-sm js-group-input"
                                                    name="rows[{{ $i }}][group_name]" value="{{ $row->group_name ?? '' }}"
                                                    list="group-options" placeholder="Add groups in master first">
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            <div class="form-check d-flex justify-content-center">
                                                <input class="form-check-input js-active-check" type="checkbox"
                                                    name="rows[{{ $i }}][is_active]" value="1"
                                                    {{ (int) ($row->is_active ?? 1) === 1 ? 'checked' : '' }}>
                                            </div>
                                        </td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm"
                                                name="rows[{{ $i }}][remark]" value="{{ $row->remark ?? '' }}"
                                                placeholder="Optional note">
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <datalist id="group-options">
                        @foreach ($groups ?? [] as $group)
                            <option value="{{ $group }}"></option>
                        @endforeach
                    </datalist>

                    <div class="d-flex justify-content-end mt-3">
                        <button type="submit" class="btn btn-success" {{ empty($tableReady) ? 'disabled' : '' }}>
                            <i class="fa-solid fa-floppy-disk me-1"></i> Save
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const groupFilter = document.getElementById('group-filter');
            const accountSearch = document.getElementById('account-search');
            const rows = Array.from(document.querySelectorAll('#group-table tbody tr'));

            function refreshRowMeta(row) {
                const groupInput = row.querySelector('.js-group-input');
                row.dataset.group = (groupInput?.value || '').trim().toLowerCase();
            }

            function applyFilter() {
                const selectedGroup = (groupFilter?.value || '').trim().toLowerCase();
                const search = (accountSearch?.value || '').trim().toLowerCase();

                rows.forEach(row => {
                    refreshRowMeta(row);
                    const group = row.dataset.group || '';
                    const account = row.dataset.account || '';
                    const groupMatched = !selectedGroup ||
                        (selectedGroup === '__blank' ? group === '' : group === selectedGroup);
                    const accountMatched = !search || account.includes(search);
                    row.classList.toggle('d-none', !(groupMatched && accountMatched));
                });
            }

            groupFilter?.addEventListener('change', applyFilter);
            accountSearch?.addEventListener('input', applyFilter);
            document.querySelectorAll('.js-group-input').forEach(input => {
                input.addEventListener('input', applyFilter);
            });

            document.getElementById('active-all-btn')?.addEventListener('click', function() {
                document.querySelectorAll('.js-active-check').forEach(cb => cb.checked = true);
            });

            document.getElementById('inactive-all-btn')?.addEventListener('click', function() {
                document.querySelectorAll('.js-active-check').forEach(cb => cb.checked = false);
            });
        });
    </script>
@endpush
