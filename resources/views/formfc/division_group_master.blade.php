@extends('layouts.layout')
@section('title', 'Division Group Master')
@section('page-title', 'Division Group Master')

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
                Please run <code>database/sql/create_vc_department_division_groups.sql</code> before saving this page.
            </div>
        @endif

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('accounting.divisionGroup.master') }}" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Search</label>
                        <input type="search" name="q" class="form-control" value="{{ $filters['q'] ?? '' }}"
                            placeholder="Department code / name / group">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Site</label>
                        <select name="site" class="form-select">
                            <option value="ALL" {{ ($filters['site'] ?? 'ALL') === 'ALL' ? 'selected' : '' }}>ALL</option>
                            @foreach ($sites ?? [] as $site)
                                <option value="{{ $site }}" {{ ($filters['site'] ?? '') === $site ? 'selected' : '' }}>
                                    {{ $site }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Status</label>
                        <select name="status" class="form-select">
                            <option value="active" {{ ($filters['status'] ?? '') === 'active' ? 'selected' : '' }}>Active</option>
                            <option value="inactive" {{ ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                            <option value="all" {{ ($filters['status'] ?? '') === 'all' ? 'selected' : '' }}>All</option>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fa-solid fa-magnifying-glass me-1"></i> Search
                        </button>
                        <a href="{{ route('accounting.divisionGroup.master') }}" class="btn btn-outline-secondary w-100">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white">
                <div class="fw-semibold">Add Department Mapping</div>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('accounting.divisionGroup.master.store') }}" class="row g-3 align-items-end">
                    @csrf
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Site</label>
                        <select name="site" class="form-select" required>
                            @foreach ($sites ?? [] as $site)
                                <option value="{{ $site }}">{{ $site }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Dept Code</label>
                        <input type="text" name="department_code" class="form-control" placeholder="PD10" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Department</label>
                        <input type="text" name="department_name" class="form-control" placeholder="DRAWING" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Division Group</label>
                        <input type="text" name="division_group" class="form-control" list="division-group-options"
                            placeholder="Production" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Remark</label>
                        <input type="text" name="remark" class="form-control" placeholder="Optional">
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-success w-100" {{ empty($tableReady) ? 'disabled' : '' }}>
                            <i class="fa-solid fa-plus"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <datalist id="division-group-options">
            @foreach ($divisionGroups ?? [] as $group)
                <option value="{{ $group }}"></option>
            @endforeach
        </datalist>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="fw-semibold">Department Mapping List</div>
                    <div class="text-muted small">{{ collect($groups ?? [])->count() }} records</div>
                </div>
            </div>
            <div class="card-body">
                @if (collect($groups ?? [])->isEmpty())
                    <div class="text-center py-5 text-muted">
                        <i class="fa-regular fa-folder-open fa-2x mb-2"></i>
                        <div>No records. Run the insert script or add a row above.</div>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 110px;">Site</th>
                                    <th style="width: 130px;">Dept Code</th>
                                    <th>Department</th>
                                    <th style="width: 210px;">Division Group</th>
                                    <th style="width: 110px;" class="text-center">Active</th>
                                    <th>Remark</th>
                                    <th style="width: 130px;" class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($groups as $row)
                                    @php $formId = 'dept-division-group-' . $row->id; @endphp
                                    <tr class="{{ (int) ($row->is_active ?? 0) === 1 ? '' : 'table-light text-muted' }}">
                                        <td>
                                            <form id="{{ $formId }}" method="POST"
                                                action="{{ route('accounting.divisionGroup.master.update', $row->id) }}">
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="is_active" value="0">
                                            </form>
                                            <select form="{{ $formId }}" name="site" class="form-select form-select-sm" required>
                                                @foreach ($sites ?? [] as $site)
                                                    <option value="{{ $site }}" {{ ($row->site ?? '') === $site ? 'selected' : '' }}>
                                                        {{ $site }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td>
                                            <input form="{{ $formId }}" name="department_code"
                                                value="{{ $row->department_code ?? '' }}" class="form-control form-control-sm" required>
                                        </td>
                                        <td>
                                            <input form="{{ $formId }}" name="department_name"
                                                value="{{ $row->department_name ?? '' }}" class="form-control form-control-sm" required>
                                        </td>
                                        <td>
                                            <input form="{{ $formId }}" name="division_group"
                                                value="{{ $row->division_group ?? '' }}" class="form-control form-control-sm"
                                                list="division-group-options" required>
                                        </td>
                                        <td class="text-center">
                                            <input form="{{ $formId }}" type="checkbox" name="is_active" value="1"
                                                class="form-check-input" {{ (int) ($row->is_active ?? 0) === 1 ? 'checked' : '' }}>
                                        </td>
                                        <td>
                                            <input form="{{ $formId }}" name="remark" value="{{ $row->remark ?? '' }}"
                                                class="form-control form-control-sm">
                                        </td>
                                        <td class="text-end">
                                            <button form="{{ $formId }}" type="submit" class="btn btn-sm btn-primary" title="Save">
                                                <i class="fa-solid fa-floppy-disk"></i>
                                            </button>
                                            @if ((int) ($row->is_active ?? 0) === 1)
                                                <form method="POST"
                                                    action="{{ route('accounting.divisionGroup.master.destroy', $row->id) }}"
                                                    class="d-inline">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Disable">
                                                        <i class="fa-solid fa-ban"></i>
                                                    </button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
