@extends('layouts.layout')
@section('page-title', 'จัดรถส่งสินค้า')
@section('title', 'จัดรถส่งสินค้า')

@section('content')
    @php
        $fmtWeight = fn($kg) => number_format((float) ($kg ?? 0), 0, '.', ',') . ' kg';
        $fmtCount = fn($value) => number_format((float) ($value ?? 0), 0, '.', ',');

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

        if (!in_array($statusFilter, ['all', 'special'], true)) {
            $specialQueueItems = collect();
        }

        if ($statusFilter === 'special') {
            $grouped = collect();
        }

        $selectedKey = (string) request()->query(
            'selected',
            optional($grouped->first())->key ?? optional($specialQueueItems->first())->key ?? ''
        );
    @endphp

    <style>
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
            @if (session('success'))
                <div class="alert alert-success py-2">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="alert alert-danger py-2">{{ session('error') }}</div>
            @endif
            @if ($errors->any())
                <div class="alert alert-danger py-2">
                    <div class="fw-semibold">บันทึกไม่สำเร็จ</div>
                    <ul class="mb-0 ps-3">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @include('formdp.partials.transport-nav', [
                'tnActive'   => 'logistics',
                'tnShipDate' => $shipDate,
                'tnSo'       => request('q', ''),
                'tnCustomer' => '',
                'tnMfg'      => '',
            ])

            <div class="text-muted small mb-3">วันที่ส่ง {{ \Carbon\Carbon::parse($shipDate)->format('d/m/Y') }}</div>

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
                        @foreach ($grouped as $group)
                            <button type="button"
                                class="queue-item jsSummaryPick {{ $group->key === $selectedKey ? 'is-active' : '' }}"
                                data-target="{{ $group->key }}">
                                <div class="d-flex justify-content-between gap-2">
                                    <div>
                                        <div class="queue-title">{{ $group->so_number }}</div>
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
                                    <span class="fw-semibold">{{ $fmtWeight($group->remaining_weight) }} คงเหลือ</span>
                                </div>
                            </button>
                        @endforeach
                        @foreach ($specialQueueItems as $special)
                            <button type="button"
                                class="queue-item jsSummaryPick {{ $special->key === $selectedKey ? 'is-active' : '' }}"
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
                        @endforeach
                        @if ($grouped->isEmpty() && $specialQueueItems->isEmpty())
                            <div class="p-4 text-center text-muted">ไม่พบข้อมูลตามเงื่อนไข</div>
                        @endif
                    </div>

                    <div class="detail-pane">
                        @foreach ($grouped as $group)
                            @php
                                $isCompleted = $group->status === 'completed';
                                $assignableRows = collect($group->rows)
                                    ->filter(fn($row) => (float) ($row->remaining_weight ?? 0) > 0
                                        || $isCompleted
                                        || (int) ($row->is_piece_qty ?? 0) === 1)
                                    ->values();
                            @endphp
                            @php
                                $regularEditId = 'regularEdit' . md5($group->key);
                            @endphp
                            <div class="dispatch-panel p-3 {{ $group->key === $selectedKey ? 'is-active' : '' }}"
                                data-panel="{{ $group->key }}">
                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                                    <div>
                                        <div class="panel-title h5 mb-1">{{ $group->so_number }}</div>
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
                                    <input type="hidden" name="return_url" value="{{ url()->full() }}">
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
                                            <div class="fw-bold">{{ $fmtWeight($group->total_weight) }}</div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-lg-3">
                                        <div class="border rounded p-2">
                                            <div class="metric-label mb-1">ขึ้นรถแล้ว</div>
                                            <div class="fw-bold text-primary">{{ $fmtWeight($group->assigned_weight) }}</div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-lg-3">
                                        <div class="border rounded p-2">
                                            <div class="metric-label mb-1">คงเหลือ</div>
                                            <div class="fw-bold text-danger">{{ $fmtWeight($group->remaining_weight) }}</div>
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
                                    <input type="hidden" name="return_url" value="{{ url()->full() }}">
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
                                    @if ($isCompleted)
                                        <input type="hidden" name="replace_mode" value="1">
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
                                            <div class="col-md-4">
                                                <label class="form-label small mb-1">ทะเบียนรถ <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control form-control-sm jsManualPlateInput" maxlength="50">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small mb-1">คนขับ (ชื่อ)</label>
                                                <input type="text" class="form-control form-control-sm jsManualDriverInput" maxlength="100">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small mb-1">เบอร์โทร</label>
                                                <input type="text" class="form-control form-control-sm jsManualPhoneInput" maxlength="30">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small mb-1">Max Load (kg)</label>
                                                <input type="number" step="1" class="form-control form-control-sm jsManualMaxInput" placeholder="เช่น 25000">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small mb-1">ความยาว</label>
                                                <input type="number" step="1" class="form-control form-control-sm jsManualLengthInput">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small mb-1">หมายเหตุ</label>
                                                <input type="text" class="form-control form-control-sm jsManualRemarkInput" maxlength="200" placeholder="เช่น เปิดข้าง / ตู้">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row g-2 align-items-end">
                                        <div class="col-xl-2 col-lg-3">
                                            <label class="form-label small mb-1">เที่ยว</label>
                                            <input type="number" class="form-control form-control-sm" name="trip_no"
                                                min="1" max="99" value="1">
                                        </div>
                                        <div class="col-xl-4 col-lg-5">
                                            <label class="form-label small mb-1">คนขับ (พนักงาน)</label>
                                            <select class="form-select form-select-sm jsStaffSelect jsDriverStaff"
                                                name="driver_staff_id" data-placeholder="-- เลือกคนขับ --">
                                                <option value="">-- เลือกคนขับ --</option>
                                            </select>
                                        </div>
                                        <div class="col-xl-6 col-lg-4">
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
                                    </div>

                                    @if (!$isCompleted && (float) ($group->assigned_weight ?? 0) > 0)
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" value="1"
                                                name="replace_mode" id="replace{{ $loop->index }}">
                                            <label class="form-check-label small" for="replace{{ $loop->index }}">
                                                ลบรถเดิมของ SO นี้ทั้งหมด แล้วค่อยจัดใหม่
                                            </label>
                                            <div class="form-text text-muted small ms-4">
                                                ปกติ: ไม่ติ๊ก = จัดรถเพิ่มเฉพาะส่วนที่ยังเหลือ (ไม่แตะรถเดิม)
                                            </div>
                                        </div>
                                    @endif

                                    <div class="table-responsive mt-3">
                                        <table class="table table-sm table-bordered line-table align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="width:42px;">เลือก</th>
                                                    <th>MFG</th>
                                                    <th>Part</th>
                                                    <th>สถานที่ส่ง</th>
                                                    <th>เพิ่มเติม / เอกสารแนบ</th>
                                                    <th class="text-end">น้ำหนัก</th>
                                                    <th class="text-end">ขึ้นรถแล้ว</th>
                                                    <th class="text-end">จัดครั้งนี้</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($group->rows as $row)
                                                    @php
                                                        $remaining = $isCompleted ? (float) ($row->qty ?? 0) : (float) ($row->remaining_weight ?? 0);
                                                        $canSelect = $remaining > 0 || (int) ($row->is_piece_qty ?? 0) === 1;
                                                        $remarkText = trim((string) ($row->remark ?? ''));
                                                        $editRemarkText = trim((string) ($row->edit_remark ?? ''));
                                                        $attachText = trim((string) ($row->attach_docs_text ?? ''));
                                                        $hasPieceInfo = (int) ($row->sell_by_line ?? 0) === 1 && (float) ($row->line_qty_display ?? 0) > 0;
                                                        $isPiece = (int) ($row->is_piece_qty ?? 0) === 1;
                                                    @endphp
                                                    <tr>
                                                        <td class="text-center">
                                                            @if ($canSelect)
                                                                <input type="checkbox" class="form-check-input"
                                                                    name="ord_ids[]" value="{{ (int) $row->ord_id }}"
                                                                    checked>
                                                            @else
                                                                <span class="text-muted">-</span>
                                                            @endif
                                                        </td>
                                                        <td>{{ $row->mfg_no ?: '-' }}</td>
                                                        <td>
                                                            <div class="fw-semibold">{{ $row->part_number ?: '-' }}</div>
                                                            <div class="line-note">{{ $row->part_desc ?: '-' }}</div>
                                                        </td>
                                                        <td>{{ $row->address ?: '-' }}</td>
                                                        <td class="small">
                                                            @if ($editRemarkText !== '')
                                                                <div class="text-warning"><i class="fas fa-pen me-1"></i>{{ $editRemarkText }}</div>
                                                            @endif
                                                            @if ($remarkText !== '')
                                                                <div class="text-muted">{{ $remarkText }}</div>
                                                            @endif
                                                            @if ($attachText !== '')
                                                                <div class="text-info">
                                                                    <i class="fas fa-paperclip me-1"></i>{{ $attachText }}
                                                                </div>
                                                            @endif
                                                            @if ($editRemarkText === '' && $remarkText === '' && $attachText === '')
                                                                <span class="text-muted">-</span>
                                                            @endif
                                                        </td>
                                                        <td class="text-end">
                                                            @if ($hasPieceInfo)
                                                                <div>{{ number_format((float) $row->line_qty_display, 0) }} {{ $row->line_qty_unit }}</div>
                                                                @if ((float) $row->qty > 0)
                                                                    <div class="text-muted small">{{ $fmtWeight($row->qty) }}</div>
                                                                @endif
                                                            @else
                                                                {{ $fmtWeight($row->qty) }}
                                                            @endif
                                                        </td>
                                                        <td class="text-end">{{ $fmtWeight($row->assigned_weight_sum) }}</td>
                                                        <td class="text-end" style="width:140px;">
                                                            @if ($isPiece)
                                                                <div class="fw-semibold">{{ number_format((float) $row->line_qty_display, 0) }} {{ $row->line_qty_unit }}</div>
                                                                <input type="hidden"
                                                                    name="assign_weight_kg[{{ (int) $row->ord_id }}]"
                                                                    value="0">
                                                            @elseif ($canSelect)
                                                                <input type="number"
                                                                    class="form-control form-control-sm text-end"
                                                                    name="assign_weight_kg[{{ (int) $row->ord_id }}]"
                                                                    min="0" step="1" value="{{ round($remaining) }}">
                                                            @else
                                                                <span class="text-muted">0 kg</span>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
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
                                        <input type="hidden" name="return_url" value="{{ url()->full() }}">
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
                                                <div class="col-md-4">
                                                    <label class="form-label small mb-1">ทะเบียนรถ <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control form-control-sm jsManualPlateInput" maxlength="50">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small mb-1">คนขับ (ชื่อ)</label>
                                                    <input type="text" class="form-control form-control-sm jsManualDriverInput" maxlength="100">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small mb-1">เบอร์โทร</label>
                                                    <input type="text" class="form-control form-control-sm jsManualPhoneInput" maxlength="30">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small mb-1">Max Load (kg)</label>
                                                    <input type="number" step="1" class="form-control form-control-sm jsManualMaxInput" placeholder="เช่น 25000">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small mb-1">ความยาว</label>
                                                    <input type="number" step="1" class="form-control form-control-sm jsManualLengthInput">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small mb-1">หมายเหตุ</label>
                                                    <input type="text" class="form-control form-control-sm jsManualRemarkInput" maxlength="200" placeholder="เช่น เปิดข้าง / ตู้">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row g-2 align-items-end">
                                            <div class="col-xl-2 col-lg-3">
                                                <label class="form-label small mb-1">เที่ยว</label>
                                                <input type="number" class="form-control form-control-sm"
                                                    name="trip_no" min="1" max="99" value="1">
                                            </div>
                                            <div class="col-xl-4 col-lg-5">
                                                <label class="form-label small mb-1">คนขับ (พนักงาน)</label>
                                                <select class="form-select form-select-sm jsStaffSelect jsDriverStaff"
                                                    name="driver_staff_id" data-placeholder="-- เลือกคนขับ --">
                                                    <option value="">-- เลือกคนขับ --</option>
                                                </select>
                                            </div>
                                            <div class="col-xl-6 col-lg-4">
                                                <div class="dispatch-actions">
                                                    <button type="submit" class="btn btn-success btn-sm flex-fill">
                                                        <i class="fas fa-truck me-1"></i> จัดรถ
                                                    </button>
                                                </div>
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
                                        <input type="hidden" name="return_url" value="{{ url()->full() }}">
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
                                        <input type="hidden" name="return_url" value="{{ url()->full() }}">
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
                                                onsubmit="return confirm('ยืนยันเปิดงานพิเศษนี้กลับ?');">
                                                @csrf
                                                <input type="hidden" name="return_url" value="{{ url()->full() }}">
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

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const STAFF_ROUTES = {
                options: @json(route('dp.truck.staff.options')),
                defaults: @json(route('dp.truck.staff.defaults')),
            };
            const pickers = Array.from(document.querySelectorAll('.jsSummaryPick'));
            const panels = Array.from(document.querySelectorAll('.dispatch-panel'));
            const manualModalEl = document.getElementById('manualTruckModal');
            const manualModal = manualModalEl && window.bootstrap ? new bootstrap.Modal(manualModalEl) : null;
            let activeManualForm = null;
            let staffOptionsPromise = null;

            function activatePanel(key) {
                pickers.forEach((btn) => btn.classList.toggle('is-active', btn.dataset.target === key));
                panels.forEach((panel) => panel.classList.toggle('is-active', panel.dataset.panel === key));
            }

            pickers.forEach((btn) => {
                btn.addEventListener('click', function () {
                    activatePanel(this.dataset.target);
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
                        setStaffValue(form.querySelector('.jsDriverStaff'), data.driver);
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
                        alert(`${label} ซ้ำกับ ${seen.get(value)} กรุณาเลือกคนละคน`);
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
                            alert('กรุณากรอกทะเบียนรถนอก');
                            form.querySelector('.jsManualPlateInput')?.focus();
                            return;
                        }
                        form.querySelector('.jsTruckId').value = '';
                        form.querySelector('.jsManualPlate').value = plate;
                        form.querySelector('.jsManualDriver').value = form.querySelector('.jsManualDriverInput')?.value.trim() || '';
                        form.querySelector('.jsManualPhone').value = form.querySelector('.jsManualPhoneInput')?.value.trim() || '';
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
                    truckForm.querySelectorAll('.jsManualPlateInput, .jsManualDriverInput, .jsManualPhoneInput, .jsManualMaxInput, .jsManualLengthInput, .jsManualRemarkInput')
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
                    form.querySelector('.jsManualDriverInput').value = data.manual_driver_name || '';
                    form.querySelector('.jsManualPhoneInput').value = data.manual_driver_phone || '';
                    form.querySelector('.jsManualMaxInput').value = data.manual_max_load || '';
                    form.querySelector('.jsManualLengthInput').value = data.manual_car_length || '';
                    form.querySelector('.jsManualRemarkInput').value = data.manual_remark || '';
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

                loadStaffOptions().then(() => {
                    setStaffSelectValue(form.querySelector('.jsDriverStaff'), data.driver_staff_id);
                    [1,2,3,4,5].forEach((n) => {
                        setStaffSelectValue(form.querySelector(`[name="helper${n}_staff_id"]`), data[`helper${n}_staff_id`]);
                    });
                });
            }

            document.querySelectorAll('.jsRegularTruckPanel').forEach((form) => {
                applyRegularMode(form, 'TRUCK_MASTER');
                prefillTruckForm(form);
                form.addEventListener('submit', function (ev) {
                    const pickMode = form.querySelector('.jsTruckPickMode')?.value;
                    if (pickMode === 'MANUAL') {
                        const plate = form.querySelector('.jsManualPlateInput')?.value.trim() || '';
                        if (!plate) {
                            ev.preventDefault();
                            alert('กรุณากรอกทะเบียนรถนอก');
                            form.querySelector('.jsManualPlateInput')?.focus();
                            return;
                        }
                        form.querySelector('.jsTruckId').value = '';
                        form.querySelector('.jsManualPlate').value = plate;
                        form.querySelector('.jsManualDriver').value = form.querySelector('.jsManualDriverInput')?.value.trim() || '';
                        form.querySelector('.jsManualPhone').value = form.querySelector('.jsManualPhoneInput')?.value.trim() || '';
                        form.querySelector('.jsManualMax').value = form.querySelector('.jsManualMaxInput')?.value || '';
                        form.querySelector('.jsManualLength').value = form.querySelector('.jsManualLengthInput')?.value || '';
                        form.querySelector('.jsManualRemark').value = form.querySelector('.jsManualRemarkInput')?.value.trim() || '';
                    }
                });
            });

            document.querySelectorAll('.jsRegularSpecialPanel').forEach((form) => {
                form.addEventListener('submit', function (ev) {
                    const target = this.dataset.regularPanel;
                    const truckForm = document.querySelector(`.jsRegularTruckPanel[data-regular-panel="${target}"]`);
                    if (!truckForm) return;
                    const checked = Array.from(truckForm.querySelectorAll('input[name="ord_ids[]"]:checked'));
                    if (checked.length === 0) {
                        ev.preventDefault();
                        alert('กรุณาติ๊กเลือกอย่างน้อย 1 รายการในตารางก่อน');
                        return;
                    }
                    if (!confirm('ยืนยันทำรายการที่เลือก (' + checked.length + ' รายการ) เป็นงานพิเศษ?')) {
                        ev.preventDefault();
                        return;
                    }
                    this.querySelectorAll('input[name="ord_ids[]"]').forEach((el) => el.remove());
                    checked.forEach((cb) => {
                        const hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'ord_ids[]';
                        hidden.value = cb.value;
                        this.appendChild(hidden);
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

                function syncTruckOption() {
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
                    manualMax.value = option.dataset.maxLoad || '';
                    manualLength.value = option.dataset.length || '';
                    manualRemark.value = option.dataset.remark || '';
                    help.textContent = [option.dataset.driver, option.dataset.phone].filter(Boolean).join(' · ');
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
@endsection
