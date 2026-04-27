@extends('layouts.layout')

@section('title', $examType->name)
@section('page-title', $examType->name)

@push('styles')
    <style>
        /* Mobile-first tweaks */
        .exam-wrap {
            padding-bottom: 84px;
        }

        /* เผื่อพื้นที่ให้ปุ่มส่งแบบทดสอบลอยล่าง */
        .exam-card-title h4 {
            font-size: 1.15rem;
        }

        .choice-item .form-check-input {
            transform: scale(1.15);
        }

        .choice-item .form-check {
            padding: .75rem .75rem;
            border: 1px solid rgba(0, 0, 0, .08);
            border-radius: 12px;
        }

        .choice-item .form-check:hover {
            background: rgba(0, 0, 0, .02);
        }

        .choice-item .form-check-label {
            width: 100%;
            cursor: pointer;
        }

        .submit-bar {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, .92);
            backdrop-filter: blur(6px);
            border-top: 1px solid rgba(0, 0, 0, .08);
            padding: .75rem;
            z-index: 1040;
        }

        @media (min-width: 992px) {
            .exam-wrap {
                padding-bottom: 0;
            }

            .submit-bar {
                position: static;
                background: transparent;
                border: 0;
                padding: 0;
                backdrop-filter: none;
            }
        }

        /* ทำให้ progress bar sticky แน่นอนบนมือถือ */
        .exam-progress {
            position: sticky;
            top: 0;
            z-index: 1050;
            background: #fff;
            border-bottom: 1px solid rgba(0, 0, 0, .08);
        }

        /* ถ้า layout ของคุณมี header fixed อยู่แล้ว ให้ปรับ top เป็นความสูง header เช่น 56px */
        @media (max-width: 767.98px) {
            .exam-progress {
                top: 0;
            }

            /* ถ้ามี navbar fixed เปลี่ยนเป็น 56px/64px */
        }

        /* กันปุ่มส่งล่างบังคำถาม */
        .exam-wrap {
            padding-bottom: 92px;
        }

        /* มือถือ: ทำให้อ่านง่ายขึ้น */
        .exam-progress .meta {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
        }

        .exam-progress .cat {
            font-weight: 700;
            font-size: 0.95rem;
            line-height: 1.2;
            max-width: 72%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .exam-progress .count {
            font-weight: 700;
            font-size: 0.95rem;
            white-space: nowrap;
        }

        .exam-progress .progress {
            height: 10px;
            border-radius: 999px;
        }

        .exam-progress .progress-bar {
            border-radius: 999px;
        }
    </style>
@endpush

@section('content')
    <div class="exam-wrap">
        <div class="row justify-content-center">
            <div class="col-12 col-xl-10">
                <form method="POST" action="{{ route('exam.submit') }}">
                    @csrf
                    <input type="hidden" name="exam_type_id" value="{{ $examType->id }}">

                    <div class="card app-card mb-3">
                        <div class="card-body">
                            <div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-3 exam-card-title">
                                <div>
                                    <h4 class="mb-1">{{ $examType->name }}</h4>
                                    @if ($examType->formExam)
                                        <p class="text-muted mb-0">ฟอร์ม: {{ $examType->formExam->name }}</p>
                                    @endif
                                </div>

                                <div class="text-md-end">
                                    <span class="badge bg-primary app-badge">
                                        {{ strtoupper($examType->code) }} TEST
                                    </span>

                                    @if ($examType->code === 'post' && !is_null($examType->pass_score))
                                        <div class="small text-muted mt-1">
                                            เกณฑ์ผ่าน: {{ $examType->pass_score }} คะแนนขึ้นไป
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <hr>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    ชื่อ - สกุล <span class="text-danger">*</span>
                                </label>
                                <input type="text" name="full_name"
                                    class="form-control @error('full_name') is-invalid @enderror"
                                    value="{{ old('full_name') }}" required>
                                @error('full_name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="alert alert-secondary small mb-0">
                                กรุณาตอบคำถามให้ครบทุกข้อก่อนกดส่งแบบทดสอบ
                            </div>
                        </div>
                    </div>

                    <div class="exam-progress">
                        <div class="container py-2">
                            <div class="meta">
                                <div class="cat">
                                    <span class="text-muted fw-normal">หมวด:</span>
                                    <span id="curCategory">-</span>
                                </div>
                                <div class="count">
                                    <span id="curIndex">1</span>/<span id="totalCount">{{ $questions->count() }}</span>
                                </div>
                            </div>

                            <div class="progress mt-2">
                                <div id="progressBar" class="progress-bar" role="progressbar" style="width: 0%"></div>
                            </div>
                        </div>
                    </div>

                    @foreach ($questions as $index => $q)
                        <div class="card mb-3 shadow-sm border-0 exam-question" data-index="{{ $index + 1 }}"
                            data-category="{{ $q->category->name ?? 'ไม่ระบุหมวด' }}">
                            <div class="card-body">
                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                                    <div class="fw-semibold">ข้อที่ {{ $index + 1 }}</div>
                                    @if ($q->category)
                                        <span class="badge bg-light text-dark border app-badge">
                                            {{ $q->category->name }}
                                        </span>
                                    @endif
                                </div>

                                <p class="mb-3">{{ $q->question_text }}</p>

                                <div class="mt-2 choice-item">
                                    @foreach ($q->choices as $choice)
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="radio"
                                                name="answers[{{ $q->id }}]"
                                                id="q{{ $q->id }}_c{{ $choice->id }}"
                                                value="{{ $choice->id }}"
                                                {{ old("answers.$q->id") == $choice->id ? 'checked' : '' }} required>
                                            <label class="form-check-label"
                                                for="q{{ $q->id }}_c{{ $choice->id }}">
                                                {{ $choice->choice_text }}
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endforeach

                    {{-- Desktop: ปุ่มอยู่ท้ายฟอร์ม / Mobile: fixed bar --}}
                    <div class="text-center mt-4 mb-4 d-none d-lg-block">
                        <button type="submit" class="btn btn-lg btn-success px-5">
                            ส่งแบบทดสอบ
                        </button>
                    </div>

                    <div class="submit-bar d-lg-none">
                        <div class="container">
                            <button type="submit" class="btn btn-success w-100 btn-lg">
                                ส่งแบบทดสอบ
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            (function() {
                const items = Array.from(document.querySelectorAll('.exam-question'));
                const total = items.length;

                const curIndexEl = document.getElementById('curIndex');
                const curCategoryEl = document.getElementById('curCategory');
                const bar = document.getElementById('progressBar');

                function setProgress(idx, cat) {
                    curIndexEl.textContent = idx;
                    curCategoryEl.textContent = cat || '-';
                    const pct = Math.round((idx / total) * 100);
                    bar.style.width = pct + '%';
                    bar.setAttribute('aria-valuenow', pct);
                }

                if (items[0]) setProgress(items[0].dataset.index, items[0].dataset.category);

                const obs = new IntersectionObserver((entries) => {
                    const visibles = entries.filter(e => e.isIntersecting);
                    if (!visibles.length) return;

                    // เลือกตัวที่อยู่ "ใกล้ขอบบน" ที่สุด (เหมาะกับมือถือ)
                    visibles.sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
                    const el = visibles[0].target;

                    setProgress(el.dataset.index, el.dataset.category);
                }, {
                    threshold: 0.15,
                    rootMargin: "-15% 0px -70% 0px" // โฟกัสบริเวณด้านบนของจอ
                });

                items.forEach(el => obs.observe(el));
            })();
        </script>
    @endpush


@endsection
