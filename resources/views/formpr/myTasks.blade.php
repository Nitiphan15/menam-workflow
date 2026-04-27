@extends('layouts.layout')

@section('title', 'My Action')
@section('page-title', 'My Action')

@section('content')
    <div class="container">
        <h4>📋 รายการที่คุณต้องดำเนินการ</h4>

        @if ($items->isEmpty())
            <div class="alert alert-info mt-3">ไม่มีรายการรอดำเนินการ</div>
        @else
            <table class="table table-striped mt-3">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>เลขที่ฟอร์ม</th>
                        <th>ประเภท</th>
                        <th>สถานะ</th>
                        <th>ขั้นตอน</th>
                        <th>เปิดฟอร์ม</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $i => $row)
                        <tr>
                            <td>{{ $i + 1 }}</td>
                            <td>{{ $row->form_no }}</td>
                            <td>{{ strtoupper($row->app_code) }}</td>
                            <td>{{ $row->form_status }}</td>
                            <td>Step {{ $row->step_no }}</td>
                            <td>
                                <a href="{{ route('pr.review', $row->form_id) }}" class="btn btn-sm btn-primary">
                                    ตรวจสอบ
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
