@extends('layouts.layout')

@section('title', 'ตรวจสถานะ Deadstock รายเดือน')
@section('page-title', 'ตรวจสถานะ Deadstock รายเดือน')

@push('styles')
    <style>
        .ds-shell {
            display: grid;
            gap: 14px;
        }

        .ds-toolbar,
        .ds-summary,
        .ds-table {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
        }

        .ds-toolbar,
        .ds-summary {
            padding: 14px 16px 12px;
        }

        .ds-toolbar-head {
            align-items: start;
            display: grid;
            gap: 12px;
            grid-template-columns: minmax(0, 1fr) minmax(360px, 460px);
            margin-bottom: 12px;
        }

        .ds-actions {
            display: grid;
            gap: 8px;
            justify-items: end;
            min-width: 0;
            width: 100%;
        }

        .ds-actions .btn {
            min-width: 128px;
        }

        .ds-action-row {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: flex-end;
        }

        .ds-action-note {
            color: #64748b;
            font-size: .8rem;
            line-height: 1.45;
            text-align: right;
        }

        .ds-import-card {
            background: #f8fafc;
            border: 1px solid #dbe4ef;
            border-radius: 8px;
            display: grid;
            gap: 8px;
            padding: 10px;
            width: 100%;
        }

        .ds-import-title {
            align-items: center;
            color: #334155;
            display: flex;
            font-size: .82rem;
            font-weight: 700;
            gap: 6px;
            justify-content: space-between;
            flex-wrap: wrap;
        }

        .ds-import-controls {
            align-items: center;
            display: grid;
            gap: 8px;
            grid-template-columns: minmax(220px, 1fr) auto auto;
        }

        .ds-import-controls .form-control[type="file"] {
            min-width: 0;
        }

        .ds-filter-title {
            align-items: center;
            border-top: 1px solid #e2e8f0;
            display: flex;
            font-weight: 700;
            justify-content: space-between;
            margin-top: 4px;
            padding-top: 12px;
        }

        .ds-active-filters {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 10px;
        }

        .ds-active-filter {
            align-items: center;
            background: #eef4ff;
            border: 1px solid #bfdbfe;
            border-radius: 999px;
            color: #1e3a8a;
            display: inline-flex;
            font-size: .78rem;
            font-weight: 700;
            gap: 6px;
            padding: .28rem .58rem;
            text-decoration: none;
        }

        .ds-active-filter:hover {
            background: #dbeafe;
            color: #1e3a8a;
            text-decoration: none;
        }

        .ds-quick-filter {
            align-items: center;
            border-top: 1px dashed #dbe4ef;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
            padding-top: 12px;
        }

        .ds-month-range {
            display: grid;
            gap: 8px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .ds-month-shortcuts {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 8px;
        }

        .ds-month-shortcuts .btn-sm {
            min-width: 72px;
            padding-left: .7rem;
            padding-right: .7rem;
        }

        .ds-filter-select+.ts-wrapper .ts-control {
            border-color: #cbd5e1;
            border-radius: 6px;
            min-height: 38px;
            padding: 4px 8px;
        }

        .ds-filter-select+.ts-wrapper.multi .ts-control {
            gap: 4px;
        }

        .ds-filter-select+.ts-wrapper.multi .item {
            background: #e0f2fe;
            border: 1px solid #bae6fd;
            border-radius: 999px;
            color: #075985;
            line-height: 1.35;
            margin: 2px;
            padding: 2px 8px;
        }

        .ds-filter-select+.ts-wrapper .ts-dropdown,
        body>.ts-dropdown {
            z-index: 1080;
        }

        body>.ts-dropdown {
            position: absolute;
        }

        .ds-summary-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        }

        .ds-kpi {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            min-height: 82px;
            padding: 12px;
        }

        .ds-kpi:nth-child(2) {
            background: #eff6ff;
        }

        .ds-kpi:nth-child(3) {
            background: #fffbeb;
        }

        .ds-kpi:nth-child(4) {
            background: #f0fdf4;
        }

        .ds-kpi-label {
            color: #64748b;
            font-size: .76rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .ds-kpi-value {
            color: #0f172a;
            font-size: 1.2rem;
            font-weight: 700;
            line-height: 1.25;
            margin-top: 6px;
        }

        .ds-status {
            border-radius: 999px;
            display: inline-flex;
            font-size: .76rem;
            font-weight: 700;
            padding: .26rem .55rem;
            white-space: nowrap;
        }

        .ds-status.pending {
            background: #e2e8f0;
            color: #334155;
        }

        .ds-status.active {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .ds-status.changed {
            background: #fef3c7;
            color: #92400e;
        }

        .ds-status.cleared {
            background: #dcfce7;
            color: #166534;
        }

        .ds-change-list {
            display: grid;
            gap: 4px;
            margin-top: 8px;
        }

        .ds-change {
            background: #fffbeb;
            border: 1px solid #fcd34d;
            border-radius: 6px;
            color: #78350f;
            font-size: .76rem;
            font-weight: 700;
            line-height: 1.3;
            padding: 6px 8px;
        }

        .ds-change-value {
            background: #fff7ed;
            border-left: 3px solid #f59e0b;
            border-radius: 4px;
            margin-top: 6px;
            padding: 6px 8px;
        }

        .ds-change-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 6px;
        }

        .ds-change-tag {
            background: #fef3c7;
            border-radius: 999px;
            color: #92400e;
            font-size: .72rem;
            font-weight: 700;
            padding: .18rem .45rem;
        }

        .ds-action-status {
            background: #eef2ff;
            border-radius: 999px;
            color: #3730a3;
            display: inline-block;
            font-size: .72rem;
            font-weight: 700;
            line-height: 1.2;
            margin-top: 6px;
            padding: .2rem .5rem;
        }

        .ds-review-meta {
            line-height: 1.35;
            text-align: center;
        }

        .ds-table-wrap {
            max-height: calc(100vh - 170px);
            overflow: auto;
        }

        .ds-grid {
            border-color: #d8e0ea;
            font-size: .86rem;
            min-width: 1420px;
            table-layout: fixed;
        }

        .ds-grid th {
            background: #edf4ff;
            border-color: #d8e0ea;
            color: #1f2937;
            font-size: .8rem;
            font-weight: 800;
            position: sticky;
            top: 0;
            user-select: none;
            white-space: nowrap;
            z-index: 4;
        }

        .ds-grid th,
        .ds-grid td {
            border-color: #d8e0ea;
            padding: .34rem .42rem;
        }

        .ds-grid th:nth-child(1),
        .ds-grid td:nth-child(1) {
            left: 0;
            position: sticky;
            text-align: center;
            width: 52px;
            z-index: 3;
        }

        .ds-grid th:nth-child(1) {
            z-index: 6;
        }

        .ds-grid td:nth-child(1) {
            background: #fff;
        }

        .ds-grid th:nth-child(2),
        .ds-grid td:nth-child(2) {
            left: 52px;
            position: sticky;
            width: 130px;
            z-index: 3;
        }

        .ds-grid th:nth-child(2) {
            z-index: 6;
        }

        .ds-grid td:nth-child(2) {
            background: #fff;
        }

        .ds-grid th:nth-child(3),
        .ds-grid td:nth-child(3),
        .ds-grid th:nth-child(6),
        .ds-grid td:nth-child(6),
        .ds-grid th:nth-child(7),
        .ds-grid td:nth-child(7) {
            width: 104px;
        }

        .ds-grid th:nth-child(5),
        .ds-grid td:nth-child(5) {
            width: 128px;
        }

        .ds-grid th:nth-child(4),
        .ds-grid td:nth-child(4) {
            width: 220px;
        }

        .ds-grid th:nth-child(9),
        .ds-grid td:nth-child(9) {
            width: 260px;
        }

        .ds-grid th:nth-child(10),
        .ds-grid td:nth-child(10) {
            width: 92px;
        }

        .ds-grid th:nth-child(11),
        .ds-grid td:nth-child(11) {
            width: 160px;
        }

        .ds-grid th:nth-child(8),
        .ds-grid td:nth-child(8),
        .ds-grid th:nth-child(12),
        .ds-grid td:nth-child(12),
        .ds-grid th:nth-child(13),
        .ds-grid td:nth-child(13),
        .ds-grid th:nth-child(14),
        .ds-grid td:nth-child(14),
        .ds-grid th:nth-child(15),
        .ds-grid td:nth-child(15) {
            display: none;
        }

        .ds-grid th:nth-child(16),
        .ds-grid td:nth-child(16) {
            width: 132px;
        }

        .ds-grid td {
            vertical-align: middle;
            word-break: break-word;
        }

        .ds-grid tbody tr:hover td {
            background: #f8fbff;
        }

        .ds-row-no {
            color: #64748b;
            font-weight: 800;
        }

        .ds-item-meta {
            color: #64748b;
            font-size: .78rem;
            margin-top: 4px;
        }

        .ds-review-btn {
            align-items: center;
            display: inline-flex;
            gap: 6px;
            justify-content: center;
            line-height: 1.25;
            min-height: 38px;
            padding: .35rem .55rem;
            width: 100%;
            white-space: normal;
        }

        .ds-note {
            color: #64748b;
            font-size: .78rem;
        }

        .ds-cell-strong {
            color: #0f172a;
            font-weight: 700;
        }

        .ds-customer-cell {
            width: 230px;
        }

        .ds-toolbar .form-label {
            font-size: .82rem;
            font-weight: 700;
        }

        .ds-toolbar form.row {
            border-top: 1px solid #e2e8f0;
            margin-top: 10px !important;
            padding-top: 12px;
        }

        .ds-toolbar form.row>[class*="col-"] {
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
        }

        .ds-toolbar form.row .btn,
        .ds-toolbar form.row .form-control,
        .ds-toolbar form.row .form-select {
            min-height: 38px;
        }

        .ds-compare-note {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            color: #1e3a8a;
            padding: 12px 14px;
        }

        .ds-compare-note.warning {
            background: #fffbeb;
            border-color: #fde68a;
            color: #78350f;
        }

        .ds-compare-note .metric {
            color: #0f172a;
            font-weight: 800;
        }

        .ds-filter-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 10px;
        }

        .ds-filter-badge {
            background: #f8fafc;
            border: 1px solid #dbe4ef;
            border-radius: 999px;
            color: #334155;
            display: inline-flex;
            font-size: .78rem;
            font-weight: 700;
            gap: 4px;
            padding: .28rem .58rem;
        }

        .ds-help-note {
            background: #f8fafc;
            border: 1px solid #dbe4ef;
            border-radius: 8px;
            color: #475569;
            font-size: .86rem;
            line-height: 1.45;
            margin-top: 10px;
            padding: 10px 12px;
        }

        .ds-status-note {
            display: grid;
            gap: 6px;
        }

        .ds-status-note strong {
            color: #1f2937;
        }

        .ds-month-picker {
            background: #fff;
            border: 1px solid #dbe4ef;
            border-radius: 6px;
            display: grid;
            gap: 4px;
            max-height: 138px;
            overflow: auto;
            padding: 8px 10px;
        }

        .ds-month-picker .form-check {
            margin: 0;
            min-height: 0;
        }

        .ds-month-picker .form-check-label {
            font-size: .9rem;
        }

        @media (max-width: 1200px) {
            .ds-actions {
                justify-items: stretch;
                justify-content: flex-start;
            }

            .ds-action-row,
            .ds-action-note {
                justify-content: flex-start;
                text-align: left;
            }

            .ds-import-controls {
                grid-template-columns: minmax(220px, 1fr) auto auto;
            }

            .ds-toolbar-head {
                grid-template-columns: 1fr;
            }

            .ds-summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 720px) {
            .ds-import-controls {
                grid-template-columns: 1fr;
            }

            .ds-import-controls .btn {
                max-width: none;
                width: 100%;
            }
        }
    </style>
@endpush

@section('content')
    @php
        $selectedMonthIds = $selectedMonthIds ?? ($selectedMonth ? [$selectedMonth->id] : []);
        $allMonthsSelected = $months->isNotEmpty() && count($selectedMonthIds) === $months->count();
        $monthLabel = $allMonthsSelected
            ? 'ทุกเดือน'
            : ($selectedMonths ?? collect())
                ->map(fn($month) => $month->snapshot_month?->format('M Y') ?? '-')
                ->implode(', ');
        $monthLabel = $monthLabel !== '' ? $monthLabel : '-';
        $formatMonthLabel = function (?string $monthValue): string {
            $monthValue = trim((string) $monthValue);
            if ($monthValue === '') {
                return '';
            }

            return \Carbon\Carbon::parse($monthValue . '-01')->format('M Y');
        };
        $requestMonthFrom = trim((string) request('month_from', ''));
        $requestMonthTo = trim((string) request('month_to', ''));
        if ($requestMonthFrom !== '' || $requestMonthTo !== '') {
            $fromLabel = $formatMonthLabel($requestMonthFrom);
            $toLabel = $formatMonthLabel($requestMonthTo);
            $monthLabel = $fromLabel !== '' && $toLabel !== '' && $fromLabel !== $toLabel
                ? $fromLabel . ' - ' . $toLabel
                : ($fromLabel !== '' ? $fromLabel : $toLabel);
        } elseif (!$allMonthsSelected) {
            $monthLabel = ($selectedMonths ?? collect())
                ->map(function ($month) {
                    $displayDate = $month->recv_date ?? $month->as_of_date ?? $month->snapshot_month;
                    return $displayDate?->format('M Y') ?? '-';
                })
                ->implode(', ');
            $monthLabel = $monthLabel !== '' ? $monthLabel : '-';
        }
        $statusFilterOptions = \App\Support\FormWOS\DeadstockCompareStatus::filterOptions();
        $statusLabels = $statusFilterOptions + \App\Support\FormWOS\DeadstockCompareStatus::rawLabels();
        $actionStatusLabels = \App\Support\FormWOS\DeadstockReviewStatus::labels();
        $actionStatusOptions = \App\Support\FormWOS\DeadstockReviewStatus::filterOptions();
        $allActionStatusLabels = $actionStatusOptions;
        $sortOptions = [
            'qty_desc' => 'Qty มากสุด',
            'purchase_oldest' => 'วันที่รับเข้าเก่าสุด',
            'due_soon' => 'กำหนดส่งใกล้สุด',
        ];
        $siteOptions = $siteOptions ?? ['WIRE' => 'WIRE', 'PLUS' => 'PLUS'];
        $customerFilters = collect($customerFilter ?? [])
            ->filter()
            ->values();
        $salesDivisionFilters = collect($salesDivisionFilter ?? [])
            ->filter()
            ->values();
        $salesDivisionOptions = $salesDivisionOptions ?? [];
        $reasonFilters = collect($reasonFilter ?? [])
            ->filter()
            ->values();
        $customerOptionValues = collect($customerOptions ?? []);
        $reasonOptionCodes = collect($reasonOptions ?? [])->pluck('deadstock_code');
        $codeGroup = strtoupper((string) request('code_group', ''));
        $codeGroupLabel = in_array($codeGroup, ['FF', 'SS'], true) ? $codeGroup . '.*' : '';
        $normalizedSiteFilter = strtoupper((string) ($siteFilter ?? 'all'));
        $monthQueryValue = fn($month) => ($month?->recv_date ?? $month?->as_of_date ?? $month?->snapshot_month)?->format('Y-m');
        $latestMonth = $monthQueryValue($months->first());
        $thirdMonth = $monthQueryValue($months->skip(2)->first()) ?? $latestMonth;
        $oldestMonth = $monthQueryValue($months->last());
        $currentStatusLabel = $statusLabels[$status] ?? $status;
        $currentActionStatusLabel = $allActionStatusLabels[$actionStatus] ?? $actionStatus;
        $currentSiteLabel = in_array($normalizedSiteFilter, ['WIRE', 'PLUS'], true)
            ? $normalizedSiteFilter
            : 'ทุก Site';
        $lastCheckedAt = !empty($compareHealth['last_checked_at'])
            ? \Carbon\Carbon::parse($compareHealth['last_checked_at'])->format('d/m/Y H:i')
            : null;
        $clearFilterUrl = fn(array $keys) => route('deadstock.review', request()->except(array_merge($keys, ['page'])));
        $activeFilterChips = [];
        $monthFilterActive =
            request()->has('month_from') ||
            request()->has('month_to') ||
            request()->has('month_ids') ||
            request()->has('month_id');
        if ($monthFilterActive) {
            $activeFilterChips[] = [
                'label' => 'เดือน',
                'value' => $monthLabel,
                'url' => $clearFilterUrl(['month_from', 'month_to', 'month_ids', 'month_id']),
            ];
        }
        if ($status !== \App\Support\FormWOS\DeadstockCompareStatus::DEFAULT) {
            $activeFilterChips[] = [
                'label' => 'สถานะรายการ',
                'value' => $currentStatusLabel,
                'url' => route('deadstock.review', array_merge(request()->except(['page']), [
                    'status' => \App\Support\FormWOS\DeadstockCompareStatus::DEFAULT,
                ])),
            ];
        }
        if ($actionStatus !== 'all') {
            $activeFilterChips[] = [
                'label' => 'สถานะติดตาม',
                'value' => $currentActionStatusLabel,
                'url' => route(
                    'deadstock.review',
                    array_merge(request()->except(['page']), ['action_status' => 'all']),
                ),
            ];
        }
        if (($siteFilter ?? 'all') !== 'all' && ($siteFilter ?? '') !== '') {
            $activeFilterChips[] = [
                'label' => 'Site',
                'value' => $currentSiteLabel !== 'ทุก Site' ? $currentSiteLabel : $siteFilter,
                'url' => route(
                    'deadstock.review',
                    array_merge(request()->except(['page', 'company']), ['site' => 'all']),
                ),
            ];
        }
        if ($customerFilters->isNotEmpty()) {
            $activeFilterChips[] = [
                'label' => 'ลูกค้า',
                'value' => $customerFilters->implode(', '),
                'url' => $clearFilterUrl(['customer']),
            ];
        }
        if ($reasonFilters->isNotEmpty()) {
            $activeFilterChips[] = [
                'label' => 'สาเหตุ',
                'value' => $reasonFilters->implode(', '),
                'url' => $clearFilterUrl(['reason_code']),
            ];
        }
        if ($codeGroupLabel !== '') {
            $activeFilterChips[] = [
                'label' => 'Code',
                'value' => $codeGroupLabel,
                'url' => $clearFilterUrl(['code_group']),
            ];
        }
        if ($salesDivisionFilters->isNotEmpty()) {
            $activeFilterChips[] = [
                'label' => 'Sales Division',
                'value' => $salesDivisionFilters
                    ->map(fn($division) => $salesDivisionOptions[$division] ?? $division)
                    ->implode(', '),
                'url' => $clearFilterUrl(['sales_division']),
            ];
        }
        if ($serialFilter !== '') {
            $activeFilterChips[] = [
                'label' => 'Serial',
                'value' => $serialFilter,
                'url' => $clearFilterUrl(['serial']),
            ];
        }
        if ($sort !== 'qty_desc') {
            $activeFilterChips[] = [
                'label' => 'เรียง',
                'value' => $sortOptions[$sort] ?? $sort,
                'url' => route('deadstock.review', array_merge(request()->except(['page']), ['sort' => 'qty_desc'])),
            ];
        }
        $hasActiveFilters = count($activeFilterChips) > 0;
    @endphp

    <div class="container-fluid py-3 ds-shell">
        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if (session('success_compare'))
            <div class="alert alert-info">{{ session('success_compare') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger">{{ $errors->first() }}</div>
        @endif

        <section class="ds-toolbar">
            <div class="ds-toolbar-head">
                <div>
                    <div class="text-muted small">
                        รอบที่เลือก: {{ $monthLabel }}
                        @if (!$allMonthsSelected && $selectedMonth?->recv_date)
                            · Snapshot วันที่ {{ $selectedMonth->recv_date->format('Y-m-d') }}
                        @endif
                    </div>
                    <div class="text-muted small mt-1">
                        Snapshot คือสำเนาข้อมูล Deadstock ณ วันที่ส่งเมลรายวัน ระบบนำ Snapshot มาเทียบกับข้อมูลปัจจุบัน
                        เพื่อดูว่ารายการยัง On hand อยู่ เคลียร์แล้ว หรือ Qty/กำหนดส่งเปลี่ยนไป
                    </div>
                    <div class="ds-filter-badges">
                        <span class="ds-filter-badge">เดือน: {{ $monthLabel }}</span>
                        <span class="ds-filter-badge">สถานะรายการ: {{ $currentStatusLabel }}</span>
                        <span class="ds-filter-badge">สถานะติดตาม: {{ $currentActionStatusLabel }}</span>
                        <span class="ds-filter-badge">Site: {{ $currentSiteLabel }}</span>
                        @if ($customerFilters->isNotEmpty())
                            <span class="ds-filter-badge">ลูกค้า: {{ $customerFilters->implode(', ') }}</span>
                        @endif
                        @if ($reasonFilters->isNotEmpty())
                            <span class="ds-filter-badge">สาเหตุ: {{ $reasonFilters->implode(', ') }}</span>
                        @endif
                        @if ($salesDivisionFilters->isNotEmpty())
                            <span class="ds-filter-badge">Sales Division:
                                {{ $salesDivisionFilters->map(fn($division) => $salesDivisionOptions[$division] ?? $division)->implode(', ') }}
                            </span>
                        @endif
                        @if ($lastCheckedAt)
                            <span class="ds-filter-badge">เทียบล่าสุด: {{ $lastCheckedAt }}</span>
                        @else
                            <span class="ds-filter-badge">ยังไม่เคยเทียบข้อมูลปัจจุบัน</span>
                        @endif
                    </div>
                    <div class="ds-help-note">
                        คำที่ใช้ในหน้านี้:
                        Snapshot = ข้อมูลตั้งต้นจากเมลรายวัน,
                        On hand = ของยังค้างในระบบปัจจุบัน,
                        เคลียร์แล้ว = ไม่พบ Serial นั้นเป็น On hand แล้ว,
                        เปลี่ยนแปลง = ยังพบของอยู่แต่ Qty หรือกำหนดส่งไม่ตรงกับ Snapshot,
                        ตัวกรอง = เงื่อนไขที่ใช้จำกัดรายการด้านล่าง
                    </div>
                </div>

                <div class="ds-actions">
                    <div class="ds-action-row">
                        <a class="btn btn-outline-secondary" href="{{ route('deadstock.dashboard') }}">
                            <i class="fa fa-chart-column me-1"></i> ภาพรวม
                        </a>
                        @if ($selectedMonth)
                            @auth
                                <form method="post" action="{{ route('deadstock.review.compare', $selectedMonth) }}">
                                    @csrf
                                    @foreach ($selectedMonthIds as $selectedMonthId)
                                        <input type="hidden" name="month_ids[]" value="{{ $selectedMonthId }}">
                                    @endforeach
                                    <button class="btn btn-primary" type="submit" title="เทียบข้อมูลปัจจุบันตามเดือนที่เลือก">
                                        <i class="fa fa-rotate me-1"></i> เทียบข้อมูลปัจจุบัน
                                    </button>
                                </form>
                                <div class="ds-action-note">
                                    เทียบตามเดือนที่เลือกอยู่ในตัวกรอง: {{ $monthLabel }}
                                </div>
                            @else
                                <a class="btn btn-primary" href="{{ route('login', ['redirect_to' => url()->full()]) }}">
                                    <i class="fa fa-right-to-bracket me-1"></i> เข้าสู่ระบบเพื่อเทียบข้อมูล
                                </a>
                            @endauth
                        @endif
                    </div>
                    <div class="ds-action-note">
                        ระบบนำเข้า Snapshot ล่าสุดและเทียบข้อมูลอัตโนมัติทุก 1 ชั่วโมง พร้อมเก็บ Snapshot สิ้นเดือนเพื่อเทียบ % ที่ลดลงจากเดือนก่อน
                    </div>
                    <div class="ds-action-row">
                        <a class="btn btn-success" href="{{ route('deadstock.review.export', request()->query()) }}">
                            <i class="fa fa-file-excel me-1"></i> Export Excel
                        </a>
                        <a class="btn btn-outline-danger" href="{{ route('deadstock.review.summary_pdf', request()->query()) }}">
                            <i class="fa fa-file-pdf me-1"></i> Summary PDF
                        </a>
                    </div>
                </div>
            </div>

            @auth
                @if (\App\Support\FormWOS\DeadstockSalesAccess::canImportAny(auth()->user()))
                <form class="ds-import-card" method="post" action="{{ route('deadstock.review.import', request()->query()) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="ds-import-title">
                        <span><i class="fa fa-upload me-1"></i> Import Excel</span>
                    </div>
                    <div class="ds-import-controls">
                        <input class="form-control form-control-sm" type="file" name="review_file" accept=".xlsx,.xls,.csv" required>
                        <button class="btn btn-outline-primary btn-sm" type="submit">
                            Import Excel
                        </button>
                        <a class="btn btn-outline-secondary btn-sm" href="{{ route('deadstock.review.import_template') }}">
                            Template Excel
                        </a>
                    </div>
                </form>
                @endif
            @endauth

            <div class="ds-filter-title">
                <span><i class="fas fa-filter me-1"></i> ตัวกรอง</span>
                <span class="text-muted small">ช่วงเดือนใช้วันที่รับเข้า Stock และทุก Filter จะใช้กับหน้าจอ / Excel / PDF เหมือนกัน</span>
            </div>

            <div class="ds-help-note ds-status-note">
                <div><strong>สถานะ</strong> คือสถานะของรายการ Deadstock หลังระบบเทียบ Snapshot กับข้อมูลปัจจุบัน เช่น คงค้าง, เปลี่ยนแปลง, เคลียร์แล้ว</div>
                <div><strong>สถานะการติดตาม</strong> คือสถานะงานที่ผู้ใช้บันทึกไว้ ได้แก่ ยังไม่ได้ติดตาม, กำลังติดตาม รอคำตอบจากลูกค้า และมีกำหนดส่งมอบแล้ว</div>
            </div>

            @if ($hasActiveFilters)
                <div class="ds-active-filters">
                    <span class="small text-muted"><i class="fas fa-filter me-1"></i>ตัวกรองที่ใช้อยู่:</span>
                    @foreach ($activeFilterChips as $chip)
                        <a class="ds-active-filter" href="{{ $chip['url'] }}" title="คลิกเพื่อลบตัวกรองนี้">
                            <span>{{ $chip['label'] }}: {{ $chip['value'] }}</span>
                            <i class="fas fa-xmark"></i>
                        </a>
                    @endforeach
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('deadstock.review') }}"
                        title="ล้างทุกตัวกรอง">
                        <i class="fas fa-eraser me-1"></i>ล้างทั้งหมด
                    </a>
                </div>
            @endif

            <form id="deadstock-filter-form" class="row g-3 align-items-end mt-2" method="get" action="{{ route('deadstock.review') }}">
                <input type="hidden" name="code_group" value="{{ $codeGroup }}">
                <div class="col-12 col-md-6 col-xl-3">
                    <label class="form-label">เดือนที่รับเข้า Stock</label>
                    <div class="ds-month-shortcuts" role="group" aria-label="เลือกช่วงเดือน">
                        <button class="btn btn-sm btn-outline-secondary js-month-shortcut" type="button"
                            data-month-from="{{ $latestMonth }}" data-month-to="{{ $latestMonth }}">ล่าสุด</button>
                        <button class="btn btn-sm btn-outline-secondary js-month-shortcut" type="button"
                            data-month-from="{{ $thirdMonth }}" data-month-to="{{ $latestMonth }}">3 เดือน</button>
                        <button class="btn btn-sm btn-outline-secondary js-month-shortcut" type="button"
                            data-month-from="{{ $oldestMonth }}" data-month-to="{{ $latestMonth }}">ทั้งหมด</button>
                    </div>
                    <div class="ds-month-range">
                        <div>
                            <div class="text-muted small mb-1">จาก</div>
                            <input class="form-control" type="month" name="month_from" value="{{ $monthFrom }}"
                                aria-label="จากเดือน">
                        </div>
                        <div>
                            <div class="text-muted small mb-1">ถึง</div>
                            <input class="form-control" type="month" name="month_to" value="{{ $monthTo }}"
                                aria-label="ถึงเดือน">
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <label class="form-label">สถานะ</label>
                    <select class="form-select ds-filter-select" name="status" data-placeholder="เลือกสถานะ">
                        @foreach ($statusFilterOptions as $value => $label)
                            <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <label class="form-label">สถานะการติดตาม</label>
                    <select class="form-select ds-filter-select" name="action_status" data-placeholder="เลือกสถานะติดตาม">
                        @foreach ($actionStatusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($actionStatus === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <label class="form-label">Site</label>
                    <select class="form-select ds-filter-select" name="site" data-placeholder="เลือก Site">
                        <option value="all" @selected(($siteFilter ?? 'all') === 'all')>ทุก Site</option>
                        @foreach ($siteOptions as $siteValue => $siteLabel)
                            <option value="{{ $siteValue }}" @selected(strtoupper((string) ($siteFilter ?? 'all')) === $siteValue)>{{ $siteLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <label class="form-label">ลูกค้า</label>
                    <select class="form-select ds-filter-select" name="customer[]" multiple data-placeholder="ทุกลูกค้า">
                        @foreach ($customerFilters as $customerName)
                            @if (!$customerOptionValues->contains($customerName))
                                <option value="{{ $customerName }}" selected>{{ $customerName }}</option>
                            @endif
                        @endforeach
                        @foreach ($customerOptions as $customerName)
                            <option value="{{ $customerName }}" @selected($customerFilters->contains($customerName))>{{ $customerName }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <label class="form-label">รหัสสาเหตุ</label>
                    <select class="form-select ds-filter-select" name="reason_code[]" multiple
                        data-placeholder="ทุกสาเหตุ">
                        @foreach ($reasonFilters as $selectedReasonCode)
                            @if (!$reasonOptionCodes->contains($selectedReasonCode))
                                <option value="{{ $selectedReasonCode }}" selected>{{ $selectedReasonCode }}</option>
                            @endif
                        @endforeach
                        @foreach ($reasonOptions as $reasonOption)
                            @php
                                $reasonCode = $reasonOption->deadstock_code;
                                $reasonName = trim((string) $reasonOption->deadstock_desc);
                                $reasonLabel = $reasonName !== '' ? $reasonCode . ' - ' . $reasonName : $reasonCode;
                            @endphp
                            <option value="{{ $reasonCode }}" @selected($reasonFilters->contains($reasonCode))>{{ $reasonLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4 col-xl-3">
                    <label class="form-label">Sales Division</label>
                    <select class="form-select ds-filter-select" name="sales_division[]" multiple
                        data-placeholder="ทุก Sales Division">
                        @foreach ($salesDivisionOptions as $division => $divisionLabel)
                            <option value="{{ $division }}" @selected($salesDivisionFilters->contains($division))>
                                {{ $divisionLabel }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <label class="form-label">Serial no.</label>
                    <input class="form-control" type="search" name="serial" value="{{ $serialFilter }}"
                        placeholder="ค้นหา Serial">
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <label class="form-label">เรียงตาม</label>
                    <select class="form-select ds-filter-select" name="sort" data-placeholder="เลือกการเรียง">
                        @foreach ($sortOptions as $value => $label)
                            <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <button class="btn btn-outline-primary w-100" type="submit">
                        <i class="fas fa-filter me-1"></i> ใช้ตัวกรอง
                    </button>
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <a class="btn btn-outline-secondary w-100" href="{{ route('deadstock.review') }}">
                        <i class="fas fa-rotate-left me-1"></i> ล้าง
                    </a>
                </div>
            </form>
            <div class="ds-quick-filter">
                <span class="fw-semibold small text-muted">ปุ่มลัด</span>
                <button class="btn btn-sm js-quick-filter {{ $normalizedSiteFilter === 'WIRE' ? 'btn-primary' : 'btn-outline-primary' }}"
                    type="button" data-filter-name="site" data-filter-value="WIRE">
                    WIRE
                </button>
                <button class="btn btn-sm js-quick-filter {{ $normalizedSiteFilter === 'PLUS' ? 'btn-primary' : 'btn-outline-primary' }}"
                    type="button" data-filter-name="site" data-filter-value="PLUS">
                    PLUS
                </button>
                <button class="btn btn-sm js-quick-filter {{ $codeGroup === 'FF' ? 'btn-dark' : 'btn-outline-dark' }}"
                    type="button" data-filter-name="code_group" data-filter-value="FF">
                    FF
                </button>
                <button class="btn btn-sm js-quick-filter {{ $codeGroup === 'SS' ? 'btn-dark' : 'btn-outline-dark' }}"
                    type="button" data-filter-name="code_group" data-filter-value="SS">
                    SS
                </button>
                <button class="btn btn-sm btn-outline-warning js-quick-filter" type="button"
                    data-filter-name="action_status" data-filter-value="open">
                    ยังไม่ได้ติดตาม
                </button>
                <button class="btn btn-sm btn-outline-primary js-quick-filter" type="button"
                    data-filter-name="action_status" data-filter-value="waiting_follow_up">
                    กำลังติดตาม รอคำตอบจากลูกค้า
                </button>
                <button class="btn btn-sm btn-outline-success js-quick-filter" type="button"
                    data-filter-name="action_status" data-filter-value="in_progress">
                    มีกำหนดส่งมอบแล้ว
                </button>
                <button class="btn btn-sm btn-outline-secondary js-quick-filter" type="button"
                    data-filter-name="sort" data-filter-value="due_soon">
                    กำหนดส่งใกล้สุด
                </button>
                @if ($hasActiveFilters)
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('deadstock.review') }}"
                        title="ล้างตัวกรองทั้งหมด">
                        <i class="fas fa-xmark me-1"></i> ยกเลิก Filter
                    </a>
                @endif
            </div>
        </section>

        @if (($rawSnapshotCount ?? 0) > 0 && $months->isEmpty())
            <div class="alert alert-warning mb-0">
                พบข้อมูลภาพรวมแล้ว แต่ยังไม่มีรายการสำหรับหน้าตรวจสถานะรายเดือน
            </div>
        @endif

        @if (!empty($compareHealth['all_active']))
            <section class="ds-compare-note {{ !empty($compareHealth['snapshot_is_recent']) ? '' : 'warning' }}">
                <div class="fw-semibold mb-1">
                    ผลเทียบตามตัวกรองปัจจุบัน: รายการยังค้างทั้งหมด
                    {{ number_format((int) ($compareHealth['total'] ?? 0)) }} รายการ
                </div>
                <div class="small">
                    ระบบเทียบกับข้อมูลปัจจุบันแล้วพบ Serial ยัง On hand ครบ จึงขึ้น “ยังค้าง รอส่ง” ทั้งหมด
                    @if (!empty($compareHealth['snapshot_is_recent']))
                        ซึ่งเป็นไปได้เมื่อ Snapshot เพิ่งนำเข้าภายใน
                        {{ number_format((int) ($compareHealth['snapshot_age_days'] ?? 0)) }} วัน
                    @else
                        ถ้า Snapshot เก่าหลายวันแล้วยังเป็นแบบนี้ ควรตรวจสอบ Serial/On hand ปัจจุบัน
                        หรือกดเทียบข้อมูลปัจจุบันอีกครั้ง
                    @endif
                </div>
                <div class="small mt-1">
                    ตรวจแล้ว <span class="metric">{{ number_format((int) ($compareHealth['checked'] ?? 0)) }}</span> /
                    มีงานติดตามที่บันทึกแล้ว <span
                        class="metric">{{ number_format((int) ($compareHealth['reviewed'] ?? 0)) }}</span>
                    @if ($lastCheckedAt)
                        / เทียบล่าสุด {{ $lastCheckedAt }}
                    @endif
                </div>
            </section>
        @endif

        <section class="ds-summary">
            <div class="fw-semibold mb-1">สรุปตามตัวกรองที่เลือก</div>
            <div class="text-muted small mb-2">
                ตัวเลขส่วนนี้เปลี่ยนตามเดือน สถานะ Site ลูกค้า Sales Division สาเหตุ และ Serial ที่เลือกไว้ด้านบน
                @if ($status !== 'all')
                    จึงอาจไม่เท่ากับผลรวมทั้งเดือนหลังเทียบข้อมูล เพราะรายการที่เคลียร์แล้วหรือสถานะอื่นอาจถูกตัวกรองซ่อนไว้
                @endif
            </div>
            <div class="ds-summary-grid">
                <div class="ds-kpi">
                    <div class="ds-kpi-label">รายการ</div>
                    <div class="ds-kpi-value">{{ number_format((int) ($summary->total_items ?? 0)) }}</div>
                </div>
                <div class="ds-kpi">
                    <div class="ds-kpi-label">ยังค้าง รอส่ง</div>
                    <div class="ds-kpi-value">{{ number_format((int) ($summary->active_items ?? 0)) }}</div>
                </div>
                <div class="ds-kpi">
                    <div class="ds-kpi-label">เปลี่ยนแปลง</div>
                    <div class="ds-kpi-value">{{ number_format((int) ($summary->changed_items ?? 0)) }}</div>
                </div>
                <div class="ds-kpi">
                    <div class="ds-kpi-label">เคลียร์แล้ว</div>
                    <div class="ds-kpi-value">{{ number_format((int) ($summary->cleared_items ?? 0)) }}</div>
                </div>
                <div class="ds-kpi">
                    <div class="ds-kpi-label">Qty จาก Snapshot</div>
                    <div class="ds-kpi-value">{{ number_format((float) ($summary->total_qty ?? 0), 2) }}</div>
                </div>
                <div class="ds-kpi">
                    <div class="ds-kpi-label">Qty หลังเทียบล่าสุด</div>
                    <div class="ds-kpi-value">{{ number_format((float) ($summary->current_total_qty ?? 0), 2) }}</div>
                </div>
                <div class="ds-kpi">
                    <div class="ds-kpi-label">ยังไม่ได้ติดตาม</div>
                    <div class="ds-kpi-value">{{ number_format((int) ($summary->not_followed_up_items ?? 0)) }}</div>
                </div>
                <div class="ds-kpi">
                    <div class="ds-kpi-label">กำลังติดตาม รอคำตอบจากลูกค้า</div>
                    <div class="ds-kpi-value">{{ number_format((int) ($summary->waiting_follow_up_items ?? 0)) }}</div>
                </div>
                <div class="ds-kpi">
                    <div class="ds-kpi-label">มีกำหนดส่งมอบแล้ว</div>
                    <div class="ds-kpi-value">{{ number_format((int) ($summary->in_progress_items ?? 0)) }}</div>
                </div>
            </div>
        </section>

        <section class="ds-table">
            <div class="ds-table-wrap">
                <table class="table table-sm table-bordered table-hover align-middle mb-0 ds-grid">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>สถานะ</th>
                            <th>วันที่รับเข้า</th>
                            <th>รายการ</th>
                            <th>Serial no.</th>
                            <th>Qty</th>
                            <th>กำหนดส่งเดิม</th>
                            <th>กำหนดส่งใหม่</th>
                            <th>ลูกค้า / Sales</th>
                            <th>รหัสสาเหตุ</th>
                            <th>รายละเอียดสาเหตุ</th>
                            <th>รายละเอียด</th>
                            <th>แนวทางแก้ไข</th>
                            <th>แนวทางป้องกันการเกิดซ้ำ</th>
                            <th>Remark</th>
                            <th>บันทึก</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $item)
                            @php
                                $review = $item->review;
                                $formId = 'review-' . $item->id;
                                $snapshotQty = (float) $item->snapshot_qty;
                                $currentQty = $item->current_qty !== null ? (float) $item->current_qty : null;
                                $snapshotQtyRounded = round($snapshotQty, 2);
                                $currentQtyRounded = $currentQty !== null ? round($currentQty, 2) : null;
                                $snapshotDue = $item->due_date?->format('Y-m-d');
                                $currentDue = $item->current_due_date?->format('Y-m-d');
                                $qtyChanged =
                                    $item->compare_status === 'changed' &&
                                    $currentQtyRounded !== null &&
                                    $currentQtyRounded !== $snapshotQtyRounded;
                                $dueChanged =
                                    $item->compare_status === 'changed' &&
                                    $currentDue !== null &&
                                    $currentDue !== $snapshotDue;
                                $changeDetails = [];
                                if ($qtyChanged) {
                                    $changeDetails[] =
                                        'Qty: ' .
                                        number_format($snapshotQtyRounded, 2) .
                                        ' -> ' .
                                        number_format($currentQtyRounded, 2);
                                }
                                if ($dueChanged) {
                                    $changeDetails[] = 'กำหนดส่ง: ' . ($snapshotDue ?: '-') . ' -> ' . $currentDue;
                                }
                                if ($item->compare_status === 'changed' && empty($changeDetails)) {
                                    $changeDetails[] = 'ข้อมูลปัจจุบันต่างจาก Snapshot';
                                }
                                $changeTags = [];
                                if ($qtyChanged) {
                                    $changeTags[] = 'Qty เปลี่ยน';
                                }
                                if ($dueChanged) {
                                    $changeTags[] = 'Due date เปลี่ยน';
                                }
                                $reviewStatus = \App\Support\FormWOS\DeadstockReviewStatus::normalize(
                                    $review?->review_status ?? 'open',
                                ) ?? 'open';
                                $serialNumber = trim((string) $item->serialnumber);
                                $transactionNumber = trim((string) $item->transaction_number);
                                $showTransactionNumber =
                                    $transactionNumber !== '' && strcasecmp($transactionNumber, $serialNumber) !== 0;
                                $liveReasonCode = trim(
                                    (string) ($item->latestCompareLog?->matched_deadstock_code ?? ''),
                                );
                                $reasonCode = $liveReasonCode !== '' ? $liveReasonCode : $item->deadstock_code;
                                $reasonDescription = \App\Support\FormWOS\DeadstockReasonMap::description(
                                    $reasonCode,
                                    $item->deadstock_desc,
                                );
                                $reasonIsFromLive =
                                    $liveReasonCode !== '' && $liveReasonCode !== (string) $item->deadstock_code;
                                $itemSiteLabel = stripos((string) $item->company, 'PLUS') !== false ? 'PLUS' : 'WIRE';
                                $canManageItem = \App\Support\FormWOS\DeadstockSalesAccess::canManage(
                                    auth()->user(),
                                    $item->salesperson_name,
                                );
                            @endphp
                            <tr>
                                <td><span class="ds-row-no">{{ $items->firstItem() + $loop->index }}</span></td>
                                <td>
                                    <span class="ds-status {{ $item->compare_status }}">
                                        {{ $statusLabels[$item->compare_status] ?? ucfirst($item->compare_status) }}
                                    </span>
                                    <div class="ds-note mt-2">
                                        @if ($item->last_checked_at)
                                            เทียบล่าสุด {{ $item->last_checked_at->format('Y-m-d H:i') }}
                                        @else
                                            ยังไม่เทียบปัจจุบัน
                                        @endif
                                    </div>
                                    @if (!empty($changeDetails))
                                        <div class="ds-change-list">
                                            @foreach ($changeDetails as $changeDetail)
                                                <div class="ds-change">{{ $changeDetail }}</div>
                                            @endforeach
                                        </div>
                                    @endif
                                    @if (!empty($changeTags))
                                        <div class="ds-change-tags">
                                            @foreach ($changeTags as $changeTag)
                                                <span class="ds-change-tag">{{ $changeTag }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td>{{ $item->purchase_date?->format('Y-m-d') ?? '-' }}</td>
                                <td>
                                    <div class="ds-cell-strong">{{ $item->part_description ?: $item->partnumber ?: '-' }}
                                    </div>
                                    <div class="ds-item-meta">
                                        {{ $item->partnumber ?: '-' }}
                                    </div>
                                </td>
                                <td>
                                    <div class="ds-cell-strong">{{ $item->serialnumber ?: '-' }}</div>
                                    @if ($showTransactionNumber)
                                        <div class="ds-item-meta">{{ $transactionNumber }}</div>
                                    @endif
                                </td>
                                <td>
                                    <div class="ds-cell-strong">{{ number_format((float) $item->snapshot_qty, 2) }}</div>
                                    @if ($item->current_qty !== null)
                                        <div @class(['ds-note', 'ds-change-value' => $qtyChanged])>
                                            ปัจจุบัน {{ number_format((float) $item->current_qty, 2) }}
                                            @if ($qtyChanged)
                                                <div>ต่าง {{ number_format($currentQtyRounded - $snapshotQtyRounded, 2) }}
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div class="ds-cell-strong">{{ $item->due_date?->format('Y-m-d') ?? '-' }}</div>
                                    @if ($item->current_due_date)
                                        <div @class(['ds-note', 'ds-change-value' => $dueChanged])>
                                            ปัจจุบัน {{ $item->current_due_date->format('Y-m-d') }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <input form="{{ $formId }}" class="form-control ds-input" type="date"
                                        name="revised_due_date"
                                        @disabled(!$canManageItem)
                                        value="{{ old('revised_due_date', $review?->revised_due_date?->format('Y-m-d')) }}">
                                </td>
                                <td class="ds-customer-cell">
                                    <div class="ds-cell-strong">{{ $item->customer_name ?: '-' }}</div>
                                    <div class="ds-item-meta">{{ \App\Support\FormWOS\DeadstockSalesMap::label($item->salesperson_name) }}</div>
                                </td>
                                <td>
                                    <div class="fw-semibold">{{ $reasonCode ?: '-' }}</div>
                                    <div class="ds-item-meta">
                                        Site: {{ $itemSiteLabel }}
                                        @if ($reasonIsFromLive)
                                            · ปัจจุบัน
                                        @endif
                                    </div>
                                </td>
                                <td>{{ $reasonDescription ?: '-' }}</td>
                                <td>
                                    <textarea form="{{ $formId }}" class="form-control ds-textarea" name="review_detail" rows="3" @disabled(!$canManageItem)>{{ old('review_detail', $review?->review_detail) }}</textarea>
                                </td>
                                <td>
                                    <textarea form="{{ $formId }}" class="form-control ds-textarea" name="corrective_action" rows="3" @disabled(!$canManageItem)>{{ old('corrective_action', $review?->corrective_action) }}</textarea>
                                </td>
                                <td>
                                    <textarea form="{{ $formId }}" class="form-control ds-textarea" name="preventive_action" rows="3" @disabled(!$canManageItem)>{{ old('preventive_action', $review?->preventive_action) }}</textarea>
                                </td>
                                <td>
                                    <textarea form="{{ $formId }}" class="form-control ds-textarea" name="sales_remark" rows="3" @disabled(!$canManageItem)>{{ old('sales_remark', $review?->sales_remark) }}</textarea>
                                </td>
                                <td>
                                    @auth
                                        @if ($canManageItem)
                                            <button type="button" class="btn btn-outline-primary ds-review-btn"
                                                data-bs-toggle="modal" data-bs-target="#review-modal-{{ $item->id }}">
                                                <i class="fa fa-pen-to-square me-1"></i> บันทึกงาน
                                            </button>
                                        @else
                                            <span class="badge text-bg-light">ดูได้อย่างเดียว</span>
                                        @endif
                                    @else
                                        <a class="btn btn-outline-primary ds-review-btn"
                                            href="{{ route('login', ['redirect_to' => url()->full()]) }}">
                                            <i class="fa fa-right-to-bracket me-1"></i> เข้าสู่ระบบเพื่อบันทึก
                                        </a>
                                    @endauth
                                    <div class="ds-note mt-2">
                                        <span
                                            class="ds-action-status">{{ \App\Support\FormWOS\DeadstockReviewStatus::label($reviewStatus) }}</span>
                                    </div>
                                    <div class="ds-note ds-review-meta mt-2">
                                        @if ($review?->next_follow_up_date)
                                            ตามต่อ {{ $review->next_follow_up_date->format('Y-m-d') }}
                                        @elseif ($review?->reviewed_at)
                                            บันทึก {{ $review->reviewed_at->format('Y-m-d H:i') }}
                                        @else
                                            ยังไม่ได้ติดตาม
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @auth
                                @if ($canManageItem)
                                <div class="modal fade" id="review-modal-{{ $item->id }}" tabindex="-1"
                                    aria-hidden="true">
                                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                        <div class="modal-content">
                                            <form method="post" action="{{ route('deadstock.review.save', $item) }}">
                                                @csrf
                                                <div class="modal-header">
                                                    <div>
                                                        <h5 class="modal-title mb-1">บันทึกการติดตาม</h5>
                                                        <div class="text-muted small">
                                                            {{ $item->part_description ?: $item->partnumber ?: '-' }}
                                                            · {{ $item->serialnumber ?: $item->transaction_number ?: '-' }}
                                                        </div>
                                                    </div>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"
                                                        aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body">
                                                    @if (!empty($changeDetails))
                                                        <div class="alert alert-warning py-2">
                                                            <div class="fw-semibold mb-1">เปลี่ยนจาก Snapshot</div>
                                                            @foreach ($changeDetails as $changeDetail)
                                                                <div>{{ $changeDetail }}</div>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                    <div class="row g-3">
                                                        <div class="col-12 col-md-4">
                                                            <label class="form-label">กำหนดส่งใหม่</label>
                                                            <input class="form-control" type="date"
                                                                name="revised_due_date"
                                                                value="{{ old('revised_due_date', $review?->revised_due_date?->format('Y-m-d')) }}">
                                                            <label class="form-check mt-2 small">
                                                                <input type="hidden" name="no_revised_due" value="0">
                                                                <input
                                                                    class="form-check-input js-no-revised-due"
                                                                    type="checkbox"
                                                                    name="no_revised_due" value="1">
                                                                <span class="form-check-label">ล้างกำหนดส่งใหม่</span>
                                                            </label>
                                                        </div>
                                                        <div class="col-12 col-md-4">
                                                            <label class="form-label">สถานะการติดตาม</label>
                                                            <select class="form-select" name="review_status">
                                                                @foreach ($actionStatusLabels as $value => $label)
                                                                    <option value="{{ $value }}"
                                                                        @selected($reviewStatus === $value)>{{ $label }}
                                                                    </option>
                                                                @endforeach
                                                            </select>
                                                        </div>
                                                        <div class="col-12 col-md-4">
                                                            <label class="form-label">วันติดตามถัดไป</label>
                                                            <input class="form-control" type="date"
                                                                name="next_follow_up_date"
                                                                value="{{ old('next_follow_up_date', $review?->next_follow_up_date?->format('Y-m-d')) }}">
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label">ลูกค้า / Sales</label>
                                                            <div class="form-control bg-light">
                                                                {{ $item->customer_name ?: '-' }} /
                                                                {{ \App\Support\FormWOS\DeadstockSalesMap::label($item->salesperson_name) }}</div>
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label">รายละเอียด</label>
                                                            <textarea class="form-control" name="review_detail" rows="4">{{ old('review_detail', $review?->review_detail) }}</textarea>
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label">แนวทางแก้ไข</label>
                                                            <textarea class="form-control" name="corrective_action" rows="4">{{ old('corrective_action', $review?->corrective_action) }}</textarea>
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label">แนวทางป้องกันการเกิดซ้ำ</label>
                                                            <textarea class="form-control" name="preventive_action" rows="4">{{ old('preventive_action', $review?->preventive_action) }}</textarea>
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label">Remark</label>
                                                            <textarea class="form-control" name="sales_remark" rows="3">{{ old('sales_remark', $review?->sales_remark) }}</textarea>
                                                        </div>
                                                        <div class="col-12">
                                                            <div class="text-muted small">
                                                                @if ($review?->reviewed_at)
                                                                    บันทึกล่าสุด
                                                                    {{ $review->reviewed_at->format('Y-m-d H:i') }}
                                                                    @if ($review?->reviewer)
                                                                        โดย {{ $review->reviewer->name }}
                                                                    @endif
                                                                @else
                                                                    ยังไม่มีประวัติการติดตาม
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-outline-secondary"
                                                        data-bs-dismiss="modal">ปิด</button>
                                                    <button class="btn btn-success" type="submit">
                                                        <i class="fa fa-floppy-disk me-1"></i> บันทึกการติดตาม
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                @endif
                            @endauth
                        @empty
                            <tr>
                                <td colspan="16" class="text-center text-muted py-4">
                                    ยังไม่มีรายการ Snapshot สำหรับเดือนนี้
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        @if ($items->hasPages())
            <div>{{ $items->links() }}</div>
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (window.TomSelect) {
                document.querySelectorAll('.ds-filter-select').forEach(function(el) {
                    if (el.tomselect) return;

                    new TomSelect(el, {
                        plugins: el.multiple ? ['remove_button'] : [],
                        create: false,
                        persist: false,
                        placeholder: el.dataset.placeholder || '',
                        maxOptions: 1000,
                        dropdownParent: 'body',
                        allowEmptyOption: !el.multiple,
                    });
                });
            }

            const filterForm = document.getElementById('deadstock-filter-form');
            const setFilterValue = function(name, value) {
                const field = filterForm?.elements.namedItem(name);
                if (!field) return;

                if (field.tomselect) {
                    field.tomselect.setValue(value, true);
                } else {
                    field.value = value;
                }
            };

            document.querySelectorAll('.js-month-shortcut').forEach(function(button) {
                button.addEventListener('click', function() {
                    setFilterValue('month_from', button.dataset.monthFrom || '');
                    setFilterValue('month_to', button.dataset.monthTo || '');
                    filterForm?.requestSubmit();
                });
            });

            document.querySelectorAll('.js-quick-filter').forEach(function(button) {
                button.addEventListener('click', function() {
                    setFilterValue(button.dataset.filterName, button.dataset.filterValue || '');
                    filterForm?.requestSubmit();
                });
            });

            document.querySelectorAll('.js-no-revised-due').forEach(function(checkbox) {
                const modal = checkbox.closest('.modal');
                if (!modal) return;

                const revisedDue = modal.querySelector('input[name="revised_due_date"]');

                const syncNoRevisedDue = function() {
                    if (!revisedDue) return;

                    if (checkbox.checked) {
                        revisedDue.value = '';
                        revisedDue.disabled = true;
                    } else {
                        revisedDue.disabled = false;
                    }
                };

                checkbox.addEventListener('change', syncNoRevisedDue);
                syncNoRevisedDue();
            });
        });
    </script>
@endpush
