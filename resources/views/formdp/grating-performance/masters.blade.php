@extends('layouts.layout')

@section('title', 'Grating Performance Masters')
@section('page-title', 'Grating Performance Masters')

@section('content')
    <style>
        .gp-wrap { background:#f5f7fa; border-radius:8px; padding:16px; }
        .gp-panel { background:#fff; border:1px solid #dfe5ec; border-radius:8px; overflow:visible; }
        .gp-head { padding:12px 16px; border-bottom:1px solid #e8edf2; display:flex; align-items:center; justify-content:space-between; gap:12px; font-weight:700; }
        .gp-table-wrap { max-height:560px; overflow:auto; }
        .gp-employee-table-wrap { overflow:visible; max-height:none; }
        .gp-table th { position:sticky; top:0; z-index:2; background:#edf4ff; white-space:nowrap; }
        .gp-table td { vertical-align:middle; }
        .gp-preset-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:8px 18px; }
        .gp-preset-item { display:flex; align-items:center; gap:10px; min-height:34px; }
        .gp-preset-box { flex:0 0 48px; height:26px; border:2px solid #111; background:#fff; }
        .gp-student-check { min-height:38px; margin:0; padding:8px 12px 8px 34px; background:#f8fbff; border:1px solid #dfe5ec; border-radius:8px; display:flex; align-items:center; white-space:nowrap; }
        .gp-student-wrap { position:relative; }
        .gp-student-fields { display:none; position:absolute; z-index:40; top:calc(100% + 6px); right:0; width:min(420px, calc(100vw - 32px)); padding:10px; border:1px solid #dbeafe; background:#fff; border-radius:8px; box-shadow:0 12px 24px rgba(15,23,42,.16); }
        .gp-student-fields.is-visible { display:block; }
        .gp-student-grid { display:grid; grid-template-columns:repeat(2, minmax(130px, 1fr)); gap:8px; }
        .gp-student-custom { grid-column:1 / -1; }
        .gp-student-grid .form-label { font-size:.78rem; margin-bottom:3px; color:#64748b; }
        .gp-student-end { font-size:.82rem; color:#64748b; margin-top:6px; }
        .gp-responsible-wrap { position:relative; }
        .gp-responsible-tags { display:flex; flex-wrap:wrap; gap:6px; min-height:34px; border:1px solid #dfe5ec; border-radius:8px; padding:6px; background:#fff; margin-top:6px; }
        .gp-responsible-chip { display:inline-flex; align-items:center; gap:6px; border:1px solid #bfdbfe; background:#eff6ff; color:#1e40af; border-radius:999px; padding:3px 8px; font-size:.8rem; }
        .gp-responsible-chip button { border:0; background:transparent; color:#1e40af; padding:0; line-height:1; font-weight:700; }
        .gp-responsible-list { position:absolute; z-index:30; background:#fff; border:1px solid #ced4da; border-radius:6px; width:100%; max-height:220px; overflow:auto; display:none; }
        .gp-responsible-list button { display:block; width:100%; border:0; background:#fff; padding:7px 10px; text-align:left; }
        .gp-responsible-list button:hover { background:#eef4ff; }
        .gp-responsible-type { color:#64748b; font-size:.75rem; margin-left:6px; }
        .num { text-align:right; font-variant-numeric:tabular-nums; }
    </style>

    <div class="gp-wrap">
        @if (!empty($setupMissing))
            <div class="alert alert-warning">ยังไม่พบตาราง Grating Performance กรุณารัน migration ก่อนใช้งาน</div>
        @endif

        <div class="d-flex justify-content-end gap-2 mb-3">
            <a href="{{ route('dp.grating-performance.index') }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-chart-line me-1"></i> Dashboard</a>
            <a href="{{ route('dp.grating-performance.inquiry') }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-table me-1"></i> Inquiry</a>
        </div>

        <div class="gp-panel mb-3">
            <div class="gp-head"><span>Master พนักงาน</span><small class="text-muted">ข้อมูลและงานที่รับผิดชอบ</small></div>
            <form method="POST" action="{{ route('dp.grating-performance.employees.store') }}" class="p-3">
                @csrf
                <div class="row g-3 align-items-end">
                    <div class="col-lg-2 col-md-3">
                        <label class="form-label">รหัสพนักงาน</label>
                        <input type="text" name="employee_code" class="form-control" maxlength="40">
                    </div>
                    <div class="col-lg-3 col-md-4">
                        <label class="form-label">ชื่อ</label>
                        <input type="text" name="name" class="form-control" maxlength="160" required>
                    </div>
                    <div class="col-lg-2 col-md-3">
                        <label class="form-label">ชื่อเล่น</label>
                        <input type="text" name="nickname" class="form-control" maxlength="80">
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label">งานที่รับผิดชอบ</label>
                        <div class="gp-responsible-wrap">
                            <input type="hidden" name="responsible_work" class="gp-responsible-value">
                            <input type="text" class="form-control gp-responsible-search" maxlength="255" placeholder="พิมพ์เพื่อค้นจาก Master Step">
                            <div class="gp-responsible-list"></div>
                            <div class="gp-responsible-tags"></div>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-3">
                        <label class="form-label d-block">ประเภท</label>
                        <div class="gp-student-wrap">
                            <div class="form-check gp-student-check">
                                <input type="hidden" name="is_student" value="0">
                                <input class="form-check-input gp-student-toggle" type="checkbox" name="is_student" value="1">
                                <label class="form-check-label">นักศึกษา</label>
                            </div>
                            <div class="gp-student-fields">
                                <div class="gp-student-grid">
                                    <div>
                                        <label class="form-label">เริ่มฝึกงาน</label>
                                        <input type="date" name="student_start_date" class="form-control form-control-sm">
                                    </div>
                                    <div>
                                        <label class="form-label">ระยะเวลา</label>
                                        <select name="student_months" class="form-select form-select-sm">
                                            <option value="">-</option>
                                            <option value="3">3 เดือน</option>
                                            <option value="6">6 เดือน</option>
                                            <option value="12">12 เดือน</option>
                                        </select>
                                    </div>
                                    <div class="gp-student-custom">
                                        <label class="form-label">กำหนดเอง</label>
                                        <input type="number" name="student_months_custom" class="form-control form-control-sm num" min="1" max="60" step="1" placeholder="เดือน">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-1 col-md-2">
                        <label class="form-label">สถานะ</label>
                        <select name="active" class="form-select">
                            <option value="1">ใช้</option>
                            <option value="0">ปิด</option>
                        </select>
                    </div>
                    <div class="col-lg-1 col-md-2 d-grid">
                        <button class="btn btn-primary"><i class="fas fa-plus me-1"></i> เพิ่ม</button>
                    </div>
                </div>
            </form>
            <div class="gp-table-wrap">
                <table class="table table-sm table-bordered gp-table mb-0">
                    <thead><tr><th>รหัสพนักงาน</th><th>ชื่อ</th><th>ชื่อเล่น</th><th>งานที่รับผิดชอบ</th><th>นักศึกษา</th><th>สถานะ</th><th style="width:118px;"></th></tr></thead>
                    <tbody>
                        @forelse ($employees as $employee)
                            @php
                                $studentMonths = (int) ($employee->student_months ?? 0);
                                $studentPreset = in_array($studentMonths, [3, 6, 12], true) ? $studentMonths : '';
                                $studentCustom = $studentMonths && !in_array($studentMonths, [3, 6, 12], true) ? $studentMonths : '';
                            @endphp
                            <tr>
                                <td><form id="employee-{{ $employee->id }}" method="POST" action="{{ route('dp.grating-performance.employees.update', $employee->id) }}">@csrf @method('PUT')<input name="employee_code" class="form-control form-control-sm" value="{{ $employee->employee_code }}"></form></td>
                                <td><input form="employee-{{ $employee->id }}" name="name" class="form-control form-control-sm" value="{{ $employee->name }}" required></td>
                                <td><input form="employee-{{ $employee->id }}" name="nickname" class="form-control form-control-sm" value="{{ $employee->nickname }}"></td>
                                <td>
                                    <div class="gp-responsible-wrap">
                                        <input form="employee-{{ $employee->id }}" type="hidden" name="responsible_work" class="gp-responsible-value" value="{{ $employee->responsible_work }}">
                                        <input type="text" class="form-control form-control-sm gp-responsible-search" maxlength="255" placeholder="ค้นจาก Master">
                                        <div class="gp-responsible-list"></div>
                                        <div class="gp-responsible-tags"></div>
                                    </div>
                                </td>
                                <td>
                                    <div class="gp-student-wrap">
                                        <div class="form-check gp-student-check">
                                            <input form="employee-{{ $employee->id }}" type="hidden" name="is_student" value="0">
                                            <input form="employee-{{ $employee->id }}" class="form-check-input gp-student-toggle" type="checkbox" name="is_student" value="1" @checked($employee->is_student ?? false)>
                                            <label class="form-check-label">นักศึกษา</label>
                                        </div>
                                        <div class="gp-student-fields">
                                            <div class="gp-student-grid">
                                                <div>
                                                    <label class="form-label">เริ่ม</label>
                                                    <input form="employee-{{ $employee->id }}" type="date" name="student_start_date" class="form-control form-control-sm" value="{{ $employee->student_start_date ?? '' }}">
                                                </div>
                                                <div>
                                                    <label class="form-label">เดือน</label>
                                                    <select form="employee-{{ $employee->id }}" name="student_months" class="form-select form-select-sm">
                                                        <option value="">-</option>
                                                        <option value="3" @selected($studentPreset === 3)>3</option>
                                                        <option value="6" @selected($studentPreset === 6)>6</option>
                                                        <option value="12" @selected($studentPreset === 12)>12</option>
                                                    </select>
                                                </div>
                                                <div class="gp-student-custom">
                                                    <label class="form-label">กำหนดเอง</label>
                                                    <input form="employee-{{ $employee->id }}" type="number" name="student_months_custom" class="form-control form-control-sm num" min="1" max="60" step="1" value="{{ $studentCustom }}" placeholder="เดือน">
                                                </div>
                                            </div>
                                            <div class="gp-student-end">
                                                สิ้นสุด:
                                                <span class="{{ ($employee->student_end_date ?? null) && \Carbon\Carbon::parse($employee->student_end_date)->lt(now('Asia/Bangkok')->startOfDay()) ? 'text-danger fw-semibold' : '' }}">
                                                    {{ $employee->student_end_date ?? '-' }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <select form="employee-{{ $employee->id }}" name="active" class="form-select form-select-sm">
                                        <option value="1" @selected($employee->active)>ใช้</option>
                                        <option value="0" @selected(!$employee->active)>ปิด</option>
                                    </select>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <button form="employee-{{ $employee->id }}" class="btn btn-sm btn-primary" title="บันทึก"><i class="fas fa-save"></i></button>
                                        <form method="POST" action="{{ route('dp.grating-performance.employees.destroy', $employee->id) }}" class="gp-delete-employee-form" data-employee-name="{{ $employee->name }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="ลบ"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">ยังไม่มีพนักงาน</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="gp-panel mb-3">
            <div class="gp-head"><span>Master Step งาน</span><small class="text-muted">รวมถึงงานออกหน้างาน และ target ชิ้น/ชม. เป็นหลัก</small></div>
            <div class="p-3 border-bottom">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <div class="fw-semibold">ขั้นตอนตามฟอร์ม Checklist</div>
                        <div class="text-muted small">ใช้เป็น master step สำหรับให้หน้า Input ติ๊กอัตโนมัติตามหน้าที่พนักงาน</div>
                    </div>
                    <form method="POST" action="{{ route('dp.grating-performance.steps.defaults') }}">
                        @csrf
                        <button class="btn btn-outline-primary">
                            <i class="fas fa-list-check me-1"></i> เติมชุดขั้นตอนมาตรฐาน
                        </button>
                    </form>
                </div>
                <div class="gp-preset-grid">
                    @foreach ($steps->where('active', true)->sortBy('sort_order') as $presetStep)
                        <div class="gp-preset-item">
                            <span class="gp-preset-box"></span>
                            <span>{{ $presetStep->step_name }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
            <form method="POST" action="{{ route('dp.grating-performance.steps.store') }}" class="p-3">
                @csrf
                <div class="row g-3 align-items-end">
                    <div class="col-lg-2 col-md-3">
                        <label class="form-label">Code <span class="text-muted fw-normal">(ระบบ)</span></label>
                        <input type="text" name="step_code" class="form-control" maxlength="40" pattern="[A-Za-z0-9_\-]+" title="ใช้ได้เฉพาะ A-Z 0-9 _ - ห้ามเว้นวรรค" required>
                    </div>
                    <div class="col-lg-3 col-md-4">
                        <label class="form-label">ชื่อขั้นตอน</label>
                        <input type="text" name="step_name" class="form-control" maxlength="160" required>
                    </div>
                    <div class="col-lg-2 col-md-3">
                        <label class="form-label">Target ชิ้น/ชม.</label>
                        <input type="number" step="0.001" min="0" name="target_pcs_per_hour" class="form-control num">
                    </div>
                    <div class="col-lg-2 col-md-3">
                        <label class="form-label">Target กก./ชม.</label>
                        <input type="number" step="0.001" min="0" name="target_kg_per_hour" class="form-control num">
                    </div>
                    <div class="col-lg-1 col-md-2">
                        <label class="form-label">Sort</label>
                        <input type="number" min="0" max="999" name="sort_order" class="form-control num" value="100">
                    </div>
                    <div class="col-lg-1 col-md-2">
                        <label class="form-label">หน้างาน</label>
                        <select name="is_field_work" class="form-select"><option value="0">ไม่ใช่</option><option value="1">ใช่</option></select>
                    </div>
                    <div class="col-lg-1 col-md-2">
                        <label class="form-label">สถานะ</label>
                        <select name="active" class="form-select"><option value="1">ใช้</option><option value="0">ปิด</option></select>
                    </div>
                    <div class="col-lg-1 col-md-3 d-grid">
                        <button class="btn btn-primary"><i class="fas fa-plus me-1"></i> เพิ่ม Step</button>
                    </div>
                </div>
            </form>
            <div class="gp-table-wrap gp-employee-table-wrap">
                <table class="table table-sm table-bordered gp-table mb-0">
                    <thead><tr><th>ชื่อขั้นตอน</th><th>Code <span class="text-muted fw-normal small">(ระบบ)</span></th><th class="num">Target ชิ้น/ชม.</th><th class="num">Target กก./ชม.</th><th class="num">Sort</th><th>หน้างาน</th><th>สถานะ</th><th style="width:90px;"></th></tr></thead>
                    <tbody>
                        @forelse ($steps as $step)
                            <tr>
                                <td><form id="step-{{ $step->id }}" method="POST" action="{{ route('dp.grating-performance.steps.update', $step->id) }}">@csrf @method('PUT')<input name="step_name" class="form-control form-control-sm" value="{{ $step->step_name }}" required></form></td>
                                <td><input form="step-{{ $step->id }}" name="step_code" class="form-control form-control-sm" value="{{ $step->step_code }}" pattern="[A-Za-z0-9_\-]+" title="ใช้ได้เฉพาะ A-Z 0-9 _ - ห้ามเว้นวรรค" required></td>
                                <td><input form="step-{{ $step->id }}" type="number" step="0.001" min="0" name="target_pcs_per_hour" class="form-control form-control-sm num" value="{{ $step->target_pcs_per_hour ?? '' }}"></td>
                                <td><input form="step-{{ $step->id }}" type="number" step="0.001" min="0" name="target_kg_per_hour" class="form-control form-control-sm num" value="{{ $step->target_kg_per_hour }}"></td>
                                <td><input form="step-{{ $step->id }}" type="number" min="0" max="999" name="sort_order" class="form-control form-control-sm num" value="{{ $step->sort_order }}"></td>
                                <td>
                                    <select form="step-{{ $step->id }}" name="is_field_work" class="form-select form-select-sm">
                                        <option value="1" @selected($step->is_field_work)>ใช่</option>
                                        <option value="0" @selected(!$step->is_field_work)>ไม่ใช่</option>
                                    </select>
                                </td>
                                <td>
                                    <select form="step-{{ $step->id }}" name="active" class="form-select form-select-sm">
                                        <option value="1" @selected($step->active)>ใช้</option>
                                        <option value="0" @selected(!$step->active)>ปิด</option>
                                    </select>
                                </td>
                                <td><button form="step-{{ $step->id }}" class="btn btn-sm btn-primary"><i class="fas fa-save"></i></button></td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">ยังไม่มี step</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="gp-panel mb-3">
            <div class="gp-head"><span>Master Project</span><small class="text-muted">ใช้ร่วมกับ Project ที่ดึงจาก workorder.notes</small></div>
            <form method="POST" action="{{ route('dp.grating-performance.projects.store') }}" class="p-3">
                @csrf
                <div class="row g-3 align-items-end">
                    <div class="col-lg-5 col-md-5">
                        <label class="form-label">Project</label>
                        <input type="text" name="project_name" class="form-control" maxlength="500" required>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">เลข SO</label>
                        <input type="text" name="salesorder" class="form-control" maxlength="80">
                    </div>
                    <div class="col-lg-1 col-md-2">
                        <label class="form-label">Sort</label>
                        <input type="number" min="0" max="999" name="sort_order" class="form-control num" value="100">
                    </div>
                    <div class="col-lg-1 col-md-2">
                        <label class="form-label">สถานะ</label>
                        <select name="active" class="form-select"><option value="1">ใช้</option><option value="0">ปิด</option></select>
                    </div>
                    <div class="col-lg-2 col-md-3 d-grid">
                        <button class="btn btn-primary"><i class="fas fa-plus me-1"></i> เพิ่ม Project</button>
                    </div>
                </div>
            </form>
            <div class="gp-table-wrap">
                <table class="table table-sm table-bordered gp-table mb-0">
                    <thead><tr><th>Project</th><th>เลข SO</th><th class="num">Sort</th><th>สถานะ</th><th style="width:90px;"></th></tr></thead>
                    <tbody>
                        @forelse ($projects as $project)
                            <tr>
                                <td><form id="project-{{ $project->id }}" method="POST" action="{{ route('dp.grating-performance.projects.update', $project->id) }}">@csrf @method('PUT')<input name="project_name" class="form-control form-control-sm" value="{{ $project->project_name }}" required></form></td>
                                <td><input form="project-{{ $project->id }}" name="salesorder" class="form-control form-control-sm" value="{{ $project->salesorder }}"></td>
                                <td><input form="project-{{ $project->id }}" type="number" min="0" max="999" name="sort_order" class="form-control form-control-sm num" value="{{ $project->sort_order }}"></td>
                                <td>
                                    <select form="project-{{ $project->id }}" name="active" class="form-select form-select-sm">
                                        <option value="1" @selected($project->active)>ใช้</option>
                                        <option value="0" @selected(!$project->active)>ปิด</option>
                                    </select>
                                </td>
                                <td><button form="project-{{ $project->id }}" class="btn btn-sm btn-primary"><i class="fas fa-save"></i></button></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มี master project</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="gp-panel">
            <div class="gp-head"><span>Master กิจกรรมหน้างาน</span><small class="text-muted">อ่านจากตาราง grating_field_activities</small></div>
            <form method="POST" action="{{ route('dp.grating-performance.field-activities.store') }}" class="p-3">
                @csrf
                <div class="row g-3 align-items-end">
                    <div class="col-lg-2 col-md-3">
                        <label class="form-label">Activity code</label>
                        <input type="text" name="activity_code" class="form-control" maxlength="40" pattern="[A-Za-z0-9_\-]+" title="ใช้ได้เฉพาะ A-Z 0-9 _ - ห้ามเว้นวรรค" required>
                    </div>
                    <div class="col-lg-5 col-md-5">
                        <label class="form-label">Activity name</label>
                        <input type="text" name="activity_name" class="form-control" maxlength="160" required>
                    </div>
                    <div class="col-lg-1 col-md-2">
                        <label class="form-label">Sort</label>
                        <input type="number" min="0" max="999" name="sort_order" class="form-control num" value="100">
                    </div>
                    <div class="col-lg-1 col-md-2">
                        <label class="form-label">สถานะ</label>
                        <select name="active" class="form-select"><option value="1">ใช้</option><option value="0">ปิด</option></select>
                    </div>
                    <div class="col-lg-3 col-md-4 d-grid">
                        <button class="btn btn-primary"><i class="fas fa-plus me-1"></i> เพิ่มกิจกรรมหน้างาน</button>
                    </div>
                </div>
            </form>
            <div class="gp-table-wrap">
                <table class="table table-sm table-bordered gp-table mb-0">
                    <thead><tr><th>Code</th><th>Name</th><th class="num">Sort</th><th>สถานะ</th><th style="width:90px;"></th></tr></thead>
                    <tbody>
                        @forelse ($fieldActivities as $activity)
                            <tr>
                                <td><form id="activity-{{ $activity->id }}" method="POST" action="{{ route('dp.grating-performance.field-activities.update', $activity->id) }}">@csrf @method('PUT')<input name="activity_code" class="form-control form-control-sm" value="{{ $activity->activity_code }}" pattern="[A-Za-z0-9_\-]+" title="ใช้ได้เฉพาะ A-Z 0-9 _ - ห้ามเว้นวรรค" required></form></td>
                                <td><input form="activity-{{ $activity->id }}" name="activity_name" class="form-control form-control-sm" value="{{ $activity->activity_name }}" required></td>
                                <td><input form="activity-{{ $activity->id }}" type="number" min="0" max="999" name="sort_order" class="form-control form-control-sm num" value="{{ $activity->sort_order }}"></td>
                                <td>
                                    <select form="activity-{{ $activity->id }}" name="active" class="form-select form-select-sm">
                                        <option value="1" @selected($activity->active)>ใช้</option>
                                        <option value="0" @selected(!$activity->active)>ปิด</option>
                                    </select>
                                </td>
                                <td><button form="activity-{{ $activity->id }}" class="btn btn-sm btn-primary"><i class="fas fa-save"></i></button></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีกิจกรรมหน้างาน</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @php
        $responsibleOptions = collect()
            ->merge($steps->where('active', true)->map(fn($step) => ['label' => $step->step_name, 'type' => 'Step']))
            ->merge($fieldActivities->where('active', true)->map(fn($activity) => ['label' => $activity->activity_name, 'type' => 'หน้างาน']))
            ->unique(fn($item) => mb_strtolower(trim((string) $item['label'])))
            ->values();
    @endphp

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const responsibleOptions = @json($responsibleOptions);

            function escapeHtml(value) {
                return String(value || '').replace(/[&<>"']/g, function (char) {
                    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
                });
            }

            function valuesFromHidden(hidden) {
                return String(hidden?.value || '')
                    .split(',')
                    .map(item => item.trim())
                    .filter(Boolean);
            }

            function syncHidden(wrap, values) {
                const hidden = wrap.querySelector('.gp-responsible-value');
                if (hidden) hidden.value = values.join(', ');
            }

            function renderTags(wrap) {
                const tags = wrap.querySelector('.gp-responsible-tags');
                if (!tags) return;
                const values = valuesFromHidden(wrap.querySelector('.gp-responsible-value'));
                tags.innerHTML = '';
                values.forEach((value, index) => {
                    const chip = document.createElement('span');
                    chip.className = 'gp-responsible-chip';
                    chip.innerHTML = `${escapeHtml(value)} <button type="button" data-responsible-remove="${index}" aria-label="remove">&times;</button>`;
                    tags.appendChild(chip);
                });
            }

            function addResponsible(wrap, label) {
                const clean = String(label || '').trim();
                if (!clean) return;
                const values = valuesFromHidden(wrap.querySelector('.gp-responsible-value'));
                if (!values.some(value => value.toLowerCase() === clean.toLowerCase())) {
                    values.push(clean);
                }
                syncHidden(wrap, values);
                renderTags(wrap);
                const input = wrap.querySelector('.gp-responsible-search');
                const list = wrap.querySelector('.gp-responsible-list');
                if (input) input.value = '';
                if (list) list.style.display = 'none';
            }

            function renderSuggestions(wrap) {
                const input = wrap.querySelector('.gp-responsible-search');
                const list = wrap.querySelector('.gp-responsible-list');
                if (!input || !list) return;
                const q = input.value.trim().toLowerCase();
                const selected = valuesFromHidden(wrap.querySelector('.gp-responsible-value')).map(value => value.toLowerCase());
                const results = responsibleOptions
                    .filter(item => item.label && !selected.includes(String(item.label).toLowerCase()))
                    .filter(item => q.length < 1 || String(item.label).toLowerCase().includes(q))
                    .slice(0, 12);

                list.innerHTML = '';
                results.forEach(item => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.innerHTML = `${escapeHtml(item.label)} <span class="gp-responsible-type">${escapeHtml(item.type)}</span>`;
                    button.addEventListener('click', function () {
                        addResponsible(wrap, item.label);
                    });
                    list.appendChild(button);
                });
                list.style.display = results.length ? 'block' : 'none';
            }

            document.querySelectorAll('.gp-responsible-wrap').forEach(wrap => {
                renderTags(wrap);
                const input = wrap.querySelector('.gp-responsible-search');
                input?.addEventListener('input', () => renderSuggestions(wrap));
                input?.addEventListener('focus', () => renderSuggestions(wrap));
                wrap.addEventListener('click', function (event) {
                    const remove = event.target.closest('[data-responsible-remove]');
                    if (!remove) return;
                    const values = valuesFromHidden(wrap.querySelector('.gp-responsible-value'));
                    values.splice(Number(remove.dataset.responsibleRemove), 1);
                    syncHidden(wrap, values);
                    renderTags(wrap);
                    renderSuggestions(wrap);
                });
            });

            function syncStudentFields(wrap, showPanel = false) {
                const checked = !!wrap.querySelector('.gp-student-toggle')?.checked;
                const fields = wrap.querySelector('.gp-student-fields');
                fields?.classList.toggle('is-visible', checked && showPanel);
                wrap.querySelectorAll('[name="student_start_date"], [name="student_months"], [name="student_months_custom"]').forEach(input => {
                    input.disabled = !checked;
                    if (!checked) input.value = '';
                });
            }

            document.querySelectorAll('.gp-student-wrap').forEach(wrap => {
                syncStudentFields(wrap);
                wrap.querySelector('.gp-student-toggle')?.addEventListener('change', function () {
                    syncStudentFields(wrap, true);
                });
                wrap.querySelector('.gp-student-check')?.addEventListener('click', function () {
                    if (wrap.querySelector('.gp-student-toggle')?.checked) {
                        syncStudentFields(wrap, true);
                    }
                });
            });

            document.querySelectorAll('.gp-delete-employee-form').forEach(form => {
                form.addEventListener('submit', function (event) {
                    event.preventDefault();

                    const employeeName = form.dataset.employeeName || '';
                    const message = employeeName
                        ? `ลบพนักงาน ${employeeName} ? รายการบันทึกเก่าจะไม่ผูกพนักงานคนนี้แล้ว`
                        : 'ลบพนักงานนี้? รายการบันทึกเก่าจะไม่ผูกพนักงานคนนี้แล้ว';

                    if (!window.Swal) {
                        if (window.confirm(message)) {
                            form.submit();
                        }
                        return;
                    }

                    const scrollTop = window.scrollY || document.documentElement.scrollTop || 0;
                    Swal.fire({
                        title: 'ยืนยันลบพนักงาน?',
                        text: message,
                        icon: 'warning',
                        heightAuto: false,
                        returnFocus: false,
                        showCancelButton: true,
                        confirmButtonText: 'ลบ',
                        cancelButtonText: 'ยกเลิก',
                        confirmButtonColor: '#dc3545',
                        cancelButtonColor: '#6c757d',
                        reverseButtons: true,
                        didOpen: () => window.scrollTo({ top: scrollTop, behavior: 'instant' }),
                    }).then(result => {
                        if (result.isConfirmed) {
                            form.submit();
                        }
                    });
                });
            });

            document.addEventListener('click', function (event) {
                document.querySelectorAll('.gp-responsible-list').forEach(list => {
                    const wrap = list.closest('.gp-responsible-wrap');
                    if (wrap && !wrap.contains(event.target)) {
                        list.style.display = 'none';
                    }
                });
                document.querySelectorAll('.gp-student-wrap').forEach(wrap => {
                    if (wrap.contains(event.target)) return;
                    wrap.querySelector('.gp-student-fields')?.classList.remove('is-visible');
                });
            });
        });
    </script>
@endsection
