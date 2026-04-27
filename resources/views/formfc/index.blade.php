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
                        class="btn btn-sm btn-success">
                        <i class="fa-solid fa-file-excel me-1"></i> Export Excel
                    </a>

                    <a href="{{ route('fc.division') }}" id="go_division_btn" class="btn btn-sm btn-primary">
                        <i class="fa-solid fa-building-user me-1"></i> Division Forecast
                    </a>
                </div>
            </div>

            <div class="card-body">
                <form class="row g-3 align-items-end filter-row" method="get" action="{{ route('fc.index') }}">
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
                @endphp
                <div class="excel-wrap">
                    <table class="excel">
                        <thead>
                            <tr>
                                <th class="sticky-col-1">RM Part</th>
                                <th class="sticky-col-2">Description</th>
                                <th style="min-width:110px;">Grade</th>
                                <th style="min-width:180px;">Supplier</th>

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

                                    <td
                                        @if ($supplierDivisionText !== '') title="{{ $supplierDivisionText }}" data-bs-toggle="tooltip" @endif>
                                        {{ $r['primary_supplier_name'] ?: '-' }}
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

                                    <td class="num fw-semibold">{{ number_format((float) $r['total_forecast'], 2) }}</td>
                                    <td class="num fw-semibold">
                                        {{ number_format((float) ($r['safety_forecast_planner'] ?? 0), 2) }}</td>
                                    <td class="num fw-semibold">{{ number_format((float) $r['total_forecast_so'], 2) }}
                                    </td>

                                    <td class="num fw-semibold {{ $needClass }}" title="{{ $needTitle }}"
                                        data-bs-toggle="tooltip">
                                        {{ $needText }}
                                    </td>

                                    @foreach ($displaySalesCodes as $code)
                                        @php $key = strtolower($code); @endphp
                                        <td class="num division-col">{{ number_format((float) ($r[$key] ?? 0), 2) }}</td>
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
                                <th class="num">{{ number_format((float) ($kpi['planner_forecast_sum'] ?? 0), 2) }}</th>
                                <th class="num">{{ number_format((float) ($kpi['total_forecast_so_sum'] ?? 0), 2) }}</th>
                                <th class="num">
                                    {{ number_format((float) $rows->sum(fn($r) => max((float) ($r['need_to_order'] ?? 0), 0)), 2) }}
                                </th>

                                @foreach ($displaySalesCodes as $code)
                                    @php $key = strtolower($code); @endphp
                                    <th class="num division-col">{{ number_format((float) $rows->sum($key), 2) }}</th>
                                @endforeach
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="modal fade" id="historyModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
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

    <div class="modal fade" id="detailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
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
