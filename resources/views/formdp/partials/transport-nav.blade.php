{{--
    Transport navigation bar
    Vars (set ก่อน @include):
        $tnActive   : 'inquiry' | 'logistics' | 'board'
        $tnShipDate : 'YYYY-MM-DD' หรือ null
        $tnSo       : SO number (string)
        $tnCustomer : customer keyword (string)
        $tnMfg      : mfg number (string)
--}}
@php
    $tn_ship = trim((string) ($tnShipDate ?? ''));
    $tn_so   = trim((string) ($tnSo ?? ''));
    $tn_cust = trim((string) ($tnCustomer ?? ''));
    $tn_mfg  = trim((string) ($tnMfg ?? ''));
    $tn_active = (string) ($tnActive ?? '');
    $tn_user = auth()->user();
    $tn_canDpa = auth()->check()
        && (($tn_user->is_superadmin ?? 0) == 1 || (method_exists($tn_user, 'hasRoleCode') && $tn_user->hasRoleCode('DPA')));

    // รวม keyword สำหรับหน้า logistics / board ที่ใช้ search รวม
    $tn_search = trim(implode(' ', array_filter([$tn_so, $tn_cust, $tn_mfg], fn($v) => $v !== '')));

    $tn_inquiryParams = array_filter([
        'ship_from' => $tn_ship !== '' ? $tn_ship : null,
        'ship_to'   => $tn_ship !== '' ? $tn_ship : null,
        'so'        => $tn_so !== '' ? $tn_so : null,
        'customer'  => $tn_cust !== '' ? $tn_cust : null,
        'mfg'       => $tn_mfg !== '' ? $tn_mfg : null,
        'status'    => 'ALL',
        'searched'  => ($tn_ship !== '' || $tn_so !== '' || $tn_cust !== '' || $tn_mfg !== '') ? 1 : null,
    ], fn($v) => $v !== null);

    $tn_logisticsParams = array_filter([
        'ship_date' => $tn_ship !== '' ? $tn_ship : null,
        'q'         => $tn_search !== '' ? $tn_search : null,
    ], fn($v) => $v !== null);

    $tn_boardParams = array_filter([
        'ship_date' => $tn_ship !== '' ? $tn_ship : null,
        'q'         => $tn_search !== '' ? $tn_search : null,
    ], fn($v) => $v !== null);

    $tn_links = [
        'inquiry' => [
            'url'   => route('dp.inquiry', $tn_inquiryParams),
            'icon'  => 'fas fa-list',
            'label' => 'DP Inquiry',
        ],
        'logistics' => [
            'url'   => route('dp.dashboard.logistics-summary', $tn_logisticsParams),
            'icon'  => 'fas fa-truck-loading',
            'label' => 'จัดรถส่งสินค้า',
        ],
        'board' => [
            'url'   => route('dp.dashboard.truck-board', $tn_boardParams),
            'icon'  => 'fas fa-th-large',
            'label' => 'ตารางรถขนส่ง',
        ],
    ];

    if (!$tn_canDpa) {
        unset($tn_links['logistics']);
    }
@endphp

<div class="dp-transport-nav d-flex flex-wrap align-items-center gap-2 mb-3">
    <div class="btn-group btn-group-sm" role="group" aria-label="transport nav">
        @foreach ($tn_links as $key => $link)
            <a href="{{ $link['url'] }}"
                class="btn {{ $tn_active === $key ? 'btn-primary' : 'btn-outline-primary' }}">
                <i class="{{ $link['icon'] }} me-1"></i> {{ $link['label'] }}
            </a>
        @endforeach
    </div>

    @if ($tn_ship !== '' || $tn_so !== '' || $tn_cust !== '' || $tn_mfg !== '')
        <div class="d-flex flex-wrap align-items-center gap-1 small">
            <span class="text-muted me-1">Filter ที่ส่งต่อ:</span>
            @if ($tn_ship !== '')
                <span class="badge bg-light text-dark border">
                    <i class="far fa-calendar-alt me-1"></i>{{ $tn_ship }}
                </span>
            @endif
            @if ($tn_so !== '')
                <span class="badge bg-light text-dark border">SO: {{ $tn_so }}</span>
            @endif
            @if ($tn_cust !== '')
                <span class="badge bg-light text-dark border">Customer: {{ $tn_cust }}</span>
            @endif
            @if ($tn_mfg !== '')
                <span class="badge bg-light text-dark border">MFG: {{ $tn_mfg }}</span>
            @endif
        </div>
    @endif
</div>
