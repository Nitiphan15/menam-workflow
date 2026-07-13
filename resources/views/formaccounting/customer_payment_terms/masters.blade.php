@extends('layouts.layout')

@section('title', 'Payment Term Masters')
@section('page-title', 'Payment Term Masters')

@push('styles')
<style>
    .cpt-card { border-radius: 12px; }
    .cpt-card .card-header {
        background: linear-gradient(90deg, #f8fafc, #ffffff);
        border-bottom: 1px solid #eef0f4;
        padding: .85rem 1.1rem;
    }
    .cpt-card .card-header .title { font-weight: 600; font-size: 1rem; }
    .cpt-card .card-header .count-badge {
        background: #eef2ff; color: #4338ca; font-weight: 600;
        padding: .15rem .55rem; border-radius: 999px; font-size: .75rem;
    }
    .cpt-toolbar { gap: .5rem; }
    .cpt-toolbar .form-control, .cpt-toolbar .form-select { height: 34px; }

    .cpt-add-panel {
        border: 1px dashed #c7d2fe;
        background: #f8faff;
        border-radius: 10px;
        padding: .9rem;
        margin-bottom: .75rem;
    }

    .cpt-table { font-size: .88rem; }
    .cpt-table thead th {
        background: #f3f4f6; font-weight: 600; color: #374151;
        position: sticky; top: 0; z-index: 2; white-space: nowrap;
    }
    .cpt-table tbody tr { transition: background .15s; }
    .cpt-table tbody tr:hover { background: #fafbff; }
    .cpt-table tbody tr.is-inactive { opacity: .55; background: #fafafa; }
    .cpt-table tbody tr.is-dirty { background: #fffbeb !important; }
    .cpt-table tbody tr.is-dirty td:first-child { border-left: 3px solid #f59e0b; }

    .cpt-table .form-control-sm, .cpt-table .form-select-sm {
        font-size: .82rem; padding: .25rem .5rem; min-height: 30px;
    }
    .cpt-table .num { max-width: 70px; text-align: center; }

    .cpt-actions { white-space: nowrap; }
    .cpt-actions .btn {
        width: 34px; height: 32px; padding: 0;
        display: inline-flex; align-items: center; justify-content: center;
    }
    .cpt-actions .btn-save:disabled { opacity: .35; }

    .cpt-switch { transform: scale(1.15); cursor: pointer; }

    .cpt-table-scroll { max-height: 62vh; overflow: auto; }
    .cpt-empty { padding: 2rem; text-align: center; color: #94a3b8; }

    .schedule-type-badge { font-size: .7rem; padding: .2rem .45rem; border-radius: 4px; margin-left: .35rem; }
</style>
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <a href="{{ route('accounting.cpt.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fa fa-arrow-left me-1"></i>Customer Payment Terms
            </a>
        </div>
        <div class="text-muted small">
            <i class="fa fa-circle-info me-1"></i>
            แก้ค่าในแถวแล้วจะมีไฮไลต์เหลือง กดปุ่ม <i class="fa fa-save text-primary"></i> เพื่อบันทึก
        </div>
    </div>

    <div class="row g-4">
        {{-- ========== BILLING PLANS ========== --}}
        <div class="col-xl-6">
            <div class="card cpt-card shadow-sm border-0" data-cpt-section="billing">
                <div class="card-header d-flex align-items-center">
                    <span class="title">เงื่อนไขแผนรับชำระ</span>
                    <span class="count-badge ms-2" data-count>{{ $billingPlans->count() }}</span>
                    <div class="ms-auto d-flex cpt-toolbar align-items-center">
                        <input type="search" class="form-control form-control-sm" placeholder="ค้นหา code / ชื่อ"
                               style="width: 180px;" data-search>
                        <select class="form-select form-select-sm" style="width: 110px;" data-filter-status>
                            <option value="all">ทั้งหมด</option>
                            <option value="active" selected>ใช้งาน</option>
                            <option value="inactive">ปิดใช้</option>
                        </select>
                        <button class="btn btn-primary btn-sm" type="button" data-toggle-add>
                            <i class="fa fa-plus me-1"></i>เพิ่ม
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    {{-- Add new --}}
                    <div class="cpt-add-panel d-none" data-add-panel>
                        <form method="POST" action="{{ route('accounting.cpt.billing-plans.store') }}" class="row g-2">
                            @csrf
                            <div class="col-md-3">
                                <label class="form-label small mb-1">Code <span class="text-danger">*</span></label>
                                <input name="code" class="form-control form-control-sm" placeholder="เช่น BILL001" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small mb-1">ชื่อเงื่อนไข <span class="text-danger">*</span></label>
                                <input name="name_th" class="form-control form-control-sm" placeholder="ชื่อเงื่อนไข" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small mb-1">ช่วงวันจาก</label>
                                <input name="billing_day_from" type="number" min="1" max="31" class="form-control form-control-sm" placeholder="1">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small mb-1">ช่วงวันถึง</label>
                                <input name="billing_day_to" type="number" min="1" max="31" class="form-control form-control-sm" placeholder="31">
                            </div>
                            <div class="col-12 d-flex align-items-center gap-3 pt-1">
                                <div class="form-check form-switch">
                                    <input class="form-check-input cpt-switch" type="checkbox" name="is_cash" value="1" id="new-bill-cash">
                                    <label class="form-check-label small" for="new-bill-cash">เงินสด (Cash)</label>
                                </div>
                                <div class="ms-auto">
                                    <button type="button" class="btn btn-light btn-sm" data-toggle-add>ยกเลิก</button>
                                    <button class="btn btn-success btn-sm"><i class="fa fa-check me-1"></i>บันทึกเงื่อนไขใหม่</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="cpt-table-scroll">
                        <table class="table table-sm align-middle cpt-table mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 140px;">Code</th>
                                    <th>ชื่อเงื่อนไข</th>
                                    <th style="width: 130px;" class="text-center">ช่วงวัน</th>
                                    <th style="width: 60px;" class="text-center">เงินสด</th>
                                    <th style="width: 70px;" class="text-center">สถานะ</th>
                                    <th style="width: 90px;" class="text-end">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody data-rows>
                                @foreach ($billingPlans as $plan)
                                    @php $fid = 'bill-'.$plan->id; @endphp
                                    <tr class="{{ $plan->is_active ? '' : 'is-inactive' }}"
                                        data-row
                                        data-active="{{ $plan->is_active ? '1' : '0' }}"
                                        data-keywords="{{ strtolower($plan->code . ' ' . $plan->name_th) }}">
                                        <td>
                                            <form id="{{ $fid }}" method="POST" action="{{ route('accounting.cpt.billing-plans.update', $plan) }}" class="cpt-row-form" data-form-id="{{ $fid }}">
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="is_cash" value="0">
                                                <input type="hidden" name="is_active" value="0">
                                            </form>
                                            <input form="{{ $fid }}" name="code" value="{{ $plan->code }}" class="form-control form-control-sm" required>
                                        </td>
                                        <td>
                                            <input form="{{ $fid }}" name="name_th" value="{{ $plan->name_th }}" class="form-control form-control-sm" required>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1 justify-content-center">
                                                <input form="{{ $fid }}" name="billing_day_from" value="{{ $plan->billing_day_from }}" type="number" min="1" max="31"
                                                       class="form-control form-control-sm num" placeholder="จาก">
                                                <span class="text-muted small align-self-center">–</span>
                                                <input form="{{ $fid }}" name="billing_day_to" value="{{ $plan->billing_day_to }}" type="number" min="1" max="31"
                                                       class="form-control form-control-sm num" placeholder="ถึง">
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <input form="{{ $fid }}" class="form-check-input cpt-switch" type="checkbox" name="is_cash" value="1" @checked($plan->is_cash)>
                                        </td>
                                        <td class="text-center">
                                            <div class="form-check form-switch d-inline-block m-0">
                                                <input form="{{ $fid }}" class="form-check-input cpt-switch" type="checkbox" name="is_active" value="1" @checked($plan->is_active)
                                                       title="เปิด/ปิดใช้งาน">
                                            </div>
                                        </td>
                                        <td class="text-end cpt-actions">
                                            <button form="{{ $fid }}" class="btn btn-sm btn-primary btn-save" disabled title="บันทึก">
                                                <i class="fa fa-save"></i>
                                            </button>
                                            @if ($plan->is_active)
                                                <form method="POST" action="{{ route('accounting.cpt.billing-plans.destroy', $plan) }}" class="d-inline cpt-disable-form">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="btn btn-sm btn-outline-danger" title="ปิดการใช้งาน">
                                                        <i class="fa fa-ban"></i>
                                                    </button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                <tr class="cpt-empty-row d-none"><td colspan="6" class="cpt-empty">ไม่พบรายการที่ตรงกับคำค้น</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- ========== PAYMENT SCHEDULES ========== --}}
        <div class="col-xl-6">
            @php
                $typeLabels = [
                    'fixed_day' => 'ระบุวันที่',
                    'end_of_month' => 'สิ้นเดือน',
                    'immediate' => 'ทันที',
                    'custom' => 'กำหนดเอง',
                ];
            @endphp
            <div class="card cpt-card shadow-sm border-0" data-cpt-section="schedule">
                <div class="card-header d-flex align-items-center">
                    <span class="title">เงื่อนไขการจ่ายชำระ</span>
                    <span class="count-badge ms-2" data-count>{{ $paymentSchedules->count() }}</span>
                    <div class="ms-auto d-flex cpt-toolbar align-items-center">
                        <input type="search" class="form-control form-control-sm" placeholder="ค้นหา code / ชื่อ"
                               style="width: 180px;" data-search>
                        <select class="form-select form-select-sm" style="width: 110px;" data-filter-status>
                            <option value="all">ทั้งหมด</option>
                            <option value="active" selected>ใช้งาน</option>
                            <option value="inactive">ปิดใช้</option>
                        </select>
                        <button class="btn btn-primary btn-sm" type="button" data-toggle-add>
                            <i class="fa fa-plus me-1"></i>เพิ่ม
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    {{-- Add new --}}
                    <div class="cpt-add-panel d-none" data-add-panel>
                        <form method="POST" action="{{ route('accounting.cpt.payment-schedules.store') }}" class="row g-2" data-schedule-form>
                            @csrf
                            <div class="col-md-3">
                                <label class="form-label small mb-1">Code <span class="text-danger">*</span></label>
                                <input name="code" class="form-control form-control-sm" placeholder="เช่น PAY001" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small mb-1">ชื่อเงื่อนไข <span class="text-danger">*</span></label>
                                <input name="name_th" class="form-control form-control-sm" placeholder="ชื่อเงื่อนไข" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small mb-1">ประเภท</label>
                                <select name="schedule_type" class="form-select form-select-sm" data-schedule-type>
                                    @foreach ($typeLabels as $val => $label)
                                        <option value="{{ $val }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small mb-1">วันที่จ่าย</label>
                                <input name="payment_day" type="number" min="1" max="31" class="form-control form-control-sm"
                                       placeholder="1-31" data-payment-day>
                            </div>
                            <div class="col-12 text-end pt-1">
                                <button type="button" class="btn btn-light btn-sm" data-toggle-add>ยกเลิก</button>
                                <button class="btn btn-success btn-sm"><i class="fa fa-check me-1"></i>บันทึกเงื่อนไขใหม่</button>
                            </div>
                        </form>
                    </div>

                    <div class="cpt-table-scroll">
                        <table class="table table-sm align-middle cpt-table mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 140px;">Code</th>
                                    <th>ชื่อเงื่อนไข</th>
                                    <th style="width: 130px;">ประเภท</th>
                                    <th style="width: 80px;" class="text-center">วันที่จ่าย</th>
                                    <th style="width: 70px;" class="text-center">สถานะ</th>
                                    <th style="width: 90px;" class="text-end">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody data-rows>
                                @foreach ($paymentSchedules as $schedule)
                                    @php $fid = 'sch-'.$schedule->id; @endphp
                                    <tr class="{{ $schedule->is_active ? '' : 'is-inactive' }}"
                                        data-row
                                        data-active="{{ $schedule->is_active ? '1' : '0' }}"
                                        data-keywords="{{ strtolower($schedule->code . ' ' . $schedule->name_th) }}">
                                        <td>
                                            <form id="{{ $fid }}" method="POST" action="{{ route('accounting.cpt.payment-schedules.update', $schedule) }}"
                                                  class="cpt-row-form" data-schedule-form data-form-id="{{ $fid }}">
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="is_active" value="0">
                                            </form>
                                            <input form="{{ $fid }}" name="code" value="{{ $schedule->code }}" class="form-control form-control-sm" required>
                                        </td>
                                        <td>
                                            <input form="{{ $fid }}" name="name_th" value="{{ $schedule->name_th }}" class="form-control form-control-sm" required>
                                        </td>
                                        <td>
                                            <select form="{{ $fid }}" name="schedule_type" class="form-select form-select-sm" data-schedule-type>
                                                @foreach ($typeLabels as $val => $label)
                                                    <option value="{{ $val }}" @selected($schedule->schedule_type === $val)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="text-center">
                                            <input form="{{ $fid }}" name="payment_day" value="{{ $schedule->payment_day }}" type="number" min="1" max="31"
                                                   class="form-control form-control-sm num" data-payment-day>
                                        </td>
                                        <td class="text-center">
                                            <div class="form-check form-switch d-inline-block m-0">
                                                <input form="{{ $fid }}" class="form-check-input cpt-switch" type="checkbox" name="is_active" value="1" @checked($schedule->is_active)
                                                       title="เปิด/ปิดใช้งาน">
                                            </div>
                                        </td>
                                        <td class="text-end cpt-actions">
                                            <button form="{{ $fid }}" class="btn btn-sm btn-primary btn-save" disabled title="บันทึก">
                                                <i class="fa fa-save"></i>
                                            </button>
                                            @if ($schedule->is_active)
                                                <form method="POST" action="{{ route('accounting.cpt.payment-schedules.destroy', $schedule) }}" class="d-inline cpt-disable-form">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="btn btn-sm btn-outline-danger" title="ปิดการใช้งาน">
                                                        <i class="fa fa-ban"></i>
                                                    </button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                <tr class="cpt-empty-row d-none"><td colspan="6" class="cpt-empty">ไม่พบรายการที่ตรงกับคำค้น</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
(function () {
    const hasSwal = typeof Swal !== 'undefined';

    // 1. Toggle add panel (per section)
    document.querySelectorAll('[data-cpt-section]').forEach(section => {
        const panel = section.querySelector('[data-add-panel]');
        section.querySelectorAll('[data-toggle-add]').forEach(btn => {
            btn.addEventListener('click', () => {
                panel.classList.toggle('d-none');
                if (!panel.classList.contains('d-none')) {
                    const first = panel.querySelector('input, select');
                    if (first) first.focus();
                }
            });
        });
    });

    function serialize(form) {
        const fd = new FormData(form);
        const parts = [];
        for (const [k, v] of fd.entries()) parts.push(k + '=' + v);
        return parts.join('&');
    }

    // 2. Dirty tracking per row → enable save button
    document.querySelectorAll('.cpt-row-form[data-form-id]').forEach(form => {
        const row = form.closest('tr');
        const saveBtn = row.querySelector('.btn-save');
        const initial = serialize(form);

        const check = () => {
            const changed = serialize(form) !== initial;
            row.classList.toggle('is-dirty', changed);
            if (saveBtn) saveBtn.disabled = !changed;
        };

        // Inputs may live outside the form (linked via form="id"); listen on the row instead
        row.addEventListener('input', e => { if (e.target.form === form) check(); });
        row.addEventListener('change', e => { if (e.target.form === form) check(); });
    });

    // 3. schedule_type → toggle payment_day enable
    function bindScheduleType(scope) {
        const typeEl = scope.querySelector('[data-schedule-type]');
        const dayEl = scope.querySelector('[data-payment-day]');
        if (!typeEl || !dayEl) return;
        const sync = () => {
            const isFixed = typeEl.value === 'fixed_day';
            dayEl.disabled = !isFixed;
            if (!isFixed) dayEl.value = '';
            dayEl.classList.toggle('bg-light', !isFixed);
        };
        typeEl.addEventListener('change', sync);
        sync();
    }
    // Add-new form (a real <form> wrapper)
    document.querySelectorAll('form[data-schedule-form]').forEach(bindScheduleType);
    // Inline row: select/input live in <tr>, not in the <form> element
    document.querySelectorAll('tr[data-row]').forEach(row => {
        if (row.querySelector('[data-schedule-type]')) bindScheduleType(row);
    });

    // 4. Search + status filter
    document.querySelectorAll('[data-cpt-section]').forEach(section => {
        const searchEl = section.querySelector('[data-search]');
        const statusEl = section.querySelector('[data-filter-status]');
        const rows = section.querySelectorAll('[data-row]');
        const emptyRow = section.querySelector('.cpt-empty-row');
        const countEl = section.querySelector('[data-count]');

        const apply = () => {
            const q = (searchEl.value || '').toLowerCase().trim();
            const status = statusEl.value;
            let visible = 0;
            rows.forEach(r => {
                const matchQ = !q || (r.dataset.keywords || '').includes(q);
                const active = r.dataset.active === '1';
                const matchStatus = status === 'all' || (status === 'active' && active) || (status === 'inactive' && !active);
                const show = matchQ && matchStatus;
                r.classList.toggle('d-none', !show);
                if (show) visible++;
            });
            emptyRow.classList.toggle('d-none', visible !== 0);
            countEl.textContent = visible;
        };
        searchEl.addEventListener('input', apply);
        statusEl.addEventListener('change', apply);
        apply();
    });

    // 5. Confirm disable
    document.querySelectorAll('.cpt-disable-form').forEach(form => {
        form.addEventListener('submit', e => {
            if (form.dataset.confirmed === '1') return;
            e.preventDefault();
            if (hasSwal) {
                Swal.fire({
                    title: 'ปิดการใช้งานรายการนี้?',
                    text: 'รายการจะถูกซ่อนจากการเลือกใช้งาน แต่ข้อมูลเดิมยังอยู่',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'ปิดใช้งาน',
                    cancelButtonText: 'ยกเลิก',
                    confirmButtonColor: '#dc3545',
                }).then(r => {
                    if (r.isConfirmed) { form.dataset.confirmed = '1'; form.submit(); }
                });
            } else if (confirm('ปิดการใช้งานรายการนี้?')) {
                form.dataset.confirmed = '1'; form.submit();
            }
        });
    });
})();
</script>
@endpush
