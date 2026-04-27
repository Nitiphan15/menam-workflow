@extends('layouts.layout')

@section('title', 'Evaluate (HR)')
@section('page-title', 'PA Online (HR)')

@section('content')
    @php
        // น้ำหนักรวมของแต่ละภาค (ปรับได้)
        $partWeights = $partWeights ?? ['A' => 80, 'B' => 20];
        dump($form->employee->id, $form);
    @endphp

    <form method="post" action="{{ route('pa.hr.store', $form) }}" id="eval-form" class="container-fluid px-3">
        @csrf

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">ประเมิน (HR)</h4>
            <div class="text-end small">
                <div class="fw-semibold">{{ $form->employee->name }}</div>
                <div class="text-muted">{{ $form->employee->email }}</div>
                <div class="text-muted">แผนก: {{ $form->employee->department->name ?? '-' }}</div>
            </div>
        </div>

        {{-- PART A: แสดงแบบอ่านอย่างเดียว --}}
        {{-- @include('formpa.parts._partA', ['editable' => false]) --}}

        {{-- สรุป Part A --}}
        {{-- @include('formpa.parts._partA_summary') --}}

        {{-- PART B: ส่วนของ HR --}}
        @include('formpa.parts._partB_hr')

        {{-- สรุปรวมถ่วงน้ำหนัก A + B --}}
        @include('formpa.parts._final_summary', [
            'showPartB' => true,
            'partWeights' => $partWeights,
        ])

        <div class="text-end my-3">
            <button type="submit" name="save_as" value="draft" class="btn btn-outline-secondary">บันทึกแบบร่าง</button>
            <button type="submit" class="btn btn-primary">บันทึกคะแนน</button>
        </div>
    </form>
@endsection
