@extends('layouts.layout')

@section('title', 'Organization Chart')
@section('page-title', 'Organization Chart')

@push('styles')
    <style>
        .org-page {
            --org-navy: #16324f;
            --org-blue: #2f6690;
            --org-sky: #d9edf7;
            --org-line: #b9c9d8;
            --org-bg: #f4f7fa;
        }

        .org-hero {
            background:
                radial-gradient(circle at 92% 18%, rgba(255, 255, 255, .2), transparent 24%),
                linear-gradient(135deg, #16324f 0%, #24557c 62%, #2f78a8 100%);
            border-radius: 20px;
            color: #fff;
            padding: 1.5rem;
            box-shadow: 0 14px 32px rgba(22, 50, 79, .18);
        }

        .org-hero-label {
            font-size: .75rem;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
            opacity: .72;
        }

        .org-filter {
            min-width: min(100%, 360px);
        }

        .org-filter .form-select {
            border: 0;
            min-height: 44px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, .12);
        }

        .org-stat {
            height: 100%;
            padding: 1rem 1.1rem;
            background: #fff;
            border: 1px solid #e1e8ef;
            border-radius: 14px;
            box-shadow: 0 6px 18px rgba(25, 55, 82, .06);
        }

        .org-stat-icon {
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            color: var(--org-blue);
            background: #eaf3f9;
            border-radius: 12px;
        }

        .org-stat-value {
            color: var(--org-navy);
            font-size: 1.45rem;
            font-weight: 800;
            line-height: 1;
        }

        .org-chart {
            background: var(--org-bg);
            border: 1px solid #e0e8ef;
            border-radius: 20px;
            padding: 1.25rem;
            overflow: hidden;
        }

        .org-level {
            position: relative;
            padding-bottom: 2.1rem;
        }

        .org-level:not(:last-child)::after {
            content: "";
            position: absolute;
            bottom: .25rem;
            left: 50%;
            width: 2px;
            height: 1.55rem;
            background: var(--org-line);
        }

        .org-level-heading {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .55rem;
            margin-bottom: .85rem;
            color: #5d7184;
            font-size: .78rem;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .org-level-heading::before,
        .org-level-heading::after {
            content: "";
            width: 38px;
            height: 1px;
            background: #cbd7e1;
        }

        .org-role-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(245px, 1fr));
            gap: .9rem;
            max-width: 1120px;
            margin: 0 auto;
        }

        .org-role {
            overflow: hidden;
            background: #fff;
            border: 1px solid #dce5ed;
            border-top: 4px solid #7794aa;
            border-radius: 14px;
            box-shadow: 0 8px 20px rgba(25, 55, 82, .07);
        }

        .org-role.executive { border-top-color: #8b5e3c; }
        .org-role.manager { border-top-color: #2f6690; }
        .org-role.supervisor { border-top-color: #4d8b74; }
        .org-role.staff { border-top-color: #7895ad; }

        .org-role-header {
            padding: .85rem 1rem;
            border-bottom: 1px solid #edf1f5;
        }

        .org-role-title {
            color: var(--org-navy);
            font-weight: 800;
            line-height: 1.35;
        }

        .org-role-code {
            color: #8191a0;
            font-size: .72rem;
            font-weight: 700;
        }

        .org-person {
            display: flex;
            gap: .75rem;
            align-items: center;
            padding: .8rem 1rem;
        }

        .org-person + .org-person {
            border-top: 1px solid #eff3f6;
        }

        .org-avatar {
            flex: 0 0 38px;
            width: 38px;
            height: 38px;
            display: grid;
            place-items: center;
            color: #fff;
            background: linear-gradient(145deg, #315f83, #4c8ab6);
            border-radius: 50%;
            font-size: .82rem;
            font-weight: 800;
        }

        .org-person-name {
            color: #233f58;
            font-size: .9rem;
            font-weight: 700;
        }

        .org-person-meta {
            color: #7a8a98;
            font-size: .72rem;
        }

        .org-vacant {
            padding: 1rem;
            color: #8796a4;
            font-size: .8rem;
            text-align: center;
            background: repeating-linear-gradient(
                -45deg,
                #fafcfd,
                #fafcfd 8px,
                #f5f8fa 8px,
                #f5f8fa 16px
            );
        }

        .org-empty {
            padding: 3.5rem 1rem;
            color: #738596;
            text-align: center;
        }

        @media print {
            .sidebar-col,
            .mobile-menu-trigger,
            .org-filter,
            .org-print,
            footer,
            .dropdown {
                display: none !important;
            }

            .main-col {
                width: 100% !important;
            }

            .main-content {
                padding: 0 !important;
            }

            .org-hero,
            .org-stat,
            .org-chart,
            .org-role {
                box-shadow: none !important;
            }
        }
    </style>
@endpush

@section('content')
    <div class="org-page">
        <section class="org-hero mb-4">
            <div class="d-lg-flex align-items-end justify-content-between gap-4">
                <div class="mb-3 mb-lg-0">
                    <div class="org-hero-label mb-2">Menam Stainless Wire</div>
                    <h3 class="fw-bold mb-1">
                        {{ $selectedDepartment?->name ?? 'โครงสร้างองค์กร' }}
                    </h3>
                    @if ($selectedDepartment)
                        <div class="opacity-75">
                            รหัสแผนก {{ $selectedDepartment->code }}
                            @if ($selectedDepartment->site_code)
                                · Site {{ $selectedDepartment->site_code }}
                            @endif
                            @if ($selectedDepartment->cost_center)
                                · Cost center {{ $selectedDepartment->cost_center }}
                            @endif
                        </div>
                    @endif
                </div>

                <form class="org-filter" method="GET" action="{{ route('organization.index') }}">
                    <label class="form-label small fw-semibold text-white-50" for="department_id">
                        เลือกแผนก
                    </label>
                    <select class="form-select" id="department_id" name="department_id"
                        onchange="this.form.submit()">
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}"
                                @selected((int) ($selectedDepartment?->id ?? 0) === (int) $department->id)>
                                {{ $department->name }} ({{ $department->code }})
                            </option>
                        @endforeach
                    </select>
                </form>
            </div>
        </section>

        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="org-stat d-flex align-items-center gap-3">
                    <span class="org-stat-icon"><i class="fas fa-users"></i></span>
                    <div>
                        <div class="org-stat-value">{{ number_format($employeeCount) }}</div>
                        <div class="small text-muted">พนักงาน Active</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="org-stat d-flex align-items-center gap-3">
                    <span class="org-stat-icon"><i class="fas fa-briefcase"></i></span>
                    <div>
                        <div class="org-stat-value">{{ number_format($roles->count()) }}</div>
                        <div class="small text-muted">ตำแหน่ง Active</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="org-stat d-flex align-items-center gap-3">
                    <span class="org-stat-icon"><i class="fas fa-user-clock"></i></span>
                    <div>
                        <div class="org-stat-value">{{ number_format($unassignedMembers->count()) }}</div>
                        <div class="small text-muted">ยังไม่ผูกตำแหน่ง</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="org-stat d-flex align-items-center gap-3">
                    <span class="org-stat-icon"><i class="fas fa-chair"></i></span>
                    <div>
                        <div class="org-stat-value">{{ number_format($vacantPositionCount) }}</div>
                        <div class="small text-muted">ตำแหน่งว่าง</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end mb-2">
            <button type="button" class="btn btn-sm btn-outline-secondary org-print"
                onclick="window.print()">
                <i class="fas fa-print me-1"></i> พิมพ์ผัง
            </button>
        </div>

        <section class="org-chart">
            @forelse ($levels as $level => $levelRoles)
                @php
                    $presentation = $levelPresentation[$level] ?? $levelPresentation[0];
                @endphp
                <div class="org-level">
                    <div class="org-level-heading">
                        Level {{ $level ?: '-' }} · {{ $presentation['label'] }}
                    </div>
                    <div class="org-role-grid">
                        @foreach ($levelRoles as $role)
                            @php
                                $members = $membersByRole->get((int) $role->id, collect());
                            @endphp
                            <article class="org-role {{ $presentation['class'] }}">
                                <header class="org-role-header">
                                    <div class="org-role-title">{{ $role->name }}</div>
                                    <div class="org-role-code">
                                        {{ $role->code }} · {{ number_format($members->count()) }} คน
                                    </div>
                                </header>

                                @forelse ($members as $member)
                                    @php
                                        $nameParts = preg_split('/\s+/u', trim($member->name), -1, PREG_SPLIT_NO_EMPTY);
                                        $initials = collect($nameParts)
                                            ->take(2)
                                            ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
                                            ->implode('');
                                    @endphp
                                    <div class="org-person">
                                        <span class="org-avatar">{{ $initials ?: '?' }}</span>
                                        <div class="min-w-0">
                                            <div class="org-person-name">{{ $member->name }}</div>
                                            <div class="org-person-meta">
                                                {{ $member->user_code ?: 'ไม่มีรหัสพนักงาน' }}
                                                @if ($member->supervisor_name)
                                                    · หัวหน้า {{ $member->supervisor_name }}
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @empty
                                    <div class="org-vacant">
                                        <i class="far fa-circle me-1"></i> ตำแหน่งว่าง
                                    </div>
                                @endforelse
                            </article>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="org-empty">
                    <i class="fas fa-sitemap fa-3x mb-3 opacity-50"></i>
                    <h6 class="fw-bold">ยังไม่มีโครงสร้างตำแหน่งในแผนกนี้</h6>
                    <div class="small">เมื่อกำหนดตำแหน่งและผูกพนักงานแล้ว ผังจะแสดงอัตโนมัติ</div>
                </div>
            @endforelse

            @if ($unassignedMembers->isNotEmpty())
                <div class="mt-2 pt-3 border-top">
                    <div class="small fw-bold text-warning-emphasis mb-2">
                        <i class="fas fa-triangle-exclamation me-1"></i>
                        พนักงานที่ยังไม่ผูกตำแหน่ง ({{ $unassignedMembers->count() }})
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        @foreach ($unassignedMembers as $member)
                            <span class="badge rounded-pill text-bg-light border px-3 py-2">
                                {{ $member->name }} · {{ $member->user_code ?: '-' }}
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif
        </section>
    </div>
@endsection
