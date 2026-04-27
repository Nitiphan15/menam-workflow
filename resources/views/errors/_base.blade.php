{{-- resources/views/errors/_base.blade.php --}}
@extends('layouts.layout')

@section('title', $title ?? 'เกิดข้อผิดพลาด')
@section('page-title', $title ?? 'เกิดข้อผิดพลาด')

@section('content')
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-12 col-md-10 col-lg-8">
                <div class="card shadow-sm border-0">
                    <div class="card-body p-4 p-md-5 text-center">
                        <div class="display-6 mb-2">{{ $emoji ?? '⚠️' }}</div>
                        <h1 class="h4 mb-1">{{ $title ?? 'เกิดข้อผิดพลาด' }}</h1>
                        @isset($subtitle)
                            <p class="text-muted mb-3">{{ $subtitle }}</p>
                        @endisset

                        @isset($message)
                            <p class="mb-3">{{ $message }}</p>
                        @endisset

                        {{-- แสดงเหตุผลเพิ่มเติม/รายละเอียด --}}
                        @isset($reason)
                            <p class="small text-danger mb-3">เหตุผล: {{ $reason }}</p>
                        @endisset

                        {{-- เผื่อกรณี 422: แสดง Validation errors --}}
                        @if (($code ?? 0) == 422 && ($errors ?? null) && $errors->any())
                            <div class="alert alert-warning text-start">
                                <div class="fw-semibold mb-1">โปรดตรวจสอบข้อมูลต่อไปนี้:</div>
                                <ul class="mb-0">
                                    @foreach ($errors->all() as $err)
                                        <li>{{ $err }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div class="d-flex flex-column flex-sm-row gap-2 justify-content-center mt-3">
                            <a href="{{ url()->previous() }}" class="btn btn-primary">← กลับหน้าก่อนหน้า</a>
                            <a href="{{ route('home') }}" class="btn btn-outline-secondary">ไปหน้าแรก</a>

                        </div>

                        <hr class="my-4">
                        <p class="small text-muted mb-0">
                            โค้ดข้อผิดพลาด: {{ $code ?? '-' }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
