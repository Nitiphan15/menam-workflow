@extends('layouts.layout')

@section('title', 'WIP Monitoring')
@section('page-title', 'WIP & Stock Pending Monitoring')

@push('styles')
    <style>
        .wip-card { border: 0; border-left: 4px solid var(--bs-gray-400); transition: transform .1s; }
        .wip-card:hover { transform: translateY(-2px); }
        .wip-card.active { box-shadow: 0 0 0 2px var(--bs-primary); }
        .wip-card .wip-ton { font-size: 1.6rem; font-weight: 700; line-height: 1.1; }
        .wip-card.b-secondary { border-left-color: var(--bs-secondary); }
        .wip-card.b-primary { border-left-color: var(--bs-primary); }
        .wip-card.b-info { border-left-color: var(--bs-info); }
        .wip-card.b-light { border-left-color: var(--bs-gray-300); opacity: .7; }
        .wip-card.b-warning { border-left-color: var(--bs-warning); }
        .wip-card.b-danger { border-left-color: var(--bs-danger); }
        .wip-card.b-success { border-left-color: var(--bs-success); }
        .wip-bar { height: 8px; background: var(--bs-gray-200); border-radius: 4px; overflow: hidden; display: flex; }
        .wip-table td, .wip-table th { white-space: nowrap; vertical-align: middle; }
        .wip-table .text-wrap-sm { white-space: normal; min-width: 220px; }
        .wip-scroll { max-height: 420px; overflow-y: auto; }
    </style>
@endpush

@section('content')
    @php
        $ton = fn($kg) => number_format(($kg ?? 0) / 1000, 1);
        $kg = fn($v) => number_format($v ?? 0, 0);
        $fmtDate = fn($v) => $v ? \Carbon\Carbon::parse($v)->format('d/m/Y') : '-';
        $q = fn(array $over) => array_merge(request()->except(['page', 'refresh']), $over);
        $maxWc = max(1, $wcSummary->max('total') ?? 1);
        $maxMc = max(1, $machineSummary->max('kg') ?? 1);
    @endphp

    {{-- Filters --}}
    <form method="GET" action="{{ route('wip.index') }}" class="card shadow-sm mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-0">Site</label>
                    <select name="site" class="form-select form-select-sm">
                        @foreach (['WIRE' => 'Wire', 'PLUS' => 'Plus', 'ALL' => 'ทั้ง 2 Site'] as $v => $l)
                            <option value="{{ $v }}" @selected($filters['site'] === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-0">WO เปิดย้อนหลัง</label>
                    <select name="days" class="form-select form-select-sm">
                        @foreach ([90, 180, 365, 730] as $d)
                            <option value="{{ $d }}" @selected($filters['days'] === $d)>{{ $d }} วัน</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-0">Route</label>
                    <select name="scope" class="form-select form-select-sm">
                        <option value="pk" @selected($filters['scope'] === 'pk')>เฉพาะที่จบด้วย PK (Pilot)</option>
                        <option value="all" @selected($filters['scope'] === 'all')>ทุก Route</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-0">Work Center</label>
                    <select name="wc" class="form-select form-select-sm">
                        <option value="">ทั้งหมด</option>
                        @foreach ($wcOptions as $wc)
                            <option value="{{ $wc }}" @selected($filters['wc'] === $wc)>{{ $wc }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-0">Aging</label>
                    <select name="aging" class="form-select form-select-sm">
                        <option value="">ทั้งหมด</option>
                        @foreach ($agingBands as $k => $b)
                            <option value="{{ $k }}" @selected($filters['aging'] === $k)>{{ $b['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-0">แสดง</label>
                    <select name="only_wip" class="form-select form-select-sm">
                        <option value="1" @selected($filters['only_wip'])>เฉพาะ WO ที่มี WIP ค้าง</option>
                        <option value="0" @selected(!$filters['only_wip'])>WO ที่เปิดอยู่ทั้งหมด</option>
                    </select>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label small mb-0">ค้นหา (WO / Product / Customer / Size / Grade / SO)</label>
                    <input type="text" name="q" value="{{ $filters['q'] }}" class="form-control form-control-sm" placeholder="เช่น W2602 S50C 15.20">
                </div>
                @if ($filters['status'] !== '')
                    <input type="hidden" name="status" value="{{ $filters['status'] }}">
                @endif
                <div class="col-12 col-md-6 d-flex gap-2 justify-content-md-end">
                    <button class="btn btn-sm btn-primary"><i class="fa-solid fa-filter"></i> กรอง</button>
                    <a href="{{ route('wip.index') }}" class="btn btn-sm btn-outline-secondary">ล้าง</a>
                    <a href="{{ route('wip.index', $q(['refresh' => 1])) }}" class="btn btn-sm btn-outline-success">
                        <i class="fa-solid fa-rotate"></i> ดึงข้อมูลใหม่
                    </a>
                </div>
            </div>
        </div>
    </form>

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
        <div>
            <span class="fs-5 fw-bold">WIP คงค้างรวม {{ $ton($totalWipKg) }} ตัน</span>
            <span class="badge text-bg-warning ms-2">Prototype</span>
        </div>
        <div class="small text-muted">
            <i class="fa-regular fa-clock"></i> ข้อมูลล่าสุด: {{ $fetchedAt ? \Carbon\Carbon::parse($fetchedAt)->format('d/m/Y H:i:s') : '-' }}
            (cache 10 นาที · WMSOne + ERP)
        </div>
    </div>

    @foreach ($dataErrors as $err)
        <div class="alert alert-danger py-2">{{ $err }}</div>
    @endforeach

    {{-- Status cards --}}
    <div class="row g-2 mb-3">
        @foreach ($statusCards as $key => $c)
            <div class="col-6 col-md-4 col-xl">
                @php $isActive = $filters['status'] === $key; @endphp
                <a href="{{ $key === 'WAITING_QC' ? '#' : route('wip.index', $q(['status' => $isActive ? null : $key])) }}"
                    class="text-decoration-none text-reset">
                    <div class="card shadow-sm h-100 wip-card b-{{ $c['class'] }} {{ $isActive ? 'active' : '' }}">
                        <div class="card-body py-2">
                            <div class="small text-muted"><i class="fa-solid {{ $c['icon'] }}"></i> {{ $c['label'] }}</div>
                            <div class="small">{{ $c['th'] }}</div>
                            @if ($key === 'WAITING_QC')
                                <div class="wip-ton text-muted">N/A</div>
                                <div class="small text-muted">ยังไม่มีข้อมูลใน WMSOne</div>
                            @else
                                <div class="wip-ton">{{ $ton($c['kg']) }} <small class="fs-6 fw-normal">ตัน</small></div>
                                <div class="small text-muted">
                                    {{ number_format($c['wo_count']) }} WO
                                    @if ($c['critical'] > 0 && $key !== 'COMPLETED')
                                        · <span class="text-danger">Critical {{ $c['critical'] }}</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="row g-3 mb-3">
        {{-- WIP by Work Center --}}
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-bold">WIP แยกตาม Work Center <small class="text-muted fw-normal">(ตัน)</small></div>
                <div class="card-body p-0 wip-scroll">
                    <table class="table table-sm table-hover mb-0 wip-table">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>WC</th>
                                <th class="text-end">รอเริ่ม</th>
                                <th class="text-end">กำลังผลิต</th>
                                <th class="text-end">รอแพ็ค</th>
                                <th class="text-end">รวม</th>
                                <th class="text-end">WO</th>
                                <th style="width:25%"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($wcSummary as $w)
                                <tr>
                                    <td>
                                        <a href="{{ route('wip.index', $q(['wc' => $w['wc_code']])) }}" class="fw-semibold">{{ $w['wc_code'] }}</a>
                                        <div class="small text-muted">{{ $w['wc_name'] }}</div>
                                    </td>
                                    <td class="text-end">{{ $w['waiting_prod'] > 0 ? $ton($w['waiting_prod']) : '-' }}</td>
                                    <td class="text-end">{{ $w['in_process'] > 0 ? $ton($w['in_process']) : '-' }}</td>
                                    <td class="text-end">{{ $w['waiting_pack'] > 0 ? $ton($w['waiting_pack']) : '-' }}</td>
                                    <td class="text-end fw-bold">{{ $ton($w['total']) }}</td>
                                    <td class="text-end">{{ $w['wo_count'] }}</td>
                                    <td>
                                        <div class="wip-bar">
                                            <div class="bg-secondary" style="width: {{ $w['waiting_prod'] / $maxWc * 100 }}%"></div>
                                            <div class="bg-primary" style="width: {{ $w['in_process'] / $maxWc * 100 }}%"></div>
                                            <div class="bg-info" style="width: {{ $w['waiting_pack'] / $maxWc * 100 }}%"></div>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted py-3">ไม่มีข้อมูล</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6 d-flex flex-column gap-3">
            {{-- Aging --}}
            <div class="card shadow-sm">
                <div class="card-header bg-white fw-bold">Aging (วันที่ค้างในสถานะปัจจุบัน)
                    <small class="text-muted fw-normal">0–7 Normal · 8–15 Monitor · 16–30 Warning · &gt;30 Critical</small>
                </div>
                <div class="card-body py-2">
                    <div class="row g-2 text-center">
                        @foreach ($agingSummary as $k => $a)
                            <div class="col-3">
                                <a href="{{ route('wip.index', $q(['aging' => $filters['aging'] === $k ? null : $k])) }}" class="text-decoration-none">
                                    <div class="border rounded py-2 {{ $filters['aging'] === $k ? 'border-primary border-2' : '' }}">
                                        <span class="badge text-bg-{{ $a['class'] }}">{{ $a['label'] }}</span>
                                        <div class="fs-5 fw-bold text-body">{{ $a['wo_count'] }} <small class="fs-6 fw-normal">WO</small></div>
                                        <div class="small text-muted">{{ $ton($a['kg']) }} ตัน</div>
                                    </div>
                                </a>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Machine --}}
            <div class="card shadow-sm flex-grow-1">
                <div class="card-header bg-white fw-bold">Top 15 เครื่องจักรที่มีงานค้าง <small class="text-muted fw-normal">(เครื่องที่รับล่าสุดของขั้นนั้น)</small></div>
                <div class="card-body p-0 wip-scroll" style="max-height: 260px">
                    <table class="table table-sm mb-0 wip-table">
                        <tbody>
                            @forelse ($machineSummary as $m)
                                <tr>
                                    <td class="fw-semibold">{{ $m['wc_code'] }}</td>
                                    <td>{{ $m['machine'] }}</td>
                                    <td class="text-end">{{ $ton($m['kg']) }} ตัน</td>
                                    <td class="text-end text-muted">{{ $m['wo_count'] }} WO</td>
                                    <td style="width:30%">
                                        <div class="wip-bar"><div class="bg-primary" style="width: {{ $m['kg'] / $maxMc * 100 }}%"></div></div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td class="text-center text-muted py-3">ไม่มีข้อมูล</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- WO list --}}
    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span class="fw-bold">
                รายการ WO
                @if ($filters['status'] !== '')
                    · <span class="badge text-bg-{{ $statuses[$filters['status']]['class'] }}">{{ $statuses[$filters['status']]['label'] }}</span>
                    <a href="{{ route('wip.index', $q(['status' => null])) }}" class="small ms-1">ล้างสถานะ</a>
                @endif
            </span>
            <span class="small text-muted">{{ number_format($rows->total()) }} รายการ · เรียงตาม Aging มาก → น้อย</span>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-sm table-hover mb-0 wip-table">
                <thead class="table-light">
                    <tr>
                        <th>WO</th>
                        <th>สถานะ</th>
                        <th>Product</th>
                        <th>Customer</th>
                        <th>Size / Grade</th>
                        <th class="text-end">Qty WO</th>
                        <th class="text-end">WIP ค้าง</th>
                        <th class="text-end">รอรับ FG</th>
                        <th class="text-end">รับ FG แล้ว</th>
                        <th>ขั้นปัจจุบัน</th>
                        <th class="text-end">Aging</th>
                        <th>ล่าสุด</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        @php $st = $statuses[$r['status']]; $ag = $agingBands[$r['aging_band']]; @endphp
                        <tr>
                            <td>
                                <a href="{{ route('wip.detail', ['site' => strtolower($r['site']), 'workorder' => $r['workorder_id']]) }}" class="fw-semibold">{{ $r['wo_no'] }}</a>
                                <div class="small text-muted">{{ $r['site'] }} · เปิด {{ $fmtDate($r['dateopen']) }}</div>
                            </td>
                            <td>
                                <span class="badge text-bg-{{ $st['class'] }}">{{ $st['label'] }}</span>
                                @if ($r['suspended'])<div class="small text-danger">Suspended</div>@endif
                            </td>
                            <td class="text-wrap-sm">
                                <div class="small fw-semibold">{{ $r['part_number'] }}</div>
                                <div class="small text-muted">{{ \Illuminate\Support\Str::limit($r['part_desc'], 60) }}</div>
                            </td>
                            <td class="small">{{ \Illuminate\Support\Str::limit($r['customer'], 28) }}</td>
                            <td class="small">{{ $r['size'] ?: '-' }}<div class="text-muted">{{ $r['grade'] }}</div></td>
                            <td class="text-end">{{ $kg($r['qty']) }}</td>
                            <td class="text-end fw-bold">{{ $r['wip_total'] > 0 ? $kg($r['wip_total']) : '-' }}</td>
                            <td class="text-end {{ $r['buckets']['WAITING_FG'] > 0 ? 'text-warning-emphasis fw-semibold' : '' }}">
                                {{ $r['buckets']['WAITING_FG'] > 0 ? $kg($r['buckets']['WAITING_FG']) : '-' }}
                            </td>
                            <td class="text-end">
                                {{ $r['erp_received'] > 0 ? $kg($r['erp_received']) : '-' }}
                                @unless ($r['erp_found'])<div class="small text-danger">ไม่พบใน ERP</div>@endunless
                            </td>
                            <td class="small">
                                <span class="fw-semibold">{{ $r['current_wc'] }}</span>
                                @if ($r['current_machine'] !== '-') <span class="text-muted">/ {{ $r['current_machine'] }}</span>@endif
                                <div class="text-muted">{{ $r['route_text'] ?: 'ไม่พบ Routing' }}</div>
                            </td>
                            <td class="text-end"><span class="badge text-bg-{{ $ag['class'] }}">{{ $r['aging_days'] }} วัน</span></td>
                            <td class="small">{{ $fmtDate($r['last_tx']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="12" class="text-center text-muted py-4">ไม่พบรายการ</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($rows->hasPages())
            <div class="card-footer bg-white">{{ $rows->links() }}</div>
        @endif
    </div>

    <div class="small text-muted mt-2">
        หมายเหตุ Prototype: น้ำหนักคิดจากการไหลของงานแต่ละขั้น (ไม่บวกยอดซ้ำข้ามขั้น) ·
        Waiting FG = output ขั้นสุดท้ายใน WMSOne − ยอดรับเข้าใน ERP ·
        Hold/Rework = ยอด NCR (receivetype 3) ของ WO ที่ยังผลิตไม่ครบ + WIP ของ WO ที่ Suspended ·
        Waiting QC ยังไม่มีข้อมูลให้คำนวณ
    </div>
@endsection
