@extends('layouts.layout')

@section('title', 'Recovery Dashboard')
@section('page-title', 'Recovery Dashboard')

@section('content')
    @php
        $filters = $filters ?? [];
        $monthlySummary = collect($monthlySummary ?? []);
        $customerSummary = collect($customerSummary ?? []);
        $analysisRows = collect($analysisRows ?? []);
        $customerPaymentBehavior = collect($customerPaymentBehavior ?? []);
        $customerOptions = collect($customerOptions ?? []);
        $filterOptions = $filterOptions ?? [];
        $kpis = $kpis ?? [];
        $advancedActive = (($filters['term_source'] ?? 'ALL') !== 'ALL')
            || (($filters['recovery_aging'] ?? 'ALL') !== 'ALL')
            || (($filters['payment_timing'] ?? 'ALL') !== 'ALL')
            || (($filters['payment_completion'] ?? 'ALL') !== 'ALL')
            || (($filters['risk_tier'] ?? 'ALL') !== 'ALL')
            || (($filters['payment_basis'] ?? 'FULL') !== 'FULL');
        $fmt = fn($value, $decimals = 2) => number_format((float) $value, $decimals);
        $fmtInt = fn($value) => number_format((int) $value);
        $sourceLabel = fn($source) => $source === 'customer_payment_terms' ? 'Customer master' : 'ERP terms fallback';
        $riskLegend = [
            ['tier' => 'GOOD', 'label' => 'Good', 'text' => 'จ่ายค่อนข้างตรงเวลา ความเสี่ยงต่ำ'],
            ['tier' => 'WATCH', 'label' => 'Watch', 'text' => 'เริ่มมีจ่ายช้า / master ไม่ครบ ควรติดตาม'],
            ['tier' => 'RISK', 'label' => 'Risk', 'text' => 'จ่ายช้าบ่อยหรือช้านาน ควรเร่งติดตาม'],
        ];
        $riskTitle = fn($tier) => collect($riskLegend)->firstWhere('tier', strtoupper((string) $tier))['text'] ?? '';
    @endphp

    <style>
        .lra-wrap { background:#f7f8fa; padding:16px; border-radius:8px; }
        .lra-card { background:#fff; border:1px solid #e1e7ef; border-radius:8px; overflow:hidden; }
        .lra-card + .lra-card { margin-top:16px; }
        .lra-card-header { padding:12px 16px; border-bottom:1px solid #e1e7ef; font-weight:700; display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; }
        .lra-card-body { padding:16px; }
        .lra-kpi { height:100%; padding:14px 16px; border:1px solid #e1e7ef; border-radius:8px; background:#fff; position:relative; overflow:hidden; }
        .lra-kpi::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:#2563eb; }
        .lra-kpi.kpi-green::before { background:#10b981; }
        .lra-kpi.kpi-amber::before { background:#f59e0b; }
        .lra-kpi.kpi-red::before { background:#dc2626; }
        .lra-kpi .label { color:#667085; font-size:.74rem; margin-bottom:6px; text-transform:uppercase; letter-spacing:.04em; font-weight:700; }
        .lra-kpi .value { color:#0f172a; font-size:1.35rem; font-weight:800; line-height:1.2; font-variant-numeric:tabular-nums; }
        .lra-kpi .sub { color:#667085; font-size:.78rem; margin-top:6px; }
        .lra-chart-box { height:320px; padding:12px 14px; }
        .lra-table th { background:#2f4357; color:#fff; white-space:nowrap; font-size:.76rem; vertical-align:middle; }
        .lra-table td { vertical-align:top; }
        .lra-num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
        .lra-text { max-width:320px; word-break:break-word; }
        .lra-badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:.7rem; font-weight:700; background:#eef2ff; color:#3730a3; }
        .lra-badge.fallback { background:#fff7ed; color:#c2410c; }
        .lra-risk { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:999px; font-size:.72rem; font-weight:800; white-space:nowrap; }
        .lra-risk.good { background:#dcfce7; color:#166534; }
        .lra-risk.watch { background:#fef3c7; color:#92400e; }
        .lra-risk.risk { background:#fee2e2; color:#b91c1c; }
        .lra-risk-help { display:flex; flex-wrap:wrap; gap:10px; align-items:center; padding:10px 12px; background:#f8fafc; border:1px solid #e1e7ef; border-radius:8px; }
        .lra-risk-help .desc { color:#475467; font-size:.8rem; }
        .lra-advanced-toggle { white-space:nowrap; }
        .lra-advanced-box { margin-top:12px; padding-top:12px; border-top:1px dashed #d7dee8; }
        .ts-dropdown { z-index:1080 !important; }
        body > .ts-dropdown { position:absolute; }
        @media (max-width: 767.98px) {
            .lra-wrap { padding:10px; }
            .lra-kpi .value { font-size:1.08rem; }
            .lra-chart-box { height:260px; }
        }
    </style>

    <div class="lra-wrap">
        <div class="lra-card mb-3">
            <div class="lra-card-header">
                <span><i class="fas fa-filter me-1 text-primary"></i> Filters</span>
                <span class="text-muted small">Expected payment month is calculated from Customer Payment Terms master.</span>
            </div>
            <form method="GET" action="{{ route('accounting.loss-provision.recovery-dashboard') }}" class="lra-card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">Invoice From</label>
                        <input type="date" name="invoice_from" class="form-control" value="{{ $filters['invoice_from'] ?? '' }}">
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">Invoice To</label>
                        <input type="date" name="invoice_to" class="form-control" value="{{ $filters['invoice_to'] ?? '' }}">
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">Recovery From</label>
                        <input type="date" name="recovery_from" class="form-control" value="{{ $filters['recovery_from'] ?? '' }}">
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">Recovery To</label>
                        <input type="date" name="recovery_to" class="form-control" value="{{ $filters['recovery_to'] ?? '' }}">
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">Site</label>
                        <select name="site" class="form-select">
                            <option value="ALL" @selected(($filters['site'] ?? 'ALL') === 'ALL')>ALL</option>
                            <option value="WIRE" @selected(($filters['site'] ?? '') === 'WIRE')>WIRE</option>
                            <option value="PLUS" @selected(($filters['site'] ?? '') === 'PLUS')>PLUS</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">Invoice / SO</label>
                        <input type="text" name="invoice" class="form-control" value="{{ $filters['invoice'] ?? '' }}">
                    </div>
                    <div class="col-lg-4 col-md-8">
                        <label class="form-label">Customer</label>
                        @php $custVal = (string) ($filters['customer'] ?? ''); @endphp
                        <select name="customer" class="form-select lra-tomselect" data-placeholder="All customers">
                            <option value=""></option>
                            @if ($custVal !== '' && !$customerOptions->contains($custVal))
                                <option value="{{ $custVal }}" selected>{{ $custVal }}</option>
                            @endif
                            @foreach ($customerOptions as $cust)
                                <option value="{{ $cust }}" @selected($custVal === $cust)>{{ $cust }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <button class="btn btn-outline-secondary w-100 lra-advanced-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#lraAdvancedFilters" aria-expanded="{{ $advancedActive ? 'true' : 'false' }}">
                            <i class="fas fa-sliders-h me-1"></i> Advanced
                            @if ($advancedActive)
                                <span class="badge bg-primary ms-1">on</span>
                            @endif
                        </button>
                    </div>
                    <div class="col-lg-12">
                        <div id="lraAdvancedFilters" class="collapse {{ $advancedActive ? 'show' : '' }} lra-advanced-box">
                            <div class="row g-3 align-items-end">
                                <div class="col-lg-2 col-md-4">
                                    <label class="form-label">Master Source</label>
                                    <select name="term_source" class="form-select">
                                        @foreach (($filterOptions['term_sources'] ?? []) as $value => $label)
                                            <option value="{{ $value }}" @selected(($filters['term_source'] ?? 'ALL') === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-4">
                                    <label class="form-label">Recovery Aging</label>
                                    <select name="recovery_aging" class="form-select">
                                        @foreach (($filterOptions['recovery_agings'] ?? []) as $value => $label)
                                            <option value="{{ $value }}" @selected(($filters['recovery_aging'] ?? 'ALL') === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-4">
                                    <label class="form-label">Payment Timing</label>
                                    <select name="payment_timing" class="form-select">
                                        @foreach (($filterOptions['payment_timings'] ?? []) as $value => $label)
                                            <option value="{{ $value }}" @selected(($filters['payment_timing'] ?? 'ALL') === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-4">
                                    <label class="form-label">Paid Status</label>
                                    <select name="payment_completion" class="form-select">
                                        @foreach (($filterOptions['payment_completions'] ?? []) as $value => $label)
                                            <option value="{{ $value }}" @selected(($filters['payment_completion'] ?? 'ALL') === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-4">
                                    <label class="form-label">Risk</label>
                                    <select name="risk_tier" class="form-select">
                                        @foreach (($filterOptions['risk_tiers'] ?? []) as $value => $label)
                                            <option value="{{ $value }}" @selected(($filters['risk_tier'] ?? 'ALL') === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-4">
                                    <label class="form-label">Paid Date Basis</label>
                                    <select name="payment_basis" class="form-select">
                                        @foreach (($filterOptions['payment_basis'] ?? []) as $value => $label)
                                            <option value="{{ $value }}" @selected(($filters['payment_basis'] ?? 'FULL') === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-12 d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i> Search</button>
                        <a href="{{ route('accounting.loss-provision.recovery-dashboard') }}" class="btn btn-outline-secondary"><i class="fas fa-rotate-left me-1"></i> Reset</a>
                        <a href="{{ route('accounting.loss-provision.recovery-inquiry', request()->query()) }}" class="btn btn-outline-success">
                            <i class="fas fa-table-list me-1"></i> Inquiry
                        </a>
                        <a href="{{ route('accounting.loss-provision.recovery-export', array_merge(request()->query(), [
                            'invoice_from' => $filters['invoice_from'] ?? null,
                            'invoice_to' => $filters['invoice_to'] ?? null,
                            'recovery_from' => $filters['recovery_from'] ?? null,
                            'recovery_to' => $filters['recovery_to'] ?? null,
                            'site' => $filters['site'] ?? 'ALL',
                            'term_source' => $filters['term_source'] ?? 'ALL',
                            'payment_timing' => $filters['payment_timing'] ?? 'ALL',
                            'payment_completion' => $filters['payment_completion'] ?? 'ALL',
                            'recovery_aging' => $filters['recovery_aging'] ?? 'ALL',
                            'risk_tier' => $filters['risk_tier'] ?? 'ALL',
                            'payment_basis' => $filters['payment_basis'] ?? 'FULL',
                        ])) }}" class="btn btn-success">
                            <i class="fas fa-file-excel me-1"></i> Export
                        </a>
                        <a href="{{ route('accounting.loss-provision.index', ['date_from' => $filters['invoice_from'] ?? null, 'date_to' => $filters['invoice_to'] ?? null, 'site' => $filters['site'] ?? 'ALL']) }}" class="btn btn-outline-primary ms-auto">
                            <i class="fas fa-list me-1"></i> Loss Provision Detail
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <div class="lra-risk-help mb-3">
            <span class="fw-bold text-muted small me-1">Risk meaning:</span>
            @foreach ($riskLegend as $risk)
                <span>
                    <span class="lra-risk {{ strtolower($risk['tier']) }}">{{ $risk['label'] }}</span>
                    <span class="desc ms-1">{{ $risk['text'] }}</span>
                </span>
            @endforeach
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-3 col-md-6">
                <div class="lra-kpi kpi-green">
                    <div class="label">Expected Recovery</div>
                    <div class="value">{{ $fmt($kpis['expected_amount'] ?? 0) }}</div>
                    <div class="sub">Open invoices in selected recovery period</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="lra-kpi">
                    <div class="label">Invoices</div>
                    <div class="value">{{ $fmtInt($kpis['invoice_count'] ?? 0) }}</div>
                    <div class="sub">{{ $fmtInt($kpis['customer_count'] ?? 0) }} customers</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="lra-kpi kpi-amber">
                    <div class="label">Overdue Recovery</div>
                    <div class="value">{{ $fmt(($kpis['recovery_due_1_30_amount'] ?? 0) + ($kpis['recovery_due_31_60_amount'] ?? 0) + ($kpis['recovery_due_61_PLUS_amount'] ?? 0)) }}</div>
                    <div class="sub">Not due: {{ $fmt($kpis['recovery_not_due_amount'] ?? 0) }}</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="lra-kpi kpi-red">
                    <div class="label">History On-Time Rate</div>
                    <div class="value">{{ number_format((float) ($kpis['history_on_time_rate'] ?? 0), 1) }}%</div>
                    <div class="sub">{{ $fmtInt($kpis['history_late_count'] ?? 0) }} late, {{ $fmtInt($kpis['history_partial_count'] ?? 0) }} partial</div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-3 col-md-6"><div class="lra-kpi"><div class="label">Not Due</div><div class="value">{{ $fmt($kpis['recovery_not_due_amount'] ?? 0) }}</div><div class="sub">Expected date is still ahead</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="lra-kpi kpi-green"><div class="label">Overdue 1-30</div><div class="value">{{ $fmt($kpis['recovery_due_1_30_amount'] ?? 0) }}</div><div class="sub">Early collection risk</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="lra-kpi kpi-amber"><div class="label">Overdue 31-60</div><div class="value">{{ $fmt($kpis['recovery_due_31_60_amount'] ?? 0) }}</div><div class="sub">Watch closely</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="lra-kpi kpi-red"><div class="label">Overdue 61+</div><div class="value">{{ $fmt($kpis['recovery_due_61_PLUS_amount'] ?? 0) }}</div><div class="sub">High risk recovery</div></div></div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-7">
                <div class="lra-card h-100">
                    <div class="lra-card-header">
                        <span><i class="fas fa-chart-column me-1 text-primary"></i> Expected Recovery by Month</span>
                    </div>
                    <div class="lra-chart-box">
                        <canvas id="lraMonthlyChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="lra-card h-100">
                    <div class="lra-card-header">
                        <span><i class="fas fa-users me-1 text-primary"></i> Top Customers</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered lra-table mb-0">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th class="lra-num">Expected</th>
                                    <th class="lra-num">Invoices</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($customerSummary->take(10) as $cust)
                                    <tr>
                                        <td><div class="lra-text">{{ $cust->customer_name }}</div></td>
                                        <td class="lra-num fw-bold">{{ $fmt($cust->amount) }}</td>
                                        <td class="lra-num">{{ $fmtInt($cust->invoice_count) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-center text-muted py-3">No data</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="lra-card">
            <div class="lra-card-header">
                <span><i class="fas fa-calendar-alt me-1 text-primary"></i> Monthly Summary</span>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered lra-table mb-0">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th class="lra-num">Expected Recovery</th>
                            <th class="lra-num">Invoices</th>
                            <th class="lra-num">Customers</th>
                            <th>Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($monthlySummary as $m)
                            @php
                                $monthFrom = \Carbon\Carbon::parse($m->key . '-01')->startOfMonth()->toDateString();
                                $monthTo = \Carbon\Carbon::parse($m->key . '-01')->endOfMonth()->toDateString();
                            @endphp
                            <tr>
                                <td><strong>{{ $m->label }}</strong></td>
                                <td class="lra-num fw-bold">{{ $fmt($m->amount) }}</td>
                                <td class="lra-num">{{ $fmtInt($m->invoice_count) }}</td>
                                <td class="lra-num">{{ $fmtInt($m->customer_count) }}</td>
                                <td>
                                    <a href="{{ route('accounting.loss-provision.recovery-inquiry', array_merge(request()->query(), ['recovery_from' => $monthFrom, 'recovery_to' => $monthTo])) }}" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-eye me-1"></i> View
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td class="lra-num">{{ $fmt($monthlySummary->sum('amount')) }}</td>
                            <td class="lra-num">{{ $fmtInt($monthlySummary->sum('invoice_count')) }}</td>
                            <td class="lra-num">{{ $fmtInt($monthlySummary->sum('customer_count')) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="row g-3 mt-0">
            <div class="col-xl-7">
                <div class="lra-card h-100">
                    <div class="lra-card-header">
                        <span><i class="fas fa-ranking-star me-1 text-primary"></i> Top Customer Chart</span>
                    </div>
                    <div class="lra-chart-box">
                        <canvas id="lraCustomerChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="lra-card h-100">
                    <div class="lra-card-header">
                        <span><i class="fas fa-circle-half-stroke me-1 text-primary"></i> Master Coverage</span>
                    </div>
                    <div class="lra-chart-box">
                        <canvas id="lraSourceChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mt-0">
            <div class="col-xl-7">
                <div class="lra-card h-100">
                    <div class="lra-card-header">
                        <span><i class="fas fa-clock-rotate-left me-1 text-primary"></i> Payment Behavior by Customer</span>
                    </div>
                    <div class="lra-chart-box">
                        <canvas id="lraBehaviorChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="lra-card h-100">
                    <div class="lra-card-header">
                        <span><i class="fas fa-circle-check me-1 text-primary"></i> Worst On-Time Customers</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered lra-table mb-0">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th class="lra-num">On-time</th>
                                    <th class="lra-num">Late</th>
                                    <th class="lra-num">Avg late</th>
                                    <th>Risk</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($customerPaymentBehavior->take(10) as $cust)
                                    <tr>
                                        <td><div class="lra-text">{{ $cust->customer_name }}</div></td>
                                        <td class="lra-num fw-bold">{{ number_format((float) $cust->on_time_rate, 1) }}%</td>
                                        <td class="lra-num text-danger">{{ $fmtInt($cust->late_count) }}</td>
                                        <td class="lra-num">{{ number_format((float) $cust->avg_days_late, 1) }} days</td>
                                        <td>
                                            <span class="lra-risk {{ strtolower($cust->risk_tier) }}" title="{{ $riskTitle($cust->risk_tier) }}">{{ $cust->risk_label }} {{ number_format((float) $cust->risk_score, 1) }}</span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted py-3">No payment history</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="lra-card">
            <div class="lra-card-header">
                <span><i class="fas fa-users me-1 text-primary"></i> Customer Summary</span>
                <a href="{{ route('accounting.loss-provision.recovery-inquiry', request()->query()) }}" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-table-list me-1"></i> Open Inquiry
                </a>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered lra-table mb-0">
                    <thead>
                        <tr>
                            <th>Site</th>
                            <th>Customer</th>
                            <th class="lra-num">Expected Recovery</th>
                            <th class="lra-num">Invoices</th>
                            <th>Expected Range</th>
                            <th class="lra-num">Master</th>
                            <th class="lra-num">Fallback</th>
                            <th>Risk</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($customerSummary as $row)
                            <tr>
                                <td>{{ $row->site }}</td>
                                <td><div class="lra-text">{{ $row->customer_name }}</div></td>
                                <td class="lra-num fw-bold">{{ $fmt($row->amount) }}</td>
                                <td class="lra-num">{{ $fmtInt($row->invoice_count) }}</td>
                                <td>{{ $row->first_expected_payment_date }} - {{ $row->last_expected_payment_date }}</td>
                                <td class="lra-num">{{ $fmtInt($row->master_count) }}</td>
                                <td class="lra-num">{{ $fmtInt($row->fallback_count) }}</td>
                                @php
                                    $behavior = $customerPaymentBehavior->first(fn($b) => strtoupper($b->site . '|' . $b->customer_code . '|' . $b->customer_name) === strtoupper($row->site . '|' . $row->customer_code . '|' . $row->customer_name));
                                @endphp
                                <td>
                                    @if ($behavior)
                                        <span class="lra-risk {{ strtolower($behavior->risk_tier) }}" title="{{ $riskTitle($behavior->risk_tier) }}">{{ $behavior->risk_label }}</span>
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">No data</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @php
        $monthlyJs = $monthlySummary->map(fn($m) => [
            'label' => $m->label,
            'amount' => (float) $m->amount,
            'invoice_count' => (int) $m->invoice_count,
        ])->values();
        $customerJs = $customerSummary->take(10)->map(fn($c) => [
            'label' => $c->customer_name,
            'amount' => (float) $c->amount,
        ])->values();
        $sourceJs = [
            ['label' => 'Customer master', 'value' => (int) ($kpis['master_count'] ?? 0), 'color' => '#2563eb'],
            ['label' => 'ERP fallback', 'value' => (int) ($kpis['fallback_count'] ?? 0), 'color' => '#f59e0b'],
        ];
        $behaviorJs = $customerPaymentBehavior->take(10)->map(fn($c) => [
            'label' => $c->customer_name,
            'on_time_rate' => (float) $c->on_time_rate,
            'late_count' => (int) $c->late_count,
        ])->values();
    @endphp
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (window.TomSelect) {
                document.querySelectorAll('.lra-tomselect').forEach(function (el) {
                    if (el.tomselect) return;
                    new TomSelect(el, {
                        create: true,
                        allowEmptyOption: true,
                        persist: false,
                        placeholder: el.dataset.placeholder || '',
                        maxOptions: 1000,
                        dropdownParent: 'body',
                    });
                });
            }

            if (!window.Chart) return;
            var monthly = @json($monthlyJs);
            var customers = @json($customerJs);
            var sources = @json($sourceJs);
            var behavior = @json($behaviorJs);
            var canvas = document.getElementById('lraMonthlyChart');
            var fmtMoney = function (v) { return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
            if (canvas) {
                new Chart(canvas, {
                    type: 'bar',
                    data: {
                        labels: monthly.map(function (m) { return m.label; }),
                        datasets: [{
                            label: 'Expected Recovery',
                            data: monthly.map(function (m) { return Math.round(Number(m.amount || 0) * 100) / 100; }),
                            backgroundColor: '#10b981',
                            borderRadius: 4,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: { callbacks: { label: function (ctx) { return ' Expected: ' + fmtMoney(ctx.raw); } } }
                        },
                        scales: {
                            y: { ticks: { callback: function (v) { return Number(v).toLocaleString(); } } }
                        }
                    }
                });
            }

            var customerCanvas = document.getElementById('lraCustomerChart');
            if (customerCanvas) {
                new Chart(customerCanvas, {
                    type: 'bar',
                    data: {
                        labels: customers.map(function (c) { return c.label; }),
                        datasets: [{
                            label: 'Expected Recovery',
                            data: customers.map(function (c) { return Math.round(Number(c.amount || 0) * 100) / 100; }),
                            backgroundColor: '#2563eb',
                            borderRadius: 4,
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: { callbacks: { label: function (ctx) { return ' Expected: ' + fmtMoney(ctx.raw); } } }
                        },
                        scales: {
                            x: { ticks: { callback: function (v) { return Number(v).toLocaleString(); } } },
                            y: { ticks: { autoSkip: false } }
                        }
                    }
                });
            }

            var sourceCanvas = document.getElementById('lraSourceChart');
            if (sourceCanvas) {
                new Chart(sourceCanvas, {
                    type: 'doughnut',
                    data: {
                        labels: sources.map(function (s) { return s.label; }),
                        datasets: [{
                            data: sources.map(function (s) { return Number(s.value || 0); }),
                            backgroundColor: sources.map(function (s) { return s.color; }),
                            borderColor: '#fff',
                            borderWidth: 2,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '58%',
                        plugins: {
                            legend: { position: 'bottom' },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        return ' ' + ctx.label + ': ' + Number(ctx.raw || 0).toLocaleString() + ' invoices';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            var behaviorCanvas = document.getElementById('lraBehaviorChart');
            if (behaviorCanvas) {
                new Chart(behaviorCanvas, {
                    type: 'bar',
                    data: {
                        labels: behavior.map(function (c) { return c.label; }),
                        datasets: [
                            {
                                label: 'On-time %',
                                data: behavior.map(function (c) { return Number(c.on_time_rate || 0); }),
                                backgroundColor: '#10b981',
                                borderRadius: 4,
                                xAxisID: 'x',
                            },
                            {
                                label: 'Late invoices',
                                data: behavior.map(function (c) { return Number(c.late_count || 0); }),
                                backgroundColor: '#dc2626',
                                borderRadius: 4,
                                xAxisID: 'xLate',
                            }
                        ]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom' } },
                        scales: {
                            x: { min: 0, max: 100, position: 'bottom', ticks: { callback: function (v) { return v + '%'; } } },
                            xLate: { min: 0, position: 'top', grid: { drawOnChartArea: false }, ticks: { precision: 0 } },
                            y: { ticks: { autoSkip: false } }
                        }
                    }
                });
            }
        });
    </script>
@endpush
