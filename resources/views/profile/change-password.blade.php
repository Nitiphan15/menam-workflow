@extends('layouts.layout')

@section('title', 'เปลี่ยนรหัสผ่าน')
@section('page-title', 'เปลี่ยนรหัสผ่าน')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-key me-2"></i>เปลี่ยนรหัสผ่าน
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('profile.update-password') }}">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label for="current_password" class="form-label">รหัสผ่านปัจจุบัน <span
                                    class="text-danger">*</span></label>
                            <input type="password" class="form-control @error('current_password') is-invalid @enderror"
                                id="current_password" name="current_password" required>
                            @error('current_password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">รหัสผ่านใหม่ <span class="text-danger">*</span></label>
                            <input type="password" class="form-control @error('password') is-invalid @enderror"
                                id="password" name="password" required>
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror

                        </div>

                        <div class="mb-3">
                            <label for="password_confirmation" class="form-label">ยืนยันรหัสผ่านใหม่ <span
                                    class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="password_confirmation"
                                name="password_confirmation" required>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="{{ route('profile.edit') }}" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-2"></i>กลับ
                            </a>
                            <button type="submit" class="btn btn-warning">
                                <i class="fas fa-key me-2"></i>เปลี่ยนรหัสผ่าน
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            {{--   <div class="card mt-3">
                <div class="card-header">
                    <h6 class="card-title mb-0">
                        <i class="fas fa-shield-alt me-2"></i>คำแนะนำความปลอดภัย
                    </h6>
                </div>
                <div class="card-body">
                    <ul class="mb-0">
                        <li>ใช้รหัสผ่านที่ยาวและซับซ้อน</li>
                        <li>รวมตัวอักษรพิมพ์ใหญ่ พิมพ์เล็ก ตัวเลข และสัญลักษณ์</li>
                        <li>หลีกเลี่ยงการใช้ข้อมูลส่วนตัวในรหัสผ่าน</li>
                        <li>ไม่ใช้รหัสผ่านเดียวกันกับบัญชีอื่น</li>
                        <li>เปลี่ยนรหัสผ่านเป็นประจำ</li>
                    </ul>
                </div>
            </div> --}}
        </div>
    </div>
@endsection
