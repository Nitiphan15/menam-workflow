@extends('layouts.layout')

@section('title', 'ตั้งค่าภาระงานเครื่องจักร')
@section('page-title', 'ตั้งค่าภาระงานเครื่องจักร')

@section('content')
    @php
        $workCenters = collect($workCenters ?? []);
        $holidays = collect($holidays ?? []);
        $workCenterOptions = collect($workCenterOptions ?? []);
        $machineOptions = collect($machineOptions ?? []);
        $settingsLabel = fn($label) => preg_replace('/^(PLUS|WIRE)\s\/\s/', '', (string) $label);
        $settingsWorkCenterOptions = $workCenterOptions->map(fn($option) => [
            'site' => $option['site'],
            'raw_value' => (string) $option['value'],
            'value' => $option['site'] . '|' . $option['value'],
            'label' => $settingsLabel($option['label'] ?? ''),
        ])->values();
        $settingsMachineOptions = $machineOptions->map(fn($option) => [
            'site' => $option['site'],
            'raw_value' => (string) $option['value'],
            'value' => $option['site'] . '|' . $option['value'],
            'label' => $settingsLabel($option['label'] ?? ''),
        ])->values();
    @endphp

    <style>
        .mla-wrap { background:#f5f7fa; border-radius:8px; padding:16px; }
        .mla-panel { background:#fff; border:1px solid #dfe5ec; border-radius:8px; overflow:visible; }
        .mla-head { padding:12px 16px; border-bottom:1px solid #e8edf2; font-weight:700; display:flex; align-items:center; justify-content:space-between; gap:12px; }
        .mla-table-wrap { max-height:540px; overflow:auto; }
        .mla-table th { position:sticky; top:0; z-index:2; background:#edf4ff; white-space:nowrap; }
        .mla-table td { vertical-align:middle; }
        .num { text-align:right; font-variant-numeric:tabular-nums; }
        .ts-dropdown { z-index:3000 !important; }
        .mla-master-select + .ts-wrapper .ts-control { min-height:38px; }
        .mla-table .ts-wrapper { min-width:240px; }
        .mla-settings-form .form-label { min-height:38px; display:flex; align-items:flex-end; margin-bottom:4px; font-weight:600; }
        .mla-settings-form .form-text { min-height:32px; font-size:.72rem; line-height:1.15; color:#6c757d; }
        .mla-settings-form .col-lg-1,
        .mla-settings-form .col-lg-2,
        .mla-settings-form .col-lg-3 { display:flex; flex-direction:column; }
        .mla-settings-form .col-lg-1 .form-control,
        .mla-settings-form .col-lg-1 .form-select,
        .mla-settings-form .col-lg-2 .form-control,
        .mla-settings-form .col-lg-2 .form-select { width:100%; }
    </style>

    <div class="mla-wrap">
        @if (!empty($settingsError))
            <div class="alert alert-warning">
                ยังไม่พบตารางตั้งค่า กรุณารัน SQL สำหรับ Machine Load ก่อนใช้งานส่วนตั้งค่า
                <div class="small mt-1">{{ $settingsError }}</div>
            </div>
        @endif

        <div class="mla-panel mb-3">
            <div class="mla-head">
                <span>ตั้งค่ากำลังผลิต / เวลาทำงานเครื่องจักร</span>
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ route('machine-load.dashboard') }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-chart-column me-1"></i> Dashboard</a>
                    <a href="{{ route('machine-load.inquiry') }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-table me-1"></i> รายละเอียด</a>
                </div>
            </div>

            <div class="alert alert-info m-3 mb-0 small">
                <i class="fas fa-info-circle me-1"></i>
                <strong>วิธีใช้:</strong>
                ค่ากำลังผลิตเริ่มต้นมาจาก ERP (Work Center / Machine). หากต้องการ <u>แก้ไขเอง</u> ให้บันทึกที่หน้านี้ — ระบบจะใช้ค่านี้ <strong>ก่อน</strong> ค่าจาก ERP ทันทีบนหน้า Dashboard.
                <br>
                <span class="text-muted">
                    • ตั้งค่าได้ทั้งระดับ <strong>Work Center</strong> (ใช้กับทุกเครื่องใน WC) หรือเจาะจง <strong>เครื่องจักร</strong> (ถ้าตั้งเจาะจง จะใช้แทน WC).
                    • ใช้ <strong>สถานะ "ปิดใช้งาน"</strong> เมื่อเครื่องเสีย / หยุดเดิน — ระบบจะไม่นับเครื่องนั้นในการคำนวณภาระงาน.
                    • ปรับ <strong>ชม./วัน</strong> และ <strong>วัน/สัปดาห์</strong> ตามจำนวนกะและวันทำงานจริง.
                </span>
            </div>

            <form method="POST" action="{{ route('machine-load.settings.work-centers.store') }}" class="p-3 mla-settings-form">
                @csrf
                <div class="row g-3 align-items-start">
                    <div class="col-lg-1 col-md-3">
                        <label class="form-label">แหล่งข้อมูล</label>
                        <select name="source_site" class="form-select" required>
                            <option value="PLUS">Plus</option>
                            <option value="WIRE">Wire</option>
                        </select>
                        <div class="form-text">Plus / Wire</div>
                    </div>
                    <div class="col-lg-2 col-md-5">
                        <label class="form-label">Work Center</label>
                        <select name="workcenter_id" class="form-select mla-master-select" data-placeholder="พิมพ์รหัสหรือชื่อ Work Center" required>
                            <option value="">เลือก Work Center</option>
                            @foreach ($settingsWorkCenterOptions as $option)
                                <option value="{{ $option['value'] }}" data-site="{{ $option['site'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">ตั้งค่าระดับกลุ่มเครื่อง</div>
                    </div>
                    <div class="col-lg-2 col-md-5">
                        <label class="form-label">เครื่องจักร</label>
                        <select name="workmachine_id" class="form-select mla-master-select" data-placeholder="พิมพ์รหัสหรือชื่อเครื่องจักร">
                            <option value="">ระดับ Work Center</option>
                            @foreach ($settingsMachineOptions as $option)
                                <option value="{{ $option['value'] }}" data-site="{{ $option['site'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">ว่าง = ใช้กับทุกเครื่องใน WC</div>
                    </div>
                    <div class="col-lg-2 col-md-6">
                        <label class="form-label">ชื่อที่ต้องการแสดง</label>
                        <input type="text" name="display_name" class="form-control" maxlength="120">
                        <div class="form-text">ไม่บังคับ (เว้นว่าง = ใช้ชื่อจาก ERP)</div>
                    </div>
                    <div class="col-lg-1 col-md-3">
                        <label class="form-label">กำลังผลิต/ชม.</label>
                        <input type="number" step="0.0001" name="capacity_per_hour" class="form-control">
                        <div class="form-text">หน่วย กก./ชม.</div>
                    </div>
                    <div class="col-lg-1 col-md-3">
                        <label class="form-label">ชม./วัน</label>
                        <input type="number" step="0.01" name="work_hours_per_day" class="form-control" value="8">
                        <div class="form-text">เช่น 1 กะ = 8, 2 กะ = 16</div>
                    </div>
                    <div class="col-lg-1 col-md-3">
                        <label class="form-label">วัน/สัปดาห์</label>
                        <input type="number" name="work_days_per_week" class="form-control" min="1" max="7" value="6" required>
                        <div class="form-text">6 = จันทร์-เสาร์</div>
                    </div>
                    <div class="col-lg-1 col-md-3">
                        <label class="form-label">นาที/หน่วย</label>
                        <input type="number" step="0.0001" name="cycle_time_minutes" class="form-control">
                        <div class="form-text">Cycle time (ถ้าเว้น = 60/Cap.)</div>
                    </div>
                    <div class="col-lg-1 col-md-3">
                        <label class="form-label">Setup นาที</label>
                        <input type="number" step="0.01" name="setup_time_minutes" class="form-control" value="0">
                        <div class="form-text">เวลาเตรียมเครื่องต่อ WO</div>
                    </div>
                    <div class="col-lg-1 col-md-3">
                        <label class="form-label">สถานะ</label>
                        <select name="active" class="form-select">
                            <option value="1">ใช้งาน</option>
                            <option value="0">ปิดใช้งาน</option>
                        </select>
                        <div class="form-text">ปิด = เครื่องเสีย/ไม่นับ</div>
                    </div>
                    <div class="col-lg-1 col-md-3">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-plus me-1"></i> เพิ่ม</button>
                        <div class="form-text">&nbsp;</div>
                    </div>
                    <div class="col-12">
                        <input type="text" name="notes" class="form-control" maxlength="500" placeholder="หมายเหตุ">
                    </div>
                </div>
            </form>

            <div class="mla-table-wrap">
                <table class="table table-bordered table-sm mla-table mb-0">
                    <thead>
                        <tr>
                            <th>แหล่งข้อมูล</th>
                            <th>Work Center</th>
                            <th>เครื่องจักร</th>
                            <th>ชื่อแสดงผล</th>
                            <th class="num">กำลังผลิต/ชม.</th>
                            <th class="num">ชม./วัน</th>
                            <th class="num">วัน/สัปดาห์</th>
                            <th class="num">นาที/หน่วย</th>
                            <th class="num">Setup นาที</th>
                            <th>สถานะ</th>
                            <th>หมายเหตุ</th>
                            <th style="width:180px;">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($workCenters as $row)
                            <tr>
                                <td>
                                    <form id="wc-form-{{ $row->id }}" method="POST" action="{{ route('machine-load.settings.work-centers.update', $row->id) }}">
                                        @csrf
                                        @method('PUT')
                                        <select name="source_site" class="form-select form-select-sm">
                                            <option value="PLUS" {{ $row->source_site === 'PLUS' ? 'selected' : '' }}>Plus</option>
                                            <option value="WIRE" {{ $row->source_site === 'WIRE' ? 'selected' : '' }}>Wire</option>
                                        </select>
                                    </form>
                                </td>
                                <td>
                                    <select name="workcenter_id" form="wc-form-{{ $row->id }}" class="form-select form-select-sm mla-master-select" data-placeholder="พิมพ์รหัสหรือชื่อ Work Center" required>
                                        @foreach ($settingsWorkCenterOptions as $option)
                                            <option value="{{ $option['value'] }}" data-site="{{ $option['site'] }}" {{ $row->source_site === $option['site'] && (string) $row->workcenter_id === (string) $option['raw_value'] ? 'selected' : '' }}>{{ $option['label'] }}</option>
                                        @endforeach
                                        @if ($workCenterOptions->isEmpty())
                                            <option value="{{ $row->workcenter_id }}" selected>{{ $row->workcenter_label }}</option>
                                        @endif
                                    </select>
                                </td>
                                <td>
                                    <select name="workmachine_id" form="wc-form-{{ $row->id }}" class="form-select form-select-sm mla-master-select" data-placeholder="พิมพ์รหัสหรือชื่อเครื่องจักร">
                                        <option value="">ระดับ Work Center</option>
                                        @foreach ($settingsMachineOptions as $option)
                                            <option value="{{ $option['value'] }}" data-site="{{ $option['site'] }}" {{ $row->source_site === $option['site'] && (string) $row->workmachine_id === (string) $option['raw_value'] ? 'selected' : '' }}>{{ $option['label'] }}</option>
                                        @endforeach
                                        @if ($machineOptions->isEmpty() && $row->workmachine_id)
                                            <option value="{{ $row->workmachine_id }}" selected>{{ $row->machine_label }}</option>
                                        @endif
                                    </select>
                                </td>
                                <td><input type="text" name="display_name" form="wc-form-{{ $row->id }}" class="form-control form-control-sm" value="{{ $row->display_name }}"></td>
                                <td><input type="number" step="0.0001" name="capacity_per_hour" form="wc-form-{{ $row->id }}" class="form-control form-control-sm num" value="{{ $row->capacity_per_hour }}"></td>
                                <td><input type="number" step="0.01" name="work_hours_per_day" form="wc-form-{{ $row->id }}" class="form-control form-control-sm num" value="{{ $row->work_hours_per_day }}"></td>
                                <td>
                                    <input type="number" name="work_days_per_week" form="wc-form-{{ $row->id }}" class="form-control form-control-sm num" value="{{ $row->work_days_per_week }}" min="1" max="7" required>
                                    <div class="form-text">6 = จันทร์-เสาร์</div>
                                </td>
                                <td><input type="number" step="0.0001" name="cycle_time_minutes" form="wc-form-{{ $row->id }}" class="form-control form-control-sm num" value="{{ $row->cycle_time_minutes }}"></td>
                                <td><input type="number" step="0.01" name="setup_time_minutes" form="wc-form-{{ $row->id }}" class="form-control form-control-sm num" value="{{ $row->setup_time_minutes }}"></td>
                                <td>
                                    <select name="active" form="wc-form-{{ $row->id }}" class="form-select form-select-sm">
                                        <option value="1" {{ (int) $row->active === 1 ? 'selected' : '' }}>ใช้งาน</option>
                                        <option value="0" {{ (int) $row->active === 0 ? 'selected' : '' }}>ปิดใช้งาน</option>
                                    </select>
                                </td>
                                <td><input type="text" name="notes" form="wc-form-{{ $row->id }}" class="form-control form-control-sm" value="{{ $row->notes }}"></td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <button type="submit" form="wc-form-{{ $row->id }}" class="btn btn-sm btn-primary"><i class="fas fa-save"></i></button>
                                        <form method="POST" action="{{ route('machine-load.settings.work-centers.destroy', $row->id) }}" onsubmit="return confirm('ต้องการลบการตั้งค่านี้หรือไม่?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="12" class="text-center text-muted py-4">ยังไม่มีการตั้งค่า</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mla-panel">
            <div class="mla-head"><span>วันหยุดบริษัท</span></div>
            <form method="POST" action="{{ route('machine-load.settings.holidays.store') }}" class="p-3">
                @csrf
                <div class="row g-3 align-items-end">
                    <div class="col-lg-2 col-md-3">
                        <label class="form-label">ใช้กับ</label>
                        <select name="source_site" class="form-select">
                            <option value="ALL">ทั้งหมด (Plus + Wire)</option>
                            <option value="PLUS">Plus</option>
                            <option value="WIRE">Wire</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">วันที่หยุด</label>
                        <input type="date" name="holiday_date" class="form-control" required>
                    </div>
                    <div class="col-lg-6 col-md-8">
                        <label class="form-label">รายละเอียด</label>
                        <input type="text" name="description" class="form-control" maxlength="255">
                    </div>
                    <div class="col-lg-2 col-md-4 d-grid">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-plus me-1"></i> เพิ่มวันหยุด</button>
                    </div>
                </div>
            </form>
            <div class="mla-table-wrap">
                <table class="table table-bordered table-sm mla-table mb-0">
                    <thead><tr><th>ใช้กับ</th><th>วันที่</th><th>รายละเอียด</th><th style="width:100px;">จัดการ</th></tr></thead>
                    <tbody>
                        @forelse ($holidays as $row)
                            <tr>
                                <td>{{ $row->source_site === 'ALL' ? 'ทั้งหมด' : ucfirst(strtolower($row->source_site)) }}</td>
                                <td>{{ $row->holiday_date }}</td>
                                <td>{{ $row->description }}</td>
                                <td>
                                    <form method="POST" action="{{ route('machine-load.settings.holidays.destroy', $row->id) }}" onsubmit="return confirm('ต้องการลบวันหยุดนี้หรือไม่?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-4">ยังไม่มีวันหยุด</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('.mla-master-select').forEach(function (select) {
                    const tom = new TomSelect(select, {
                        create: false,
                        maxOptions: 1000,
                        dropdownParent: 'body',
                        placeholder: select.dataset.placeholder || 'พิมพ์เพื่อค้นหา',
                    });

                    if (select.name === 'workcenter_id') {
                        tom.on('change', function (value) {
                            const parts = String(value || '').split('|');
                            if (!['PLUS', 'WIRE'].includes(parts[0])) return;

                            const formId = select.getAttribute('form');
                            const form = formId ? document.getElementById(formId) : select.closest('form');
                            const source = form ? form.querySelector('select[name="source_site"]') : null;
                            if (source) source.value = parts[0];
                        });
                    }
                });
            });
        </script>
        <script>
            const mlaWorkCenterOptions = @json($settingsWorkCenterOptions);
            const mlaMachineOptions = @json($settingsMachineOptions);

            document.addEventListener('DOMContentLoaded', function () {
                function formForSelect(select) {
                    const formId = select.getAttribute('form');
                    return formId ? document.getElementById(formId) : select.closest('form');
                }

                function sourceForSelect(select) {
                    const form = formForSelect(select);
                    return form ? form.querySelector('select[name="source_site"]') : null;
                }

                function optionSetForSelect(select) {
                    return select.name === 'workmachine_id' ? mlaMachineOptions : mlaWorkCenterOptions;
                }

                function refreshMasterSelect(select, keepCurrent) {
                    const source = sourceForSelect(select);
                    const site = source ? source.value : 'PLUS';
                    const tom = select.tomselect;
                    if (!tom) return;

                    const current = tom.getValue();
                    const options = optionSetForSelect(select).filter(function (option) {
                        return option.site === site;
                    });
                    const currentStillValid = keepCurrent && options.some(function (option) {
                        return option.value === current;
                    });

                    tom.clear(true);
                    tom.clearOptions();
                    options.forEach(function (option) {
                        tom.addOption({
                            value: option.value,
                            text: option.label,
                            site: option.site
                        });
                    });
                    tom.refreshOptions(false);

                    if (currentStillValid) {
                        tom.setValue(current, true);
                    }
                }

                const masterSelects = Array.from(document.querySelectorAll('.mla-master-select'));
                masterSelects.forEach(function (select) {
                    refreshMasterSelect(select, true);
                });

                document.querySelectorAll('select[name="source_site"]').forEach(function (source) {
                    source.addEventListener('change', function () {
                        const form = source.closest('form');
                        if (!form) return;

                        masterSelects.forEach(function (select) {
                            if (formForSelect(select) === form) {
                                refreshMasterSelect(select, false);
                            }
                        });
                    });
                });
            });
        </script>
    @endpush
@endsection
