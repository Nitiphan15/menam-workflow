@extends('layouts.layout')

@section('title', 'Critical Spare Parts Dashboard')
@section('page-title', 'Critical Spare Parts Dashboard')

@cannot('SSC')
    @php abort(403, 'คุณไม่มีสิทธิ์ SSC สำหรับดู Critical Spare Parts'); @endphp
@endcannot

@section('content')
    @php
        $totalParts = $rows->count();
        $outOfStockCount = $rows->filter(fn($row) => (float) ($row->onhand ?? 0) <= 0)->count();
        $lowStockCount = $rows
            ->filter(
                fn($row) => isset($row->required_qty, $row->onhand) &&
                    (float) $row->onhand > 0 &&
                    (float) $row->onhand < (float) $row->required_qty,
            )
            ->count();
        $reorderCount = $rows
            ->filter(
                fn($row) => isset($row->reorder_point, $row->onhand) &&
                    (float) $row->onhand > 0 &&
                    (float) $row->onhand >= (float) ($row->required_qty ?? 0) &&
                    (float) $row->onhand <= (float) $row->reorder_point,
            )
            ->count();
        $highRiskCount = $outOfStockCount + $lowStockCount;
        $inactiveCount = $rows->filter(fn($row) => is_null($row->days_inactive) || $row->days_inactive >= 180)->count();
        $inventoryValue = $rows->sum('inventory_value');
        $lastUpdate = $rows->max('transdate');
        $machineOptions = $rows
            ->pluck('machine')
            ->map(fn($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
        $supplierOptions = $rows
            ->flatMap(
                fn($row) => collect([
                    $row->supplier,
                    ...explode(',', (string) $row->alternate_suppliers),
                ]),
            )
            ->map(fn($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    @endphp

    <style>
        .csp-dashboard {
            --csp-navy: #102a43;
            --csp-blue: #1261a0;
            --csp-sky: #e8f3fb;
            --csp-line: #dce6ee;
            --csp-muted: #627d98;
            --csp-danger: #c0392b;
            --csp-warning: #d97706;
            --csp-success: #1f7a4d;
            color: #243b53;
            padding-bottom: 2rem;
        }

        .csp-hero {
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1.5rem;
            padding: 1.6rem 1.75rem;
            margin-bottom: 1rem;
            border-radius: 18px;
            color: #fff;
            background: linear-gradient(120deg, #102a43 0%, #1261a0 62%, #1496bb 100%);
            box-shadow: 0 14px 30px rgba(16, 42, 67, .18);
        }

        .csp-hero::after {
            content: '';
            position: absolute;
            right: -80px;
            top: -130px;
            width: 310px;
            height: 310px;
            border: 52px solid rgba(255, 255, 255, .08);
            border-radius: 50%;
        }

        .csp-hero-copy,
        .csp-hero-actions {
            position: relative;
            z-index: 1;
        }

        .csp-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .3rem .7rem;
            margin-bottom: .7rem;
            border: 1px solid rgba(255, 255, 255, .28);
            border-radius: 999px;
            background: rgba(255, 255, 255, .1);
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .csp-hero h2 {
            margin: 0 0 .4rem;
            font-size: clamp(1.35rem, 2.5vw, 2rem);
            font-weight: 800;
        }

        .csp-hero p {
            max-width: 680px;
            margin: 0;
            color: rgba(255, 255, 255, .82);
            font-size: .92rem;
        }

        .csp-updated {
            margin-top: .7rem;
            color: rgba(255, 255, 255, .76);
            font-size: .78rem;
        }

        .csp-export-btn {
            white-space: nowrap;
            border: 0;
            background: #fff;
            color: var(--csp-navy);
            font-weight: 700;
            box-shadow: 0 8px 20px rgba(0, 0, 0, .14);
        }

        .csp-export-btn:hover {
            background: #eaf7ef;
            color: #17653f;
        }

        .csp-kpi-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: .75rem;
            margin-bottom: 1rem;
        }

        .csp-kpi {
            position: relative;
            min-height: 116px;
            padding: 1rem;
            overflow: hidden;
            border: 1px solid var(--csp-line);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 5px 16px rgba(16, 42, 67, .06);
        }

        .csp-kpi::before {
            content: '';
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            background: var(--kpi-color, var(--csp-blue));
        }

        .csp-kpi-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            margin-bottom: .55rem;
            border-radius: 9px;
            color: var(--kpi-color, var(--csp-blue));
            background: color-mix(in srgb, var(--kpi-color, var(--csp-blue)) 10%, white);
        }

        .csp-kpi-label {
            color: var(--csp-muted);
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .035em;
        }

        .csp-kpi-value {
            margin-top: .15rem;
            color: var(--csp-navy);
            font-size: 1.45rem;
            font-weight: 800;
            line-height: 1.15;
        }

        .csp-kpi-value.is-money {
            font-size: 1.12rem;
        }

        .csp-panel {
            border: 1px solid var(--csp-line);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 5px 16px rgba(16, 42, 67, .06);
        }

        .csp-alerts {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: .65rem;
            padding: .85rem;
            margin-bottom: 1rem;
        }

        .csp-alert {
            display: flex;
            align-items: center;
            gap: .75rem;
            width: 100%;
            padding: .75rem;
            border: 1px solid var(--csp-line);
            border-radius: 11px;
            background: #fff;
            color: inherit;
            text-align: left;
            transition: border-color .15s ease, background .15s ease, transform .15s ease;
        }

        .csp-alert:hover,
        .csp-alert.is-active {
            border-color: var(--alert-color, var(--csp-blue));
            background: color-mix(in srgb, var(--alert-color, var(--csp-blue)) 6%, white);
            transform: translateY(-1px);
        }

        .csp-alert-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 38px;
            height: 38px;
            border-radius: 10px;
            color: #fff;
            background: var(--alert-color, var(--csp-blue));
        }

        .csp-alert strong {
            display: block;
            color: var(--csp-navy);
            font-size: .88rem;
        }

        .csp-alert span {
            color: var(--csp-muted);
            font-size: .76rem;
        }

        .csp-table-panel {
            overflow: hidden;
        }

        .csp-toolbar {
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid var(--csp-line);
        }

        .csp-toolbar-title h5 {
            margin: 0;
            color: var(--csp-navy);
            font-weight: 800;
        }

        .csp-toolbar-title small {
            color: var(--csp-muted);
        }

        .csp-filter-form {
            display: flex;
            align-items: end;
            gap: .6rem;
        }

        .csp-filter-field label {
            display: block;
            margin-bottom: .25rem;
            color: var(--csp-muted);
            font-size: .7rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .csp-filter-field .form-control,
        .csp-filter-field .form-select {
            min-height: 36px;
            border-color: var(--csp-line);
            font-size: .82rem;
        }

        .csp-filter-field .ts-wrapper {
            min-width: 145px;
        }

        .csp-filter-field .ts-control {
            min-height: 36px;
            border-color: var(--csp-line);
            font-size: .82rem;
        }

        .csp-filter-field .ts-control > input {
            min-width: 70px;
        }

        .csp-search {
            min-width: 250px;
        }

        .csp-table-wrap {
            max-height: 610px;
            overflow: auto;
        }

        .csp-table {
            min-width: 1930px;
            margin: 0;
        }

        .csp-table thead {
            position: sticky;
            top: 0;
            z-index: 4;
        }

        .csp-table thead th {
            padding: .8rem .7rem;
            border-bottom: 1px solid #c8d7e2;
            background: #edf4f8;
            color: #486581;
            font-size: .69rem;
            letter-spacing: .035em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .csp-table tbody td {
            padding: .75rem .7rem;
            border-color: #edf2f6;
            color: #334e68;
            font-size: .8rem;
            vertical-align: middle;
        }

        .csp-table tbody tr:hover td {
            background: #f6fbfe;
        }

        .csp-part-number {
            color: var(--csp-blue);
            font-weight: 800;
            white-space: nowrap;
        }

        .csp-site {
            display: inline-flex;
            padding: .25rem .55rem;
            border: 1px solid #c9dce8;
            border-radius: 999px;
            color: #315b73;
            background: #f0f7fb;
            font-size: .7rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .csp-part-name {
            min-width: 380px;
            max-width: 480px;
            font-weight: 600;
        }

        .csp-remark-col {
            min-width: 210px;
            white-space: normal;
        }

        .csp-supplier-col {
            min-width: 230px;
            white-space: normal;
        }

        .csp-subtext {
            display: block;
            margin-top: .16rem;
            color: #829ab1;
            font-size: .7rem;
            font-weight: 400;
        }

        .csp-number {
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .csp-stock {
            font-size: .95rem;
            font-weight: 800;
        }

        .csp-stock.is-low {
            color: var(--csp-danger);
        }

        .csp-stock.is-ok {
            color: var(--csp-success);
        }

        .csp-status {
            display: inline-flex;
            align-items: center;
            gap: .32rem;
            padding: .3rem .58rem;
            border-radius: 999px;
            font-size: .7rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .csp-status-out {
            color: #8a1c13;
            background: #fde8e6;
        }

        .csp-status-low {
            color: #9a4d05;
            background: #fff1d6;
        }

        .csp-status-reorder {
            color: #166082;
            background: #e3f3fa;
        }

        .csp-status-ok {
            color: #17653f;
            background: #e6f5ec;
        }

        .csp-table-footer {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: .7rem 1rem;
            border-top: 1px solid var(--csp-line);
            color: var(--csp-muted);
            background: #fbfdfe;
            font-size: .75rem;
        }

        .csp-empty-filter {
            display: none;
            padding: 2.5rem 1rem;
            color: var(--csp-muted);
            text-align: center;
        }

        @media (max-width: 1399.98px) {
            .csp-kpi-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 991.98px) {
            .csp-alerts {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .csp-toolbar {
                align-items: stretch;
                flex-direction: column;
            }

            .csp-filter-form {
                align-items: stretch;
            }

            .csp-filter-field,
            .csp-filter-field .form-control,
            .csp-filter-field .form-select {
                width: 100%;
            }

            .csp-search {
                min-width: 0;
            }
        }

        @media (max-width: 767.98px) {
            .csp-hero {
                align-items: flex-start;
                flex-direction: column;
                padding: 1.25rem;
            }

            .csp-hero-actions,
            .csp-export-btn {
                width: 100%;
            }

            .csp-kpi-grid,
            .csp-alerts {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .csp-kpi {
                min-height: 108px;
            }

            .csp-filter-form {
                flex-direction: column;
            }
        }

        @media (max-width: 430px) {
            .csp-kpi-grid,
            .csp-alerts {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="csp-dashboard">
        <section class="csp-hero" aria-labelledby="csp-title">
            <div class="csp-hero-copy">
                <div class="csp-eyebrow">
                    <i class="fa-solid fa-shield-halved"></i>
                    Shaft 1 Department · Site Plus &amp; Wire
                </div>
                <h2 id="csp-title">Critical Spare Parts Dashboard</h2>
                <p>ติดตามระดับสต็อกอะไหล่สำคัญของแผนกเพลา 1 เพื่อระบุความเสี่ยงและเตรียมแผนจัดซื้อได้ทันเวลา</p>
                <div class="csp-updated">
                    <i class="fa-regular fa-clock me-1"></i>
                    ข้อมูลล่าสุด {{ $lastUpdate ? \Carbon\Carbon::parse($lastUpdate)->format('d/m/Y') : 'ไม่พบวันที่เคลื่อนไหว' }}
                </div>
            </div>
            <div class="csp-hero-actions">
                <a href="{{ route('ssc.export') }}" class="btn csp-export-btn px-3 py-2">
                    <i class="fa-solid fa-file-excel me-2 text-success"></i>Export Excel
                </a>
            </div>
        </section>

        <section class="csp-kpi-grid" aria-label="Executive summary">
            <article class="csp-kpi" style="--kpi-color: #1261a0">
                <div class="csp-kpi-icon"><i class="fa-solid fa-gears"></i></div>
                <div class="csp-kpi-label">Critical Parts</div>
                <div class="csp-kpi-value">{{ number_format($totalParts) }}</div>
            </article>
            <article class="csp-kpi" style="--kpi-color: #d97706">
                <div class="csp-kpi-icon"><i class="fa-solid fa-arrow-trend-down"></i></div>
                <div class="csp-kpi-label">Below Minimum</div>
                <div class="csp-kpi-value">{{ number_format($lowStockCount) }}</div>
            </article>
            <article class="csp-kpi" style="--kpi-color: #5b5bd6">
                <div class="csp-kpi-icon"><i class="fa-solid fa-cart-shopping"></i></div>
                <div class="csp-kpi-label">Reorder Required</div>
                <div class="csp-kpi-value">{{ number_format($reorderCount) }}</div>
            </article>
            <article class="csp-kpi" style="--kpi-color: #c0392b">
                <div class="csp-kpi-icon"><i class="fa-solid fa-box-open"></i></div>
                <div class="csp-kpi-label">Out of Stock</div>
                <div class="csp-kpi-value">{{ number_format($outOfStockCount) }}</div>
            </article>
            <article class="csp-kpi" style="--kpi-color: #1f7a4d">
                <div class="csp-kpi-icon"><i class="fa-solid fa-coins"></i></div>
                <div class="csp-kpi-label">Inventory Value</div>
                <div class="csp-kpi-value is-money">฿{{ number_format($inventoryValue, 2) }}</div>
            </article>
            <article class="csp-kpi" style="--kpi-color: #7c3f8c">
                <div class="csp-kpi-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <div class="csp-kpi-label">High Risk</div>
                <div class="csp-kpi-value">{{ number_format($highRiskCount) }}</div>
            </article>
        </section>

        <section class="csp-panel csp-alerts" aria-label="Stock alerts">
            <button type="button" class="csp-alert js-csp-quick-filter" data-filter="out" style="--alert-color: #c0392b">
                <span class="csp-alert-icon"><i class="fa-solid fa-box-open"></i></span>
                <span><strong>Out of Stock</strong><span>{{ $outOfStockCount }} รายการไม่มีสต็อก</span></span>
            </button>
            <button type="button" class="csp-alert js-csp-quick-filter" data-filter="low" style="--alert-color: #d97706">
                <span class="csp-alert-icon"><i class="fa-solid fa-arrow-trend-down"></i></span>
                <span><strong>ต่ำกว่า Minimum</strong><span>{{ $lowStockCount }} รายการต้องติดตาม</span></span>
            </button>
            <button type="button" class="csp-alert js-csp-quick-filter" data-filter="reorder" style="--alert-color: #1261a0">
                <span class="csp-alert-icon"><i class="fa-solid fa-cart-plus"></i></span>
                <span><strong>ถึงจุด Reorder</strong><span>{{ $reorderCount }} รายการควรวางแผน</span></span>
            </button>
            <button type="button" class="csp-alert js-csp-quick-filter" data-filter="inactive" style="--alert-color: #7c3f8c">
                <span class="csp-alert-icon"><i class="fa-solid fa-calendar-xmark"></i></span>
                <span><strong>ไม่มีการเคลื่อนไหว</strong><span>{{ $inactiveCount }} รายการ ≥ 180 วัน</span></span>
            </button>
        </section>

        <section class="csp-panel csp-table-panel">
            <div class="csp-toolbar">
                <div class="csp-toolbar-title">
                    <h5>Critical Spare Parts Detail</h5>
                    <small>ข้อมูล Stock และ Minimum จากรายการ Critical Spare Parts ของแผนกเพลา 1</small>
                </div>
                <div class="csp-filter-form" role="search">
                    <div class="csp-filter-field csp-search">
                        <label for="csp-search">ค้นหาอะไหล่</label>
                        <input id="csp-search" type="search" class="form-control form-control-sm"
                            placeholder="Part No., ชื่อ หรือ Drawing No.">
                    </div>
                    <div class="csp-filter-field">
                        <label for="csp-site-filter">Site</label>
                        <select id="csp-site-filter" class="form-select form-select-sm js-csp-multi-filter" multiple
                            data-placeholder="ทุก Site">
                            <option value="plus">Plus</option>
                            <option value="wire">Wire</option>
                        </select>
                    </div>
                    <div class="csp-filter-field">
                        <label for="csp-status-filter">สถานะ</label>
                        <select id="csp-status-filter" class="form-select form-select-sm js-csp-multi-filter" multiple
                            data-placeholder="ทุกสถานะ">
                            <option value="out">Out of Stock</option>
                            <option value="low">ต่ำกว่า Min.Stock</option>
                            <option value="reorder">ถึงจุด Reorder</option>
                            <option value="inactive">ไม่มีการเคลื่อนไหว ≥ 180 วัน</option>
                            <option value="ok">Stock เพียงพอ</option>
                        </select>
                    </div>
                    <div class="csp-filter-field">
                        <label for="csp-machine-filter">เครื่องที่ใช้</label>
                        <select id="csp-machine-filter" class="form-select form-select-sm js-csp-multi-filter" multiple
                            data-placeholder="ทุกเครื่อง">
                            @foreach ($machineOptions as $machine)
                                <option value="{{ \Illuminate\Support\Str::lower($machine) }}">{{ $machine }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="csp-filter-field">
                        <label for="csp-supplier-filter">Supplier</label>
                        <select id="csp-supplier-filter" class="form-select form-select-sm js-csp-multi-filter" multiple
                            data-placeholder="ทุก Supplier">
                            @foreach ($supplierOptions as $supplier)
                                <option value="{{ \Illuminate\Support\Str::lower($supplier) }}">{{ $supplier }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button id="csp-clear-filter" type="button" class="btn btn-outline-secondary btn-sm px-3">
                        <i class="fa-solid fa-rotate-left me-1"></i>ล้าง
                    </button>
                </div>
            </div>

            <div class="csp-table-wrap">
                <table class="table csp-table" id="csp-parts-table">
                    <thead>
                        <tr>
                            <th>รายการที่</th>
                            <th>รหัสสินค้า</th>
                            <th>สินค้า</th>
                            <th>Site</th>
                            <th class="text-end">Minimum Stock</th>
                            <th class="text-end">จำนวนคงเหลือ (CPA)</th>
                            <th>เครื่องที่ใช้</th>
                            <th>หมายเหตุ</th>
                            <th>Supplier / Supplier สำรอง</th>
                            <th>Status</th>
                            <th class="text-end">On PO (PO ค้างรับ)</th>
                            <th class="text-end">ต้นทุนล่าสุด (CPA)</th>
                            <th class="text-end">Leadtime การสั่งซื้อ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $index => $row)
                            @php
                                $onhand = (float) ($row->onhand ?? 0);
                                $minimum = isset($row->required_qty) ? (float) $row->required_qty : null;
                                $reorderPoint = isset($row->reorder_point) ? (float) $row->reorder_point : null;
                                $isOut = $onhand <= 0;
                                $isLow = !$isOut && !is_null($minimum) && $onhand < $minimum;
                                $isReorder = !$isOut && !$isLow && !is_null($reorderPoint) && $onhand <= $reorderPoint;
                                $isInactive = is_null($row->days_inactive) || $row->days_inactive >= 180;
                                $status = $isOut ? 'out' : ($isLow ? 'low' : ($isReorder ? 'reorder' : 'ok'));
                                $filterStatuses = collect([
                                    $status,
                                    $isInactive ? 'inactive' : null,
                                ])->filter()->unique()->implode(' ');
                            @endphp
                            <tr class="js-csp-row" data-site="{{ $row->site_keys }}"
                                data-machine="{{ \Illuminate\Support\Str::lower((string) $row->machine) }}"
                                data-suppliers="{{ \Illuminate\Support\Str::lower(collect([$row->supplier, ...explode(',', (string) $row->alternate_suppliers)])->map(fn($value) => trim((string) $value))->filter()->unique()->implode('|')) }}"
                                data-status="{{ $filterStatuses }}"
                                data-search="{{ \Illuminate\Support\Str::lower(implode(' ', [$row->partnumber, $row->description, $row->site, $row->machine, $row->remark, $row->supplier, $row->alternate_suppliers, $row->po_numbers])) }}">
                                <td class="text-muted">{{ $index + 1 }}</td>
                                <td><span class="csp-part-number">{{ $row->partnumber }}</span></td>
                                <td class="csp-part-name">
                                    {{ $row->description ?: '-' }}
                                </td>
                                <td><span class="csp-site">{{ $row->site }}</span></td>
                                <td class="text-end csp-number">
                                    {{ is_null($minimum) ? '-' : number_format($minimum, 2) }}
                                </td>
                                <td class="text-end csp-number">
                                    <span class="csp-stock {{ $isLow ? 'is-low' : 'is-ok' }}">{{ number_format($onhand, 2) }}</span>
                                    @if ($row->site === 'Wire, Plus')
                                        <span class="csp-subtext">
                                            Wire {{ number_format($row->stock_wire, 2) }} ·
                                            Plus {{ number_format($row->stock_plus, 2) }}
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    <span class="fw-semibold">{{ $row->machine ?: '-' }}</span>
                                    @if ($row->machine_source)
                                        <span class="csp-subtext">{{ $row->machine_source }}</span>
                                    @endif
                                </td>
                                <td class="csp-remark-col">{{ $row->remark ?: '-' }}</td>
                                <td class="csp-supplier-col">
                                    @if ($row->supplier)
                                        <span class="fw-semibold">{{ $row->supplier }}</span>
                                        @if ($row->alternate_suppliers)
                                            <span class="csp-subtext">สำรอง: {{ $row->alternate_suppliers }}</span>
                                        @else
                                            <span class="csp-subtext text-warning">ไม่มี Supplier สำรองในประวัติ 24 เดือน</span>
                                        @endif
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                    @if ($row->site === 'Wire, Plus')
                                        <span class="csp-subtext">
                                            Wire {{ number_format($row->on_po_wire, 2) }} ·
                                            Plus {{ number_format($row->on_po_plus, 2) }}
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    @if ($status === 'out')
                                        <span class="csp-status csp-status-out"><i class="fa-solid fa-circle-xmark"></i>Out of Stock</span>
                                    @elseif ($status === 'low')
                                        <span class="csp-status csp-status-low"><i class="fa-solid fa-triangle-exclamation"></i>ต่ำกว่า Min.Stock</span>
                                    @elseif ($status === 'reorder')
                                        <span class="csp-status csp-status-reorder"><i class="fa-solid fa-cart-plus"></i>ถึงจุด Reorder</span>
                                    @else
                                        <span class="csp-status csp-status-ok"><i class="fa-solid fa-circle-check"></i>เพียงพอ</span>
                                    @endif
                                </td>
                                <td class="text-end csp-number">
                                    @if (($row->on_po ?? 0) > 0)
                                        <span class="fw-bold text-primary">{{ number_format($row->on_po, 2) }}</span>
                                        @if ($row->po_numbers)
                                            <span class="csp-subtext">{{ str_replace(' | ', ', ', $row->po_numbers) }}</span>
                                        @endif
                                        @if ($row->next_receive_date)
                                            <span class="csp-subtext">กำหนดรับ {{ \Carbon\Carbon::parse($row->next_receive_date)->format('d/m/Y') }}</span>
                                        @endif
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td class="text-end csp-number">{{ ($row->lastcost ?? 0) > 0 ? number_format($row->lastcost, 2) : '-' }}</td>
                                <td class="text-end csp-number">
                                    {{ is_null($row->lead_time_days) ? '-' : number_format($row->lead_time_days) . ' วัน' }}
                                    @if ($row->last_receive_date)
                                        <span class="csp-subtext">รับล่าสุด {{ \Carbon\Carbon::parse($row->last_receive_date)->format('d/m/Y') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="13" class="text-center text-muted py-5">
                                    <i class="fa-solid fa-box-open fa-2x mb-2 d-block"></i>
                                    ไม่พบข้อมูล Critical Spare Parts
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                <div id="csp-empty-filter" class="csp-empty-filter">
                    <i class="fa-solid fa-magnifying-glass fa-2x mb-2 d-block"></i>
                    ไม่พบรายการที่ตรงกับตัวกรอง
                </div>
            </div>
            <div class="csp-table-footer">
                <span>แสดง <strong id="csp-visible-count">{{ $totalParts }}</strong> จาก {{ $totalParts }} รายการ</span>
                <span>Lead Time = ค่าเฉลี่ย PO ถึงรับจริงย้อนหลัง 24 เดือน · เกณฑ์ไม่มีการเคลื่อนไหว 180 วัน</span>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const searchInput = document.getElementById('csp-search');
            const siteSelect = document.getElementById('csp-site-filter');
            const statusSelect = document.getElementById('csp-status-filter');
            const machineSelect = document.getElementById('csp-machine-filter');
            const supplierSelect = document.getElementById('csp-supplier-filter');
            const clearButton = document.getElementById('csp-clear-filter');
            const rows = Array.from(document.querySelectorAll('.js-csp-row'));
            const quickFilters = Array.from(document.querySelectorAll('.js-csp-quick-filter'));
            const visibleCount = document.getElementById('csp-visible-count');
            const emptyState = document.getElementById('csp-empty-filter');
            const table = document.getElementById('csp-parts-table');

            document.querySelectorAll('.js-csp-multi-filter').forEach(function (select) {
                if (!window.TomSelect || select.tomselect) return;

                new TomSelect(select, {
                    plugins: ['remove_button'],
                    create: false,
                    persist: false,
                    placeholder: select.dataset.placeholder || '',
                    maxOptions: 1000,
                    dropdownParent: 'body',
                    closeAfterSelect: false,
                });
            });

            function selectedValues(select) {
                const value = select.tomselect ? select.tomselect.getValue() :
                    Array.from(select.selectedOptions).map(function (option) { return option.value; });

                return Array.isArray(value) ? value : (value ? [value] : []);
            }

            function setSelectedValues(select, values) {
                if (select.tomselect) {
                    select.tomselect.setValue(values, true);
                    return;
                }

                const selected = new Set(values);
                Array.from(select.options).forEach(function (option) {
                    option.selected = selected.has(option.value);
                });
            }

            function applyFilters() {
                const query = (searchInput.value || '').trim().toLocaleLowerCase('th');
                const selectedSites = selectedValues(siteSelect);
                const selectedStatuses = selectedValues(statusSelect);
                const selectedMachines = selectedValues(machineSelect);
                const selectedSuppliers = selectedValues(supplierSelect);
                let count = 0;

                rows.forEach(function (row) {
                    const matchesSearch = !query || (row.dataset.search || '').includes(query);
                    const sites = (row.dataset.site || '').split(' ');
                    const matchesSite = selectedSites.length === 0 || selectedSites.some(function (site) {
                        return sites.includes(site);
                    });
                    const statuses = (row.dataset.status || '').split(' ');
                    const matchesStatus = selectedStatuses.length === 0 || selectedStatuses.some(function (status) {
                        return statuses.includes(status);
                    });
                    const matchesMachine = selectedMachines.length === 0 || selectedMachines.includes(row.dataset.machine);
                    const suppliers = (row.dataset.suppliers || '').split('|');
                    const matchesSupplier = selectedSuppliers.length === 0 || selectedSuppliers.some(function (supplier) {
                        return suppliers.includes(supplier);
                    });
                    const visible = matchesSearch && matchesSite && matchesStatus
                        && matchesMachine && matchesSupplier;

                    row.classList.toggle('d-none', !visible);
                    if (visible) count += 1;
                });

                quickFilters.forEach(function (button) {
                    button.classList.toggle('is-active', selectedStatuses.includes(button.dataset.filter));
                });

                visibleCount.textContent = count.toLocaleString();
                emptyState.style.display = count === 0 ? 'block' : 'none';
                table.style.display = count === 0 ? 'none' : 'table';
            }

            searchInput.addEventListener('input', applyFilters);
            siteSelect.addEventListener('change', applyFilters);
            statusSelect.addEventListener('change', applyFilters);
            machineSelect.addEventListener('change', applyFilters);
            supplierSelect.addEventListener('change', applyFilters);

            quickFilters.forEach(function (button) {
                button.addEventListener('click', function () {
                    const statuses = new Set(selectedValues(statusSelect));
                    if (statuses.has(button.dataset.filter)) {
                        statuses.delete(button.dataset.filter);
                    } else {
                        statuses.add(button.dataset.filter);
                    }
                    setSelectedValues(statusSelect, Array.from(statuses));
                    applyFilters();
                    document.getElementById('csp-parts-table').scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            });

            clearButton.addEventListener('click', function () {
                searchInput.value = '';
                [siteSelect, statusSelect, machineSelect, supplierSelect].forEach(function (select) {
                    setSelectedValues(select, []);
                });
                applyFilters();
                searchInput.focus();
            });
        });
    </script>
@endpush
