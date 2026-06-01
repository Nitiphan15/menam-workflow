{{-- resources/views/formdp/inquiry.blade.php --}}
@extends('layouts.layout')
@section('page-title', 'Inquiry Data')
@section('title', 'Inquiry')

@section('content')
    @php
        $isLoggedIn = auth()->check();
        $loginUrl = route('login');
        $u = auth()->user();

        $canDp =
            $isLoggedIn &&
            (($u->is_superadmin ?? 0) == 1 || (method_exists($u, 'hasRoleCode') && $u->hasRoleCode('DP')));

        $canDpa =
            $isLoggedIn &&
            (($u->is_superadmin ?? 0) == 1 || (method_exists($u, 'hasRoleCode') && $u->hasRoleCode('DPA')));

        $canDpMail =
            $isLoggedIn &&
            (($u->is_superadmin ?? 0) == 1 ||
                (method_exists($u, 'hasRoleCode') && $u->hasRoleCode(['DPEMAIL', 'DPMAIL'])));

        $tableColspan = 18;
        $fmtTon = fn($kg) => number_format(((float) ($kg ?? 0)) / 1000, 3);
    @endphp

    <div class="container-fluid">

        <div class="dp-inquiry-filter mb-2">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="fw-bold fs-5">กรองการค้นหา</div>

                <div class="d-flex gap-2 align-items-center">
                    <a href="{{ route('dp.inquiry.export', request()->query()) }}" class="btn btn-sm btn-success"
                        id="btnExportExcel">
                        Export Excel
                    </a>

                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnToggleKpi"
                        aria-controls="kpiCollapse" aria-expanded="true">
                        KPI
                    </button>

                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnToggleFilter"
                        aria-controls="filterCollapse" aria-expanded="true">
                        Filters
                    </button>

                    @if ($canDpMail)
                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal"
                            data-bs-target="#sendPlanMailModal">
                            <i class="fas fa-paper-plane me-1"></i> ส่งเมลแจ้งแผน
                        </button>
                    @endif
                </div>


            </div>

            <div class="collapse show" id="kpiCollapse">
                <div class="dp-kpi-row mb-2" id="kpiBar">
                    <div class="dp-kpi-card">
                        <div class="text-muted small">Lines (Visible / Total)</div>
                        <div class="dp-kpi-num"><span id="kpi_visible">0</span> / <span id="kpi_total">0</span></div>
                    </div>

                    <div class="dp-kpi-card">
                        <div class="text-muted small">SO / ACID</div>
                        <div class="dp-kpi-num"><span id="kpi_so">0</span> / <span id="kpi_acid">0</span></div>
                    </div>

                    <div class="dp-kpi-card">
                        <div class="text-muted small">Sell by line</div>
                        <div class="dp-kpi-num"><span id="kpi_sbl">0</span></div>
                    </div>
                </div>
            </div>

            <hr class="mt-0">

            <div class="collapse show" id="filterCollapse">
                <form method="GET" action="{{ route('dp.inquiry') }}" id="filterForm">
                    <input type="hidden" name="searched" value="1">

                    <div class="dp-filter-grid">
                        <div>
                            <label class="form-label mb-1 small">Mode</label>
                            <select id="f_mode" name="mode" class="form-select form-select-sm">
                                <option value="" {{ request('mode') === '' ? 'selected' : '' }}>ทั้งหมด</option>
                                <option value="SO" {{ request('mode') === 'SO' ? 'selected' : '' }}>ปกติ</option>
                                <option value="ACID" {{ request('mode') === 'ACID' ? 'selected' : '' }}>กัดกรด</option>
                            </select>
                        </div>

                        <div>
                            <label class="form-label mb-1 small">วันที่ส่งสินค้า (เริ่มต้น)</label>
                            <input id="f_ship_from" name="ship_from" type="date" value="{{ request('ship_from', '') }}"
                                class="form-control form-control-sm">
                        </div>

                        <div>
                            <label class="form-label mb-1 small">วันที่ส่งสินค้า (ถึง)</label>
                            <input id="f_ship_to" name="ship_to" type="date" value="{{ request('ship_to', '') }}"
                                class="form-control form-control-sm">
                        </div>

                        <div>
                            <label class="form-label mb-1 small">Sales Order</label>
                            <input id="f_so" name="so" value="{{ request('so', '') }}"
                                class="form-control form-control-sm" placeholder="ค้นหา SO">
                        </div>

                        <div>
                            <label class="form-label mb-1 small">Customer</label>
                            <input id="f_customer" name="customer" value="{{ request('customer', '') }}"
                                class="form-control form-control-sm" placeholder="ค้นหาลูกค้า">
                        </div>

                        <div>
                            <label class="form-label mb-1 small">Ship To</label>
                            <input id="f_shipto" name="shipto" value="{{ request('shipto', '') }}"
                                class="form-control form-control-sm" placeholder="ค้นหา Ship To">
                        </div>

                        <div>
                            <label class="form-label mb-1 small">Division/Sales</label>
                            <input id="f_sales" name="divsales" value="{{ request('divsales', '') }}"
                                class="form-control form-control-sm" placeholder="เช่น D3 / ภควดี / วางแผน">
                        </div>

                        <div>
                            <label class="form-label mb-1 small">Status</label>
                            @php $st = strtoupper(request('status', 'NEW')); @endphp
                            <select id="f_status" name="status" class="form-select form-select-sm">
                                <option value="NEW" {{ $st === 'NEW' ? 'selected' : '' }}>NEW</option>
                                <option value="ASSIGN" {{ $st === 'ASSIGN' ? 'selected' : '' }}>ASSIGN</option>
                                <option value="SPECIAL" {{ $st === 'SPECIAL' ? 'selected' : '' }}>SPECIAL</option>
                                <option value="POSTPONED" {{ $st === 'POSTPONED' ? 'selected' : '' }}>POSTPONED</option>
                                <option value="CLOSED" {{ $st === 'CLOSED' ? 'selected' : '' }}>CLOSED</option>
                                <option value="VOID" {{ $st === 'VOID' ? 'selected' : '' }}>VOID</option>
                                <option value="ALL" {{ $st === 'ALL' ? 'selected' : '' }}>ALL</option>
                            </select>
                        </div>

                        <div>
                            <label class="form-label mb-1 small">Order By</label>
                            @php $ob = request('order_by',''); @endphp
                            <select id="f_order_by" name="order_by" class="form-select form-select-sm">
                                <option value="" {{ $ob === '' ? 'selected' : '' }}></option>
                                <option value="ship_posted_at" {{ $ob === 'ship_posted_at' ? 'selected' : '' }}>
                                    วันที่ส่งสินค้า</option>
                                <option value="window_at" {{ $ob === 'window_at' ? 'selected' : '' }}>ช่วงเวลารับส่ง
                                </option>
                                <option value="so" {{ $ob === 'so' ? 'selected' : '' }}>SO</option>
                                <option value="customer" {{ $ob === 'customer' ? 'selected' : '' }}>Customer</option>
                                <option value="shipto" {{ $ob === 'shipto' ? 'selected' : '' }}>Ship To</option>
                                <option value="qty" {{ $ob === 'qty' ? 'selected' : '' }}>KG</option>
                                <option value="revision" {{ $ob === 'revision' ? 'selected' : '' }}>Revision</option>
                            </select>
                        </div>

                        <div>
                            <label class="form-label mb-1 small">Direction</label>
                            @php $od = request('order_dir',''); @endphp
                            <select id="f_order_dir" name="order_dir" class="form-select form-select-sm">
                                <option value="" {{ $od === '' ? 'selected' : '' }}></option>
                                <option value="desc" {{ $od === 'desc' ? 'selected' : '' }}>DESC</option>
                                <option value="asc" {{ $od === 'asc' ? 'selected' : '' }}>ASC</option>
                            </select>
                        </div>

                        <div class="d-flex gap-2 align-items-end">
                            <button type="submit" class="btn btn-primary btn-sm" id="btnSearch">ค้นหา</button>
                            <a class="btn btn-outline-secondary btn-sm" href="{{ route('dp.inquiry') }}">Reset</a>
                        </div>

                        <div class="d-flex align-items-end justify-content-end">
                            <div id="inqCount" class="text-muted small"></div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="fw-bold fs-5 mt-2">ตารางข้อมูล</div>

            <div class="table-responsive dp-table-wrap">
                <table class="table table-bordered table-sm align-middle mb-0" id="inqTable">
                    <thead class="table-light">
                        <tr class="dp-inquiry-header-row">
                            <th class="sticky-col sticky-1">#</th>

                            <th class="sticky-col sticky-2">วันที่ส่งสินค้า</th>
                            <th class="text-center" style="min-width:160px;">Truck</th>
                            <th class="sticky-col sticky-3">ลูกค้า</th>
                            <th class="sticky-col sticky-4">Part Desc</th>
                            <th>เลข MFG</th>
                            <th class="text-end">จำนวน/KG</th>
                            <th class="text-end">Stock FG</th>
                            <th class="text-end">ขายระบุเส้น/ชิ้น</th>
                            <th>สถานที่ส่ง</th>
                            <th>เลข SO</th>
                            <th>เอกสารแนบ</th>
                            <th>เพิ่มเติม</th>
                            <th>เบอร์โทร/ชื่อผู้ติดต่อ</th>
                            <th class="text-center" style="width:90px;">แก้ไข(ครั้ง)</th>
                            <th>Status</th>
                            <th class="text-center" style="min-width:120px;">Action</th>
                            <th class="text-center" style="min-width:120px;">History</th>
                        </tr>
                    </thead>

                    <tbody id="inqTbody">
                        @forelse ($divGroups as $divKey => $items)
                            @php
                                $groupTitle = $divLabel[$divKey] ?? (string) $divKey;
                                $groupCount = is_countable($items) ? count($items) : 0;
                                $no = 1;
                            @endphp

                            <tr class="table-secondary group-row" data-group="{{ e($groupTitle) }}">
                                <td colspan="{{ $tableColspan }}" class="fw-semibold">
                                    {{ $groupTitle }} <span class="text-muted">({{ $groupCount }} รายการ)</span>
                                </td>
                            </tr>

                            @foreach ($items as $r)
                                @php
                                    $revNo = (int) ($r->revision_number ?? 0);
                                    $revLookup = $revNo > 5 ? 5 : $revNo;
                                    $revColor = $revColorMap[$revLookup] ?? ($revColorMap[5] ?? '#111827');
                                    $assignedQty = is_numeric($r->assigned_weight_sum ?? null)
                                        ? (float) $r->assigned_weight_sum
                                        : 0.0;
                                    $remainingQty = is_numeric($r->remaining_assign_qty ?? null)
                                        ? (float) $r->remaining_assign_qty
                                        : max(0, $qty - $assignedQty);

                                    $docs = collect(explode(',', (string) ($r->attach_docs ?? '')))
                                        ->map(fn($x) => trim((string) $x))
                                        ->filter()
                                        ->values();

                                    $other = trim((string) ($r->attach_docs_other ?? ''));

                                    $docTexts = [];
                                    foreach ($docs as $code) {
                                        $codeU = strtoupper($code);
                                        $isOther = $codeU === 'OTHER';
                                        if ($isOther && $other === '') {
                                            continue;
                                        }

                                        $name = $docMap[$codeU] ?? $codeU;
                                        if ($isOther && $other !== '') {
                                            $name .= ': ' . $other;
                                        }

                                        $docTexts[] = $name;
                                    }
                                    $docText = implode(', ', $docTexts);

                                    $mode = strtoupper(trim((string) ($r->delivery_type ?? '')));

                                    $sellByLine = (int) ($r->sell_by_line ?? 0) ? 1 : 0;
                                    $lineQty = is_numeric($r->line_qty ?? null) ? (float) $r->line_qty : null;
                                    $qty = is_numeric($r->qty ?? null) ? (float) $r->qty : 0.0;
                                    $lineUnit = $sellByLine && $qty == 0.0 ? 'ชิ้น' : 'เส้น';
                                    $linePrefix = $lineUnit === 'ชิ้น' ? 'ชิ้น : ' : 'ระบุเส้น : ';

                                    $lineQtyText =
                                        $lineQty !== null && $lineQty > 0
                                            ? $linePrefix . number_format($lineQty, 0) . ' ' . $lineUnit . ' '
                                            : '-';

                                    $customerText = (string) ($r->customer_name ?? '#' . ($r->customer_id ?? ''));
                                    $shiptoText = (string) ($r->address ?? '');
                                    $soText = (string) ($r->so_number ?? '');
                                    $mfgText = (string) ($r->mfg_no ?? '');
                                    $telText = (string) ($r->tel ?? '');

                                    $moreText = collect([
                                        $r->remark ?? null,
                                        $r->edit_remark ?? null,
                                        $r->due_date_remark ?? null,
                                    ])
                                        ->filter(fn($x) => trim((string) $x) !== '')
                                        ->implode(' | ');

                                    $qty = is_numeric($r->qty ?? null) ? (float) $r->qty : 0.0;
                                    $stock = is_numeric($r->stock_qty_rt ?? null) ? (float) $r->stock_qty_rt : 0.0;

                                    try {
                                        $shipDateOnly = !empty($r->ship_posted_at)
                                            ? \Carbon\Carbon::parse($r->ship_posted_at)->format('Y-m-d')
                                            : '';
                                    } catch (\Throwable $e) {
                                        $shipDateOnly = '';
                                    }

                                    try {
                                        $shipDMY =
                                            $shipDateOnly !== ''
                                                ? \Carbon\Carbon::parse($shipDateOnly)->format('d/m/Y')
                                                : '';
                                    } catch (\Throwable $e) {
                                        $shipDMY = '';
                                    }

                                    try {
                                        $timeFromWindow = !empty($r->window_at)
                                            ? \Carbon\Carbon::parse($r->window_at)->format('H:i')
                                            : '';
                                    } catch (\Throwable $e) {
                                        $timeFromWindow = '';
                                    }

                                    $shipDateTimeText =
                                        $shipDMY !== '' && $timeFromWindow !== ''
                                            ? $shipDMY . ' ' . $timeFromWindow
                                            : ($shipDMY ?:
                                            '-');

                                    $shipSort = $shipDateOnly !== '' ? $shipDateOnly . 'T' . $timeFromWindow : '';

                                    try {
                                        $windowSort = !empty($r->window_at)
                                            ? \Carbon\Carbon::parse($r->window_at)->format('Y-m-d\TH:i')
                                            : '';
                                    } catch (\Throwable $e) {
                                        $windowSort = '';
                                    }

                                    $partText =
                                        trim((string) ($r->part_number ?? '')) .
                                        ' ' .
                                        trim((string) ($r->part_desc ?? ''));

                                    $statusUpper = strtoupper(trim((string) ($r->status ?? '')));
                                    $canAssignTruckUi =
                                        !in_array($statusUpper, ['VOID', 'CLOSED', 'SPECIAL', 'POSTPONED'], true) &&
                                        !empty($r->can_pick_truck) &&
                                        ($remainingQty > 0 || $assignedQty > 0);
                                    $canSpecialDispatchUi = $canDpa && !in_array($statusUpper, ['VOID', 'CLOSED'], true);
                                    $canOpenDispatchModalUi =
                                        $canDpa && !in_array($statusUpper, ['VOID', 'CLOSED'], true);
                                    $specialLabel = trim((string) ($r->special_dispatch_label ?? ''));
                                    $specialIsOpen =
                                        strtoupper(trim((string) ($r->special_dispatch_status ?? ''))) === 'OPEN' &&
                                        strtoupper(trim((string) ($r->special_dispatch_type ?? ''))) !== 'POSTPONED';
                                @endphp

                                <tr class="rev-row data-row" style="--rev: {{ $revColor }};"
                                    data-group="{{ e($groupTitle) }}" data-mode="{{ e($mode) }}"
                                    data-so="{{ e($soText) }}" data-customer="{{ e($customerText) }}"
                                    data-part="{{ e($partText) }}" data-shipto="{{ e($shiptoText) }}"
                                    data-ship="{{ e($shipSort) }}" data-window="{{ e($windowSort) }}"
                                    data-qty="{{ e(number_format($qty, 3, '.', '')) }}" data-rev="{{ e($revNo) }}"
                                    data-sbl="{{ e($sellByLine) }}">

                                    <td class="sticky-col sticky-1 text-center">{{ $no++ }}</td>

                                    <td class="sticky-col sticky-2">{{ $shipDateTimeText }}</td>

                                    <td class="truck-cell">
                                        @php
                                            $truckList = collect(explode(',', (string) ($r->truck_plate_list ?? '')))
                                                ->map(fn($x) => trim($x))
                                                ->filter()
                                                ->unique()
                                                ->values();
                                        @endphp

                                        @if ($truckList->isNotEmpty())
                                            @foreach ($truckList as $plate)
                                                <div class="truck-plate">{{ $plate }}</div>
                                            @endforeach

                                            @if ($truckList->count() > 1)
                                                <div class="truck-meta">รวม {{ $truckList->count() }} คัน</div>
                                            @endif
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>

                                    <td class="sticky-col sticky-3">{{ $customerText }}</td>
                                    <td class="sticky-col sticky-4">{{ $r->part_desc ?? '' }}</td>

                                    <td class="text-wrap" style="white-space:normal;min-width:160px;">
                                        {{ $mfgText !== '' ? $mfgText : '-' }}
                                    </td>

                                    <td class="text-end qty-cell">
                                        <div class="qty-main">{{ number_format($qty, 3) }}</div>

                                        @if ($assignedQty > 0)
                                            <div class="qty-sub qty-sub-muted">
                                                <span class="qty-label">ขึ้นรถแล้ว</span>
                                                <span class="qty-value">{{ number_format($assignedQty, 3) }}</span>
                                            </div>

                                            <div class="qty-sub {{ $remainingQty > 0 ? 'qty-sub-warn' : 'qty-sub-ok' }}">
                                                <span class="qty-label">คงเหลือ</span>
                                                <span class="qty-value">{{ number_format($remainingQty, 3) }}</span>
                                            </div>
                                        @endif
                                    </td>

                                    <td class="text-end">{{ number_format($stock, 3) }}</td>

                                    <td class="text-wrap" style="white-space:normal;min-width:220px;">
                                        <div>{{ $lineQtyText }}</div>
                                    </td>

                                    <td class="text-wrap" style="white-space:normal;min-width:240px;">
                                        {{ $shiptoText !== '' ? $shiptoText : '-' }}
                                    </td>

                                    <td>{{ $soText !== '' ? $soText : '-' }}</td>

                                    <td class="text-wrap" style="white-space:normal;min-width:220px;">
                                        {{ $docText !== '' ? $docText : '-' }}
                                    </td>



                                    <td class="text-wrap" style="white-space:normal;min-width:220px;">
                                        {{ $moreText !== '' ? $moreText : '-' }}
                                    </td>

                                    <td class="text-wrap" style="white-space:normal;min-width:160px;">
                                        {{ $telText !== '' ? $telText : '-' }}
                                    </td>

                                    <td class="text-center">{{ $revNo }}</td>
                                    <td>
                                        <div>{{ $r->status ?? '-' }}</div>
                                        @if ($specialLabel !== '')
                                            <div class="small text-primary">{{ $specialLabel }}</div>
                                        @endif
                                        @php
                                            $pcStatus = $r->planner_confirmation_status ?? null;
                                            $pcLabel = $r->planner_confirmation_label ?? '-';
                                            $pcBadge = $r->planner_confirmation_badge ?? 'light text-dark border';
                                            $pcNewDate = !empty($r->planner_confirmation_new_date)
                                                ? \Carbon\Carbon::parse($r->planner_confirmation_new_date)->format('d/m/Y')
                                                : null;
                                            $pcAt = !empty($r->planner_confirmation_confirmed_at)
                                                ? \Carbon\Carbon::parse($r->planner_confirmation_confirmed_at)->format('d/m H:i')
                                                : null;
                                            $statusUpperForPc = strtoupper(trim((string) ($r->status ?? '')));
                                        @endphp
                                        @if (!empty($pcStatus))
                                            @php
                                                $tip = 'ฝ่ายวางแผน: ' . $pcLabel
                                                    . ($pcNewDate ? ' → ' . $pcNewDate : '')
                                                    . ($pcAt ? "\nบันทึก " . $pcAt : '')
                                                    . (!empty($r->planner_confirmation_by) ? ' โดย ' . $r->planner_confirmation_by : '');
                                            @endphp
                                            <div class="small mt-1" title="{{ $tip }}">
                                                <span class="badge bg-{{ $pcBadge }}">
                                                    <i class="fas fa-clipboard-check me-1"></i>{{ $pcLabel }}
                                                </span>
                                                @if ($pcStatus === 'POSTPONE' && $pcNewDate)
                                                    <div class="text-warning fw-semibold mt-1">
                                                        วันส่งใหม่: {{ $pcNewDate }}
                                                    </div>
                                                @endif
                                            </div>
                                        @elseif (!in_array($statusUpperForPc, ['VOID', 'CLOSED'], true))
                                            <div class="small mt-1" title="ฝ่ายวางแผนยังไม่ได้ยืนยัน — ระบบถือว่าส่งได้ตามแผนเดิมโดย default">
                                                <span class="badge bg-light text-dark border">
                                                    <i class="far fa-clock me-1"></i>รอวางแผนยืนยัน
                                                </span>
                                            </div>
                                        @endif
                                    </td>

                                    <td class="text-center">
                                        <div class="d-flex gap-1 justify-content-center flex-wrap">
                                            @if (!$isLoggedIn)
                                                <a class="btn btn-sm btn-outline-primary"
                                                    href="{{ $loginUrl }}">แก้ไข</a>
                                            @elseif ($canDp)
                                                <a class="btn btn-sm btn-outline-primary"
                                                    href="{{ route('dp.day', ['date' => $shipDateOnly ?: now()->toDateString()]) }}?edit={{ $r->ord_id }}">
                                                    แก้ไข
                                                </a>
                                                @if (!in_array($statusUpper, ['VOID', 'CLOSED', 'POSTPONED'], true))
                                                    <button type="button"
                                                        class="btn btn-sm btn-outline-warning jsPostponeBtn"
                                                        data-ord-id="{{ $r->ord_id }}"
                                                        data-so="{{ e($soText) }}"
                                                        data-part="{{ e($r->part_number ?? '') }}"
                                                        data-ship-date="{{ e($shipDateOnly) }}"
                                                        data-window-time="{{ e($timeFromWindow) }}">
                                                        เลื่อนแผน
                                                    </button>
                                                @endif
                                            @endif

                                            @php
                                                $shipDateKey = $shipDateOnly ?: '';
                                                $soLineKey = (string) ($r->so_number ?? '') . '|' . $shipDateKey;
                                            @endphp

                                            @if ($canOpenDispatchModalUi)
                                                @if (!$isLoggedIn)
                                                    <a class="btn btn-sm btn-outline-info" href="{{ $loginUrl }}">
                                                        {{ !empty($r->truck_plate_display) ? 'เปลี่ยนรถ' : 'เลือกรถ' }}
                                                    </a>
                                                @elseif ($canDpa)
                                                    <button type="button"
                                                        class="btn btn-sm btn-outline-info jsOpenTruckModal"
                                                        data-ord-id="{{ $r->ord_id }}" data-so="{{ e($soText) }}"
                                                        data-mfg="{{ e($mfgText) }}"
                                                        data-status="{{ e($statusUpper) }}"
                                                        data-ship-date="{{ e($shipDateOnly) }}"
                                                        data-shipto="{{ e($shiptoText) }}"
                                                        data-current-truck-id="{{ e($r->truck_id ?? '') }}"
                                                        data-current-truck-source="{{ e($r->truck_source ?? '') }}"
                                                        data-current-manual-plate="{{ e($r->manual_plate_no ?? '') }}"
                                                        data-current-special-type="{{ e($r->special_dispatch_type ?? '') }}"
                                                        data-lines='@json($soLines[$soLineKey] ?? [])'>
                                                        {{ !empty($r->truck_plate_display) ? 'เปลี่ยนรถ' : 'เลือกรถ' }}
                                                    </button>

                                                    @if (!empty($r->truck_plate_display) && empty($r->truck_closed_at))
                                                        <button type="button"
                                                            class="btn btn-sm btn-outline-warning jsUnassignTruckBtn"
                                                            data-ord-id="{{ $r->ord_id }}"
                                                            data-so="{{ e($soText) }}"
                                                            data-mfg="{{ e($mfgText) }}"
                                                            data-plate="{{ e($r->truck_plate_display) }}"
                                                            title="ยกเลิกรถของรายการนี้">
                                                            ยกเลิกรถ
                                                        </button>
                                                    @endif
                                                @endif
                                            @endif

                                            @if ($specialIsOpen)
                                                <form method="POST"
                                                    action="{{ route('dp.inquiry.special-dispatch.close', ['ordId' => $r->ord_id]) }}"
                                                    class="d-inline"
                                                    onsubmit="return confirm('ยืนยันปิดงานพิเศษนี้?');">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-outline-success">
                                                        ปิดงานพิเศษ
                                                    </button>
                                                </form>
                                            @endif

                                            @php
                                                $shipRaw = trim((string) ($r->ship_posted_at ?? ''));
                                                $shipDt = null;

                                                try {
                                                    $shipDt =
                                                        $shipRaw !== ''
                                                            ? \Carbon\Carbon::parse($shipRaw)->startOfDay()
                                                            : null;
                                                } catch (\Throwable $e) {
                                                    $shipDt = null;
                                                }

                                                $cutoff = $shipDt ? $shipDt->copy()->subDays(3)->endOfDay() : null;
                                                $now = \Carbon\Carbon::now();
                                                $canVoid = $canDp && $cutoff ? $now->lte($cutoff) : false;

                                                $voidReason = !$shipDt
                                                    ? 'ยกเลิกไม่ได้ เพราะวันที่ส่งสินค้า(ship_posted_at) ไม่ถูกต้อง/ว่าง'
                                                    : 'ยกเลิกได้เฉพาะก่อนวันส่งสินค้าอย่างน้อย 3 วัน (ภายใน ' .
                                                        $cutoff->format('d/m/Y') .
                                                        ')';
                                            @endphp

                                            @if (!$isLoggedIn)
                                                <a class="btn btn-sm btn-outline-danger"
                                                    href="{{ $loginUrl }}">ยกเลิก</a>
                                            @elseif ($canDp)
                                                <button type="button"
                                                    class="btn btn-sm {{ $canVoid ? 'btn-outline-danger jsVoidBtn' : 'btn-outline-secondary' }}"
                                                    {{ $canVoid ? '' : 'disabled' }} data-ord-id="{{ $r->ord_id }}"
                                                    data-so="{{ e($soText) }}"
                                                    data-part="{{ e($r->part_number ?? '') }}"
                                                    data-ship="{{ e($shipRaw) }}"
                                                    title="{{ $canVoid ? 'ยกเลิกรายการ' : $voidReason }}">
                                                    ยกเลิก
                                                </button>
                                            @endif
                                        </div>
                                    </td>

                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-dark jsHistoryBtn"
                                            data-ord-id="{{ $r->ord_id }}" data-current-rev="{{ $revNo }}"
                                            data-bs-toggle="modal" data-bs-target="#historyModal">
                                            ดู history
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        @empty
                            <tr>
                                <td colspan="{{ $tableColspan }}" class="text-center text-muted py-4">ไม่พบข้อมูล</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $rows->links() }}
            </div>
        </div>

        {{-- History Modal --}}
        <div class="modal fade" id="historyModal" tabindex="-1">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title">History</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                            <div id="historyMeta" class="text-muted small"></div>

                            <div class="btn-group btn-group-sm" role="group" aria-label="history view">
                                <button type="button" class="btn btn-outline-primary"
                                    id="btnHistCurrent">เทียบกับปัจจุบัน</button>
                                <button type="button" class="btn btn-outline-secondary"
                                    id="btnHistStep">ดูการแก้ไขแต่ละครั้ง</button>
                                <button type="button" class="btn btn-outline-dark"
                                    id="btnHistDb">ภาพรวมการแก้ไขข้อมูล</button>
                            </div>
                        </div>

                        <div id="histViewTop">
                            <table class="table table-sm table-bordered align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:70px" class="text-center">Rev</th>
                                        <th style="width:260px">Period</th>
                                        <th style="width:140px">Revise By</th>
                                        <th id="histColTitle">Changed Fields (เทียบกับปัจจุบัน)</th>
                                    </tr>
                                </thead>
                                <tbody id="historyTbody"></tbody>
                            </table>
                        </div>

                        <div id="histViewDb" class="d-none">
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr id="histDbThead"></tr>
                                    </thead>
                                    <tbody id="histDbTbody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Postpone Modal --}}
        <div class="modal fade" id="postponeModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="POST" id="postponeForm">
                    @csrf
                    <input type="hidden" name="return_url" value="{{ url()->full() }}">

                    <div class="modal-header">
                        <h5 class="modal-title">เลื่อนแผนส่ง</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-2">
                            <div class="small text-muted">รายการเดิม</div>
                            <div class="fw-semibold" id="postponeInfo">-</div>
                        </div>

                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">วันที่ส่งใหม่</label>
                                <input type="date" class="form-control" name="new_ship_posted_date"
                                    id="postponeShipDate" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">เวลาใหม่</label>
                                <input type="time" class="form-control" name="new_window_time"
                                    id="postponeWindowTime" required>
                            </div>
                        </div>

                        <div class="mt-2">
                            <label class="form-label fw-semibold">เหตุผลเลื่อนแผน</label>
                            <textarea class="form-control" name="postpone_reason" id="postponeReason" rows="3"
                                placeholder="กรอกเหตุผล" required></textarea>
                        </div>

                        <div class="alert alert-warning small mb-0 mt-3">
                            ระบบจะสร้างแผนใหม่จากข้อมูลเดิม และเก็บรายการเดิมไว้เป็น reference สถานะ POSTPONED
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button>
                        <button type="submit" class="btn btn-warning" id="btnConfirmPostpone">ยืนยันเลื่อนแผน</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Void Modal --}}
        <div class="modal fade" id="voidModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="POST" id="voidForm">
                    @csrf

                    <div class="modal-header">
                        <h5 class="modal-title">ยกเลิก PlanDelivery</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-2">
                            <div class="small text-muted">รายการ</div>
                            <div class="fw-semibold" id="voidInfo">-</div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-semibold">เหตุผลยกเลิก</label>
                            <textarea class="form-control" name="remark_void" id="remarkVoid" rows="3" placeholder="กรอกเหตุผล" required></textarea>
                        </div>

                        <div class="alert alert-warning small mb-0">
                            ยกเลิกได้เฉพาะรายการก่อนถึงวันส่งสินค้า 3 วันเท่านั้น
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button>
                        <button type="submit" class="btn btn-danger" id="btnConfirmVoid">Void</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Unassign Truck Modal --}}
        <div class="modal fade" id="unassignTruckModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="POST" id="unassignTruckForm">
                    @csrf

                    <div class="modal-header">
                        <h5 class="modal-title">ยกเลิกรถ</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-2">
                            <div class="small text-muted">รายการ</div>
                            <div class="fw-semibold" id="unassignTruckInfo">-</div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-semibold">เหตุผลยกเลิกรถ</label>
                            <textarea class="form-control" name="remark_unassign" id="remarkUnassign" rows="3"
                                placeholder="กรอกเหตุผล" required></textarea>
                        </div>

                        <div class="alert alert-warning small mb-0">
                            จะลบการ assign รถของงานชิ้นนี้ และเปลี่ยนสถานะกลับเป็น NEW
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button>
                        <button type="submit" class="btn btn-warning" id="btnConfirmUnassignTruck">ยืนยันยกเลิกรถ</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Truck Modal --}}
        <div class="modal fade" id="truckModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <form method="POST" id="truckAssignForm"
                        action="{{ route('dp.inquiry.truck.assign', ['ordId' => 0]) }}">
                        @csrf

                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title mb-1">เลือกรถ</h5>
                                <div class="small text-muted">
                                    SO: <span id="tmSoText" class="fw-semibold">-</span>
                                    <span class="mx-2">|</span>
                                    วันที่ส่งสินค้า: <span id="tmShipDateText" class="fw-semibold">-</span>
                                </div>
                                <div class="small text-muted">
                                    สถานที่ส่ง: <span id="tmShipToText" class="fw-semibold">-</span>
                                </div>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>

                        <div class="modal-body truck-modal-body">
                            <input type="hidden" name="ord_id" id="tmOrdId">
                            <input type="hidden" name="so_number" id="tmSoHidden">
                            <input type="hidden" name="ship_posted_at" id="tmShipDateHidden">
                            <input type="hidden" name="truck_id" id="tmTruckId">
                            <input type="hidden" name="return_url" id="tmReturnUrl" value="{{ url()->full() }}">
                            <input type="hidden" name="manual_plate_no" id="tmManualPlateHidden">
                            <input type="hidden" name="manual_driver_name" id="tmManualDriverHidden">
                            <input type="hidden" name="manual_driver_phone" id="tmManualPhoneHidden">
                            <input type="hidden" name="manual_max_load" id="tmManualMaxLoadHidden">
                            <input type="hidden" name="manual_car_length" id="tmManualLengthHidden">
                            <input type="hidden" name="manual_remark" id="tmManualRemarkHidden">

                            <div class="dp-dispatch-panel mb-3">
                                <div class="d-flex flex-wrap align-items-end gap-3">
                                    <div class="flex-grow-1">
                                        <label class="form-label small fw-semibold mb-2">รูปแบบการจัดส่ง</label>
                                        <div class="btn-group btn-group-sm dispatch-mode-group" role="group"
                                            aria-label="dispatch mode">
                                            <input type="radio" class="btn-check" name="tm_dispatch_mode"
                                                id="tmDispatchTruckMode" value="TRUCK" checked>
                                            <label class="btn btn-outline-primary" for="tmDispatchTruckMode">
                                                จัดรถปกติ
                                            </label>

                                            <input type="radio" class="btn-check" name="tm_dispatch_mode"
                                                id="tmDispatchSpecialMode" value="SPECIAL">
                                            <label class="btn btn-outline-warning" for="tmDispatchSpecialMode">
                                                ช่องทางพิเศษ
                                            </label>
                                        </div>
                                    </div>

                                    <div class="dp-trip-control" id="tmTripControl">
                                        <label class="form-label small fw-semibold mb-2" for="tmTripNo">เที่ยวที่</label>
                                        <div class="input-group input-group-sm">
                                            <button class="btn btn-outline-secondary" type="button"
                                                id="tmTripMinus">-</button>
                                            <input type="number" class="form-control text-center" name="trip_no"
                                                id="tmTripNo" min="1" max="99" step="1" value="1">
                                            <button class="btn btn-outline-secondary" type="button"
                                                id="tmTripPlus">+</button>
                                        </div>
                                    </div>
                                </div>

                                <div class="dp-special-panel mt-3 d-none" id="tmSpecialDispatchPanel">
                                    <div class="row g-2">
                                        <div class="col-md-5">
                                            <label class="form-label small fw-semibold mb-1"
                                                for="tmSpecialDispatchType">ประเภทช่องทางพิเศษ</label>
                                            <select class="form-select form-select-sm" name="dispatch_type"
                                                id="tmSpecialDispatchType">
                                                @foreach ($specialDispatchTypes as $type => $label)
                                                    <option value="{{ $type }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-7">
                                            <label class="form-label small fw-semibold mb-1"
                                                for="tmSpecialDispatchRemark">หมายเหตุ</label>
                                            <textarea class="form-control form-control-sm" name="remark"
                                                id="tmSpecialDispatchRemark" rows="2"
                                                placeholder="ระบุรายละเอียดเพิ่มเติม"></textarea>
                                        </div>
                                    </div>
                                    <div class="form-text">
                                        ช่องทางพิเศษจะไม่คิด capacity รถ และจะลบ assignment รถเดิมของรายการนี้ออก
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3" id="tmLineSelectPanel">
                                <div class="d-flex gap-2 mb-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                        id="tmCheckAll">เลือกทั้งหมด</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                        id="tmUncheckAll">ล้างเลือก</button>
                                </div>

                                <div class="table-responsive truck-lines-wrap">
                                    <table class="table table-sm table-bordered align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>เลือก</th>
                                                <th>เลข MFG</th>
                                                <th>Part</th>
                                                <th>Part Desc</th>
                                                <th>ระบุเส้น</th>
                                                <th>สถานที่ส่ง</th>
                                                <th class="text-end">ขึ้นรถแล้ว (ตัน)</th>
                                                <th class="text-end">คงเหลือ (ตัน)</th>
                                                <th class="text-end">น้ำหนักขึ้นรถ (ตัน)</th>
                                            </tr>
                                        </thead>
                                        <tbody id="tmLineTbody"></tbody>
                                        <tfoot>
                                            <tr>
                                                <th colspan="7" class="text-end">รวมที่เลือก</th>
                                                <th class="text-end" id="tmTotalQty">0.000</th>
                                                <th></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>

                            <div class="row g-3" id="tmTruckPickPanel">
                                <div class="col-lg-7">
                                    <div class="card h-100">
                                        <div class="card-header py-2 fw-semibold">รถในระบบ</div>

                                        <div class="card-body truck-system-body">
                                            <div class="mb-2">
                                                <input type="text" id="tmTruckSearch"
                                                    class="form-control form-control-sm"
                                                    placeholder="ค้นหาทะเบียน / คนขับ / หมายเหตุ / ลูกค้า / ประเภทงาน">
                                            </div>

                                            <div class="alert alert-secondary py-2 px-3 small mb-2">
                                                น้ำหนักที่เลือก: <span class="fw-bold" id="tmSelectedWeight">0.000</span>
                                                ตัน
                                                <span class="mx-2">|</span>
                                                รถจะเหลือ: <span class="fw-bold" id="tmTruckRemainAfter">-</span>
                                            </div>

                                            <div id="tmTruckPickedInfo"
                                                class="alert alert-info py-2 px-3 small mb-2 d-none">
                                                <div><span class="fw-semibold">รถที่เลือก:</span> <span
                                                        id="tmPickedPlate">-</span></div>
                                                <div><span class="fw-semibold">งานที่บรรทุกอยู่:</span> <span
                                                        id="tmPickedJobs">-</span></div>
                                            </div>

                                            <div class="truck-master-area">
                                                <div class="table-responsive truck-master-wrap">
                                                    <table
                                                        class="table table-sm table-hover align-middle mb-0 truck-master-table">
                                                        <colgroup>
                                                            <col style="width:70px;">
                                                            <col style="width:180px;">
                                                            <col style="width:90px;">
                                                            <col style="width:90px;">
                                                            <col style="width:90px;">
                                                            <col style="width:80px;">
                                                            <col style="width:240px;">
                                                            <col style="width:260px;">
                                                        </colgroup>
                                                        <thead class="table-light">
                                                            <tr>
                                                                <th class="text-center">เลือก</th>
                                                                <th>ทะเบียน / คนขับ</th>
                                                                <th class="text-end">Max (ตัน)</th>
                                                                <th class="text-end">Current (ตัน)</th>
                                                                <th class="text-end">Remain (ตัน)</th>
                                                                <th class="text-end">Length</th>
                                                                <th>ลูกค้า / ประเภทงาน</th>
                                                                <th>SO / MFG / หมายเหตุ</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody id="tmTruckTableBody">
                                                            @foreach ($trucks as $t)
                                                                @php
                                                                    $isManualTemp =
                                                                        ($t->truck_pick_type ?? '') === 'MANUAL_TEMP';
                                                                    $rowKey = $t->row_key ?? '';
                                                                    $jobSummary = collect($t->job_summary ?? []);
                                                                    $jobSummaryText = $jobSummary
                                                                        ->map(function ($x) use ($fmtTon) {
                                                                            return trim(
                                                                                ($x['customer_name'] ?? '-') .
                                                                                    ' | ' .
                                                                                    ($x['job_type'] ?? '-') .
                                                                                    ' (' .
                                                                                    $fmtTon($x['assigned_weight'] ?? 0) .
                                                                                    ' ตัน)',
                                                                            );
                                                                        })
                                                                        ->implode(' || ');
                                                                    $soSummaryText = trim(
                                                                        (string) ($t->so_summary_text ?? ''),
                                                                    );
                                                                    $mfgSummaryText = trim(
                                                                        (string) ($t->mfg_summary_text ?? ''),
                                                                    );
                                                                    $remarkText = trim((string) ($t->remark ?? ''));
                                                                    $capacityUnlimited =
                                                                        !empty($t->capacity_unlimited) ||
                                                                        (float) ($t->max_load ?? 0) <= 0;
                                                                @endphp

                                                                <tr class="tmTruckRow" data-row-key="{{ e($rowKey) }}"
                                                                    data-truck-id="{{ e($t->truck_id ?? '') }}"
                                                                    data-manual-plate="{{ e($t->manual_plate_no ?? '') }}"
                                                                    data-job-summary="{{ e($jobSummaryText) }}"
                                                                    data-search="{{ mb_strtolower(trim(($t->plate_no ?? '') . ' ' . ($t->driver_name ?? '') . ' ' . $remarkText . ' ' . $jobSummaryText . ' ' . $soSummaryText . ' ' . $mfgSummaryText)) }}">
                                                                    <td class="text-center">
                                                                        <input type="radio"
                                                                            class="form-check-input tmTruckRadio"
                                                                            name="truck_pick_mode"
                                                                            value="{{ $isManualTemp ? 'MANUAL_TEMP' : 'MASTER' }}"
                                                                            data-row-key="{{ e($rowKey) }}"
                                                                            data-truck-id="{{ e($t->truck_id ?? '') }}"
                                                                            data-manual-plate="{{ e($t->manual_plate_no ?? '') }}"
                                                                            data-plate="{{ e($t->plate_no ?? '') }}"
                                                                            data-driver-name="{{ e($t->driver_name ?? '') }}"
                                                                            data-driver-phone="{{ e($t->driver_phone ?? '') }}"
                                                                            data-max="{{ e((float) ($t->max_load ?? 0)) }}"
                                                                            data-current="{{ e((float) ($t->current_load ?? 0)) }}"
                                                                            data-remaining="{{ e((float) ($t->remaining_capacity ?? 0)) }}"
                                                                            data-capacity-unlimited="{{ $capacityUnlimited ? '1' : '0' }}"
                                                                            data-car-length="{{ e($t->car_length ?? '') }}"
                                                                            data-remark="{{ e($remarkText) }}"
                                                                            data-job-summary="{{ e($jobSummaryText) }}"
                                                                            {{ !$capacityUnlimited && (float) ($t->remaining_capacity ?? 0) <= 0 ? 'disabled' : '' }}>
                                                                    </td>

                                                                    <td>
                                                                        <div class="fw-semibold">
                                                                            {{ $t->plate_no ?? '-' }}
                                                                            @if ($isManualTemp)
                                                                                <span
                                                                                    class="badge bg-info-subtle text-info-emphasis border ms-1">รถนอกวันนี้</span>
                                                                            @else
                                                                                <span
                                                                                    class="badge bg-light text-dark border ms-1">ในระบบ</span>
                                                                            @endif
                                                                        </div>
                                                                        <div class="small text-muted">
                                                                            {{ $t->driver_name ?? '-' }}</div>
                                                                        <div class="small text-muted">
                                                                            {{ $t->driver_phone ?? '-' }}</div>
                                                                    </td>

                                                                    <td class="text-end">
                                                                        {{ $capacityUnlimited ? 'ไม่ระบุ' : $fmtTon($t->max_load ?? 0) }}
                                                                    </td>

                                                                    <td class="text-end">
                                                                        {{ $fmtTon($t->current_load ?? 0) }}
                                                                    </td>

                                                                    @php
                                                                        $tooltipLines = [];

                                                                        if (!empty($t->job_summary)) {
                                                                            foreach ($t->job_summary as $job) {
                                                                                $tooltipLines[] =
                                                                                    ($job['so_number'] ??
                                                                                    $soSummaryText ?:
                                                                                        '-') .
                                                                                    ' || ' .
                                                                                    ($job['mfg_no'] ??
                                                                                    $mfgSummaryText ?:
                                                                                        '-') .
                                                                                    ' || ' .
                                                                                    $fmtTon($job['assigned_weight'] ?? 0) .
                                                                                    ' ตัน || ' .
                                                                                    ($job['address'] ?? '-');
                                                                            }
                                                                        }

                                                                        if (empty($tooltipLines)) {
                                                                            $tooltipLines[] = 'ยังไม่มีรายการ';
                                                                        }

                                                                        $remainTooltip = implode("\n", $tooltipLines);
                                                                    @endphp

                                                                    <td class="text-end {{ !$capacityUnlimited && (float) ($t->remaining_capacity ?? 0) < 0 ? 'text-danger fw-bold' : 'fw-semibold' }}"
                                                                        data-bs-toggle="tooltip" data-bs-placement="top"
                                                                        data-bs-html="false"
                                                                        data-bs-custom-class="truck-remain-tooltip"
                                                                        title="{{ $remainTooltip }}">
                                                                        {{ $capacityUnlimited ? 'ตามน้ำหนักที่กรอก' : $fmtTon($t->remaining_capacity ?? 0) }}
                                                                    </td>

                                                                    <td class="text-end">
                                                                        {{ $t->car_length ? number_format((float) $t->car_length, 0) : '-' }}
                                                                    </td>

                                                                    <td class="small">
                                                                        @forelse(($t->job_summary ?? []) as $job)
                                                                            <div class="mb-1">
                                                                                <span
                                                                                    class="fw-semibold">{{ $job['customer_name'] ?? '-' }}</span>
                                                                                <span class="text-muted">|
                                                                                    {{ $job['job_type'] ?? '-' }}</span>
                                                                                <span
                                                                                    class="text-primary">({{ $fmtTon($job['assigned_weight'] ?? 0) }}
                                                                                    ตัน)</span>
                                                                            </div>
                                                                        @empty
                                                                            <span class="text-muted">ยังไม่มีรายการ</span>
                                                                        @endforelse
                                                                    </td>

                                                                    <td class="small">
                                                                        @if ($soSummaryText !== '')
                                                                            <div><span class="fw-semibold">SO:</span>
                                                                                {{ $soSummaryText }}</div>
                                                                        @endif
                                                                        @if ($mfgSummaryText !== '')
                                                                            <div><span class="fw-semibold">MFG:</span>
                                                                                {{ $mfgSummaryText }}</div>
                                                                        @endif
                                                                        <div><span class="fw-semibold">Remark:</span>
                                                                            {{ $remarkText !== '' ? $remarkText : '-' }}
                                                                        </div>
                                                                    </td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>

                                            <div class="form-check mt-3 flex-shrink-0">
                                                <input class="form-check-input" type="checkbox" id="tmReplaceMode"
                                                    name="replace_mode" value="1">
                                                <label class="form-check-label" for="tmReplaceMode">
                                                    เปลี่ยนรถใหม่สำหรับรายการที่เลือก
                                                </label>
                                                <div class="form-text">
                                                    เมื่อติ๊ก ระบบจะลบ assignment เดิมของรายการที่เลือกก่อน
                                                    แล้วค่อยจัดรถใหม่
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-lg-5">
                                    <div class="card h-100">
                                        <div class="card-header py-2 fw-semibold">รถนอก</div>
                                        <div class="card-body">
                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="radio" name="truck_pick_mode"
                                                    id="tmManualMode" value="MANUAL">
                                                <label class="form-check-label" for="tmManualMode">
                                                    ใช้ข้อมูลรถนอกนี้
                                                </label>
                                            </div>
                                            <div class="row g-2">
                                                <div class="col-md-6">
                                                    <label class="form-label small mb-1">ทะเบียนรถ</label>
                                                    <input type="text" class="form-control form-control-sm"
                                                        id="tmManualPlate">
                                                </div>

                                                <div class="col-md-6">
                                                    <label class="form-label small mb-1">คนขับ</label>
                                                    <input type="text" class="form-control form-control-sm"
                                                        id="tmManualDriver">
                                                </div>

                                                <div class="col-md-6">
                                                    <label class="form-label small mb-1">เบอร์โทร</label>
                                                    <input type="text" class="form-control form-control-sm"
                                                        id="tmManualPhone">
                                                </div>

                                                <div class="col-md-6">
                                                    <label class="form-label small mb-1">Max Load (ตัน)</label>
                                                    <input type="number" step="0.01"
                                                        class="form-control form-control-sm" id="tmManualMaxLoad"
                                                        placeholder="เช่น 25 = 25 ตัน">
                                                </div>

                                                <div class="col-md-6">
                                                    <label class="form-label small mb-1">ความยาวรถ</label>
                                                    <input type="number" step="1"
                                                        class="form-control form-control-sm" id="tmManualLength">
                                                </div>

                                                <div class="col-md-6">
                                                    <label class="form-label small mb-1">หมายเหตุ</label>
                                                    <input type="text" class="form-control form-control-sm"
                                                        id="tmManualRemark" placeholder="เช่น รถนอก / เปิดข้าง / ตู้">
                                                </div>
                                            </div>

                                            <div class="alert alert-info small mt-3 mb-2">
                                                ถ้าใช้ทะเบียนเดิมในวันส่งเดียวกัน ระบบจะถือเป็นรถนอกคันเดิม และจะนำ capacity
                                                ที่เหลือมาใช้ต่อกับ SO อื่นได้
                                            </div>

                                            <hr class="my-3">

                                            <div class="fw-semibold mb-2">พนักงานประจำรถ</div>

                                            <div class="row g-2">
                                                <div class="col-md-12">
                                                    <label class="form-label small mb-1">คนขับ</label>
                                                    <select class="form-select form-select-sm" name="driver_staff_id"
                                                        id="tmDriverStaffId">
                                                        <option value="">-- เลือกคนขับ --</option>
                                                    </select>
                                                </div>

                                                <div class="col-md-12">
                                                    <label class="form-label small mb-1">เด็กรถ 1</label>
                                                    <select class="form-select form-select-sm" name="helper1_staff_id"
                                                        id="tmHelper1StaffId">
                                                        <option value="">-- เลือกเด็กรถ 1 --</option>
                                                    </select>
                                                </div>

                                                <div class="col-md-12">
                                                    <label class="form-label small mb-1">เด็กรถ 2</label>
                                                    <select class="form-select form-select-sm" name="helper2_staff_id"
                                                        id="tmHelper2StaffId">
                                                        <option value="">-- เลือกเด็กรถ 2 --</option>
                                                    </select>
                                                </div>

                                                <div class="col-md-12">
                                                    <label class="form-label small mb-1">เด็กรถ 3</label>
                                                    <select class="form-select form-select-sm" name="helper3_staff_id"
                                                        id="tmHelper3StaffId">
                                                        <option value="">-- เลือกเด็กรถ 3 --</option>
                                                    </select>
                                                </div>
                                            </div>

                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="modal-footer">
                            <div class="me-auto small text-muted" id="tmFooterHelp">
                                ระบบจะบันทึกตามน้ำหนักที่รถยังรับได้จริง และถ้าเต็มก่อนจะบันทึกเฉพาะบางส่วน
                            </div>
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button>
                            <button type="submit" class="btn btn-primary" id="btnTruckAssignSubmit">บันทึก</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>


        <div class="modal fade" id="sendPlanMailModal" tabindex="-1" aria-labelledby="sendPlanMailModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <form id="formSendMail" method="POST" action="{{ route('dp.inquiry.send-plan-mail') }}"
                    class="modal-content">
                    @csrf

                    <div class="modal-header">
                        <h5 class="modal-title" id="sendPlanMailModalLabel">ส่งเมลแจ้งแผนส่งมอบ</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">วันที่ส่งสินค้า</label>
                                <input type="date" name="ship_posted_at" class="form-control"
                                    value="{{ old('ship_posted_at', request('ship_posted_at')) }}" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Revision</label>
                                <input type="number" name="revision_number" class="form-control"
                                    value="{{ old('revision_number', request('revision_number', 0)) }}" min="0"
                                    required>
                                <div class="form-text">
                                    Auto: เพิ่มเติม 1 = 10:00 AM, เพิ่มเติม 2 = 13:00 PM, เพิ่มเติม 3 = 16:00 PM.
                                    เพิ่มเติม 4-6 = Sale Co. ส่งเอง
                                </div>
                            </div>

                            <div class="col-md-12">
                                <label class="form-label">Remark</label>
                                <textarea name="remark" class="form-control" rows="2" placeholder="หมายเหตุเพิ่มเติม">{{ old('remark') }}</textarea>
                            </div>

                            <div class="col-md-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="1" id="forceSend"
                                        name="force_send">
                                    <label class="form-check-label" for="forceSend">
                                        Force Send (ส่งซ้ำแม้เคยส่งแล้ว)
                                    </label>
                                </div>
                            </div>
                        </div>

                        @if ($errors->has('mail') || $errors->has('ship_posted_at') || $errors->has('revision_number'))
                            <div class="alert alert-danger mt-3 mb-0">
                                @foreach ($errors->all() as $error)
                                    <div>{{ $error }}</div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">ปิด</button>
                        <button type="submit" id="btnSendMail" class="btn btn-primary">
                            <span class="btn-text">
                                <i class="fas fa-paper-plane me-1"></i> ส่งเมล
                            </span>

                            <span class="btn-loading d-none">
                                <span class="spinner-border spinner-border-sm me-1" role="status"></span>
                                กำลังส่ง...
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>
@endsection

@section('styles')
    <link rel="stylesheet" href="{{ asset('css/formdp/inquiry.css') }}">
@endsection

@push('scripts')
    <script>
        window.DP_INQUIRY = {
            docMap: @json($docMap),
            routes: {
                history: @json(route('dp.inquiry.history', ['ordId' => '__ID__'])),
                historyDb: @json(route('dp.history.db', ['ord_id' => '__ID__'])),
                void: @json(route('dp.void', ['ordId' => '__ID__'])),
                postpone: @json(route('dp.inquiry.postpone', ['ordId' => '__ID__'])),
                specialDispatch: @json(route('dp.inquiry.special-dispatch', ['ordId' => '__ID__'])),
                truckAssign: @json(route('dp.inquiry.truck.assign', ['ordId' => '__ID__'])),
                truckUnassign: @json(route('dp.inquiry.truck.unassign', ['ordId' => '__ID__'])),
                base: @json(route('dp.inquiry')),
                truckCapacity: @json(route('dp.truck.capacity')),
                truckStaffOptions: @json(route('dp.truck.staff.options')),
                truckStaffDefaults: @json(route('dp.truck.staff.defaults')),
            }
        };
    </script>

    <script src="{{ asset('js/formdp/inquiry.js') }}?v=20260601_1"></script>
@endpush
