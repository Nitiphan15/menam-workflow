@extends('layouts.layout')

@section('title', 'Request Form')
@section('page-title', 'Request Form')

@section('content')
    <div class="row">
        <div class="col-lg-12">
            @if (session('ok'))
                <div class="alert alert-success">{{ session('ok') }}</div>
            @endif
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-plus me-2"></i>ส่งคำขอใหม่
                    </h5>
                </div>
                <div class="card-body">

                    <form method="POST" id="requestForm" action="{{ route('pr.store') }}" enctype="multipart/form-data">
                        @csrf

                        <!-- User Information Section -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h6 class="mb-0">
                                    <i class="fas fa-user me-2"></i>ข้อมูลผู้ขอ
                                    @if ($user)
                                        <span class="badge bg-success ms-2">ข้อมูลจากบัญชี</span>
                                    @endif
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="name" class="form-label">ชื่อ-นามสกุล <span
                                                class="text-danger">*</span></label>
                                        <input type="text" class="form-control @error('name') is-invalid @enderror"
                                            id="name" name="name"
                                            value="{{ old('name', $user ? $user->name : '') }}" required
                                            placeholder="กรอกชื่อ-นามสกุล" {{ $user ? 'readonly' : '' }}>
                                        @error('name')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="email" class="form-label">อีเมล <span
                                                class="text-danger">*</span></label>
                                        <input type="email" class="form-control @error('email') is-invalid @enderror"
                                            id="email" name="email"
                                            value="{{ old('email', $user ? $user->email : '') }}" required
                                            placeholder="กรอกอีเมล" {{ $user ? 'readonly' : '' }}>
                                        @error('email')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="phone" class="form-label">เบอร์โทรศัพท์ภายใน <span
                                                class="text-danger">*</span></label>
                                        <input type="text" class="form-control @error('phone') is-invalid @enderror"
                                            id="phone" name="phone"
                                            value="{{ old('phone', $user ? $user->phone : '') }}" required
                                            placeholder="เบอร์โทรศัพท์ภายใน" {{ $user ? 'readonly' : '' }}>
                                        @error('phone')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="department" class="form-label">แผนกที่ขอซื้อ <span
                                                class="text-danger">*</span></label>
                                        <input type="hidden" id="department_id" name="department_id"
                                            value="{{ old('department_id', $items ? $items[0]->id : '') }}"></input>
                                        <input type="text" class="form-control @error('department') is-invalid @enderror"
                                            id="department" name="department" rows="6"
                                            value="{{ old('department', $items ? $items[0]->name : '') }}" required
                                            placeholder="แผนก..."{{ $items ? 'readonly' : '' }}></input>
                                        @error('department')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>

                                </div>

                                @if ($user)
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        ข้อมูลถูกดึงมาจากบัญชีของคุณ หากต้องการแก้ไขข้อมูลส่วนตัว
                                        <a href="#" class="alert-link">คลิกที่นี่</a>
                                    </div>
                                @endif

                            </div>
                        </div>

                        <!-- Request Information Section -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h6 class="mb-0">
                                    <i class="fas fa-file-alt me-2"></i>รายละเอียดคำขอ
                                </h6>
                            </div>

                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="company" class="form-label">บริษัท <span
                                            class="text-danger">*</span></label>

                                    <div class="form-check form-check">
                                        <input class="form-check-input @error('company') is-invalid @enderror"
                                            type="radio" name="company" id="company1" value="1">
                                        <label class="form-check-label" for="inlineRadio1">บริษัท แม่น้ำสแตนเลสไวร์
                                            จำกัด(มหาชน)</label>
                                    </div>
                                    <div class="form-check form-check">
                                        <input class="form-check-input @error('company') is-invalid @enderror"
                                            type="radio" name="company" id="company2" value="2">
                                        <label class="form-check-label" for="inlineRadio2">บริษัท แม่น้ำพลัส จำกัด</label>
                                    </div>
                                    <div class="form-check form-check @error('company') is-invalid @enderror">
                                        <input class="form-check-input" type="radio" name="company" id="company3"
                                            value="2">
                                        <label class="form-check-label" for="inlineRadio2">บริษัท แม่น้ำดีซี จำกัด</label>
                                    </div>
                                    @error('company')
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="request_date" class="form-label">วันที่ขอซื้อ <span
                                                class="text-danger">*</span></label>
                                        <input type="date" readonly {{-- หรือ datetime-local --}} id="request_date"
                                            name="request_date" class="form-control"
                                            value="{{ old('request_date', \Carbon\Carbon::now()->format('Y-m-d')) }}">
                                        @error('request_date')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="use_date" class="form-label">วันที่ต้องการใช้งาน </label>
                                        <input type="date" {{-- หรือ datetime-local --}} id="use_date" name="use_date"
                                            class="form-control" value="">
                                        @error('use_date')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>


                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="company" class="form-label">บริษัทที่ต้องการติดต่อ </label>
                                        <input tpye="text" class="form-control @error('company') is-invalid @enderror"
                                            id="company" name="company" rows="6">{{ old('company') }}</input>
                                        @error('company')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="type" class="form-label">ประเภทของสินค้า <span
                                                class="text-danger">*</span></label>
                                    </div>
                                    <div class="col-md-6 mb-3 d-none" id="lbl_other" name="lbl_other">
                                        <label for="type_other" class="form-label">อื่น ๆ </label>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="checkbox" id="cb1"
                                                name="type[]" value="1">
                                            <label class="form-check-label" for="cb1">งาน Project
                                                สร้างเครื่องจักร</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="checkbox" id="cb2"
                                                name="type[]" value="2">
                                            <label class="form-check-label" for="cb2">อะไหล่</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="checkbox" id="cb3"
                                                name="type[]" value="3">
                                            <label class="form-check-label" for="cb3">ภาชนะ/บรรจุภัณฑ์</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="checkbox" id="cb4"
                                                name="type[]" value="4">
                                            <label class="form-check-label"
                                                for="cb4">งานซ่อมแซมเครื่องมือ/เครื่องจักร</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="checkbox" id="cb5"
                                                name="type[]" value="5">
                                            <label class="form-check-label" for="cb5">เบ็ดเตล็ด/ทั่วไป</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="checkbox" id="cb6"
                                                name="type[]" value="6">
                                            <label class="form-check-label" for="cb6">อื่น ๆ</label>
                                        </div>
                                    </div>

                                    <div class="col-md-6 mb-3 d-none" name="div_other" id="div_other">

                                        <input tpye="text"
                                            class="form-control @error('type_other') is-invalid @enderror" id="type_other"
                                            name="type_other" rows="6">{{ old('type_other') }}</input>
                                        @error('type_other')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>

                                <div class="card mb-5 shadow-sm">
                                    <div class="card-body">

                                        <div class="row">
                                            <div class="d-flex justify-content-end mb-2">
                                                <button type="button" id="addDetailBtn"
                                                    class="btn btn-sm btn-outline-secondary">
                                                    <i class="fa fa-plus"></i> เพิ่มรายละเอียด
                                                </button>
                                            </div>

                                            <div class="col table-responsive" style="max-height:1000px;">
                                                <table id="detailTable"
                                                    class="table table-borderless table-striped table-hover align-middle">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th style="width:5%">No.</th>
                                                            <th class="text-center">รายละเอียดสินค้า</th>
                                                            <th style="width:12%" class="text-center">จำนวน</th>
                                                            <th style="width:10%" class="text-center">หน่วย</th>
                                                            <th style="width:12%" class="text-center">ราคา</th>
                                                            <th class="text-center">จุดประสงค์การใช้งาน
                                                            </th>
                                                            <th class="text-center">
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="items-body">
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>

                                </div>

                                <div class="mb-3">

                                    <label for="files" class="form-label">Attached File</label>

                                    <input type="file" class="form-control @error('files.*') is-invalid @enderror"
                                        id="files" name="files[]" multiple
                                        accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png,.gif,.xlsx">
                                    @error('files.*')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror

                                    <div class="form-text">
                                        คุณสามารถแนบไฟล์ได้หลายไฟล์ รูปแบบที่รองรับ: PDF, DOC, DOCX, TXT, JPG, PNG,
                                        GIF.,
                                        XLSX
                                        ขนาดไฟล์สูงสุด: 10MB ต่อไฟล์
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="reason" class="form-label">Remark <span
                                            class="text-danger"></span></label>
                                    <textarea class="form-control @error('description') is-invalid @enderror" id="reason" name="reason"
                                        rows="6" placeholder="กรุณาให้รายละเอียดคำขอของคุณ...">{{ old('reason') }}</textarea>
                                    @error('reason')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>


                            </div>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="/" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-2"></i>ยกเลิก
                            </a>
                            <button id="btnSubmit" type="submit" class="btn btn-primary">
                                <span class="spinner-border spinner-border-sm me-1 d-none" id="btnSpinner"></span>
                                <i class="fas fa-paper-plane me-2"></i> ส่งคำขอ
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {

            const btDetail = document.getElementById('addDetailBtn');
            const cbOther = document.getElementById('cb6');
            const divOther = document.getElementById('div_other');
            const lblOther = document.getElementById('lbl_other')

            document
                .getElementById('addDetailBtn')
                .addEventListener('click', addDetail);
            document
                .getElementById('cb6')
                .addEventListener('click', showText);

            function showText() {

                if (cbOther.checked) {
                    divOther.classList.remove('d-none')
                    lblOther.classList.remove('d-none')
                } else {
                    divOther.classList.add('d-none')
                    lblOther.classList.add('d-none')
                }
            }

            const oldList = @json(old('list', []));
            if (Array.isArray(oldList) && oldList.length) {
                const tbody = document.getElementById('items-body');
                oldList.forEach((it, i) => {
                    const tr = document.createElement('tr');
                    tr.classList.add('detail-row');
                    tr.innerHTML = `
                    <td class="seq_no">${i+1}</td>
                    <td><input type="text" name="list[${i}][detail]" value="${it.detail ?? ''}" class="form-control form-control-sm list-detail" required></td>
                    <td><input type="number" name="list[${i}][qty]" value="${it.qty ?? ''}" class="form-control form-control-sm list-qty" min="0" step="0.01" required></td>
                    <td><input type="text" name="list[${i}][unit]" value="${it.unit ?? ''}" class="form-control form-control-sm list-unit text-center"></td>
                    <td><input type="number" name="list[${i}][price]" value="${it.price ?? 0}" class="form-control form-control-sm list-price text-center" min="0" step="0.01"></td>
                    <td><input type="text" name="list[${i}][objective]" value="${it.objective ?? ''}" class="form-control form-control-sm list-objective text-center"></td>
                    <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger detail-remove"><i class="fa-solid fa-minus"></i></button>
                    </td>`;
                    tbody.appendChild(tr);
                    tr.querySelector('.detail-remove').addEventListener('click', () => {
                        tr.remove();
                        reIndexDetails();
                    });
                });
            } else {
                // ไม่มีค่าเก่า – ใส่แถวแรกให้เลย
                document.getElementById('addDetailBtn').click();
            }

            function addDetail() {
                const tbody = document.getElementById('items-body');
                const idx = tbody.querySelectorAll('.detail-row').length;

                const tr = document.createElement('tr');
                tr.classList.add('detail-row');
                tr.innerHTML = `
                        <td class="seq_no">
                            ${idx+1}
                        </td>

                        <td>
                            <input type="text"
                                name="list[${idx}][detail]"
                                class="form-control form-control-sm list-detail">
                        </td>
                        <td>
                            <input type="number"
                                name="list[${idx}][qty]"
                                class="form-control form-control-sm list-qty" min="0">
                        </td>

                        <td class="text-center align-middle">
                            <input type="text"
                                name="list[${idx}][unit]"
                                class="form-control form-control-sm list-unit text-center">
                        </td>

                        <td class="text-center align-middle">
                            <input type="number"
                                name="list[${idx}][price]"
                                class="form-control form-control-sm list-price text-center" min="0" >
                        </td>

                        <td class="text-center align-middle">
                            <input type="text"
                                name="list[${idx}][objective]"
                                class="form-control form-control-sm list-objective text-center">
                        </td>

                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-outline-danger detail-remove">
                                <i class="fa-solid fa-minus"></i>
                            </button>
                        </td>
                        `;
                tbody.appendChild(tr);

                tr.querySelector('.detail-remove')
                    .addEventListener('click', () => {
                        tr.remove();
                        reIndexDetails(); // ถ้าต้องการรี-index name ของแถวที่เหลือ
                    });
            }


            function reIndexDetails() {
                document.querySelectorAll('#items-body .detail-row')
                    .forEach((tr, i) => {
                        console.log(tr)
                        tr.querySelector('.list-detail').name = `list[${i}][detail]`;
                        tr.querySelector('.list-qty').name = `list[${i}][qty]`;
                        tr.querySelector('.list-unit').name = `list[${i}][unit]`;
                        tr.querySelector('.list-price').name = `list[${i}][price]`;
                        tr.querySelector('.list-objective').name = `list[${i}][objective]`;
                        const seqTd = tr.querySelector('td.seq_no');
                        if (seqTd) seqTd.textContent = i + 1;
                    });

            }
        });
    </script>
@endpush
