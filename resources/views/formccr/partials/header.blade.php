@php
    $activePage = $activePage ?? 'summary';
    $year = $year ?? now()->year;
    $site = $site ?? 'ALL';
    $classnumber = $classnumber ?? '';
    $tabs = [
        'summary' => ['label' => 'สรุปทั้งปี', 'route' => 'cost-center.summary', 'icon' => 'fa-chart-pie'],
        'detail'  => ['label' => 'รายละเอียด', 'route' => 'cost-center.detail',  'icon' => 'fa-list'],
    ];
    $queryForTabs = ['year' => $year, 'site' => $site];
    if ($classnumber !== '') {
        $queryForTabs['classnumber'] = $classnumber;
    }
@endphp

<div class="ccr-tabs">
    @foreach ($tabs as $key => $tab)
        <a class="btn {{ $activePage === $key ? 'active' : '' }}" href="{{ route($tab['route'], $queryForTabs) }}">
            <i class="fas {{ $tab['icon'] }} me-1"></i> {{ $tab['label'] }}
        </a>
    @endforeach
</div>
