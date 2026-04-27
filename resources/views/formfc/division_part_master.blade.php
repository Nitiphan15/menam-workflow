@extends('layouts.layout')
@section('title', 'Division Part Master')
@section('page-title', 'Division Part Master')

@section('content')
    <div class="container-fluid py-3">

        @if (session('success'))
            <div class="alert alert-success shadow-sm">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger shadow-sm">
                <div class="fw-bold mb-1">บันทึกไม่สำเร็จ</div>
                <ul class="mb-0 ps-3">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('fc.divisionPartMaster.index') }}">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Role Division</label>

                            @if (!empty($showDivisionDropdown) && $showDivisionDropdown)
                                <select name="division" class="form-select">
                                    @foreach ($allowedDivisions as $div)
                                        <option value="{{ $div }}"
                                            {{ ($division ?? '') === $div ? 'selected' : '' }}>
                                            {{ $divisionLabels[$div] ?? $div }}
                                        </option>
                                    @endforeach
                                </select>
                            @else
                                <input type="text" class="form-control"
                                    value="{{ $divisionLabels[$division] ?? ($division ?? '-') }}" readonly>
                                <input type="hidden" name="division" value="{{ $division ?? '' }}">
                            @endif
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">ค้นหา FG / RM / Description</label>
                            <input type="text" name="part" value="{{ $part ?? '' }}" class="form-control"
                                placeholder="เช่น FG001 / RM001 / stainless">
                        </div>

                        <div class="col-md-3 d-flex gap-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fa-solid fa-magnifying-glass me-1"></i> ค้นหา
                            </button>

                            <a href="{{ route('fc.divisionPartMaster.index', ['division' => $division]) }}"
                                class="btn btn-outline-secondary w-100">
                                ล้างตัวกรอง
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <form method="POST" action="{{ route('fc.divisionPartMaster.save') }}">
            @csrf
            <input type="hidden" name="division" value="{{ $division ?? '' }}">

            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-0 pt-3 pb-0">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <h5 class="mb-1">รายการ FG / RM</h5>
                            <div class="text-muted small">
                                ทั้งหมด {{ collect($rows ?? [])->count() }} รายการ
                                @if (!empty($division))
                                    | Role: <span class="fw-semibold">{{ $divisionLabels[$division] ?? $division }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="check-all-btn">
                                เลือกทั้งหมด
                            </button>

                            <button type="button" class="btn btn-outline-secondary btn-sm" id="uncheck-all-btn">
                                เอาออกทั้งหมด
                            </button>

                            <button type="submit" class="btn btn-success btn-sm">
                                <i class="fa-solid fa-floppy-disk me-1"></i> บันทึก
                            </button>
                        </div>
                    </div>
                </div>

                <div class="card-body">
                    @if (collect($rows ?? [])->isEmpty())
                        <div class="text-center py-5 text-muted">
                            <div class="mb-2">
                                <i class="fa-regular fa-folder-open fa-2x"></i>
                            </div>
                            <div>ไม่พบข้อมูล part สำหรับเงื่อนไขที่เลือก</div>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 70px;" class="text-center">ลำดับ</th>
                                        <th style="width: 170px;">FG Part</th>
                                        <th>FG Description</th>
                                        <th style="width: 170px;">RM Part</th>
                                        <th>RM Description</th>
                                        <th style="width: 140px;" class="text-center">ใช้ Forecast</th>
                                        <th style="width: 260px;">Remark</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows ?? [] as $i => $row)
                                        <tr>
                                            <td class="text-center text-muted">
                                                {{ $i + 1 }}
                                            </td>

                                            <td>
                                                <div class="fw-semibold">{{ $row->fg_partnumber ?? '' }}</div>
                                            </td>

                                            <td>
                                                <div>{{ $row->fg_description ?? '-' }}</div>
                                            </td>

                                            <td>
                                                <div class="fw-semibold">{{ $row->rm_partnumber ?? '' }}</div>
                                                <input type="hidden" name="rows[{{ $i }}][rm_partnumber]"
                                                    value="{{ $row->rm_partnumber ?? '' }}">
                                            </td>

                                            <td>
                                                <div>{{ $row->rm_description ?? '-' }}</div>
                                                <input type="hidden" name="rows[{{ $i }}][rm_description]"
                                                    value="{{ $row->rm_description ?? '' }}">
                                            </td>

                                            <td class="text-center">
                                                <div class="form-check d-flex justify-content-center">
                                                    <input class="form-check-input row-check" type="checkbox"
                                                        name="rows[{{ $i }}][is_forecast]" value="1"
                                                        {{ (int) ($row->is_forecast ?? 0) === 1 ? 'checked' : '' }}>
                                                </div>
                                            </td>

                                            <td>
                                                <input type="text" class="form-control form-control-sm"
                                                    name="rows[{{ $i }}][remark]"
                                                    value="{{ $row->remark ?? '' }}" placeholder="หมายเหตุเพิ่มเติม">
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-end mt-3">
                            <button type="submit" class="btn btn-success">
                                <i class="fa-solid fa-floppy-disk me-1"></i> บันทึก
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const checkAllBtn = document.getElementById('check-all-btn');
            const uncheckAllBtn = document.getElementById('uncheck-all-btn');

            function getCheckboxes() {
                return document.querySelectorAll('.row-check');
            }

            if (checkAllBtn) {
                checkAllBtn.addEventListener('click', function() {
                    getCheckboxes().forEach(cb => cb.checked = true);
                });
            }

            if (uncheckAllBtn) {
                uncheckAllBtn.addEventListener('click', function() {
                    getCheckboxes().forEach(cb => cb.checked = false);
                });
            }
        });
    </script>
@endpush
