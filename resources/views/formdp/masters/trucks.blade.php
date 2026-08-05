@extends('layouts.layout')
@section('page-title', 'Master รถ')
@section('title', 'Master รถ')

@section('content')
    <div class="container-fluid py-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h3 class="mb-1 fw-bold">Master รถ</h3>
                <div class="text-muted">ข้อมูลรถสำหรับ FormDP dev</div>
            </div>
            <div class="btn-group">
                <a class="btn btn-outline-primary btn-sm" href="{{ route('dp.master.drivers') }}">พนักงานขับรถ</a>
                <a class="btn btn-outline-primary btn-sm" href="{{ route('dp.master.helpers') }}">เด็กรถ</a>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">เพิ่มรถ</div>
            <div class="card-body">
                <form method="POST" action="{{ route('dp.master.trucks.store') }}" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-md-2">
                        <label class="form-label small mb-1">ทะเบียน</label>
                        <input class="form-control form-control-sm" name="plate_no" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">คนขับ</label>
                        <select class="form-select form-select-sm jsDriverSelect" name="driver_staff_id">
                            <option value="">- เลือกคนขับ -</option>
                            @foreach ($drivers as $driver)
                                <option value="{{ $driver->id }}" data-phone="{{ e($driver->phone) }}">{{ $driver->name }}</option>
                            @endforeach
                        </select>
                        <input type="hidden" name="driver_name">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">โทร</label>
                        <input class="form-control form-control-sm jsDriverPhone" name="driver_phone">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small mb-1">Max (ตัน)</label>
                        <input type="number" step="0.1" min="0" class="form-control form-control-sm"
                            name="max_load_ton" placeholder="0 = ไม่ระบุ">
                        <div class="form-text small">0 = ไม่ระบุ Max Load</div>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small mb-1">ยาว</label>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="car_length">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">หมายเหตุ</label>
                        <input class="form-control form-control-sm" name="remark">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small mb-1">สถานะ</label>
                        <select class="form-select form-select-sm" name="status">
                            <option value="ACTIVE">ACTIVE</option>
                            <option value="INACTIVE">INACTIVE</option>
                        </select>
                    </div>
                    <div class="col-md-1 d-grid">
                        <button class="btn btn-primary btn-sm">บันทึก</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-white">
                <div class="row g-2 align-items-center">
                    <div class="col-md-8">
                        <input type="search" class="form-control form-control-sm" id="truckMasterSearch"
                            placeholder="ค้นหาทะเบียน / คนขับ / เบอร์โทร / หมายเหตุ">
                    </div>
                    <div class="col-md-4">
                        <select class="form-select form-select-sm" id="truckMasterStatusFilter">
                            <option value="ALL">ทุกสถานะ</option>
                            <option value="ACTIVE" selected>ACTIVE</option>
                            <option value="INACTIVE">INACTIVE</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:70px;">No</th>
                            <th>ทะเบียน</th>
                            <th>คนขับ</th>
                            <th>โทร</th>
                            <th class="text-end">
                                Max (ตัน)
                                <div class="small fw-normal text-muted">0 = ไม่ระบุ</div>
                            </th>
                            <th class="text-end">ยาว</th>
                            <th>หมายเหตุ</th>
                            <th style="width:120px;">สถานะ</th>
                            <th style="width:150px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            @php
                                $editFormId = 'truckEditForm' . (int) $row->id;
                            @endphp
                            @php
                                $rowSearchText = mb_strtolower(
                                    trim(
                                        collect([
                                            $row->id,
                                            $row->plate_no,
                                            $row->driver_name,
                                            $row->driver_phone,
                                            $row->remark,
                                            $row->status,
                                        ])->filter()->implode(' '),
                                    ),
                                );
                            @endphp
                            <tr class="jsTruckMasterRow" data-status="{{ strtoupper((string) ($row->status ?? '')) }}"
                                data-search="{{ e($rowSearchText) }}">
                                <td>
                                    <form id="{{ $editFormId }}" method="POST"
                                        action="{{ route('dp.master.trucks.update', $row->id) }}">
                                        @csrf
                                    </form>
                                    {{ $row->id }}
                                </td>
                                <td><input class="form-control form-control-sm" name="plate_no"
                                        form="{{ $editFormId }}" value="{{ $row->plate_no }}" required></td>
                                    <td>
                                        <select class="form-select form-select-sm jsDriverSelect" name="driver_staff_id"
                                            form="{{ $editFormId }}">
                                            <option value="">- เลือกคนขับ -</option>
                                            @foreach ($drivers as $driver)
                                                <option value="{{ $driver->id }}"
                                                    data-phone="{{ e($driver->phone) }}"
                                                    {{ (int) ($driverMap[(int) $row->id] ?? 0) === (int) $driver->id ? 'selected' : '' }}>
                                                    {{ $driver->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <input type="hidden" name="driver_name" form="{{ $editFormId }}"
                                            value="{{ $row->driver_name }}">
                                    </td>
                                    <td><input class="form-control form-control-sm jsDriverPhone" name="driver_phone"
                                            form="{{ $editFormId }}" value="{{ $row->driver_phone }}"></td>
                                    <td><input type="number" step="0.1" min="0"
                                            class="form-control form-control-sm text-end" name="max_load_ton"
                                            form="{{ $editFormId }}"
                                            placeholder="0 = ไม่ระบุ"
                                            value="{{ number_format(((float) ($row->max_load ?? 0)) / 1000, 1, '.', '') }}">
                                    </td>
                                    <td><input type="number" step="0.01" min="0"
                                            class="form-control form-control-sm text-end" name="car_length"
                                            form="{{ $editFormId }}" value="{{ $row->car_length }}"></td>
                                    <td><input class="form-control form-control-sm" name="remark"
                                            form="{{ $editFormId }}" value="{{ $row->remark }}"></td>
                                    <td>
                                        <select class="form-select form-select-sm" name="status"
                                            form="{{ $editFormId }}">
                                            <option value="ACTIVE" {{ ($row->status ?? '') === 'ACTIVE' ? 'selected' : '' }}>ACTIVE</option>
                                            <option value="INACTIVE" {{ ($row->status ?? '') === 'INACTIVE' ? 'selected' : '' }}>INACTIVE</option>
                                        </select>
                                    </td>
                                    <td class="text-nowrap">
                                        <button class="btn btn-outline-primary btn-sm"
                                            form="{{ $editFormId }}">Save</button>
                                        <form method="POST" action="{{ route('dp.master.trucks.delete', $row->id) }}" class="d-inline" onsubmit="return confirm('ปิดการใช้งานรถนี้?');">
                                            @csrf
                                            <button class="btn btn-outline-danger btn-sm">Inactive</button>
                                        </form>
                                    </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted">ไม่มีข้อมูล</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        (function truckMasterFilters() {
            const searchInput = document.getElementById('truckMasterSearch');
            const statusSelect = document.getElementById('truckMasterStatusFilter');
            const rows = Array.from(document.querySelectorAll('.jsTruckMasterRow'));

            function applyFilters() {
                const keyword = String(searchInput?.value || '').trim().toLowerCase();
                const status = String(statusSelect?.value || 'ALL').toUpperCase();

                rows.forEach((row) => {
                    const rowStatus = String(row.dataset.status || '').toUpperCase();
                    const rowText = String(row.dataset.search || '').toLowerCase();
                    const matchStatus = status === 'ALL' || rowStatus === status;
                    const matchText = keyword === '' || rowText.includes(keyword);
                    row.classList.toggle('d-none', !(matchStatus && matchText));
                });
            }

            if (searchInput) searchInput.addEventListener('input', applyFilters);
            if (statusSelect) statusSelect.addEventListener('change', applyFilters);
            applyFilters();
        })();

        document.addEventListener('change', function (event) {
            const select = event.target.closest('.jsDriverSelect');
            if (!select) return;

            const row = select.closest('form') || select.closest('tr') || document;
            const option = select.options[select.selectedIndex];
            const phoneInput = row.querySelector('.jsDriverPhone');
            const nameInput = row.querySelector('input[name="driver_name"]');

            if (phoneInput) {
                phoneInput.value = option ? (option.dataset.phone || '') : '';
            }

            if (nameInput) {
                nameInput.value = option && option.value ? option.text.trim() : '';
            }
        });
    </script>
@endsection
