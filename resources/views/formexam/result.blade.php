@extends('layouts.layout')

@section('title', 'ผลการทำแบบทดสอบ')
@section('page-title', 'ผลการทำแบบทดสอบ')

@push('styles')
    <style>
        /* Table -> Cards on mobile */
        .answers-cards {
            display: none;
        }

        @media (max-width: 767.98px) {
            .answers-table {
                display: none;
            }

            .answers-cards {
                display: block;
            }

            .answer-card {
                border: 1px solid rgba(0, 0, 0, .08);
                border-radius: 14px;
                padding: 12px;
                background: #fff;
            }

            .answer-meta {
                font-size: .9rem;
            }
        }
    </style>
@endpush

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-lg-10">

            {{-- สรุปผลด้านบน --}}
            <div class="card app-card mb-4">
                <div class="card-body p-3 p-md-4">

                    <h4 class="mb-3">ผลการทำแบบทดสอบ</h4>

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <div class="mb-1 text-muted small">ชื่อผู้ทำแบบทดสอบ</div>
                            <div class="fw-semibold">{{ $session->full_name }}</div>
                        </div>

                        <div class="col-12 col-md-6">
                            <div class="mb-1 text-muted small">ฟอร์ม</div>
                            <div class="fw-semibold">
                                {{ $session->formExam->name ?? '-' }}
                                @if ($session->examType)
                                    <span class="text-muted"> ({{ $session->examType->name }})</span>
                                @endif
                            </div>
                        </div>

                        <div class="col-12 col-md-4">
                            <div class="mb-1 text-muted small">คะแนน</div>
                            <div class="fw-semibold">
                                {{ $session->total_score }} / {{ $session->max_score }}
                                @if (!is_null($percentage))
                                    <span class="text-muted"> ({{ $percentage }}%)</span>
                                @endif
                            </div>
                        </div>

                        <div class="col-12 col-md-4">
                            <div class="mb-1 text-muted small">วันที่ทำแบบทดสอบ</div>
                            <div class="fw-semibold">
                                {{ optional($session->submitted_at)->format('d/m/Y H:i') }}
                            </div>
                        </div>

                        <div class="col-12 col-md-4">
                            <div class="mb-1 text-muted small">สถานะ</div>
                            <div class="fw-semibold">
                                @if ($session->examType && $session->examType->code === 'post')
                                    @if ($session->is_pass === 1)
                                        <span class="badge bg-success">ผ่านเกณฑ์</span>
                                    @elseif($session->is_pass === 0)
                                        <span class="badge bg-danger">ไม่ผ่านเกณฑ์</span>
                                    @else
                                        <span class="badge bg-secondary">ยังไม่คำนวณ</span>
                                    @endif

                                    @if (!is_null($session->examType->pass_score))
                                        <span class="small text-muted ms-2">
                                            (เกณฑ์ผ่าน {{ $session->examType->pass_score }} ข้อขึ้นไป)
                                        </span>
                                    @endif
                                @else
                                    <span class="badge bg-info text-dark">Pre Test (ไม่มีเกณฑ์ผ่าน)</span>
                                @endif
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            {{-- รายละเอียดคำตอบ --}}
            <div class="card app-card">
                <div class="card-body p-3 p-md-4">

                    <h5 class="mb-3">รายละเอียดคำตอบ</h5>

                    {{-- Desktop/Tablet: Table responsive --}}
                    <div class="answers-table">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width:60px" class="text-center">#</th>
                                        <th>คำถาม</th>
                                        <th style="min-width:220px;">คำตอบที่เลือก</th>
                                        <th style="min-width:220px;">คำตอบที่ถูกต้อง</th>
                                        <th style="width:100px;" class="text-center">ผล</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($session->answers as $answer)
                                        @php
                                            $question = $answer->question;
                                            $chosenChoice = $answer->choice;
                                            $correctChoice = $question?->choices->firstWhere('is_correct', 1);
                                        @endphp
                                        <tr>
                                            <td class="text-center">{{ $loop->iteration }}</td>
                                            <td>{{ $question->question_text ?? '-' }}</td>
                                            <td>
                                                @if ($chosenChoice)
                                                    {{ $chosenChoice->choice_text }}
                                                @else
                                                    <span class="text-muted">ไม่ได้เลือกคำตอบ</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if ($correctChoice)
                                                    {{ $correctChoice->choice_text }}
                                                @else
                                                    <span class="text-muted">ไม่กำหนดเฉลย</span>
                                                @endif
                                            </td>
                                            <td class="text-center">
                                                @if ($answer->is_correct)
                                                    <span class="badge bg-success">ถูก</span>
                                                @else
                                                    <span class="badge bg-danger">ผิด</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- Mobile: Card list --}}
                    <div class="answers-cards">
                        <div class="d-grid gap-2">
                            @foreach ($session->answers as $answer)
                                @php
                                    $question = $answer->question;
                                    $chosenChoice = $answer->choice;
                                    $correctChoice = $question?->choices->firstWhere('is_correct', 1);
                                @endphp
                                <div class="answer-card">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div class="fw-semibold">ข้อที่ {{ $loop->iteration }}</div>
                                        <div>
                                            @if ($answer->is_correct)
                                                <span class="badge bg-success">ถูก</span>
                                            @else
                                                <span class="badge bg-danger">ผิด</span>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="mt-2">
                                        <div class="text-muted small mb-1">คำถาม</div>
                                        <div>{{ $question->question_text ?? '-' }}</div>
                                    </div>

                                    <div class="mt-3 answer-meta">
                                        <div class="text-muted small">คำตอบที่เลือก</div>
                                        <div class="fw-semibold">
                                            @if ($chosenChoice)
                                                {{ $chosenChoice->choice_text }}
                                            @else
                                                <span class="text-muted">ไม่ได้เลือกคำตอบ</span>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="mt-2 answer-meta">
                                        <div class="text-muted small">คำตอบที่ถูกต้อง</div>
                                        <div class="fw-semibold">
                                            @if ($correctChoice)
                                                {{ $correctChoice->choice_text }}
                                            @else
                                                <span class="text-muted">ไม่กำหนดเฉลย</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    @php
                        $prevUrl = url()->previous();
                        $cameFromSessions = \Illuminate\Support\Str::contains($prevUrl, '/exam/master/');
                    @endphp

                    <div class="mt-3">
                        @if ($cameFromSessions)
                            <a href="{{ route('exam.master.sessions.index', $session->exam_form_id) }}"
                                class="btn btn-outline-secondary btn-sm w-100 w-md-auto">
                                กลับไปหน้าผลสอบทั้งหมด
                            </a>
                        @else
                            <a href="{{ route('exam.select') }}" class="btn btn-outline-secondary btn-sm w-100 w-md-auto">
                                กลับไปเลือกแบบทดสอบ
                            </a>
                        @endif
                    </div>

                </div>
            </div>

        </div>
    </div>
@endsection
