@extends('layouts.layout')

@section('title', 'Request Form')
@section('page-title', 'Request Form')

@php
    // แปลงประเภทสินค้า (เก็บเป็น CSV เช่น "1,5")
    $types = collect(explode(',', (string) ($data->type ?? '')))->map(fn($x) => trim($x))->filter()->all();

    function isType($t, $arr)
    {
        return in_array((string) $t, $arr, true);
    }

    // map สถานะหลักให้อ่านง่าย
    $statusMap = [2 => 'REQUESTED', 999 => 'COMPLETED', 998 => 'VOID', 490 => 'REJECTED'];
    $statusLabel = $statusMap[$form->form_status] ?? $form->form_status;
@endphp

@section('content')
    <div class="container py-2">
        <div class="row g-4">
            {{-- LEFT: Form read-only --}}
            <div class="col-lg-8">
                {{-- ส่วนข้อมูลผู้ยื่น --}}
                <div class="card shadow-sm mb-3">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-send-plus me-2"></i><strong>ส่งคำขอใหม่</strong>
                        <span class="badge bg-success ms-2">ข้อมูลจากบัญชี</span>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">ชื่อ-นามสกุล</label>
                                <input class="form-control" value="{{ $requester->name ?? '-' }}" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">อีเมล</label>
                                <input class="form-control" value="{{ $requester->email ?? '-' }}" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">เบอร์โทรศัพท์ภายใน *</label>
                                <input class="form-control" value="{{ $requester->phone_ext ?? ($data->phone_ext ?? '-') }}"
                                    readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">แผนกที่สังกัด *</label>
                                <input class="form-control" value="{{ $data->department ?? '-' }}" readonly>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- รายละเอียดคำขอ --}}
                <div class="card shadow-sm mb-3">
                    <div class="card-header"><i class="bi bi-journal-text me-2"></i><strong>รายละเอียดคำขอ</strong></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">วันที่ขอซื้อ *</label>
                                <input class="form-control" value="{{ $data->req_date ?? '-' }}" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">วันที่ต้องการใช้งาน</label>
                                <input class="form-control" value="{{ $data->need_date ?? '' }}" readonly>
                            </div>

                            <div class="col-12">
                                <label class="form-label">บริษัทที่ต้องการติดต่อ</label>
                                <input class="form-control" value="{{ $data->contact_company ?? '' }}" readonly>
                            </div>

                            <div class="col-12">
                                <label class="form-label d-block">ประเภทของสินค้า *</label>

                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" disabled
                                                {{ isType(1, $types) ? 'checked' : '' }}>
                                            <label class="form-check-label">งาน Project สร้างเครื่องจักร</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" disabled
                                                {{ isType(2, $types) ? 'checked' : '' }}>
                                            <label class="form-check-label">อะไหล่</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" disabled
                                                {{ isType(3, $types) ? 'checked' : '' }}>
                                            <label class="form-check-label">ภาชนะ/บรรจุภัณฑ์</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" disabled
                                                {{ isType(4, $types) ? 'checked' : '' }}>
                                            <label class="form-check-label">งานซ่อมแซมเครื่องมือ/เครื่องจักร</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" disabled
                                                {{ isType(5, $types) ? 'checked' : '' }}>
                                            <label class="form-check-label">เบ็ดเตล็ด/ทั่วไป</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" disabled
                                                {{ isType(6, $types) ? 'checked' : '' }}>
                                            <label class="form-check-label">อื่น ๆ</label>
                                        </div>
                                    </div>
                                </div>
                                @if (!empty($data->type_other))
                                    <div class="mt-2">
                                        <input class="form-control" value="อื่น ๆ: {{ $data->type_other }}" readonly>
                                    </div>
                                @endif
                            </div>

                            {{-- ตารางรายการสินค้า --}}
                            <div class="col-12">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="form-label mb-0"><i class="bi bi-table me-1"></i>รายละเอียดสินค้า</label>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width:60px">No.</th>
                                                <th>รายละเอียดสินค้า</th>
                                                <th style="width:120px">จำนวน</th>
                                                <th style="width:120px">หน่วย</th>
                                                <th style="width:140px">ราคา</th>
                                                <th style="width:220px">จุดประสงค์การใช้งาน</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @forelse($lines as $i => $row)
                                                <tr>
                                                    <td class="text-center">{{ $row->seq_no ?? $i + 1 }}</td>
                                                    <td>{{ $row->detail }}</td>
                                                    <td class="text-end">
                                                        {{ rtrim(rtrim(number_format($row->qty, 2), '0'), '.') }}</td>
                                                    <td>{{ $row->unit }}</td>
                                                    <td class="text-end">{{ number_format($row->price, 2) }}</td>
                                                    <td>{{ $row->objective }}</td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted">ไม่มีรายการสินค้า</td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            {{-- ไฟล์แนบ --}}
                            <div class="col-12">
                                <label class="form-label">Attached File</label>
                                @if ($files->count())
                                    <ul class="list-unstyled mb-0">
                                        @foreach ($files as $f)
                                            <li class="mb-1">
                                                <i class="bi bi-paperclip me-1"></i>
                                                <a href="{{ asset('storage/' . $f->file_path) }}"
                                                    target="_blank">{{ $f->original_name }}</a>
                                                <small
                                                    class="text-muted ms-2">{{ number_format(($f->file_size ?? 0) / 1024, 1) }}
                                                    KB</small>
                                            </li>
                                        @endforeach
                                    </ul>
                                @else
                                    <div class="text-muted">ไม่มีไฟล์แนบ</div>
                                @endif
                            </div>

                            {{-- Remark --}}
                            <div class="col-12">
                                <label class="form-label">Remark</label>
                                <textarea class="form-control" rows="3" readonly>{{ $data->remark ?? '' }}</textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- RIGHT: Update / Timeline --}}
            <div class="col-lg-4">
                <div class="card shadow-sm mb-3">
                    <div class="card-header"><strong>Update Status</strong></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <input class="form-control" value="{{ strtoupper($statusLabel) }}" readonly>
                        </div>
                        @if ($canApprove)
                            <form method="POST" action="{{ route('pr.approve', $form->id) }}" class="mb-2">
                                @csrf
                                <label class="form-label">Admin Notes</label>
                                <textarea name="comment" class="form-control mb-3" rows="3" placeholder="หมายเหตุ (ถ้ามี)"></textarea>
                                <button class="btn btn-primary w-100">Update Status (Approve)</button>
                            </form>

                            <button class="btn btn-outline-danger w-100" data-bs-toggle="modal"
                                data-bs-target="#rejectModal">
                                ✖ Reject
                            </button>
                        @else
                            <div class="alert alert-info mb-0">คุณไม่มีสิทธิ์อนุมัติเอกสารนี้</div>
                        @endif
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-header"><strong>Request Timeline</strong></div>
                    <div class="card-body">
                        @if ($history->count())
                            <ul class="list-unstyled mb-0">
                                @foreach ($history as $h)
                                    <li class="mb-3">
                                        <div class="fw-semibold">{{ ucfirst(strtolower($h->action_type)) }}</div>
                                        <div class="small text-muted">
                                            {{ \Carbon\Carbon::parse($h->created_at)->format('M d, Y h:i A') }}
                                            @if ($h->actor_name)
                                                · by {{ $h->actor_name }}
                                            @endif
                                            · Step {{ $h->step_no }}
                                        </div>
                                        @if ($h->comment)
                                            <div class="small mt-1">{{ $h->comment }}</div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <div class="text-muted">ไม่มีประวัติ</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Reject Modal --}}
    <div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form class="modal-content" method="POST" action="{{ route('pr.reject', $form->id) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">เหตุผลในการปฏิเสธ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <textarea name="comment" class="form-control" rows="4" required></textarea>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-danger">ยืนยันปฏิเสธ</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        .card {
            border-radius: .6rem;
        }
    </style>
@endpush
