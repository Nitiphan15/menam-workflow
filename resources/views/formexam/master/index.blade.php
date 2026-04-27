@extends('layouts.layout')

@section('title', 'จัดการฟอร์มแบบทดสอบ')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="card app-card p-4">
                <div class="card-body">

                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h4 class="mb-1">ฟอร์มแบบทดสอบทั้งหมด</h4>
                            <p class="text-muted mb-0">
                                เลือกฟอร์มเพื่อแก้ไขหัวข้อ ข้อสอบ และเกณฑ์ผ่าน
                            </p>
                        </div>
                        <a href="{{ route('exam.master.create') }}" class="btn btn-primary btn-sm">
                            + เพิ่มฟอร์มใหม่
                        </a>
                    </div>

                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>ชื่อฟอร์ม</th>
                                <th class="text-center">จำนวนข้อสอบ</th>
                                <th class="text-center" style="width:120px;">สถานะ</th>
                                <th class="text-end">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($forms as $form)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $form->name }}</div>
                                        @if ($form->description)
                                            <div class="text-muted small">{{ $form->description }}</div>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        {{ $form->questions_count }}
                                    </td>
                                    <td class="text-center">
                                        @if ($form->is_active)
                                            <span class="badge bg-success">Active</span>
                                        @else
                                            <span class="badge bg-secondary">Inactive</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1">

                                            <a href="{{ route('exam.master.edit', $form->id) }}"
                                                class="btn btn-sm btn-outline-primary">
                                                จัดการ
                                            </a>

                                            @if ($form->is_active)
                                                <form action="{{ route('exam.master.deactivate', $form->id) }}"
                                                    method="POST">
                                                    @csrf
                                                    <button class="btn btn-sm btn-outline-danger"
                                                        onclick="return confirm('ปิดการใช้งานฟอร์มนี้?')">
                                                        ปิด
                                                    </button>
                                                </form>
                                            @else
                                                <form action="{{ route('exam.master.activate', $form->id) }}"
                                                    method="POST">
                                                    @csrf
                                                    <button class="btn btn-sm btn-outline-success"
                                                        onclick="return confirm('เปิดการใช้งานฟอร์มนี้?')">
                                                        เปิด
                                                    </button>
                                                </form>
                                            @endif

                                        </div>
                                    </td>

                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center text-muted py-4">
                                        ยังไม่มีฟอร์มข้อสอบ
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>

                </div>
            </div>
        </div>
    </div>
@endsection
