@extends('layouts.layout')
@section('title', 'ฟอร์มขอเปิด / แก้ไข / ยกเลิกเอกสาร')
@section('page-title', 'ฟอร์มขอเปิด / แก้ไข / ยกเลิกเอกสาร')

@section('content')
    @php
        $action = $action ?? '#';

        $form = $form ?? null;
        $readonly = ($readonly ?? false) === true;
        $currentStep = (int) data_get($form, 'current_step_no', 1);
        //dd($form);
        $fmt = function ($d) {
            return $d ? \Carbon\Carbon::parse($d)->format('d/m/Y') : '';
        };
        $selected = old('req_type', $WocrData->req_type ?? null); // 'open_new' | 'modify_mfg' | 'cancel_mfg'

        //dd($history);

    @endphp
    @php
        $urgency = (int) data_get($WocrData, 'urgency', 0);
        $urgencyLabel = \App\Models\FormWOCR\WocrData::urgencyText($urgency);
        $urgencyClass = [1 => 'text-bg-secondary', 2 => 'text-bg-warning', 3 => 'text-bg-danger'][$urgency] ?? 'text-bg-light';
    @endphp
    <div class="alert alert-info">
        <div><strong>Document Number:</strong> {{ data_get($WocrData, 'docu_no', '-') }}</div>
        <div>
            <strong>ความเร่งด่วน:</strong>
            <span class="badge {{ $urgencyClass }}">{{ $urgencyLabel }}</span>
        </div>
        <div><strong>Requested By:</strong> {{ data_get($form, 'requester.name', '-') }}</div>
        <div><strong>Requested At:</strong> {{ optional(data_get($form, 'request_dt'))->format('d/m/Y H:i') }}</div>
        <div>
            @php $step = (int) data_get($form, 'current_step_no'); @endphp
            <strong>สถานะ:</strong>
            <span class="badge text-bg-warning">
                Step {{ $step ?: '-' }}:
                @switch($step)
                    @case(999)
                        ปิดเอกสารแล้ว
                    @break

                    @case(998)
                        ยกเลิกเอกสารแล้ว
                    @break

                    @case(2)
                        รอ Planner อนุมัติ
                    @break

                    @case(3)
                        รอหัวหน้า Planner อนุมัติ
                    @break

                    @default
                        —
                @endswitch
            </span>
        </div>
    </div>
    <div class="sheet">
        {{-- <div class="top-actions mb-2 d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-sm btn-light" onclick="window.print()">พิมพ์ / Print</button>
        </div> --}}


        <form method="POST" id="requestForm" action="{{ route('wocr.planner_action', ['id' => $form->id ?? $WocrData->form_id]) }}">
            @csrf
            <div class="head-bar mb-3">
                <div class="form-title">{{ $form->title ?? 'แบบฟอร์มขอแก้ไข / เปิดใหม่ / ยกเลิกใบคำสั่งผลิต' }} </div>
                <div class="form-no">Form No: <input name="form_no" readonly
                        value="{{ old('form_no', $form->form_no ?? '') }}"
                        class="form-control form-control-sm d-inline-block" style="width:120px"></div>
            </div>

            {{-- ตารางรายการ --}}
            <div class="d-flex justify-content-between align-items-center mb-1">
                <div class="fw-semibold">ส่วนของผู้ขอดำเนินการ</div>
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
                            <th>รายละเอียด</th>

                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $idx => $row)
                            <tr data-row>
                                <td><input name="items[{{ $idx }}][mfg_no]" value="{{ $row['mfg_no'] }}"
                                        placeholder="เช่น W2501246" class="form-control form-control-sm" readonly></td>
                                <td><input name="items[{{ $idx }}][grade]" value="{{ $row['grade'] }}"
                                        placeholder="เช่น 420J2" class="form-control form-control-sm" readonly></td>
                                <td><input name="items[{{ $idx }}][type]" value="{{ $row['type'] }}"
                                        placeholder="เช่น 420J2" class="form-control form-control-sm" readonly>
                                </td>
                                <td><input name="items[{{ $idx }}][size]" value="{{ $row['size'] }}"
                                        placeholder="เช่น 3.20" class="form-control form-control-sm" readonly></td>
                                <td><input name="items[{{ $idx }}][length]" value="{{ $row['length'] }}"
                                        placeholder="เช่น 2500.00" class="form-control form-control-sm" inputmode="decimal"
                                        readonly>
                                </td>
                                <td><input name="items[{{ $idx }}][qty]" value="{{ $row['qty'] }}"
                                        placeholder="-" class="form-control form-control-sm" inputmode="decimal" readonly>
                                </td>
                                <td>
                                    <textarea name="items[{{ $idx }}][remark]" rows="2" class="form-control form-control-sm" readonly
                                        placeholder="รายละเอียด เช่น เปลี่ยนแปลงน้ำมันเป็น D381FW ...">{{ $row['remark'] }}</textarea>
                                </td>

                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>


            {{-- สาเหตุ --}}
            <div class="mb-2 mt-1 fw-semibold">สาเหตุ</div>
            <div class="mb-5">
                <textarea name="reason" rows="3" class="form-control" placeholder="อธิบายเหตุผล" readonly>{{ $history[0]->comment ?? ($history[1]->comment ?? '') }}</textarea>
            </div>

            {{-- ลายเซ็น ผู้ขอ / ผู้อนุมัติ --}}
            <div class="row g-3 sig-row">
                <div class="col-md-6">
                    <div class="mb-2">ผู้ขอดำเนินการ</div>
                    <div class="row g-2 align-items-center">
                        <div class="col-3 text-end">ชื่อ :</div>
                        <div class="col-9"><input name="actor_name" class="form-control" readonly
                                value="{{ old('actor_name', $history[0]->actor_name ?? '') }}"></div>
                        <div class="col-3 text-end">วันที่ :</div>
                        <div class="col-9"><input name="created_at" class="form-control" readonly
                                value="{{ old('created_at', $history[0]->created_at ?? '') }}"></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-2">ผู้อนุมัติ (หัวหน้าหรือผู้จัดการ)</div>
                    <div class="row g-2 align-items-center">
                        <div class="col-3 text-end">ชื่อ :</div>
                        <div class="col-9"><input name="approver_name" class="form-control" readonly
                                value="{{ old('approver_name', $form->approver_name ?? '') }}"></div>
                        <div class="col-3 text-end">วันที่ :</div>
                        <div class="col-9"><input name="approver_date" class="form-control" readonly
                                value="{{ old('approver_date', $form->approver_date ?? '') }}"></div>
                    </div>
                </div>
            </div>

            {{-- ส่วนของผู้เปิดใบคำสั่งผลิต --}}
            <div class="border rounded-3 p-3 mt-2 mb-3">
                <div class="fw-semibold mb-2">ส่วนของผู้เปิดใบคำสั่งผลิต</div>
                <div class="row g-3">
                    <div class="col-md-5 readonly">
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="radio" name="req_type" id="req_type_open"
                                value="open_new" @checked($selected == 1) disabled readonly>
                            <label for="req_type_open" class="form-check-label">ขอให้เปิดใบคำสั่งผลิตใหม่</label>
                        </div>

                        <div class="form-check mb-1">
                            <input class="form-check-input" type="radio" name="req_type" id="req_type_modify"
                                value="modify_mfg" @checked($selected == 2) disabled readonly>
                            <label for="req_type_modify" class="form-check-label">ขอให้แก้ไขใบคำสั่งผลิต</label>
                        </div>

                        <div class="form-check mb-1">
                            <input class="form-check-input" type="radio" name="req_type" id="req_type_cancel"
                                value="cancel_mfg" @checked($selected == 3) disabled readonly>
                            <label for="req_type_cancel" class="form-check-label">ยกเลิกใบคำสั่งผลิต</label>
                        </div>
                    </div>
                    {{--    <div class="col-md-7">
                        <div class="row g-2 align-items-center">
                            <div class="col-3 text-end">ลงชื่อ :</div>
                            <div class="col-9"><input name="opener_name" class="form-control" readonly
                                    value="{{ old('opener_name', $form->opener_name ?? '') }}"></div>
                            <div class="col-3 text-end">Mfg No. :</div>
                            <div class="col-9"><input name="open_mfg_no" class="form-control" readonly
                                    value="{{ old('open_mfg_no', $form->open_mfg_no ?? '') }}"
                                    placeholder="กรอกเมื่อเปิดใหม่แล้ว"></div>
                            <div class="col-5 text-end">วันที่ดำเนินการเสร็จ :</div>
                            <div class="col-7"><input type="date" name="completed_date" class="form-control"
                                    readonly value="{{ old('completed_date', $form->completed_date ?? '') }}"></div>
                        </div>
                    </div> --}}
                </div>
            </div>



            <div class="d-flex justify-content-between align-items-center">
                <div class="doc-code">{{ $form->doc_code ?? 'PC-06 Rev.01' }}</div>
            </div>

            {{-- ==== ไฟล์แนบจากผู้ขอ/Wocr (wocr_data_files) ==== --}}
            @if (isset($attachments) && $attachments->count())
                <div class="card shadow-sm mb-3">
                    <div class="card-header fw-semibold">
                        ไฟล์แนบ ({{ $attachments->count() }} ไฟล์)
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            @foreach ($attachments as $f)
                                <div class="col-md-6 col-lg-4">
                                    <div class="border rounded-3 p-2 h-100 d-flex gap-2">
                                        <div class="text-center" style="width:38px">
                                            <i class="fa {{ $f->icon }} fa-lg text-secondary"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="fw-semibold text-truncate" title="{{ $f->original_name }}">
                                                {{ $f->original_name ?? $f->file_name }}
                                            </div>
                                            <div class="small text-muted">
                                                {{ $f->size_human }} · {{ $f->file_type }}
                                            </div>

                                            <div class="mt-1 d-flex gap-2">
                                                <a href="{{ route('wocr.files.download', ['id' => $f->id]) }}"
                                                    class="btn btn-sm btn-outline-secondary">
                                                    ดาวน์โหลด
                                                </a>
                                            </div>

                                            @if (Str::startsWith(strtolower($f->file_type), 'image/'))
                                                <div class="mt-2">
                                                    <img src="{{ $f->url }}" alt=""
                                                        class="img-fluid rounded border"
                                                        style="max-height:160px; object-fit:contain;">
                                                </div>
                                            @elseif(Str::contains(strtolower($f->file_type), 'pdf'))
                                                <div class="mt-2 small">
                                                    <i class="fa fa-file-pdf me-1"></i> ไฟล์ PDF
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
            @endif

            <div class="container px-4">
                <div class="mb-1 fw-semibold">Comment</div>
                <div class="mb-3">
                    <textarea name="reason" rows="3" class="form-control"></textarea>
                </div>

                <div class="d-flex justify-content-between align-items-center">
                    @if ($canApprove)
                        <div class="d-flex gap-2">
                            <button type="submit" name="action" value="approve" class="btn btn-success">
                                <i class="fas fa-check me-1"></i> อนุมัติ
                            </button>
                            <button type="submit" name="action" value="reject" class="btn btn-danger">
                                <i class="fa fa-times"></i> Reject
                            </button>
                        </div>
                    @endif
                </div>
            </div>

        </form>


    </div>



    @if ($canApprove == false)
        <div class="container px-4">
            {{-- รูป Step --}}
            <div class="row g-3 mt-4">
                <div class="col-md-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header fw-semibold">Workflow Diagram</div>
                        <div class="card-body text-center">
                            <img src="{{ asset("formpp/{$currentStep}.png") }}"
                                class="img-fluid border rounded shadow-sm p-2" style="max-width: auto; height:500px;">
                        </div>
                    </div>
                </div>

                {{-- History --}}
                <div class="col-md-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header fw-semibold">Workflow History</div>
                        <div class="card-body ps-4 ps-md-5">
                            @if ($history->count())
                                <ul class="wf-timeline list-unstyled">
                                    @foreach ($history as $h)
                                        @php
                                            $action = strtolower($h->action_type ?? '');
                                            $dotClass = match ($action) {
                                                'submit' => 'is-submit',
                                                'approve' => 'is-approve',
                                                'reject' => 'is-reject',
                                                default => '',
                                            };
                                        @endphp
                                        <li class="wf-item">
                                            <span class="wf-rail"></span>
                                            <span class="wf-dot {{ $dotClass }}">{{ $h->step_no }}</span>

                                            <div class="wf-title">{{ ucfirst($action) }}</div>
                                            <div class="wf-meta">
                                                {{ \Carbon\Carbon::parse($h->created_at)->format('d/m/Y H:i') }}
                                                · Step {{ $h->step_no }}
                                                @if ($h->actor_name)
                                                    · by {{ $h->actor_name }}
                                                @endif
                                            </div>

                                            @if ($h->comment)
                                                <div class="wf-comment text-dark">{{ $h->comment }}</div>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <div class="text-muted">ไม่มีประวัติการทำรายการ</div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

    @endif
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


        /* Timeline ปรับ balance ระหว่าง dot / rail / text */
        .wf-timeline {
            position: relative;
            padding-left: 65px;
            /* เว้นให้ข้อความไม่ชนวงกลม */
            margin: 0;
        }

        .wf-item {
            position: relative;
            padding: 16px 0 18px;
            /* เพิ่ม padding บนล่างให้หายแน่น */
            border-bottom: 1px dashed var(--bs-border-color);
        }

        .wf-item:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        /* ขนาดจุด */
        :root {
            --wf-dot-size: 22px;
            --wf-dot-left: 28px;
            /* ขยับจุดไปทางซ้าย */
        }

        /* rail ผ่านกลางจุด */
        .wf-rail {
            position: absolute;
            left: -64px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--bs-border-color);
        }

        .wf-item:last-child .wf-rail {
            background: linear-gradient(180deg, var(--bs-border-color), transparent);
        }

        /* วงกลม */
        .wf-dot {
            position: absolute;
            left: -73px;
            top: 2px;
            width: var(--wf-dot-size);
            height: var(--wf-dot-size);
            border-radius: 50%;
            background: var(--bs-body-bg);
            border: 2px solid var(--bs-border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
        }

        /* ข้อความ */
        .wf-title {
            font-weight: 600;
        }

        .wf-meta {
            font-size: .85rem;
            color: var(--bs-secondary-color);
        }

        .wf-comment {
            font-size: .9rem;
            margin-top: .25rem;
        }

        /* สี dot */
        .wf-dot.is-submit {
            border-color: #0d6efd;
            color: #0d6efd;
        }

        .wf-dot.is-approve {
            border-color: #198754;
            color: #198754;
        }

        .wf-dot.is-reject {
            border-color: #dc3545;
            color: #dc3545;
        }

        /* ทำให้ดูเรียบร้อยขึ้น */
        .ts-wrapper.form-select.form-select-lg .ts-control {
            border-radius: .75rem;
            /* rounded-lg แทน pill */
            min-height: calc(1.6em + 1rem + 2px);
            padding: .5rem .75rem;
        }

        .ts-wrapper .item {
            /* ชิปที่ถูกเลือก */
            padding: .2rem .5rem;
            border-radius: 9999px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            margin-right: .25rem;
        }

        .ts-dropdown {
            max-height: 320px;
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
@endsection
