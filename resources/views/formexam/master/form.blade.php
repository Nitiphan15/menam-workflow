@extends('layouts.layout')

@section('title', 'จัดการฟอร์ม: ' . $formExam->name)

@section('content')
    <div class="row g-4">
        <div class="col-lg-7">

            {{-- Exam Types --}}
            <div class="card app-card mb-3">
                <div class="card-body">
                    <h5 class="mb-3">ประเภทแบบทดสอบ / เกณฑ์ผ่าน</h5>

                    @foreach ($formExam->examTypes as $type)
                        <form action="{{ route('exam.master.examtypes.update', [$formExam->id, $type->id]) }}" method="POST"
                            class="row g-2 align-items-end mb-3">
                            @csrf
                            @method('PUT')

                            <div class="col-md-4">
                                <label class="form-label fw-semibold">{{ $type->name }}</label>
                                <div class="text-muted small">code: {{ $type->code }}</div>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">เกณฑ์ผ่าน (จำนวนข้อถูกขั้นต่ำ)</label>

                                @if ($type->code === 'pre')
                                    {{-- Pre Test: ไม่มีเกณฑ์ผ่าน แสดงเป็นข้อความเฉย ๆ --}}
                                    <input type="text" class="form-control" value="ไม่มีเกณฑ์ผ่าน แสดงเฉพาะคะแนน"
                                        readonly style="background-color:#f8f9fa;">
                                @else
                                    {{-- Post Test: ตั้งค่าได้ --}}
                                    <input type="number" name="pass_score" value="{{ $type->pass_score }}" min="0"
                                        class="form-control">
                                @endif
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">สถานะ</label><br>
                                @if ($type->is_active)
                                    <span class="badge bg-success">Active</span>
                                @else
                                    <span class="badge bg-secondary">Inactive</span>
                                @endif
                            </div>

                            <div class="col-md-3 text-end">
                                <button type="submit" class="btn btn-primary mt-4">บันทึก</button>
                            </div>
                        </form>
                        <hr>
                    @endforeach

                    @if ($formExam->examTypes->isEmpty())
                        <div class="text-muted small">
                            ยังไม่มีการตั้งค่า Pre/Post สำหรับฟอร์มนี้
                        </div>
                    @endif

                </div>
            </div>


            {{-- Categories --}}
            <div class="card app-card">
                <div class="card-body">
                    <h5 class="mb-3">หัวข้อ / หมวดข้อสอบ</h5>

                    <form method="POST" action="{{ route('exam.master.categories.store', $formExam->id) }}"
                        class="row g-2 mb-3">
                        @csrf
                        <div class="col-md-9">
                            <input type="text" name="name" class="form-control form-control-sm"
                                placeholder="เพิ่มหัวข้อ เช่น ความปลอดภัย, 5ส" required>
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-success btn-sm w-100">+ เพิ่มหัวข้อ</button>
                        </div>
                    </form>

                    <div class="table-responsive" style="max-height: 260px; overflow-y:auto;">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>หัวข้อ</th>
                                    <th class="text-end" style="width: 160px;">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($formExam->categories as $cat)
                                    <tr>
                                        <td>{{ $cat->name }}</td>
                                        <td class="text-end">
                                            {{-- ปุ่มแก้ไข: เปิด modal --}}
                                            <button type="button" class="btn btn-sm btn-outline-secondary me-1"
                                                data-bs-toggle="modal" data-bs-target="#editCategoryModal"
                                                data-id="{{ $cat->id }}" data-name="{{ $cat->name }}">
                                                แก้ไข
                                            </button>

                                            {{-- ปุ่มลบ --}}
                                            <form action="{{ route('exam.master.categories.destroy', $cat->id) }}"
                                                method="POST" class="d-inline"
                                                onsubmit="return confirm('ยืนยันการลบหัวข้อนี้?')">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger">ลบ</button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="2" class="text-center text-muted">ยังไม่มีหัวข้อ</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>



        </div>

        <div class="col-lg-5">

            {{-- Questions --}}
            <div class="card app-card mb-4">
                <div class="card-body">
                    <h5 class="mb-3">ข้อสอบ</h5>

                    <a href="{{ route('exam.master.import.create', ['formExam' => $formExam->id]) }}"
                        class="btn btn-success btn-sm mb-3">
                        Import Excel
                    </a>
                    <a href="{{ route('exam.master.questions.create', $formExam->id) }}"
                        class="btn btn-primary btn-sm mb-3">+ เพิ่มข้อสอบใหม่</a>

                    <a href="{{ route('exam.master.questions.index', $formExam->id) }}"
                        class="btn btn-outline-secondary btn-sm mb-3">
                        ดูรายการข้อสอบทั้งหมด
                    </a>

                </div>
            </div>

            {{-- Sessions --}}
            <div class="card app-card">
                <div class="card-body">
                    <h5 class="mb-3">ผลการทำข้อสอบ</h5>

                    <a href="{{ route('exam.master.sessions.index', $formExam->id) }}"
                        class="btn btn-outline-primary btn-sm">
                        ดูผลการทำข้อสอบ
                    </a>
                </div>
            </div>

        </div>
    </div>



    {{-- Modal แก้ไขหัวข้อ --}}
    <div class="modal fade" id="editCategoryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" id="edit-category-form">
                @csrf
                @method('PUT')

                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">แก้ไขหัวข้อ</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">ชื่อหัวข้อ</label>
                            <input type="text" name="name" id="edit-category-name" class="form-control" required>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary">บันทึก</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Script ตั้งค่า action + ค่าเดิมใน modal --}}
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var modalEl = document.getElementById('editCategoryModal');

            if (!modalEl) return;

            modalEl.addEventListener('show.bs.modal', function(event) {
                var button = event.relatedTarget;
                var id = button.getAttribute('data-id');
                var name = button.getAttribute('data-name');

                var form = document.getElementById('edit-category-form');
                var actionTemplate = "{{ route('exam.master.categories.update', ':id') }}";
                form.action = actionTemplate.replace(':id', id);

                document.getElementById('edit-category-name').value = name;
            });
        });
    </script>

@endsection
