@php
    use Illuminate\Support\Str;

    $u = auth()->user();

    $menuKey = function ($item) {
        if (!empty($item['route'])) {
            return 'route:' . $item['route'];
        }

        return 'text:' . ($item['text'] ?? '');
    };

    $collectMenuRoutes = function (array $menuItems) {
        $routes = [];

        foreach ($menuItems as $item) {
            if (!empty($item['route'])) {
                $routes[$item['route']] = true;
            }

            foreach ($item['children'] ?? [] as $child) {
                if (!empty($child['route'])) {
                    $routes[$child['route']] = true;
                }
            }
        }

        return $routes;
    };

    $stripDuplicateRoutes = function (array $item, array $seenRoutes) {
        if (!empty($item['route']) && isset($seenRoutes[$item['route']])) {
            return null;
        }

        if (!empty($item['children'])) {
            $item['children'] = array_values(
                array_filter(
                    $item['children'],
                    fn($child) => empty($child['route']) || !isset($seenRoutes[$child['route']]),
                ),
            );

            if (empty($item['children']) && empty($item['route'])) {
                return null;
            }
        }

        return $item;
    };

    $appendMenus = function (array $baseMenus, array $newMenus) use (
        $menuKey,
        $collectMenuRoutes,
        $stripDuplicateRoutes,
    ) {
        $seen = [];
        $seenRoutes = $collectMenuRoutes($baseMenus);

        foreach ($baseMenus as $item) {
            $seen[$menuKey($item)] = true;
        }

        foreach ($newMenus as $item) {
            $item = $stripDuplicateRoutes($item, $seenRoutes);

            if ($item === null) {
                continue;
            }

            $key = $menuKey($item);

            if (!isset($seen[$key])) {
                $baseMenus[] = $item;
                $seen[$key] = true;

                foreach ($collectMenuRoutes([$item]) as $route => $_) {
                    $seenRoutes[$route] = true;
                }
            }
        }

        return $baseMenus;
    };

    $mergeMenuChildren = function (array $baseChildren, array $newChildren) use ($menuKey) {
        $seen = [];

        foreach ($baseChildren as $child) {
            $seen[$menuKey($child)] = true;
        }

        foreach ($newChildren as $child) {
            $key = $menuKey($child);

            if (!isset($seen[$key])) {
                $baseChildren[] = $child;
                $seen[$key] = true;
            }
        }

        return $baseChildren;
    };

    $mergeDuplicateMenus = function (array $menuItems) use ($menuKey, $mergeMenuChildren) {
        $merged = [];
        $indexes = [];

        foreach ($menuItems as $item) {
            $key = $menuKey($item);

            if (!isset($indexes[$key])) {
                $indexes[$key] = count($merged);
                $merged[] = $item;
                continue;
            }

            $index = $indexes[$key];

            if (!empty($item['children'])) {
                $merged[$index]['children'] = $mergeMenuChildren($merged[$index]['children'] ?? [], $item['children']);
            }
        }

        return $merged;
    };

    if (auth()->check()) {
        $menus = config('menu.menu.auth', []);

        if (config('menu.show_guest_menu_when_auth', false)) {
            $hiddenGuestRoutes = config('menu.hide_guest_menu_routes_when_auth', []);
            $guestMenus = array_filter(config('menu.menu.guest', []), function ($item) use ($hiddenGuestRoutes) {
                return !in_array($item['route'] ?? null, $hiddenGuestRoutes, true);
            });

            $menus = $appendMenus($menus, array_values($guestMenus));
        }

        $menus = array_merge($menus, auth()->user()->hasRoleCode('PP') ? config('menu.menu.pp', []) : []);
        $menus = array_merge($menus, auth()->user()->hasRoleCode('PA') ? config('menu.menu.pa', []) : []);
        $menus = array_merge($menus, auth()->user()->hasRoleCode('PAADMIN') ? config('menu.menu.paadmin', []) : []);
        $menus = array_merge($menus, auth()->user()->hasRoleCode('PAHR') ? config('menu.menu.pahr', []) : []);
        $menus = array_merge($menus, auth()->user()->hasRoleCode('PR') ? config('menu.menu.pr', []) : []);
        $menus = array_merge(
            $menus,
            auth()
                ->user()
                ->hasRoleCode(['PO', 'POPUR'])
                ? config('menu.menu.po', [])
                : [],
        );
        $menus = array_merge($menus, auth()->user()->hasRoleCode('WLM') ? config('menu.menu.wlm', []) : []);
        $menus = array_merge($menus, auth()->user()->hasRoleCode('WOCR') ? config('menu.menu.wocr', []) : []);
        $menus = array_merge($menus, auth()->user()->hasRoleCode('PRADMIN') ? config('menu.menu.adminpr', []) : []);
        $menus = array_merge($menus, auth()->user()->hasRoleCode('ADMINWEB') ? config('menu.menu.adminweb', []) : []);
        $menus = array_merge($menus, auth()->user()->hasRoleCode('WR') ? config('menu.menu.wr', []) : []);
        $menus = array_merge($menus, auth()->user()->hasRoleCode('EXAM') ? config('menu.menu.exam', []) : []);
        $menus = array_merge($menus, auth()->user()->hasRoleCode('ISR') ? config('menu.menu.isr', []) : []);
        $menus = array_merge(
            $menus,
            auth()
                ->user()
                ->hasRoleCode(['TDP'])
                ? config('menu.menu.tdp', [])
                : [],
        );
        $menus = array_merge(
            $menus,
            auth()
                ->user()
                ->hasRoleCode(['GP', 'GPM'])
                ? config('menu.menu.gp', [])
                : [],
        );
        $menus = array_merge(
            $menus,
            auth()
                ->user()
                ->hasRoleCode(['VC', 'VCA', 'VCL', 'VCPD', 'VCP', 'VCS', 'VCM', 'VCC'])
                ? config('menu.menu.vc', [])
                : [],
        );
        $menus = array_merge(
            $menus,
            auth()
                ->user()
                ->hasRoleCode(['DP', 'DPA', 'DPEMAIL', 'DPMAIL'])
                ? config('menu.menu.dp', [])
                : [],
        );
        //$menus = array_merge($menus, auth()->user()->hasRoleCode('DPA') ? config('menu.menu.dpa', []) : []);
        $menus = array_merge($menus, auth()->user()->hasRoleCode('RISK') ? config('menu.menu.risk', []) : []);

        $menus = array_merge(
            $menus,
            auth()->user()->hasRoleCode('FC') ||
            auth()->user()->hasRoleCode('FC_PLN') ||
            auth()->user()->hasRoleCode('FCM') ||
            auth()->user()->hasRoleCode('FCAPPROVE') ||
            auth()->user()->hasRoleCode('D1') ||
            auth()->user()->hasRoleCode('D2') ||
            auth()->user()->hasRoleCode('D3') ||
            auth()->user()->hasRoleCode('D4') ||
            auth()->user()->hasRoleCode('D5') ||
            auth()->user()->hasRoleCode('D6') ||
            auth()->user()->hasRoleCode('D7') ||
            auth()->user()->hasRoleCode('D8') ||
            auth()->user()->hasRoleCode('D9')
                ? config('menu.menu.fc', [])
                : [],
        );
        $menus = array_merge($menus, auth()->user()->hasRoleCode('WOS') ? config('menu.menu.wos', []) : []);
    } else {
        $menus = config('menu.menu.guest', []);
    }

    $menus = $mergeDuplicateMenus($menus);

    $menus = array_filter($menus, function ($item) {
        return empty($item['permission']) || (auth()->check() && auth()->user()->hasRoleCode($item['permission']));
    });

    $menuGroupFor = function ($item) {
        $text = $item['text'] ?? '';
        $route = $item['route'] ?? '';

        if ($route === 'home') {
            return 'Main';
        }

        if ($route === 'login') {
            return 'Main';
        }

        if (
            Str::contains($text, [
                'Production',
                'Forecast',
                'WO Change',
                'Wirerod',
                'Risk',
                'Workload Machine',
                'Packaging',
            ])
        ) {
            return 'Planning';
        }
        if (Str::contains($text, ['PA Online', 'PA Admin', 'Cost', 'ขาดทุน', 'Payment'])) {
            return 'Accounting';
        }

        if (Str::contains($text, ['Sales', 'Delivery Plan', 'Delivery Volume', 'Order Due Date', 'Deadstock'])) {
            return 'Sales';
        }

        if (Str::contains($text, ['PR Online', 'PO Online'])) {
            return 'Purchasing';
        }

        if (Str::contains($text, ['Inspection'])) {
            return 'Operations';
        }

        if (Str::contains($text, ['Admin', 'Exam'])) {
            return 'Management';
        }

        return 'Workflows';
    };

    $groupOrder = array_flip([
        'Main',
        'Sales',
        'Accounting',
        'Planning',
        'Purchasing',
        'QA',
        'Operations',
        'Management',
        'Workflows',
    ]);

    $menus = collect($menus)->sortBy(fn($item) => $groupOrder[$menuGroupFor($item)] ?? 99)->values()->all();

    $menuItemIsActive = function ($item) {
        if (!empty($item['route']) && request()->routeIs($item['route'], $item['route'] . '.*')) {
            return true;
        }

        foreach ($item['children'] ?? [] as $child) {
            if (!empty($child['route']) && request()->routeIs($child['route'], $child['route'] . '.*')) {
                return true;
            }
        }

        return false;
    };

    $menuGroupIsActive = function (string $group) use ($menus, $menuGroupFor, $menuItemIsActive) {
        return collect($menus)
            ->filter(fn($item) => $menuGroupFor($item) === $group)
            ->contains(fn($item) => $menuItemIsActive($item));
    };

    // prefix id ให้ไม่ชนกันระหว่าง desktop/mobile
    $desktopPrefix = 'desk';
    $mobilePrefix = 'mob';
@endphp



{{-- ปุ่มเปิดเมนู (แสดงเฉพาะมือถือ) --}}
<div class="mobile-menu-trigger d-md-none mb-2">
    <button class="btn btn-outline-secondary w-100" type="button" data-bs-toggle="offcanvas"
        data-bs-target="#mobileSidebar" aria-controls="mobileSidebar">
        <i class="fas fa-bars me-2"></i> เมนู
    </button>
</div>

{{-- DESKTOP SIDEBAR (แสดงเฉพาะ md ขึ้นไป) --}}
<div class="col-md-3 col-lg-2 px-0 h-100 d-none d-md-block sidebar-col">

    <div class="sidebar h-100 p-3 overflow-auto">
        <div class="sidebar-head">
            <div class="sidebar-toprow">
                <button type="button" class="sidebar-topbtn" id="sidebarToggleBtn" aria-label="Toggle sidebar">
                    <i class="fas fa-bars"></i>
                </button>

                <div class="sidebar-title">
                    Menam Online
                </div>
            </div>

            {{-- โลโก้แยกลงมา (ซ่อนตอนย่อ) --}}
            <div class="sidebar-brand">
                <img src="{{ asset('assets/logo_sidebar.png') }}" alt="Logo" class="img-fluid">
            </div>
        </div>

        <nav class="nav flex-column">
            @php
                $currentGroup = null;
                $sectionOpen = false;
            @endphp
            @foreach ($menus as $item)
                @php $nextGroup = $menuGroupFor($item); @endphp
                @if ($nextGroup !== $currentGroup)
                    @if ($sectionOpen)
                        </div>
                    @endif
                    @php
                        $sectionActive = $menuGroupIsActive($nextGroup);
                        $sectionKey = Str::slug($nextGroup);
                        $sectionId = $desktopPrefix . '-section-' . $sectionKey;
                    @endphp
                    <button type="button" class="sidebar-section sidebar-section-toggle"
                        data-bs-toggle="collapse" data-bs-target="#{{ $sectionId }}"
                        aria-controls="{{ $sectionId }}" aria-expanded="true">
                        <span>{{ $nextGroup }}</span>
                        <i class="fas fa-chevron-down sidebar-section-chevron"></i>
                    </button>
                    <div class="collapse show sidebar-section-items" id="{{ $sectionId }}">
                    @php
                        $currentGroup = $nextGroup;
                        $sectionOpen = true;
                    @endphp
                @endif


                @if (!empty($item['children']))
                    @php
                        $slug = $desktopPrefix . '-submenu-' . Str::slug($item['text']);
                        $open = collect($item['children'])
                            ->pluck('route')
                            ->contains(fn($r) => request()->routeIs($r, $r . '.*'));
                    @endphp

                    {{-- parent (มี children): tooltip ใช้ class + title (ห้ามใส่ data-bs-toggle="tooltip") --}}
                    <a class="nav-link d-flex justify-content-between align-items-center has-tooltip {{ $open ? 'active' : '' }}"
                        data-bs-toggle="collapse" href="#{{ $slug }}"
                        aria-expanded="{{ $open ? 'true' : 'false' }}" title="{{ $item['text'] }}">

                        <span class="nav-title d-flex align-items-center gap-2">
                            <i class="fas fa-fw {{ $item['icon'] }}"></i>
                            <span class="nav-text">{{ $item['text'] }}</span>
                        </span>

                        <i class="fas fa-chevron-down fs-6 nav-chevron"></i>
                    </a>

                    <div class="collapse {{ $open ? 'show' : '' }}" id="{{ $slug }}">
                        <nav class="nav flex-column ms-3 submenu">
                            @foreach ($item['children'] as $child)
                                @if (empty($child['permission']) || (auth()->check() && auth()->user()->hasRoleCode($child['permission'])))
                                    <a href="{{ route($child['route'], $child['query'] ?? []) }}"
                                        class="nav-link has-tooltip {{ request()->routeIs($child['route'], $child['route'] . '.*') ? 'active' : '' }}"
                                        title="{{ $child['text'] }}">
                                        <i class="fas {{ $child['icon'] }} me-1"></i>
                                        <span class="nav-text">{{ $child['text'] }}</span>
                                    </a>
                                @endif
                            @endforeach
                        </nav>
                    </div>
                @else
                    <a href="{{ route($item['route'], $item['query'] ?? []) }}"
                        class="nav-link has-tooltip {{ request()->routeIs($item['route'] . '*') ? 'active' : '' }}"
                        title="{{ $item['text'] }}">
                        <i class="fas fa-fw {{ $item['icon'] }}"></i>
                        <span class="nav-text">{{ $item['text'] }}</span>
                    </a>
                @endif
            @endforeach
            @if ($sectionOpen)
                </div>
            @endif

            <span data-sidebar-section-stop></span>

            @auth
                <hr class="text-white my-3">

                <a href="#" class="nav-link has-tooltip" title="ออกจากระบบ"
                    onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                    <i class="fas fa-fw fa-sign-out-alt"></i>
                    <span class="nav-text">ออกจากระบบ</span>
                </a>
            @endauth
        </nav>
    </div>
</div>

{{-- MOBILE OFFCANVAS SIDEBAR (แสดงเฉพาะมือถือ) --}}
<div class="offcanvas offcanvas-start d-md-none" tabindex="-1" id="mobileSidebar" aria-labelledby="mobileSidebarLabel"
    style="width: 86vw; max-width: 360px;">
    <div class="offcanvas-header sidebar text-white">
        <h5 class="offcanvas-title" id="mobileSidebarLabel">
            <i class="fas fa-bars me-2"></i> Menam Online
        </h5>

    </div>

    <div class="offcanvas-body sidebar p-3 overflow-auto">
        <div class="text-center mb-3 brand">
            <img src="{{ asset('assets/logo_sidebar.png') }}" alt="Logo" class="img-fluid mb-2">
        </div>

        <nav class="nav flex-column">
            @php
                $currentGroup = null;
                $sectionOpen = false;
            @endphp
            @foreach ($menus as $item)
                @php $nextGroup = $menuGroupFor($item); @endphp
                @if ($nextGroup !== $currentGroup)
                    @if ($sectionOpen)
                        </div>
                    @endif
                    @php
                        $sectionActive = $menuGroupIsActive($nextGroup);
                        $sectionKey = Str::slug($nextGroup);
                        $sectionId = $mobilePrefix . '-section-' . $sectionKey;
                    @endphp
                    <button type="button" class="sidebar-section sidebar-section-toggle"
                        data-bs-toggle="collapse" data-bs-target="#{{ $sectionId }}"
                        aria-controls="{{ $sectionId }}" aria-expanded="true">
                        <span>{{ $nextGroup }}</span>
                        <i class="fas fa-chevron-down sidebar-section-chevron"></i>
                    </button>
                    <div class="collapse show sidebar-section-items" id="{{ $sectionId }}">
                    @php
                        $currentGroup = $nextGroup;
                        $sectionOpen = true;
                    @endphp
                @endif


                @if (!empty($item['children']))
                    @php
                        $slug = $mobilePrefix . '-submenu-' . Str::slug($item['text']);
                        $open = collect($item['children'])
                            ->pluck('route')
                            ->contains(fn($r) => request()->routeIs($r, $r . '.*'));
                    @endphp

                    <a class="nav-link d-flex justify-content-between align-items-center has-tooltip {{ $open ? 'active' : '' }}"
                        data-bs-toggle="collapse" href="#{{ $slug }}"
                        aria-expanded="{{ $open ? 'true' : 'false' }}" title="{{ $item['text'] }}">

                        <span class="nav-title d-flex align-items-center gap-2">
                            <i class="fas fa-fw {{ $item['icon'] }}"></i>
                            <span class="nav-text">{{ $item['text'] }}</span>
                        </span>

                        <i class="fas fa-chevron-down fs-6 nav-chevron"></i>
                    </a>

                    <div class="collapse {{ $open ? 'show' : '' }}" id="{{ $slug }}">
                        <nav class="nav flex-column ms-3 submenu">
                            @foreach ($item['children'] as $child)
                                @if (empty($child['permission']) || (auth()->check() && auth()->user()->hasRoleCode($child['permission'])))
                                    <a href="{{ route($child['route'], $child['query'] ?? []) }}"
                                        class="nav-link has-tooltip {{ request()->routeIs($child['route'], $child['route'] . '.*') ? 'active' : '' }}"
                                        title="{{ $child['text'] }}">
                                        <i class="fas {{ $child['icon'] }} me-1"></i>
                                        <span class="nav-text">{{ $child['text'] }}</span>
                                    </a>
                                @endif
                            @endforeach
                        </nav>
                    </div>
                @else
                    <a href="{{ route($item['route'], $item['query'] ?? []) }}"
                        class="nav-link has-tooltip {{ request()->routeIs($item['route'] . '*') ? 'active' : '' }}"
                        title="{{ $item['text'] }}">
                        <i class="fas fa-fw {{ $item['icon'] }}"></i>
                        <span class="nav-text">{{ $item['text'] }}</span>
                    </a>
                @endif
            @endforeach
            @if ($sectionOpen)
                </div>
            @endif

            <span data-sidebar-section-stop></span>

            @auth
                <hr class="text-white my-3">

                <a href="#" class="nav-link has-tooltip" title="ออกจากระบบ"
                    onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                    <i class="fas fa-fw fa-sign-out-alt"></i>
                    <span class="nav-text">ออกจากระบบ</span>
                </a>
            @endauth
        </nav>


    </div>
</div>

<style>
    /* =========================================================
   MENAM SIDEBAR - FINAL (Sticky + No Horizontal Scroll)
   ========================================================= */

    /* กันแนวนอนทั้งระบบ (กัน bootstrap row ล้น) */


    /* ================================
   Sidebar column
   ================================ */
    .sidebar-col {
        position: relative;
        padding-left: 0;
        padding-right: 0;
        overflow-x: hidden;
    }

    /* ================================
   Sidebar container
   ================================ */
    .sidebar {
        position: relative;
        background: #2f4357;
        border-right: 1px solid rgba(255, 255, 255, .08);

        min-height: 100vh;
        max-height: 100vh;

        overflow-y: auto;
        overflow-x: hidden;

        /* ใช้ padding แบบเดียวพอ */
        padding: 12px;
        padding-right: 18px;

        isolation: isolate;
    }

    .sidebar .nav {
        position: relative;
        z-index: 1;
        padding-top: 6px;
    }

    .sidebar-section {
        align-items: center;
        background: transparent;
        border: 0;
        color: rgba(255, 255, 255, .46);
        display: flex;
        font-size: 11px;
        font-weight: 700;
        justify-content: space-between;
        letter-spacing: .08em;
        line-height: 1;
        margin: 18px 8px 7px;
        padding: 0;
        text-align: left;
        text-transform: uppercase;
        width: calc(100% - 16px);
        white-space: nowrap;
    }

    .sidebar-section-toggle {
        cursor: pointer;
        transition: color .15s ease;
    }

    .sidebar-section-toggle:hover {
        color: rgba(255, 255, 255, .78);
    }

    .sidebar-section-chevron {
        font-size: 10px;
        opacity: .62;
        transition: transform .16s ease, opacity .16s ease;
    }

    .sidebar-section-toggle.is-collapsed .sidebar-section-chevron {
        transform: rotate(-90deg);
    }

    .sidebar-section-toggle[aria-expanded="false"] .sidebar-section-chevron {
        transform: rotate(-90deg);
    }

    .sidebar-section-toggle:hover .sidebar-section-chevron {
        opacity: .95;
    }

    .sidebar-section:first-child {
        margin-top: 6px;
    }

    /* ================================
   Scrollbar style
   ================================ */
    .sidebar::-webkit-scrollbar {
        width: 6px;
    }

    .sidebar::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, .25);
        border-radius: 10px;
    }

    /* ================================
   Sidebar header (STICKY ✅)
   - ไม่ใช้ margin ลบ / width calc / left shift
   - เลยไม่เกิด horizontal scroll
   ================================ */
    .sidebar-head {
        position: sticky;
        top: 0;
        z-index: 60;

        background: #2f4357;
        border-bottom: 1px solid rgba(255, 255, 255, .08);

        /* align ให้ตรงกับ sidebar padding */
        padding: 12px 18px 10px 12px;
        margin: 0 0 12px 0;

        overflow-x: hidden;
    }

    /* header row */
    .sidebar-toprow {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 2px 0;
        /* ไม่ดันให้ล้น */
    }

    /* toggle button */
    .sidebar-topbtn {
        width: 38px;
        height: 38px;
        border-radius: 999px;
        border: 1px solid rgba(255, 255, 255, .25);

        display: inline-flex;
        align-items: center;
        justify-content: center;

        background: rgba(47, 67, 87, .92);
        color: #fff;

        cursor: pointer;
        transition: background .15s ease, transform .1s ease;

        position: relative;
        z-index: 3;
    }

    .sidebar-topbtn:hover {
        background: rgba(255, 255, 255, .16);
    }

    .sidebar-topbtn:active {
        transform: scale(.98);
    }

    /* title */
    .sidebar-title {
        color: rgba(255, 255, 255, .94);
        font-weight: 700;
        font-size: 18px;
        line-height: 1;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* logo */
    .sidebar-brand {
        margin-top: 10px;
        text-align: center;
    }

    .sidebar-brand img {
        max-width: 220px;
        opacity: .98;
    }

    /* ================================
   Nav links
   ================================ */
    .sidebar .nav-link {
        color: rgba(255, 255, 255, .82);
        padding: .66rem .85rem;
        margin: .14rem 0;
        border-radius: 8px;

        display: flex;
        align-items: center;
        gap: .55rem;

        text-decoration: none;
        transition: background .15s ease, color .15s ease;

        max-width: 100%;
    }

    .sidebar .nav-link:hover,
    .sidebar .nav-link.active {
        color: #fff;
        background: rgba(255, 255, 255, .14);
    }

    /* icons uniform */
    .sidebar .nav-link i {
        width: 22px;
        flex: 0 0 22px;
        text-align: center;
        font-size: 18px;
        opacity: .95;
    }

    /* text */
    .sidebar .nav-text {
        display: inline-block;
    }

    /* parent title wrapper */
    .sidebar .nav-title {
        display: flex;
        align-items: center;
        gap: .55rem;
        min-width: 0;
    }

    .sidebar .nav-chevron {
        opacity: .9;
        transition: transform .15s ease;
    }

    .sidebar .nav-link[aria-expanded="true"] .nav-chevron {
        transform: rotate(180deg);
    }

    /* submenu */
    .sidebar .submenu {
        margin: 2px 0 8px;
        padding: 3px 0 3px 11px;
        border-left: 1px solid rgba(255, 255, 255, .14);
    }

    .sidebar .submenu .nav-link {
        padding: .52rem .72rem;
        border-radius: 7px;
        margin: .08rem 0;
        font-size: 14px;
        color: rgba(255, 255, 255, .72);
    }

    /* ================================
   Desktop widths
   ================================ */
    @media (min-width: 768px) {

        .sidebar-col {
            width: 260px !important;
            flex: 0 0 260px !important;
            max-width: 260px !important;
        }

        .main-col {
            width: calc(100% - 260px) !important;
            flex: 0 0 calc(100% - 260px) !important;
            max-width: calc(100% - 260px) !important;
        }

        /* collapsed */
        body.sidebar-collapsed .sidebar-col {
            width: 92px !important;
            flex: 0 0 92px !important;
            max-width: 92px !important;
        }

        body.sidebar-collapsed .main-col {
            width: calc(100% - 92px) !important;
            flex: 0 0 calc(100% - 92px) !important;
            max-width: calc(100% - 92px) !important;
        }

        /* hide logo + title in mini */
        body.sidebar-collapsed .sidebar-brand {
            display: none !important;
        }

        body.sidebar-collapsed .sidebar-title {
            display: none !important;
        }

        /* mini nav: icon top, text bottom */
        body.sidebar-collapsed .sidebar .nav-link {
            flex-direction: column !important;
            justify-content: center !important;
            align-items: center !important;
            gap: 6px !important;

            padding: .70rem .35rem !important;
            border-radius: 14px;
            text-align: center;
        }

        body.sidebar-collapsed .sidebar .nav-link i {
            width: 24px;
            flex: 0 0 24px;
            font-size: 20px;
            margin: 0 !important;
        }

        body.sidebar-collapsed .sidebar .nav-text {
            display: -webkit-box !important;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;

            font-size: 10px;
            line-height: 1.1;

            max-width: 64px;
            word-break: break-word;

            opacity: .92;
            white-space: normal;
        }

        body.sidebar-collapsed .sidebar .nav-title {
            flex-direction: column !important;
            gap: 6px !important;
            align-items: center !important;
        }

        /* hide submenu + chevron in mini */
        body.sidebar-collapsed .sidebar .submenu,
        body.sidebar-collapsed .sidebar .nav-chevron {
            display: none !important;
        }

        /* mini active */
        body.sidebar-collapsed .sidebar .nav-link.active {
            background: rgba(255, 255, 255, .10);
            border-radius: 16px;
            padding-top: .75rem !important;
            padding-bottom: .75rem !important;
        }

        body.sidebar-collapsed .sidebar-section {
            display: none;
        }
    }

    /* ================================
   Mobile tweaks
   ================================ */
    @media (max-width: 767.98px) {
        .sidebar .nav-link {
            padding: .95rem 1.05rem;
            margin: .35rem 0;
            border-radius: .65rem;
            font-size: 1.05rem;
        }

        .sidebar-section {
            margin: 20px 10px 8px;
        }

        .offcanvas .sidebar {
            min-height: auto;
            max-height: none;
            overflow-y: visible;
        }

        .offcanvas-header.sidebar {
            background: #34495E;
            border-bottom: 1px solid rgba(255, 255, 255, .12);
        }
    }

    /* tooltip */
    .tooltip {
        z-index: 9999 !important;
    }
</style>

@push('scripts')
    <script>
        (function() {
            const key = 'sidebarCollapsed';

            function apply(isCollapsed) {
                document.body.classList.toggle('sidebar-collapsed', isCollapsed);

                const btn = document.getElementById('sidebarToggleBtn');
                if (btn) {
                    const icon = btn.querySelector('i');
                    if (icon) icon.className = isCollapsed ? 'fas fa-angles-right' : 'fas fa-bars';
                }
            }

            function initTooltips() {
                document.querySelectorAll('.has-tooltip').forEach(el => {
                    // กันซ้อนหลายครั้ง
                    if (el._menamTooltip) return;

                    el._menamTooltip = new bootstrap.Tooltip(el, {
                        placement: 'right',
                        trigger: 'hover',
                        container: 'body'
                    });
                });
            }

            document.addEventListener('DOMContentLoaded', function() {
                apply(localStorage.getItem(key) === '1');
                initTooltips();
            });

            document.addEventListener('click', function(e) {
                const btn = e.target.closest('#sidebarToggleBtn');
                if (!btn) return;

                e.preventDefault();
                e.stopPropagation();

                const next = !document.body.classList.contains('sidebar-collapsed');
                localStorage.setItem(key, next ? '1' : '0');
                apply(next);
            });
        })();
    </script>
@endpush
