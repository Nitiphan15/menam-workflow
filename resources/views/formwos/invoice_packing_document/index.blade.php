@extends('layouts.layout')

@section('title', 'Invoice Packing List')
@section('page-title', 'Invoice Packing List')

@section('content')
    <div class="container py-4" style="max-width: 920px;">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3">
                <h4 class="mb-1">สร้างรายการ Invoice / Packing List</h4>
                <div class="text-muted small">
                    ดึง PO, วันที่ Invoice, DOB, จำนวนลัง, Net/Gross Weight, ราคา และรายละเอียดจาก ERP
                </div>
            </div>

            <div class="card-body p-4">
                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('wos.invoice_packing_document.preview') }}" target="_blank">
                    @csrf

                    <div class="mb-3">
                        <label for="invoice_numbers" class="form-label fw-semibold">เลข Invoice</label>
                        <textarea
                            id="invoice_numbers"
                            name="invoice_numbers"
                            rows="6"
                            class="form-control @error('invoice_numbers') is-invalid @enderror"
                            placeholder="D2026080106&#10;D2026080107"
                            required>{{ old('invoice_numbers', 'D2026080106') }}</textarea>
                        <div class="form-text">
                            กรอกได้หลายเลข โดยแยกด้วย Enter, comma หรือ semicolon — สูงสุด 10 Invoice และผลลัพธ์รวมไม่เกิน 10 รายการ
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="signer_name" class="form-label fw-semibold">ชื่อ–นามสกุลใต้ช่องลงชื่อ</label>
                        <input
                            id="signer_name"
                            name="signer_name"
                            type="text"
                            maxlength="150"
                            class="form-control @error('signer_name') is-invalid @enderror"
                            value="{{ old('signer_name') }}"
                            placeholder="ชื่อ นามสกุล"
                            required>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="fa-solid fa-file-lines me-1"></i>
                            แสดงตัวอย่างเอกสาร
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
