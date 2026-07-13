@extends('layouts.layout')
@section('title', !empty($isApprovalMode) ? 'Division Forecast Approval' : 'Division Forecast FG')
@section('page-title', !empty($isApprovalMode) ? 'Division Forecast Approval' : 'Division Forecast FG')

@section('content')
    <div class="container-fluid">
        <style>
            .fc-card {
                border: 0;
                border-radius: 14px;
                box-shadow: 0 6px 18px rgba(0, 0, 0, .06)
            }

            .fc-card .card-header {
                background: #fff;
                border-bottom: 1px solid #eef0f2
            }

            .excel-wrap {
                overflow: auto;
                max-height: calc(100vh - 320px);
                border: 1px solid #e6e8eb;
                background: #fff;
                border-radius: 14px
            }

            table.excel {
                border-collapse: separate;
                border-spacing: 0;
                font-size: 13.5px;
                width: max-content;
                min-width: 100%
            }

            table.excel th,
            table.excel td {
                border: 1px solid #eef0f2;
                padding: 6px 8px;
                white-space: nowrap;
                vertical-align: middle
            }

            table.excel thead th {
                position: sticky;
                background: #fbfbfc;
                text-align: center;
                font-weight: 600
            }

            table.excel thead tr:first-child th {
                top: 0;
                z-index: 7;
            }

            table.excel thead tr.filter-row th {
                top: 38px;
                z-index: 6;
            }

            .num {
                text-align: right;
                font-variant-numeric: tabular-nums
            }

            .kpi-title {
                font-size: 12px;
                color: #6c757d
            }

            .kpi-val {
                font-size: 24px;
                font-weight: 700;
                line-height: 1.15
            }

            .section-title {
                font-size: 15px;
                font-weight: 700
            }

            .empty-box {
                padding: 28px 16px;
                text-align: center;
                color: #6c757d;
                font-size: 14px
            }

            .btn-link.clean-link {
                text-decoration: none;
                font-weight: 600
            }

            .btn-link.clean-link:hover {
                text-decoration: underline
            }

            table.excel thead th .col-filter {
                width: 100%;
                font-size: 12px;
                padding: 2px 4px;
                font-weight: 400
            }

            table.excel th.sortable {
                cursor: pointer;
                user-select: none
            }

            table.excel th.sortable:hover {
                background: #f1f3f5
            }

            table.excel th.sortable .sort-ind {
                font-size: 10px;
                color: #6c757d;
                margin-left: 4px
            }

            .draft-banner {
                background: #fff3cd;
                border: 1px solid #ffe69c;
                color: #664d03;
                padding: 8px 12px;
                border-radius: 8px;
                margin-bottom: 12px;
                display: none
            }

            .draft-banner.show {
                display: flex;
                gap: 8px;
                align-items: center;
                justify-content: space-between
            }


            .fc-main-header {
                display: flex;
                flex-wrap: wrap;
                gap: 10px 14px;
                align-items: flex-start;
            }

            .fc-toolbar-title {
                flex: 1 1 360px;
                min-width: 280px;
            }

            .fc-toolbar-actions {
                flex: 1 1 520px;
                display: flex;
                flex-wrap: wrap;
                justify-content: flex-end;
                align-items: center;
                gap: 6px;
                min-width: 320px;
            }

            .fc-toolbar-actions #globalSearch {
                flex: 1 1 200px;
                max-width: 260px;
            }

            .k-stepper {
                min-width: 132px;
            }

            .k-stepper .form-control {
                min-width: 70px;
                text-align: right;
                font-variant-numeric: tabular-nums;
            }

            .k-stepper .btn {
                width: 28px;
                padding-left: 0;
                padding-right: 0;
            }

            .filter-actions-wrap {
                display: flex;
                gap: 8px;
                align-items: end;
            }

            .workflow-strip {
                display: flex;
                flex-wrap: wrap;
                gap: 10px 16px;
                align-items: center;
                justify-content: space-between;
                border: 1px solid #9eeaf9;
                background: #cff4fc;
                border-radius: 6px;
                padding: 10px 14px;
                color: #055160;
            }

            .workflow-strip-main {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                align-items: center;
            }

            .workflow-strip .badge {
                font-weight: 600;
            }

            .approval-action-form {
                display: grid;
                grid-template-columns: minmax(0, 1fr) auto;
                gap: 8px;
                align-items: center;
            }

            .approval-reject-form {
                display: grid;
                grid-template-columns: minmax(0, 1fr) auto;
                gap: 8px;
                align-items: center;
            }

            .reject-notice {
                border: 1px solid #f1aeb5;
                background: #f8d7da;
                color: #58151c;
                border-radius: 8px;
                padding: 12px 14px;
            }

            .reject-notice-reason {
                background: rgba(255, 255, 255, .65);
                border-radius: 6px;
                padding: 8px 10px;
                margin-top: 8px;
                white-space: pre-wrap;
            }

            .wf-timeline {
                position: relative;
                padding-left: 42px;
                margin: 0;
            }

            .wf-history-card.is-readonly .card-body {
                background: linear-gradient(180deg, #fbfcfe 0%, #f6f8fb 100%);
            }

            .wf-history-shell {
                display: grid;
                grid-template-columns: minmax(220px, 300px) minmax(0, 1fr);
                gap: 18px;
                align-items: stretch;
            }

            .wf-history-summary {
                border: 1px solid #e4e8ef;
                background: #fff;
                border-radius: 10px;
                padding: 14px;
                min-height: 150px;
            }

            .wf-summary-label {
                color: #6c757d;
                font-size: 12px;
                margin-bottom: 4px;
            }

            .wf-summary-value {
                font-weight: 700;
                font-size: 16px;
                line-height: 1.25;
            }

            .wf-history-panel {
                border: 1px solid #e4e8ef;
                background: #fff;
                border-radius: 10px;
                padding: 14px 16px;
            }

            .wf-history-panel .wf-timeline {
                max-width: 820px;
            }

            .wf-item {
                position: relative;
                padding: 12px 0 14px;
                border-bottom: 1px dashed #dce1e7;
            }

            .wf-item:last-child {
                border-bottom: 0;
                padding-bottom: 0;
            }

            .wf-rail {
                position: absolute;
                left: -28px;
                top: 0;
                bottom: 0;
                width: 2px;
                background: #dce1e7;
            }

            .wf-item:last-child .wf-rail {
                bottom: calc(100% - 22px);
            }

            .wf-dot {
                position: absolute;
                left: -38px;
                top: 11px;
                width: 22px;
                height: 22px;
                border-radius: 50%;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                background: #adb5bd;
                color: #fff;
                font-size: 11px;
                font-weight: 700;
            }

            .wf-dot.is-submit {
                background: #0d6efd;
            }

            .wf-dot.is-approve {
                background: #198754;
            }

            .wf-dot.is-reject {
                background: #dc3545;
            }

            .wf-title {
                font-weight: 700;
                line-height: 1.2;
            }

            .wf-meta {
                color: #6c757d;
                font-size: 12px;
            }

            .wf-comment {
                display: inline-block;
                margin-top: 8px;
                font-size: 13px;
                background: #f1f5f9;
                border: 1px solid #e2e8f0;
                border-radius: 7px;
                padding: 5px 8px;
            }

            @media (max-width: 991.98px) {
                .filter-actions-wrap {
                    align-items: stretch;
                }

                .approval-action-form,
                .approval-reject-form {
                    grid-template-columns: 1fr;
                }

                .wf-history-shell {
                    grid-template-columns: 1fr;
                }
            }



            .supplier-cell-wrap {
                position: relative;
                min-width: 220px;
            }

            .supplier-suggest-menu {
                position: absolute;
                left: 0;
                right: 0;
                top: calc(100% + 4px);
                z-index: 1060;
                max-height: 240px;
                overflow: auto;
                border-radius: 10px;
                box-shadow: 0 12px 28px rgba(15, 23, 42, .14);
            }

            .supplier-suggest-menu .list-group-item {
                padding: 8px 10px;
                font-size: 12px;
            }

            .supplier-suggest-code {
                display: inline-block;
                min-width: 70px;
                color: #64748b;
                font-size: 11px;
            }
        </style>

        @if (!empty($isSubmitted) && empty($isApprovalMode))
            <div class="alert alert-warning py-2">
                เดือนนี้ Division {{ $divisionLabels[$salesCode] ?? $salesCode }} submit แล้ว ระบบล็อกไม่ให้บันทึกทับอีก ต้องแก้ผ่านหน้า Approval
            </div>
        @endif
        @if (!empty($rejectedSubmission) && empty($isApprovalMode))
            <div class="reject-notice mb-3">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                    <div>
                        <div class="fw-semibold">Forecast รอบนี้ถูก Reject กรุณาแก้ไขแล้ว Submit ใหม่</div>
                        <div class="small">
                            {{ $rejectedSubmission->form_no ?? '-' }}
                            @if (!empty($rejectedSubmission->rejected_at))
                                | {{ \Carbon\Carbon::parse($rejectedSubmission->rejected_at)->format('d/m/Y H:i') }}
                            @endif
                        </div>
                    </div>
                    <span class="badge bg-danger">REJECTED</span>
                </div>
                @if (!empty($rejectedSubmission->reject_reason))
                    <div class="reject-notice-reason">{{ $rejectedSubmission->reject_reason }}</div>
                @endif
            </div>
        @endif
        @if (!empty($isApprovalMode) && empty($approvalTableAvailable))
            <div class="alert alert-warning py-2">
                หน้า Approval ต้องใช้ table `fc_rm_division_forecast_approval` กรุณารัน SQL ใน `database/sql/create_fc_division_forecast_approval.sql`
            </div>
        @endif
        @if (empty($submissionTableAvailable))
            <div class="alert alert-warning py-2">
                หน้า Division Workflow ต้องใช้ table `fc_rm_division_forecast_submissions` กรุณารัน SQL ใน `database/sql/create_fc_division_forecast_workflow.sql`
            </div>
        @endif
        @if (!empty($workflow))
            <div class="workflow-strip mb-3">
                <div class="workflow-strip-main">
                    <span class="fw-semibold">Workflow</span>
                    <span>{{ $workflow->form_no ?? '-' }}</span>
                    <span class="badge bg-primary">{{ $workflow->form_status ?? '-' }}</span>
                    <span class="badge bg-dark">Step {{ $workflow->current_step_no ?? '-' }}</span>
                </div>
                @if (!empty($isApprovalMode) && !empty($canApproveCurrentSubmission))
                    <span class="badge bg-warning text-dark">Waiting your approval</span>
                @endif
            </div>
        @endif
        @if (!empty($approvalNoSubmission))
            <div class="alert alert-warning py-2">
                Division {{ $divisionLabels[$salesCode] ?? $salesCode }} ยังไม่มีการบันทึก Forecast ของเดือนนี้ จึงยังไม่มีรายการให้ approve
            </div>
        @endif

        @if (empty($isApprovalMode))
        <div class="card fc-card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div class="fw-semibold">ตัวกรอง</div>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <a href="{{ route('fc.division.documents', ['division' => $salesCode]) }}"
                        class="btn btn-sm btn-outline-secondary">Documents</a>
                    <div class="small text-muted">
                        Division:
                        <b>{{ $divisionLabels[$salesCode] ?? $salesCode }}</b>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <form class="row g-3 align-items-end" method="get"
                    action="{{ !empty($isApprovalMode) ? route('fc.division.approval') : route('fc.division') }}">

                    <div class="col-md-3">
                        <label class="form-label">Division</label>

                        @if (!empty($showDivisionDropdown) && $showDivisionDropdown)
                            <select name="division" class="form-select form-select-sm">
                                @foreach ($allowedDivisions as $div)
                                    <option value="{{ $div }}" {{ $salesCode === $div ? 'selected' : '' }}>
                                        {{ $divisionLabels[$div] ?? $div }}
                                    </option>
                                @endforeach
                            </select>
                        @else
                            <input type="text" class="form-control form-control-sm"
                                value="{{ $divisionLabels[$salesCode] ?? $salesCode }}" readonly>
                            <input type="hidden" name="division" value="{{ $salesCode }}">
                        @endif
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">FG Part Filter</label>
                        <input type="text" name="fg" id="fgFilterInput" class="form-control form-control-sm"
                            value="{{ $fgLike ?? '' }}" placeholder="เช่น FMY309,FTY316 หรือ FMY309 FTY316">
                    </div>

                    <div class="col-md-3 position-relative">
                        <label class="form-label">Customer Filter</label>
                        <input type="text" name="customer_name" id="customerFilterInput"
                            class="form-control form-control-sm" value="{{ $customerNameText ?? '' }}"
                            placeholder="พิมพ์ชื่อลูกค้า หรือเลือกจากรายการ">
                        <input type="hidden" name="customer_id" id="customerFilterId" value="{{ $customerId ?? '' }}">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">K Factor Default</label>
                        <div class="input-group input-group-sm k-stepper">
                            <button type="button" class="btn btn-outline-secondary js-k-step" data-delta="-0.1">−</button>
                            <input type="text" class="form-control form-control-sm js-kfactor-input" name="k_factor"
                                inputmode="decimal" value="{{ number_format((float) $selectedK, 1, '.', '') }}">
                            <button type="button" class="btn btn-outline-secondary js-k-step" data-delta="0.1">+</button>
                        </div>

                    </div>

                    <div class="col-md-3 filter-actions-wrap">
                        <button type="submit" class="btn btn-sm btn-outline-primary flex-fill">โหลดข้อมูล</button>
                        <a href="{{ !empty($isApprovalMode) ? route('fc.division.approval', ['division' => $salesCode]) : route('fc.division', ['division' => $salesCode]) }}"
                            class="btn btn-sm btn-outline-secondary flex-fill">
                            ล้างตัวกรอง
                        </a>
                        @if (!empty($canApproveDivisionForecast))
                            <a href="{{ !empty($isApprovalMode) ? route('fc.division', ['division' => $salesCode]) : route('fc.division.approval', ['division' => $salesCode]) }}"
                                class="btn btn-sm btn-outline-dark flex-fill">
                                {{ !empty($isApprovalMode) ? 'Division View' : 'Approval' }}
                            </a>
                        @endif
                    </div>
                </form>
            </div>
        </div>
        @endif

        <div class="row g-2 mb-3">
            <div class="col-md-3">
                <div class="card fc-card">
                    <div class="card-body py-2">
                        <div class="kpi-title">ทั้งหมด</div>
                        <div class="kpi-val js-kpi-items">{{ number_format($kpi['items']) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card fc-card">
                    <div class="card-body py-2">
                        <div class="kpi-title">Avg 6M รวม</div>
                        <div class="kpi-val js-kpi-avg6">{{ number_format($kpi['avg6_sum'], 2) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card fc-card">
                    <div class="card-body py-2">
                        <div class="kpi-title">Forecast 1 เดือน</div>
                        <div class="kpi-val js-kpi-f1">{{ number_format($kpi['forecast_1m_sum'], 2) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card fc-card">
                    <div class="card-body py-2">
                        <div class="kpi-title">Forecast 6 เดือน</div>
                        <div class="kpi-val js-kpi-f6">{{ number_format($kpi['forecast_6m_sum'], 2) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <datalist id="supplier-options-list">
            @foreach ($suppliers as $sp)
                <option value="{{ $sp['label'] }}" data-code="{{ $sp['supplier_code'] }}"></option>
            @endforeach
        </datalist>

        <form id="gen-form" method="post"
            action="{{ !empty($isApprovalMode) ? route('fc.division.approval-save') : route('fc.division.generate') }}">
            @csrf
            <input type="hidden" name="sales_code" value="{{ $salesCode }}">
            <input type="hidden" name="division" value="{{ $salesCode }}">
            <input type="hidden" name="customer_id" value="{{ $customerId ?? '' }}">
            <input type="hidden" name="customer_name" value="{{ $customerNameText ?? '' }}">
            <input type="hidden" name="k_factor" id="kFactorHidden"
                value="{{ number_format((float) $selectedK, 1, '.', '') }}">
            <input type="hidden" name="submit_action" id="submitActionHidden" value="submit_approval">
            <input type="hidden" name="payload" id="payloadHidden" value="{{ old('payload', '') }}">
            @if (!empty($isApprovalMode))
                <input type="hidden" name="comment" id="approvalCommentHidden" value="">
            @endif

            <div class="card fc-card mb-3">
                <div class="card-header fc-main-header">
                    <div class="fc-toolbar-title">
                        <div class="section-title">Forecast ราย Customer + FG Part</div>
                        <div class="small text-muted">เดือนนี้ใช้ค่า save ก่อน, ถ้ายังไม่เคย save จะ fallback ไป Division
                            Part Master และ K default</div>
                        @if (empty($isApprovalMode))
                            <div class="small text-warning fw-semibold mt-1">
                                Tip: ถ้ามีรายการ Manual ให้กด Save Manual Draft ก่อน แล้วค่อยกด Submit Forecast เพื่อส่งข้อมูลเข้า approval
                            </div>
                        @endif
                    </div>
                    <div class="fc-toolbar-actions">
                        <input type="text" id="globalSearch" class="form-control form-control-sm"
                            placeholder="ค้นหาในตาราง...">
                        @if (empty($isApprovalMode))
                            <button type="submit" name="submit_action" value="save_draft"
                                class="btn btn-sm btn-primary" @disabled(!empty($isSubmitted))>
                                บันทึก Draft
                            </button>
                            <button type="submit" name="submit_action" value="submit_approval"
                                class="btn btn-sm btn-warning" @disabled(!empty($isSubmitted))>
                                ส่งให้หัวหน้ายืนยัน
                            </button>
                        @endif
                        <button type="button" class="btn btn-sm btn-outline-success" id="btnExportExcel">Export
                            Excel</button>
                        @if (empty($isApprovalMode))
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                id="checkAllForecast">เลือกทั้งหมด</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                id="uncheckAllForecast">เอาออกทั้งหมด</button>
                        @endif
                    </div>
                </div>

                <div id="draftBanner" class="draft-banner mx-3 mt-2" @if (!empty($isApprovalMode)) style="display:none" @endif>
                    <div>มีข้อมูลที่ยังไม่ได้บันทึกจากครั้งก่อน <span id="draftBannerTime"
                            class="text-muted small"></span></div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-warning" id="restoreDraftBtn">กู้คืนข้อมูล</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                            id="discardDraftBtn">ทิ้งร่าง</button>
                    </div>
                </div>

                @if ($rows->isEmpty())
                    <div class="empty-box">ไม่มีข้อมูลสำหรับ forecast</div>
                @else
                    <div class="excel-wrap">
                        <table class="excel">
                            <thead>
                                <tr>
                                    <th class="sortable" data-sort-col="0" data-sort-type="text">Customer<span
                                            class="sort-ind"></span></th>
                                    <th class="sortable" data-sort-col="1" data-sort-type="text">FG Part<span
                                            class="sort-ind"></span></th>
                                    <th class="sortable" data-sort-col="2" data-sort-type="text">Description<span
                                            class="sort-ind"></span></th>
                                    <th class="sortable" data-sort-col="3" data-sort-type="text">RM Part<span
                                            class="sort-ind"></span></th>
                                    <th class="sortable" data-sort-col="4" data-sort-type="num">Sales Order<span
                                            class="sort-ind"></span></th>
                                    <th class="sortable" data-sort-col="5" data-sort-type="num">Avg 6M<span
                                            class="sort-ind"></span></th>
                                    <th class="sortable" data-sort-col="6" data-sort-type="num">Forecast?<span
                                            class="sort-ind"></span></th>
                                    <th class="sortable" data-sort-col="7" data-sort-type="num">K ที่ใช้<span
                                            class="sort-ind"></span></th>
                                    <th class="sortable" data-sort-col="8" data-sort-type="num">{{ !empty($isApprovalMode) ? 'Approval 1M' : 'Forecast 1M' }}<span
                                            class="sort-ind"></span></th>
                                    <th class="sortable" data-sort-col="9" data-sort-type="num">{{ !empty($isApprovalMode) ? 'Approval 6M' : 'Forecast 6M' }}<span
                                            class="sort-ind"></span></th>
                                    <th class="sortable" data-sort-col="10" data-sort-type="text">Supplier<span
                                            class="sort-ind"></span></th>
                                    <th class="sortable" data-sort-col="11" data-sort-type="text">Remark<span
                                            class="sort-ind"></span></th>
                                    <th>History</th>
                                </tr>
                                <tr class="filter-row">
                                    <th><input type="text" class="form-control form-control-sm col-filter"
                                            data-filter-col="0" placeholder="กรอง"></th>
                                    <th><input type="text" class="form-control form-control-sm col-filter"
                                            data-filter-col="1" placeholder="กรอง"></th>
                                    <th><input type="text" class="form-control form-control-sm col-filter"
                                            data-filter-col="2" placeholder="กรอง"></th>
                                    <th><input type="text" class="form-control form-control-sm col-filter"
                                            data-filter-col="3" placeholder="กรอง RM"></th>
                                    <th><input type="text" class="form-control form-control-sm col-filter"
                                            data-filter-col="4" placeholder=">= ตัวเลข"></th>
                                    <th><input type="text" class="form-control form-control-sm col-filter"
                                            data-filter-col="5" placeholder=">= ตัวเลข"></th>
                                    <th>
                                        <select class="form-select form-select-sm col-filter" data-filter-col="6">
                                            <option value="" @selected(empty($isApprovalMode))>ทั้งหมด</option>
                                            <option value="1" @selected(!empty($isApprovalMode))>เลือกแล้ว</option>
                                            <option value="0">ไม่เลือก</option>
                                        </select>
                                    </th>
                                    <th><input type="text" class="form-control form-control-sm col-filter"
                                            data-filter-col="7" placeholder=">= K"></th>
                                    <th><input type="text" class="form-control form-control-sm col-filter"
                                            data-filter-col="8" placeholder=">= ตัวเลข"></th>
                                    <th><input type="text" class="form-control form-control-sm col-filter"
                                            data-filter-col="9" placeholder=">= ตัวเลข"></th>
                                    <th><input type="text" class="form-control form-control-sm col-filter"
                                            data-filter-col="10" placeholder="กรอง"></th>
                                    <th><input type="text" class="form-control form-control-sm col-filter"
                                            data-filter-col="11" placeholder="กรอง"></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    @php
                                        $meta = [
                                            'customer_id' => $r['customer_id'],
                                            'customer_name' => $r['customer_name'],
                                            'fg_partnumber' => $r['fg_partnumber'],
                                            'fg_description' => $r['fg_description'],
                                            'rm_partnumber' => $r['rm_partnumber'],
                                            'avg6' => (float) $r['avg6'],
                                            'sales_order_qty' => (float) ($r['sales_order_qty'] ?? 0),
                                            'division_forecast_1m' => (float) ($r['forecast_1m'] ?? 0),
                                            'division_forecast_6m' => (float) ($r['forecast_6m'] ?? 0),
                                        ];
                                    @endphp
                                    <tr data-row-key="{{ $r['row_key'] }}" data-avg6="{{ (float) $r['avg6'] }}">
                                        <td>{{ $r['customer_name'] }}</td>
                                        <td class="fw-semibold">{{ $r['fg_partnumber'] }}</td>
                                        <td>{{ $r['fg_description'] }}</td>
                                        <td class="fw-semibold text-muted">{{ $r['rm_partnumber'] ?: '-' }}</td>

                                        <td class="num">
                                            <button type="button" class="btn btn-link clean-link p-0 js-so-detail"
                                                data-fg="{{ $r['fg_partnumber'] }}" data-company="ALL">
                                                {{ number_format((float) ($r['sales_order_qty'] ?? 0), 2) }}
                                            </button>
                                        </td>

                                        <td class="num js-avg6">
                                            <button type="button" class="btn btn-link clean-link p-0 avg6-link"
                                                data-customer="{{ $r['customer_name'] }}"
                                                data-fg="{{ $r['fg_partnumber'] }}"
                                                data-description="{{ $r['fg_description'] }}"
                                                data-history='@json($r['history_detail'] ?? [])'>
                                                {{ number_format((float) $r['avg6'], 2) }}
                                            </button>
                                        </td>

                                        <td class="text-center">
                                            <input type="checkbox" class="js-forecast-flag"
                                                name="forecast_flag[{{ $r['row_key'] }}]" value="1"
                                                @checked((int) ($r['is_selected'] ?? 0) === 1) @disabled(!empty($isApprovalMode) || (!empty($isSubmitted) && empty($isApprovalMode)))>
                                        </td>

                                        <td>
                                            <div class="input-group input-group-sm k-stepper">
                                                <button type="button" class="btn btn-outline-secondary js-k-step"
                                                    data-delta="-0.1"
                                                    @disabled((!empty($isSubmitted) && empty($isApprovalMode)) || (!empty($isApprovalMode) && empty($canApproveCurrentSubmission)))>−</button>
                                                <input type="text" name="row_k_factor[{{ $r['row_key'] }}]"
                                                    class="form-control form-control-sm js-row-kfactor js-kfactor-input"
                                                    inputmode="decimal"
                                                    value="{{ number_format((float) (!empty($isApprovalMode) ? ($r['approval_k_factor'] ?? $r['row_k_factor'] ?? ($r['k_used'] ?? $selectedK)) : ($r['row_k_factor'] ?? ($r['k_used'] ?? $selectedK))), 1, '.', '') }}"
                                                    @readonly((!empty($isSubmitted) && empty($isApprovalMode)) || (!empty($isApprovalMode) && empty($canApproveCurrentSubmission)))>
                                                <button type="button" class="btn btn-outline-secondary js-k-step"
                                                    data-delta="0.1"
                                                    @disabled((!empty($isSubmitted) && empty($isApprovalMode)) || (!empty($isApprovalMode) && empty($canApproveCurrentSubmission)))>+</button>
                                            </div>
                                        </td>

                                        <td>
                                            <input type="number" step="0.01" min="0"
                                                class="form-control form-control-sm js-manual-forecast"
                                                name="{{ !empty($isApprovalMode) ? 'approval_forecast_1m' : 'manual_forecast_1m' }}[{{ $r['row_key'] }}]"
                                                value="{{ number_format((float) (!empty($isApprovalMode) ? ($r['approval_forecast_1m'] ?? $r['forecast_1m'] ?? 0) : ($r['manual_forecast_1m'] ?? 0)), 2, '.', '') }}"
                                                data-user-edited="{{ !empty($r['manual_forecast_saved']) ? '1' : '0' }}"
                                                @readonly((!empty($isSubmitted) && empty($isApprovalMode)) || (!empty($isApprovalMode) && empty($canApproveCurrentSubmission)))>
                                        </td>

                                        <td class="num js-f6">{{ number_format((float) $r['forecast_6m'], 2) }}</td>

                                        <td>
                                            <div class="supplier-cell-wrap js-supplier-wrap">
                                                <input type="text"
                                                    class="form-control form-control-sm js-supplier-input"
                                                    name="supplier_name_manual[{{ $r['row_key'] }}]"
                                                    value="{{ $r['supplier_name'] ?? '' }}"
                                                    placeholder="พิมพ์/เลือก supplier">
                                                <div class="list-group supplier-suggest-menu d-none js-supplier-menu">
                                                </div>
                                            </div>
                                            <input type="hidden" class="js-supplier-code"
                                                name="supplier_code[{{ $r['row_key'] }}]"
                                                value="{{ $r['supplier_code'] ?? '' }}">
                                        </td>

                                        <td>
                                            <input type="text" class="form-control form-control-sm"
                                                name="{{ !empty($isApprovalMode) ? 'approval_remark' : 'row_remark' }}[{{ $r['row_key'] }}]"
                                                value="{{ !empty($isApprovalMode) ? ($r['approval_remark'] ?? '') : ($r['row_remark'] ?? '') }}" placeholder="Remark"
                                                @readonly((!empty($isSubmitted) && empty($isApprovalMode)) || (!empty($isApprovalMode) && empty($canApproveCurrentSubmission)))>
                                        </td>

                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline-secondary btn-history"
                                                data-customer="{{ $r['customer_name'] }}"
                                                data-fg="{{ $r['fg_partnumber'] }}"
                                                data-description="{{ $r['fg_description'] }}"
                                                data-history='@json($r['save_history'] ?? [])'>
                                                ดู
                                            </button>
                                        </td>

                                        <input type="hidden" name="row_meta[{{ $r['row_key'] }}]"
                                            value='@json($meta)'>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </form>

        @if (!empty($isApprovalMode) && (!empty($canApproveCurrentSubmission) || (!empty($workflowHistory) && $workflowHistory->count())))
            <div class="card fc-card wf-history-card {{ empty($canApproveCurrentSubmission) ? 'is-readonly' : '' }} mb-3">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="fw-semibold">
                        {{ !empty($canApproveCurrentSubmission) ? 'Approval Action' : 'Workflow History' }}
                    </div>
                    <div class="small text-muted">
                        {{ $workflow->form_no ?? '-' }} | Step {{ $workflow->current_step_no ?? '-' }}
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-4 align-items-start">
                        @if (!empty($canApproveCurrentSubmission))
                            <div class="col-lg-7">
                                <label class="form-label">Approval Comment</label>
                                <div class="approval-action-form mb-3">
                                    <input type="text" class="form-control form-control-sm flex-grow-1" id="approvalCommentInput"
                                        placeholder="Comment">
                                    <button type="submit" class="btn btn-sm btn-success" form="gen-form">Approve</button>
                                </div>

                                <label class="form-label">Reject Reason</label>
                                <form method="post" action="{{ route('fc.division.approval-reject') }}" class="approval-reject-form">
                                    @csrf
                                    <input type="hidden" name="sales_code" value="{{ $salesCode }}">
                                    <input type="text" name="comment" class="form-control form-control-sm" required
                                        placeholder="Reject reason">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Reject</button>
                                </form>
                            </div>
                        @endif
                        <div class="{{ !empty($canApproveCurrentSubmission) ? 'col-lg-5' : 'col-12' }}">
                            <div class="{{ empty($canApproveCurrentSubmission) ? 'wf-history-shell' : '' }}">
                                @if (empty($canApproveCurrentSubmission))
                                    <div class="wf-history-summary">
                                        <div class="wf-summary-label">Status</div>
                                        <div class="wf-summary-value mb-3">{{ $workflow->form_status ?? '-' }}</div>

                                        <div class="wf-summary-label">Form No</div>
                                        <div class="fw-semibold mb-3">{{ $workflow->form_no ?? '-' }}</div>

                                        <div class="wf-summary-label">Current Step</div>
                                        <span class="badge bg-dark">Step {{ $workflow->current_step_no ?? '-' }}</span>
                                    </div>
                                @endif
                                <div class="{{ empty($canApproveCurrentSubmission) ? 'wf-history-panel' : '' }}">
                                    <div class="fw-semibold mb-2">Workflow History</div>
                                    @if (!empty($workflowHistory) && $workflowHistory->count())
                                        <ul class="wf-timeline list-unstyled">
                                            @foreach ($workflowHistory as $h)
                                                @php
                                                    $action = strtolower((string) ($h->action_type ?? ''));
                                                    $actionLabel = match ($action) {
                                                        'submit' => 'Submitted',
                                                        'approve' => 'Approved',
                                                        'reject' => 'Rejected',
                                                        default => ucfirst($action ?: '-'),
                                                    };
                                                    $dotClass = match ($action) {
                                                        'submit' => 'is-submit',
                                                        'approve' => 'is-approve',
                                                        'reject' => 'is-reject',
                                                        default => '',
                                                    };
                                                @endphp
                                                <li class="wf-item">
                                                    <span class="wf-rail"></span>
                                                    <span class="wf-dot {{ $dotClass }}">{{ $h->step_no ?? '-' }}</span>
                                                    <div class="wf-title">{{ $actionLabel }}</div>
                                                    <div class="wf-meta">
                                                        {{ !empty($h->created_at) ? \Carbon\Carbon::parse($h->created_at)->format('d/m/Y H:i') : '-' }}
                                                        &middot; Step {{ $h->step_no ?? '-' }}
                                                        @if (!empty($h->actor_name))
                                                            &middot; by {{ $h->actor_name }}
                                                        @endif
                                                    </div>
                                                    @if (!empty($h->comment))
                                                        <div class="wf-comment text-dark">{{ $h->comment }}</div>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @else
                                        <div class="text-muted small">ไม่มีประวัติการทำรายการ</div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if (empty($isApprovalMode))
        <form id="manualForecastForm" method="post" action="{{ route('fc.division.manual-save') }}">
            @csrf
            <input type="hidden" name="sales_code" value="{{ $salesCode }}">
            <input type="hidden" name="division" value="{{ $salesCode }}">

            <div class="card fc-card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <div class="section-title">Manual Forecast</div>
                        <div class="small text-muted">เฉพาะรายการที่ sales ดูแล แต่ไม่มีข้อมูลย้อนหลัง 6 เดือน</div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="addManualRow" @disabled(!empty($isSubmitted))>
                            เพิ่ม Manual Row
                        </button>
                        <button type="submit" class="btn btn-sm btn-primary" @disabled(!empty($isSubmitted))>Save Manual Draft</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="toggleManualForecast"
                            data-bs-toggle="collapse" data-bs-target="#manualForecastCollapse"
                            aria-expanded="true" aria-controls="manualForecastCollapse" title="ย่อ/ขยายตาราง Manual">
                            <span class="js-toggle-caret">▲</span>
                            <span class="js-toggle-label">ย่อ</span>
                        </button>
                    </div>
                </div>

                <div id="manualForecastCollapse" class="collapse show">
                    @if (empty($manualOnlyRows) || $manualOnlyRows->isEmpty())
                        <div class="px-3 pt-3 small text-muted">ยังไม่มีรายการตั้งต้น คุณสามารถกด "เพิ่ม Manual Row" เพื่อเพิ่ม
                            customer และ FG เองได้</div>
                    @endif
                    <div class="excel-wrap">
                        <table class="excel">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>FG Part</th>
                                    <th>RM Part</th>
                                    <th>Manual 1M</th>
                                    <th>Forecast 6M</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="manualForecastBody">
                                @foreach ($manualOnlyRows as $r)
                                    @php
                                        $manualMeta = [
                                            'customer_id' => $r['customer_id'] ?? 0,
                                            'customer_name' => $r['customer_name'] ?? '',
                                            'fg_partnumber' => $r['fg_partnumber'] ?? '',
                                            'fg_description' => $r['fg_description'] ?? '',
                                            'rm_partnumber' => $r['rm_partnumber'] ?? '',
                                        ];
                                    @endphp
                                    <tr class="js-manual-row" data-row-key="{{ $r['row_key'] }}">
                                        <td>{{ $r['customer_name'] ?? '-' }}</td>
                                        <td class="fw-semibold">{{ $r['fg_partnumber'] ?? '-' }}</td>
                                        <td class="js-manual-rm-cell">{{ $r['rm_partnumber'] ?? '-' }}</td>
                                        <td>
                                            <input type="number" step="0.01" min="0"
                                                class="form-control form-control-sm text-end js-manual-1m-only"
                                                name="manual_rows[{{ $r['row_key'] }}]"
                                                value="{{ number_format((float) ($r['manual_forecast_1m'] ?? 0), 2, '.', '') }}">
                                        </td>
                                        <td class="num js-manual-6m-only">
                                            {{ number_format((float) (($r['manual_forecast_1m'] ?? 0) * 6), 2) }}
                                        </td>
                                        <td class="text-center text-muted">-</td>
                                        <input type="hidden" name="manual_meta[{{ $r['row_key'] }}]"
                                            value='@json($manualMeta)'>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </form>
        @endif

        <template id="manualRowTemplate">
            <tr class="js-manual-row">
                <td class="position-relative">
                    <input type="text" class="form-control form-control-sm js-manual-customer-input"
                        placeholder="ค้นหา customer">
                    <div class="list-group position-absolute w-100 shadow-sm d-none js-manual-customer-suggest"
                        style="z-index: 20; max-height: 220px; overflow: auto;"></div>
                </td>
                <td class="position-relative">
                    <input type="text" class="form-control form-control-sm js-manual-fg-input"
                        placeholder="ค้นหา FG Part">
                    <div class="list-group position-absolute w-100 shadow-sm d-none js-manual-fg-suggest"
                        style="z-index: 20; max-height: 220px; overflow: auto;"></div>
                </td>
                <td class="js-manual-rm-cell">-</td>
                <td>
                    <input type="number" step="0.01" min="0"
                        class="form-control form-control-sm text-end js-manual-1m-only">
                </td>
                <td class="num js-manual-6m-only">0.00</td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger js-remove-manual-row">ลบ</button>
                </td>
            </tr>
        </template>

        <div class="modal fade" id="historyModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <div class="fw-semibold" id="historyModalTitle">ประวัติการบันทึก</div>
                            <div class="small text-muted" id="historyModalSub"></div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0">
                                <thead>
                                    <tr>
                                        <th>เดือนที่บันทึก</th>
                                        <th class="text-end">K Factor</th>
                                        <th class="text-end">Forecast 1M</th>
                                        <th class="text-end">Forecast 6M</th>
                                        <th>Source</th>
                                        <th>เวลาบันทึก</th>
                                    </tr>
                                </thead>
                                <tbody id="historyModalBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="avg6Modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <div class="fw-semibold" id="avg6ModalTitle">Avg 6M รายเดือน</div>
                            <div class="small text-muted" id="avg6ModalSub"></div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0">
                                <thead>
                                    <tr>
                                        <th>เดือน</th>
                                        <th class="text-end">Wire</th>
                                        <th class="text-end">Plus</th>
                                        <th class="text-end">รวม</th>
                                    </tr>
                                </thead>
                                <tbody id="avg6ModalBody"></tbody>
                                <tfoot>
                                    <tr class="table-light">
                                        <th>รวม 6 เดือน</th>
                                        <th class="text-end" id="avg6FootWire">0.00</th>
                                        <th class="text-end" id="avg6FootPlus">0.00</th>
                                        <th class="text-end" id="avg6FootTotal">0.00</th>
                                    </tr>
                                    <tr>
                                        <th>เฉลี่ย</th>
                                        <th class="text-end" id="avg6AvgWire">0.00</th>
                                        <th class="text-end" id="avg6AvgPlus">0.00</th>
                                        <th class="text-end fw-bold" id="avg6AvgTotal">0.00</th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="soDetailModal" tabindex="-1">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Sales Order Detail</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div id="soDetailLoading" class="text-muted">Loading...</div>
                        <div class="table-responsive d-none" id="soDetailWrap">
                            <table class="table table-sm table-bordered">
                                <thead>
                                    <tr>
                                        <th>Company</th>
                                        <th>FG Part</th>
                                        <th>Description</th>
                                        <th>PO</th>
                                        <th>SO</th>
                                        <th>Order Date</th>
                                        <th>Due Date</th>
                                        <th>Customer</th>
                                        <th class="text-end">Backorder Qty</th>
                                    </tr>
                                </thead>
                                <tbody id="soDetailBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const OLD_PAYLOAD_JSON = @json(old('payload'));

        document.getElementById('checkAllForecast')?.addEventListener('click', () => {
            document.querySelectorAll('table.excel tbody .js-forecast-flag').forEach(el => el.checked = true);
            document.querySelectorAll('table.excel tbody tr[data-row-key]').forEach(recalcRow);
            recalcKpi();
        });

        document.getElementById('uncheckAllForecast')?.addEventListener('click', () => {
            document.querySelectorAll('table.excel tbody .js-forecast-flag').forEach(el => el.checked = false);
            document.querySelectorAll('table.excel tbody tr[data-row-key]').forEach(recalcRow);
            recalcKpi();
        });

        function formatNum(n, digits = 2) {
            return Number(n || 0).toLocaleString(undefined, {
                minimumFractionDigits: digits,
                maximumFractionDigits: digits
            });
        }

        function recalcManualOnlyRow(tr) {
            if (!tr) return;
            const input = tr.querySelector('.js-manual-1m-only');
            const f6El = tr.querySelector('.js-manual-6m-only');
            if (!input || !f6El) return;

            const f1 = parseFloat(input.value || '0') || 0;
            f6El.textContent = formatNum(f1 * 6);
        }

        function bindManual1mInput(input) {
            if (!input) return;
            input.addEventListener('input', function() {
                recalcManualOnlyRow(this.closest('tr'));
            });

            input.addEventListener('change', function() {
                recalcManualOnlyRow(this.closest('tr'));
            });

            recalcManualOnlyRow(input.closest('tr'));
        }

        function debounce(fn, wait = 250) {
            let timer = null;
            return function(...args) {
                clearTimeout(timer);
                timer = setTimeout(() => fn.apply(this, args), wait);
            };
        }

        function hideSuggest(el) {
            if (!el) return;
            el.classList.add('d-none');
            el.innerHTML = '';
        }

        function bindAutocompleteInput({
            input,
            suggestEl,
            fetchItems,
            renderItem,
            onSelect
        }) {
            if (!input || !suggestEl) return;

            const runLookup = debounce(async () => {
                const term = input.value.trim();
                if (term.length < 2) {
                    hideSuggest(suggestEl);
                    return;
                }

                try {
                    const items = await fetchItems(term);
                    if (!Array.isArray(items) || !items.length) {
                        hideSuggest(suggestEl);
                        return;
                    }

                    suggestEl.innerHTML = items.map(renderItem).join('');
                    suggestEl.classList.remove('d-none');
                } catch (err) {
                    hideSuggest(suggestEl);
                }
            }, 250);

            input.addEventListener('input', runLookup);
            input.addEventListener('focus', runLookup);
            input.addEventListener('blur', () => setTimeout(() => hideSuggest(suggestEl), 150));

            suggestEl.addEventListener('click', function(e) {
                const btn = e.target.closest('button[data-payload]');
                if (!btn) return;
                try {
                    onSelect(JSON.parse(decodeURIComponent(btn.dataset.payload || '%7B%7D')));
                } finally {
                    hideSuggest(suggestEl);
                }
            });
        }

        const customerFilterInput = document.getElementById('customerFilterInput');
        const customerFilterId = document.getElementById('customerFilterId');
        let customerSuggestEl = document.getElementById('customerFilterSuggest');

        if (customerFilterInput) {
            if (!customerSuggestEl) {
                customerSuggestEl = document.createElement('div');
                customerSuggestEl.id = 'customerFilterSuggest';
                customerSuggestEl.className =
                    'list-group position-absolute w-100 shadow-sm d-none';
                customerSuggestEl.style.zIndex = '20';
                customerSuggestEl.style.maxHeight = '220px';
                customerSuggestEl.style.overflow = 'auto';
                customerFilterInput.insertAdjacentElement('afterend', customerSuggestEl);
            }

            const customerLookupBaseUrl = `{{ route('fc.division.customer.lookup') }}`;
            let customerLookupTimer = null;

            const hideCustomerSuggest = () => {
                customerSuggestEl.classList.add('d-none');
                customerSuggestEl.innerHTML = '';
            };

            customerFilterInput.addEventListener('input', function() {
                if (customerFilterId) customerFilterId.value = '';
                const term = this.value.trim();
                const divisionEl = document.querySelector('select[name="division"], input[name="division"]');
                const division = divisionEl?.value || `{{ $salesCode }}`;

                clearTimeout(customerLookupTimer);

                if (term.length < 2) {
                    hideCustomerSuggest();
                    return;
                }

                customerLookupTimer = setTimeout(async () => {
                    try {
                        const url =
                            `${customerLookupBaseUrl}?division=${encodeURIComponent(division)}&q=${encodeURIComponent(term)}`;
                        const res = await fetch(url, {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });
                        const data = await res.json();
                        const items = Array.isArray(data?.items) ? data.items : [];

                        if (!items.length) {
                            hideCustomerSuggest();
                            return;
                        }

                        customerSuggestEl.innerHTML = items.map(item => `
                            <button type="button" class="list-group-item list-group-item-action js-customer-suggest"
                                data-id="${String(item.id ?? '').replace(/"/g, '&quot;')}"
                                data-name="${String(item.customer_name ?? item.text ?? '').replace(/"/g, '&quot;')}"
                                data-text="${String(item.text ?? '').replace(/"/g, '&quot;')}">
                                ${item.text ?? ''}
                            </button>
                        `).join('');
                        customerSuggestEl.classList.remove('d-none');
                    } catch (err) {
                        hideCustomerSuggest();
                    }
                }, 250);
            });

            customerSuggestEl.addEventListener('click', function(e) {
                const btn = e.target.closest('.js-customer-suggest');
                if (!btn) return;
                customerFilterInput.value = btn.dataset.name || btn.dataset.text || '';
                if (customerFilterId) customerFilterId.value = btn.dataset.id || '';
                hideCustomerSuggest();
            });

            customerFilterInput.addEventListener('blur', () => {
                setTimeout(hideCustomerSuggest, 150);
            });
        }

        document.querySelectorAll('.js-manual-1m-only').forEach(bindManual1mInput);

        const manualBodyEl = document.getElementById('manualForecastBody');
        const manualTpl = document.getElementById('manualRowTemplate');
        const addManualRowBtn = document.getElementById('addManualRow');
        const manualFormEl = document.getElementById('manualForecastForm');
        const divisionEl = document.querySelector('select[name="division"], input[name="division"]');
        const manualCustomerLookupUrl = `{{ route('fc.division.customer.lookup') }}`;
        const manualPartLookupUrl = `{{ route('fc.division.part.lookup') }}`;
        let manualRowSeq = 0;

        function manualRowMeta(tr) {
            const metaInput = tr?.querySelector('.js-manual-meta, input[name^="manual_meta["]');
            if (!metaInput) return {};

            try {
                const parsed = JSON.parse(metaInput.value || '{}');
                return parsed && typeof parsed === 'object' ? parsed : {};
            } catch (err) {
                return {};
            }
        }

        function manualRowIdentity(tr) {
            const meta = manualRowMeta(tr);
            const customerId = String(meta.customer_id || tr?.dataset.customerId || '').trim();
            const customerName = String(meta.customer_name || tr?.dataset.customerName || '').trim();
            const fgPartnumber = String(meta.fg_partnumber || tr?.dataset.fgPartnumber || '').trim().toUpperCase();

            return {
                key: customerId && fgPartnumber ? `${customerId}|${fgPartnumber}` : '',
                label: `${customerName || customerId || '-'} / ${fgPartnumber || '-'}`,
            };
        }

        function syncManualMeta(tr) {
            const metaInput = tr?.querySelector('.js-manual-meta');
            if (!metaInput) return;

            const meta = {
                customer_id: tr.dataset.customerId || '',
                customer_name: tr.dataset.customerName || '',
                fg_partnumber: tr.dataset.fgPartnumber || '',
                fg_description: tr.dataset.fgDescription || '',
                rm_partnumber: tr.dataset.rmPartnumber || '',
            };

            metaInput.value = JSON.stringify(meta);
        }

        function bindManualDynamicRow(tr, rowKey) {
            if (!tr) return;

            tr.dataset.rowKey = rowKey;
            tr.dataset.dynamicRow = '1';

            const customerInput = tr.querySelector('.js-manual-customer-input');
            const customerSuggest = tr.querySelector('.js-manual-customer-suggest');
            const fgInput = tr.querySelector('.js-manual-fg-input');
            const fgSuggest = tr.querySelector('.js-manual-fg-suggest');
            const rmCell = tr.querySelector('.js-manual-rm-cell');
            const qtyInput = tr.querySelector('.js-manual-1m-only');
            const removeBtn = tr.querySelector('.js-remove-manual-row');

            qtyInput.name = `manual_rows[${rowKey}]`;
            qtyInput.value = '0.00';
            bindManual1mInput(qtyInput);

            const metaInput = document.createElement('input');
            metaInput.type = 'hidden';
            metaInput.name = `manual_meta[${rowKey}]`;
            metaInput.className = 'js-manual-meta';
            tr.appendChild(metaInput);
            syncManualMeta(tr);

            removeBtn?.addEventListener('click', function() {
                tr.remove();
            });

            bindAutocompleteInput({
                input: customerInput,
                suggestEl: customerSuggest,
                fetchItems: async (term) => {
                    const division = divisionEl?.value || `{{ $salesCode }}`;
                    const url =
                        `${manualCustomerLookupUrl}?division=${encodeURIComponent(division)}&q=${encodeURIComponent(term)}`;
                    const res = await fetch(url, {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const data = await res.json();
                    return Array.isArray(data?.items) ? data.items : [];
                },
                renderItem: (item) =>
                    `<button type="button" class="list-group-item list-group-item-action" data-payload="${encodeURIComponent(JSON.stringify(item))}">${item.text ?? ''}</button>`,
                onSelect: (item) => {
                    customerInput.value = item.text || '';
                    tr.dataset.customerId = item.id || '';
                    tr.dataset.customerName = item.customer_name || item.text || '';
                    syncManualMeta(tr);
                }
            });

            bindAutocompleteInput({
                input: fgInput,
                suggestEl: fgSuggest,
                fetchItems: async (term) => {
                    const url = `${manualPartLookupUrl}?q=${encodeURIComponent(term)}`;
                    const res = await fetch(url, {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const data = await res.json();
                    return Array.isArray(data?.items) ? data.items : [];
                },
                renderItem: (item) =>
                    `<button type="button" class="list-group-item list-group-item-action" data-payload="${encodeURIComponent(JSON.stringify(item))}">${item.text ?? ''}</button>`,
                onSelect: (item) => {
                    fgInput.value = item.fg_partnumber || item.text || '';
                    tr.dataset.fgPartnumber = item.fg_partnumber || '';
                    tr.dataset.fgDescription = item.fg_description || '';
                    tr.dataset.rmPartnumber = item.rm_partnumber || '';
                    rmCell.textContent = item.rm_partnumber || '-';
                    syncManualMeta(tr);
                }
            });
        }

        addManualRowBtn?.addEventListener('click', function() {
            if (!manualBodyEl || !manualTpl) return;
            manualRowSeq += 1;
            const rowKey = `new_${Date.now()}_${manualRowSeq}`;
            const fragment = manualTpl.content.cloneNode(true);
            const tr = fragment.querySelector('tr');
            bindManualDynamicRow(tr, rowKey);
            manualBodyEl.appendChild(fragment);
            tr.scrollIntoView({
                behavior: 'smooth',
                block: 'end'
            });
            tr.querySelector('.js-manual-customer-input')?.focus();
        });

        // ย่อ/ขยายตาราง Manual Forecast
        (function () {
            const collapseEl = document.getElementById('manualForecastCollapse');
            const toggleBtn = document.getElementById('toggleManualForecast');
            if (!collapseEl || !toggleBtn) return;

            const caretEl = toggleBtn.querySelector('.js-toggle-caret');
            const labelEl = toggleBtn.querySelector('.js-toggle-label');

            function syncState(expanded) {
                if (caretEl) caretEl.textContent = expanded ? '▲' : '▼';
                if (labelEl) labelEl.textContent = expanded ? 'ย่อ' : 'ขยาย';
                toggleBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            }

            collapseEl.addEventListener('shown.bs.collapse', () => syncState(true));
            collapseEl.addEventListener('hidden.bs.collapse', () => syncState(false));

            // กดเพิ่มแถวขณะย่ออยู่ => ขยายให้อัตโนมัติ
            addManualRowBtn?.addEventListener('click', function () {
                if (!collapseEl.classList.contains('show') && window.bootstrap?.Collapse) {
                    window.bootstrap.Collapse.getOrCreateInstance(collapseEl).show();
                }
            });
        })();

        manualFormEl?.addEventListener('submit', function(e) {
            const dynamicRows = Array.from(this.querySelectorAll('tr[data-dynamic-row="1"]'));

            for (const tr of dynamicRows) {
                const customerId = (tr.dataset.customerId || '').trim();
                const fgPartnumber = (tr.dataset.fgPartnumber || '').trim();
                const rmPartnumber = (tr.dataset.rmPartnumber || '').trim();
                const qtyInput = tr.querySelector('.js-manual-1m-only');
                const qtyRaw = (qtyInput?.value || '').trim();

                const hasAnyInput = customerId !== '' || fgPartnumber !== '' || rmPartnumber !== '' || qtyRaw !==
                    '' && qtyRaw !== '0.00' && qtyRaw !== '0';
                if (!hasAnyInput) {
                    continue;
                }

                if (customerId === '') {
                    e.preventDefault();
                    alert('กรุณาเลือก Customer ในแถว Manual ที่เพิ่มใหม่');
                    tr.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                    tr.querySelector('.js-manual-customer-input')?.focus();
                    return;
                }

                if (fgPartnumber === '') {
                    e.preventDefault();
                    alert('กรุณาเลือก FG Part ในแถว Manual ที่เพิ่มใหม่');
                    tr.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                    tr.querySelector('.js-manual-fg-input')?.focus();
                    return;
                }

                if (rmPartnumber === '') {
                    e.preventDefault();
                    alert('FG Part ที่เลือกยังไม่มี RM Part (part.f4) กรุณาเลือก FG ที่มี RM');
                    tr.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                    tr.querySelector('.js-manual-fg-input')?.focus();
                    return;
                }
            }

            const seenManualKeys = new Map();
            for (const tr of Array.from(this.querySelectorAll('.js-manual-row'))) {
                const qtyRaw = (tr.querySelector('.js-manual-1m-only')?.value || '').trim();
                if (qtyRaw === '') continue;

                const identity = manualRowIdentity(tr);
                if (!identity.key) continue;

                if (seenManualKeys.has(identity.key)) {
                    e.preventDefault();
                    alert(`Manual Forecast ซ้ำ Customer + FG Part: ${identity.label}`);
                    tr.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                    return;
                }

                seenManualKeys.set(identity.key, tr);
            }
        });

        function recalcRow(tr) {
            if (!tr) return;

            const avg6 = parseFloat(tr.dataset.avg6 || '0') || 0;
            const forecastFlagEl = tr.querySelector('.js-forecast-flag');
            const rowKEl = tr.querySelector('.js-row-kfactor');
            const manualInput = tr.querySelector('.js-manual-forecast');
            const f6El = tr.querySelector('.js-f6');

            if (!forecastFlagEl || !rowKEl || !manualInput || !f6El) {
                return;
            }

            const checked = forecastFlagEl.checked;
            const k = parseFloat(String(rowKEl.value || '0').replace(/,/g, '')) || 0;
            const autoForecast1m = Math.round((avg6 * k) * 100) / 100;
            const manualRaw = manualInput.value || '';
            const manual = manualRaw === '' ? null : parseFloat(String(manualRaw).replace(/,/g, ''));
            const forceAuto = manualInput.dataset.forceAutoOnce === '1';
            const userEdited = manualInput.dataset.userEdited === '1';
            const hasManualValue = manual !== null && !Number.isNaN(manual) && Math.abs(manual) > 0.0001;

            let f1 = 0;
            let f6 = 0;

            if (checked) {
                // ถ้าติ๊ก Forecast ใหม่และช่อง 1M ยังว่าง/0 ให้คำนวณจาก Avg6*K ทันที
                if (!forceAuto && userEdited && hasManualValue) {
                    f1 = manual;
                } else {
                    f1 = autoForecast1m;
                    manualInput.value = f1.toFixed(2);
                    manualInput.dataset.userEdited = '0';
                }
                f6 = f1 * 6;
                manualInput.dataset.forceAutoOnce = '0';
            } else {
                if (!userEdited || !hasManualValue) {
                    manualInput.value = '0.00';
                    manualInput.dataset.userEdited = '0';
                }
            }

            f6El.textContent = formatNum(f6);
        }

        function recalcKpi() {
            let items = 0;
            let avg6 = 0;
            let f1 = 0;
            let f6 = 0;

            document.querySelectorAll('table.excel tbody tr[data-row-key]').forEach(tr => {
                items += 1;
                avg6 += parseFloat(tr.dataset.avg6 || '0') || 0;

                const manualInput = tr.querySelector('.js-manual-forecast');
                const f6El = tr.querySelector('.js-f6');
                const forecastFlagEl = tr.querySelector('.js-forecast-flag');

                if (!manualInput || !f6El || !forecastFlagEl) return;

                if (forecastFlagEl.checked) {
                    f1 += parseFloat((manualInput.value || '0').replace(/,/g, '')) || 0;
                    f6 += parseFloat((f6El.textContent || '0').replace(/,/g, '')) || 0;
                }
            });

            const itemsEl = document.querySelector('.js-kpi-items');
            const avg6El = document.querySelector('.js-kpi-avg6');
            const f1El = document.querySelector('.js-kpi-f1');
            const f6El = document.querySelector('.js-kpi-f6');

            if (itemsEl) itemsEl.textContent = items.toLocaleString();
            if (avg6El) avg6El.textContent = formatNum(avg6);
            if (f1El) f1El.textContent = formatNum(f1);
            if (f6El) f6El.textContent = formatNum(f6);
        }

        document.querySelectorAll('.js-manual-forecast').forEach(el => {
            el.addEventListener('input', function() {
                this.dataset.userEdited = '1';
                recalcRow(this.closest('tr'));
                recalcKpi();
            });
        });

        function sanitizeKInputValue(v) {
            v = String(v ?? '').replace(/,/g, '').replace(/[^0-9.]/g, '');
            const parts = v.split('.');
            if (parts.length > 2) v = parts.shift() + '.' + parts.join('');
            return v;
        }

        document.querySelectorAll('.js-row-kfactor').forEach(el => {
            el.addEventListener('input', function() {
                const clean = sanitizeKInputValue(this.value);
                if (this.value !== clean) this.value = clean;

                const tr = this.closest('tr');
                const manualInput = tr?.querySelector('.js-manual-forecast');

                // เปลี่ยน K = ใช้ auto ใหม่ของรอบนี้
                if (manualInput) {
                    manualInput.dataset.userEdited = '0';
                    manualInput.dataset.forceAutoOnce = '1';
                }

                recalcRow(tr);
                recalcKpi();
            });

            el.addEventListener('change', function() {
                if (this.value !== '') this.value = formatKValue(this.value);
                const tr = this.closest('tr');
                const manualInput = tr?.querySelector('.js-manual-forecast');

                if (manualInput) {
                    manualInput.dataset.userEdited = '0';
                    manualInput.dataset.forceAutoOnce = '1';
                }

                recalcRow(tr);
                recalcKpi();
                applyFilters?.();
            });
        });

        document.querySelectorAll('.js-forecast-flag').forEach(el => {
            el.addEventListener('change', function() {
                const tr = this.closest('tr');
                const manualInput = tr?.querySelector('.js-manual-forecast');
                const manual = parseFloat(String(manualInput?.value || '0').replace(/,/g, '')) || 0;

                // เมื่อติ๊กเลือก Forecast ให้คำนวณ 1M/6M จาก Avg6*K ทันที ไม่ต้องขยับ K ก่อน
                if (this.checked && manualInput) {
                    manualInput.dataset.userEdited = '0';
                    manualInput.dataset.forceAutoOnce = '1';
                }

                recalcRow(tr);
                recalcKpi();
            });
        });

        // SO detail
        document.addEventListener('click', async function(e) {
            const btn = e.target.closest('.js-so-detail');
            if (!btn) return;

            const fg = btn.dataset.fg || '';
            const company = btn.dataset.company || 'ALL';

            const modalEl = document.getElementById('soDetailModal');
            const loadingEl = document.getElementById('soDetailLoading');
            const wrapEl = document.getElementById('soDetailWrap');
            const bodyEl = document.getElementById('soDetailBody');

            if (!modalEl || !loadingEl || !wrapEl || !bodyEl) return;

            bodyEl.innerHTML = '';
            loadingEl.classList.remove('d-none');
            wrapEl.classList.add('d-none');

            const modal = new bootstrap.Modal(modalEl);
            modal.show();

            try {
                const url =
                    `{{ route('fc.division.soDetail') }}?fg_partnumber=${encodeURIComponent(fg)}&company=${encodeURIComponent(company)}`;
                const res = await fetch(url, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                const rows = await res.json();

                bodyEl.innerHTML = rows.map(r => `
            <tr>
                <td>${r.company ?? ''}</td>
                <td>${r.fg_partnumber ?? ''}</td>
                <td>${r.description ?? ''}</td>
                <td>${r.po ?? ''}</td>
                <td>${r.ordnumber ?? ''}</td>
                <td>${r.order_date ?? ''}</td>
                <td>${r.due_date ?? ''}</td>
                <td>${r.customer_name ?? ''}</td>
                <td class="text-end">${formatNum(r.backorder_qty ?? 0)}</td>
            </tr>
        `).join('');

                loadingEl.classList.add('d-none');
                wrapEl.classList.remove('d-none');
            } catch (err) {
                loadingEl.textContent = 'โหลดข้อมูลไม่สำเร็จ';
            }
        });

        // Forecast history
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-history');
            if (!btn) return;

            const titleEl = document.getElementById('historyModalTitle');
            const subEl = document.getElementById('historyModalSub');
            const bodyEl = document.getElementById('historyModalBody');
            const modalEl = document.getElementById('historyModal');

            if (!titleEl || !subEl || !bodyEl || !modalEl) return;

            const customer = btn.dataset.customer || '';
            const fg = btn.dataset.fg || '';
            const desc = btn.dataset.description || '';

            let history = [];
            try {
                history = JSON.parse(btn.dataset.history || '[]');
            } catch (e) {
                history = [];
            }

            titleEl.textContent = `ประวัติการบันทึก | ${customer} | ${fg}`;
            subEl.textContent = desc;
            bodyEl.innerHTML = '';

            if (!history.length) {
                bodyEl.innerHTML = `
            <tr>
                <td colspan="6" class="text-center text-muted">ยังไม่มีประวัติการบันทึก</td>
            </tr>
        `;
                new bootstrap.Modal(modalEl).show();
                return;
            }

            bodyEl.innerHTML = history.map(row => {
                const month = row.base_month || row.forecast_base_month || row.ym || '-';
                const k = parseFloat(row.k_factor || 0) || 0;
                const f1 = parseFloat(row.forecast_1m || row.forecast_qty || 0) || 0;
                const f6 = parseFloat(row.forecast_6m || 0) || 0;
                const source = row.source_type || '-';
                const savedAt = row.saved_at || row.created_at || row.updated_at || '-';

                return `
            <tr>
                <td>${month}</td>
                <td class="text-end">${formatNum(k, 1)}</td>
                <td class="text-end">${formatNum(f1)}</td>
                <td class="text-end">${formatNum(f6)}</td>
                <td>${source}</td>
                <td>${savedAt}</td>
            </tr>
        `;
            }).join('');

            new bootstrap.Modal(modalEl).show();
        });

        document.querySelectorAll('table.excel tbody tr[data-row-key]').forEach(recalcRow);
        recalcKpi();

        /* ================== K FACTOR FORMATTING ================== */
        function formatKValue(v) {
            const raw = String(v ?? '').replace(/,/g, '').trim();
            if (raw === '') return '';
            const n = parseFloat(raw);
            if (Number.isNaN(n)) return '';
            return Math.max(0, n).toFixed(1);
        }

        function bindKFormatter(el) {
            if (!el) return;
            const normalize = () => {
                if (el.value === '' || el.value === null) return;
                el.value = formatKValue(el.value);
            };
            el.addEventListener('input', function() {
                const clean = sanitizeKInputValue(this.value);
                if (this.value !== clean) this.value = clean;
            });
            el.addEventListener('blur', function() {
                normalize();
                this.dispatchEvent(new Event('change', {
                    bubbles: true
                }));
            });
            normalize();
        }

        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.js-k-step');
            if (!btn) return;

            const wrap = btn.closest('.k-stepper');
            const input = wrap?.querySelector('.js-kfactor-input, .js-row-kfactor, input[name="k_factor"]');
            if (!input) return;

            const delta = parseFloat(btn.dataset.delta || '0') || 0;
            const current = parseFloat(String(input.value || '0').replace(/,/g, '')) || 0;
            input.value = formatKValue(current + delta);
            input.dispatchEvent(new Event('input', {
                bubbles: true
            }));
            input.dispatchEvent(new Event('change', {
                bubbles: true
            }));
        });
        bindKFormatter(document.querySelector('input[name="k_factor"]'));
        bindKFormatter(document.getElementById('kFactorHidden'));
        document.querySelectorAll('.js-row-kfactor').forEach(bindKFormatter);

        // sync hidden k_factor to top input on submit form
        const topKInput = document.querySelector('form[method="get"] input[name="k_factor"]');
        const kHidden = document.getElementById('kFactorHidden');
        if (topKInput && kHidden) {
            topKInput.addEventListener('change', () => {
                topKInput.value = formatKValue(topKInput.value || '0');
                kHidden.value = topKInput.value;
            });
        }

        /* ================== SUPPLIER AUTOCOMPLETE IN TABLE ================== */
        const supplierOptions = Array.from(document.querySelectorAll('#supplier-options-list option'))
            .map(opt => ({
                label: String(opt.value || '').trim(),
                code: String(opt.dataset.code || '').trim(),
            }))
            .filter(item => item.label !== '');

        const supplierMapByName = {};
        supplierOptions.forEach(item => {
            supplierMapByName[item.label.toUpperCase()] = item.code;
        });

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function syncSupplierCode(input) {
            const tr = input.closest('tr');
            const codeInput = tr?.querySelector('.js-supplier-code');
            if (!codeInput) return;
            const name = (input.value || '').trim().toUpperCase();
            codeInput.value = supplierMapByName[name] || '';
        }

        function supplierMenuFor(input) {
            return input.closest('.js-supplier-wrap')?.querySelector('.js-supplier-menu');
        }

        function closeSupplierMenu(input) {
            const menu = supplierMenuFor(input);
            if (!menu) return;
            menu.classList.add('d-none');
            menu.innerHTML = '';
        }

        function openSupplierMenu(input) {
            const menu = supplierMenuFor(input);
            if (!menu) return;

            const term = String(input.value || '').trim().toUpperCase();
            const items = supplierOptions
                .filter(item => {
                    if (!term) return true;
                    return item.label.toUpperCase().includes(term) || item.code.toUpperCase().includes(term);
                })
                .slice(0, 30);

            if (!items.length) {
                menu.innerHTML = `
                    <button type="button" class="list-group-item list-group-item-action disabled text-muted">
                        ไม่พบ supplier / พิมพ์ manual ได้
                    </button>`;
                menu.classList.remove('d-none');
                return;
            }

            menu.innerHTML = items.map(item => `
                <button type="button" class="list-group-item list-group-item-action js-supplier-pick"
                    data-label="${escapeHtml(item.label)}" data-code="${escapeHtml(item.code)}">
                    <span class="supplier-suggest-code">${escapeHtml(item.code || '-')}</span>
                    <span>${escapeHtml(item.label)}</span>
                </button>
            `).join('');
            menu.classList.remove('d-none');
        }

        document.querySelectorAll('.js-supplier-input').forEach(inp => {
            inp.addEventListener('focus', function() {
                openSupplierMenu(this);
            });
            inp.addEventListener('input', function() {
                syncSupplierCode(this);
                openSupplierMenu(this);
                markDraftDirty();
            });
            inp.addEventListener('change', function() {
                syncSupplierCode(this);
                markDraftDirty();
            });
            inp.addEventListener('blur', function() {
                setTimeout(() => closeSupplierMenu(this), 180);
            });
            syncSupplierCode(inp);
        });

        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.js-supplier-pick');
            if (!btn) return;

            const wrap = btn.closest('.js-supplier-wrap');
            const input = wrap?.querySelector('.js-supplier-input');
            const tr = input?.closest('tr');
            const codeInput = tr?.querySelector('.js-supplier-code');

            if (input) input.value = btn.dataset.label || '';
            if (codeInput) codeInput.value = btn.dataset.code || '';
            if (input) {
                closeSupplierMenu(input);
                input.dispatchEvent(new Event('change', {
                    bubbles: true
                }));
            }
        });

        /* ================== AVG 6M MODAL ================== */
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.avg6-link');
            if (!btn) return;

            const titleEl = document.getElementById('avg6ModalTitle');
            const subEl = document.getElementById('avg6ModalSub');
            const bodyEl = document.getElementById('avg6ModalBody');
            const modalEl = document.getElementById('avg6Modal');
            if (!titleEl || !subEl || !bodyEl || !modalEl) return;

            const customer = btn.dataset.customer || '';
            const fg = btn.dataset.fg || '';
            const desc = btn.dataset.description || '';

            let history = [];
            try {
                history = JSON.parse(btn.dataset.history || '[]');
            } catch (e) {
                history = [];
            }

            titleEl.textContent = `Avg 6M | ${customer} | ${fg}`;
            subEl.textContent = desc;

            let sumW = 0,
                sumP = 0,
                sumT = 0,
                count = 0;
            bodyEl.innerHTML = (history.length === 0) ?
                `<tr><td colspan="4" class="text-center text-muted">ไม่มีข้อมูล</td></tr>` :
                history.map(row => {
                    const w = parseFloat(row.qty_wire || 0) || 0;
                    const p = parseFloat(row.qty_plus || 0) || 0;
                    const t = parseFloat(row.qty || (w + p)) || 0;
                    sumW += w;
                    sumP += p;
                    sumT += t;
                    count += 1;
                    return `<tr>
                        <td>${row.ym || '-'}</td>
                        <td class="text-end">${formatNum(w)}</td>
                        <td class="text-end">${formatNum(p)}</td>
                        <td class="text-end fw-semibold">${formatNum(t)}</td>
                    </tr>`;
                }).join('');

            document.getElementById('avg6FootWire').textContent = formatNum(sumW);
            document.getElementById('avg6FootPlus').textContent = formatNum(sumP);
            document.getElementById('avg6FootTotal').textContent = formatNum(sumT);
            const denom = count > 0 ? count : 1;
            document.getElementById('avg6AvgWire').textContent = formatNum(sumW / denom);
            document.getElementById('avg6AvgPlus').textContent = formatNum(sumP / denom);
            document.getElementById('avg6AvgTotal').textContent = formatNum(sumT / denom);

            new bootstrap.Modal(modalEl).show();
        });

        /* ================== EXCEL-LIKE SORT + FILTER ================== */
        const mainTable = document.querySelector('#gen-form table.excel');

        function tableRows() {
            return mainTable ? Array.from(mainTable.querySelectorAll('tbody tr[data-row-key]')) : [];
        }

        function restorePayloadState(payload) {
            if (!payload || typeof payload !== 'object') return;
            const flags = payload.forecast_flag || {};
            const rowK = payload.row_k_factor || {};
            const manual = payload.manual_forecast_1m || {};
            const manualEdited = payload.manual_user_edited || {};
            const remarks = payload.row_remark || {};
            const supplierNames = payload.supplier_name_manual || {};
            const supplierCodes = payload.supplier_code || {};

            tableRows().forEach(tr => {
                const key = tr.dataset.rowKey;
                if (!key) return;

                const flagEl = tr.querySelector('.js-forecast-flag');
                const kEl = tr.querySelector('.js-row-kfactor');
                const manualEl = tr.querySelector('.js-manual-forecast');
                const remarkEl = tr.querySelector('input[name^="row_remark"]');
                const supplierEl = tr.querySelector('.js-supplier-input');
                const supplierCodeEl = tr.querySelector('.js-supplier-code');

                if (flagEl) flagEl.checked = String(flags[key] || '') === '1';
                if (kEl && rowK[key] !== undefined && rowK[key] !== '') kEl.value = formatKValue(rowK[key]);
                if (manualEl && manual[key] !== undefined) {
                    manualEl.value = manual[key] === '' ? '' : Number(manual[key] || 0).toFixed(2);
                    manualEl.dataset.userEdited = String(manualEdited[key] || '0') === '1' ? '1' : '0';
                }
                if (remarkEl && remarks[key] !== undefined) remarkEl.value = remarks[key] || '';
                if (supplierEl && supplierNames[key] !== undefined) supplierEl.value = supplierNames[key] || '';
                if (supplierCodeEl && supplierCodes[key] !== undefined) supplierCodeEl.value = supplierCodes[key] ||
                    '';

                if (supplierEl) syncSupplierCode(supplierEl);
                recalcRow(tr);
            });
            recalcKpi();
        }

        function getCellInputAwareValue(td) {
            if (!td) return '';
            const input = td.querySelector('input[type="number"], input[type="text"], input[type="checkbox"]');
            if (input) {
                return input.type === 'checkbox' ? (input.checked ? '1' : '0') : String(input.value || '');
            }
            return td.innerText.trim();
        }

        function getRowSearchText(tr) {
            return Array.from(tr.children)
                .map(td => getCellInputAwareValue(td))
                .join(' ')
                .toLowerCase();
        }

        function getCellSortValue(tr, col, type) {
            // ใช้ค่าจาก input ถ้ามี (สำหรับ K, Forecast 1M, Supplier, Remark)
            const td = tr.children[col];
            const v = getCellInputAwareValue(td);
            if (type === 'num') {
                return parseFloat(String(v).replace(/,/g, '')) || 0;
            }
            return String(v).toLowerCase();
        }

        let currentSort = {
            col: -1,
            dir: 1
        };
        document.querySelectorAll('#gen-form table.excel thead th.sortable').forEach(th => {
            th.addEventListener('click', function() {
                const col = parseInt(this.dataset.sortCol, 10);
                const type = this.dataset.sortType || 'text';
                const dir = (currentSort.col === col) ? -currentSort.dir : 1;
                currentSort = {
                    col,
                    dir
                };

                document.querySelectorAll('#gen-form table.excel thead th.sortable .sort-ind').forEach(
                    ind => ind.textContent = '');
                this.querySelector('.sort-ind').textContent = dir === 1 ? '▲' : '▼';

                const rows = tableRows();
                const tbody = mainTable.querySelector('tbody');
                rows.sort((a, b) => {
                    const va = getCellSortValue(a, col, type);
                    const vb = getCellSortValue(b, col, type);
                    if (va < vb) return -1 * dir;
                    if (va > vb) return 1 * dir;
                    return 0;
                });
                rows.forEach(r => tbody.appendChild(r));
            });
        });

        function applyFilters() {
            const filters = Array.from(document.querySelectorAll('#gen-form table.excel thead .col-filter')).map(el => ({
                col: parseInt(el.dataset.filterCol, 10),
                value: (el.value || '').trim().toLowerCase(),
                type: el.tagName === 'SELECT' ? 'select' : 'text',
            })).filter(f => f.value !== '');

            const globalQ = (document.getElementById('globalSearch')?.value || '').trim().toLowerCase();

            tableRows().forEach(tr => {
                let show = true;

                if (globalQ !== '') {
                    const text = getRowSearchText(tr);
                    if (!text.includes(globalQ)) show = false;
                }

                if (show) {
                    for (const f of filters) {
                        const td = tr.children[f.col];
                        if (!td) continue;
                        let cellVal = getCellInputAwareValue(td);

                        if (f.type === 'select') {
                            if (String(cellVal) !== f.value) {
                                show = false;
                                break;
                            }
                        } else {
                            // ถ้า value ขึ้นต้นด้วย >= ตัวเลข
                            const numMatch = f.value.match(/^>=?\s*(-?\d+(?:\.\d+)?)$/);
                            if (numMatch) {
                                if ((parseFloat(String(cellVal).replace(/,/g, '')) || 0) < parseFloat(numMatch[
                                        1])) {
                                    show = false;
                                    break;
                                }
                            } else if (!String(cellVal).toLowerCase().includes(f.value)) {
                                show = false;
                                break;
                            }
                        }
                    }
                }

                tr.style.display = show ? '' : 'none';
            });
        }
        document.querySelectorAll('#gen-form table.excel thead .col-filter').forEach(el => {
            el.addEventListener('input', applyFilters);
            el.addEventListener('change', applyFilters);
        });
        document.getElementById('globalSearch')?.addEventListener('input', applyFilters);
        applyFilters();

        /* ================== EXPORT EXCEL (client-side .xls via HTML) ================== */
        document.getElementById('btnExportExcel')?.addEventListener('click', function() {
            const visibleRows = tableRows().filter(tr => tr.style.display !== 'none');
            const headers = ['Customer', 'FG Part', 'Description', 'RM Part', 'Sales Order', 'Avg 6M', 'Forecast?',
                'K', 'Forecast 1M', 'Forecast 6M', 'Supplier', 'Remark'
            ];
            const escapeCell = (value) => String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
            const tableHtml = (lines) => `<table border="1">${lines.join('')}</table>`;
            const excelNs = 'x';
            const worksheet = (name) => `
                <${excelNs}:ExcelWorksheet>
                    <${excelNs}:Name>${escapeCell(name)}</${excelNs}:Name>
                    <${excelNs}:WorksheetOptions><${excelNs}:DisplayGridlines/></${excelNs}:WorksheetOptions>
                </${excelNs}:ExcelWorksheet>`;
            const lines = [];
            lines.push('<tr>' + headers.map(h => `<th>${escapeCell(h)}</th>`).join('') + '</tr>');

            visibleRows.forEach(tr => {
                const cells = [];
                for (let i = 0; i <= 11; i++) {
                    const td = tr.children[i];
                    if (!td) {
                        cells.push('');
                        continue;
                    }
                    const input = td.querySelector(
                        'input[type="number"], input[type="text"], input[type="checkbox"]');
                    let v;
                    if (input) {
                        if (input.type === 'checkbox') v = input.checked ? '1' : '0';
                        else v = input.value || '';
                    } else {
                        v = td.innerText.trim();
                    }
                    cells.push(escapeCell(v));
                }
                lines.push('<tr>' + cells.map(c => `<td>${c}</td>`).join('') + '</tr>');
            });

            const manualHeaders = ['Customer', 'FG Part', 'RM Part', 'Manual 1M', 'Forecast 6M'];
            const manualLines = [];
            manualLines.push('<tr>' + manualHeaders.map(h => `<th>${escapeCell(h)}</th>`).join('') + '</tr>');

            document.querySelectorAll('#manualForecastBody .js-manual-row').forEach(tr => {
                const meta = manualRowMeta(tr);
                const customerInput = tr.querySelector('.js-manual-customer-input');
                const fgInput = tr.querySelector('.js-manual-fg-input');
                const qtyInput = tr.querySelector('.js-manual-1m-only');
                const cells = [
                    meta.customer_name || tr.dataset.customerName || customerInput?.value || tr.children[0]?.innerText.trim() || '',
                    meta.fg_partnumber || tr.dataset.fgPartnumber || fgInput?.value || tr.children[1]?.innerText.trim() || '',
                    meta.rm_partnumber || tr.dataset.rmPartnumber || tr.querySelector('.js-manual-rm-cell')?.innerText.trim() || '',
                    qtyInput?.value || '',
                    tr.querySelector('.js-manual-6m-only')?.innerText.trim() || '',
                ].map(escapeCell);

                manualLines.push('<tr>' + cells.map(c => `<td>${c}</td>`).join('') + '</tr>');
            });

            const html =
                `<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">
                    <head>
                        <meta charset="UTF-8">
                        <!--[if gte mso 9]><xml><${excelNs}:ExcelWorkbook><${excelNs}:ExcelWorksheets>
                            ${worksheet('Forecast')}
                            ${worksheet('Manual Customer')}
                        </${excelNs}:ExcelWorksheets></${excelNs}:ExcelWorkbook></xml><![endif]-->
                    </head>
                    <body>
                        ${tableHtml(lines)}
                        <br style="mso-special-character:line-break;page-break-before:always">
                        ${tableHtml(manualLines)}
                    </body>
                </html>`;
            const blob = new Blob(['﻿' + html], {
                type: 'application/vnd.ms-excel;charset=utf-8'
            });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            const ts = new Date().toISOString().slice(0, 10);
            a.download = `division_forecast_{{ $salesCode }}_${ts}.xls`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        });

        /* ================== DRAFT AUTOSAVE (localStorage) ================== */
        const DRAFT_KEY =
            `fc_division_draft_{{ $salesCode }}_{{ now('Asia/Bangkok')->startOfMonth()->toDateString() }}`;
        let draftDirty = false;
        let draftDebounce = null;

        function collectFormState() {
            const state = {
                _at: Date.now(),
                rows: {}
            };
            tableRows().forEach(tr => {
                const key = tr.dataset.rowKey;
                if (!key) return;
                state.rows[key] = {
                    flag: tr.querySelector('.js-forecast-flag')?.checked ? 1 : 0,
                    k: tr.querySelector('.js-row-kfactor')?.value || '',
                    manual: tr.querySelector('.js-manual-forecast')?.value || '',
                    remark: tr.querySelector('input[name^="row_remark"]')?.value || '',
                    supplier_name: tr.querySelector('.js-supplier-input')?.value || '',
                };
            });
            return state;
        }

        function saveDraft() {
            try {
                localStorage.setItem(DRAFT_KEY, JSON.stringify(collectFormState()));
            } catch (e) {}
        }

        function markDraftDirty() {
            draftDirty = true;
            clearTimeout(draftDebounce);
            draftDebounce = setTimeout(saveDraft, 500);
        }

        function clearDraft() {
            try {
                localStorage.removeItem(DRAFT_KEY);
            } catch (e) {}
            draftDirty = false;
        }

        // Restore old payload automatically after Laravel validation redirects back.
        (function restoreOldPayloadAutomatically() {
            if (!OLD_PAYLOAD_JSON) return;
            try {
                const payload = typeof OLD_PAYLOAD_JSON === 'string' ?
                    JSON.parse(OLD_PAYLOAD_JSON) :
                    OLD_PAYLOAD_JSON;
                restorePayloadState(payload);
                clearDraft();
            } catch (e) {}
        })();

        // Bind change events to mark dirty
        ['change', 'input'].forEach(ev => {
            document.querySelectorAll(
                '#gen-form .js-forecast-flag, #gen-form .js-row-kfactor, #gen-form .js-manual-forecast, #gen-form input[name^="row_remark"]'
            ).forEach(el => {
                el.addEventListener(ev, markDraftDirty);
            });
        });

        // ไม่แสดง draft banner แล้ว: ใช้ old('payload') restore อัตโนมัติแทน
        (function restorePrompt() {
            return;
            try {
                const raw = localStorage.getItem(DRAFT_KEY);
                if (!raw) return;
                const draft = JSON.parse(raw);
                if (!draft || !draft.rows) return;
                const banner = document.getElementById('draftBanner');
                const timeEl = document.getElementById('draftBannerTime');
                if (banner) {
                    banner.classList.add('show');
                    if (timeEl && draft._at) {
                        timeEl.textContent = '(' + new Date(draft._at).toLocaleString('th-TH') + ')';
                    }
                }
                document.getElementById('restoreDraftBtn')?.addEventListener('click', function() {
                    Object.entries(draft.rows).forEach(([key, st]) => {
                        const tr = mainTable?.querySelector(`tr[data-row-key="${CSS.escape(key)}"]`);
                        if (!tr) return;
                        const flag = tr.querySelector('.js-forecast-flag');
                        const k = tr.querySelector('.js-row-kfactor');
                        const manual = tr.querySelector('.js-manual-forecast');
                        const remark = tr.querySelector('input[name^="row_remark"]');
                        const supp = tr.querySelector('.js-supplier-input');
                        if (flag) flag.checked = !!st.flag;
                        if (k && st.k !== '') k.value = st.k;
                        if (manual && st.manual !== '') {
                            manual.value = st.manual;
                            manual.dataset.userEdited = '1';
                        }
                        if (remark) remark.value = st.remark || '';
                        if (supp) {
                            supp.value = st.supplier_name || '';
                            syncSupplierCode(supp);
                        }
                        recalcRow(tr);
                    });
                    recalcKpi();
                    banner.classList.remove('show');
                });
                document.getElementById('discardDraftBtn')?.addEventListener('click', function() {
                    clearDraft();
                    banner.classList.remove('show');
                });
            } catch (e) {}
        })();

        // Clear draft if last save was successful
        @if (session('success'))
            clearDraft();
        @endif

        /* ================== JSON PAYLOAD SUBMIT (bypass max_input_vars) ================== */
        const genForm = document.getElementById('gen-form');
        genForm?.addEventListener('submit', function(e) {
            if (@json(!empty($isApprovalMode))) {
                const commentHidden = document.getElementById('approvalCommentHidden');
                const commentInput = document.getElementById('approvalCommentInput');
                if (commentHidden && commentInput) commentHidden.value = commentInput.value || '';
                return;
            }

            const submitAction = e.submitter?.value === 'save_draft' ? 'save_draft' : 'submit_approval';
            const submitActionHidden = document.getElementById('submitActionHidden');
            if (submitActionHidden) submitActionHidden.value = submitAction;

            const payload = {
                sales_code: '{{ $salesCode }}',
                division: '{{ $salesCode }}',
                customer_id: this.querySelector('input[name="customer_id"]')?.value || '',
                customer_name: this.querySelector('input[name="customer_name"]')?.value || '',
                k_factor: formatKValue(kHidden?.value ||
                    '{{ number_format((float) $selectedK, 1, '.', '') }}'),
                submit_action: submitAction,
                forecast_flag: {},
                row_k_factor: {},
                row_meta: {},
                manual_forecast_1m: {},
                row_remark: {},
                supplier_code: {},
                supplier_name_manual: {},
                manual_user_edited: {},
            };

            tableRows().forEach(tr => {
                const key = tr.dataset.rowKey;
                if (!key) return;
                const flag = tr.querySelector('.js-forecast-flag');
                if (flag && flag.checked) payload.forecast_flag[key] = '1';
                const k = tr.querySelector('.js-row-kfactor');
                if (k) payload.row_k_factor[key] = k.value;
                const meta = tr.querySelector('input[name^="row_meta"]');
                if (meta) payload.row_meta[key] = meta.value;
                const manual = tr.querySelector('.js-manual-forecast');
                if (manual) {
                    payload.manual_forecast_1m[key] = manual.value;
                    payload.manual_user_edited[key] = manual.dataset.userEdited === '1' ? '1' : '0';
                }
                const remark = tr.querySelector('input[name^="row_remark"]');
                if (remark) payload.row_remark[key] = remark.value;
                const code = tr.querySelector('.js-supplier-code');
                if (code) payload.supplier_code[key] = code.value;
                const supp = tr.querySelector('.js-supplier-input');
                if (supp) payload.supplier_name_manual[key] = supp.value;
            });

            document.querySelectorAll('#manualForecastBody .js-manual-row').forEach(tr => {
                const key = tr.dataset.rowKey;
                if (!key || payload.row_meta[key]) return;

                const meta = manualRowMeta(tr);
                const qty = tr.querySelector('.js-manual-1m-only')?.value || '';
                const qtyNum = parseFloat(String(qty).replace(/,/g, '')) || 0;
                if (qtyNum <= 0) return;

                payload.forecast_flag[key] = '1';
                payload.row_k_factor[key] = '0';
                payload.manual_forecast_1m[key] = qtyNum.toFixed(2);
                payload.manual_user_edited[key] = '1';
                payload.row_meta[key] = JSON.stringify({
                    customer_id: meta.customer_id || tr.dataset.customerId || 0,
                    customer_name: meta.customer_name || tr.dataset.customerName || '',
                    fg_partnumber: meta.fg_partnumber || tr.dataset.fgPartnumber || '',
                    fg_description: meta.fg_description || '',
                    rm_partnumber: meta.rm_partnumber || tr.dataset.rmPartnumber || '',
                    avg6: 0,
                    sales_order_qty: 0
                });
            });

            const payloadHidden = document.getElementById('payloadHidden');
            if (payloadHidden) payloadHidden.value = JSON.stringify(payload);

            // ลบ name ของ field ทุกแถวเพื่อกัน max_input_vars (เหลือเฉพาะ payload + scalar fields)
            this.querySelectorAll(
                'input[name^="forecast_flag["], input[name^="row_k_factor["], input[name^="row_meta["], input[name^="manual_forecast_1m["], input[name^="row_remark["], input[name^="supplier_code["], input[name^="supplier_name_manual["]'
            ).forEach(el => {
                el.removeAttribute('name');
            });
        });
    </script>
@endsection
