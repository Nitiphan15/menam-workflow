@extends('layouts.layout')
@section('title', 'Sales Forecast Documents')
@section('page-title', 'Sales Forecast Documents')

@section('content')
    <div class="container-fluid">
        <style>
            .fc-card {
                border: 0;
                border-radius: 12px;
                box-shadow: 0 6px 18px rgba(0, 0, 0, .06);
            }

            .doc-toolbar {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                align-items: center;
                justify-content: flex-end;
            }

            .doc-table th,
            .doc-table td {
                vertical-align: middle;
            }

            .status-pill {
                display: inline-flex;
                align-items: center;
                border-radius: 999px;
                padding: 3px 9px;
                font-size: 12px;
                font-weight: 700;
                white-space: nowrap;
            }

            .status-pending {
                background: #fff3cd;
                color: #7a5800;
            }

            .status-approved {
                background: #d1e7dd;
                color: #0f5132;
            }

            .status-rejected {
                background: #f8d7da;
                color: #842029;
            }

            .status-other {
                background: #e9ecef;
                color: #495057;
            }

            .doc-row:hover td {
                background: #f8fafc;
            }

            .muted-note {
                color: #6c757d;
                font-size: 12px;
            }
        </style>

        @if (session('success'))
            <div class="alert alert-success py-2">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger py-2">{{ session('error') }}</div>
        @endif

        @if (!empty($tableMissing))
            <div class="alert alert-warning py-2">
                Missing table `fc_rm_division_forecast_submissions`.
                Please run `database/sql/create_fc_division_forecast_workflow.sql`.
            </div>
        @endif

        <div class="card fc-card mb-3">
            <div class="card-header bg-white d-flex flex-wrap gap-2 justify-content-between align-items-center">
                <div>
                    <div class="fw-semibold">Division Forecast Document List</div>
                    <div class="muted-note">Shows submitted forecast documents by month from the workflow table.</div>
                </div>
                <div class="doc-toolbar">
                    <a href="{{ route('fc.division') }}" class="btn btn-sm btn-outline-secondary">Division Forecast</a>
                    @if (!empty($canApproveDivisionForecast))
                        <a href="{{ route('fc.division.approvals') }}" class="btn btn-sm btn-outline-dark">Approval List</a>
                    @endif
                    <a href="{{ route('fc.division.documents.export', request()->query()) }}"
                        class="btn btn-sm btn-success">Export Excel</a>
                </div>
            </div>
            <div class="card-body">
                <form method="get" action="{{ route('fc.division.documents') }}" class="row g-2 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label">Month</label>
                        <input type="month" name="month" class="form-control form-control-sm"
                            value="{{ \Carbon\Carbon::parse($month)->format('Y-m') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Division</label>
                        <select name="division" class="form-select form-select-sm">
                            <option value="">All</option>
                            @foreach ($allowedDivisions as $div)
                                <option value="{{ $div }}" @selected($division === $div)>
                                    {{ $divisionLabels[$div] ?? $div }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            @foreach (['ALL' => 'All', 'PENDING_APPROVAL' => 'Pending Approval', 'APPROVED' => 'Approved', 'CLOSED' => 'Closed', 'REJECTED' => 'Rejected'] as $value => $label)
                                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Search</label>
                        <input type="text" name="q" class="form-control form-control-sm"
                            value="{{ $q }}" placeholder="Form no, division, requester">
                    </div>
                    <div class="col-md-1 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary flex-fill">Search</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card fc-card">
            <div class="table-responsive">
                <table class="table table-sm table-hover doc-table mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Form No</th>
                            <th>Division</th>
                            <th>Month</th>
                            <th>Status</th>
                            <th>Workflow Step</th>
                            <th>Requester</th>
                            <th>Submitted At</th>
                            <th>Approved At</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr class="doc-row">
                                <td class="fw-semibold">{{ $row->form_no ?? ($row->wf_form_no ?? '-') }}</td>
                                <td>{{ $divisionLabels[$row->sales_code] ?? $row->sales_code }}</td>
                                <td>{{ \Carbon\Carbon::parse($row->forecast_base_month)->format('m/Y') }}</td>
                                <td>
                                    <span class="status-pill {{ $row->status_class ?? 'status-other' }}">
                                        {{ $row->display_status ?? ($row->status ?? '-') }}
                                    </span>
                                </td>
                                <td>
                                    {{ $row->wf_current_step_name ?? '-' }}
                                    @if (!empty($row->wf_current_step))
                                        <div class="muted-note">Step {{ $row->wf_current_step }}</div>
                                    @endif
                                </td>
                                <td>{{ $row->requester_name ?? '-' }}</td>
                                <td>{{ $row->submitted_at ? \Carbon\Carbon::parse($row->submitted_at)->format('d/m/Y H:i') : '-' }}</td>
                                <td>{{ $row->approved_at ? \Carbon\Carbon::parse($row->approved_at)->format('d/m/Y H:i') : '-' }}</td>
                                <td class="text-end">
                                    @if (!empty($row->can_open_current_month))
                                        <a href="{{ !empty($row->can_approve) ? route('fc.division.approval', ['division' => $row->sales_code]) : route('fc.division', ['division' => $row->sales_code]) }}"
                                            class="btn btn-sm {{ !empty($row->can_approve) ? 'btn-primary' : 'btn-outline-secondary' }}">
                                            {{ !empty($row->can_approve) ? 'Approve' : 'Open' }}
                                        </a>
                                    @else
                                        <span class="muted-note">History only</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
                                    No forecast documents found for this filter.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if (method_exists($rows, 'links'))
                <div class="card-footer bg-white">
                    {{ $rows->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection
