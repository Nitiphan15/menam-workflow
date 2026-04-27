@extends('layouts.layout')
@section('title', 'Division Forecast FG')
@section('page-title', 'Division Forecast FG')

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
        </style>

        @if (session('success'))
            <div class="alert alert-success py-2">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger py-2">{{ session('error') }}</div>
        @endif

        <div class="card fc-card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div class="fw-semibold">ตัวกรอง</div>
                <div class="small text-muted">
                    Division:
                    <b>{{ $divisionLabels[$salesCode] ?? $salesCode }}</b>
                </div>
            </div>
            <div class="card-body">
                <form class="row g-3 align-items-end" method="get" action="{{ route('fc.division') }}">

                    <div class="col-md-3">
                        <label class="form-label">Division</label>

                        @if (!empty($showDivisionDropdown) && $showDivisionDropdown)
                            <select name="division" class="form-select form-select-sm">
                                @foreach ($allowedDivisions as $div)
                                    <option value="{{ $div }}" {{ $salesCode === $div ? 'selected' : '' }}>
                                        {{ $divisionLabels[$div] ?? $div }}
                                    </option>
                                @endforeach
                            </select>
                        @else
                            <input type="text" class="form-control form-control-sm"
                                value="{{ $divisionLabels[$salesCode] ?? $salesCode }}" readonly>
                            <input type="hidden" name="division" value="{{ $salesCode }}">
                        @endif
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">FG Part Filter</label>
                        <input type="text" name="fg" class="form-control form-control-sm"
                            value="{{ $fgLike ?? '' }}" placeholder="เช่น FMY309,FTY316 หรือ FMY309 FTY316">
                    </div>

                    <div class="col-md-3 position-relative">
                        <label class="form-label">Customer Filter</label>
                        <input type="text" name="customer_name" id="customerFilterInput" class="form-control form-control-sm"
                            value="{{ $customerNameText ?? '' }}" placeholder="เช่น BOC,SEAH หรือ BOC SEAH">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">K Factor Default</label>
                        <input type="number" class="form-control form-control-sm" name="k_factor" step="0.1"
                            min="0" inputmode="decimal" value="{{ number_format((float) $selectedK, 1, '.', '') }}">
                    </div>

                    <div class="col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-outline-primary w-100">โหลดข้อมูล</button>
                        <a href="{{ route('fc.division', ['division' => $salesCode]) }}"
                            class="btn btn-sm btn-outline-secondary w-100">
                            ล้างตัวกรอง
                        </a>
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

        <form id="gen-form" method="post" action="{{ route('fc.division.generate') }}">
            @csrf
            <input type="hidden" name="sales_code" value="{{ $salesCode }}">
            <input type="hidden" name="division" value="{{ $salesCode }}">
            <input type="hidden" name="customer_name" value="{{ $customerNameText ?? '' }}">
            <input type="hidden" name="k_factor" value="{{ number_format((float) $selectedK, 1, '.', '') }}">

            <div class="card fc-card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <div class="section-title">Forecast ราย Customer + FG Part</div>
                        <div class="small text-muted">เดือนนี้ใช้ค่า save ก่อน, ถ้ายังไม่เคย save จะ fallback ไป Division
                            Part Master และ K default</div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-primary"
                            id="checkAllForecast">เลือกทั้งหมด</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                            id="uncheckAllForecast">เอาออกทั้งหมด</button>
                        <button type="submit" class="btn btn-sm btn-primary">บันทึก Forecast</button>
                    </div>
                </div>

                @if ($rows->isEmpty())
                    <div class="empty-box">ไม่มีข้อมูลสำหรับ forecast</div>
                @else
                    <div class="excel-wrap">
                        <table class="excel">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>FG Part</th>
                                    <th>Description</th>
                                    <th>Sales Order</th>
                                    <th>Avg 6M</th>
                                    <th>Forecast?</th>
                                    <th>K ที่ใช้</th>
                                    <th>Forecast 1M</th>
                                    <th>Forecast 6M</th>
                                    <th>Supplier</th>
                                    <th>Remark</th>
                                    <th>History</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    @php
                                        $meta = [
                                            'customer_id' => $r['customer_id'],
                                            'customer_name' => $r['customer_name'],
                                            'fg_partnumber' => $r['fg_partnumber'],
                                            'fg_description' => $r['fg_description'],
                                            'rm_partnumber' => $r['rm_partnumber'],
                                            'avg6' => (float) $r['avg6'],
                                            'sales_order_qty' => (float) ($r['sales_order_qty'] ?? 0),
                                        ];
                                    @endphp
                                    <tr data-row-key="{{ $r['row_key'] }}" data-avg6="{{ (float) $r['avg6'] }}">
                                        <td>{{ $r['customer_name'] }}</td>
                                        <td class="fw-semibold">{{ $r['fg_partnumber'] }}</td>
                                        <td>{{ $r['fg_description'] }}</td>

                                        <td class="num">
                                            <button type="button" class="btn btn-link clean-link p-0 js-so-detail"
                                                data-fg="{{ $r['fg_partnumber'] }}" data-company="ALL">
                                                {{ number_format((float) ($r['sales_order_qty'] ?? 0), 2) }}
                                            </button>
                                        </td>

                                        <td class="num js-avg6">
                                            <button type="button" class="btn btn-link clean-link p-0 avg6-link"
                                                data-customer="{{ $r['customer_name'] }}"
                                                data-fg="{{ $r['fg_partnumber'] }}"
                                                data-description="{{ $r['fg_description'] }}"
                                                data-history='@json($r['history_detail'] ?? [])'>
                                                {{ number_format((float) $r['avg6'], 2) }}
                                            </button>
                                        </td>

                                        <td class="text-center">
                                            <input type="checkbox" class="js-forecast-flag"
                                                name="forecast_flag[{{ $r['row_key'] }}]" value="1"
                                                @checked((int) ($r['is_selected'] ?? 0) === 1)>
                                        </td>

                                        <td>
                                            <input type="number" name="row_k_factor[{{ $r['row_key'] }}]"
                                                class="form-control form-control-sm js-row-kfactor" step="0.1"
                                                min="0" inputmode="decimal"
                                                value="{{ number_format((float) ($r['row_k_factor'] ?? ($r['k_used'] ?? $selectedK)), 1, '.', '') }}">
                                        </td>

                                        <td>
                                            <input type="number" step="0.01" min="0"
                                                class="form-control form-control-sm js-manual-forecast"
                                                name="manual_forecast_1m[{{ $r['row_key'] }}]"
                                                value="{{ number_format((float) ($r['manual_forecast_1m'] ?? 0), 2, '.', '') }}"
                                                data-user-edited="{{ !empty($r['manual_forecast_saved']) ? '1' : '0' }}">
                                        </td>

                                        <td class="num js-f6">{{ number_format((float) $r['forecast_6m'], 2) }}</td>

                                        <td>
                                            <select name="supplier_code[{{ $r['row_key'] }}]"
                                                class="form-select form-select-sm">
                                                <option value="">- เลือก Supplier -</option>
                                                @foreach ($suppliers as $sp)
                                                    <option value="{{ $sp['supplier_code'] }}"
                                                        @selected(($r['supplier_code'] ?? '') === $sp['supplier_code'])>
                                                        {{ $sp['label'] }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </td>

                                        <td>
                                            <input type="text" class="form-control form-control-sm"
                                                name="row_remark[{{ $r['row_key'] }}]"
                                                value="{{ $r['row_remark'] ?? '' }}" placeholder="Remark">
                                        </td>

                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline-secondary btn-history"
                                                data-customer="{{ $r['customer_name'] }}"
                                                data-fg="{{ $r['fg_partnumber'] }}"
                                                data-description="{{ $r['fg_description'] }}"
                                                data-history='@json($r['save_history'] ?? [])'>
                                                ดู
                                            </button>
                                        </td>

                                        <input type="hidden" name="row_meta[{{ $r['row_key'] }}]"
                                            value='@json($meta)'>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </form>

        <form id="manualForecastForm" method="post" action="{{ route('fc.division.manual-save') }}">
            @csrf
            <input type="hidden" name="sales_code" value="{{ $salesCode }}">
            <input type="hidden" name="division" value="{{ $salesCode }}">

            <div class="card fc-card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <div class="section-title">Manual Forecast</div>
                        <div class="small text-muted">เฉพาะรายการที่ sales ดูแล แต่ไม่มีข้อมูลย้อนหลัง 6 เดือน</div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="addManualRow">
                            เพิ่ม Manual Row
                        </button>
                        <button type="submit" class="btn btn-sm btn-primary">บันทึก Manual</button>
                    </div>
                </div>

                @if (empty($manualOnlyRows) || $manualOnlyRows->isEmpty())
                    <div class="px-3 pt-3 small text-muted">ยังไม่มีรายการตั้งต้น คุณสามารถกด "เพิ่ม Manual Row" เพื่อเพิ่ม customer และ FG เองได้</div>
                @endif
                <div class="excel-wrap">
                    <table class="excel">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>FG Part</th>
                                <th>RM Part</th>
                                <th>Manual 1M</th>
                                <th>Forecast 6M</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="manualForecastBody">
                            @foreach ($manualOnlyRows as $r)
                                @php
                                    $manualMeta = [
                                        'customer_id' => $r['customer_id'] ?? 0,
                                        'customer_name' => $r['customer_name'] ?? '',
                                        'fg_partnumber' => $r['fg_partnumber'] ?? '',
                                        'fg_description' => $r['fg_description'] ?? '',
                                        'rm_partnumber' => $r['rm_partnumber'] ?? '',
                                    ];
                                @endphp
                                <tr class="js-manual-row" data-row-key="{{ $r['row_key'] }}">
                                    <td>{{ $r['customer_name'] ?? '-' }}</td>
                                    <td class="fw-semibold">{{ $r['fg_partnumber'] ?? '-' }}</td>
                                    <td class="js-manual-rm-cell">{{ $r['rm_partnumber'] ?? '-' }}</td>
                                    <td>
                                        <input type="number" step="0.01" min="0"
                                            class="form-control form-control-sm text-end js-manual-1m-only"
                                            name="manual_rows[{{ $r['row_key'] }}]"
                                            value="{{ number_format((float) ($r['manual_forecast_1m'] ?? 0), 2, '.', '') }}">
                                    </td>
                                    <td class="num js-manual-6m-only">
                                        {{ number_format((float) (($r['manual_forecast_1m'] ?? 0) * 6), 2) }}
                                    </td>
                                    <td class="text-center text-muted">-</td>
                                    <input type="hidden" name="manual_meta[{{ $r['row_key'] }}]"
                                        value='@json($manualMeta)'>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </form>

        <template id="manualRowTemplate">
            <tr class="js-manual-row">
                <td class="position-relative">
                    <input type="text" class="form-control form-control-sm js-manual-customer-input"
                        placeholder="ค้นหา customer">
                    <div class="list-group position-absolute w-100 shadow-sm d-none js-manual-customer-suggest"
                        style="z-index: 20; max-height: 220px; overflow: auto;"></div>
                </td>
                <td class="position-relative">
                    <input type="text" class="form-control form-control-sm js-manual-fg-input"
                        placeholder="ค้นหา FG Part">
                    <div class="list-group position-absolute w-100 shadow-sm d-none js-manual-fg-suggest"
                        style="z-index: 20; max-height: 220px; overflow: auto;"></div>
                </td>
                <td class="js-manual-rm-cell">-</td>
                <td>
                    <input type="number" step="0.01" min="0"
                        class="form-control form-control-sm text-end js-manual-1m-only">
                </td>
                <td class="num js-manual-6m-only">0.00</td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger js-remove-manual-row">ลบ</button>
                </td>
            </tr>
        </template>

        <div class="modal fade" id="historyModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <div class="fw-semibold" id="historyModalTitle">ประวัติการบันทึก</div>
                            <div class="small text-muted" id="historyModalSub"></div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0">
                                <thead>
                                    <tr>
                                        <th>เดือนที่บันทึก</th>
                                        <th class="text-end">K Factor</th>
                                        <th class="text-end">Forecast 1M</th>
                                        <th class="text-end">Forecast 6M</th>
                                        <th>Source</th>
                                        <th>เวลาบันทึก</th>
                                    </tr>
                                </thead>
                                <tbody id="historyModalBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="soDetailModal" tabindex="-1">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Sales Order Detail</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div id="soDetailLoading" class="text-muted">Loading...</div>
                        <div class="table-responsive d-none" id="soDetailWrap">
                            <table class="table table-sm table-bordered">
                                <thead>
                                    <tr>
                                        <th>Company</th>
                                        <th>FG Part</th>
                                        <th>Description</th>
                                        <th>PO</th>
                                        <th>SO</th>
                                        <th>Order Date</th>
                                        <th>Due Date</th>
                                        <th>Customer</th>
                                        <th class="text-end">Backorder Qty</th>
                                    </tr>
                                </thead>
                                <tbody id="soDetailBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('checkAllForecast')?.addEventListener('click', () => {
            document.querySelectorAll('table.excel tbody .js-forecast-flag').forEach(el => el.checked = true);
            document.querySelectorAll('table.excel tbody tr[data-row-key]').forEach(recalcRow);
            recalcKpi();
        });

        document.getElementById('uncheckAllForecast')?.addEventListener('click', () => {
            document.querySelectorAll('table.excel tbody .js-forecast-flag').forEach(el => el.checked = false);
            document.querySelectorAll('table.excel tbody tr[data-row-key]').forEach(recalcRow);
            recalcKpi();
        });

        function formatNum(n, digits = 2) {
            return Number(n || 0).toLocaleString(undefined, {
                minimumFractionDigits: digits,
                maximumFractionDigits: digits
            });
        }

        function recalcManualOnlyRow(tr) {
            if (!tr) return;
            const input = tr.querySelector('.js-manual-1m-only');
            const f6El = tr.querySelector('.js-manual-6m-only');
            if (!input || !f6El) return;

            const f1 = parseFloat(input.value || '0') || 0;
            f6El.textContent = formatNum(f1 * 6);
        }

        function bindManual1mInput(input) {
            if (!input) return;
            input.addEventListener('input', function() {
                recalcManualOnlyRow(this.closest('tr'));
            });

            input.addEventListener('change', function() {
                recalcManualOnlyRow(this.closest('tr'));
            });

            recalcManualOnlyRow(input.closest('tr'));
        }

        function debounce(fn, wait = 250) {
            let timer = null;
            return function(...args) {
                clearTimeout(timer);
                timer = setTimeout(() => fn.apply(this, args), wait);
            };
        }

        function hideSuggest(el) {
            if (!el) return;
            el.classList.add('d-none');
            el.innerHTML = '';
        }

        function bindAutocompleteInput({
            input,
            suggestEl,
            fetchItems,
            renderItem,
            onSelect
        }) {
            if (!input || !suggestEl) return;

            const runLookup = debounce(async () => {
                const term = input.value.trim();
                if (term.length < 2) {
                    hideSuggest(suggestEl);
                    return;
                }

                try {
                    const items = await fetchItems(term);
                    if (!Array.isArray(items) || !items.length) {
                        hideSuggest(suggestEl);
                        return;
                    }

                    suggestEl.innerHTML = items.map(renderItem).join('');
                    suggestEl.classList.remove('d-none');
                } catch (err) {
                    hideSuggest(suggestEl);
                }
            }, 250);

            input.addEventListener('input', runLookup);
            input.addEventListener('focus', runLookup);
            input.addEventListener('blur', () => setTimeout(() => hideSuggest(suggestEl), 150));

            suggestEl.addEventListener('click', function(e) {
                const btn = e.target.closest('button[data-payload]');
                if (!btn) return;
                try {
                    onSelect(JSON.parse(decodeURIComponent(btn.dataset.payload || '%7B%7D')));
                } finally {
                    hideSuggest(suggestEl);
                }
            });
        }

        const customerFilterInput = document.getElementById('customerFilterInput');
        let customerSuggestEl = document.getElementById('customerFilterSuggest');

        if (customerFilterInput) {
            if (!customerSuggestEl) {
                customerSuggestEl = document.createElement('div');
                customerSuggestEl.id = 'customerFilterSuggest';
                customerSuggestEl.className =
                    'list-group position-absolute w-100 shadow-sm d-none';
                customerSuggestEl.style.zIndex = '20';
                customerSuggestEl.style.maxHeight = '220px';
                customerSuggestEl.style.overflow = 'auto';
                customerFilterInput.insertAdjacentElement('afterend', customerSuggestEl);
            }

            const customerLookupBaseUrl = `{{ route('fc.division.customer.lookup') }}`;
            let customerLookupTimer = null;

            const hideCustomerSuggest = () => {
                customerSuggestEl.classList.add('d-none');
                customerSuggestEl.innerHTML = '';
            };

            customerFilterInput.addEventListener('input', function() {
                const term = this.value.trim();
                const divisionEl = document.querySelector('select[name="division"], input[name="division"]');
                const division = divisionEl?.value || `{{ $salesCode }}`;

                clearTimeout(customerLookupTimer);

                if (term.length < 2) {
                    hideCustomerSuggest();
                    return;
                }

                customerLookupTimer = setTimeout(async () => {
                    try {
                        const url =
                            `${customerLookupBaseUrl}?division=${encodeURIComponent(division)}&q=${encodeURIComponent(term)}`;
                        const res = await fetch(url, {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });
                        const data = await res.json();
                        const items = Array.isArray(data?.items) ? data.items : [];

                        if (!items.length) {
                            hideCustomerSuggest();
                            return;
                        }

                        customerSuggestEl.innerHTML = items.map(item => `
                            <button type="button" class="list-group-item list-group-item-action js-customer-suggest"
                                data-text="${String(item.text ?? '').replace(/"/g, '&quot;')}">
                                ${item.text ?? ''}
                            </button>
                        `).join('');
                        customerSuggestEl.classList.remove('d-none');
                    } catch (err) {
                        hideCustomerSuggest();
                    }
                }, 250);
            });

            customerSuggestEl.addEventListener('click', function(e) {
                const btn = e.target.closest('.js-customer-suggest');
                if (!btn) return;
                customerFilterInput.value = btn.dataset.text || '';
                hideCustomerSuggest();
            });

            customerFilterInput.addEventListener('blur', () => {
                setTimeout(hideCustomerSuggest, 150);
            });
        }

        document.querySelectorAll('.js-manual-1m-only').forEach(bindManual1mInput);

        const manualBodyEl = document.getElementById('manualForecastBody');
        const manualTpl = document.getElementById('manualRowTemplate');
        const addManualRowBtn = document.getElementById('addManualRow');
        const manualFormEl = document.getElementById('manualForecastForm');
        const divisionEl = document.querySelector('select[name="division"], input[name="division"]');
        const manualCustomerLookupUrl = `{{ route('fc.division.customer.lookup') }}`;
        const manualPartLookupUrl = `{{ route('fc.division.part.lookup') }}`;
        let manualRowSeq = 0;

        function syncManualMeta(tr) {
            const metaInput = tr?.querySelector('.js-manual-meta');
            if (!metaInput) return;

            const meta = {
                customer_id: tr.dataset.customerId || '',
                customer_name: tr.dataset.customerName || '',
                fg_partnumber: tr.dataset.fgPartnumber || '',
                fg_description: tr.dataset.fgDescription || '',
                rm_partnumber: tr.dataset.rmPartnumber || '',
            };

            metaInput.value = JSON.stringify(meta);
        }

        function bindManualDynamicRow(tr, rowKey) {
            if (!tr) return;

            tr.dataset.rowKey = rowKey;
            tr.dataset.dynamicRow = '1';

            const customerInput = tr.querySelector('.js-manual-customer-input');
            const customerSuggest = tr.querySelector('.js-manual-customer-suggest');
            const fgInput = tr.querySelector('.js-manual-fg-input');
            const fgSuggest = tr.querySelector('.js-manual-fg-suggest');
            const rmCell = tr.querySelector('.js-manual-rm-cell');
            const qtyInput = tr.querySelector('.js-manual-1m-only');
            const removeBtn = tr.querySelector('.js-remove-manual-row');

            qtyInput.name = `manual_rows[${rowKey}]`;
            qtyInput.value = '0.00';
            bindManual1mInput(qtyInput);

            const metaInput = document.createElement('input');
            metaInput.type = 'hidden';
            metaInput.name = `manual_meta[${rowKey}]`;
            metaInput.className = 'js-manual-meta';
            tr.appendChild(metaInput);
            syncManualMeta(tr);

            removeBtn?.addEventListener('click', function() {
                tr.remove();
            });

            bindAutocompleteInput({
                input: customerInput,
                suggestEl: customerSuggest,
                fetchItems: async(term) => {
                    const division = divisionEl?.value || `{{ $salesCode }}`;
                    const url =
                        `${manualCustomerLookupUrl}?division=${encodeURIComponent(division)}&q=${encodeURIComponent(term)}`;
                    const res = await fetch(url, {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const data = await res.json();
                    return Array.isArray(data?.items) ? data.items : [];
                },
                renderItem: (item) =>
                    `<button type="button" class="list-group-item list-group-item-action" data-payload="${encodeURIComponent(JSON.stringify(item))}">${item.text ?? ''}</button>`,
                onSelect: (item) => {
                    customerInput.value = item.text || '';
                    tr.dataset.customerId = item.id || '';
                    tr.dataset.customerName = item.customer_name || item.text || '';
                    syncManualMeta(tr);
                }
            });

            bindAutocompleteInput({
                input: fgInput,
                suggestEl: fgSuggest,
                fetchItems: async(term) => {
                    const url = `${manualPartLookupUrl}?q=${encodeURIComponent(term)}`;
                    const res = await fetch(url, {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const data = await res.json();
                    return Array.isArray(data?.items) ? data.items : [];
                },
                renderItem: (item) =>
                    `<button type="button" class="list-group-item list-group-item-action" data-payload="${encodeURIComponent(JSON.stringify(item))}">${item.text ?? ''}</button>`,
                onSelect: (item) => {
                    fgInput.value = item.fg_partnumber || item.text || '';
                    tr.dataset.fgPartnumber = item.fg_partnumber || '';
                    tr.dataset.fgDescription = item.fg_description || '';
                    tr.dataset.rmPartnumber = item.rm_partnumber || '';
                    rmCell.textContent = item.rm_partnumber || '-';
                    syncManualMeta(tr);
                }
            });
        }

        addManualRowBtn?.addEventListener('click', function() {
            if (!manualBodyEl || !manualTpl) return;
            manualRowSeq += 1;
            const rowKey = `new_${Date.now()}_${manualRowSeq}`;
            const fragment = manualTpl.content.cloneNode(true);
            const tr = fragment.querySelector('tr');
            bindManualDynamicRow(tr, rowKey);
            manualBodyEl.appendChild(fragment);
            tr.scrollIntoView({
                behavior: 'smooth',
                block: 'end'
            });
            tr.querySelector('.js-manual-customer-input')?.focus();
        });

        manualFormEl?.addEventListener('submit', function(e) {
            const dynamicRows = Array.from(this.querySelectorAll('tr[data-dynamic-row="1"]'));

            for (const tr of dynamicRows) {
                const customerId = (tr.dataset.customerId || '').trim();
                const fgPartnumber = (tr.dataset.fgPartnumber || '').trim();
                const rmPartnumber = (tr.dataset.rmPartnumber || '').trim();
                const qtyInput = tr.querySelector('.js-manual-1m-only');
                const qtyRaw = (qtyInput?.value || '').trim();

                const hasAnyInput = customerId !== '' || fgPartnumber !== '' || rmPartnumber !== '' || qtyRaw !== '' && qtyRaw !== '0.00' && qtyRaw !== '0';
                if (!hasAnyInput) {
                    continue;
                }

                if (customerId === '') {
                    e.preventDefault();
                    alert('กรุณาเลือก Customer ในแถว Manual ที่เพิ่มใหม่');
                    tr.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                    tr.querySelector('.js-manual-customer-input')?.focus();
                    return;
                }

                if (fgPartnumber === '') {
                    e.preventDefault();
                    alert('กรุณาเลือก FG Part ในแถว Manual ที่เพิ่มใหม่');
                    tr.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                    tr.querySelector('.js-manual-fg-input')?.focus();
                    return;
                }

                if (rmPartnumber === '') {
                    e.preventDefault();
                    alert('FG Part ที่เลือกยังไม่มี RM Part (part.f4) กรุณาเลือก FG ที่มี RM');
                    tr.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                    tr.querySelector('.js-manual-fg-input')?.focus();
                    return;
                }
            }
        });

        function recalcRow(tr) {
            if (!tr) return;

            const avg6 = parseFloat(tr.dataset.avg6 || '0') || 0;
            const forecastFlagEl = tr.querySelector('.js-forecast-flag');
            const rowKEl = tr.querySelector('.js-row-kfactor');
            const manualInput = tr.querySelector('.js-manual-forecast');
            const f6El = tr.querySelector('.js-f6');

            if (!forecastFlagEl || !rowKEl || !manualInput || !f6El) {
                return;
            }

            const checked = forecastFlagEl.checked;
            const k = parseFloat(rowKEl.value || '0') || 0;
            const manualRaw = manualInput.value || '';
            const manual = manualRaw === '' ? null : parseFloat(manualRaw);
            const userEdited = manualInput.dataset.userEdited === '1';

            let f1 = 0;
            let f6 = 0;

            if (checked) {
                if (userEdited && manual !== null && !Number.isNaN(manual)) {
                    f1 = manual;
                } else {
                    f1 = avg6 * k;
                    manualInput.value = f1.toFixed(2);
                }
                f6 = f1 * 6;
            } else {
                if (!userEdited) {
                    manualInput.value = '0.00';
                }
            }

            f6El.textContent = formatNum(f6);
        }

        function recalcKpi() {
            let items = 0;
            let avg6 = 0;
            let f1 = 0;
            let f6 = 0;

            document.querySelectorAll('table.excel tbody tr[data-row-key]').forEach(tr => {
                items += 1;
                avg6 += parseFloat(tr.dataset.avg6 || '0') || 0;

                const manualInput = tr.querySelector('.js-manual-forecast');
                const f6El = tr.querySelector('.js-f6');
                const forecastFlagEl = tr.querySelector('.js-forecast-flag');

                if (!manualInput || !f6El || !forecastFlagEl) return;

                if (forecastFlagEl.checked) {
                    f1 += parseFloat((manualInput.value || '0').replace(/,/g, '')) || 0;
                    f6 += parseFloat((f6El.textContent || '0').replace(/,/g, '')) || 0;
                }
            });

            const itemsEl = document.querySelector('.js-kpi-items');
            const avg6El = document.querySelector('.js-kpi-avg6');
            const f1El = document.querySelector('.js-kpi-f1');
            const f6El = document.querySelector('.js-kpi-f6');

            if (itemsEl) itemsEl.textContent = items.toLocaleString();
            if (avg6El) avg6El.textContent = formatNum(avg6);
            if (f1El) f1El.textContent = formatNum(f1);
            if (f6El) f6El.textContent = formatNum(f6);
        }

        document.querySelectorAll('.js-manual-forecast').forEach(el => {
            el.addEventListener('input', function() {
                this.dataset.userEdited = '1';
                recalcRow(this.closest('tr'));
                recalcKpi();
            });
        });

        document.querySelectorAll('.js-row-kfactor').forEach(el => {
            el.addEventListener('input', function() {
                const v = this.value;
                if (!(/^\d*(\.\d{0,1})?$/.test(v) || v === '')) {
                    this.value = v.slice(0, -1);
                }

                const tr = this.closest('tr');
                const manualInput = tr?.querySelector('.js-manual-forecast');

                // เปลี่ยน K = ใช้ auto ใหม่ของรอบนี้
                if (manualInput) {
                    manualInput.dataset.userEdited = '0';
                }

                recalcRow(tr);
                recalcKpi();
            });

            el.addEventListener('change', function() {
                const tr = this.closest('tr');
                const manualInput = tr?.querySelector('.js-manual-forecast');

                if (manualInput) {
                    manualInput.dataset.userEdited = '0';
                }

                recalcRow(tr);
                recalcKpi();
            });
        });

        document.querySelectorAll('.js-forecast-flag').forEach(el => {
            el.addEventListener('change', function() {
                recalcRow(this.closest('tr'));
                recalcKpi();
            });
        });

        // SO detail
        document.addEventListener('click', async function(e) {
            const btn = e.target.closest('.js-so-detail');
            if (!btn) return;

            const fg = btn.dataset.fg || '';
            const company = btn.dataset.company || 'ALL';

            const modalEl = document.getElementById('soDetailModal');
            const loadingEl = document.getElementById('soDetailLoading');
            const wrapEl = document.getElementById('soDetailWrap');
            const bodyEl = document.getElementById('soDetailBody');

            if (!modalEl || !loadingEl || !wrapEl || !bodyEl) return;

            bodyEl.innerHTML = '';
            loadingEl.classList.remove('d-none');
            wrapEl.classList.add('d-none');

            const modal = new bootstrap.Modal(modalEl);
            modal.show();

            try {
                const url =
                    `{{ route('fc.division.soDetail') }}?fg_partnumber=${encodeURIComponent(fg)}&company=${encodeURIComponent(company)}`;
                const res = await fetch(url, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                const rows = await res.json();

                bodyEl.innerHTML = rows.map(r => `
            <tr>
                <td>${r.company ?? ''}</td>
                <td>${r.fg_partnumber ?? ''}</td>
                <td>${r.description ?? ''}</td>
                <td>${r.po ?? ''}</td>
                <td>${r.ordnumber ?? ''}</td>
                <td>${r.order_date ?? ''}</td>
                <td>${r.due_date ?? ''}</td>
                <td>${r.customer_name ?? ''}</td>
                <td class="text-end">${formatNum(r.backorder_qty ?? 0)}</td>
            </tr>
        `).join('');

                loadingEl.classList.add('d-none');
                wrapEl.classList.remove('d-none');
            } catch (err) {
                loadingEl.textContent = 'โหลดข้อมูลไม่สำเร็จ';
            }
        });

        // Forecast history
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-history');
            if (!btn) return;

            const titleEl = document.getElementById('historyModalTitle');
            const subEl = document.getElementById('historyModalSub');
            const bodyEl = document.getElementById('historyModalBody');
            const modalEl = document.getElementById('historyModal');

            if (!titleEl || !subEl || !bodyEl || !modalEl) return;

            const customer = btn.dataset.customer || '';
            const fg = btn.dataset.fg || '';
            const desc = btn.dataset.description || '';

            let history = [];
            try {
                history = JSON.parse(btn.dataset.history || '[]');
            } catch (e) {
                history = [];
            }

            titleEl.textContent = `ประวัติการบันทึก | ${customer} | ${fg}`;
            subEl.textContent = desc;
            bodyEl.innerHTML = '';

            if (!history.length) {
                bodyEl.innerHTML = `
            <tr>
                <td colspan="6" class="text-center text-muted">ยังไม่มีประวัติการบันทึก</td>
            </tr>
        `;
                new bootstrap.Modal(modalEl).show();
                return;
            }

            bodyEl.innerHTML = history.map(row => {
                const month = row.base_month || row.forecast_base_month || row.ym || '-';
                const k = parseFloat(row.k_factor || 0) || 0;
                const f1 = parseFloat(row.forecast_1m || row.forecast_qty || 0) || 0;
                const f6 = parseFloat(row.forecast_6m || 0) || 0;
                const source = row.source_type || '-';
                const savedAt = row.saved_at || row.created_at || row.updated_at || '-';

                return `
            <tr>
                <td>${month}</td>
                <td class="text-end">${formatNum(k, 1)}</td>
                <td class="text-end">${formatNum(f1)}</td>
                <td class="text-end">${formatNum(f6)}</td>
                <td>${source}</td>
                <td>${savedAt}</td>
            </tr>
        `;
            }).join('');

            new bootstrap.Modal(modalEl).show();
        });

        document.querySelectorAll('table.excel tbody tr[data-row-key]').forEach(recalcRow);
        recalcKpi();
    </script>
@endsection
