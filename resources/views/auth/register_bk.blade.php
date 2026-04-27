@extends('layouts.layout')


@section('title', 'สมัครสมาชิก - ระบบคำขอ')

@section('content')
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card shadow-lg border-0">
                    <div class="card-body p-5">
                        <div class="text-center mb-4">
                            <h2 class="fw-bold text-primary">Menam Workflow Online</h2>
                            <p class="text-muted">สร้างบัญชีใหม่</p>
                        </div>

                        <form method="POST" action="{{ route('register') }}">
                            @csrf

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="name" class="form-label">ชื่อ-นามสกุล</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                                        <input type="text" class="form-control @error('name') is-invalid @enderror"
                                            id="name" name="name" value="{{ old('name') }}" required>
                                    </div>
                                    @error('name')
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="email" class="form-label">อีเมล</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                        <input type="email" class="form-control @error('email') is-invalid @enderror"
                                            id="email" name="email" value="{{ old('email') }}" required>
                                    </div>
                                    @error('email')
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="phone" class="form-label">เบอร์โทรศัพท์</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                        <input type="text" class="form-control @error('phone') is-invalid @enderror"
                                            id="phone" name="phone" value="{{ old('phone') }}">
                                    </div>
                                    @error('phone')
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="department" class="form-label">แผนก</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-building"></i></span>
                                        <input type="text" class="form-control @error('department') is-invalid @enderror"
                                            id="department" name="department" value="{{ old('department') }}">
                                    </div>
                                    @error('department')
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="position" class="form-label">ตำแหน่ง</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-briefcase"></i></span>
                                    <input type="text" class="form-control @error('position') is-invalid @enderror"
                                        id="position" name="position" value="{{ old('position') }}">
                                </div>
                                @error('position')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="password" class="form-label">รหัสผ่าน</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                        <input type="password" class="form-control @error('password') is-invalid @enderror"
                                            id="password" name="password" required>
                                    </div>
                                    @error('password')
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="password_confirmation" class="form-label">ยืนยันรหัสผ่าน</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                        <input type="password" class="form-control" id="password_confirmation"
                                            name="password_confirmation" required>
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-user-plus me-2"></i>สร้างบัญชี
                                </button>
                            </div>
                        </form>

                        <div class="text-center mt-4">
                            <p class="mb-0">มีบัญชีอยู่แล้ว?
                                <a href="{{ route('login') }}" class="text-decoration-none">เข้าสู่ระบบที่นี่</a>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
