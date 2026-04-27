@php
    // Blade form with input fields and dynamic rows
    $action = $action ?? '';
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
@endphp
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{ $form->title ?? 'MFG Change Form' }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #111827;
            --line: #111827;
            --muted: #6b7280;
        }

        html,
        body {
            font-family: 'Sarabun', Tahoma, 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: var(--ink);
        }

        .sheet {
            max-width: 1100px;
            margin: 24px auto;
            padding: 20px 24px;
            background: #fff;
            border: 2px solid var(--line);
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .06)
        }

        .head-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px
        }

        .form-title {
            font-weight: 700;
            font-size: 26px;
            text-align: center;
            flex: 1
        }

        .form-no {
            white-space: nowrap;
            font-weight: 700;
            font-size: 18px
        }

        .table-grid {
            width: 100%;
            border-collapse: collapse
        }

        .table-grid th,
        .table-grid td {
            border: 1.6px solid var(--line);
            padding: 6px 8px;
            vertical-align: top
        }

        .table-grid thead th {
            background: #f3f4f6;
            text-align: center;
            font-weight: 700
        }

        .table-grid input,
        .table-grid select,
        .table-grid textarea {
            border: none;
            outline: none;
            width: 100%;
            font-size: 14px
        }

        .table-grid textarea {
            resize: vertical
        }

        .muted {
            color: var(--muted)
        }

        .dashed-area {
            border: 1.6px dashed var(--muted);
            border-radius: 8px;
            min-height: 64px;
            padding: 10px 12px
        }

        .sig-row {
            border: 1.6px solid var(--line);
            border-radius: 10px;
            padding: 12px
        }

        .cb {
            width: 18px;
            height: 18px
        }

        .doc-code {
            font-size: 12px;
            color: var(--muted)
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center
        }

        @media print {
            .top-actions {
                display: none !important
            }

            .sheet {
                margin: 0;
                max-width: none;
                box-shadow: none
            }

            @page {
                size: A4 portrait;
                margin: 12mm
            }
        }
    </style>
</head>

<body>
    <div class="sheet">
        <div class="top-actions mb-2 d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-sm btn-light" onclick="window.print()">พิมพ์ / Print</button>
        </div>

        <form method="POST" action="{{ $action }}">
            @csrf

            <div class="head-bar mb-3">
                <div class="form-title">{{ $form->title ?? 'แบบฟอร์มขอแก้ไข / เปิดใหม่ / ยกเลิกใบคำสั่งผลิต' }}</div>
                <div class="form-no">Form No: <input name="form_no" value="{{ old('form_no', $form->form_no ?? '') }}"
                        class="form-control form-control-sm d-inline-block" style="width:120px"></div>
            </div>

            {{-- ตารางรายการ --}}
            <div class="d-flex justify-content-between align-items-center mb-1">
                <div class="fw-semibold">ส่วนของผู้ขอดำเนินการ</div>
                <div class="small text-muted">* กรอกข้อมูลลงในช่องได้โดยตรง / เพิ่มแถวได้</div>
            </div>

            <div class="table-responsive">
                <table class="table-grid align-middle" id="itemsTable">
                    <thead>
                        <tr>
                            <th style="width:120px">Mfg No.</th>
                            <th style="width:90px">Grade</th>
                            <th style="width:80px">Type</th>
                            <th style="width:80px">Size</th>
                            <th style="width:90px">Length</th>
                            <th style="width:80px">Qty</th>
                            <th>ยกเลิกใบคำสั่งผลิตและเปิด <strong>MFG</strong> ใหม่ (รายละเอียด)</th>
                            <th style="width:46px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $idx => $row)
                            <tr data-row>
                                <td><input name="items[{{ $idx }}][mfg_no]" value="{{ $row['mfg_no'] }}"
                                        placeholder="เช่น W2501246" class="form-control form-control-sm"></td>
                                <td><input name="items[{{ $idx }}][grade]" value="{{ $row['grade'] }}"
                                        placeholder="เช่น 420J2" class="form-control form-control-sm"></td>
                                <td>
                                    <select name="items[{{ $idx }}][type]" class="form-select form-select-sm">
                                        @php($types = ['BAR', 'WIRE', 'COIL', 'ROD'])
                                        @foreach ($types as $t)
                                            <option value="{{ $t }}" @selected(($row['type'] ?? '') === $t)>
                                                {{ $t }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input name="items[{{ $idx }}][size]" value="{{ $row['size'] }}"
                                        placeholder="เช่น 3.20" class="form-control form-control-sm"></td>
                                <td><input name="items[{{ $idx }}][length]" value="{{ $row['length'] }}"
                                        placeholder="เช่น 2500.00" class="form-control form-control-sm"
                                        inputmode="decimal"></td>
                                <td><input name="items[{{ $idx }}][qty]" value="{{ $row['qty'] }}"
                                        placeholder="-" class="form-control form-control-sm" inputmode="decimal"></td>
                                <td>
                                    <textarea name="items[{{ $idx }}][remark]" rows="2" class="form-control form-control-sm"
                                        placeholder="รายละเอียด เช่น เปลี่ยนแปลงน้ำมันเป็น D381FW ...">{{ $row['remark'] }}</textarea>
                                </td>
                                <td class="text-center"><button type="button"
                                        class="btn btn-outline-danger btn-sm btn-icon" data-remove
                                        title="ลบแถว">&times;</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="d-flex gap-2 my-2">
                <button class="btn btn-outline-primary btn-sm" type="button" id="btnAddRow">+ เพิ่มแถว</button>
                <div class="ms-auto">มีผลตั้งแต่วันที่: <input type="date" name="effective_date"
                        class="form-control form-control-sm d-inline-block" style="width: 180px"
                        value="{{ old('effective_date', $form->effective_date ?? '') }}"></div>
            </div>

            {{-- สาเหตุ --}}
            <div class="mb-2 fw-semibold">สาเหตุ</div>
            <div class="mb-3">
                <textarea name="reason" rows="3" class="form-control" placeholder="อธิบายเหตุผล">{{ old('reason', $form->reason ?? '') }}</textarea>
            </div>

            {{-- ลายเซ็น ผู้ขอ / ผู้อนุมัติ --}}
            <div class="row g-3 mb-3 sig-row">
                <div class="col-md-6">
                    <div class="mb-2">ผู้ขอดำเนินการ</div>
                    <div class="row g-2 align-items-center">
                        <div class="col-3 text-end">ชื่อ :</div>
                        <div class="col-9"><input name="requester_name" class="form-control"
                                value="{{ old('requester_name', $form->requester_name ?? '') }}"></div>
                        <div class="col-3 text-end">วันที่ :</div>
                        <div class="col-9"><input type="date" name="request_date" class="form-control"
                                value="{{ old('request_date', $form->request_date ?? '') }}"></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-2">ผู้อนุมัติ (หัวหน้าหรือผู้จัดการ)</div>
                    <div class="row g-2 align-items-center">
                        <div class="col-3 text-end">ชื่อ :</div>
                        <div class="col-9"><input name="approver_name" class="form-control"
                                value="{{ old('approver_name', $form->approver_name ?? '') }}"></div>
                        <div class="col-3 text-end">วันที่ :</div>
                        <div class="col-9"><input type="date" name="approver_date" class="form-control"
                                value="{{ old('approver_date', $form->approver_date ?? '') }}"></div>
                    </div>
                </div>
            </div>

            {{-- ส่วนของผู้เปิดใบคำสั่งผลิต --}}
            <div class="border rounded-3 p-3 mb-3">
                <div class="fw-semibold mb-2">ส่วนของผู้เปิดใบคำสั่งผลิต</div>
                <div class="row g-3">
                    <div class="col-md-5">
                        <div class="form-check mb-1">
                            <input class="form-check-input cb" type="checkbox" name="open_new" id="open_new"
                                value="1" @checked(old('open_new', $form->open_new ?? false))>
                            <label for="open_new" class="form-check-label">ขอให้เปิดใบคำสั่งผลิตใหม่</label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input cb" type="checkbox" name="modify_mfg" id="modify_mfg"
                                value="1" @checked(old('modify_mfg', $form->modify_mfg ?? false))>
                            <label for="modify_mfg" class="form-check-label">ขอให้แก้ไขใบคำสั่งผลิต</label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input cb" type="checkbox" name="cancel_mfg" id="cancel_mfg"
                                value="1" @checked(old('cancel_mfg', $form->cancel_mfg ?? false))>
                            <label for="cancel_mfg" class="form-check-label">ยกเลิกใบคำสั่งผลิต</label>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="row g-2 align-items-center">
                            <div class="col-3 text-end">ลงชื่อ :</div>
                            <div class="col-9"><input name="opener_name" class="form-control"
                                    value="{{ old('opener_name', $form->opener_name ?? '') }}"></div>
                            <div class="col-3 text-end">Mfg No. :</div>
                            <div class="col-9"><input name="open_mfg_no" class="form-control"
                                    value="{{ old('open_mfg_no', $form->open_mfg_no ?? '') }}"
                                    placeholder="กรอกเมื่อเปิดใหม่แล้ว"></div>
                            <div class="col-5 text-end">วันที่ดำเนินการเสร็จ :</div>
                            <div class="col-7"><input type="date" name="completed_date" class="form-control"
                                    value="{{ old('completed_date', $form->completed_date ?? '') }}"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center">
                <div class="doc-code">{{ $form->doc_code ?? 'PC-06 Rev.01' }}</div>
                <div class="d-flex gap-2">
                    <button type="submit" name="_action" value="save" class="btn btn-primary">บันทึก</button>
                    <button type="submit" name="_action" value="draft"
                        class="btn btn-outline-secondary">บันทึกร่าง</button>
                </div>
            </div>
        </form>
    </div>

    <script>
        (function() {
            const table = document.querySelector('#itemsTable tbody');
            const btnAdd = document.querySelector('#btnAddRow');

            function reindex() {
                [...table.querySelectorAll('tr[data-row]')].forEach((tr, i) => {
                    [...tr.querySelectorAll('input,select,textarea')].forEach(el => {
                        el.name = el.name.replace(/items\[[0-9]+\]/, `items[${i}]`);
                    });
                });
            }

            function addRow(data = {}) {
                const idx = table.querySelectorAll('tr[data-row]').length;
                const tr = document.createElement('tr');
                tr.setAttribute('data-row', '');
                tr.innerHTML = `
      <td><input name="items[${idx}][mfg_no]" class="form-control form-control-sm" placeholder="เช่น W2501246" value="${data.mfg_no||''}"></td>
      <td><input name="items[${idx}][grade]" class="form-control form-control-sm" placeholder="เช่น 420J2" value="${data.grade||''}"></td>
      <td>
        <select name="items[${idx}][type]" class="form-select form-select-sm">
          ${['BAR','WIRE','COIL','ROD'].map(t=>`<option value="${t}" ${data.type===t?'selected':''}>${t}</option>`).join('')}
        </select>
      </td>
      <td><input name="items[${idx}][size]" class="form-control form-control-sm" placeholder="เช่น 3.20" value="${data.size||''}"></td>
      <td><input name="items[${idx}][length]" class="form-control form-control-sm" inputmode="decimal" placeholder="เช่น 2500.00" value="${data.length||''}"></td>
      <td><input name="items[${idx}][qty]" class="form-control form-control-sm" inputmode="decimal" placeholder="-" value="${data.qty||''}"></td>
      <td><textarea name="items[${idx}][remark]" rows="2" class="form-control form-control-sm" placeholder="รายละเอียด">${data.remark||''}</textarea></td>
      <td class="text-center"><button type="button" class="btn btn-outline-danger btn-sm btn-icon" data-remove>&times;</button></td>
    `;
                table.appendChild(tr);
            }

            btnAdd?.addEventListener('click', () => addRow());

            table.addEventListener('click', (e) => {
                if (e.target && (e.target.matches('[data-remove]') || e.target.closest('[data-remove]'))) {
                    const tr = e.target.closest('tr');
                    tr.parentNode.removeChild(tr);
                    reindex();
                }
            });
        })();
    </script>
</body>

</html>
