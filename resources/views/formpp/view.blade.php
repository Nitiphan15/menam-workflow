@extends('layouts.layout')

@section('title', 'ฟอร์มวางแผนการผลิต')
@section('page-title', 'ฟอร์มวางแผนการผลิต')

@section('content')
    @php
        use Illuminate\Support\Str;
        use Illuminate\Support\Carbon;

        $nf = fn($v) => number_format((float) $v, 2);

        // โหมด Planner?
        $plannerMode = $plannerMode ?? false;

        // กัน null
        $ppData = $ppData ?? null;
        $form = $form ?? ($ppData->wfForm ?? null);
        $currentStep = (int) data_get($form, 'current_step_no', 1);

        // กัน null ให้ตัวแปรหน้า index เดิม
        $sku = $sku ?? '';
        $result = $result ?? null;
        $customers = $customers ?? collect();

        // --- แปลงค่าที่เลือกให้เป็น array เสมอ: รองรับ array / string เดี่ยว / CSV ---
        $selectedRaw =
            $selectedCustomers ??
            ($selectedCustomer ?? request('customers', request('customer', data_get($ppData ?? null, 'customer', ''))));

        if (is_array($selectedRaw)) {
            $selectedCustomers = array_values(array_unique(array_map('trim', $selectedRaw)));
        } else {
            $selectedCustomers =
                $selectedRaw !== '' ? preg_split('/\s*,\s*/u', (string) $selectedRaw, -1, PREG_SPLIT_NO_EMPTY) : [];
            $selectedCustomers = array_values(array_unique(array_map('trim', $selectedCustomers)));
        }

        // รายชื่อลูกค้าที่ใช้แสดงผล
        $customers = array_values(array_unique(array_filter((array) ($customers ?? []))));
        //dd($customers);
    @endphp

    <div class="container py-3">

        {{-- <a href="#" class="btn btn-outline-secondary btn-sm rounded-pill shadow-sm mb-3"
            data-fallback="{{ route('pp.index') }}" onclick="return backToPreviousPath(this)">← กลับ</a> --}}


        @if ($ppData)
            <div class="alert alert-info">
                <div><strong>Document Number:</strong> {{ data_get($ppData, 'docu_no', '-') }}</div>
                <div><strong>Requested By:</strong> {{ data_get($form, 'requester.name', '-') }}</div>
                <div><strong>Requested At:</strong> {{ optional(data_get($form, 'request_dt'))->format('d/m/Y H:i') }}</div>
                <div>
                    @php $step = (int) data_get($form, 'current_step_no'); @endphp
                    <strong>สถานะ:</strong>
                    <span class="badge text-bg-warning">
                        Step {{ $step ?: '-' }}:
                        @switch($step)
                            @case(999)
                                ปิดเอกสารแล้ว
                            @break

                            @case(998)
                                ยกเลิกเอกสารแล้ว
                            @break

                            @case(2)
                                รอ Planner อนุมัติ
                            @break

                            @default
                                —
                        @endswitch
                    </span>
                </div>
            </div>
        @endif


        @if ($ppData)
            {{-- Summary Badges --}}
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small">รหัสสินค้า</div>
                            <div class="fs-5 fw-semibold">{{ $ppData->part_no ?? '-' }}</div>
                            <div class="text">{{ $ppData->name ?? '-' }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small">ประเภท / หมวด / กลุ่ม</div>
                            <div class="fw-semibold">{{ $ppData->type ?? '-' }}</div>
                            <div class="text-truncate">{{ $ppData->category ?? '-' }}</div>
                            <div class="text-truncate">{{ $ppData->group ?? '-' }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small">ต้องผลิตเพิ่ม (Sales)</div>
                            <div class="fs-4 fw-bold text-primary">
                                {{ number_format($ppData->sales_prod ?? 0, 2) }}
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small">ต้องผลิตเพิ่ม (Planner)</div>
                            <div class="fs-4 fw-bold text-primary">
                                {{ number_format($ppData->planner_prod ?? 0, 2) }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>


            {{--  <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label for="customer" class="form-label fw-semibold mb-1">ลูกค้า</label>
                    <div class="position-relative">
                        <select id="customer" name="customer"
                            class="form-select form-select-lg rounded-pill shadow-sm pe-5" form="searchForm"
                            onchange="this.form.submit()" @disabled(true)>
                            <option value={{ $ppData->customer }}>{{ $ppData->customer }}</option>
                        </select>

                    </div>
                    @if ($selectedCustomer)
                        <div class="form-text mt-1">
                            กำลังกรองลูกค้า: <strong>{{ $selectedCustomer }}</strong>

                        </div>
                    @endif
                </div>
            </div> --}}


            <div class="row g-3 mb-3">
                <div class="col-md-7">
                    <label for="customers" class="form-label fw-semibold mb-1">ลูกค้า</label>

                    <div class="d-flex gap-2 align-items-center">
                        <select id="customers" name="customers[]" multiple class="form-select form-select-lg"
                            form="searchForm" data-placeholder="— ทุกลูกค้า —" readonly disabled>
                            @foreach ($customers as $cus)
                                <option value="{{ $cus }}" @selected(in_array($cus, $selectedCustomers, true))>
                                    {{ $cus }}
                                </option>
                            @endforeach

                            {{-- กรณีมีค่าที่เลือกไว้ แต่ไม่มีในรายการ customers -> ใส่เพิ่มให้เห็นใน UI --}}
                            @php
                                $missing = array_diff($selectedCustomers, $customers);
                            @endphp
                            @foreach ($missing as $cus)
                                <option value="{{ $cus }}" selected>{{ $cus }}</option>
                            @endforeach
                        </select>

                    </div>

                    @php
                        $selCount = count($selectedCustomers);
                        $preview = $selCount
                            ? implode(', ', array_slice($selectedCustomers, 0, 3)) .
                                ($selCount > 3 ? ' และอีก ' . ($selCount - 3) . ' รายการ' : '')
                            : 'ไม่เลือก = ทุกลูกค้า';
                    @endphp
                    <div class="form-text mt-1" id="selected-summary">
                        {{ $selCount ? "เลือกแล้ว {$selCount} รายการ: {$preview}" : $preview }}
                    </div>
                </div>
            </div>


            {{-- Table like Excel --}}
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light fw-semibold">สรุปยอดรายการ</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped mb-0 align-middle">
                            <thead class="table-warning">
                                <tr class="text-center">
                                    <th style="width:60px;">ลำดับ</th>
                                    <th>รายการ</th>
                                    <th style="width:240px;">QTY (KG.) [Sales]</th>
                                    <th style="width:240px;">QTY (KG.) [Planner]</th>
                                    <th style="width:220px;">หมายเหตุ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="text-center">1</td>
                                    <td>จำนวน FG (สินค้าใน Stock พร้อมส่ง)</td>
                                    <td class="text-center fw-semibold">
                                        {{ number_format($ppData->sales_fg ?? 0, 2) }}
                                    </td>
                                    <td class="text-center fw-semibold">
                                        {{ number_format($ppData->planner_fg ?? 0, 2) }}
                                    </td>
                                    <td class="text-muted">จาก CPA 7 (Wire + Plus)</td>
                                </tr>
                                <tr>
                                    <td class="text-center">2</td>
                                    <td>จำนวน WIP (สินค้าที่กำลังผลิต)</td>
                                    <td class="text-center fw-semibold">
                                        {{ number_format($ppData->sales_wip ?? 0, 2) }}
                                    </td>
                                    <td class="text-center fw-semibold">
                                        {{ number_format($ppData->planner_wip ?? 0, 2) }}
                                    </td>
                                    <td class="text-muted">จาก CPA 30 (Wire + Plus)</td>
                                </tr>
                                <tr>
                                    <td class="text-center">3</td>
                                    <td>จำนวนใบคำสั่งที่เปิดแล้ว/จองแผนผลิต</td>
                                    <td class="text-center fw-semibold">
                                        {{ number_format($ppData->sales_mfg ?? 0, 2) }}
                                    </td>
                                    <td class="text-center fw-semibold">
                                        {{ number_format($ppData->planner_mfg ?? 0, 2) }}
                                    </td>
                                    <td class="text-muted">จาก ManuCost</td>
                                </tr>
                                <tr>
                                    <td class="text-center">4</td>
                                    <td>จำนวนยอดค้างส่งทุก Sale Order</td>
                                    <td class="text-center fw-semibold">
                                        {{ number_format($ppData->sales_order ?? 0, 2) }}
                                    </td>
                                    <td class="text-center fw-semibold">
                                        {{ number_format($ppData->planner_order ?? 0, 2) }}
                                    </td>
                                    <td class="text-muted">จาก CPA 24 (Wire)</td>
                                </tr>
                                <tr class="table-primary">
                                    <td class="text-center">5</td>
                                    <td class="fw-semibold">จำนวนที่ต้องผลิตเพิ่ม</td>
                                    <td class="text-center fw-semibold">
                                        {{ number_format($ppData->sales_prod ?? 0, 2) }}
                                    </td>
                                    <td class="text-center fw-semibold">
                                        {{ number_format($ppData->planner_prod ?? 0, 2) }}
                                    </td>
                                    <td></td>
                                </tr>
                                <tr class="table-info">
                                    <td class="text-center">6</td>
                                    <td class="fw-semibold">จำนวนคงเหลือวัตถุดิบหลังจากหักยอดจองผลิตแล้ว</td>
                                    <td class="text-center fw-semibold">
                                        {{ number_format($ppData->sales_rm ?? 0, 2) }}
                                    </td>
                                    <td class="text-center fw-semibold">
                                        {{ number_format($ppData->planner_rm ?? 0, 2) }}
                                    </td>
                                    <td class="text-muted">1 RM ผลิตมากกว่า 1 FG
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3">
                        <button type="button" id="exportBtn" class="btn btn-success">
                            <i class="bi bi-file-earmark-arrow-down me-1"></i> Export CSV
                        </button>
                    </div>
                </div>
            @elseif($sku !== '')
                <div class="alert alert-warning mt-3">
                    ไม่พบข้อมูลสำหรับรหัสสินค้า: <b>{{ $sku }}</b>
                </div>
        @endif
        <div class="container px-4">
            {{-- รูป Step --}}
            <div class="row g-3 mt-4">
                <div class="col-md-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header fw-semibold">Workflow Diagram</div>
                        <div class="card-body text-center">
                            <img src="{{ asset("formpp/{$currentStep}.png") }}"
                                class="img-fluid border rounded shadow-sm p-2" style="max-width: auto; height:500px;">
                        </div>
                    </div>
                </div>

                {{-- History --}}
                <div class="col-md-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header fw-semibold">Workflow History</div>
                        <div class="card-body ps-4 ps-md-5">
                            @if ($history->count())
                                <ul class="wf-timeline list-unstyled">
                                    @foreach ($history as $h)
                                        @php
                                            $action = strtolower($h->action_type ?? '');
                                            $dotClass = match ($action) {
                                                'submit' => 'is-submit',
                                                'approve' => 'is-approve',
                                                'reject' => 'is-reject',
                                                default => '',
                                            };
                                        @endphp
                                        <li class="wf-item">
                                            <span class="wf-rail"></span>
                                            <span class="wf-dot {{ $dotClass }}">{{ $h->step_no }}</span>

                                            <div class="wf-title">{{ ucfirst($action) }}</div>
                                            <div class="wf-meta">
                                                {{ \Carbon\Carbon::parse($h->created_at)->format('d/m/Y H:i') }}
                                                · Step {{ $h->step_no }}
                                                @if ($h->actor_name)
                                                    · by {{ $h->actor_name }}
                                                @endif
                                            </div>

                                            @if ($h->comment)
                                                <div class="wf-comment text-dark">{{ $h->comment }}</div>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <div class="text-muted">ไม่มีประวัติการทำรายการ</div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endsection

    @push('styles')
        <style>
            .table thead th {
                position: sticky;
                top: 0;
                z-index: 1;
            }

            .table td,
            .table th {
                white-space: normal !important;
                /* อนุญาตให้ตัดบรรทัด */
                word-wrap: break-word;
                /* ตัดคำถ้าจำเป็น */
                word-break: break-word;
                /* บังคับตัดคำที่ยาวเกิน */
            }

            .form-select.rounded-pill {
                padding-left: 1.25rem;
            }

            .form-select.shadow-sm {
                box-shadow: 0 .25rem .75rem rgba(0, 0, 0, .06);
            }

            /* Timeline ปรับ balance ระหว่าง dot / rail / text */
            .wf-timeline {
                position: relative;
                padding-left: 65px;
                /* เว้นให้ข้อความไม่ชนวงกลม */
                margin: 0;
            }

            .wf-item {
                position: relative;
                padding: 16px 0 18px;
                /* เพิ่ม padding บนล่างให้หายแน่น */
                border-bottom: 1px dashed var(--bs-border-color);
            }

            .wf-item:last-child {
                border-bottom: 0;
                padding-bottom: 0;
            }

            /* ขนาดจุด */
            :root {
                --wf-dot-size: 22px;
                --wf-dot-left: 28px;
                /* ขยับจุดไปทางซ้าย */
            }

            /* rail ผ่านกลางจุด */
            .wf-rail {
                position: absolute;
                left: -64px;
                top: 0;
                bottom: 0;
                width: 2px;
                background: var(--bs-border-color);
            }

            .wf-item:last-child .wf-rail {
                background: linear-gradient(180deg, var(--bs-border-color), transparent);
            }

            /* วงกลม */
            .wf-dot {
                position: absolute;
                left: -73px;
                top: 2px;
                width: var(--wf-dot-size);
                height: var(--wf-dot-size);
                border-radius: 50%;
                background: var(--bs-body-bg);
                border: 2px solid var(--bs-border-color);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 12px;
                font-weight: 700;
                line-height: 1;
            }

            /* ข้อความ */
            .wf-title {
                font-weight: 600;
            }

            .wf-meta {
                font-size: .85rem;
                color: var(--bs-secondary-color);
            }

            .wf-comment {
                font-size: .9rem;
                margin-top: .25rem;
            }

            /* สี dot */
            .wf-dot.is-submit {
                border-color: #0d6efd;
                color: #0d6efd;
            }

            .wf-dot.is-approve {
                border-color: #198754;
                color: #198754;
            }

            .wf-dot.is-reject {
                border-color: #dc3545;
                color: #dc3545;
            }

            /* ทำให้ดูเรียบร้อยขึ้น */
            .ts-wrapper.form-select.form-select-lg .ts-control {
                border-radius: .75rem;
                /* rounded-lg แทน pill */
                min-height: calc(1.6em + 1rem + 2px);
                padding: .5rem .75rem;
            }

            .ts-wrapper .item {
                /* ชิปที่ถูกเลือก */
                padding: .2rem .5rem;
                border-radius: 9999px;
                background: #f1f5f9;
                border: 1px solid #e2e8f0;
                margin-right: .25rem;
            }

            .ts-dropdown {
                max-height: 320px;
            }
        </style>
    @endpush

    @push('scripts')
        <script>
            // ---------- SKU auto-complete: ห่อด้วย if กัน DOM ไม่มี ----------
            {
                const input = document.getElementById('sku');
                if (input) {
                    const hid = document.getElementById('sku_id');
                    const listEl = document.getElementById('sku-list');
                    const url = input.dataset.url;

                    let timer = null,
                        idx = -1;
                    const debounce = (fn, ms = 200) => (...a) => {
                        clearTimeout(timer);
                        timer = setTimeout(() => fn(...a), ms);
                    };

                    const render = (rows) => {
                        listEl.innerHTML = '';
                        idx = -1;
                        if (!rows.length) {
                            listEl.style.display = 'none';
                            return;
                        }
                        rows.forEach(r => {
                            const a = document.createElement('a');
                            a.href = 'javascript:void(0)';
                            a.className = 'list-group-item list-group-item-action';
                            a.dataset.id = r.id;
                            a.dataset.value = r.value;
                            a.dataset.label = r.label;
                            a.textContent = r.label;
                            a.addEventListener('mousedown', () => select(r));
                            listEl.appendChild(a);
                        });
                        listEl.style.display = 'block';
                    };

                    const select = (row) => {
                        input.value = row.value;
                        if (hid) hid.value = row.id || '';
                        listEl.style.display = 'none';
                    };

                    const search = async (q) => {
                        const res = await fetch(`${url}?q=${encodeURIComponent(q)}`, {
                            headers: {
                                'Accept': 'application/json'
                            }
                        });
                        const rows = await res.json();
                        render(rows);
                    };

                    input.addEventListener('input', debounce(() => {
                        if (hid) hid.value = '';
                        const q = input.value.trim();
                        if (q.length < 1) {
                            listEl.style.display = 'none';
                            return;
                        }
                        search(q);
                    }, 200));

                    // Enter เพื่อค้นหา (มีฟิลด์เท่านั้นค่อย bind)
                    input.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            document.getElementById('searchForm')?.submit();
                        }
                    });
                }
            }

            // ป้องกันกดซ้ำ & แสดง loading ระหว่างค้นหา
            document.getElementById('searchForm')?.addEventListener('submit', function() {
                const btn = document.getElementById('btnSearch');
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>กำลังค้นหา...';
                }
            });

            // ---------- TomSelect ลูกค้า ----------
            {
                const selectEl = document.getElementById('customers');
                const summary = document.getElementById('selected-summary');

                if (selectEl && window.TomSelect) {
                    const ts = new TomSelect(selectEl, {
                        maxItems: null,
                        plugins: ['remove_button', 'checkbox_options', 'clear_button'],
                        placeholder: selectEl.dataset.placeholder,
                        hideSelected: true,
                        closeAfterSelect: false,
                        onChange: () => document.getElementById('searchForm')?.submit(),
                    });

                    // เลือกทั้งหมด / ล้าง
                    document.getElementById('btn-select-all')?.addEventListener('click', () => {
                        ts.setValue([...ts.options.keys()]); // ทุกค่า
                        document.getElementById('searchForm')?.submit();
                    });
                    document.getElementById('btn-clear')?.addEventListener('click', () => {
                        ts.clear();
                        document.getElementById('searchForm')?.submit();
                    });

                    // แสดงสรุปแบบโชว์ 3 รายการแรก + จำนวนที่เหลือ
                    const renderSummary = () => {
                        const values = ts.getValue();
                        if (!summary) return;
                        if (!values.length) {
                            summary.textContent = 'ไม่เลือก = ทุกลูกค้า';
                            return;
                        }
                        const labels = values.map(v => ts.options[v]?.text ?? v);
                        const head = labels.slice(0, 3).join(', ');
                        const tail = labels.length > 3 ? ` และอีก ${labels.length - 3} รายการ` : '';
                        summary.textContent = `เลือกแล้ว ${labels.length} รายการ: ${head}${tail}`;
                    };
                    ts.on('change', renderSummary);
                    renderSummary();
                }
            }

            // ---------- Export CSV: ให้ตรงกับตาราง (มีทั้ง Sales/Planner) ----------
            {
                const exportBtn = document.getElementById('exportBtn');
                if (exportBtn) {
                    exportBtn.addEventListener('click', () => {
                        try {
                            const rows = [
                                ['ลำดับ', 'รายการ', 'QTY (KG.) [Sales]', 'QTY (KG.) [Planner]', 'หมายเหตุ'],
                                ['1', 'จำนวน FG (สินค้าใน Stock พร้อมส่ง)',
                                    '{{ number_format($ppData->sales_fg ?? 0, 2) }}',
                                    '{{ number_format($ppData->planner_fg ?? 0, 2) }}', 'CPA 7 (Wire + Plus)'
                                ],
                                ['2', 'จำนวน WIP (สินค้าที่กำลังผลิต)',
                                    '{{ number_format($ppData->sales_wip ?? 0, 2) }}',
                                    '{{ number_format($ppData->planner_wip ?? 0, 2) }}', 'CPA 30 (Wire + Plus)'
                                ],
                                ['3', 'จำนวนใบคำสั่งที่เปิดแล้ว/จองแผนผลิต',
                                    '{{ number_format($ppData->sales_mfg ?? 0, 2) }}',
                                    '{{ number_format($ppData->planner_mfg ?? 0, 2) }}', 'ManuCost'
                                ],
                                ['4', 'จำนวนยอดค้างส่งทุก Sale Order',
                                    '{{ number_format($ppData->sales_order ?? 0, 2) }}',
                                    '{{ number_format($ppData->planner_order ?? 0, 2) }}', 'CPA 24 (Wire)'
                                ],
                                ['5', 'จำนวนที่ต้องผลิตเพิ่ม', '{{ number_format($ppData->sales_prod ?? 0, 2) }}',
                                    '{{ number_format($ppData->planner_prod ?? 0, 2) }}',
                                    '= ข้อ 4 − (ข้อ 1 + ข้อ 2 + ข้อ 3)'
                                ],
                            ];
                            const csv = rows.map(r => r.map(x => `"${String(x).replace(/"/g,'""')}"`).join(',')).join(
                                '\r\n');
                            const blob = new Blob(["\ufeff" + csv], {
                                type: 'text/csv;charset=utf-8;'
                            });
                            const url = URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.href = url;
                            a.download = `PP-Summary-{{ $ppData->part_no ?? ($result['sku'] ?? 'unknown') }}.csv`;
                            document.body.appendChild(a);
                            a.click();
                            document.body.removeChild(a);
                            URL.revokeObjectURL(url);
                        } catch (err) {
                            if (window.Swal) Swal.fire({
                                icon: 'error',
                                title: 'เกิดข้อผิดพลาด',
                                text: err.message
                            });
                            else alert('เกิดข้อผิดพลาด: ' + err.message);
                        }
                    });
                }
            }

            (function() {
                try {
                    const curr = location.pathname + location.search + location.hash;
                    const prev = sessionStorage.getItem('currPath');
                    sessionStorage.setItem('prevPath', prev || '');
                    sessionStorage.setItem('currPath', curr);
                } catch (e) {}
            })();

            /* กลับไป "path ก่อนหน้า" อย่างฉลาด */
            function backToPreviousPath(el) {
                const fallback = el?.dataset?.fallback || '/';
                const here = location.pathname + location.search + location.hash;

                // 1) ถ้ามี referrer และเป็นโดเมนเดียวกัน -> กลับไป path นั้น
                try {
                    if (document.referrer) {
                        const ref = new URL(document.referrer);
                        if (ref.origin === location.origin) {
                            const path = ref.pathname + ref.search + ref.hash;
                            if (path && path !== here) {
                                location.replace(path); // ไม่สร้าง entry ใหม่ใน history
                                return false;
                            }
                        }
                    }
                } catch (e) {}

                // 2) ใช้ prevPath จาก sessionStorage (เผื่อ referrer ใช้ไม่ได้)
                try {
                    const prevPath = sessionStorage.getItem('prevPath');
                    if (prevPath && prevPath !== here) {
                        location.replace(prevPath);
                        return false;
                    }
                } catch (e) {}

                // 3) ไม่เจออะไรเลย -> ไป fallback
                location.href = fallback;
                return false;
            }
        </script>
    @endpush
