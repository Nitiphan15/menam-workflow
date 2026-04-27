@extends('layouts.layout')

@section('content')
    <div class="container">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h4 class="mb-0">Create Inquiry</h4>
            <a href="{{ route('lis.index') }}" class="btn btn-outline-secondary">Inquiry List</a>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger">
                <div class="fw-semibold mb-1">กรอกข้อมูลไม่ครบ/ไม่ถูกต้อง</div>
                <ul class="mb-0">
                    @foreach ($errors->all() as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('lis.store') }}" enctype="multipart/form-data" class="card">
            @csrf
            <div class="card-body row g-3">

                <div class="col-md-6">
                    <label class="form-label">ลูกค้า (Customer)</label>
                    <input type="text" name="customer" value="{{ old('customer') }}" class="form-control" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Partnumber</label>
                    <input type="text" name="partnumber" value="{{ old('partnumber') }}" class="form-control" required>
                </div>

                <div class="col-md-4">
                    <label class="form-label">จำนวน (Qty)</label>
                    <input type="number" name="qty" min="1" value="{{ old('qty', 1) }}" class="form-control"
                        required>
                </div>

                <div class="col-md-4">
                    <label class="form-label">วันที่ต้องส่ง (Delivery Date)</label>
                    <input type="date" name="delivery_date" value="{{ old('delivery_date') }}" class="form-control"
                        required>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Priority</label>
                    <select name="priority" class="form-select" required>
                        <option value="1" @selected(old('priority') == 1)>High</option>
                        <option value="2" @selected(old('priority') == 2)>Medium</option>
                        <option value="3" @selected(old('priority', 3) == 3)>Low</option>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">Attached files</label>
                    <input type="file" name="attachments[]" class="form-control" multiple>
                    <div class="form-text">แนบได้หลายไฟล์ (เช่น pdf, xlsx, png ฯลฯ)</div>
                </div>

                <div class="col-12">
                    <label class="form-label">หมายเหตุ (optional)</label>
                    <textarea name="remark" rows="3" class="form-control">{{ old('remark') }}</textarea>
                </div>

            </div>
            <div class="card-footer d-flex gap-2">
                <button class="btn btn-primary">Save</button>
                <a href="{{ route('lis.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
@endsection
