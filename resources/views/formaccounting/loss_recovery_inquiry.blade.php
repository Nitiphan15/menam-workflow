@extends('layouts.layout')

@section('title', 'Recovery Inquiry')
@section('page-title', 'Recovery Inquiry')

@section('content')
    @php
        $filters = $filters ?? [];
        $analysisRows = collect($analysisRows ?? []);
        $paymentHistoryRows = collect($paymentHistoryRows ?? []);
        $customerPaymentBehavior = collect($customerPaymentBehavior ?? []);
        $customerOptions = collect($customerOptions ?? []);
        $filterOptions = $filterOptions ?? [];
        $kpis = $kpis ?? [];
        $detailLimit = 500;
        $displayAnalysisRows = $analysisRows->take($detailLimit);
        $displayPaymentHistoryRows = $paymentHistoryRows->take($detailLimit);
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
        .lri-wrap { background:#f7f8fa; padding:16px; border-radius:8px; }
        .lri-card { background:#fff; border:1px solid #e1e7ef; border-radius:8px; overflow:hidden; }
        .lri-card + .lri-card { margin-top:16px; }
        .lri-card-header { padding:12px 16px; border-bottom:1px solid #e1e7ef; font-weight:700; display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; }
        .lri-card-body { padding:16px; }
        .lri-kpi { height:100%; padding:14px 16px; border:1px solid #e1e7ef; border-radius:8px; background:#fff; position:relative; overflow:hidden; }
        .lri-kpi::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:#2563eb; }
        .lri-kpi.kpi-green::before { background:#10b981; }
        .lri-kpi.kpi-amber::before { background:#f59e0b; }
        .lri-kpi.kpi-red::before { background:#dc2626; }
        .lri-kpi .label { color:#667085; font-size:.74rem; margin-bottom:6px; text-transform:uppercase; letter-spacing:.04em; font-weight:700; }
        .lri-kpi .value { color:#0f172a; font-size:1.35rem; font-weight:800; line-height:1.2; font-variant-numeric:tabular-nums; }
        .lri-kpi .sub { color:#667085; font-size:.78rem; margin-top:6px; }
        .lri-table-wrap { max-height:680px; overflow:auto; border:1px solid #e1e7ef; }
        .lri-table { min-width:1280px; margin-bottom:0; }
        .lri-table th { position:sticky; top:0; z-index:2; background:#2f4357; color:#fff; white-space:nowrap; font-size:.76rem; vertical-align:middle; }
        .lri-table td { vertical-align:top; }
        .lri-num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
        .lri-text { max-width:320px; word-break:break-word; }
        .lri-badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:.7rem; font-weight:700; background:#eef2ff; color:#3730a3; }
        .lri-badge.fallback { background:#fff7ed; color:#c2410c; }
        .lri-badge.late { background:#fee2e2; color:#b91c1c; }
        .lri-badge.on-time { background:#dcfce7; color:#166534; }
        .lri-risk { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:999px; font-size:.72rem; font-weight:800; white-space:nowrap; }
        .lri-risk.good { background:#dcfce7; color:#166534; }
        .lri-risk.watch { background:#fef3c7; color:#92400e; }
        .lri-risk.risk { background:#fee2e2; color:#b91c1c; }
        .lri-risk-help { display:flex; flex-wrap:wrap; gap:10px; align-items:center; padding:10px 12px; background:#f8fafc; border:1px solid #e1e7ef; border-radius:8px; }
        .lri-risk-help .desc { color:#475467; font-size:.8rem; }
        .lri-limit-note { color:#667085; font-size:.78rem; font-weight:500; }
        .lri-advanced-toggle { white-space:nowrap; }
        .lri-advanced-box { margin-top:12px; padding-top:12px; border-top:1px dashed #d7dee8; }
        .ts-dropdown { z-index:1080 !important; }
        body > .ts-dropdown { position:absolute; }
        @media (max-width: 767.98px) {
            .lri-wrap { padding:10px; }
            .lri-kpi .value { font-size:1.08rem; }
        }
    </style>

    <div class="lri-wrap">
        <div class="lri-card mb-3">
            <div class="lri-card-header">
                <span><i class="fas fa-filter me-1 text-primary"></i> Filters</span>
                <span class="text-muted small">Inquiry includes forecast open invoices and historical payment behavior.</span>
            </div>
            <form method="GET" action="{{ route('accounting.loss-provision.recovery-inquiry') }}" class="lri-card-body">
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
                        <select name="customer" class="form-select lri-tomselect" data-placeholder="All customers">
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
                        <button class="btn btn-outline-secondary w-100 lri-advanced-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#lriAdvancedFilters" aria-expanded="{{ $advancedActive ? 'true' : 'false' }}">
                            <i class="fas fa-sliders-h me-1"></i> Advanced
                            @if ($advancedActive)
                                <span class="badge bg-primary ms-1">on</span>
                            @endif
                        </button>
                    </div>
                    <div class="col-lg-12">
                        <div id="lriAdvancedFilters" class="collapse {{ $advancedActive ? 'show' : '' }} lri-advanced-box">
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
                        <a href="{{ route('accounting.loss-provision.recovery-inquiry') }}" class="btn btn-outline-secondary"><i class="fas fa-rotate-left me-1"></i> Reset</a>
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
                        <a href="{{ route('accounting.loss-provision.recovery-dashboard', request()->query()) }}" class="btn btn-outline-success ms-auto">
                            <i class="fas fa-chart-line me-1"></i> Dashboard
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <div class="lri-risk-help mb-3">
            <span class="fw-bold text-muted small me-1">Risk meaning:</span>
            @foreach ($riskLegend as $risk)
                <span>
                    <span class="lri-risk {{ strtolower($risk['tier']) }}">{{ $risk['label'] }}</span>
                    <span class="desc ms-1">{{ $risk['text'] }}</span>
                </span>
            @endforeach
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-3 col-md-6">
                <div class="lri-kpi kpi-green">
                    <div class="label">Expected Recovery</div>
                    <div class="value">{{ $fmt($kpis['expected_amount'] ?? 0) }}</div>
                    <div class="sub">{{ $fmtInt($kpis['invoice_count'] ?? 0) }} open invoices</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="lri-kpi">
                    <div class="label">Payment History</div>
                    <div class="value">{{ $fmtInt($kpis['history_invoice_count'] ?? 0) }}</div>
                    <div class="sub">Paid invoices in invoice period</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="lri-kpi kpi-amber">
                    <div class="label">On-Time Rate</div>
                    <div class="value">{{ number_format((float) ($kpis['history_on_time_rate'] ?? 0), 1) }}%</div>
                    <div class="sub">{{ $fmtInt($kpis['history_on_time_count'] ?? 0) }} on-time payments</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="lri-kpi kpi-red">
                    <div class="label">Late Payments</div>
                    <div class="value">{{ $fmtInt($kpis['history_late_count'] ?? 0) }}</div>
                    <div class="sub">{{ $fmtInt($kpis['history_partial_count'] ?? 0) }} partial paid invoices</div>
                </div>
            </div>
        </div>

        <div class="lri-card">
            <div class="lri-card-header">
                <span><i class="fas fa-table me-1 text-primary"></i> Expected Recovery Inquiry</span>
                <span class="lri-limit-note">Showing {{ $fmtInt($displayAnalysisRows->count()) }} of {{ $fmtInt($analysisRows->count()) }} rows. Export for all rows.</span>
            </div>
            <div class="lri-table-wrap">
                <table class="table table-bordered lri-table">
                    <thead>
                        <tr>
                            <th>Expected Date</th>
                            <th>Site</th>
                            <th>Invoice</th>
                            <th>SO</th>
                            <th>Customer</th>
                            <th class="lri-num">Remaining</th>
                            <th>Aging</th>
                            <th>Payment Terms</th>
                            <th>Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($displayAnalysisRows as $row)
                            <tr>
                                <td class="fw-bold">{{ $row->expected_payment_date }}</td>
                                <td>{{ $row->site }}</td>
                                <td>{{ $row->invnumber }}</td>
                                <td>{{ $row->ordnumber }}</td>
                                <td><div class="lri-text">{{ $row->customer_name }}</div></td>
                                <td class="lri-num fw-bold">{{ $fmt($row->remaining) }}</td>
                                <td>{{ $row->recovery_aging_label }}</td>
                                <td>
                                    <div>{{ $row->payment_term_label ?: '-' }}</div>
                                    @if ($row->billing_plan_label)
                                        <div class="text-muted small">{{ $row->billing_plan_label }}</div>
                                    @endif
                                    @if ($row->payment_schedule_label)
                                        <div class="text-muted small">{{ $row->payment_schedule_label }}</div>
                                    @endif
                                </td>
                                <td>
                                    <span class="lri-badge {{ $row->term_source === 'customer_payment_terms' ? '' : 'fallback' }}">
                                        {{ $sourceLabel($row->term_source) }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted py-4">No data</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="lri-card">
            <div class="lri-card-header">
                <span><i class="fas fa-clock-rotate-left me-1 text-primary"></i> Previous Payment Behavior</span>
                <span class="lri-limit-note">Showing {{ $fmtInt($displayPaymentHistoryRows->count()) }} of {{ $fmtInt($paymentHistoryRows->count()) }} rows. Actual paid date vs master due date.</span>
            </div>
            <div class="lri-table-wrap">
                <table class="table table-bordered lri-table">
                    <thead>
                        <tr>
                            <th>Actual Paid</th>
                            <th>Expected Due</th>
                            <th>Timing</th>
                            <th class="lri-num">Days Late</th>
                            <th>Completion</th>
                            <th>Site</th>
                            <th>Invoice</th>
                            <th>SO</th>
                            <th>Customer</th>
                            <th class="lri-num">Paid Amount</th>
                            <th class="lri-num">Remaining</th>
                            <th class="lri-num">Paid %</th>
                            <th>Payment Terms</th>
                            <th>Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($displayPaymentHistoryRows as $row)
                            <tr>
                                <td class="fw-bold">{{ $row->actual_payment_date }}</td>
                                <td>{{ $row->expected_payment_date }}</td>
                                <td>
                                    <span class="lri-badge {{ $row->payment_timing === 'ON_TIME' ? 'on-time' : 'late' }}">
                                        {{ $row->payment_timing === 'ON_TIME' ? 'On time' : 'Late' }}
                                    </span>
                                </td>
                                <td class="lri-num {{ $row->days_late > 0 ? 'text-danger fw-bold' : 'text-success' }}">{{ $fmtInt(max(0, (int) $row->days_late)) }}</td>
                                <td>{{ $row->payment_completion === 'FULL' ? 'Paid full' : 'Partial paid' }}</td>
                                <td>{{ $row->site }}</td>
                                <td>{{ $row->invnumber }}</td>
                                <td>{{ $row->ordnumber }}</td>
                                <td><div class="lri-text">{{ $row->customer_name }}</div></td>
                                <td class="lri-num fw-bold">{{ $fmt($row->paid_amount) }}</td>
                                <td class="lri-num">{{ $fmt($row->remaining) }}</td>
                                <td class="lri-num">{{ number_format((float) $row->paid_ratio, 1) }}%</td>
                                <td>
                                    <div>{{ $row->payment_term_label ?: '-' }}</div>
                                    @if ($row->billing_plan_label)
                                        <div class="text-muted small">{{ $row->billing_plan_label }}</div>
                                    @endif
                                    @if ($row->payment_schedule_label)
                                        <div class="text-muted small">{{ $row->payment_schedule_label }}</div>
                                    @endif
                                </td>
                                <td>
                                    <span class="lri-badge {{ $row->term_source === 'customer_payment_terms' ? '' : 'fallback' }}">
                                        {{ $sourceLabel($row->term_source) }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="14" class="text-center text-muted py-4">No payment history</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="lri-card">
            <div class="lri-card-header">
                <span><i class="fas fa-users me-1 text-primary"></i> Customer Payment Behavior Summary</span>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered lri-table mb-0">
                    <thead>
                        <tr>
                            <th>Site</th>
                            <th>Customer</th>
                            <th class="lri-num">Invoices</th>
                            <th class="lri-num">On-time</th>
                            <th class="lri-num">Late</th>
                            <th class="lri-num">On-time %</th>
                            <th class="lri-num">Avg Late Days</th>
                            <th class="lri-num">Partial</th>
                            <th>Risk</th>
                            <th>Last Payment</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($customerPaymentBehavior as $row)
                            <tr>
                                <td>{{ $row->site }}</td>
                                <td><div class="lri-text">{{ $row->customer_name }}</div></td>
                                <td class="lri-num">{{ $fmtInt($row->invoice_count) }}</td>
                                <td class="lri-num text-success">{{ $fmtInt($row->on_time_count) }}</td>
                                <td class="lri-num text-danger">{{ $fmtInt($row->late_count) }}</td>
                                <td class="lri-num fw-bold">{{ number_format((float) $row->on_time_rate, 1) }}%</td>
                                <td class="lri-num">{{ number_format((float) $row->avg_days_late, 1) }}</td>
                                <td class="lri-num">{{ $fmtInt($row->partial_count) }}</td>
                                <td><span class="lri-risk {{ strtolower($row->risk_tier) }}" title="{{ $riskTitle($row->risk_tier) }}">{{ $row->risk_label }} {{ number_format((float) $row->risk_score, 1) }}</span></td>
                                <td>{{ $row->last_payment_date }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="text-center text-muted py-4">No payment history</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (!window.TomSelect) return;
            document.querySelectorAll('.lri-tomselect').forEach(function (el) {
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
        });
    </script>
@endpush
