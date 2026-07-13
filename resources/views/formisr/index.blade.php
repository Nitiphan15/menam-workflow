{{-- resources/views/inspection/index.blade.php --}}
@extends('layouts.layout')

@section('page-title', 'Inspection Report')
@section('title', 'Inspection Report')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/inspection.css') }}">
@endpush

@section('content')

    @php
        $canManual = auth()->check() && auth()->user()->deptRoles()->where('code', 'ISR')->exists();

        $vmHeader = $vm['header'] ?? [];
        $woId = $vmHeader['wo_id'] ?? '';
        $stepSeq = $vmHeader['step_seq'] ?? '';
    @endphp
    <div class="container-xxl py-3">
        {{-- Search --}}
        <div class="card shadow-sm border-0 report-card mb-4">
            <div class="card-body">

                <div class="report-section-title mb-2">Search</div>
                <form method="get" action="{{ route('isr.index') }}" class="row g-2 align-items-end">
                    <div class="col-md-3 col-sm-6">
                        <label class="report-label">MFG No</label>
                        <input type="text" name="mfg_no" class="form-control form-control-sm"
                            value="{{ old('mfg_no', $mfgNo ?? '') }}" placeholder="เช่น W2500001">
                    </div>
                    <div class="col-md-2 col-sm-4">
                        <button class="btn btn-primary btn-sm w-100">
                            ค้นหา
                        </button>
                    </div>
                    @if (!empty($mfgNo))
                        <div class="col-auto">
                            <span class="badge bg-light text-secondary">
                                กำลังแสดงผลสำหรับ: <strong>{{ $mfgNo }}</strong>
                            </span>
                        </div>
                    @endif

                    <small class="text-muted">ค้นหาด้วย MFG No เพื่อแสดงผลการตรวจสอบ</small>
                </form>
            </div>
        </div>


        @if (empty($mfgNo))
            <div class="alert alert-info border-0 shadow-sm report-card">
                ใส่ <strong>MFG No</strong> แล้วกดค้นหาเพื่อดูรายงานการตรวจสอบ
            </div>
        @elseif (!$vm)
            <div class="alert alert-warning border-0 shadow-sm report-card">
                ไม่พบข้อมูลสำหรับ MFG No <strong>{{ $mfgNo }}</strong>
            </div>
        @else
            @php
                $header = $vm['header'] ?? [];
                $workTests = $vm['work_tests'] ?? [];
                $decision = !empty($vm['manual_decision']['decision'])
                    ? $vm['manual_decision']['decision']
                    : ($vm['summary_overall']['pass'] ?? 0
                        ? 'PASS'
                        : 'NG');
                $pass = strtoupper($decision) === 'PASS';

                $hasManual = !empty($vm['manual_decision']['decision']);
                $autoPass = (int) ($vm['summary_overall']['pass'] ?? 0) === 1;
                $autoDecision = $autoPass ? 'PASS' : 'NG';

                // ✅ decision ที่ใช้แสดง overview (manual มาก่อน)
                $decision = $hasManual ? $vm['manual_decision']['decision'] : $autoDecision;
                $pass = strtoupper($decision) === 'PASS';
                $testsToShow = $vm['tests_to_show'] ?? [];
                $summaryCols = $vm['summary_cols'] ?? [];
                $summaryLabels = $vm['summary_labels'] ?? [];
                $summaryOverall = $vm['summary_overall'] ?? ['pass' => 0, 'ng' => 0, 'remark' => null];

                // ✅ คอลัมน์ทั้งหมดจาก controller
                $allDisplayCols = $vm['display_cols'] ?? [];

                // ✅ ใช้ตัวเดียวทั้งหน้า (Spec + QA + Summary)
                $displayCols = collect($allDisplayCols)
                    ->filter(function ($col) use ($testsToShow) {
                        $key = $col['key'] ?? null;
                        if (!$key) {
                            return true;
                        }
                        if (!array_key_exists($key, $testsToShow)) {
                            return true;
                        }
                        return (bool) $testsToShow[$key];
                    })
                    ->values();

                $maxCols = $displayCols->count();
                $wtCols = collect($workTests)->values();
                $qaRows = $vm['qawip_rows'] ?? [];
                $rowCount = is_countable($qaRows) ? count($qaRows) : 0;

            @endphp

            {{-- Header section (Overview) --}}
            <div class="card shadow-sm border-0 report-card mb-4">
                <div class="card-body">
                    <div class="report-section-title mb-3">Overview</div>
                    <div class="row g-3 align-items-center">
                        <div class="col-md-4">
                            <div class="report-label">MFG No</div>
                            <div class="fw-semibold text-primary">
                                {{ $header['mfg_no'] ?? '-' }}
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="report-label">Brand name</div>
                            <div class="fw-semibold">
                                {{ $header['brand_name'] ?? '-' }}
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="report-label">Customer</div>
                            <div class="fw-semibold">
                                {{ $header['customer_name'] ?? '-' }}
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="report-label">Quantity (kg)</div>
                            <div class="fw-semibold">
                                {{ number_format($header['qty_kg'] ?? 0, 0) }}
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="report-label">Step used</div>
                            <div class="fw-semibold">
                                {{ $header['step_label'] ?? ($header['workcenter'] ?? '-') }}
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="report-label mb-1">Overall decision</div>
                            @php
                                $pass = strtoupper($decision) === 'PASS';
                            @endphp
                            <span
                                class="status-pill {{ $pass ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-danger-subtle text-danger border border-danger-subtle' }} fw-semibold">
                                {{ $pass ? 'PASS' : 'NG' }}
                            </span>
                        </div>

                        @if (!empty($vm['header']['remark']) || !empty($vm['header']['step_detail']))
                            <div class="mt-3">
                                @if (!empty($vm['header']['remark']))
                                    <div class="mb-2">
                                        <div class="text-muted small">หมายเหตุ</div>
                                        <div class="fw-semibold">{!! nl2br(e($vm['header']['remark'])) !!}</div>
                                    </div>
                                @endif

                                @if (!empty($vm['header']['step_detail']))
                                    <div>
                                        <div class="text-muted small">รายละเอียดขั้นตอน</div>
                                        <div class="fw-semibold">{!! nl2br(e($vm['header']['step_detail'])) !!}</div>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ========= SECTION 1 : WORKORDER TEST ========= --}}
            <div class="card shadow-sm border-0 report-card mb-4">
                <div class="card-body">
                    <div class="report-section-title mb-3">
                        WORKORDER TEST / PROCESS CONDITION
                    </div>

                    @if ($wtCols->isEmpty() || $maxCols === 0)
                        <div class="text-muted small">
                            ไม่มีรายการตรวจในขั้นตอนนี้ (ตามหมายเหตุ/รายละเอียด) หรือไม่พบ Workorder Test
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered table-report align-middle mb-0 table-spec">
                                <colgroup>
                                    <col class="col-meta">
                                    @for ($c = 0; $c < $maxCols; $c++)
                                        <col class="col-spec">
                                    @endfor
                                </colgroup>
                                <thead class="table-light">
                                    <tr>
                                        <th style="min-width:160px;">&nbsp;</th>
                                        @for ($c = 0; $c < $maxCols; $c++)
                                            <th class="text-center" style="width:70px;">
                                                {{ $c + 1 }}
                                            </th>
                                        @endfor
                                    </tr>
                                </thead>
                                <tbody>

                                    {{-- รายการตรวจสอบ --}}
                                    <tr>
                                        <th>รายการตรวจสอบ</th>
                                        @for ($c = 0; $c < $maxCols; $c++)
                                            <td class="text-center">{{ $displayCols[$c]['label'] ?? '' }}</td>
                                        @endfor
                                    </tr>

                                    {{-- ค่ามาตรฐาน --}}
                                    <tr>
                                        <th>ค่ามาตรฐาน</th>
                                        @for ($c = 0; $c < $maxCols; $c++)
                                            <td class="text-center">{{ $displayCols[$c]['standard'] ?? '' }}</td>
                                        @endfor
                                    </tr>

                                    {{-- Usl / Lsl --}}
                                    <tr>
                                        <th>Upper spec limit</th>
                                        @for ($c = 0; $c < $maxCols; $c++)
                                            <td class="text-center">{{ $displayCols[$c]['usl'] ?? '' }}</td>
                                        @endfor
                                    </tr>
                                    <tr>
                                        <th>Lower spec limit</th>
                                        @for ($c = 0; $c < $maxCols; $c++)
                                            <td class="text-center">{{ $displayCols[$c]['lsl'] ?? '' }}</td>
                                        @endfor
                                    </tr>

                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            {{-- ========= SECTION 2 : QA WIP MEASUREMENT ========= --}}
            <div class="card shadow-sm border-0 report-card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="report-section-title mb-0">
                            QA WIP Measurement Result
                        </div>
                    </div>

                    @if ($rowCount === 0 || $maxCols === 0)
                        <div class="text-muted small">
                            ยังไม่มีผลตรวจ QA WIP สำหรับใบงาน / Step นี้ หรือไม่มีรายการตรวจสำหรับขั้นตอนนี้
                        </div>
                    @else
                        <div class="table-responsive qa-scroll mt-2">
                            <table class="qa-table">
                                <colgroup>
                                    <col style="width: 56px"> {{-- # --}}
                                    <col style="width: 120px"> {{-- Serial --}}
                                    <col style="width: 170px"> {{-- Inspected --}}
                                    <col style="width: 160px"> {{-- Inspector --}}
                                    @foreach ($displayCols as $col)
                                        <col style="width: 140px"> {{-- ค่าที่วัด (ขนาด/ความยาว/Tensile/...) --}}
                                    @endforeach
                                </colgroup>

                                <thead class="qa-thead-sticky">
                                    <tr>
                                        <th class="col-num">#</th>
                                        <th>Serial (Coil)</th>
                                        <th>Inspected</th>
                                        <th>Inspector</th>
                                        @foreach ($displayCols as $col)
                                            <th class="col-right">{{ $col['label'] }}</th>
                                        @endforeach
                                    </tr>
                                </thead>


                                @php
                                    $cols = $displayCols;

                                    // หา sample fills ครั้งเดียว
                                    $fills = [
                                        'tensile' => null,
                                        'elongation' => null,
                                        'hardness_avg' => null,
                                        'hardness_pts' => [],
                                    ];

                                    foreach ($qaRows as $__r) {
                                        if ($fills['tensile'] === null) {
                                            $raw = $__r->tensile ?? ($__r->ts ?? ($__r->t3 ?? ($__r->qt3 ?? null)));
                                            if ($raw !== null && $raw !== '' && is_numeric($raw)) {
                                                $fills['tensile'] = (float) $raw;
                                            }
                                        }

                                        if ($fills['elongation'] === null) {
                                            $raw = $__r->elongation ?? ($__r->el ?? null);
                                            if ($raw !== null && $raw !== '' && is_numeric($raw)) {
                                                $fills['elongation'] = (float) $raw;
                                            }
                                        }

                                        if ($fills['hardness_avg'] === null) {
                                            $raw = $__r->hardness_avg ?? null;
                                            if ($raw !== null && $raw !== '' && is_numeric($raw)) {
                                                $fills['hardness_avg'] = (float) $raw;
                                            }
                                        }

                                        if (!$fills['hardness_pts']) {
                                            $pts = $__r->hardness_points ?? [];
                                            if (is_array($pts) && count($pts)) {
                                                $fills['hardness_pts'] = $pts;
                                            }
                                        }

                                        if (
                                            $fills['tensile'] !== null &&
                                            $fills['elongation'] !== null &&
                                            $fills['hardness_avg'] !== null &&
                                            $fills['hardness_pts']
                                        ) {
                                            break;
                                        }
                                    }

                                    // helper: format
                                    $fmtNum = function ($v, $dec) {
                                        return is_numeric($v) ? number_format((float) $v, (int) $dec) : $v ?? '';
                                    };

                                    // helper: resolve value per key (รวม logic พิเศษไว้ที่เดียว)
                                    $resolveVal = function ($r, $key) use (&$fills) {
                                        $val = $r->{$key} ?? null;

                                        if ($key === 'tensile') {
                                            $raw = $val ?? ($r->ts ?? ($r->tensile ?? ($r->t3 ?? ($r->qt3 ?? null))));
                                            if ($raw !== null && $raw !== '' && is_numeric($raw)) {
                                                $fills['tensile'] = (float) $raw; // update fill
                                                return $fills['tensile'];
                                            }
                                            return $fills['tensile'];
                                        }

                                        if ($key === 'elongation') {
                                            $raw = $val ?? ($r->el ?? ($r->elongation ?? null));
                                            if ($raw !== null && $raw !== '' && is_numeric($raw)) {
                                                $fills['elongation'] = (float) $raw;
                                                return $fills['elongation'];
                                            }
                                            return $fills['elongation'];
                                        }

                                        if ($key === 'size') {
                                            return $val ?? ($r->dia ?? ($r->size ?? null));
                                        }

                                        return $val;
                                    };
                                @endphp
                                <tbody>
                                    @foreach ($qaRows as $i => $r)
                                        <tr>
                                            <td class="col-num">{{ $i + 1 }}</td>
                                            <td class="truncate">{{ $r->coil ?? '' }}</td>

                                            <td>
                                                @php $t = $r->inspectedtime ?? null; @endphp
                                                @if ($t)
                                                    <div class="dt">
                                                        {{ \Illuminate\Support\Carbon::parse($t)->format('d/m/Y') }}
                                                        <small>{{ \Illuminate\Support\Carbon::parse($t)->format('H:i') }}</small>
                                                    </div>
                                                @else
                                                    -
                                                @endif
                                            </td>

                                            <td class="truncate">{{ $r->inspector_name ?? '' }}</td>

                                            @foreach ($displayCols as $col)
                                                @php
                                                    $key = $col['key'];
                                                    $dec = $col['decimals'] ?? 3;
                                                    $val = $resolveVal($r, $key);
                                                @endphp

                                                <td class="col-right">
                                                    @if ($key === 'size' && !empty($r->size_points) && is_array($r->size_points))
                                                        @php
                                                            $avg = $r->size_avg ?? ($r->size ?? null);
                                                            $pts = $r->size_points ?? [];
                                                        @endphp

                                                        <div class="text-end fw-semibold">
                                                            {{ is_numeric($avg) ? number_format((float) $avg, 3) : '' }}
                                                        </div>

                                                        <div class="d-flex flex-wrap justify-content-end gap-1 mt-1">
                                                            @foreach (['P1', 'P2', 'P3', 'P4', 'P5'] as $pl)
                                                                @php $pv = $pts[$pl] ?? null; @endphp
                                                                <span class="badge rounded-pill text-bg-light border">
                                                                    {{ $pl }}
                                                                    {{ is_numeric($pv) ? number_format((float) $pv, 3) : '-' }}
                                                                </span>
                                                            @endforeach
                                                        </div>
                                                    @elseif ($key === 'hardness')
                                                        @php
                                                            $avg = is_numeric($r->hardness_avg ?? null)
                                                                ? $r->hardness_avg
                                                                : $fills['hardness_avg'];
                                                            $pts =
                                                                !empty($r->hardness_points) &&
                                                                is_array($r->hardness_points)
                                                                    ? $r->hardness_points
                                                                    : $fills['hardness_pts'];
                                                        @endphp

                                                        <div class="text-end fw-semibold">
                                                            {{ is_numeric($avg) ? number_format((float) $avg, 2) : '' }}
                                                        </div>

                                                        <div class="d-flex flex-wrap justify-content-end gap-1 mt-1">
                                                            @foreach (['point1' => 'P1', 'point2' => 'P2', 'point3' => 'P3', 'point4' => 'P4', 'point5' => 'P5'] as $pf => $pl)
                                                                @php $pv = $pts[$pf] ?? null; @endphp
                                                                <span class="badge rounded-pill text-bg-light border">
                                                                    {{ $pl }}
                                                                    {{ is_numeric($pv) ? number_format((float) $pv, 2) : '-' }}
                                                                </span>
                                                            @endforeach
                                                        </div>
                                                    @else
                                                        {{ $fmtNum($val, $dec) }}
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>

                            </table>
                        </div>
                    @endif

                    @if (!empty($summaryCols))
                        @php
                            $hasOkTextMap = [];
                            foreach ($qaRows as $r) {
                                foreach ($displayCols as $c) {
                                    $k = $c['key'] ?? null;
                                    if (!$k) {
                                        continue;
                                    }

                                    $v = $r->{$k} ?? null;
                                    if ($v === null || $v === '') {
                                        continue;
                                    }

                                    $t = strtoupper(trim((string) $v));
                                    if (in_array($t, ['OK', 'ปกติ', 'NORMAL', 'PASS'], true)) {
                                        $hasOkTextMap[$k] = true;
                                    }
                                }
                            }

                            $zeroOkMap = $displayCols
                                ->mapWithKeys(fn($c) => [$c['key'] ?? '' => (bool) ($c['zero_is_ok'] ?? false)])
                                ->all();
                            $decMap = $displayCols
                                ->mapWithKeys(fn($c) => [$c['key'] ?? '' => (int) ($c['decimals'] ?? 3)])
                                ->all();

                            $fmtSummary = function ($key, $v) use ($decMap, $zeroOkMap, $hasOkTextMap) {
                                $dec = $decMap[$key] ?? 3;
                                $zeroIsOk = (bool) ($zeroOkMap[$key] ?? false);
                                $hasOkTxt = (bool) ($hasOkTextMap[$key] ?? false);

                                if (($zeroIsOk || $hasOkTxt) && is_numeric($v) && (float) $v == 0.0) {
                                    return 'ปกติ';
                                }
                                return is_numeric($v) ? number_format((float) $v, $dec) : '-';
                            };
                        @endphp


                        <div class="mt-3">
                            <table class="table table-sm table-bordered mt-3">
                                <thead>
                                    <tr class="text-center">
                                        <th style="width:220px;">รายการ</th>
                                        <th>ค่าเฉลี่ย</th>
                                        <th>ค่าสูงสุด</th>
                                        <th>ค่าต่ำสุด</th>
                                        <th>ค่าเบี่ยงเบน</th>
                                        <th style="width:90px;">การตัดสิน</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    @foreach ($summaryCols as $key => $s)
                                        @php
                                            $label = $summaryLabels[$key] ?? $key;
                                            $dec = $decMap[$key] ?? 3;
                                            $zeroIsOk = (bool) ($zeroOkMap[$key] ?? false);
                                            $hasOkTxt = (bool) ($hasOkTextMap[$key] ?? false);

                                            $fmt = function ($v) use ($dec, $zeroIsOk, $hasOkTxt) {
                                                // ✅ ถ้าเป็น defect-like (รู้จาก config หรือเดาจาก text) และสรุปเป็น 0 → แสดง "ปกติ"
                                                if (($zeroIsOk || $hasOkTxt) && is_numeric($v) && (float) $v == 0.0) {
                                                    return 'ปกติ';
                                                }
                                                return is_numeric($v) ? number_format((float) $v, $dec) : '-';
                                            };

                                            $d = strtoupper((string) ($s['decide'] ?? ''));
                                            $badge =
                                                $d === 'PASS' || $d === 'OK'
                                                    ? 'text-bg-success'
                                                    : ($d === 'NG'
                                                        ? 'text-bg-danger'
                                                        : 'text-bg-secondary');
                                        @endphp

                                        <tr>
                                            <td class="fw-semibold">{{ $label }}</td>
                                            <td class="text-end">{{ $fmt($s['avg'] ?? null) }}</td>
                                            <td class="text-end">{{ $fmt($s['max'] ?? null) }}</td>
                                            <td class="text-end">{{ $fmt($s['min'] ?? null) }}</td>
                                            <td class="text-end">{{ $fmt($s['stdev'] ?? null) }}</td>
                                            <td class="text-center fw-semibold">
                                                <span class="badge {{ $badge }}">{{ $d ?: '-' }}</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @php
                        $overallPass = ($vm['summary_overall']['pass'] ?? 0) == 1;
                        $ngReasons = $vm['ng_reasons'] ?? [];

                        $ngTipLines = [];
                        foreach ($ngReasons as $r) {
                            $avg = is_numeric($r['avg'] ?? null)
                                ? number_format((float) $r['avg'], 3)
                                : $r['avg'] ?? '-';

                            $specTxt = [];
                            if (($r['std'] ?? null) !== null && $r['std'] !== '') {
                                $specTxt[] = "STD: {$r['std']}";
                            }
                            if (($r['lsl'] ?? null) !== null && $r['lsl'] !== '') {
                                $specTxt[] = "LSL: {$r['lsl']}";
                            }
                            if (($r['usl'] ?? null) !== null && $r['usl'] !== '') {
                                $specTxt[] = "USL: {$r['usl']}";
                            }
                            $specTxt = $specTxt ? implode(' | ', $specTxt) : 'No spec';

                            $ngTipLines[] = "{$r['label']} => {$r['decide']} (AVG {$avg}) [{$specTxt}]";
                        }

                        // ✅ tooltip แบบขึ้นบรรทัดจริง
                        $ngTooltip = $ngTipLines ? implode("\n", $ngTipLines) : 'Auto NG (reason not found)';

                        $manualRow = $vm['manual_decision'] ?? null;
                        $manualByName = $manualRow['by_name'] ?? ($manualRow['decided_by_name'] ?? null);
                        $manualAt = $manualRow['at'] ?? ($manualRow['decided_at'] ?? null);

                        $hist = $vm['manual_history'] ?? [];
                        $hasHist = is_array($hist)
                            ? count($hist) > 0
                            : (method_exists($hist, 'count')
                                ? $hist->count() > 0
                                : !empty($hist));
                    @endphp

                    <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            {{-- Auto badge --}}
                            <span class="badge {{ $autoPass ? 'text-bg-success' : 'text-bg-danger' }}"
                                @if (!$autoPass) data-bs-toggle="tooltip"
                                data-bs-placement="top"
                                data-bs-html="false"
                                title="{{ $ngTooltip }}"
                                style="cursor: help; white-space: pre-line;" @endif>
                                {{ $autoPass ? 'PASS' : 'NG' }}
                            </span>
                            <span class="badge text-bg-light border">Source: Auto</span>

                            {{-- Final decision (manual > auto) --}}
                            <span class="badge {{ $pass ? 'text-bg-success' : 'text-bg-danger' }}">
                                Final: {{ $pass ? 'PASS' : 'NG' }}
                            </span>
                            <span class="badge text-bg-light border">
                                Source: {{ $hasManual ? 'Manual' : 'Auto' }}
                            </span>
                        </div>

                        <div class="ms-auto d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal"
                                data-bs-target="#manualHistoryModal" {{ $hasHist ? '' : 'disabled' }}>
                                History
                            </button>

                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                data-bs-target="{{ auth()->check() ? '#manualDecisionModal' : '#loginModal' }}">
                                Manual decision
                            </button>
                        </div>
                    </div>

                    @if ($hasManual)
                        <div class="small text-muted mt-2">
                            <div>
                                Manual: <b>{{ $manualRow['decision'] ?? '-' }}</b>
                                @if (!empty($manualRow['remark']))
                                    — {{ $manualRow['remark'] }}
                                @endif
                            </div>
                            <div>
                                โดย: <b>{{ $manualByName ?: '-' }}</b>
                                @if (!empty($manualAt))
                                    • เวลา: {{ \Illuminate\Support\Carbon::parse($manualAt)->format('d/m/Y H:i') }}
                                @endif
                            </div>
                        </div>
                    @endif

                </div>
            </div>
        @endif
    </div>


    {{-- Login Modal --}}
    <div class="modal fade" id="loginModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="{{ route('login') }}">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">Login เพื่อทำ Manual decision</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <input type="hidden" name="redirect_to" value="{{ url()->full() }}">

                        <div class="mb-3">
                            <label class="form-label">Username หรือ Email</label>
                            <input name="login" type="text" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input name="password" type="password" class="form-control" required>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button class="btn btn-primary" type="submit">Login</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Manual Modal --}}
    <div class="modal fade" id="manualDecisionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="{{ route('isr.manual.store') }}">
                    @csrf
                    <input type="hidden" name="mfg_no" value="{{ $mfgNo }}">
                    {{-- ✅ ใช้ตัวแปรที่กัน null แล้ว --}}
                    <input type="hidden" name="wo_id" value="{{ $woId }}">
                    <input type="hidden" name="step_seq" value="{{ $stepSeq }}">

                    <div class="modal-header">
                        <h5 class="modal-title">Manual decision</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        @if (!$canManual)
                            <div class="alert alert-warning mb-0">
                                คุณไม่มีสิทธิ์ทำ Manual decision
                            </div>
                        @else
                            <div class="d-flex gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="decision" id="mdPass"
                                        value="PASS" required>
                                    <label class="form-check-label" for="mdPass">ผ่าน (PASS)</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="decision" id="mdNg"
                                        value="NG" required>
                                    <label class="form-check-label" for="mdNg">ไม่ผ่าน (NG)</label>
                                </div>
                            </div>

                            <div class="mt-3">
                                <label class="form-label">หมายเหตุ (ถ้ามี)</label>
                                <textarea name="remark" class="form-control" rows="3"></textarea>
                            </div>
                        @endif
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                        <button type="submit" class="btn btn-primary" {{ $canManual ? '' : 'disabled' }}>บันทึก</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="manualHistoryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Manual Decision History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    @php $hist = $vm['manual_history'] ?? []; @endphp

                    @if (empty($hist))
                        <div class="text-muted">ไม่มีประวัติ</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-0">
                                <thead class="table-light">
                                    <tr class="text-center">
                                        <th style="width:60px">#</th>
                                        <th style="width:90px">ผล</th>
                                        <th>หมายเหตุ</th>
                                        <th style="width:180px">โดย</th>
                                        <th style="width:160px">เวลา</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($hist as $i => $h)
                                        @php
                                            $d = strtoupper($h['decision'] ?? '');
                                            $badge = $d === 'PASS' ? 'text-bg-success' : 'text-bg-danger';
                                        @endphp
                                        <tr>
                                            <td class="text-center">{{ $i + 1 }}</td>
                                            <td class="text-center"><span
                                                    class="badge {{ $badge }}">{{ $d ?: '-' }}</span></td>
                                            <td>{{ $h['remark'] ?? '' }}</td>
                                            <td>{{ $h['by_name'] ?? 'UID: ' . ($h['by'] ?? '-') }}</td>
                                            <td class="text-center">
                                                {{ !empty($h['at']) ? \Illuminate\Support\Carbon::parse($h['at'])->format('d/m/Y H:i') : '-' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                </div>
            </div>
        </div>
    </div>



    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const els = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                els.forEach(function(el) {
                    new bootstrap.Tooltip(el);
                });
            });
        </script>
    @endpush

@endsection
