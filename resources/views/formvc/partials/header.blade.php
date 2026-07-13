@php
    $filters = $filters ?? [];
    $kpis = $kpis ?? [];
    $fmt = fn($value, $decimals = 0) => number_format((float) $value, $decimals);
    $activePage = $activePage ?? 'summary';
    $tabs = [
        'summary' => ['label' => 'สรุปแผนก', 'route' => 'variable-cost.summary'],
        'monthly' => ['label' => 'รายเดือน', 'route' => 'variable-cost.monthly'],
        'matrix' => ['label' => 'แผนก x ค่าใช้จ่าย', 'route' => 'variable-cost.matrix'],
        'accounts' => ['label' => 'ตามบัญชี', 'route' => 'variable-cost.accounts'],
        'yearly' => ['label' => 'รายปีตาม Class', 'route' => 'variable-cost.yearly'],
    ];
@endphp

@include('formvc.partials.loading')

@if ($activePage !== 'yearly')
    @include('formvc.partials.filters')
@endif

<div class="row g-3 mb-3">
    <div class="col-xl-2 col-md-4"><div class="vc-kpi"><div class="label">ยอดรวม</div><div class="value">{{ $fmt($kpis['total_amount'] ?? 0, 2) }}</div><div class="sub">บาท</div></div></div>
    <div class="col-xl-2 col-md-4"><div class="vc-kpi"><div class="label">จำนวนบิล</div><div class="value">{{ $fmt($kpis['bill_count'] ?? 0) }}</div><div class="sub">บิล</div></div></div>
    <div class="col-xl-2 col-md-4"><div class="vc-kpi"><div class="label">จำนวนรายการ</div><div class="value">{{ $fmt($kpis['line_count'] ?? 0) }}</div><div class="sub">รายการ</div></div></div>
    <div class="col-xl-2 col-md-4"><div class="vc-kpi"><div class="label">เฉลี่ยต่อบิล</div><div class="value">{{ $fmt($kpis['avg_per_bill'] ?? 0, 2) }}</div><div class="sub">บาท / บิล</div></div></div>
    <div class="col-xl-2 col-md-4"><div class="vc-kpi"><div class="label">บัญชี</div><div class="value">{{ $fmt($kpis['account_count'] ?? 0) }}</div><div class="sub">บัญชี</div></div></div>
    <div class="col-xl-2 col-md-4"><div class="vc-kpi"><div class="label">แผนก</div><div class="value">{{ $fmt($kpis['department_count'] ?? 0) }}</div><div class="sub">แผนก</div></div></div>
</div>

<div class="vc-tabs">
    @foreach ($tabs as $key => $tab)
        <a class="btn {{ $activePage === $key ? 'active' : '' }}" href="{{ route($tab['route'], request()->query()) }}">
            {{ $tab['label'] }}
        </a>
    @endforeach
</div>
