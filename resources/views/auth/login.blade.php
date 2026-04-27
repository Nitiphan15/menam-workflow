@extends('layouts.layout')


@section('title', 'เข้าสู่ระบบ - ระบบคำขอ')

@section('content')
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-4">
                <div class="card shadow-lg border-0">
                    <div class="card-body p-5">
                        <div class="text-center mb-4">
                            <h2 class="fw-bold text-primary">Login</h2>

                            <p class="text-muted">เข้าสู่ระบบ</p>
                        </div>

                        <form method="POST" action="{{ route('login') }}">
                            @csrf

                            <div class="mb-3">
                                <label for="email" class="form-label">อีเมล</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                    <input type="email" class="form-control @error('email') is-invalid @enderror"
                                        id="email" name="email" value="{{ old('email') }}" required autofocus>
                                </div>
                                @error('email')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-3">
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

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-sign-in-alt me-2"></i>เข้าสู่ระบบ
                                </button>
                            </div>
                        </form>

                        <div class="text-center mt-4">
                            <p class="mb-0">หากยังไม่มีบัญชี
                                <br>สามารถติดต่อขอสิทธิ์การใช้งานที่
                                <br>nitiphan@menamstainless.co.th
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
