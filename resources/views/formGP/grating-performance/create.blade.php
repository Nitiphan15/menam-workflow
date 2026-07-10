@extends('layouts.layout')

@section('title', 'Grating Performance Input')
@section('page-title', 'Grating Performance Input')

@section('content')
    @php
        $fmt = fn($value, $dec = 1) => number_format((float) $value, $dec);
        $employeePayload = $employees->map(fn($employee) => [
            'id' => (string) $employee->id,
            'name' => $employee->name,
            'nickname' => $employee->nickname,
            'responsible_work' => $employee->responsible_work,
        ])->values();
        $stepPayload = $steps->map(fn($step) => [
            'id' => (string) $step->id,
            'code' => $step->step_code,
            'name' => $step->step_name,
            'target_pcs' => $step->target_pcs_per_hour ?? null,
            'target_kg' => $step->target_kg_per_hour,
            'is_field_work' => (bool) $step->is_field_work,
            'label' => $step->step_name,
        ])->values();
        $fieldActivities = $fieldActivities ?? [];
    @endphp

    <style>
        .gp-wrap { background:#f5f7fa; border-radius:8px; padding:16px; }
        .gp-panel { background:#fff; border:1px solid #dfe5ec; border-radius:8px; overflow:visible; }
        .gp-head { padding:12px 16px; border-bottom:1px solid #e8edf2; display:flex; align-items:center; justify-content:space-between; gap:12px; font-weight:700; }
        .gp-form-section { padding:16px; border-bottom:1px solid #edf1f5; }
        .gp-form-section:last-child { border-bottom:0; }
        .gp-section-title { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px; font-weight:700; flex-wrap:wrap; }
        .gp-section-title small { font-weight:400; color:#64748b; }
        .gp-section-title .btn { flex:0 0 auto; white-space:nowrap; }
        .gp-soft-panel { background:#f8fbff; border:1px solid #dbeafe; border-radius:8px; padding:12px; }
        .gp-mfg-wrap { position:relative; }
        .gp-mfg-list { position:absolute; z-index:20; background:#fff; border:1px solid #ced4da; border-radius:6px; width:100%; max-height:240px; overflow:auto; display:none; }
        .gp-mfg-list button { display:block; width:100%; border:0; background:#fff; padding:8px 10px; text-align:left; }
        .gp-mfg-list button:hover { background:#eef4ff; }
        .gp-mfg-item-main { font-weight:700; color:#1f2937; }
        .gp-mfg-item-sub { font-size:.78rem; color:#64748b; margin-top:2px; }
        .gp-mfg-detail { display:none; margin-top:8px; border:1px solid #dbeafe; background:#f8fbff; border-radius:8px; padding:8px 10px; font-size:.82rem; color:#334155; }
        .gp-mfg-detail.is-visible { display:block; }
        .gp-mfg-detail.is-warning { border-color:#fde68a; background:#fffbeb; color:#92400e; }
        .gp-mfg-detail .title { font-weight:700; color:#1e40af; margin-bottom:4px; }
        .gp-mfg-detail .line { margin-top:2px; }
        .gp-field-mfg-wrap { position:relative; }
        .gp-field-mfg-tags { display:flex; flex-wrap:wrap; gap:6px; min-height:38px; border:1px solid #dfe5ec; border-radius:8px; padding:6px; background:#fff; }
        .gp-field-mfg-tag { display:inline-flex; align-items:center; gap:6px; border:1px solid #bfdbfe; background:#eff6ff; color:#1e40af; border-radius:999px; padding:4px 8px; font-size:.82rem; }
        .gp-field-mfg-tag button { border:0; background:transparent; color:#1e40af; padding:0; line-height:1; font-weight:700; }
        .gp-field-summary { display:none; margin-top:8px; border:1px solid #fde68a; background:#fffbeb; color:#92400e; border-radius:8px; padding:8px 10px; font-size:.84rem; }
        .gp-field-summary.is-visible { display:block; }
        .gp-mode-badge { display:none; align-items:center; gap:6px; border:1px solid #bfdbfe; background:#eff6ff; color:#1d4ed8; border-radius:999px; padding:4px 10px; font-size:.82rem; font-weight:700; }
        .gp-mode-badge.is-visible { display:inline-flex; }
        .gp-step-hint { background:#f8fbff; border:1px solid #dbeafe; border-radius:8px; padding:12px; min-height:80px; }
        .gp-chip { display:inline-flex; align-items:center; border:1px solid #b6d4fe; background:#eef6ff; color:#16427c; border-radius:999px; padding:4px 10px; margin:3px; font-size:.82rem; }
        .gp-check-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:8px 14px; }
        .gp-step-checklist { display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:8px 14px; background:#fff; border:1px solid #dfe5ec; border-radius:8px; padding:12px; min-height:88px; }
        .gp-step-checklist .form-check { margin:0; min-height:32px; }
        .gp-field-activities { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:8px 14px; background:#fff; border:1px solid #dfe5ec; border-radius:8px; padding:10px 12px; }
        .gp-field-activities .form-check { margin:0; min-height:30px; display:flex; align-items:center; gap:6px; }
        .gp-mfg-grid { align-items:start; }
        .gp-mfg-grid .form-control { min-height:38px; }
        .gp-mfg-grid .form-text { min-height:18px; margin-top:6px; }
        .gp-autosize { resize:none; overflow:hidden; min-height:38px; line-height:1.4; }
        .gp-entry-row { border:1px solid #dfe5ec; border-radius:8px; padding:12px; margin-bottom:12px; background:#fff; }
        .gp-entry-row:last-child { margin-bottom:0; }
        .gp-option-check { min-height:38px; margin:0; padding:8px 12px 8px 34px; background:#f8fbff; border:1px solid #dfe5ec; border-radius:8px; display:flex; align-items:center; white-space:nowrap; }
        .gp-option-check .form-check-input { margin-top:0; }
        /* coworker chip panel */
        .gp-coworker-panel { background:#f8fbff; border:1px solid #dbeafe; border-radius:8px; padding:12px; }
        .gp-coworker-label { font-weight:600; margin-bottom:10px; color:#1e40af; font-size:.9rem; }
        .gp-coworker-grid { display:flex; flex-wrap:wrap; gap:8px; }
        .gp-coworker-chip { display:flex; flex-direction:column; align-items:flex-start; gap:1px; border:1px solid #bfdbfe; border-radius:8px; padding:7px 12px; background:#fff; cursor:pointer; min-width:120px; transition:background .15s,border-color .15s; }
        .gp-coworker-chip:hover { background:#eff6ff; border-color:#93c5fd; }
        .gp-coworker-input { display:none; }
        .gp-coworker-input:checked ~ .gp-coworker-name { color:#1d4ed8; font-weight:700; }
        .gp-coworker-chip:has(.gp-coworker-input:checked) { background:#dbeafe; border-color:#3b82f6; }
        .gp-coworker-chip:has(.gp-coworker-input:disabled) { opacity:.4; pointer-events:none; }
        .gp-coworker-name { font-size:.88rem; font-weight:600; color:#374151; }
        .gp-coworker-role { font-size:.75rem; color:#6b7280; }
        .ts-dropdown { z-index:3000 !important; }
        .num { text-align:right; font-variant-numeric:tabular-nums; }
    </style>

    <div class="gp-wrap">
        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger mb-3">{{ $errors->first() }}</div>
        @endif
        @if (!empty($setupMissing))
            <div class="alert alert-warning">
                ยังไม่พบตาราง Grating Performance กรุณารัน migration ก่อนใช้งาน: <code>php artisan migrate</code>
            </div>
        @endif

        <div class="gp-panel">
            <div class="gp-head">
                <span>Input รายวัน</span>
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ route('grating-performance.index') }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-chart-line me-1"></i> Dashboard</a>
                    <a href="{{ route('grating-performance.inquiry') }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-table me-1"></i> Inquiry</a>
                    @can('GPM')
                    <a href="{{ route('grating-performance.masters') }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-sliders me-1"></i> Masters</a>
                    @endcan
                </div>
            </div>

            <form method="POST" action="{{ route('grating-performance.entries.store') }}">
                @csrf
                <div class="gp-form-section">
                    <div class="gp-section-title">
                        <span>ข้อมูลหลัก</span>
                        <small>เลือกวันและพนักงานก่อน ระบบจะแนะนำ step ที่พนักงานทำได้</small>
                    </div>
                    <div class="row g-3">
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">วันที่</label>
                        <input type="date" name="work_date" class="form-control" value="{{ old('work_date', now('Asia/Bangkok')->toDateString()) }}" required>
                    </div>
                    <div class="col-lg-4 col-md-8">
                        <label class="form-label">พนักงาน</label>
                        <select name="employee_id" id="gp-employee" class="form-select" required>
                            <option value="">พิมพ์ชื่อพนักงาน</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}" @selected(old('employee_id') == $employee->id)>
                                    {{ $employee->name }}{{ $employee->nickname ? ' - '.$employee->nickname : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-6">
                        <label class="form-label">หน้าที่ / step ที่เกี่ยวข้อง</label>
                        <div id="gp-step-hint" class="gp-step-hint text-muted">เลือกพนักงานก่อน ระบบจะแสดง step ที่เกี่ยวกับหน้าที่ของคนนั้น</div>
                    </div>
                    </div>
                </div>

                <div class="gp-form-section">
                    <div class="gp-section-title">
                        <span>ขั้นตอนงาน</span>
                        <small>เลือกขั้นตอนที่ทำจริงจาก Master เท่านั้น</small>
                        <input type="hidden" name="is_field_work" value="{{ old('is_field_work') ? 1 : 0 }}" id="gp-field-toggle">
                    </div>
                    <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Step จาก Master</label>
                        <div id="gp-step-checklist" class="gp-step-checklist">
                            @foreach ($steps as $step)
                                <label class="form-check">
                                    <input class="form-check-input gp-step-checkbox" type="radio" name="step_id" value="{{ $step->id }}" data-step-id="{{ $step->id }}" @checked(old('step_id') == $step->id)>
                                    <span class="form-check-label">
                                        {{ $step->step_name }}
                                        @if ($step->target_pcs_per_hour ?? null)
                                            <span class="text-muted">(เป้าหมาย {{ $fmt($step->target_pcs_per_hour) }} ชิ้น/ชม.)</span>
                                        @elseif ($step->target_kg_per_hour)
                                            <span class="text-muted">(เป้าหมาย {{ $fmt($step->target_kg_per_hour) }} กก./ชม.)</span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <div class="form-text">1 รายการเลือก step ที่ทำจริงได้เพียง 1 ขั้นตอน ถ้า MFG เดียวกันทำหลายขั้นตอนให้บันทึกเพิ่มอีกแถว</div>
                    </div>
                    <div class="col-12 d-none" id="gp-field-fields">
                        <div class="gp-soft-panel">
                        <div class="row g-2">
                            <div class="col-12">
                                <label class="form-label">กิจกรรมหน้างาน</label>
                                <div class="gp-field-activities">
                                    @foreach ($fieldActivities as $activity)
                                        <label class="form-check">
                                            <input class="form-check-input gp-field-activity" type="checkbox" name="field_activity[]" value="{{ $activity }}" @checked(collect(old('field_activity', []))->contains($activity))>
                                            <span class="form-check-label">{{ $activity }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">รายละเอียดงานหน้างาน</label>
                                <textarea name="field_details" class="form-control gp-autosize" maxlength="1000" rows="1" placeholder="เช่น ไปวัดพื้นที่จุดติดตั้ง, แก้ไขตำแหน่ง, ส่งมอบงาน">{{ old('field_details') }}</textarea>
                            </div>
                        </div>
                        </div>
                    </div>
                    </div>
                </div>

                <div class="gp-form-section">
                    <div class="gp-section-title">
                        <span>รายละเอียด MFG</span>
                        <span class="gp-mode-badge" id="gp-field-mode-badge"><i class="fas fa-location-dot"></i> งานหน้างาน</span>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="gp-add-entry">
                            <i class="fas fa-plus me-1"></i> เพิ่ม MFG
                        </button>
                    </div>
                    <div id="gp-entry-list">
                    <div class="row g-3 gp-mfg-grid gp-entry-row" data-entry-index="0">
                    {{-- แถว 1: MFG + ช่วงเวลา --}}
                    <div class="col-12 col-lg-6 gp-mfg-wrap">
                        <label class="form-label">MFG</label>
                        <input type="text" name="entries[0][mfg_no]" class="form-control gp-mfg-input" value="{{ old('entries.0.mfg_no', old('mfg_no')) }}" autocomplete="off" placeholder="พิมพ์ MFG เช่น G2600..." required>
                        <div class="gp-mfg-list"></div>
                        <input type="hidden" name="entries[0][mfg_site]" class="gp-mfg-site" value="{{ old('entries.0.mfg_site') }}">
                        <input type="hidden" name="entries[0][partnumber]" class="gp-partnumber" value="{{ old('entries.0.partnumber') }}">
                        <input type="hidden" name="entries[0][part_description]" class="gp-part-description" value="{{ old('entries.0.part_description') }}">
                        <input type="hidden" name="entries[0][part_unit]" class="gp-part-unit" value="{{ old('entries.0.part_unit') }}">
                        <input type="hidden" name="entries[0][ref_unit_qty]" class="gp-ref-unit-qty" value="{{ old('entries.0.ref_unit_qty') }}">
                        <input type="hidden" name="entries[0][plan_qty_pcs]" class="gp-plan-qty-pcs" value="{{ old('entries.0.plan_qty_pcs') }}">
                        <input type="hidden" name="entries[0][width_mm]" class="gp-width-mm" value="{{ old('entries.0.width_mm') }}">
                        <input type="hidden" name="entries[0][length_mm]" class="gp-length-mm" value="{{ old('entries.0.length_mm') }}">
                        <input type="hidden" name="entries[0][sqm_per_piece]" class="gp-sqm-per-piece" value="{{ old('entries.0.sqm_per_piece') }}">
                        <div class="gp-mfg-detail"></div>
                        <div class="form-text">ค้นจากฐาน MFG Wire/Plus และเริ่มต้นเป็นงานเดี่ยวของพนักงานหลัก</div>
                    </div>
                    <div class="col-12 col-lg-6 gp-field-mfg-wrap d-none">
                        <label class="form-label">MFG ที่เกี่ยวข้องกับงานหน้างาน</label>
                        <input type="text" class="form-control gp-field-mfg-input" autocomplete="off" placeholder="พิมพ์ MFG แล้วเลือกได้หลายรายการ">
                        <div class="gp-field-mfg-list gp-mfg-list"></div>
                        <div class="gp-field-mfg-tags mt-2"></div>
                        <div class="gp-field-summary"></div>
                        <div class="form-text">ใช้สำหรับอ้างอิง/ค้นหาเท่านั้น ไม่เอาไปคิดยอดชิ้นหรือกิโล</div>
                    </div>
                    <div class="col-12 col-md-8 col-lg-5 gp-project-wrap position-relative">
                        <label class="form-label">โครงการ</label>
                        <input type="text" name="entries[0][project]" class="form-control gp-project" maxlength="500" autocomplete="off" value="{{ old('entries.0.project') }}">
                        <div class="gp-project-list gp-mfg-list"></div>
                        <div class="form-text gp-field-project-note d-none">งานหน้างานให้เลือก/กรอกโครงการเอง เป็นค่ากลางของรายการนี้</div>
                    </div>
                    <div class="col-12 col-lg-4">
                        <label class="form-label">เลข SO</label>
                        <input type="text" name="entries[0][salesorder]" class="form-control gp-salesorder" maxlength="80" value="{{ old('entries.0.salesorder') }}">
                        <div class="form-text gp-field-project-note d-none">งานหน้างานให้เลือก/กรอก SO เอง</div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <label class="form-label">เริ่ม</label>
                        <input type="text" name="entries[0][start_time]" class="form-control gp-time-input" value="{{ old('entries.0.start_time', old('start_time', '08:00')) }}" inputmode="numeric" maxlength="5" pattern="^([01][0-9]|2[0-3]):[0-5][0-9]$" placeholder="08:00" required>
                    </div>
                    <div class="col-6 col-lg-3">
                        <label class="form-label">จบ</label>
                        <input type="text" name="entries[0][finish_time]" class="form-control gp-time-input" value="{{ old('entries.0.finish_time', old('finish_time', '17:00')) }}" inputmode="numeric" maxlength="5" pattern="^([01][0-9]|2[0-3]):[0-5][0-9]$" placeholder="17:00">
                    </div>

                    {{-- แถว 2: ยอดดี/เสีย จับคู่ตามหน่วย ชิ้น → กก. → ตร.ม. --}}
                    <div class="col-6 col-md-4 col-lg-2 gp-production-field">
                        <label class="form-label">ยอดดี (ชิ้น)</label>
                        <input type="number" step="1" min="0" inputmode="numeric" name="entries[0][good_qty_pcs]" class="form-control num gp-good-pcs" value="{{ old('entries.0.good_qty_pcs', 0) }}">
                    </div>
                    <div class="col-6 col-md-4 col-lg-2 gp-production-field">
                        <label class="form-label">ยอดเสีย (ชิ้น)</label>
                        <input type="number" step="1" min="0" inputmode="numeric" name="entries[0][bad_qty_pcs]" class="form-control num gp-bad-pcs" value="{{ old('entries.0.bad_qty_pcs', 0) }}">
                    </div>
                    <div class="col-6 col-md-4 col-lg-2 gp-production-field">
                        <label class="form-label">ยอดดี (กก.)</label>
                        <input type="number" step="0.001" min="0" inputmode="numeric" name="entries[0][good_qty_kg]" class="form-control num gp-good-kg" value="{{ old('entries.0.good_qty_kg', old('good_qty_kg', 0)) }}" readonly>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2 gp-production-field">
                        <label class="form-label">ยอดเสีย (กก.)</label>
                        <input type="number" step="0.001" min="0" inputmode="numeric" name="entries[0][bad_qty_kg]" class="form-control num gp-bad-kg" value="{{ old('entries.0.bad_qty_kg', old('bad_qty_kg', 0)) }}" readonly>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2 gp-production-field">
                        <label class="form-label">ยอดดี ตร.ม.</label>
                        <input type="number" step="0.001" min="0" name="entries[0][good_area_sqm]" class="form-control num gp-good-sqm" value="{{ old('entries.0.good_area_sqm', 0) }}" readonly>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2 gp-production-field">
                        <label class="form-label">ยอดเสีย ตร.ม.</label>
                        <input type="number" step="0.001" min="0" name="entries[0][bad_area_sqm]" class="form-control num gp-bad-sqm" value="{{ old('entries.0.bad_area_sqm', 0) }}" readonly>
                    </div>

                    {{-- แถว 3: จำนวนแผน + ข้อมูลอ้างอิงจาก MFG --}}
                    <div class="col-12 col-md-4 col-lg-3 gp-production-field">
                        <label class="form-label">จำนวนแผน (ชิ้น)</label>
                        <input type="number" step="1" min="0" class="form-control num gp-plan-display" value="{{ old('entries.0.plan_qty_pcs') }}" readonly>
                        <div class="form-text gp-step-balance"></div>
                    </div>
                    <div class="col-12">
                        <div class="row g-2 align-items-end">
                            <div class="col-auto">
                                <label class="form-label d-block mb-1">จบงาน</label>
                                <div class="form-check gp-option-check">
                                    <input type="hidden" name="entries[0][is_finished]" value="0">
                                    <input class="form-check-input gp-is-finished" type="checkbox" name="entries[0][is_finished]" value="1" @checked(old('entries.0.is_finished', old('is_finished')) === '1')>
                                    <label class="form-check-label">จบแล้ว</label>
                                </div>
                            </div>
                            <div class="col-auto">
                                <label class="form-label d-block mb-1">ผู้ร่วมงาน</label>
                                <div class="form-check gp-option-check">
                                    <input class="form-check-input gp-team-toggle" type="checkbox">
                                    <label class="form-check-label">ทำร่วมกับคนอื่น</label>
                                </div>
                            </div>
                            <div class="col">
                                <label class="form-label mb-1">หมายเหตุ</label>
                                <textarea name="entries[0][notes]" class="form-control gp-autosize" maxlength="1000" rows="1">{{ old('entries.0.notes', old('notes')) }}</textarea>
                            </div>
                            <div class="col-auto">
                                <button type="button" class="btn btn-outline-danger gp-remove-entry d-none" aria-label="ลบรายการ">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 d-none gp-entry-coworkers">
                        <div class="gp-coworker-panel">
                            <div class="gp-coworker-label"><i class="fas fa-users me-1"></i> เลือกผู้ร่วมงานของ MFG นี้</div>
                            <div class="gp-coworker-grid">
                                @foreach ($employees as $employee)
                                    <label class="gp-coworker-chip">
                                        <input class="gp-coworker-input gp-coworker" type="checkbox" name="entries[0][coworker_ids][]" value="{{ $employee->id }}" @checked(collect(old('entries.0.coworker_ids', old('coworker_ids', [])))->contains($employee->id))>
                                        <span class="gp-coworker-name">{{ $employee->name }}</span>
                                        @if($employee->responsible_work)
                                            <span class="gp-coworker-role">{{ $employee->responsible_work }}</span>
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                            <div class="form-text mt-1">ยอดดี/เสียเป็นของ MFG รายการเดียว ไม่คูณตามจำนวนคน</div>
                        </div>
                    </div>
                    </div>
                    </div>
                </div>

                <div class="gp-form-section">
                    <div class="row g-3">
                    <div class="col-12 d-none" id="gp-coworkers">
                        <label class="form-label">เลือกผู้ร่วมงาน</label>
                        <div class="gp-check-grid">
                            @foreach ($employees as $employee)
                                <label class="form-check">
                                    <input class="form-check-input gp-coworker" type="checkbox" name="coworker_ids[]" value="{{ $employee->id }}" @checked(collect(old('coworker_ids', []))->contains($employee->id))>
                                    <span class="form-check-label">{{ $employee->name }}{{ $employee->responsible_work ? ' - '.$employee->responsible_work : '' }}</span>
                                </label>
                            @endforeach
                        </div>
                        <div class="form-text">ยอดดี/เสียเป็นของ MFG รายการเดียว ไม่คูณตามจำนวนคน</div>
                    </div>

                    <div class="col-12 d-flex justify-content-end gap-2">
                        <a href="{{ route('grating-performance.index') }}" class="btn btn-outline-secondary">ยกเลิก</a>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> บันทึก</button>
                    </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
        <script>
            const gpEmployees = @json($employeePayload);
            const gpSteps = @json($stepPayload);
            const gpHasStepOld = @json((bool) old('step_id'));
            const gpEntrySaved = @json((bool) session('grating_entry_saved'));
            const gpStepBalanceUrl = @json(route('grating-performance.step-balance'));

            document.addEventListener('DOMContentLoaded', function () {
                const form = document.querySelector('form[action="{{ route('grating-performance.entries.store') }}"]');
                const employeeSelect = document.getElementById('gp-employee');
                const stepCheckboxes = Array.from(document.querySelectorAll('.gp-step-checkbox'));
                const stepHint = document.getElementById('gp-step-hint');
                const entryList = document.getElementById('gp-entry-list');
                const addEntryButton = document.getElementById('gp-add-entry');
                const fieldToggle = document.getElementById('gp-field-toggle');
                const fieldFields = document.getElementById('gp-field-fields');
                const fieldModeBadge = document.getElementById('gp-field-mode-badge');
                const fieldActivities = Array.from(document.querySelectorAll('.gp-field-activity'));
                let preserveInitialStepSelection = gpHasStepOld;
                let entryIndex = entryList ? entryList.querySelectorAll('.gp-entry-row').length : 1;
                let employeeTom = null;

                if (window.TomSelect && employeeSelect) {
                    employeeTom = new TomSelect(employeeSelect, { dropdownParent: 'body' });
                }
                function matchingSteps(responsibleWork) {
                    const text = String(responsibleWork || '').toLowerCase();
                    if (!text) return [];
                    const tokens = text.split(/[,/| ]+/).map(token => token.trim()).filter(token => token.length >= 2);
                    return gpSteps.filter(step => {
                        const haystack = `${step.code} ${step.name}`.toLowerCase();
                        return tokens.some(token => haystack.includes(token) || token.includes(String(step.name).toLowerCase()));
                    });
                }

                function escapeHtml(value) {
                    return String(value || '').replace(/[&<>"']/g, function (char) {
                        return ({
                            '&': '&amp;',
                            '<': '&lt;',
                            '>': '&gt;',
                            '"': '&quot;',
                            "'": '&#039;'
                        })[char];
                    });
                }

                function setStepChecks(stepIds, replace = true) {
                    const selected = new Set((stepIds || []).slice(0, 1).map(id => String(id)));
                    stepCheckboxes.forEach(input => {
                        if (replace) {
                            input.checked = selected.has(String(input.value));
                        } else if (selected.has(String(input.value))) {
                            input.checked = true;
                        }
                    });
                    syncFieldWork();
                }

                function hasFieldStepChecked() {
                    const fieldStepIds = gpSteps
                        .filter(step => step.is_field_work)
                        .map(step => String(step.id));
                    return stepCheckboxes.some(input => input.checked && fieldStepIds.includes(String(input.value)));
                }

                function updateEmployeeHint() {
                    const employee = gpEmployees.find(item => item.id === String(employeeSelect.value));
                    document.querySelectorAll('.gp-coworker').forEach(input => {
                        input.disabled = input.value === String(employeeSelect.value);
                        if (input.disabled) input.checked = false;
                    });

                    if (!employee) {
                        if (!preserveInitialStepSelection) {
                            setStepChecks([], true);
                        }
                        preserveInitialStepSelection = false;
                        stepHint.className = 'gp-step-hint text-muted';
                        stepHint.textContent = 'เลือกพนักงานก่อน ระบบจะแสดง step ที่เกี่ยวกับหน้าที่ของคนนั้น';
                        return;
                    }

                    const steps = matchingSteps(employee.responsible_work);
                    if (!preserveInitialStepSelection) {
                        setStepChecks(steps.length === 1 ? [steps[0].id] : [], true);
                    }
                    preserveInitialStepSelection = false;
                    const name = escapeHtml(employee.name);
                    const responsible = escapeHtml(employee.responsible_work || '-');
                    if (!steps.length) {
                        stepHint.className = 'gp-step-hint';
                        stepHint.innerHTML = `<div class="fw-semibold">${name}</div><div class="text-muted">หน้าที่: ${responsible}</div><div class="mt-2 text-warning">ยัง match กับ master step ไม่ได้ เลือกขั้นตอนที่ทำจริงจากรายการด้านล่าง หรือเพิ่ม/แก้ไขที่หน้า Masters</div>`;
                        return;
                    }

                    stepHint.className = 'gp-step-hint';
                    stepHint.innerHTML = `<div class="fw-semibold">${name}</div><div class="text-muted">หน้าที่: ${responsible}</div><div class="mt-2">${steps.map(step => `<button type="button" class="gp-chip" data-step-id="${escapeHtml(step.id)}">${escapeHtml(step.label)}</button>`).join('')}</div><div class="small text-muted mt-1">คลิกเพื่อเลือกขั้นตอนที่ทำจริงใน MFG นี้ 1 ขั้นตอน</div>`;
                }

                stepHint.addEventListener('click', function (event) {
                    const chip = event.target.closest('[data-step-id]');
                    if (!chip) return;
                    const input = stepCheckboxes.find(item => String(item.value) === String(chip.dataset.stepId));
                    if (input) {
                        input.checked = true;
                        input.focus();
                        syncFieldWork();
                        refreshAllStepBalances();
                    }
                });

                employeeSelect.addEventListener('change', updateEmployeeHint);
                updateEmployeeHint();

                function syncRowCoworkerState(row) {
                    const toggle = row.querySelector('.gp-team-toggle');
                    const coworkers = row.querySelector('.gp-entry-coworkers');
                    if (!toggle || !coworkers) return;
                    coworkers.classList.toggle('d-none', !toggle.checked);
                    if (!toggle.checked) {
                        coworkers.querySelectorAll('.gp-coworker').forEach(input => {
                            input.checked = false;
                        });
                    }
                }

                entryList?.querySelectorAll('.gp-entry-row').forEach(row => {
                    const hasCoworker = row.querySelector('.gp-coworker:checked');
                    if (hasCoworker) {
                        const toggle = row.querySelector('.gp-team-toggle');
                        if (toggle) toggle.checked = true;
                    }
                    syncRowCoworkerState(row);
                });

                function syncFieldWork() {
                    const isFieldWork = hasFieldStepChecked();
                    const isPackStep = stepCheckboxes.some(input => input.checked && (gpSteps.find(step => String(step.id) === String(input.value))?.code === 'PACK'));
                    fieldToggle.value = isFieldWork ? '1' : '0';
                    fieldModeBadge?.classList.toggle('is-visible', isFieldWork);
                    if (addEntryButton) {
                        addEntryButton.innerHTML = isFieldWork
                            ? '<i class="fas fa-plus me-1"></i> เพิ่มช่วงหน้างาน'
                            : '<i class="fas fa-plus me-1"></i> เพิ่ม MFG';
                    }
                    fieldFields.classList.toggle('d-none', !isFieldWork);
                    entryList?.querySelectorAll('.gp-mfg-wrap').forEach(mfgWrap => {
                        const mfgInput = mfgWrap.querySelector('.gp-mfg-input');
                        const mfgList = mfgWrap.querySelector('.gp-mfg-list');
                        mfgInput.required = !isFieldWork;
                        if (isFieldWork) {
                            mfgInput.value = '';
                            clearMfgMetadata(mfgInput.closest('.gp-entry-row'), false);
                        }
                        if (mfgList && isFieldWork) {
                            mfgList.style.display = 'none';
                        }
                        mfgWrap.classList.toggle('d-none', isFieldWork);
                    });
                    entryList?.querySelectorAll('.gp-field-mfg-wrap').forEach(wrap => {
                        wrap.classList.toggle('d-none', !isFieldWork);
                    });
                    entryList?.querySelectorAll('.gp-field-project-note').forEach(note => {
                        note.classList.toggle('d-none', !isFieldWork);
                    });
                    entryList?.querySelectorAll('.gp-production-field').forEach(field => {
                        field.classList.toggle('d-none', isFieldWork);
                    });
                    entryList?.querySelectorAll('.gp-good-pcs, .gp-bad-pcs, .gp-good-kg, .gp-bad-kg, .gp-good-sqm, .gp-bad-sqm, .gp-plan-display').forEach(input => {
                        input.disabled = isFieldWork;
                        if (isFieldWork) input.value = '0';
                    });
                    fieldActivities.forEach(input => {
                        input.required = isFieldWork;
                    });
                    const details = fieldFields.querySelector('[name="field_details"]');
                    if (details) details.required = isFieldWork;
                    if (details && isFieldWork) autoGrow(details);
                    entryList?.querySelectorAll('.gp-project, .gp-salesorder').forEach(input => {
                        input.required = isFieldWork;
                    });

                    if (!isFieldWork) {
                        fieldActivities.forEach(input => {
                            input.checked = false;
                        });
                        if (details) details.value = '';
                    }
                    entryList?.querySelectorAll('.gp-entry-row').forEach(updateFieldMfgSummary);

                    entryList?.querySelectorAll('.gp-is-finished').forEach(input => {
                        input.checked = isPackStep || input.checked;
                        input.disabled = isPackStep;
                    });
                }

                stepCheckboxes.forEach(input => {
                    input.addEventListener('change', function () {
                        syncFieldWork();
                        refreshAllStepBalances();
                    });
                });
                syncFieldWork();

                function autoGrow(area) {
                    if (!area) return;
                    area.style.height = 'auto';
                    area.style.height = (area.scrollHeight) + 'px';
                }

                form?.addEventListener('input', function (event) {
                    if (event.target.classList.contains('gp-autosize')) {
                        autoGrow(event.target);
                    }
                });
                document.querySelectorAll('.gp-autosize').forEach(autoGrow);

                function reindexEntries() {
                    entryList?.querySelectorAll('.gp-entry-row').forEach((row, index) => {
                        row.dataset.entryIndex = String(index);
                        row.querySelectorAll('[name]').forEach(input => {
                            input.name = input.name.replace(/entries\[\d+\]/, `entries[${index}]`);
                        });
                        renderFieldMfgs(row);
                        row.querySelector('.gp-remove-entry')?.classList.toggle('d-none', index === 0 && entryList.querySelectorAll('.gp-entry-row').length === 1);
                    });
                    entryIndex = entryList ? entryList.querySelectorAll('.gp-entry-row').length : 1;
                }

                function resetEntryRow(row) {
                    row.querySelectorAll('input').forEach(input => {
                        if (input.type === 'checkbox') {
                            input.checked = false;
                        } else if (input.type === 'number') {
                            input.value = '0';
                        } else if (input.type === 'hidden' && !input.name.endsWith('[is_finished]')) {
                            input.value = '';
                        } else if (input.type !== 'hidden') {
                            input.value = '';
                        }
                    });
                    row.querySelectorAll('.gp-mfg-detail').forEach(detail => {
                        detail.innerHTML = '';
                        detail.classList.remove('is-visible');
                    });
                    row.querySelectorAll('.gp-mfg-list').forEach(list => {
                        list.innerHTML = '';
                        list.style.display = 'none';
                    });
                    row.querySelectorAll('textarea').forEach(area => {
                        area.value = '';
                        autoGrow(area);
                    });
                    row.dataset.fieldMfgs = '[]';
                    const startInput = row.querySelector('input[name$="[start_time]"]');
                    const finishInput = row.querySelector('input[name$="[finish_time]"]');
                    if (startInput) startInput.value = '08:00';
                    if (finishInput) finishInput.value = '17:00';
                    renderFieldMfgs(row);
                    row.querySelector('.gp-entry-coworkers')?.classList.add('d-none');
                }

                function addEntryRow() {
                    const firstRow = entryList?.querySelector('.gp-entry-row');
                    if (!firstRow || !entryList) return;
                    const row = firstRow.cloneNode(true);
                    resetEntryRow(row);
                    entryList.appendChild(row);
                    reindexEntries();
                    syncFieldWork();
                    const focusInput = hasFieldStepChecked()
                        ? row.querySelector('.gp-field-mfg-input')
                        : row.querySelector('.gp-mfg-input');
                    (focusInput || row.querySelector('input[name$="[start_time]"]'))?.focus();
                }

                addEntryButton?.addEventListener('click', addEntryRow);

                entryList?.addEventListener('click', function (event) {
                    const removeButton = event.target.closest('.gp-remove-entry');
                    if (removeButton) {
                        const rows = entryList.querySelectorAll('.gp-entry-row');
                        if (rows.length > 1) {
                            removeButton.closest('.gp-entry-row')?.remove();
                            reindexEntries();
                            syncFieldWork();
                        }
                        return;
                    }
                    const fieldMfgRemove = event.target.closest('[data-field-mfg-remove]');
                    if (fieldMfgRemove) {
                        removeFieldMfg(fieldMfgRemove.closest('.gp-entry-row'), Number(fieldMfgRemove.dataset.fieldMfgRemove));
                        return;
                    }
                });

                entryList?.addEventListener('change', function (event) {
                    if (event.target.matches('.gp-team-toggle')) {
                        syncRowCoworkerState(event.target.closest('.gp-entry-row'));
                    }
                });

                function normalizeTimeInput(input) {
                    const raw = String(input.value || '').trim();
                    if (!raw) return;
                    const digits = raw.replace(/\D/g, '').slice(0, 4);
                    if (digits.length === 4) {
                        input.value = `${digits.slice(0, 2)}:${digits.slice(2, 4)}`;
                    }
                }

                entryList?.addEventListener('input', function (event) {
                    if (!event.target.matches('.gp-time-input')) return;
                    const digits = event.target.value.replace(/\D/g, '').slice(0, 4);
                    event.target.value = digits.length > 2 ? `${digits.slice(0, 2)}:${digits.slice(2)}` : digits;
                });

                entryList?.addEventListener('blur', function (event) {
                    if (event.target.matches('.gp-time-input')) {
                        normalizeTimeInput(event.target);
                    }
                }, true);

                reindexEntries();

                function storeRepeatContext() {
                    if (!form) return;
                    const selectedStep = stepCheckboxes.find(input => input.checked);
                    sessionStorage.setItem('gpRepeatContext', JSON.stringify({
                        work_date: form.querySelector('[name="work_date"]')?.value || '',
                        employee_id: employeeSelect?.value || '',
                        step_id: selectedStep?.value || ''
                    }));
                }

                function clearJobFieldsForRepeat() {
                    entryList?.querySelectorAll('.gp-entry-row').forEach((row, index) => {
                        if (index === 0) {
                            resetEntryRow(row);
                        } else {
                            row.remove();
                        }
                    });
                    const details = form.querySelector('[name="field_details"]');
                    if (details) details.value = '';
                    document.querySelectorAll('.gp-field-activity').forEach(input => input.checked = false);
                    reindexEntries();
                    syncFieldWork();
                }

                function restoreRepeatContext() {
                    let context = null;
                    try {
                        context = JSON.parse(sessionStorage.getItem('gpRepeatContext') || 'null');
                    } catch (error) {
                        context = null;
                    }
                    if (!context) return;

                    const workDate = form.querySelector('[name="work_date"]');
                    if (workDate && context.work_date) workDate.value = context.work_date;
                    if (employeeSelect && context.employee_id) {
                        if (employeeTom) {
                            employeeTom.setValue(context.employee_id, true);
                        } else {
                            employeeSelect.value = context.employee_id;
                        }
                        employeeSelect.dispatchEvent(new Event('change'));
                    }
                    if (context.step_id) {
                        setStepChecks([context.step_id], true);
                    }
                    clearJobFieldsForRepeat();
                }

                form?.addEventListener('submit', function (event) {
                    if (!validateAllPlanLimits()) {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                        Swal.fire({
                            icon: 'error',
                            title: 'จำนวนเกินแผนเปิด',
                            text: 'มี MFG ที่จำนวนชิ้นรวม (ที่ทำแล้ว + ที่กำลังกรอก) เกินแผนเปิดของ step นี้ กรุณาแก้ไขก่อนบันทึก',
                        });
                    }
                });

                form?.addEventListener('submit', storeRepeatContext);

                if (gpEntrySaved && sessionStorage.getItem('gpRepeatContext')) {
                    const askRepeat = function () {
                        Swal.fire({
                            icon: 'question',
                            title: 'บันทึกต่อด้วยคนเดิม?',
                            text: 'ถ้าใช่ ระบบจะคงวันที่ พนักงาน และ step ไว้ แล้วล้างข้อมูล MFG สำหรับรายการถัดไป',
                            showCancelButton: true,
                            confirmButtonText: 'ใช่',
                            cancelButtonText: 'ไม่ใช่'
                        }).then(result => {
                            if (result.isConfirmed) {
                                restoreRepeatContext();
                            } else {
                                sessionStorage.removeItem('gpRepeatContext');
                            }
                        });
                    };

                    if (window.Swal) {
                        setTimeout(askRepeat, 350);
                    } else {
                        restoreRepeatContext();
                    }
                }

                function numberValue(value) {
                    const parsed = Number.parseFloat(value);
                    return Number.isFinite(parsed) ? parsed : 0;
                }

                function formatNumber(value, digits = 3) {
                    const parsed = numberValue(value);
                    return parsed.toLocaleString(undefined, {
                        minimumFractionDigits: 0,
                        maximumFractionDigits: digits
                    });
                }

                function calculateRowArea(row) {
                    if (!row) return;
                    const sqmPerPiece = numberValue(row.querySelector('.gp-sqm-per-piece')?.value);
                    const goodPcs = numberValue(row.querySelector('.gp-good-pcs')?.value);
                    const badPcs = numberValue(row.querySelector('.gp-bad-pcs')?.value);
                    const goodSqm = row.querySelector('.gp-good-sqm');
                    const badSqm = row.querySelector('.gp-bad-sqm');
                    if (goodSqm) goodSqm.value = sqmPerPiece ? (goodPcs * sqmPerPiece).toFixed(3) : '0';
                    if (badSqm) badSqm.value = sqmPerPiece ? (badPcs * sqmPerPiece).toFixed(3) : '0';
                }

                function calculateRowKgFromPcs(row) {
                    if (!row) return;
                    const refUnitQty = numberValue(row.querySelector('.gp-ref-unit-qty')?.value);
                    if (!refUnitQty) {
                        calculateRowArea(row);
                        return;
                    }

                    const goodPcs = numberValue(row.querySelector('.gp-good-pcs')?.value);
                    const badPcs = numberValue(row.querySelector('.gp-bad-pcs')?.value);
                    const goodKg = row.querySelector('.gp-good-kg');
                    const badKg = row.querySelector('.gp-bad-kg');

                    if (goodKg) goodKg.value = (goodPcs * refUnitQty).toFixed(3);
                    if (badKg) badKg.value = (badPcs * refUnitQty).toFixed(3);
                    calculateRowArea(row);
                }

                function validatePlanLimit(row) {
                    const planQty = numberValue(row.querySelector('.gp-plan-qty-pcs')?.value);
                    if (!planQty) return true;

                    const totalPcs = numberValue(row.querySelector('.gp-good-pcs')?.value) + numberValue(row.querySelector('.gp-bad-pcs')?.value);
                    const usedPcs = numberValue(row.dataset.usedQtyPcs || 0);
                    if ((usedPcs + totalPcs) > planQty) {
                        showMfgWarning(row, `จำนวนชิ้นเกินแผนเปิด ${formatNumber(planQty, 0)} ชิ้น`);
                        return false;
                    }

                    return true;
                }

                // เช็คทุกแถวก่อน submit โดยนับรวมยอดของแถวที่เป็น MFG+step เดียวกันใน submit เดียวกัน
                // (สอดคล้องกับ assertEntryDoesNotExceedPlan ฝั่ง server)
                function validateAllPlanLimits() {
                    if (hasFieldStepChecked()) return true; // งานหน้างานไม่เช็คแผนเปิด

                    const stepId = selectedStepId();
                    const groupTotals = {}; // key: mfgNo|stepId -> ยอดสะสมจากแถวก่อนหน้า
                    let firstInvalidRow = null;

                    (entryList?.querySelectorAll('.gp-entry-row') || []).forEach(row => {
                        const planQty = numberValue(row.querySelector('.gp-plan-qty-pcs')?.value);
                        const mfgNo = (row.querySelector('.gp-mfg-input')?.value || '').trim().toUpperCase();
                        if (!planQty || !mfgNo || !stepId) return;

                        const totalPcs = numberValue(row.querySelector('.gp-good-pcs')?.value) + numberValue(row.querySelector('.gp-bad-pcs')?.value);
                        const usedPcs = numberValue(row.dataset.usedQtyPcs || 0);
                        const key = `${mfgNo}|${stepId}`;
                        const prior = groupTotals[key] || 0;

                        if ((usedPcs + prior + totalPcs) > (planQty + 0.0001)) {
                            showMfgWarning(row, `จำนวนชิ้นรวมเกินแผนเปิด ${formatNumber(planQty, 0)} ชิ้น (ทำแล้ว ${formatNumber(usedPcs, 0)} + แถวก่อนหน้า ${formatNumber(prior, 0)} + กรอก ${formatNumber(totalPcs, 0)})`);
                            if (!firstInvalidRow) firstInvalidRow = row;
                        }

                        groupTotals[key] = prior + totalPcs;
                    });

                    if (firstInvalidRow) {
                        firstInvalidRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        return false;
                    }

                    return true;
                }

                function selectedStepId() {
                    return stepCheckboxes.find(input => input.checked)?.value || '';
                }

                function setStepBalanceText(row, data = null) {
                    const target = row?.querySelector('.gp-step-balance');
                    if (!target) return;
                    if (!data) {
                        target.textContent = '';
                        row.dataset.usedQtyPcs = '0';
                        row.dataset.remainingQtyPcs = '';
                        return;
                    }

                    row.dataset.usedQtyPcs = String(data.used_qty_pcs || 0);
                    row.dataset.remainingQtyPcs = String(data.remaining_qty_pcs || 0);
                    target.textContent = `แผน ${formatNumber(data.plan_qty_pcs || 0, 0)} ชิ้น / ทำแล้ว ${formatNumber(data.used_qty_pcs || 0, 0)} / คงเหลือ ${formatNumber(data.remaining_qty_pcs || 0, 0)}`;
                }

                function fetchStepBalance(row) {
                    const mfgNo = row?.querySelector('.gp-mfg-input')?.value?.trim();
                    const stepId = selectedStepId();
                    const planQty = numberValue(row?.querySelector('.gp-plan-qty-pcs')?.value);

                    if (!row || !mfgNo || !stepId || !planQty || hasFieldStepChecked()) {
                        setStepBalanceText(row, null);
                        validatePlanLimit(row);
                        return Promise.resolve(null);
                    }

                    const params = new URLSearchParams({
                        mfg_no: mfgNo,
                        step_id: stepId,
                        plan_qty_pcs: String(planQty)
                    });

                    return fetch(`${gpStepBalanceUrl}?${params.toString()}`, { headers: { 'Accept': 'application/json' } })
                        .then(response => response.ok ? response.json() : null)
                        .then(data => {
                            setStepBalanceText(row, data);
                            validatePlanLimit(row);
                            return data;
                        })
                        .catch(() => {
                            setStepBalanceText(row, null);
                            validatePlanLimit(row);
                            return null;
                        });
                }

                function refreshAllStepBalances() {
                    entryList?.querySelectorAll('.gp-entry-row').forEach(row => fetchStepBalance(row));
                }

                function showMfgWarning(row, message) {
                    const detail = row?.querySelector('.gp-mfg-detail');
                    if (!detail) return;
                    detail.innerHTML = `<div class="line">${escapeHtml(message)}</div>`;
                    detail.classList.add('is-visible', 'is-warning');
                }

                function setHidden(row, selector, value) {
                    const input = row?.querySelector(selector);
                    if (input) input.value = value ?? '';
                }

                function setMfgMetadata(row, item) {
                    const meta = item?.meta || {};
                    setHidden(row, '.gp-mfg-site', meta.site);
                    setHidden(row, '.gp-partnumber', meta.partnumber);
                    setHidden(row, '.gp-part-description', meta.part_desc);
                    setHidden(row, '.gp-part-unit', meta.unit);
                    setHidden(row, '.gp-ref-unit-qty', meta.ref_unit_qty);
                    setHidden(row, '.gp-plan-qty-pcs', meta.plan_qty_pcs);
                    setHidden(row, '.gp-width-mm', meta.width_mm);
                    setHidden(row, '.gp-length-mm', meta.length_mm);
                    setHidden(row, '.gp-sqm-per-piece', meta.sqm_per_piece);
                    setHidden(row, '.gp-project', meta.project);
                    setHidden(row, '.gp-salesorder', meta.salesorder);
                    setHidden(row, '.gp-plan-display', meta.plan_qty_pcs);

                    const detail = row?.querySelector('.gp-mfg-detail');
                    if (detail) {
                        const area = meta.area_label || 'ไม่พบ W/L ใน description';
                        detail.innerHTML = `
                            <div class="title">${escapeHtml(meta.partnumber || item.id || '')}</div>
                            <div class="line">${escapeHtml(meta.part_desc || '')}</div>
                            <div class="line">จำนวนแผน: ${formatNumber(meta.plan_qty_pcs || 0, 0)} ชิ้น | กก./ชิ้น: ${formatNumber(meta.ref_unit_qty || 0)} | เลข SO: ${escapeHtml(meta.salesorder || '-')}</div>
                            <div class="line">โครงการ: ${escapeHtml(meta.project || '-')}</div>
                            <div class="line">Site: ${escapeHtml(meta.site || '-')} | WO Qty: ${formatNumber(meta.wo_qty)} | Unit: ${escapeHtml(meta.unit || '-')}</div>
                            <div class="line">${escapeHtml(area)}</div>
                        `;
                        detail.classList.add('is-visible');
                        detail.classList.remove('is-warning');
                    }

                    setStepBalanceText(row, null);
                    calculateRowKgFromPcs(row);
                    validatePlanLimit(row);
                    fetchStepBalance(row);
                }

                function clearMfgMetadata(row, clearProjectFields = true) {
                    ['.gp-mfg-site', '.gp-partnumber', '.gp-part-description', '.gp-part-unit', '.gp-ref-unit-qty', '.gp-plan-qty-pcs', '.gp-plan-display', '.gp-width-mm', '.gp-length-mm', '.gp-sqm-per-piece'].forEach(selector => setHidden(row, selector, ''));
                    if (clearProjectFields) {
                        ['.gp-project', '.gp-salesorder'].forEach(selector => setHidden(row, selector, ''));
                    }
                    const detail = row?.querySelector('.gp-mfg-detail');
                    if (detail) {
                        detail.innerHTML = '';
                        detail.classList.remove('is-visible', 'is-warning');
                    }
                    setStepBalanceText(row, null);
                    calculateRowKgFromPcs(row);
                }

                function fetchMfgResults(q, limit = 8) {
                    return fetch('{{ route('api.mfgs.search') }}?q=' + encodeURIComponent(q) + '&limit=' + encodeURIComponent(limit), { headers: { 'Accept': 'application/json' } })
                        .then(response => response.ok ? response.json() : { results: [] })
                        .then(data => data.results || [])
                        .catch(() => []);
                }

                function fetchProjectResults(q, limit = 8) {
                    return fetch('{{ route('api.grating-projects.search') }}?q=' + encodeURIComponent(q) + '&limit=' + encodeURIComponent(limit), { headers: { 'Accept': 'application/json' } })
                        .then(response => response.ok ? response.json() : { results: [] })
                        .then(data => data.results || [])
                        .catch(() => []);
                }

                function fieldMfgItems(row) {
                    try {
                        return JSON.parse(row?.dataset.fieldMfgs || '[]');
                    } catch (error) {
                        return [];
                    }
                }

                function renderFieldMfgs(row) {
                    const tags = row?.querySelector('.gp-field-mfg-tags');
                    if (!tags) return;
                    const rowIndex = row.dataset.entryIndex || '0';
                    tags.innerHTML = '';
                    fieldMfgItems(row).forEach((item, index) => {
                        const tag = document.createElement('span');
                        tag.className = 'gp-field-mfg-tag';
                        tag.innerHTML = `
                            ${escapeHtml(item.mfg_no || '')}
                            <button type="button" aria-label="remove" data-field-mfg-remove="${index}">&times;</button>
                            <input type="hidden" name="entries[${rowIndex}][field_mfgs][${index}][mfg_no]" value="${escapeHtml(item.mfg_no || '')}">
                            <input type="hidden" name="entries[${rowIndex}][field_mfgs][${index}][workorder_id]" value="${escapeHtml(item.workorder_id || '')}">
                            <input type="hidden" name="entries[${rowIndex}][field_mfgs][${index}][project]" value="${escapeHtml(item.project || '')}">
                            <input type="hidden" name="entries[${rowIndex}][field_mfgs][${index}][salesorder]" value="${escapeHtml(item.salesorder || '')}">
                        `;
                        tags.appendChild(tag);
                    });
                    updateFieldMfgSummary(row);
                }

                function uniqueFilled(items, key) {
                    return [...new Set(items.map(item => String(item[key] || '').trim()).filter(Boolean))];
                }

                function updateFieldMfgSummary(row) {
                    const summary = row?.querySelector('.gp-field-summary');
                    if (!summary) return;
                    const items = fieldMfgItems(row);
                    if (!hasFieldStepChecked() || !items.length) {
                        summary.classList.remove('is-visible');
                        summary.textContent = '';
                        return;
                    }

                    const projects = uniqueFilled(items, 'project');
                    const salesorders = uniqueFilled(items, 'salesorder');
                    const projectText = projects.length ? `${projects.length} โครงการ` : 'ไม่พบโครงการจาก MFG';
                    const soText = salesorders.length ? `${salesorders.length} SO` : 'ไม่พบ SO จาก MFG';
                    summary.classList.add('is-visible');
                    summary.textContent = `เลือก ${items.length} MFG | พบ ${projectText} | พบ ${soText} | กรุณาเลือกโครงการและ SO กลางของงานนี้เอง`;
                }

                function addFieldMfg(row, item) {
                    const meta = item?.meta || {};
                    const mfgNo = String(item?.id || meta.mfg_no || '').trim().toUpperCase();
                    if (!row || !mfgNo) return;
                    const items = fieldMfgItems(row);
                    if (!items.some(existing => String(existing.mfg_no || '').toUpperCase() === mfgNo)) {
                        items.push({
                            mfg_no: mfgNo,
                            workorder_id: meta.workorder_id || '',
                            project: meta.project || '',
                            salesorder: meta.salesorder || ''
                        });
                    }
                    row.dataset.fieldMfgs = JSON.stringify(items);
                    renderFieldMfgs(row);
                }

                function removeFieldMfg(row, index) {
                    const items = fieldMfgItems(row);
                    items.splice(index, 1);
                    row.dataset.fieldMfgs = JSON.stringify(items);
                    renderFieldMfgs(row);
                }

                function ensureMfgMetadataForRow(row) {
                    if (!row) return Promise.resolve(false);
                    if (numberValue(row.querySelector('.gp-ref-unit-qty')?.value)) {
                        return Promise.resolve(true);
                    }

                    const mfgInput = row.querySelector('.gp-mfg-input');
                    const q = (mfgInput?.value || '').trim();
                    if (q.length < 2) {
                        showMfgWarning(row, 'เลือก MFG จากรายการก่อน เพื่อให้ระบบรู้ kg/pcs และคำนวณ pcs กับ ตร.ม.');
                        return Promise.resolve(false);
                    }

                    return fetchMfgResults(q, 10).then(results => {
                        const exact = results.find(item => String(item.id || '').toUpperCase() === q.toUpperCase());
                        const item = exact || (results.length === 1 ? results[0] : null);
                        if (!item) {
                            showMfgWarning(row, 'พบ MFG หลายรายการ กรุณาคลิกเลือกจากรายการ autocomplete ก่อน');
                            return false;
                        }

                        mfgInput.value = item.id;
                        setMfgMetadata(row, item);
                        return true;
                    });
                }

                let timer = null;

                entryList?.addEventListener('input', function (event) {
                    const input = event.target.closest('.gp-mfg-input');
                    const fieldMfgInput = event.target.closest('.gp-field-mfg-input');
                    const projectInput = event.target.closest('.gp-project');
                    if (event.target.matches('.gp-good-pcs, .gp-bad-pcs')) {
                        const row = event.target.closest('.gp-entry-row');
                        ensureMfgMetadataForRow(row).then(() => {
                            calculateRowKgFromPcs(row);
                            validatePlanLimit(row);
                            fetchStepBalance(row);
                        });
                    }
                    if (projectInput) {
                        const row = projectInput.closest('.gp-entry-row');
                        const list = row?.querySelector('.gp-project-list');
                        if (!list) return;

                        clearTimeout(timer);
                        const q = projectInput.value.trim();
                        if (q.length < 2) {
                            list.style.display = 'none';
                            return;
                        }

                        timer = setTimeout(function () {
                            fetchProjectResults(q, 8)
                                .then(results => {
                                    list.innerHTML = '';
                                    results.forEach(item => {
                                        const button = document.createElement('button');
                                        button.type = 'button';
                                        const meta = item.meta || {};
                                        button.innerHTML = `
                                            <div class="gp-mfg-item-main">${escapeHtml(item.text || item.id || '')}</div>
                                            <div class="gp-mfg-item-sub">เลข SO: ${escapeHtml(meta.salesorder || '-')} | MFG: ${escapeHtml(meta.mfg_no || '-')} | ${escapeHtml(meta.site || '-')}</div>
                                        `;
                                        button.addEventListener('click', function () {
                                            projectInput.value = meta.project || item.text || item.id || '';
                                            const soInput = row.querySelector('.gp-salesorder');
                                            if (soInput && !soInput.value && meta.salesorder) {
                                                soInput.value = meta.salesorder;
                                            }
                                            list.style.display = 'none';
                                        });
                                        list.appendChild(button);
                                    });
                                    list.style.display = list.children.length ? 'block' : 'none';
                                });
                        }, 250);
                        return;
                    }
                    if (fieldMfgInput) {
                        const row = fieldMfgInput.closest('.gp-entry-row');
                        const list = row?.querySelector('.gp-field-mfg-list');
                        if (!list) return;

                        clearTimeout(timer);
                        const q = fieldMfgInput.value.trim();
                        if (q.length < 2) {
                            list.style.display = 'none';
                            return;
                        }

                        timer = setTimeout(function () {
                            fetchMfgResults(q, 8)
                                .then(results => {
                                    list.innerHTML = '';
                                    results.forEach(item => {
                                        const button = document.createElement('button');
                                        button.type = 'button';
                                        const meta = item.meta || {};
                                        button.innerHTML = `
                                            <div class="gp-mfg-item-main">${escapeHtml(item.id || '')} | ${escapeHtml(meta.partnumber || '')}</div>
                                            <div class="gp-mfg-item-sub">${escapeHtml(meta.project || '-')} | SO: ${escapeHtml(meta.salesorder || '-')}</div>
                                        `;
                                        button.addEventListener('click', function () {
                                            addFieldMfg(row, item);
                                            fieldMfgInput.value = '';
                                            list.style.display = 'none';
                                        });
                                        list.appendChild(button);
                                    });
                                    list.style.display = list.children.length ? 'block' : 'none';
                                });
                        }, 250);
                        return;
                    }
                    if (!input) return;
                    clearMfgMetadata(input.closest('.gp-entry-row'));
                    const list = input.closest('.gp-mfg-wrap')?.querySelector('.gp-mfg-list');
                    if (!list) return;

                    clearTimeout(timer);
                    const q = input.value.trim();
                    if (q.length < 2) {
                        list.style.display = 'none';
                        return;
                    }
                    timer = setTimeout(function () {
                        fetchMfgResults(q, 8)
                            .then(results => {
                                list.innerHTML = '';
                                results.forEach(item => {
                                    const button = document.createElement('button');
                                    button.type = 'button';
                                    const meta = item.meta || {};
                                    button.innerHTML = `
                                        <div class="gp-mfg-item-main">${escapeHtml(item.id || '')} | ${escapeHtml(meta.partnumber || '')}</div>
                                        <div class="gp-mfg-item-sub">${escapeHtml(meta.part_desc || item.text || '')}</div>
                                        <div class="gp-mfg-item-sub">Qty ${formatNumber(meta.wo_qty)} | ${escapeHtml(meta.site || '-')} | ${escapeHtml(meta.area_label || 'ไม่พบ W/L')}</div>
                                    `;
                                    button.addEventListener('click', function () {
                                        const row = input.closest('.gp-entry-row');
                                        input.value = item.id;
                                        setMfgMetadata(row, item);
                                        list.style.display = 'none';
                                    });
                                    list.appendChild(button);
                                });
                                list.style.display = list.children.length ? 'block' : 'none';
                            });
                    }, 250);
                });

                document.addEventListener('click', function (event) {
                    document.querySelectorAll('.gp-mfg-list').forEach(list => {
                        const wrap = list.closest('.gp-mfg-wrap, .gp-project-wrap, .gp-field-mfg-wrap');
                        if (wrap && !wrap.contains(event.target)) {
                            list.style.display = 'none';
                        }
                    });
                });
            });
        </script>
    @endpush
@endsection
