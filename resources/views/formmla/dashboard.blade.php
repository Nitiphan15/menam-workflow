@extends('layouts.layout')

@section('title', 'Dashboard ภาระงานเครื่องจักร')
@section('page-title', 'Dashboard ภาระงานและวันว่างของเครื่องจักร')

@section('content')
    @php
        $filters = $filters ?? [];
        $kpis = $kpis ?? [];
        $machines = collect($machines ?? []);
        $workCenterGroups = collect($workCenterGroups ?? []);
        $buckets = collect($buckets ?? []);
        $selectedSources = $filters['source'] ?? [];
        $selectedWorkCenters = array_map('strval', $filters['workcenter_ids'] ?? []);
        $selectedMachines = array_map('strval', $filters['machine_ids'] ?? []);
        $fmt = fn($value, $decimals = 0) => is_numeric($value) ? number_format((float) $value, $decimals) : '-';
        $loadClass = function ($value) {
            if ((float) $value >= 100) {
                return 'text-danger';
            }
            if ((float) $value >= 80) {
                return 'text-warning';
            }
            return 'text-success';
        };
        $dateTypeText = ($filters['date_type'] ?? 'dateopen') === 'reqdate' ? 'กำหนดส่ง / Due Date' : 'วันที่เปิด WO';
        $currentFilters = [
            'date_from' => $filters['date_from'] ?? '',
            'date_to' => $filters['date_to'] ?? '',
            'date_type' => $filters['date_type'] ?? 'dateopen',
            'status' => $filters['status'] ?? 'open',
            'quick_filter' => $filters['quick_filter'] ?? 'all',
            'start_at' => $filters['start_at'] ?? '',
            'bucket' => $filters['bucket'] ?? 'week',
        ];
    @endphp

    <style>
        .mla-wrap {
            background: #f5f7fa;
            border-radius: 8px;
            padding: 16px;
        }

        .mla-panel {
            background: #fff;
            border: 1px solid #dfe5ec;
            border-radius: 8px;
            overflow: hidden;
        }

        .mla-head {
            padding: 12px 16px;
            border-bottom: 1px solid #e8edf2;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .mla-kpi {
            height: 100%;
            background: #fff;
            border: 1px solid #dfe5ec;
            border-radius: 8px;
            padding: 14px;
        }

        .mla-kpi .label {
            color: #667085;
            font-size: .82rem;
        }

        .mla-kpi .value {
            color: #1f2937;
            font-size: 1.35rem;
            font-weight: 800;
            line-height: 1.2;
            margin-top: 4px;
        }

        .mla-chart {
            height: 300px;
            padding: 14px;
        }

        .mla-chart-scroll {
            padding: 14px;
            max-height: 560px;
            overflow-y: auto;
        }

        .mla-chart-scroll canvas {
            min-height: 520px;
        }

        .mla-table-wrap {
            max-height: 620px;
            overflow: auto;
        }

        .mla-table th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #edf4ff;
            white-space: nowrap;
        }

        .mla-table td {
            vertical-align: middle;
        }

        .num {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .load-bar {
            height: 8px;
            border-radius: 999px;
            background: #edf2f7;
            overflow: hidden;
            min-width: 110px;
        }

        .load-fill {
            height: 100%;
            background: #0d6efd;
        }

        .load-fill.warn {
            background: #f0ad4e;
        }

        .load-fill.danger {
            background: #dc3545;
        }

        .wc-row {
            background: #f8fbff;
        }

        .machine-row td:first-child {
            border-left: 4px solid #dbeafe;
        }

        .ts-dropdown {
            z-index: 3000 !important;
        }

        @media (max-width: 767.98px) {
            .mla-wrap {
                padding: 10px;
            }

            .mla-chart {
                height: 260px;
            }
        }
    </style>

    <div class="mla-wrap">
        <div class="mla-panel mb-3">
            <div class="mla-head">
                <span>ตัวกรองข้อมูล</span>
                <div class="d-flex gap-2">
                    <a href="{{ route('machine-load.inquiry', request()->query()) }}"
                        class="btn btn-sm btn-outline-primary"><i class="fas fa-table me-1"></i> รายละเอียด</a>
                    <a href="{{ route('machine-load.settings') }}" class="btn btn-sm btn-outline-secondary"><i
                            class="fas fa-gears me-1"></i> ตั้งค่า</a>
                </div>
            </div>
            <form method="GET" action="{{ route('machine-load.dashboard') }}" class="p-3">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">ชนิดวันที่</label>
                        <select name="date_type" class="form-select">
                            <option value="dateopen" {{ ($filters['date_type'] ?? '') === 'dateopen' ? 'selected' : '' }}>
                                วันที่เปิด WO</option>
                            <option value="reqdate" {{ ($filters['date_type'] ?? '') === 'reqdate' ? 'selected' : '' }}>
                                กำหนดส่ง / Due Date</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">ตั้งแต่วันที่</label>
                        <input type="date" name="date_from" class="form-control"
                            value="{{ $filters['date_from'] ?? '' }}">
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">ถึงวันที่</label>
                        <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">เริ่มคำนวณวันว่าง</label>
                        <input type="datetime-local" name="start_at" class="form-control"
                            value="{{ isset($filters['start_at']) ? str_replace(' ', 'T', $filters['start_at']) : '' }}">
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">แหล่งข้อมูล</label>
                        <select name="source[]" class="form-select mla-select" multiple data-placeholder="ทั้งหมด">
                            @foreach ($sourceOptions ?? ($siteOptions ?? []) as $option)
                                <option value="{{ $option['value'] }}"
                                    {{ in_array($option['value'], $selectedSources, true) ? 'selected' : '' }}>
                                    {{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label">Work Center</label>
                        <select name="workcenter_ids[]" class="form-select mla-select" multiple
                            data-placeholder="ทุก Work Center">
                            @foreach ($workcenterOptions ?? [] as $option)
                                <option value="{{ $option['value'] }}" data-site="{{ $option['site'] ?? '' }}"
                                    {{ in_array((string) $option['value'], $selectedWorkCenters, true) ? 'selected' : '' }}>
                                    {{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label">เครื่องจักร</label>
                        <select name="machine_ids[]" class="form-select mla-select" multiple
                            data-placeholder="ทุกเครื่องจักร">
                            @foreach ($machineOptions ?? [] as $option)
                                <option value="{{ $option['value'] }}" data-site="{{ $option['site'] ?? '' }}"
                                    {{ in_array((string) $option['value'], $selectedMachines, true) ? 'selected' : '' }}>
                                    {{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-1 col-md-3">
                        <label class="form-label">สรุปตาม</label>
                        <select name="bucket" class="form-select">
                            <option value="week" {{ ($filters['bucket'] ?? '') === 'week' ? 'selected' : '' }}>รายสัปดาห์
                            </option>
                            <option value="month" {{ ($filters['bucket'] ?? '') === 'month' ? 'selected' : '' }}>รายเดือน
                            </option>
                        </select>
                    </div>
                    <div class="col-lg-1 col-md-3">
                        <label class="form-label">Quick Filter</label>
                        <select name="quick_filter" class="form-select">
                            <option value="all" {{ ($filters['quick_filter'] ?? 'all') === 'all' ? 'selected' : '' }}>All</option>
                            <option value="load_80" {{ ($filters['quick_filter'] ?? '') === 'load_80' ? 'selected' : '' }}>Load &gt;= 80%</option>
                            <option value="load_100" {{ ($filters['quick_filter'] ?? '') === 'load_100' ? 'selected' : '' }}>Load &gt;= 100%</option>
                            <option value="due_risk" {{ ($filters['quick_filter'] ?? '') === 'due_risk' ? 'selected' : '' }}>Late Risk</option>
                        </select>
                    </div>
                    <div class="col-lg-1 col-md-3">
                        <label class="form-label">WO Status</label>
                        <select name="status" class="form-select">
                            <option value="open" {{ ($filters['status'] ?? '') === 'open' ? 'selected' : '' }}>ยังไม่ปิด
                            </option>
                            <option value="closed" {{ ($filters['status'] ?? '') === 'closed' ? 'selected' : '' }}>ปิดแล้ว
                            </option>
                            <option value="all" {{ ($filters['status'] ?? '') === 'all' ? 'selected' : '' }}>ทั้งหมด
                            </option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i> ค้นหา</button>
                        <a href="{{ route('machine-load.dashboard') }}" class="btn btn-outline-secondary"><i class="fas fa-eraser me-1"></i> Clear</a>
                    </div>
                </div>
            </form>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl col-md-4">
                <div class="mla-kpi">
                    <div class="label">จำนวน Work Center</div>
                    <div class="value">{{ $fmt($kpis['workcenter_count'] ?? 0) }}</div>
                </div>
            </div>
            <div class="col-xl col-md-4">
                <div class="mla-kpi">
                    <div class="label">จำนวนเครื่องจักร</div>
                    <div class="value">{{ $fmt($kpis['machine_count'] ?? 0) }}</div>
                </div>
            </div>
            <div class="col-xl col-md-4">
                <div class="mla-kpi">
                    <div class="label">จำนวน WO ค้างผลิต</div>
                    <div class="value">{{ $fmt($kpis['backlog_orders'] ?? 0) }}</div>
                </div>
            </div>
            <div class="col-xl col-md-4">
                <div class="mla-kpi">
                    <div class="label">จำนวนค้างผลิต</div>
                    <div class="value">{{ $fmt($kpis['backlog_qty'] ?? 0, 2) }}</div>
                </div>
            </div>
            <div class="col-xl col-md-6">
                <div class="mla-kpi">
                    <div class="label">ชั่วโมงงานรวม</div>
                    <div class="value">{{ $fmt($kpis['load_hours'] ?? 0, 2) }}</div>
                </div>
            </div>
            <div class="col-xl col-md-6">
                <div class="mla-kpi">
                    <div class="label">เครื่องที่โหลดเกิน</div>
                    <div class="value text-danger">{{ $fmt($kpis['critical_count'] ?? 0) }}</div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-7">
                <div class="mla-panel">
                    <div class="mla-head">
                        <span>CAPACITY PLAN — Top 25 เครื่องตามภาระงาน</span>
                        <small class="text-muted">คลิกแท่งเพื่อดูรายละเอียด WO · เขียว &lt; 80% / เหลือง 80–100% / แดง &gt; 100%</small>
                    </div>
                    <div class="mla-chart-scroll" id="machineLoadChartWrap">
                        <canvas id="machineLoadChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="mla-panel">
                    <div class="mla-head"><span>ชั่วโมงงานเทียบกำลังผลิต</span></div>
                    <div class="mla-chart"><canvas id="bucketLoadChart"></canvas></div>
                </div>
            </div>
        </div>

        <div class="mla-panel mb-3">
            <div class="mla-head">
                <span>สรุปวันว่างตาม Work Center</span>
                <a href="{{ route('machine-load.export', request()->query()) }}" class="btn btn-sm btn-success"><i
                        class="fas fa-file-excel me-1"></i> Export Excel</a>
            </div>
            <div class="mla-table-wrap">
                <table class="table table-bordered table-sm mla-table mb-0">
                    <thead>
                        <tr>
                            <th>แหล่งข้อมูล</th>
                            <th>Work Center</th>
                            <th class="num">เครื่อง</th>
                            <th class="num">WO</th>
                            <th class="num">ค้างผลิต (กก.)</th>
                            <th class="num">Capacity (กก.)</th>
                            <th class="num">ชั่วโมงงาน</th>
                            <th class="num">กำลังผลิต ชม.</th>
                            <th class="num">โหลด %</th>
                            <th>รับงานถึงวันที่</th>
                            <th style="width:110px;">ดูข้อมูล</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($workCenterGroups as $group)
                            @php
                                $fillClass =
                                    $group->load_pct >= 100 ? 'danger' : ($group->load_pct >= 80 ? 'warn' : '');
                                $machineId = 'machine-list-' . $loop->index;
                            @endphp
                            <tr class="wc-row">
                                <td>{{ ucfirst(strtolower($group->source_site)) }}</td>
                                <td class="fw-bold">{{ $group->workcenter_label }}</td>
                                <td class="num">{{ $fmt($group->machine_count) }}</td>
                                <td class="num">{{ $fmt($group->order_count) }}</td>
                                <td class="num">{{ $fmt($group->balance_qty, 2) }}</td>
                                <td class="num">{{ $fmt($group->capacity_qty ?? 0, 2) }}</td>
                                <td class="num">{{ $fmt($group->load_hours, 2) }}</td>
                                <td class="num">{{ $fmt($group->capacity_hours, 2) }}</td>
                                <td class="num">
                                    <div class="d-flex align-items-center gap-2 justify-content-end">
                                        <div class="load-bar">
                                            <div class="load-fill {{ $fillClass }}"
                                                style="width: {{ min(100, max(0, (float) $group->load_pct)) }}%"></div>
                                        </div>
                                        <span
                                            class="{{ $loadClass($group->load_pct) }} fw-bold">{{ $fmt($group->load_pct, 2) }}%</span>
                                    </div>
                                </td>
                                <td class="fw-bold">{{ $group->next_available_at }}</td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" type="button"
                                        data-bs-toggle="collapse" data-bs-target="#{{ $machineId }}"
                                        aria-expanded="false">
                                        <i class="fas fa-industry me-1"></i> เครื่องจักร
                                    </button>
                                </td>
                            </tr>
                            <tr class="collapse" id="{{ $machineId }}">
                                <td colspan="11" class="p-0">
                                    <div class="p-3 bg-light">
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-sm mb-0 bg-white">
                                                <thead>
                                                    <tr>
                                                        <th>เครื่องจักร</th>
                                                        <th class="num">WO</th>
                                                        <th class="num">ค้างผลิต</th>
                                                        <th class="num">ชั่วโมงงาน</th>
                                                        <th class="num">โหลด %</th>
                                                        <th>ว่างวันที่</th>
                                                        <th style="width:92px;">ดู WO</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($group->machines ?? collect() as $machine)
                                                        @php $detailId = 'machine-load-detail-' . $loop->parent->index . '-' . $loop->index; @endphp
                                                        <tr class="machine-row">
                                                            <td>{{ $machine->machine_label }}</td>
                                                            <td class="num">{{ $fmt($machine->order_count) }}</td>
                                                            <td class="num fw-bold">{{ $fmt($machine->balance_qty, 2) }}
                                                            </td>
                                                            <td class="num">{{ $fmt($machine->load_hours, 2) }}</td>
                                                            <td class="num {{ $loadClass($machine->load_pct) }} fw-bold">
                                                                {{ $fmt($machine->load_pct, 2) }}%</td>
                                                            <td class="fw-bold">{{ $machine->next_available_at }}</td>
                                                            <td>
                                                                <button class="btn btn-sm btn-outline-secondary"
                                                                    type="button" data-bs-toggle="collapse"
                                                                    data-bs-target="#{{ $detailId }}">
                                                                    <i class="fas fa-list me-1"></i> WO
                                                                </button>
                                                            </td>
                                                        </tr>
                                                        <tr class="collapse" id="{{ $detailId }}">
                                                            <td colspan="7" class="p-0">
                                                                <div class="p-2">
                                                                    <div class="small text-muted mb-2">แสดง
                                                                        {{ min($machine->items_total ?? 0, 80) }} จาก
                                                                        {{ $machine->items_total ?? 0 }} รายการ</div>
                                                                    <div class="table-responsive">
                                                                        <table class="table table-bordered table-sm mb-0">
                                                                            <thead>
                                                                                <tr>
                                                                                    <th>WO</th>
                                                                                    <th>{{ $dateTypeText }}</th>
                                                                                    <th>วันที่เปิด WO</th>
                                                                                    <th>กำหนดส่ง</th>
                                                                                    <th class="num">จำนวนสั่งผลิต</th>
                                                                                    <th class="num">ผลิตแล้ว</th>
                                                                                    <th class="num">ค้างผลิต</th>
                                                                                    <th class="num">ชั่วโมงงาน</th>
                                                                                    <th>ลูกค้า</th>
                                                                                </tr>
                                                                            </thead>
                                                                            <tbody>
                                                                                @foreach ($machine->items ?? collect() as $item)
                                                                                    <tr>
                                                                                        <td>{{ $item->workordernumber }}
                                                                                        </td>
                                                                                        <td>{{ $item->filter_date }}</td>
                                                                                        <td>{{ $item->dateopen }}</td>
                                                                                        <td>{{ $item->reqdate }}</td>
                                                                                        <td class="num">
                                                                                            {{ $fmt($item->order_qty, 2) }}
                                                                                        </td>
                                                                                        <td class="num">
                                                                                            {{ $fmt($item->produced_qty, 2) }}
                                                                                        </td>
                                                                                        <td class="num fw-bold">
                                                                                            {{ $fmt($item->balance_qty, 2) }}
                                                                                        </td>
                                                                                        <td class="num">
                                                                                            {{ $fmt($item->required_hours, 2) }}
                                                                                        </td>
                                                                                        <td>{{ $item->customer_name }}</td>
                                                                                    </tr>
                                                                                @endforeach
                                                                            </tbody>
                                                                        </table>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="text-center text-muted py-4">ไม่พบข้อมูลภาระงานเครื่องจักร</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mla-panel">
            <div class="mla-head"><span>สรุปตามช่วงเวลา</span></div>
            <div class="mla-table-wrap">
                <table class="table table-bordered table-sm mla-table mb-0">
                    <thead>
                        <tr>
                            <th>ช่วงเวลา</th>
                            <th class="num">WO</th>
                            <th class="num">ค้างผลิต</th>
                            <th class="num">ชั่วโมงงาน</th>
                            <th class="num">กำลังผลิต ชม.</th>
                            <th class="num">โหลด %</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($buckets as $row)
                            <tr>
                                <td>{{ $row->label }}</td>
                                <td class="num">{{ $fmt($row->order_count) }}</td>
                                <td class="num">{{ $fmt($row->balance_qty, 2) }}</td>
                                <td class="num">{{ $fmt($row->load_hours, 2) }}</td>
                                <td class="num">{{ $fmt($row->capacity_hours, 2) }}</td>
                                <td class="num {{ $loadClass($row->load_pct) }} fw-bold">{{ $fmt($row->load_pct, 2) }}%
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">ไม่พบข้อมูล</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const tomBySelect = new Map();
                document.querySelectorAll('.mla-select').forEach(function(el) {
                    const ts = new TomSelect(el, {
                        plugins: ['remove_button'],
                        maxOptions: 1000,
                        placeholder: el.dataset.placeholder || 'ทั้งหมด',
                        dropdownParent: 'body'
                    });
                    tomBySelect.set(el.name, {
                        el,
                        ts
                    });
                });

                const mlaWcOptions = @json($workcenterOptions ?? []);
                const mlaMcOptions = @json($machineOptions ?? []);
                const sourceSel = tomBySelect.get('source[]');
                const wcSel = tomBySelect.get('workcenter_ids[]');
                const mcSel = tomBySelect.get('machine_ids[]');

                function repopulate(entry, fullOptions) {
                    if (!entry || !sourceSel) return;
                    const ts = entry.ts;
                    const allow = new Set((sourceSel.ts.getValue() || []).map(v => String(v)
                        .toUpperCase()));
                    const current = ts.getValue() || [];
                    const filtered = fullOptions.filter(o => allow.size === 0 || allow.has(String(o
                        .site || '').toUpperCase()));
                    const filteredValues = new Set(filtered.map(o => String(o.value)));

                    ts.clearOptions();
                    filtered.forEach(o => ts.addOption({
                        value: String(o.value),
                        text: o.label,
                        site: o.site
                    }));
                    ts.refreshOptions(false);
                    ts.setValue(current.filter(v => filteredValues.has(String(v))), true);
                }

                function applySourceFilter() {
                    repopulate(wcSel, mlaWcOptions);
                    repopulate(mcSel, mlaMcOptions);
                }

                if (sourceSel) {
                    sourceSel.ts.on('change', applySourceFilter);
                    applySourceFilter();
                }

                const charts = @json($charts ?? []);
                const machineRows = charts.machineLoad || [];
                const bucketRows = charts.bucketLoad || [];

                const loadColor = pct => {
                    const v = parseFloat(pct) || 0;
                    if (v >= 100) return '#dc3545';
                    if (v >= 80) return '#f0ad4e';
                    return '#28a745';
                };
                const fmtNum = n => (Number(n) || 0).toLocaleString(undefined, {
                    maximumFractionDigits: 0
                });

                const wrap = document.getElementById('machineLoadChartWrap');
                if (wrap) {
                    wrap.querySelector('canvas').style.minHeight = Math.max(360, machineRows.length * 28) + 'px';
                }

                const inquiryUrl = @json(route('machine-load.inquiry'));
                const currentFilters = @json($currentFilters);

                const buildInquiryHref = (row) => {
                    const params = new URLSearchParams();
                    Object.entries(currentFilters).forEach(([k, v]) => {
                        if (v !== '' && v !== null && v !== undefined) params.append(k, v);
                    });
                    if (row.site) params.append('source[]', row.site);
                    if (row.workcenter_id) params.append('workcenter_ids[]', row.workcenter_id);
                    if (row.workmachine_id) params.append('machine_ids[]', row.workmachine_id);
                    return inquiryUrl + '?' + params.toString();
                };

                new Chart(document.getElementById('machineLoadChart'), {
                    type: 'bar',
                    data: {
                        labels: machineRows.map(row => row.label),
                        datasets: [{
                            label: 'โหลด %',
                            data: machineRows.map(row => row.load_pct),
                            backgroundColor: machineRows.map(row => loadColor(row.load_pct)),
                            borderColor: machineRows.map(row => loadColor(row.load_pct)),
                            borderWidth: 1
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        onHover: (event, elements) => {
                            event.native.target.style.cursor = elements.length ? 'pointer' :
                                'default';
                        },
                        onClick: (event, elements) => {
                            if (!elements.length) return;
                            const row = machineRows[elements[0].index];
                            if (!row) return;
                            const href = buildInquiryHref(row);
                            if (event.native && (event.native.ctrlKey || event.native.metaKey)) {
                                window.open(href, '_blank');
                            } else {
                                window.location.href = href;
                            }
                        },
                        layout: {
                            padding: {
                                right: 160
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    title: items => (items[0] && machineRows[items[0]
                                        .dataIndex]) ? machineRows[items[0].dataIndex]
                                        .full_label : '',
                                    label: item => 'โหลด: ' + (machineRows[item.dataIndex]
                                        .load_pct || 0) + '%',
                                    afterBody: function(items) {
                                        if (!items.length) return '';
                                        const row = machineRows[items[0].dataIndex] || {};
                                        const lines = [];
                                        if (row.workcenter) lines.push('WC: ' + row.workcenter);
                                        lines.push('ค้างผลิต: ' + fmtNum(row.balance_qty) +
                                            ' กก.');
                                        lines.push('Capacity: ' + fmtNum(row.capacity_qty) +
                                            ' กก.');
                                        if (row.next_available_at) lines.push('รับงานถึง: ' + row
                                            .next_available_at);
                                        return lines;
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                beginAtZero: true,
                                suggestedMax: 120,
                                title: {
                                    display: true,
                                    text: 'โหลด (%)'
                                },
                                ticks: {
                                    callback: v => v + '%'
                                },
                                grid: {
                                    color: ctx => ctx.tick.value === 100 ? '#dc3545' : '#eef2f7'
                                }
                            },
                            y: {
                                ticks: {
                                    autoSkip: false,
                                    font: {
                                        size: 11
                                    }
                                }
                            }
                        }
                    },
                    plugins: [{
                        id: 'machineLoadLabels',
                        afterDatasetsDraw(chart) {
                            const {
                                ctx,
                                chartArea,
                                scales
                            } = chart;
                            const meta = chart.getDatasetMeta(0);
                            ctx.save();
                            ctx.font = '11px sans-serif';
                            ctx.textBaseline = 'middle';
                            meta.data.forEach((bar, i) => {
                                const row = machineRows[i] || {};
                                const txt = fmtNum(row.balance_qty) + ' กก.' + (row
                                    .next_available_at ? ' · ถึง ' + row.next_available_at
                                    .substring(0, 10) : '');
                                ctx.fillStyle = '#333';
                                ctx.textAlign = 'left';
                                ctx.fillText(txt, Math.min(bar.x + 6, chartArea.right + 4),
                                    bar.y);
                            });
                            ctx.restore();
                        }
                    }]
                });

                new Chart(document.getElementById('bucketLoadChart'), {
                    type: 'bar',
                    data: {
                        labels: bucketRows.map(row => row.label),
                        datasets: [{
                                label: 'ชั่วโมงงาน',
                                data: bucketRows.map(row => row.load_hours),
                                backgroundColor: '#dc3545'
                            },
                            {
                                label: 'กำลังผลิต ชม.',
                                data: bucketRows.map(row => row.capacity_hours),
                                backgroundColor: '#198754'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            });
        </script>
    @endpush
@endsection
