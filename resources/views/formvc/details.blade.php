@extends('layouts.layout')

@section('title', 'Variable Cost - Details')
@section('page-title', 'Variable Cost')

@section('content')
    @include('formvc.partials.styles')

    <div class="vc-wrap">
        @include('formvc.partials.header')

        @if (request()->filled('return_url'))
            <div class="mb-3">
                <a href="{{ request('return_url') }}" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i> {{ request('return_label', 'กลับหน้าก่อนหน้า') }}
                </a>
            </div>
        @endif

        <div class="vc-card">
            <div class="vc-card-header">รายละเอียดค่าใช้จ่าย (รายบรรทัด)</div>
            <div class="p-4">
                <div class="alert alert-info mb-3">
                    <i class="fas fa-info-circle me-1"></i>
                    หน้ารายละเอียดรายบรรทัดถูกย้ายไปอยู่ใน <strong>ไฟล์ Excel</strong> เท่านั้น
                    เพื่อให้หน้าสรุปอื่น ๆ โหลดเร็วขึ้น
                </div>

                <p class="mb-3">
                    กรองข้อมูลที่ต้องการในแท็บอื่น (สรุปแผนก / ตามบัญชี / รายเดือน / แผนก x ค่าใช้จ่าย)
                    แล้วกดปุ่ม <strong>Export Excel</strong> เพื่อดาวน์โหลดรายละเอียดทั้งหมด
                </p>

                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ route('variable-cost.export', request()->query() + ['page' => 'summary']) }}"
                       class="btn btn-success">
                        <i class="fas fa-file-excel me-1"></i> ดาวน์โหลด Excel (รายละเอียดเต็ม)
                    </a>
                    <a href="{{ route('variable-cost.summary', request()->query()) }}"
                       class="btn btn-outline-primary">
                        <i class="fas fa-list me-1"></i> ไปหน้าสรุปแผนก
                    </a>
                </div>

                <hr class="my-4">

                <div class="text-muted small">
                    <div class="mb-2"><strong>Filter ปัจจุบัน</strong></div>
                    <ul class="mb-0">
                        <li>วันที่: {{ $filters['date_from'] ?? '-' }} ถึง {{ $filters['date_to'] ?? '-' }}</li>
                        <li>Site: {{ $filters['site'] ?? 'ALL' }}</li>
                        <li>Department: {{ is_array($filters['department'] ?? null) ? (count($filters['department']) ? implode(', ', $filters['department']) : '— ทั้งหมด —') : ($filters['department'] ?: '— ทั้งหมด —') }}</li>
                        <li>Account: {{ is_array($filters['account'] ?? null) ? (count($filters['account']) ? implode(', ', $filters['account']) : '— ทั้งหมด —') : ($filters['account'] ?: '— ทั้งหมด —') }}</li>
                        <li>Invoice: {{ $filters['invoice'] ?: '-' }}</li>
                        <li>Notes: {{ $filters['notes'] ?: '-' }}</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
@endsection
