@extends('layouts.layout')

@section('title', 'PO Detail')
@section('page-title', 'PO Detail')

@section('content')
    <div class="container py-3">
        @if (session('ok'))
            <div class="alert alert-success">{{ session('ok') }}</div>
        @endif

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
                <div class="card shadow-sm mb-3">
                    <div class="card-header"><strong>ข้อมูลเอกสาร</strong></div>
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

                <div class="card shadow-sm mb-3">
                    <div class="card-header"><strong>รายการสินค้า</strong></div>
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

                <div class="card shadow-sm">
                    <div class="card-header"><strong>Attached / เอกสารประกอบ</strong></div>
                    <div class="card-body">
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
                                            <td><a href="{{ Storage::disk('public')->url($attachment->file_path) }}"
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

            <div class="col-lg-4">
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
                        @else
                            <div class="alert alert-info mb-0">เอกสารนี้ยังไม่มี action ที่ต้องทำสำหรับผู้ใช้ปัจจุบัน</div>
                        @endif
                    </div>
                </div>

                <div class="card shadow-sm mb-3">
                    <div class="card-header"><strong>Waiting Approval</strong></div>
                    <div class="card-body">
                        @if ($po->workflow_id && $pendingApprovers->isNotEmpty())
                            <div class="small text-muted mb-2">
                                Current Step:
                                {{ $currentStepName ?: 'Step ' . ($po->workflow?->current_step_no ?? '-') }}
                            </div>
                            @foreach ($pendingApprovers as $approver)
                                <div class="border rounded p-3 mb-2">
                                    <div class="fw-semibold">{{ $approver->name }}</div>
                                    <div class="small text-muted">{{ $approver->email ?: '-' }}</div>
                                    <div class="small text-muted">Step {{ $approver->step_no }}</div>
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
                                    'SKIP', 'SKIPPED' => 'text-bg-warning',
                                    'COMPLETE', 'CLOSED' => 'text-bg-secondary',
                                    default => 'text-bg-primary',
                                };
                            @endphp
                            <div class="border rounded p-3 mb-2">
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
                                        ->pluck('signature_url')
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
                                    'image' => $signatures['authorized_by']->signature_url ?? null,
                                    'date' => !empty($signatures['authorized_by']?->created_at)
                                        ? \Illuminate\Support\Carbon::parse(
                                            $signatures['authorized_by']->created_at,
                                        )->format('d-M-Y')
                                        : '',
                                ],
                            ];
                        @endphp

                        @foreach ($signatureCards as $card)
                            <div class="border rounded p-3 mb-3">
                                <div class="fw-semibold">{{ $card['label'] }}</div>
                                <div class="mt-2">
                                    @if (!empty($card['image']))
                                        <img src="{{ $card['image'] }}" alt="{{ $card['label'] }}" style="max-width: 180px; max-height: 58px;">
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
@endsection
