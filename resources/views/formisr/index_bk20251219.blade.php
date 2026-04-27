{{-- resources/views/inspection/index.blade.php --}}
@extends('layouts.layout')

@section('page-title', 'Inspection Report')
@section('title', 'Inspection Report')

@section('content')
    <style>
        .report-card {
            border-radius: 1rem;
        }

        .report-section-title {
            font-size: .85rem;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: #6c757d;
            font-weight: 600;
        }

        .report-label {
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #9ca3af;
        }

        .table-report th {
            white-space: nowrap;
            font-size: .8rem;
        }

        .table-report td {
            font-size: .8rem;
            vertical-align: middle;
        }

        .status-pill {
            border-radius: 999px;
            padding: .15rem .9rem;
            font-size: .75rem;
            font-weight: 600;
        }

        .table-spec,
        .table-meas {
            table-layout: fixed;
        }

        .col-meta {
            width: 160px;
        }

        .col-spec {
            width: 70px;
        }

        .qa-table {
            width: 100%;
            table-layout: fixed;
            /* ✅ คุมสัดส่วนคอลัมน์ */
        }

        .qa-table th,
        .qa-table td {
            vertical-align: middle;
            padding: 10px 12px;
            border-top: 1px solid #e5e7eb;
        }

        .qa-table thead th {
            font-weight: 700;
            color: #111827;
            background: #fafafa;
            border-top: 0;
        }

        .qa-table .col-num {
            text-align: center;
        }

        .qa-table .col-right {
            text-align: right;
        }

        .qa-table .truncate {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            /* ✅ กันตัดหลายบรรทัด */
        }

        .qa-table .dt {
            line-height: 1.15;
            white-space: nowrap;
        }

        .qa-table .dt small {
            display: block;
            color: #6b7280;
            font-weight: 500;
            margin-top: 2px;
        }
    </style>

    <div class="container-xxl py-3">
        {{-- Search --}}
        <div class="card shadow-sm border-0 report-card mb-4">
            <div class="card-body">

                <div class="report-section-title mb-2">Search</div>
                <form method="get" class="row g-2 align-items-end">
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
                $decision = $vm['decision'] ?? 'OK';
                $testsToShow = $vm['tests_to_show'] ?? [];
                $summaryCols = $vm['summary_cols'] ?? [];
                $summaryLabels = $vm['summary_labels'] ?? [];
                $summaryOverall = $vm['summary_overall'] ?? ['pass' => 0, 'ng' => 0, 'remark' => null];

                // คอลัมน์ทั้งหมดจาก controller
                $allDisplayCols = $vm['display_cols'] ?? [];

                // กรองคอลัมน์ให้เหลือเฉพาะ test ที่ต้องโชว์ (อิงจาก tests_to_show)
                $displayCols = collect($allDisplayCols)
                    ->filter(function ($col) use ($testsToShow) {
                        $key = $col['key'] ?? null;
                        if (!$key) {
                            return true; // ถ้าไม่มี key ชัดเจน โชว์ไว้ก่อน
                        }
                        if (!array_key_exists($key, $testsToShow)) {
                            return true; // ยังไม่ได้ทำ flag → โชว์ไว้ก่อน
                        }
                        return (bool) $testsToShow[$key];
                    })
                    ->values();

                $maxCols = $displayCols->count();
                $wtCols = collect($workTests)->values();
                $wtCount = $wtCols->count();
                // ใช้ qawip_rows จาก controller (worktest 100% + qawip sample)
                $qaRows = $vm['qawip_rows'] ?? [];
                $rowCount = count($qaRows);
                $rowCount2 = count($workTests);
                //dd($workTests);
                // order สำหรับ summary (controller คำนวณจาก qaRows เหมือนกัน)
                $summaryOrder = [];
                foreach ($displayCols as $col) {
                    if (($col['key'] ?? '') === 'size') {
                        $summaryOrder[] = 'size';
                    } elseif (($col['key'] ?? '') === 'tensile') {
                        $summaryOrder[] = 'ts';
                    }
                }
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

                        @if (!empty($vm['remark']) || !empty($vm['step_detail']))
                            <div class="mt-3">
                                @if (!empty($vm['remark']))
                                    <div class="mb-2">
                                        <div class="text-muted small">หมายเหตุ</div>
                                        <div class="fw-semibold">{!! nl2br(e($vm['remark'])) !!}</div>
                                    </div>
                                @endif

                                @if (!empty($vm['step_detail']))
                                    <div>
                                        <div class="text-muted small">รายละเอียดขั้นตอน</div>
                                        <div class="fw-semibold">{!! nl2br(e($vm['step_detail'])) !!}</div>
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
                        <div class="table-responsive">
                            <table class="qa-table">
                                <colgroup>
                                    <col style="width: 56px"> {{-- # --}}
                                    <col style="width: 120px"> {{-- Serial --}}
                                    <col style="width: 170px"> {{-- Inspected --}}
                                    <col style="width: 160px"> {{-- Inspector --}}
                                    @foreach ($vm['display_cols'] ?? [] as $col)
                                        <col style="width: 140px"> {{-- ค่าที่วัด (ขนาด/ความยาว/Tensile/...) --}}
                                    @endforeach
                                </colgroup>

                                <thead>
                                    <tr>
                                        <th class="col-num">#</th>
                                        <th>Serial (Coil)</th>
                                        <th>Inspected</th>
                                        <th>Inspector</th>
                                        @foreach ($vm['display_cols'] ?? [] as $col)
                                            <th class="col-right">{{ $col['label'] }}</th>
                                        @endforeach
                                    </tr>
                                </thead>

                                <tbody>
                                    @foreach ($vm['qawip_rows'] ?? [] as $i => $r)
                                        <tr>
                                            <td class="col-num">{{ $i + 1 }}</td>

                                            <td class="truncate">{{ $r->coil ?? '' }}</td>

                                            <td>
                                                @php
                                                    $t = $r->inspectedtime ?? null;
                                                @endphp
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

                                            @foreach ($vm['display_cols'] ?? [] as $col)
                                                @php
                                                    $key = $col['key'];
                                                    $val = $r->{$key} ?? null;
                                                    $dec = $col['decimals'] ?? 3;
                                                    $zeroIsOk = (bool) ($col['zero_is_ok'] ?? false);
                                                @endphp


                                                <td class="col-right">
                                                    @if ($key === 'hardness')
                                                        @php
                                                            $avg = $r->hardness_avg ?? $val;
                                                            $pts = $r->hardness_points ?? [];
                                                        @endphp

                                                        <div class="text-end fw-semibold">
                                                            {{ is_numeric($avg) ? number_format($avg, 2) : '' }}</div>
                                                        <div class="d-flex flex-wrap justify-content-end gap-1 mt-1">
                                                            @foreach (['point1' => 'P1', 'point2' => 'P2', 'point3' => 'P3', 'point4' => 'P4', 'point5' => 'P5'] as $pf => $pl)
                                                                @php $pv = $pts[$pf] ?? null; @endphp
                                                                <span class="badge rounded-pill text-bg-light border">
                                                                    {{ $pl }}
                                                                    {{ is_numeric($pv) ? number_format($pv, 2) : '-' }}
                                                                </span>
                                                            @endforeach
                                                        </div>
                                                    @else
                                                        @if (is_numeric($val))
                                                            {{ number_format((float) $val, (int) $dec) }}
                                                        @else
                                                            {{ $val ?? '' }}
                                                        @endif
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if (!empty($summaryCols) && !empty($summaryOrder))
                        {{-- ตาราง summary --}}
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
                                    @foreach ($vm['summary_cols'] ?? [] as $key => $s)
                                        @php
                                            $label = $vm['summary_labels'][$key] ?? $key;

                                            $dec = 3;
                                            $zeroOk = false;
                                            foreach ($vm['display_cols'] ?? [] as $c) {
                                                if (($c['key'] ?? null) === $key) {
                                                    $dec = $c['decimals'] ?? $dec;
                                                    $zeroOk = (bool) ($c['zero_is_ok'] ?? false);
                                                    break;
                                                }
                                            }
                                            $zeroIsOk = false;
                                            foreach ($vm['display_cols'] ?? [] as $c) {
                                                if (($c['key'] ?? null) === $key) {
                                                    $dec = $c['decimals'] ?? $dec;
                                                    $zeroIsOk = (bool) ($c['zero_is_ok'] ?? false);
                                                    break;
                                                }
                                            }
                                            $fmt = function ($v) use ($dec, $zeroIsOk) {
                                                if ($zeroIsOk && is_numeric($v) && (float) $v == 0.0) {
                                                    return 'ปกติ';
                                                }
                                                return is_numeric($v) ? number_format((float) $v, $dec) : '-';
                                            };
                                        @endphp

                                        <tr>
                                            <td class="fw-semibold">{{ $label }}</td>
                                            <td class="text-end">{{ $fmt($s['avg'] ?? null) }}</td>
                                            <td class="text-end">{{ $fmt($s['max'] ?? null) }}</td>
                                            <td class="text-end">{{ $fmt($s['min'] ?? null) }}</td>
                                            <td class="text-end">{{ $fmt($s['stdev'] ?? null) }}</td>
                                            <td class="text-center fw-semibold">{{ $s['decide'] ?? '-' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>

                        </div>
                    @endif

                    {{-- ผลรวมผ่าน/ไม่ผ่าน --}}
                    <div class="mt-2">
                        <table class="table table-sm table-bordered table-report align-middle mb-0">
                            <tbody>
                                <tr>
                                    <th style="width:160px;">ผลการตัดสิน</th>
                                    <th class="text-center" style="width:80px;">ผ่าน</th>
                                    <th class="text-center" style="width:80px;">ไม่ผ่าน</th>
                                    <th class="text-center">หมายเหตุ</th>
                                </tr>
                                @php
                                    $passCount = $summaryOverall['pass'] ?? 0;
                                    $ngCount = $summaryOverall['ng'] ?? 0;
                                    $allOk = $passCount > 0 && $ngCount == 0;
                                @endphp
                                <tr>
                                    <td>&nbsp;</td>

                                    @if ($allOk)
                                        <td class="text-center">
                                            <span class="d-inline-block rounded-circle"
                                                style="width:14px;height:14px;background:#198754;"></span>
                                        </td>
                                        <td class="text-center">&nbsp;</td>
                                    @else
                                        <td class="text-center text-success fw-semibold">
                                            {{ $passCount }}
                                        </td>
                                        <td class="text-center text-danger fw-semibold">
                                            {{ $ngCount }}
                                        </td>
                                    @endif

                                    <td>
                                        {{ $summaryOverall['remark'] ?? '' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection
