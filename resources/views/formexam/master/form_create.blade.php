@extends('layouts.layout')

@section('title', 'สร้างฟอร์มข้อสอบใหม่')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-8">

            <div class="card app-card">
                <div class="card-body p-4">

                    <h4 class="mb-3">สร้างฟอร์มข้อสอบใหม่</h4>
                    <p class="text-muted mb-4">ฟอร์มหนึ่งฟอร์มจะมีหัวข้อ, ข้อสอบ และ Pre/Post test ของตัวเอง</p>

                    <form action="{{ route('exam.master.store') }}" method="POST">
                        @csrf

                        {{-- ฟิลด์ชื่อฟอร์ม --}}
                        <div class="mb-3">
                            <label class="form-label fw-semibold">ชื่อฟอร์มแบบทดสอบ <span
                                    class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                                placeholder="เช่น แบบทดสอบความปลอดภัย, แบบทดสอบ OJT" value="{{ old('name') }}" required>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- คำอธิบาย --}}
                        <div class="mb-3">
                            <label class="form-label fw-semibold">คำอธิบายเพิ่มเติม</label>
                            <textarea name="description" rows="3" class="form-control @error('description') is-invalid @enderror"
                                placeholder="อธิบายวัตถุประสงค์หรือรายละเอียดของฟอร์ม (ไม่จำเป็น)">
                            {{ old('description') }}
                        </textarea>
                            @error('description')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <hr>

                        <div class="d-flex justify-content-between">
                            <a href="{{ route('exam.master.index') }}" class="btn btn-outline-secondary">
                                ย้อนกลับ
                            </a>

                            <button type="submit" class="btn btn-primary">
                                บันทึกฟอร์มใหม่
                            </button>
                        </div>

                    </form>

                </div>
            </div>

        </div>
    </div>
@endsection
