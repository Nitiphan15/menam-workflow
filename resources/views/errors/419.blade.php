{{-- resources/views/errors/419.blade.php --}}
@extends('layouts.layout')

@section('title', 'Session expired')
@section('page-title', 'Session expired')

@section('content')
    @php
        $previousUrl = url()->previous();
        $homeUrl = route('home');
        $loginUrl = route('login');
    @endphp

    <style>
        .session-expired-wrap { min-height: 62vh; display:flex; align-items:center; justify-content:center; padding:32px 12px; }
        .session-expired-card { width:min(720px, 100%); background:#fff; border:1px solid #dfe5ec; border-radius:8px; box-shadow:0 12px 28px rgba(15,23,42,.08); }
        .session-expired-body { padding:28px; }
        .session-expired-icon { width:56px; height:56px; border-radius:50%; display:flex; align-items:center; justify-content:center; background:#fff7ed; color:#c2410c; font-size:24px; margin-bottom:14px; }
        .session-expired-title { font-size:1.35rem; font-weight:700; margin:0 0 6px; color:#1f2937; }
        .session-expired-text { color:#475569; margin-bottom:16px; }
        .session-expired-note { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; color:#475569; font-size:.92rem; }
        .session-expired-actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:18px; }
        @media (max-width: 575.98px) {
            .session-expired-body { padding:22px; }
            .session-expired-actions .btn { width:100%; }
        }
    </style>

    <div class="session-expired-wrap">
        <div class="session-expired-card">
            <div class="session-expired-body">
                <div class="session-expired-icon">
                    <i class="fas fa-clock"></i>
                </div>

                <h1 class="session-expired-title">หน้านี้หมดอายุแล้ว</h1>
                <p class="session-expired-text">
                    ระบบไม่ได้บันทึกข้อมูล เพราะหน้านี้เปิดทิ้งไว้นานเกินไป หรือ session/login มีการเปลี่ยนระหว่างใช้งาน
                    เพื่อป้องกันการบันทึกผิดบัญชี กรุณาโหลดหน้าใหม่แล้วกรอกหรือบันทึกอีกครั้ง
                </p>

                <div class="session-expired-note">
                    ถ้าเพิ่งกรอกข้อมูลไว้ ให้เปิดหน้าเดิมในแท็บใหม่ก่อน แล้วค่อยย้อนกลับมาคัดลอกข้อมูลที่ยังต้องใช้
                    หลังจากโหลดหน้าใหม่ token สำหรับบันทึกจะถูกสร้างใหม่
                </div>

                <div class="session-expired-actions">
                    <a href="{{ $previousUrl }}" class="btn btn-primary">
                        <i class="fas fa-rotate-right me-1"></i> โหลดหน้าเดิมใหม่
                    </a>
                    <a href="{{ $homeUrl }}" class="btn btn-outline-secondary">
                        <i class="fas fa-home me-1"></i> ไปหน้าแรก
                    </a>
                    @guest
                        <a href="{{ $loginUrl }}" class="btn btn-outline-primary">
                            <i class="fas fa-right-to-bracket me-1"></i> เข้าสู่ระบบใหม่
                        </a>
                    @endguest
                </div>

                <div class="small text-muted mt-3">Error code: 419 Page Expired</div>
            </div>
        </div>
    </div>
@endsection
