@extends('layouts.layout')

@section('title', 'Production Status Tracking')
@section('page-title', 'Production Status Tracking')

@section('content')
    @php
        $filters = $filters ?? [];
        $summary = $summary ?? [];
        $insights = $insights ?? [];
        $statusSummary = collect($insights['statusSummary'] ?? []);
        $processSummary = collect($insights['processSummary'] ?? []);
        $watchlist = collect($insights['watchlist'] ?? []);
        $progressBands = collect($insights['progressBands'] ?? []);
        $siteSummary = collect($insights['siteSummary'] ?? []);
        $dueBuckets = collect($insights['dueBuckets'] ?? []);
        $actionSummary = collect($insights['actionSummary'] ?? []);
        $unitSummary = collect($insights['unitSummary'] ?? []);
        $dataQualitySummary = collect($insights['dataQualitySummary'] ?? []);
        $movementSummary = collect($insights['movementSummary'] ?? []);
        $divisionSummary = collect($insights['divisionSummary'] ?? []);
        $fmtDate = fn($value) => $value ? \Carbon\Carbon::parse($value)->format('d/m/Y') : '-';
        $riskClass = fn($risk) => match ($risk) {
            'HIGH' => 'danger',
            'MEDIUM' => 'warning',
            'NO_ROUTE' => 'secondary',
            default => 'success',
        };
        $statusClass = fn($status) => match ($status) {
            'Delayed', 'Late', 'At risk' => 'danger',
            'Watch' => 'warning',
            'Ready', 'Completed' => 'success',
            'No MFG route', 'ไม่พบ Routing' => 'secondary',
            default => 'primary',
        };
        $movementClass = fn($status) => match ($status) {
            'งานนิ่ง', 'ไม่พบ Routing' => 'danger',
            'ยังไม่เริ่ม' => 'warning',
            'เสร็จแล้ว' => 'success',
            default => 'primary',
        };
        $filterQuery = fn($value) => array_merge(request()->except(['page']), ['completion_filter' => $value]);
        $statusQuery = fn($value) => array_merge(request()->except(['page']), ['status_filter' => $value]);
        $movementQuery = fn($value) => array_merge(request()->except(['page']), ['movement_filter' => $value]);
        $processQuery = fn($value) => array_merge(request()->except(['page']), ['process_filter' => $value]);
        $divisionQuery = fn($value) => array_merge(request()->except(['page']), ['keyword' => $value]);
        $clearProcessQuery = request()->except(['page', 'process_filter']);
    @endphp

    <style>
        .pst-wrap {
            background: #f5f7fa;
            border-radius: 8px;
            padding: 16px;
        }

        .pst-panel {
            background: #fff;
            border: 1px solid #dfe5ec;
            border-radius: 8px;
            overflow: hidden;
        }

        .pst-head {
            padding: 12px 16px;
            border-bottom: 1px solid #e8edf2;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            font-weight: 700;
        }

        .pst-kpi {
            height: 100%;
            background: #fff;
            border: 1px solid #dfe5ec;
            border-radius: 8px;
            padding: 14px;
        }

        .pst-kpi .label {
            color: #667085;
            font-size: .82rem;
        }

        .pst-kpi .value {
            color: #1f2937;
            font-size: 1.35rem;
            font-weight: 800;
            line-height: 1.2;
            margin-top: 4px;
        }

        .pst-kpi .hint {
            color: #667085;
            font-size: .78rem;
            margin-top: 2px;
        }

        .pst-board {
            display: grid;
            grid-template-columns: minmax(0, 1.25fr) minmax(300px, .75fr);
            gap: 16px;
            margin-bottom: 16px;
        }

        .pst-lane {
            background: #fff;
            border: 1px solid #dfe5ec;
            border-radius: 8px;
            overflow: hidden;
        }

        .pst-lane-head {
            padding: 12px 14px;
            border-bottom: 1px solid #e8edf2;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .pst-lane-body {
            padding: 14px;
        }

        .pst-status-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }

        .pst-status-tile {
            border: 1px solid #dfe5ec;
            border-radius: 8px;
            padding: 12px;
            min-height: 92px;
        }

        .pst-status-tile .count {
            font-size: 1.4rem;
            font-weight: 800;
            line-height: 1.1;
        }

        .pst-status-tile .caption {
            color: #667085;
            font-size: .82rem;
            margin-top: 4px;
        }

        .pst-progress-rail {
            height: 8px;
            border-radius: 999px;
            background: #edf2f7;
            overflow: hidden;
            margin-top: 10px;
        }

        .pst-progress-fill {
            height: 100%;
            background: #0d6efd;
        }

        .pst-process-row {
            display: grid;
            grid-template-columns: minmax(120px, 1fr) 70px minmax(120px, 1.2fr) 54px;
            gap: 10px;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f0f3f7;
        }

        .pst-process-row:last-child {
            border-bottom: 0;
        }

        .pst-process-link {
            color: #1f2937;
            text-decoration: none;
        }

        .pst-process-link:hover {
            color: #0d6efd;
            text-decoration: underline;
        }

        .pst-bar {
            height: 8px;
            background: #edf2f7;
            border-radius: 999px;
            overflow: hidden;
        }

        .pst-bar span {
            display: block;
            height: 100%;
            background: #2563eb;
        }

        .pst-watch {
            display: grid;
            gap: 10px;
        }

        .pst-watch-scroll {
            max-height: 560px;
            overflow-y: auto;
            padding-right: 4px;
        }

        .pst-watch-item {
            border: 1px solid #dfe5ec;
            border-radius: 8px;
            padding: 11px 12px;
            background: #fff;
        }

        .pst-watch-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }

        .pst-band-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 8px;
            margin-top: 14px;
        }

        .pst-band {
            border: 1px solid #dfe5ec;
            border-radius: 8px;
            padding: 9px;
        }

        .pst-quality-strip {
            display: grid;
            grid-template-columns: minmax(170px, .9fr) minmax(220px, 1.3fr) minmax(220px, 1.3fr) minmax(220px, 1.3fr);
            gap: 12px;
            align-items: center;
            padding: 12px 16px;
        }

        .pst-quality-stat {
            min-width: 0;
        }

        .pst-quality-stat .label {
            color: #667085;
            font-size: .78rem;
        }

        .pst-quality-stat .value {
            color: #1f2937;
            font-size: 1.15rem;
            font-weight: 800;
            line-height: 1.15;
        }

        .pst-quality-stack {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .pst-chip-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .pst-division-wrap {
            max-height: 280px;
            overflow: auto;
        }

        .pst-division-row {
            display: grid;
            grid-template-columns: minmax(90px, 1fr) repeat(5, 74px) minmax(110px, 1.4fr);
            gap: 10px;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f0f3f7;
        }

        .pst-division-row.header {
            color: #667085;
            font-size: .78rem;
            font-weight: 700;
            padding-top: 0;
        }

        .pst-division-row:last-child {
            border-bottom: 0;
        }

        .pst-control-extra {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 14px;
        }

        .pst-mini-panel {
            border: 1px solid #dfe5ec;
            border-radius: 8px;
            padding: 12px;
            background: #fbfcfe;
        }

        .pst-mini-row {
            display: grid;
            grid-template-columns: minmax(86px, 1fr) 48px minmax(90px, 1.1fr);
            gap: 8px;
            align-items: center;
            padding: 5px 0;
        }

        .pst-table-wrap {
            max-height: 640px;
            overflow: auto;
        }

        .pst-table {
            table-layout: fixed;
            min-width: 1510px;
        }

        .pst-table th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #edf4ff;
            white-space: nowrap;
        }

        .pst-table td {
            vertical-align: middle;
        }

        .pst-mfg {
            width: 132px;
        }

        .pst-customer {
            width: 240px;
        }

        .pst-item {
            width: 220px;
            white-space: normal;
        }

        .pst-qty {
            width: 110px;
        }

        .pst-unit {
            width: 72px;
        }

        .pst-date {
            width: 92px;
        }

        .pst-current {
            width: 105px;
        }

        .pst-progress {
            width: 108px;
        }

        .pst-movement {
            width: 138px;
        }

        .pst-remain {
            width: 160px;
            white-space: normal;
        }

        .pst-dp-status-col {
            width: 92px;
        }

        .pst-status-col {
            width: 110px;
        }

        .pst-risk-col {
            width: 92px;
        }

        .pst-actions-col {
            width: 105px;
        }

        .pst-confirm-col {
            width: 280px;
            min-width: 280px;
        }

        .pst-table td.pst-confirm {
            padding-right: 12px;
            vertical-align: top;
        }

        .pst-pin-left {
            position: sticky;
            left: 0;
            background: #fff;
            z-index: 1;
            box-shadow: 6px 0 8px -8px rgba(15, 23, 42, .45);
        }

        .pst-table th.pst-pin-left {
            background: #edf4ff;
            z-index: 3;
        }

        .pst-actions {
            position: sticky;
            right: 0;
            background: #fff;
            z-index: 1;
            box-shadow: -6px 0 8px -8px rgba(15, 23, 42, .45);
        }

        .pst-table th.pst-actions {
            background: #edf4ff;
            z-index: 3;
        }

        .pst-muted {
            color: #667085;
            font-size: .82rem;
        }

        .num {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        @media (max-width: 767.98px) {
            .pst-wrap {
                padding: 10px;
            }

            .pst-head {
                align-items: flex-start;
                flex-direction: column;
            }

            .pst-board {
                grid-template-columns: 1fr;
            }

            .pst-status-grid,
            .pst-band-grid {
                grid-template-columns: 1fr;
            }

            .pst-quality-strip {
                grid-template-columns: 1fr;
            }

            .pst-control-extra {
                grid-template-columns: 1fr;
            }

            .pst-process-row {
                grid-template-columns: 1fr;
                gap: 4px;
            }

            .pst-division-row {
                grid-template-columns: 1fr;
                gap: 4px;
            }
        }
    </style>

    <div class="pst-wrap">
        @if (!empty($dataError))
            <div class="alert alert-warning mb-3">
                <div class="fw-semibold">Live data unavailable</div>
                <div class="small">{{ $dataError }}</div>
            </div>
        @endif

        <div class="row g-3 mb-3">
            <div class="col-md-2 col-6">
                <div class="pst-kpi">
                    <div class="label">MFG in plan</div>
                    <div class="value">{{ number_format($summary['total'] ?? 0) }}</div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="pst-kpi">
                    <div class="label">ยังไม่เสร็จ</div>
                    <div class="value text-warning">{{ number_format($summary['open'] ?? 0) }}</div>
                    <div class="hint">open jobs</div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="pst-kpi">
                    <div class="label">Delayed</div>
                    <div class="value text-danger">{{ number_format($summary['delayed'] ?? 0) }}</div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="pst-kpi">
                    <div class="label">At Risk</div>
                    <div class="value text-danger">{{ number_format($summary['at_risk'] ?? 0) }}</div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="pst-kpi">
                    <div class="label">Overdue</div>
                    <div class="value text-danger">{{ number_format($summary['overdue'] ?? 0) }}</div>
                    <div class="hint">not completed</div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="pst-kpi">
                    <div class="label">Due 1-3 days</div>
                    <div class="value text-primary">{{ number_format($summary['due_soon'] ?? 0) }}</div>
                    <div class="hint">Avg {{ number_format($summary['avg_progress'] ?? 0, 1) }}%</div>
                </div>
            </div>
        </div>

        <div class="pst-panel mb-3">
            <div class="pst-quality-strip">
                <div class="fw-semibold">Data Quality</div>
                @foreach ($dataQualitySummary->whereIn('label', ['ไม่พบ Routing', 'UNKNOWN']) as $quality)
                    <div class="pst-quality-stat">
                        <div class="d-flex align-items-center justify-content-between gap-2">
                            <span class="label">{{ $quality['label'] ?? '-' }}</span>
                            <span
                                class="badge bg-{{ $quality['class'] ?? 'secondary' }}">{{ number_format((float) ($quality['pct'] ?? 0), 1) }}%</span>
                        </div>
                        <div class="value mt-1">{{ number_format($quality['count'] ?? 0) }}</div>
                    </div>
                @endforeach
                <div class="pst-quality-stat">
                    <div class="label mb-2">Site mix</div>
                    <div class="pst-quality-stack">
                        @foreach ($dataQualitySummary->whereIn('label', ['WIRE', 'PLUS']) as $quality)
                            <span class="badge bg-light text-dark border">
                                {{ $quality['label'] ?? '-' }} {{ number_format($quality['count'] ?? 0) }}
                                <span class="text-muted">{{ number_format((float) ($quality['pct'] ?? 0), 1) }}%</span>
                            </span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="pst-panel mb-3">
            <div class="pst-head">
                <span>Filters</span>
                <span class="pst-muted">Delivery Plan + Barcode/ManuCost route progress</span>
            </div>
            <form method="GET" action="{{ route('dp.production-status') }}" class="p-3">
                <input type="hidden" name="completion_filter" value="{{ $filters['completion_filter'] ?? 'all' }}">
                <input type="hidden" name="status_filter" value="{{ $filters['status_filter'] ?? 'all' }}">
                <input type="hidden" name="process_filter" value="{{ $filters['process_filter'] ?? '' }}">
                <input type="hidden" name="movement_filter" value="{{ $filters['movement_filter'] ?? 'all' }}">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">Ship from</label>
                        <input type="date" name="ship_from" class="form-control"
                            value="{{ $filters['ship_from'] ?? '' }}">
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">Ship to</label>
                        <input type="date" name="ship_to" class="form-control" value="{{ $filters['ship_to'] ?? '' }}">
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">Site</label>
                        <select name="site" class="form-select">
                            @foreach ($siteOptions ?? [] as $option)
                                <option value="{{ $option['value'] }}"
                                    {{ ($filters['site'] ?? '') === $option['value'] ? 'selected' : '' }}>
                                    {{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">Risk</label>
                        <select name="risk_status" class="form-select">
                            @foreach ($riskOptions ?? [] as $option)
                                <option value="{{ $option['value'] }}"
                                    {{ ($filters['risk_status'] ?? '') === $option['value'] ? 'selected' : '' }}>
                                    {{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">DP status</label>
                        <select name="delivery_status" class="form-select">
                            @foreach (['NEW' => 'NEW', 'ASSIGN' => 'ASSIGN', 'CLOSED' => 'CLOSED', 'VOID' => 'VOID', 'ALL' => 'ALL'] as $value => $label)
                                <option value="{{ $value }}"
                                    {{ ($filters['delivery_status'] ?? 'NEW') === $value ? 'selected' : '' }}>
                                    {{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">Rows</label>
                        <select name="per_page" class="form-select">
                            @foreach ([50, 100, 200] as $n)
                                <option value="{{ $n }}" {{ (int) ($perPage ?? 50) === $n ? 'selected' : '' }}>
                                    {{ $n }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-8 col-md-8">
                        <label class="form-label">Search</label>
                        <input type="search" name="keyword" class="form-control" value="{{ $filters['keyword'] ?? '' }}"
                            placeholder="MFG, SO, customer, item, sales">
                    </div>
                    <div class="col-lg-4 col-md-4 d-flex gap-2">
                        <button class="btn btn-primary flex-fill" type="submit"><i class="fas fa-filter me-1"></i>
                            Apply</button>
                        <a class="btn btn-outline-success"
                            href="{{ route('dp.production-status.export', request()->query()) }}">
                            <i class="fas fa-file-export me-1"></i> Export
                        </a>
                        <a class="btn btn-outline-secondary" href="{{ route('dp.production-status') }}"><i
                                class="fas fa-rotate-left"></i></a>
                    </div>
                </div>
            </form>
        </div>

        <div class="pst-panel mb-3">
            <div class="pst-head">
                <span>Movement Filter</span>
                <span class="pst-muted">กดเพื่อดูงานตามสถานะเคลื่อนไหว</span>
            </div>
            <div class="p-3 pst-chip-row">
                <a class="btn btn-sm {{ ($filters['movement_filter'] ?? 'all') === 'all' ? 'btn-primary' : 'btn-outline-primary' }}"
                    href="{{ route('dp.production-status', $movementQuery('all')) }}">
                    ทั้งหมด <span class="ms-1">{{ number_format($allRowsCount ?? 0) }}</span>
                </a>
                @foreach ($movementSummary as $movement)
                    <a class="btn btn-sm {{ ($filters['movement_filter'] ?? 'all') === ($movement['value'] ?? '') ? 'btn-' . ($movement['class'] ?? 'secondary') : 'btn-outline-' . ($movement['class'] ?? 'secondary') }}"
                        href="{{ route('dp.production-status', $movementQuery($movement['value'] ?? 'all')) }}">
                        {{ $movement['label'] ?? '-' }}
                        <span class="ms-1">{{ number_format($movement['count'] ?? 0) }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        <div class="pst-board">
            <div class="pst-lane">
                <div class="pst-lane-head">
                    <span>Production Control</span>
                    <span class="pst-muted">{{ number_format($allRowsCount ?? 0) }} MFG</span>
                </div>
                <div class="pst-lane-body">
                    <div class="pst-status-grid">
                        @foreach ($statusSummary as $status)
                            @php
                                $label = $status['label'] ?? '-';
                                $tone =
                                    $label === 'Delayed' ? 'danger' : ($label === 'Completed' ? 'success' : 'primary');
                            @endphp
                            <div class="pst-status-tile">
                                <div class="d-flex align-items-center justify-content-between gap-2">
                                    <span class="badge bg-{{ $tone }}">{{ $label }}</span>
                                    <span class="pst-muted">{{ number_format($status['pct'] ?? 0, 1) }}%</span>
                                </div>
                                <div class="count mt-2">{{ number_format($status['count'] ?? 0) }}</div>
                                <div class="caption">jobs in current filter</div>
                                <div class="pst-progress-rail">
                                    <div class="pst-progress-fill bg-{{ $tone }}"
                                        style="width: {{ max(0, min(100, (float) ($status['pct'] ?? 0))) }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="pst-band-grid">
                        @foreach ($progressBands as $band)
                            <div class="pst-band">
                                <div class="fw-semibold">{{ $band['label'] }}</div>
                                <div class="d-flex align-items-end justify-content-between gap-2">
                                    <span class="fs-5 fw-bold">{{ number_format($band['count'] ?? 0) }}</span>
                                    <span class="pst-muted">{{ number_format($band['pct'] ?? 0, 1) }}%</span>
                                </div>
                                <div class="pst-bar mt-2">
                                    <span style="width: {{ max(0, min(100, (float) ($band['pct'] ?? 0))) }}%"></span>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-3">
                        @foreach ($siteSummary as $site)
                            <span class="badge bg-light text-dark border">
                                {{ $site['site'] }}: {{ number_format($site['count'] ?? 0) }}
                                <span class="text-muted">open {{ number_format($site['open'] ?? 0) }}</span>
                            </span>
                        @endforeach
                    </div>

                    <div class="pst-control-extra">
                        <div class="pst-mini-panel">
                            <div class="fw-semibold mb-2">Due Pressure</div>
                            @foreach ($dueBuckets as $bucket)
                                <div class="pst-mini-row">
                                    <span class="pst-muted">{{ $bucket['label'] }}</span>
                                    <span class="fw-semibold num">{{ number_format($bucket['count'] ?? 0) }}</span>
                                    <div class="pst-bar">
                                        <span class="bg-{{ $bucket['class'] ?? 'primary' }}"
                                            style="width: {{ max(0, min(100, (float) ($bucket['pct'] ?? 0))) }}%"></span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="pst-mini-panel">
                            <div class="fw-semibold mb-2">Action Needed</div>
                            @foreach ($actionSummary as $action)
                                <div class="pst-mini-row">
                                    <span class="pst-muted">{{ $action['label'] }}</span>
                                    <span class="fw-semibold num">{{ number_format($action['count'] ?? 0) }}</span>
                                    <div class="pst-bar">
                                        <span class="bg-{{ $action['class'] ?? 'primary' }}"
                                            style="width: {{ max(0, min(100, (float) ($action['pct'] ?? 0))) }}%"></span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="pst-mini-panel">
                            <div class="fw-semibold mb-2">Sales Unit</div>
                            @foreach ($unitSummary as $unit)
                                <div class="pst-mini-row">
                                    <span class="pst-muted">{{ $unit['unit'] }}</span>
                                    <span class="fw-semibold num">{{ number_format($unit['count'] ?? 0) }}</span>
                                    <div>
                                        <div class="pst-bar">
                                            <span
                                                style="width: {{ max(0, min(100, (float) ($unit['pct'] ?? 0))) }}%"></span>
                                        </div>
                                        <div class="pst-muted mt-1">
                                            @if (($unit['unit'] ?? '') === 'kg')
                                                {{ number_format((float) ($unit['qty_kg'] ?? 0), 0) }} kg
                                            @elseif (in_array($unit['unit'] ?? '', ['เส้น', 'ชิ้น'], true))
                                                {{ number_format((float) ($unit['line_qty'] ?? 0), 0) }}
                                                {{ $unit['unit'] }}
                                            @else
                                                {{ number_format((float) ($unit['qty_kg'] ?? 0), 0) }} kg /
                                                {{ number_format((float) ($unit['line_qty'] ?? 0), 0) }} เส้น
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="pst-lane">
                <div class="pst-lane-head">
                    <span>Action Queue</span>
                    <a class="btn btn-sm btn-outline-danger"
                        href="{{ route('dp.production-status', $statusQuery('delayed')) }}">Delayed</a>
                </div>
                <div class="pst-lane-body pst-watch-scroll">
                    <div class="pst-watch">
                        @forelse ($watchlist as $item)
                            <div class="pst-watch-item">
                                <div class="pst-watch-top">
                                    <div>
                                        <div class="fw-semibold">{{ $item->mfg_no }}</div>
                                        <div class="pst-muted">{{ $item->customer ?: '-' }}</div>
                                    </div>
                                    <span
                                        class="badge bg-{{ $statusClass($item->delivery_status) }}">{{ $item->delivery_status }}</span>
                                </div>
                                <div class="mt-2 small">{{ \Illuminate\Support\Str::limit($item->item ?: '-', 78) }}</div>
                                <div class="d-flex flex-wrap gap-1 mt-2">
                                    @foreach ($item->watch_reasons ?? [] as $reason)
                                        <span class="badge bg-light text-dark border">{{ $reason }}</span>
                                    @endforeach
                                </div>
                                <div class="d-flex align-items-center justify-content-between gap-2 mt-2">
                                    <span class="pst-muted">Ship {{ $fmtDate($item->ship_date ?? null) }}</span>
                                    <span class="pst-muted">{{ number_format((float) $item->progress_pct, 1) }}%</span>
                                </div>
                                <div class="pst-bar mt-1">
                                    <span style="width: {{ max(0, min(100, (float) $item->progress_pct)) }}%"></span>
                                </div>
                            </div>
                        @empty
                            <div class="text-muted text-center py-3">No open watchlist items.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="pst-lane mb-3">
            <div class="pst-lane-head">
                <span>Division Summary</span>
                <span class="pst-muted">สรุปปัญหาแยกตาม Division</span>
            </div>
            <div class="pst-lane-body">
                <div class="pst-division-wrap">
                    <div class="pst-division-row header">
                        <div>Division</div>
                        <div class="num">Total</div>
                        <div class="num">Open</div>
                        <div class="num">Delayed</div>
                        <div class="num">No Route</div>
                        <div class="num">งานนิ่ง</div>
                        <div>Avg Progress</div>
                    </div>
                    @php
                        $divisionLabelMap = [
                            'D1'  => 'D1 - ดิลก + ขวัญเรือน',
                            'D2'  => 'D2 - ปรียาพรรณ + นิตยา',
                            'D3'  => 'D3 - ภควดี + ธนัชชา',
                            'D5'  => 'D5 - ธัธลิญา + เฌอร์ลิญา',
                            'D6'  => 'D6 - สุรศักดิ์ + คณัญญ์นิชา',
                            'D7'  => 'D7 - ศิรินภา + มนพัทธ์',
                            'D8'  => 'D8 - สาธิต + สุธาสินี',
                            'D9'  => 'D9 - วรเดชา + ลัดดาวัลย์',
                            'PLN' => 'PLN - วางแผน - กันยกร',
                        ];
                    @endphp
                    @forelse ($divisionSummary as $division)
                        @php
                            $divCode = $division['division'] ?? 'UNKNOWN';
                            $divLabel = $divisionLabelMap[$divCode] ?? $divCode;
                        @endphp
                        <div class="pst-division-row">
                            <div class="fw-semibold">
                                <a class="pst-process-link"
                                    href="{{ route('dp.production-status', $divisionQuery($divCode)) }}">
                                    {{ $divLabel }}
                                </a>
                            </div>
                            <div class="num">{{ number_format($division['total'] ?? 0) }}</div>
                            <div class="num">{{ number_format($division['open'] ?? 0) }}</div>
                            <div class="num">
                                @if (($division['delayed'] ?? 0) > 0)
                                    <span class="badge bg-danger">{{ number_format($division['delayed']) }}</span>
                                @else
                                    <span class="pst-muted">0</span>
                                @endif
                            </div>
                            <div class="num">
                                @if (($division['no_route'] ?? 0) > 0)
                                    <span class="badge bg-secondary">{{ number_format($division['no_route']) }}</span>
                                @else
                                    <span class="pst-muted">0</span>
                                @endif
                            </div>
                            <div class="num">
                                @if (($division['stale'] ?? 0) > 0)
                                    <span
                                        class="badge bg-warning text-dark">{{ number_format($division['stale']) }}</span>
                                @else
                                    <span class="pst-muted">0</span>
                                @endif
                            </div>
                            <div>
                                <div class="d-flex align-items-center justify-content-between gap-2">
                                    <span
                                        class="pst-muted">{{ number_format((float) ($division['avg_progress'] ?? 0), 1) }}%</span>
                                    @if (($division['at_risk'] ?? 0) > 0)
                                        <span class="badge bg-danger">{{ number_format($division['at_risk']) }}
                                            risk</span>
                                    @endif
                                </div>
                                <div class="pst-bar mt-1">
                                    <span
                                        style="width: {{ max(0, min(100, (float) ($division['avg_progress'] ?? 0))) }}%"></span>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="text-muted text-center py-3">No division data.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="pst-lane mb-3">
            <div class="pst-lane-head">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span>Current Process Load</span>
                    @if (!empty($filters['process_filter']))
                        <span class="badge bg-primary">{{ $filters['process_filter'] }}</span>
                        <a class="btn btn-sm btn-outline-secondary"
                            href="{{ route('dp.production-status', $clearProcessQuery) }}">Clear</a>
                    @endif
                </div>
                <span class="pst-muted">Click station to filter</span>
            </div>
            <div class="pst-lane-body">
                @forelse ($processSummary as $process)
                    @php
                        $pct = ($allRowsCount ?? 0) > 0 ? (($process['count'] ?? 0) / max(1, $allRowsCount)) * 100 : 0;
                    @endphp
                    <div class="pst-process-row">
                        <div class="fw-semibold">
                            <a class="pst-process-link"
                                href="{{ route('dp.production-status', $processQuery($process['process'])) }}">
                                {{ $process['process'] }}
                            </a>
                        </div>
                        <div class="num">{{ number_format($process['count'] ?? 0) }}</div>
                        <div class="pst-bar"><span style="width: {{ max(0, min(100, $pct)) }}%"></span></div>
                        <div class="text-end">
                            @if (($process['at_risk'] ?? 0) > 0)
                                <span class="badge bg-danger">{{ $process['at_risk'] }}</span>
                            @else
                                <span class="pst-muted">{{ number_format($process['avg_progress'] ?? 0, 1) }}%</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="text-muted text-center py-3">No current process data.</div>
                @endforelse
            </div>
        </div>

        <div class="pst-panel">
            <div class="pst-head">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span>Dashboard Summary</span>
                    @if (!empty($filters['process_filter']))
                        <span class="badge bg-primary">Process: {{ $filters['process_filter'] }}</span>
                        <a class="btn btn-sm btn-outline-secondary"
                            href="{{ route('dp.production-status', $clearProcessQuery) }}">Clear process</a>
                    @endif
                    <a class="btn btn-sm {{ ($filters['completion_filter'] ?? 'all') === 'all' ? 'btn-primary' : 'btn-outline-primary' }}"
                        href="{{ route('dp.production-status', $filterQuery('all')) }}">All</a>
                    <a class="btn btn-sm {{ ($filters['completion_filter'] ?? 'all') === 'open' ? 'btn-warning' : 'btn-outline-warning' }}"
                        href="{{ route('dp.production-status', $filterQuery('open')) }}">
                        <i class="fas fa-clock me-1"></i> ยังไม่เสร็จ
                        <span class="ms-1">{{ number_format($summary['open'] ?? 0) }}</span>
                    </a>
                    <a class="btn btn-sm {{ ($filters['completion_filter'] ?? 'all') === 'completed' ? 'btn-success' : 'btn-outline-success' }}"
                        href="{{ route('dp.production-status', $filterQuery('completed')) }}">
                        <i class="fas fa-check me-1"></i> Completed
                        <span class="ms-1">{{ number_format($summary['completed'] ?? 0) }}</span>
                    </a>
                    <a class="btn btn-sm {{ ($filters['status_filter'] ?? 'all') === 'delayed' ? 'btn-danger' : 'btn-outline-danger' }}"
                        href="{{ route('dp.production-status', $statusQuery('delayed')) }}">
                        <i class="fas fa-triangle-exclamation me-1"></i> Delayed
                        <span class="ms-1">{{ number_format($summary['delayed'] ?? 0) }}</span>
                    </a>
                    <a class="btn btn-sm {{ ($filters['status_filter'] ?? 'all') === 'at_risk' ? 'btn-danger' : 'btn-outline-danger' }}"
                        href="{{ route('dp.production-status', $statusQuery('at_risk')) }}">
                        <i class="fas fa-bolt me-1"></i> At Risk
                        <span class="ms-1">{{ number_format($summary['at_risk'] ?? 0) }}</span>
                    </a>
                </div>
                <span class="pst-muted">{{ number_format($allRowsCount ?? 0) }} rows</span>
            </div>
            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-light">
                <div class="small text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    รายการที่ยังไม่ได้บันทึก จะถือว่า <b>"รอวางแผนยืนยัน"</b> — กดปุ่มขวาเพื่อยืนยัน Confirm ทุกรายการที่ยังว่างในหน้านี้
                </div>
                <button type="button" class="btn btn-sm btn-success" id="pstBulkConfirmBtn">
                    <i class="fas fa-check-double me-1"></i>
                    Confirm Delivery (ทุกรายการที่ยังไม่ยืนยัน)
                    <span class="badge bg-light text-success ms-1" id="pstBulkPendingCount">0</span>
                </button>
            </div>
            <div class="pst-table-wrap">
                <table class="table table-sm table-hover align-middle mb-0 pst-table">
                    <colgroup>
                        <col class="pst-mfg">
                        <col class="pst-customer">
                        <col class="pst-item">
                        <col class="pst-qty">
                        <col class="pst-unit">
                        <col class="pst-date">
                        <col class="pst-date">
                        <col class="pst-current">
                        <col class="pst-progress">
                        <col class="pst-movement">
                        <col class="pst-remain">
                        <col class="pst-dp-status-col">
                        <col class="pst-status-col">
                        <col class="pst-risk-col">
                        <col class="pst-confirm-col">
                        <col class="pst-actions-col">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="pst-pin-left">Mfg No.</th>
                            <th class="pst-customer">ลูกค้า</th>
                            <th class="pst-item">สินค้า</th>
                            <th class="num">จำนวน</th>
                            <th>Sale By</th>
                            <th>วันส่ง</th>
                            <th>กำหนดผลิต</th>
                            <th>ขั้นตอนปัจจุบัน</th>
                            <th>ความคืบหน้า</th>
                            <th>เคลื่อนไหวล่าสุด</th>
                            <th>ขั้นตอนที่เหลือ</th>
                            <th>สถานะ DP</th>
                            <th>สถานะผลิต</th>
                            <th>ความเสี่ยง</th>
                            <th style="min-width:240px;">Delivery Confirmation</th>
                            <th class="pst-actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $seenSoQty = []; @endphp
                        @forelse ($rows as $row)
                            @php
                                $row = is_array($row) ? (object) $row : $row;
                                if (!(($row->remaining_process ?? null) instanceof \Illuminate\Support\Collection)) {
                                    $row->remaining_process = collect($row->remaining_process ?? []);
                                }
                                $soKey = trim((string) ($row->so_number ?? ''));
                                $showQty = $soKey === '' || !isset($seenSoQty[$soKey]);
                                if ($soKey !== '') { $seenSoQty[$soKey] = true; }
                            @endphp
                            <tr>
                                <td class="pst-pin-left">
                                    <div class="fw-semibold">{{ $row->mfg_no ?: '-' }}</div>
                                    <div class="pst-muted">{{ $row->site ?: '-' }}
                                        {{ $row->so_number ? '| SO ' . $row->so_number : '' }}</div>
                                </td>
                                <td class="pst-customer">
                                    <div>{{ $row->customer ?: '-' }}</div>
                                    <div class="pst-muted">{{ $row->customernumber ?: '' }}</div>
                                </td>
                                <td>
                                    <div class="fw-semibold">{{ $row->item ?: '-' }}</div>
                                    <div class="pst-muted">{{ $row->item_desc ?: '' }}</div>
                                </td>
                                <td class="num">
                                    @if ($showQty)
                                        {{ $row->qty_display ?? '-' }}
                                    @else
                                        <span class="pst-muted" title="รวมอยู่ใน SO {{ $soKey }} แถวบนแล้ว">—</span>
                                    @endif
                                </td>
                                <td><span class="badge bg-light text-dark border">{{ $row->sale_type ?? '-' }}</span>
                                </td>
                                <td>{{ $fmtDate($row->ship_date ?? null) }}</td>
                                <td>
                                    <div>{{ $fmtDate($row->due_date) }}</div>
                                    <div class="pst-muted">
                                        {{ is_numeric($row->days_to_due) ? $row->days_to_due . ' days' : '-' }}
                                    </div>
                                </td>
                                <td>{{ $row->current_process ?: '-' }}</td>
                                <td class="pst-progress">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress flex-grow-1" style="height:8px;">
                                            <div class="progress-bar"
                                                style="width: {{ max(0, min(100, (int) $row->progress_pct)) }}%"></div>
                                        </div>
                                        <span
                                            class="pst-muted">{{ number_format((float) $row->progress_pct, 1) }}%</span>
                                    </div>
                                    <div class="pst-muted">{{ $row->step_text ?? '' }}</div>
                                </td>
                                <td class="pst-movement">
                                    <span
                                        class="badge bg-{{ $movementClass($row->movement_status ?? '') }}">{{ $row->movement_status ?? '-' }}</span>
                                    <div class="pst-muted mt-1">
                                        {{ !empty($row->last_receive_at) ? \Carbon\Carbon::parse($row->last_receive_at)->format('d/m H:i') : '-' }}
                                    </div>
                                    @if (!empty($row->last_receive_process) && $row->last_receive_process !== '-')
                                        <div class="pst-muted">
                                            {{ \Illuminate\Support\Str::limit($row->last_receive_process, 22) }}</div>
                                    @endif
                                </td>
                                <td class="pst-remain">
                                    @if ($row->remaining_process->isEmpty())
                                        <span class="text-success">เสร็จแล้ว</span>
                                    @else
                                        {{ $row->remaining_process->take(3)->implode(', ') }}
                                        @if ($row->remaining_process->count() > 3)
                                            <span class="pst-muted">+{{ $row->remaining_process->count() - 3 }}</span>
                                        @endif
                                    @endif
                                </td>
                                <td><span class="badge bg-light text-dark border">{{ $row->dp_status ?? '-' }}</span>
                                </td>
                                <td><span
                                        class="badge bg-{{ $statusClass($row->delivery_status) }}">{{ $row->delivery_status }}</span>
                                </td>
                                <td><span
                                        class="badge bg-{{ $riskClass($row->risk_status) }}">{{ $row->risk_display ?? $row->risk_status }}</span>
                                </td>
                                @php
                                    $confStatus = $row->confirmation_status ?? null;
                                    $confLabel = $row->confirmation_status_label ?? '-';
                                    $confBadge = $row->confirmation_badge_class ?? 'light text-dark border';
                                    $confNewDate = !empty($row->confirmation_new_delivery_date)
                                        ? \Carbon\Carbon::parse($row->confirmation_new_delivery_date)->format('Y-m-d')
                                        : '';
                                    $confNewDateDisp = $confNewDate
                                        ? \Carbon\Carbon::parse($confNewDate)->format('d/m/Y')
                                        : '';
                                    $origShip = !empty($row->ship_date)
                                        ? \Carbon\Carbon::parse($row->ship_date)->format('Y-m-d')
                                        : '';
                                @endphp
                                <td class="pst-confirm">
                                    <form class="pst-confirm-form d-flex flex-column gap-1"
                                        data-mfg="{{ $row->mfg_no }}"
                                        data-site="{{ $row->site }}"
                                        data-so="{{ $row->so_number ?? '' }}"
                                        data-orig-ship="{{ $origShip }}"
                                        data-saved="{{ $confStatus ? '1' : '0' }}">
                                        <div class="d-flex align-items-center gap-1 flex-wrap">
                                            <select class="form-select form-select-sm pst-confirm-status" style="width:130px;flex:0 0 130px;">
                                                <option value="CONFIRM" {{ !$confStatus || $confStatus === 'CONFIRM' ? 'selected' : '' }}>Confirm Delivery</option>
                                                <option value="POSTPONE" {{ $confStatus === 'POSTPONE' ? 'selected' : '' }}>Request Postpone</option>
                                            </select>
                                            <input type="date" class="form-control form-control-sm pst-confirm-date"
                                                value="{{ $confNewDate }}"
                                                min="{{ $origShip ? \Carbon\Carbon::parse($origShip)->addDay()->format('Y-m-d') : '' }}"
                                                style="width:130px;flex:0 0 130px; {{ $confStatus === 'POSTPONE' ? '' : 'display:none;' }}">
                                            <button type="button" class="btn btn-sm btn-primary pst-confirm-save" title="บันทึก">
                                                <i class="fas fa-save"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary pst-confirm-history" title="ประวัติ">
                                                <i class="fas fa-clock-rotate-left"></i>
                                            </button>
                                        </div>
                                        <div class="pst-confirm-status-display">
                                            @if ($confStatus)
                                                <span class="badge bg-{{ $confBadge }}">{{ $confLabel }}</span>
                                                @if ($confStatus === 'POSTPONE' && $confNewDateDisp)
                                                    <span class="pst-muted">→ {{ $confNewDateDisp }}</span>
                                                @endif
                                                @if (!empty($row->confirmation_confirmed_at))
                                                    <div class="pst-muted small">
                                                        บันทึก {{ \Carbon\Carbon::parse($row->confirmation_confirmed_at)->format('d/m H:i') }}
                                                        @if (!empty($row->confirmation_confirmed_by))
                                                            โดย {{ $row->confirmation_confirmed_by }}
                                                        @endif
                                                    </div>
                                                @endif
                                            @else
                                                <span class="badge bg-secondary">รอวางแผนยืนยัน</span>
                                                <span class="pst-muted small">(ถือว่าส่งได้ตามแผน)</span>
                                            @endif
                                        </div>
                                    </form>
                                </td>
                                <td class="text-end pst-actions">
                                    <a class="btn btn-sm btn-outline-primary"
                                        href="{{ route('dp.production-status.detail', ['mfg_no' => $row->mfg_no, 'site' => $row->site, 'return_url' => request()->fullUrl()]) }}">
                                        <i class="fas fa-list-check me-1"></i> รายละเอียด
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="16" class="text-center text-muted py-4">ไม่พบข้อมูล Production Status
                                    ตามเงื่อนไขที่เลือก</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-3">
                {{ $rows->links() }}
            </div>
        </div>
    </div>

    <div class="modal fade" id="pstConfirmHistoryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">ประวัติ Delivery Confirmation <small class="text-muted" id="pstHistMfg"></small></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="pstHistBody">
                        <div class="text-center text-muted py-4">กำลังโหลด...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        .pst-confirm-form .pst-confirm-status-display { font-size: 12px; }
        .pst-confirm-form .pst-confirm-status-display .pst-muted { color: #6b7280; }
    </style>

    <script>
        (function () {
            const SAVE_URL = @json(route('dp.production-status.confirm'));
            const BULK_URL = @json(route('dp.production-status.confirm.bulk'));
            const HIST_URL = @json(route('dp.production-status.confirm.history'));
            const CSRF = @json(csrf_token());

            function notify(msg, ok) {
                if (window.Swal) {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: ok ? 'success' : 'error',
                        title: msg,
                        showConfirmButton: false,
                        timer: ok ? 2200 : 3200,
                        timerProgressBar: true,
                    });
                } else if (window.toastr) {
                    ok ? toastr.success(msg) : toastr.error(msg);
                } else {
                    alert(msg);
                }
            }

            function confirmDialog(opts) {
                if (!window.Swal) {
                    return Promise.resolve(window.confirm(opts.text || 'ยืนยัน?'));
                }
                return Swal.fire({
                    title: opts.title || 'ยืนยันการบันทึก',
                    html: opts.html || opts.text || '',
                    icon: opts.icon || 'question',
                    showCancelButton: true,
                    confirmButtonText: opts.confirmText || 'บันทึก',
                    cancelButtonText: 'ยกเลิก',
                    confirmButtonColor: opts.confirmColor || '#0d6efd',
                    cancelButtonColor: '#6c757d',
                    reverseButtons: true,
                }).then(r => r.isConfirmed);
            }

            function pendingForms() {
                return Array.from(document.querySelectorAll('.pst-confirm-form'))
                    .filter(f => f.dataset.saved !== '1');
            }

            function refreshPendingCount() {
                const el = document.getElementById('pstBulkPendingCount');
                if (el) el.textContent = pendingForms().length;
            }

            document.querySelectorAll('.pst-confirm-form').forEach(function (form) {
                const select = form.querySelector('.pst-confirm-status');
                const dateInp = form.querySelector('.pst-confirm-date');
                const saveBtn = form.querySelector('.pst-confirm-save');
                const histBtn = form.querySelector('.pst-confirm-history');
                const display = form.querySelector('.pst-confirm-status-display');

                select.addEventListener('change', function () {
                    if (select.value === 'POSTPONE') {
                        dateInp.style.display = '';
                    } else {
                        dateInp.style.display = 'none';
                        dateInp.value = '';
                    }
                });

                saveBtn.addEventListener('click', async function () {
                    const status = select.value;
                    if (!status) {
                        notify('กรุณาเลือกสถานะ', false);
                        return;
                    }
                    if (status === 'POSTPONE' && !dateInp.value) {
                        notify('กรุณาระบุ New Delivery Date', false);
                        return;
                    }

                    const mfg = form.dataset.mfg || '-';
                    const origShip = form.dataset.origShip || '';
                    const fmt = (d) => {
                        if (!d) return '-';
                        const [y, m, day] = d.split('-');
                        return day + '/' + m + '/' + y;
                    };

                    let html = '';
                    if (status === 'CONFIRM') {
                        html = '<div class="text-start">'
                            + '<div><b>MFG:</b> ' + mfg + '</div>'
                            + '<div><b>วันส่งเดิม:</b> ' + fmt(origShip) + '</div>'
                            + '<div class="mt-2 text-success"><b>ยืนยันส่งได้ตามกำหนดเดิม</b></div>'
                            + '</div>';
                    } else {
                        html = '<div class="text-start">'
                            + '<div><b>MFG:</b> ' + mfg + '</div>'
                            + '<div><b>วันส่งเดิม:</b> ' + fmt(origShip) + '</div>'
                            + '<div class="mt-2 text-warning"><b>ขอเลื่อนส่งเป็น:</b> ' + fmt(dateInp.value) + '</div>'
                            + '</div>';
                    }

                    const ok = await confirmDialog({
                        title: status === 'CONFIRM' ? 'ยืนยันการส่งมอบ' : 'ยืนยันขอเลื่อนส่ง',
                        html: html,
                        icon: status === 'CONFIRM' ? 'success' : 'warning',
                        confirmText: 'บันทึก',
                        confirmColor: status === 'CONFIRM' ? '#198754' : '#fd7e14',
                    });
                    if (!ok) return;

                    saveBtn.disabled = true;
                    if (window.Swal) {
                        Swal.fire({
                            title: 'กำลังบันทึก...',
                            allowOutsideClick: false,
                            didOpen: () => Swal.showLoading(),
                        });
                    }

                    const fd = new FormData();
                    fd.append('_token', CSRF);
                    fd.append('mfg_no', form.dataset.mfg || '');
                    fd.append('site', form.dataset.site || '');
                    fd.append('so_number', form.dataset.so || '');
                    fd.append('confirmation_status', status);
                    fd.append('original_ship_date', form.dataset.origShip || '');
                    if (status === 'POSTPONE') {
                        fd.append('new_delivery_date', dateInp.value);
                    }

                    fetch(SAVE_URL, {
                        method: 'POST',
                        body: fd,
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                        credentials: 'same-origin'
                    })
                        .then(r => r.json().then(j => ({ status: r.status, json: j })))
                        .then(({ status: code, json }) => {
                            if (window.Swal) Swal.close();
                            if (!json.ok) {
                                notify(json.message || 'บันทึกไม่สำเร็จ', false);
                                return;
                            }
                            notify(json.message || 'บันทึกแล้ว', true);
                            const d = json.data || {};
                            let html = '<span class="badge bg-' + (d.badge_class || 'secondary') + '">' + (d.status_label || '-') + '</span>';
                            if (d.confirmation_status === 'POSTPONE' && d.new_delivery_date_display) {
                                html += ' <span class="pst-muted">→ ' + d.new_delivery_date_display + '</span>';
                            }
                            if (d.confirmed_at) {
                                html += '<div class="pst-muted small">บันทึก ' + d.confirmed_at + (d.confirmed_by_name ? ' โดย ' + d.confirmed_by_name : '') + '</div>';
                            }
                            display.innerHTML = html;
                            form.dataset.saved = '1';
                            refreshPendingCount();
                        })
                        .catch(() => {
                            if (window.Swal) Swal.close();
                            notify('เกิดข้อผิดพลาดในการบันทึก', false);
                        })
                        .finally(() => { saveBtn.disabled = false; });
                });

                // refresh pending count when status changes (e.g. switched to POSTPONE → no longer "pending bulk")
                select.addEventListener('change', refreshPendingCount);

                histBtn.addEventListener('click', function () {
                    const mfg = form.dataset.mfg || '';
                    const site = form.dataset.site || '';
                    document.getElementById('pstHistMfg').textContent = mfg + (site ? ' (' + site + ')' : '');
                    const body = document.getElementById('pstHistBody');
                    body.innerHTML = '<div class="text-center text-muted py-4">กำลังโหลด...</div>';
                    new bootstrap.Modal(document.getElementById('pstConfirmHistoryModal')).show();

                    const url = HIST_URL + '?mfg_no=' + encodeURIComponent(mfg) + '&site=' + encodeURIComponent(site);
                    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                        .then(r => r.json())
                        .then(j => {
                            if (!j.ok || !j.rows || j.rows.length === 0) {
                                body.innerHTML = '<div class="text-center text-muted py-4">ยังไม่มีประวัติ</div>';
                                return;
                            }
                            let html = '<div class="table-responsive"><table class="table table-sm table-bordered align-middle">';
                            html += '<thead class="table-light"><tr><th>วันที่บันทึก</th><th>สถานะ</th><th>วันส่งเดิม</th><th>วันส่งใหม่</th><th>ผู้บันทึก</th><th>หมายเหตุ</th></tr></thead><tbody>';
                            j.rows.forEach(function (r) {
                                html += '<tr>';
                                html += '<td>' + (r.confirmed_at || '-') + '</td>';
                                html += '<td><span class="badge bg-' + (r.badge_class || 'secondary') + '">' + (r.status_label || '-') + '</span></td>';
                                html += '<td>' + (r.original_ship_date || '-') + '</td>';
                                html += '<td>' + (r.new_delivery_date || '-') + '</td>';
                                html += '<td>' + (r.confirmed_by_name || '-') + '</td>';
                                html += '<td>' + (r.remark || '-') + '</td>';
                                html += '</tr>';
                            });
                            html += '</tbody></table></div>';
                            body.innerHTML = html;
                        })
                        .catch(() => {
                            body.innerHTML = '<div class="text-center text-danger py-4">โหลดประวัติไม่สำเร็จ</div>';
                        });
                });
            });

            // Bulk confirm button
            const bulkBtn = document.getElementById('pstBulkConfirmBtn');
            if (bulkBtn) {
                bulkBtn.addEventListener('click', async function () {
                    const forms = pendingForms().filter(f => (f.querySelector('.pst-confirm-status') || {}).value === 'CONFIRM');
                    if (forms.length === 0) {
                        notify('ไม่มีรายการที่รอยืนยัน (รายการที่เลือก Postpone จะไม่ถูก bulk save)', false);
                        return;
                    }

                    const ok = await confirmDialog({
                        title: 'ยืนยันส่งได้ตามแผน (Bulk)',
                        html: '<div class="text-start">'
                            + '<div>จะบันทึก <b class="text-success">Confirm Delivery</b> ทั้งหมด <b>' + forms.length + '</b> รายการที่ยังไม่ได้ยืนยัน</div>'
                            + '<div class="text-muted small mt-2">รายการที่เลือก "Request Postpone" จะไม่ถูกบันทึก ต้องกด Save แยกทีละแถว</div>'
                            + '</div>',
                        icon: 'success',
                        confirmText: 'บันทึกทั้งหมด',
                        confirmColor: '#198754',
                    });
                    if (!ok) return;

                    bulkBtn.disabled = true;
                    if (window.Swal) {
                        Swal.fire({
                            title: 'กำลังบันทึก ' + forms.length + ' รายการ...',
                            allowOutsideClick: false,
                            didOpen: () => Swal.showLoading(),
                        });
                    }

                    const fd = new FormData();
                    fd.append('_token', CSRF);
                    forms.forEach((f, i) => {
                        fd.append('items[' + i + '][mfg_no]', f.dataset.mfg || '');
                        fd.append('items[' + i + '][site]', f.dataset.site || '');
                        fd.append('items[' + i + '][so_number]', f.dataset.so || '');
                        fd.append('items[' + i + '][original_ship_date]', f.dataset.origShip || '');
                    });

                    fetch(BULK_URL, {
                        method: 'POST',
                        body: fd,
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                        credentials: 'same-origin'
                    })
                        .then(r => r.json())
                        .then(j => {
                            if (window.Swal) Swal.close();
                            if (!j.ok) {
                                notify(j.message || 'บันทึกไม่สำเร็จ', false);
                                return;
                            }
                            notify(j.message || 'บันทึกสำเร็จ', true);
                            // Mark forms as saved + update display inline
                            forms.forEach(f => {
                                f.dataset.saved = '1';
                                const disp = f.querySelector('.pst-confirm-status-display');
                                if (disp) {
                                    disp.innerHTML = '<span class="badge bg-success">Confirm Delivery</span>'
                                        + '<div class="pst-muted small">เพิ่งบันทึก (bulk)</div>';
                                }
                            });
                            refreshPendingCount();
                        })
                        .catch(() => {
                            if (window.Swal) Swal.close();
                            notify('เกิดข้อผิดพลาดในการบันทึก', false);
                        })
                        .finally(() => { bulkBtn.disabled = false; });
                });
            }

            refreshPendingCount();
        })();
    </script>
@endsection
