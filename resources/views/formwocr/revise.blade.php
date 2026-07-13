@extends('layouts.layout')

@section('title', 'ฟอร์มขอเปิด / แก้ไข / ยกเลิกเอกสาร')
@section('page-title', 'ฟอร์มขอเปิด / แก้ไข / ยกเลิกเอกสาร')

@section('content')
    @php
        // Minimal form version (only: header+table+effective_date+reason+checkbox panel+buttons)
        //$action = $action ?? route('wocr.revise_action', [], false);
        $sku = $sku ?? '';
        $v = fn($k, $fallback = '') => old($k, data_get($wocrData, $k, $fallback));

        //$mfg = $mfg ?? '';
        // Blade form with input fields and dynamic rows
        $wocrData = $wocrData ?? ($WocrData ?? null);
        $wfId = $form->id ?? ($wfId ?? data_get($wocrData, 'form_id'));
        //dd($wocrData);
        $form = $form ?? (object) [];
        $items = collect(
            old(
                'items',
                $items ?? [
                    [
                        'mfg_no' => '',
                        'grade' => '',
                        'type' => 'BAR',
                        'size' => '',
                        'length' => '',
                        'qty' => '',
                        'remark' => '',
                    ],
                ],
            ),
        );
        $reqType = (string) $v('req_type', '');
        $urgency = (string) $v('urgency', '2');
    @endphp


    <div class="sheet">
        <form method="POST" action="{{ route('wocr.revise_action', ['id' => $wfId]) }} " enctype="multipart/form-data">
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
                            <label for="name" class="form-label">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror" id="name"
                                name="name" value="{{ old('name', $user ? $user->name : '') }}" required
                                placeholder="กรอกชื่อ-นามสกุล" {{ $user ? 'readonly' : '' }}>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">อีเมล <span class="text-danger">*</span></label>
                            <input type="email" class="form-control @error('email') is-invalid @enderror" id="email"
                                name="email" value="{{ old('email', $user ? $user->email : '') }}" required
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
                            <input type="text" class="form-control @error('phone') is-invalid @enderror" id="phone"
                                name="phone" value="{{ old('phone', $user ? $user->phone : '') }}" required
                                placeholder="เบอร์โทรศัพท์ภายใน" {{ $user ? 'readonly' : '' }}>
                            @error('phone')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="department" class="form-label">แผนก <span class="text-danger">*</span></label>
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
                        {{-- <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            ข้อมูลถูกดึงมาจากบัญชีของคุณ หากต้องการแก้ไขข้อมูลส่วนตัว
                            <a href="#" class="alert-link">คลิกที่นี่</a>
                        </div> --}}
                    @endif
                </div>

            </div>
            <div class="border rounded-3 p-3 mb-3">
                <div class="mb-3">
                    <label for="req_type" class="fw-semibold mb-2">ส่วนของผู้เปิดใบคำสั่งผลิต
                        <span class="text-danger">*</span></label>

                    <div class="form-check">
                        <input class="form-check-input @error('req_type') is-invalid @enderror" type="radio"
                            name="req_type" id="req_type1" value="1" @checked($reqType === '1')>
                        <label class="form-check-label" for="req_type1">ขอให้เปิดใบคำสั่งผลิตใหม่</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input @error('req_type') is-invalid @enderror" type="radio"
                            name="req_type" id="req_type2" value="2" @checked($reqType === '2')>
                        <label class="form-check-label" for="req_type2">ขอให้แก้ไขใบคำสั่งผลิต</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input @error('req_type') is-invalid @enderror" type="radio"
                            name="req_type" id="req_type3" value="3" @checked($reqType === '3')>
                        <label class="form-check-label" for="req_type3">ยกเลิกใบคำสั่งผลิต</label>
                    </div>
                    @error('req_type')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="urgency" class="form-label fw-semibold">ระดับความเร่งด่วน
                        <span class="text-danger">*</span></label>
                    <select name="urgency" id="urgency"
                        class="form-select @error('urgency') is-invalid @enderror" style="max-width:240px;">
                        <option value="1" @selected($urgency === '1')>น้อย</option>
                        <option value="2" @selected($urgency === '2')>ปานกลาง</option>
                        <option value="3" @selected($urgency === '3')>มาก</option>
                    </select>
                    @error('urgency')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <div class="row">
                    <div class="col-md-4 position-relative">
                        <label for="mfg_no" class="form-label fw-semibold">MFG No.</label>
                        <input type="text" id="mfg_no" name="mfg_no" class="form-control form-control-lg"
                            placeholder="W25xxxxxx" autocomplete="off" autofocus value="{{ $v('mfg_no') }}"
                            data-url="{{ route('api.wocr.mfgs.search') }}">

                        <!-- ถ้าต้องการเก็บ id แยก ใช้ hid ตามเดิม -->
                        <input type="hidden" id="mfg_no_id" name="mfg_no_id" value="{{ old('mfg_no_id') }}">
                        <div id="mfg_no-list" class="list-group position-absolute w-100 shadow-sm"
                            style="z-index:1050;max-height:260px;overflow:auto;display:none;"></div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label for="grade" class="form-label">Grade</label>
                        <input type="text" id="grade" name="grade"
                            class="form-control @error('grade') is-invalid @enderror" value="{{ $v('grade') }}"
                            readonly>
                        @error('grade')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-3 mb-3">
                        <label for="form_type" class="form-label">Type</label>
                        <input type="text" id="form_type" name="form_type"
                            class="form-control @error('form_type') is-invalid @enderror" value="{{ $v('form_type') }}"
                            readonly>
                        @error('form_type')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-3 mb-3">
                        <label for="size" class="form-label">Size</label>
                        <input type="text" id="size" name="size"
                            class="form-control @error('size') is-invalid @enderror" value="{{ $v('size') }}"
                            readonly>
                        @error('size')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-3 mb-3">
                        <label for="length" class="form-label">Length</label>
                        <input type="text" id="length" name="length"
                            class="form-control @error('length') is-invalid @enderror" value="{{ $v('length') }}"
                            readonly>
                        @error('length')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-3 mb-3">
                        <label for="qty" class="form-label">QTY <span class="text-danger">*</span></label>
                        <input type="number" id="qty" name="qty"
                            class="form-control @error('qty') is-invalid @enderror" value="{{ $v('qty') }}"
                            step="0.01" min="0">
                        @error('qty')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <div class="row">
                    <div class="mb-1 fw-semibold">สาเหตุในการเปิด / ยกเลิก / แก้ไข MFG <span class="text-danger">*</span>
                    </div>
                    <div class="mb-3">
                        <textarea name="mfg_request_detail" rows="3" class="form-control" placeholder="อธิบายเหตุผล">{{ $v('mfg_request_detail') }}</textarea>
                    </div>
                </div>

                <div class="mb-3">

                    <label for="files" class="form-label">Attached File</label>

                    <input type="file" class="form-control @error('files.*') is-invalid @enderror" id="files"
                        name="files[]" multiple accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png,.gif,.xlsx">
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

                <div class="mb-1 fw-semibold">Comment</div>
                <div class="mb-3">
                    <textarea name="reason" rows="3" class="form-control">{{ $v('reason') }}</textarea>
                </div>


                @if ($canApprove)
                    <button type="submit" name="action" value="approve" class="btn btn-success">
                        <i class="fas fa-check me-1"></i> อนุมัติ
                    </button>
                    <button type="submit" name="action" value="reject" class="btn btn-danger">
                        <i class="fa fa-times"></i> Reject
                    </button>
                @endif

            </div>


        </form>
    </div>

@endsection


<style>

</style>

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const input = document.getElementById('mfg_no');
            const hid = document.getElementById('mfg_no_id');
            const listEl = document.getElementById('mfg_no-list');
            const url = input.dataset.url;

            const gradeEl = document.getElementById('grade');
            const typeEl = document.getElementById('form_type');
            const sizeEl = document.getElementById('size');
            const qtyEl = document.getElementById('qty');
            const lengthEl = document.getElementById('length');

            let timer = null,
                idx = -1;

            const fill = (m = {}) => {
                gradeEl.value = m.grade ?? '';
                typeEl.value = m.type ?? '';
                sizeEl.value = m.size ?? '';
                qtyEl.value = m.wo_qty ?? '';
                lengthEl.value = m.length ?? '';
            };

            const debounce = (fn, ms = 200) => (...a) => {
                clearTimeout(timer);
                timer = setTimeout(() => fn(...a), ms);
            };

            const render = (rows) => {
                listEl.innerHTML = '';
                idx = -1;
                if (!rows.length) {
                    listEl.style.display = 'none';
                    return;
                }

                const frag = document.createDocumentFragment();
                rows.forEach(r => {
                    const a = document.createElement('a');
                    a.href = 'javascript:void(0)';
                    a.className = 'list-group-item list-group-item-action';
                    a.dataset.id = r.id;
                    a.dataset.value = r.value;
                    a.dataset.label = r.label;
                    a.textContent = r.label;
                    // ใช้ mousedown กัน blur ก่อนกดเลือก
                    a.addEventListener('mousedown', () => select(r));
                    frag.appendChild(a);
                });
                listEl.appendChild(frag);
                listEl.style.display = 'block';
            };

            const select = (row) => {
                input.value = row.value ?? row.id ?? '';
                hid.value = row.id ?? '';
                fill(row.meta || {}); // << ใส่ค่าลงช่องปลายทาง
                listEl.style.display = 'none';
            };

            const search = async (q) => {
                try {
                    const res = await fetch(`${url}?q=${encodeURIComponent(q)}`, {
                        headers: {
                            'Accept': 'application/json'
                        }
                    });
                    const data = await res.json();
                    const items = (Array.isArray(data) ? data : (data.results || []))
                        .map(r => ({
                            id: r.id,
                            value: r.id, // ให้กล่องหลักแสดง MFG id
                            label: r.text ?? r.label ?? String(r.id), // ข้อความในลิสต์
                            meta: r.meta ?? null
                        }));
                    render(items);
                } catch (e) {
                    console.error(e);
                    render([]);
                }
            };

            input.addEventListener('input', debounce(() => {
                hid.value = '';
                fill({}); // << เคลียร์ช่องปลายทางเมื่อเริ่มพิมพ์ใหม่
                const q = input.value.trim();
                if (q.length < 1) {
                    listEl.style.display = 'none';
                    return;
                }
                search(q);
            }, 200));

            // คลิกนอกลิสต์ให้ซ่อน
            document.addEventListener('click', (e) => {
                if (!listEl.contains(e.target) && e.target !== input) {
                    listEl.style.display = 'none';
                }
            });
        });
    </script>
@endpush
