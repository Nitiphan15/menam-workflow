@extends('layouts.layout')

@section('title', 'Master Data Map')

@section('content')
    <div class="container-fluid py-4 master-map-page">
        <header class="master-map-hero mb-4">
            <div>
                <div class="master-map-kicker">ADMIN CONTROL DIRECTORY</div>
                <h1 class="master-map-title">Master Data Map</h1>
                <p class="master-map-intro mb-0">ศูนย์รวมทางเข้าข้อมูล Master ของแต่ละระบบ ตรวจเจ้าของข้อมูล สิทธิ์ และแหล่งข้อมูลก่อนเปิดหน้าเดิมเพื่อจัดการ</p>
            </div>
            <div class="master-map-summary" aria-label="สรุป Master Data">
                <div><strong>{{ $masters->count() }}</strong><span>Masters</span></div>
                <div><strong>{{ $modules->count() }}</strong><span>Modules</span></div>
                <div><strong>{{ $masters->where('available', true)->count() }}</strong><span>พร้อมเปิด</span></div>
            </div>
        </header>

        <section class="master-map-toolbar mb-3" aria-label="ตัวกรอง Master Data">
            <div class="master-map-search">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <label class="visually-hidden" for="master-map-search">ค้นหา Master</label>
                <input id="master-map-search" type="search" placeholder="ค้นหาชื่อ Module, Master, ตาราง หรือสิทธิ์…" autocomplete="off">
            </div>
            <label class="visually-hidden" for="master-map-module">กรอง Module</label>
            <select id="master-map-module" class="form-select">
                <option value="">ทุก Module</option>
                @foreach ($modules as $module)
                    <option value="{{ $module }}" {{ ($initialModule ?? null) === $module ? 'selected' : '' }}>{{ $module }}</option>
                @endforeach
            </select>
            <label class="visually-hidden" for="master-map-status">กรองสถานะ</label>
            <select id="master-map-status" class="form-select">
                <option value="">ทุกสถานะ</option>
                <option value="available">พร้อมเปิด</option>
                <option value="unavailable">Route ไม่พร้อม</option>
            </select>
        </section>

        <div class="master-map-result-bar mb-2">
            <span id="master-map-result-count">แสดง {{ $masters->count() }} รายการ</span>
            <button id="master-map-clear" type="button" class="btn btn-link btn-sm text-decoration-none">ล้างตัวกรอง</button>
        </div>

        <section id="master-map-list" class="master-map-list" aria-live="polite">
            @foreach ($masters as $master)
                <article class="master-map-row"
                    data-module="{{ $master['module'] }}"
                    data-status="{{ $master['available'] ? 'available' : 'unavailable' }}"
                    data-search="{{ mb_strtolower(implode(' ', [$master['module'], $master['name'], $master['description'], $master['permission'], $master['source']])) }}">
                    <div class="master-map-module">
                        <span>{{ $master['module'] }}</span>
                    </div>
                    <div class="master-map-main">
                        <h2>{{ $master['name'] }}</h2>
                        <p>{{ $master['description'] }}</p>
                    </div>
                    <div class="master-map-meta">
                        <div>
                            <span class="meta-label">Source</span>
                            <code>{{ $master['source'] }}</code>
                        </div>
                        <div>
                            <span class="meta-label">Permission</span>
                            <span class="permission-pill">{{ $master['permission'] }}</span>
                        </div>
                    </div>
                    <div class="master-map-action">
                        @if ($master['available'] && !request()->routeIs($master['route']))
                            <a href="{{ $master['url'] }}" class="btn btn-primary master-map-open">
                                เปิด Master <i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i>
                            </a>
                        @elseif ($master['available'])
                            <span class="route-current"><i class="fa-solid fa-circle-check me-1"></i> หน้าปัจจุบัน</span>
                        @else
                            <span class="route-unavailable"><i class="fa-solid fa-triangle-exclamation me-1"></i> Route ไม่พร้อม</span>
                        @endif
                    </div>
                </article>
            @endforeach
        </section>

        <div id="master-map-empty" class="master-map-empty d-none">
            <i class="fa-solid fa-filter-circle-xmark" aria-hidden="true"></i>
            <h2>ไม่พบ Master ที่ตรงกับตัวกรอง</h2>
            <p>ลองค้นหาด้วยชื่อ Module, ตาราง หรือรหัส Permission</p>
            <button type="button" class="btn btn-outline-primary" data-clear-filters>ล้างตัวกรอง</button>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        .master-map-page { --mm-ink: #172033; --mm-muted: #667085; --mm-line: #dfe4ec; --mm-accent: #1d4ed8; color: var(--mm-ink); }
        .master-map-hero { display: flex; justify-content: space-between; align-items: flex-end; gap: 2rem; padding: 1.7rem 1.9rem; background: #f2f6ff; border-left: 5px solid var(--mm-accent); }
        .master-map-kicker { color: var(--mm-accent); font-size: .75rem; font-weight: 800; letter-spacing: .13em; margin-bottom: .45rem; }
        .master-map-title { font-size: clamp(1.7rem, 3vw, 2.45rem); line-height: 1.2; font-weight: 750; margin: 0 0 .45rem; }
        .master-map-intro { color: var(--mm-muted); max-width: 760px; line-height: 1.65; }
        .master-map-summary { display: flex; gap: 1.5rem; flex: 0 0 auto; }
        .master-map-summary div { min-width: 72px; text-align: right; }
        .master-map-summary strong { display: block; color: var(--mm-accent); font-size: 1.7rem; line-height: 1; font-variant-numeric: tabular-nums; }
        .master-map-summary span { color: var(--mm-muted); font-size: .78rem; }
        .master-map-toolbar { display: grid; grid-template-columns: minmax(280px, 1fr) 210px 180px; gap: .7rem; }
        .master-map-search { position: relative; }
        .master-map-search i { position: absolute; left: .95rem; top: 50%; transform: translateY(-50%); color: #87909f; }
        .master-map-search input { width: 100%; height: 42px; border: 1px solid var(--mm-line); border-radius: .375rem; padding: .55rem .9rem .55rem 2.55rem; outline: none; transition: border-color .18s ease, box-shadow .18s ease; }
        .master-map-search input:focus { border-color: #7aa2f7; box-shadow: 0 0 0 .2rem rgba(29, 78, 216, .12); }
        .master-map-result-bar { min-height: 30px; display: flex; align-items: center; justify-content: space-between; color: var(--mm-muted); font-size: .86rem; }
        .master-map-list { border-top: 2px solid var(--mm-ink); }
        .master-map-row { display: grid; grid-template-columns: 150px minmax(260px, 1.25fr) minmax(290px, 1fr) 145px; align-items: center; gap: 1.25rem; padding: 1.15rem .7rem; border-bottom: 1px solid var(--mm-line); background: #fff; transition: background-color .16s ease, transform .16s ease; animation: masterMapEnter .36s ease both; }
        .master-map-row:hover { background: #f8faff; }
        .master-map-row:nth-child(n+2) { animation-delay: calc(min(var(--row-index, 1), 7) * 35ms); }
        .master-map-module span { display: inline-block; color: #344054; background: #eef1f6; padding: .32rem .58rem; border-radius: .25rem; font-size: .76rem; font-weight: 750; }
        .master-map-main h2 { font-size: 1rem; font-weight: 750; margin: 0 0 .28rem; }
        .master-map-main p { color: var(--mm-muted); font-size: .86rem; line-height: 1.5; margin: 0; }
        .master-map-meta { display: grid; grid-template-columns: 1fr 1fr; gap: .8rem; min-width: 0; }
        .master-map-meta > div { min-width: 0; }
        .meta-label { display: block; color: #98a2b3; font-size: .68rem; font-weight: 750; letter-spacing: .05em; text-transform: uppercase; margin-bottom: .25rem; }
        .master-map-meta code { display: block; color: #344054; font-size: .76rem; white-space: normal; overflow-wrap: anywhere; }
        .permission-pill { color: #175cd3; font-size: .75rem; font-weight: 700; }
        .master-map-action { text-align: right; }
        .master-map-open { min-width: 126px; transition: transform .12s ease, box-shadow .18s ease; }
        .master-map-open:hover { transform: translateY(-2px); box-shadow: 0 5px 13px rgba(29, 78, 216, .18); }
        .master-map-open:active { transform: scale(.98); }
        .route-unavailable { color: #b54708; background: #fff4e5; border-radius: .25rem; padding: .38rem .55rem; font-size: .75rem; white-space: nowrap; }
        .route-current { color: #067647; background: #ecfdf3; border-radius: .25rem; padding: .38rem .55rem; font-size: .75rem; white-space: nowrap; }
        .master-map-empty { text-align: center; padding: 4rem 1rem; color: var(--mm-muted); }
        .master-map-empty > i { font-size: 2rem; color: #98a2b3; margin-bottom: .8rem; }
        .master-map-empty h2 { color: var(--mm-ink); font-size: 1.05rem; }
        .master-map-empty p { font-size: .88rem; }
        @keyframes masterMapEnter { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
        @media (max-width: 1100px) {
            .master-map-row { grid-template-columns: 120px minmax(230px, 1fr) minmax(240px, 1fr); }
            .master-map-action { grid-column: 2 / -1; text-align: left; }
        }
        @media (max-width: 767.98px) {
            .master-map-hero { align-items: flex-start; flex-direction: column; padding: 1.25rem; }
            .master-map-summary { width: 100%; justify-content: space-between; gap: .7rem; }
            .master-map-summary div { text-align: left; min-width: 0; }
            .master-map-toolbar { grid-template-columns: 1fr; }
            .master-map-row { grid-template-columns: 1fr; gap: .75rem; padding: 1.15rem .25rem; }
            .master-map-meta { grid-template-columns: 1fr; }
            .master-map-action { grid-column: auto; }
            .master-map-open { width: 100%; }
        }
        @media (prefers-reduced-motion: reduce) {
            .master-map-row { animation: none; transition: none; }
            .master-map-open { transition: none; }
        }
    </style>
@endpush

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const search = document.getElementById('master-map-search');
            const module = document.getElementById('master-map-module');
            const status = document.getElementById('master-map-status');
            const rows = Array.from(document.querySelectorAll('.master-map-row'));
            const count = document.getElementById('master-map-result-count');
            const empty = document.getElementById('master-map-empty');

            rows.forEach((row, index) => row.style.setProperty('--row-index', index));

            function filterRows() {
                const query = search.value.trim().toLocaleLowerCase('th');
                let visible = 0;

                rows.forEach(row => {
                    const matches = (!query || row.dataset.search.includes(query))
                        && (!module.value || row.dataset.module === module.value)
                        && (!status.value || row.dataset.status === status.value);

                    row.classList.toggle('d-none', !matches);
                    if (matches) visible++;
                });

                count.textContent = `แสดง ${visible} รายการ`;
                empty.classList.toggle('d-none', visible !== 0);
            }

            function clearFilters() {
                search.value = '';
                module.value = '';
                status.value = '';
                filterRows();
                search.focus();
            }

            search.addEventListener('input', filterRows);
            module.addEventListener('change', filterRows);
            status.addEventListener('change', filterRows);
            document.getElementById('master-map-clear').addEventListener('click', clearFilters);
            document.querySelector('[data-clear-filters]').addEventListener('click', clearFilters);
            filterRows();
        });
    </script>
@endpush
