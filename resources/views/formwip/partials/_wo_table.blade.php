{{--
    ตาราง WO — @include('formwip.partials._wo_table', ['woRows' => $rows, 'compact' => false])
    ใช้ $statuses, $agingBands จากหน้าที่ include
--}}
@php
    $tColors = [
        'WAITING_PRODUCTION' => '#6c757d', 'IN_PROCESS' => '#0d6efd', 'WAITING_PACKAGING' => '#0dcaf0',
        'WAITING_QC' => '#adb5bd', 'WAITING_FG' => '#f0ad00', 'HOLD' => '#dc3545', 'COMPLETED' => '#198754',
    ];
    $tBand = ['NORMAL' => '#198754', 'MONITOR' => '#0dcaf0', 'WARNING' => '#f0ad00', 'CRITICAL' => '#dc3545'];
    $tKg = fn($v) => number_format($v ?? 0, 0);
    $tDate = fn($v) => $v ? \Carbon\Carbon::parse($v)->format('d/m/y') : '-';
    $compact = $compact ?? false;
@endphp
<table class="table table-hover mb-0 wip-table">
    <thead>
        <tr>
            <th class="ps-3">WO</th>
            <th>สถานะ</th>
            <th>Product</th>
            @unless ($compact)<th>Customer</th>@endunless
            <th class="text-end">Qty WO</th>
            <th class="text-end">WIP ค้าง</th>
            @unless ($compact)<th class="text-end">FG รับแล้ว / รอรับ</th>@endunless
            <th>ขั้นปัจจุบัน</th>
            <th class="text-center">ค้าง</th>
            <th class="pe-3">เคลื่อนไหวล่าสุด</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($woRows as $r)
            @php
                $st = $statuses[$r['status']];
                $bc = $tBand[$r['aging_band']];
                $url = route('wip.detail', ['site' => strtolower($r['site']), 'workorder' => $r['workorder_id']]);
                $exact = ($r['aging_source'] ?? 'activity') === 'status';
            @endphp
            <tr data-href="{{ $url }}">
                <td class="ps-3">
                    <a href="{{ $url }}" class="fw-semibold text-decoration-none">{{ $r['wo_no'] }}</a>
                    <div class="small text-secondary">{{ $r['site'] }} · {{ $tDate($r['dateopen']) }}</div>
                </td>
                <td>
                    <span class="wip-status"><span class="wip-dot" style="background: {{ $tColors[$r['status']] }}"></span>{{ $st['th'] }}</span>
                    @if ($r['suspended'])<div class="small text-danger">Suspended</div>@endif
                </td>
                <td style="min-width: 220px; max-width: 320px">
                    <div class="fw-semibold small text-truncate">{{ $r['part_number'] }}</div>
                    <div class="small text-secondary text-truncate" title="{{ $r['part_desc'] }}">
                        {{ $r['size'] ?: '' }}{{ $r['grade'] ? ' · ' . $r['grade'] : '' }}{{ ($r['size'] || $r['grade']) ? ' · ' : '' }}{{ $r['part_desc'] }}
                    </div>
                </td>
                @unless ($compact)
                    <td class="small text-truncate" style="max-width: 180px" title="{{ $r['customer'] }}">{{ $r['customer'] }}</td>
                @endunless
                <td class="text-end text-secondary">{{ $tKg($r['qty']) }}</td>
                <td class="text-end fw-bold">{{ $r['wip_total'] > 0 ? $tKg($r['wip_total']) : '–' }}</td>
                @unless ($compact)
                    <td class="text-end small">
                        @if (!$r['erp_found'])
                            <span class="text-danger">ไม่พบใน ERP</span>
                        @else
                            {{ $r['erp_received'] > 0 ? $tKg($r['erp_received']) : '–' }}
                            <span class="text-secondary">/</span>
                            <span class="{{ $r['buckets']['WAITING_FG'] > 0 ? 'fw-semibold text-warning-emphasis' : 'text-secondary' }}">{{ $r['buckets']['WAITING_FG'] > 0 ? $tKg($r['buckets']['WAITING_FG']) : '–' }}</span>
                        @endif
                    </td>
                @endunless
                <td class="small">
                    <span class="fw-semibold">{{ $r['current_wc'] }}</span>@if ($r['current_machine'] !== '-')<span class="text-secondary"> · {{ $r['current_machine'] }}</span>@endif
                    <div class="text-secondary text-truncate" style="max-width: 220px" title="{{ $r['route_text'] }}">{{ $r['route_text'] ?: 'ไม่พบ Routing' }}</div>
                </td>
                <td class="text-center">
                    <span class="wip-age" style="background: {{ $bc }}1f; color: {{ $bc }}"
                        title="{{ $exact ? 'อยู่ในสถานะนี้ตั้งแต่ ' . \Carbon\Carbon::parse($r['aging_ref'])->format('d/m/Y H:i') : 'ประมาณจาก transaction ล่าสุด (ยังไม่มีประวัติสถานะ)' }}">{{ $r['aging_days'] }} วัน{{ $exact ? '' : '*' }}</span>
                </td>
                <td class="pe-3 small text-secondary">{{ $tDate($r['last_tx']) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="{{ $compact ? 8 : 10 }}" class="text-center text-secondary py-5">
                    <i class="fa-regular fa-folder-open fs-3 d-block mb-2"></i> ไม่พบรายการตามเงื่อนไขที่เลือก
                </td>
            </tr>
        @endforelse
    </tbody>
</table>
