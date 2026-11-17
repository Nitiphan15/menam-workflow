@extends('layouts.layout')

@section('title', 'WIP ' . $row['wo_no'])
@section('page-title', 'WIP Detail · ' . $row['wo_no'])

@section('content')
    @php
        $kg = fn($v) => number_format($v ?? 0, 1);
        $fmtDate = fn($v) => $v ? \Carbon\Carbon::parse($v)->format('d/m/Y') : '-';
        $fmtDt = fn($v) => $v ? \Carbon\Carbon::parse($v)->format('d/m/Y H:i') : '-';
        $st = $statuses[$row['status']];
        $ag = $agingBands[$row['aging_band']];
        $typeLabel = [0 => ['WIP', 'secondary'], 1 => ['ของดี', 'success'], 3 => ['NCR/Rework', 'danger'], 4 => ['Type 4', 'warning']];
        $lastIdx = count($row['steps']) - 1;
    @endphp

    <div class="mb-3">
        <a href="{{ $backUrl }}" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left"></i> กลับ</a>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                        <span class="fs-4 fw-bold">{{ $row['wo_no'] }}</span>
                        <span class="badge text-bg-dark">{{ $row['site'] }}</span>
                        <span class="badge text-bg-{{ $st['class'] }}">{{ $st['label'] }} · {{ $st['th'] }}</span>
                        <span class="badge text-bg-{{ $ag['class'] }}">Aging {{ $row['aging_days'] }} วัน ({{ $ag['label'] }})</span>
                        @if ($row['suspended'])<span class="badge text-bg-danger">Suspended</span>@endif
                        @if ($row['dateclose'])<span class="badge text-bg-secondary">ปิด WO แล้ว {{ $fmtDate($row['dateclose']) }}</span>@endif
                    </div>
                    <dl class="row small mb-0">
                        <dt class="col-sm-3">Product</dt><dd class="col-sm-9">{{ $row['part_number'] }} — {{ $row['part_desc'] }}</dd>
                        <dt class="col-sm-3">Customer</dt><dd class="col-sm-9">{{ $row['customer'] }}</dd>
                        <dt class="col-sm-3">Size / Grade</dt><dd class="col-sm-9">{{ $row['size'] ?: '-' }} / {{ $row['grade'] ?: '-' }}</dd>
                        <dt class="col-sm-3">SO (ordnumber)</dt><dd class="col-sm-9">{{ $row['ord_number'] ?: '-' }}</dd>
                        <dt class="col-sm-3">Qty WO</dt><dd class="col-sm-9">{{ $kg($row['qty']) }} kg</dd>
                        <dt class="col-sm-3">เปิด / Due</dt><dd class="col-sm-9">{{ $fmtDate($row['dateopen']) }} / {{ $fmtDate($row['reqdate']) }}</dd>
                        <dt class="col-sm-3">เริ่มผลิต / ล่าสุด</dt><dd class="col-sm-9">{{ $fmtDt($row['production_start']) }} / {{ $fmtDt($row['last_tx']) }}</dd>
                        <dt class="col-sm-3">ขั้นปัจจุบัน</dt><dd class="col-sm-9">{{ $row['current_wc'] }} @if ($row['current_machine'] !== '-') / {{ $row['current_machine'] }} @endif</dd>
                        <dt class="col-sm-3">Aging นับจาก</dt><dd class="col-sm-9">{{ $fmtDt($row['aging_ref']) }}</dd>
                    </dl>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-bold">น้ำหนักแยกตามสถานะ (kg)</div>
                <ul class="list-group list-group-flush">
                    @foreach ($row['buckets'] as $key => $v)
                        <li class="list-group-item d-flex justify-content-between py-1">
                            <span><span class="badge text-bg-{{ $statuses[$key]['class'] }}">&nbsp;</span> {{ $statuses[$key]['label'] }}</span>
                            <span class="{{ $v > 0 ? 'fw-bold' : 'text-muted' }}">{{ $v > 0 ? $kg($v) : '-' }}</span>
                        </li>
                    @endforeach
                    <li class="list-group-item d-flex justify-content-between py-1 small text-muted">
                        <span>Output ขั้นสุดท้าย (WMSOne) / รับเข้า FG (ERP)</span>
                        <span>{{ $kg($row['final_output']) }} / {{ $kg($row['erp_received']) }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between py-1 small text-muted">
                        <span>NCR สะสมทุกขั้น</span><span>{{ $kg($row['ncr_total']) }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    {{-- Routing / step balance --}}
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-white fw-bold">ขั้นตอนการผลิตและยอดคงเหลือแต่ละขั้น</div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Seq</th><th>Work Center</th><th>Size in → out</th>
                        <th class="text-end">เบิกเข้า (usage)</th><th class="text-end">Output ของดี</th><th class="text-end">NCR</th>
                        <th class="text-end">ค้างที่ขั้นนี้</th><th>เครื่องล่าสุด</th><th>รับครั้งแรก</th><th>รับล่าสุด</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($row['steps'] as $i => $s)
                        <tr class="{{ $s['wc_code'] === $row['current_wc'] ? 'table-warning' : '' }}">
                            <td>{{ $s['seq'] }}</td>
                            <td><span class="fw-semibold">{{ $s['wc_code'] }}</span> <span class="small text-muted">{{ $s['wc_name'] }}</span>
                                @if ($row['ends_with_pk'] && $i === $lastIdx)<span class="badge text-bg-info">Packaging</span>@endif
                            </td>
                            <td class="small">{{ $s['size_in'] ?? '-' }} → {{ $s['size_out'] ?? '-' }}</td>
                            <td class="text-end">{{ $s['input_qty'] > 0 ? $kg($s['input_qty']) : '-' }}</td>
                            <td class="text-end">{{ $s['good_qty'] > 0 ? $kg($s['good_qty']) : '-' }}</td>
                            <td class="text-end {{ $s['ncr_qty'] > 0 ? 'text-danger' : '' }}">{{ $s['ncr_qty'] > 0 ? $kg($s['ncr_qty']) : '-' }}</td>
                            <td class="text-end fw-bold">{{ $s['balance'] > 0 ? $kg($s['balance']) : '-' }}</td>
                            <td>{{ $s['machine'] ?: '-' }}</td>
                            <td class="small">{{ $fmtDt($s['first_stamp']) }}</td>
                            <td class="small">{{ $fmtDt($s['last_stamp']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center text-muted py-3">ไม่พบ Routing ใน WMSOne</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-bold">รับเข้า FG Stock (ERP)</div>
                <div class="card-body p-0">
                    @if ($erpError)
                        <div class="alert alert-danger m-2 py-2">{{ $erpError }}</div>
                    @endif
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>เลขที่</th><th>วันที่</th><th class="text-end">Qty</th></tr></thead>
                        <tbody>
                            @forelse ($erpLines as $l)
                                <tr><td class="small">{{ $l->transnumber }}</td><td class="small">{{ $fmtDate($l->transdate) }}</td><td class="text-end">{{ $kg($l->qty) }}</td></tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-muted py-3">ยังไม่มีการรับเข้า</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-bold">ประวัติการรับในแต่ละขั้น (WMSOne, ล่าสุด 500 รายการ)</div>
                <div class="card-body p-0 table-responsive" style="max-height: 420px; overflow-y: auto">
                    <table class="table table-sm mb-0">
                        <thead class="table-light sticky-top">
                            <tr><th>เวลา</th><th>Seq</th><th>WC</th><th>เครื่อง</th><th>ประเภท</th><th class="text-end">Qty</th><th>Heat / Coil</th><th>หมายเหตุ</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($history as $h)
                                @php [$tl, $tc] = $typeLabel[(int) $h->receivetype] ?? ['Type ' . $h->receivetype, 'light']; @endphp
                                <tr>
                                    <td class="small">{{ $fmtDt($h->receivestamp) }}</td>
                                    <td>{{ $h->workseq }}</td>
                                    <td>{{ $h->wc_code }}</td>
                                    <td>{{ $h->machine ?? '-' }}</td>
                                    <td><span class="badge text-bg-{{ $tc }}">{{ $tl }}</span></td>
                                    <td class="text-end">{{ $kg($h->qty) }}</td>
                                    <td class="small">{{ $h->heatno }} / {{ $h->coilno }}</td>
                                    <td class="small">{{ $h->reworkreason }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-center text-muted py-3">ยังไม่มีการรับ</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
