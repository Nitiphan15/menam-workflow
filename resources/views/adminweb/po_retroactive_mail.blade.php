@extends('layouts.layout')

@section('title', 'ส่งเมลอนุมัติ PO ย้อนหลัง')
@section('page-title', 'ส่งเมลอนุมัติ PO ย้อนหลัง')

@section('content')
<div class="container py-4" style="max-width: 760px">
    <div class="card shadow-sm">
        <div class="card-header fw-semibold">ส่งเมลแจ้งผู้อนุมัติ PO ที่รออยู่ Step 3</div>
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
                <div class="border rounded p-3 mt-4 bg-light">
                    <div><strong>PO:</strong> {{ $po->ordnumber }}</div>
                    <div><strong>Section:</strong> {{ $po->f1 }}</div>
                    <div><strong>สถานะ:</strong> {{ $po->status_code }} / Step 3</div>
                    <div><strong>ผู้รับ:</strong></div>
                    @foreach ($result['approvers'] as $approver)
                        <div>{{ $approver->name ?: $approver->username }} — {{ $approver->email }}</div>
                    @endforeach
                </div>

                @if ($result['unreachable_approvers']->isNotEmpty())
                    <div class="alert alert-warning mt-3 mb-0">
                        ผู้อนุมัติที่ส่งเมลไม่ได้ (ไม่มีอีเมลหรือบัญชีถูกปิด):
                        {{ $result['unreachable_approvers']->map(fn ($approver) => $approver->name ?: $approver->username)->implode(', ') }}
                    </div>
                @endif
                @if ($result['already_dispatched'])
                    <div class="alert alert-info mt-3 mb-0">รายการนี้เคยส่งเมลแล้ว สามารถส่งเมลย้ำได้อีก</div>
                @endif
                <form method="POST" action="{{ route('adminweb.po-retroactive-mail.send') }}" class="mt-3"
                      onsubmit="return confirm('ยืนยันส่งเมลย้ำ PO {{ $po->ordnumber }} ให้ผู้อนุมัติ {{ $result['approvers']->count() }} คน? การส่งนี้ไม่เปลี่ยนสถานะ PO หรือสร้าง approval ใหม่')">
                    @csrf
                    <input type="hidden" name="ordnumber" value="{{ $po->ordnumber }}">
                    <button class="btn btn-primary">ส่งเมลย้ำให้ผู้อนุมัติ</button>
                </form>
            @endif
        </div>
    </div>
</div>
@endsection
