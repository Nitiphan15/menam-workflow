@extends('layouts.layout')

@section('title', 'เลือกประเภทการทดสอบ')
@section('page-title', 'เลือกประเภทการทดสอบ')

@push('styles')
    <style>
        .form-card .card-header {
            display: flex;
            flex-direction: column;
            gap: .25rem;
        }

        @media (min-width: 768px) {
            .form-card .card-header {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }
    </style>
@endpush

@section('content')
    <div class="container my-4">
        <h3 class="mb-2">เลือกประเภทการทดสอบ</h3>
        <p class="text-muted">
            กรุณาเลือกว่าจะทำแบบทดสอบล่วงหน้า (Pre-Test) หรือหลังการเรียน (Post-Test)
        </p>

        @foreach ($forms as $form)
            <div class="card app-card mb-4 form-card">
                <div class="card-header bg-light">
                    <div>
                        <h5 class="mb-0">{{ $form->name }}</h5>
                        @if ($form->description)
                            <div class="text-muted small">{{ $form->description }}</div>
                        @endif
                    </div>
                </div>

                <div class="card-body">
                    {{-- มือถือ = 1 คอลัมน์ / md ขึ้นไป = 2 คอลัมน์ --}}
                    <div class="row g-3">
                        @forelse($form->examTypes as $type)
                            <div class="col-12 col-md-6">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                        <span class="badge bg-primary px-3 py-2">
                                            {{ strtoupper($type->code) }}
                                        </span>
                                    </div>

                                    <h6 class="mb-1">{{ $type->name }}</h6>

                                    @if ($type->code === 'post' && !is_null($type->pass_score))
                                        <div class="text-muted small mb-2">
                                            เกณฑ์ผ่าน: {{ $type->pass_score }} คะแนนขึ้นไป
                                        </div>
                                    @else
                                        <div class="text-muted small mb-2">
                                            ไม่มีเกณฑ์ผ่าน แสดงเฉพาะคะแนน
                                        </div>
                                    @endif

                                    <a href="{{ route('exam.start', $type->id) }}"
                                        class="btn btn-outline-primary w-100 mt-2">
                                        เริ่มทำแบบทดสอบ
                                    </a>
                                </div>
                            </div>
                        @empty
                            <div class="col-12 text-muted small">
                                ฟอร์มนี้ยังไม่ได้ตั้งค่า Pre/Post
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endsection
