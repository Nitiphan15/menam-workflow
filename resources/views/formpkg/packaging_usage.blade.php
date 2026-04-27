@extends('layouts.layout')

@section('title', 'สรุปการใช้บรรจุภัณฑ์')
@section('page-title', 'สรุปการใช้บรรจุภัณฑ์')

@section('content')
    @php
        $groupsCol = collect($groups ?? []);
        $productTotalsCol = collect($productTotals ?? []);

        $selectedSite = (string) ($site ?? '');
    @endphp

    <style>
        .pkg-wrap {
            background: #f7f8fa;
            padding: 16px;
            border-radius: 16px;
        }

        .pkg-card {
            background: #fff;
            border: 1px solid #e7e7e7;
            border-radius: 16px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, .04);
            overflow: hidden;
        }

        .pkg-card-header {
            padding: 14px 18px;
            border-bottom: 1px solid #ececec;
            background: linear-gradient(180deg, #fff, #fafafa);
        }

        .pkg-kpi {
            border: 1px solid #ececec;
            border-radius: 14px;
            background: #fff;
            padding: 14px;
            height: 100%;
        }

        .pkg-kpi .label {
            font-size: .85rem;
            color: #6b7280;
            margin-bottom: 6px;
        }

        .pkg-kpi .value {
            font-size: 1.35rem;
            font-weight: 700;
            color: #111827;
            line-height: 1.2;
        }

        .pkg-kpi .sub {
            margin-top: 6px;
            font-size: .78rem;
            color: #6b7280;
        }

        .table-pkg thead th {
            background: #efe2b8;
            color: #222;
            vertical-align: middle;
            white-space: nowrap;
            border-color: #d8d8d8;
        }

        .table-pkg tbody td {
            vertical-align: middle;
            border-color: #d8d8d8;
        }

        .cell-demand {
            color: #d90429;
            font-weight: 700;
        }

        .cell-balance {
            color: #0d6efd;
            font-weight: 700;
        }

        .site-badge {
            display: inline-block;
            font-size: .78rem;
            padding: .42rem .68rem;
            border-radius: 999px;
            border: 1px solid transparent;
            min-width: 58px;
            text-align: center;
        }

        .site-wire {
            background: rgba(13, 110, 253, .10);
            color: #0d6efd;
            border-color: rgba(13, 110, 253, .18);
        }

        .site-plus {
            background: rgba(25, 135, 84, .10);
            color: #198754;
            border-color: rgba(25, 135, 84, .18);
        }

        .chip-pack {
            display: inline-block;
            margin: 2px 4px 2px 0;
            padding: 4px 10px;
            border-radius: 999px;
            background: #f6e7ef;
            border: 1px solid #ead0dd;
            font-size: .82rem;
            white-space: nowrap;
        }

        .btn-wo {
            white-space: nowrap;
        }

        .wo-subtable {
            font-size: .92rem;
        }

        .wo-subtable th {
            background: #f6f7f9;
        }

        .row-shortage {
            background: #fff5f5;
        }

        .row-risk {
            background: #fffaf0;
        }

        .mini-bar-wrap {
            min-width: 170px;
        }

        .mini-bar-row {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 4px;
        }

        .mini-bar-row:last-child {
            margin-bottom: 0;
        }

        .mini-label {
            width: 32px;
            flex: 0 0 32px;
            font-size: .75rem;
            font-weight: 700;
            color: #6b7280;
        }

        .mini-track {
            position: relative;
            flex: 1;
            height: 10px;
            background: #edf0f3;
            border-radius: 999px;
            overflow: hidden;
        }

        .mini-fill {
            height: 100%;
            border-radius: 999px;
        }

        .mini-demand {
            background: #dc3545;
        }

        .mini-stock {
            background: #0d6efd;
        }

        .mini-value {
            min-width: 42px;
            text-align: right;
            font-size: .75rem;
            color: #374151;
        }

        .coverage-text {
            font-weight: 700;
            white-space: nowrap;
        }

        .coverage-shortage {
            color: #dc3545;
        }

        .coverage-risk {
            color: #b7791f;
        }

        .coverage-enough {
            color: #198754;
        }

        .coverage-idle {
            color: #6c757d;
        }

        .pack-name {
            font-weight: 500;
        }

        .pack-sub {
            margin-top: 4px;
            font-size: .80rem;
            color: #6b7280;
        }

        .pkg-legend {
            padding: 10px 18px 0 18px;
            font-size: .82rem;
            color: #6b7280;
        }

        .sticky-note {
            display: inline-block;
            padding: .3rem .55rem;
            border-radius: 10px;
            background: #f8f9fa;
            border: 1px solid #ececec;
            font-size: .78rem;
            color: #6b7280;
        }

        .table-summary thead th {
            background: #f8f9fa;
            border-color: #dee2e6;
            white-space: nowrap;
        }

        .table-summary td,
        .table-summary th {
            border-color: #dee2e6;
        }

        .summary-collapse-btn {
            border: 0;
            background: transparent;
            font-weight: 600;
            color: #212529;
            padding: 0;
        }

        .summary-hint {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            margin-left: 6px;
            border-radius: 50%;
            background: #eef2f7;
            color: #6b7280;
            font-size: .72rem;
            font-weight: 700;
            cursor: help;
            border: 1px solid #d9dee7;
            vertical-align: middle;
        }

        .summary-footnote {
            padding: 10px 14px 14px 14px;
            font-size: .82rem;
            color: #6b7280;
        }

        @media (max-width: 991.98px) {
            .pkg-kpi .value {
                font-size: 1.15rem;
            }

            .mini-bar-wrap {
                min-width: 150px;
            }
        }
    </style>

    <div class="pkg-wrap">
        {{-- KPI --}}
        <div class="row g-3 mb-3">
            <div class="col-md-2">
                <div class="pkg-kpi">
                    <div class="label">ค้างผลิตรวม (KG)</div>
                    <div class="value">{{ number_format((float) $groupsCol->sum('sum_qty'), 2) }}</div>
                </div>
            </div>

            <div class="col-md-2">
                <div class="pkg-kpi">
                    <div class="label">Demand รวม (PCS)</div>
                    <div class="value">{{ number_format((int) $groupsCol->sum('demand_pcs')) }}</div>
                </div>
            </div>

            <div class="col-md-2">
                <div class="pkg-kpi">
                    <div class="label">รายการที่ขาด</div>
                    <div class="value text-danger">
                        {{ number_format($groupsCol->filter(fn($x) => (float) ($x->shortage_pcs ?? 0) > 0)->count()) }}
                    </div>
                </div>
            </div>

            <div class="col-md-2">
                <div class="pkg-kpi">
                    <div class="label">ขาดรวม (PCS)</div>
                    <div class="value text-danger">
                        {{ number_format((float) $groupsCol->sum(fn($x) => (float) ($x->shortage_pcs ?? 0)), 0) }}
                    </div>
                </div>
            </div>

            <div class="col-md-2">
                <div class="pkg-kpi">
                    <div class="label">Coverage Avg</div>
                    <div
                        class="value {{ is_numeric($coverageAvg ?? null) && (float) $coverageAvg < 120 ? 'text-warning' : '' }}">
                        {{ is_numeric($coverageAvg ?? null) ? number_format((float) $coverageAvg, 2) . '%' : '-' }}
                    </div>
                    <div class="sub">เฉลี่ยจากรายการที่มี Demand</div>
                </div>
            </div>

            <div class="col-md-2">
                <div class="pkg-kpi">
                    <div class="label">Risk Codes</div>
                    <div class="value text-warning">
                        {{ number_format((int) ($riskCount ?? 0)) }}
                    </div>
                    <div class="sub">ขาด + เสี่ยง</div>
                </div>
            </div>
        </div>

        @php
            $dateType = $dateType ?? 'reqdate';
            $dateFrom = $dateFrom ?? '';
            $dateTo = $dateTo ?? '';
            $selectedSite = (string) ($site ?? '');
            $selectedProduct = (string) ($product ?? '');
        @endphp

        {{-- Filter --}}
        <div class="pkg-card mb-3">
            <div class="pkg-card-header">
                <strong>ตัวกรอง</strong>
            </div>
            <div class="p-3">
                <form method="GET" action="{{ route('pkg.packaging.usage') }}" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">เงื่อนไขวันที่</label>
                        <select name="date_type" class="form-select">
                            <option value="reqdate" {{ $dateType === 'reqdate' ? 'selected' : '' }}>
                                วันกำหนดส่ง
                            </option>
                            <option value="opendate" {{ $dateType === 'opendate' ? 'selected' : '' }}>
                                วันที่เปิดเอกสาร
                            </option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">จากวันที่</label>
                        <input type="date" name="date_from" class="form-control" value="{{ $dateFrom }}">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">ถึงวันที่</label>
                        <input type="date" name="date_to" class="form-control" value="{{ $dateTo }}">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Product</label>
                        <select name="product" class="form-select">
                            <option value="">-- ทั้งหมด --</option>
                            @foreach ($productOptions ?? [] as $opt)
                                <option value="{{ $opt }}"
                                    {{ $selectedProduct === (string) $opt ? 'selected' : '' }}>
                                    {{ $opt }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Standard Pack</label>
                        <select name="fpack" class="form-select">
                            <option value="">-- ทั้งหมด --</option>
                            @foreach ($fpackOptions ?? [] as $opt)
                                <option value="{{ $opt }}"
                                    {{ (string) ($fpack ?? '') === (string) $opt ? 'selected' : '' }}>
                                    {{ $opt }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Site</label>
                        <select name="site" class="form-select">
                            @foreach ($siteOptions as $opt)
                                <option value="{{ $opt['value'] }}"
                                    {{ $selectedSite === (string) $opt['value'] ? 'selected' : '' }}>
                                    {{ $opt['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">สถานะ</label>
                        <select name="status" class="form-select">
                            @foreach ($statusOptions ?? [] as $opt)
                                <option value="{{ $opt['value'] }}"
                                    {{ (string) ($status ?? '') === (string) $opt['value'] ? 'selected' : '' }}>
                                    {{ $opt['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-12 d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-1"></i> ค้นหา
                        </button>

                        <a href="{{ route('pkg.packaging.usage') }}" class="btn btn-outline-secondary">
                            ล้างค่า
                        </a>

                        <a href="{{ route('pkg.packaging.usage.export', request()->query()) }}" class="btn btn-success">
                            Export
                        </a>

                        <a href="{{ route('pkg.packaging.usage', array_merge(request()->query(), ['status' => 'shortage'])) }}"
                            class="btn btn-outline-danger">
                            ดูเฉพาะขาด
                        </a>

                        <a href="{{ route('pkg.packaging.usage', array_merge(request()->query(), ['status' => 'risk'])) }}"
                            class="btn btn-outline-warning">
                            ดูเฉพาะเสี่ยง
                        </a>
                    </div>
                </form>
            </div>
        </div>

        {{-- Product / Site Summary --}}
        @if ($productTotalsCol->isNotEmpty())
            <div class="pkg-card mb-3">
                <div class="pkg-card-header d-flex justify-content-between align-items-center">
                    <button class="summary-collapse-btn" type="button" data-bs-toggle="collapse"
                        data-bs-target="#productSiteSummaryBox" aria-expanded="true" aria-controls="productSiteSummaryBox">
                        สรุปตาม Product / Site
                    </button>

                    <span class="sticky-note">คลิกเพื่อแสดง / ซ่อน</span>
                </div>

                <div class="collapse show" id="productSiteSummaryBox">
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm align-middle mb-0 table-summary">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th class="text-center">จาก</th>
                                    <th class="text-end">เปิดผลิต</th>
                                    <th class="text-end">ผลิตไปแล้ว</th>
                                    <th class="text-end">ค้างผลิต</th>
                                    <th class="text-end">
                                        จำนวนบรรจุภัณฑ์ที่ต้องใช้ (ประมาณ)
                                        <span class="summary-hint" data-bs-toggle="tooltip" data-bs-placement="top"
                                            title="คำนวณจากค้างผลิต ÷ package_per_kg และอาจรวมหลายบรรจุภัณฑ์ตามที่ map ไว้ใน packaging master">
                                            ?
                                        </span>
                                    </th>
                                    <th class="text-end">WO</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($productTotalsCol as $pt)
                                    @php
                                        $sumSite = strtoupper((string) ($pt->source_site ?? ''));
                                        $sumSiteClass = match ($sumSite) {
                                            'WIRE' => 'site-wire',
                                            'PLUS' => 'site-plus',
                                            default => '',
                                        };
                                    @endphp
                                    <tr>
                                        <td>{{ $pt->product ?: '-' }}</td>
                                        <td class="text-center">
                                            <span class="site-badge {{ $sumSiteClass }}">
                                                {{ $pt->source_site ?: '-' }}
                                            </span>
                                        </td>
                                        <td class="text-end">{{ number_format((float) ($pt->sum_open_qty ?? 0), 2) }}</td>
                                        <td class="text-end">{{ number_format((float) ($pt->sum_produced_qty ?? 0), 2) }}
                                        </td>
                                        <td class="text-end fw-semibold">
                                            {{ number_format((float) ($pt->sum_balance_qty ?? 0), 2) }}</td>
                                        <td class="text-end">
                                            {{ number_format((float) ($pt->sum_packs_used ?? 0), 0) }} <span
                                                class="text-muted"></span>
                                        </td>
                                        <td class="text-end">{{ number_format((int) ($pt->count_wo ?? 0)) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="summary-footnote">
                        * จำนวนบรรจุภัณฑ์ที่ต้องใช้ (ประมาณ) คำนวณจากค้างผลิต ÷ package_per_kg
                    </div>
                </div>
            </div>
        @endif

        {{-- Main table --}}
        <div class="pkg-card">
            <div class="pkg-card-header d-flex justify-content-between align-items-center">
                <strong>สรุปการใช้บรรจุภัณฑ์</strong>
                <small class="text-muted">
                    ค้นหาจาก
                    {{ ($dateType ?? 'reqdate') === 'opendate' ? 'วันที่เปิดเอกสาร' : 'วันกำหนดส่ง' }}
                    :
                    {{ $dateFrom ?: '-' }} ถึง {{ $dateTo ?: '-' }}
                </small>
            </div>

            <div class="pkg-legend">
                DEM = Demand, STK = Stock,
                Coverage &lt; 100% = ขาด,
                100% - 119.99% = เสี่ยง,
                ≥ 120% = พอ
            </div>

            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0 table-pkg">
                    <thead>
                        <tr>
                            <th class="text-center">ดู WO</th>
                            <th>CODE บรรจุภัณฑ์</th>
                            <th class="text-center">จาก</th>
                            <th>ชื่อบรรจุภัณฑ์</th>
                            <th class="text-end">ค้างผลิต (KG)</th>
                            <th class="text-end">Demand (PCS)</th>
                            <th class="text-end">Stock (PCS)</th>
                            <th class="text-end">คงเหลือ</th>
                            <th class="text-end">ขาด (PCS)</th>
                            <th class="text-center">Coverage %</th>
                            <th class="text-center">สถานะ</th>
                            <th>Bar</th>
                            <th>Standard Pack</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($groupsCol as $i => $g)
                            @php
                                $rowSite = strtoupper((string) ($g->source_site ?? ''));
                                $modalId = 'woModal_' . $i;

                                $stock = (float) ($g->stock_on_hand ?? 0);
                                $demand = (float) ($g->demand_pcs ?? 0);
                                $shortage = (float) ($g->shortage_pcs ?? 0);
                                $statusVal = (string) ($g->status ?? 'unknown');

                                $maxBar = max($stock, $demand, 1);
                                $stockPct = ($stock / $maxBar) * 100;
                                $demandPct = ($demand / $maxBar) * 100;

                                $siteClass = match ($rowSite) {
                                    'WIRE' => 'site-wire',
                                    'PLUS' => 'site-plus',
                                    default => '',
                                };

                                $rowClass = match ($statusVal) {
                                    'shortage' => 'row-shortage',
                                    'risk' => 'row-risk',
                                    default => '',
                                };

                                $coverage = $g->coverage_pct ?? null;
                                $coverageText = is_numeric($coverage)
                                    ? ((float) $coverage >= 999999
                                        ? '∞'
                                        : number_format((float) $coverage, 2) . '%')
                                    : '-';

                                $coverageClass = match ($statusVal) {
                                    'shortage' => 'coverage-shortage',
                                    'risk' => 'coverage-risk',
                                    'enough' => 'coverage-enough',
                                    'idle' => 'coverage-idle',
                                    default => 'coverage-idle',
                                };
                            @endphp

                            <tr class="{{ $rowClass }}">
                                <td class="text-center">
                                    <button type="button" class="btn btn-outline-secondary btn-sm btn-wo"
                                        data-bs-toggle="modal" data-bs-target="#{{ $modalId }}">
                                        ดู WO
                                    </button>
                                </td>

                                <td>{{ $g->code_packaging ?? '-' }}</td>

                                <td class="text-center">
                                    <span class="site-badge {{ $siteClass }}">
                                        {{ $rowSite ?: '-' }}
                                    </span>
                                </td>

                                <td>
                                    <div class="pack-name">{{ $g->pack_name ?? '-' }}</div>
                                    <div class="pack-sub">
                                        Product: {{ $g->product ?? '-' }}
                                        @if (!empty($g->length_mm))
                                            | Length: {{ number_format((float) $g->length_mm, 0) }} mm
                                        @endif
                                    </div>
                                </td>

                                <td class="text-end fw-semibold">{{ number_format((float) ($g->sum_qty ?? 0), 2) }}</td>

                                <td class="text-end cell-demand">
                                    {{ number_format((int) ($g->demand_pcs ?? 0)) }}
                                </td>

                                <td class="text-end">
                                    {{ is_numeric($g->stock_on_hand) ? number_format((float) $g->stock_on_hand, 0) : '-' }}
                                </td>

                                <td class="text-end cell-balance">
                                    {{ is_numeric($g->balance_pcs) ? number_format((float) $g->balance_pcs, 0) : '-' }}
                                </td>

                                <td class="text-end fw-bold {{ $shortage > 0 ? 'text-danger' : 'text-success' }}">
                                    {{ number_format($shortage, 0) }}
                                </td>

                                <td class="text-center">
                                    <span class="coverage-text {{ $coverageClass }}">
                                        {{ $coverageText }}
                                    </span>
                                </td>

                                <td class="text-center">
                                    @if ($statusVal === 'shortage')
                                        <span class="badge bg-danger">ขาด</span>
                                    @elseif($statusVal === 'risk')
                                        <span class="badge bg-warning text-dark">เสี่ยง</span>
                                    @elseif($statusVal === 'enough')
                                        <span class="badge bg-success">พอ</span>
                                    @elseif($statusVal === 'idle')
                                        <span class="badge bg-secondary">ไม่มีใช้</span>
                                    @else
                                        <span class="badge bg-light text-dark">-</span>
                                    @endif
                                </td>

                                <td>
                                    <div class="mini-bar-wrap">
                                        <div class="mini-bar-row">
                                            <span class="mini-label">DEM</span>
                                            <div class="mini-track">
                                                <div class="mini-fill mini-demand" style="width: {{ $demandPct }}%">
                                                </div>
                                            </div>
                                            <span class="mini-value">{{ number_format($demand, 0) }}</span>
                                        </div>
                                        <div class="mini-bar-row">
                                            <span class="mini-label">STK</span>
                                            <div class="mini-track">
                                                <div class="mini-fill mini-stock" style="width: {{ $stockPct }}%">
                                                </div>
                                            </div>
                                            <span class="mini-value">{{ number_format($stock, 0) }}</span>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    @if (collect($g->fpack_breakdowns ?? [])->isNotEmpty())
                                        @foreach ($g->fpack_breakdowns as $fp)
                                            <span class="chip-pack">
                                                {{ $fp->fpack ?: '-' }} ({{ (int) ($fp->demand_pcs ?? 0) }})
                                            </span>
                                        @endforeach

                                        <div class="mt-1">
                                            <button type="button" class="btn btn-link p-0 text-decoration-none"
                                                data-bs-toggle="modal" data-bs-target="#{{ $modalId }}">
                                                ดูในรายละเอียด
                                            </button>
                                        </div>
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="13" class="text-center text-muted py-4">ไม่พบข้อมูล</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Modals --}}
    @foreach ($groupsCol as $i => $g)
        @php
            $modalId = 'woModal_' . $i;
            $modalCoverage = $g->coverage_pct ?? null;
        @endphp

        <div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title mb-1">
                                {{ $g->code_packaging ?? '-' }} | {{ $g->pack_name ?? '-' }}
                            </h5>
                            <div class="small text-muted">
                                Site: {{ $g->source_site ?? '-' }}
                                | Product: {{ $g->product ?? '-' }}
                                | WO: {{ number_format((int) collect($g->items ?? [])->count()) }} รายการ
                                | Stock:
                                {{ is_numeric($g->stock_on_hand) ? number_format((float) $g->stock_on_hand, 0) : '-' }}
                                | Demand: {{ number_format((int) ($g->demand_pcs ?? 0)) }}
                                | Shortage: {{ number_format((float) ($g->shortage_pcs ?? 0), 0) }}
                                | Coverage:
                                @if (is_numeric($modalCoverage))
                                    {{ (float) $modalCoverage >= 999999 ? '∞' : number_format((float) $modalCoverage, 2) . '%' }}
                                @else
                                    -
                                @endif
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm wo-subtable align-middle">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>WO</th>
                                        <th>Date Open</th>
                                        <th>Req Date</th>
                                        <th>Product</th>
                                        <th>Std Pack</th>
                                        <th class="text-end">ความยาว</th>
                                        <th class="text-end">เปิดผลิต</th>
                                        <th class="text-end">ผลิตไปแล้ว</th>
                                        <th class="text-end">ค้างผลิต</th>
                                        <th class="text-end">KG/Pack</th>
                                        <th class="text-end">Demand</th>
                                        <th class="text-end">Stock</th>
                                        <th class="text-end">Shortage</th>
                                        <th class="text-center">Source</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (collect($g->items ?? [])->values() as $idx => $row)
                                        <tr>
                                            <td>{{ $idx + 1 }}</td>
                                            <td>{{ $row->workordernumber ?? '-' }}</td>
                                            <td>{{ $row->dateopen ?? '-' }}</td>
                                            <td>{{ $row->reqdate ?? '-' }}</td>
                                            <td>{{ $row->product ?? '-' }}</td>
                                            <td>{{ $row->fpack ?? '-' }}</td>
                                            <td class="text-end">
                                                {{ isset($row->flen) && is_numeric($row->flen) ? number_format((float) $row->flen, 0) : '-' }}
                                            </td>
                                            <td class="text-end">{{ number_format((float) ($row->open_qty ?? 0), 2) }}
                                            </td>
                                            <td class="text-end">{{ number_format((float) ($row->produced_qty ?? 0), 2) }}
                                            </td>
                                            <td class="text-end">{{ number_format((float) ($row->balance_qty ?? 0), 2) }}
                                            </td>
                                            <td class="text-end">
                                                {{ is_numeric($row->kg_per_pack) ? number_format((float) $row->kg_per_pack, 4) : '-' }}
                                            </td>
                                            <td class="text-end">{{ number_format((int) ($row->demand_pcs ?? 0)) }}</td>
                                            <td class="text-end">
                                                {{ is_numeric($row->stock_on_hand) ? number_format((float) $row->stock_on_hand, 0) : '-' }}
                                            </td>
                                            <td class="text-end">
                                                {{ is_numeric($row->shortage_pcs) ? number_format((float) $row->shortage_pcs, 0) : '-' }}
                                            </td>
                                            <td class="text-center">
                                                @php
                                                    $detailSite = strtoupper((string) ($row->source_site ?? ''));
                                                    $detailSiteClass = match ($detailSite) {
                                                        'WIRE' => 'site-wire',
                                                        'PLUS' => 'site-plus',
                                                        default => '',
                                                    };
                                                @endphp
                                                <span class="site-badge {{ $detailSiteClass }}">
                                                    {{ $detailSite ?: '-' }}
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                @if (collect($g->items ?? [])->isNotEmpty())
                                    <tfoot>
                                        <tr class="table-light fw-semibold">
                                            <td colspan="7" class="text-end">รวม</td>
                                            <td class="text-end">
                                                {{ number_format((float) collect($g->items)->sum('open_qty'), 2) }}
                                            </td>
                                            <td class="text-end">
                                                {{ number_format((float) collect($g->items)->sum('produced_qty'), 2) }}
                                            </td>
                                            <td class="text-end">
                                                {{ number_format((float) collect($g->items)->sum('balance_qty'), 2) }}
                                            </td>
                                            <td></td>
                                            <td class="text-end">
                                                {{ number_format((int) collect($g->items)->sum('demand_pcs')) }}
                                            </td>
                                            <td class="text-end">
                                                {{ number_format((float) collect($g->items)->sum('stock_on_hand'), 0) }}
                                            </td>
                                            <td class="text-end">
                                                {{ number_format((float) collect($g->items)->sum('shortage_pcs'), 0) }}
                                            </td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                @endif
                            </table>
                        </div>

                        @if (collect($g->fpack_breakdowns ?? [])->isNotEmpty())
                            <hr>
                            <h6 class="mb-2">สรุปตาม Standard Pack</h6>
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm align-middle">
                                    <thead>
                                        <tr>
                                            <th>Standard Pack</th>
                                            <th class="text-end">KG/Pack</th>
                                            <th class="text-end">ค้างผลิต (KG)</th>
                                            <th class="text-end">Demand</th>
                                            <th class="text-end">WO</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($g->fpack_breakdowns as $fp)
                                            <tr>
                                                <td>{{ $fp->fpack ?? '-' }}</td>
                                                <td class="text-end">
                                                    {{ is_numeric($fp->kg_per_pack) ? number_format((float) $fp->kg_per_pack, 4) : '-' }}
                                                </td>
                                                <td class="text-end">{{ number_format((float) ($fp->sum_qty ?? 0), 2) }}
                                                </td>
                                                <td class="text-end">{{ number_format((int) ($fp->demand_pcs ?? 0)) }}
                                                </td>
                                                <td class="text-end">{{ number_format((int) ($fp->count_wo ?? 0)) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                tooltipTriggerList.forEach(function(el) {
                    new bootstrap.Tooltip(el);
                });
            });
        </script>
    @endpush
@endsection
