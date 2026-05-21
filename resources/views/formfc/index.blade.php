@extends('layouts.layout')
@section('title', 'Sales Forecast')
@section('page-title', 'Sales Forecast')

@section('content')
    <div class="container-fluid">
        <style>
            .fc-card {
                border: 0;
                border-radius: 14px;
                box-shadow: 0 6px 18px rgba(0, 0, 0, .06);
            }

            .fc-card .card-header {
                background: #fff;
                border-bottom: 1px solid #eef0f2;
                border-top-left-radius: 14px;
                border-top-right-radius: 14px;
            }

            .excel-wrap {
                position: relative;
                overflow: auto;
                max-height: calc(100vh - 280px);
                border: 1px solid #e6e8eb;
                background: #fff;
                border-radius: 14px;
                overscroll-behavior: contain;
                contain: layout paint;
                -webkit-overflow-scrolling: touch;
            }

            .legend-chip {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 4px 10px;
                border-radius: 999px;
                font-size: 12px;
                background: #f8fafc;
                border: 1px solid #e9edf2;
            }

            .legend-dot {
                width: 10px;
                height: 10px;
                border-radius: 999px;
                display: inline-block;
            }

            .dot-cpa13 {
                background: #cddcff;
            }

            .dot-stock {
                background: #ffd8b0;
            }

            .dot-po {
                background: #cbeed4;
            }

            .dot-sales {
                background: #ffe7ba;
            }

            .dot-supply {
                background: #ded7ff;
            }

            table.excel {
                border-collapse: separate;
                border-spacing: 0;
                font-size: 14px;
                width: max-content;
                min-width: 100%;
                table-layout: auto;
            }

            table.excel th,
            table.excel td {
                border: 1px solid #eef0f2;
                padding: 10px 12px;
                white-space: nowrap;
                vertical-align: middle;
                background-clip: padding-box;
            }

            table.excel thead th {
                position: sticky;
                top: 0;
                z-index: 30;
                background: #fbfbfc;
                text-align: center;
                font-weight: 700;
                font-size: 15px;
            }

            .sticky-col-1,
            .sticky-col-2 {
                position: sticky;
                z-index: 20;
                background: #fff;
                background-clip: padding-box;
            }

            table.excel thead .sticky-col-1,
            table.excel thead .sticky-col-2 {
                z-index: 40;
            }

            table.excel tbody tr:nth-child(even) td {
                background: #fcfcfd;
            }

            table.excel tbody tr:hover td {
                background: #f5f9ff;
            }

            table.excel tbody tr:nth-child(even) .sticky-col-1,
            table.excel tbody tr:nth-child(even) .sticky-col-2 {
                background: #fcfcfd;
            }

            table.excel tbody tr:hover .sticky-col-1,
            table.excel tbody tr:hover .sticky-col-2 {
                background: #f5f9ff;
            }

            .sticky-col-1 {
                left: 0;
                min-width: 140px;
                width: 140px;
                box-shadow: 4px 0 8px rgba(15, 23, 42, .04);
            }

            .sticky-col-2 {
                left: 140px;
                min-width: 260px;
                width: 260px;
                max-width: 260px;
                overflow: hidden;
                text-overflow: ellipsis;
                box-shadow: 6px 0 10px rgba(15, 23, 42, .04);
            }

            .supplier-col {
                min-width: 180px;
                width: 180px;
                max-width: 180px;
                white-space: normal !important;
                overflow-wrap: anywhere;
                word-break: break-word;
                line-height: 1.25;
            }

            .supplier-col .badge {
                white-space: nowrap;
            }

            .num {
                text-align: right;
                font-variant-numeric: tabular-nums;
            }

            .h-cpa13 {
                background: #eaf0ff !important;
            }

            .h-stock {
                background: #fff3e8 !important;
            }

            .h-po {
                background: #eaf8ee !important;
            }

            .h-sales {
                background: #fff7ea !important;
            }

            .h-supply {
                background: #f1edff !important;
            }

            .empty-state {
                padding: 44px 20px;
                text-align: center;
                color: #64748b;
            }

            .row-zero td {
                color: #94a3b8;
            }

            .division-hidden .division-col {
                display: none;
            }

            .filter-row .form-control,
            .filter-row .form-select,
            .filter-row .ts-wrapper .ts-control {
                min-height: 38px;
                height: 38px;
            }

            .filter-row .ts-wrapper.multi .ts-control {
                padding-top: 4px;
                padding-bottom: 4px;
                display: flex;
                align-items: center;
                flex-wrap: nowrap;
                overflow-x: auto;
                overflow-y: hidden;
            }

            .filter-row .ts-wrapper.multi .ts-control>input {
                min-width: 120px !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .filter-row .ts-wrapper .ts-control>div {
                margin: 0 4px 0 0;
                padding: 2px 8px;
                line-height: 1.2;
            }

            .filter-row .form-label {
                margin-bottom: 6px;
                font-weight: 500;
            }

            .ts-wrapper.multi .ts-control>div {
                background: #eef4ff;
                border: 1px solid #d7e3ff;
                color: #1f2937;
            }

            .ts-wrapper .ts-control {
                min-height: 38px;
            }

            .ts-dropdown {
                z-index: 1055;
            }

            table.excel th.sortable {
                cursor: pointer;
                user-select: none;
            }

            table.excel th.sortable:hover {
                background: #eef4ff !important;
            }

            table.excel th .sort-ind {
                color: #64748b;
                font-size: 11px;
                margin-left: 4px;
            }

            table.excel thead tr.excel-filter-row th {
                top: 42px;
                z-index: 31;
                padding: 6px;
                background: #fff;
            }

            table.excel thead tr.excel-filter-row th.sticky-col-1,
            table.excel thead tr.excel-filter-row th.sticky-col-2 {
                z-index: 41;
            }

            table.excel .col-filter {
                width: 100%;
                min-width: 70px;
                font-size: 12px;
                padding: 4px 6px;
                font-weight: 400;
            }

            .btn-link.clean-link {
                text-decoration: none;
                font-weight: 700;
            }

            .btn-link.clean-link:hover {
                text-decoration: underline;
            }

            .manual-order-chip {
                min-width: 92px;
                justify-content: flex-end;
                font-variant-numeric: tabular-nums;
            }

            .forecast-ref {
                display: block;
                margin-top: 2px;
                color: #94a3b8;
                font-size: 11px;
                font-weight: 400;
                line-height: 1.15;
            }

            .detail-muted {
                color: #94a3b8 !important;
                font-size: 12px;
            }

            .fc-detail-modal .modal-dialog {
                max-width: min(1500px, calc(100vw - 32px));
            }

            .fc-detail-modal .modal-body {
                max-height: calc(100vh - 160px);
                overflow: auto;
            }

            .fc-detail-modal .table-responsive {
                max-height: calc(100vh - 230px);
                overflow: auto;
            }

            .fc-detail-modal table {
                min-width: 1100px;
            }

            .fc-detail-modal thead th {
                position: sticky;
                top: 0;
                z-index: 2;
                background: #f8fafc;
            }

            @media (max-width: 991.98px) {
                .fc-detail-modal .modal-body,
                .fc-detail-modal .table-responsive {
                    max-height: none;
                }
            }
        </style>

        <div class="card fc-card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div class="fw-semibold">ตัวกรอง</div>
                <div class="d-flex gap-2">
                    <a href="{{ route(
                        'fc.exportExcel',
                        array_merge(request()->query(), [
                            'sku' => $skuLike,
                            'plan_month' => $planMonth,
                        ]),
                    ) }}"
                        class="btn btn-sm btn-success" id="btnExportExcel">
                        <i class="fa-solid fa-file-excel me-1"></i> Export Excel
                    </a>

                    <a href="{{ route(
                        'fc.supplierShortage',
                        array_merge(request()->query(), [
                            'sku' => $skuLike,
                            'plan_month' => $planMonth,
                        ]),
                    ) }}"
                        class="btn btn-sm btn-outline-dark">
                        <i class="fa-solid fa-truck-ramp-box me-1"></i> Supplier Summary
                    </a>

                    <a href="{{ route('fc.division') }}" id="go_division_btn" class="btn btn-sm btn-primary">
                        <i class="fa-solid fa-building-user me-1"></i> Division Forecast
                    </a>
                </div>
            </div>

            <div class="card-body">
                <form class="row g-3 align-items-end filter-row" method="get" action="{{ route('fc.index') }}">
                    @if ($shortageOnly ?? false)
                        <input type="hidden" name="shortage_only" value="1">
                    @endif
                    <div class="col-md-3">
                        <label class="form-label">SKU</label>
                        <div class="position-relative">
                            <input class="form-control form-control-sm" name="sku" id="sku_input"
                                value="{{ $skuLike }}" placeholder="พิมพ์หลาย SKU คั่นด้วย , เช่น R115..., R11SMN...">
                            <div id="sku_suggest_box" class="list-group position-absolute w-100 shadow-sm"
                                style="z-index:1055; display:none; max-height:260px; overflow:auto;"></div>
                        </div>
                    </div>

                    {{-- <div class="col-md-3">
                        <label class="form-label">บริษัท</label>
                        <select class="form-select form-select-sm" name="company" id="company_select">
                            <option value="ALL" @selected($companyMode === 'ALL')>ทั้งหมด</option>
                            <option value="WIRE" @selected($companyMode === 'WIRE')>WIRE</option>
                            <option value="PLUS" @selected($companyMode === 'PLUS')>PLUS</option>
                        </select>
                    </div> --}}

                    <div class="col-md-3 filter-field-wrap">
                        <label class="form-label">Supplier</label>
                        <select class="form-select form-select-sm" id="supplier_select" name="suppliers[]" multiple>
                            @foreach ($supplierOptions as $sp)
                                <option value="{{ $sp['supplier_code'] }}" @selected(in_array($sp['supplier_code'], $selectedSuppliers ?? [], true))>
                                    {{ $sp['supplier_code'] }} - {{ $sp['supplier_name'] }}
                                </option>
                            @endforeach
                        </select>

                    </div>

                    <div class="col-md-3">
                        <label class="form-label">เดือน Forecast</label>
                        <input type="month" class="form-control form-control-sm" name="plan_month"
                            value="{{ \Carbon\Carbon::parse($planMonth)->format('Y-m') }}">
                    </div>

                    <div class="col-md-3 filter-field-wrap">
                        <label class="form-label">Grade</label>
                        <select class="form-select form-select-sm" id="grade_select" name="grades[]" multiple>
                            @foreach ($gradeOptions as $gr)
                                <option value="{{ $gr }}" @selected(in_array($gr, $selectedGrades ?? [], true))>
                                    {{ $gr }}
                                </option>
                            @endforeach
                        </select>

                    </div>

                    <div class="col-12 d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="fa-solid fa-filter me-1"></i> กรองข้อมูล
                        </button>
                        <button type="submit" name="shortage_only" value="1"
                            class="btn btn-sm {{ ($shortageOnly ?? false) ? 'btn-danger' : 'btn-outline-danger' }}"
                            title="แสดงเฉพาะ RM Part ที่ต้องสั่งเพิ่มเป็นสีแดง" data-bs-toggle="tooltip"
                            aria-pressed="{{ ($shortageOnly ?? false) ? 'true' : 'false' }}">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i> เฉพาะขาด
                        </button>
                        @if ($shortageOnly ?? false)
                            <a class="btn btn-sm btn-outline-secondary"
                                href="{{ route('fc.index', request()->except('shortage_only')) }}">
                                <i class="fa-solid fa-eye me-1"></i> ดูทั้งหมด
                            </a>
                        @endif
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('fc.index') }}">
                            <i class="fa-solid fa-rotate-left me-1"></i> ล้างตัวกรอง
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-md-2">
                <div class="card fc-card">
                    <div class="card-body py-2">
                        <div class="text-muted small">จำนวน RM Part</div>
                        <div class="fs-4 fw-bold">{{ number_format($kpi['items']) }}</div>
                    </div>
                </div>
            </div>

            <div class="col-md-2">
                <div class="card fc-card">
                    <div class="card-body py-2">
                        <div class="text-muted small">Forecast รวม</div>
                        <div class="fs-4 fw-bold">{{ number_format($kpi['total_forecast_sum'], 2) }}</div>
                    </div>
                </div>
            </div>

            <div class="col-md-2">
                <div class="card fc-card">
                    <div class="card-body py-2">
                        <div class="text-muted small">Need To Order รวม</div>
                        <div class="fs-4 fw-bold">{{ number_format($kpi['need_to_order_sum'], 2) }}</div>
                    </div>
                </div>
            </div>

            <div class="col-md-2">
                <div class="card fc-card">
                    <div class="card-body py-2">
                        <div class="text-muted small">Onhand รวม</div>
                        <div class="fs-4 fw-bold">{{ number_format($kpi['onhand_sum'], 2) }}</div>
                    </div>
                </div>
            </div>

            <div class="col-md-2">
                <div class="card fc-card">
                    <div class="card-body py-2">
                        <div class="text-muted small">FG รวม</div>
                        <div class="fs-4 fw-bold">{{ number_format($kpi['fg_sum'] ?? 0, 2) }}</div>
                    </div>
                </div>
            </div>

            <div class="col-md-2">
                <div class="card fc-card">
                    <div class="card-body py-2">
                        <div class="text-muted small">SO รวม</div>
                        <div class="fs-4 fw-bold">{{ number_format($kpi['so_sum'], 2) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card fc-card mb-3">
            <div class="card-body py-2">
                <div class="d-flex gap-2 flex-wrap align-items-center mb-2">
                    <span class="legend-chip"><span class="legend-dot dot-cpa13"></span> Avg 3M / Avg 6M จาก CPA13</span>
                    <span class="legend-chip"><span class="legend-dot dot-stock"></span> Onhand</span>
                    <span class="legend-chip"><span class="legend-dot dot-stock"></span> FG</span>
                    <span class="legend-chip"><span class="legend-dot dot-po"></span> Total PO</span>
                    <span class="legend-chip"><span class="legend-dot dot-sales"></span> Total Forecast</span>
                    <span class="legend-chip"><span class="legend-dot dot-supply"></span> Forecast + SO</span>
                    <span class="legend-chip"><span class="legend-dot dot-sales"></span> Safety(Planner)</span>
                </div>
                <div class="small text-muted">
                    หมายเหตุ: คลิก RM Part เพื่อดูย้อนหลังรายเดือน และคลิก Total PO / WIP / SO เพื่อดูรายละเอียดรายการ
                </div>
            </div>
        </div>

        <div class="card fc-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div class="fw-semibold">สรุปรายการ</div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="toggleDivisionCols">
                        ซ่อน Division
                    </button>
                    <div class="small text-muted">Forecast เดือน {{ \Carbon\Carbon::parse($planMonth)->format('m/Y') }}
                    </div>
                </div>
            </div>

            @if ($rows->isEmpty())
                <div class="empty-state">
                    <div class="fw-semibold mb-2">ไม่พบข้อมูลตามเงื่อนไขที่เลือก</div>
                    <div class="small mb-3">ลองเปลี่ยน SKU, บริษัท หรือเดือน Forecast แล้วค้นหาอีกครั้ง</div>
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('fc.index') }}">
                        <i class="fa-solid fa-rotate-left me-1"></i> ล้างตัวกรอง
                    </a>
                </div>
            @else
                @php
                    $displaySalesCodes = ['D1', 'D2', 'D3', 'D5', 'D6', 'D7', 'D9'];
                    $canManualOrder = $canManualOrder ?? false;
                @endphp
                <div class="excel-wrap">
                    <table class="excel" id="forecastIndexTable">
                        <thead>
                            <tr>
                                <th class="sticky-col-1">RM Part</th>
                                <th class="sticky-col-2">Description</th>
                                <th style="min-width:110px;">Grade</th>
                                <th class="supplier-col">Supplier</th>

                                <th class="h-cpa13" style="min-width:90px;">Avg 3M</th>
                                <th class="h-cpa13" style="min-width:90px;">Avg 6M</th>
                                <th class="h-stock" style="min-width:110px;">Onhand</th>
                                <th class="h-po" style="min-width:110px;">FG</th>
                                <th class="h-po" style="min-width:110px;">Total PO</th>
                                <th class="h-po" style="min-width:110px;">WIP</th>
                                <th class="h-po" style="min-width:110px;">SO</th>

                                <th class="h-sales" style="min-width:120px;">Total Forecast</th>
                                <th class="h-sales" style="min-width:150px;">Safety Forecast (Planner)</th>
                                <th class="h-supply" style="min-width:140px;">Forecast + SO</th>
                                <th class="h-supply" style="min-width:140px;"
                                    title="แดง = ต้องสั่งเพิ่ม | เขียว = ของพอ/มีเกิน | ดำ = พอดี"
                                    data-bs-toggle="tooltip">
                                    ต้องสั่งเพิ่ม
                                </th>
                                <th class="h-supply" style="min-width:150px;"
                                    title="บันทึกตัวเลขสั่งเพิ่มเอง โดยไม่ทับค่าคำนวณเดิม" data-bs-toggle="tooltip">
                                    Manual สั่งเพิ่ม
                                </th>

                                @foreach ($displaySalesCodes as $code)
                                    <th class="h-sales division-col" style="min-width:90px;">{{ $code }}</th>
                                @endforeach
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($rows as $r)
                                @php
                                    $monthMap = $cpa13BySkuMonth[$r['sku']] ?? [];
                                    $isZeroRow =
                                        (float) $r['total_forecast'] == 0 &&
                                        (float) $r['po_total'] == 0 &&
                                        (float) $r['wip'] == 0 &&
                                        (float) $r['so'] == 0;

                                    $need = (float) ($r['need_to_order'] ?? 0);

                                    if ($need > 0) {
                                        $needText = number_format($need, 2);
                                        $needClass = 'text-danger';
                                        $needTitle = 'สีแดง = ต้องสั่งเพิ่ม';
                                    } elseif ($need < 0) {
                                        $needText = number_format(abs($need), 2);
                                        $needClass = 'text-success';
                                        $needTitle = 'สีเขียว = ของพอ / มีเกิน';
                                    } else {
                                        $needText = number_format(0, 2);
                                        $needClass = 'text-dark';
                                        $needTitle = 'สีดำ = พอดี';
                                    }

                                    $supplierDivisionText = collect($r['supplier_by_division'] ?? [])
                                        ->map(fn($name, $div) => $div . ' : ' . $name)
                                        ->implode(' | ');

                                    $supplierDisplay = trim(
                                        (string) ($r['display_supplier_name'] ?? ($r['primary_supplier_name'] ?? '')),
                                    );
                                    $supplierDisplay = $supplierDisplay !== '' ? $supplierDisplay : '-';
                                    $supplierSource = (string) ($r['display_supplier_source'] ?? 'MASTER');
                                    $manualSupplierTitle = trim((string) ($r['display_supplier_title'] ?? ''));
                                    $supplierTitleParts = [];

                                    if ($manualSupplierTitle !== '') {
                                        $supplierTitleParts[] =
                                            ($supplierSource === 'MANUAL' ? 'Manual: ' : 'Master: ') .
                                            $manualSupplierTitle;
                                    }

                                    if ($supplierDivisionText !== '') {
                                        $supplierTitleParts[] = 'Division: ' . $supplierDivisionText;
                                    }

                                    $supplierTitle = implode(' | ', $supplierTitleParts);
                                    if ($supplierTitle === '') {
                                        $supplierTitle = $supplierDisplay;
                                    }
                                @endphp

                                <tr class="{{ $isZeroRow ? 'row-zero' : '' }}">
                                    <td class="sticky-col-1 fw-semibold">
                                        <a href="javascript:void(0)" class="text-decoration-none btn-history"
                                            data-sku="{{ $r['sku'] }}" data-description="{{ $r['description'] }}"
                                            data-history='@json($monthMap)'>
                                            {{ $r['sku'] }}
                                        </a>
                                    </td>

                                    <td class="sticky-col-2" title="{{ $r['description'] }}">{{ $r['description'] }}
                                    </td>
                                    <td>{{ $r['grade'] ?? '-' }}</td>

                                    <td class="supplier-col" title="{{ $supplierTitle }}" data-bs-toggle="tooltip">
                                        @if ($supplierSource === 'MANUAL')
                                            <span class="badge bg-warning text-dark me-1">Manual</span>
                                        @endif
                                        {{ $supplierDisplay }}
                                    </td>

                                    <td class="num">{{ number_format((float) $r['avg3'], 2) }}</td>
                                    <td class="num">{{ number_format((float) $r['avg6'], 2) }}</td>
                                    <td class="num">{{ number_format((float) $r['onhand'], 2) }}</td>
                                    <td class="num">{{ number_format((float) ($r['fg'] ?? 0), 2) }}</td>

                                    <td class="num">
                                        <a href="javascript:void(0)" class="text-decoration-none btn-po-detail"
                                            data-sku="{{ $r['sku'] }}" data-company="{{ $companyMode }}">
                                            {{ number_format((float) $r['po_total'], 2) }}
                                        </a>
                                    </td>

                                    <td class="num">
                                        <a href="javascript:void(0)" class="text-decoration-none btn-wip-detail"
                                            data-sku="{{ $r['sku'] }}" data-company="{{ $companyMode }}">
                                            {{ number_format((float) $r['wip'], 2) }}
                                        </a>
                                    </td>

                                    <td class="num">
                                        <a href="javascript:void(0)" class="text-decoration-none btn-so-detail"
                                            data-sku="{{ $r['sku'] }}" data-company="{{ $companyMode }}">
                                            {{ number_format((float) $r['so'], 2) }}
                                        </a>
                                    </td>

                                    <td class="num fw-semibold">
                                        <a href="javascript:void(0)" class="btn-link clean-link btn-forecast-detail"
                                            data-sku="{{ $r['sku'] }}" data-sales=""
                                            data-company="{{ $companyMode }}" data-plan-month="{{ $planMonth }}">
                                            {{ number_format((float) $r['total_forecast'], 2) }}
                                        </a>
                                        @if ((float) ($r['total_approval_forecast'] ?? 0) > 0 && abs((float) ($r['total_forecast'] ?? 0) - (float) ($r['total_division_forecast'] ?? 0)) > 0.01)
                                            <span class="forecast-ref">
                                                Div {{ number_format((float) ($r['total_division_forecast'] ?? 0), 2) }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="num fw-semibold">
                                        {{ number_format((float) ($r['safety_forecast_planner'] ?? 0), 2) }}</td>
                                    <td class="num fw-semibold">{{ number_format((float) $r['total_forecast_so'], 2) }}
                                    </td>

                                    <td class="num fw-semibold {{ $needClass }}" title="{{ $needTitle }}"
                                        data-bs-toggle="tooltip">
                                        {{ $needText }}
                                    </td>

                                    <td class="num">
                                        @if ($canManualOrder)
                                            <button type="button"
                                                class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1 manual-order-chip btn-manual-order"
                                                data-sku="{{ $r['sku'] }}"
                                                data-description="{{ $r['description'] }}"
                                                data-company="{{ $companyMode }}" data-plan-month="{{ $planMonth }}"
                                                data-auto-need="{{ round(max((float) ($r['need_to_order'] ?? 0), 0), 2) }}"
                                                data-manual-qty="{{ round((float) ($r['manual_order_qty'] ?? 0), 2) }}"
                                                data-supplier-codes='@json($r['manual_order_supplier_codes'] ?? [])'
                                                data-supplier-qty-map='@json($r['manual_order_supplier_qty_by_code'] ?? [])'
                                                data-remark="{{ e($r['manual_order_remark'] ?? '') }}">
                                                <span>{{ number_format((float) ($r['manual_order_qty'] ?? 0), 2) }}</span>
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </button>
                                        @else
                                            <span class="text-muted" title="ต้องมีสิทธิ์เพื่อแก้ไข Manual"
                                                data-bs-toggle="tooltip">
                                                {{ number_format((float) ($r['manual_order_qty'] ?? 0), 2) }}
                                            </span>
                                        @endif
                                    </td>

                                    @foreach ($displaySalesCodes as $code)
                                        @php $key = strtolower($code); @endphp
                                        <td class="num division-col">
                                            @if ((float) ($r[$key] ?? 0) > 0)
                                                <a href="javascript:void(0)"
                                                    class="btn-link clean-link btn-forecast-detail"
                                                    data-sku="{{ $r['sku'] }}" data-sales="{{ $code }}"
                                                    data-company="{{ $companyMode }}"
                                                    data-plan-month="{{ $planMonth }}"
                                                    title="{{ (float) ($r[$key . '_approval'] ?? 0) > 0 ? 'Manager Forecast' : 'Division Forecast' }}"
                                                    data-bs-toggle="tooltip">
                                                    {{ number_format((float) ($r[$key] ?? 0), 2) }}
                                                </a>
                                                @if ((float) ($r[$key . '_approval'] ?? 0) > 0 && abs((float) ($r[$key . '_approval'] ?? 0) - (float) ($r[$key . '_division'] ?? 0)) > 0.01)
                                                    <span class="forecast-ref">
                                                        Div {{ number_format((float) ($r[$key . '_division'] ?? 0), 2) }}
                                                    </span>
                                                @endif
                                            @elseif ((float) ($r[$key . '_submitted'] ?? 0) > 0)
                                                <span class="badge bg-warning text-dark"
                                                    title="Division submitted, waiting for manager approval"
                                                    data-bs-toggle="tooltip">
                                                    {{ ($r[$key . '_status'] ?? '') === 'REJECTED' ? 'Rejected' : 'Pending' }}
                                                </span>
                                            @else
                                                <span class="text-muted">0.00</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>

                        <tfoot>
                            <tr>
                                <th class="sticky-col-1 text-end">รวม</th>
                                @for ($i = 0; $i < 3; $i++)
                                    <th></th>
                                @endfor
                                <th class="num">{{ number_format((float) ($kpi['avg3_sum'] ?? 0), 2) }}</th>
                                <th class="num">{{ number_format((float) ($kpi['avg6_sum'] ?? 0), 2) }}</th>
                                <th class="num">{{ number_format((float) ($kpi['onhand_sum'] ?? 0), 2) }}</th>
                                <th class="num">{{ number_format((float) ($kpi['fg_sum'] ?? 0), 2) }}</th>
                                <th class="num">{{ number_format((float) ($kpi['po_total_sum'] ?? 0), 2) }}</th>
                                <th class="num">{{ number_format((float) ($kpi['wip_sum'] ?? 0), 2) }}</th>
                                <th class="num">{{ number_format((float) ($kpi['so_sum'] ?? 0), 2) }}</th>
                                <th class="num">{{ number_format((float) ($kpi['total_forecast_sum'] ?? 0), 2) }}</th>
                                <th class="num">{{ number_format((float) ($kpi['planner_forecast_sum'] ?? 0), 2) }}
                                </th>
                                <th class="num">{{ number_format((float) ($kpi['total_forecast_so_sum'] ?? 0), 2) }}
                                </th>
                                <th class="num">
                                    {{ number_format((float) $rows->sum(fn($r) => max((float) ($r['need_to_order'] ?? 0), 0)), 2) }}
                                </th>
                                <th class="num">{{ number_format((float) ($kpi['manual_order_sum'] ?? 0), 2) }}</th>

                                @foreach ($displaySalesCodes as $code)
                                    @php $key = strtolower($code); @endphp
                                    <th class="num division-col">
                                        {{ number_format((float) $rows->sum($key), 2) }}
                                    </th>
                                @endforeach
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="modal fade fc-detail-modal" id="historyModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-lg-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">ย้อนหลังรายเดือน</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="fw-semibold" id="historySku">-</div>
                        <div class="text-muted small" id="historyDesc">-</div>
                    </div>

                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-bordered mb-0">
                            <thead>
                                <tr>
                                    @foreach ($cpa13Labels as $lb)
                                        <th class="text-center">{{ $lb }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                <tr id="historyRow"></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="fw-semibold mb-2">Detail รายการจริง</div>

                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Company</th>
                                    <th>Month</th>
                                    <th>Date</th>
                                    <th>WO</th>
                                    <th>FG Part</th>
                                    <th>FG Description</th>
                                    <th>Customer</th>
                                    <th class="text-end">Qty</th>
                                </tr>
                            </thead>
                            <tbody id="historyDetailBody"></tbody>
                        </table>
                    </div>

                    <div class="text-muted small mt-2" id="historyDetailEmpty" style="display:none;">
                        ไม่พบรายละเอียด
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade fc-detail-modal" id="detailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-lg-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="detailModalTitle">รายละเอียด</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0">
                            <thead id="detailTableHead"></thead>
                            <tbody id="detailTableBody"></tbody>
                        </table>
                    </div>
                    <div class="text-muted small mt-2" id="detailEmpty" style="display:none;">
                        ไม่พบข้อมูล
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if ($canManualOrder)
        <div class="modal fade fc-detail-modal" id="manualOrderModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-lg-down">
                <form class="modal-content" method="post" action="{{ route('fc.manualOrder.save') }}">
                    @csrf
                    <input type="hidden" name="plan_month" id="manualOrderPlanMonth">
                    <input type="hidden" name="company" id="manualOrderCompany">
                    <input type="hidden" name="sku" id="manualOrderSku">
                    <input type="hidden" name="description" id="manualOrderDescription">
                    <input type="hidden" name="auto_need_to_order" id="manualOrderAutoNeed">

                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title">Manual ต้องสั่งเพิ่ม</h5>
                            <div class="small text-muted" id="manualOrderSub">-</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">ต้องสั่งเพิ่มจากสูตรเดิม</label>
                                <input type="text" class="form-control form-control-sm text-end"
                                    id="manualOrderAutoNeedText" readonly>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Manual สั่งเพิ่มรวม</label>
                                <input type="hidden" name="manual_order_qty" id="manualOrderQty" value="0.00">
                                <input type="text" class="form-control form-control-sm text-end"
                                    id="manualOrderQtyText" value="0.00" readonly>
                                <div class="form-text">รวมจากยอดที่แยกตาม Supplier ด้านล่าง</div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Supplier ที่ต้องการสอบราคา/สั่งซื้อ</label>
                                <select class="form-select form-select-sm" name="supplier_codes[]"
                                    id="manualOrderSupplierSelect" multiple required>
                                    @foreach ($supplierOptions as $sp)
                                        <option value="{{ $sp['supplier_code'] }}">
                                            {{ $sp['supplier_code'] }} - {{ $sp['supplier_name'] }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="form-text">เลือกหลาย Supplier แล้วระบบจะแยกช่องกรอกจำนวนให้แต่ละราย</div>
                            </div>

                            <div class="col-12">
                                <div class="border rounded-3 p-2 bg-light">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div class="fw-semibold small">แยกยอด Manual ตาม Supplier</div>
                                        <div class="small text-muted">Total: <span
                                                id="manualSupplierSplitTotal">0.00</span></div>
                                    </div>
                                    <div id="manualSupplierSplitEmpty" class="text-muted small py-2">เลือก Supplier
                                        อย่างน้อย 1 ราย</div>
                                    <div id="manualSupplierSplitRows" class="d-grid gap-2"></div>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Remark</label>
                                <textarea class="form-control form-control-sm" name="remark" id="manualOrderRemark" rows="3"
                                    placeholder="เหตุผล/หมายเหตุเพิ่มเติม"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                            data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-sm btn-primary">บันทึก Manual</button>
                    </div>
                </form>
            </div>
        </div>

    @endif

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const input = document.getElementById('sku_input');
                const box = document.getElementById('sku_suggest_box');

                if (!input || !box) return;

                let timer = null;
                let suggestAbortController = null;

                function hideBox() {
                    box.style.display = 'none';
                    box.innerHTML = '';
                }

                function getLastToken(value) {
                    const parts = (value || '').split(',');
                    return (parts[parts.length - 1] || '').trim();
                }

                function replaceLastToken(selectedSku) {
                    const raw = input.value || '';
                    const parts = raw.split(',');
                    parts[parts.length - 1] = ' ' + selectedSku;
                    input.value = parts
                        .map((x) => x.trim())
                        .filter((x, i) => i < parts.length - 1 || x !== '')
                        .join(', ')
                        .replace(/^,\s*/, '');

                    if (!input.value.endsWith(', ')) {
                        input.value = input.value + ', ';
                    }
                }

                function showItems(items) {
                    if (!items.length) {
                        hideBox();
                        return;
                    }

                    box.innerHTML = items.map(item => `
            <button type="button" class="list-group-item list-group-item-action sku-item"
                data-sku="${item.sku}">
                <div class="fw-semibold">${item.sku}</div>
                <div class="small text-muted">${item.description || ''}</div>
            </button>
        `).join('');

                    box.style.display = 'block';

                    box.querySelectorAll('.sku-item').forEach(el => {
                        el.addEventListener('click', function() {
                            replaceLastToken(this.dataset.sku || '');
                            hideBox();
                            input.focus();
                        });
                    });
                }

                async function loadSuggest() {
                    const term = getLastToken(input.value || '');
                    if (!term) {
                        hideBox();
                        return;
                    }

                    try {
                        if (suggestAbortController) {
                            suggestAbortController.abort();
                        }
                        suggestAbortController = new AbortController();

                        const url = `{{ route('fc.skuAutocomplete') }}?term=${encodeURIComponent(term)}`;

                        const res = await fetch(url, {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            signal: suggestAbortController.signal
                        });

                        const items = await res.json();

                        if (!Array.isArray(items)) {
                            hideBox();
                            return;
                        }

                        showItems(items);
                    } catch (e) {
                        if (e.name !== 'AbortError') {
                            hideBox();
                        }
                    }
                }

                input.addEventListener('input', function() {
                    clearTimeout(timer);
                    timer = setTimeout(loadSuggest, 250);
                });

                input.addEventListener('focus', function() {
                    loadSuggest();
                });

                document.addEventListener('click', function(e) {
                    if (!box.contains(e.target) && e.target !== input) {
                        hideBox();
                    }
                });
            });

            document.addEventListener('DOMContentLoaded', function() {
                const modalEl = document.getElementById('historyModal');
                if (!modalEl) return;

                const modal = new bootstrap.Modal(modalEl);
                const skuEl = document.getElementById('historySku');
                const descEl = document.getElementById('historyDesc');
                const rowEl = document.getElementById('historyRow');
                const detailBodyEl = document.getElementById('historyDetailBody');
                const detailEmptyEl = document.getElementById('historyDetailEmpty');

                const historyMonths = @json($cpa13Months);
                const companyMode = @json($companyMode);

                let historyAbortController = null;
                let historyRequestSeq = 0;

                function fmtNumber(val) {
                    return Number(val || 0).toLocaleString(undefined, {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                }

                function escapeHtml(text) {
                    const div = document.createElement('div');
                    div.textContent = text ?? '';
                    return div.innerHTML;
                }

                function renderHistoryLoading() {
                    detailBodyEl.innerHTML = `
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                <div class="d-flex flex-column align-items-center gap-2">
                                    <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
                                    <div>กำลังโหลดข้อมูล...</div>
                                </div>
                            </td>
                        </tr>
                    `;
                }

                modalEl.addEventListener('hidden.bs.modal', function() {
                    if (historyAbortController) {
                        historyAbortController.abort();
                        historyAbortController = null;
                    }
                    detailBodyEl.innerHTML = '';
                    detailEmptyEl.style.display = 'none';
                });

                document.querySelectorAll('.btn-history').forEach(btn => {
                    btn.addEventListener('click', async function() {
                        const requestId = ++historyRequestSeq;
                        const sku = this.dataset.sku || '-';
                        const desc = this.dataset.description || '-';
                        let history = {};

                        try {
                            history = JSON.parse(this.dataset.history || '{}');
                        } catch (e) {
                            history = {};
                        }

                        skuEl.textContent = sku;
                        descEl.textContent = desc;

                        rowEl.innerHTML = '';
                        historyMonths.forEach(ym => {
                            const td = document.createElement('td');
                            td.className = 'text-end';
                            td.textContent = fmtNumber(history[ym] || 0);
                            rowEl.appendChild(td);
                        });

                        detailEmptyEl.style.display = 'none';
                        renderHistoryLoading();
                        modal.show();

                        try {
                            if (historyAbortController) {
                                historyAbortController.abort();
                            }
                            historyAbortController = new AbortController();

                            const url =
                                `{{ route('fc.historyDetail') }}?sku=${encodeURIComponent(sku)}&company=${encodeURIComponent(companyMode)}`;

                            const res = await fetch(url, {
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest'
                                },
                                signal: historyAbortController.signal
                            });

                            const items = await res.json();

                            if (requestId !== historyRequestSeq) return;

                            detailBodyEl.innerHTML = '';

                            if (!Array.isArray(items) || items.length === 0) {
                                detailEmptyEl.style.display = 'block';
                                return;
                            }

                            items.forEach(item => {
                                const tr = document.createElement('tr');
                                tr.innerHTML = `
                                    <td>${escapeHtml(item.company || '')}</td>
                                    <td>${escapeHtml(item.month_label || '')}</td>
                                    <td>${escapeHtml(item.transdate || '')}</td>
                                    <td>${escapeHtml(item.workordernumber || '')}</td>
                                    <td>${escapeHtml(item.fg_partnumber || '')}</td>
                                    <td>${escapeHtml(item.fg_description || '')}</td>
                                    <td>${escapeHtml(item.customer_name || '')}</td>
                                    <td class="text-end">${fmtNumber(item.qty || 0)}</td>
                                `;
                                detailBodyEl.appendChild(tr);
                            });
                        } catch (e) {
                            if (e.name === 'AbortError') return;
                            if (requestId !== historyRequestSeq) return;

                            detailBodyEl.innerHTML = '';
                            detailEmptyEl.style.display = 'block';
                        }
                    });
                });
            });

            document.addEventListener('DOMContentLoaded', function() {
                const btn = document.getElementById('go_division_btn');
                const supplierEl = document.getElementById('supplier_select');
                const gradeEl = document.getElementById('grade_select');
                const skuEl = document.querySelector('[name="sku"]');
                const planMonthEl = document.querySelector('[name="plan_month"]');

                if (!btn) return;

                btn.addEventListener('click', function(e) {
                    e.preventDefault();

                    const sku = skuEl ? ((skuEl.value || '').trim() || 'R') : 'R';
                    const planMonth = planMonthEl ? (planMonthEl.value || '') : '';

                    const params = new URLSearchParams({
                        sku: sku
                    });

                    if (planMonth) {
                        params.set('plan_month', planMonth);
                    }

                    if (supplierEl) {
                        Array.from(supplierEl.selectedOptions).forEach(opt => {
                            params.append('suppliers[]', opt.value);
                        });
                    }

                    if (gradeEl) {
                        Array.from(gradeEl.selectedOptions).forEach(opt => {
                            params.append('grades[]', opt.value);
                        });
                    }

                    window.location.href = `{{ route('fc.division') }}?${params.toString()}`;
                });
            });

            document.addEventListener('DOMContentLoaded', function() {
                const modalEl = document.getElementById('detailModal');
                if (!modalEl) return;

                const modal = new bootstrap.Modal(modalEl);
                const titleEl = document.getElementById('detailModalTitle');
                const headEl = document.getElementById('detailTableHead');
                const bodyEl = document.getElementById('detailTableBody');
                const emptyEl = document.getElementById('detailEmpty');

                let detailAbortController = null;
                let detailRequestSeq = 0;

                function renderLoadingRow(colspan, text = 'กำลังโหลดข้อมูล...') {
                    bodyEl.innerHTML = `
                        <tr>
                            <td colspan="${colspan}" class="text-center text-muted py-4">
                                <div class="d-flex flex-column align-items-center gap-2">
                                    <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
                                    <div>${text}</div>
                                </div>
                            </td>
                        </tr>
                    `;
                }

                modalEl.addEventListener('hidden.bs.modal', function() {
                    if (detailAbortController) {
                        detailAbortController.abort();
                        detailAbortController = null;
                    }
                    bodyEl.innerHTML = '';
                    emptyEl.style.display = 'none';
                });

                async function openDetailModal(title, url, columns) {
                    const requestId = ++detailRequestSeq;

                    if (detailAbortController) {
                        detailAbortController.abort();
                    }
                    detailAbortController = new AbortController();

                    titleEl.textContent = title;
                    headEl.innerHTML = '';
                    bodyEl.innerHTML = '';
                    emptyEl.style.display = 'none';

                    const headRow = document.createElement('tr');
                    columns.forEach(col => {
                        const th = document.createElement('th');
                        th.textContent = col.label;
                        headRow.appendChild(th);
                    });
                    headEl.appendChild(headRow);

                    renderLoadingRow(columns.length);
                    modal.show();

                    try {
                        const res = await fetch(url, {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            signal: detailAbortController.signal
                        });

                        const items = await res.json();

                        if (requestId !== detailRequestSeq) return;

                        bodyEl.innerHTML = '';
                        emptyEl.style.display = 'none';

                        if (!Array.isArray(items) || items.length === 0) {
                            emptyEl.style.display = 'block';
                            return;
                        }

                        items.forEach(item => {
                            const tr = document.createElement('tr');

                            columns.forEach(col => {
                                const td = document.createElement('td');
                                const value = item[col.key] ?? '';

                                if (col.type === 'number') {
                                    td.className = 'text-end';
                                    td.textContent = Number(value || 0).toLocaleString(undefined, {
                                        minimumFractionDigits: 2,
                                        maximumFractionDigits: 2
                                    });
                                } else {
                                    td.textContent = value;
                                }

                                if (col.className) {
                                    td.className = `${td.className} ${col.className}`.trim();
                                }

                                tr.appendChild(td);
                            });

                            bodyEl.appendChild(tr);
                        });
                    } catch (e) {
                        if (e.name === 'AbortError') return;
                        if (requestId !== detailRequestSeq) return;

                        bodyEl.innerHTML = '';
                        emptyEl.style.display = 'block';
                    }
                }

                document.querySelectorAll('.btn-po-detail').forEach(el => {
                    el.addEventListener('click', function() {
                        const sku = this.dataset.sku;
                        const company = this.dataset.company || 'ALL';
                        const url =
                            `{{ route('fc.poDetail') }}?sku=${encodeURIComponent(sku)}&company=${encodeURIComponent(company)}`;

                        openDetailModal(`PO Detail : ${sku}`, url, [{
                                key: 'company',
                                label: 'Company'
                            },
                            {
                                key: 'po_no',
                                label: 'PO No'
                            },
                            {
                                key: 'vendor',
                                label: 'Vendor'
                            },
                            {
                                key: 'req_date',
                                label: 'Req Date'
                            },
                            {
                                key: 'open',
                                label: 'Open Qty',
                                type: 'number'
                            },
                        ]);
                    });
                });

                document.querySelectorAll('.btn-wip-detail').forEach(el => {
                    el.addEventListener('click', function() {
                        const sku = this.dataset.sku;
                        const company = this.dataset.company || 'ALL';
                        const url =
                            `{{ route('fc.wipDetail') }}?sku=${encodeURIComponent(sku)}&company=${encodeURIComponent(company)}`;

                        openDetailModal(`WIP Detail : ${sku}`, url, [{
                                key: 'company',
                                label: 'Company'
                            },
                            {
                                key: 'fg_partnumber',
                                label: 'FG Part'
                            },
                            {
                                key: 'workordernumber',
                                label: 'WO'
                            },
                            {
                                key: 'dateopen',
                                label: 'Open Date'
                            },
                            {
                                key: 'reqdate',
                                label: 'Req Date'
                            },
                            {
                                key: 'customer_name',
                                label: 'Customer'
                            },
                            {
                                key: 'balance_qty',
                                label: 'WIP Qty',
                                type: 'number'
                            },
                        ]);
                    });
                });

                document.querySelectorAll('.btn-so-detail').forEach(el => {
                    el.addEventListener('click', function() {
                        const sku = this.dataset.sku;
                        const company = this.dataset.company || 'ALL';
                        const url =
                            `{{ route('fc.soDetail') }}?sku=${encodeURIComponent(sku)}&company=${encodeURIComponent(company)}`;

                        openDetailModal(`SO Detail : ${sku}`, url, [{
                                key: 'company',
                                label: 'Company'
                            },
                            {
                                key: 'fg_partnumber',
                                label: 'FG Part'
                            },
                            {
                                key: 'ordnumber',
                                label: 'SO No'
                            },
                            {
                                key: 'po',
                                label: 'Customer PO'
                            },
                            {
                                key: 'order_date',
                                label: 'Order Date'
                            },
                            {
                                key: 'due_date',
                                label: 'Due Date'
                            },
                            {
                                key: 'customer_name',
                                label: 'Customer'
                            },
                            {
                                key: 'backorder_qty',
                                label: 'SO Qty',
                                type: 'number'
                            },
                        ]);
                    });
                });

                document.querySelectorAll('.btn-forecast-detail').forEach(el => {
                    el.addEventListener('click', function() {
                        const sku = this.dataset.sku;
                        const sales = this.dataset.sales || '';
                        const company = this.dataset.company || 'ALL';
                        const planMonth = this.dataset.planMonth || '';
                        const params = new URLSearchParams({
                            sku,
                            company,
                            plan_month: planMonth
                        });
                        if (sales) params.set('sales_code', sales);

                        const url = `{{ route('fc.forecastDetail') }}?${params.toString()}`;
                        const title = sales ? `Forecast Detail : ${sku} | ${sales}` :
                            `Total Forecast Detail : ${sku}`;

                        openDetailModal(title, url, [{
                                key: 'sales_code',
                                label: 'Division'
                            },
                            {
                                key: 'customer_name',
                                label: 'Customer'
                            },
                            {
                                key: 'fg_partnumber',
                                label: 'FG Part'
                            },
                            {
                                key: 'fg_description',
                                label: 'FG Description'
                            },
                            {
                                key: 'history_avg6',
                                label: 'Avg 6M',
                                type: 'number'
                            },
                            {
                                key: 'k_factor',
                                label: 'K',
                                type: 'number'
                            },
                            {
                                key: 'forecast_1m',
                                label: 'Manager 1M',
                                type: 'number'
                            },
                            {
                                key: 'forecast_6m',
                                label: 'Manager 6M',
                                type: 'number'
                            },
                            {
                                key: 'division_forecast_1m',
                                label: 'Division 1M',
                                type: 'number',
                                className: 'detail-muted'
                            },
                            {
                                key: 'division_forecast_6m',
                                label: 'Division 6M',
                                type: 'number',
                                className: 'detail-muted'
                            },
                            {
                                key: 'row_remark',
                                label: 'Remark'
                            },
                            {
                                key: 'supplier_name',
                                label: 'Supplier'
                            },
                            {
                                key: 'source_type',
                                label: 'Source'
                            },
                        ]);
                    });
                });
            });

            document.addEventListener('DOMContentLoaded', function() {
                const table = document.getElementById('forecastIndexTable');
                if (!table || !table.tHead || !table.tBodies.length) return;

                window.forecastIndexTableState = window.forecastIndexTableState || {
                    filters: [],
                    sortCol: null,
                    sortDir: 1
                };

                const headerRow = table.tHead.rows[0];
                if (!headerRow || table.tHead.querySelector('.excel-filter-row')) return;

                const numericColumns = new Set();
                Array.from(headerRow.cells).forEach((th, idx) => {
                    const label = th.innerText.trim().toLowerCase();
                    const isText = ['rm part', 'description', 'grade', 'supplier'].some(x => label.includes(x));
                    if (!isText) numericColumns.add(idx);

                    th.classList.add('sortable');
                    const ind = document.createElement('span');
                    ind.className = 'sort-ind';
                    th.appendChild(ind);
                });

                const filterRow = document.createElement('tr');
                filterRow.className = 'excel-filter-row';

                Array.from(headerRow.cells).forEach((th, idx) => {
                    const fth = document.createElement('th');
                    fth.className = th.className;

                    const input = document.createElement('input');
                    input.type = 'text';
                    input.className = 'form-control form-control-sm col-filter';
                    input.dataset.filterCol = String(idx);
                    input.placeholder = numericColumns.has(idx) ? '>= หรือ ค้นหา' : 'กรอง';

                    fth.appendChild(input);
                    filterRow.appendChild(fth);
                });

                table.tHead.appendChild(filterRow);

                const bodyRows = () => Array.from(table.tBodies[0].rows);

                function normalizeText(v) {
                    return String(v || '').toLowerCase().replace(/\s+/g, ' ').trim();
                }

                function getCellValue(tr, col) {
                    const td = tr.cells[col];
                    if (!td) return '';
                    return td.innerText.trim();
                }

                function parseNum(v) {
                    return parseFloat(String(v || '').replace(/,/g, '').replace(/[^\d.-]/g, '')) || 0;
                }

                function compareFilter(cellText, filterText, isNum) {
                    const f = String(filterText || '').trim();
                    if (!f) return true;

                    if (isNum) {
                        const n = parseNum(cellText);
                        const m = f.match(/^(>=|<=|>|<|=)?\s*(-?\d+(?:\.\d+)?)$/);
                        if (m) {
                            const op = m[1] || '>=';
                            const x = parseFloat(m[2]);
                            if (op === '>=') return n >= x;
                            if (op === '<=') return n <= x;
                            if (op === '>') return n > x;
                            if (op === '<') return n < x;
                            if (op === '=') return Math.abs(n - x) < 0.0001;
                        }
                    }

                    return normalizeText(cellText).includes(normalizeText(f));
                }

                function applyFilters() {
                    const filters = Array.from(table.querySelectorAll('.col-filter'))
                        .map(input => ({
                            col: parseInt(input.dataset.filterCol || '0', 10),
                            value: input.value,
                            isNum: numericColumns.has(parseInt(input.dataset.filterCol || '0', 10)),
                        }))
                        .filter(x => String(x.value || '').trim() !== '');

                    window.forecastIndexTableState.filters = filters;

                    bodyRows().forEach(tr => {
                        const ok = filters.every(f => compareFilter(getCellValue(tr, f.col), f.value, f.isNum));
                        tr.style.display = ok ? '' : 'none';
                    });
                }

                table.querySelectorAll('.col-filter').forEach(input => {
                    input.addEventListener('input', applyFilters);
                    input.addEventListener('click', e => e.stopPropagation());
                });

                let currentSort = {
                    col: -1,
                    dir: 1
                };

                Array.from(headerRow.cells).forEach((th, col) => {
                    th.addEventListener('click', function() {
                        const dir = currentSort.col === col ? currentSort.dir * -1 : 1;
                        currentSort = {
                            col,
                            dir
                        };
                        window.forecastIndexTableState.sortCol = col;
                        window.forecastIndexTableState.sortDir = dir;

                        Array.from(headerRow.cells).forEach(h => {
                            const ind = h.querySelector('.sort-ind');
                            if (ind) ind.textContent = '';
                        });
                        const ind = th.querySelector('.sort-ind');
                        if (ind) ind.textContent = dir === 1 ? '▲' : '▼';

                        const isNum = numericColumns.has(col);
                        const sorted = bodyRows().sort((a, b) => {
                            const av = getCellValue(a, col);
                            const bv = getCellValue(b, col);

                            if (isNum) {
                                return (parseNum(av) - parseNum(bv)) * dir;
                            }

                            return normalizeText(av).localeCompare(normalizeText(bv)) * dir;
                        });

                        sorted.forEach(tr => table.tBodies[0].appendChild(tr));
                        applyFilters();
                    });
                });
            });

            document.addEventListener('DOMContentLoaded', function() {
                const exportBtn = document.getElementById('btnExportExcel');
                const table = document.getElementById('forecastIndexTable');

                if (!exportBtn || !table) return;

                exportBtn.addEventListener('click', function() {
                    const url = new URL(exportBtn.href, window.location.origin);
                    const filters = Array.from(table.querySelectorAll('.excel-filter-row .col-filter'))
                        .map(input => ({
                            col: parseInt(input.dataset.filterCol || '0', 10),
                            value: input.value || '',
                            isNum: (input.placeholder || '').includes('>='),
                        }))
                        .filter(x => String(x.value || '').trim() !== '');

                    if (filters.length) {
                        url.searchParams.set('table_filters', JSON.stringify(filters));
                    } else {
                        url.searchParams.delete('table_filters');
                    }

                    const state = window.forecastIndexTableState || {};
                    if (state.sortCol !== null && state.sortCol !== undefined) {
                        url.searchParams.set('sort_col', String(state.sortCol));
                        url.searchParams.set('sort_dir', String(state.sortDir || 1));
                    } else {
                        url.searchParams.delete('sort_col');
                        url.searchParams.delete('sort_dir');
                    }

                    exportBtn.href = url.toString();
                });
            });

            document.addEventListener('DOMContentLoaded', function() {
                const table = document.querySelector('table.excel');
                const btn = document.getElementById('toggleDivisionCols');

                if (!table || !btn) return;

                let collapsed = false;

                function applyDivisionState() {
                    if (collapsed) {
                        table.classList.add('division-hidden');
                        btn.textContent = 'ขยาย';
                    } else {
                        table.classList.remove('division-hidden');
                        btn.textContent = 'ซ่อน';
                    }
                }

                btn.addEventListener('click', function() {
                    collapsed = !collapsed;
                    applyDivisionState();
                });

                applyDivisionState();
            });

            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
                    new bootstrap.Tooltip(el);
                });
            });
            @if ($canManualOrder)
                document.addEventListener('DOMContentLoaded', function() {
                    const modalEl = document.getElementById('manualOrderModal');
                    const manualOrderForm = modalEl?.querySelector('form');
                    const supplierSelect = document.getElementById('manualOrderSupplierSelect');
                    const splitRowsEl = document.getElementById('manualSupplierSplitRows');
                    const splitEmptyEl = document.getElementById('manualSupplierSplitEmpty');
                    const splitTotalEl = document.getElementById('manualSupplierSplitTotal');
                    const manualTotalHidden = document.getElementById('manualOrderQty');
                    const manualTotalText = document.getElementById('manualOrderQtyText');
                    let manualSupplierTom = null;
                    let currentSupplierQtyMap = {};

                    function formatQty(n) {
                        return Number(n || 0).toLocaleString(undefined, {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        });
                    }

                    function normalCode(code) {
                        return String(code || '').trim().toUpperCase();
                    }

                    function supplierLabel(code) {
                        code = normalCode(code);
                        if (!supplierSelect) return code;
                        const opt = Array.from(supplierSelect.options).find(o => normalCode(o.value) === code);
                        return opt ? opt.textContent.trim() : code;
                    }

                    function getSelectedSupplierCodes() {
                        if (manualSupplierTom) {
                            return manualSupplierTom.items.map(normalCode).filter(Boolean);
                        }

                        if (!supplierSelect) return [];
                        return Array.from(supplierSelect.selectedOptions).map(opt => normalCode(opt.value)).filter(
                            Boolean);
                    }

                    function recalcSupplierSplitTotal() {
                        let total = 0;
                        splitRowsEl?.querySelectorAll('.js-manual-supplier-qty').forEach(input => {
                            total += parseFloat(input.value || '0') || 0;
                        });

                        const fixed = total.toFixed(2);
                        if (manualTotalHidden) manualTotalHidden.value = fixed;
                        if (manualTotalText) manualTotalText.value = formatQty(total);
                        if (splitTotalEl) splitTotalEl.textContent = formatQty(total);
                    }

                    function renderSupplierSplitRows() {
                        if (!splitRowsEl || !splitEmptyEl) return;

                        const oldValues = {};
                        splitRowsEl.querySelectorAll('.js-manual-supplier-qty').forEach(input => {
                            oldValues[normalCode(input.dataset.code)] = input.value;
                        });

                        const selectedCodes = getSelectedSupplierCodes();
                        splitRowsEl.innerHTML = '';

                        if (!selectedCodes.length) {
                            splitEmptyEl.style.display = '';
                            recalcSupplierSplitTotal();
                            return;
                        }

                        splitEmptyEl.style.display = 'none';

                        selectedCodes.forEach(code => {
                            const value = oldValues[code] ?? currentSupplierQtyMap[code] ?? '0.00';
                            const row = document.createElement('div');
                            row.className = 'row g-2 align-items-center bg-white border rounded-2 p-2';
                            row.innerHTML = `
                            <div class="col-md-7">
                                <div class="small fw-semibold">${supplierLabel(code)}</div>
                                <div class="text-muted small">${code}</div>
                            </div>
                            <div class="col-md-5">
                                <input type="number" step="0.01" min="0"
                                    class="form-control form-control-sm text-end js-manual-supplier-qty"
                                    name="supplier_order_qty[${code}]"
                                    data-code="${code}"
                                    value="${Number(value || 0).toFixed(2)}"
                                    placeholder="0.00">
                            </div>
                        `;
                            splitRowsEl.appendChild(row);
                        });

                        splitRowsEl.querySelectorAll('.js-manual-supplier-qty').forEach(input => {
                            input.addEventListener('input', recalcSupplierSplitTotal);
                            input.addEventListener('change', function() {
                                this.value = (parseFloat(this.value || '0') || 0).toFixed(2);
                                recalcSupplierSplitTotal();
                            });
                        });

                        recalcSupplierSplitTotal();
                    }

                    if (supplierSelect && window.TomSelect) {
                        manualSupplierTom = new TomSelect(supplierSelect, {
                            plugins: ['remove_button'],
                            create: false,
                            persist: false,
                            hideSelected: true,
                            closeAfterSelect: false,
                            placeholder: 'เลือก supplier ได้มากกว่า 1 ราย...',
                            onChange: renderSupplierSplitRows,
                        });
                    } else if (supplierSelect) {
                        supplierSelect.addEventListener('change', renderSupplierSplitRows);
                    }

                    function setSupplierValues(values) {
                        const arr = Array.isArray(values) ? values.map(normalCode).filter(Boolean) : [];
                        if (manualSupplierTom) {
                            manualSupplierTom.clear(true);
                            arr.forEach(v => manualSupplierTom.addItem(v, true));
                            manualSupplierTom.refreshOptions(false);
                        } else if (supplierSelect) {
                            Array.from(supplierSelect.options).forEach(opt => {
                                opt.selected = arr.includes(normalCode(opt.value));
                            });
                        }
                        renderSupplierSplitRows();
                    }

                    document.querySelectorAll('.btn-manual-order').forEach(btn => {
                        btn.addEventListener('click', function() {
                            const sku = this.dataset.sku || '';
                            const desc = this.dataset.description || '';
                            const autoNeed = parseFloat(this.dataset.autoNeed || '0') || 0;
                            const manualQty = parseFloat(this.dataset.manualQty || '0') || 0;
                            let suppliers = [];

                            try {
                                suppliers = JSON.parse(this.dataset.supplierCodes || '[]');
                            } catch (e) {
                                suppliers = [];
                            }

                            try {
                                currentSupplierQtyMap = JSON.parse(this.dataset.supplierQtyMap ||
                                    '{}') || {};
                            } catch (e) {
                                currentSupplierQtyMap = {};
                            }

                            currentSupplierQtyMap = Object.fromEntries(
                                Object.entries(currentSupplierQtyMap).map(([code, qty]) => [
                                    normalCode(code), Number(qty || 0).toFixed(2)
                                ])
                            );

                            // รองรับข้อมูลเก่า: ถ้าเคยมี total แต่ยังไม่มี qty แยก supplier ให้ใส่ยอดไว้ที่ supplier แรกก่อน
                            if (suppliers.length && Object.keys(currentSupplierQtyMap).length === 0 &&
                                manualQty > 0) {
                                currentSupplierQtyMap[normalCode(suppliers[0])] = manualQty.toFixed(2);
                            }

                            document.getElementById('manualOrderPlanMonth').value = this.dataset
                                .planMonth || '';
                            document.getElementById('manualOrderCompany').value = this.dataset
                                .company || 'ALL';
                            document.getElementById('manualOrderSku').value = sku;
                            document.getElementById('manualOrderDescription').value = desc;
                            document.getElementById('manualOrderAutoNeed').value = autoNeed.toFixed(2);
                            document.getElementById('manualOrderAutoNeedText').value = formatQty(
                                autoNeed);
                            document.getElementById('manualOrderRemark').value = this.dataset.remark ||
                                '';
                            document.getElementById('manualOrderSub').textContent = `${sku} | ${desc}`;

                            setSupplierValues(suppliers);

                            new bootstrap.Modal(modalEl).show();
                        });
                    });

                    manualOrderForm?.addEventListener('submit', function(e) {
                        const selectedCodes = getSelectedSupplierCodes();
                        if (!selectedCodes.length) {
                            e.preventDefault();
                            alert('กรุณาเลือก Supplier อย่างน้อย 1 ราย');
                            return;
                        }
                        recalcSupplierSplitTotal();
                    });
                });
            @endif
            document.addEventListener('DOMContentLoaded', function() {
                const supplierEl = document.getElementById('supplier_select');
                const gradeEl = document.getElementById('grade_select');

                if (supplierEl) {
                    new TomSelect(supplierEl, {
                        plugins: ['remove_button'],
                        create: false,
                        persist: false,
                        hideSelected: true,
                        closeAfterSelect: false,
                        placeholder: 'พิมพ์ค้นหา supplier...',
                    });
                }

                if (gradeEl) {
                    new TomSelect(gradeEl, {
                        plugins: ['remove_button'],
                        create: false,
                        persist: false,
                        hideSelected: true,
                        closeAfterSelect: false,
                        placeholder: 'พิมพ์ค้นหา grade...',
                    });
                }
            });
        </script>
    @endpush
@endsection
