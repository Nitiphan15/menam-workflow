@extends('layouts.layout')

@section('title', 'แก้ไขข้อมูลพนักงาน')

@section('content')
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">แก้ไขข้อมูลพนักงาน</h5>
            <a href="{{ route('adminweb.users.register') }}" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-arrow-left"></i> กลับ
            </a>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-header bg-primary text-white fw-bold">
                #{{ $user->id }} — {{ $user->name }}
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('adminweb.users.update', $user->id) }}" class="row g-3">
                    @csrf
                    @method('PUT')

                    <div class="col-md-3">
                        <label class="form-label">รหัสพนักงาน</label>
                        <input name="user_code" class="form-control" value="{{ old('user_code', $user->user_code) }}">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Username</label>
                        <input class="form-control" value="{{ $user->username }}" readonly>
                    </div>

                    <div class="col-md-5">
                        <label class="form-label">ชื่อ-นามสกุล</label>
                        <input name="name" class="form-control" value="{{ old('name', $user->name) }}" required>
                        <div class="form-text">หลังบันทึก ระบบจะสร้าง username ใหม่จากชื่อ-นามสกุลนี้</div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">สถานะ</label>
                        <select name="is_active" class="form-select">
                            <option value="1" {{ old('is_active', (int) $user->is_active) == 1 ? 'selected' : '' }}>Active</option>
                            <option value="0" {{ old('is_active', (int) $user->is_active) == 0 ? 'selected' : '' }}>Inactive</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">อีเมล</label>
                        <input name="email" type="email" class="form-control" value="{{ old('email', $user->email) }}" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">โทรศัพท์</label>
                        <input name="phone" class="form-control" value="{{ old('phone', $user->phone) }}">
                    </div>

                    <div class="col-12"><hr class="my-1"></div>

                    <div class="col-md-6">
                        <label class="form-label">รหัสผ่านใหม่ <span class="text-muted small">(เว้นว่างถ้าไม่เปลี่ยน)</span></label>
                        <input name="password" type="password" class="form-control" autocomplete="new-password">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">ยืนยันรหัสผ่านใหม่</label>
                        <input name="password_confirmation" type="password" class="form-control" autocomplete="new-password">
                    </div>

                    <div class="col-12 d-flex justify-content-between align-items-center">
                        <div class="form-text mb-0">
                            การเปลี่ยน <strong>แผนก/ตำแหน่ง</strong> ให้ทำที่หน้า
                            <a href="{{ route('adminweb.user-department-assignments.index') }}">ย้ายแผนก/ตำแหน่งพนักงาน</a>
                            เพื่อรักษาประวัติการผูกให้ถูกต้อง
                        </div>
                        <button class="btn btn-primary">บันทึกการแก้ไข</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
