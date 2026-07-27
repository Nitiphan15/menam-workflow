@extends('layouts.layout')

@section('title', 'PO Detail')
@section('page-title', 'PO Detail')

@section('content')
    <style>
        .po-approval-sidebar .card {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
        }

        .po-approval-sidebar .card-header {
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            padding: 10px 14px;
        }

        .po-approval-sidebar .card-body {
            padding: 12px 14px;
        }

        .po-approval-sidebar .alert {
            border-radius: 8px;
            padding: 12px;
        }

        .po-approval-chip {
            border-radius: 999px;
            padding: 0.2rem 0.55rem;
            background: #e2e8f0;
            color: #334155;
            font-size: 0.74rem;
            font-weight: 700;
        }

        .po-person-card,
        .po-timeline-card,
        .po-sign-card {
            border: 1px solid #dbe4f0;
            border-radius: 9px;
            background: #fff;
            padding: 12px;
        }

        .po-person-card + .po-person-card,
        .po-timeline-card + .po-timeline-card,
        .po-sign-card + .po-sign-card {
            margin-top: 8px;
        }

        .po-person-avatar {
            width: 34px;
            height: 34px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #dbeafe;
            color: #1d4ed8;
            font-weight: 800;
            flex: 0 0 auto;
        }

        .po-sign-img {
            max-width: 180px;
            max-height: 58px;
        }

        .po-cpa-card {
            border: 1px solid #dbe4f0;
            border-radius: 10px;
            background: #f8fafc;
        }

        .po-help-dot {
            width: 18px;
            height: 18px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #dbeafe;
            color: #1d4ed8;
            font-size: 0.72rem;
            font-weight: 800;
            cursor: help;
        }

        .po-doc-card .form-control[readonly],
        .po-doc-card textarea[readonly] {
            background: #f8fafc;
            border-color: #dbe4f0;
            color: #0f172a;
            font-weight: 600;
        }

        .po-lines-card .table thead th,
        .po-attach-card .table thead th {
            background: #f8fafc;
            color: #334155;
            font-size: 0.84rem;
        }

        .po-pdf-edit-card {
            border: 1px solid #dbe4f0;
            border-radius: 10px;
        }

        .po-pdf-edit-card .form-text {
            color: #64748b;
        }
    </style>

    <div class="container py-3">
        @if (session('ok'))
            <div class="alert alert-success">{{ session('ok') }}</div>
        @endif
        @if ($errors->has('erp_phpsessid'))
            <div class="alert alert-danger">{{ $errors->first('erp_phpsessid') }}</div>
        @endif

        @can('POPUR')
            <div class="card po-cpa-card shadow-sm mb-3">
                <div class="card-body d-flex flex-wrap align-items-end gap-2">
                    <form method="POST" action="{{ route('po.erp.connect') }}" class="d-flex flex-wrap align-items-end gap-2">
                        @csrf
                        <div>
                            <label class="form-label small text-muted mb-1">
                                CPA Username
                                <span class="po-help-dot" data-bs-toggle="tooltip" data-bs-placement="top"
                                    title="ใช้บัญชี CPA เพื่อสร้าง session สำหรับดึง PDF ต้นฉบับจาก CPA ก่อนระบบจะแปะลายเซ็นและข้อมูล workflow เพิ่ม">?</span>
                            </label>
                            <input type="text" name="erp_username" class="form-control form-control-sm" style="min-width: 150px;"
                                autocomplete="username">
                        </div>
                        <div>
                            <label class="form-label small text-muted mb-1">CPA Password</label>
                            <input type="password" name="erp_password" class="form-control form-control-sm" style="min-width: 150px;"
                                autocomplete="current-password">
                        </div>
                        <div>
                            <label class="form-label small text-muted mb-1">Company</label>
                            <select name="erp_dataset" class="form-select form-select-sm">
                                <option value="msw">Menam Stainless Wire PCL</option>
                                <option value="mswplus">Menam Plus</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary">Login CPA</button>
                    </form>
                    <form method="POST" action="{{ route('po.erpSession.save') }}" class="d-flex flex-wrap align-items-end gap-2">
                        @csrf
                        <div>
                            <label class="form-label small text-muted mb-1">
                                CPA PHPSESSID
                                <span class="po-help-dot" data-bs-toggle="tooltip" data-bs-placement="top"
                                    title="กรณี login CPA ผ่านหน้าเว็บหลักไว้แล้ว สามารถนำค่า PHPSESSID มาใส่เพื่อให้ระบบดาวน์โหลด PDF ได้">?</span>
                            </label>
                            <input type="text" name="erp_phpsessid" value="{{ session('po_erp_phpsessid') }}"
                                class="form-control form-control-sm" style="min-width: 280px;"
                                placeholder="Paste CPA PHPSESSID or full Cookie header">
                        </div>
                        <button type="submit" class="btn btn-sm btn-outline-primary">Save CPA Session</button>
                    </form>
                    <form method="POST" action="{{ route('po.erpSession.clear') }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Clear CPA Session</button>
                    </form>
                </div>
            </div>
        @endcan

        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
            <div>
                <h4 class="mb-1">{{ $po->ordnumber }}</h4>
                <div class="text-muted">{{ $po->vendor_name }} | {{ $po->f1 ?: 'ไม่ระบุแผนก' }}</div>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('po.print', $po->id) }}" class="btn btn-outline-dark">Download PDF</a>
                <a href="{{ route('po.index') }}" class="btn btn-outline-secondary">กลับหน้ารายการ</a>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card po-doc-card shadow-sm mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center gap-2">
                        <strong>ข้อมูลเอกสาร</strong>
                        <span class="badge text-bg-light">{{ $po->status_code }}</span>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">PO No.</label>
                                <input class="form-control" value="{{ $po->ordnumber }}" readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Invoice No.</label>
                                <input class="form-control" value="{{ $po->invnumber }}" readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <input class="form-control" value="{{ $po->status_code }}" readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Source</label>
                                <input class="form-control" value="{{ \App\Services\Po\PoErpService::sourceLabel($po->site) }}" readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">PO Date</label>
                                <input class="form-control" value="{{ optional($po->transdate)->format('d-M-Y') }}"
                                    readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Request Date</label>
                                <input class="form-control" value="{{ optional($po->reqdate)->format('d-M-Y') }}" readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Currency</label>
                                <input class="form-control" value="{{ $po->curr }}" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Vendor</label>
                                <input class="form-control" value="{{ $po->vendor_name }}" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Requester</label>
                                <input class="form-control" value="{{ $po->requester_name }}" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Department</label>
                                <input class="form-control" value="{{ $po->f1 }}" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Payment Term</label>
                                <input class="form-control" value="{{ $po->terms }}" readonly>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" rows="3" readonly>{{ $po->notes }}</textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card po-lines-card shadow-sm mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center gap-2">
                        <strong>รายการสินค้า</strong>
                        <span class="badge text-bg-light">{{ number_format($detailRows->count()) }} lines</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 70px;">No.</th>
                                    <th>Description</th>
                                    <th class="text-end" style="width: 140px;">Qty</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($detailRows as $index => $row)
                                    <tr>
                                        <td class="text-center">{{ $index + 1 }}</td>
                                        <td>{{ $row->description }}</td>
                                        <td class="text-end">{{ number_format((float) $row->qty, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center text-muted">ไม่พบรายการย่อยจาก ERP</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @php
                    $pdfDescriptionOverrideText = collect(old('pdf_description_overrides', $po->pdf_description_overrides ?? []))
                        ->map(fn ($value) => trim((string) $value))
                        ->filter()
                        ->implode("\n");
                    $pdfCommentsOverride = old('pdf_comments_override', $po->pdf_comments_override ?? '');
                    $hasPdfTextOverride = $pdfDescriptionOverrideText !== ''
                        || trim((string) $pdfCommentsOverride) !== '';
                @endphp
                <div class="card po-pdf-edit-card shadow-sm mb-3">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <strong>เพิ่มข้อความใน PDF</strong>
                            <span class="badge {{ $hasPdfTextOverride ? 'text-bg-warning' : 'text-bg-light' }}">
                                {{ $hasPdfTextOverride ? 'มีข้อความเพิ่ม' : 'ตามต้นฉบับ CPA' }}
                            </span>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" data-po-edit-focus="pdf-description">
                                เพิ่มข้อความ Description
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-po-edit-focus="pdf-comments">
                                เพิ่มข้อความ Comments
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('po.update', $po->id) }}">
                            @csrf
                            @method('PUT')

                            <div class="alert alert-light border mb-3">
                                ข้อความที่กรอกจะถูกพิมพ์<strong>เพิ่มต่อท้าย</strong>ข้อความเดิมของ CPA ใน PDF ตอน Download
                                (ข้อความเดิมยังอยู่ครบ ไม่ถูกแทนที่); เว้นว่าง = ไม่เพิ่มอะไร
                            </div>

                            <div class="mb-3">
                                <label class="form-label">เพิ่มต่อท้าย Description</label>
                                <textarea name="pdf_description_overrides[0]"
                                    class="form-control"
                                    rows="3"
                                    data-po-edit-target="pdf-description"
                                    {{ $canEditPdfOverride ? '' : 'readonly' }}>{{ $pdfDescriptionOverrideText }}</textarea>
                                <div class="form-text">จะพิมพ์ต่อใต้บรรทัดสุดท้ายของช่อง Description ในหน้าที่เลือก (ขึ้นบรรทัดใหม่ได้)</div>
                            </div>

                            <div class="mb-3" style="max-width: 260px;">
                                <label class="form-label">พิมพ์ Description ที่หน้า</label>
                                <input type="text" name="pdf_description_override_pages"
                                    class="form-control"
                                    placeholder="หน้าสุดท้าย"
                                    value="{{ old('pdf_description_override_pages', $po->pdf_description_override_pages) }}"
                                    {{ $canEditPdfOverride ? '' : 'readonly' }}>
                                <div class="form-text">เช่น 2 หรือ 1,3 หรือ 1-3 หรือ all (ทุกหน้า); เว้นว่าง = หน้าสุดท้าย</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">เพิ่มต่อท้าย Comments</label>
                                <textarea name="pdf_comments_override"
                                    class="form-control"
                                    rows="3"
                                    data-po-edit-target="pdf-comments"
                                    {{ $canEditPdfOverride ? '' : 'readonly' }}>{{ $pdfCommentsOverride }}</textarea>
                                <div class="form-text">จะพิมพ์ต่อจากบรรทัดสุดท้ายของช่อง Comments ใน PDF (แยกจากหมายเหตุไฟล์แนบ)</div>
                            </div>

                            <div class="mb-3" style="max-width: 260px;">
                                <label class="form-label">พิมพ์ Comments ที่หน้า</label>
                                <input type="text" name="pdf_comments_override_pages"
                                    class="form-control"
                                    placeholder="หน้าสุดท้าย"
                                    value="{{ old('pdf_comments_override_pages', $po->pdf_comments_override_pages) }}"
                                    {{ $canEditPdfOverride ? '' : 'readonly' }}>
                                <div class="form-text">เช่น 2 หรือ 1,3 หรือ 1-3 หรือ all (ทุกหน้า); เว้นว่าง = หน้าสุดท้าย</div>
                            </div>

                            @if ($canEditPdfOverride)
                                <button class="btn btn-primary">บันทึกข้อความบน PDF</button>
                            @else
                                <div class="alert alert-secondary mb-0">เอกสารเข้า workflow แล้ว แก้ข้อความ PDF ไม่ได้จนกว่าจะถูกตีกลับ</div>
                            @endif
                        </form>
                    </div>
                </div>

                <div class="card po-attach-card shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center gap-2">
                        <strong>Attached / เอกสารประกอบ</strong>
                        <span class="badge text-bg-light">{{ number_format($po->attachments->count()) }} files</span>
                    </div>
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <button type="button" class="btn btn-sm btn-outline-primary" data-po-edit-focus="notes">
                                แก้หมายเหตุ
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-po-edit-focus="files">
                                เพิ่มไฟล์แนบ
                            </button>
                        </div>

                        <form method="POST" action="{{ route('po.update', $po->id) }}" enctype="multipart/form-data">
                            @csrf
                            @method('PUT')

                            <div class="mb-3">
                                <label class="form-label">หมายเหตุเพิ่มเติม</label>
                                <textarea name="notes" class="form-control" rows="3" {{ $canEditAttachment ? '' : 'readonly' }}>{{ old('notes', $po->notes) }}</textarea>
                            </div>

                            @if ($canEditAttachment)
                                <div class="mb-3">
                                    <label class="form-label">เลือกไฟล์แนบ</label>
                                    <input type="file" name="files[]" class="form-control mb-2" multiple>
                                    <div class="form-text">รองรับหลายไฟล์ เช่น PR, ใบเสนอราคา หรือเอกสารประกอบอื่น ๆ</div>
                                </div>

                                @if ($po->attachments->isEmpty())
                                    <div class="alert alert-warning">
                                        ยังไม่มีไฟล์แนบ เอกสารจะยังส่งเข้า workflow ไม่ได้จนกว่าจะมี attachment อย่างน้อย 1
                                        ไฟล์
                                    </div>
                                @endif

                                <button class="btn btn-primary">บันทึกเอกสาร</button>
                            @else
                                <div class="alert alert-secondary mb-0">เอกสารถูกส่งเข้า workflow แล้ว
                                    แก้ไขไฟล์แนบไม่ได้จนกว่าจะถูกตีกลับ</div>
                            @endif
                        </form>

                        <hr>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>File</th>
                                        <th>Remark</th>
                                        <th>Uploaded By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($po->attachments as $attachmentIndex => $attachment)
                                        @php
                                            $attachmentExt =
                                                $attachment->file_ext ?:
                                                pathinfo((string) $attachment->file_name, PATHINFO_EXTENSION);
                                            $fallbackName = sprintf(
                                                '%s_%02d%s',
                                                $po->ordnumber,
                                                $attachmentIndex + 1,
                                                $attachmentExt ? ".{$attachmentExt}" : '',
                                            );
                                            $displayFileName = str_starts_with(
                                                (string) $attachment->file_name,
                                                (string) $po->ordnumber,
                                            )
                                                ? $attachment->file_name
                                                : $fallbackName;
                                        @endphp
                                        <tr>
                                            <td><a href="{{ route('po.attachments.show', [$po->id, $attachment->id]) }}"
                                                    target="_blank">{{ $displayFileName }}</a></td>
                                            <td>{{ $attachment->remark ?: '-' }}</td>
                                            <td>{{ $attachment->creator?->name ?: ($attachment->created_by ?: '-') }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="text-center text-muted">ยังไม่มีไฟล์แนบ</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 po-approval-sidebar">
                <div class="card shadow-sm mb-3">
                    <div class="card-header"><strong>Workflow</strong></div>
                    <div class="card-body">
                        @if ($canSubmit)
                            <form method="POST" action="{{ route('po.submit', $po->id) }}">
                                @csrf
                                <div class="mb-3">
                                    <label class="form-label">Comment</label>
                                    <textarea name="comment" class="form-control" rows="3" placeholder="หมายเหตุสำหรับการส่งเข้า workflow"></textarea>
                                </div>
                                <button class="btn btn-success w-100">ส่งเข้าระบบอนุมัติ</button>
                            </form>
                        @elseif (blank($po->workflow_id) && $po->attachments->isEmpty())
                            <div class="alert alert-warning mb-0">เอกสารนี้ยังไม่มี attachment
                                จึงยังส่งเข้าระบบอนุมัติไม่ได้</div>
                        @elseif ($canApprove)
                            <form method="POST" action="{{ route('po.approve', $po->id) }}" class="mb-2">
                                @csrf
                                <label class="form-label">Approve Comment</label>
                                <textarea name="comment" class="form-control mb-3" rows="3"></textarea>
                                <button class="btn btn-primary w-100">Approve</button>
                            </form>

                            <button class="btn btn-outline-danger w-100" data-bs-toggle="modal"
                                data-bs-target="#rejectModal">
                                Send Back / Reject
                            </button>
                        @elseif ($canReopen)
                            <div class="alert alert-warning">
                                PO นี้อนุมัติครบแล้ว หากราคาเปลี่ยน สามารถยกเลิกผลอนุมัติเดิมเพื่อกลับไปแก้ไขไฟล์แนบ
                                แล้วส่งอนุมัติใหม่ตั้งแต่ต้นได้
                            </div>
                            <form method="POST" action="{{ route('po.reopen', $po->id) }}"
                                onsubmit="return confirm('ยืนยันยกเลิกผลอนุมัติเดิมและกลับไปแก้ไขไฟล์แนบใช่หรือไม่?')">
                                @csrf
                                <label class="form-label">เหตุผลที่ต้องอนุมัติใหม่</label>
                                <textarea name="comment" class="form-control mb-3" rows="3" required
                                    maxlength="1000" placeholder="เช่น มีการแก้ไขราคา"></textarea>
                                <button class="btn btn-outline-danger w-100">
                                    ยกเลิกผลอนุมัติและกลับไปแก้ไฟล์
                                </button>
                            </form>
                        @else
                            <div class="alert alert-info mb-0">เอกสารนี้ยังไม่มี action ที่ต้องทำสำหรับผู้ใช้ปัจจุบัน</div>
                        @endif
                    </div>
                </div>

                <div class="card shadow-sm mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center gap-2">
                        <strong>Waiting Approval</strong>
                        <span class="po-approval-chip">{{ $pendingApprovers->count() }} pending</span>
                    </div>
                    <div class="card-body">
                        @if ($po->workflow_id && $pendingApprovers->isNotEmpty())
                            <div class="small text-muted mb-2">
                                Current Step:
                                {{ $currentStepName ?: 'Step ' . ($po->workflow?->current_step_no ?? '-') }}
                            </div>
                            @foreach ($pendingApprovers as $approver)
                                <div class="po-person-card d-flex gap-3 align-items-start">
                                    <div class="po-person-avatar">{{ mb_substr((string) $approver->name, 0, 1) }}</div>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold">{{ $approver->name }}</div>
                                        <div class="small text-muted">{{ $approver->email ?: '-' }}</div>
                                        <div class="small text-muted">Step {{ $approver->step_no }}</div>
                                    </div>
                                </div>
                            @endforeach
                        @elseif ($po->workflow_id)
                            <div class="alert alert-light mb-0">ไม่พบรายชื่อผู้อนุมัติที่กำลังรอดำเนินการ</div>
                        @else
                            <div class="alert alert-light mb-0">เอกสารนี้ยังไม่ได้ส่งเข้า workflow</div>
                        @endif
                    </div>
                </div>

                <div class="card shadow-sm mb-3">
                    <div class="card-header"><strong>Workflow Comment / Remark</strong></div>
                    <div class="card-body">
                        @forelse ($workflowRemarks as $remark)
                            @php
                                $action = strtoupper((string) $remark->action_type);
                                $badgeClass = match ($action) {
                                    'APPROVE', 'APPROVED' => 'text-bg-success',
                                    'REJECT', 'REJECTED', 'RETURN', 'CANCEL', 'CANCELLED' => 'text-bg-danger',
                                    'SUBMIT', 'PENDING' => 'text-bg-primary',
                                    'REOPEN', 'SKIP', 'SKIPPED' => 'text-bg-warning',
                                    'COMPLETE', 'CLOSED' => 'text-bg-secondary',
                                    default => 'text-bg-primary',
                                };
                            @endphp
                            <div class="po-timeline-card">
                                <div class="d-flex justify-content-between gap-2">
                                    <span class="badge {{ $badgeClass }}">{{ $action ?: '-' }}</span>
                                    <span class="small text-muted">
                                        {{ !empty($remark->created_at) ? \Illuminate\Support\Carbon::parse($remark->created_at)->format('d-M-Y H:i') : '-' }}
                                    </span>
                                </div>
                                <div class="small text-muted mt-2">Step {{ $remark->step_no }} |
                                    {{ $remark->actor_name ?: '-' }}</div>
                                <div class="mt-2">{{ trim((string) $remark->comment) !== '' ? $remark->comment : '-' }}
                                </div>
                            </div>
                        @empty
                            <div class="alert alert-light mb-0">ยังไม่มี comment หรือ reject remark</div>
                        @endforelse
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-header"><strong>Signature Preview</strong></div>
                    <div class="card-body">
                        @php
                            $signatureCards = [
                                [
                                    'label' => 'Ordered by',
                                    'name' => collect($signatures['ordered_by'] ?? [])
                                        ->pluck('actor_name')
                                        ->filter(fn($name) => trim((string) $name) !== '')
                                        ->implode(' / '),
                                    'image' => collect($signatures['ordered_by'] ?? [])
                                        ->pluck('signature_data_uri')
                                        ->filter()
                                        ->first(),
                                    'date' => collect($signatures['ordered_by'] ?? [])
                                        ->pluck('created_at')
                                        ->filter()
                                        ->map(fn($date) => \Illuminate\Support\Carbon::parse($date)->format('d-M-Y'))
                                        ->implode(' / '),
                                ],
                                [
                                    'label' => 'Authorized by',
                                    'name' => $signatures['authorized_by']->actor_name ?? '',
                                    'image' => $signatures['authorized_by']->signature_data_uri ?? null,
                                    'date' => !empty($signatures['authorized_by']?->created_at)
                                        ? \Illuminate\Support\Carbon::parse(
                                            $signatures['authorized_by']->created_at,
                                        )->format('d-M-Y')
                                        : '',
                                ],
                            ];
                        @endphp

                        @foreach ($signatureCards as $card)
                            <div class="po-sign-card">
                                <div class="d-flex justify-content-between align-items-center gap-2">
                                    <div class="fw-semibold">{{ $card['label'] }}</div>
                                    <span class="badge {{ $card['date'] !== '' ? 'text-bg-success' : 'text-bg-light' }}">
                                        {{ $card['date'] !== '' ? 'Signed' : 'Pending' }}
                                    </span>
                                </div>
                                <div class="mt-2">
                                    @if (!empty($card['image']))
                                        <img src="{{ $card['image'] }}" alt="{{ $card['label'] }}" class="po-sign-img">
                                    @else
                                        {{ $card['name'] !== '' ? $card['name'] : '................................' }}
                                    @endif
                                </div>
                                <div class="small text-muted mt-1">
                                    Date: {{ $card['date'] !== '' ? $card['date'] : '........................' }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form class="modal-content" method="POST" action="{{ route('po.reject', $po->id) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">เหตุผลการตีกลับ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <textarea name="comment" class="form-control" rows="4" required></textarea>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-danger">ยืนยัน</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        (() => {
            if (window.bootstrap?.Tooltip) {
                document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
                    bootstrap.Tooltip.getOrCreateInstance(el);
                });
            }

            const focusTarget = (selector) => {
                const target = document.querySelector(selector);
                if (!target) return;

                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                window.setTimeout(() => target.focus({ preventScroll: true }), 250);
            };

            document.querySelector('[data-po-edit-focus="notes"]')?.addEventListener('click', () => {
                focusTarget('textarea[name="notes"]');
            });

            document.querySelector('[data-po-edit-focus="files"]')?.addEventListener('click', () => {
                focusTarget('input[name="files[]"]');
            });

            document.querySelector('[data-po-edit-focus="pdf-description"]')?.addEventListener('click', () => {
                focusTarget('[data-po-edit-target="pdf-description"]');
            });

            document.querySelector('[data-po-edit-focus="pdf-comments"]')?.addEventListener('click', () => {
                focusTarget('[data-po-edit-target="pdf-comments"]');
            });
        })();
    </script>
@endsection
