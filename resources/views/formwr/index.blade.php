{{-- resources/views/formwr/index.blade.php --}}
@extends('layouts.layout')

@section('title', 'Wirerod Incoming')
@section('page-title', 'Wirerod Incoming')

@php
    $hasFilter =
        !empty($selected['vendor'] ?? []) ||
        !empty($selected['item'] ?? '') ||
        !empty($selected['po'] ?? '') ||
        count($selected['year'] ?? []) > 0 ||
        count($selected['company'] ?? []) !== 2;

    $activeCompanies = $selected['company'] ?? ['MENAM PLUS', 'MENAM WIRE'];
    $selectedYears = $selected['year'] ?? [now()->year];
    $selectedVendors = $selected['vendor'] ?? [];
@endphp

@section('styles')
    <style>
        :root {
            --wr-primary: #0d6efd;
            --wr-primary-soft: #eaf3ff;
            --wr-success-soft: #eaf7ef;
            --wr-border: #e5e7eb;
            --wr-text-soft: #6b7280;
            --wr-bg-soft: #f8fafc;
            --wr-card-shadow: 0 8px 24px rgba(15, 23, 42, .06);
            --wr-radius: 16px;
        }

        .wr-page-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .wr-subtitle {
            color: var(--wr-text-soft);
            font-size: .92rem;
        }

        .wr-toolbar {
            display: flex;
            gap: .75rem;
            flex-wrap: wrap;
        }

        .wr-card {
            border: 1px solid var(--wr-border);
            border-radius: var(--wr-radius);
            background: #fff;
            box-shadow: var(--wr-card-shadow);
        }

        .wr-filter-card {
            position: sticky;
            top: 10px;
            z-index: 20;
        }

        .wr-filter-head {
            padding: 1rem 1.25rem .5rem 1.25rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .wr-filter-title {
            font-size: 1rem;
            font-weight: 800;
            margin: 0;
        }

        .wr-filter-subtitle {
            color: var(--wr-text-soft);
            font-size: .84rem;
            margin-top: .2rem;
        }

        .wr-filter-body {
            padding: 1rem 1.25rem 1.25rem 1.25rem;
        }

        .wr-label {
            font-weight: 700;
            font-size: .9rem;
            margin-bottom: .45rem;
        }

        .wr-hint {
            font-size: .78rem;
            color: var(--wr-text-soft);
            margin-top: .35rem;
        }

        .wr-section-gap {
            margin-top: 1rem;
        }

        .wr-chip-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
        }

        .wr-chip {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            border: 1px solid #dbeafe;
            background: var(--wr-primary-soft);
            color: #1d4ed8;
            border-radius: 999px;
            padding: .36rem .7rem;
            font-size: .82rem;
            font-weight: 600;
        }

        .wr-chip-muted {
            border: 1px dashed #d1d5db;
            background: #fff;
            color: #6b7280;
        }

        .wr-kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }

        .wr-kpi-card {
            padding: 1rem 1rem .9rem;
            min-height: 108px;
        }

        .wr-kpi-label {
            color: var(--wr-text-soft);
            font-size: .84rem;
            margin-bottom: .55rem;
            line-height: 1.35;
        }

        .wr-kpi-value {
            font-size: 1.7rem;
            font-weight: 800;
            line-height: 1.1;
        }

        .wr-kpi-note {
            font-size: .75rem;
            color: #94a3b8;
            margin-top: .4rem;
        }

        .wr-grid-2 {
            display: grid;
            grid-template-columns: 1.05fr .95fr;
            gap: 1rem;
        }

        .wr-panel-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem 1.1rem .8rem;
            border-bottom: 1px solid #eef2f7;
        }

        .wr-panel-title {
            font-weight: 800;
            margin: 0;
            font-size: 1rem;
        }

        .wr-panel-subtitle {
            color: var(--wr-text-soft);
            font-size: .83rem;
            margin-top: .2rem;
        }

        .wr-panel-body {
            padding: 0;
        }

        .wr-badge-soft {
            border: 1px solid #dbeafe;
            background: #eff6ff;
            color: #1d4ed8;
            border-radius: 999px;
            padding: .22rem .55rem;
            font-size: .78rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .wr-badge-gray {
            border: 1px solid #e5e7eb;
            background: #f8fafc;
            color: #475569;
        }

        .wr-table-wrap {
            max-height: 560px;
            overflow: auto;
        }

        .wr-table {
            margin-bottom: 0;
            font-size: .92rem;
        }

        .wr-table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8fafc;
            border-bottom: 1px solid #dbe3ee;
            white-space: nowrap;
        }

        .wr-table tbody tr:hover {
            background: #fafcff;
        }

        .wr-table tfoot th,
        .wr-table tfoot td {
            background: #fbfdff;
            position: sticky;
            bottom: 0;
            z-index: 1;
        }

        .wr-table .text-soft {
            color: var(--wr-text-soft);
        }

        .wr-stat-box {
            display: flex;
            align-items: center;
            gap: .5rem;
            flex-wrap: wrap;
        }

        .wr-vendor-search {
            position: relative;
        }

        .wr-vendor-search .bi {
            position: absolute;
            left: .75rem;
            top: .72rem;
            color: #94a3b8;
        }

        .wr-vendor-search input {
            padding-left: 2.2rem;
        }

        .wr-select-tools {
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
            margin-bottom: .5rem;
        }

        .wr-company-box {
            display: flex;
            gap: .65rem;
            flex-wrap: wrap;
        }

        .wr-company-option {
            flex: 0 0 auto;
        }

        .wr-company-option input {
            display: none;
        }

        .wr-company-option label {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: .55rem .95rem;
            border: 1px solid var(--wr-border);
            border-radius: 999px;
            font-weight: 700;
            cursor: pointer;
            background: #fff;
            transition: .18s ease;
        }

        .wr-company-option input:checked+label {
            background: var(--wr-primary-soft);
            color: #1d4ed8;
            border-color: #bfdbfe;
        }

        .wr-result-summary {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
            margin: .35rem 0 1rem;
        }

        .wr-empty {
            padding: 2rem 1rem;
            text-align: center;
            color: var(--wr-text-soft);
        }

        .wr-po-inline {
            padding: .8rem 1rem !important;
        }

        .wr-po-list {
            margin: 0;
            padding-left: 1.1rem;
        }

        .wr-po-list li {
            margin-bottom: .35rem;
        }

        .wr-highlight-row {
            background: #fffaf0;
        }

        .wr-detail-card {
            margin-top: 1rem;
        }

        .wr-sticky-actions {
            position: sticky;
            bottom: 10px;
            z-index: 15;
            display: flex;
            justify-content: flex-end;
            margin-top: 1rem;
            pointer-events: none;
        }

        .wr-sticky-actions-inner {
            display: flex;
            gap: .65rem;
            background: rgba(255, 255, 255, .92);
            backdrop-filter: blur(8px);
            border: 1px solid var(--wr-border);
            border-radius: 999px;
            padding: .55rem;
            box-shadow: 0 10px 25px rgba(15, 23, 42, .08);
            pointer-events: auto;
        }

        @media (max-width: 1199.98px) {
            .wr-kpi-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .wr-grid-2 {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 767.98px) {
            .wr-kpi-grid {
                grid-template-columns: 1fr;
            }

            .wr-page-head,
            .wr-result-summary {
                flex-direction: column;
                align-items: stretch;
            }

            .wr-toolbar {
                width: 100%;
            }

            .wr-toolbar>* {
                flex: 1 1 auto;
            }
        }
    </style>
@endsection

@section('content')

    <div class="wr-page-head">
        <div>
            <h3 class="mb-1 fw-bold">รายงานวัตถุดิบ - ยอดค้างรับ</h3>
            <div class="wr-subtitle">
                ดูภาพรวม PO ค้างรับ, ยอดคงเหลือ, FG Stock และรายละเอียด PO โดยกรองได้ง่ายขึ้นในหน้าเดียว
            </div>
        </div>

        <div class="wr-toolbar">
            <a href="{{ route('wr.export', request()->query()) }}" class="btn btn-outline-primary">
                <i class="bi bi-filetype-xlsx me-1"></i> Export Excel
            </a>
            <a href="{{ route('wr.index') }}" class="btn btn-light border">
                <i class="bi bi-x-circle me-1"></i> ล้างทั้งหมด
            </a>
        </div>
    </div>

    <form method="GET" action="{{ route('wr.index') }}" id="filter-form">
        <div class="wr-card wr-filter-card">
            <div class="wr-filter-head">
                <h5 class="wr-filter-title">ตัวกรองข้อมูล</h5>
                <div class="wr-filter-subtitle">กรองแล้วกดใช้งานได้ทันที พร้อมแสดงตัวกรองที่กำลังเปิดอยู่ด้านล่าง</div>
            </div>

            <div class="wr-filter-body">
                <div class="row g-3">
                    <div class="col-xl-4">
                        <div class="wr-label">คู่ค้า</div>

                        <select name="vendor[]" multiple class="form-select" id="vendor-select">
                            @foreach ($filters['vendors'] ?? [] as $v)
                                <option value="{{ $v }}" @selected(in_array($v, $selectedVendors))>{{ $v }}
                                </option>
                            @endforeach
                        </select>
                        <div class="wr-hint">พิมพ์ค้นหาและเลือกได้หลายคู่ค้า</div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="wr-label">Item / Part / Description</div>
                        <input id="item-ts" name="item" type="text" class="form-control"
                            placeholder="ค้นหา Part หรือ Description" value="{{ $selected['item'] ?? '' }}"
                            autocomplete="off">
                        <div class="wr-hint">พิมพ์เพื่อค้นหา part ที่ขึ้นต้นด้วย R</div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="wr-label">เลขที่ใบสั่งซื้อ</div>
                        <input id="po-ts" name="po" type="text" class="form-control"
                            placeholder="เช่น POR2025..." value="{{ $selected['po'] ?? '' }}" autocomplete="off">
                        <div class="wr-hint">ค้นหาเลข PO ได้โดยตรง</div>
                    </div>

                    <div class="col-xl-2 col-md-6">
                        <div class="wr-label">ปีที่ต้องการดู</div>
                        <select name="year[]" class="form-select" multiple id="year-select">
                            @foreach ($filters['years'] ?? [] as $y)
                                <option value="{{ $y }}" @selected(in_array($y, $selectedYears))>{{ $y }}
                                </option>
                            @endforeach
                        </select>
                        <div class="wr-hint">เลือกได้หลายปี</div>
                    </div>

                    <div class="col-xl-6 col-md-12">
                        <div class="wr-label">บริษัท</div>
                        <div class="wr-company-box">
                            <div class="wr-company-option">
                                <input type="checkbox" name="company[]" value="MENAM PLUS" id="cPlus"
                                    @checked(in_array('MENAM PLUS', $activeCompanies))>
                                <label for="cPlus">MENAM PLUS</label>
                            </div>

                            <div class="wr-company-option">
                                <input type="checkbox" name="company[]" value="MENAM WIRE" id="cWire"
                                    @checked(in_array('MENAM WIRE', $activeCompanies))>
                                <label for="cWire">MENAM WIRE</label>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6 col-md-12 d-flex align-items-end justify-content-xl-end gap-2 flex-wrap">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-funnel me-1"></i> ใช้ตัวกรอง
                        </button>

                        <a href="{{ route('wr.index') }}" class="btn btn-light border px-4">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> เคลียร์ตัวกรอง
                        </a>
                    </div>
                </div>

                <div class="wr-section-gap">
                    <div class="wr-label mb-2">ตัวกรองที่ใช้อยู่</div>
                    <div class="wr-chip-wrap">
                        @if ($hasFilter)
                            @foreach ($activeCompanies as $company)
                                <span class="wr-chip">
                                    <i class="bi bi-building"></i> {{ $company }}
                                </span>
                            @endforeach

                            @foreach ($selectedYears as $year)
                                <span class="wr-chip">
                                    <i class="bi bi-calendar4"></i> ปี {{ $year }}
                                </span>
                            @endforeach

                            @if (!empty($selected['item']))
                                <span class="wr-chip">
                                    <i class="bi bi-upc-scan"></i> Item: {{ $selected['item'] }}
                                </span>
                            @endif

                            @if (!empty($selected['po']))
                                <span class="wr-chip">
                                    <i class="bi bi-file-earmark-text"></i> PO: {{ $selected['po'] }}
                                </span>
                            @endif

                            @foreach (array_slice($selectedVendors, 0, 4) as $vendor)
                                <span class="wr-chip">
                                    <i class="bi bi-people"></i> {{ $vendor }}
                                </span>
                            @endforeach

                            @if (count($selectedVendors) > 4)
                                <span class="wr-chip">+{{ count($selectedVendors) - 4 }} คู่ค้า</span>
                            @endif
                        @else
                            <span class="wr-chip wr-chip-muted">
                                <i class="bi bi-info-circle"></i> ตอนนี้ยังไม่ได้กรองเพิ่มเติม
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </form>

    <div class="wr-kpi-grid">
        <div class="wr-card wr-kpi-card">
            <div class="wr-kpi-label">บรรทัด PO ที่ยังไม่ถึงกำหนดรับ</div>
            <div class="wr-kpi-value">{{ number_format($kpi['po_lines_not_due'] ?? 0) }}</div>
            <div class="wr-kpi-note">จำนวนบรรทัดเอกสารที่ยังเปิดอยู่</div>
        </div>

        <div class="wr-card wr-kpi-card">
            <div class="wr-kpi-label">ค้างรับยังไม่ถึงกำหนด (KG.)</div>
            <div class="wr-kpi-value">{{ number_format($kpi['open_not_due'] ?? 0, 2) }}</div>
            <div class="wr-kpi-note">ใช้ติดตามรายการที่ยังไม่ overdue</div>
        </div>

        <div class="wr-card wr-kpi-card">
            <div class="wr-kpi-label">ค้างรับเกินกำหนด (KG.)</div>
            <div class="wr-kpi-value">{{ number_format($kpi['open_overdue'] ?? 0, 2) }}</div>
            <div class="wr-kpi-note">ดูความเสี่ยงที่ต้องเร่งติดตาม</div>
        </div>

        <div class="wr-card wr-kpi-card">
            <div class="wr-kpi-label">มูลค่าค้างรับยังไม่ถึงกำหนด (THB)</div>
            <div class="wr-kpi-value">{{ number_format($kpi['open_value_thb'] ?? 0, 2) }}</div>
            <div class="wr-kpi-note">มูลค่าค้างรับตามตัวกรองปัจจุบัน</div>
        </div>
    </div>

    <div class="wr-result-summary">
        <div class="wr-stat-box">
            <span class="wr-badge-soft">FG Stock {{ $fgItemsAll->count() ?? 0 }} แถว</span>
            <span class="wr-badge-soft wr-badge-gray">ยอดคงเหลือรวม {{ number_format($totals['balance'] ?? 0, 2) }}
                KG.</span>
            <span class="wr-badge-soft wr-badge-gray">ค้างรับรวม {{ number_format($totals['open'] ?? 0, 2) }} KG.</span>
        </div>
        <div class="text-muted small">
            ตารางด้านซ้ายเน้น “ยังไม่ถึงกำหนด” และด้านขวาเน้น “ของคงเหลือ + ค้างรับที่เหลือ/เกินกำหนด”
        </div>
    </div>

    <div class="wr-card mb-3">
        <div class="wr-panel-head">
            <div>
                <h5 class="wr-panel-title">FG Stock (Wire / Plus)</h5>
                <div class="wr-panel-subtitle">ใช้ดู stock ล่าสุดตาม item ที่กรองไว้</div>
            </div>
            <span class="wr-badge-soft {{ $fgItemsAll->count() ?? 0 ? '' : 'wr-badge-gray' }}">
                {{ $fgItemsAll->count() ?? 0 }} rows
            </span>
        </div>

        <div class="wr-panel-body">
            @if (($fgItemsAll->count() ?? 0) > 0)
                <div class="table-responsive">
                    <table class="table table-sm align-middle wr-table">
                        <thead>
                            <tr>
                                <th>Transdate</th>
                                <th>Site</th>
                                <th>Customer</th>
                                <th>Part</th>
                                <th>Description</th>
                                <th class="text-end">Receive</th>
                                <th class="text-end">Issue</th>
                                <th class="text-end">Balance</th>
                                <th>Unit</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($fgItemsAll as $r)
                                <tr>
                                    <td>{{ $r->transdate_max ? \Carbon\Carbon::parse($r->transdate_max)->format('Y-m-d') : '-' }}
                                    </td>
                                    <td>{{ $r->site }}</td>
                                    <td>{{ $r->customer }}</td>
                                    <td class="fw-semibold">{{ $r->rm_partnumber }}</td>
                                    <td class="text-truncate" style="max-width: 320px;" title="{{ $r->part_desc }}">
                                        {{ $r->part_desc }}</td>
                                    <td class="text-end">{{ number_format($r->receive_qty, 2) }}</td>
                                    <td class="text-end">{{ number_format($r->issue_qty, 2) }}</td>
                                    <td class="text-end fw-semibold">{{ number_format($r->balance_qty, 2) }}</td>
                                    <td>{{ $r->unit_name }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="wr-empty">
                    <div class="fw-semibold mb-1">ยังไม่พบข้อมูล FG Stock</div>
                    <div>ลองระบุ SKU / Item แล้วกดใช้ตัวกรองใหม่ หรือเช็ก Company ที่เลือกอีกครั้ง</div>
                </div>
            @endif
        </div>
    </div>

    @if ($stockReport['warnings'] ?? [])
        <div class="alert alert-warning">
            <div class="fw-semibold">MFG ที่ยังไม่นับเป็นยอดจอง</div>
            <ul class="mb-0 small">
                @foreach ($stockReport['warnings'] as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @include('formwr.shipment-summary')

    <div class="wr-grid-2">
        <div class="wr-card">
            <div class="wr-panel-head">
                <div>
                    <h5 class="wr-panel-title">PO ค้างรับตาม Item (รายเดือน)</h5>
                    <div class="wr-panel-subtitle">เน้นรายการที่ยังไม่ถึงกำหนดรับ เพื่อวางแผนล่วงหน้า</div>
                </div>
                <span class="wr-badge-soft">{{ count($poByItemMonth ?? []) }} items</span>
            </div>

            <div class="wr-panel-body">
                <div class="wr-table-wrap">
                    <table class="table table-sm wr-table">
                        <thead>
                            <tr>
                                <th style="min-width: 240px;">Item</th>
                                @foreach ($monthOrder as $m)
                                    <th class="text-end">{{ $monthLabels[$m] ?? $m }}</th>
                                @endforeach
                                <th class="text-end">รวม</th>
                            </tr>
                        </thead>

                        <tbody>
                            @forelse($poByItemMonth ?? [] as $row)
                                <tr>
                                    <td>
                                        <div class="fw-semibold text-uppercase">{{ $row['item'] }}</div>
                                        @if (!empty($row['description']))
                                            <div class="small text-soft text-truncate" title="{{ $row['description'] }}">
                                                {{ $row['description'] }}
                                            </div>
                                        @endif
                                    </td>

                                    @foreach ($monthOrder as $m)
                                        <td class="text-end">{{ number_format($row['by_month'][$m] ?? 0, 2) }}</td>
                                    @endforeach

                                    <td class="text-end fw-semibold">{{ number_format($row['total'] ?? 0, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ 2 + count($monthOrder) }}" class="wr-empty">
                                        ไม่พบข้อมูลตามตัวกรองที่เลือก</td>
                                </tr>
                            @endforelse
                        </tbody>

                        <tfoot>
                            <tr>
                                <th>รวม</th>
                                @foreach ($monthOrder as $m)
                                    <th class="text-end">{{ number_format($sumMonth['by_month'][$m] ?? 0, 2) }}</th>
                                @endforeach
                                <th class="text-end">{{ number_format($sumMonth['total'] ?? 0, 2) }}</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div class="wr-card">
            <div class="wr-panel-head">
                <div>
                    <h5 class="wr-panel-title">ยอดคงเหลือ / จองวัตถุดิบ / สุทธิ</h5>
                    <div class="wr-panel-subtitle">ยอดล่าสุด {{ $stockReport['retrieved_at'] }} · MFG ที่เบิกถึง {{ round(($stockReport['complete_ratio'] ?? 1) * 100) }}% ไม่นับจอง · คลิก Item เพื่อดู Heat / MFG · หน่วย KG.</div>
                </div>
                <span class="wr-badge-soft">{{ count($balanceWithOpen ?? []) }} items</span>
            </div>

            <div class="wr-panel-body">
                <div class="wr-table-wrap">
                    <table class="table table-sm align-middle wr-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>บริษัท</th>
                                <th class="text-end">ยอดคงเหลือ (KG.)</th><th class="text-end">จองคงเหลือ</th><th class="text-end">สุทธิ</th>
                                <th class="text-end">ค้างรับ (KG.)</th>
                                <th class="text-center" style="width: 130px;">รายละเอียด</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($balanceWithOpen as $r)
                                @php
                                    $poList = $r['overdue_po'] ?? [];
                                    $countPo = count($poList);
                                    $collapse = 'poRow' . $loop->index;
                                    $isOpen = ($r['open'] ?? 0) > 0;
                                @endphp

                                <tr class="{{ $isOpen ? 'wr-highlight-row' : '' }}">
                                    <td class="fw-semibold text-nowrap">@if ($r['stock_details'])<a href="#wr-stock-{{ $loop->index }}" data-bs-toggle="modal">{{ $r['item'] }}</a>@else {{ $r['item'] }} @endif</td>
                                    <td>{{ $r['company'] }}</td>
                                    <td class="text-end">{{ number_format($r['balance'], 2) }}</td><td class="text-end">{{ number_format($r['reserved'], 2) }}</td><td class="text-end {{ $r['net'] < 0 ? 'text-danger fw-bold' : '' }}">{{ number_format($r['net'], 2) }}</td>
                                    <td class="text-end fw-semibold {{ $isOpen ? 'text-danger' : '' }}">
                                        {{ number_format($r['open'], 2) }}
                                    </td>
                                    <td class="text-center">
                                        @if ($countPo > 0)
                                            <button class="btn btn-sm btn-outline-primary" type="button"
                                                data-bs-toggle="collapse" data-bs-target="#{{ $collapse }}"
                                                aria-expanded="false" aria-controls="{{ $collapse }}">
                                                PO ที่ค้าง ({{ $countPo }})
                                            </button>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                </tr>

                                @if ($countPo > 0)
                                    <tr class="collapse" id="{{ $collapse }}">
                                        <td colspan="7" class="wr-po-inline bg-light">
                                            <ul class="wr-po-list small">
                                                @foreach ($poList as $po)
                                                    <li>
                                                        <span class="fw-semibold">{{ $po['po_no'] }}</span>
                                                        — กำหนดรับ
                                                        {{ \Carbon\Carbon::parse($po['req_date'])->format('d/m/Y') }}
                                                        — ค้าง {{ number_format($po['open'], 2) }} KG.
                                                        @if (!empty($po['vendor']))
                                                            — {{ $po['vendor'] }}
                                                        @endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </td>
                                    </tr>
                                @endif
                            @empty
                                <tr>
                                    <td colspan="7" class="wr-empty">ไม่พบข้อมูลยอดคงเหลือ / ค้างรับ ตามตัวกรองนี้</td>
                                </tr>
                            @endforelse
                        </tbody>

                        <tfoot>
                            <tr>
                                <th colspan="2">รวม</th>
                                <th class="text-end">{{ number_format($totals['balance'] ?? 0, 2) }}</th><th class="text-end">{{ number_format($totals['reserved'], 2) }}</th><th class="text-end">{{ number_format($totals['net'], 2) }}</th>
                                <th class="text-end">{{ number_format($totals['open'] ?? 0, 2) }}</th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Keep dialogs outside the scrollable table so they are not clipped. --}}
    @foreach ($balanceWithOpen as $r)
        @if ($r['stock_details'])
            <div class="modal fade" id="wr-stock-{{ $loop->index }}" tabindex="-1" aria-labelledby="wr-stock-title-{{ $loop->index }}" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-lg-down">
                    <div class="modal-content">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title" id="wr-stock-title-{{ $loop->index }}">Heat / MFG · {{ $r['item'] }}</h5>
                                <div class="text-muted small">จำนวนหน่วย KG. · คลิก Heat เพื่อดูใบสั่งผลิต</div>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                        </div>
                        <div class="modal-body">
                            @include('formwr.stock-details', ['r' => $r, 'rowIndex' => $loop->index])
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endforeach

    @if (!empty($poLines))
        <div class="wr-card wr-detail-card">
            <div class="wr-panel-head">
                <div>
                    <h5 class="wr-panel-title">รายละเอียด PO ค้างรับ</h5>
                    <div class="wr-panel-subtitle">ตารางล่างใช้ตรวจสอบเอกสารรายบรรทัดแบบละเอียด</div>
                </div>
                <span class="wr-badge-soft">{{ count($poLines) }} rows</span>
            </div>

            <div class="wr-panel-body">
                <div class="wr-table-wrap" style="max-height: 680px;">
                    <table class="table table-sm table-hover wr-table">
                        <thead>
                            <tr>
                                <th>เลขที่ใบสั่งซื้อ</th>
                                <th>วันที่สั่ง</th>
                                <th>กำหนดรับ</th>
                                <th>คู่ค้า</th>
                                <th>RM Part</th>
                                <th class="text-end">สั่งซื้อ</th>
                                <th class="text-end">รับแล้ว</th>
                                <th class="text-end">ค้างรับ</th>
                                <th class="text-end">ราคา/หน่วย</th>
                                <th class="text-end">มูลค่าค้างรับ</th>
                                <th>บริษัท</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($poLines as $l)
                                <tr>
                                    <td class="text-nowrap fw-semibold">{{ $l['po_no'] }}</td>
                                    <td class="text-nowrap">
                                        {{ \Illuminate\Support\Carbon::parse($l['po_date'])->format('Y-m-d') }}</td>
                                    <td class="text-nowrap">
                                        {{ \Illuminate\Support\Carbon::parse($l['req_date'])->format('Y-m-d') }}</td>
                                    <td class="text-truncate" style="max-width: 220px;" title="{{ $l['vendor'] }}">
                                        {{ $l['vendor'] }}</td>
                                    <td class="text-uppercase">{{ $l['rm_partnumber'] }}</td>
                                    <td class="text-end">{{ number_format($l['qty'] ?? 0, 2) }}</td>
                                    <td class="text-end">{{ number_format($l['received'] ?? 0, 2) }}</td>
                                    <td class="text-end fw-semibold">{{ number_format($l['open'] ?? 0, 2) }}</td>
                                    <td class="text-end">{{ number_format($l['price_thb'] ?? 0, 2) }}</td>
                                    <td class="text-end">{{ number_format($l['open_value'] ?? 0, 2) }}</td>
                                    <td class="text-nowrap">{{ $l['company'] ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="5" class="text-end">รวม</th>
                                <th class="text-end">{{ number_format($rawTotals['qty'] ?? 0, 2) }}</th>
                                <th class="text-end">{{ number_format($rawTotals['received'] ?? 0, 2) }}</th>
                                <th class="text-end">{{ number_format($rawTotals['open'] ?? 0, 2) }}</th>
                                <th></th>
                                <th class="text-end">{{ number_format($rawTotals['open_value_thb'] ?? 0, 2) }}</th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    @endif

    <div class="wr-sticky-actions">
        <div class="wr-sticky-actions-inner">
            <button type="submit" form="filter-form" class="btn btn-primary">
                <i class="bi bi-funnel me-1"></i> ใช้ตัวกรอง
            </button>
            <a href="{{ route('wr.index') }}" class="btn btn-light border">
                <i class="bi bi-x-circle me-1"></i> เคลียร์
            </a>
        </div>
    </div>

@endsection

@push('scripts')
    <script>
        document.addEventListener('click', function(event) {
            const link=event.target.closest('a[href^="#wr-mfg-"]');
            if (!link) return;
            const detail=document.getElementById(link.getAttribute('href').slice(1));
            if (detail) detail.open=true;
        });
        function debounce(fn, wait = 300) {
            let t;
            return function(...args) {
                clearTimeout(t);
                t = setTimeout(() => fn.apply(this, args), wait);
            };
        }

        new TomSelect('#vendor-select', {
            plugins: ['remove_button'], maxItems: null, create: false,
            placeholder: 'ทุกคู่ค้า — พิมพ์เพื่อค้นหา', closeAfterSelect: true,
            hideSelected: true
        });
        new TomSelect('#year-select', {
            plugins: ['remove_button'], maxItems: null, create: false,
            placeholder: 'เลือกปีที่กำหนดรับ', closeAfterSelect: true
        });
        document.getElementById('filter-form').addEventListener('submit', function(event) {
            if (!document.querySelector('#filter-form input[name="company[]"]:checked')) {
                event.preventDefault();
                document.getElementById('cPlus').setCustomValidity('เลือกอย่างน้อยหนึ่งบริษัท');
                document.getElementById('cPlus').reportValidity();
            }
        });
        document.querySelectorAll('#filter-form input[name="company[]"]').forEach(function(input) {
            input.addEventListener('change', function() {
                document.getElementById('cPlus').setCustomValidity('');
            });
        });

        if (document.querySelector('#item-ts')) {
            new TomSelect('#item-ts', {
                valueField: 'id',
                labelField: 'text',
                searchField: ['text'],
                plugins: ['clear_button'],
                maxItems: 1,
                create: false,
                persist: false,
                preload: false,
                placeholder: 'ค้นหา Item / Part',
                load: debounce(function(query, callback) {
                    if ((query || '').trim().length < 2) {
                        callback();
                        return;
                    }
                    fetch(`{{ route('wr.autocomplete.items') }}?q=${encodeURIComponent(query)}`)
                        .then(res => res.ok ? res.json() : [])
                        .then(json => callback(json || []))
                        .catch(() => callback());
                })
            });
        }

        if (document.querySelector('#po-ts')) {
            new TomSelect('#po-ts', {
                valueField: 'id',
                labelField: 'text',
                searchField: ['text'],
                plugins: ['clear_button'],
                maxItems: 1,
                create: false,
                persist: false,
                preload: false,
                placeholder: 'ค้นหาเลขที่ PO',
                load: debounce(function(query, callback) {
                    if ((query || '').trim().length < 2) {
                        callback();
                        return;
                    }
                    fetch(`{{ route('wr.autocomplete.po') }}?q=${encodeURIComponent(query)}`)
                        .then(res => res.ok ? res.json() : [])
                        .then(json => callback(json || []))
                        .catch(() => callback());
                })
            });
        }
    </script>
@endpush
