@extends('layouts.layout')
@section('page-title', 'Delivery Plan')
@section('title', 'Delivery Plan')

@section('content')
    @php
        $dayStr = isset($day) ? $day->toDateString() : now()->toDateString();
        $dayTitle = isset($day) ? $day->format('D, d M Y') : now()->format('D, d M Y');

        $header = $header ?? [];
        $lines = old('lines', $lines ?? []);
        if (!is_array($lines)) {
            $lines = [];
        }
        $ln0 = $lines[0] ?? [];

        $isEdit = $isEdit ?? !empty($lines[0]['id'] ?? null);

        $dueDateVal = old('due_date', $header['due_date'] ?? $dayStr);
        $telVal = old('tel', $header['tel'] ?? '');

        $rawAttach = old('attach_docs', $header['attach_docs'] ?? []);
        if (is_string($rawAttach)) {
            $rawAttach = array_filter(array_map('trim', explode(',', $rawAttach)));
        }
        $hasDocs = is_array($rawAttach) ? $rawAttach : [];
        $otherCode = 'OTHER';
        $sbl = (string) old('lines.0.sell_by_line', $ln0['sell_by_line'] ?? '0');
    @endphp

    <div class="container-fluid py-3 px-3">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div>
                <div class="fw-bold fs-5">Add Data</div>
                <div class="text-muted">{{ $dayTitle }}</div>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('dp.inquiry') }}" class="btn btn-outline-dark btn-sm">
                    ไปหน้า Inquiry
                </a>
            </div>
        </div>

        @if (session('ok'))
            <div class="alert alert-success py-2">{{ session('ok') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger py-2">
                <div class="fw-bold mb-1">เกิดข้อผิดพลาด</div>
                <ul class="mb-0">
                    @foreach ($errors->all() as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="dp-card dp-form-section">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="fw-bold">เพิ่ม / แก้ไข</div>
                @if ($isEdit)
                    <span class="badge text-bg-warning">EDIT MODE</span>
                @endif
            </div>

            <form id="dpForm" method="POST" action="{{ route('dp.store', ['date' => $dayStr]) }}">
                @csrf

                <div id="editTop" class="dp-card mt-3">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div class="fw-bold">รายละเอียดสินค้า</div>
                    </div>

                    <input type="hidden" id="ordId" name="lines[0][id]"
                        value="{{ old('lines.0.id', $ln0['id'] ?? '') }}">

                    <div class="dp-form-grid3 mt-3">

                        <div class="dp-col-12">
                            <label class="form-label">โหมด</label>
                            <div class="dp-radio-row">
                                <label class="dp-radio">
                                    <input type="radio" class="form-check-input me-1" name="lines[0][mode]" value="SO"
                                        {{ old('lines.0.mode', $ln0['mode'] ?? 'SO') === 'SO' ? 'checked' : '' }}>
                                    ปกติ (SO)
                                </label>
                                <label class="dp-radio">
                                    <input type="radio" class="form-check-input me-1" name="lines[0][mode]" value="ACID"
                                        {{ old('lines.0.mode', $ln0['mode'] ?? 'SO') === 'ACID' ? 'checked' : '' }}>
                                    ส่งกัดกรด
                                </label>
                            </div>
                        </div>

                        <div class="dp-col-6">
                            <label class="form-label">วันที่ส่งสินค้า <span class="text-danger">*</span></label>
                            <input type="date" name="ship_posted_date"
                                class="form-control @error('ship_posted_date') is-invalid @enderror"
                                value="{{ old('ship_posted_date', isset($header['ship_posted_date']) ? \Carbon\Carbon::parse($header['ship_posted_date'])->toDateString() : $dayStr) }}"
                                required>
                            @error('ship_posted_date')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="dp-col-6">
                            <label class="form-label">ช่วงเวลารับส่ง <span class="text-danger">*</span></label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <input type="text" class="form-control @error('window_time') is-invalid @enderror"
                                        name="window_time" value="{{ old('window_time', $header['window_time']) }}"
                                        placeholder="เช่น 08:00">
                                    @error('window_time')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <div class="dp-col-6">
                            <label class="form-label">Sales Order</label>
                            <div class="position-relative">
                                <input type="text" class="form-control dp-input" id="soInput"
                                    name="lines[0][so_number]"
                                    value="{{ old('lines.0.so_number', $ln0['so_number'] ?? '') }}"
                                    placeholder="พิมพ์ SO (อย่างน้อย 2 ตัว)" autocomplete="off"
                                    data-so-lookup-url="{{ route('dp.soLookup') }}"
                                    data-so-lines-url="{{ route('dp.soLines') }}">
                                <div id="soSuggest" class="dp-suggest d-none"></div>
                            </div>
                            <div class="form-text">พิมพ์แล้วเลือกจากรายการ</div>
                        </div>

                        <div class="dp-col-6">
                            <input type="hidden" id="erpDueDate" value="{{ $dueDateVal }}"
                                class="form-control dp-input @error('due_date') is-invalid @enderror" disabled>
                            <input type="hidden" name="due_date" value="{{ $dueDateVal }}">
                            <input type="hidden" name="is_manual_mfg" id="isManualMfg" value="1">
                            @error('due_date')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="dp-col-6" style="position:relative;">
                            <label class="form-label">Customer</label>

                            <input type="hidden" id="customerId" name="lines[0][customer_id]"
                                value="{{ old('lines.0.customer_id', $ln0['customer_id'] ?? '') }}">

                            <input type="text" class="form-control dp-input" id="customerInput"
                                name="lines[0][customer_name]"
                                value="{{ old('lines.0.customer_name', $ln0['customer_name'] ?? '') }}"
                                placeholder="พิมพ์ชื่อ/โค้ดลูกค้า" autocomplete="off">

                            <div id="customerSuggest" class="dp-suggest d-none"></div>
                            <div class="form-text">พิมพ์แล้วเลือกจากรายการ</div>
                        </div>

                        <div class="dp-col-4" style="position:relative;">
                            <label class="form-label">Sales</label>

                            <input type="hidden" id="salesIdInput" name="lines[0][sales_id]"
                                value="{{ old('lines.0.sales_id', $ln0['sales_id'] ?? '') }}">

                            <input type="hidden" id="salesTextHidden" name="lines[0][sales_text]"
                                value="{{ old('lines.0.sales_text', $ln0['sales_text'] ?? '') }}">

                            <input type="text" class="form-control dp-input" id="salesInput"
                                value="{{ old('lines.0.sales_text', $ln0['sales_text'] ?? '') }}"
                                placeholder="พิมพ์ชื่อ / code / id" autocomplete="off">

                            <div id="salesSuggest" class="dp-suggest d-none"></div>
                        </div>

                        <input type="hidden" id="partsId" name="lines[0][parts_id]"
                            value="{{ old('lines.0.parts_id', $ln0['parts_id'] ?? '') }}">
                        <input type="hidden" id="kgPerLine" value="">

                        <div class="dp-col-6">
                            <label class="form-label">Part No</label>
                            <div class="position-relative">
                                <input type="text" class="form-control dp-input" id="partNo"
                                    name="lines[0][part_no]" value="{{ old('lines.0.part_no', $ln0['part_no'] ?? '') }}"
                                    placeholder="กรอก/เลือก Part" autocomplete="off"
                                    data-part-lookup-url="{{ route('dp.partLookup') }}">
                                <div id="partSuggest" class="dp-suggest d-none"></div>
                            </div>
                        </div>

                        <div class="dp-col-6">
                            <label class="form-label">Part Desc</label>
                            <input type="text" class="form-control dp-input" id="partDesc"
                                name="lines[0][part_desc]"
                                value="{{ old('lines.0.part_desc', $ln0['part_desc'] ?? '') }}" readonly>
                        </div>

                        <div class="dp-col-12">
                            <label class="form-label">MFG No</label>
                            <div class="position-relative">
                                <input type="text" class="form-control dp-input" id="mfgNo"
                                    name="lines[0][mfg_no]" value="{{ old('lines.0.mfg_no', $ln0['mfg_no'] ?? '') }}"
                                    placeholder="พิมพ์ W26... แล้วเลือก (manual ได้)" autocomplete="off"
                                    data-mfg-lookup-url="{{ route('dp.mfgLookup') }}">
                                <div id="mfgSuggest" class="dp-suggest d-none"></div>
                            </div>
                            <div class="form-text">รองรับหลายค่า คั่นด้วย ,</div>
                        </div>

                        <div class="dp-col-12">
                            <label class="form-label">ขายแบบระบุเส้น</label>
                            <div class="dp-radio-row">
                                <label class="dp-radio">
                                    <input type="radio" class="form-check-input me-1 js-sell-by-line"
                                        name="lines[0][sell_by_line]" value="1" {{ $sbl === '1' ? 'checked' : '' }}>
                                    ใช่
                                </label>
                                <label class="dp-radio">
                                    <input type="radio" class="form-check-input me-1 js-sell-by-line"
                                        name="lines[0][sell_by_line]" value="0" {{ $sbl !== '1' ? 'checked' : '' }}>
                                    ไม่ใช่
                                </label>
                            </div>
                        </div>

                        <div class="dp-col-6 {{ $sbl === '1' ? '' : 'd-none' }}" id="sellByLineQtyWrap">
                            <label class="form-label">Qty ระบุเส้น <span class="text-danger">*</span></label>
                            <input type="number" min="1" step="1" id="sellByLineQty"
                                name="lines[0][sell_by_line_qty]"
                                value="{{ old('lines.0.sell_by_line_qty', $ln0['sell_by_line_qty'] ?? '') }}"
                                class="form-control dp-input @error('lines.0.sell_by_line_qty') is-invalid @enderror"
                                placeholder="กรอกจำนวนเส้น">
                            @error('lines.0.sell_by_line_qty')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="dp-col-4">
                            <label class="form-label">จำนวน (KG) <span class="text-danger">*</span></label>
                            <input type="number" inputmode="decimal" step="0.001" min="0"
                                class="form-control dp-input @error('lines.0.qty_kg') is-invalid @enderror" id="qtyKg"
                                name="lines[0][qty_kg]" value="{{ old('lines.0.qty_kg', $ln0['qty_kg'] ?? '') }}"
                                placeholder="เช่น 1000">
                            @error('lines.0.qty_kg')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror

                            <div id="qtyCalcHint" class="form-text text-primary d-none"></div>
                            <div id="qtyCalcError" class="form-text text-danger d-none"></div>

                            @if (!empty($ln0['auto_qty_kg'] ?? null))
                                <div class="form-text">Auto Qty: {{ number_format((float) $ln0['auto_qty_kg'], 3) }}
                                    KG</div>
                            @endif
                        </div>

                        <input type="hidden" name="lines[0][auto_qty_kg]"
                            value="{{ old('lines.0.auto_qty_kg', $ln0['auto_qty_kg'] ?? '') }}">

                        <div class="dp-col-12">
                            <label class="form-label">สถานที่ส่ง <span class="text-danger">*</span></label>
                            <input type="text"
                                class="form-control dp-input @error('lines.0.delivery_location') is-invalid @enderror"
                                name="lines[0][delivery_location]"
                                value="{{ old('lines.0.delivery_location', $ln0['delivery_location'] ?? '') }}"
                                placeholder="สถานที่ส่ง" required>
                            @error('lines.0.delivery_location')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                    </div>

                    <hr class="my-3">

                    <div class="dp-form-grid2">
                        <div class="dp-field">
                            <label class="form-label">เบอร์โทร</label>
                            <input type="text" name="tel" value="{{ $telVal }}"
                                class="form-control dp-input @error('tel') is-invalid @enderror">
                            @error('tel')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="dp-field"></div>

                        <div class="dp-field dp-col-span-3">
                            <label class="form-label">หมายเหตุ (text)</label>
                            <textarea class="form-control" id="remarkInput" name="remark" rows="3">{{ old('remark', $header['remark'] ?? '') }}</textarea>
                        </div>

                        <div class="dp-field dp-col-span-3">
                            <label class="form-label">เอกสารแนบ</label>

                            <div class="dp-attach-box">
                                @foreach ($attachMasters as $doc)
                                    @php
                                        $code = (string) ($doc['code'] ?? '');
                                        $name = (string) ($doc['name'] ?? $code);
                                        $isOther = strtoupper($code) === $otherCode;
                                        $checked = in_array($code, $hasDocs, true);
                                    @endphp

                                    <label class="dp-check">
                                        <input type="checkbox" name="attach_docs[]" value="{{ $code }}"
                                            class="{{ $isOther ? 'jsAttachOtherCb' : '' }}"
                                            {{ $checked ? 'checked' : '' }}>
                                        <span>{{ $name }}</span>
                                    </label>

                                    @if ($isOther)
                                        <div id="attachDocOtherBox" class="{{ $checked ? '' : 'd-none' }}">
                                            <input id="attachDocOtherText" type="text"
                                                class="form-control dp-input mt-2 @error('attach_docs_other') is-invalid @enderror"
                                                name="attach_docs_other"
                                                value="{{ old('attach_docs_other', $header['attach_docs_other'] ?? '') }}"
                                                placeholder="ระบุเอกสารอื่นๆ">
                                            @error('attach_docs_other')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div id="editRemarkWrap" class="mt-3 d-none">
                        <label class="form-label">Edit Remark (ตอนแก้ไข)</label>
                        <textarea class="form-control" id="editRemarkInput" name="lines[0][edit_remark]" rows="2"
                            {{ $isEdit ? 'required' : '' }} placeholder="ใส่เหตุผล/หมายเหตุในการแก้ไข">{{ old('lines.0.edit_remark', $ln0['edit_remark'] ?? '') }}</textarea>
                        <div class="form-text">จำเป็นเมื่อแก้ไขรายการเดิม</div>
                    </div>

                    <div class="d-flex gap-2 justify-content-end pt-3">
                        <button class="btn btn-primary px-4" type="submit">บันทึก</button>
                        <button class="btn btn-outline-secondary" type="button" id="btnResetLine">ล้างฟอร์ม</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <style>
        .dp-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 14px
        }

        .dp-form-section {
            margin-bottom: 28px
        }

        .dp-input,
        .dp-form-grid2 .form-select {
            height: 40px;
            padding: .5rem .75rem;
            font-size: .95rem
        }

        .dp-form-grid2 {
            display: grid;
            grid-template-columns: 1.1fr 1.2fr 1fr;
            gap: 18px;
            align-items: start
        }

        .dp-col-span-3 {
            grid-column: span 3
        }

        .dp-form-grid2 textarea.form-control {
            min-height: 96px;
            height: auto
        }

        .dp-input[readonly] {
            background: #f8fafc
        }

        .dp-radio-row {
            display: flex;
            gap: 16px;
            align-items: center;
            min-height: 40px;
            flex-wrap: wrap
        }

        .dp-radio {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
            user-select: none;
            font-weight: 600
        }

        .dp-attach-box {
            border: 1px dashed #e5e7eb;
            border-radius: 12px;
            padding: 12px;
            background: #fafafa;
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(3, minmax(0, 1fr))
        }

        .dp-check {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
            user-select: none
        }

        .dp-suggest {
            position: absolute;
            left: 0;
            right: 0;
            top: calc(100% + 6px);
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 10px 24px rgba(0, 0, 0, .06);
            padding: 6px;
            max-height: 260px;
            overflow: auto;
            z-index: 9999
        }

        .dp-suggest button {
            width: 100%;
            text-align: left;
            border: 0;
            background: transparent
        }

        .dp-suggest-item {
            padding: 8px 10px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            gap: 2px
        }

        .dp-suggest-item:hover {
            background: #f3f4f6
        }

        .dp-suggest-title {
            font-weight: 700;
            font-size: 13px
        }

        .dp-suggest-sub {
            font-size: 12px;
            color: #6b7280
        }

        .dp-form-grid3 {
            display: grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap: 14px 16px;
            align-items: start
        }

        .dp-col-12 {
            grid-column: span 12;
            min-width: 0
        }

        .dp-col-6 {
            grid-column: span 6;
            min-width: 0
        }

        .dp-col-4 {
            grid-column: span 4;
            min-width: 0
        }

        .dp-form-grid3 .form-label {
            font-weight: 800;
            font-size: .92rem;
            margin-bottom: .35rem
        }

        .dp-form-grid3 .form-text {
            font-size: .80rem;
            color: #6b7280;
            margin-top: .25rem
        }

        @media (max-width: 992px) {
            .dp-form-grid2 {
                grid-template-columns: 1fr 1fr
            }

            .dp-col-span-3 {
                grid-column: span 2
            }

            .dp-attach-box {
                grid-template-columns: repeat(2, minmax(0, 1fr))
            }

            .dp-col-6,
            .dp-col-4 {
                grid-column: span 12
            }
        }

        @media (max-width: 576px) {
            .dp-form-grid2 {
                grid-template-columns: 1fr
            }

            .dp-col-span-3 {
                grid-column: span 1
            }

            .dp-attach-box {
                grid-template-columns: 1fr
            }
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            (function editModeToggle() {
                const isEdit = @json($isEdit);
                const wrap = document.getElementById('editRemarkWrap');
                const ord = document.getElementById('ordId');
                if (!wrap) return;
                const hasId = ord && (ord.value || '').trim() !== '';
                if (isEdit || hasId) wrap.classList.remove('d-none');
            })();

            async function fetchJson(url, options = {}) {
                const res = await fetch(url, {
                    ...options,
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(options.headers || {})
                    }
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                return res.json();
            }

            function esc(v) {
                if (v === null || v === undefined) return '';
                return String(v)
                    .replaceAll('&', '&amp;')
                    .replaceAll('<', '&lt;')
                    .replaceAll('>', '&gt;')
                    .replaceAll('"', '&quot;')
                    .replaceAll("'", "&#039;");
            }

            function toNum(val) {
                const n = parseFloat(val);
                return Number.isFinite(n) ? n : 0;
            }

            const qtyKg = document.getElementById('qtyKg');
            const qtyMaxHint = document.getElementById('qtyMaxHint');
            const sellByLineQtyInput = document.getElementById('sellByLineQty');
            const kgPerLineInput = document.getElementById('kgPerLine');
            const qtyCalcHint = document.getElementById('qtyCalcHint');
            const qtyCalcError = document.getElementById('qtyCalcError');
            const yesRadio = document.querySelector('input[name="lines[0][sell_by_line]"][value="1"]');
            const noRadio = document.querySelector('input[name="lines[0][sell_by_line]"][value="0"]');

            let maxQtyAllowed = 0;
            let qtySource = '';

            function setQtyLimit(maxQty, source) {
                maxQtyAllowed = toNum(maxQty);
                qtySource = source || '';

                if (qtyKg) {
                    qtyKg.setAttribute('max', String(maxQtyAllowed || 0));
                    qtyKg.setAttribute('data-max-qty', String(maxQtyAllowed || 0));
                }

                if (qtyMaxHint) {
                    qtyMaxHint.textContent = maxQtyAllowed > 0 ?
                        `Max ${maxQtyAllowed} KG จาก ${qtySource}` :
                        '';
                }
            }

            function validateQtyInput() {
                if (!qtyKg) return true;

                const current = toNum(qtyKg.value);

                if (current < 0) {
                    qtyKg.value = '0';
                }

                if (maxQtyAllowed > 0 && current > maxQtyAllowed) {
                    qtyKg.value = String(maxQtyAllowed);
                    qtyKg.setCustomValidity(`จำนวน KG ห้ามเกิน ${maxQtyAllowed} จาก ${qtySource}`);
                    qtyKg.reportValidity();
                    return false;
                }

                qtyKg.setCustomValidity('');
                return true;
            }

            function setCalcHint(msg = '') {
                if (!qtyCalcHint) return;
                if (!msg) {
                    qtyCalcHint.classList.add('d-none');
                    qtyCalcHint.textContent = '';
                    return;
                }
                qtyCalcHint.classList.remove('d-none');
                qtyCalcHint.textContent = msg;
            }

            function setCalcError(msg = '') {
                if (!qtyCalcError) return;
                if (!msg) {
                    qtyCalcError.classList.add('d-none');
                    qtyCalcError.textContent = '';
                    return;
                }
                qtyCalcError.classList.remove('d-none');
                qtyCalcError.textContent = msg;
            }

            function isSellByLineYes() {
                const checked = document.querySelector('input[name="lines[0][sell_by_line]"]:checked');
                return checked && checked.value === '1';
            }

            function canSellByLine() {
                return !!(kgPerLineInput && kgPerLineInput.value && parseFloat(kgPerLineInput.value) > 0);
            }

            function hasSelectedPart() {
                const partNo = document.getElementById('partNo')?.value?.trim() || '';
                const partsId = document.getElementById('partsId')?.value?.trim() || '';
                return partNo !== '' || partsId !== '';
            }

            function syncSellByLineAvailability() {
                const hasPart = hasSelectedPart();
                const canCalc = canSellByLine();

                if (yesRadio) {
                    yesRadio.disabled = hasPart ? !canCalc : false;
                }

                if (!hasPart) {
                    setCalcError('');
                    return;
                }

                if (!canCalc && yesRadio && yesRadio.checked && noRadio) {
                    noRadio.checked = true;
                    noRadio.dispatchEvent(new Event('change', {
                        bubbles: true
                    }));
                }

                if (!canCalc) {
                    setCalcError('Part นี้ไม่รองรับขายแบบระบุเส้น');
                } else {
                    setCalcError('');
                }
            }

            function recalcSellByLineQtyToKg() {
                if (!qtyKg || !sellByLineQtyInput || !kgPerLineInput) return;

                const yes = isSellByLineYes();
                const lineQty = parseFloat(sellByLineQtyInput.value || '0');
                const kgPerLine = parseFloat(kgPerLineInput.value || '0');
                const hasPart = hasSelectedPart();

                if (!yes) {
                    qtyKg.readOnly = false;
                    qtyKg.classList.remove('bg-light');
                    setCalcHint('');
                    setCalcError('');
                    return;
                }

                qtyKg.readOnly = true;
                qtyKg.classList.add('bg-light');

                if (!hasPart) {
                    qtyKg.value = '';
                    setCalcHint('');
                    setCalcError('');
                    return;
                }

                if (!kgPerLine) {
                    qtyKg.value = '';
                    setCalcHint('');
                    setCalcError('Part นี้ไม่รองรับขายแบบระบุเส้น');
                    return;
                }

                if (!lineQty) {
                    qtyKg.value = '';
                    setCalcError('');
                    setCalcHint('กรอก Qty ระบุเส้น เพื่อคำนวณเป็น KG');
                    return;
                }

                const totalKg = lineQty * kgPerLine;
                qtyKg.value = totalKg.toFixed(3);

                const autoQtyEl = document.querySelector('input[name="lines[0][auto_qty_kg]"]');
                if (autoQtyEl) {
                    autoQtyEl.value = totalKg.toFixed(3);
                }

                setCalcError('');
                setCalcHint(`${lineQty} เส้น × ${kgPerLine.toFixed(6)} KG/เส้น = ${totalKg.toFixed(3)} KG`);

                validateQtyInput();
            }

            async function hydratePartCalcByPartNo(partNoValue) {
                const partNo = (partNoValue || '').trim();
                const partLookupUrl = document.getElementById('partNo')?.getAttribute('data-part-lookup-url') ||
                    '';
                const kgPerLine = document.getElementById('kgPerLine');

                if (!partNo || !partLookupUrl || !kgPerLine) return;

                try {
                    const data = await fetchJson(partLookupUrl + '?q=' + encodeURIComponent(partNo));
                    const items = data.items || [];
                    const found = items.find(x => (x.part_no || '').trim() === partNo);
                    kgPerLine.value = found?.kg_per_line ?? '';
                    syncSellByLineAvailability();
                    recalcSellByLineQtyToKg();
                } catch (e) {
                    kgPerLine.value = '';
                    syncSellByLineAvailability();
                }
            }

            if (qtyKg) {
                qtyKg.addEventListener('input', validateQtyInput);
                qtyKg.addEventListener('change', validateQtyInput);
                qtyKg.addEventListener('blur', validateQtyInput);
            }

            if (sellByLineQtyInput) {
                sellByLineQtyInput.addEventListener('input', recalcSellByLineQtyToKg);
                sellByLineQtyInput.addEventListener('change', recalcSellByLineQtyToKg);
            }

            document.querySelectorAll('.js-sell-by-line').forEach(r => {
                r.addEventListener('change', recalcSellByLineQtyToKg);
            });

            const partNoElForCalc = document.getElementById('partNo');
            if (partNoElForCalc) {
                partNoElForCalc.addEventListener('blur', () => {
                    setTimeout(recalcSellByLineQtyToKg, 250);
                });
            }

            (function attachOtherToggle() {
                const cb = document.querySelector('input[name="attach_docs[]"][value="OTHER"]');
                const box = document.getElementById('attachDocOtherBox');
                const inp = document.getElementById('attachDocOtherText');
                if (!cb || !box || !inp) return;

                function syncOther() {
                    if (cb.checked) {
                        box.classList.remove('d-none');
                        inp.disabled = false;
                    } else {
                        box.classList.add('d-none');
                        inp.disabled = true;
                        inp.value = '';
                    }
                }
                cb.addEventListener('change', syncOther);
                syncOther();
            })();

            (function modeAcidToggle() {
                const modeRadios = document.querySelectorAll('input[name="lines[0][mode]"]');

                const soInput = document.getElementById('soInput');
                const soDd = document.getElementById('soSuggest');

                const salesIdInput = document.getElementById('salesIdInput');
                const salesInput = document.getElementById('salesInput');
                const salesDd = document.getElementById('salesSuggest');
                const salesTextHidden = document.getElementById('salesTextHidden');

                const partNo = document.getElementById('partNo');
                const partDesc = document.getElementById('partDesc');

                function closeDd(dd) {
                    if (!dd) return;
                    dd.classList.add('d-none');
                    dd.innerHTML = '';
                }

                function setAcidModeUI(isAcid) {
                    if (soInput) {
                        soInput.disabled = !!isAcid;
                        if (isAcid) {
                            soInput.value = '';
                            closeDd(soDd);
                        }
                    }

                    if (salesInput) {
                        if (isAcid) {
                            if (salesIdInput) salesIdInput.value = '';
                            salesInput.value = 'วางแผน';
                            salesInput.readOnly = true;
                            salesInput.disabled = true;
                            closeDd(salesDd);
                            if (salesTextHidden) salesTextHidden.value = 'วางแผน';
                        } else {
                            salesInput.disabled = false;
                            salesInput.readOnly = false;
                        }
                    }

                    if (isAcid) {
                        if (partNo) partNo.readOnly = false;
                        if (partDesc) partDesc.readOnly = false;
                    } else {
                        if (partNo) partNo.readOnly = true;
                        if (partDesc) partDesc.readOnly = true;
                    }
                }

                function sync() {
                    const checked = document.querySelector('input[name="lines[0][mode]"]:checked');
                    const mode = checked ? (checked.value || '').toUpperCase() : 'SO';
                    setAcidModeUI(mode === 'ACID');
                }

                modeRadios.forEach(r => r.addEventListener('change', sync));
                sync();
            })();

            (function soPartReadonlyAssist() {
                const partNo = document.getElementById('partNo');
                if (!partNo) return;

                function isAcid() {
                    const checked = document.querySelector('input[name="lines[0][mode]"]:checked');
                    return (checked ? checked.value : 'SO').toUpperCase() === 'ACID';
                }

                partNo.addEventListener('focus', () => {
                    if (!isAcid()) partNo.readOnly = false;
                });

                partNo.addEventListener('blur', () => {
                    if (!isAcid()) partNo.readOnly = true;
                });

                partNo.addEventListener('paste', (e) => {
                    if (!isAcid()) e.preventDefault();
                });
            })();

            (function soAutocompleteNice() {
                const soInput = document.getElementById('soInput');
                const soDd = document.getElementById('soSuggest');
                if (!soInput || !soDd) return;

                const partNo = document.getElementById('partNo');
                const partDesc = document.getElementById('partDesc');
                const partsId = document.getElementById('partsId');
                const mfgNo = document.getElementById('mfgNo');
                const stockFg = document.getElementById('stockFg');

                const customerId = document.getElementById('customerId');
                const customerInput = document.getElementById('customerInput');

                const salesIdInput = document.getElementById('salesIdInput');
                const salesInput = document.getElementById('salesInput');
                const salesTextHidden = document.getElementById('salesTextHidden');

                const soLookupUrl = soInput.getAttribute('data-so-lookup-url') || '';

                let lastItems = [];
                let activeIndex = -1;
                let debounce = null;

                function closeDd() {
                    soDd.classList.add('d-none');
                    soDd.innerHTML = '';
                    lastItems = [];
                    activeIndex = -1;
                }

                function highlightActive() {
                    const items = Array.from(soDd.querySelectorAll('.dp-suggest-item'));
                    items.forEach((el, i) => {
                        el.classList.toggle('active', i === activeIndex);
                        el.style.background = i === activeIndex ? 'rgba(0,0,0,0.05)' : '';
                    });
                }

                function setCustomerSalesFromSO(it) {
                    const cid = (it.customer_id || '').toString().trim();
                    const cname = (it.customer_name || '').trim();
                    const ccode = (it.customernumber || it.customer_code || '').toString().trim();

                    if (customerId) customerId.value = cid;
                    if (customerInput) {
                        customerInput.value = (ccode ? `${ccode} — ` : '') + (cname || '');
                    }

                    const sid = (it.sales_id || '').toString().trim();
                    const snameTh = (it.sales_name_th || '').trim();
                    const sname = (it.sales_name || '').trim();
                    const scode = (it.sales_code || '').trim();

                    if (salesIdInput) salesIdInput.value = sid;

                    if (salesInput) {
                        const displayName = snameTh || sname || (sid ? `Sales ${sid}` : '');
                        const text = (scode ? `${scode} ` : '') + displayName;
                        salesInput.value = text;
                        if (salesTextHidden) salesTextHidden.value = text;
                    }
                }

                async function applyLineToForm(it) {
                    if (!it) return;

                    soInput.value = (it.ordnumber || '').trim();
                    setCustomerSalesFromSO(it);

                    if (partNo) partNo.value = it.part_number || '';
                    if (partDesc) partDesc.value = it.part_desc || '';
                    if (partsId) {
                        partsId.value =
                            it.parts_id !== null && it.parts_id !== undefined ?
                            String(it.parts_id) :
                            '';
                    }

                    await hydratePartCalcByPartNo(it.part_number || '');

                    const soQty = toNum(it.ordered_qty ?? it.qty ?? it.so_qty ?? 0);
                    if (qtyKg && !isSellByLineYes()) qtyKg.value = String(soQty);

                    setQtyLimit(soQty, 'SO');

                    const fgQty = it.stock_qty ?? it.stock_fg ?? '';
                    if (stockFg) stockFg.value = fgQty;

                    if (mfgNo) mfgNo.value = '';

                    recalcSellByLineQtyToKg();
                    closeDd();
                }

                function render(items) {
                    if (!Array.isArray(items) || items.length === 0) return closeDd();

                    lastItems = items;
                    activeIndex = -1;

                    soDd.innerHTML = items.map((it, idx) => {
                        const orderedQty = it.ordered_qty ?? it.qty ?? it.so_qty ?? '';
                        const stockQty = it.stock_qty ?? it.stock_fg ?? '';

                        return `
                    <button type="button" class="dp-suggest-item" data-idx="${idx}">
                        <div class="dp-suggest-title">${esc(it.ordnumber || '')}</div>
                        <div class="dp-suggest-sub">
                            ${esc(it.part_number || '')}${it.part_desc ? ' — ' + esc(it.part_desc) : ''}
                        </div>
                        <div class="dp-suggest-sub">
                            Ordered: ${esc(orderedQty)} | Stock: ${esc(stockQty)}
                        </div>
                    </button>
                `;
                    }).join('');

                    soDd.classList.remove('d-none');

                    soDd.querySelectorAll('.dp-suggest-item').forEach((btn) => {
                        btn.addEventListener('mousedown', async () => {
                            const idx = parseInt(btn.dataset.idx || '-1', 10);
                            if (idx >= 0 && lastItems[idx]) {
                                await applyLineToForm(lastItems[idx]);
                            }
                        });
                    });
                }

                async function lookup() {
                    const q = (soInput.value || '').trim();
                    if (!soLookupUrl || q.length < 2) return closeDd();

                    const data = await fetchJson(`${soLookupUrl}?q=${encodeURIComponent(q)}`);
                    render(data.items || []);
                }

                soInput.addEventListener('input', () => {
                    clearTimeout(debounce);
                    debounce = setTimeout(() => lookup().catch(() => closeDd()), 200);
                });

                soInput.addEventListener('keydown', (e) => {
                    if (soDd.classList.contains('d-none')) return;
                    const max = lastItems.length;
                    if (!max) return;

                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        activeIndex = Math.min(activeIndex + 1, max - 1);
                        highlightActive();
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        activeIndex = Math.max(activeIndex - 1, 0);
                        highlightActive();
                    } else if (e.key === 'Enter') {
                        if (activeIndex >= 0 && lastItems[activeIndex]) {
                            e.preventDefault();
                            applyLineToForm(lastItems[activeIndex]);
                        }
                    } else if (e.key === 'Escape') {
                        closeDd();
                    }
                });

                soInput.addEventListener('blur', () => setTimeout(closeDd, 200));
                document.addEventListener('click', (e) => {
                    if (soDd.contains(e.target) || soInput.contains(e.target)) return;
                    closeDd();
                });
            })();

            (function customerAutocomplete() {
                const inp = document.getElementById('customerInput');
                const hid = document.getElementById('customerId');
                const dd = document.getElementById('customerSuggest');
                const salesIdEl = document.getElementById('salesIdInput');
                const salesTextEl = document.getElementById('salesInput');
                const salesTextHidden = document.getElementById('salesTextHidden');

                if (!inp || !hid || !dd || !salesIdEl || !salesTextEl) return;

                const urlBase = @json(route('dp.customerLookup'));
                let t = null;
                let salesTouched = false;

                salesTextEl.addEventListener('input', () => {
                    salesTouched = true;
                });

                function closeDd() {
                    dd.classList.add('d-none');
                    dd.innerHTML = '';
                }

                function render(items) {
                    if (!Array.isArray(items) || items.length === 0) return closeDd();

                    dd.innerHTML = items.map(it => `
                        <button type="button" class="dp-suggest-item"
                            data-id="${esc(it.id)}"
                            data-text="${esc(it.text)}"
                            data-sales-id="${esc(it.sales_id ?? '')}"
                            data-sales-text="${esc(it.sales_text ?? '')}">
                            <div class="dp-suggest-title">${esc(it.text)}</div>
                            ${it.sales_text ? `<div class="dp-suggest-sub">${esc(it.sales_text)}</div>` : ''}
                        </button>
                    `).join('');

                    dd.classList.remove('d-none');

                    dd.querySelectorAll('.dp-suggest-item').forEach(btn => {
                        btn.addEventListener('click', (e) => {
                            const el = e.currentTarget;
                            inp.value = el.dataset.text || '';
                            hid.value = el.dataset.id || '';

                            if (!salesTouched) {
                                const sid = el.dataset.salesId || '';
                                const stext = el.dataset.salesText || sid;

                                salesIdEl.value = sid;
                                salesTextEl.value = stext;
                                if (salesTextHidden) salesTextHidden.value = stext;
                            }
                            closeDd();
                        });
                    });
                }

                async function lookup() {
                    const q = (inp.value || '').trim();
                    if (q === '') {
                        hid.value = '';
                        return closeDd();
                    }
                    if (q.length < 2) return closeDd();

                    const data = await fetchJson(urlBase + '?q=' + encodeURIComponent(q));
                    render(Array.isArray(data) ? data : (data.items || []));
                }

                inp.addEventListener('input', () => {
                    clearTimeout(t);
                    t = setTimeout(() => lookup().catch(closeDd), 250);
                });

                inp.addEventListener('focus', () => {
                    if ((inp.value || '').trim().length >= 2) lookup().catch(closeDd);
                });

                inp.addEventListener('blur', () => setTimeout(closeDd, 200));
                document.addEventListener('click', (e) => {
                    if (dd.contains(e.target) || inp.contains(e.target)) return;
                    closeDd();
                });
            })();

            (function salesAutocomplete() {
                const inp = document.getElementById('salesInput');
                const hid = document.getElementById('salesIdInput');
                const dd = document.getElementById('salesSuggest');
                const salesTextHidden = document.getElementById('salesTextHidden');

                if (!inp || !hid || !dd) return;

                const urlBase = @json(route('dp.salesLookup'));
                let t = null;

                function closeDd() {
                    dd.classList.add('d-none');
                    dd.innerHTML = '';
                }

                function render(items) {
                    if (!Array.isArray(items) || !items.length) return closeDd();

                    dd.innerHTML = items.map(it => `
                        <button type="button" class="dp-suggest-item"
                            data-id="${esc(it.id)}"
                            data-text="${esc(it.text)}"
                            data-name-th="${esc(it.sales_name_th || '')}">
                            <div class="dp-suggest-title">${esc(it.text)}</div>
                        </button>
                    `).join('');

                    dd.classList.remove('d-none');

                    dd.querySelectorAll('.dp-suggest-item').forEach(btn => {
                        btn.addEventListener('click', (e) => {
                            const el = e.currentTarget;
                            hid.value = el.dataset.id || '';
                            const text = el.dataset.nameTh || el.dataset.text || '';
                            inp.value = text;
                            if (salesTextHidden) salesTextHidden.value = text;
                            closeDd();
                        });
                    });
                }

                async function lookup() {
                    const q = (inp.value || '').trim();
                    if (q === '') {
                        hid.value = '';
                        return closeDd();
                    }
                    if (q.length < 2) return closeDd();

                    const data = await fetchJson(urlBase + '?q=' + encodeURIComponent(q));
                    render(Array.isArray(data) ? data : (data.items || []));
                }

                inp.addEventListener('input', () => {
                    clearTimeout(t);
                    t = setTimeout(() => lookup().catch(closeDd), 200);
                });

                inp.addEventListener('blur', () => setTimeout(closeDd, 200));
                document.addEventListener('click', (e) => {
                    if (dd.contains(e.target) || inp.contains(e.target)) return;
                    closeDd();
                });
            })();

            (function mfgAutocompleteNice() {
                const mfgInput = document.getElementById('mfgNo');
                const mfgDd = document.getElementById('mfgSuggest');
                if (!mfgInput || !mfgDd) return;

                const soInput = document.getElementById('soInput');
                const customerId = document.getElementById('customerId');
                const partsId = document.getElementById('partsId');

                const mfgLookupUrl = mfgInput.getAttribute('data-mfg-lookup-url') || '';

                let lastItems = [];
                let activeIndex = -1;
                let debounce = null;

                function closeDd() {
                    mfgDd.classList.add('d-none');
                    mfgDd.innerHTML = '';
                    lastItems = [];
                    activeIndex = -1;
                }

                function highlightActive() {
                    const items = Array.from(mfgDd.querySelectorAll('.dp-suggest-item'));
                    items.forEach((el, i) => {
                        el.classList.toggle('active', i === activeIndex);
                        el.style.background = i === activeIndex ? 'rgba(0,0,0,0.05)' : '';
                    });
                }

                const isManualMfgInput = document.getElementById('isManualMfg');

                async function applyMfgToForm(it) {
                    if (!it) return;

                    mfgInput.value = (it.mfg_no || it.workordernumber || '').trim();

                    if (isManualMfgInput) {
                        isManualMfgInput.value = '0';
                    }

                    const mfgQty = toNum(it.qty ?? 0);
                    if (qtyKg && !isSellByLineYes()) qtyKg.value = String(mfgQty);

                    setQtyLimit(mfgQty, 'MFG');
                    closeDd();
                }

                function render(items) {
                    if (!Array.isArray(items) || items.length === 0) return closeDd();

                    lastItems = items;
                    activeIndex = -1;

                    mfgDd.innerHTML = items.map((it, idx) => `
                <button type="button" class="dp-suggest-item" data-idx="${idx}">
                    <div class="dp-suggest-title">${esc(it.mfg_no || it.workordernumber || '')}</div>
                    <div class="dp-suggest-sub">SO: ${esc(it.ordnumber || '-')}</div>
                    <div class="dp-suggest-sub">Qty: ${esc(it.qty ?? '')}</div>
                </button>
            `).join('');

                    mfgDd.classList.remove('d-none');

                    mfgDd.querySelectorAll('.dp-suggest-item').forEach((btn) => {
                        btn.addEventListener('mousedown', async () => {
                            const idx = parseInt(btn.dataset.idx || '-1', 10);
                            if (idx >= 0 && lastItems[idx]) {
                                await applyMfgToForm(lastItems[idx]);
                            }
                        });
                    });
                }

                async function lookup() {
                    const q = (mfgInput.value || '').trim();
                    if (!mfgLookupUrl || q.length < 2) return closeDd();

                    const params = new URLSearchParams({
                        q,
                        ordnumber: (soInput?.value || '').trim(),
                        customer_id: (customerId?.value || '').trim(),
                        parts_id: (partsId?.value || '').trim(),
                    });

                    const data = await fetchJson(`${mfgLookupUrl}?${params.toString()}`);
                    render(data.items || []);
                }

                mfgInput.addEventListener('input', () => {
                    if (isManualMfgInput) {
                        isManualMfgInput.value = '1';
                    }

                    clearTimeout(debounce);
                    debounce = setTimeout(() => lookup().catch(() => closeDd()), 200);
                });

                mfgInput.addEventListener('change', () => {
                    if (!(mfgInput.value || '').trim() && isManualMfgInput) {
                        isManualMfgInput.value = '1';
                    }
                });

                mfgInput.addEventListener('keydown', (e) => {
                    if (mfgDd.classList.contains('d-none')) return;
                    const max = lastItems.length;
                    if (!max) return;

                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        activeIndex = Math.min(activeIndex + 1, max - 1);
                        highlightActive();
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        activeIndex = Math.max(activeIndex - 1, 0);
                        highlightActive();
                    } else if (e.key === 'Enter') {
                        if (activeIndex >= 0 && lastItems[activeIndex]) {
                            e.preventDefault();
                            applyMfgToForm(lastItems[activeIndex]);
                        }
                    } else if (e.key === 'Escape') {
                        closeDd();
                    }
                });

                mfgInput.addEventListener('blur', () => setTimeout(closeDd, 200));
                document.addEventListener('click', (e) => {
                    if (mfgDd.contains(e.target) || mfgInput.contains(e.target)) return;
                    closeDd();
                });
            })();

            const form = qtyKg?.closest('form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    if (!validateQtyInput()) {
                        e.preventDefault();
                        qtyKg?.focus();
                    }
                });
            }

            (function partAutocomplete() {
                const inp = document.getElementById('partNo');
                const hid = document.getElementById('partsId');
                const desc = document.getElementById('partDesc');
                const dd = document.getElementById('partSuggest');
                if (!inp || !dd) return;

                const urlBase = inp.getAttribute('data-part-lookup-url') || '';
                let t = null;
                let last = [];

                function closeDd() {
                    dd.classList.add('d-none');
                    dd.innerHTML = '';
                    last = [];
                }

                function render(items) {
                    if (!Array.isArray(items) || !items.length) return closeDd();
                    last = items;

                    dd.innerHTML = items.map((it, idx) => `
                        <button type="button" class="dp-suggest-item" data-idx="${idx}">
                            <div class="dp-suggest-title">${esc(it.part_no || '')}</div>
                            <div class="dp-suggest-sub">${esc(it.part_desc || '')}</div>
                        </button>
                    `).join('');
                    dd.classList.remove('d-none');

                    dd.querySelectorAll('.dp-suggest-item').forEach(btn => {
                        btn.addEventListener('mousedown', () => {
                            const idx = parseInt(btn.dataset.idx || '-1', 10);
                            const it = last[idx];
                            if (!it) return;

                            inp.value = it.part_no || '';
                            if (desc) desc.value = it.part_desc || '';
                            if (hid) hid.value = (it.id ?? '') || '';
                            inp.dataset.committed = (inp.value || '').trim();

                            if (kgPerLineInput) {
                                kgPerLineInput.value = it.kg_per_line ?? '';
                            }

                            const mfg = document.getElementById('mfgNo');
                            if (mfg) mfg.value = '';

                            syncSellByLineAvailability();
                            recalcSellByLineQtyToKg();
                            closeDd();
                        });
                    });
                }

                async function lookup() {
                    const q = (inp.value || '').trim();
                    if (!urlBase || q.length < 2) return closeDd();
                    const data = await fetchJson(urlBase + '?q=' + encodeURIComponent(q));
                    render(data.items || []);
                }

                inp.addEventListener('input', () => {
                    clearTimeout(t);
                    t = setTimeout(() => lookup().catch(closeDd), 200);
                });

                inp.addEventListener('blur', () => setTimeout(closeDd, 200));
                document.addEventListener('click', (e) => {
                    if (dd.contains(e.target) || inp.contains(e.target)) return;
                    closeDd();
                });
            })();

            (function qtyAndLocationValidateBeforeSubmit() {
                const form = document.getElementById('dpForm');
                if (!form) return;

                form.addEventListener('submit', function(e) {
                    const qtyEl = document.getElementById('qtyKg');
                    const locEl = document.querySelector('input[name="lines[0][delivery_location]"]');
                    const autoQtyEl = document.querySelector('input[name="lines[0][auto_qty_kg]"]');

                    const qty = parseFloat(qtyEl?.value || '0');
                    const autoQty = parseFloat(autoQtyEl?.value || '0');
                    const loc = (locEl?.value || '').trim();

                    if (!loc) {
                        e.preventDefault();
                        alert('กรุณาระบุสถานที่ส่ง');
                        locEl?.focus();
                        return;
                    }

                    if (autoQty > 0 && qty > autoQty) {
                        e.preventDefault();
                        alert(`จำนวนที่กรอก (${qty}) มากกว่าค่าจากระบบ (${autoQty})`);
                        qtyEl?.focus();
                    }
                });
            })();

            (function partManualTypingReset() {
                const partNo = document.getElementById('partNo');
                const partDesc = document.getElementById('partDesc');
                const partsId = document.getElementById('partsId');
                const mfgNo = document.getElementById('mfgNo');
                if (!partNo) return;

                partNo.dataset.committed = (partNo.dataset.committed || partNo.value || '').trim();

                partNo.addEventListener('input', () => {
                    const cur = (partNo.value || '').trim();
                    const committed = (partNo.dataset.committed || '').trim();

                    if (cur !== committed) {
                        if (partsId) partsId.value = '';
                        if (partDesc) partDesc.value = '';
                        if (mfgNo) mfgNo.value = '';
                        if (kgPerLineInput) kgPerLineInput.value = '';
                        syncSellByLineAvailability();
                    }
                });

                partNo.addEventListener('blur', () => {
                    partNo.dataset.committed = (partNo.value || '').trim();
                });
            })();

            (function sellByLineToggle() {
                const radios = document.querySelectorAll('.js-sell-by-line');
                const wrap = document.getElementById('sellByLineQtyWrap');
                const qtyInput = document.getElementById('sellByLineQty');

                if (!radios.length || !wrap || !qtyInput) return;

                function sync() {
                    const checked = document.querySelector('input[name="lines[0][sell_by_line]"]:checked');
                    const isYes = checked && checked.value === '1';

                    wrap.classList.toggle('d-none', !isYes);

                    if (isYes) {
                        qtyInput.disabled = false;
                        qtyKg.readOnly = true;
                        qtyKg.classList.add('bg-light');
                        recalcSellByLineQtyToKg();
                    } else {
                        qtyInput.disabled = true;
                        qtyInput.value = '';
                        qtyKg.readOnly = false;
                        qtyKg.classList.remove('bg-light');

                        const autoQtyEl = document.querySelector('input[name="lines[0][auto_qty_kg]"]');
                        if (autoQtyEl) autoQtyEl.value = '';

                        setCalcHint('');
                        if (!canSellByLine()) {
                            setCalcError('Part นี้ไม่รองรับขายแบบระบุเส้น');
                        } else {
                            setCalcError('');
                        }
                    }
                }

                radios.forEach(r => r.addEventListener('change', sync));
                sync();
            })();

            syncSellByLineAvailability();
            recalcSellByLineQtyToKg();
        });
    </script>
@endsection
