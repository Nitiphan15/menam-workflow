@extends('layouts.layout')

@section('title', 'Home')
@section('page-title', 'Home')

@section('content')
    <div class="container py-4">
        <div class="text-center mb-4">
            <h2 class="fw-bold">ยินดีต้อนรับสู่ระบบ <span class="text-primary">MENAM Online</span></h2>
            <p class="text-muted">กรุณาเลือกเมนูจากด้านซ้ายเพื่อเริ่มต้นใช้งาน</p>
        </div>

        <div class="mt-5">
            <h4 class="mb-3">📢 ข่าวประกาศ</h4>
            <ul class="list-group">
                <li class="list-group-item">🆕 v 1.0.0 เพิ่มเมนู “ฟอร์มวางแผนการผลิต”</li>
                <li class="list-group-item">🆕 v 1.0.1 เพิ่ม feature Autocomplete ในการค้นหาด้วย Partnumber </li>
            </ul>
        </div>


    </div>

@endsection
