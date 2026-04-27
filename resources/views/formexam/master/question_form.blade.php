@extends('layouts.layout')

@section('title', $mode === 'edit' ? 'แก้ไขข้อสอบ' : 'เพิ่มข้อสอบ')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">

            <div class="card app-card">
                <div class="card-body p-4">

                    <h4 class="mb-1">
                        {{ $mode === 'edit' ? 'แก้ไขข้อสอบ' : 'เพิ่มข้อสอบใหม่' }}
                    </h4>
                    <p class="text-muted mb-3">
                        ฟอร์ม: {{ $formExam->name }}
                    </p>

                    <form method="POST"
                        action="{{ $mode === 'edit'
                            ? route('exam.master.questions.update', [$formExam->id, $question->id])
                            : route('exam.master.questions.store', $formExam->id) }}">
                        @csrf

                        <div class="mb-3">
                            <label class="form-label fw-semibold">ประเภทแบบทดสอบ</label>
                            <select name="exam_type_id" class="form-select" required>
                                @foreach ($formExam->examTypes as $type)
                                    <option value="{{ $type->id }}" @selected(old('exam_type_id', $question->exam_type_id ?? '') == $type->id)>
                                        {{ $type->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>


                        {{-- Category --}}
                        <div class="mb-3">
                            <label class="form-label fw-semibold">หมวด</label>
                            <select name="category_id" class="form-select" required>
                                <option value="">-- เลือกหมวด --</option>
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->id }}" @selected(old('category_id', optional($question)->exam_category_id) == $cat->id)>
                                        {{ $cat->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Question Text --}}
                        <div class="mb-3">
                            <label class="form-label fw-semibold">คำถาม</label>
                            <textarea name="question_text" rows="3" class="form-control" required>{{ old('question_text', optional($question)->question_text) }}</textarea>
                        </div>

                        <hr>

                        <h6 class="fw-semibold mb-3">ช้อยส์คำตอบ</h6>

                        @php
                            $choices =
                                old('choices') ??
                                ($mode === 'edit' ? $question->choices->pluck('choice_text')->toArray() : []);
                            $total = max(4, count($choices));
                        @endphp

                        @for ($i = 0; $i < $total; $i++)
                            <div class="row g-2 mb-2">
                                <div class="col-auto">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="correct_index"
                                            value="{{ $i }}" @checked(old(
                                                    'correct_index',
                                                    $mode === 'edit'
                                                        ? optional($question->choices->where('is_correct', 1)->first())->choice_text === ($choices[$i] ?? null)
                                                        : false))>

                                        <label class="form-check-label">เฉลย</label>
                                    </div>
                                </div>

                                <div class="col">
                                    <input type="text" class="form-control form-control-sm"
                                        name="choices[{{ $i }}]" placeholder="ช้อยส์ข้อที่ {{ $i + 1 }}"
                                        value="{{ $choices[$i] ?? '' }}">
                                </div>
                            </div>
                        @endfor

                        <div class="form-text mb-3">ต้องมีอย่างน้อย 2 ช้อยส์ และเลือกเฉลย 1 ข้อ</div>

                        <div class="d-flex justify-content-between">
                            <a href="{{ route('exam.master.questions.index', $formExam->id) }}"
                                class="btn btn-outline-secondary">
                                ย้อนกลับ
                            </a>

                            <button class="btn btn-primary">บันทึก</button>
                        </div>

                    </form>

                </div>
            </div>

        </div>
    </div>
@endsection
