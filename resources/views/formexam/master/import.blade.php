@extends('layouts.layout')

@section('title', 'Import Excel: ' . $formExam->name)

@section('content')
    <div class="card app-card">
        <div class="card-body">
            <div class="d-flex align-items-start justify-content-between">
                <div>
                    <h4 class="mb-1">Import ข้อสอบด้วย Excel</h4>
                    <div class="text-muted">ฟอร์ม: <b>{{ $formExam->name }}</b></div>
                </div>

                <a href="{{ route('exam.master.import.template', ['formExam' => $formExam->id]) }}"
                    class="btn btn-outline-secondary btn-sm">
                    Download Format Excel
                </a>
            </div>

            <hr>

            <form method="POST" action="{{ route('exam.master.import.store', $formExam->id) }}"
                enctype="multipart/form-data" class="row g-3">
                @csrf

                <div class="col-md-6">
                    <label class="form-label">ไฟล์ Excel</label>
                    <input type="file" name="file" class="form-control" required>
                    <div class="form-text">รองรับ .xlsx / .xls / .csv</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">โหมด Import</label>
                    <select name="mode" class="form-select" required>
                        @php $mode = old('mode', 'append'); @endphp
                        <option value="append" @selected($mode === 'append')>เพิ่มต่อจากของเดิม (Append)</option>
                        {{-- ✅ รองรับ mode เก่า (ถ้ามี JS/ของเดิมส่งมา) --}}
                        <option value="replace" @selected($mode === 'replace')>ล้างข้อสอบทั้งหมดของฟอร์มนี้ก่อน (Replace -
                            legacy)</option>

                        <option value="replace_pre" @selected($mode === 'replace_pre')>ล้างเฉพาะ Pre ของฟอร์มนี้ก่อน (Replace Pre)
                        </option>
                        <option value="replace_post" @selected($mode === 'replace_post')>ล้างเฉพาะ Post ของฟอร์มนี้ก่อน (Replace
                            Post)</option>
                        <option value="replace_all" @selected($mode === 'replace_all')>ล้างข้อสอบทั้งหมดของฟอร์มนี้ก่อน (Replace
                            All)</option>
                    </select>
                    <div class="form-text">Replace จะลบ Questions/Choices ของฟอร์มนี้ทั้งหมด</div>
                </div>

                <div class="col-12 d-flex gap-2">
                    <a href="{{ route('exam.master.edit', $formExam->id) }}" class="btn btn-light">ย้อนกลับ</a>
                    <button class="btn btn-success">Import</button>
                </div>
            </form>
        </div>
    </div>
@endsection
