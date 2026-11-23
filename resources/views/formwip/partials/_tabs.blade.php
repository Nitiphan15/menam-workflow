{{-- แท็บสลับ Dashboard / รายการ WO — ส่ง filter ติดไปด้วย --}}
@php
    $tabQuery = request()->except(['page', 'refresh']);
    // สถานะ / การเรียง ใช้เฉพาะหน้ารายการ — ไม่ส่งไป Dashboard
    $dashQuery = request()->except(['page', 'refresh', 'status', 'sort']);
    $current = request()->route()?->getName();
@endphp
<ul class="nav nav-tabs wip-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link {{ $current === 'wip.index' ? 'active' : '' }}" href="{{ route('wip.index', $dashQuery) }}">
            <i class="fa-solid fa-gauge-high me-1"></i> Dashboard
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ $current === 'wip.list' ? 'active' : '' }}" href="{{ route('wip.list', $tabQuery) }}">
            <i class="fa-solid fa-list me-1"></i> รายการ WO
        </a>
    </li>
</ul>
