@extends('layouts.layout')

@section('title', 'Customer Payment Terms')
@section('page-title', 'Customer Payment Terms')

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <form method="GET" class="d-flex flex-wrap align-items-center gap-2">
            <input type="text" name="q" class="form-control" style="width: 280px" value="{{ $filters['q'] ?? '' }}"
                placeholder="ค้นหาลูกค้า / terms">
            <select name="status" class="form-select" style="width: 150px">
                <option value="active" @selected(($filters['status'] ?? 'active') === 'active')>Active</option>
                <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option>
                <option value="all" @selected(($filters['status'] ?? '') === 'all')>All</option>
            </select>
            <button class="btn btn-outline-secondary" type="submit">
                <i class="fa fa-search me-1"></i>ค้นหา
            </button>
        </form>

        <div class="d-flex gap-2">
            <a href="{{ route('accounting.cpt.masters') }}" class="btn btn-outline-primary">
                <i class="fa fa-sliders me-1"></i>Masters
            </a>
        <button type="button" class="btn btn-primary" id="btnCreateTerm">
            <i class="fa fa-plus me-1"></i>เพิ่มลูกค้า
        </button>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 80px">Source</th>
                        <th>Customer</th>
                        <th style="width: 100px">ERP Terms</th>
                        <th style="width: 160px">Effective</th>
                        <th style="width: 90px">Days</th>
                        <th style="width: 90px">Grace</th>
                        <th>รายละเอียด</th>
                        <th>แผนวางบิล</th>
                        <th>รอบชำระ</th>
                        <th style="width: 90px">Status</th>
                        <th style="width: 150px"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($terms as $term)
                        <tr>
                            <td>
                                <span class="badge bg-secondary">{{ strtoupper(str_replace('pgsql', '', $term->erp_source)) }}</span>
                            </td>
                            <td>
                                <div class="fw-semibold">{{ $term->customer_name }}</div>
                                <div class="text-muted small">{{ $term->customer_code }}</div>
                            </td>
                            <td>{{ $term->erp_terms ?: '-' }}</td>
                            <td>
                                <div>{{ optional($term->effective_from)->format('Y-m-d') ?: 'Any date' }}</div>
                                <div class="text-muted small">to {{ optional($term->effective_to)->format('Y-m-d') ?: 'open end' }}</div>
                            </td>
                            <td>{{ number_format((int) $term->credit_days) }}</td>
                            <td>{{ number_format((int) ($term->grace_days ?? 0)) }}</td>
                            <td>
                                <div>{{ $term->credit_term_code ?: '-' }}</div>
                                @if ($term->credit_term_detail)
                                    <div class="text-muted small">{{ $term->credit_term_detail }}</div>
                                @endif
                            </td>
                            <td>{{ optional($term->billingPlan)->name_th ?: '-' }}</td>
                            <td>
                                {{ optional($term->paymentSchedule)->name_th ?: '-' }}
                                @if ($term->override_payment_day)
                                    <div class="text-muted small">Override วันที่ {{ $term->override_payment_day }}</div>
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ $term->is_active ? 'bg-success' : 'bg-secondary' }}">
                                    {{ $term->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary js-edit-term"
                                    data-term='@json($term)'>
                                    <i class="fa fa-pen"></i>
                                </button>
                                @if ($term->is_active)
                                    <form method="POST" action="{{ route('accounting.cpt.destroy', $term) }}" class="d-inline js-disable-form">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="fa fa-ban"></i>
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="text-center text-muted py-4">ยังไม่มีข้อมูล</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $terms->links() }}
    </div>

    <div class="modal fade" id="paymentTermModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <form method="POST" action="{{ route('accounting.cpt.store') }}" class="modal-content" id="paymentTermForm">
                @csrf
                <input type="hidden" name="_method" value="POST" id="formMethod">
                <input type="hidden" name="erp_source" id="erpSourceInput">
                <input type="hidden" name="customer_code" id="customerCodeInput">
                <input type="hidden" name="customer_name" id="customerNameInput">
                <input type="hidden" name="erp_terms" id="erpTermsInput">

                <div class="modal-header">
                    <h5 class="modal-title" id="paymentTermModalTitle">เพิ่มเงื่อนไขรับชำระลูกค้า</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <label class="form-label">ลูกค้าจาก ERP</label>
                            <select id="erpCustomerSelect" placeholder="พิมพ์ชื่อลูกค้าอย่างน้อย 2 ตัวอักษร"></select>
                        </div>
                        <div class="col-lg-2">
                            <label class="form-label">Source</label>
                            <input type="text" class="form-control" id="sourcePreview" readonly>
                        </div>
                        <div class="col-lg-4">
                            <label class="form-label">ERP Terms</label>
                            <input type="text" class="form-control" id="erpTermsPreview" readonly>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">Credit Days</label>
                            <input type="number" min="0" max="999" name="credit_days" id="creditDaysInput"
                                class="form-control" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Grace Days</label>
                            <input type="number" min="0" max="365" name="grace_days" id="graceDaysInput"
                                class="form-control" value="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Effective From</label>
                            <input type="date" name="effective_from" id="effectiveFromInput" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Effective To</label>
                            <input type="date" name="effective_to" id="effectiveToInput" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Term Code</label>
                            <input type="text" name="credit_term_code" id="creditTermCodeInput" class="form-control"
                                placeholder="N/30, N/60, COD">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label">รายละเอียดเงื่อนไข</label>
                            <input type="text" name="credit_term_detail" id="creditTermDetailInput" class="form-control"
                                placeholder="เช่น n/90 จ่ายทันทีหลังส่ง">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">เงื่อนไขแผนรับชำระ</label>
                            <select name="billing_plan_id" id="billingPlanInput" class="form-select">
                                <option value="">-- เลือก --</option>
                                @foreach ($billingPlans as $plan)
                                    <option value="{{ $plan->id }}">{{ $plan->name_th }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">เงื่อนไขการจ่ายชำระ</label>
                            <select name="payment_schedule_id" id="paymentScheduleInput" class="form-select">
                                <option value="">-- เลือก --</option>
                                @foreach ($paymentSchedules as $schedule)
                                    <option value="{{ $schedule->id }}"
                                        data-schedule-type="{{ $schedule->schedule_type }}"
                                        data-payment-day="{{ $schedule->payment_day }}">
                                        {{ $schedule->name_th }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Override วันจ่าย</label>
                            <input type="number" min="1" max="31" name="override_payment_day"
                                id="overridePaymentDayInput" class="form-control" placeholder="1-31">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select name="is_active" id="isActiveInput" class="form-select">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <div class="border rounded bg-light p-3">
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-3">
                                        <label class="form-label">Preview Invoice Date</label>
                                        <input type="date" id="previewInvoiceDateInput" class="form-control">
                                    </div>
                                    <div class="col-md-9">
                                        <div class="small text-muted mb-1">Due Date Preview</div>
                                        <div class="fw-semibold" id="dueDatePreview">-</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Remark</label>
                            <textarea name="remark" id="remarkInput" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-save me-1"></i>บันทึก
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modalEl = document.getElementById('paymentTermModal');
            const modal = new bootstrap.Modal(modalEl);
            const form = document.getElementById('paymentTermForm');
            const methodInput = document.getElementById('formMethod');
            const titleEl = document.getElementById('paymentTermModalTitle');
            const storeUrl = @json(route('accounting.cpt.store'));
            const lookupUrl = @json(route('accounting.cpt.customerLookup'));

            const fields = {
                erpSource: document.getElementById('erpSourceInput'),
                customerCode: document.getElementById('customerCodeInput'),
                customerName: document.getElementById('customerNameInput'),
                erpTerms: document.getElementById('erpTermsInput'),
                sourcePreview: document.getElementById('sourcePreview'),
                erpTermsPreview: document.getElementById('erpTermsPreview'),
                creditDays: document.getElementById('creditDaysInput'),
                graceDays: document.getElementById('graceDaysInput'),
                effectiveFrom: document.getElementById('effectiveFromInput'),
                effectiveTo: document.getElementById('effectiveToInput'),
                creditTermCode: document.getElementById('creditTermCodeInput'),
                creditTermDetail: document.getElementById('creditTermDetailInput'),
                billingPlan: document.getElementById('billingPlanInput'),
                paymentSchedule: document.getElementById('paymentScheduleInput'),
                overridePaymentDay: document.getElementById('overridePaymentDayInput'),
                isActive: document.getElementById('isActiveInput'),
                remark: document.getElementById('remarkInput'),
                previewInvoiceDate: document.getElementById('previewInvoiceDateInput'),
                dueDatePreview: document.getElementById('dueDatePreview'),
            };

            const customerSelect = new TomSelect('#erpCustomerSelect', {
                valueField: 'id',
                labelField: 'customer_name',
                searchField: ['customer_name', 'terms'],
                create: false,
                maxItems: 1,
                loadThrottle: 300,
                load: function (query, callback) {
                    if (!query || query.length < 2) {
                        callback();
                        return;
                    }

                    fetch(`${lookupUrl}?q=${encodeURIComponent(query)}`)
                        .then(response => response.json())
                        .then(json => callback(json.results || []))
                        .catch(() => callback());
                },
                render: {
                    option: function (item, escape) {
                        return `<div>
                            <div class="fw-semibold">${escape(item.customer_name)}</div>
                            <div class="small text-muted">${escape(item.source_label || item.erp_source)} | Terms: ${escape(item.terms || '-')}</div>
                        </div>`;
                    },
                    item: function (item, escape) {
                        return `<div>${escape(item.customer_name)} <span class="text-muted">(${escape(item.source_label || item.erp_source)})</span></div>`;
                    },
                },
                onChange: function (value) {
                    if (!value) return;
                    const selected = this.options[value];
                    if (!selected) return;
                    fillCustomer(selected);
                },
            });

            function fillCustomer(data) {
                fields.erpSource.value = data.erp_source || '';
                fields.customerCode.value = data.customer_code || data.customer_name || '';
                fields.customerName.value = data.customer_name || '';
                fields.erpTerms.value = data.terms || data.erp_terms || '';
                fields.sourcePreview.value = (data.source_label || data.erp_source || '').toUpperCase();
                fields.erpTermsPreview.value = data.terms || data.erp_terms || '';

                if (!fields.creditDays.dataset.touched) {
                    fields.creditDays.value = data.credit_days ?? extractCreditDays(data.terms || data.erp_terms || '');
                }
                updateDueDatePreview();
            }

            function extractCreditDays(text) {
                const match = String(text || '').match(/\d+/);
                return match ? Number(match[0]) : 0;
            }

            fields.creditDays.addEventListener('input', () => {
                fields.creditDays.dataset.touched = '1';
            });

            [fields.creditDays, fields.graceDays, fields.paymentSchedule, fields.overridePaymentDay, fields.previewInvoiceDate].forEach(input => {
                input.addEventListener('input', updateDueDatePreview);
                input.addEventListener('change', updateDueDatePreview);
            });

            function toDateInputValue(date) {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}`;
            }

            function parseDateInput(value) {
                if (!value) return null;
                const parts = value.split('-').map(Number);
                if (parts.length !== 3 || parts.some(Number.isNaN)) return null;
                return new Date(parts[0], parts[1] - 1, parts[2]);
            }

            function addDays(date, days) {
                const next = new Date(date.getTime());
                next.setDate(next.getDate() + days);
                return next;
            }

            function endOfMonth(date) {
                return new Date(date.getFullYear(), date.getMonth() + 1, 0);
            }

            function fixedDayCandidate(date, day) {
                const clamped = Math.max(1, Math.min(31, day));
                let candidate = new Date(date.getFullYear(), date.getMonth(), Math.min(clamped, endOfMonth(date).getDate()));
                if (candidate < date) {
                    const nextMonth = new Date(date.getFullYear(), date.getMonth() + 1, 1);
                    candidate = new Date(nextMonth.getFullYear(), nextMonth.getMonth(), Math.min(clamped, endOfMonth(nextMonth).getDate()));
                }
                return candidate;
            }

            function updateDueDatePreview() {
                const base = parseDateInput(fields.previewInvoiceDate.value);
                if (!base) {
                    fields.dueDatePreview.textContent = 'Select an invoice date to preview.';
                    return;
                }

                let due = addDays(base, Number(fields.creditDays.value || 0));
                const option = fields.paymentSchedule.selectedOptions[0];
                const scheduleType = option?.dataset.scheduleType || '';
                const paymentDay = Number(fields.overridePaymentDay.value || option?.dataset.paymentDay || 0);

                if (scheduleType === 'end_of_month') {
                    due = endOfMonth(due);
                } else if (scheduleType === 'fixed_day' && paymentDay > 0) {
                    due = fixedDayCandidate(due, paymentDay);
                }

                const graceDays = Number(fields.graceDays.value || 0);
                const contractualDue = toDateInputValue(due);
                if (graceDays > 0) {
                    due = addDays(due, graceDays);
                    fields.dueDatePreview.textContent = `${toDateInputValue(due)} (${contractualDue} + ${graceDays} grace days)`;
                    return;
                }

                fields.dueDatePreview.textContent = contractualDue;
            }

            document.getElementById('btnCreateTerm').addEventListener('click', function () {
                resetForm();
                titleEl.textContent = 'เพิ่มเงื่อนไขรับชำระลูกค้า';
                form.action = storeUrl;
                methodInput.value = 'POST';
                modal.show();
            });

            document.querySelectorAll('.js-edit-term').forEach(button => {
                button.addEventListener('click', function () {
                    resetForm();
                    const term = JSON.parse(this.dataset.term);
                    const option = {
                        id: `${term.erp_source}|${term.customer_code}`,
                        erp_source: term.erp_source,
                        source_label: term.erp_source === 'pgsqlp' ? 'PLUS' : 'WIRE',
                        customer_code: term.customer_code,
                        customer_name: term.customer_name,
                        terms: term.erp_terms || '',
                        credit_days: term.credit_days || 0,
                    };

                    customerSelect.addOption(option);
                    customerSelect.setValue(option.id, true);
                    fillCustomer(option);

                    fields.creditDays.value = term.credit_days ?? 0;
                    fields.creditDays.dataset.touched = '1';
                    fields.graceDays.value = term.grace_days ?? 0;
                    fields.effectiveFrom.value = term.effective_from || '';
                    fields.effectiveTo.value = term.effective_to || '';
                    fields.creditTermCode.value = term.credit_term_code || '';
                    fields.creditTermDetail.value = term.credit_term_detail || '';
                    fields.billingPlan.value = term.billing_plan_id || '';
                    fields.paymentSchedule.value = term.payment_schedule_id || '';
                    fields.overridePaymentDay.value = term.override_payment_day || '';
                    fields.isActive.value = term.is_active ? '1' : '0';
                    fields.remark.value = term.remark || '';
                    updateDueDatePreview();

                    titleEl.textContent = 'แก้ไขเงื่อนไขรับชำระลูกค้า';
                    form.action = @json(url('/accounting/customer-payment-terms')) + '/' + term.id;
                    methodInput.value = 'PUT';
                    modal.show();
                });
            });

            document.querySelectorAll('.js-disable-form').forEach(disableForm => {
                disableForm.addEventListener('submit', function (event) {
                    if (!confirm('ยืนยันปิดการใช้งานรายการนี้?')) {
                        event.preventDefault();
                    }
                });
            });

            function resetForm() {
                form.reset();
                form.action = storeUrl;
                methodInput.value = 'POST';
                delete fields.creditDays.dataset.touched;
                customerSelect.clear(true);
                customerSelect.clearOptions();
                Object.values(fields).forEach(input => {
                    if (input.tagName === 'INPUT' || input.tagName === 'TEXTAREA') {
                        input.value = '';
                    }
                });
                fields.creditDays.value = 0;
                fields.graceDays.value = 0;
                fields.isActive.value = '1';
                fields.previewInvoiceDate.value = toDateInputValue(new Date());
                updateDueDatePreview();
            }
        });
    </script>
@endpush
