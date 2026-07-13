@extends('layouts.layout')

@section('title', 'Production Plan Dashboard')
@section('page-title', 'Production Plan Dashboard')

@section('content')
    @php
        $filters = $filters ?? [];
        $kpis = $kpis ?? [];
        $selectedSources = $filters['source'] ?? [];
        $selectedWorkCenters = array_map('strval', $filters['workcenter_ids'] ?? []);
        $selectedMachines = array_map('strval', $filters['machine_ids'] ?? []);
        $fmt = fn($value, $decimals = 0) => is_numeric($value) ? number_format((float) $value, $decimals) : '-';
    @endphp

    <style>
        .ppd-wrap {
            background: #f6f7f9;
            border-radius: 8px;
            padding: 16px;
        }

        .ppd-panel,
        .ppd-kpi {
            background: #fff;
            border: 1px solid #dde4ec;
            border-radius: 8px;
        }

        .ppd-panel-head {
            padding: 12px 16px;
            border-bottom: 1px solid #e8edf2;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            font-weight: 700;
        }

        .ppd-kpi {
            height: 100%;
            padding: 14px;
        }

        .ppd-kpi .label {
            color: #64748b;
            font-size: .82rem;
        }

        .ppd-kpi .value {
            color: #1f2937;
            font-size: 1.35rem;
            font-weight: 800;
            line-height: 1.2;
            margin-top: 4px;
        }

        .ppd-chart {
            height: 300px;
            padding: 14px;
        }

        .ppd-table-wrap {
            max-height: 620px;
            overflow: auto;
        }

        .ppd-table th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #edf4ff;
            white-space: nowrap;
        }

        .ppd-table td {
            vertical-align: middle;
        }

        .num {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .ppd-timeline {
            padding: 14px;
        }

        .ppd-subtle {
            color: #64748b;
            font-size: .82rem;
        }

        .ppd-loadbar {
            height: 8px;
            min-width: 110px;
            background: #e8eef5;
            border-radius: 999px;
            overflow: hidden;
        }

        .ppd-loadfill {
            height: 100%;
            background: #2563eb;
            border-radius: inherit;
        }

        .ppd-chip {
            display: inline-flex;
            max-width: 100%;
            align-items: center;
            gap: 4px;
            padding: 2px 7px;
            border-radius: 999px;
            background: #eef6ff;
            color: #1f5aa6;
            font-size: .75rem;
            white-space: nowrap;
        }

        .ts-dropdown {
            z-index: 3000 !important;
        }

        @media (max-width: 767.98px) {
            .ppd-wrap {
                padding: 10px;
            }

            .ppd-lane {
                grid-template-columns: 1fr;
                gap: 4px;
                padding: 8px 0;
            }

            .ppd-chart {
                height: 260px;
            }
        }
    </style>

    <div class="ppd-wrap">
        <div class="ppd-panel mb-3">
            <div class="ppd-panel-head">
                <span>ตัวกรองแผนผลิต</span>
                <a href="{{ route('machine-load.plan-dashboard') }}" class="btn btn-sm btn-outline-secondary">
                    <i class="fa-solid fa-rotate-left me-1"></i> ล้างตัวกรอง
                </a>
            </div>
            <form method="GET" action="{{ route('machine-load.plan-dashboard') }}" class="p-3">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">Source</label>
                        <select name="source[]" class="form-select ppd-select" multiple data-placeholder="เลือก source">
                            @foreach ($siteOptions ?? [] as $option)
                                <option value="{{ $option['value'] }}" {{ in_array($option['value'], $selectedSources, true) ? 'selected' : '' }}>
                                    {{ $option['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-5">
                        <label class="form-label">WPLAN</label>
                        <select name="transnumber" class="form-select ppd-select" data-placeholder="เลือกแผนล่าสุด">
                            <option value="">แผนล่าสุด</option>
                            @foreach ($planOptions ?? [] as $option)
                                <option value="{{ $option['value'] }}" {{ ($filters['transnumber'] ?? '') === $option['value'] ? 'selected' : '' }}>
                                    {{ $option['label'] }} {{ $option['startdate'] ? '(' . $option['startdate'] . ')' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">จากวันที่</label>
                        <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">ถึงวันที่</label>
                        <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label">ค้นหา</label>
                        <input type="search" name="q" class="form-control" value="{{ $filters['q'] ?? '' }}" placeholder="WO, เครื่อง, work center, spec">
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label">Work Center</label>
                        <select name="workcenter_ids[]" class="form-select ppd-select" multiple data-placeholder="ทุก Work Center">
                            @foreach ($workcenterOptions ?? [] as $option)
                                <option value="{{ $option['value'] }}" {{ in_array((string) $option['value'], $selectedWorkCenters, true) ? 'selected' : '' }}>
                                    {{ $option['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label">Machine</label>
                        <select name="machine_ids[]" class="form-select ppd-select" multiple data-placeholder="ทุกเครื่อง">
                            @foreach ($machineOptions ?? [] as $option)
                                <option value="{{ $option['value'] }}" {{ in_array((string) $option['value'], $selectedMachines, true) ? 'selected' : '' }}>
                                    {{ $option['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fa-solid fa-filter me-1"></i> แสดงผล
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-2 col-md-4 col-6">
                <div class="ppd-kpi">
                    <div class="label">Operations</div>
                    <div class="value">{{ $fmt($kpis['operation_count'] ?? 0) }}</div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="ppd-kpi">
                    <div class="label">Work Orders</div>
                    <div class="value">{{ $fmt($kpis['workorder_count'] ?? 0) }}</div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="ppd-kpi">
                    <div class="label">Planned Hours</div>
                    <div class="value">{{ $fmt($kpis['planned_hours'] ?? 0, 1) }}</div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="ppd-kpi">
                    <div class="label">Machines</div>
                    <div class="value">{{ $fmt($kpis['machine_count'] ?? 0) }}</div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="ppd-kpi">
                    <div class="label">Overlaps</div>
                    <div class="value {{ ($kpis['overlap_count'] ?? 0) > 0 ? 'text-danger' : '' }}">{{ $fmt($kpis['overlap_count'] ?? 0) }}</div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="ppd-kpi">
                    <div class="label">No Machine</div>
                    <div class="value {{ ($kpis['no_machine_count'] ?? 0) > 0 ? 'text-warning' : '' }}">{{ $fmt($kpis['no_machine_count'] ?? 0) }}</div>
                </div>
            </div>
        </div>

        @if (($kpis['operation_count'] ?? 0) === 0)
            <div class="alert alert-warning">
                ไม่พบข้อมูลแผนตามตัวกรองนี้ ลองเลือก WPLAN อื่น หรือขยายช่วงวันที่
            </div>
        @endif

        <div class="row g-3 mb-3">
            <div class="col-xl-7">
                <div class="ppd-panel h-100">
                    <div class="ppd-panel-head">
                        <span>โหลดรายวัน</span>
                        <span class="ppd-subtle">ชั่วโมงแผน + จำนวน operation</span>
                    </div>
                    <div class="ppd-chart">
                        <canvas id="dailyLoadChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="ppd-panel h-100">
                    <div class="ppd-panel-head">
                        <span>เครื่องที่โหลดสูงสุด</span>
                        <span class="ppd-subtle">Top 20</span>
                    </div>
                    <div class="ppd-chart">
                        <canvas id="machineLoadChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-5">
                <div class="ppd-panel h-100">
                    <div class="ppd-panel-head">
                        <span>Work Center Load</span>
                        <span class="ppd-subtle">Top 15</span>
                    </div>
                    <div class="ppd-chart">
                        <canvas id="workCenterChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-xl-7">
                <div class="ppd-panel h-100">
                    <div class="ppd-panel-head">
                        <span>สรุปโหลดเครื่อง</span>
                        <span class="ppd-subtle">เรียงตามชั่วโมงรวม</span>
                    </div>
                    <div class="ppd-table-wrap">
                        @php
                            $topMachines = collect($machineLoad ?? [])->take(14);
                            $maxMachineHours = max(1, (float) ($topMachines->first()->hours ?? 1));
                        @endphp
                        <table class="table table-sm table-hover ppd-table mb-0">
                            <thead>
                                <tr>
                                    <th>Machine</th>
                                    <th class="num">Jobs</th>
                                    <th class="num">WO</th>
                                    <th class="num">ชม.</th>
                                    <th>Load</th>
                                    <th>ช่วงแผน</th>
                                    <th>วันที่หนักสุด</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($topMachines as $row)
                                    <tr>
                                        <td style="min-width: 210px;">
                                            <div class="fw-semibold">{{ $row->machine_label }}</div>
                                            <div class="ppd-subtle">
                                                @foreach (($row->sample_jobs ?? collect())->take(2) as $job)
                                                    <span class="ppd-chip me-1 mt-1">{{ $job['workorder'] }}</span>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td class="num">{{ $fmt($row->jobs) }}</td>
                                        <td class="num">{{ $fmt($row->workorders) }}</td>
                                        <td class="num fw-semibold">{{ $fmt($row->hours, 1) }}</td>
                                        <td>
                                            <div class="ppd-loadbar" title="{{ $fmt($row->hours, 1) }} ชม.">
                                                <div class="ppd-loadfill" style="width: {{ min(100, ((float) $row->hours / $maxMachineHours) * 100) }}%;"></div>
                                            </div>
                                        </td>
                                        <td class="text-nowrap small">
                                            {{ $row->first_start ? \Carbon\Carbon::parse($row->first_start)->format('d/m') : '-' }}
                                            -
                                            {{ $row->last_end ? \Carbon\Carbon::parse($row->last_end)->format('d/m') : '-' }}
                                        </td>
                                        <td class="text-nowrap small">
                                            {{ $row->peak_day ? \Carbon\Carbon::parse($row->peak_day)->format('d/m/Y') : '-' }}
                                            <span class="text-muted">({{ $fmt($row->peak_hours, 1) }} ชม.)</span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">ไม่มีข้อมูลโหลดเครื่อง</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-7">
                <div class="ppd-panel h-100">
                    <div class="ppd-panel-head">
                        <span>Operation ยาวผิดปกติ</span>
                        <span class="ppd-subtle">ตั้งแต่ 8 ชั่วโมงขึ้นไป</span>
                    </div>
                    <div class="ppd-table-wrap">
                        <table class="table table-sm table-hover ppd-table mb-0">
                            <thead>
                                <tr>
                                    <th>เริ่ม</th>
                                    <th>WO</th>
                                    <th>Machine</th>
                                    <th class="num">ชม.</th>
                                    <th class="num">Qty</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($longOperations ?? [] as $row)
                                    <tr>
                                        <td class="text-nowrap">{{ $row->start_text }}</td>
                                        <td>{{ $row->workorder_label }}</td>
                                        <td>{{ $row->machine_label }}</td>
                                        <td class="num">{{ $fmt($row->duration_hours, 1) }}</td>
                                        <td class="num">{{ $fmt($row->qty, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">ไม่พบ operation ยาวผิดปกติ</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="ppd-panel h-100">
                    <div class="ppd-panel-head">
                        <span>สรุปรายวัน</span>
                        <span class="ppd-subtle">{{ count($dailyLoad ?? []) }} วัน</span>
                    </div>
                    <div class="ppd-table-wrap">
                        <table class="table table-sm table-hover ppd-table mb-0">
                            <thead>
                                <tr>
                                    <th>วันที่</th>
                                    <th class="num">Jobs</th>
                                    <th class="num">ชม.</th>
                                    <th class="num">WO</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($dailyLoad ?? [] as $row)
                                    <tr>
                                        <td>{{ $row->date }}</td>
                                        <td class="num">{{ $fmt($row->jobs) }}</td>
                                        <td class="num">{{ $fmt($row->hours, 1) }}</td>
                                        <td class="num">{{ $fmt($row->workorders) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">ไม่พบข้อมูล</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="ppd-panel">
            <div class="ppd-panel-head">
                <span>รายการแผนผลิต</span>
                <span class="ppd-subtle">แสดงสูงสุด 300 รายการแรก</span>
            </div>
            <div class="ppd-table-wrap">
                <table class="table table-sm table-hover ppd-table mb-0">
                    <thead>
                        <tr>
                            <th>เริ่ม</th>
                            <th>จบ</th>
                            <th>WPLAN</th>
                            <th>WO</th>
                            <th>Work Center</th>
                            <th>Machine</th>
                            <th class="num">Seq</th>
                            <th class="num">ชม.</th>
                            <th class="num">Qty</th>
                            <th>Spec / Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($detailRows ?? [] as $row)
                            <tr>
                                <td class="text-nowrap">{{ $row->start_text }}</td>
                                <td class="text-nowrap">{{ $row->end_text }}</td>
                                <td>{{ $row->source_site }} / {{ $row->transnumber }}</td>
                                <td>{{ $row->workorder_label }}</td>
                                <td>{{ $row->workcenter_label }}</td>
                                <td>{{ $row->machine_label }}</td>
                                <td class="num">{{ $row->workseq }}</td>
                                <td class="num">{{ $fmt($row->duration_hours, 2) }}</td>
                                <td class="num">{{ $fmt($row->qty, 2) }}</td>
                                <td class="small text-muted" style="min-width: 260px;">
                                    {{ \Illuminate\Support\Str::limit(trim((string) ($row->operation_description ?? $row->operation_notes ?? '')), 130) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4">ไม่พบข้อมูลแผนผลิต</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('.ppd-select').forEach((el) => {
            if (window.TomSelect) {
                new TomSelect(el, {
                    plugins: el.multiple ? ['remove_button'] : [],
                    maxOptions: 500,
                    allowEmptyOption: true
                });
            }
        });

        const dailyData = @json($charts['daily'] ?? []);
        const machineData = @json($charts['machine'] ?? []);
        const workCenterData = @json($charts['workcenter'] ?? []);
        const chartNumber = value => {
            const parsed = Number(value);
            return Number.isFinite(parsed) ? parsed : 0;
        };

        const makeChart = (id, labels, datasets, options = {}) => {
            const canvas = document.getElementById(id);
            if (!canvas || !window.Chart) return;
            const normalizedDatasets = datasets.map(dataset => ({
                ...dataset,
                data: (dataset.data || []).map(chartNumber)
            }));
            new Chart(canvas, {
                data: { labels, datasets: normalizedDatasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                title(items) {
                                    const item = items[0];
                                    const full = item?.dataset?.fullLabels?.[item.dataIndex];
                                    return full || item.label;
                                }
                            }
                        }
                    },
                    scales: options.scales || {},
                }
            });
        };

        makeChart('dailyLoadChart', dailyData.map(row => row.label), [
            {
                type: 'bar',
                label: 'Planned Hours',
                data: dailyData.map(row => row.hours),
                backgroundColor: '#2f80ed',
                borderRadius: 4
            },
            {
                type: 'line',
                label: 'Operations',
                data: dailyData.map(row => row.jobs),
                borderColor: '#119c78',
                backgroundColor: '#119c78',
                yAxisID: 'jobs',
                tension: .28
            }
        ], {
            scales: {
                y: { beginAtZero: true, title: { display: true, text: 'Hours' } },
                jobs: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Jobs' } }
            }
        });

        makeChart('machineLoadChart', machineData.map(row => row.label), [
            {
                type: 'bar',
                label: 'Hours',
                data: machineData.map(row => row.hours),
                fullLabels: machineData.map(row => row.full_label),
                backgroundColor: '#7c3aed',
                borderRadius: 4
            }
        ], {
            scales: {
                y: { beginAtZero: true }
            }
        });

        makeChart('workCenterChart', workCenterData.map(row => row.label), [
            {
                type: 'bar',
                label: 'Hours',
                data: workCenterData.map(row => row.hours),
                fullLabels: workCenterData.map(row => row.full_label),
                backgroundColor: '#d97706',
                borderRadius: 4
            }
        ], {
            scales: {
                y: { beginAtZero: true }
            }
        });
    </script>
@endpush
