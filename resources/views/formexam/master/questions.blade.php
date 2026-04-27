{{-- resources/views/formexam/master/questions.blade.php --}}
@extends('layouts.layout')

@section('title', 'ข้อสอบทั้งหมด')
@section('page-title', 'ข้อสอบทั้งหมด')

@push('styles')
    <style>
        .page-wrap {
            background: #f6f7fb;
            min-height: calc(100vh - 56px);
        }

        .card-soft {
            border: 0;
            border-radius: 16px;
            box-shadow: 0 8px 24px rgba(16, 24, 40, .06);
        }

        .tabs-pill .nav-link {
            border-radius: 999px;
            padding: .45rem 1rem;
        }

        .tabs-pill .nav-link.active {
            font-weight: 700;
        }

        .table-wrap {
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid rgba(0, 0, 0, .06);
            background: #fff;
        }

        .table-scroll {
            max-height: 62vh;
            overflow: auto;
        }

        .table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #fff;
            box-shadow: 0 1px 0 rgba(0, 0, 0, .06);
        }

        .q-text {
            max-width: 920px;
            white-space: normal;
        }

        .badge-dot {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
        }

        .badge-dot::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: currentColor;
            opacity: .7;
        }

        .section-title {
            font-weight: 700;
        }

        .section-bar {
            height: 1px;
            background: rgba(0, 0, 0, .08);
        }

        .actions-col {
            width: 240px;
        }

        @media (max-width: 992px) {
            .actions-col {
                width: 170px;
            }

            .q-text {
                max-width: 520px;
            }
        }

        @media (max-width: 576px) {
            .q-text {
                max-width: 260px;
            }
        }
    </style>
@endpush

@section('content')
    <div class="page-wrap py-4">
        <div class="container">

            @php
                $totalQuestions = 0;
                foreach ($groupedQuestions as $items) {
                    $totalQuestions += $items->count();
                }
            @endphp

            {{-- Header --}}
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                <div>

                    <div class="text-muted mt-1">
                        ฟอร์ม: <span
                            class="fw-semibold">{{ $formExam->name ?? ($formExam->title ?? '#' . $formExam->id) }}</span>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-primary"
                        href="{{ route('exam.master.questions.create', $formExam) }}?exam_type_id={{ $activeTypeId }}">
                        + เพิ่มข้อสอบใหม่
                    </a>
                </div>
            </div>

            <div class="card card-soft">
                <div class="card-body p-3 p-md-4">

                    {{-- Tabs: Pre/Post --}}
                    <ul class="nav nav-pills tabs-pill gap-2 mb-3">
                        @foreach ($examTypes as $t)
                            <li class="nav-item">
                                <a class="nav-link {{ (int) $t->id === (int) $activeTypeId ? 'active' : '' }}"
                                    href="{{ route('exam.master.questions.index', $formExam) }}?exam_type_id={{ $t->id }}">
                                    {{ $t->name ?? ($t->code ?? 'Type ' . $t->id) }}
                                </a>
                            </li>
                        @endforeach
                    </ul>

                    {{-- Grouped Tables --}}
                    @forelse($groupedQuestions as $categoryName => $items)
                        @php
                            $countInGroup = $items->count();
                        @endphp

                        <div class="d-flex align-items-center justify-content-between gap-2 mt-3 mb-2">
                            <div class="section-title">
                                {{ $categoryName }}
                                <span class="text-muted fw-normal ms-2">{{ $countInGroup }} ข้อ</span>
                            </div>
                            <div class="flex-grow-1 section-bar"></div>
                        </div>

                        <div class="table-wrap mb-3">
                            <div class="table-scroll">
                                <table class="table align-middle mb-0">
                                    <thead>
                                        <tr class="text-muted small">
                                            <th style="width:70px">#</th>
                                            <th>คำถาม</th>
                                            <th class="text-center" style="width:120px">สถานะ</th>
                                            <th class="text-end actions-col">แก้ไข</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        @forelse($items as $idx => $q)
                                            @php
                                                $isActive = (bool) ($q->is_active ?? false);
                                                $choiceCount =
                                                    $q->choices_count ??
                                                    (isset($q->choices) && is_countable($q->choices)
                                                        ? count($q->choices)
                                                        : null);
                                            @endphp

                                            <tr>
                                                <td class="text-muted">{{ $idx + 1 }}</td>

                                                <td>
                                                    <div class="q-text">
                                                        <div class="fw-semibold">
                                                            {{ $q->question_text ?? '-' }}
                                                        </div>

                                                        <div class="text-muted small mt-1 d-flex flex-wrap gap-2">
                                                            <span class="badge text-bg-light border">ID:
                                                                {{ $q->id }}</span>
                                                            @if (!is_null($choiceCount))
                                                                <span class="badge text-bg-light border">ช้อยส์:
                                                                    {{ $choiceCount }}</span>
                                                            @endif
                                                        </div>
                                                    </div>
                                                </td>

                                                <td class="text-center">
                                                    @if ($isActive)
                                                        <span class="badge text-bg-success badge-dot">Active</span>
                                                    @else
                                                        <span class="badge text-bg-secondary badge-dot">Inactive</span>
                                                    @endif
                                                </td>

                                                <td class="text-end">
                                                    <div class="d-inline-flex gap-2 align-items-center">

                                                        <a class="btn btn-outline-primary btn-sm"
                                                            href="{{ route('exam.master.questions.edit', [$formExam, $q]) }}?exam_type_id={{ $activeTypeId }}">
                                                            แก้ไข
                                                        </a>

                                                        @if ($isActive)
                                                            <form method="POST"
                                                                action="{{ route('exam.master.questions.deactivate', [$formExam, $q]) }}"
                                                                onsubmit="return confirm('ปิดใช้งานข้อสอบนี้?');"
                                                                class="d-inline">
                                                                @csrf
                                                                <button type="submit"
                                                                    class="btn btn-outline-danger btn-sm">ปิด</button>
                                                            </form>
                                                        @else
                                                            <form method="POST"
                                                                action="{{ route('exam.master.questions.activate', [$formExam, $q]) }}"
                                                                onsubmit="return confirm('เปิดใช้งานข้อสอบนี้?');"
                                                                class="d-inline">
                                                                @csrf
                                                                <button type="submit"
                                                                    class="btn btn-outline-success btn-sm">เปิด</button>
                                                            </form>
                                                        @endif

                                                        <form method="POST"
                                                            action="{{ route('exam.master.questions.copy', [$formExam, $q]) }}"
                                                            onsubmit="return confirm('Copy ข้อนี้ไปอีกชุด?');"
                                                            class="d-none d-lg-inline">
                                                            @csrf
                                                            <button type="submit" class="btn btn-outline-secondary btn-sm">
                                                                Copy ไปอีกชุด
                                                            </button>
                                                        </form>

                                                        {{-- Mobile actions --}}
                                                        <div class="dropdown d-inline d-lg-none">
                                                            <button class="btn btn-light border btn-sm" type="button"
                                                                data-bs-toggle="dropdown" aria-expanded="false">
                                                                ⋯
                                                            </button>
                                                            <ul class="dropdown-menu dropdown-menu-end">
                                                                <li>
                                                                    <a class="dropdown-item"
                                                                        href="{{ route('exam.master.questions.edit', [$formExam, $q]) }}?exam_type_id={{ $activeTypeId }}">
                                                                        แก้ไข
                                                                    </a>
                                                                </li>
                                                                <li>
                                                                    <hr class="dropdown-divider">
                                                                </li>
                                                                <li>
                                                                    <form method="POST"
                                                                        action="{{ route('exam.master.questions.copy', [$formExam, $q]) }}">
                                                                        @csrf
                                                                        <button class="dropdown-item" type="submit">Copy
                                                                            ไปอีกชุด</button>
                                                                    </form>
                                                                </li>
                                                            </ul>
                                                        </div>

                                                    </div>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="4" class="text-center text-muted py-4">
                                                    ไม่มีข้อสอบในหมวดนี้
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    @empty
                        <div class="text-center text-muted py-5">
                            ยังไม่มีข้อสอบ
                        </div>
                    @endforelse

                </div>
            </div>
        </div>
    </div>
@endsection
