@extends('layouts.layout')

@section('title', 'Sales Weekly Status')
@section('page-title', 'Sales Weekly Status')

@section('content')
    <div class="container-fluid py-3">

        <div class="card card-body">

            <h5 class="mb-3">Report Monitoring</h5>

            <div class="row mb-2">
                <div class="col-md-3 fw-bold">ช่วงวันที่:</div>
                <div class="col-md-9">{{ $from }} → {{ $to }}</div>
            </div>

            <div class="row mb-2">
                <div class="col-md-3 fw-bold">Hash ปัจจุบัน:</div>
                <div class="col-md-9 text-monospace">{{ $currentHash ?? '-' }}</div>
            </div>

            <div class="row mb-2">
                <div class="col-md-3 fw-bold">จำนวนครั้งที่เปลี่ยน:</div>
                <div class="col-md-9">
                    <span class="badge bg-warning text-dark">
                        {{ $changeCount }}
                    </span>
                </div>
            </div>

            <div class="row mb-2">
                <div class="col-md-3 fw-bold">เปลี่ยนล่าสุดเมื่อ:</div>
                <div class="col-md-9">
                    {{ $lastChange ?? '-' }}
                </div>
            </div>

        </div>

    </div>
@endsection
