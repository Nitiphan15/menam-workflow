@extends('layouts.layout')

@section('page-title', 'เมนูทั้งหมด')

@section('content')
    <div class="container-fluid py-3 all-menus-page">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
            <p class="text-muted mb-0">รวมเมนูสาธารณะ เมนูที่ใช้ประจำยังอยู่ในแถบด้านซ้าย</p>

            <div class="all-menus-search">
                <label for="allMenuSearch" class="form-label small text-muted mb-1">ค้นหาเมนู</label>
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                    <input id="allMenuSearch" type="search" class="form-control" placeholder="พิมพ์ชื่อเมนู..." autocomplete="off">
                </div>
            </div>
        </div>

        <div class="row g-3" id="allMenuGrid">
            @foreach ($menuGroups as $group)
                <div class="col-12 col-md-6 col-xl-4 all-menu-card" data-menu-group="{{ mb_strtolower($group['text']) }}">
                    <section class="card h-100 shadow-sm border-0">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <span class="all-menu-icon"><i class="{{ $group['icon'] }}"></i></span>
                                <h2 class="h6 mb-0">{{ $group['text'] }}</h2>
                            </div>

                            <div class="list-group list-group-flush">
                                @foreach ($group['links'] as $link)
                                    <a class="list-group-item list-group-item-action px-0 d-flex align-items-center justify-content-between gap-3 all-menu-link"
                                        data-menu-text="{{ mb_strtolower($link['text']) }}"
                                        href="{{ $link['url'] }}">
                                        <span><i class="{{ $link['icon'] }} fa-fw me-2 text-muted"></i>{{ $link['text'] }}</span>
                                        <i class="fas fa-chevron-right small text-muted"></i>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    </section>
                </div>
            @endforeach
        </div>

        <div id="allMenuEmpty" class="text-center text-muted py-5 {{ count($menuGroups) ? 'd-none' : '' }}">
            <i class="fas fa-search fa-2x mb-3"></i>
            <p class="mb-0">{{ count($menuGroups) ? 'ไม่พบเมนูที่ค้นหา' : 'ยังไม่มีเมนูที่คุณเข้าถึงได้' }}</p>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        .all-menus-page { max-width: 1240px; }
        .all-menus-search { width: min(100%, 360px); }
        .all-menu-icon {
            display: inline-grid;
            place-items: center;
            width: 2.5rem;
            height: 2.5rem;
            flex: 0 0 2.5rem;
            color: #34495e;
            background: #eef2f5;
            border-radius: .65rem;
        }
        .all-menu-card .card { border-radius: .75rem; }
        .all-menu-card .list-group-item { border-color: #edf0f2; }
        .all-menu-card .list-group-item:last-child { border-bottom: 0; }
    </style>
@endpush

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const input = document.getElementById('allMenuSearch');
            const cards = [...document.querySelectorAll('.all-menu-card')];
            const empty = document.getElementById('allMenuEmpty');

            input?.addEventListener('input', () => {
                const query = input.value.trim().toLocaleLowerCase('th-TH');
                let visibleCards = 0;

                cards.forEach(card => {
                    // ชื่อกลุ่มตรง → แสดงทุกลิงก์ในการ์ด, ไม่งั้นแสดงเฉพาะลิงก์ที่ตรง
                    const groupMatched = !query || card.dataset.menuGroup.includes(query);
                    let visibleLinks = 0;

                    card.querySelectorAll('.all-menu-link').forEach(link => {
                        const matched = groupMatched || link.dataset.menuText.includes(query);
                        link.classList.toggle('d-none', !matched);
                        if (matched) visibleLinks++;
                    });

                    card.classList.toggle('d-none', visibleLinks === 0);
                    if (visibleLinks > 0) visibleCards++;
                });

                empty?.classList.toggle('d-none', visibleCards > 0);
            });
        });
    </script>
@endpush
