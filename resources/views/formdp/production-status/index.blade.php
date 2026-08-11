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
        $siteCounts = $siteSummary->keyBy(fn($item) => strtoupper((string) ($item['site'] ?? '')));
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
        $filterQuery = fn($value) => array_merge(request()->except(['page']), [
            'completion_filter' => $value,
            'status_filter' => 'all',
        ]);
        $statusQuery = fn($value) => array_merge(request()->except(['page']), [
            'completion_filter' => 'all',
            'status_filter' => $value,
        ]);
        $siteQuery = function ($value) use ($filters) {
            $query = request()->except(['page']);
            if (($filters['site'] ?? '') === $value) {
                unset($query['site']);
                return $query;
            }

            return array_merge($query, ['site' => $value]);
        };
        $clearSiteQuery = request()->except(['page', 'site']);
        $movementQuery = fn($value) => array_merge(request()->except(['page']), ['movement_filter' => $value]);
        $rawProcessFilters = $filters['process_filter'] ?? [];
        $processFilterValues = collect(is_array($rawProcessFilters) ? $rawProcessFilters : [$rawProcessFilters])
            ->map(fn($value) => trim((string) $value))
            ->filter()
            ->values();
        $processQuery = fn($value) => array_merge(request()->except(['page']), ['process_filter' => [$value]]);
        $divisionQuery = fn($value) => array_merge(request()->except(['page']), ['keyword' => $value]);
        $clearProcessQuery = request()->except(['page', 'process_filter']);
        $confirmationQuery = fn($value) => array_merge(request()->except(['page']), ['confirmation_filter' => $value]);
        $confirmationFilterValue = strtolower((string) ($filters['confirmation_filter'] ?? 'all'));
        $deliveryTypeFilterValue = strtoupper((string) ($filters['delivery_type'] ?? 'ALL'));
        $deliveryTypeQuery = function ($value) use ($deliveryTypeFilterValue) {
            $query = request()->except(['page']);
            if ($deliveryTypeFilterValue === strtoupper($value)) {
                unset($query['delivery_type']);
                return $query;
            }

            return array_merge($query, ['delivery_type' => $value]);
        };
        $clearQuickActionQuery = request()->except([
            'page',
            'process_filter',
            'site',
            'delivery_type',
            'completion_filter',
            'status_filter',
            'confirmation_filter',
        ]);
        $hasActiveQuickActionFilters =
            !empty($filters['site']) ||
            !empty($filters['process_filter']) ||
            $deliveryTypeFilterValue !== 'ALL' ||
            ($filters['completion_filter'] ?? 'all') !== 'all' ||
            ($filters['status_filter'] ?? 'all') !== 'all' ||
            $confirmationFilterValue !== 'all';
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

        /* Filters panel must allow autocomplete dropdowns to overflow */
        .pst-panel-filters {
            overflow: visible;
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

        .pst-kpi-groups {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 12px;
        }

        .pst-kpi-group {
            background: #fff;
            border: 1px solid #dfe5ec;
            border-radius: 10px;
            padding: 10px 12px 12px;
            position: relative;
        }

        .pst-kpi-group-alert {
            background: #fff7f7;
            border-color: #f3c2c2;
        }

        .pst-kpi-group-label {
            color: #475569;
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .pst-kpi-group-alert .pst-kpi-group-label {
            color: #b91c1c;
        }

        @media (max-width: 991.98px) {
            .pst-kpi-groups {
                grid-template-columns: 1fr;
            }
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

        .pst-quality-link {
            text-decoration: none;
            color: inherit;
            display: block;
            border-radius: 6px;
            padding: 4px 6px;
            margin: -4px -6px;
            transition: background-color .12s ease;
        }

        .pst-quality-link:hover {
            background: #fef2f2;
            color: inherit;
        }

        .pst-collapsible {
            border: 1px solid #dfe5ec;
            border-radius: 8px;
            background: #fff;
        }

        .pst-collapsible>.pst-collapsible-summary {
            list-style: none;
            cursor: pointer;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            user-select: none;
        }

        .pst-collapsible>.pst-collapsible-summary::-webkit-details-marker {
            display: none;
        }

        .pst-collapsible[open]>.pst-collapsible-summary {
            border-bottom: 1px solid #e8edf2;
        }

        .pst-collapsible-icon {
            color: #94a3b8;
            font-size: .8rem;
            transition: transform .15s ease;
        }

        .pst-collapsible[open] .pst-collapsible-icon {
            transform: rotate(180deg);
        }

        /* Autocomplete (same look & feel as DP inquiry) */
        .dp-ac-wrap {
            position: relative;
        }

        .dp-ac-wrap .dp-ac-input {
            padding-right: 28px;
        }

        .dp-ac-clear {
            position: absolute;
            right: 6px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            line-height: 18px;
            text-align: center;
            border: 0;
            border-radius: 50%;
            background: #e2e8f0;
            color: #475569;
            font-size: 16px;
            cursor: pointer;
            display: none;
            padding: 0;
        }

        .dp-ac-clear:hover {
            background: #cbd5e1;
            color: #0f172a;
        }

        .dp-ac-wrap.has-value .dp-ac-clear {
            display: inline-block;
        }

        .dp-suggest {
            position: absolute;
            left: 12px;
            right: 12px;
            top: calc(100% + 2px);
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.10);
            padding: 6px;
            max-height: 280px;
            overflow: auto;
            z-index: 1060;
            min-width: 220px;
        }

        .dp-suggest-item {
            width: 100%;
            text-align: left;
            border: 0;
            background: transparent;
            padding: 8px 10px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .dp-suggest-item:hover,
        .dp-suggest-item.is-active {
            background: #eef4ff;
        }

        .dp-suggest-title {
            font-weight: 600;
            font-size: 13px;
            color: #0f172a;
        }

        .dp-suggest-title mark {
            background: #fef08a;
            color: inherit;
            padding: 0;
        }

        .dp-suggest-empty {
            padding: 10px;
            text-align: center;
            color: #94a3b8;
            font-size: 12px;
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
            max-height: calc(100vh - 160px);
            overflow: auto;
        }

        .pst-date-preset.is-active {
            background: #0d6efd;
            color: #fff;
            border-color: #0d6efd;
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

        .pst-resizable-table th {
            position: sticky;
            user-select: none;
        }

        .pst-resizer {
            position: absolute;
            top: 0;
            right: -3px;
            width: 8px;
            height: 100%;
            cursor: col-resize;
            z-index: 5;
        }

        .pst-resizer::after {
            content: "";
            position: absolute;
            top: 22%;
            bottom: 22%;
            left: 3px;
            width: 1px;
            background: rgba(100, 116, 139, .45);
        }

        .pst-table-resizing {
            cursor: col-resize;
            user-select: none;
        }

        .pst-hide-col-btn {
            border: 0;
            background: transparent;
            color: #64748b;
            cursor: pointer;
            font-size: 11px;
            margin-left: 5px;
            padding: 0 2px;
            position: relative;
            vertical-align: middle;
            z-index: 6;
        }

        .pst-hide-col-btn:hover {
            color: #dc3545;
        }

        .pst-column-menu {
            max-height: 360px;
            min-width: 260px;
            overflow-y: auto;
        }

        .pst-column-menu .dropdown-item {
            align-items: center;
            display: flex;
            gap: 8px;
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

        .pst-no-col {
            width: 48px;
            min-width: 48px;
        }

        .pst-no-cell {
            font-variant-numeric: tabular-nums;
            color: #6b7280;
        }

        .pst-group-tag {
            display: inline-block;
            color: #6366f1;
            font-size: 11px;
            margin-right: 4px;
            vertical-align: middle;
        }

        tr.pst-group-cont>td {
            background-color: #f8faff;
            border-top: 1px dashed #c7d2fe !important;
        }

        tr.pst-group-cont>td.pst-pin-left {
            box-shadow: 6px 0 8px -8px rgba(15, 23, 42, .25);
            border-left: 3px solid #6366f1;
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
                <div class="fw-semibold">ไม่สามารถดึงข้อมูลปัจจุบันได้</div>
                <div class="small">{{ $dataError }}</div>
            </div>
        @endif

        <div class="pst-kpi-groups mb-3">
            <div class="pst-kpi-group">
                <div class="pst-kpi-group-label">สถานะรวม</div>
                <div class="row g-2">
                    <div class="col-4">
                        <div class="pst-kpi" title="จำนวน MFG ทั้งหมดในตัวกรองปัจจุบัน (รวมทั้งยังไม่เสร็จและเสร็จแล้ว) — Avg progress = ค่าเฉลี่ย % ความคืบหน้าของทุก MFG">
                            <div class="label">MFG ในแผน</div>
                            <div class="value">{{ number_format($summary['total'] ?? 0) }}</div>
                            <div class="hint">Avg progress
                                {{ number_format($summary['avg_progress'] ?? 0, 1) }}%</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="pst-kpi" title="งานที่ยังไม่ Completed — รวมงานนิ่ง + ยังไม่เริ่ม + เคลื่อนไหวอยู่">
                            <div class="label">ยังไม่เสร็จ</div>
                            <div class="value text-warning">{{ number_format($summary['open'] ?? 0) }}</div>
                            <div class="hint">งานที่ยังเปิดอยู่</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="pst-kpi" title="งานที่กำหนดส่งภายใน 1-3 วัน นับจากวันนี้">
                            <div class="label">ครบกำหนดใน 1-3 วัน</div>
                            <div class="value text-primary">{{ number_format($summary['due_soon'] ?? 0) }}</div>
                            <div class="hint">ใกล้ครบกำหนด</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="pst-kpi-group pst-kpi-group-alert">
                <div class="pst-kpi-group-label">เตือน</div>
                <div class="row g-2">
                    <div class="col-4">
                        <div class="pst-kpi" title="งานที่เลย Due Date ไปแล้วและยังไม่ Completed">
                            <div class="label">เลยกำหนด</div>
                            <div class="value text-danger">{{ number_format($summary['overdue'] ?? 0) }}</div>
                            <div class="hint">ยังไม่เสร็จ</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="pst-kpi" title="งานที่สถานะผลิตเป็น Delayed (ความคืบหน้าช้ากว่าแผนเทียบกับ Due Date)">
                            <div class="label">ล่าช้า</div>
                            <div class="value text-danger">{{ number_format($summary['delayed'] ?? 0) }}</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="pst-kpi" title="งานที่ระบบประเมินว่ามีโอกาสส่งไม่ทัน (รวมงานที่ยังไม่ Completed ที่ใกล้ Due และยังไม่ถึง 100%)">
                            <div class="label">เสี่ยงไม่ทัน</div>
                            <div class="value text-danger">{{ number_format($summary['at_risk'] ?? 0) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @php
            $noRouteCount = (int) ($dataQualitySummary->firstWhere('label', 'ไม่พบ Routing')['count'] ?? 0);
            $unknownCount = (int) ($dataQualitySummary->firstWhere('label', 'UNKNOWN')['count'] ?? 0);
            $dataQualityHasIssue = $noRouteCount > 0 || $unknownCount > 0;
        @endphp
        <details class="pst-panel mb-3 pst-collapsible" data-storage-key="pst-data-quality-open" {{ $dataQualityHasIssue ? 'open' : '' }}>
            <summary class="pst-collapsible-summary">
                <span class="fw-semibold">คุณภาพข้อมูล</span>
                <span class="pst-muted ms-auto small">
                    @if ($noRouteCount === 0 && $unknownCount === 0)
                        <span class="badge bg-success-subtle text-success border border-success-subtle">ข้อมูลครบ</span>
                    @else
                        @if ($noRouteCount > 0)
                            <span class="badge bg-danger me-1">ไม่พบ Routing {{ number_format($noRouteCount) }}</span>
                        @endif
                        @if ($unknownCount > 0)
                            <span class="badge bg-warning text-dark">UNKNOWN {{ number_format($unknownCount) }}</span>
                        @endif
                    @endif
                </span>
                <i class="fas fa-chevron-down pst-collapsible-icon ms-2"></i>
            </summary>
            <div class="pst-quality-strip">
                @foreach ($dataQualitySummary->whereIn('label', ['ไม่พบ Routing', 'UNKNOWN']) as $quality)
                    @php
                        $qCount = (int) ($quality['count'] ?? 0);
                        $qClass = $qCount === 0 ? 'success' : ($quality['class'] ?? 'secondary');
                        $isNoRoute = ($quality['label'] ?? '') === 'ไม่พบ Routing';
                        $qLinkable = $isNoRoute && $qCount > 0;
                    @endphp
                    @if ($qLinkable)
                        <a class="pst-quality-stat pst-quality-link"
                            href="{{ route('dp.production-status', $movementQuery('no_route')) }}"
                            title="ดูเฉพาะรายการที่ไม่พบ Routing">
                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <span class="label">{{ $quality['label'] ?? '-' }}</span>
                                <span
                                    class="badge bg-{{ $qClass }}">{{ number_format((float) ($quality['pct'] ?? 0), 1) }}%</span>
                            </div>
                            <div class="value mt-1">{{ number_format($qCount) }}</div>
                        </a>
                    @else
                        <div class="pst-quality-stat">
                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <span class="label">{{ $quality['label'] ?? '-' }}</span>
                                <span
                                    class="badge bg-{{ $qClass }}">{{ number_format((float) ($quality['pct'] ?? 0), 1) }}%</span>
                            </div>
                            <div class="value mt-1">{{ number_format($qCount) }}</div>
                        </div>
                    @endif
                @endforeach
                <div class="pst-quality-stat">
                    <div class="label mb-2">สัดส่วนโรงงาน</div>
                    <div class="pst-quality-stack">
                        @php
                            $siteChips = $dataQualitySummary
                                ->whereIn('label', ['WIRE', 'PLUS'])
                                ->filter(fn($q) => (int) ($q['count'] ?? 0) > 0);
                        @endphp
                        @forelse ($siteChips as $quality)
                            <span class="badge bg-light text-dark border">
                                {{ $quality['label'] ?? '-' }} {{ number_format($quality['count'] ?? 0) }}
                                <span class="text-muted">{{ number_format((float) ($quality['pct'] ?? 0), 1) }}%</span>
                            </span>
                        @empty
                            <span class="pst-muted small">-</span>
                        @endforelse
                    </div>
                </div>
            </div>
        </details>

        <div class="pst-panel pst-panel-filters mb-3">
            <div class="pst-head">
                <span>ตัวกรอง</span>
                <span class="pst-muted">Delivery Plan + ความคืบหน้า Barcode/ManuCost</span>
            </div>
            <form method="GET" action="{{ route('dp.production-status') }}" class="p-3">
                <input type="hidden" name="completion_filter" value="{{ $filters['completion_filter'] ?? 'all' }}">
                <input type="hidden" name="status_filter" value="{{ $filters['status_filter'] ?? 'all' }}">
                @foreach ($processFilterValues as $processFilterValue)
                    <input type="hidden" name="process_filter[]" value="{{ $processFilterValue }}">
                @endforeach
                <input type="hidden" name="movement_filter" value="{{ $filters['movement_filter'] ?? 'all' }}">
                <input type="hidden" name="confirmation_filter" value="{{ $confirmationFilterValue }}">
                <input type="hidden" name="delivery_type" value="{{ $deliveryTypeFilterValue }}">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-3 col-md-6 position-relative">
                        <label class="form-label">MFG</label>
                        <div class="dp-ac-wrap">
                            <input id="pst_mfg" name="mfg" value="{{ $filters['mfg'] ?? '' }}" autocomplete="off"
                                class="form-control dp-ac-input" data-ac-source="mfg" placeholder="ค้นหา MFG">
                            <button type="button" class="dp-ac-clear" data-ac-clear="pst_mfg" title="ล้าง"
                                aria-label="ล้าง">&times;</button>
                        </div>
                        <div class="dp-suggest d-none" data-ac-for="pst_mfg"></div>
                    </div>
                    <div class="col-lg-3 col-md-6 position-relative">
                        <label class="form-label">SO</label>
                        <div class="dp-ac-wrap">
                            <input id="pst_so" name="so" value="{{ $filters['so'] ?? '' }}" autocomplete="off"
                                class="form-control dp-ac-input" data-ac-source="so" placeholder="ค้นหา SO">
                            <button type="button" class="dp-ac-clear" data-ac-clear="pst_so" title="ล้าง"
                                aria-label="ล้าง">&times;</button>
                        </div>
                        <div class="dp-suggest d-none" data-ac-for="pst_so"></div>
                    </div>
                    <div class="col-lg-3 col-md-6 position-relative">
                        <label class="form-label">Customer</label>
                        <div class="dp-ac-wrap">
                            <input id="pst_customer" name="customer" value="{{ $filters['customer'] ?? '' }}"
                                autocomplete="off" class="form-control dp-ac-input" data-ac-source="customer"
                                placeholder="ค้นหาลูกค้า">
                            <button type="button" class="dp-ac-clear" data-ac-clear="pst_customer" title="ล้าง"
                                aria-label="ล้าง">&times;</button>
                        </div>
                        <div class="dp-suggest d-none" data-ac-for="pst_customer"></div>
                    </div>
                    <div class="col-lg-3 col-md-6 position-relative">
                        <label class="form-label">Item / Part</label>
                        <div class="dp-ac-wrap">
                            <input id="pst_item" name="item" value="{{ $filters['item'] ?? '' }}" autocomplete="off"
                                class="form-control dp-ac-input" data-ac-source="item"
                                placeholder="Part No หรือ Description">
                            <button type="button" class="dp-ac-clear" data-ac-clear="pst_item" title="ล้าง"
                                aria-label="ล้าง">&times;</button>
                        </div>
                        <div class="dp-suggest d-none" data-ac-for="pst_item"></div>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label d-flex align-items-center justify-content-between">
                            <span>Ship from</span>
                        </label>
                        <input type="date" name="ship_from" id="pst_ship_from" class="form-control"
                            value="{{ $filters['ship_from'] ?? '' }}">
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">Ship to</label>
                        <input type="date" name="ship_to" id="pst_ship_to" class="form-control" value="{{ $filters['ship_to'] ?? '' }}">
                    </div>
                    <div class="col-12">
                        <div class="d-flex flex-wrap gap-1 align-items-center">
                            <span class="pst-muted small me-1">ช่วงเร็ว:</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary pst-date-preset" data-range="today">วันนี้</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary pst-date-preset" data-range="7">7 วัน</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary pst-date-preset" data-range="14">14 วัน</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary pst-date-preset" data-range="30">30 วัน</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary pst-date-preset" data-range="month">เดือนนี้</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary pst-date-preset" data-range="clear">ล้างวันที่</button>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">โรงงาน</label>
                        <select name="site" class="form-select">
                            @foreach ($siteOptions ?? [] as $option)
                                <option value="{{ $option['value'] }}"
                                    {{ ($filters['site'] ?? '') === $option['value'] ? 'selected' : '' }}>
                                    {{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">ความเสี่ยง</label>
                        <select name="risk_status" class="form-select">
                            @foreach ($riskOptions ?? [] as $option)
                                <option value="{{ $option['value'] }}"
                                    {{ ($filters['risk_status'] ?? '') === $option['value'] ? 'selected' : '' }}>
                                    {{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">สถานะ DP</label>
                        <select name="delivery_status" class="form-select">
                            @foreach (['NEW' => 'NEW', 'ASSIGN' => 'ASSIGN', 'CLOSED' => 'CLOSED', 'VOID' => 'VOID', 'ALL' => 'ALL'] as $value => $label)
                                <option value="{{ $value }}"
                                    {{ ($filters['delivery_status'] ?? 'NEW') === $value ? 'selected' : '' }}>
                                    {{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">แถวต่อหน้า</label>
                        <select name="per_page" class="form-select">
                            @foreach ([50, 100, 200] as $n)
                                <option value="{{ $n }}" {{ (int) ($perPage ?? 50) === $n ? 'selected' : '' }}>
                                    {{ $n }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 d-flex gap-2 justify-content-end">
                        <a class="btn btn-outline-secondary" href="{{ route('dp.production-status') }}"
                            title="ล้างตัวกรอง"><i class="fas fa-rotate-left me-1"></i> ล้าง</a>
                        <a class="btn btn-outline-success"
                            href="{{ route('dp.production-status.export', request()->query()) }}">
                            <i class="fas fa-file-export me-1"></i> ส่งออก
                        </a>
                        <button class="btn btn-primary" type="submit">
                            <i class="fas fa-filter me-1"></i> ใช้ตัวกรอง
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <details class="pst-panel mb-3 pst-collapsible" data-storage-key="pst-movement-open" open>
            <summary class="pst-collapsible-summary">
                <span class="fw-semibold">ตัวกรองการเคลื่อนไหว</span>
                @php
                    $movementActive = $filters['movement_filter'] ?? 'all';
                    $movementActiveLabel =
                        $movementActive === 'all'
                            ? 'ทั้งหมด'
                            : ($movementSummary->firstWhere('value', $movementActive)['label'] ?? $movementActive);
                @endphp
                <span class="pst-muted ms-auto small">
                    @if ($movementActive !== 'all')
                        <span class="badge bg-primary">{{ $movementActiveLabel }}</span>
                    @else
                        กดเพื่อดูงานตามสถานะเคลื่อนไหว
                    @endif
                </span>
                <i class="fas fa-chevron-down pst-collapsible-icon ms-2"></i>
            </summary>
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
        </details>

        @php
            $pstLabelTh = [
                // statusSummary
                'Delayed' => 'ล่าช้า',
                'On Track' => 'ทันกำหนด',
                'Completed' => 'เสร็จแล้ว',
                'At Risk' => 'เสี่ยงไม่ทัน',
                // dueBuckets
                'Overdue' => 'เลยกำหนด',
                'Today' => 'วันนี้',
                '1-3 Days' => 'อีก 1-3 วัน',
                '4-7 Days' => 'อีก 4-7 วัน',
                '> 7 Days' => 'เกิน 7 วัน',
                // actionSummary
                'Expedite' => 'เร่งด่วน',
                'Keep Watching' => 'เฝ้าระวัง',
                'Ready / Done' => 'พร้อม / เสร็จ',
            ];
            $pstTh = fn($k) => $pstLabelTh[$k] ?? $k;
        @endphp
        <div class="pst-board">
            <details class="pst-lane pst-collapsible" data-storage-key="pst-production-overview-open" open>
                <summary class="pst-lane-head pst-collapsible-summary">
                    <span>ภาพรวมการผลิต</span>
                    <i class="fas fa-chevron-down pst-collapsible-icon ms-2"></i>
                </summary>
                <div class="pst-lane-body">
                    {{-- status tiles (ล่าช้า / ทันกำหนด / เสร็จแล้ว) ซ้ำกับ HEALTH+Movement Filter จึงตัดออก --}}
                    <div class="pst-band-grid" style="margin-top:0;">
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
                                <span class="text-muted">ค้าง {{ number_format($site['open'] ?? 0) }}</span>
                            </span>
                        @endforeach
                    </div>

                    <div class="pst-control-extra">
                        <div class="pst-mini-panel">
                            <div class="fw-semibold mb-2">ความเร่งด่วน</div>
                            @foreach ($dueBuckets as $bucket)
                                <div class="pst-mini-row">
                                    <span class="pst-muted">{{ $pstTh($bucket['label']) }}</span>
                                    <span class="fw-semibold num">{{ number_format($bucket['count'] ?? 0) }}</span>
                                    <div class="pst-bar">
                                        <span class="bg-{{ $bucket['class'] ?? 'primary' }}"
                                            style="width: {{ max(0, min(100, (float) ($bucket['pct'] ?? 0))) }}%"></span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="pst-mini-panel">
                            <div class="fw-semibold mb-2">ต้องดำเนินการ</div>
                            @foreach ($actionSummary as $action)
                                <div class="pst-mini-row">
                                    <span class="pst-muted">{{ $pstTh($action['label']) }}</span>
                                    <span class="fw-semibold num">{{ number_format($action['count'] ?? 0) }}</span>
                                    <div class="pst-bar">
                                        <span class="bg-{{ $action['class'] ?? 'primary' }}"
                                            style="width: {{ max(0, min(100, (float) ($action['pct'] ?? 0))) }}%"></span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="pst-mini-panel">
                            <div class="fw-semibold mb-2">หน่วยขาย</div>
                            @foreach ($unitSummary as $unit)
                                @php
                                    $uName = $unit['unit'] ?? '';
                                    if ($uName === 'kg') {
                                        $primaryQty = (float) ($unit['qty_kg'] ?? 0);
                                        $primaryUnit = 'kg';
                                    } elseif (in_array($uName, ['เส้น', 'ชิ้น'], true)) {
                                        $primaryQty = (float) ($unit['line_qty'] ?? 0);
                                        $primaryUnit = $uName;
                                    } else {
                                        $primaryQty = (float) ($unit['qty_kg'] ?? 0);
                                        $primaryUnit = 'kg';
                                    }
                                @endphp
                                <div class="pst-mini-row">
                                    <span class="pst-muted">{{ $uName }}</span>
                                    <span class="fw-semibold num" title="ยอดรวม">
                                        {{ number_format($primaryQty, 0) }}
                                        <span class="pst-muted small">{{ $primaryUnit }}</span>
                                    </span>
                                    <div>
                                        <div class="pst-bar">
                                            <span
                                                style="width: {{ max(0, min(100, (float) ($unit['pct'] ?? 0))) }}%"></span>
                                        </div>
                                        <div class="pst-muted mt-1">
                                            {{ number_format($unit['count'] ?? 0) }} MFG
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </details>

            @php
                $watchOpenItems = $watchlist->filter(function ($it) {
                    $st = (string) ($it->delivery_status ?? '');
                    return !in_array($st, ['Completed', 'Ready'], true);
                });
                $watchOpenCount = $watchOpenItems->count();
                $statusFilterActive = strtolower((string) ($filters['status_filter'] ?? 'all'));
                $actionQueueLabel = match ($statusFilterActive) {
                    'delayed' => 'ล่าช้า',
                    'at_risk' => 'เสี่ยงไม่ทัน',
                    default => 'ต้องดำเนินการ',
                };
            @endphp
            <details class="pst-lane pst-collapsible" data-storage-key="pst-action-queue-open" open>
                <summary class="pst-lane-head pst-collapsible-summary">
                    <span>คิวที่ต้องดำเนินการ
                        @if ($watchOpenCount > 0)
                            <span class="badge bg-light text-muted border ms-1">{{ number_format($watchOpenCount) }}</span>
                        @endif
                    </span>
                    <span class="d-flex align-items-center gap-2">
                        <a class="btn btn-sm {{ $statusFilterActive === 'delayed' ? 'btn-danger' : 'btn-outline-danger' }}"
                            href="{{ route('dp.production-status', $statusQuery('delayed')) }}"
                            title="กรองเฉพาะรายการล่าช้า" onclick="event.stopPropagation()">{{ $actionQueueLabel }}</a>
                        <i class="fas fa-chevron-down pst-collapsible-icon"></i>
                    </span>
                </summary>
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
                            <div class="text-muted text-center py-3">ไม่มีรายการที่ต้องดำเนินการ</div>
                        @endforelse
                    </div>
                </div>
            </details>
        </div>

        @if ($divisionSummary->count() > 1)
        <details class="pst-lane mb-3 pst-collapsible" data-storage-key="pst-division-summary-open" open>
            <summary class="pst-lane-head pst-collapsible-summary">
                <span>สรุปตาม Division</span>
                <span class="d-flex align-items-center gap-2">
                    <span class="pst-muted">สรุปปัญหาแยกตาม Division</span>
                    <i class="fas fa-chevron-down pst-collapsible-icon"></i>
                </span>
            </summary>
            <div class="pst-lane-body">
                <div class="pst-division-wrap">
                    <div class="pst-division-row header">
                        <div>Division</div>
                        <div class="num">ทั้งหมด</div>
                        <div class="num">ยังเปิด</div>
                        <div class="num">ล่าช้า</div>
                        <div class="num">ไม่มี Routing</div>
                        <div class="num">งานนิ่ง</div>
                        <div>ความคืบหน้าเฉลี่ย</div>
                    </div>
                    @php
                        $divisionLabelMap = [
                            'D1' => 'D1 - ดิลก + ขวัญเรือน',
                            'D2' => 'D2 - ปรียาพรรณ + นิตยา',
                            'D3' => 'D3 - ภควดี + ธนัชชา',
                            'D5' => 'D5 - ธัธลิญา + เฌอร์ลิญา',
                            'D6' => 'D6 - สุรศักดิ์ + คณัญญ์นิชา',
                            'D7' => 'D7 - ศิรินภา + มนพัทธ์',
                            'D8' => 'D8 - สาธิต + สุธาสินี',
                            'D9' => 'D9 - วรเดชา + ลัดดาวัลย์',
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
                                            เสี่ยง</span>
                                    @endif
                                </div>
                                <div class="pst-bar mt-1">
                                    <span
                                        style="width: {{ max(0, min(100, (float) ($division['avg_progress'] ?? 0))) }}%"></span>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="text-muted text-center py-3">ไม่มีข้อมูล Division</div>
                    @endforelse
                </div>
            </div>
        </details>
        @endif

        <details class="pst-lane mb-3 pst-collapsible" data-storage-key="pst-process-summary-open" open>
            <summary class="pst-lane-head pst-collapsible-summary">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span>งานในแต่ละขั้นตอน</span>
                    @if ($processFilterValues->isNotEmpty())
                        <span class="badge bg-primary">{{ $processFilterValues->implode(', ') }}</span>
                        <a class="btn btn-sm btn-outline-secondary"
                            href="{{ route('dp.production-status', $clearProcessQuery) }}"
                            onclick="event.stopPropagation()">ล้าง</a>
                    @endif
                </div>
                <span class="d-flex align-items-center gap-2">
                    <span class="pst-muted">กดเพื่อกรองเฉพาะขั้นตอน</span>
                    <i class="fas fa-chevron-down pst-collapsible-icon"></i>
                </span>
            </summary>
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
                    <div class="text-muted text-center py-3">ไม่มีข้อมูลขั้นตอน</div>
                @endforelse
            </div>
        </details>

        <div class="pst-panel">
            <div class="pst-head">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span>Dashboard Summary</span>
                    <form method="GET" action="{{ route('dp.production-status') }}"
                        class="d-inline-flex align-items-center gap-1 pst-process-filter-form"
                        title="Quick action: กรองตามสถานีงาน (เลือกได้หลายสถานี)">
                        @foreach (request()->except(['page', 'process_filter']) as $name => $value)
                            @if (is_scalar($value))
                                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                            @endif
                        @endforeach
                        <label for="pstQuickProcessFilter" class="small text-nowrap mb-0">
                            <i class="fas fa-filter me-1"></i>สถานีงาน
                        </label>
                        <select id="pstQuickProcessFilter" name="process_filter[]" multiple
                            class="form-select form-select-sm" style="width:260px"
                            data-placeholder="ทุกสถานีงาน">
                            @foreach ($processOptions ?? [] as $processOption)
                                <option value="{{ $processOption['value'] }}"
                                    @selected($processFilterValues->contains($processOption['value']))>
                                    {{ $processOption['label'] }} ({{ number_format($processOption['count']) }})
                                </option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">
                            กรอง
                        </button>
                    </form>
                    @if ($processFilterValues->isNotEmpty())
                        <span class="badge bg-primary">ขั้นตอน: {{ $processFilterValues->implode(', ') }}</span>
                        <a class="btn btn-sm btn-outline-secondary"
                            href="{{ route('dp.production-status', $clearProcessQuery) }}">ล้างขั้นตอน</a>
                    @endif
                    <a class="btn btn-sm {{ ($filters['site'] ?? '') === 'WIRE' ? 'btn-info' : 'btn-outline-info' }}"
                        href="{{ route('dp.production-status', $siteQuery('WIRE')) }}">
                        WIRE
                        <span class="ms-1">{{ number_format($siteCounts->get('WIRE')['count'] ?? 0) }}</span>
                    </a>
                    <a class="btn btn-sm {{ ($filters['site'] ?? '') === 'PLUS' ? 'btn-warning' : 'btn-outline-warning' }}"
                        href="{{ route('dp.production-status', $siteQuery('PLUS')) }}">
                        PLUS
                        <span class="ms-1">{{ number_format($siteCounts->get('PLUS')['count'] ?? 0) }}</span>
                    </a>
                    <a class="btn btn-sm {{ $deliveryTypeFilterValue === 'ACID' ? 'btn-warning' : 'btn-outline-warning' }}"
                        href="{{ route('dp.production-status', $deliveryTypeQuery('ACID')) }}"
                        title="กรองเฉพาะ delivery_type = ACID">
                        <i class="fas fa-flask me-1"></i> งานกัดกรด
                        <span class="ms-1">{{ number_format($summary['acid'] ?? 0) }}</span>
                    </a>
                    <a class="btn btn-sm {{ $deliveryTypeFilterValue === 'SPECIAL' ? 'btn-info' : 'btn-outline-info' }}"
                        href="{{ route('dp.production-status', $deliveryTypeQuery('SPECIAL')) }}"
                        title="กรองเฉพาะ delivery_type = SPECIAL">
                        <i class="fas fa-screwdriver-wrench me-1"></i> งานพิเศษ
                        <span class="ms-1">{{ number_format($summary['special'] ?? 0) }}</span>
                    </a>
                    @if (!empty($filters['site']))
                        <a class="btn btn-sm btn-outline-secondary"
                            href="{{ route('dp.production-status', $clearSiteQuery) }}">
                            <i class="fas fa-xmark me-1"></i> ล้างโรงงาน
                        </a>
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
                    <a class="btn btn-sm {{ $confirmationFilterValue === 'pending' ? 'btn-secondary' : 'btn-outline-secondary' }}"
                        href="{{ route('dp.production-status', $confirmationQuery($confirmationFilterValue === 'pending' ? 'all' : 'pending')) }}"
                        title="ดูรายการที่ยังไม่ได้ยืนยันหรือขอเลื่อนการส่ง">
                        <i class="fas fa-circle-question me-1"></i> ยังไม่ Confirm/เลื่อน
                        <span class="ms-1">{{ number_format($summary['pending_confirmation'] ?? 0) }}</span>
                    </a>
                    <a class="btn btn-sm {{ $confirmationFilterValue === 'postpone' ? 'btn-warning' : 'btn-outline-warning' }}"
                        href="{{ route('dp.production-status', $confirmationQuery($confirmationFilterValue === 'postpone' ? 'all' : 'postpone')) }}"
                        title="ดูรายการที่ขอเลื่อนส่ง">
                        <i class="fas fa-calendar-xmark me-1"></i> ขอเลื่อนส่ง
                        <span class="ms-1">{{ number_format($summary['postpone'] ?? 0) }}</span>
                    </a>
                    @if ($hasActiveQuickActionFilters)
                        <a class="btn btn-sm btn-outline-secondary"
                            href="{{ route('dp.production-status', $clearQuickActionQuery) }}"
                            title="ล้างเฉพาะตัวกรอง Quick Action โดยคงตัวกรองหลักไว้">
                            <i class="fas fa-xmark me-1"></i> ยกเลิก Filter
                        </a>
                    @endif
                </div>
                <span class="pst-muted">{{ number_format($allRowsCount ?? 0) }} รายการ</span>
            </div>
            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-light">
                <div class="small text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    รายการที่ยังไม่ได้บันทึก จะถือว่า <b>"รอวางแผนยืนยัน"</b> — กดปุ่มขวาเพื่อยืนยัน Confirm
                    ทุกรายการที่ยังว่างในหน้านี้
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                    <div class="dropdown">
                        <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle"
                            id="pstColumnMenuBtn" data-bs-toggle="dropdown" data-bs-auto-close="outside"
                            aria-expanded="false">
                            <i class="fas fa-table-columns me-1"></i> คอลัมน์
                            <span class="badge bg-secondary ms-1" id="pstHiddenColumnCount">0</span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end p-2 pst-column-menu"
                            aria-labelledby="pstColumnMenuBtn">
                            <div class="d-flex gap-2 mb-2">
                                <button type="button" class="btn btn-sm btn-outline-primary flex-fill"
                                    id="pstShowAllColumnsBtn">
                                    <i class="fas fa-eye me-1"></i> แสดงทั้งหมด
                                </button>
                            </div>
                            <div id="pstColumnToggleList"></div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-success" id="pstBulkConfirmBtn">
                        <i class="fas fa-check-double me-1"></i>
                        ยืนยันการส่ง (ทุกรายการที่ยังไม่ยืนยัน)
                        <span class="badge bg-light text-success ms-1" id="pstBulkPendingCount">0</span>
                    </button>
                </div>
            </div>
            <div class="pst-table-wrap">
                <table class="table table-sm table-hover align-middle mb-0 pst-table pst-resizable-table"
                    id="pstMainTable">
                    <colgroup>
                        <col class="pst-no-col">
                        <col class="pst-mfg">
                        <col class="pst-customer">
                        <col class="pst-item">
                        <col class="pst-qty">
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
                            <th class="text-center pst-no-col">#</th>
                            <th class="pst-pin-left">Mfg No.</th>
                            <th class="pst-customer">ลูกค้า</th>
                            <th class="pst-item">สินค้า</th>
                            <th class="num">จำนวนส่ง</th>
                            <th class="num" title="ยอด Stock FG รวมจาก WIRE และ PLUS ซึ่งใช้ร่วมกัน">
                                Stock FG
                                <div class="pst-muted small text-nowrap">WIRE + PLUS</div>
                            </th>
                            <th>Sale By</th>
                            <th>วันส่งตามแผน</th>
                            <th>Due Date</th>
                            <th>ขั้นตอนปัจจุบัน</th>
                            <th>
                                <span class="d-inline-flex align-items-center gap-1">
                                    ความคืบหน้า
                                    @include('formdp.production-status.partials.weight-tolerance-help')
                                </span>
                            </th>
                            <th>เคลื่อนไหวล่าสุด</th>
                            <th>ขั้นตอนที่เหลือ</th>
                            <th>สถานะ DP</th>
                            <th>สถานะผลิต</th>
                            <th>ความเสี่ยง</th>
                            <th style="min-width:280px;">ยืนยันการส่ง</th>
                            <th class="pst-actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $seenGroup = [];
                            $prevGroupKey = null;
                            $rowNoStart =
                                method_exists($rows, 'firstItem') && $rows->firstItem() ? $rows->firstItem() : 1;
                            $rowIdx = 0;
                        @endphp
                        @forelse ($rows as $row)
                            @php
                                $row = is_array($row) ? (object) $row : $row;
                                if (!(($row->remaining_process ?? null) instanceof \Illuminate\Support\Collection)) {
                                    $row->remaining_process = collect($row->remaining_process ?? []);
                                }
                                $groupKey = trim((string) ($row->group_key ?? ''));
                                $isGroupCont = $groupKey !== '' && $groupKey === $prevGroupKey;
                                $isGroupFirst = $groupKey !== '' && !isset($seenGroup[$groupKey]);
                                if ($groupKey !== '') {
                                    $seenGroup[$groupKey] = ($seenGroup[$groupKey] ?? 0) + 1;
                                }
                                // qty + DP info shown once per group only
                                $showGroupOnce = $groupKey === '' || $isGroupFirst;
                                $prevGroupKey = $groupKey;
                                $displayNo = $rowNoStart + $rowIdx;
                                $rowIdx++;
                            @endphp
                            <tr class="{{ $isGroupCont ? 'pst-group-cont' : '' }}">
                                <td class="text-center pst-no-cell">
                                    <span class="fw-semibold text-muted">{{ $displayNo }}</span>
                                    @if ($isGroupCont)
                                        <div class="pst-muted" style="font-size:10px;line-height:1;">↳</div>
                                    @endif
                                </td>
                                <td class="pst-pin-left">
                                    <div class="fw-semibold">
                                        @if ($isGroupCont)
                                            <span class="pst-group-tag"
                                                title="กลุ่มเดียวกับแถวบน (มาจาก Delivery Plan record เดียวกัน)">
                                                <i class="fas fa-link"></i>
                                            </span>
                                        @endif
                                        {{ $row->mfg_no ?: '-' }}
                                    </div>
                                    <div class="pst-muted">{{ $row->site ?: '-' }}
                                        {{ $row->so_number ? '| SO ' . $row->so_number : '' }}</div>
                                    @if (in_array($row->delivery_mode ?? '', ['ACID', 'SPECIAL'], true))
                                        <div class="mt-1">
                                            <span class="badge bg-{{ $row->delivery_mode_badge_class ?? 'warning text-dark border' }}"
                                                title="{{ $row->delivery_mode_note ?? 'งานส่งกัดกรด' }}">
                                                {{ $row->delivery_mode_label ?? 'งานกัดกรด' }}
                                            </span>
                                        </div>
                                    @endif
                                    @if ($isGroupCont)
                                        <div class="pst-muted small fst-italic">↳ กลุ่มเดียวกัน</div>
                                    @endif
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
                                    @if ($showGroupOnce)
                                        @if (is_numeric($row->qty_kg ?? null) && (float) $row->qty_kg > 0)
                                            {{ number_format((float) $row->qty_kg, 0) }} kg
                                            @if (is_numeric($row->line_qty ?? null) && (float) $row->line_qty > 0)
                                                <div class="pst-muted small">
                                                    {{ number_format((float) $row->line_qty, 0) }}
                                                    {{ ($row->sale_type ?? '') === 'ชิ้น' ? 'ชิ้น' : 'เส้น' }}
                                                </div>
                                            @endif
                                        @else
                                            <span class="pst-muted">-</span>
                                        @endif
                                    @else
                                        <span class="pst-muted" title="ยอดแสดงในแถวบนของกลุ่มเดียวกัน">—</span>
                                    @endif
                                </td>
                                <td class="num">
                                    @if (is_numeric($row->stock_fg ?? null))
                                        {{ number_format((float) $row->stock_fg, 2) }}
                                    @else
                                        <span class="pst-muted">-</span>
                                    @endif
                                </td>
                                <td><span class="badge bg-light text-dark border">{{ $row->sale_type ?? '-' }}</span>
                                </td>
                                <td>
                                    <div>{{ $fmtDate($row->ship_date ?? $row->due_date) }}</div>
                                    <div class="pst-muted">
                                        {{ is_numeric($row->days_to_due) ? $row->days_to_due . ' days' : '-' }}
                                    </div>
                                </td>
                                <td>{{ $fmtDate($row->dp_due_date ?? null) }}</td>
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
                                    $confIsActive = \App\Models\FormDP\DeliveryConfirmation::isActiveStatus($confStatus);
                                @endphp
                                <td class="pst-confirm">
                                    <form class="pst-confirm-form d-flex flex-column gap-1"
                                        data-mfg="{{ $row->mfg_no }}" data-site="{{ $row->site }}"
                                        data-so="{{ $row->so_number ?? '' }}" data-orig-ship="{{ $origShip }}"
                                        data-saved="{{ $confIsActive ? '1' : '0' }}"
                                        data-active="{{ $confIsActive ? '1' : '0' }}">
                                        <div class="d-flex align-items-center gap-1 flex-wrap">
                                            <select class="form-select form-select-sm pst-confirm-status"
                                                style="width:130px;flex:0 0 130px;">
                                                <option value="CONFIRM"
                                                    {{ !$confStatus || $confStatus === 'CONFIRM' ? 'selected' : '' }}>
                                                    ยืนยันการส่ง</option>
                                                <option value="POSTPONE"
                                                    {{ $confStatus === 'POSTPONE' ? 'selected' : '' }}>ขอเลื่อนการส่ง
                                                </option>
                                            </select>
                                            <input type="date" class="form-control form-control-sm pst-confirm-date"
                                                value="{{ $confNewDate }}"
                                                min="{{ $origShip ? \Carbon\Carbon::parse($origShip)->addDay()->format('Y-m-d') : '' }}"
                                                style="width:130px;flex:0 0 130px; {{ $confStatus === 'POSTPONE' ? '' : 'display:none;' }}">
                                            <button type="button" class="btn btn-sm btn-primary pst-confirm-save"
                                                title="บันทึก">
                                                <i class="fas fa-save"></i>
                                            </button>
                                            <button type="button"
                                                class="btn btn-sm btn-outline-secondary pst-confirm-history"
                                                title="ประวัติ">
                                                <i class="fas fa-clock-rotate-left"></i>
                                            </button>
                                            <button type="button"
                                                class="btn btn-sm btn-outline-danger pst-confirm-cancel"
                                                title="ยกเลิก Delivery Confirmation"
                                                style="{{ $confIsActive ? '' : 'display:none;' }}">
                                                <i class="fas fa-rotate-left"></i>
                                            </button>
                                        </div>
                                        <input type="text" class="form-control form-control-sm pst-confirm-remark"
                                            placeholder="หมายเหตุ (ไม่บังคับ)" maxlength="500"
                                            value="{{ $row->confirmation_remark ?? '' }}">
                                        <div class="pst-confirm-status-display">
                                            @if ($confStatus)
                                                <span class="badge bg-{{ $confBadge }}">{{ $confLabel }}</span>
                                                @if ($confStatus === 'POSTPONE' && $confNewDateDisp)
                                                    <span class="pst-muted">→ {{ $confNewDateDisp }}</span>
                                                @endif
                                                @if (!empty($row->confirmation_confirmed_at))
                                                    <div class="pst-muted small">
                                                        บันทึก
                                                        {{ \Carbon\Carbon::parse($row->confirmation_confirmed_at)->format('d/m H:i') }}
                                                        @if (!empty($row->confirmation_confirmed_by))
                                                            โดย {{ $row->confirmation_confirmed_by }}
                                                        @endif
                                                    </div>
                                                @endif
                                                @if (!empty($row->confirmation_remark))
                                                    <div class="pst-muted small">
                                                        <i
                                                            class="fas fa-comment me-1"></i>{{ $row->confirmation_remark }}
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
                                        href="{{ route('dp.production-status.detail', [
                                            'mfg_no' => $row->mfg_no,
                                            'site' => $row->site,
                                            'ord_id' => $row->ord_id ?? null,
                                            'so_number' => $row->so_number ?? null,
                                            'dp_qty' => $row->qty_display ?? null,
                                            'dp_sale_type' => $row->sale_type ?? null,
                                            'dp_status' => $row->dp_status ?? null,
                                            'delivery_type' => $row->delivery_type ?? null,
                                            'ship_date' => $row->ship_date ?? null,
                                            'deadline' => $row->due_date ?? null,
                                            'return_url' => request()->fullUrl(),
                                        ]) }}">
                                        <i class="fas fa-list-check me-1"></i> รายละเอียด
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="18" class="text-center text-muted py-4">ไม่พบข้อมูล Production Status
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
                    <h5 class="modal-title">ประวัติ Delivery Confirmation <small class="text-muted"
                            id="pstHistMfg"></small></h5>
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
        .pst-confirm-form .pst-confirm-status-display {
            font-size: 12px;
        }

        .pst-confirm-form .pst-confirm-status-display .pst-muted {
            color: #6b7280;
        }
    </style>

    <script>
        (function() {
            const SAVE_URL = @json(route('dp.production-status.confirm'));
            const CANCEL_URL = @json(route('dp.production-status.confirm.cancel'));
            const BULK_URL = @json(route('dp.production-status.confirm.bulk'));
            const HIST_URL = @json(route('dp.production-status.confirm.history'));
            const CSRF = @json(csrf_token());

            document.addEventListener('DOMContentLoaded', function() {
                const processSelect = document.getElementById('pstQuickProcessFilter');
                if (processSelect && window.TomSelect && !processSelect.tomselect) {
                    new TomSelect(processSelect, {
                        plugins: ['remove_button'],
                        closeAfterSelect: false,
                        hideSelected: true,
                        maxOptions: 1000,
                        placeholder: processSelect.dataset.placeholder || 'ทุกสถานีงาน',
                        dropdownParent: 'body'
                    });
                }
            });

            const escapeHtml = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [c]));

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
                        heightAuto: false,
                        scrollbarPadding: false,
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
                    heightAuto: false,
                    scrollbarPadding: false,
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

            function initResizableTable() {
                const table = document.getElementById('pstMainTable');
                if (!table) return;

                const cols = Array.from(table.querySelectorAll('colgroup col'));
                const headers = Array.from(table.querySelectorAll('thead th'));
                const widthStorageKey = 'pst.productionStatus.columnWidths.v3';
                const hiddenStorageKey = 'pst.productionStatus.hiddenColumns.v3';
                const protectedColumns = new Set([0]);
                const toggleList = document.getElementById('pstColumnToggleList');
                const showAllBtn = document.getElementById('pstShowAllColumnsBtn');
                const hiddenCount = document.getElementById('pstHiddenColumnCount');
                let saved = {};
                let hiddenColumns = [];
                try {
                    saved = JSON.parse(localStorage.getItem(widthStorageKey) || '{}') || {};
                } catch (e) {
                    saved = {};
                }
                try {
                    hiddenColumns = JSON.parse(localStorage.getItem(hiddenStorageKey) || '[]') || [];
                } catch (e) {
                    hiddenColumns = [];
                }

                const hiddenSet = new Set(hiddenColumns.map(Number).filter(Number.isFinite));

                function headerLabel(th, idx) {
                    const clone = th.cloneNode(true);
                    clone.querySelectorAll('button, span.pst-resizer').forEach(el => el.remove());
                    const text = clone.textContent.trim().replace(/\s+/g, ' ');
                    return text || 'Column ' + (idx + 1);
                }

                function saveHiddenColumns() {
                    try {
                        localStorage.setItem(hiddenStorageKey, JSON.stringify(Array.from(hiddenSet).sort((a, b) => a -
                            b)));
                    } catch (e) {
                        // Column visibility still applies for the current page when browser storage is unavailable.
                    }
                }

                function applyColumnVisibility() {
                    const rows = Array.from(table.querySelectorAll('tr'));
                    cols.forEach((col, idx) => {
                        col.style.display = hiddenSet.has(idx) ? 'none' : '';
                    });
                    headers.forEach((th, idx) => {
                        th.style.display = hiddenSet.has(idx) ? 'none' : '';
                    });
                    rows.forEach(row => {
                        Array.from(row.children).forEach((cell, idx) => {
                            cell.style.display = hiddenSet.has(idx) ? 'none' : '';
                        });
                    });

                    let visibleWidth = 0;
                    cols.forEach((col, idx) => {
                        if (hiddenSet.has(idx)) return;
                        visibleWidth += Math.round(col.getBoundingClientRect().width || parseInt(col.style
                            .width, 10) || 90);
                    });
                    table.style.minWidth = Math.max(760, visibleWidth) + 'px';

                    if (hiddenCount) {
                        hiddenCount.textContent = hiddenSet.size;
                        hiddenCount.classList.toggle('bg-danger', hiddenSet.size > 0);
                        hiddenCount.classList.toggle('bg-secondary', hiddenSet.size === 0);
                    }

                    if (toggleList) {
                        toggleList.querySelectorAll('input[data-col-index]').forEach(input => {
                            input.checked = !hiddenSet.has(Number(input.dataset.colIndex));
                        });
                    }
                }

                function toggleColumn(idx, visible) {
                    if (protectedColumns.has(idx)) return;
                    if (visible) {
                        hiddenSet.delete(idx);
                    } else {
                        hiddenSet.add(idx);
                    }
                    saveHiddenColumns();
                    applyColumnVisibility();
                }

                cols.forEach((col, idx) => {
                    if (saved[idx]) {
                        col.style.width = saved[idx] + 'px';
                    }
                });

                headers.forEach((th, idx) => {
                    if (!cols[idx]) return;

                    if (!protectedColumns.has(idx)) {
                        const hideBtn = document.createElement('button');
                        hideBtn.type = 'button';
                        hideBtn.className = 'pst-hide-col-btn';
                        hideBtn.title = 'Hide column';
                        hideBtn.innerHTML = '<i class="fas fa-eye-slash"></i>';
                        hideBtn.addEventListener('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            toggleColumn(idx, false);
                        });
                        th.appendChild(hideBtn);
                    }

                    const handle = document.createElement('span');
                    handle.className = 'pst-resizer';
                    handle.setAttribute('aria-hidden', 'true');
                    th.appendChild(handle);

                    handle.addEventListener('mousedown', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        const startX = e.clientX;
                        const startWidth = cols[idx].getBoundingClientRect().width || th
                            .getBoundingClientRect().width;
                        document.body.classList.add('pst-table-resizing');

                        const onMove = (moveEvent) => {
                            const nextWidth = Math.max(48, Math.round(startWidth + moveEvent
                                .clientX - startX));
                            cols[idx].style.width = nextWidth + 'px';
                        };

                        const onUp = () => {
                            document.removeEventListener('mousemove', onMove);
                            document.removeEventListener('mouseup', onUp);
                            document.body.classList.remove('pst-table-resizing');
                            const widths = {};
                            cols.forEach((col, colIdx) => {
                                const width = Math.round(col.getBoundingClientRect().width);
                                if (width) widths[colIdx] = width;
                            });
                            try {
                                localStorage.setItem(widthStorageKey, JSON.stringify(widths));
                            } catch (e) {
                                // Resizing still applies for the current page when browser storage is unavailable.
                            }
                        };

                        document.addEventListener('mousemove', onMove);
                        document.addEventListener('mouseup', onUp);
                    });
                });

                if (toggleList) {
                    toggleList.innerHTML = '';
                    headers.forEach((th, idx) => {
                        if (protectedColumns.has(idx)) return;

                        const item = document.createElement('label');
                        item.className = 'dropdown-item mb-1';
                        item.innerHTML =
                            '<input type="checkbox" class="form-check-input m-0" data-col-index="' + idx +
                            '">' +
                            '<span>' + headerLabel(th, idx) + '</span>';
                        const input = item.querySelector('input');
                        input.checked = !hiddenSet.has(idx);
                        input.addEventListener('change', function(e) {
                            toggleColumn(idx, e.target.checked);
                        });
                        toggleList.appendChild(item);
                    });
                }

                if (showAllBtn) {
                    showAllBtn.addEventListener('click', function() {
                        hiddenSet.clear();
                        saveHiddenColumns();
                        applyColumnVisibility();
                    });
                }

                applyColumnVisibility();
            }

            initResizableTable();

            document.querySelectorAll('.pst-confirm-form').forEach(function(form) {
                const select = form.querySelector('.pst-confirm-status');
                const dateInp = form.querySelector('.pst-confirm-date');
                const saveBtn = form.querySelector('.pst-confirm-save');
                const histBtn = form.querySelector('.pst-confirm-history');
                const cancelBtn = form.querySelector('.pst-confirm-cancel');
                const display = form.querySelector('.pst-confirm-status-display');

                select.addEventListener('change', function() {
                    if (select.value === 'POSTPONE') {
                        dateInp.style.display = '';
                    } else {
                        dateInp.style.display = 'none';
                        dateInp.value = '';
                    }
                });

                const remarkInp = form.querySelector('.pst-confirm-remark');

                saveBtn.addEventListener('click', async function() {
                    const status = select.value;
                    const remark = remarkInp ? remarkInp.value.trim() : '';
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

                    const remarkLine = remark ?
                        '<div class="mt-2"><b>หมายเหตุ:</b> ' + escapeHtml(remark) + '</div>' :
                        '';

                    let html = '';
                    if (status === 'CONFIRM') {
                        html = '<div class="text-start">' +
                            '<div><b>MFG:</b> ' + mfg + '</div>' +
                            '<div><b>วันส่งเดิม:</b> ' + fmt(origShip) + '</div>' +
                            '<div class="mt-2 text-success"><b>ยืนยันส่งได้ตามกำหนดเดิม</b></div>' +
                            remarkLine +
                            '</div>';
                    } else {
                        html = '<div class="text-start">' +
                            '<div><b>MFG:</b> ' + mfg + '</div>' +
                            '<div><b>วันส่งเดิม:</b> ' + fmt(origShip) + '</div>' +
                            '<div class="mt-2 text-warning"><b>ขอเลื่อนส่งเป็น:</b> ' + fmt(dateInp
                                .value) + '</div>' +
                            remarkLine +
                            '</div>';
                    }

                    const ok = await confirmDialog({
                        title: status === 'CONFIRM' ? 'ยืนยันการส่งมอบ' :
                            'ยืนยันขอเลื่อนส่ง',
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
                            heightAuto: false,
                            scrollbarPadding: false,
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
                    if (remark) {
                        fd.append('remark', remark);
                    }

                    fetch(SAVE_URL, {
                            method: 'POST',
                            body: fd,
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest',
                                'Accept': 'application/json'
                            },
                            credentials: 'same-origin'
                        })
                        .then(r => r.json().then(j => ({
                            status: r.status,
                            json: j
                        })))
                        .then(({
                            status: code,
                            json
                        }) => {
                            if (window.Swal) Swal.close();
                            if (!json.ok) {
                                notify(json.message || 'บันทึกไม่สำเร็จ', false);
                                return;
                            }
                            notify(json.message || 'บันทึกแล้ว', true);
                            const d = json.data || {};
                            let html = '<span class="badge bg-' + (d.badge_class ||
                                'secondary') + '">' + (d.status_label || '-') + '</span>';
                            if (d.confirmation_status === 'POSTPONE' && d
                                .new_delivery_date_display) {
                                html += ' <span class="pst-muted">→ ' + d
                                    .new_delivery_date_display + '</span>';
                            }
                            if (d.confirmed_at) {
                                html += '<div class="pst-muted small">บันทึก ' + d
                                    .confirmed_at + (d.confirmed_by_name ? ' โดย ' + d
                                        .confirmed_by_name : '') + '</div>';
                            }
                            if (d.remark) {
                                const esc = (s) => String(s).replace(/[&<>"']/g, c => ({
                                    '&': '&amp;',
                                    '<': '&lt;',
                                    '>': '&gt;',
                                    '"': '&quot;',
                                    "'": '&#39;'
                                } [c]));
                                html +=
                                    '<div class="pst-muted small"><i class="fas fa-comment me-1"></i>' +
                                    esc(d.remark) + '</div>';
                            }
                            display.innerHTML = html;
                            form.dataset.saved = '1';
                            form.dataset.active = '1';
                            if (cancelBtn) cancelBtn.style.display = '';
                            refreshPendingCount();
                        })
                        .catch(() => {
                            if (window.Swal) Swal.close();
                            notify('เกิดข้อผิดพลาดในการบันทึก', false);
                        })
                        .finally(() => {
                            saveBtn.disabled = false;
                        });
                });

                if (cancelBtn) {
                    cancelBtn.addEventListener('click', async function() {
                        if (form.dataset.active !== '1') {
                            notify('รายการนี้ไม่มี Delivery Confirmation ที่ยังใช้งานอยู่', false);
                            return;
                        }

                        let reason = null;
                        if (window.Swal) {
                            const result = await Swal.fire({
                                title: 'ยกเลิก Delivery Confirmation',
                                html: '<div class="text-start mb-2"><b>MFG:</b> ' +
                                    escapeHtml(form.dataset.mfg || '-') +
                                    '</div><div class="text-start text-danger small">ระบบจะเก็บรายการยกเลิกไว้ในประวัติ</div>',
                                input: 'textarea',
                                inputLabel: 'เหตุผลที่ยกเลิก',
                                inputPlaceholder: 'กรุณาระบุเหตุผล...',
                                inputAttributes: {
                                    maxlength: 500,
                                    required: true
                                },
                                icon: 'warning',
                                showCancelButton: true,
                                confirmButtonText: 'ยืนยันยกเลิก',
                                cancelButtonText: 'กลับ',
                                confirmButtonColor: '#dc3545',
                                reverseButtons: true,
                                heightAuto: false,
                                scrollbarPadding: false,
                                inputValidator: value => !String(value || '').trim() ?
                                    'กรุณาระบุเหตุผลที่ยกเลิก' : null,
                            });
                            if (!result.isConfirmed) return;
                            reason = String(result.value || '').trim();
                        } else {
                            reason = window.prompt('กรุณาระบุเหตุผลที่ยกเลิก Delivery Confirmation');
                            if (reason === null) return;
                            reason = reason.trim();
                            if (!reason) {
                                notify('กรุณาระบุเหตุผลที่ยกเลิก', false);
                                return;
                            }
                        }

                        cancelBtn.disabled = true;
                        const fd = new FormData();
                        fd.append('_token', CSRF);
                        fd.append('mfg_no', form.dataset.mfg || '');
                        fd.append('site', form.dataset.site || '');
                        fd.append('reason', reason);

                        fetch(CANCEL_URL, {
                                method: 'POST',
                                body: fd,
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'Accept': 'application/json'
                                },
                                credentials: 'same-origin'
                            })
                            .then(r => r.json().then(j => ({
                                status: r.status,
                                json: j
                            })))
                            .then(({ json }) => {
                                if (!json.ok) {
                                    notify(json.message || 'ยกเลิกไม่สำเร็จ', false);
                                    return;
                                }
                                const d = json.data || {};
                                display.innerHTML =
                                    '<span class="badge bg-' + (d.badge_class || 'danger') + '">' +
                                    escapeHtml(d.status_label || 'ยกเลิกการยืนยัน') + '</span>' +
                                    (d.confirmed_at ? '<div class="pst-muted small">บันทึก ' +
                                        escapeHtml(d.confirmed_at) + (d.confirmed_by_name ? ' โดย ' +
                                            escapeHtml(d.confirmed_by_name) : '') + '</div>' : '') +
                                    '<div class="pst-muted small"><i class="fas fa-comment me-1"></i>' +
                                    escapeHtml(d.remark || reason) + '</div>' +
                                    '<div class="pst-muted small">กลับไปรอวางแผนยืนยัน</div>';
                                form.dataset.saved = '0';
                                form.dataset.active = '0';
                                cancelBtn.style.display = 'none';
                                select.value = 'CONFIRM';
                                dateInp.value = '';
                                dateInp.style.display = 'none';
                                if (remarkInp) remarkInp.value = '';
                                refreshPendingCount();
                                notify(json.message || 'ยกเลิกเรียบร้อย', true);
                            })
                            .catch(() => notify('เกิดข้อผิดพลาดในการยกเลิก', false))
                            .finally(() => {
                                cancelBtn.disabled = false;
                            });
                    });
                }

                // refresh pending count when status changes (e.g. switched to POSTPONE → no longer "pending bulk")
                select.addEventListener('change', refreshPendingCount);

                histBtn.addEventListener('click', function() {
                    const mfg = form.dataset.mfg || '';
                    const site = form.dataset.site || '';
                    document.getElementById('pstHistMfg').textContent = mfg + (site ? ' (' + site +
                        ')' : '');
                    const body = document.getElementById('pstHistBody');
                    body.innerHTML = '<div class="text-center text-muted py-4">กำลังโหลด...</div>';
                    new bootstrap.Modal(document.getElementById('pstConfirmHistoryModal')).show();

                    const url = HIST_URL + '?mfg_no=' + encodeURIComponent(mfg) + '&site=' +
                        encodeURIComponent(site);
                    fetch(url, {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            credentials: 'same-origin'
                        })
                        .then(r => r.json())
                        .then(j => {
                            if (!j.ok || !j.rows || j.rows.length === 0) {
                                body.innerHTML =
                                    '<div class="text-center text-muted py-4">ยังไม่มีประวัติ</div>';
                                return;
                            }
                            let html =
                                '<div class="table-responsive"><table class="table table-sm table-bordered align-middle">';
                            html +=
                                '<thead class="table-light"><tr><th>วันที่บันทึก</th><th>สถานะ</th><th>วันส่งเดิม</th><th>วันส่งใหม่</th><th>ผู้บันทึก</th><th>หมายเหตุ</th></tr></thead><tbody>';
                            j.rows.forEach(function(r) {
                                html += '<tr>';
                                html += '<td>' + (r.confirmed_at || '-') + '</td>';
                                html += '<td><span class="badge bg-' + (r.badge_class ||
                                        'secondary') + '">' + (r.status_label || '-') +
                                    '</span></td>';
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
                            body.innerHTML =
                                '<div class="text-center text-danger py-4">โหลดประวัติไม่สำเร็จ</div>';
                        });
                });
            });

            // Bulk confirm button
            const bulkBtn = document.getElementById('pstBulkConfirmBtn');
            if (bulkBtn) {
                bulkBtn.addEventListener('click', async function() {
                    const pending = pendingForms();
                    const forms = pending.filter(f => (f.querySelector('.pst-confirm-status') || {})
                        .value === 'CONFIRM');
                    const postponeCount = pending.length - forms.length;

                    if (forms.length === 0) {
                        if (pending.length === 0) {
                            notify('ทุกรายการในหน้านี้บันทึกแล้ว ไม่มีอะไรต้องยืนยันเพิ่ม', false);
                        } else {
                            notify('รายการที่ยังไม่ยืนยันทั้งหมดเลือกเป็น Request Postpone — กรุณากด Save แยกแต่ละแถว เพราะต้องระบุวันส่งใหม่',
                                false);
                        }
                        return;
                    }

                    let html = '<div class="text-start">' +
                        '<div>จะบันทึก <b class="text-success">Confirm Delivery</b> จำนวน <b>' + forms
                        .length + '</b> รายการ</div>';
                    if (postponeCount > 0) {
                        html += '<div class="text-warning small mt-2">' +
                            '<i class="fas fa-triangle-exclamation me-1"></i>' +
                            'ข้าม ' + postponeCount +
                            ' รายการที่เลือก Request Postpone (ต้องกรอกวันใหม่ + Save แยก)' +
                            '</div>';
                    }
                    html += '</div>';

                    const ok = await confirmDialog({
                        title: 'ยืนยันส่งได้ตามแผน (Bulk)',
                        html: html,
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
                            heightAuto: false,
                            scrollbarPadding: false,
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
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest',
                                'Accept': 'application/json'
                            },
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
                                f.dataset.active = '1';
                                const cancel = f.querySelector('.pst-confirm-cancel');
                                if (cancel) cancel.style.display = '';
                                const disp = f.querySelector('.pst-confirm-status-display');
                                if (disp) {
                                    disp.innerHTML =
                                        '<span class="badge bg-success">Confirm Delivery</span>' +
                                        '<div class="pst-muted small">เพิ่งบันทึก (bulk)</div>';
                                }
                            });
                            refreshPendingCount();
                        })
                        .catch(() => {
                            if (window.Swal) Swal.close();
                            notify('เกิดข้อผิดพลาดในการบันทึก', false);
                        })
                        .finally(() => {
                            bulkBtn.disabled = false;
                        });
                });
            }

            refreshPendingCount();
        })();

        // Persist collapsible panel state in localStorage
        (function () {
            document.querySelectorAll('details.pst-collapsible[data-storage-key]').forEach(function (el) {
                const key = el.getAttribute('data-storage-key');
                if (!key) return;
                try {
                    const saved = localStorage.getItem(key);
                    if (saved === '1') el.setAttribute('open', '');
                    else if (saved === '0') el.removeAttribute('open');
                } catch (e) {}
                el.addEventListener('toggle', function () {
                    try {
                        localStorage.setItem(key, el.open ? '1' : '0');
                    } catch (e) {}
                });
            });
        })();

        // Autocomplete for filter inputs (MFG, SO, Customer, Item)
        @php
            $pstAcSources = $insights['autocompleteSources'] ?? [
                'mfg' => [],
                'so' => [],
                'customer' => [],
                'item' => [],
            ];
        @endphp
        (function pstAutocomplete() {
            const sources = {!! json_encode($pstAcSources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!};
            const sourceArrays = {};
            ['mfg', 'so', 'customer', 'item'].forEach(function (key) {
                const list = Array.isArray(sources[key]) ? sources[key] : [];
                sourceArrays[key] = list
                    .map(function (v) { return String(v || '').trim(); })
                    .filter(function (v) { return v !== ''; })
                    .sort(function (a, b) { return a.localeCompare(b, 'th'); });
            });

            const escapeHtml = function (s) {
                return String(s).replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            };
            const escapeRegex = function (s) {
                return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            };
            const highlight = function (text, term) {
                if (!term) return escapeHtml(text);
                const re = new RegExp('(' + escapeRegex(term) + ')', 'ig');
                return escapeHtml(text).replace(re, '<mark>$1</mark>');
            };

            // Clear (X) buttons
            document.querySelectorAll('.dp-ac-clear').forEach(function (btn) {
                const targetId = btn.dataset.acClear;
                const input = document.getElementById(targetId);
                if (!input) return;
                const wrap = btn.closest('.dp-ac-wrap');
                const sync = function () {
                    if (wrap) wrap.classList.toggle('has-value', input.value.trim() !== '');
                };
                sync();
                input.addEventListener('input', sync);
                input.addEventListener('change', sync);
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    input.value = '';
                    sync();
                    input.focus();
                    input.dispatchEvent(new Event('input'));
                });
            });

            document.querySelectorAll('.dp-ac-input').forEach(function (input) {
                const sourceKey = input.dataset.acSource || '';
                const list = sourceArrays[sourceKey] || [];
                const dd = document.querySelector('.dp-suggest[data-ac-for="' + input.id + '"]');
                if (!dd) return;

                let activeIdx = -1;

                function render(term) {
                    const q = (term || '').trim().toLowerCase();
                    const matched = q
                        ? list.filter(function (v) { return v.toLowerCase().includes(q); }).slice(0, 30)
                        : list.slice(0, 30);

                    if (!matched.length) {
                        dd.innerHTML = '<div class="dp-suggest-empty">ไม่พบรายการ</div>';
                    } else {
                        dd.innerHTML = matched.map(function (v, i) {
                            return '<button type="button" class="dp-suggest-item" data-val="' + escapeHtml(v) + '" data-idx="' + i + '">' +
                                '<div class="dp-suggest-title">' + highlight(v, term) + '</div>' +
                                '</button>';
                        }).join('');
                    }
                    dd.classList.remove('d-none');
                    activeIdx = -1;
                }

                function hide() {
                    dd.classList.add('d-none');
                    activeIdx = -1;
                }

                function setActive(idx) {
                    const items = dd.querySelectorAll('.dp-suggest-item');
                    items.forEach(function (el) { el.classList.remove('is-active'); });
                    if (idx >= 0 && idx < items.length) {
                        items[idx].classList.add('is-active');
                        items[idx].scrollIntoView({ block: 'nearest' });
                        activeIdx = idx;
                    }
                }

                input.addEventListener('focus', function () { render(input.value); });
                input.addEventListener('input', function () { render(input.value); });

                input.addEventListener('keydown', function (e) {
                    const items = dd.querySelectorAll('.dp-suggest-item');
                    if (!items.length || dd.classList.contains('d-none')) return;
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        setActive(Math.min(activeIdx + 1, items.length - 1));
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        setActive(Math.max(activeIdx - 1, 0));
                    } else if (e.key === 'Enter' && activeIdx >= 0) {
                        e.preventDefault();
                        input.value = items[activeIdx].dataset.val || '';
                        hide();
                    } else if (e.key === 'Escape') {
                        hide();
                    }
                });

                dd.addEventListener('mousedown', function (e) {
                    const btn = e.target.closest('.dp-suggest-item');
                    if (!btn) return;
                    e.preventDefault();
                    input.value = btn.dataset.val || '';
                    hide();
                    input.focus();
                });

                document.addEventListener('click', function (e) {
                    if (!input.contains(e.target) && !dd.contains(e.target)) hide();
                });
            });
        })();

        // Date range presets (Ship from/to)
        (function () {
            const from = document.getElementById('pst_ship_from');
            const to = document.getElementById('pst_ship_to');
            if (!from || !to) return;
            const buttons = Array.from(document.querySelectorAll('.pst-date-preset'));
            const fmt = (d) => {
                const y = d.getFullYear();
                const m = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');
                return `${y}-${m}-${day}`;
            };
            const computeRange = (r) => {
                const now = new Date();
                if (r === 'clear') return { from: '', to: '' };
                if (r === 'today') return { from: fmt(now), to: fmt(now) };
                if (r === 'month') {
                    const start = new Date(now.getFullYear(), now.getMonth(), 1);
                    const end = new Date(now.getFullYear(), now.getMonth() + 1, 0);
                    return { from: fmt(start), to: fmt(end) };
                }
                const days = parseInt(r, 10);
                if (!Number.isNaN(days)) {
                    const end = new Date(now);
                    end.setDate(end.getDate() + days);
                    return { from: fmt(now), to: fmt(end) };
                }
                return null;
            };
            const syncActive = () => {
                const cur = { from: from.value, to: to.value };
                let matched = null;
                if (!cur.from && !cur.to) {
                    matched = 'clear';
                } else {
                    for (const btn of buttons) {
                        const r = btn.dataset.range;
                        if (r === 'clear') continue;
                        const rng = computeRange(r);
                        if (rng && rng.from === cur.from && rng.to === cur.to) {
                            matched = r;
                            break;
                        }
                    }
                }
                buttons.forEach((b) => b.classList.toggle('is-active', b.dataset.range === matched));
            };
            buttons.forEach((btn) => {
                btn.addEventListener('click', function () {
                    const rng = computeRange(btn.dataset.range);
                    if (!rng) return;
                    from.value = rng.from;
                    to.value = rng.to;
                    syncActive();
                });
            });
            from.addEventListener('change', syncActive);
            to.addEventListener('change', syncActive);
            syncActive();
        })();

        // Confirm before bulk Confirm Delivery
        (function () {
            const btn = document.getElementById('pstBulkConfirmBtn');
            if (!btn) return;
            btn.addEventListener('click', function (e) {
                const countEl = document.getElementById('pstBulkPendingCount');
                const n = countEl ? (parseInt(countEl.textContent, 10) || 0) : 0;
                const msg = n > 0
                    ? `ยืนยันการส่งทั้งหมด ${n} รายการที่ยังไม่ยืนยัน?\n\nรายการเหล่านี้จะถูกบันทึกเป็น "ยืนยัน" ทันที`
                    : 'ไม่พบรายการที่ยังไม่ยืนยันในหน้านี้';
                if (n === 0) {
                    e.preventDefault();
                    e.stopPropagation();
                    alert(msg);
                    return;
                }
                if (!confirm(msg)) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            }, true);
        })();
    </script>
@endsection
