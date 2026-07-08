@extends('layouts.layout')

@section('title', 'PO Online')
@section('page-title', 'PO Online')

@section('content')
    <style>
        .po-summary-card {
            border: 1px solid #e9ecef;
            border-radius: 16px;
            background: linear-gradient(180deg, #ffffff 0%, #fbfcfe 100%);
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
        }

        .po-stat {
            border: 1px solid #eef2f7;
            border-radius: 14px;
            padding: 14px 16px;
            background: #fff;
            min-height: 94px;
        }

        .po-stat-label {
            font-size: 0.82rem;
            color: #6c757d;
            margin-bottom: 6px;
        }

        .po-stat-value {
            font-size: 1.6rem;
            font-weight: 700;
            line-height: 1;
            color: #0f172a;
        }

        .po-stat-hint {
            font-size: 0.8rem;
            color: #94a3b8;
            margin-top: 8px;
        }

        .po-filter-card {
            border: 0;
            border-radius: 18px;
            background: #ffffff;
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.08);
        }

        .po-filter-title {
            font-size: 1rem;
            font-weight: 700;
            color: #0f172a;
        }

        .po-group-card {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.05);
            background: #fff;
        }

        .po-group-header {
            background: linear-gradient(135deg, #f8fbff 0%, #eef5ff 100%);
            border-bottom: 1px solid #e6edf7;
            padding: 10px 14px;
            cursor: pointer;
            list-style: none;
        }

        details:not([open]) .po-group-header {
            border-bottom: 0;
        }

        .po-group-header::-webkit-details-marker {
            display: none;
        }

        .po-group-name {
            font-size: 0.98rem;
            font-weight: 700;
            color: #0f172a;
        }

        .po-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 0.18rem 0.58rem;
            font-size: 0.74rem;
            font-weight: 700;
        }

        .po-pill-muted {
            background: #64748b;
            color: #fff;
        }

        .po-pill-warning {
            background: #facc15;
            color: #111827;
        }

        .po-pill-soft {
            background: #e2e8f0;
            color: #334155;
        }

        .po-group-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .po-group-toggle {
            width: 28px;
            height: 28px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #e2e8f0;
            color: #334155;
            font-weight: 800;
            flex: 0 0 auto;
        }

        .po-group-toggle::before {
            content: "+";
        }

        details[open] .po-group-toggle {
            background: #2563eb;
            color: #fff;
        }

        details[open] .po-group-toggle::before {
            content: "-";
        }

        .po-table-wrap {
            max-height: 560px;
            overflow: auto;
        }

        .po-table thead {
            position: sticky;
            top: 0;
            z-index: 2;
        }

        .po-table thead th {
            border-bottom: 0;
            color: #334155;
            font-size: 0.84rem;
            font-weight: 700;
            background: #f8fafc;
            white-space: nowrap;
        }

        .po-table tbody td {
            padding-top: 0.9rem;
            padding-bottom: 0.9rem;
            vertical-align: middle;
        }

        .po-table tbody tr:hover {
            background: #fafcff;
        }

        .po-po-number {
            font-weight: 700;
            color: #0f172a;
        }

        .po-subtext {
            font-size: 0.8rem;
            color: #94a3b8;
        }

        .po-status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 130px;
            border-radius: 999px;
            padding: 0.35rem 0.75rem;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .po-bulk-toolbar {
            position: sticky;
            top: 0;
            z-index: 5;
            background: rgba(248, 250, 252, 0.94);
            backdrop-filter: blur(8px);
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 10px;
        }

        .po-dept-nav {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            background: #fff;
            padding: 12px;
        }

        .po-dept-nav-list {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 2px;
            scrollbar-width: thin;
        }

        .po-dept-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            border: 1px solid #dbe4f0;
            border-radius: 999px;
            padding: 0.45rem 0.8rem;
            color: #334155;
            text-decoration: none;
            background: #f8fafc;
            font-weight: 700;
            font-size: 0.82rem;
        }

        .po-dept-chip:hover {
            color: #0f172a;
            background: #eef5ff;
            border-color: #bfdbfe;
        }

        .po-dept-count {
            border-radius: 999px;
            background: #2563eb;
            color: #fff;
            padding: 0.05rem 0.45rem;
            font-size: 0.72rem;
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
    </style>

    <div class="container-fluid py-3 px-0 po-page-wide">
        @if (session('ok'))
            <div class="alert alert-success border-0 shadow-sm">{{ session('ok') }}</div>
        @endif

        @if ($errors->has('erp_phpsessid'))
            <div class="alert alert-danger border-0 shadow-sm">{{ $errors->first('erp_phpsessid') }}</div>
        @endif

        <div class="card border-0 shadow-sm mb-3">
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
                            placeholder="Paste your CPA PHPSESSID">
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

        <div class="po-summary-card p-3 p-lg-4 mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                <div>
                    <div class="po-filter-title">ภาพรวมรายการ PO Online</div>
                    <div class="text-muted small">ค้นหา กรองข้อมูล แยก source และติดตามสถานะการอนุมัติได้จากหน้าเดียว</div>
                </div>
                <div class="d-flex gap-2">
                    <a href="{{ route('po.myActions', request()->only(['search', 'department', 'date_from', 'date_to', 'status', 'source'])) }}"
                        class="btn btn-outline-primary">My Action</a>
                    <a href="{{ route('po.export.list', request()->only(['search', 'department', 'date_from', 'date_to', 'status', 'source'])) }}"
                        class="btn btn-outline-success">Export CSV</a>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-6 col-lg-3">
                    <div class="po-stat">
                        <div class="po-stat-label">PO ทั้งหมด</div>
                        <div class="po-stat-value">{{ number_format($summary['po_count']) }}</div>
                        <div class="po-stat-hint">ตามเงื่อนไขที่กำลังกำหนดกรองอยู่</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="po-stat">
                        <div class="po-stat-label">กลุ่มแผนก</div>
                        <div class="po-stat-value">{{ number_format($summary['department_count']) }}</div>
                        <div class="po-stat-hint">รวมกลุ่มตาม prefix ของแผนก เช่น Store, HR, R&amp;D</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="po-stat">
                        <div class="po-stat-label">แนบเอกสารแล้ว</div>
                        <div class="po-stat-value">{{ number_format($summary['attached_count']) }}</div>
                        <div class="po-stat-hint">พร้อมสำหรับส่งเข้า workflow</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="po-stat">
                        <div class="po-stat-label">สถานะ Draft</div>
                        <div class="po-stat-value">{{ number_format($summary['draft_count']) }}</div>
                        <div class="po-stat-hint">เอกสารที่เปิดแล้วแต่ยังไม่ถูกส่งอนุมัติ</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card po-filter-card mb-4">
            <div class="card-body p-3 p-lg-4">
                <div class="po-filter-title mb-3">ค้นหาและกรองข้อมูล</div>

                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small text-muted">คำค้นหา</label>
                        <input type="text" name="search" value="{{ request('search') }}" class="form-control"
                            placeholder="PO / Vendor / แผนก">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small text-muted">กลุ่มแผนก</label>
                        <select name="department" class="form-select">
                            <option value="">ทุกแผนก</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department }}" @selected(request('department') === $department)>{{ $department }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small text-muted">สถานะ</label>
                        <select name="status" class="form-select">
                            <option value="">ทุกสถานะ</option>
                            @foreach ($statusOptions as $value => $label)
                                <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small text-muted">Source</label>
                        <select name="source" class="form-select">
                            <option value="">ทั้งหมด</option>
                            @foreach ($sourceOptions as $value => $label)
                                <option value="{{ $value }}" @selected(request('source') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small text-muted">วันที่เริ่มต้น</label>
                        <input type="date" name="date_from" value="{{ request('date_from') }}" class="form-control">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small text-muted">วันที่สิ้นสุด</label>
                        <input type="date" name="date_to" value="{{ request('date_to') }}" class="form-control">
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <button class="btn btn-primary w-100">กรอง</button>
                    </div>
                    <div class="col-md-12 d-flex justify-content-end">
                        <a href="{{ route('po.index') }}" class="btn btn-outline-secondary">ล้างตัวกรอง</a>
                    </div>
                </form>
            </div>
        </div>

        <form id="po-selected-pdf-form" method="POST" action="{{ route('po.print.selected') }}">
            @csrf
        </form>
        <form id="po-selected-mail-form" method="POST" action="{{ route('po.notifySelectedStepThree') }}">
            @csrf
        </form>

        <div class="po-dept-nav mb-3">
            <div class="d-flex justify-content-between align-items-center gap-3 mb-2">
                <div class="fw-semibold">Department quick jump</div>
                <div class="small text-muted">Open a department, then scroll inside that table</div>
            </div>
            <div class="po-dept-nav-list">
                @foreach ($grouped as $group)
                    <a class="po-dept-chip" href="#po-group-{{ \Illuminate\Support\Str::slug((string) $group->name) ?: $loop->index }}">
                        {{ $group->name }}
                        <span class="po-dept-count">{{ $group->items->count() }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        <div class="po-bulk-toolbar mb-3">
            <div class="d-flex flex-wrap justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary" id="po-expand-groups">
                    เปิดทุกแผนก
                </button>
                <button type="button" class="btn btn-outline-secondary" id="po-collapse-groups">
                    ปิดทุกแผนก
                </button>
                <button type="button" class="btn btn-outline-secondary" id="po-select-all-pdf">
                    เลือก PDF ทั้งหมด
                </button>
                <button type="button" class="btn btn-outline-secondary" id="po-clear-all-pdf">
                    ล้าง PDF
                </button>
                <button type="button" class="btn btn-outline-secondary" id="po-select-all-mail">
                    เลือก Mail ได้ทั้งหมด
                </button>
                <button type="button" class="btn btn-outline-secondary" id="po-clear-all-mail">
                    ล้าง Mail
                </button>
                <button type="submit" class="btn btn-outline-dark" form="po-selected-pdf-form">
                    Download PDF รายการที่เลือก
                </button>
                <button type="submit" class="btn btn-outline-primary" form="po-selected-mail-form"
                    onclick="return confirm('ส่งอีเมลแจ้งเตือนให้รายการที่เลือกใช่หรือไม่? เลือกได้เฉพาะ PO ที่อยู่ step 3 เท่านั้น');">
                    ส่ง mail รายการที่เลือก
                </button>
            </div>
        </div>

        @forelse ($grouped as $group)
            <details class="po-group-card mb-3" id="po-group-{{ \Illuminate\Support\Str::slug((string) $group->name) ?: $loop->index }}" @if (request('department') === $group->name || $loop->first) open @endif>
                <summary class="po-group-header">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                        <div class="d-flex align-items-start gap-3">
                            <span class="po-group-toggle" aria-hidden="true"></span>
                            <div>
                                <div class="po-group-name mb-2">{{ $group->name }}</div>
                                <div class="d-flex flex-wrap gap-2">
                                    <span class="po-pill po-pill-muted">{{ $group->items->count() }} PO</span>
                                    <span class="po-pill po-pill-warning">พร้อมส่งอนุมัติ {{ $group->pending_submit_count }}</span>
                                    <span class="po-pill po-pill-soft">รอหัวหน้าแผนก {{ $group->dept_manager_pending_count }}</span>
                                    <span class="po-pill po-pill-soft">Attached {{ $group->attached_count }}</span>
                                    <span class="po-pill po-pill-soft">Draft {{ $group->draft_count }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="po-group-actions">
                            <a href="{{ route('po.print.department', array_merge(request()->only(['search', 'date_from', 'date_to', 'status', 'source']), ['department' => $group->name])) }}"
                                class="btn btn-outline-dark btn-sm">
                                Download PDF ทั้งกลุ่ม
                            </a>

                            @if ($group->dept_manager_pending_count > 0)
                                <form method="POST" action="{{ route('po.remindDepartmentHead') }}"
                                    onsubmit="return confirm('ส่งเมลแจ้งหัวหน้าแผนกสำหรับกลุ่ม {{ $group->name }} ใช่หรือไม่?');">
                                    @csrf
                                    <input type="hidden" name="department_group" value="{{ $group->name }}">
                                    <button class="btn btn-outline-primary btn-sm">ส่ง mail หาหัวหน้าแผนก</button>
                                </form>
                            @endif
                        </div>
                    </div>
                </summary>

                <div class="table-responsive po-table-wrap">
                    <table class="table po-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 70px;">PDF</th>
                                <th style="width: 70px;">Mail</th>
                                <th>Source</th>
                                <th>PO No.</th>
                                <th>แผนกต้นทาง</th>
                                <th>วันที่สั่ง</th>
                                <th>วันที่ส่ง</th>
                                <th class="text-end">Qty/Amount</th>
                                <th>Status</th>
                                <th>Attached</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($group->items as $row)
                                @php
                                    $status = strtoupper((string) $row->status_code);
                                    $statusMeta = match ($status) {
                                        'PURCHASE_SUBMITTED' => ['label' => 'Purchase Submitted', 'class' => 'bg-primary-subtle text-primary'],
                                        'PURCHASE_APPROVAL' => ['label' => 'Waiting Purchase Approval', 'class' => 'bg-info-subtle text-info'],
                                        'DEPT_MANAGER_APPROVAL' => ['label' => 'Waiting Department Approval', 'class' => 'bg-secondary-subtle text-secondary'],
                                        'CLOSED' => ['label' => 'Closed', 'class' => 'bg-success-subtle text-success'],
                                        'REJECTED' => ['label' => 'Rejected', 'class' => 'bg-danger-subtle text-danger'],
                                        'CANCELLED' => ['label' => 'Cancelled', 'class' => 'bg-dark-subtle text-dark'],
                                        'DRAFT' => ['label' => 'Draft', 'class' => 'bg-warning-subtle text-warning-emphasis'],
                                        'NEW' => ['label' => 'New', 'class' => 'bg-warning-subtle text-warning-emphasis'],
                                        default => ['label' => str_replace('_', ' ', $status), 'class' => 'bg-light text-dark'],
                                    };
                                    $canTickMail = $row->po_header_id && $status === 'DEPT_MANAGER_APPROVAL';
                                    $canSelectPo = (bool) $row->po_header_id;
                                @endphp
                                <tr>
                                    <td class="text-center">
                                        <input type="checkbox" class="js-po-pdf-check" name="po_ids[]" value="{{ $row->po_header_id }}"
                                            form="po-selected-pdf-form" @checked(false) @disabled(!$canSelectPo)>
                                    </td>
                                    <td class="text-center">
                                        <input type="checkbox" class="js-po-mail-check" name="po_ids[]" value="{{ $row->po_header_id }}"
                                            form="po-selected-mail-form" @checked(false) @disabled(!$canTickMail)>
                                    </td>
                                    <td><span class="badge text-bg-light">{{ $row->source_label }}</span></td>
                                    <td>
                                        <div class="po-po-number">{{ $row->ordnumber }}</div>
                                        <div class="po-subtext">PO document</div>
                                    </td>
                                    <td>{{ $row->department }}</td>
                                    <td>{{ \Illuminate\Support\Carbon::parse($row->transdate)->format('d-M-Y') }}</td>
                                    <td>{{ $row->reqdate ? \Illuminate\Support\Carbon::parse($row->reqdate)->format('d-M-Y') : '-' }}</td>
                                    <td class="text-end">{{ number_format($row->qty, 2) }}</td>
                                    <td>
                                        <span class="po-status-badge {{ $statusMeta['class'] }}">{{ $statusMeta['label'] }}</span>
                                    </td>
                                    <td>
                                        @if ($row->has_attachment)
                                            <span class="badge text-bg-success">Attached</span>
                                        @else
                                            <span class="badge text-bg-light">No File</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-2">
                                            @if ($row->po_header_id)
                                                <a href="{{ route('po.print', $row->po_header_id) }}" class="btn btn-sm btn-outline-dark">Download PDF</a>
                                                <a href="{{ route('po.show', $row->po_header_id) }}" class="btn btn-sm btn-primary">เปิด</a>
                                            @else
                                                <a href="{{ route('po.create', ['ordnumber' => $row->ordnumber, 'source' => $row->site]) }}" class="btn btn-sm btn-outline-primary">เลือก PO</a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @empty
            <div class="card border-0 shadow-sm">
                <div class="card-body py-5 text-center">
                    <div class="fw-bold fs-5 mb-2">ไม่พบข้อมูล PO ตามเงื่อนไขที่เลือก</div>
                    <div class="text-muted mb-3">ลองเปลี่ยนคำค้นหา ช่วงวันที่ แผนก สถานะ หรือ source แล้วค้นหาใหม่อีกครั้ง</div>
                    <a href="{{ route('po.index') }}" class="btn btn-outline-secondary">ล้างตัวกรอง</a>
                </div>
            </div>
        @endforelse
    </div>

    <script>
        (() => {
            const checks = (selector) => Array.from(document.querySelectorAll(`${selector}:not(:disabled)`));
            const setChecked = (selector, checked) => {
                checks(selector).forEach((check) => {
                    check.checked = checked;
                });
            };

            if (window.bootstrap?.Tooltip) {
                document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
                    bootstrap.Tooltip.getOrCreateInstance(el);
                });
            }

            document.getElementById('po-select-all-pdf')?.addEventListener('click', () => setChecked('.js-po-pdf-check', true));
            document.getElementById('po-clear-all-pdf')?.addEventListener('click', () => setChecked('.js-po-pdf-check', false));
            document.getElementById('po-select-all-mail')?.addEventListener('click', () => setChecked('.js-po-mail-check', true));
            document.getElementById('po-clear-all-mail')?.addEventListener('click', () => setChecked('.js-po-mail-check', false));
            document.getElementById('po-expand-groups')?.addEventListener('click', () => {
                document.querySelectorAll('.po-group-card').forEach((group) => {
                    group.open = true;
                });
            });
            document.getElementById('po-collapse-groups')?.addEventListener('click', () => {
                document.querySelectorAll('.po-group-card').forEach((group) => {
                    group.open = false;
                });
            });

            document.querySelectorAll('.po-group-actions').forEach((actions) => {
                actions.addEventListener('click', (event) => event.stopPropagation());
            });

            document.querySelectorAll('.po-dept-chip[href^="#"]').forEach((chip) => {
                chip.addEventListener('click', () => {
                    const target = document.querySelector(chip.getAttribute('href'));
                    if (target && target.tagName === 'DETAILS') {
                        target.open = true;
                    }
                });
            });
        })();
    </script>
@endsection
