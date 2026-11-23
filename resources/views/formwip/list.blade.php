@extends('layouts.layout')

@section('title', 'WIP · รายการ WO')
@section('page-title', 'WIP & Stock Pending Monitoring')

@push('styles')
    @include('formwip.partials._styles')
@endpush

@section('content')
    @php
        $colors = [
            'WAITING_PRODUCTION' => '#6c757d', 'IN_PROCESS' => '#0d6efd', 'WAITING_PACKAGING' => '#0dcaf0',
            'WAITING_QC' => '#adb5bd', 'WAITING_FG' => '#f0ad00', 'HOLD' => '#dc3545', 'COMPLETED' => '#198754',
        ];
        $bandColors = ['NORMAL' => '#198754', 'MONITOR' => '#0dcaf0', 'WARNING' => '#f0ad00', 'CRITICAL' => '#dc3545'];
        $ton = fn($kg) => number_format(($kg ?? 0) / 1000, 1);
        $q = fn(array $over) => array_merge(request()->except(['page', 'refresh']), $over);
        $sortLabels = ['aging' => 'ค้างนานสุด', 'wip' => 'WIP มากสุด', 'due' => 'Due ใกล้สุด', 'open' => 'เปิด WO ล่าสุด', 'wo' => 'เลขที่ WO'];
        $stripKeys = ['WAITING_PRODUCTION', 'IN_PROCESS', 'WAITING_PACKAGING', 'WAITING_FG', 'HOLD', 'COMPLETED'];
    @endphp

    <div class="wip">
        @include('formwip.partials._tabs')
        @include('formwip.partials._filter', ['mode' => 'list'])

        @foreach ($dataErrors as $err)
            <div class="alert alert-danger py-2"><i class="fa-solid fa-circle-exclamation"></i> {{ $err }}</div>
        @endforeach

        {{-- สรุปตาม filter + สลับสถานะเร็ว --}}
        <div class="card shadow-sm mb-3">
            <div class="card-body py-2">
                <div class="wip-summary-strip">
                    <span class="me-2">
                        <span class="muted-label">WIP ตาม filter</span>
                        <span class="fs-5 fw-bold ms-1">{{ $ton($totalWipKg) }}</span> <span class="text-secondary">ตัน</span>
                    </span>
                    <a href="{{ route('wip.list', $q(['status' => null])) }}" class="{{ $filters['status'] === '' ? 'active' : '' }}" style="--stage-color: var(--bs-primary)">
                        ทุกสถานะ
                    </a>
                    @foreach ($stripKeys as $k)
                        @php $c = $statusCards[$k]; @endphp
                        @continue($c['wo_count'] === 0 && $filters['status'] !== $k)
                        <a href="{{ route('wip.list', $q(['status' => $k])) }}" class="{{ $filters['status'] === $k ? 'active' : '' }}" style="--stage-color: {{ $colors[$k] }}">
                            <span class="wip-dot" style="background: {{ $colors[$k] }}"></span>
                            {{ $c['th'] }}
                            <span class="text-secondary">{{ $ton($c['kg']) }} ต. · {{ number_format($c['wo_count']) }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- ตาราง WO --}}
        <div class="card shadow-sm">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="d-flex flex-wrap align-items-center gap-2">
                    <span><i class="fa-solid fa-list me-1 text-secondary"></i> รายการ WO</span>
                    @if ($filters['status'] !== '')
                        <span class="wip-status"><span class="wip-dot" style="background: {{ $colors[$filters['status']] }}"></span>{{ $statuses[$filters['status']]['label'] }}</span>
                        <a href="{{ route('wip.list', $q(['status' => null])) }}" class="btn btn-sm btn-outline-secondary py-0">
                            <i class="fa-solid fa-xmark"></i> ล้างสถานะ
                        </a>
                    @endif
                </span>
                <span class="d-flex flex-wrap align-items-center gap-2">
                    <span class="small fw-normal text-secondary">{{ number_format($rows->total()) }} รายการ</span>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary py-0 dropdown-toggle" data-bs-toggle="dropdown">
                            <i class="fa-solid fa-arrow-down-wide-short"></i> {{ $sortLabels[$filters['sort']] ?? 'ค้างนานสุด' }}
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            @foreach ($sortLabels as $k => $l)
                                <li><a class="dropdown-item {{ $filters['sort'] === $k ? 'active' : '' }}" href="{{ route('wip.list', $q(['sort' => $k === 'aging' ? null : $k])) }}">{{ $l }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                    <a href="{{ route('wip.export', $q([])) }}" class="btn btn-sm btn-success py-0"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
                </span>
            </div>
            <div class="card-body p-0 table-responsive">
                @include('formwip.partials._wo_table', ['woRows' => $rows, 'compact' => false])
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
                <li>Aging = จำนวนวันที่อยู่ในสถานะปัจจุบัน · ตัวเลขที่มี * = ประมาณจาก transaction ล่าสุดใน WMSOne</li>
                <li>ขั้นที่ "ครบ" ใช้เกณฑ์ % น้ำหนักเดียวกับ Production Status Tracking</li>
            </ul>
        </details>
    </div>
@endsection
