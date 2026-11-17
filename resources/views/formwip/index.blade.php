@extends('layouts.layout')

@section('title', 'WIP Monitoring')
@section('page-title', 'WIP & Stock Pending Monitoring')

@push('styles')
    <style>
        .wip { --wip-radius: .75rem; font-variant-numeric: tabular-nums; }
        .wip .card { border: 1px solid rgba(0, 0, 0, .06); border-radius: var(--wip-radius); }
        .wip .card-header { background: transparent; border-bottom: 1px solid rgba(0, 0, 0, .06); font-weight: 600; padding: .75rem 1rem; }
        .wip .muted-label { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: var(--bs-secondary-color); }

        /* toolbar */
        .wip-toolbar .btn-check + .btn { min-width: 4.5rem; }
        .wip-chip { display: inline-flex; align-items: center; gap: .35rem; font-size: .8rem; padding: .2rem .6rem; border-radius: 999px; background: var(--bs-primary-bg-subtle); color: var(--bs-primary-text-emphasis); text-decoration: none; }
        .wip-chip:hover { background: var(--bs-primary-border-subtle); }

        /* hero */
        .wip-hero-num { font-size: 2.6rem; font-weight: 700; line-height: 1; }
        .wip-stack { display: flex; height: 14px; border-radius: 7px; overflow: hidden; background: var(--bs-tertiary-bg); }
        .wip-stack > div { height: 100%; }
        .wip-legend { display: grid; grid-template-columns: auto 1fr auto; gap: .25rem .5rem; font-size: .85rem; align-items: center; }
        .wip-dot { width: .65rem; height: .65rem; border-radius: 50%; display: inline-block; }

        /* pipeline */
        .wip-flow { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem; }
        .wip-stage { position: relative; display: block; color: inherit; text-decoration: none; border-radius: var(--wip-radius); padding: .9rem 1rem; background: var(--bs-body-bg); border: 1px solid rgba(0, 0, 0, .08); border-top: 4px solid var(--stage-color); transition: box-shadow .15s, transform .15s; height: 100%; }
        .wip-stage:hover { box-shadow: 0 .4rem 1rem rgba(0, 0, 0, .08); transform: translateY(-1px); color: inherit; }
        .wip-stage.active { box-shadow: 0 0 0 2px var(--stage-color); }
        .wip-flow .wip-stage:not(:last-child)::after { content: "\f054"; font-family: "Font Awesome 6 Free"; font-weight: 900; position: absolute; right: -.62rem; top: 50%; transform: translateY(-50%); font-size: .7rem; color: var(--bs-secondary-color); background: var(--bs-body-bg); border-radius: 50%; width: 1.1rem; height: 1.1rem; display: grid; place-items: center; z-index: 1; border: 1px solid rgba(0, 0, 0, .08); }
        .wip-stage .ton { font-size: 1.75rem; font-weight: 700; line-height: 1.1; }
        .wip-stage .ton small { font-size: .85rem; font-weight: 400; color: var(--bs-secondary-color); }
        .wip-stage .title { font-weight: 600; font-size: .9rem; }
        .wip-stage .sub { font-size: .78rem; color: var(--bs-secondary-color); }
        .wip-side { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; }
        .wip-side .wip-stage { padding: .65rem .9rem; border-top-width: 1px; border-left: 4px solid var(--stage-color); }
        .wip-side .wip-stage .ton { font-size: 1.25rem; }
        .wip-stage.disabled { opacity: .55; pointer-events: none; }

        /* tables */
        .wip-table { font-size: .875rem; }
        .wip-table th { font-size: .75rem; font-weight: 600; color: var(--bs-secondary-color); text-transform: uppercase; letter-spacing: .03em; white-space: nowrap; }
        .wip-table td { vertical-align: middle; }
        .wip-table tbody tr[data-href] { cursor: pointer; }
        .wip-scroll { max-height: 440px; overflow-y: auto; }
        .wip-scroll thead th { position: sticky; top: 0; background: var(--bs-body-bg); z-index: 1; }
        .wip-bar { display: flex; height: 8px; border-radius: 4px; overflow: hidden; background: var(--bs-tertiary-bg); min-width: 80px; }
        .wip-age { display: inline-block; min-width: 3.4rem; text-align: center; font-weight: 600; font-size: .78rem; padding: .2rem .45rem; border-radius: 999px; }
        .wip-status { display: inline-flex; align-items: center; gap: .35rem; font-size: .8rem; white-space: nowrap; }

        /* aging */
        .wip-aging { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .5rem; }
        .wip-aging a { text-decoration: none; color: inherit; border-radius: .6rem; padding: .6rem .75rem; border: 1px solid rgba(0, 0, 0, .08); border-left: 4px solid var(--band-color); display: block; }
        .wip-aging a.active { box-shadow: 0 0 0 2px var(--band-color); }
        .wip-aging .n { font-size: 1.4rem; font-weight: 700; line-height: 1.1; }

        @media (max-width: 991.98px) {
            .wip-flow { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .wip-flow .wip-stage:not(:last-child)::after { display: none; }
        }
        @media (max-width: 575.98px) {
            .wip-side { grid-template-columns: 1fr; }
            .wip-aging { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .wip-hero-num { font-size: 2.1rem; }
        }
    </style>
@endpush

@section('content')
    @php
        $colors = [
            'WAITING_PRODUCTION' => '#6c757d',
            'IN_PROCESS' => '#0d6efd',
            'WAITING_PACKAGING' => '#0dcaf0',
            'WAITING_QC' => '#adb5bd',
            'WAITING_FG' => '#f0ad00',
            'HOLD' => '#dc3545',
            'COMPLETED' => '#198754',
        ];
        $bandColors = ['NORMAL' => '#198754', 'MONITOR' => '#0dcaf0', 'WARNING' => '#f0ad00', 'CRITICAL' => '#dc3545'];
        $ton = fn($kg) => number_format(($kg ?? 0) / 1000, 1);
        $kg = fn($v) => number_format($v ?? 0, 0);
        $fmtDate = fn($v) => $v ? \Carbon\Carbon::parse($v)->format('d/m/y') : '-';
        $q = fn(array $over) => array_merge(request()->except(['page', 'refresh']), $over);

        $flowKeys = ['WAITING_PRODUCTION', 'IN_PROCESS', 'WAITING_PACKAGING', 'WAITING_FG'];
        $sideKeys = ['HOLD', 'WAITING_QC', 'COMPLETED'];
        $wipKeys = ['WAITING_PRODUCTION', 'IN_PROCESS', 'WAITING_PACKAGING', 'WAITING_FG', 'HOLD'];
        $totalKg = max(1, $totalWipKg);
        $woWithWip = collect($agingSummary)->sum('wo_count');
        $criticalWo = $agingSummary['CRITICAL']['wo_count'] ?? 0;
        $maxWc = max(1, $wcSummary->max('total') ?? 1);
        $topMachines = $machineSummary['top'];
        $maxMc = max(1, $topMachines->max('kg') ?? 1);
        $siteLabels = ['WIRE' => 'Wire', 'PLUS' => 'Plus', 'ALL' => 'ทั้งหมด'];
        $dayOptions = [90, 180, 365, 730];

        // chips ของ filter ที่ใช้งานอยู่
        $chips = [];
        if ($filters['status'] !== '') $chips[] = ['สถานะ: ' . $statuses[$filters['status']]['label'], 'status'];
        if ($filters['wc'] !== '') $chips[] = ['WC: ' . $filters['wc'], 'wc'];
        if ($filters['aging'] !== '') $chips[] = ['Aging: ' . $agingBands[$filters['aging']]['label'], 'aging'];
        if ($filters['q'] !== '') $chips[] = ['ค้นหา: ' . $filters['q'], 'q'];
    @endphp

    <div class="wip">
        {{-- ============ Toolbar ============ --}}
        <form method="GET" action="{{ route('wip.index') }}" class="card shadow-sm mb-3 wip-toolbar" id="wipFilter">
            <div class="card-body py-2">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <div class="btn-group btn-group-sm" role="group" aria-label="Site">
                        @foreach ($siteLabels as $v => $l)
                            <input type="radio" class="btn-check" name="site" id="site-{{ $v }}" value="{{ $v }}" @checked($filters['site'] === $v) onchange="this.form.submit()">
                            <label class="btn btn-outline-primary" for="site-{{ $v }}">{{ $l }}</label>
                        @endforeach
                    </div>

                    <div class="flex-grow-1" style="min-width: 220px; max-width: 420px">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-transparent"><i class="fa-solid fa-magnifying-glass text-secondary"></i></span>
                            <input type="text" name="q" value="{{ $filters['q'] }}" class="form-control" placeholder="WO, Product, Customer, Size, Grade, SO">
                        </div>
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#wipMoreFilters">
                        <i class="fa-solid fa-sliders"></i> ตัวกรองเพิ่มเติม
                    </button>
                    <button class="btn btn-sm btn-primary"><i class="fa-solid fa-filter"></i> กรอง</button>

                    <div class="ms-auto d-flex align-items-center gap-2 small text-secondary">
                        <span class="badge rounded-pill text-bg-warning">Prototype</span>
                        <span><i class="fa-regular fa-clock"></i> {{ $fetchedAt ? \Carbon\Carbon::parse($fetchedAt)->format('d/m/Y H:i') : '-' }}</span>
                        <a href="{{ route('wip.index', $q(['refresh' => 1])) }}" class="btn btn-sm btn-outline-success" title="ดึงข้อมูลใหม่จาก WMSOne + ERP">
                            <i class="fa-solid fa-rotate"></i><span class="d-none d-md-inline"> อัปเดต</span>
                        </a>
                    </div>
                </div>

                <div class="collapse {{ $filters['wc'] !== '' || $filters['aging'] !== '' || $filters['days'] !== 180 || $filters['scope'] !== 'pk' || !$filters['only_wip'] ? 'show' : '' }}" id="wipMoreFilters">
                    <div class="row g-2 pt-2 mt-2 border-top">
                        <div class="col-6 col-md-3 col-xl-2">
                            <label class="form-label small text-secondary mb-0">WO เปิดย้อนหลัง</label>
                            <select name="days" class="form-select form-select-sm">
                                @foreach ($dayOptions as $d)
                                    <option value="{{ $d }}" @selected($filters['days'] === $d)>{{ $d }} วัน</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 col-md-3 col-xl-2">
                            <label class="form-label small text-secondary mb-0">Route</label>
                            <select name="scope" class="form-select form-select-sm">
                                <option value="pk" @selected($filters['scope'] === 'pk')>จบด้วย PK (Pilot)</option>
                                <option value="all" @selected($filters['scope'] === 'all')>ทุก Route</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-2 col-xl-2">
                            <label class="form-label small text-secondary mb-0">Work Center</label>
                            <select name="wc" class="form-select form-select-sm">
                                <option value="">ทั้งหมด</option>
                                @foreach ($wcOptions as $wc)
                                    <option value="{{ $wc }}" @selected($filters['wc'] === $wc)>{{ $wc }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 col-md-2 col-xl-2">
                            <label class="form-label small text-secondary mb-0">Aging</label>
                            <select name="aging" class="form-select form-select-sm">
                                <option value="">ทั้งหมด</option>
                                @foreach ($agingBands as $k => $b)
                                    <option value="{{ $k }}" @selected($filters['aging'] === $k)>{{ $b['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 col-md-2 col-xl-2">
                            <label class="form-label small text-secondary mb-0">แสดง WO</label>
                            <select name="only_wip" class="form-select form-select-sm">
                                <option value="1" @selected($filters['only_wip'])>มี WIP ค้าง</option>
                                <option value="0" @selected(!$filters['only_wip'])>ที่เปิดอยู่ทั้งหมด</option>
                            </select>
                        </div>
                    </div>
                </div>

                @if ($filters['status'] !== '')
                    <input type="hidden" name="status" value="{{ $filters['status'] }}">
                @endif

                @if ($chips)
                    <div class="d-flex flex-wrap align-items-center gap-2 pt-2">
                        @foreach ($chips as [$text, $key])
                            <a href="{{ route('wip.index', $q([$key => null])) }}" class="wip-chip">{{ $text }} <i class="fa-solid fa-xmark"></i></a>
                        @endforeach
                        <a href="{{ route('wip.index', ['site' => $filters['site']]) }}" class="small text-secondary">ล้างทั้งหมด</a>
                    </div>
                @endif
            </div>
        </form>

        @foreach ($dataErrors as $err)
            <div class="alert alert-danger py-2"><i class="fa-solid fa-circle-exclamation"></i> {{ $err }}</div>
        @endforeach

        {{-- ============ Hero + Pipeline ============ --}}
        <div class="row g-3 mb-3">
            <div class="col-xl-4">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <div class="muted-label">WIP คงค้างรวม · {{ $siteLabels[$filters['site']] }}</div>
                        <div class="d-flex align-items-baseline gap-2 mt-1">
                            <span class="wip-hero-num">{{ $ton($totalWipKg) }}</span>
                            <span class="fs-5 text-secondary">ตัน</span>
                        </div>
                        <div class="small text-secondary mb-3">
                            {{ number_format($woWithWip) }} WO มีงานค้าง
                            @if ($criticalWo > 0)
                                · <a href="{{ route('wip.index', $q(['aging' => 'CRITICAL', 'status' => null])) }}" class="text-danger fw-semibold text-decoration-none">ค้างเกิน 30 วัน {{ $criticalWo }} WO</a>
                            @endif
                        </div>

                        <div class="wip-stack mb-2" role="img" aria-label="สัดส่วน WIP ตามสถานะ">
                            @foreach ($wipKeys as $k)
                                @php $pct = ($statusCards[$k]['kg'] ?? 0) / $totalKg * 100; @endphp
                                @if ($pct > 0)
                                    <div style="width: {{ $pct }}%; background: {{ $colors[$k] }}" title="{{ $statuses[$k]['label'] }} {{ number_format($pct, 1) }}%"></div>
                                @endif
                            @endforeach
                        </div>
                        <div class="wip-legend">
                            @foreach ($wipKeys as $k)
                                @php $c = $statusCards[$k]; @endphp
                                <span class="wip-dot" style="background: {{ $colors[$k] }}"></span>
                                <span>{{ $c['th'] }}</span>
                                <span class="text-end"><strong>{{ $ton($c['kg']) }}</strong> <span class="text-secondary">ตัน · {{ number_format(($c['kg'] ?? 0) / $totalKg * 100, 0) }}%</span></span>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-8 d-flex flex-column gap-3">
                <div class="wip-flow">
                    @foreach ($flowKeys as $k)
                        @php $c = $statusCards[$k]; $active = $filters['status'] === $k; @endphp
                        <a href="{{ route('wip.index', $q(['status' => $active ? null : $k])) }}" class="wip-stage {{ $active ? 'active' : '' }}" style="--stage-color: {{ $colors[$k] }}">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="title">{{ $c['th'] }}</span>
                                <i class="fa-solid {{ $c['icon'] }}" style="color: {{ $colors[$k] }}"></i>
                            </div>
                            <div class="sub mb-1">{{ $c['label'] }}</div>
                            <div class="ton">{{ $ton($c['kg']) }} <small>ตัน</small></div>
                            <div class="sub mt-1">
                                {{ number_format($c['wo_count']) }} WO
                                @if ($c['critical'] > 0)
                                    · <span class="text-danger fw-semibold">{{ $c['critical'] }} Critical</span>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>
                <div class="wip-side">
                    @foreach ($sideKeys as $k)
                        @php $c = $statusCards[$k]; $active = $filters['status'] === $k; $isQc = $k === 'WAITING_QC'; @endphp
                        <a href="{{ $isQc ? '#' : route('wip.index', $q(['status' => $active ? null : $k])) }}"
                            class="wip-stage {{ $active ? 'active' : '' }} {{ $isQc ? 'disabled' : '' }}" style="--stage-color: {{ $colors[$k] }}">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="title"><i class="fa-solid {{ $c['icon'] }} me-1" style="color: {{ $colors[$k] }}"></i>{{ $c['th'] }}</div>
                                    <div class="sub">{{ $isQc ? 'ยังไม่มีข้อมูลใน WMSOne' : number_format($c['wo_count']) . ' WO' }}</div>
                                </div>
                                <div class="ton text-end">{{ $isQc ? 'N/A' : $ton($c['kg']) }} @unless ($isQc)<small>ตัน</small>@endunless</div>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- ============ Work Center / Aging / Machine ============ --}}
        <div class="row g-3 mb-3">
            <div class="col-xl-7">
                <div class="card shadow-sm h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fa-solid fa-industry me-1 text-secondary"></i> งานค้างแยกตาม Work Center</span>
                        <span class="d-none d-md-flex gap-3 small fw-normal text-secondary">
                            <span><span class="wip-dot" style="background: {{ $colors['WAITING_PRODUCTION'] }}"></span> รอเริ่ม</span>
                            <span><span class="wip-dot" style="background: {{ $colors['IN_PROCESS'] }}"></span> กำลังผลิต</span>
                            <span><span class="wip-dot" style="background: {{ $colors['WAITING_PACKAGING'] }}"></span> รอแพ็ค</span>
                        </span>
                    </div>
                    <div class="card-body p-0 wip-scroll">
                        <table class="table table-hover mb-0 wip-table">
                            <thead>
                                <tr>
                                    <th class="ps-3">Work Center</th>
                                    <th style="width: 38%"></th>
                                    <th class="text-end">ตัน</th>
                                    <th class="text-end pe-3">WO</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($wcSummary as $w)
                                    <tr data-href="{{ route('wip.index', $q(['wc' => $w['wc_code'], 'status' => null])) }}" class="{{ $filters['wc'] === $w['wc_code'] ? 'table-active' : '' }}">
                                        <td class="ps-3">
                                            <span class="fw-semibold">{{ $w['wc_code'] }}</span>
                                            <span class="text-secondary small ms-1">{{ \Illuminate\Support\Str::limit($w['wc_name'], 28) }}</span>
                                        </td>
                                        <td>
                                            <div class="wip-bar" title="รอเริ่ม {{ $ton($w['waiting_prod']) }} · กำลังผลิต {{ $ton($w['in_process']) }} · รอแพ็ค {{ $ton($w['waiting_pack']) }} ตัน">
                                                <div style="width: {{ $w['waiting_prod'] / $maxWc * 100 }}%; background: {{ $colors['WAITING_PRODUCTION'] }}"></div>
                                                <div style="width: {{ $w['in_process'] / $maxWc * 100 }}%; background: {{ $colors['IN_PROCESS'] }}"></div>
                                                <div style="width: {{ $w['waiting_pack'] / $maxWc * 100 }}%; background: {{ $colors['WAITING_PACKAGING'] }}"></div>
                                            </div>
                                        </td>
                                        <td class="text-end fw-semibold">{{ $ton($w['total']) }}</td>
                                        <td class="text-end pe-3 text-secondary">{{ $w['wo_count'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-center text-secondary py-4">ไม่มีงานค้าง</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-xl-5 d-flex flex-column gap-3">
                <div class="card shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fa-regular fa-hourglass-half me-1 text-secondary"></i> Aging</span>
                        <span class="small fw-normal text-secondary">วันที่ค้างในสถานะปัจจุบัน</span>
                    </div>
                    <div class="card-body">
                        <div class="wip-aging">
                            @foreach ($agingSummary as $k => $a)
                                @php $active = $filters['aging'] === $k; @endphp
                                <a href="{{ route('wip.index', $q(['aging' => $active ? null : $k])) }}" class="{{ $active ? 'active' : '' }}" style="--band-color: {{ $bandColors[$k] }}">
                                    <div class="small fw-semibold" style="color: {{ $bandColors[$k] }}">{{ $a['label'] }}</div>
                                    <div class="n">{{ $a['wo_count'] }} <small class="fs-6 fw-normal text-secondary">WO</small></div>
                                    <div class="small text-secondary">{{ $ton($a['kg']) }} ตัน</div>
                                </a>
                            @endforeach
                        </div>
                        <div class="small text-secondary mt-2">0–7 วัน Normal · 8–15 Monitor · 16–30 Warning · มากกว่า 30 Critical</div>
                    </div>
                </div>

                <div class="card shadow-sm flex-grow-1">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fa-solid fa-gears me-1 text-secondary"></i> เครื่องจักรที่มีงานค้างมากสุด</span>
                        <span class="small fw-normal text-secondary">Top 15</span>
                    </div>
                    <div class="card-body p-0 wip-scroll" style="max-height: 250px">
                        <table class="table table-sm mb-0 wip-table">
                            <tbody>
                                @forelse ($topMachines as $m)
                                    <tr>
                                        <td class="ps-3"><span class="fw-semibold">{{ $m['machine'] }}</span> <span class="text-secondary small">{{ $m['wc_code'] }}</span></td>
                                        <td style="width: 35%"><div class="wip-bar"><div style="width: {{ $m['kg'] / $maxMc * 100 }}%; background: {{ $colors['IN_PROCESS'] }}"></div></div></td>
                                        <td class="text-end fw-semibold">{{ $ton($m['kg']) }}</td>
                                        <td class="text-end pe-3 text-secondary small">{{ $m['wo_count'] }} WO</td>
                                    </tr>
                                @empty
                                    <tr><td class="text-center text-secondary py-4">ไม่มีข้อมูล</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if ($machineSummary['unassigned_kg'] > 0)
                        <div class="card-footer bg-transparent small text-secondary">
                            <i class="fa-solid fa-circle-info"></i>
                            งานที่รอเข้าขั้นถัดไปแต่ยังไม่ได้ลงเครื่อง {{ $ton($machineSummary['unassigned_kg']) }} ตัน ({{ $machineSummary['unassigned_wo'] }} รายการ) ไม่รวมในตารางนี้
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- ============ WO list ============ --}}
        <div class="card shadow-sm">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><i class="fa-solid fa-list me-1 text-secondary"></i> รายการ WO
                    @if ($filters['status'] !== '')
                        <span class="wip-status ms-2"><span class="wip-dot" style="background: {{ $colors[$filters['status']] }}"></span>{{ $statuses[$filters['status']]['label'] }}</span>
                    @endif
                </span>
                <span class="small fw-normal text-secondary">{{ number_format($rows->total()) }} รายการ · เรียงตามค้างนานสุด</span>
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover mb-0 wip-table">
                    <thead>
                        <tr>
                            <th class="ps-3">WO</th>
                            <th>สถานะ</th>
                            <th>Product</th>
                            <th>Customer</th>
                            <th class="text-end">Qty WO</th>
                            <th class="text-end">WIP ค้าง</th>
                            <th class="text-end">FG รับแล้ว / รอรับ</th>
                            <th>ขั้นปัจจุบัน</th>
                            <th class="text-center">ค้าง</th>
                            <th class="pe-3">เคลื่อนไหวล่าสุด</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $r)
                            @php
                                $st = $statuses[$r['status']];
                                $bc = $bandColors[$r['aging_band']];
                                $url = route('wip.detail', ['site' => strtolower($r['site']), 'workorder' => $r['workorder_id']]);
                            @endphp
                            <tr data-href="{{ $url }}">
                                <td class="ps-3">
                                    <a href="{{ $url }}" class="fw-semibold text-decoration-none">{{ $r['wo_no'] }}</a>
                                    <div class="small text-secondary">{{ $r['site'] }} · {{ $fmtDate($r['dateopen']) }}</div>
                                </td>
                                <td>
                                    <span class="wip-status"><span class="wip-dot" style="background: {{ $colors[$r['status']] }}"></span>{{ $st['th'] }}</span>
                                    @if ($r['suspended'])<div class="small text-danger">Suspended</div>@endif
                                </td>
                                <td style="min-width: 220px; max-width: 320px">
                                    <div class="fw-semibold small text-truncate">{{ $r['part_number'] }}</div>
                                    <div class="small text-secondary text-truncate" title="{{ $r['part_desc'] }}">
                                        {{ $r['size'] ?: '' }}{{ $r['grade'] ? ' · ' . $r['grade'] : '' }}{{ ($r['size'] || $r['grade']) ? ' · ' : '' }}{{ $r['part_desc'] }}
                                    </div>
                                </td>
                                <td class="small text-truncate" style="max-width: 180px" title="{{ $r['customer'] }}">{{ $r['customer'] }}</td>
                                <td class="text-end text-secondary">{{ $kg($r['qty']) }}</td>
                                <td class="text-end fw-bold">{{ $r['wip_total'] > 0 ? $kg($r['wip_total']) : '–' }}</td>
                                <td class="text-end small">
                                    @if (!$r['erp_found'])
                                        <span class="text-danger">ไม่พบใน ERP</span>
                                    @else
                                        {{ $r['erp_received'] > 0 ? $kg($r['erp_received']) : '–' }}
                                        <span class="text-secondary">/</span>
                                        <span class="{{ $r['buckets']['WAITING_FG'] > 0 ? 'fw-semibold text-warning-emphasis' : 'text-secondary' }}">{{ $r['buckets']['WAITING_FG'] > 0 ? $kg($r['buckets']['WAITING_FG']) : '–' }}</span>
                                    @endif
                                </td>
                                <td class="small">
                                    <span class="fw-semibold">{{ $r['current_wc'] }}</span>@if ($r['current_machine'] !== '-')<span class="text-secondary"> · {{ $r['current_machine'] }}</span>@endif
                                    <div class="text-secondary text-truncate" style="max-width: 220px" title="{{ $r['route_text'] }}">{{ $r['route_text'] ?: 'ไม่พบ Routing' }}</div>
                                </td>
                                <td class="text-center">
                                    <span class="wip-age" style="background: {{ $bc }}1f; color: {{ $bc }}">{{ $r['aging_days'] }} วัน</span>
                                </td>
                                <td class="pe-3 small text-secondary">{{ $fmtDate($r['last_tx']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center text-secondary py-5">
                                    <i class="fa-regular fa-folder-open fs-3 d-block mb-2"></i> ไม่พบรายการตามเงื่อนไขที่เลือก
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($rows->hasPages())
                <div class="card-footer bg-transparent">{{ $rows->links() }}</div>
            @endif
        </div>

        <details class="small text-secondary mt-3">
            <summary>วิธีคำนวณ (Prototype)</summary>
            <ul class="mt-2 mb-0">
                <li>น้ำหนักคิดจากการไหลของงานแต่ละขั้น ไม่บวกยอดซ้ำข้ามขั้น — ค้างที่ขั้น i = output ของดีขั้นก่อนหน้า − output ขั้นนี้ − NCR</li>
                <li>รอรับเข้า FG = output ขั้นสุดท้ายใน WMSOne − ยอดรับเข้าใน ERP</li>
                <li>Hold / Rework = ยอด NCR (receivetype 3) ของ WO ที่ยังผลิตไม่ครบ + WIP ของ WO ที่ Suspended</li>
                <li>Waiting QC ยังไม่มีข้อมูลให้คำนวณ</li>
            </ul>
        </details>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('.wip tr[data-href]').forEach(function (tr) {
            tr.addEventListener('click', function (e) {
                if (e.target.closest('a, button, input, select')) return;
                window.location.href = tr.dataset.href;
            });
        });
    </script>
@endpush
