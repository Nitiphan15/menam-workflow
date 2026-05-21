@extends('layouts.layout')
@section('title', 'Planner Forecast')
@section('page-title', 'Planner Forecast')

@section('content')
    <div class="container-fluid">
        <style>
            .fc-card {
                border: 0;
                border-radius: 14px;
                box-shadow: 0 6px 18px rgba(0, 0, 0, .06)
            }

            .fc-card .card-header {
                background: #fff;
                border-bottom: 1px solid #eef0f2
            }

            .excel-wrap {
                overflow: auto;
                max-height: calc(100vh - 320px);
                border: 1px solid #e6e8eb;
                background: #fff;
                border-radius: 14px
            }

            table.excel {
                border-collapse: separate;
                border-spacing: 0;
                font-size: 12.5px;
                width: max-content;
                min-width: 100%
            }

            table.excel th,
            table.excel td {
                border: 1px solid #eef0f2;
                padding: 6px 8px;
                white-space: nowrap;
                vertical-align: middle
            }

            table.excel thead th {
                position: sticky;
                top: 0;
                z-index: 5;
                background: #fbfbfc;
                text-align: center;
                font-weight: 600
            }

            .num {
                text-align: right;
                font-variant-numeric: tabular-nums
            }

            .kpi-title {
                font-size: 12px;
                color: #6c757d
            }

            .kpi-val {
                font-size: 24px;
                font-weight: 700;
                line-height: 1.15
            }

            .section-title {
                font-size: 15px;
                font-weight: 700
            }

            .empty-box {
                padding: 28px 16px;
                text-align: center;
                color: #6c757d;
                font-size: 14px
            }

            .btn-link.clean-link {
                text-decoration: none;
                font-weight: 600
            }

            .btn-link.clean-link:hover {
                text-decoration: underline
            }

            .planner-note {
                font-size: 12px;
                color: #6c757d;
            }
        </style>

        @if (session('success'))
            <div class="alert alert-success py-2">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger py-2">{{ session('error') }}</div>
        @endif

        <div class="card fc-card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div class="fw-semibold">ตัวกรอง Planner</div>
                <a href="{{ route('fc.planner.master') }}" class="btn btn-sm btn-outline-secondary">ไปหน้า Master</a>
            </div>
            <div class="card-body">
                <form class="row g-3 align-items-end" method="get" action="{{ route('fc.planner.index') }}">
                    <div class="col-md-4">
                        <label class="form-label">RM Part</label>
                        <input type="text" class="form-control form-control-sm" name="sku"
                            value="{{ $fgLike }}">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">บริษัท</label>
                        <select class="form-select form-select-sm" name="company">
                            <option value="ALL" @selected($companyMode === 'ALL')>ทั้งหมด</option>
                            <option value="WIRE" @selected($companyMode === 'WIRE')>WIRE</option>
                            <option value="PLUS" @selected($companyMode === 'PLUS')>PLUS</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">K Factor Default</label>
                        <input type="number" step="0.1" min="0" class="form-control form-control-sm"
                            name="k_factor" id="defaultKInput" value="{{ number_format((float) $selectedK, 1, '.', '') }}">
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary">โหลดข้อมูล</button>
                        <a href="{{ route('fc.planner.index') }}" class="btn btn-sm btn-outline-secondary">ล้างตัวกรอง</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-md-3">
                <div class="card fc-card">
                    <div class="card-body py-2">
                        <div class="kpi-title">ทั้งหมด</div>
                        <div class="kpi-val js-kpi-items">{{ number_format($kpi['items']) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card fc-card">
                    <div class="card-body py-2">
                        <div class="kpi-title">Avg 6M รวม</div>
                        <div class="kpi-val js-kpi-avg6">{{ number_format($kpi['avg6_sum'], 2) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card fc-card">
                    <div class="card-body py-2">
                        <div class="kpi-title">Forecast 1 เดือน</div>
                        <div class="kpi-val js-kpi-f1">{{ number_format($kpi['forecast_1m_sum'], 2) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card fc-card">
                    <div class="card-body py-2">
                        <div class="kpi-title">Forecast 6 เดือน</div>
                        <div class="kpi-val js-kpi-f6">{{ number_format($kpi['forecast_6m_sum'], 2) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <form id="gen-form" method="post" action="{{ route('fc.planner.generate') }}">
            @csrf
            <input type="hidden" name="company" value="{{ $companyMode }}">
            <input type="hidden" name="k_factor" value="{{ number_format((float) $selectedK, 1, '.', '') }}"
                id="hiddenDefaultK">
            <input type="hidden" name="company" value="{{ $companyMode ?? 'ALL' }}">
            <div class="card fc-card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <div class="section-title">Forecast ราย RM Part</div>
                        <div class="small text-muted">
                            เดือนนี้ใช้ค่า save ก่อน, ถ้ายังไม่เคย save จะ fallback ไป Planner Master และ K default
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-primary"
                            id="checkAllForecast">เลือกทั้งหมด</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                            id="uncheckAllForecast">เอาออกทั้งหมด</button>
                        <button type="submit" class="btn btn-sm btn-primary">บันทึก Forecast</button>
                    </div>
                </div>

                <div class="card-body border-bottom">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label d-block">เดือนถัดไป</label>
                            <div class="form-check">
                                <input type="hidden" name="auto_forecast_enabled" value="0">
                                <input class="form-check-input" type="checkbox" value="1" id="auto_forecast_enabled"
                                    name="auto_forecast_enabled" @checked(!empty($autoForecastEnabled))>
                                <label class="form-check-label" for="auto_forecast_enabled">
                                    save auto ในเดือนถัดไป
                                </label>
                            </div>
                            <div class="planner-note mt-1">
                                ติ๊กไว้เพื่อจำ setting ปัจจุบันสำหรับรอบถัดไป ยังไม่สั่งรัน command อัตโนมัติในตอนนี้
                            </div>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">ทุกวันที่</label>
                            <select class="form-select form-select-sm" name="auto_forecast_day">
                                @for ($d = 1; $d <= 31; $d++)
                                    <option value="{{ $d }}" @selected((int) $autoForecastDay === $d)>{{ $d }}
                                    </option>
                                @endfor
                            </select>
                        </div>
                    </div>
                </div>

                @if ($rows->isEmpty())
                    <div class="empty-box">ไม่มีข้อมูลสำหรับ forecast</div>
                @else
                    <div class="excel-wrap">
                        <table class="excel">
                            <thead>
                                <tr>
                                    <th>RM Part</th>
                                    <th>Description</th>
                                    <th>Avg 6M</th>
                                    <th>Forecast?</th>
                                    <th>K ที่ใช้</th>
                                    <th>Manual Avg 1M</th>
                                    <th>Forecast 1M</th>
                                    <th>Forecast 6M</th>
                                    <th>Remark</th>
                                    <th>History</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    @php
                                        $meta = [
                                            'fg_partnumber' => $r['fg_partnumber'] ?? '',
                                            'fg_description' => $r['fg_description'] ?? '',
                                            'rm_partnumber' => $r['rm_partnumber'] ?? '',
                                            'rm_description' => $r['rm_description'] ?? '',
                                            'avg6' => (float) ($r['avg6'] ?? 0),
                                        ];
                                    @endphp
                                    <tr data-row-key="{{ $r['row_key'] }}" data-avg6="{{ (float) ($r['avg6'] ?? 0) }}">
                                        <td class="fw-semibold">{{ $r['rm_partnumber'] ?? '-' }}</td>
                                        <td>{{ $r['rm_description'] ?? '-' }}</td>

                                        <td class="num js-avg6">{{ number_format((float) ($r['avg6'] ?? 0), 2) }}</td>

                                        <td class="text-center">
                                            <input type="checkbox" class="form-check-input js-forecast-flag"
                                                name="forecast_flag[{{ $r['row_key'] }}]" value="1"
                                                @checked((int) ($r['is_selected'] ?? 0) === 1)>
                                        </td>

                                        <td style="min-width:100px;">
                                            <input type="number" step="0.1" min="0"
                                                class="form-control form-control-sm text-end js-row-k"
                                                name="row_k_factor[{{ $r['row_key'] }}]"
                                                value="{{ number_format((float) ($r['row_k_factor'] ?? $selectedK), 1, '.', '') }}">
                                        </td>

                                        <td style="min-width:120px;">
                                            <input type="number" step="0.01" min="0"
                                                class="form-control form-control-sm text-end js-manual-1m"
                                                name="manual_forecast_1m[{{ $r['row_key'] }}]"
                                                value="{{ number_format((float) ($r['manual_forecast_1m'] ?? 0), 2, '.', '') }}">
                                        </td>

                                        <td class="num js-f1">{{ number_format((float) ($r['forecast_1m'] ?? 0), 2) }}
                                        </td>
                                        <td class="num js-f6">{{ number_format((float) ($r['forecast_6m'] ?? 0), 2) }}
                                        </td>

                                        <td style="min-width:180px;">
                                            <input type="text" class="form-control form-control-sm"
                                                name="row_remark[{{ $r['row_key'] }}]"
                                                value="{{ $r['row_remark'] ?? '' }}">
                                        </td>

                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                                data-bs-toggle="modal" data-bs-target="#historyModal{{ $loop->index }}">
                                                ดู
                                            </button>
                                        </td>

                                        <input type="hidden" name="row_meta[{{ $r['row_key'] }}]"
                                            value='@json($meta)'>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        @foreach ($rows as $r)
                            <div class="modal fade" id="historyModal{{ $loop->index }}" tabindex="-1"
                                aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">History : {{ $r['rm_partnumber'] ?? '-' }}</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="table-responsive">
                                                <table class="table table-sm table-bordered">
                                                    <thead>
                                                        <tr>
                                                            <th>เดือนย้อนหลัง</th>
                                                            <th class="text-end">Qty</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @forelse(($r['history_detail'] ?? []) as $h)
                                                            <tr>
                                                                <td>{{ $h['ym'] ?? '-' }}</td>
                                                                <td class="text-end">
                                                                    {{ number_format((float) ($h['qty'] ?? 0), 2) }}</td>
                                                            </tr>
                                                        @empty
                                                            <tr>
                                                                <td colspan="2" class="text-center text-muted">
                                                                    ไม่มีข้อมูลย้อนหลัง</td>
                                                            </tr>
                                                        @endforelse
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </form>

        <form method="post" action="{{ route('fc.planner.manual-save') }}">
            @csrf
            <input type="hidden" name="company" value="{{ $companyMode }}">

            <div class="card fc-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <div class="section-title">Manual Forecast</div>
                        <div class="small text-muted">เฉพาะรายการที่ไม่มีข้อมูลย้อนหลัง 6 เดือน</div>
                    </div>

                    @if ($manualOnlyRows->isNotEmpty())
                        <button type="submit" class="btn btn-sm btn-primary">บันทึก Manual</button>
                    @endif
                </div>

                @if ($manualOnlyRows->isEmpty())
                    <div class="empty-box">ไม่มีรายการที่ต้องกรอก Manual Forecast เพิ่ม</div>
                @else
                    <div class="excel-wrap">
                        <table class="excel">
                            <thead>
                                <tr>
                                    <th>RM Part</th>
                                    <th>FG Part</th>
                                    <th>Manual 1M</th>
                                    <th>Forecast 6M</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($manualOnlyRows as $r)
                                    @php
                                        $manualMeta = [
                                            'fg_partnumber' => $r['fg_partnumber'] ?? '',
                                            'fg_description' => $r['fg_description'] ?? '',
                                            'rm_partnumber' => $r['rm_partnumber'] ?? '',
                                        ];
                                    @endphp
                                    <tr>
                                        <td class="fw-semibold">{{ $r['rm_partnumber'] ?? '-' }}</td>
                                        <td>{{ $r['fg_partnumber'] ?? '-' }}</td>
                                        <td>
                                            <input type="number" step="0.01" min="0"
                                                class="form-control form-control-sm text-end js-manual-1m-only"
                                                name="manual_rows[{{ $r['row_key'] }}]"
                                                value="{{ number_format((float) ($r['manual_forecast_1m'] ?? 0), 2, '.', '') }}">
                                        </td>
                                        <td class="num js-manual-6m-only">
                                            {{ number_format((float) (($r['manual_forecast_1m'] ?? 0) * 6), 2) }}
                                        </td>
                                        <input type="hidden" name="manual_meta[{{ $r['row_key'] }}]"
                                            value='@json($manualMeta)'>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const defaultKInput = document.getElementById('defaultKInput');
            const hiddenDefaultK = document.getElementById('hiddenDefaultK');

            function num(v) {
                const x = parseFloat(v);
                return Number.isNaN(x) ? 0 : x;
            }

            function format2(v) {
                return num(v).toLocaleString(undefined, {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }

            function recalcManualOnlyRow(tr) {
                if (!tr) return;
                const input = tr.querySelector('.js-manual-1m-only');
                const f6Cell = tr.querySelector('.js-manual-6m-only');
                if (!input || !f6Cell) return;

                const f1 = num(input.value || 0);
                f6Cell.textContent = format2(f1 * 6);
            }

            function calcAuto(avg6, k) {
                return +(num(avg6) * num(k)).toFixed(2);
            }

            function recalcRow(tr) {
                const checked = tr.querySelector('.js-forecast-flag')?.checked ?? false;
                const avg6 = num(tr.dataset.avg6 || 0);
                const kInput = tr.querySelector('.js-row-k');
                const manualInput = tr.querySelector('.js-manual-1m');
                const f1Cell = tr.querySelector('.js-f1');
                const f6Cell = tr.querySelector('.js-f6');

                const k = num(kInput?.value || 0);
                const auto1 = calcAuto(avg6, k);

                let manualVal = manualInput?.value ?? '';
                let forecast1 = 0;

                if (checked) {
                    if (manualVal === '' || manualVal === null) {
                        forecast1 = auto1;
                    } else {
                        forecast1 = num(manualVal);
                    }
                }

                const forecast6 = checked ? +(forecast1 * 6).toFixed(2) : 0;

                if (f1Cell) f1Cell.textContent = format2(forecast1);
                if (f6Cell) f6Cell.textContent = format2(forecast6);
            }

            function recalcKpi() {
                let items = 0;
                let avg6Sum = 0;
                let f1Sum = 0;
                let f6Sum = 0;

                document.querySelectorAll('tr[data-row-key]').forEach(tr => {
                    items++;
                    avg6Sum += num(tr.dataset.avg6 || 0);
                    f1Sum += num((tr.querySelector('.js-f1')?.textContent || '0').replace(/,/g, ''));
                    f6Sum += num((tr.querySelector('.js-f6')?.textContent || '0').replace(/,/g, ''));
                });

                document.querySelector('.js-kpi-items')?.replaceChildren(document.createTextNode(items
                    .toLocaleString()));
                document.querySelector('.js-kpi-avg6')?.replaceChildren(document.createTextNode(format2(avg6Sum)));
                document.querySelector('.js-kpi-f1')?.replaceChildren(document.createTextNode(format2(f1Sum)));
                document.querySelector('.js-kpi-f6')?.replaceChildren(document.createTextNode(format2(f6Sum)));
            }

            function recalcAll() {
                document.querySelectorAll('tr[data-row-key]').forEach(recalcRow);
                recalcKpi();
            }

            if (defaultKInput && hiddenDefaultK) {
                hiddenDefaultK.value = defaultKInput.value;
            }

            document.querySelectorAll('.js-row-k').forEach(input => {
                if (!input.value || parseFloat(input.value) === 0) {
                    input.value = defaultKInput?.value || '1.2';
                }

                input.addEventListener('input', function() {
                    const tr = this.closest('tr');
                    const avg6 = num(tr.dataset.avg6 || 0);
                    const manualInput = tr.querySelector('.js-manual-1m');

                    if (manualInput && (!manualInput.dataset.touched || manualInput.dataset
                            .touched !== '1')) {
                        manualInput.value = calcAuto(avg6, this.value).toFixed(2);
                    }

                    recalcRow(tr);
                    recalcKpi();
                });

                input.addEventListener('change', function() {
                    this.dataset.touched = '1';
                    recalcRow(this.closest('tr'));
                    recalcKpi();
                });
            });

            document.querySelectorAll('.js-manual-1m').forEach(input => {
                input.addEventListener('input', function() {
                    this.dataset.touched = '1';
                    recalcRow(this.closest('tr'));
                    recalcKpi();
                });

                input.addEventListener('change', function() {
                    this.dataset.touched = '1';
                    recalcRow(this.closest('tr'));
                    recalcKpi();
                });
            });

            document.querySelectorAll('.js-forecast-flag').forEach(input => {
                input.addEventListener('change', function() {
                    recalcRow(this.closest('tr'));
                    recalcKpi();
                });
            });

            document.querySelectorAll('.js-manual-1m-only').forEach(input => {
                input.addEventListener('input', function() {
                    recalcManualOnlyRow(this.closest('tr'));
                });

                input.addEventListener('change', function() {
                    recalcManualOnlyRow(this.closest('tr'));
                });
            });

            defaultKInput?.addEventListener('input', function() {
                if (hiddenDefaultK) hiddenDefaultK.value = this.value;

                document.querySelectorAll('.js-row-k').forEach(input => {
                    if (!input.dataset.touched || input.dataset.touched !== '1') {
                        input.value = this.value;

                        const tr = input.closest('tr');
                        const avg6 = num(tr.dataset.avg6 || 0);
                        const manualInput = tr.querySelector('.js-manual-1m');

                        if (manualInput && (!manualInput.dataset.touched || manualInput.dataset
                                .touched !== '1')) {
                            manualInput.value = calcAuto(avg6, this.value).toFixed(2);
                        }
                    }
                });

                recalcAll();
            });

            document.getElementById('checkAllForecast')?.addEventListener('click', function() {
                document.querySelectorAll('.js-forecast-flag').forEach(el => el.checked = true);
                recalcAll();
            });

            document.getElementById('uncheckAllForecast')?.addEventListener('click', function() {
                document.querySelectorAll('.js-forecast-flag').forEach(el => el.checked = false);
                recalcAll();
            });

            recalcAll();
            document.querySelectorAll('.js-manual-1m-only').forEach(input => recalcManualOnlyRow(input.closest('tr')));
        });
    </script>
@endsection
