@extends('layouts.layout')

@section('title', 'Grating Performance Inquiry')
@section('page-title', 'Grating Performance Inquiry')

@section('content')
    @php
        $fmt = fn($value, $dec = 1) => number_format((float) $value, $dec);
        $hours = fn($minutes) => $minutes ? number_format($minutes / 60, 1) : '0.0';
    @endphp

    <style>
        .gp-wrap { background:#f5f7fa; border-radius:8px; padding:16px; }
        .gp-panel { background:#fff; border:1px solid #dfe5ec; border-radius:8px; overflow:visible; }
        .gp-head { padding:12px 16px; border-bottom:1px solid #e8edf2; display:flex; align-items:center; justify-content:space-between; gap:12px; font-weight:700; }
        .gp-table-wrap { max-height:640px; overflow:auto; }
        .gp-table th { position:sticky; top:0; z-index:2; background:#edf4ff; white-space:nowrap; }
        .gp-table td { vertical-align:middle; }
        .gp-table .gp-sticky-date { position:sticky; left:0; z-index:3; background:#fff; min-width:92px; }
        .gp-table th.gp-sticky-date { z-index:5; background:#edf4ff; }
        .gp-table .gp-sticky-mfg { position:sticky; left:92px; z-index:3; background:#fff; min-width:132px; }
        .gp-table th.gp-sticky-mfg { z-index:5; background:#edf4ff; }
        .gp-table .gp-sticky-action { position:sticky; right:0; z-index:3; background:#fff; min-width:88px; }
        .gp-table th.gp-sticky-action { z-index:5; background:#edf4ff; }
        .gp-table tbody tr:hover .gp-sticky-date,
        .gp-table tbody tr:hover .gp-sticky-mfg,
        .gp-table tbody tr:hover .gp-sticky-action { background:#f8fbff; }
        .gp-summary-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px; }
        .gp-summary-card { border:1px solid #dfe5ec; border-radius:8px; background:#fff; padding:10px 12px; }
        .gp-summary-card .label { color:#6b7280; font-size:.78rem; }
        .gp-summary-card .value { font-weight:800; font-size:1.15rem; line-height:1.2; }
        .gp-subline { display:block; color:#6b7280; font-size:.78rem; line-height:1.35; }
        .gp-project-wrap { position:relative; }
        .gp-mfg-list { position:absolute; z-index:20; background:#fff; border:1px solid #ced4da; border-radius:6px; width:100%; max-height:240px; overflow:auto; display:none; }
        .gp-mfg-list button { display:block; width:100%; border:0; background:#fff; padding:8px 10px; text-align:left; }
        .gp-mfg-list button:hover { background:#eef4ff; }
        .gp-mfg-item-main { font-weight:700; color:#1f2937; }
        .gp-mfg-item-sub { font-size:.78rem; color:#64748b; margin-top:2px; }
        .num { text-align:right; font-variant-numeric:tabular-nums; }
        .gp-autosize { resize:none; overflow:hidden; min-height:38px; line-height:1.4; }
    </style>

    <div class="gp-wrap">
        @if (!empty($setupMissing))
            <div class="alert alert-warning">ยังไม่พบตาราง Grating Performance กรุณารัน migration ก่อนใช้งาน</div>
        @endif

        <div class="gp-panel mb-3">
            <div class="gp-head">
                <span>รายการ Grating Performance</span>
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ route('dp.grating-performance.entries.create') }}" class="btn btn-sm btn-primary"><i class="fas fa-plus me-1"></i> Input</a>
                    <a href="{{ route('dp.grating-performance.index') }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-chart-line me-1"></i> Dashboard</a>
                    <a href="{{ route('dp.grating-performance.masters') }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-sliders me-1"></i> Masters</a>
                </div>
            </div>
            <form method="GET" class="p-3">
                <div class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label">จากวันที่</label>
                        <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">ถึงวันที่</label>
                        <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">MFG</label>
                        <input type="text" name="mfg_no" class="form-control" value="{{ $filters['mfg_no'] }}">
                    </div>
                    <div class="col-md-2 gp-project-wrap">
                        <label class="form-label">โครงการ</label>
                        <input type="text" name="project" class="form-control gp-project-autocomplete" value="{{ $filters['project'] ?? '' }}" autocomplete="off">
                        <div class="gp-project-list gp-mfg-list"></div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">เลข SO</label>
                        <input type="text" name="salesorder" class="form-control" value="{{ $filters['salesorder'] ?? '' }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Step</label>
                        <select name="step_id" class="form-select">
                            <option value="">ทั้งหมด</option>
                            @foreach ($steps as $step)
                                <option value="{{ $step->id }}" @selected((string) $filters['step_id'] === (string) $step->id)>{{ $step->step_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">พนักงาน</label>
                        <select name="employee_id" class="form-select">
                            <option value="">ทั้งหมด</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}" @selected((string) $filters['employee_id'] === (string) $employee->id)>{{ $employee->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 d-grid">
                        <button class="btn btn-outline-primary"><i class="fas fa-filter me-1"></i> Filter</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="gp-summary-grid mb-3">
            <div class="gp-summary-card">
                <div class="label">Records</div>
                <div class="value">{{ number_format($summary['entry_count'] ?? 0) }}</div>
                <span class="gp-subline">MFG {{ number_format($summary['mfg_count'] ?? 0) }}</span>
            </div>
            <div class="gp-summary-card">
                <div class="label">ยอดดี</div>
                <div class="value">{{ $fmt($summary['good_pcs'] ?? 0, 0) }} ชิ้น</div>
                <span class="gp-subline">{{ $fmt($summary['good_kg'] ?? 0) }} กก.</span>
            </div>
            <div class="gp-summary-card">
                <div class="label">ยอดเสีย</div>
                <div class="value">{{ $fmt($summary['bad_pcs'] ?? 0, 0) }} ชิ้น</div>
                <span class="gp-subline">{{ $fmt($summary['bad_kg'] ?? 0) }} กก.</span>
            </div>
            <div class="gp-summary-card">
                <div class="label">สถานะงาน</div>
                <div class="value">{{ number_format($summary['finished_count'] ?? 0) }} จบ</div>
                <span class="gp-subline">{{ number_format($summary['open_count'] ?? 0) }} ยังไม่จบ</span>
            </div>
        </div>

        <div class="gp-panel">
            <div class="gp-head"><span>รายการที่บันทึก</span><small class="text-muted">{{ method_exists($rows, 'total') ? number_format($rows->total()) : 0 }} records</small></div>
            <div class="gp-table-wrap">
                <table class="table table-sm table-bordered gp-table mb-0">
                    <thead>
                        <tr>
                            <th class="gp-sticky-date">วันที่</th>
                            <th class="gp-sticky-mfg">MFG</th>
                            <th>โครงการ / เลข SO</th>
                            <th>Step</th>
                            <th>พนักงาน</th>
                            <th>เริ่ม</th>
                            <th>จบ</th>
                            <th class="num">ชม.</th>
                            <th class="num">ยอดดี</th>
                            <th class="num">ยอดเสีย</th>
                            <th class="num">แผน / เป้า</th>
                            <th>งานหน้างาน</th>
                            <th>จบงาน</th>
                            <th>หมายเหตุ</th>
                            <th class="gp-sticky-action">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            @php
                                $targetPcs = ($row->duration_minutes && ($row->target_pcs_per_hour ?? null)) ? ($row->duration_minutes / 60 * $row->target_pcs_per_hour) : 0;
                                $targetKg = ($row->duration_minutes && $row->target_kg_per_hour) ? ($row->duration_minutes / 60 * $row->target_kg_per_hour) : 0;
                            @endphp
                            <tr>
                                <td class="gp-sticky-date">{{ \Carbon\Carbon::parse($row->work_date)->format('d/m/Y') }}</td>
                                <td class="gp-sticky-mfg">
                                    @if ($row->is_field_work && ($row->field_mfgs ?? ''))
                                        {{ $row->field_mfgs }}
                                        <span class="gp-subline">งานหน้างาน</span>
                                    @else
                                        {{ $row->mfg_no }}
                                    @endif
                                </td>
                                <td>
                                    {{ ($row->project ?? null) ?: '-' }}
                                    @if($row->salesorder ?? null)
                                        <div class="small text-muted">{{ $row->salesorder }}</div>
                                    @endif
                                </td>
                                <td>{{ $row->step_name }}</td>
                                <td>{{ $row->employee_names }}<div class="small text-muted">{{ number_format($row->team_size) }} คน</div></td>
                                <td>{{ \Carbon\Carbon::parse($row->started_at)->format('H:i') }}</td>
                                <td>{{ $row->finished_at ? \Carbon\Carbon::parse($row->finished_at)->format('H:i') : '-' }}</td>
                                <td class="num">{{ $hours($row->duration_minutes) }}</td>
                                <td class="num">
                                    <strong>{{ $fmt($row->good_qty_pcs, 0) }}</strong>
                                    <span class="gp-subline">{{ $fmt($row->good_qty_kg) }} กก.</span>
                                </td>
                                <td class="num">
                                    <strong>{{ $fmt($row->bad_qty_pcs, 0) }}</strong>
                                    <span class="gp-subline">{{ $fmt($row->bad_qty_kg) }} กก.</span>
                                </td>
                                <td class="num">
                                    <strong>{{ ($row->plan_qty_pcs ?? null) ? $fmt($row->plan_qty_pcs, 0) : '-' }}</strong>
                                    <span class="gp-subline">เป้า {{ $targetPcs > 0 ? $fmt($targetPcs, 0).' ชิ้น' : '-' }}</span>
                                    @if ($targetKg > 0)
                                        <span class="gp-subline">{{ $fmt($targetKg) }} กก.</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($row->is_field_work)
                                        <span class="badge bg-info text-dark">{{ $row->field_activity }}</span>
                                        <div class="small text-muted">{{ $row->field_details }}</div>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td>{!! $row->is_finished ? '<span class="badge bg-success">จบงาน</span>' : '<span class="badge bg-warning text-dark">ยังไม่จบ</span>' !!}</td>
                                <td>{{ $row->notes }}</td>
                                <td class="text-nowrap gp-sticky-action">
                                    <div class="d-grid gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#edit-entry-{{ $row->id }}">แก้ไข</button>
                                    <form method="POST" action="{{ route('dp.grating-performance.entries.destroy', $row->id) }}" class="gp-delete-entry-form" data-entry-label="{{ $row->is_field_work ? ($row->project ?? 'Field work') : $row->mfg_no }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger w-100">ยกเลิก</button>
                                    </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="15" class="text-center text-muted py-4">ยังไม่มีข้อมูล</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @foreach ($rows as $row)
                @php
                    $startValue = $row->started_at ? \Carbon\Carbon::parse($row->started_at)->format('H:i') : '';
                    $finishValue = $row->finished_at ? \Carbon\Carbon::parse($row->finished_at)->format('H:i') : '';
                    $refUnitQty = ($row->good_qty_pcs ?? 0) > 0
                        ? ((float) $row->good_qty_kg / (float) $row->good_qty_pcs)
                        : ((($row->bad_qty_pcs ?? 0) > 0) ? ((float) $row->bad_qty_kg / (float) $row->bad_qty_pcs) : 0);
                @endphp
                <div class="modal fade" id="edit-entry-{{ $row->id }}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-scrollable">
                        <form method="POST" action="{{ route('dp.grating-performance.entries.update', $row->id) }}" class="modal-content gp-edit-form" data-entry-id="{{ $row->id }}" data-mfg-no="{{ $row->mfg_no }}" data-field-work="{{ $row->is_field_work ? '1' : '0' }}">
                            @csrf
                            @method('PUT')
                            @if ($row->is_field_work && ($row->field_mfgs ?? ''))
                                @foreach (array_values(array_filter(array_map('trim', explode(',', $row->field_mfgs)))) as $fieldMfgIndex => $fieldMfgNo)
                                    <input type="hidden" name="field_mfgs[{{ $fieldMfgIndex }}][mfg_no]" value="{{ $fieldMfgNo }}">
                                @endforeach
                            @endif
                            <div class="modal-header">
                                <h5 class="modal-title">แก้ไขรายการ {{ $row->mfg_no }}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">วันที่</label>
                                        <input type="date" name="work_date" class="form-control" value="{{ \Carbon\Carbon::parse($row->work_date)->toDateString() }}" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Step</label>
                                        <select name="step_id" class="form-select gp-edit-step" required>
                                            @foreach ($steps as $step)
                                                <option value="{{ $step->id }}" @selected((string) $row->step_id === (string) $step->id)>{{ $step->step_name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">เริ่ม</label>
                                        <input type="text" name="start_time" class="form-control gp-edit-time" value="{{ $startValue }}" maxlength="5" inputmode="numeric" pattern="^([01][0-9]|2[0-3]):[0-5][0-9]$" placeholder="08:50" title="รูปแบบเวลา HH:MM เช่น 08:50" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">จบ</label>
                                        <input type="text" name="finish_time" class="form-control gp-edit-time" value="{{ $finishValue }}" maxlength="5" inputmode="numeric" pattern="^([01][0-9]|2[0-3]):[0-5][0-9]$" placeholder="17:30" title="รูปแบบเวลา HH:MM เช่น 17:30">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">ยอดดี (ชิ้น)</label>
                                        <input type="number" name="good_qty_pcs" class="form-control num gp-edit-good-pcs" step="1" min="0" value="{{ (float) $row->good_qty_pcs }}">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">ยอดเสีย (ชิ้น)</label>
                                        <input type="number" name="bad_qty_pcs" class="form-control num gp-edit-bad-pcs" step="1" min="0" value="{{ (float) $row->bad_qty_pcs }}">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">ยอดดี (กก.)</label>
                                        <input type="number" name="good_qty_kg" class="form-control num gp-edit-good-kg" step="0.001" min="0" value="{{ (float) $row->good_qty_kg }}" @if($refUnitQty > 0) readonly @endif>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">ยอดเสีย (กก.)</label>
                                        <input type="number" name="bad_qty_kg" class="form-control num gp-edit-bad-kg" step="0.001" min="0" value="{{ (float) $row->bad_qty_kg }}" @if($refUnitQty > 0) readonly @endif>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">จำนวนแผน (ชิ้น)</label>
                                        <input type="number" name="plan_qty_pcs" class="form-control num gp-edit-plan" step="1" min="0" value="{{ $row->plan_qty_pcs ?? '' }}">
                                        <div class="form-text gp-edit-balance"></div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">ตร.ม./ชิ้น</label>
                                        <input type="number" name="sqm_per_piece" class="form-control num" step="0.000001" min="0" value="{{ $row->sqm_per_piece }}">
                                    </div>
                                    <input type="hidden" name="ref_unit_qty" class="gp-edit-ref" value="{{ $refUnitQty }}">
                                    <div class="col-md-3">
                                        <label class="form-label">โครงการ</label>
                                        <input type="text" name="project" class="form-control" maxlength="500" value="{{ $row->project ?? '' }}" @required($row->is_field_work)>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">เลข SO</label>
                                        <input type="text" name="salesorder" class="form-control" maxlength="80" value="{{ $row->salesorder ?? '' }}" @required($row->is_field_work)>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">กิจกรรมหน้างาน</label>
                                        <input type="text" name="field_activity" class="form-control" maxlength="500" value="{{ $row->field_activity }}">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">MFG หน้างาน</label>
                                        <input type="text" name="field_mfg_text" class="form-control" maxlength="1000" value="{{ $row->field_mfgs ?? '' }}" placeholder="คั่นหลาย MFG ด้วย comma">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">รายละเอียดงานหน้างาน</label>
                                        <textarea name="field_details" class="form-control gp-autosize" maxlength="1000" rows="1">{{ $row->field_details }}</textarea>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label d-block">จบงาน</label>
                                        <div class="form-check mt-2">
                                            <input type="hidden" name="is_finished" value="0">
                                            <input class="form-check-input" type="checkbox" name="is_finished" value="1" @checked($row->is_finished)>
                                            <label class="form-check-label">จบแล้ว</label>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">หมายเหตุ</label>
                                        <textarea name="notes" class="form-control gp-autosize" maxlength="1000" rows="1">{{ $row->notes }}</textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button>
                                <button type="submit" class="btn btn-primary">บันทึกแก้ไข</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endforeach
            @if (method_exists($rows, 'links'))
                <div class="p-3">{{ $rows->links() }}</div>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            const gpStepBalanceUrl = @json(route('dp.grating-performance.step-balance'));

            function numberValue(value) {
                const parsed = parseFloat(String(value ?? '').replace(/,/g, ''));
                return Number.isFinite(parsed) ? parsed : 0;
            }

            function formatNumber(value, digits = 0) {
                return numberValue(value).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: digits });
            }

            function autoGrow(area) {
                if (!area) return;
                area.style.height = 'auto';
                area.style.height = area.scrollHeight + 'px';
            }

            // เวลา: ใส่ ":" อัตโนมัติ HH:MM
            function formatTime(input) {
                const digits = input.value.replace(/[^0-9]/g, '').slice(0, 4);
                input.value = digits.length >= 3 ? `${digits.slice(0, 2)}:${digits.slice(2)}` : digits;
            }

            // คำนวณ กก. = ชิ้น × กก./ชิ้น (เหมือนหน้า create) เมื่อมี ref_unit_qty
            function recalcKg(form) {
                const ref = numberValue(form.querySelector('.gp-edit-ref')?.value);
                if (ref <= 0) return;
                const goodKg = form.querySelector('.gp-edit-good-kg');
                const badKg = form.querySelector('.gp-edit-bad-kg');
                if (goodKg) goodKg.value = (numberValue(form.querySelector('.gp-edit-good-pcs')?.value) * ref).toFixed(3);
                if (badKg) badKg.value = (numberValue(form.querySelector('.gp-edit-bad-pcs')?.value) * ref).toFixed(3);
            }

            function setBalanceText(form, data) {
                const target = form.querySelector('.gp-edit-balance');
                if (!data) {
                    form.dataset.usedQtyPcs = '0';
                    if (target) target.textContent = '';
                    return;
                }
                form.dataset.usedQtyPcs = String(data.used_qty_pcs || 0);
                if (target) {
                    target.textContent = `แผน ${formatNumber(data.plan_qty_pcs)} / ทำแล้ว (ไม่รวมรายการนี้) ${formatNumber(data.used_qty_pcs)} / คงเหลือ ${formatNumber(data.remaining_qty_pcs)}`;
                }
            }

            // ดึงยอดที่ทำแล้วของ MFG+step โดยไม่นับรายการที่กำลังแก้ (exclude_entry_id)
            function fetchEditBalance(form) {
                const mfgNo = (form.dataset.mfgNo || '').trim();
                const entryId = form.dataset.entryId;
                const stepId = form.querySelector('.gp-edit-step')?.value;
                const planQty = numberValue(form.querySelector('.gp-edit-plan')?.value);

                if (form.dataset.fieldWork === '1' || !mfgNo || !stepId || !planQty) {
                    setBalanceText(form, null);
                    return;
                }

                const params = new URLSearchParams({
                    mfg_no: mfgNo,
                    step_id: stepId,
                    plan_qty_pcs: String(planQty),
                    exclude_entry_id: String(entryId || '')
                });

                fetch(`${gpStepBalanceUrl}?${params.toString()}`, { headers: { 'Accept': 'application/json' } })
                    .then(response => response.ok ? response.json() : null)
                    .then(data => setBalanceText(form, data))
                    .catch(() => setBalanceText(form, null));
            }

            function validateEditForm(form) {
                if (form.dataset.fieldWork === '1') return true;
                const planQty = numberValue(form.querySelector('.gp-edit-plan')?.value);
                if (!planQty) return true;
                const totalPcs = numberValue(form.querySelector('.gp-edit-good-pcs')?.value) + numberValue(form.querySelector('.gp-edit-bad-pcs')?.value);
                const usedPcs = numberValue(form.dataset.usedQtyPcs || 0);
                return (usedPcs + totalPcs) <= (planQty + 0.0001);
            }

            function bindProjectAutocomplete() {
                document.querySelectorAll('.gp-project-autocomplete').forEach(projectInput => {
                    const wrap = projectInput.closest('.gp-project-wrap');
                    const list = wrap?.querySelector('.gp-project-list');
                    const salesorderInput = projectInput.closest('form')?.querySelector('[name="salesorder"]');
                    let timer = null;
                    if (!list) return;

                    projectInput.addEventListener('input', function () {
                        clearTimeout(timer);
                        const q = projectInput.value.trim();
                        if (q.length < 2) {
                            list.style.display = 'none';
                            return;
                        }
                        timer = setTimeout(function () {
                            fetch('{{ route('api.grating-projects.search') }}?q=' + encodeURIComponent(q) + '&limit=8', { headers: { 'Accept': 'application/json' } })
                                .then(response => response.ok ? response.json() : { results: [] })
                                .then(data => {
                                    list.innerHTML = '';
                                    (data.results || []).forEach(item => {
                                        const meta = item.meta || {};
                                        const button = document.createElement('button');
                                        button.type = 'button';
                                        button.innerHTML = `
                                            <div class="gp-mfg-item-main">${item.text || item.id || ''}</div>
                                            <div class="gp-mfg-item-sub">${meta.source === 'master' ? 'Master' : (meta.site || 'WO Notes')} | SO: ${meta.salesorder || '-'}</div>
                                        `;
                                        button.addEventListener('click', function () {
                                            projectInput.value = meta.project || item.text || item.id || '';
                                            if (salesorderInput && !salesorderInput.value && meta.salesorder) {
                                                salesorderInput.value = meta.salesorder;
                                            }
                                            list.style.display = 'none';
                                        });
                                        list.appendChild(button);
                                    });
                                    list.style.display = list.children.length ? 'block' : 'none';
                                });
                        }, 250);
                    });
                });
            }

            bindProjectAutocomplete();

            // --- event delegation ---
            document.addEventListener('input', function (event) {
                const t = event.target;
                if (t.classList.contains('gp-autosize')) autoGrow(t);
                if (t.classList.contains('gp-edit-time')) formatTime(t);
                if (t.classList.contains('gp-edit-good-pcs') || t.classList.contains('gp-edit-bad-pcs')) {
                    recalcKg(t.closest('.gp-edit-form'));
                }
                if (t.classList.contains('gp-edit-plan')) {
                    fetchEditBalance(t.closest('.gp-edit-form'));
                }
            });

            document.addEventListener('change', function (event) {
                if (event.target.classList.contains('gp-edit-step')) {
                    fetchEditBalance(event.target.closest('.gp-edit-form'));
                }
            });

            document.addEventListener('shown.bs.modal', function (event) {
                event.target.querySelectorAll('.gp-autosize').forEach(autoGrow);
                const form = event.target.querySelector('.gp-edit-form');
                if (form) {
                    recalcKg(form);
                    fetchEditBalance(form);
                }
            });

            document.addEventListener('submit', function (event) {
                const form = event.target.closest('.gp-edit-form');
                if (!form) return;
                if (!validateEditForm(form)) {
                    event.preventDefault();
                    const planQty = numberValue(form.querySelector('.gp-edit-plan')?.value);
                    if (window.Swal) {
                        Swal.fire({
                            icon: 'error',
                            title: 'จำนวนเกินแผนเปิด',
                            text: `จำนวนชิ้น (ที่ทำแล้ว + ที่กำลังแก้) เกินแผนเปิด ${formatNumber(planQty)} ชิ้นของ step นี้`,
                        });
                    } else {
                        alert('จำนวนชิ้นเกินแผนเปิดของ step นี้');
                    }
                }
            });

            document.addEventListener('submit', function (event) {
                const form = event.target.closest('.gp-delete-entry-form');
                if (!form || form.dataset.confirmed === '1') return;

                event.preventDefault();
                const entryLabel = form.dataset.entryLabel || '';
                const message = entryLabel
                    ? `ยืนยันยกเลิกรายการ ${entryLabel}?`
                    : 'ยืนยันยกเลิกรายการนี้?';

                if (!window.Swal) {
                    if (window.confirm(message)) {
                        form.dataset.confirmed = '1';
                        form.submit();
                    }
                    return;
                }

                const scrollY = window.scrollY;
                Swal.fire({
                    icon: 'warning',
                    title: 'ยืนยันยกเลิกรายการ?',
                    text: message,
                    showCancelButton: true,
                    confirmButtonText: 'ยกเลิกรายการ',
                    cancelButtonText: 'กลับ',
                    confirmButtonColor: '#dc3545',
                    heightAuto: false,
                    returnFocus: false,
                    didOpen: () => window.scrollTo({ top: scrollY }),
                }).then(result => {
                    if (result.isConfirmed) {
                        form.dataset.confirmed = '1';
                        form.submit();
                    }
                });
            });

            document.addEventListener('click', function (event) {
                document.querySelectorAll('.gp-project-list').forEach(list => {
                    const wrap = list.closest('.gp-project-wrap');
                    if (wrap && !wrap.contains(event.target)) {
                        list.style.display = 'none';
                    }
                });
            });
        })();
    </script>
@endpush
