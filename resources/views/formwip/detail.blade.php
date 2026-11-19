@extends('layouts.layout')

@section('title', 'WIP ' . $row['wo_no'])
@section('page-title', 'WIP Detail')

@push('styles')
    <style>
        .wip { font-variant-numeric: tabular-nums; }
        .wip .card { border: 1px solid rgba(0, 0, 0, .06); border-radius: .75rem; }
        .wip .card-header { background: transparent; border-bottom: 1px solid rgba(0, 0, 0, .06); font-weight: 600; padding: .75rem 1rem; }
        .wip .muted-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: var(--bs-secondary-color); }
        .wip-dot { width: .65rem; height: .65rem; border-radius: 50%; display: inline-block; }
        .wip-pill { display: inline-flex; align-items: center; gap: .35rem; font-size: .8rem; font-weight: 600; padding: .25rem .7rem; border-radius: 999px; }
        .wip-facts { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: .9rem 1.25rem; }
        .wip-facts .v { word-break: break-word; }
        .wip-facts .v { font-weight: 600; }
        .wip-stack { display: flex; height: 14px; border-radius: 7px; overflow: hidden; background: var(--bs-tertiary-bg); }
        .wip-legend { display: grid; grid-template-columns: auto 1fr auto; gap: .35rem .6rem; font-size: .875rem; align-items: center; }

        /* route stepper */
        .wip-route { display: flex; gap: .5rem; overflow-x: auto; padding-bottom: .25rem; }
        .wip-step { flex: 1 0 120px; max-width: 200px; border-radius: .6rem; padding: .55rem .7rem; border: 1px solid rgba(0, 0, 0, .08); border-top: 4px solid var(--step-color); background: var(--bs-body-bg); position: relative; }
        .wip-step.current { box-shadow: 0 0 0 2px var(--step-color); }
        .wip-step .code { font-weight: 700; }
        .wip-step .meter { height: 5px; border-radius: 3px; background: var(--bs-tertiary-bg); overflow: hidden; margin: .35rem 0 .25rem; }
        .wip-step .meter > div { height: 100%; background: var(--step-color); }

        .wip-table { font-size: .875rem; }
        .wip-table th { font-size: .74rem; font-weight: 600; color: var(--bs-secondary-color); text-transform: uppercase; letter-spacing: .03em; white-space: nowrap; }
        .wip-table td { vertical-align: middle; }
        .wip-scroll { max-height: 440px; overflow-y: auto; }
        .wip-scroll thead th { position: sticky; top: 0; background: var(--bs-body-bg); z-index: 1; }
    </style>
@endpush

@section('content')
    @php
        $colors = [
            'WAITING_PRODUCTION' => '#6c757d', 'IN_PROCESS' => '#0d6efd', 'WAITING_PACKAGING' => '#0dcaf0',
            'WAITING_QC' => '#adb5bd', 'WAITING_FG' => '#f0ad00', 'HOLD' => '#dc3545', 'COMPLETED' => '#198754',
        ];
        $bandColors = ['NORMAL' => '#198754', 'MONITOR' => '#0dcaf0', 'WARNING' => '#f0ad00', 'CRITICAL' => '#dc3545'];
        $kg = fn($v) => number_format($v ?? 0, 1);
        $fmtDate = fn($v) => $v ? \Carbon\Carbon::parse($v)->format('d/m/Y') : '-';
        $fmtDt = fn($v) => $v ? \Carbon\Carbon::parse($v)->format('d/m/Y H:i') : '-';
        $st = $statuses[$row['status']];
        $ag = $agingBands[$row['aging_band']];
        $bc = $bandColors[$row['aging_band']];
        $sc = $colors[$row['status']];
        $typeLabel = [0 => ['WIP', '#6c757d'], 1 => ['ของดี', '#198754'], 3 => ['NCR/Rework', '#dc3545'], 4 => ['Type 4', '#f0ad00']];
        $lastIdx = count($row['steps']) - 1;
        $bucketTotal = max(1, array_sum($row['buckets']));
        $target = max(1, (float) $row['qty']);
    @endphp

    <div class="wip">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <a href="{{ $backUrl }}" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left"></i> กลับ</a>
            <span class="fs-4 fw-bold ms-1">{{ $row['wo_no'] }}</span>
            <span class="badge rounded-pill text-bg-dark">{{ $row['site'] }}</span>
            <span class="wip-pill" style="background: {{ $sc }}1f; color: {{ $sc }}"><span class="wip-dot" style="background: {{ $sc }}"></span>{{ $st['th'] }}</span>
            @if ($row['status'] !== 'COMPLETED')
                <span class="wip-pill" style="background: {{ $bc }}1f; color: {{ $bc }}"><i class="fa-regular fa-hourglass-half"></i> ค้าง {{ $row['aging_days'] }} วัน · {{ $ag['label'] }}</span>
            @endif
            @if ($row['suspended'])<span class="wip-pill" style="background:#dc35451f;color:#dc3545">Suspended</span>@endif
            @if ($row['dateclose'])<span class="wip-pill" style="background:#6c757d1f;color:#6c757d">ปิด WO {{ $fmtDate($row['dateclose']) }}</span>@endif
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-8">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <div class="fw-semibold">{{ $row['part_number'] }}</div>
                        <div class="text-secondary mb-3">{{ $row['part_desc'] }}</div>
                        <div class="wip-facts">
                            <div><div class="muted-label">Customer</div><div class="v">{{ $row['customer'] }}</div></div>
                            <div><div class="muted-label">Size / Grade</div><div class="v">{{ $row['size'] ?: '-' }} / {{ $row['grade'] ?: '-' }}</div></div>
                            <div><div class="muted-label">SO</div><div class="v">{{ $row['ord_number'] ?: '-' }}</div></div>
                            <div><div class="muted-label">Qty WO</div><div class="v">{{ $kg($row['qty']) }} kg</div></div>
                            <div><div class="muted-label">เปิด WO / Due</div><div class="v">{{ $fmtDate($row['dateopen']) }} / {{ $fmtDate($row['reqdate']) }}</div></div>
                            <div><div class="muted-label">เริ่มผลิต</div><div class="v">{{ $fmtDt($row['production_start']) }}</div></div>
                            <div><div class="muted-label">เคลื่อนไหวล่าสุด</div><div class="v">{{ $fmtDt($row['last_tx']) }}</div></div>
                            <div><div class="muted-label">ขั้นปัจจุบัน</div><div class="v">{{ $row['current_wc'] }}@if ($row['current_machine'] !== '-') · {{ $row['current_machine'] }}@endif</div></div>
                            <div>
                                <div class="muted-label">{{ ($row['aging_source'] ?? 'activity') === 'status' ? 'อยู่ในสถานะนี้ตั้งแต่' : 'Aging นับจาก (ประมาณ)' }}</div>
                                <div class="v">{{ $fmtDt($row['aging_ref']) }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header">น้ำหนักแยกตามสถานะ</div>
                    <div class="card-body">
                        <div class="wip-stack mb-3">
                            @foreach ($row['buckets'] as $key => $v)
                                @if ($v > 0)
                                    <div style="width: {{ $v / $bucketTotal * 100 }}%; background: {{ $colors[$key] }}" title="{{ $statuses[$key]['label'] }} {{ $kg($v) }} kg"></div>
                                @endif
                            @endforeach
                        </div>
                        <div class="wip-legend">
                            @foreach ($row['buckets'] as $key => $v)
                                <span class="wip-dot" style="background: {{ $colors[$key] }}"></span>
                                <span class="{{ $v > 0 ? '' : 'text-secondary' }}">{{ $statuses[$key]['th'] }}</span>
                                <span class="text-end {{ $v > 0 ? 'fw-semibold' : 'text-secondary' }}">{{ $v > 0 ? $kg($v) . ' kg' : '–' }}</span>
                            @endforeach
                        </div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between small text-secondary"><span>Output ขั้นสุดท้าย (WMSOne)</span><span>{{ $kg($row['final_output']) }}</span></div>
                        <div class="d-flex justify-content-between small text-secondary"><span>รับเข้า FG (ERP)</span><span>{{ $kg($row['erp_received']) }}</span></div>
                        <div class="d-flex justify-content-between small text-secondary"><span>NCR สะสมทุกขั้น</span><span>{{ $kg($row['ncr_total']) }}</span></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Route stepper + step table --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-route me-1 text-secondary"></i> ขั้นตอนการผลิต</span>
                <span class="d-flex align-items-center gap-2 small fw-normal text-secondary">
                    <span class="d-none d-md-inline">ครบตามเกณฑ์น้ำหนักเดียวกับ Tracking: ≥ {{ $kg(\App\Support\FormDP\ProductionWeightTolerance::minimumReceivedKg((float) $row['qty'])) }} kg
                        (ยอมรับขาด {{ rtrim(rtrim(number_format(\App\Support\FormDP\ProductionWeightTolerance::shortageRate((float) $row['qty']) * 100, 2), '0'), '.') }}%)</span>
                    @include('formdp.production-status.partials.weight-tolerance-help')
                </span>
            </div>
            <div class="card-body">
                @if ($row['steps'])
                    <div class="wip-route mb-3">
                        @foreach ($row['steps'] as $i => $s)
                            @php
                                $done = $s['done'];
                                $color = $s['balance'] > 0 ? ($row['ends_with_pk'] && $i === $lastIdx ? $colors['WAITING_PACKAGING'] : $colors['IN_PROCESS'])
                                    : ($done ? $colors['COMPLETED'] : ($s['good_qty'] > 0 ? $colors['IN_PROCESS'] : '#ced4da'));
                                $pct = min(100, $s['good_qty'] / $target * 100);
                            @endphp
                            <div class="wip-step {{ $s['wc_code'] === $row['current_wc'] ? 'current' : '' }}" style="--step-color: {{ $color }}" title="{{ $s['wc_name'] }}">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="code">{{ $s['wc_code'] }}</span>
                                    <span class="small text-secondary">#{{ $s['seq'] }}</span>
                                </div>
                                <div class="meter"><div style="width: {{ $pct }}%"></div></div>
                                <div class="small">{{ $s['good_qty'] > 0 ? $kg($s['good_qty']) : '–' }} <span class="text-secondary">kg</span></div>
                                @if ($s['balance'] > 0)
                                    <div class="small fw-semibold" style="color: {{ $color }}">ค้าง {{ $kg($s['balance']) }}</div>
                                @elseif ($done)
                                    <div class="small text-success" title="{{ $s['done_by'] === 'passed' ? 'ขั้นถัดไปครบแล้ว จึงถือว่าขั้นนี้ครบ (กฎเดียวกับ Tracking)' : 'ยอดรับถึงเกณฑ์น้ำหนัก' }}">
                                        <i class="fa-solid fa-check"></i> ครบ{{ $s['done_by'] === 'passed' ? ' (ขั้นหลังครบ)' : '' }}
                                    </div>
                                @else
                                    <div class="small text-secondary">{{ $s['good_qty'] > 0 ? 'ทำบางส่วน' : 'ยังไม่เริ่ม' }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 wip-table">
                        <thead>
                            <tr>
                                <th>Seq</th><th>Work Center</th><th>Size in → out</th>
                                <th class="text-end">เบิกเข้า</th><th class="text-end">Output ของดี</th><th class="text-end">NCR</th>
                                <th class="text-end">ค้างที่ขั้นนี้</th><th>สถานะขั้น</th><th>เครื่องล่าสุด</th><th>รับครั้งแรก</th><th>รับล่าสุด</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($row['steps'] as $i => $s)
                                <tr class="{{ $s['wc_code'] === $row['current_wc'] ? 'table-warning' : '' }}">
                                    <td class="text-secondary">{{ $s['seq'] }}</td>
                                    <td><span class="fw-semibold">{{ $s['wc_code'] }}</span> <span class="small text-secondary">{{ $s['wc_name'] }}</span></td>
                                    <td class="small">{{ $s['size_in'] ?? '-' }} → {{ $s['size_out'] ?? '-' }}</td>
                                    <td class="text-end">{{ $s['input_qty'] > 0 ? $kg($s['input_qty']) : '–' }}</td>
                                    <td class="text-end">{{ $s['good_qty'] > 0 ? $kg($s['good_qty']) : '–' }}</td>
                                    <td class="text-end {{ $s['ncr_qty'] > 0 ? 'text-danger' : 'text-secondary' }}">{{ $s['ncr_qty'] > 0 ? $kg($s['ncr_qty']) : '–' }}</td>
                                    <td class="text-end fw-bold">{{ $s['balance'] > 0 ? $kg($s['balance']) : '–' }}</td>
                                    <td class="small">
                                        @if ($s['done'])
                                            <span class="text-success">Completed{{ $s['done_by'] === 'passed' ? ' (ขั้นหลังครบ)' : '' }}</span>
                                        @elseif ($s['good_qty'] + $s['ncr_qty'] > 0)
                                            <span class="text-primary">In Progress</span>
                                        @else
                                            <span class="text-secondary">Pending</span>
                                        @endif
                                    </td>
                                    <td>{{ $s['machine'] ?: '–' }}</td>
                                    <td class="small text-secondary">{{ $fmtDt($s['first_stamp']) }}</td>
                                    <td class="small text-secondary">{{ $fmtDt($s['last_stamp']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="11" class="text-center text-secondary py-4">ไม่พบ Routing ใน WMSOne</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Status history (snapshot) --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-timeline me-1 text-secondary"></i> ประวัติการเปลี่ยนสถานะ</span>
                <span class="small fw-normal text-secondary">บันทึกจากการ sync อัตโนมัติทุก 15 นาที</span>
            </div>
            <div class="card-body p-0 wip-scroll" style="max-height: 300px">
                @if (!$snapshotReady)
                    <div class="text-secondary small p-3"><i class="fa-solid fa-circle-info"></i> ยังไม่ได้เปิดใช้การเก็บประวัติ (ยังไม่มีตาราง snapshot)</div>
                @else
                    <table class="table table-sm table-hover mb-0 wip-table">
                        <thead><tr><th class="ps-3">เวลา</th><th>สถานะ</th><th>Work Center</th><th class="text-end">อยู่สถานะเดิม (วัน)</th><th class="text-end pe-3">WIP (kg)</th></tr></thead>
                        <tbody>
                            @forelse ($statusHistory as $h)
                                @php
                                    $label = fn($s) => $s === null ? 'พบครั้งแรก' : ($s === 'CLOSED' ? 'ปิด WO' : ($statuses[$s]['th'] ?? $s));
                                    $color = fn($s) => $colors[$s] ?? '#6c757d';
                                @endphp
                                <tr>
                                    <td class="ps-3 small">{{ $fmtDt($h->changed_at) }}</td>
                                    <td class="small">
                                        @if ($h->from_status !== $h->to_status)
                                            <span class="text-secondary">{{ $label($h->from_status) }}</span>
                                            <i class="fa-solid fa-arrow-right mx-1 text-secondary small"></i>
                                        @endif
                                        <span class="fw-semibold" style="color: {{ $color($h->to_status) }}">{{ $label($h->to_status) }}</span>
                                    </td>
                                    <td class="small">
                                        @if ($h->from_wc && $h->from_wc !== $h->to_wc)
                                            <span class="text-secondary">{{ $h->from_wc }}</span> <i class="fa-solid fa-arrow-right mx-1 text-secondary small"></i>
                                        @endif
                                        {{ $h->to_wc ?? '–' }}
                                    </td>
                                    <td class="text-end small">{{ $h->days_in_prev ?? '–' }}</td>
                                    <td class="text-end pe-3 small">{{ $h->wip_kg !== null ? $kg($h->wip_kg) : '–' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-secondary py-4">ยังไม่มีประวัติ (จะเริ่มบันทึกหลัง sync รอบแรก)</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        <div class="row g-3">
            <div class="col-xl-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header"><i class="fa-solid fa-warehouse me-1 text-secondary"></i> รับเข้า FG Stock (ERP)</div>
                    <div class="card-body p-0 wip-scroll">
                        @if ($erpError)
                            <div class="alert alert-danger m-2 py-2">{{ $erpError }}</div>
                        @endif
                        <table class="table table-sm mb-0 wip-table">
                            <thead><tr><th class="ps-3">เลขที่</th><th>วันที่</th><th class="text-end pe-3">Qty (kg)</th></tr></thead>
                            <tbody>
                                @forelse ($erpLines as $l)
                                    <tr><td class="ps-3 small">{{ $l->transnumber }}</td><td class="small">{{ $fmtDate($l->transdate) }}</td><td class="text-end pe-3">{{ $kg($l->qty) }}</td></tr>
                                @empty
                                    <tr><td colspan="3" class="text-center text-secondary py-4">ยังไม่มีการรับเข้า</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-xl-8">
                <div class="card shadow-sm h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fa-solid fa-clock-rotate-left me-1 text-secondary"></i> ประวัติการรับในแต่ละขั้น (WMSOne)</span>
                        <span class="small fw-normal text-secondary">ล่าสุด 500 รายการ</span>
                    </div>
                    <div class="card-body p-0 table-responsive wip-scroll">
                        <table class="table table-sm table-hover mb-0 wip-table">
                            <thead>
                                <tr><th class="ps-3">เวลา</th><th>Seq</th><th>WC</th><th>เครื่อง</th><th>ประเภท</th><th class="text-end">Qty</th><th>Heat / Coil</th><th class="pe-3">หมายเหตุ</th></tr>
                            </thead>
                            <tbody>
                                @forelse ($history as $h)
                                    @php [$tl, $tc] = $typeLabel[(int) $h->receivetype] ?? ['Type ' . $h->receivetype, '#adb5bd']; @endphp
                                    <tr>
                                        <td class="ps-3 small">{{ $fmtDt($h->receivestamp) }}</td>
                                        <td class="text-secondary">{{ $h->workseq }}</td>
                                        <td class="fw-semibold">{{ $h->wc_code }}</td>
                                        <td>{{ $h->machine ?? '–' }}</td>
                                        <td><span class="wip-pill" style="background: {{ $tc }}1f; color: {{ $tc }}; font-size: .72rem; padding: .1rem .5rem">{{ $tl }}</span></td>
                                        <td class="text-end">{{ $kg($h->qty) }}</td>
                                        <td class="small text-secondary">{{ $h->heatno }} / {{ $h->coilno }}</td>
                                        <td class="small pe-3">{{ $h->reworkreason }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="text-center text-secondary py-4">ยังไม่มีการรับ</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
