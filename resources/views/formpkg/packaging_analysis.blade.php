@extends('layouts.layout')

@section('title', 'Packaging Usage Analysis')
@section('page-title', 'Packaging Usage Analysis')

@section('content')
    @php
        $filters = $filters ?? [];
        $kpis = $kpis ?? [];
        $charts = $charts ?? [];
        $periods = $periods ?? [];
        $comparison = $comparison ?? [];
        $riskInsights = $riskInsights ?? [];
        $productComparison = collect($comparison['products'] ?? []);
        $codeComparison = collect($comparison['codes'] ?? []);
        $topShortageCodes = collect($riskInsights['top_shortage_codes'] ?? []);
        $lowCoverageRows = collect($riskInsights['low_coverage_rows'] ?? []);
        $growthCodes = collect($riskInsights['growth_codes'] ?? []);
        $shortageProducts = collect($riskInsights['shortage_products'] ?? []);
        $monthlySummary = collect($monthlySummary ?? []);
        $productSummary = collect($productSummary ?? []);
        $codeSummary = collect($codeSummary ?? []);
        $matrix = $matrix ?? ['codes' => collect(), 'rows' => collect(), 'columnTotals' => [], 'grandTotal' => 0];
        $fmt = fn($value, $decimals = 0) => is_numeric($value) ? number_format((float) $value, $decimals) : '-';
        $pct = fn($value) => is_null($value) ? 'N/A' : number_format((float) $value, 2) . '%';
        $delta = fn($value) => ((float) $value > 0 ? '+' : '') . number_format((float) $value, 0);
        $deltaClass = function ($value) {
            if (!is_numeric($value) || (float) $value == 0) {
                return 'text-muted';
            }
            return (float) $value > 0 ? 'text-success' : 'text-danger';
        };
        $changeText = function ($value) {
            if ($value === null) {
                return 'New';
            }
            return ((float) $value > 0 ? '+' : '') . number_format((float) $value, 1) . '%';
        };
        $coverageClass = function ($value) {
            if (!is_numeric($value)) {
                return 'text-muted';
            }
            if ((float) $value < 100) {
                return 'text-danger';
            }
            if ((float) $value < 120) {
                return 'text-warning';
            }
            return 'text-success';
        };
    @endphp

    <style>
        .analysis-wrap {
            background: #f6f7f9;
            padding: 16px;
            border-radius: 8px;
        }

        .analysis-card {
            background: #fff;
            border: 1px solid #e1e5ea;
            border-radius: 8px;
            overflow: hidden;
        }

        .analysis-card-header {
            padding: 12px 16px;
            border-bottom: 1px solid #e8edf2;
            background: #fff;
            font-weight: 700;
        }

        .analysis-kpi {
            height: 100%;
            padding: 14px;
            border: 1px solid #e1e5ea;
            border-radius: 8px;
            background: #fff;
        }

        .analysis-kpi .label {
            color: #667085;
            font-size: .82rem;
            margin-bottom: 6px;
        }

        .analysis-kpi .value {
            color: #1f2937;
            font-size: 1.35rem;
            font-weight: 800;
            line-height: 1.2;
        }

        .analysis-kpi .sub {
            color: #667085;
            font-size: .78rem;
            margin-top: 6px;
        }

        .decision-kpi {
            border-left: 4px solid #98a2b3;
        }

        .decision-kpi.danger {
            border-left-color: #dc3545;
        }

        .decision-kpi.warning {
            border-left-color: #f59f00;
        }

        .decision-kpi.success {
            border-left-color: #198754;
        }

        .risk-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 12px;
            align-items: center;
            padding: 10px 12px;
            border-bottom: 1px solid #edf1f5;
        }

        .risk-row:last-child {
            border-bottom: 0;
        }

        .risk-title {
            color: #111827;
            font-weight: 800;
        }

        .risk-meta {
            color: #667085;
            font-size: .78rem;
            line-height: 1.35;
        }

        .risk-number {
            text-align: right;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .risk-number .main {
            color: #dc3545;
            font-weight: 800;
        }

        .risk-number .sub {
            color: #667085;
            font-size: .76rem;
        }

        .period-note {
            color: #667085;
            font-size: .8rem;
            font-weight: 500;
        }

        .summary-readout {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            border-bottom: 1px solid #edf1f5;
        }

        .summary-readout>div {
            padding: 10px 12px;
            border-right: 1px solid #edf1f5;
        }

        .summary-readout>div:last-child {
            border-right: 0;
        }

        .summary-readout .mini-label {
            color: #667085;
            font-size: .74rem;
        }

        .summary-readout .mini-value {
            color: #111827;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
        }

        .comparison-table td:first-child {
            min-width: 120px;
        }

        .comparison-table .context {
            color: #667085;
            font-size: .74rem;
            line-height: 1.25;
        }

        .comparison-table .drill-link {
            white-space: nowrap;
        }

        .chart-box {
            height: 300px;
            padding: 12px;
        }

        .analysis-table-wrap {
            max-height: 430px;
            overflow: auto;
        }

        .analysis-table th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #edf4ff;
            white-space: nowrap;
        }

        .analysis-table td {
            vertical-align: middle;
        }

        .num {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .shortage-cell {
            color: #dc3545;
            font-weight: 700;
        }

        .risk-cell {
            color: #b7791f;
            font-weight: 700;
        }

        .enough-cell {
            color: #198754;
            font-weight: 700;
        }

        .quick-actions .btn {
            white-space: nowrap;
        }

        .matrix-wrap {
            max-height: 520px;
            overflow: auto;
        }

        .matrix-wrap th:first-child,
        .matrix-wrap td:first-child {
            position: sticky;
            left: 0;
            z-index: 3;
            background: #fff;
        }

        .matrix-wrap thead th:first-child {
            z-index: 4;
            background: #edf4ff;
        }

        @media (max-width: 767.98px) {
            .analysis-wrap {
                padding: 10px;
            }

            .chart-box {
                height: 260px;
            }

            .analysis-kpi .value {
                font-size: 1.1rem;
            }

            .summary-readout {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .summary-readout>div:nth-child(2) {
                border-right: 0;
            }

            .risk-row {
                grid-template-columns: minmax(0, 1fr);
            }

            .risk-number {
                text-align: left;
            }
        }
    </style>

    <div class="analysis-wrap">
        <div class="analysis-card mb-3">
            <div class="analysis-card-header d-flex justify-content-between align-items-center">
                <span>Filters</span>
                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse"
                    data-bs-target="#analysisFilters" aria-expanded="true">
                    <i class="fas fa-sliders-h me-1"></i> Toggle
                </button>
            </div>
            <div id="analysisFilters" class="collapse show">
                <form method="GET" action="{{ route('pkg.packaging.analysis') }}" class="p-3">
                    <input type="hidden" name="date_type" value="{{ $filters['date_type'] ?? 'reqdate' }}">
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-1 col-md-2">
                            <label class="form-label">Year</label>
                            <input type="number" name="year" class="form-control"
                                value="{{ $filters['year'] ?? now()->year }}" min="2000" max="2100">
                        </div>
                        <div class="col-lg-1 col-md-2">
                            <label class="form-label">Month</label>
                            <input type="number" name="month" class="form-control"
                                value="{{ $filters['month'] ?? now()->month }}" min="1" max="12">
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <label class="form-label">From Date</label>
                            <input type="date" name="date_from" class="form-control"
                                value="{{ $filters['date_from'] ?? '' }}">
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <label class="form-label">To Date</label>
                            <input type="date" name="date_to" class="form-control"
                                value="{{ $filters['date_to'] ?? '' }}">
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <label class="form-label">Product</label>
                            <select name="product" class="form-select">
                                <option value="">ALL</option>
                                @foreach ($productOptions ?? [] as $option)
                                    <option value="{{ $option }}"
                                        {{ (string) ($filters['product'] ?? '') === (string) $option ? 'selected' : '' }}>
                                        {{ $option }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <label class="form-label">Code Packaging</label>
                            <select name="code_packaging" class="form-select">
                                <option value="">ALL</option>
                                @foreach ($codeOptions ?? [] as $option)
                                    <option value="{{ $option }}"
                                        {{ (string) ($filters['code_packaging'] ?? '') === (string) $option ? 'selected' : '' }}>
                                        {{ $option }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-lg-1 col-md-2">
                            <label class="form-label">Site</label>
                            <select name="site" class="form-select">
                                <option value="">ALL</option>
                                <option value="WIRE" {{ ($filters['site'] ?? '') === 'WIRE' ? 'selected' : '' }}>WIRE
                                </option>
                                <option value="PLUS" {{ ($filters['site'] ?? '') === 'PLUS' ? 'selected' : '' }}>PLUS
                                </option>
                            </select>
                        </div>
                        <div class="col-lg-1 col-md-2">
                            <label class="form-label">Std Pack</label>
                            <select name="fpack" class="form-select">
                                <option value="">ALL</option>
                                @foreach ($fpackOptions ?? [] as $option)
                                    <option value="{{ $option }}"
                                        {{ (string) ($filters['fpack'] ?? '') === (string) $option ? 'selected' : '' }}>
                                        {{ $option }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-3 quick-actions">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i> Search</button>
                        <a href="{{ route('pkg.packaging.analysis') }}" class="btn btn-outline-secondary"><i
                                class="fas fa-rotate-left me-1"></i> Reset</a>
                        <a href="{{ route('pkg.packaging.analysis.export', request()->query()) }}"
                            class="btn btn-success"><i class="fas fa-file-excel me-1"></i> Export Excel</a>
                        <a href="{{ route('pkg.packaging.analysis', $quickLinks['this_month'] ?? []) }}"
                            class="btn btn-outline-primary">This Month</a>
                        <a href="{{ route('pkg.packaging.analysis', $quickLinks['last_month'] ?? []) }}"
                            class="btn btn-outline-primary">Last Month</a>
                        <a href="{{ route('pkg.packaging.analysis', $quickLinks['this_year'] ?? []) }}"
                            class="btn btn-outline-primary">This Year</a>
                        <a href="{{ route('pkg.packaging.analysis', $quickLinks['yoy'] ?? []) }}"
                            class="btn btn-outline-primary">YoY</a>
                        <a href="{{ route('pkg.packaging.analysis', $quickLinks['mom'] ?? []) }}"
                            class="btn btn-outline-primary">MoM</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-3 col-md-6">
                <div class="analysis-kpi decision-kpi {{ ($riskInsights['total_shortage_pcs'] ?? 0) > 0 ? 'danger' : 'success' }}">
                    <div class="label">Shortage To Act</div>
                    <div class="value {{ ($riskInsights['total_shortage_pcs'] ?? 0) > 0 ? 'text-danger' : 'text-success' }}">
                        {{ $fmt($riskInsights['total_shortage_pcs'] ?? 0) }}</div>
                    <div class="sub">PCS across {{ $fmt($riskInsights['shortage_code_count'] ?? 0) }} code packaging</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="analysis-kpi decision-kpi {{ ($riskInsights['low_coverage_code_count'] ?? 0) > 0 ? 'warning' : 'success' }}">
                    <div class="label">Low Coverage</div>
                    <div class="value {{ ($riskInsights['low_coverage_code_count'] ?? 0) > 0 ? 'text-warning' : 'text-success' }}">
                        {{ $fmt($riskInsights['low_coverage_code_count'] ?? 0) }}</div>
                    <div class="sub">codes below 120% coverage</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="analysis-kpi decision-kpi {{ ($riskInsights['coverage_pct'] ?? null) !== null && ($riskInsights['coverage_pct'] ?? 0) < 100 ? 'danger' : 'success' }}">
                    <div class="label">Overall Coverage</div>
                    <div class="value {{ $coverageClass($riskInsights['coverage_pct'] ?? null) }}">
                        {{ $pct($riskInsights['coverage_pct'] ?? null) }}</div>
                    <div class="sub">{{ $fmt($riskInsights['total_stock_pcs'] ?? 0) }} stock / {{ $fmt($riskInsights['total_demand_pcs'] ?? 0) }} demand</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="analysis-kpi decision-kpi">
                    <div class="label">Active Codes</div>
                    <div class="value">{{ $fmt($riskInsights['active_code_count'] ?? 0) }}</div>
                    <div class="sub">filtered code packaging with demand</div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-7">
                <div class="analysis-card h-100">
                    <div class="analysis-card-header d-flex justify-content-between align-items-center">
                        <span>Shortage Priority Queue</span>
                        <span class="period-note">{{ $periods['current'] ?? '' }}</span>
                    </div>
                    @forelse ($topShortageCodes as $row)
                        <div class="risk-row">
                            <div>
                                <div class="risk-title">{{ $row->code_packaging }} <span class="text-muted fw-normal">/ {{ $row->product }}</span></div>
                                <div class="risk-meta">{{ $row->pack_name }} - {{ $row->source_site }} - Coverage {{ $pct($row->coverage_pct) }}</div>
                            </div>
                            <div class="risk-number">
                                <div class="main">{{ $fmt($row->shortage_pcs) }} PCS</div>
                                <div class="sub">{{ $pct($row->impact_pct) }} of shortage</div>
                                <a class="btn btn-sm btn-outline-primary mt-1" href="{{ route('pkg.packaging.analysis', $row->link_filters) }}">View</a>
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-muted py-4">No shortage in the selected filters</div>
                    @endforelse
                </div>
            </div>
            <div class="col-xl-5">
                <div class="analysis-card h-100">
                    <div class="analysis-card-header">Watchlist</div>
                    <div class="summary-readout">
                        <div>
                            <div class="mini-label">Worst Coverage</div>
                            <div class="mini-value">{{ $pct(optional($lowCoverageRows->first())->coverage_pct ?? null) }}</div>
                        </div>
                        <div>
                            <div class="mini-label">Top Product Gap</div>
                            <div class="mini-value">{{ optional($shortageProducts->first())->product ?? '-' }}</div>
                        </div>
                        <div>
                            <div class="mini-label">Growth Codes</div>
                            <div class="mini-value">{{ $fmt($growthCodes->count()) }}</div>
                        </div>
                        <div>
                            <div class="mini-label">Shortage Products</div>
                            <div class="mini-value">{{ $fmt($shortageProducts->count()) }}</div>
                        </div>
                    </div>
                    <div class="analysis-table-wrap">
                        <table class="table table-bordered table-sm analysis-table mb-0">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Product/Site</th>
                                    <th class="num">Coverage</th>
                                    <th class="num">Shortage</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($lowCoverageRows->take(8) as $row)
                                    <tr>
                                        <td><a href="{{ route('pkg.packaging.analysis', $row->link_filters) }}">{{ $row->code_packaging }}</a></td>
                                        <td>{{ $row->product }}<div class="context">{{ $row->source_site }}</div></td>
                                        <td class="num {{ $coverageClass($row->coverage_pct) }}">{{ $pct($row->coverage_pct) }}</td>
                                        <td class="num shortage-cell">{{ $fmt($row->shortage_pcs) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">No low-coverage codes</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-3 col-md-6">
                <div class="analysis-kpi">
                    <div class="label">Demand Selected Month</div>
                    <div class="value">{{ $fmt($kpis['selected_month_demand'] ?? 0) }}</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="analysis-kpi">
                    <div class="label">Demand Previous Month</div>
                    <div class="value">{{ $fmt($kpis['previous_month_demand'] ?? 0) }}</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="analysis-kpi">
                    <div class="label">MoM Change</div>
                    <div class="value {{ ($kpis['mom_change_pct'] ?? 0) < 0 ? 'text-danger' : 'text-success' }}">
                        {{ $pct($kpis['mom_change_pct'] ?? null) }}</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="analysis-kpi">
                    <div class="label">Demand Selected Year</div>
                    <div class="value">{{ $fmt($kpis['selected_year_demand'] ?? 0) }}</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="analysis-kpi">
                    <div class="label">Demand Previous Year</div>
                    <div class="value">{{ $fmt($kpis['previous_year_demand'] ?? 0) }}</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="analysis-kpi">
                    <div class="label">YoY Change</div>
                    <div class="value {{ ($kpis['yoy_change_pct'] ?? 0) < 0 ? 'text-danger' : 'text-success' }}">
                        {{ $pct($kpis['yoy_change_pct'] ?? null) }}</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="analysis-kpi">
                    <div class="label">Top Product</div>
                    <div class="value">{{ optional($kpis['top_product'] ?? null)->product ?? '-' }}</div>
                    <div class="sub">{{ $fmt(optional($kpis['top_product'] ?? null)->demand_pcs ?? 0) }} PCS</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="analysis-kpi">
                    <div class="label">Top Code Packaging</div>
                    <div class="value">{{ optional($kpis['top_code'] ?? null)->code_packaging ?? '-' }}</div>
                    <div class="sub">{{ $fmt(optional($kpis['top_code'] ?? null)->demand_pcs ?? 0) }} PCS</div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-6">
                <div class="analysis-card">
                    <div class="analysis-card-header d-flex justify-content-between align-items-center">
                        <span>Product Drilldown Compare</span>
                        <span class="period-note">{{ $periods['current'] ?? '' }}</span>
                    </div>
                    <div class="summary-readout">
                        <div>
                            <div class="mini-label">This Period</div>
                            <div class="mini-value">{{ $fmt($productComparison->sum('current_demand_pcs')) }}</div>
                        </div>
                        <div>
                            <div class="mini-label">Last Month</div>
                            <div class="mini-value">{{ $fmt($productComparison->sum('previous_month_demand_pcs')) }}</div>
                        </div>
                        <div>
                            <div class="mini-label">Last Year</div>
                            <div class="mini-value">{{ $fmt($productComparison->sum('previous_year_demand_pcs')) }}</div>
                        </div>
                        <div>
                            <div class="mini-label">Rows</div>
                            <div class="mini-value">{{ $fmt($productComparison->count()) }}</div>
                        </div>
                    </div>
                    <div class="analysis-table-wrap">
                        <table class="table table-bordered table-sm analysis-table comparison-table mb-0">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th class="num">This</th>
                                    <th class="num">Last Month</th>
                                    <th class="num">MoM</th>
                                    <th class="num">Last Year</th>
                                    <th class="num">YoY</th>
                                    <th class="num">Shortage</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($productComparison->take(15) as $row)
                                    <tr>
                                        <td><strong>{{ $row->product }}</strong>
                                            <div class="context">WO {{ $fmt($row->wo_count) }} - Coverage
                                                {{ $pct($row->coverage_pct) }}</div>
                                        </td>
                                        <td class="num fw-bold">{{ $fmt($row->current_demand_pcs) }}</td>
                                        <td class="num">{{ $fmt($row->previous_month_demand_pcs) }}</td>
                                        <td class="num {{ $deltaClass($row->mom_change_pcs) }}">
                                            {{ $delta($row->mom_change_pcs) }} <span
                                                class="context">({{ $changeText($row->mom_change_pct) }})</span></td>
                                        <td class="num">{{ $fmt($row->previous_year_demand_pcs) }}</td>
                                        <td class="num {{ $deltaClass($row->yoy_change_pcs) }}">
                                            {{ $delta($row->yoy_change_pcs) }} <span
                                                class="context">({{ $changeText($row->yoy_change_pct) }})</span></td>
                                        <td class="num shortage-cell">{{ $fmt($row->shortage_pcs) }}</td>
                                        <td class="text-end"><a class="btn btn-sm btn-outline-primary drill-link"
                                                href="{{ route('pkg.packaging.analysis', $row->link_filters) }}">ดู</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">No data</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="analysis-card">
                    <div class="analysis-card-header d-flex justify-content-between align-items-center">
                        <span>Code Packaging Drilldown Compare</span>
                        <span class="period-note">{{ $periods['current'] ?? '' }}</span>
                    </div>
                    <div class="summary-readout">
                        <div>
                            <div class="mini-label">This Period</div>
                            <div class="mini-value">{{ $fmt($codeComparison->sum('current_demand_pcs')) }}</div>
                        </div>
                        <div>
                            <div class="mini-label">Last Month</div>
                            <div class="mini-value">{{ $fmt($codeComparison->sum('previous_month_demand_pcs')) }}</div>
                        </div>
                        <div>
                            <div class="mini-label">Last Year</div>
                            <div class="mini-value">{{ $fmt($codeComparison->sum('previous_year_demand_pcs')) }}</div>
                        </div>
                        <div>
                            <div class="mini-label">Codes</div>
                            <div class="mini-value">{{ $fmt($codeComparison->count()) }}</div>
                        </div>
                    </div>
                    <div class="analysis-table-wrap">
                        <table class="table table-bordered table-sm analysis-table comparison-table mb-0">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Product/Site</th>
                                    <th class="num">This</th>
                                    <th class="num">Last Month</th>
                                    <th class="num">MoM</th>
                                    <th class="num">Last Year</th>
                                    <th class="num">YoY</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($codeComparison->take(20) as $row)
                                    <tr>
                                        <td><strong>{{ $row->code_packaging }}</strong></td>
                                        <td>
                                            <div>{{ $row->product }}</div>
                                            <div class="context">{{ $row->source_site }} - Shortage
                                                {{ $fmt($row->shortage_pcs) }}</div>
                                        </td>
                                        <td class="num fw-bold">{{ $fmt($row->current_demand_pcs) }}</td>
                                        <td class="num">{{ $fmt($row->previous_month_demand_pcs) }}</td>
                                        <td class="num {{ $deltaClass($row->mom_change_pcs) }}">
                                            {{ $delta($row->mom_change_pcs) }} <span
                                                class="context">({{ $changeText($row->mom_change_pct) }})</span></td>
                                        <td class="num">{{ $fmt($row->previous_year_demand_pcs) }}</td>
                                        <td class="num {{ $deltaClass($row->yoy_change_pcs) }}">
                                            {{ $delta($row->yoy_change_pcs) }} <span
                                                class="context">({{ $changeText($row->yoy_change_pct) }})</span></td>
                                        <td class="text-end"><a class="btn btn-sm btn-outline-primary drill-link"
                                                href="{{ route('pkg.packaging.analysis', $row->link_filters) }}">ดู</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">No data</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-6">
                <div class="analysis-card">
                    <div class="analysis-card-header">Monthly Trend</div>
                    <div class="chart-box"><canvas id="monthlyTrendChart"></canvas></div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="analysis-card">
                    <div class="analysis-card-header">Product Usage Top 10</div>
                    <div class="chart-box"><canvas id="productUsageChart"></canvas></div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="analysis-card">
                    <div class="analysis-card-header">Code Packaging Usage Top 10</div>
                    <div class="chart-box"><canvas id="codeUsageChart"></canvas></div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="analysis-card">
                    <div class="analysis-card-header">WIRE vs PLUS</div>
                    <div class="chart-box"><canvas id="siteUsageChart"></canvas></div>
                </div>
            </div>
        </div>

        <div class="analysis-card mb-3">
            <div class="analysis-card-header">Summary by Month</div>
            <div class="analysis-table-wrap">
                <table class="table table-bordered table-sm analysis-table mb-0">
                    <thead>
                        <tr>
                            <th>Year-Month</th>
                            <th class="num">Demand PCS</th>
                            <th class="num">Stock PCS</th>
                            <th class="num">Shortage PCS</th>
                            <th class="num">Coverage %</th>
                            <th class="num">WO Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($monthlySummary as $row)
                            <tr>
                                <td>{{ $row->year_month }}</td>
                                <td class="num">{{ $fmt($row->demand_pcs) }}</td>
                                <td class="num">{{ $fmt($row->stock_pcs) }}</td>
                                <td class="num shortage-cell">{{ $fmt($row->shortage_pcs) }}</td>
                                <td class="num {{ $coverageClass($row->coverage_pct) }}">{{ $pct($row->coverage_pct) }}
                                </td>
                                <td class="num">{{ $fmt($row->wo_count) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No data</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-6">
                <div class="analysis-card">
                    <div class="analysis-card-header">Summary by Product</div>
                    <div class="analysis-table-wrap">
                        <table class="table table-bordered table-sm analysis-table mb-0">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th class="num">Demand PCS</th>
                                    <th class="num">Stock PCS</th>
                                    <th class="num">Shortage PCS</th>
                                    <th class="num">Coverage %</th>
                                    <th class="num">Code Count</th>
                                    <th class="num">WO Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($productSummary as $row)
                                    <tr>
                                        <td>{{ $row->product }}</td>
                                        <td class="num">{{ $fmt($row->demand_pcs) }}</td>
                                        <td class="num">{{ $fmt($row->stock_pcs) }}</td>
                                        <td class="num shortage-cell">{{ $fmt($row->shortage_pcs) }}</td>
                                        <td class="num {{ $coverageClass($row->coverage_pct) }}">
                                            {{ $pct($row->coverage_pct) }}</td>
                                        <td class="num">{{ $fmt($row->code_packaging_count) }}</td>
                                        <td class="num">{{ $fmt($row->wo_count) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">No data</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="analysis-card">
                    <div class="analysis-card-header">Summary by Code Packaging</div>
                    <div class="analysis-table-wrap">
                        <table class="table table-bordered table-sm analysis-table mb-0">
                            <thead>
                                <tr>
                                    <th>Code Packaging</th>
                                    <th>Packaging Name</th>
                                    <th>Product</th>
                                    <th>Site</th>
                                    <th class="num">Demand PCS</th>
                                    <th class="num">Stock PCS</th>
                                    <th class="num">Shortage PCS</th>
                                    <th class="num">Coverage %</th>
                                    <th class="num">WO Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($codeSummary as $row)
                                    <tr>
                                        <td>{{ $row->code_packaging }}</td>
                                        <td>{{ $row->pack_name }}</td>
                                        <td>{{ $row->product }}</td>
                                        <td>{{ $row->source_site }}</td>
                                        <td class="num">{{ $fmt($row->demand_pcs) }}</td>
                                        <td class="num">{{ $fmt($row->stock_pcs) }}</td>
                                        <td class="num shortage-cell">{{ $fmt($row->shortage_pcs) }}</td>
                                        <td class="num {{ $coverageClass($row->coverage_pct) }}">
                                            {{ $pct($row->coverage_pct) }}</td>
                                        <td class="num">{{ $fmt($row->wo_count) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="text-center text-muted py-4">No data</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="analysis-card">
            <div class="analysis-card-header">Product x Code Packaging Matrix</div>
            <div class="matrix-wrap">
                <table class="table table-bordered table-sm analysis-table mb-0">
                    <thead>
                        <tr>
                            <th>Product</th>
                            @foreach ($matrix['codes'] as $code)
                                <th class="num">{{ $code }}</th>
                            @endforeach
                            <th class="num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($matrix['rows'] as $row)
                            <tr>
                                <td>{{ $row['product'] }}</td>
                                @foreach ($matrix['codes'] as $code)
                                    <td class="num">{{ $fmt($row['cells'][$code] ?? 0) }}</td>
                                @endforeach
                                <td class="num fw-bold">{{ $fmt($row['total']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($matrix['codes']) + 2 }}" class="text-center text-muted py-4">No
                                    data</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if (count($matrix['codes']) > 0)
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td>Total</td>
                                @foreach ($matrix['codes'] as $code)
                                    <td class="num">{{ $fmt($matrix['columnTotals'][$code] ?? 0) }}</td>
                                @endforeach
                                <td class="num">{{ $fmt($matrix['grandTotal'] ?? 0) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const chartData = @json($charts);
                const blue = '#0d6efd';
                const green = '#198754';
                const yellow = '#f0ad4e';
                const red = '#dc3545';

                function labels(rows) {
                    return (rows || []).map(row => row.label);
                }

                function values(rows) {
                    return (rows || []).map(row => row.value);
                }

                new Chart(document.getElementById('monthlyTrendChart'), {
                    type: 'line',
                    data: {
                        labels: labels(chartData.monthlyTrend),
                        datasets: [{
                            label: 'Demand PCS',
                            data: values(chartData.monthlyTrend),
                            borderColor: blue,
                            backgroundColor: 'rgba(13,110,253,.12)',
                            fill: true,
                            tension: .25
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });

                new Chart(document.getElementById('productUsageChart'), {
                    type: 'bar',
                    data: {
                        labels: labels(chartData.productUsage),
                        datasets: [{
                            label: 'Demand PCS',
                            data: values(chartData.productUsage),
                            backgroundColor: blue
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            x: {
                                beginAtZero: true
                            }
                        }
                    }
                });

                new Chart(document.getElementById('codeUsageChart'), {
                    type: 'bar',
                    data: {
                        labels: labels(chartData.codeUsage),
                        datasets: [{
                            label: 'Demand PCS',
                            data: values(chartData.codeUsage),
                            backgroundColor: yellow
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            x: {
                                beginAtZero: true
                            }
                        }
                    }
                });

                new Chart(document.getElementById('siteUsageChart'), {
                    type: 'bar',
                    data: {
                        labels: labels(chartData.siteUsage),
                        datasets: [{
                            label: 'Demand PCS',
                            data: values(chartData.siteUsage),
                            backgroundColor: [blue, green, red]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
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
