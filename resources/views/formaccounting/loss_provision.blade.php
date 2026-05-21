@extends('layouts.layout')

@section('title', 'รายการเผื่อผลขาดทุน')
@section('page-title', 'รายการเผื่อผลขาดทุน')

@section('content')
    @php
        $invoiceMap = collect($invoiceMap ?? []);
        $periods = collect($periods ?? []);
        $filters = $filters ?? [];
        $kpis = $kpis ?? [];
        $agingSummary = collect($agingSummary ?? []);
        $agingBuckets = $agingBuckets ?? \App\Services\FormAccounting\LossProvisionService::agingBuckets();
        $customerOptions = collect($customerOptions ?? []);
        $fmt = fn($value, $decimals = 0) => number_format((float) $value, $decimals);
        $moneyClass = fn($value) => (float) $value < 0 ? ' text-danger' : '';
        $statusLabels = [
            'PAID'    => 'จ่ายแล้ว',
            'PARTIAL' => 'ชำระบางส่วน',
            'UNPAID'  => 'ยังไม่จ่าย',
        ];
        $statusBadge = function ($status) {
            return match ($status) {
                'PAID'    => 'bg-success',
                'PARTIAL' => 'bg-warning text-dark',
                default   => 'bg-danger',
            };
        };
        $groupCustomer = ($filters['group_customer'] ?? '0') === '1';
        $unpaidOneYearParams = array_merge(request()->query(), [
            'date_from' => now('Asia/Bangkok')->subYear()->toDateString(),
            'date_to' => now('Asia/Bangkok')->toDateString(),
            'status' => 'UNPAID',
            'aging' => 'ALL',
        ]);

        // when grouping is on, sort by customer first
        $displayRows = $groupCustomer
            ? $invoiceMap->sortBy([
                ['customer_name', 'asc'],
                ['transdate', 'asc'],
                ['invnumber', 'asc'],
            ])->values()
            : $invoiceMap;

        // for row highlighting
        $rowClassFor = function ($row) {
            $remain = (float) $row->remaining;
            $days = (int) ($row->days_outstanding ?? 0);
            if (($row->status ?? '') !== 'PAID' && $days > 180 && $remain > 0.01) return 'lp-row-critical';
            if ($remain < -0.01) return 'lp-row-overpaid';
            if (($row->status ?? '') !== 'PAID' && $days > 90 && $remain > 0.01) return 'lp-row-warn';
            return '';
        };

        $totalColspan = 7 + $periods->count() + 4;
    @endphp

    <style>
        .lp-wrap { background:#f7f8fa; padding:16px; border-radius:8px; position:relative; }
        .lp-card { background:#fff; border:1px solid #e1e7ef; border-radius:8px; overflow:hidden; }
        .lp-card-header { padding:12px 16px; border-bottom:1px solid #e1e7ef; background:#fff; font-weight:700; }
        .lp-kpi { height:100%; padding:14px 16px; border:1px solid #e1e7ef; border-radius:8px; background:#fff; position:relative; overflow:hidden; }
        .lp-kpi::before { content:''; position:absolute; top:0; left:0; width:100%; height:3px; background:#2f4357; }
        .lp-kpi.kpi-amber::before { background:#f59e0b; }
        .lp-kpi.kpi-red::before { background:#dc2626; }
        .lp-kpi.kpi-green::before { background:#10b981; }
        .lp-kpi .label { color:#667085; font-size:.74rem; margin-bottom:6px; text-transform:uppercase; letter-spacing:.04em; font-weight:600; }
        .lp-kpi .value { color:#1f2937; font-size:1.35rem; font-weight:800; line-height:1.2; font-variant-numeric:tabular-nums; }
        .lp-kpi .value.text-red { color:#dc2626; }
        .lp-kpi .sub { color:#667085; font-size:.78rem; margin-top:6px; }
        .lp-kpi .meta-line { display:flex; justify-content:space-between; gap:8px; font-size:.78rem; color:#667085; margin-top:6px; }
        .lp-kpi .meta-line .meta-val { color:#1f2937; font-weight:700; }

        .lp-table-wrap { max-height:680px; overflow:auto; border:1px solid #e1e7ef; }
        .lp-table { min-width:1620px; }
        .lp-table th { position:sticky; top:0; z-index:2; background:#2f4357; color:#fff; white-space:nowrap; font-size:.76rem; vertical-align:middle; }
        .lp-table td { vertical-align:top; white-space:nowrap; }
        .lp-table tbody tr.lp-main-row:hover { background:#edf4fb !important; }
        .lp-table tfoot td { background:#d9edf8; border-top:2px solid #2f4357; font-weight:800; }
        .lp-text { max-width:300px; white-space:normal; word-break:break-word; line-height:1.35; }
        .lp-table th.lp-col-customer, .lp-table td.lp-col-customer { min-width:260px; max-width:340px; }
        .lp-table th.lp-col-status, .lp-table td.lp-col-status { min-width:190px; }
        .lp-table th.lp-col-aging, .lp-table td.lp-col-aging { min-width:210px; }
        .lp-doc { font-weight:800; color:#0f5132; }
        .lp-drill { width:30px; height:30px; display:inline-flex; align-items:center; justify-content:center; }
        .lp-detail-row td { background:#fbfdff; padding:0; }
        .lp-detail-panel { padding:12px 16px; border-left:4px solid #2f4357; }
        .lp-detail-table th { background:#eef3f8; color:#1f2937; position:static; }
        .lp-period { min-width:116px; }
        .num { text-align:right; font-variant-numeric:tabular-nums; }

        .lp-row-overpaid td { background:#fff8e1 !important; }
        .lp-row-warn     td { background:#fff4ed !important; }
        .lp-row-critical td { background:#fde4e4 !important; }
        .lp-customer-row td { background:#0f172a !important; color:#fff !important; font-weight:700; }
        .lp-customer-subtotal td { background:#dbeafe !important; font-weight:700; border-top:2px solid #2563eb; }

        .lp-aging-badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:.7rem; font-weight:700; color:#fff; min-width:54px; text-align:center; }
        .lp-aging-meta { font-size:.7rem; color:#667085; margin-top:2px; }

        .quick-actions .btn { white-space:nowrap; }
        .lp-loading-overlay { position:fixed; inset:0; background:rgba(15,23,42,.5); display:none; align-items:center; justify-content:center; z-index:2000; }
        .lp-loading-overlay.active { display:flex; }
        .lp-loading-overlay .box { position:relative; background:#fff; padding:24px 56px 24px 32px; border-radius:10px; box-shadow:0 6px 20px rgba(0,0,0,.2); display:flex; align-items:center; gap:14px; font-weight:600; color:#0f172a; min-width:240px; }
        .lp-loading-overlay .spinner { width:24px; height:24px; border:3px solid #e2e8f0; border-top-color:#2563eb; border-radius:50%; animation:lp-spin 0.7s linear infinite; }
        .lp-loading-overlay .close-btn { position:absolute; top:6px; right:8px; background:transparent; border:0; font-size:1.2rem; color:#94a3b8; cursor:pointer; line-height:1; padding:4px 8px; }
        .lp-loading-overlay .close-btn:hover { color:#dc2626; }
        @keyframes lp-spin { to { transform: rotate(360deg); } }

        .lp-summary-strip { display:flex; justify-content:flex-end; gap:18px; padding:10px 14px; background:#eef3f8; border-top:1px solid #cbd5e1; font-weight:700; flex-wrap:wrap; }
        .lp-summary-strip .item { color:#0f172a; }
        .lp-summary-strip .item .v { color:#2563eb; font-variant-numeric:tabular-nums; margin-left:6px; }
        .lp-summary-strip .item.danger .v { color:#dc2626; }

        .lp-chart-box { padding:12px 14px; height:280px; }

        .ts-dropdown { z-index: 1080 !important; }
        body > .ts-dropdown { position: absolute; }

        @media (max-width: 767.98px) {
            .lp-wrap { padding:10px; }
            .lp-kpi .value { font-size:1.08rem; }
        }
    </style>

    <div class="lp-wrap">

        {{-- ========== Filters ========== --}}
        <div class="lp-card mb-3">
            <div class="lp-card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-filter me-1 text-primary"></i> ตัวกรอง</span>
                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#lpFilters" aria-expanded="true">
                    <i class="fas fa-sliders-h me-1"></i> Toggle
                </button>
            </div>
            <div id="lpFilters" class="collapse show">
                <form method="GET" action="{{ route('accounting.loss-provision.index') }}" class="p-3" id="lpFilterForm">
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-2 col-md-4">
                            <label class="form-label">From Date</label>
                            <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <label class="form-label">To Date</label>
                            <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <label class="form-label">Site</label>
                            <select name="site" class="form-select">
                                <option value="ALL" {{ ($filters['site'] ?? 'ALL') === 'ALL' ? 'selected' : '' }}>ALL</option>
                                <option value="WIRE" {{ ($filters['site'] ?? '') === 'WIRE' ? 'selected' : '' }}>WIRE</option>
                                <option value="PLUS" {{ ($filters['site'] ?? '') === 'PLUS' ? 'selected' : '' }}>PLUS</option>
                            </select>
                        </div>
                        <div class="col-lg-6 col-md-12">
                            <label class="form-label">ลูกค้า</label>
                            @php $custVal = (string) ($filters['customer'] ?? ''); @endphp
                            <select name="customer" class="form-select lp-tomselect" data-placeholder="-- ทุกลูกค้า --">
                                <option value=""></option>
                                @if ($custVal !== '' && !$customerOptions->contains($custVal))
                                    <option value="{{ $custVal }}" selected>{{ $custVal }}</option>
                                @endif
                                @foreach ($customerOptions as $cust)
                                    <option value="{{ $cust }}" {{ $custVal === $cust ? 'selected' : '' }}>{{ $cust }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-lg-4 col-md-6">
                            <label class="form-label">Invoice / SO / Voucher</label>
                            <input type="text" name="invoice" class="form-control" value="{{ $filters['invoice'] ?? '' }}">
                        </div>

                        <div class="col-lg-3 col-md-4">
                            <label class="form-label">สถานะ</label>
                            <select name="status" class="form-select">
                                <option value="ALL" {{ ($filters['status'] ?? 'ALL') === 'ALL' ? 'selected' : '' }}>ทั้งหมด</option>
                                <option value="UNPAID" {{ ($filters['status'] ?? '') === 'UNPAID' ? 'selected' : '' }}>ยังไม่จ่าย</option>
                                <option value="PARTIAL" {{ ($filters['status'] ?? '') === 'PARTIAL' ? 'selected' : '' }}>ชำระบางส่วน</option>
                                <option value="PAID" {{ ($filters['status'] ?? '') === 'PAID' ? 'selected' : '' }}>จ่ายแล้ว</option>
                            </select>
                        </div>
                        <div class="col-lg-3 col-md-4">
                            <label class="form-label">อายุหนี้ (Aging)</label>
                            <select name="aging" class="form-select">
                                <option value="ALL" {{ ($filters['aging'] ?? 'ALL') === 'ALL' ? 'selected' : '' }}>ทั้งหมด</option>
                                @foreach ($agingBuckets as $key => $bucket)
                                    <option value="{{ $key }}" {{ ($filters['aging'] ?? '') === $key ? 'selected' : '' }}>{{ $bucket['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-lg-3 col-md-4 d-flex align-items-end">
                            <div class="form-check pt-3">
                                <input class="form-check-input" type="checkbox" name="group_customer" id="lpGroupCustomer" value="1" {{ $groupCustomer ? 'checked' : '' }}>
                                <label class="form-check-label" for="lpGroupCustomer">จัดกลุ่มตามลูกค้า (มี Sub-total)</label>
                            </div>
                        </div>

                        {{-- Quick status buttons --}}
                        <div class="col-lg-12">
                            <div class="d-flex flex-wrap gap-2">
                                <span class="text-muted small me-2 align-self-center">ปุ่มลัด:</span>
                                <button type="submit" name="status" value="UNPAID" class="btn btn-sm btn-outline-danger">
                                    <i class="fas fa-times-circle me-1"></i> ยังไม่จ่าย
                                </button>
                                <a href="{{ route('accounting.loss-provision.index', $unpaidOneYearParams) }}" class="btn btn-sm btn-danger">
                                    <i class="fas fa-calendar-days me-1"></i> ยังไม่จ่ายทั้งหมด 1 ปี
                                </a>
                                <button type="submit" name="status" value="PARTIAL" class="btn btn-sm btn-outline-warning">
                                    <i class="fas fa-adjust me-1"></i> ชำระบางส่วน
                                </button>
                                <button type="submit" name="status" value="PAID" class="btn btn-sm btn-outline-success">
                                    <i class="fas fa-check-circle me-1"></i> จ่ายแล้ว
                                </button>
                                <span class="vr mx-1"></span>
                                <button type="submit" name="aging" value="B91_180" class="btn btn-sm btn-outline-warning">
                                    <i class="fas fa-clock me-1"></i> ค้าง 91–180 วัน
                                </button>
                                <button type="submit" name="aging" value="B180_PLUS" class="btn btn-sm btn-outline-danger">
                                    <i class="fas fa-fire me-1"></i> ค้างเกิน 180 วัน
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-3 quick-actions">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i> Search</button>
                        <a href="{{ route('accounting.loss-provision.index') }}" class="btn btn-outline-secondary"><i class="fas fa-rotate-left me-1"></i> Reset</a>
                        <a href="{{ route('accounting.loss-provision.export', request()->query()) }}" class="btn btn-success"><i class="fas fa-file-excel me-1"></i> Export Excel</a>
                        <a href="{{ route('accounting.loss-provision.yearly', ['site' => $filters['site'] ?? 'ALL']) }}" class="btn btn-outline-primary ms-auto">
                            <i class="fas fa-calendar-alt me-1"></i> ดูสรุปทั้งปี
                        </a>
                    </div>
                </form>
            </div>
        </div>

        {{-- ========== KPIs ========== --}}
        <div class="row g-3 mb-3">
            <div class="col-xl-3 col-md-6">
                <div class="lp-kpi">
                    <div class="label">จำนวนเงินรวม</div>
                    <div class="value">{{ $fmt($kpis['total_amount'] ?? 0, 2) }}</div>
                    <div class="sub">ยอดตามเอกสารหลัก</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="lp-kpi kpi-green">
                    <div class="label">รวมรับชำระ / คืนสินค้า</div>
                    <div class="value">{{ $fmt($kpis['total_cash'] ?? 0, 2) }}</div>
                    <div class="sub">cashin และ return</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="lp-kpi kpi-amber">
                    <div class="label">คงเหลือ</div>
                    <div class="value{{ $moneyClass($kpis['remaining'] ?? 0) }}">{{ $fmt($kpis['remaining'] ?? 0, 2) }}</div>
                    <div class="meta-line">
                        <span>เอกสาร: <span class="meta-val">{{ $fmt($kpis['invoice_count'] ?? 0) }}</span></span>
                        <span>ลูกค้า: <span class="meta-val">{{ $fmt($kpis['customer_count'] ?? 0) }}</span></span>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="lp-kpi kpi-red">
                    <div class="label">หนี้ค้างนาน (Critical)</div>
                    <div class="value text-red">{{ $fmt($kpis['over_180'] ?? 0, 2) }}</div>
                    <div class="meta-line">
                        <span>&gt; 90 วัน: <span class="meta-val">{{ $fmt($kpis['over_90'] ?? 0, 2) }}</span></span>
                        <span>&gt; 180 วัน: <span class="meta-val text-danger">{{ $fmt($kpis['over_180'] ?? 0, 2) }}</span></span>
                    </div>
                </div>
            </div>
        </div>

        {{-- ========== Aging Chart + Breakdown Table ========== --}}
        @if ($agingSummary->isNotEmpty() && $agingSummary->sum('remaining') > 0.01)
            <div class="row g-3 mb-3">
                <div class="col-xl-5">
                    <div class="lp-card h-100">
                        <div class="lp-card-header">
                            <i class="fas fa-chart-pie me-1 text-primary"></i> สัดส่วนคงเหลือตามอายุหนี้
                        </div>
                        <div class="lp-chart-box">
                            <canvas id="lpAgingChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-xl-7">
                    <div class="lp-card h-100">
                        <div class="lp-card-header">
                            <i class="fas fa-table me-1 text-primary"></i> สรุปอายุหนี้ (เฉพาะที่ยังไม่จ่ายครบ)
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm mb-0">
                                <thead style="background:#2f4357; color:#fff;">
                                    <tr>
                                        <th>ช่วงอายุ</th>
                                        <th class="num">จำนวนเอกสาร</th>
                                        <th class="num">ยอดคงเหลือ</th>
                                        <th class="num">% ของรวม</th>
                                        <th>ดูรายการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @php $agingTotal = max((float) $agingSummary->sum('remaining'), 0.0001); @endphp
                                    @foreach ($agingSummary as $bucket)
                                        <tr>
                                            <td>
                                                <span class="lp-aging-badge" style="background:{{ $bucket->color }}">{{ $bucket->label }}</span>
                                            </td>
                                            <td class="num">{{ $fmt($bucket->count) }}</td>
                                            <td class="num fw-bold">{{ $fmt($bucket->remaining, 2) }}</td>
                                            <td class="num">{{ $bucket->remaining > 0 ? number_format($bucket->remaining / $agingTotal * 100, 1) . '%' : '-' }}</td>
                                            <td>
                                                <a href="{{ route('accounting.loss-provision.index', array_merge(request()->query(), ['aging' => $bucket->key])) }}" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-filter"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- ========== Main Table ========== --}}
        <div class="lp-card">
            <div class="lp-card-header d-flex flex-wrap justify-content-between gap-2">
                <span><i class="fas fa-list me-1 text-primary"></i> Map เอกสารหลัก AR กับเอกสารรับชำระ</span>
                <span class="text-muted small">
                    แสดง {{ number_format($invoiceMap->count()) }} เอกสารหลัก / {{ number_format($allRowCount ?? 0) }} รายการรับชำระ
                </span>
            </div>
            <div class="lp-table-wrap">
                <table class="table table-bordered table-sm lp-table mb-0">
                    <thead>
                        <tr>
                            <th></th>
                            <th>Site</th>
                            <th>วันที่</th>
                            <th>เอกสารเลขที่</th>
                            <th>SO</th>
                            <th class="lp-col-customer">Customer</th>
                            <th class="lp-col-status">เงื่อนไข / สถานะ</th>
                            <th class="lp-col-aging">วันครบชำระ / อายุหนี้</th>
                            <th class="num">มูลค่าสินค้า / บริการ</th>
                            <th class="num">ภาษี</th>
                            <th class="num">จำนวนเงิน</th>
                            @foreach ($periods as $period)
                                <th class="num lp-period">{{ $period->label }}</th>
                            @endforeach
                            <th class="num">รวมรับชำระ</th>
                            <th class="num">คงเหลือ</th>
                            <th class="num">เอกสารจ่าย</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $prevCustomer = null;
                            $custCount = 0;
                            $custNet = 0;
                            $custAr = 0;
                            $custCash = 0;
                            $custRemain = 0;
                            $voucherDataMap = [];
                        @endphp
                        @forelse ($displayRows as $row)
                            @php
                                $rowCustomer = $row->customer_name ?? '';
                                $collapseId = 'lp-detail-' . md5($row->site_ar_id);
                                $bucketCfg = $agingBuckets[$row->aging_key] ?? null;
                                $rowClass = $rowClassFor($row);
                            @endphp

                            @if ($groupCustomer && $rowCustomer !== $prevCustomer)
                                @if ($prevCustomer !== null)
                                    {{-- emit prev customer subtotal --}}
                                    <tr class="lp-customer-subtotal">
                                        <td colspan="8" class="text-end">รวม {{ $custCount }} รายการ — {{ $prevCustomer ?: '-' }}</td>
                                        <td class="num">{{ $fmt($custNet, 2) }}</td>
                                        <td></td>
                                        <td class="num">{{ $fmt($custAr, 2) }}</td>
                                        @foreach ($periods as $period) <td></td> @endforeach
                                        <td class="num">{{ $fmt($custCash, 2) }}</td>
                                        <td class="num">{{ $fmt($custRemain, 2) }}</td>
                                        <td></td>
                                    </tr>
                                @endif
                                <tr class="lp-customer-row">
                                    <td colspan="{{ $totalColspan }}">
                                        <i class="fas fa-user me-1"></i> {{ $rowCustomer ?: '(ไม่ระบุลูกค้า)' }}
                                    </td>
                                </tr>
                                @php $custCount = 0; $custNet = 0; $custAr = 0; $custCash = 0; $custRemain = 0; @endphp
                            @endif

                            @php
                                $custCount++;
                                $custNet += (float) $row->netamount;
                                $custAr += (float) $row->ar_amount;
                                $custCash += (float) $row->total_cash;
                                $custRemain += (float) $row->remaining;
                                $prevCustomer = $rowCustomer;
                            @endphp

                            @php
                                $voucherDataMap[$collapseId] = $row->vouchers->map(fn($v) => [
                                    'vouchernumber' => (string) ($v->vouchernumber ?? ''),
                                    'payment_source' => (string) ($v->payment_source ?? ''),
                                    'cashin_transdate' => (string) ($v->cashin_transdate ?? ''),
                                    'cash_amount' => (float) ($v->cash_amount ?? 0),
                                    'cashin_description' => (string) ($v->cashin_description ?? ''),
                                    'cashin_notes' => (string) ($v->cashin_notes ?? ''),
                                ])->values();
                            @endphp
                            <tr class="lp-main-row {{ $rowClass }}">
                                <td>
                                    <button class="btn btn-sm btn-outline-primary lp-drill lp-toggle-detail" type="button" data-detail-for="{{ $collapseId }}" aria-expanded="false" title="ดูเอกสารรับชำระ">
                                        <i class="fas fa-chevron-down"></i>
                                    </button>
                                </td>
                                <td>{{ $row->site }}</td>
                                <td>{{ $row->transdate }}</td>
                                <td>
                                    <span class="lp-doc">{{ $row->invnumber }}</span>
                                    @if (($row->document_type ?? '') === 'RETURN' && !empty($row->refnumber))
                                        <div class="text-muted small">อ้างอิง AR: {{ $row->refnumber }}</div>
                                    @endif
                                </td>
                                <td>{{ $row->ordnumber }}</td>
                                <td class="lp-col-customer"><div class="lp-text">{{ $row->customer_name }}</div></td>
                                <td class="lp-col-status">
                                    <div class="fw-semibold small">{{ $row->payment_term_label ?? '-' }}</div>
                                    @if (!empty($row->billing_plan_label))
                                        <div class="text-muted small">{{ $row->billing_plan_label }}</div>
                                    @endif
                                    @if (!empty($row->payment_schedule_label))
                                        <div class="text-muted small">{{ $row->payment_schedule_label }}</div>
                                    @endif
                                    <span class="badge mt-1 {{ $statusBadge($row->status) }}">
                                        {{ $statusLabels[$row->status] ?? $row->status }}
                                    </span>
                                </td>
                                <td class="lp-col-aging">
                                    @if (($row->status ?? '') !== 'PAID' && $bucketCfg)
                                        <span class="lp-aging-badge" style="background:{{ $bucketCfg['color'] }}">{{ $bucketCfg['label'] }}</span>
                                        <div class="lp-aging-meta">
                                            {{ $row->days_outstanding ?? 0 }} วัน
                                            · วันครบชำระ {{ $row->aging_base_date ?? '-' }}
                                            <span class="text-muted">
                                                ({{ ($row->aging_base_source ?? '') === 'customer_payment_terms' ? 'เงื่อนไข master' : 'ERP fallback' }})
                                            </span>
                                        </div>
                                    @else
                                        @if (!empty($row->aging_base_date))
                                            <span class="text-muted small">วันครบชำระ {{ $row->aging_base_date }}</span>
                                        @else
                                            <span class="text-muted small">-</span>
                                        @endif
                                    @endif
                                </td>
                                <td class="num{{ $moneyClass($row->netamount) }}">{{ $fmt($row->netamount, 2) }}</td>
                                <td class="num{{ $moneyClass($row->taxable) }}">{{ $fmt($row->taxable, 2) }}</td>
                                <td class="num fw-bold{{ $moneyClass($row->ar_amount) }}">{{ $fmt($row->ar_amount, 2) }}</td>
                                @foreach ($periods as $period)
                                    @php $amount = (float) ($row->period_amounts[$period->key] ?? 0); @endphp
                                    <td class="num{{ $moneyClass($amount) }}">{{ abs($amount) > 0.00001 ? $fmt($amount, 2) : '' }}</td>
                                @endforeach
                                <td class="num fw-bold{{ $moneyClass($row->total_cash) }}">{{ $fmt($row->total_cash, 2) }}</td>
                                <td class="num fw-bold{{ $moneyClass($row->remaining) }}">{{ $fmt($row->remaining, 2) }}</td>
                                <td class="num">{{ $fmt($row->voucher_count) }}</td>
                            </tr>
                            <tr class="lp-detail-row" id="{{ $collapseId }}-row" style="display:none;" data-rendered="0" data-invnumber="{{ $row->invnumber }}">
                                <td colspan="{{ $totalColspan }}">
                                    <div class="lp-detail-panel">
                                        <div class="fw-bold mb-2">เอกสารรับชำระของ {{ $row->invnumber }}</div>
                                        <div class="lp-detail-content"></div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $totalColspan }}" class="text-center text-muted py-4">ไม่มีข้อมูลตามตัวกรองที่เลือก</td></tr>
                        @endforelse

                        {{-- last customer subtotal --}}
                        @if ($groupCustomer && $prevCustomer !== null)
                            <tr class="lp-customer-subtotal">
                                <td colspan="8" class="text-end">รวม {{ $custCount }} รายการ — {{ $prevCustomer ?: '-' }}</td>
                                <td class="num">{{ $fmt($custNet, 2) }}</td>
                                <td></td>
                                <td class="num">{{ $fmt($custAr, 2) }}</td>
                                @foreach ($periods as $period) <td></td> @endforeach
                                <td class="num">{{ $fmt($custCash, 2) }}</td>
                                <td class="num">{{ $fmt($custRemain, 2) }}</td>
                                <td></td>
                            </tr>
                        @endif
                    </tbody>
                    @if ($invoiceMap->isNotEmpty())
                        <tfoot>
                            <tr>
                                <td colspan="8">Total</td>
                                <td class="num">{{ $fmt($invoiceMap->sum('netamount'), 2) }}</td>
                                <td class="num">{{ $fmt($invoiceMap->sum('taxable'), 2) }}</td>
                                <td class="num">{{ $fmt($invoiceMap->sum('ar_amount'), 2) }}</td>
                                @foreach ($periods as $period)
                                    <td class="num">{{ $fmt($invoiceMap->sum(fn($r) => (float) ($r->period_amounts[$period->key] ?? 0)), 2) }}</td>
                                @endforeach
                                <td class="num">{{ $fmt($invoiceMap->sum('total_cash'), 2) }}</td>
                                <td class="num">{{ $fmt($invoiceMap->sum('remaining'), 2) }}</td>
                                <td class="num">{{ $fmt($invoiceMap->sum('voucher_count')) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>

            <script>
                window.lpVouchers = @json($voucherDataMap);
            </script>

            @if ($invoiceMap->isNotEmpty())
                <div class="lp-summary-strip">
                    <span class="item">เอกสาร: <span class="v">{{ number_format($invoiceMap->count()) }}</span></span>
                    <span class="item">ยอดรวม: <span class="v">{{ $fmt($invoiceMap->sum('ar_amount'), 2) }}</span></span>
                    <span class="item">รับชำระ: <span class="v">{{ $fmt($invoiceMap->sum('total_cash'), 2) }}</span></span>
                    <span class="item danger">คงเหลือ: <span class="v">{{ $fmt($invoiceMap->sum('remaining'), 2) }}</span></span>
                </div>
            @endif
        </div>
    </div>

    <div class="lp-loading-overlay" id="lpLoadingOverlay">
        <div class="box">
            <button type="button" class="close-btn" id="lpOverlayClose" title="ปิด">&times;</button>
            <div class="spinner"></div>
            <div id="lpOverlayMsg">กำลังโหลดข้อมูล…</div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        function lpEscape(str) {
            return String(str == null ? '' : str)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
        function lpFmt(v) {
            return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        function lpRenderVouchers(list) {
            if (!list || list.length === 0) {
                return '<div class="text-center text-muted py-3">ยังไม่มีเอกสารรับชำระ</div>';
            }
            var rows = list.map(function (v) {
                var amtClass = Number(v.cash_amount) < 0 ? ' text-danger' : '';
                return '<tr>' +
                    '<td class="fw-bold">' + lpEscape(v.vouchernumber) + '</td>' +
                    '<td>' + lpEscape(v.payment_source) + '</td>' +
                    '<td>' + lpEscape(v.cashin_transdate) + '</td>' +
                    '<td class="num' + amtClass + '">' + lpFmt(v.cash_amount) + '</td>' +
                    '<td><div class="lp-text">' + lpEscape(v.cashin_description) + '</div></td>' +
                    '<td><div class="lp-text">' + lpEscape(v.cashin_notes) + '</div></td>' +
                '</tr>';
            }).join('');
            return '<div class="table-responsive">' +
                '<table class="table table-sm table-bordered lp-detail-table mb-0">' +
                    '<thead><tr>' +
                        '<th>Voucher</th><th>Type</th><th>วันที่รับชำระ</th>' +
                        '<th class="num">ยอดรับชำระ</th><th>Description</th><th>Notes</th>' +
                    '</tr></thead>' +
                    '<tbody>' + rows + '</tbody>' +
                '</table></div>';
        }

        document.addEventListener('DOMContentLoaded', function () {
            // Lazy detail panel toggle
            document.querySelectorAll('.lp-toggle-detail').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var id = btn.dataset.detailFor;
                    var row = document.getElementById(id + '-row');
                    if (!row) return;
                    var icon = btn.querySelector('i');
                    if (row.dataset.rendered !== '1') {
                        var data = (window.lpVouchers && window.lpVouchers[id]) || [];
                        row.querySelector('.lp-detail-content').innerHTML = lpRenderVouchers(data);
                        row.dataset.rendered = '1';
                    }
                    var isHidden = row.style.display === 'none' || row.style.display === '';
                    row.style.display = isHidden ? 'table-row' : 'none';
                    btn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
                    if (icon) icon.className = isHidden ? 'fas fa-chevron-up' : 'fas fa-chevron-down';
                });
            });

            // Loading overlay
            const form = document.getElementById('lpFilterForm');
            const overlay = document.getElementById('lpLoadingOverlay');
            const overlayMsg = document.getElementById('lpOverlayMsg');
            const overlayClose = document.getElementById('lpOverlayClose');
            function showOverlay(msg) {
                if (!overlay) return;
                if (overlayMsg) overlayMsg.textContent = msg || 'กำลังโหลดข้อมูล…';
                overlay.classList.add('active');
            }
            function hideOverlay() { overlay && overlay.classList.remove('active'); }
            overlayClose && overlayClose.addEventListener('click', hideOverlay);

            if (form && overlay) {
                form.addEventListener('submit', function () { showOverlay('กำลังโหลดข้อมูล…'); });
            }
            // Export Excel link: show overlay, poll cookie to detect download completion
            function readCookie(name) {
                var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
                return m ? decodeURIComponent(m[1]) : null;
            }
            function clearCookie(name) {
                document.cookie = name + '=; Path=/; Max-Age=0';
            }
            document.querySelectorAll('a.btn-success').forEach(function (a) {
                if (a.tagName === 'A' && a.href && a.href.indexOf('#') === -1) {
                    a.addEventListener('click', function () {
                        clearCookie('lp_dl_token');
                        showOverlay('กำลังเตรียมไฟล์ Excel… กรุณารอสักครู่');
                        var startedAt = Date.now();
                        var poll = setInterval(function () {
                            // ปิด overlay ถ้า cookie มีค่าใดๆ (server set แล้ว) หรือเกิน 30 วินาที
                            if (readCookie('lp_dl_token')) {
                                clearInterval(poll);
                                clearCookie('lp_dl_token');
                                hideOverlay();
                            } else if (Date.now() - startedAt > 30000) {
                                clearInterval(poll);
                                hideOverlay();
                            }
                        }, 400);
                    });
                }
            });

            // TomSelect for customer
            if (window.TomSelect) {
                document.querySelectorAll('.lp-tomselect').forEach(function (el) {
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


            // Aging chart
            if (window.Chart) {
                const agingData = @json($agingSummary->map(fn($b) => ['label' => $b->label, 'remaining' => $b->remaining, 'color' => $b->color])->values());
                const filtered = agingData.filter(b => Number(b.remaining) > 0.01);
                const canvas = document.getElementById('lpAgingChart');
                if (canvas && filtered.length) {
                    new Chart(canvas, {
                        type: 'doughnut',
                        data: {
                            labels: filtered.map(b => b.label),
                            datasets: [{
                                data: filtered.map(b => Math.round(Number(b.remaining) * 100) / 100),
                                backgroundColor: filtered.map(b => b.color),
                                borderColor: '#fff',
                                borderWidth: 2,
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            cutout: '60%',
                            plugins: {
                                legend: { position: 'bottom' },
                                tooltip: {
                                    callbacks: {
                                        label: function (ctx) {
                                            const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                            const pct = total ? (ctx.raw / total * 100).toFixed(1) : 0;
                                            return ' ' + ctx.label + ': ' + Number(ctx.raw).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' (' + pct + '%)';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            }
        });
    </script>
@endpush
