@extends('layouts.layout')
@section('page-title', 'จัดรถส่งสินค้า')
@section('title', 'จัดรถส่งสินค้า')

@section('content')
    @php
        $fmtWeight = fn($kg) => number_format((float) ($kg ?? 0), 0, '.', ',') . ' kg';
        $fmtCount = fn($value) => number_format((float) ($value ?? 0), 0, '.', ',');
        $isLoggedIn = auth()->check();
        $u = auth()->user();
        $canDpMail = $isLoggedIn &&
            (($u->is_superadmin ?? 0) == 1 ||
                (method_exists($u, 'hasRoleCode') && $u->hasRoleCode(['DPEMAIL', 'DPMAIL'])));
        $canDpa = $isLoggedIn &&
            (($u->is_superadmin ?? 0) == 1 ||
                (method_exists($u, 'hasRoleCode') && $u->hasRoleCode('DPA')));

        // สำหรับรายการที่ขายเป็นชิ้น/เส้น (qty = 0 kg, แต่มี line_qty)
        // ให้โชว์เป็น "N ชิ้น" แทน "0 kg" เพื่อไม่ให้ผู้ใช้สับสน
        $isPieceGroup = fn($g) => (float) ($g->total_weight ?? 0) <= 0 && (float) ($g->piece_count ?? 0) > 0;
        $fmtGroupAmount = function ($g, $kind) use ($fmtWeight, $isPieceGroup) {
            if (!$isPieceGroup($g)) {
                $key = $kind . '_weight';
                return $fmtWeight($g->{$key} ?? 0);
            }
            $pieces = (float) ($g->piece_count ?? 0);
            $piecesText = number_format($pieces, 0, '.', ',') . ' ชิ้น';
            $completed = ($g->status ?? '') === 'completed';
            return match ($kind) {
                'total' => $piecesText,
                'assigned' => $completed ? $piecesText : '0 ชิ้น',
                'remaining' => $completed ? '0 ชิ้น' : $piecesText,
                default => $piecesText,
            };
        };

        // แยกรถในระบบ / รถนอก (ที่เคยใช้ในวันส่งเดียวกัน) สำหรับ dropdown
        $masterTrucks   = collect($trucks ?? [])->filter(fn($t) => ($t->truck_pick_type ?? '') === 'MASTER')->values();
        $externalTrucks = collect($trucks ?? [])->filter(fn($t) => ($t->truck_pick_type ?? '') !== 'MASTER')->values();
        $renderTruckOption = function ($truck) use ($fmtWeight) {
            $remain = $truck->capacity_unlimited ? 'ไม่จำกัด' : $fmtWeight($truck->remaining_capacity);
            $plate = $truck->plate_no ?: '-';
            return [
                'value'    => $truck->row_key,
                'attrs'    => [
                    'data-pick-type' => $truck->truck_pick_type,
                    'data-truck-id'  => $truck->truck_id,
                    'data-plate'     => $truck->plate_no,
                    'data-driver'    => $truck->driver_name,
                    'data-phone'     => $truck->driver_phone,
                    'data-max-load'  => $truck->max_load,
                    'data-length'    => $truck->car_length,
                    'data-remark'    => $truck->remark,
                ],
                'text'     => $plate . ' · เหลือ ' . $remain,
            ];
        };
        $statusLabel = [
            'unassigned' => 'ยังไม่จัดรถ',
            'partial' => 'จัดบางส่วน',
            'completed' => 'จัดครบ',
        ];
        $statusClass = [
            'unassigned' => 'warning',
            'partial' => 'primary',
            'completed' => 'success',
        ];
        $specialRowsFlat = $specialGroups->flatten(1)->values();

        $specialMap = $specialRowsFlat
            ->map(function ($row) use ($shipDate, $specialDispatchTypes) {
                $type = strtoupper((string) ($row->dispatch_type ?? ''));
                $label = $specialDispatchTypes[$type] ?? ($row->dispatch_label ?? 'งานพิเศษ');
                $searchText = mb_strtolower(collect([
                    $row->so_number ?? '',
                    $row->mfg_no ?? '',
                    $row->customer_name ?? '',
                    $row->address ?? '',
                    $row->special_remark ?? '',
                    $row->close_remark ?? '',
                    $label,
                ])->implode(' '));

                return (object) [
                    'key' => 'special-' . (int) ($row->ord_id ?? 0),
                    'ord_id' => (int) ($row->ord_id ?? 0),
                    'so_number' => $row->so_number ?: '-',
                    'mfg_no' => $row->mfg_no ?: '-',
                    'customer_display' => $row->customer_name ?: '-',
                    'ship_to_display' => $row->address ?: '-',
                    'ship_date' => $shipDate,
                    'qty' => (float) ($row->qty ?? 0),
                    'is_open' => (bool) ($row->is_open ?? false),
                    'dispatch_type' => $type,
                    'dispatch_label' => $label,
                    'special_remark' => $row->special_remark ?? '',
                    'closed_at' => $row->closed_at ?? null,
                    'closed_by_name' => $row->closed_by_name ?? null,
                    'close_remark' => $row->close_remark ?? '',
                    'search_text' => $searchText,
                    'row' => $row,
                ];
            })
            ->values();

        if ($search !== '') {
            $specialMap = $specialMap->filter(fn($s) => str_contains($s->search_text, $search))->values();
        }

        $specialQueueItems = $specialMap->filter(fn($s) => $s->is_open)->values();
        $specialClosedItems = $specialMap->filter(fn($s) => !$s->is_open)->values();

        $selectedOrdIds = trim((string) request()->query('ord_ids', ''));
        $isSelectedBatchView = $selectedOrdIds !== '';

        if (!in_array($statusFilter, ['all', 'special'], true)) {
            $specialQueueItems = collect();
        }

        // โหมด batch: งานพิเศษเข้าผ่านลิสต์ "+" แล้ว ไม่ต้องโชว์คิว/พาเนลงานพิเศษเดิมอีก (กันขึ้นซ้ำ 2 ที่)
        if ($isSelectedBatchView) {
            $specialQueueItems = collect();
        }

        // โหมด batch: คงกลุ่มที่มัดรายการไว้เสมอ อย่า empty ทิ้งตามตัวกรองสถานะ
        if ($statusFilter === 'special' && !$isSelectedBatchView) {
            $grouped = collect();
        }

        // โหมด batch มีกลุ่มเดียว — บังคับให้ active เสมอ ไม่ต้องพึ่ง param selected (กันฝั่งขวาว่าง)
        $selectedKey = $isSelectedBatchView && $grouped->isNotEmpty()
            ? (string) $grouped->first()->key
            : (string) request()->query(
                'selected',
                optional($grouped->first())->key ?? optional($specialQueueItems->first())->key ?? ''
            );
        $selectedReturnUrl = fn($key) => route('dp.dashboard.logistics-summary', array_filter([
            'ship_date' => $shipDate,
            'selected' => $key,
            'ord_ids' => $selectedOrdIds !== '' ? $selectedOrdIds : null,
        ], fn($value) => $value !== null && $value !== ''));
    @endphp

    <style>
        /* Custom autocomplete dropdown สำหรับช่องคนขับ */
        .driver-ac-wrap {
            position: relative;
        }
        .driver-ac-panel {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            min-width: 280px;
            width: max-content;
            max-width: 380px;
            z-index: 1080;
            max-height: 280px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, .14);
            padding: 4px;
            display: none;
        }
        .driver-ac-panel.is-open {
            display: block;
        }
        .driver-ac-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            border-radius: 6px;
            cursor: pointer;
            line-height: 1.25;
            transition: background-color .12s;
        }
        .driver-ac-item:hover,
        .driver-ac-item.is-active {
            background: #eff6ff;
        }
        .driver-ac-avatar {
            flex: 0 0 30px;
            height: 30px;
            border-radius: 50%;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .82rem;
            font-weight: 600;
        }
        .driver-ac-body {
            flex: 1 1 auto;
            min-width: 0;
        }
        .driver-ac-name {
            font-size: .9rem;
            color: #0f172a;
            font-weight: 500;
            white-space: nowrap;
        }
        .driver-ac-phone {
            font-size: .78rem;
            color: #64748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .driver-ac-phone i {
            font-size: .7rem;
            margin-right: 4px;
        }
        .driver-ac-item mark {
            background: #fef3c7;
            color: inherit;
            padding: 0 1px;
            border-radius: 2px;
        }
        .driver-ac-empty {
            padding: 14px;
            text-align: center;
            color: #94a3b8;
            font-size: .85rem;
        }
        .driver-ac-group {
            font-size: .72rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .04em;
            padding: 8px 10px 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .driver-ac-group:not(:first-child) {
            border-top: 1px solid #e5e7eb;
            margin-top: 4px;
        }
        .driver-ac-group i {
            font-size: .72rem;
            color: #94a3b8;
        }
        .driver-ac-item .driver-ac-badge {
            font-size: .65rem;
            padding: 1px 6px;
            border-radius: 10px;
            background: #ecfdf5;
            color: #047857;
            margin-left: 6px;
            font-weight: 500;
        }

        /* ลิสต์ "เพิ่มรายการเข้ารถคันนี้" ทางซ้าย */
        .dp-batch-add-item {
            padding: 7px 8px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #fff;
            margin-bottom: 6px;
        }
        .dp-batch-add-item:hover {
            background: #eff6ff;
            border-color: #bfdbfe;
        }
        .dp-batch-add-item .min-w-0 {
            min-width: 0;
        }

        /* ตรึงแถบ "จัดพร้อมกัน" / "เพิ่มรายการ" ไว้บนสุดของคิว เวลาเลื่อนรายการลง */
        .jsQueuePickBar,
        .jsBatchAddPane {
            position: sticky;
            top: 0;
            z-index: 6;
            box-shadow: 0 4px 8px rgba(15, 23, 42, .06);
        }

        /* checkbox หน้าการ์ดคิว สำหรับ "จัดพร้อมกัน" */
        .queue-pick {
            background: #fff;
            cursor: pointer;
            border-right: 1px solid #edf2f7;
            border-bottom: 1px solid #edf2f7;
        }
        .queue-pick:hover {
            background: #eef6ff;
        }
        .queue-row .queue-item {
            border-bottom: 1px solid #edf2f7;
        }

        .logistics-page {
            background: #f4f7fb;
            min-height: calc(100vh - 70px);
        }

        .logistics-toolbar,
        .logistics-metric,
        .logistics-surface,
        .logistics-special {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 8px 22px rgba(15, 23, 42, .06);
        }

        .logistics-metric {
            padding: 14px 16px;
            height: 100%;
        }

        .metric-label {
            color: #64748b;
            font-size: .82rem;
            margin-bottom: 5px;
        }

        .metric-value {
            color: #0f172a;
            font-size: 1.55rem;
            font-weight: 700;
            line-height: 1.1;
        }

        .filter-chip {
            min-width: 108px;
        }

        .workspace-grid {
            display: grid;
            grid-template-columns: minmax(320px, 34%) minmax(0, 1fr);
            min-height: 620px;
        }

        .queue-pane {
            border-right: 1px solid #e5e7eb;
            max-height: 75vh;
            overflow: auto;
        }

        .detail-pane {
            max-height: 75vh;
            overflow: auto;
        }

        .queue-item {
            width: 100%;
            border: 0;
            border-bottom: 1px solid #edf2f7;
            background: #fff;
            text-align: left;
            padding: 12px 14px;
        }

        .queue-item:hover,
        .queue-item.is-active {
            background: #eef6ff;
        }

        .queue-item.is-active {
            box-shadow: inset 4px 0 0 #0d6efd;
        }

        .queue-title,
        .panel-title {
            color: #0f172a;
            font-weight: 700;
        }

        .queue-meta,
        .line-note {
            color: #64748b;
            font-size: .82rem;
        }

        .dispatch-panel {
            display: none;
        }

        .dispatch-panel.is-active {
            display: block;
        }

        .inline-section {
            border-top: 1px solid #edf2f7;
            padding-top: 14px;
            margin-top: 14px;
        }

        .line-table th {
            color: #475569;
            font-size: .78rem;
            white-space: nowrap;
        }

        .line-table td {
            font-size: .84rem;
            vertical-align: middle;
        }

        .truck-option-help {
            min-height: 20px;
        }

        .dispatch-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
        }

        .dispatch-actions {
            display: flex;
            gap: 8px;
            align-items: end;
            height: 100%;
        }

        .special-edit {
            border-top: 1px solid #edf2f7;
            padding: 12px 0;
        }

        .special-edit:first-child {
            border-top: 0;
            padding-top: 0;
        }

        @media (max-width: 991.98px) {
            .workspace-grid {
                grid-template-columns: 1fr;
            }

            .queue-pane {
                border-right: 0;
                border-bottom: 1px solid #e5e7eb;
                max-height: 360px;
            }

            .detail-pane {
                max-height: none;
            }
        }
    </style>

    <div class="logistics-page py-3">
        <div class="container-fluid">
            @include('formdp.partials.transport-nav', [
                'tnActive'   => 'logistics',
                'tnShipDate' => $shipDate,
                'tnSo'       => request('q', ''),
                'tnCustomer' => '',
                'tnMfg'      => '',
            ])

            <div class="text-muted small mb-3">วันที่ส่ง {{ \Carbon\Carbon::parse($shipDate)->format('d/m/Y') }}</div>

            @php
                $statusLabelMap = [
                    'unassigned' => 'ยังไม่จัดรถ',
                    'partial'    => 'จัดบางส่วน',
                    'completed'  => 'จัดครบ',
                    'special'    => 'งานพิเศษ',
                ];
                $activeFilters = [];
                if (($statusFilter ?? 'all') !== 'all') {
                    $activeFilters[] = ['key' => 'status', 'label' => 'สถานะ', 'value' => $statusLabelMap[$statusFilter] ?? $statusFilter];
                }
                if (trim((string) ($search ?? '')) !== '') {
                    $activeFilters[] = ['key' => 'q', 'label' => 'ค้นหา', 'value' => $search];
                }
                if (!empty($hideCompleted)) {
                    $activeFilters[] = ['key' => 'hide_completed', 'label' => 'ตัวเลือก', 'value' => 'ซ่อนงานจัดครบ'];
                }
                foreach (['so', 'mfg', 'customer', 'shipto', 'ord_id'] as $extraKey) {
                    $val = trim((string) request($extraKey, ''));
                    if ($val !== '') {
                        $extraLabels = ['so' => 'SO', 'mfg' => 'MFG', 'customer' => 'Customer', 'shipto' => 'Ship To', 'ord_id' => 'Ord ID'];
                        $activeFilters[] = ['key' => $extraKey, 'label' => $extraLabels[$extraKey], 'value' => $val];
                    }
                }
            @endphp

            @if (!empty($activeFilters))
                <div class="dp-active-filters mb-2 d-flex flex-wrap align-items-center gap-2">
                    <span class="small text-muted"><i class="fas fa-filter me-1"></i>ตัวกรองที่ใช้อยู่:</span>
                    @foreach ($activeFilters as $f)
                        @php
                            $removeUrl = request()->fullUrlWithQuery([$f['key'] => null]);
                        @endphp
                        <a href="{{ $removeUrl }}"
                            class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle text-decoration-none"
                            title="คลิกเพื่อลบตัวกรองนี้">
                            {{ $f['label'] }}: <span class="fw-semibold">{{ $f['value'] }}</span>
                            <i class="fas fa-times ms-1"></i>
                        </a>
                    @endforeach
                    <a href="{{ route('dp.dashboard.logistics-summary', ['ship_date' => $shipDate]) }}"
                        class="btn btn-sm btn-outline-danger py-0 px-2" title="ล้างทุกตัวกรอง">
                        <i class="fas fa-eraser me-1"></i>ล้างทั้งหมด
                    </a>
                </div>
            @endif

            <form method="GET" action="{{ route('dp.dashboard.logistics-summary') }}"
                class="logistics-toolbar p-3 mb-3">
                <div class="row g-2 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label small mb-1">วันที่ส่ง</label>
                        <input type="date" class="form-control form-control-sm" name="ship_date"
                            value="{{ $shipDate }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">สถานะจัดรถ</label>
                        <select class="form-select form-select-sm" name="status">
                            <option value="all" @selected($statusFilter === 'all')>ทั้งหมด</option>
                            <option value="unassigned" @selected($statusFilter === 'unassigned')>ยังไม่จัดรถ</option>
                            <option value="partial" @selected($statusFilter === 'partial')>จัดบางส่วน</option>
                            <option value="completed" @selected($statusFilter === 'completed')>จัดครบ</option>
                            <option value="special" @selected($statusFilter === 'special')>งานพิเศษ</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1">ค้นหา</label>
                        <input type="search" class="form-control form-control-sm" name="q"
                            value="{{ $search }}" placeholder="SO, MFG, ลูกค้า, สถานที่ส่ง, Sales">
                    </div>
                    <div class="col-md-2">
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="1" id="hideCompleted"
                                name="hide_completed" @checked($hideCompleted)>
                            <label class="form-check-label small" for="hideCompleted">ซ่อนงานจัดครบ</label>
                        </div>
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm flex-fill">
                            <i class="fas fa-search me-1"></i> ค้นหา
                        </button>
                        <a href="{{ route('dp.dashboard.logistics-summary', ['ship_date' => $shipDate]) }}"
                            class="btn btn-outline-secondary btn-sm">Reset</a>
                    </div>
                </div>
            </form>

            <div class="d-flex justify-content-end align-items-center gap-2 flex-wrap mb-3">
                <div class="btn-group btn-group-sm">
                    <a href="{{ route('dp.dashboard.truck-board.export.print', ['ship_date' => $shipDate]) }}"
                        class="btn btn-outline-primary" target="_blank">
                        <i class="fas fa-print me-1"></i> Export จัดรถทั้งหมด
                    </a>
                    <a href="{{ route('dp.dashboard.truck-board.export.pdf', ['ship_date' => $shipDate]) }}"
                        class="btn btn-outline-primary">PDF</a>
                    <a href="{{ route('dp.dashboard.truck-board.export.excel', ['ship_date' => $shipDate]) }}"
                        class="btn btn-outline-primary">Excel</a>
                </div>
                @if ($canDpa)
                    <form method="POST" action="{{ route('dp.dashboard.truck-board.send-mail') }}"
                        id="assignMailForm" class="jsAssignMailForm d-inline"
                        data-already-sent="{{ ($assignMailAlreadySent ?? false) ? '1' : '0' }}"
                        data-ship-text="{{ \Carbon\Carbon::parse($shipDate)->format('d-m-y') }}">
                        @csrf
                        <input type="hidden" name="ship_date" value="{{ $shipDate }}">
                        <input type="hidden" name="force_send" class="jsAssignMailForce" value="0">
                        <button type="submit" class="btn btn-sm btn-success">
                            <i class="fas fa-paper-plane me-1"></i> ส่งเมลจัดรถ
                        </button>
                    </form>
                @endif
            </div>

            <div class="row g-3 mb-3">
                <div class="col-6 col-xl-2">
                    <div class="logistics-metric">
                        <div class="metric-label">SO ทั้งหมด</div>
                        <div class="metric-value">{{ $fmtCount($summary['group_count'] ?? 0) }}</div>
                    </div>
                </div>
                <div class="col-6 col-xl-2">
                    <div class="logistics-metric">
                        <div class="metric-label">รายการทั้งหมด</div>
                        <div class="metric-value">{{ $fmtCount($summary['item_count'] ?? 0) }}</div>
                    </div>
                </div>
                <div class="col-6 col-xl-2">
                    <div class="logistics-metric">
                        <div class="metric-label">น้ำหนักรวม</div>
                        <div class="metric-value">{{ $fmtWeight($summary['total_weight'] ?? 0) }}</div>
                    </div>
                </div>
                <div class="col-6 col-xl-2">
                    <div class="logistics-metric">
                        <div class="metric-label">ขึ้นรถแล้ว</div>
                        <div class="metric-value">{{ $fmtWeight($summary['assigned_weight'] ?? 0) }}</div>
                    </div>
                </div>
                <div class="col-6 col-xl-2">
                    <div class="logistics-metric">
                        <div class="metric-label">คงเหลือ</div>
                        <div class="metric-value">{{ $fmtWeight($summary['remaining_weight'] ?? 0) }}</div>
                    </div>
                </div>
                <div class="col-6 col-xl-2">
                    <div class="logistics-metric">
                        <div class="metric-label">งานพิเศษ</div>
                        <div class="metric-value">{{ $fmtCount($summary['special_count'] ?? 0) }}</div>
                    </div>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mb-3">
                @foreach ([
                    'all' => 'ทั้งหมด',
                    'unassigned' => 'ยังไม่จัดรถ ' . $fmtCount($summary['unassigned_count'] ?? 0),
                    'partial' => 'จัดบางส่วน ' . $fmtCount($summary['partial_count'] ?? 0),
                    'completed' => 'จัดครบ ' . $fmtCount($summary['completed_count'] ?? 0),
                    'special' => 'งานพิเศษ ' . $fmtCount($summary['special_count'] ?? 0),
                ] as $key => $label)
                    @php
                        $chipQuery = request()->query();
                        $chipQuery['ship_date'] = $shipDate;
                        $chipQuery['status'] = $key;
                    @endphp
                    <a href="{{ route('dp.dashboard.logistics-summary', $chipQuery) }}"
                        class="btn btn-sm filter-chip {{ $statusFilter === $key ? 'btn-primary' : 'btn-outline-primary' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            <div class="logistics-surface mb-3">
                <div class="workspace-grid">
                    <div class="queue-pane">
                        <div class="p-3 border-bottom">
                            <div class="fw-bold">คิวรอจัดรถ</div>
                            <div class="text-muted small">เลือก SO เพื่อดู summary และจัดรถทางขวา</div>
                        </div>
                        @if ($isSelectedBatchView && $batchCandidates->isNotEmpty())
                            <div class="p-2 border-bottom bg-light jsBatchAddPane">
                                <div class="fw-semibold small mb-1">
                                    <i class="fas fa-plus me-1"></i> เพิ่มรายการเข้ารถคันนี้
                                </div>
                                <input type="search" class="form-control form-control-sm mb-2 jsBatchAddListSearch"
                                    placeholder="ค้นหา SO / MFG / ลูกค้า / Part">
                                <div class="jsBatchAddList" style="max-height:320px; overflow-y:auto;"></div>
                            </div>
                        @endif
                        @if (!$isSelectedBatchView && ($grouped->isNotEmpty() || $specialQueueItems->where('is_open', true)->isNotEmpty()))
                            <div class="p-2 border-bottom bg-light jsQueuePickBar">
                                <div class="d-flex align-items-center justify-content-between gap-2">
                                    <span class="small text-muted">
                                        เลือก <span class="fw-semibold jsQueuePickCount">0</span> รายการเพื่อจัดรถคันเดียวกัน
                                    </span>
                                    <div class="d-flex gap-1">
                                        <button type="button" class="btn btn-outline-secondary btn-sm jsQueuePickClear" disabled>ล้าง</button>
                                        <button type="button" class="btn btn-primary btn-sm jsQueuePickGo" disabled>
                                            <i class="fas fa-truck me-1"></i> จัดพร้อมกัน
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endif
                        @foreach ($grouped as $group)
                            <div class="queue-row d-flex align-items-stretch">
                                @if (!$isSelectedBatchView)
                                    <label class="queue-pick d-flex align-items-center px-2 border-bottom"
                                        title="เลือกเพื่อจัดพร้อมกัน" onclick="event.stopPropagation();">
                                        <input type="checkbox" class="form-check-input m-0 jsQueueBatchPick"
                                            value="{{ (int) $group->ord_id }}">
                                    </label>
                                @endif
                                <button type="button"
                                    class="queue-item jsSummaryPick flex-grow-1 {{ $group->key === $selectedKey ? 'is-active' : '' }}"
                                    data-target="{{ $group->key }}">
                                <div class="d-flex justify-content-between gap-2">
                                    <div>
                                        <div class="queue-title">{{ $group->so_number }}</div>
                                        <div class="queue-meta">{{ $group->mfg_no }}</div>
                                        <div class="queue-meta">
                                            {{ \Carbon\Carbon::parse($group->ship_date)->format('d/m/Y') }}
                                            @if (!empty($group->window_at))
                                                · {{ \Carbon\Carbon::parse($group->window_at)->format('H:i') }}
                                            @endif
                                        </div>
                                    </div>
                                    <span class="badge bg-{{ $statusClass[$group->status] ?? 'secondary' }} align-self-start">
                                        {{ $statusLabel[$group->status] ?? $group->status }}
                                    </span>
                                </div>
                                <div class="queue-meta mt-2">{{ $group->customer_display }}</div>
                                <div class="queue-meta">{{ $group->ship_to_display }}</div>
                                <div class="d-flex justify-content-between mt-2 small">
                                    <span>{{ $fmtCount($group->item_count) }} รายการ</span>
                                    <span class="fw-semibold">{{ $fmtGroupAmount($group, 'remaining') }} คงเหลือ</span>
                                </div>
                                </button>
                            </div>
                        @endforeach
                        @foreach ($specialQueueItems as $special)
                            <div class="queue-row d-flex align-items-stretch">
                                @if (!$isSelectedBatchView && $special->is_open)
                                    <label class="queue-pick d-flex align-items-center px-2"
                                        title="เลือกเพื่อจัดพร้อมกัน" onclick="event.stopPropagation();">
                                        <input type="checkbox" class="form-check-input m-0 jsQueueBatchPick"
                                            value="{{ (int) $special->ord_id }}">
                                    </label>
                                @endif
                                <button type="button"
                                    class="queue-item jsSummaryPick flex-grow-1 {{ $special->key === $selectedKey ? 'is-active' : '' }}"
                                    data-target="{{ $special->key }}">
                                <div class="d-flex justify-content-between gap-2">
                                    <div>
                                        <div class="queue-title">{{ $special->so_number }}</div>
                                        <div class="queue-meta">{{ $special->mfg_no }}</div>
                                    </div>
                                    <span class="badge {{ $special->is_open ? 'bg-warning text-dark' : 'bg-success' }} align-self-start">
                                        {{ $special->dispatch_label }}
                                    </span>
                                </div>
                                <div class="queue-meta mt-2">{{ $special->customer_display }}</div>
                                <div class="queue-meta">{{ $special->ship_to_display }}</div>
                                <div class="d-flex justify-content-between mt-2 small">
                                    <span class="text-warning fw-semibold">
                                        <i class="fas fa-route me-1"></i>งานพิเศษ
                                    </span>
                                    <span class="fw-semibold">{{ $fmtWeight($special->qty) }}</span>
                                </div>
                                </button>
                            </div>
                        @endforeach
                        @if ($grouped->isEmpty() && $specialQueueItems->isEmpty())
                            <div class="p-4 text-center text-muted">ไม่พบข้อมูลตามเงื่อนไข</div>
                        @endif
                    </div>

                    <div class="detail-pane">
                        @foreach ($grouped as $group)
                            @php
                                $isCompleted = $group->status === 'completed';
                                $hasAssignment = (float) ($group->assigned_weight ?? 0) > 0
                                    || !empty($group->assignment);
                                $assignableRows = collect($group->rows)
                                    ->filter(fn($row) => (float) ($row->remaining_weight ?? 0) > 0
                                        || $hasAssignment
                                        || (int) ($row->is_piece_qty ?? 0) === 1)
                                    ->values();
                                $unassignableRows = collect($group->rows)
                                    ->filter(fn($row) => (int) ($row->assign_count ?? 0) > 0
                                        || (float) ($row->assigned_weight_sum ?? 0) > 0)
                                    ->values();
                                $primaryRow = collect($group->rows)->first();
                                $primaryRemaining = $primaryRow
                                    ? (float) ($primaryRow->remaining_weight ?? 0)
                                    : 0;
                                $primaryTotal = $primaryRow ? (float) ($primaryRow->qty ?? 0) : 0;
                                $primaryIsPiece = $primaryRow && (int) ($primaryRow->is_piece_qty ?? 0) === 1;
                                $primaryCanSelect = $primaryRow && ($primaryRemaining > 0 || $hasAssignment || $primaryIsPiece);
                            @endphp
                            @php
                                $regularEditId = 'regularEdit' . md5($group->key);
                            @endphp
                            <div class="dispatch-panel p-3 {{ $group->key === $selectedKey ? 'is-active' : '' }}"
                                data-panel="{{ $group->key }}">
                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                                    <div>
                                        <div class="panel-title h5 mb-1">{{ $group->so_number }}</div>
                                        <div class="fw-semibold small">{{ $group->mfg_no }}</div>
                                        <div class="text-muted small">
                                            {{ $group->customer_display }} · {{ $group->sales_display }}
                                        </div>
                                        <div class="text-muted small">{{ $group->ship_to_display }}</div>
                                    </div>
                                    <div class="d-flex gap-2 flex-wrap">
                                        <a href="{{ route('dp.inquiry', ['ship_from' => $shipDate, 'ship_to' => $shipDate, 'so' => $group->so_number, 'status' => 'ALL', 'searched' => 1]) }}"
                                            class="btn btn-outline-secondary btn-sm" title="ดู SO นี้ในหน้า Inquiry">
                                            <i class="fas fa-list me-1"></i> ดูใน Inquiry
                                        </a>
                                        <a href="{{ route('dp.dashboard.truck-board', ['ship_date' => $shipDate]) }}"
                                            class="btn btn-outline-primary btn-sm">
                                            <i class="fas fa-table me-1"></i> ดูตารางรถวันนี้
                                        </a>
                                        @if ($unassignableRows->isNotEmpty())
                                            <button type="button" class="btn btn-outline-warning btn-sm jsSummaryUnassignTruckBtn"
                                                data-ord-id="{{ (int) ($unassignableRows->first()->ord_id ?? $group->ord_id) }}"
                                                data-ord-ids="{{ e($unassignableRows->pluck('ord_id')->map(fn($id) => (int) $id)->implode(',')) }}"
                                                data-count="{{ $unassignableRows->count() }}"
                                                data-so="{{ e($group->so_number) }}"
                                                data-mfg="{{ e($group->mfg_no) }}"
                                                data-plate="{{ e(optional($group->assignment)->tm_plate_no ?: optional($group->assignment)->manual_plate_no ?: '') }}"
                                                data-return-url="{{ e($selectedReturnUrl($group->key)) }}">
                                                <i class="fas fa-times me-1"></i> ยกเลิกรถ
                                            </button>
                                        @endif
                                    </div>
                                </div>

                                <div class="btn-group btn-group-sm mb-3" role="group">
                                    <input type="radio" class="btn-check jsRegularEditMode"
                                        name="{{ $regularEditId }}_mode" id="{{ $regularEditId }}Master"
                                        value="TRUCK_MASTER" data-target="{{ $regularEditId }}" checked>
                                    <label class="btn btn-outline-success" for="{{ $regularEditId }}Master">
                                        <i class="fas fa-truck me-1"></i> รถในระบบ
                                    </label>
                                    <input type="radio" class="btn-check jsRegularEditMode"
                                        name="{{ $regularEditId }}_mode" id="{{ $regularEditId }}Manual"
                                        value="TRUCK_MANUAL" data-target="{{ $regularEditId }}">
                                    <label class="btn btn-outline-info" for="{{ $regularEditId }}Manual">
                                        <i class="fas fa-truck-loading me-1"></i> รถนอก (กรอกเอง)
                                    </label>
                                    <input type="radio" class="btn-check jsRegularEditMode"
                                        name="{{ $regularEditId }}_mode" id="{{ $regularEditId }}Special"
                                        value="SPECIAL" data-target="{{ $regularEditId }}">
                                    <label class="btn btn-outline-warning" for="{{ $regularEditId }}Special">
                                        <i class="fas fa-route me-1"></i> งานพิเศษ
                                    </label>
                                </div>

                                <form method="POST"
                                    action="{{ route('dp.inquiry.special-dispatch', ['ordId' => 0]) }}"
                                    class="dispatch-box jsRegularSpecialPanel d-none mb-3"
                                    data-regular-panel="{{ $regularEditId }}">
                                    @csrf
                                    <input type="hidden" name="return_url" value="{{ $selectedReturnUrl($group->key) }}">
                                    @foreach ($assignableRows as $row)
                                        <input type="hidden" name="ord_ids[]" value="{{ (int) $row->ord_id }}">
                                    @endforeach
                                    <div class="row g-2 align-items-end">
                                        <div class="col-md-4">
                                            <label class="form-label small mb-1">ประเภท</label>
                                            <select class="form-select form-select-sm" name="dispatch_type" required>
                                                @foreach ($specialDispatchTypes as $type => $label)
                                                    <option value="{{ $type }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small mb-1">หมายเหตุ</label>
                                            <input type="text" class="form-control form-control-sm" name="remark"
                                                maxlength="500" placeholder="หมายเหตุ">
                                        </div>
                                        <div class="col-md-2">
                                            <button type="submit" class="btn btn-warning btn-sm w-100">
                                                <i class="fas fa-route me-1"></i> ทำเป็นพิเศษ
                                            </button>
                                        </div>
                                    </div>
                                    <div class="text-muted small mt-2">
                                        จะใช้ "เลือก" จากตารางด้านล่างเป็นรายการที่ทำเป็นงานพิเศษ
                                    </div>
                                </form>

                                <div class="row g-2 mb-3">
                                    <div class="col-6 col-lg-3">
                                        <div class="border rounded p-2">
                                            <div class="metric-label mb-1">MFG</div>
                                            <div class="fw-bold">{{ $fmtCount($group->mfg_count) }}</div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-lg-3">
                                        <div class="border rounded p-2">
                                            <div class="metric-label mb-1">น้ำหนักรวม</div>
                                            <div class="fw-bold">{{ $fmtGroupAmount($group, 'total') }}</div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-lg-3">
                                        <div class="border rounded p-2">
                                            <div class="metric-label mb-1">ขึ้นรถแล้ว</div>
                                            <div class="fw-bold text-primary">{{ $fmtGroupAmount($group, 'assigned') }}</div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-lg-3">
                                        <div class="border rounded p-2">
                                            <div class="metric-label mb-1">คงเหลือ</div>
                                            <div class="fw-bold text-danger">{{ $fmtGroupAmount($group, 'remaining') }}</div>
                                        </div>
                                    </div>
                                </div>

                                @php
                                    $prefillJson = null;
                                    if (!empty($group->assignment)) {
                                        $a = $group->assignment;
                                        $prefillJson = json_encode([
                                            'truck_source' => strtoupper((string) ($a->truck_source ?? '')),
                                            'truck_id' => (int) ($a->truck_id ?? 0),
                                            'tm_plate_no' => (string) ($a->tm_plate_no ?? ''),
                                            'trip_no' => (int) ($a->trip_no ?? 1),
                                            'manual_plate_no' => (string) ($a->manual_plate_no ?? ''),
                                            'manual_driver_name' => (string) ($a->manual_driver_name ?? ''),
                                            'manual_driver_phone' => (string) ($a->manual_driver_phone ?? ''),
                                            'manual_max_load' => (string) ($a->manual_max_load ?? ''),
                                            'manual_car_length' => (string) ($a->manual_car_length ?? ''),
                                            'manual_remark' => (string) ($a->manual_remark ?? ''),
                                            'driver_staff_id' => (int) ($a->driver_staff_id ?? 0),
                                            'driver_name' => (string) ($a->ta_driver_name ?? ''),
                                            'driver_phone' => (string) ($a->ta_driver_phone ?? ''),
                                            'shipping_phone' => (string) ($a->shipping_phone ?? ''),
                                            'helper1_staff_id' => (int) ($a->helper1_staff_id ?? 0),
                                            'helper2_staff_id' => (int) ($a->helper2_staff_id ?? 0),
                                            'helper3_staff_id' => (int) ($a->helper3_staff_id ?? 0),
                                            'helper4_staff_id' => (int) ($a->helper4_staff_id ?? 0),
                                            'helper5_staff_id' => (int) ($a->helper5_staff_id ?? 0),
                                        ], JSON_UNESCAPED_UNICODE);
                                    }
                                @endphp
                                <form method="POST" action="{{ route('dp.inquiry.truck.assign', ['ordId' => 0]) }}"
                                    class="jsInlineAssignForm jsRegularTruckPanel"
                                    data-regular-panel="{{ $regularEditId }}"
                                    @if ($prefillJson) data-prefill='{{ $prefillJson }}' @endif>
                                    @csrf
                                    <input type="hidden" name="return_url" value="{{ $selectedReturnUrl($group->key) }}">
                                    <input type="hidden" name="so_number" value="{{ $group->so_number }}">
                                    <input type="hidden" name="ship_posted_at" value="{{ $shipDate }}">
                                    <input type="hidden" name="truck_pick_mode" class="jsTruckPickMode" value="MASTER">
                                    <input type="hidden" name="truck_id" class="jsTruckId">
                                    <input type="hidden" name="manual_plate_no" class="jsManualPlate">
                                    <input type="hidden" name="manual_driver_name" class="jsManualDriver">
                                    <input type="hidden" name="manual_driver_phone" class="jsManualPhone">
                                    <input type="hidden" name="manual_max_load" class="jsManualMax">
                                    <input type="hidden" name="manual_car_length" class="jsManualLength">
                                    <input type="hidden" name="manual_remark" class="jsManualRemark">
                                    @if ($isSelectedBatchView)
                                        <div class="dispatch-box mb-3">
                                            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
                                                <div class="fw-semibold small">
                                                    <i class="fas fa-list-check me-1"></i> รายการที่เลือกมาจัดรถคันเดียวกัน
                                                </div>
                                                <div class="d-flex gap-2 align-items-center flex-wrap">
                                                    <span class="badge bg-info text-dark jsBatchSelectedCount">{{ $fmtCount($assignableRows->count()) }} รายการ</span>
                                                    <button type="button" class="btn btn-outline-warning btn-sm jsBatchUnassignSelectedBtn" disabled>
                                                        <i class="fas fa-times me-1"></i> ยกเลิกรถที่เลือก
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-bordered align-middle mb-0 line-table">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th style="width:42px;" class="text-center">เอาออก</th>
                                                            <th style="width:48px;" class="text-center">ยกเลิกรถ</th>
                                                            <th>ลูกค้า</th>
                                                            <th>SO</th>
                                                            <th>MFG</th>
                                                            <th>Part</th>
                                                            <th>สถานที่ส่ง</th>
                                                            <th class="text-end">คงเหลือ</th>
                                                            <th style="width:150px;" class="text-end">จัดครั้งนี้ (kg)</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="jsBatchTbody">
                                                        @foreach ($assignableRows as $row)
                                                            @php
                                                                $rowIsPiece = (int) ($row->is_piece_qty ?? 0) === 1;
                                                                $rowRemaining = (float) ($row->remaining_weight ?? 0);
                                                                $rowCanUnassign = (int) ($row->assign_count ?? 0) > 0
                                                                    || (float) ($row->assigned_weight_sum ?? 0) > 0;
                                                            @endphp
                                                            <tr data-ord-id="{{ (int) $row->ord_id }}">
                                                                <td class="text-center">
                                                                    <input type="hidden" class="jsBatchOrdInput" name="ord_ids[]" value="{{ (int) $row->ord_id }}">
                                                                    <button type="button" class="btn btn-sm btn-outline-danger jsBatchRowRemove" title="เอาออก"><i class="fas fa-times"></i></button>
                                                                </td>
                                                                <td class="text-center">
                                                                    <input type="checkbox" class="form-check-input jsBatchUnassignCheck"
                                                                        value="{{ (int) $row->ord_id }}"
                                                                        @disabled(!$rowCanUnassign)
                                                                        data-so="{{ e($row->so_number ?? '') }}"
                                                                        data-mfg="{{ e($row->mfg_no ?? '') }}"
                                                                        data-plate="{{ e(optional($group->assignment)->tm_plate_no ?: optional($group->assignment)->manual_plate_no ?: '') }}">
                                                                </td>
                                                                <td>{{ $row->customer_name ?: '-' }}</td>
                                                                <td>{{ $row->so_number ?: '-' }}</td>
                                                                <td>{{ $row->mfg_no ?: '-' }}</td>
                                                                <td>
                                                                    <div class="fw-semibold">{{ $row->part_number ?: '-' }}</div>
                                                                    <div class="text-muted small">{{ $row->part_desc ?: '-' }}</div>
                                                                </td>
                                                                <td>{{ $row->address ?: '-' }}</td>
                                                                <td class="text-end fw-semibold">
                                                                    {{ $rowIsPiece ? ($row->line_text ?? '-') : $fmtWeight($rowRemaining) }}
                                                                </td>
                                                                <td class="text-end">
                                                                    @if ($rowIsPiece)
                                                                        <div class="form-control form-control-sm bg-light text-muted text-end">
                                                                            {{ number_format((float) ($row->line_qty_display ?? 0), 0) }} {{ $row->line_qty_unit }}
                                                                        </div>
                                                                        <input type="hidden" name="assign_weight_kg[{{ (int) $row->ord_id }}]" value="0">
                                                                    @else
                                                                        <input type="number"
                                                                            class="form-control form-control-sm text-end jsAssignWeight jsBatchWeight"
                                                                            name="assign_weight_kg[{{ (int) $row->ord_id }}]"
                                                                            min="0" step="1" value="{{ round($rowRemaining) }}"
                                                                            data-remaining="{{ round($rowRemaining) }}"
                                                                            data-total="{{ round((float) ($row->qty ?? 0)) }}">
                                                                    @endif
                                                                </td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                            <div class="form-text small mt-1">
                                                <i class="fas fa-info-circle me-1"></i> ทุกรายการในตารางนี้จะถูกจัดขึ้นรถคันเดียวกัน — ปรับน้ำหนักได้ กด <i class="fas fa-times text-danger"></i> เพื่อเอาออก หรือเพิ่มรายการจากลิสต์ทางซ้าย แล้วเลือกรถด้านล่างกด "จัดรถ" ครั้งเดียว
                                            </div>
                                        </div>
                                    @else
                                        @foreach ($assignableRows as $row)
                                            @php
                                                $rowIsPiece = (int) ($row->is_piece_qty ?? 0) === 1;
                                            @endphp
                                            <input type="hidden" name="ord_ids[]" value="{{ (int) $row->ord_id }}">
                                            @if ($rowIsPiece)
                                                <input type="hidden" name="assign_weight_kg[{{ (int) $row->ord_id }}]" value="0">
                                            @endif
                                        @endforeach
                                    @endif

                                    <div class="dispatch-box">
                                    <div class="jsTruckMasterBox mb-2">
                                        <label class="form-label small mb-1">รถในระบบ</label>
                                        <select class="form-select form-select-sm jsTruckSelect" required>
                                            <option value="">เลือกรถ</option>
                                            @if ($masterTrucks->isNotEmpty())
                                                <optgroup label="🚚 รถในระบบ">
                                                    @foreach ($masterTrucks as $truck)
                                                        <option value="{{ $truck->row_key }}"
                                                            data-pick-type="{{ $truck->truck_pick_type }}"
                                                            data-truck-id="{{ $truck->truck_id }}"
                                                            data-plate="{{ e($truck->plate_no) }}"
                                                            data-driver="{{ e($truck->driver_name) }}"
                                                            data-phone="{{ e($truck->driver_phone) }}"
                                                            data-max-load="{{ $truck->max_load }}"
                                                            data-length="{{ $truck->car_length }}"
                                                            data-remark="{{ e($truck->remark) }}">
                                                            {{ $truck->plate_no ?: '-' }}
                                                            · เหลือ {{ $truck->capacity_unlimited ? 'ไม่จำกัด' : $fmtWeight($truck->remaining_capacity) }}
                                                        </option>
                                                    @endforeach
                                                </optgroup>
                                            @endif
                                            @if ($externalTrucks->isNotEmpty())
                                                <optgroup label="📦 รถนอก (ที่ใช้ในวันนี้)">
                                                    @foreach ($externalTrucks as $truck)
                                                        <option value="{{ $truck->row_key }}"
                                                            data-pick-type="{{ $truck->truck_pick_type }}"
                                                            data-truck-id="{{ $truck->truck_id }}"
                                                            data-plate="{{ e($truck->plate_no) }}"
                                                            data-driver="{{ e($truck->driver_name) }}"
                                                            data-phone="{{ e($truck->driver_phone) }}"
                                                            data-max-load="{{ $truck->max_load }}"
                                                            data-length="{{ $truck->car_length }}"
                                                            data-remark="{{ e($truck->remark) }}">
                                                            [รถนอก] {{ $truck->plate_no ?: '-' }}
                                                            · เหลือ {{ $truck->capacity_unlimited ? 'ไม่จำกัด' : $fmtWeight($truck->remaining_capacity) }}
                                                        </option>
                                                    @endforeach
                                                </optgroup>
                                            @endif
                                        </select>
                                        <div class="truck-option-help small text-muted mt-1 jsTruckHelp"></div>
                                    </div>

                                    <div class="jsTruckManualBox d-none mb-2 p-2 border rounded bg-info-subtle">
                                        <div class="fw-semibold small mb-2"><i class="fas fa-truck-loading me-1"></i> รถนอก (กรอกเอง)</div>
                                        <div class="row g-2">
                                            <div class="col-md-6 col-lg-3">
                                                <label class="form-label small mb-1">ทะเบียนรถ <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control form-control-sm jsManualPlateInput jsPlateAutocomplete"
                                                    autocomplete="off" maxlength="50" placeholder="ค้นหาทะเบียนเดิม หรือพิมพ์ใหม่">
                                            </div>
                                            <div class="col-md-6 col-lg-3">
                                                <label class="form-label small mb-1">Max Load (kg)</label>
                                                <input type="number" step="1" class="form-control form-control-sm jsManualMaxInput" placeholder="เช่น 25000">
                                            </div>
                                            <div class="col-md-6 col-lg-3">
                                                <label class="form-label small mb-1">ความยาว</label>
                                                <input type="number" step="1" class="form-control form-control-sm jsManualLengthInput">
                                            </div>
                                            <div class="col-md-6 col-lg-3">
                                                <label class="form-label small mb-1">หมายเหตุ</label>
                                                <input type="text" class="form-control form-control-sm jsManualRemarkInput" maxlength="200" placeholder="เช่น เปิดข้าง / ตู้">
                                            </div>
                                        </div>
                                        <div class="form-text small mt-1">
                                            <i class="fas fa-info-circle me-1"></i> คนขับและเบอร์โทรกรอกที่แถวด้านล่าง (ใช้ร่วมกับรถในระบบ)
                                        </div>
                                    </div>

                                    <div class="row g-2 align-items-end">
                                        <div class="col-xl-2 col-lg-3">
                                            <label class="form-label small mb-1">เที่ยว</label>
                                            <input type="number" class="form-control form-control-sm" name="trip_no"
                                                min="1" max="99" value="1">
                                        </div>
                                        <div class="col-xl-2 col-lg-3">
                                            <label class="form-label small mb-1">คนขับ (ชื่อ)</label>
                                            <input type="text" class="form-control form-control-sm jsDriverNameInput jsDriverAutocomplete"
                                                name="driver_name_input" maxlength="100"
                                                data-ac-field="name"
                                                autocomplete="off"
                                                placeholder="ชื่อคนขับ">
                                        </div>
                                        <div class="col-xl-2 col-lg-3">
                                            <label class="form-label small mb-1">เบอร์โทร</label>
                                            <input type="text" class="form-control form-control-sm jsDriverPhoneInput jsDriverAutocomplete"
                                                name="driver_phone_input" maxlength="50"
                                                data-ac-field="phone"
                                                autocomplete="off"
                                                placeholder="เบอร์โทร">
                                        </div>
                                        @if (!$isSelectedBatchView)
                                        <div class="col-xl-3 col-lg-3">
                                            <label class="form-label small mb-1">จัดครั้งนี้</label>
                                            @if ($primaryIsPiece && $primaryRow)
                                                <div class="form-control form-control-sm bg-light">
                                                    {{ number_format((float) ($primaryRow->line_qty_display ?? 0), 0) }} {{ $primaryRow->line_qty_unit }}
                                                </div>
                                            @elseif ($primaryCanSelect && $primaryRow)
                                                <input type="number"
                                                    class="form-control form-control-sm text-end jsAssignWeight"
                                                    name="assign_weight_kg[{{ (int) $primaryRow->ord_id }}]"
                                                    min="0" step="1" value="{{ round($primaryRemaining) }}"
                                                    data-remaining="{{ round($primaryRemaining) }}"
                                                    data-total="{{ round($primaryTotal) }}">
                                            @else
                                                <div class="form-control form-control-sm bg-light text-muted">0 kg</div>
                                            @endif
                                        </div>
                                        @endif
                                        <div class="col-xl-3 col-lg-2">
                                            <div class="dispatch-actions">
                                                <button type="submit" class="btn btn-success btn-sm flex-fill"
                                                    @disabled($assignableRows->isEmpty())>
                                                    <i class="fas fa-truck me-1"></i>
                                                    {{ $isCompleted ? 'เปลี่ยนรถ' : 'จัดรถ' }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row g-2 mt-1">
                                        <div class="col-xl-3 col-lg-4 col-md-6">
                                            <label class="form-label small mb-1">เบอร์โทร Shipping</label>
                                            <input type="text"
                                                class="form-control form-control-sm jsShippingPhoneInput"
                                                name="shipping_phone" maxlength="50" autocomplete="off"
                                                placeholder="เบอร์โทร Shipping">
                                        </div>
                                    </div>

                                    <div class="row g-2 mt-1">
                                        @for ($helperNo = 1; $helperNo <= 5; $helperNo++)
                                            <div class="col-xl col-md-4 col-6">
                                                <label class="form-label small mb-1">เด็กรถ {{ $helperNo }}</label>
                                                <select class="form-select form-select-sm jsStaffSelect"
                                                    name="helper{{ $helperNo }}_staff_id"
                                                    data-placeholder="-- เลือกเด็กรถ {{ $helperNo }} --">
                                                    <option value="">-- เลือกเด็กรถ {{ $helperNo }} --</option>
                                                </select>
                                            </div>
                                        @endfor
                                    </div>

                                    @if ($hasAssignment)
                                        <div class="form-check mt-2">
                                            <input class="form-check-input jsReplaceExisting" type="checkbox" value="1"
                                                name="replace_mode" id="replace{{ $loop->index }}">
                                            <label class="form-check-label small" for="replace{{ $loop->index }}">
                                                บันทึกทับรถเดิมของ MFG นี้ (ลบรายการรถเดิม แล้วบันทึกใหม่)
                                            </label>
                                            <div class="form-text text-muted small ms-4">
                                                ไม่ติ๊ก = จัดเพิ่มเฉพาะยอดคงเหลือ {{ $fmtWeight($group->remaining_weight) }}
                                            </div>
                                        </div>
                                    @endif
                                    </div>
                                </form>

                            </div>
                        @endforeach

                        @foreach ($specialQueueItems as $special)
                            @php
                                $row = $special->row;
                                $specialEditId = 'specialEdit' . $special->ord_id;
                            @endphp
                            <div class="dispatch-panel p-3 {{ $special->key === $selectedKey ? 'is-active' : '' }}"
                                data-panel="{{ $special->key }}">
                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                                    <div>
                                        <div class="panel-title h5 mb-1">
                                            {{ $special->so_number }}
                                            <span class="badge bg-warning text-dark ms-2">งานพิเศษ</span>
                                        </div>
                                        <div class="text-muted small">
                                            {{ $special->customer_display }} · MFG {{ $special->mfg_no }}
                                        </div>
                                        <div class="text-muted small">{{ $special->ship_to_display }}</div>
                                    </div>
                                    <div class="d-flex gap-2 flex-wrap align-self-start">
                                        <a href="{{ route('dp.inquiry', ['ship_from' => $shipDate, 'ship_to' => $shipDate, 'ord_id' => $special->ord_id, 'status' => 'ALL', 'searched' => 1]) }}"
                                            class="btn btn-outline-secondary btn-sm" title="ดูรายการนี้ใน Inquiry">
                                            <i class="fas fa-list me-1"></i> ดูใน Inquiry
                                        </a>
                                        <span class="badge {{ $special->is_open ? 'bg-warning text-dark' : 'bg-success' }} align-self-center">
                                            {{ $special->is_open ? 'เปิดอยู่' : 'ปิดแล้ว' }}
                                        </span>
                                    </div>
                                </div>

                                @if ($special->is_open)
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <input type="radio" class="btn-check jsSpecialEditMode"
                                                name="{{ $specialEditId }}_mode" id="{{ $specialEditId }}Master"
                                                value="TRUCK_MASTER" data-target="{{ $specialEditId }}">
                                            <label class="btn btn-outline-success" for="{{ $specialEditId }}Master">
                                                <i class="fas fa-truck me-1"></i> รถในระบบ
                                            </label>
                                            <input type="radio" class="btn-check jsSpecialEditMode"
                                                name="{{ $specialEditId }}_mode" id="{{ $specialEditId }}Manual"
                                                value="TRUCK_MANUAL" data-target="{{ $specialEditId }}">
                                            <label class="btn btn-outline-info" for="{{ $specialEditId }}Manual">
                                                <i class="fas fa-truck-loading me-1"></i> รถนอก (กรอกเอง)
                                            </label>
                                            <input type="radio" class="btn-check jsSpecialEditMode"
                                                name="{{ $specialEditId }}_mode" id="{{ $specialEditId }}Special"
                                                value="SPECIAL" data-target="{{ $specialEditId }}" checked>
                                            <label class="btn btn-outline-warning" for="{{ $specialEditId }}Special">
                                                <i class="fas fa-route me-1"></i> งานพิเศษ
                                            </label>
                                        </div>
                                        <span class="text-muted small">น้ำหนัก {{ $fmtWeight($special->qty) }}</span>
                                    </div>

                                    <form method="POST"
                                        action="{{ route('dp.inquiry.truck.assign', ['ordId' => $special->ord_id]) }}"
                                        class="dispatch-box jsInlineAssignForm jsSpecialTruckPanel d-none mb-3"
                                        data-special-panel="{{ $specialEditId }}">
                                        @csrf
                                        <input type="hidden" name="return_url" value="{{ $selectedReturnUrl($special->key) }}">
                                        <input type="hidden" name="so_number" value="{{ $special->so_number }}">
                                        <input type="hidden" name="ship_posted_at" value="{{ $shipDate }}">
                                        <input type="hidden" name="truck_pick_mode" class="jsTruckPickMode" value="MASTER">
                                        <input type="hidden" name="truck_id" class="jsTruckId">
                                        <input type="hidden" name="manual_plate_no" class="jsManualPlate">
                                        <input type="hidden" name="manual_driver_name" class="jsManualDriver">
                                        <input type="hidden" name="manual_driver_phone" class="jsManualPhone">
                                        <input type="hidden" name="manual_max_load" class="jsManualMax">
                                        <input type="hidden" name="manual_car_length" class="jsManualLength">
                                        <input type="hidden" name="manual_remark" class="jsManualRemark">
                                        <input type="hidden" name="ord_ids[]" value="{{ $special->ord_id }}">
                                        <input type="hidden" name="assign_weight_kg[{{ $special->ord_id }}]" value="{{ round($special->qty) }}">

                                        <div class="jsTruckMasterBox mb-2">
                                            <label class="form-label small mb-1">รถในระบบ</label>
                                            <select class="form-select form-select-sm jsTruckSelect" required>
                                                <option value="">เลือกรถ</option>
                                                @if ($masterTrucks->isNotEmpty())
                                                    <optgroup label="🚚 รถในระบบ">
                                                        @foreach ($masterTrucks as $truck)
                                                            <option value="{{ $truck->row_key }}"
                                                                data-pick-type="{{ $truck->truck_pick_type }}"
                                                                data-truck-id="{{ $truck->truck_id }}"
                                                                data-plate="{{ e($truck->plate_no) }}"
                                                                data-driver="{{ e($truck->driver_name) }}"
                                                                data-phone="{{ e($truck->driver_phone) }}"
                                                                data-max-load="{{ $truck->max_load }}"
                                                                data-length="{{ $truck->car_length }}"
                                                                data-remark="{{ e($truck->remark) }}">
                                                                {{ $truck->plate_no ?: '-' }} · เหลือ {{ $truck->capacity_unlimited ? 'ไม่จำกัด' : $fmtWeight($truck->remaining_capacity) }}
                                                            </option>
                                                        @endforeach
                                                    </optgroup>
                                                @endif
                                                @if ($externalTrucks->isNotEmpty())
                                                    <optgroup label="📦 รถนอก (ที่ใช้ในวันนี้)">
                                                        @foreach ($externalTrucks as $truck)
                                                            <option value="{{ $truck->row_key }}"
                                                                data-pick-type="{{ $truck->truck_pick_type }}"
                                                                data-truck-id="{{ $truck->truck_id }}"
                                                                data-plate="{{ e($truck->plate_no) }}"
                                                                data-driver="{{ e($truck->driver_name) }}"
                                                                data-phone="{{ e($truck->driver_phone) }}"
                                                                data-max-load="{{ $truck->max_load }}"
                                                                data-length="{{ $truck->car_length }}"
                                                                data-remark="{{ e($truck->remark) }}">
                                                                [รถนอก] {{ $truck->plate_no ?: '-' }} · เหลือ {{ $truck->capacity_unlimited ? 'ไม่จำกัด' : $fmtWeight($truck->remaining_capacity) }}
                                                            </option>
                                                        @endforeach
                                                    </optgroup>
                                                @endif
                                            </select>
                                            <div class="truck-option-help small text-muted mt-1 jsTruckHelp"></div>
                                        </div>

                                        <div class="jsTruckManualBox d-none mb-2 p-2 border rounded bg-info-subtle">
                                            <div class="fw-semibold small mb-2"><i class="fas fa-truck-loading me-1"></i> รถนอก (กรอกเอง)</div>
                                            <div class="row g-2">
                                                <div class="col-md-6 col-lg-3">
                                                    <label class="form-label small mb-1">ทะเบียนรถ <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control form-control-sm jsManualPlateInput jsPlateAutocomplete"
                                                        autocomplete="off" maxlength="50" placeholder="ค้นหาทะเบียนเดิม หรือพิมพ์ใหม่">
                                                </div>
                                                <div class="col-md-6 col-lg-3">
                                                    <label class="form-label small mb-1">Max Load (kg)</label>
                                                    <input type="number" step="1" class="form-control form-control-sm jsManualMaxInput" placeholder="เช่น 25000">
                                                </div>
                                                <div class="col-md-6 col-lg-3">
                                                    <label class="form-label small mb-1">ความยาว</label>
                                                    <input type="number" step="1" class="form-control form-control-sm jsManualLengthInput">
                                                </div>
                                                <div class="col-md-6 col-lg-3">
                                                    <label class="form-label small mb-1">หมายเหตุ</label>
                                                    <input type="text" class="form-control form-control-sm jsManualRemarkInput" maxlength="200" placeholder="เช่น เปิดข้าง / ตู้">
                                                </div>
                                            </div>
                                            <div class="form-text small mt-1">
                                                <i class="fas fa-info-circle me-1"></i> คนขับและเบอร์โทรกรอกที่แถวด้านล่าง (ใช้ร่วมกับรถในระบบ)
                                            </div>
                                        </div>

                                        <div class="row g-2 align-items-end">
                                            <div class="col-xl-2 col-lg-3">
                                                <label class="form-label small mb-1">เที่ยว</label>
                                                <input type="number" class="form-control form-control-sm"
                                                    name="trip_no" min="1" max="99" value="1">
                                            </div>
                                            <div class="col-xl-3 col-lg-4">
                                                <label class="form-label small mb-1">คนขับ (ชื่อ)</label>
                                                <input type="text" class="form-control form-control-sm jsDriverNameInput jsDriverAutocomplete"
                                                    name="driver_name_input" maxlength="100"
                                                    data-ac-field="name"
                                                    autocomplete="off"
                                                    placeholder="ชื่อคนขับ">
                                            </div>
                                            <div class="col-xl-3 col-lg-3">
                                                <label class="form-label small mb-1">เบอร์โทร</label>
                                                <input type="text" class="form-control form-control-sm jsDriverPhoneInput jsDriverAutocomplete"
                                                    name="driver_phone_input" maxlength="50"
                                                    data-ac-field="phone"
                                                    autocomplete="off"
                                                    placeholder="เบอร์โทร">
                                            </div>
                                            <div class="col-xl-4 col-lg-2">
                                                <div class="dispatch-actions">
                                                    <button type="submit" class="btn btn-success btn-sm flex-fill">
                                                        <i class="fas fa-truck me-1"></i> จัดรถ
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row g-2 mt-1">
                                            <div class="col-xl-3 col-lg-4 col-md-6">
                                                <label class="form-label small mb-1">เบอร์โทร Shipping</label>
                                                <input type="text"
                                                    class="form-control form-control-sm jsShippingPhoneInput"
                                                    name="shipping_phone" maxlength="50" autocomplete="off"
                                                    placeholder="เบอร์โทร Shipping">
                                            </div>
                                        </div>
                                        <div class="row g-2 mt-1">
                                            @for ($helperNo = 1; $helperNo <= 5; $helperNo++)
                                                <div class="col-xl col-md-4 col-6">
                                                    <label class="form-label small mb-1">เด็กรถ {{ $helperNo }}</label>
                                                    <select class="form-select form-select-sm jsStaffSelect"
                                                        name="helper{{ $helperNo }}_staff_id"
                                                        data-placeholder="-- เลือกเด็กรถ {{ $helperNo }} --">
                                                        <option value="">-- เลือกเด็กรถ {{ $helperNo }} --</option>
                                                    </select>
                                                </div>
                                            @endfor
                                        </div>
                                    </form>

                                    <form method="POST"
                                        action="{{ route('dp.inquiry.special-dispatch', ['ordId' => $special->ord_id]) }}"
                                        class="dispatch-box jsSpecialKeepPanel"
                                        data-special-panel="{{ $specialEditId }}">
                                        @csrf
                                        <input type="hidden" name="return_url" value="{{ $selectedReturnUrl($special->key) }}">
                                        <div class="row g-2 align-items-end">
                                            <div class="col-md-5">
                                                <label class="form-label small mb-1">ประเภท</label>
                                                <select class="form-select form-select-sm" name="dispatch_type" required>
                                                    @foreach ($specialDispatchTypes as $type => $label)
                                                        <option value="{{ $type }}" @selected($special->dispatch_type === $type)>
                                                            {{ $label }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-md-5">
                                                <label class="form-label small mb-1">หมายเหตุ</label>
                                                <input type="text" class="form-control form-control-sm" name="remark"
                                                    maxlength="500" value="{{ $special->special_remark }}"
                                                    placeholder="หมายเหตุ">
                                            </div>
                                            <div class="col-md-2">
                                                <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                                                    แก้ไข
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                    <form method="POST"
                                        action="{{ route('dp.inquiry.special-dispatch.close', ['ordId' => $special->ord_id]) }}"
                                        class="d-flex gap-2 mt-2">
                                        @csrf
                                        <input type="hidden" name="return_url" value="{{ $selectedReturnUrl($special->key) }}">
                                        <input type="text" class="form-control form-control-sm" name="close_remark"
                                            maxlength="500" placeholder="หมายเหตุปิดงาน">
                                        <button type="submit" class="btn btn-success btn-sm">
                                            ปิดงาน
                                        </button>
                                    </form>
                                @else
                                    <div class="text-muted small">
                                        {{ $special->dispatch_label ?: '-' }}
                                        @if (!empty($special->special_remark))
                                            · {{ $special->special_remark }}
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach

                        @if ($grouped->isEmpty() && $specialQueueItems->isEmpty())
                            <div class="p-4 text-center text-muted">เลือกวันที่หรือ filter ใหม่เพื่อดูรายการ</div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="logistics-special p-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <div>
                        <div class="fw-bold">งานพิเศษที่ปิดแล้ว</div>
                        <div class="text-muted small">รายการของวันส่งนี้ — กด "เปิดงานกลับ" หากต้องการกลับมาแก้ไข</div>
                    </div>
                    <span class="badge bg-secondary">{{ $fmtCount($specialClosedItems->count()) }} รายการ</span>
                </div>

                @if ($specialClosedItems->isEmpty())
                    <div class="text-muted py-3">ยังไม่มีงานพิเศษที่ปิดของวันที่นี้</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>SO</th>
                                    <th>MFG</th>
                                    <th>ลูกค้า / สถานที่ส่ง</th>
                                    <th>ประเภท</th>
                                    <th>หมายเหตุงาน</th>
                                    <th>ปิดเมื่อ</th>
                                    <th>หมายเหตุปิด</th>
                                    <th class="text-center" style="width:120px;">การจัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($specialClosedItems as $closed)
                                    <tr>
                                        <td>{{ $closed->so_number }}</td>
                                        <td>{{ $closed->mfg_no }}</td>
                                        <td>
                                            <div>{{ $closed->customer_display }}</div>
                                            <div class="text-muted small">{{ $closed->ship_to_display }}</div>
                                        </td>
                                        <td>
                                            <span class="badge bg-warning text-dark">{{ $closed->dispatch_label }}</span>
                                        </td>
                                        <td class="text-muted small">{{ $closed->special_remark ?: '-' }}</td>
                                        <td class="text-muted small">
                                            @if ($closed->closed_at)
                                                {{ \Carbon\Carbon::parse($closed->closed_at)->format('d/m/Y H:i') }}
                                                @if ($closed->closed_by_name)
                                                    <div>โดย {{ $closed->closed_by_name }}</div>
                                                @endif
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td class="text-muted small">{{ $closed->close_remark ?: '-' }}</td>
                                        <td class="text-center">
                                            <form method="POST"
                                                action="{{ route('dp.inquiry.special-dispatch.reopen', ['ordId' => $closed->ord_id]) }}"
                                                class="jsConfirmReopenSpecial">
                                                @csrf
                                                <input type="hidden" name="return_url" value="{{ $selectedReturnUrl('special-' . (int) $closed->ord_id) }}">
                                                <button type="submit" class="btn btn-sm btn-outline-warning">
                                                    <i class="fas fa-undo me-1"></i> เปิดงานกลับ
                                                </button>
                                            </form>
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

    <div class="modal fade" id="manualTruckModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">รถนอก</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="manualTruckPlate">ทะเบียน</label>
                            <input type="text" class="form-control form-control-sm" id="manualTruckPlate" maxlength="50">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="manualTruckDriver">คนขับ</label>
                            <input type="text" class="form-control form-control-sm" id="manualTruckDriver" maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="manualTruckPhone">โทร</label>
                            <input type="text" class="form-control form-control-sm" id="manualTruckPhone" maxlength="50">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1" for="manualTruckMax">Max kg</label>
                            <input type="number" class="form-control form-control-sm" id="manualTruckMax" min="0" step="1">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1" for="manualTruckLength">ความยาว</label>
                            <input type="number" class="form-control form-control-sm" id="manualTruckLength" min="0" step="0.01">
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-1" for="manualTruckRemark">หมายเหตุรถ</label>
                            <input type="text" class="form-control form-control-sm" id="manualTruckRemark" maxlength="255">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="button" class="btn btn-primary btn-sm" id="saveManualTruckBtn">
                        <i class="fas fa-check me-1"></i> ใช้รถนอก
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="summaryUnassignTruckModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="POST" id="summaryUnassignTruckForm">
                @csrf
                <input type="hidden" name="return_url" id="summaryUnassignReturnUrl" value="{{ url()->full() }}">
                <div id="summaryUnassignOrdIds"></div>
                <div class="modal-header">
                    <h5 class="modal-title">ยกเลิกรถ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <div class="small text-muted">รายการ</div>
                        <div class="fw-semibold" id="summaryUnassignTruckInfo">-</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold" for="summaryRemarkUnassign">เหตุผลยกเลิกรถ</label>
                        <textarea class="form-control" name="remark_unassign" id="summaryRemarkUnassign" rows="3"
                            placeholder="กรอกเหตุผล" required></textarea>
                    </div>
                    <div class="alert alert-warning small mb-0">
                        ระบบจะลบข้อมูลรถของ MFG นี้ และเปลี่ยนสถานะกลับเป็น NEW
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">ปิด</button>
                    <button type="submit" class="btn btn-warning btn-sm">ยืนยันยกเลิกรถ</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const STAFF_ROUTES = {
                options: @json(route('dp.truck.staff.options')),
                defaults: @json(route('dp.truck.staff.defaults')),
            };
            const UNASSIGN_ROUTE = @json(route('dp.inquiry.truck.unassign', ['ordId' => '__ID__']));
            const BULK_UNASSIGN_ROUTE = @json(route('dp.inquiry.truck.unassign.bulk'));
            const pickers = Array.from(document.querySelectorAll('.jsSummaryPick'));
            const panels = Array.from(document.querySelectorAll('.dispatch-panel'));
            const manualModalEl = document.getElementById('manualTruckModal');
            const manualModal = manualModalEl && window.bootstrap ? new bootstrap.Modal(manualModalEl) : null;
            const unassignModalEl = document.getElementById('summaryUnassignTruckModal');
            const unassignModal = unassignModalEl && window.bootstrap ? new bootstrap.Modal(unassignModalEl) : null;
            const unassignForm = document.getElementById('summaryUnassignTruckForm');
            const unassignInfo = document.getElementById('summaryUnassignTruckInfo');
            const unassignRemark = document.getElementById('summaryRemarkUnassign');
            const unassignReturnUrl = document.getElementById('summaryUnassignReturnUrl');
            const unassignOrdIds = document.getElementById('summaryUnassignOrdIds');
            let activeManualForm = null;
            let staffOptionsPromise = null;
            const scrollStorageKey = 'dp.logisticsSummary.scrollY';

            function saveScrollPosition() {
                try {
                    sessionStorage.setItem(scrollStorageKey, String(window.scrollY || 0));
                } catch (_) {
                    // Ignore storage failures; the submit flow should still continue.
                }
            }

            function restoreScrollPosition(scrollY) {
                const y = Number(scrollY || 0);
                if (!Number.isFinite(y) || y <= 0) return;
                window.scrollTo({ top: y, left: 0, behavior: 'auto' });
            }

            function restoreSavedScrollPosition() {
                let saved = '';
                try {
                    saved = sessionStorage.getItem(scrollStorageKey) || '';
                    sessionStorage.removeItem(scrollStorageKey);
                } catch (_) {
                    saved = '';
                }

                if (saved !== '') {
                    setTimeout(() => restoreScrollPosition(saved), 80);
                }
            }

            function warn(message, title = 'ตรวจสอบข้อมูล') {
                const scrollY = window.scrollY || 0;
                if (window.Swal && typeof window.Swal.fire === 'function') {
                    window.Swal.fire({
                        icon: 'warning',
                        title,
                        text: message,
                        confirmButtonText: 'ตกลง',
                        heightAuto: false,
                        returnFocus: false,
                        scrollbarPadding: false,
                        didOpen: () => restoreScrollPosition(scrollY),
                        didClose: () => restoreScrollPosition(scrollY),
                    });
                    return;
                }

                alert(message);
            }

            function confirmAction(message, title = 'ยืนยันรายการ') {
                const scrollY = window.scrollY || 0;
                if (window.Swal && typeof window.Swal.fire === 'function') {
                    return window.Swal.fire({
                        icon: 'question',
                        title,
                        text: message,
                        showCancelButton: true,
                        confirmButtonText: 'ยืนยัน',
                        cancelButtonText: 'ยกเลิก',
                        reverseButtons: true,
                        heightAuto: false,
                        returnFocus: false,
                        scrollbarPadding: false,
                        didOpen: () => restoreScrollPosition(scrollY),
                    }).then((result) => {
                        if (!result.isConfirmed) {
                            restoreScrollPosition(scrollY);
                        }
                        return !!result.isConfirmed;
                    });
                }

                return Promise.resolve(confirm(message));
            }

            restoreSavedScrollPosition();

            document.addEventListener('submit', function (ev) {
                const form = ev.target;
                if (!(form instanceof HTMLFormElement)) return;
                if (!form.matches('#summaryUnassignTruckForm, .jsInlineAssignForm, .jsSpecialKeepPanel')) return;

                setTimeout(() => {
                    if (!ev.defaultPrevented) {
                        saveScrollPosition();
                    }
                }, 0);
            }, true);

            function activatePanel(key) {
                pickers.forEach((btn) => btn.classList.toggle('is-active', btn.dataset.target === key));
                panels.forEach((panel) => panel.classList.toggle('is-active', panel.dataset.panel === key));
            }

            pickers.forEach((btn) => {
                btn.addEventListener('click', function () {
                    activatePanel(this.dataset.target);
                });
            });

            function openUnassignModal(items, returnUrl) {
                if (!unassignModal || !unassignForm) return;
                const cleanItems = (items || []).filter((item) => item && item.ordId);
                if (!cleanItems.length) {
                    warn('กรุณาเลือกรายการที่ต้องการยกเลิกรถ');
                    return;
                }

                if (unassignOrdIds) unassignOrdIds.innerHTML = '';
                if (cleanItems.length === 1) {
                    unassignForm.action = UNASSIGN_ROUTE.replace('__ID__', encodeURIComponent(cleanItems[0].ordId));
                } else {
                    unassignForm.action = BULK_UNASSIGN_ROUTE;
                    cleanItems.forEach((item) => {
                        const hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'ord_ids[]';
                        hidden.value = item.ordId;
                        unassignOrdIds?.appendChild(hidden);
                    });
                }

                if (unassignReturnUrl) {
                    unassignReturnUrl.value = returnUrl || window.location.href;
                }
                if (unassignInfo) {
                    if (cleanItems.length === 1) {
                        const item = cleanItems[0];
                        unassignInfo.textContent = [
                            item.so ? 'SO: ' + item.so : '',
                            item.mfg ? 'MFG: ' + item.mfg : '',
                            item.plate ? 'ทะเบียน: ' + item.plate : '',
                        ].filter(Boolean).join(' | ') || '-';
                    } else {
                        const preview = cleanItems
                            .slice(0, 4)
                            .map((item) => item.mfg || item.so || ('ord_id=' + item.ordId))
                            .join(', ');
                        unassignInfo.textContent = cleanItems.length + ' รายการ' + (preview ? ' | ' + preview : '');
                    }
                }
                if (unassignRemark) unassignRemark.value = '';
                unassignModal.show();
            }

            document.querySelectorAll('.jsSummaryUnassignTruckBtn').forEach((btn) => {
                btn.addEventListener('click', function () {
                    const ordIds = String(this.dataset.ordIds || this.dataset.ordId || '')
                        .split(',')
                        .map((id) => id.trim())
                        .filter(Boolean);
                    const items = ordIds.map((ordId) => ({
                        ordId,
                        so: this.dataset.so || '',
                        mfg: ordIds.length === 1 ? (this.dataset.mfg || '') : '',
                        plate: this.dataset.plate || '',
                    }));
                    openUnassignModal(items, this.dataset.returnUrl || window.location.href);
                });
            });

            document.querySelectorAll('.jsConfirmReopenSpecial').forEach((form) => {
                form.addEventListener('submit', function (ev) {
                    if (this.dataset.confirmedSubmit === '1') {
                        delete this.dataset.confirmedSubmit;
                        return;
                    }

                    ev.preventDefault();
                    confirmAction('ยืนยันเปิดงานพิเศษนี้กลับ?').then((confirmed) => {
                        if (!confirmed) return;
                        saveScrollPosition();
                        this.dataset.confirmedSubmit = '1';
                        this.submit();
                    });
                });
            });

            function staffSelects(form) {
                return Array.from(form.querySelectorAll('.jsStaffSelect'));
            }

            function loadStaffOptions() {
                if (staffOptionsPromise) return staffOptionsPromise;
                staffOptionsPromise = fetch(STAFF_ROUTES.options, {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                })
                    .then((res) => res.ok ? res.json() : [])
                    .catch(() => [])
                    .finally(() => {
                        staffOptionsPromise = null;
                    });
                return staffOptionsPromise;
            }

            function fillStaffOptions(form) {
                return loadStaffOptions().then((data) => {
                    const drivers = Array.isArray(data?.drivers) ? data.drivers : [];
                    const helpers = Array.isArray(data?.helpers) ? data.helpers : [];
                    staffSelects(form).forEach((selectEl) => {
                        const current = selectEl.value;
                        const placeholder = selectEl.dataset.placeholder || '-- เลือก --';
                        const items = selectEl.classList.contains('jsDriverStaff') ? drivers : helpers;
                        selectEl.innerHTML = '';
                        selectEl.appendChild(new Option(placeholder, ''));
                        items.forEach((staff) => {
                            const label = staff.name || staff.text || staff.full_name || `Staff #${staff.id}`;
                            selectEl.appendChild(new Option(label, staff.id));
                        });
                        if (current) selectEl.value = current;
                    });
                });
            }

            function setStaffValue(selectEl, staff) {
                if (!selectEl || !staff || !staff.id) return;
                const id = String(staff.id);
                if (!Array.from(selectEl.options).some((option) => option.value === id)) {
                    selectEl.appendChild(new Option(staff.name || `Staff #${id}`, id));
                }
                selectEl.value = id;
            }

            function applyStaffDefaults(form, params) {
                if (!STAFF_ROUTES.defaults) return;
                const q = new URLSearchParams(params);
                fetch(`${STAFF_ROUTES.defaults}?${q.toString()}`, {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                })
                    .then((res) => res.ok ? res.json() : null)
                    .then((data) => {
                        if (!data) return;
                        // คนขับ: เติมเป็น text input (ถ้ายังไม่มีค่า) — driver มาจาก last-used ของรถ/ทะเบียนนี้
                        const driverNameInput = form.querySelector('.jsDriverNameInput');
                        const driverPhoneInput = form.querySelector('.jsDriverPhoneInput');
                        if (data.driver) {
                            if (driverNameInput && !driverNameInput.value.trim() && data.driver.name) {
                                driverNameInput.value = data.driver.name;
                            }
                            if (driverPhoneInput && !driverPhoneInput.value.trim() && data.driver.phone) {
                                driverPhoneInput.value = data.driver.phone;
                            }
                        }
                        const helpers = Array.isArray(data.helpers) ? data.helpers : [];
                        [1, 2, 3, 4, 5].forEach((slot) => {
                            setStaffValue(
                                form.querySelector(`[name="helper${slot}_staff_id"]`),
                                helpers[slot - 1] || data[`helper${slot}`]
                            );
                        });
                    })
                    .catch(() => {});
            }

            function validateStaffUnique(form) {
                const seen = new Map();
                for (const selectEl of staffSelects(form)) {
                    const value = String(selectEl.value || '').trim();
                    if (!value) continue;
                    const label = selectEl.closest('.col-xl, .col-md-4, .col-md-3')?.querySelector('label')?.textContent?.trim() || 'พนักงาน';
                    if (seen.has(value)) {
                        warn(`${label} ซ้ำกับ ${seen.get(value)} กรุณาเลือกคนละคน`);
                        selectEl.focus();
                        return false;
                    }
                    seen.set(value, label);
                }
                return true;
            }

            document.querySelectorAll('.jsSpecialEditMode').forEach((radio) => {
                radio.addEventListener('change', function () {
                    const target = this.dataset.target;
                    const mode = this.value;
                    const truckForm = document.querySelector(`.jsSpecialTruckPanel[data-special-panel="${target}"]`);
                    const keepForm = document.querySelector(`.jsSpecialKeepPanel[data-special-panel="${target}"]`);
                    truckForm?.classList.toggle('d-none', mode === 'SPECIAL');
                    keepForm?.classList.toggle('d-none', mode !== 'SPECIAL');
                    applyRegularMode(truckForm, mode);
                });
            });

            document.querySelectorAll('.jsSpecialTruckPanel').forEach((form) => {
                applyRegularMode(form, 'TRUCK_MASTER');
                form.addEventListener('submit', function (ev) {
                    const pickMode = form.querySelector('.jsTruckPickMode')?.value;
                    if (pickMode === 'MANUAL') {
                        const plate = form.querySelector('.jsManualPlateInput')?.value.trim() || '';
                        if (!plate) {
                            ev.preventDefault();
                            warn('กรุณากรอกทะเบียนรถนอก');
                            form.querySelector('.jsManualPlateInput')?.focus();
                            return;
                        }
                        form.querySelector('.jsTruckId').value = '';
                        form.querySelector('.jsManualPlate').value = plate;
                        // คนขับ/เบอร์: รวบมาจากแถวด้านล่าง (ใช้ร่วมกับฟอร์มรถในระบบ) แทนช่องในกล่องรถนอกที่ถูกตัดทิ้ง
                        form.querySelector('.jsManualDriver').value = form.querySelector('.jsDriverNameInput')?.value.trim() || '';
                        form.querySelector('.jsManualPhone').value = form.querySelector('.jsDriverPhoneInput')?.value.trim() || '';
                        form.querySelector('.jsManualMax').value = form.querySelector('.jsManualMaxInput')?.value || '';
                        form.querySelector('.jsManualLength').value = form.querySelector('.jsManualLengthInput')?.value || '';
                        form.querySelector('.jsManualRemark').value = form.querySelector('.jsManualRemarkInput')?.value.trim() || '';
                    }
                });
            });

            function applyRegularMode(truckForm, mode) {
                if (!truckForm) return;
                const masterBox = truckForm.querySelector('.jsTruckMasterBox');
                const manualBox = truckForm.querySelector('.jsTruckManualBox');
                const truckSelect = truckForm.querySelector('.jsTruckSelect');
                const pickMode = truckForm.querySelector('.jsTruckPickMode');
                masterBox?.classList.toggle('d-none', mode !== 'TRUCK_MASTER');
                manualBox?.classList.toggle('d-none', mode !== 'TRUCK_MANUAL');
                if (truckSelect) {
                    if (mode === 'TRUCK_MASTER') {
                        truckSelect.setAttribute('required', '');
                    } else {
                        truckSelect.removeAttribute('required');
                        truckSelect.value = '';
                    }
                }
                if (pickMode) {
                    pickMode.value = mode === 'TRUCK_MANUAL' ? 'MANUAL' : 'MASTER';
                }
                if (mode === 'TRUCK_MANUAL') {
                    truckForm.querySelectorAll('.jsManualPlateInput, .jsManualMaxInput, .jsManualLengthInput, .jsManualRemarkInput')
                        .forEach((el) => el.dataset.required = el.classList.contains('jsManualPlateInput') ? '1' : '');
                }
            }

            document.querySelectorAll('.jsRegularEditMode').forEach((radio) => {
                radio.addEventListener('change', function () {
                    const target = this.dataset.target;
                    const mode = this.value;
                    const truckForm = document.querySelector(`.jsRegularTruckPanel[data-regular-panel="${target}"]`);
                    const specialForm = document.querySelector(`.jsRegularSpecialPanel[data-regular-panel="${target}"]`);
                    truckForm?.classList.toggle('d-none', mode === 'SPECIAL');
                    specialForm?.classList.toggle('d-none', mode !== 'SPECIAL');
                    applyRegularMode(truckForm, mode);
                });
            });

            function setStaffSelectValue(selectEl, id) {
                if (!selectEl || !id) return;
                const val = String(id);
                if (Array.from(selectEl.options).some((o) => o.value === val)) {
                    selectEl.value = val;
                } else {
                    const opt = new Option('พนักงาน #' + val, val, true, true);
                    selectEl.appendChild(opt);
                }
            }

            function prefillTruckForm(form) {
                const raw = form.dataset.prefill;
                if (!raw) return;
                let data;
                try { data = JSON.parse(raw); } catch (e) { return; }

                const trip = form.querySelector('[name="trip_no"]');
                if (trip && data.trip_no) trip.value = data.trip_no;

                const source = (data.truck_source || '').toUpperCase();
                const radios = document.querySelectorAll(`.jsRegularEditMode[data-target="${form.dataset.regularPanel}"], .jsSpecialEditMode[data-target="${form.dataset.specialPanel}"]`);

                if (source === 'MANUAL') {
                    const manualRadio = Array.from(radios).find((r) => r.value === 'TRUCK_MANUAL');
                    if (manualRadio) { manualRadio.checked = true; manualRadio.dispatchEvent(new Event('change')); }
                    form.querySelector('.jsManualPlateInput').value = data.manual_plate_no || '';
                    form.querySelector('.jsManualMaxInput').value = data.manual_max_load || '';
                    form.querySelector('.jsManualLengthInput').value = data.manual_car_length || '';
                    form.querySelector('.jsManualRemarkInput').value = data.manual_remark || '';
                    // คนขับ/เบอร์: ใช้แถวด้านล่าง (driver_name_input) — ตั้งค่าจาก manual_driver_name เป็น fallback
                    const dn = form.querySelector('.jsDriverNameInput');
                    const dp = form.querySelector('.jsDriverPhoneInput');
                    if (dn && !dn.value.trim()) dn.value = data.manual_driver_name || '';
                    if (dp && !dp.value.trim()) dp.value = data.manual_driver_phone || '';
                } else if (data.truck_id) {
                    const truckSelect = form.querySelector('.jsTruckSelect');
                    if (truckSelect) {
                        const wantTruckId = String(data.truck_id);
                        const opt = Array.from(truckSelect.options).find((o) => (o.dataset.truckId || '') === wantTruckId);
                        if (opt) {
                            truckSelect.value = opt.value;
                            truckSelect.dispatchEvent(new Event('change'));
                        }
                    }
                }

                // คนขับเป็น text input — set หลัง dispatch change ของ truck เพื่อไม่ให้โดน syncTruckOption เขียนทับ
                const driverNameInput = form.querySelector('.jsDriverNameInput');
                const driverPhoneInput = form.querySelector('.jsDriverPhoneInput');
                if (driverNameInput && data.driver_name) driverNameInput.value = data.driver_name;
                if (driverPhoneInput && data.driver_phone) driverPhoneInput.value = data.driver_phone;

                const shippingPhoneInput = form.querySelector('.jsShippingPhoneInput');
                if (shippingPhoneInput && data.shipping_phone) shippingPhoneInput.value = data.shipping_phone;

                loadStaffOptions().then(() => {
                    [1,2,3,4,5].forEach((n) => {
                        setStaffSelectValue(form.querySelector(`[name="helper${n}_staff_id"]`), data[`helper${n}_staff_id`]);
                    });
                });
            }

            document.querySelectorAll('.jsRegularTruckPanel').forEach((form) => {
                applyRegularMode(form, 'TRUCK_MASTER');
                prefillTruckForm(form);
                const replaceExisting = form.querySelector('.jsReplaceExisting');
                const assignWeightInput = form.querySelector('.jsAssignWeight');
                replaceExisting?.addEventListener('change', function () {
                    if (!assignWeightInput) return;
                    assignWeightInput.value = this.checked
                        ? (assignWeightInput.dataset.total || assignWeightInput.value || '0')
                        : (assignWeightInput.dataset.remaining || '0');
                });

                // โหมดเลือกหลายรายการ: ทุกแถวในตาราง = จะถูกจัดขึ้นรถ, กด × เพื่อเอาออก
                const batchTbody = form.querySelector('.jsBatchTbody');
                if (batchTbody) {
                    const batchCountBadge = form.querySelector('.jsBatchSelectedCount');
                    const batchUnassignBtn = form.querySelector('.jsBatchUnassignSelectedBtn');
                    const selectedUnassignChecks = () => Array.from(batchTbody.querySelectorAll('.jsBatchUnassignCheck:checked:not(:disabled)'));
                    const syncBatch = () => {
                        const n = batchTbody.querySelectorAll('input[name="ord_ids[]"]').length;
                        if (batchCountBadge) batchCountBadge.textContent = n + ' รายการ';
                        if (batchUnassignBtn) {
                            batchUnassignBtn.disabled = selectedUnassignChecks().length === 0;
                        }
                    };
                    form.__batchSync = syncBatch;

                    // กด × เอาแถวออก (รองรับทั้งแถวเดิมและแถวที่เพิ่มภายหลังผ่าน delegation)
                    batchTbody.addEventListener('click', (e) => {
                        const rm = e.target.closest('.jsBatchRowRemove');
                        if (!rm) return;
                        const tr = rm.closest('tr');
                        if (tr) tr.remove();
                        // เอาแถวออกแล้ว รายการจะกลับมากดเพิ่มได้อีกในลิสต์ทางซ้าย (available())
                        syncBatch();
                        document.dispatchEvent(new CustomEvent('dp:batch-changed'));
                    });
                    batchTbody.addEventListener('change', (e) => {
                        if (e.target.closest('.jsBatchUnassignCheck')) syncBatch();
                    });
                    batchUnassignBtn?.addEventListener('click', () => {
                        const items = selectedUnassignChecks().map((check) => ({
                            ordId: String(check.value || '').trim(),
                            so: check.dataset.so || '',
                            mfg: check.dataset.mfg || '',
                            plate: check.dataset.plate || '',
                        })).filter((item) => item.ordId);
                        openUnassignModal(items, window.location.href);
                    });
                    syncBatch();
                }

                form.addEventListener('submit', function (ev) {
                    const batchTbodyEl = form.querySelector('.jsBatchTbody');
                    if (batchTbodyEl && batchTbodyEl.querySelectorAll('input[name="ord_ids[]"]').length === 0) {
                        ev.preventDefault();
                        warn('กรุณาเพิ่มอย่างน้อย 1 รายการ');
                        return;
                    }
                    // โหมด batch: ตัด ord_ids/selected เดิมออกจาก return_url เพื่อให้หลังบันทึก
                    // กลับไปมุมมองปกติ เห็นทุกรายการที่จัด (รวมที่เพิ่มทีหลัง) แบบแยกรายการทันที
                    if (batchTbodyEl) {
                        const ru = form.querySelector('input[name="return_url"]');
                        if (ru && ru.value) {
                            try {
                                const u = new URL(ru.value, window.location.origin);
                                u.searchParams.delete('ord_ids');
                                u.searchParams.delete('selected');
                                ru.value = u.toString();
                            } catch (e) { /* ใช้ค่าเดิมถ้า parse ไม่ได้ */ }
                        }
                    }
                    const pickMode = form.querySelector('.jsTruckPickMode')?.value;
                    if (pickMode === 'MANUAL') {
                        const plate = form.querySelector('.jsManualPlateInput')?.value.trim() || '';
                        if (!plate) {
                            ev.preventDefault();
                            warn('กรุณากรอกทะเบียนรถนอก');
                            form.querySelector('.jsManualPlateInput')?.focus();
                            return;
                        }
                        form.querySelector('.jsTruckId').value = '';
                        form.querySelector('.jsManualPlate').value = plate;
                        // คนขับ/เบอร์: รวบจากแถวด้านล่าง (ใช้ร่วมกัน MASTER/MANUAL) ลด duplication
                        form.querySelector('.jsManualDriver').value = form.querySelector('.jsDriverNameInput')?.value.trim() || '';
                        form.querySelector('.jsManualPhone').value = form.querySelector('.jsDriverPhoneInput')?.value.trim() || '';
                        form.querySelector('.jsManualMax').value = form.querySelector('.jsManualMaxInput')?.value || '';
                        form.querySelector('.jsManualLength').value = form.querySelector('.jsManualLengthInput')?.value || '';
                        form.querySelector('.jsManualRemark').value = form.querySelector('.jsManualRemarkInput')?.value.trim() || '';
                    }
                });
            });

            // เพิ่มรายการเข้า batch จากลิสต์ทางซ้าย (กดปุ่ม +) — กดลบแถวก็กลับมาเพิ่มได้อีก
            (function batchAddList() {
                const form = document.querySelector('.jsRegularTruckPanel');
                const tbody = form ? form.querySelector('.jsBatchTbody') : null;
                const pane = document.querySelector('.jsBatchAddPane');
                if (!form || !tbody || !pane) return;

                const listEl = pane.querySelector('.jsBatchAddList');
                const searchInput = pane.querySelector('.jsBatchAddListSearch');
                let pool = @json($batchCandidates->values());
                if (!Array.isArray(pool)) pool = [];

                const esc = (s) => String(s ?? '')
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');

                // รายการที่ยัง "เพิ่มได้" = ยังไม่มีในตารางขวาตอนนี้
                function available() {
                    const existing = new Set(
                        Array.from(tbody.querySelectorAll('input[name="ord_ids[]"]')).map((el) => String(el.value)),
                    );
                    return pool.filter((c) => !existing.has(String(c.ord_id)));
                }

                function searchText(c) {
                    return `${c.customer_name} ${c.so_number} ${c.mfg_no} ${c.part_number} ${c.part_desc} ${c.address}`.toLowerCase();
                }

                function renderList() {
                    const q = String(searchInput?.value || '').trim().toLowerCase();
                    const list = available().filter((c) => q === '' || searchText(c).includes(q));

                    if (!list.length) {
                        listEl.innerHTML = '<div class="text-muted small text-center py-2">ไม่มีรายการให้เพิ่ม</div>';
                        return;
                    }

                    listEl.innerHTML = list.map((c) => {
                        const amount = c.is_piece ? esc(c.line_text) : (Number(c.remaining || 0).toLocaleString() + ' kg');
                        const badge = c.special_label
                            ? `<span class="badge bg-warning text-dark ms-1">${esc(c.special_label)}</span>`
                            : (c.is_assigned ? `<span class="badge bg-success ms-1">จัดแล้ว</span>` : '');
                        return `<div class="dp-batch-add-item d-flex align-items-start gap-2" data-ord-id="${c.ord_id}">
                                <button type="button" class="btn btn-sm btn-outline-primary jsBatchAddBtn flex-shrink-0" title="เพิ่มเข้ารถ">
                                    <i class="fas fa-plus"></i>
                                </button>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-semibold small text-truncate">${esc(c.customer_name)} · ${esc(c.so_number)}</div>
                                    <div class="text-muted" style="font-size:.78rem;">${esc(c.mfg_no)} · ${esc(c.part_number)}</div>
                                    <div class="text-muted text-truncate" style="font-size:.78rem;">${esc(c.address)} · <span class="text-primary">${amount}</span>${badge}</div>
                                </div>
                            </div>`;
                    }).join('');
                }

                function buildRow(c) {
                    const isPiece = !!c.is_piece;
                    const remain = Number(c.remaining || 0);
                    const rowBadge = c.special_label
                        ? `<span class="badge bg-warning text-dark ms-1">${esc(c.special_label)}</span>`
                        : (c.is_assigned ? `<span class="badge bg-success ms-1">จัดแล้ว</span>` : '');
                    const remainCell = (isPiece ? esc(c.line_text) : (remain.toLocaleString() + ' kg')) + rowBadge;
                    const weightCell = isPiece
                        ? `<div class="form-control form-control-sm bg-light text-muted text-end">${esc(c.line_qty)} ${esc(c.line_unit)}</div>`
                            + `<input type="hidden" name="assign_weight_kg[${c.ord_id}]" value="0">`
                        : `<input type="number" class="form-control form-control-sm text-end jsAssignWeight jsBatchWeight"`
                            + ` name="assign_weight_kg[${c.ord_id}]" min="0" step="1" value="${remain}"`
                            + ` data-remaining="${remain}" data-total="${remain}">`;
                    const tr = document.createElement('tr');
                    tr.setAttribute('data-ord-id', c.ord_id);
                    tr.innerHTML =
                        `<td class="text-center">`
                        + `<input type="hidden" class="jsBatchOrdInput" name="ord_ids[]" value="${c.ord_id}">`
                        + `<button type="button" class="btn btn-sm btn-outline-danger jsBatchRowRemove" title="เอาออก"><i class="fas fa-times"></i></button>`
                        + `</td>`
                        + `<td>${esc(c.customer_name)}</td>`
                        + `<td>${esc(c.so_number)}</td>`
                        + `<td>${esc(c.mfg_no)}</td>`
                        + `<td><div class="fw-semibold">${esc(c.part_number)}</div><div class="text-muted small">${esc(c.part_desc)}</div></td>`
                        + `<td>${esc(c.address)}</td>`
                        + `<td class="text-end fw-semibold">${remainCell}</td>`
                        + `<td class="text-end">${weightCell}</td>`;
                    return tr;
                }

                function addByOrd(ordId) {
                    const c = pool.find((x) => String(x.ord_id) === String(ordId));
                    if (!c) return;
                    if (tbody.querySelector(`input[name="ord_ids[]"][value="${c.ord_id}"]`)) return;
                    tbody.appendChild(buildRow(c));
                    if (typeof form.__batchSync === 'function') form.__batchSync();
                    renderList();
                }

                searchInput?.addEventListener('input', renderList);
                listEl.addEventListener('click', (e) => {
                    const btn = e.target.closest('.jsBatchAddBtn');
                    if (!btn) return;
                    const item = btn.closest('.dp-batch-add-item');
                    if (item) addByOrd(item.dataset.ordId);
                });
                // เมื่อมีการลบแถวออกจากตารางขวา ให้รีเฟรชลิสต์ (รายการกลับมาเพิ่มได้)
                document.addEventListener('dp:batch-changed', renderList);

                renderList();
            })();

            // โหมดปกติ: ติ๊กหลาย SO จากคิวซ้าย แล้วกด "จัดพร้อมกัน" → เข้าโหมด batch (มีลิสต์เพิ่ม/ลบ/น้ำหนักรายบรรทัด)
            (function queuePickBatch() {
                const bar = document.querySelector('.jsQueuePickBar');
                if (!bar) return;
                const checks = Array.from(document.querySelectorAll('.jsQueueBatchPick'));
                if (!checks.length) return;
                const countEl = bar.querySelector('.jsQueuePickCount');
                const goBtn = bar.querySelector('.jsQueuePickGo');
                const clearBtn = bar.querySelector('.jsQueuePickClear');
                const shipDate = @json($shipDate);

                const selected = () => checks.filter((c) => c.checked);
                const sync = () => {
                    const n = selected().length;
                    if (countEl) countEl.textContent = n;
                    if (goBtn) goBtn.disabled = n === 0;
                    if (clearBtn) clearBtn.disabled = n === 0;
                };

                checks.forEach((c) => c.addEventListener('change', sync));
                clearBtn?.addEventListener('click', () => { checks.forEach((c) => { c.checked = false; }); sync(); });
                goBtn?.addEventListener('click', () => {
                    const ids = selected().map((c) => String(c.value)).filter(Boolean);
                    if (!ids.length) return;
                    const url = new URL(window.location.href);
                    url.searchParams.set('ship_date', shipDate);
                    url.searchParams.set('ord_ids', ids.join(','));
                    url.searchParams.set('selected', 'ord-' + ids[0]);
                    // เคลียร์ตัวกรองที่อาจทำให้กลุ่ม batch ถูกซ่อน
                    url.searchParams.set('status', 'all');
                    url.searchParams.delete('hide_completed');
                    window.location.href = url.toString();
                });
                sync();
            })();

            document.querySelectorAll('.jsRegularSpecialPanel').forEach((form) => {
                form.addEventListener('submit', function (ev) {
                    if (this.dataset.confirmedSubmit === '1') {
                        delete this.dataset.confirmedSubmit;
                        return;
                    }

                    const target = this.dataset.regularPanel;
                    const truckForm = document.querySelector(`.jsRegularTruckPanel[data-regular-panel="${target}"]`);
                    if (!truckForm) return;
                    const checked = Array.from(truckForm.querySelectorAll('input[name="ord_ids[]"]:checked'));
                    const selected = checked.length
                        ? checked
                        : Array.from(truckForm.querySelectorAll('input[type="hidden"][name="ord_ids[]"]'));
                    if (selected.length === 0) {
                        ev.preventDefault();
                        warn('กรุณาติ๊กเลือกอย่างน้อย 1 รายการในตารางก่อน');
                        return;
                    }
                    ev.preventDefault();
                    this.querySelectorAll('input[name="ord_ids[]"]').forEach((el) => el.remove());
                    selected.forEach((cb) => {
                        const hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'ord_ids[]';
                        hidden.value = cb.value;
                        this.appendChild(hidden);
                    });
                    confirmAction('ยืนยันทำรายการนี้เป็นงานพิเศษ?').then((confirmed) => {
                        if (!confirmed) return;
                        saveScrollPosition();
                        this.dataset.confirmedSubmit = '1';
                        this.submit();
                    });
                });
            });

            document.querySelectorAll('.jsInlineAssignForm').forEach((form) => {
                const truckSelect = form.querySelector('.jsTruckSelect');
                const help = form.querySelector('.jsTruckHelp');
                const pickMode = form.querySelector('.jsTruckPickMode');
                const truckId = form.querySelector('.jsTruckId');
                const manualPlate = form.querySelector('.jsManualPlate');
                const manualDriver = form.querySelector('.jsManualDriver');
                const manualPhone = form.querySelector('.jsManualPhone');
                const manualMax = form.querySelector('.jsManualMax');
                const manualLength = form.querySelector('.jsManualLength');
                const manualRemark = form.querySelector('.jsManualRemark');
                fillStaffOptions(form);

                function syncTruckOption(event) {
                    const option = truckSelect.selectedOptions[0];

                    if (!option || !option.value) {
                        pickMode.value = 'MASTER';
                        truckId.value = '';
                        help.textContent = '';
                        return;
                    }

                    pickMode.value = option.dataset.pickType || 'MASTER';
                    truckSelect.required = true;
                    truckId.value = option.dataset.truckId || '';
                    manualPlate.value = option.dataset.plate || '';
                    manualDriver.value = option.dataset.driver || '';
                    manualPhone.value = option.dataset.phone || '';
                    manualMax.value = Number(option.dataset.maxLoad || 0) > 0 ? option.dataset.maxLoad : '';
                    manualLength.value = option.dataset.length || '';
                    manualRemark.value = option.dataset.remark || '';
                    help.textContent = [option.dataset.driver, option.dataset.phone].filter(Boolean).join(' · ');

                    // auto-fill ชื่อคนขับ/เบอร์โทร ตามคนขับประจำรถคันนี้
                    // - User เปลี่ยนรถ → เขียนทับเสมอ (ใช้คนขับใหม่ตามรถ)
                    // - Initial sync ตอนโหลดหน้า → เติมเฉพาะถ้าว่าง เพื่อไม่ให้ทับค่าจาก prefill
                    const driverNameInput = form.querySelector('.jsDriverNameInput');
                    const driverPhoneInput = form.querySelector('.jsDriverPhoneInput');
                    const isUserChange = !!(event && event.isTrusted);
                    if (driverNameInput && (isUserChange || !driverNameInput.value.trim())) {
                        driverNameInput.value = option.dataset.driver || '';
                    }
                    if (driverPhoneInput && (isUserChange || !driverPhoneInput.value.trim())) {
                        driverPhoneInput.value = option.dataset.phone || '';
                    }
                    applyStaffDefaults(form, {
                        truck_id: option.dataset.truckId || '',
                        manual_plate_no: option.dataset.plate || '',
                        ship_posted_at: form.querySelector('[name="ship_posted_at"]')?.value || '',
                    });
                }

                truckSelect.addEventListener('change', syncTruckOption);
                form.addEventListener('submit', function (event) {
                    if (!validateStaffUnique(form)) {
                        event.preventDefault();
                    }
                });
                form.querySelector('.jsManualTruckBtn')?.addEventListener('click', function () {
                    activeManualForm = form;
                    document.getElementById('manualTruckPlate').value = manualPlate.value || '';
                    document.getElementById('manualTruckDriver').value = manualDriver.value || '';
                    document.getElementById('manualTruckPhone').value = manualPhone.value || '';
                    document.getElementById('manualTruckMax').value = manualMax.value || '';
                    document.getElementById('manualTruckLength').value = manualLength.value || '';
                    document.getElementById('manualTruckRemark').value = manualRemark.value || '';
                    manualModal?.show();
                });
                syncTruckOption();
            });

            document.getElementById('saveManualTruckBtn')?.addEventListener('click', function () {
                if (!activeManualForm) return;

                const plate = document.getElementById('manualTruckPlate').value.trim();
                if (!plate) {
                    document.getElementById('manualTruckPlate').focus();
                    return;
                }

                activeManualForm.querySelector('.jsTruckPickMode').value = 'MANUAL';
                activeManualForm.querySelector('.jsTruckId').value = '';
                activeManualForm.querySelector('.jsTruckSelect').required = false;
                activeManualForm.querySelector('.jsTruckSelect').value = '';
                activeManualForm.querySelector('.jsManualPlate').value = plate;
                activeManualForm.querySelector('.jsManualDriver').value = document.getElementById('manualTruckDriver').value.trim();
                activeManualForm.querySelector('.jsManualPhone').value = document.getElementById('manualTruckPhone').value.trim();
                // โยนค่าลงแถวด้านล่างด้วย เพื่อให้ฟอร์มเห็นและ submit handler ส่งค่าไปยัง hidden fields ได้
                const _bottomDn = activeManualForm.querySelector('.jsDriverNameInput');
                const _bottomDp = activeManualForm.querySelector('.jsDriverPhoneInput');
                if (_bottomDn) _bottomDn.value = document.getElementById('manualTruckDriver').value.trim();
                if (_bottomDp) _bottomDp.value = document.getElementById('manualTruckPhone').value.trim();
                activeManualForm.querySelector('.jsManualMax').value = document.getElementById('manualTruckMax').value;
                activeManualForm.querySelector('.jsManualLength').value = document.getElementById('manualTruckLength').value;
                activeManualForm.querySelector('.jsManualRemark').value = document.getElementById('manualTruckRemark').value.trim();
                activeManualForm.querySelector('.jsTruckHelp').textContent = 'ใช้รถนอก: ' + plate;
                applyStaffDefaults(activeManualForm, {
                    truck_id: '',
                    manual_plate_no: plate,
                    ship_posted_at: activeManualForm.querySelector('[name="ship_posted_at"]')?.value || '',
                });
                manualModal?.hide();
            });
        });
    </script>

    <script>
        // Custom autocomplete สำหรับช่องคนขับ — ทดแทน native datalist (สวยกว่า + ค้นได้ทั้งชื่อและเบอร์)
        // ข้อมูล: ดึงจาก DB (ta.driver_name distinct ที่เคยบันทึก) — ส่งมาเป็น JSON ฝัง inline
        (function () {
            const HISTORY = @json($driverHistory ?? []);
            const MAX_SHOW = 8;

            function escapeHtml(s) {
                return String(s ?? '')
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
            }

            function highlight(text, query) {
                text = String(text ?? '');
                const q = String(query ?? '').trim();
                if (!q) return escapeHtml(text);
                const lower = text.toLowerCase();
                const idx = lower.indexOf(q.toLowerCase());
                if (idx < 0) return escapeHtml(text);
                return escapeHtml(text.slice(0, idx))
                    + '<mark>' + escapeHtml(text.slice(idx, idx + q.length)) + '</mark>'
                    + escapeHtml(text.slice(idx + q.length));
            }

            function initial(name) {
                const t = String(name ?? '').trim();
                if (!t) return '?';
                // ตัด "นาย/นาง/น.ส./คุณ" ออกก่อน เพื่อให้ได้ตัวอักษรชื่อจริง
                const cleaned = t.replace(/^(นาย|นาง|น\.ส\.|นางสาว|คุณ|Mr\.?|Mrs\.?|Ms\.?)\s*/i, '');
                return (cleaned.charAt(0) || t.charAt(0) || '?').toUpperCase();
            }

            function filterHistory(query, field, scope) {
                const q = String(query ?? '').trim().toLowerCase();
                let list = HISTORY;
                // ฟอร์มรถนอก: โชว์เฉพาะคนขับที่บันทึกเอง (ไม่เอาพนักงานประจำรถใน master)
                if (scope === 'manual') {
                    list = list.filter((h) => !h.is_master);
                }
                // ช่องเบอร์โทร: ตัดรายการที่ไม่มีเบอร์ออก ไม่งั้นจะขึ้น "ไม่มีเบอร์" ซ้ำ ๆ ไม่มีประโยชน์
                if (field === 'phone') {
                    list = list.filter((h) => (h.phone || '').trim() !== '');
                }
                if (q) {
                    list = list.filter((h) => {
                        return (h.name || '').toLowerCase().includes(q)
                            || (h.phone || '').toLowerCase().includes(q);
                    });
                    // เรียง: รายการที่ตรงกับ field ปัจจุบันก่อน
                    list.sort((a, b) => {
                        const aMatch = ((field === 'phone' ? a.phone : a.name) || '').toLowerCase().includes(q) ? 0 : 1;
                        const bMatch = ((field === 'phone' ? b.phone : b.name) || '').toLowerCase().includes(q) ? 0 : 1;
                        return aMatch - bMatch;
                    });
                }
                return list.slice(0, MAX_SHOW);
            }

            function attachAutocomplete(input) {
                if (input.dataset.acReady === '1') return;
                input.dataset.acReady = '1';

                // wrap parent → relative
                const parent = input.parentElement;
                if (parent && getComputedStyle(parent).position === 'static') {
                    parent.classList.add('driver-ac-wrap');
                }

                const panel = document.createElement('div');
                panel.className = 'driver-ac-panel';
                parent.appendChild(panel);

                const field = input.dataset.acField || 'name';
                const scope = input.dataset.acScope || 'regular';
                const form = input.closest('form');
                // scope=manual ใช้คู่ jsManual*Input (ฟอร์มรถนอก) — scope ปกติใช้คู่ jsDriver*Input
                const nameSel = scope === 'manual' ? '.jsManualDriverInput' : '.jsDriverNameInput';
                const phoneSel = scope === 'manual' ? '.jsManualPhoneInput' : '.jsDriverPhoneInput';
                const nameInput = form?.querySelector(nameSel);
                const phoneInput = form?.querySelector(phoneSel);

                let activeIndex = -1;
                let currentList = [];

                function render(query) {
                    currentList = filterHistory(query, field, scope);
                    // จัดให้คนขับประจำรถมาก่อน เพื่อให้ keyboard navigation ตรงกับลำดับที่เห็น
                    currentList.sort((a, b) => (b.is_master ? 1 : 0) - (a.is_master ? 1 : 0));
                    activeIndex = -1;
                    if (currentList.length === 0) {
                        // ไม่มีอะไรให้แสดง — ไม่ต้องเปิด panel เลย ให้ user พิมพ์เองสบาย ๆ
                        // (ยกเว้นกรณีพิมพ์ค้นหา แล้วไม่เจอ ค่อยขึ้น "ไม่พบ")
                        const typed = String(query ?? '').trim();
                        if (!typed) {
                            panel.classList.remove('is-open');
                            return;
                        }
                        panel.innerHTML = '<div class="driver-ac-empty">ไม่พบรายการที่ตรงกัน</div>';
                        panel.classList.add('is-open');
                        return;
                    }
                    // แยก 2 กลุ่ม: คนขับประจำรถในระบบ vs คนขับนอก (จดเอง)
                    const masterItems = currentList.filter((h) => h.is_master);
                    const manualItems = currentList.filter((h) => !h.is_master);

                    const renderItem = (h) => {
                        const i = currentList.indexOf(h);
                        const nameHtml = highlight(h.name || '-', query);
                        const phoneHtml = h.phone ? highlight(h.phone, query) : '<span class="text-muted">ไม่มีเบอร์</span>';
                        const badge = h.is_master ? '<span class="driver-ac-badge">ประจำรถ</span>' : '';
                        return `
                            <div class="driver-ac-item" data-idx="${i}">
                                <div class="driver-ac-avatar">${escapeHtml(initial(h.name))}</div>
                                <div class="driver-ac-body">
                                    <div class="driver-ac-name">${nameHtml}${badge}</div>
                                    <div class="driver-ac-phone"><i class="fas fa-phone"></i>${phoneHtml}</div>
                                </div>
                            </div>
                        `;
                    };

                    // ถ้ามีกลุ่มเดียวไม่ต้องโชว์ header (รก) — โชว์ header ก็ต่อเมื่อมีทั้ง 2 กลุ่ม
                    const hasTwoGroups = masterItems.length > 0 && manualItems.length > 0;
                    let html = '';
                    if (masterItems.length) {
                        if (hasTwoGroups) html += '<div class="driver-ac-group"><i class="fas fa-id-badge"></i> คนขับประจำรถในระบบ</div>';
                        html += masterItems.map(renderItem).join('');
                    }
                    if (manualItems.length) {
                        if (hasTwoGroups) html += '<div class="driver-ac-group"><i class="fas fa-user-edit"></i> คนขับที่บันทึกเอง</div>';
                        html += manualItems.map(renderItem).join('');
                    }
                    panel.innerHTML = html;
                    panel.classList.add('is-open');
                }

                function selectIndex(i) {
                    if (i < 0 || i >= currentList.length) return;
                    const item = currentList[i];
                    if (nameInput && item.name) nameInput.value = item.name;
                    if (phoneInput && item.phone) phoneInput.value = item.phone;
                    panel.classList.remove('is-open');
                    // หลังเลือกชื่อ → กระโดดไปช่องเบอร์
                    // ถ้ายังไม่มีเบอร์ (record นี้ไม่มีเบอร์) เปิด autocomplete ให้พิมพ์/เลือกต่อได้เลย
                    // ถ้ามีเบอร์แล้ว ก็แค่ focus เฉย ๆ (ไม่ต้อง overwrite)
                    if (field === 'name' && phoneInput) {
                        phoneInput.focus(); // เด้ง autocomplete ของช่องเบอร์เปิดอัตโนมัติ
                    }
                }

                function setActive(i) {
                    panel.querySelectorAll('.driver-ac-item').forEach((el, idx) => {
                        el.classList.toggle('is-active', idx === i);
                    });
                    activeIndex = i;
                    const activeEl = panel.querySelector('.driver-ac-item.is-active');
                    if (activeEl) activeEl.scrollIntoView({ block: 'nearest' });
                }

                input.addEventListener('focus', () => render(input.value));
                input.addEventListener('input', () => render(input.value));
                input.addEventListener('keydown', (ev) => {
                    if (!panel.classList.contains('is-open')) return;
                    if (ev.key === 'ArrowDown') {
                        ev.preventDefault();
                        setActive(Math.min(activeIndex + 1, currentList.length - 1));
                    } else if (ev.key === 'ArrowUp') {
                        ev.preventDefault();
                        setActive(Math.max(activeIndex - 1, 0));
                    } else if (ev.key === 'Enter') {
                        if (activeIndex >= 0) {
                            ev.preventDefault();
                            selectIndex(activeIndex);
                        }
                    } else if (ev.key === 'Escape') {
                        panel.classList.remove('is-open');
                    }
                });
                panel.addEventListener('mousedown', (ev) => {
                    const item = ev.target.closest('.driver-ac-item');
                    if (!item) return;
                    ev.preventDefault(); // กัน blur ก่อน select
                    selectIndex(parseInt(item.dataset.idx, 10));
                });
                input.addEventListener('blur', () => {
                    // delay ให้ click ใน panel ทำงานก่อน
                    setTimeout(() => panel.classList.remove('is-open'), 150);
                });
            }

            document.addEventListener('DOMContentLoaded', function () {
                // เปิด autocomplete เฉพาะช่องชื่อ (ทั้งฟอร์มจัดรถปกติ + ฟอร์มรถนอก)
                // ช่องเบอร์ปล่อยพิมพ์อย่างเดียว — เบอร์มาจากการเลือกชื่อ
                document.querySelectorAll('.jsDriverAutocomplete[data-ac-field="name"]').forEach(attachAutocomplete);
            });
        })();
    </script>

    <script>
        // Autocomplete ทะเบียนรถนอก — เลือกแล้ว autofill คนขับ/เบอร์/max load/length ของรถนั้น
        (function () {
            const PLATE_HISTORY = @json($plateHistory ?? []);
            const MAX_SHOW = 8;

            function escapeHtml(s) {
                return String(s ?? '')
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
            }

            function highlight(text, query) {
                text = String(text ?? '');
                const q = String(query ?? '').trim();
                if (!q) return escapeHtml(text);
                const lower = text.toLowerCase();
                const idx = lower.indexOf(q.toLowerCase());
                if (idx < 0) return escapeHtml(text);
                return escapeHtml(text.slice(0, idx))
                    + '<mark>' + escapeHtml(text.slice(idx, idx + q.length)) + '</mark>'
                    + escapeHtml(text.slice(idx + q.length));
            }

            function filterPlates(query) {
                const q = String(query ?? '').trim().toLowerCase();
                let list = PLATE_HISTORY;
                if (q) {
                    list = list.filter((p) =>
                        (p.plate || '').toLowerCase().includes(q)
                        || (p.driver || '').toLowerCase().includes(q)
                    );
                }
                return list.slice(0, MAX_SHOW);
            }

            function attach(input) {
                if (input.dataset.acReady === '1') return;
                input.dataset.acReady = '1';

                const parent = input.parentElement;
                if (parent && getComputedStyle(parent).position === 'static') {
                    parent.classList.add('driver-ac-wrap');
                }
                const panel = document.createElement('div');
                panel.className = 'driver-ac-panel';
                parent.appendChild(panel);

                const form = input.closest('form');
                let activeIndex = -1;
                let currentList = [];

                function render(query) {
                    currentList = filterPlates(query);
                    activeIndex = -1;
                    if (currentList.length === 0) {
                        const typed = String(query ?? '').trim();
                        if (!typed) {
                            panel.classList.remove('is-open');
                            return;
                        }
                        panel.innerHTML = '<div class="driver-ac-empty">ไม่พบทะเบียนรถนี้ — กดบันทึกเพื่อเพิ่มใหม่</div>';
                        panel.classList.add('is-open');
                        return;
                    }
                    panel.innerHTML = currentList.map((p, i) => {
                        const plateHtml = highlight(p.plate, query);
                        const driverHtml = p.driver ? highlight(p.driver, query) : '<span class="text-muted">ไม่ระบุคนขับ</span>';
                        const phoneHtml = p.phone ? escapeHtml(p.phone) : '';
                        const maxHtml = p.max_load > 0 ? `<span class="text-muted ms-1">· ${Number(p.max_load).toLocaleString()} kg</span>` : '';
                        return `
                            <div class="driver-ac-item" data-idx="${i}">
                                <div class="driver-ac-avatar" style="background:linear-gradient(135deg,#0ea5e9,#06b6d4);">
                                    <i class="fas fa-truck" style="font-size:.78rem;"></i>
                                </div>
                                <div class="driver-ac-body">
                                    <div class="driver-ac-name">${plateHtml}${maxHtml}</div>
                                    <div class="driver-ac-phone"><i class="fas fa-user"></i>${driverHtml}${phoneHtml ? ' · ' + phoneHtml : ''}</div>
                                </div>
                            </div>
                        `;
                    }).join('');
                    panel.classList.add('is-open');
                }

                function selectIndex(i) {
                    if (i < 0 || i >= currentList.length) return;
                    const p = currentList[i];
                    input.value = p.plate;
                    if (form) {
                        const set = (sel, val) => {
                            const el = form.querySelector(sel);
                            if (el && val !== undefined && val !== null && val !== '') el.value = val;
                        };
                        set('.jsManualMaxInput', p.max_load > 0 ? p.max_load : '');
                        set('.jsManualLengthInput', p.car_length > 0 ? p.car_length : '');
                        // เติมคนขับ/เบอร์ลงแถวด้านล่าง (ทับเสมอ เพราะเป็น context ของรถคันใหม่)
                        const dn = form.querySelector('.jsDriverNameInput');
                        const dp = form.querySelector('.jsDriverPhoneInput');
                        if (dn) dn.value = p.driver || '';
                        if (dp) dp.value = p.phone || '';
                    }
                    panel.classList.remove('is-open');
                }

                function setActive(i) {
                    panel.querySelectorAll('.driver-ac-item').forEach((el, idx) => {
                        el.classList.toggle('is-active', idx === i);
                    });
                    activeIndex = i;
                    const activeEl = panel.querySelector('.driver-ac-item.is-active');
                    if (activeEl) activeEl.scrollIntoView({ block: 'nearest' });
                }

                input.addEventListener('focus', () => render(input.value));
                input.addEventListener('input', () => render(input.value));
                input.addEventListener('keydown', (ev) => {
                    if (!panel.classList.contains('is-open')) return;
                    if (ev.key === 'ArrowDown') { ev.preventDefault(); setActive(Math.min(activeIndex + 1, currentList.length - 1)); }
                    else if (ev.key === 'ArrowUp') { ev.preventDefault(); setActive(Math.max(activeIndex - 1, 0)); }
                    else if (ev.key === 'Enter') { if (activeIndex >= 0) { ev.preventDefault(); selectIndex(activeIndex); } }
                    else if (ev.key === 'Escape') { panel.classList.remove('is-open'); }
                });
                panel.addEventListener('mousedown', (ev) => {
                    const item = ev.target.closest('.driver-ac-item');
                    if (!item) return;
                    ev.preventDefault();
                    selectIndex(parseInt(item.dataset.idx, 10));
                });
                input.addEventListener('blur', () => {
                    setTimeout(() => panel.classList.remove('is-open'), 150);
                });
            }

            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('.jsPlateAutocomplete').forEach(attach);
            });
        })();
    </script>

    @if ($canDpa)
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                function askSend(sent, t) {
                    var title = sent ? 'ยืนยันส่งซ้ำ' : 'ยืนยันส่งเมลจัดรถ';
                    var text = sent
                        ? ('เคยส่งเมลจัดรถของวันที่ ' + t + ' แล้ว ต้องการส่งซ้ำอีกครั้งหรือไม่?')
                        : ('ต้องการส่งเมลจัดรถของวันที่ ' + t + ' หรือไม่?');
                    if (!window.Swal) {
                        return Promise.resolve(window.confirm(text));
                    }
                    return Swal.fire({
                        title: title,
                        text: text,
                        icon: sent ? 'warning' : 'question',
                        showCancelButton: true,
                        confirmButtonText: sent ? 'ส่งซ้ำ' : 'ส่งเมล',
                        cancelButtonText: 'ยกเลิก',
                        confirmButtonColor: sent ? '#f59e0b' : '#22c55e',
                        cancelButtonColor: '#64748b',
                        reverseButtons: true,
                    }).then(function (r) { return r.isConfirmed; });
                }

                function doSend(form, sent) {
                    var force = form.querySelector('.jsAssignMailForce');
                    if (force) force.value = sent ? '1' : '0';
                    form.dataset.confirmed = '1';
                    if (window.Swal) {
                        Swal.fire({
                            title: 'กำลังส่งเมล...',
                            text: 'กรุณารอสักครู่',
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                            didOpen: function () { Swal.showLoading(); },
                        });
                    }
                    form.submit();
                }

                document.querySelectorAll('.jsAssignMailForm').forEach(function (form) {
                    form.addEventListener('submit', function (e) {
                        if (form.dataset.confirmed === '1') return; // ผ่านการยืนยันแล้ว
                        e.preventDefault();
                        var sent = form.dataset.alreadySent === '1';
                        askSend(sent, form.dataset.shipText || '').then(function (ok) {
                            if (ok) doSend(form, sent);
                        });
                    });
                });

                @if (session('assign_mail_confirm'))
                    var dupForm = document.querySelector('.jsAssignMailForm');
                    if (dupForm) {
                        askSend(true, @json(session('assign_mail_confirm'))).then(function (ok) {
                            if (ok) doSend(dupForm, true);
                        });
                    }
                @endif

                @if (session('assign_mail_success'))
                    if (window.Swal) {
                        Swal.fire({
                            icon: 'success',
                            title: 'ส่งเมลสำเร็จ',
                            text: @json(session('assign_mail_success')),
                            confirmButtonColor: '#22c55e',
                            timer: 3000,
                            timerProgressBar: true,
                            showConfirmButton: false,
                        });
                    } else {
                        alert(@json(session('assign_mail_success')));
                    }
                @endif

                @if (session('assign_mail_error'))
                    if (window.Swal) {
                        Swal.fire({
                            icon: 'error',
                            title: 'ส่งเมลไม่สำเร็จ',
                            text: @json(session('assign_mail_error')),
                            confirmButtonColor: '#ef4444',
                        });
                    } else {
                        alert(@json(session('assign_mail_error')));
                    }
                @endif
            });
        </script>
    @endif
@endsection
