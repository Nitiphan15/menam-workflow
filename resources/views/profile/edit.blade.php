@extends('layouts.layout')

@section('title', 'แก้ไขข้อมูลส่วนตัว')
@section('page-title', 'แก้ไขข้อมูลส่วนตัว')

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-user-edit me-2"></i>ข้อมูลส่วนตัว
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data">
                        @csrf
                        @method('PUT')

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="name" class="form-label">ชื่อ-นามสกุล <span
                                        class="text-danger">*</span></label>
                                <input type="text" class="form-control @error('name') is-invalid @enderror"
                                    id="name" name="name" value="{{ old('name', $user->name) }}" required>
                                @error('name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">อีเมล <span class="text-danger">*</span></label>
                                <input type="email" class="form-control @error('email') is-invalid @enderror"
                                    id="email" name="email" value="{{ old('email', $user->email) }}" required>
                                @error('email')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="phone" class="form-label">เบอร์ออฟฟิศ <span
                                        class="text-danger">*</span></label>
                                <input type="text" class="form-control @error('phone') is-invalid @enderror"
                                    id="phone" name="phone" value="{{ old('phone', $user->phone) }}" required>
                                @error('phone')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6 mb-3">

                            </div>
                        </div>

                        <div class="mb-3">

                        </div>

                        <div class="mb-4">
                            <label for="signature" class="form-label">ลายเซ็นสำหรับเอกสาร PDF</label>
                            @if (!empty($user->signature_path))
                                <div class="border rounded p-3 mb-2 bg-light">
                                    <div class="small text-muted mb-2">ลายเซ็นปัจจุบัน</div>
                                    <img src="{{ Storage::disk('public')->url($user->signature_path) }}"
                                        alt="Signature" style="max-width: 240px; max-height: 80px;">
                                </div>
                            @endif
                            <input type="file" class="form-control @error('signature') is-invalid @enderror"
                                id="signature" name="signature" accept="image/png,image/jpeg">
                            <div class="form-text">รองรับ PNG/JPG ไม่เกิน 2MB ระบบจะย่อรูปให้เหมาะกับช่องลายเซ็นใน PDF อัตโนมัติ</div>
                            @error('signature')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="d-flex justify-content-between">

                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>บันทึกข้อมูล
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h6 class="card-title mb-0">
                        <i class="fas fa-shield-alt me-2"></i>การตั้งค่าความปลอดภัย
                    </h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <h6 class="text-primary">ข้อมูลบัญชี:</h6>
                        <ul class="list-unstyled">
                            <li><strong>สถานะ:</strong>
                                @if ($user->role === 'admin')
                                    <span class="badge bg-danger">ผู้ดูแลระบบ</span>
                                @else
                                    <span class="badge bg-primary">ผู้ใช้</span>
                                @endif
                            </li>
                            <li><strong>สมัครเมื่อ:</strong> {{ $user->created_at->format('d/m/Y H:i') }}</li>
                            <li><strong>อัพเดทล่าสุด:</strong> {{ $user->updated_at->format('d/m/Y H:i') }}</li>
                        </ul>
                    </div>

                    <div class="d-grid gap-2">
                        <a href="{{ route('profile.change-password') }}" class="btn btn-outline-warning">
                            <i class="fas fa-key me-2"></i>เปลี่ยนรหัสผ่าน
                        </a>
                    </div>

                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>หมายเหตุ:</strong> การเปลี่ยนแปลงข้อมูลจะส่งผลต่อการส่งคำขอในอนาคต
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
