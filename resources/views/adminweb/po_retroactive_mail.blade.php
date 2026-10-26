@extends('layouts.layout')

@section('title', 'ส่งเมลอนุมัติ PO ย้อนหลัง')
@section('page-title', 'ส่งเมลอนุมัติ PO ย้อนหลัง')

@section('content')
<div class="container py-4" style="max-width: 760px">
    <div class="card shadow-sm">
        <div class="card-header fw-semibold">ส่งเมลแจ้ง Jittinan สำหรับ Factory PO</div>
        <div class="card-body">
            @if (session('ok'))
                <div class="alert alert-success">{{ session('ok') }}</div>
            @endif
            @if ($errors->any())
                <div class="alert alert-danger">{{ $errors->first() }}</div>
            @endif
            @if ($error)
                <div class="alert alert-danger">{{ $error }}</div>
            @endif

            <form method="GET" action="{{ route('adminweb.po-retroactive-mail.index') }}" class="row g-2">
                <div class="col-sm-9">
                    <label class="form-label" for="ordnumber">เลขที่ PO</label>
                    <input id="ordnumber" name="ordnumber" class="form-control" value="{{ $ordnumber }}"
                           placeholder="เช่น PO2026090147" required>
                </div>
                <div class="col-sm-3 d-grid align-items-end">
                    <button class="btn btn-outline-primary mt-sm-4">ตรวจสอบ</button>
                </div>
            </form>

            @if ($result)
                @php($po = $result['po'])
                @php($approver = $result['approver'])
                <div class="border rounded p-3 mt-4 bg-light">
                    <div><strong>PO:</strong> {{ $po->ordnumber }}</div>
                    <div><strong>Section:</strong> {{ $po->f1 }}</div>
                    <div><strong>สถานะ:</strong> {{ $po->status_code }} / Step 3</div>
                    <div><strong>ผู้รับ:</strong> {{ $approver->name }} — {{ $approver->email }}</div>
                </div>

                @if ($result['already_dispatched'])
                    <div class="alert alert-warning mt-3 mb-0">เคยส่งเมลย้อนหลังแล้ว ระบบไม่อนุญาตให้ส่งซ้ำ</div>
                @else
                    <form method="POST" action="{{ route('adminweb.po-retroactive-mail.send') }}" class="mt-3"
                          onsubmit="return confirm('ยืนยันส่งเมลแจ้งอนุมัติ PO {{ $po->ordnumber }} ให้ Jittinan?')">
                        @csrf
                        <input type="hidden" name="ordnumber" value="{{ $po->ordnumber }}">
                        <button class="btn btn-primary">ส่งเมลให้ Jittinan</button>
                    </form>
                @endif
            @endif
        </div>
    </div>
</div>
@endsection
