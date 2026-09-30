@extends('layouts.layout')
@section('title', 'Sales Forecast Approval')
@section('page-title', 'Sales Forecast Approval')

@section('content')
    <div class="container-fluid">
        <style>
            .fc-card {
                border: 0;
                border-radius: 12px;
                box-shadow: 0 6px 18px rgba(0, 0, 0, .06);
            }

            .approval-table th,
            .approval-table td {
                vertical-align: middle;
            }

            .status-pill {
                display: inline-flex;
                align-items: center;
                border-radius: 999px;
                padding: 3px 9px;
                font-size: 12px;
                font-weight: 700;
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

            .approval-row {
                cursor: pointer;
            }

            .approval-row:hover td {
                background: #f8fafc;
            }
        </style>


        @if (!empty($tableMissing))
            <div class="alert alert-warning py-2">
                ยังไม่พบ table workflow ของ FormFC กรุณารัน SQL ใน `database/sql/create_fc_division_forecast_workflow.sql`
            </div>
        @endif

        <div class="card fc-card mb-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <div class="fw-semibold">รายการ Division ที่ Submit Forecast มาแล้ว</div>
                <div class="d-flex gap-2">
                    <a href="{{ route('fc.division.documents') }}" class="btn btn-sm btn-outline-dark">Documents</a>
                    <a href="{{ route('fc.division') }}" class="btn btn-sm btn-outline-secondary">Division Forecast</a>
                </div>
            </div>
            <div class="card-body">
                <form method="get" action="{{ route('fc.division.approvals') }}" class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Month</label>
                        <input type="month" name="month" class="form-control form-control-sm"
                            value="{{ \Carbon\Carbon::parse($month)->format('Y-m') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            @foreach (['PENDING_APPROVAL' => 'Pending Approval', 'APPROVED' => 'Approved', 'REJECTED' => 'Rejected', 'ALL' => 'All'] as $value => $label)
                                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Search</label>
                        <input type="text" name="q" class="form-control form-control-sm"
                            value="{{ $q }}" placeholder="Division, form no, requester">
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary flex-fill">Search</button>
                        <a href="{{ route('fc.division.approvals') }}"
                            class="btn btn-sm btn-outline-secondary flex-fill">Clear</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card fc-card">
            <div class="table-responsive">
                <table class="table table-sm table-hover approval-table mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Form No</th>
                            <th>Division</th>
                            <th>Month</th>
                            <th>Status</th>
                            <th>Workflow Step</th>
                            <th>Requester</th>
                            <th>Submitted At</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            @php
                                $rowStatus = strtoupper((string) ($row->status ?? ''));
                                $statusClass = match ($rowStatus) {
                                    'PENDING_APPROVAL', 'SUBMITTED' => 'status-pending',
                                    'APPROVED' => 'status-approved',
                                    'REJECTED' => 'status-rejected',
                                    default => 'status-other',
                                };
                            @endphp
                            <tr class="approval-row"
                                data-href="{{ route('fc.division.approval', ['division' => $row->sales_code]) }}">
                                <td class="fw-semibold">
                                    <a href="{{ route('fc.division.approval', ['division' => $row->sales_code]) }}"
                                        class="text-decoration-none">
                                        {{ $row->form_no ?? ($row->wf_form_no ?? '-') }}
                                    </a>
                                </td>
                                <td>{{ $divisionLabels[$row->sales_code] ?? $row->sales_code }}</td>
                                <td>{{ \Carbon\Carbon::parse($row->forecast_base_month)->format('m/Y') }}</td>
                                <td><span class="status-pill {{ $statusClass }}">{{ $rowStatus ?: '-' }}</span></td>
                                <td>
                                    {{ $row->wf_current_step_name ?? '-' }}
                                    @if (!empty($row->wf_status))
                                        <div class="small text-muted">{{ $row->wf_status }}</div>
                                    @endif
                                </td>
                                <td>{{ $row->requester_name ?? '-' }}</td>
                                <td>{{ $row->submitted_at ? \Carbon\Carbon::parse($row->submitted_at)->format('d/m/Y H:i') : '-' }}</td>
                                <td class="text-end">
                                    <a href="{{ route('fc.division.approval', ['division' => $row->sales_code]) }}"
                                        class="btn btn-sm {{ !empty($row->can_approve) ? 'btn-primary' : 'btn-outline-secondary' }}">
                                        {{ !empty($row->can_approve) ? 'Approve' : 'View' }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    ยังไม่มี Division ที่ submit Forecast มาตามเงื่อนไขนี้
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

@push('scripts')
    <script>
        document.querySelectorAll('.approval-row').forEach(row => {
            row.addEventListener('click', event => {
                if (event.target.closest('a, button, input, select, textarea, form')) {
                    return;
                }

                const href = row.dataset.href;
                if (href) {
                    window.location.href = href;
                }
            });
        });
    </script>
@endpush
