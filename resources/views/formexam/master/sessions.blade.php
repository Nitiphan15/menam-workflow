@extends('layouts.layout')

@section('title', 'ผลการทำข้อสอบ: ' . $formExam->name)

@section('content')
    <div class="card app-card">
        <div class="card-body">

            {{-- Header --}}
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="mb-0">ผลการทำข้อสอบทั้งหมด</h5>
                    <div class="text-muted small">
                        ฟอร์ม: {{ $formExam->name }}
                    </div>
                </div>

                <div class="text-end">
                    {{-- ปุ่ม Export: ส่ง query string filter ไปด้วย --}}
                    <a href="{{ route('exam.master.sessions.export', $formExam->id) }}?{{ http_build_query(request()->only('exam_type', 'date_from', 'date_to')) }}"
                        class="btn btn-success btn-sm me-2">
                        Export Excel
                    </a>

                    <a href="{{ route('exam.master.index', $formExam->id) }}" class="btn btn-outline-secondary btn-sm">
                        ย้อนกลับ
                    </a>
                </div>
            </div>

            {{-- Filters --}}
            <form method="GET" class="row g-2 mb-3">
                <div class="col-md-3">
                    <label class="form-label">ประเภทแบบทดสอบ</label>
                    <select name="exam_type" class="form-select form-select-sm">
                        <option value="">ทั้งหมด</option>
                        <option value="pre" {{ request('exam_type') === 'pre' ? 'selected' : '' }}>Pre Test</option>
                        <option value="post" {{ request('exam_type') === 'post' ? 'selected' : '' }}>Post Test</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">วันที่ทำ (ตั้งแต่)</label>
                    <input type="date" name="date_from" value="{{ request('date_from') }}"
                        class="form-control form-control-sm">
                </div>

                <div class="col-md-3">
                    <label class="form-label">ถึงวันที่</label>
                    <input type="date" name="date_to" value="{{ request('date_to') }}"
                        class="form-control form-control-sm">
                </div>

                <div class="col-md-3 d-flex align-items-end">
                    <button class="btn btn-primary btn-sm me-2">ค้นหา</button>
                    <a href="{{ route('exam.master.sessions.index', $formExam->id) }}"
                        class="btn btn-outline-secondary btn-sm">
                        ล้างค่า
                    </a>
                </div>
            </form>

            {{-- Table --}}
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th style="width: 60px;">#</th>
                            <th>ชื่อผู้ทำแบบทดสอบ</th>
                            <th style="width: 110px;">ประเภท</th>
                            <th class="text-center" style="width: 120px;">คะแนน</th>
                            <th class="text-center" style="width: 120px;">สถานะ</th>
                            <th style="width: 170px;">วันที่ทำ</th>
                            <th class="text-center" style="width: 100px;">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sessions as $index => $session)
                            {{-- ถ้า Post แล้วไม่ผ่าน → row แดงอ่อน --}}
                            @php
                                $isFail = ($session->examType->code ?? null) === 'post' && $session->is_pass === 0;
                            @endphp
                            <tr class="{{ $isFail ? 'table-danger' : '' }}">
                                <td>{{ $index + 1 }}</td>
                                <td>
                                    {{ $session->full_name }}
                                    <div class="text-muted small">
                                        Session #{{ $session->id }}
                                    </div>
                                </td>
                                <td>
                                    @php $code = $session->examType->code ?? ''; @endphp
                                    <span class="badge bg-info text-dark">
                                        {{ strtoupper($code) }} Test
                                    </span>
                                </td>
                                <td class="text-center">
                                    {{ $session->total_score }} / {{ $session->max_score }}
                                </td>
                                <td class="text-center">
                                    @if (($session->examType->code ?? null) === 'pre')
                                        <span class="badge bg-secondary">
                                            Pre Test
                                        </span>
                                    @else
                                        @if ($session->is_pass === 1)
                                            <span class="badge bg-success">ผ่าน</span>
                                        @elseif ($session->is_pass === 0)
                                            <span class="badge bg-danger">ไม่ผ่าน</span>
                                        @else
                                            <span class="badge bg-secondary">-</span>
                                        @endif
                                    @endif
                                </td>
                                <td>
                                    {{ optional($session->submitted_at)->format('d/m/Y H:i') }}
                                </td>
                                <td class="text-center">
                                    <a href="{{ route('exam.result', $session->id) }}"
                                        class="btn btn-outline-primary btn-sm">
                                        ดูผล
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted">
                                    ยังไม่มีการทำแบบทดสอบ
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        </div>
    </div>
@endsection
