{{-- resources/views/formwr/index.blade.php --}}
@extends('layouts.layout')

@section('title', 'Wirerod Incoming')
@section('page-title', 'Wirerod Incoming')

@push('styles')
    <style>
        .card-shadow {
            box-shadow: 0 6px 24px rgba(0, 0, 0, .06)
        }

        .kpi .value {
            font-size: 1.6rem;
            font-weight: 800
        }

        .kpi .label {
            font-size: .85rem;
            color: #6c757d
        }

        .table-sticky thead th {
            position: sticky;
            top: 0;
            background: #fff;
            z-index: 2
        }

        .h-560 {
            max-height: 560px;
            overflow: auto
        }

        .input-icon .input-group-text {
            background: #fff
        }

        .badge-wr {
            background: #e7f1ff;
            color: #0d6efd
        }

        .badge-plus {
            background: #e8f5ee;
            color: #198754
        }

        .form-hint {
            font-size: .8rem;
            color: #6c757d
        }

        .vendor-filter {
            position: relative
        }

        .vendor-filter input {
            padding-left: 2rem
        }

        .vendor-filter .bi {
            position: absolute;
            left: .6rem;
            top: .6rem;
            opacity: .6
        }
    </style>
@endpush

@section('content')

    {{-- HEADER --}}
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <div>
            <h3 class="mb-0 fw-bold">รายงานวัตถุดิบ - ยอดค้างรับ</h3>
            <div class="text-muted small">
                ภาพรวม PO ค้างรับ + ยอดคงเหลือวัสดุ (ตามตัวกรอง)

            </div>
        </div>
        <div class="ms-auto d-flex gap-2">
            <a href="{{ route('wr.export', request()->query()) }}" class="btn btn-outline-primary">
                <i class="bi bi-filetype-xlsx me-1"></i> Export (6 Sheets)
            </a>

        </div>
    </div>

    {{-- FILTERS --}}
    <form method="GET" action="{{ route('wr.index') }}" id="filter-form" class="mb-3">
        <div class="card card-shadow border-0">
            <div class="card-body">
                <div class="small text-muted mb-3">บริษัทและวัตถุดิบใช้กับทั้งสต็อกและยอดจอง · คู่ค้า, PO และปี ใช้กับยอดค้างรับ</div>
                <div class="row g-3">
                    {{-- Vendor (multi) + client-side filter --}}
                    <div class="col-lg-4">
                        <label for="vendor-select" class="form-label mb-1">คู่ค้า</label>

                        <select name="vendor[]" multiple class="form-select" id="vendor-select">
                            @foreach ($filters['vendors'] ?? [] as $v)
                                <option value="{{ $v }}" @selected(in_array($v, $selected['vendor'] ?? []))>{{ $v }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-hint">พิมพ์ค้นหาและเลือกได้หลายคู่ค้า</div>
                    </div>

                    <div class="col-lg-4">
                        <label for="item-ts" class="form-label mb-1">วัตถุดิบ (รหัส / ชื่อ)</label>
                        <input id="item-ts" name="item" type="text" class="form-control"
                            placeholder="พิมพ์อย่างน้อย 2 ตัวอักษร (R…)" value="{{ $selected['item'] ?? '' }}"
                            autocomplete="off">

                    </div>

                    <div class="col-lg-4">
                        <label for="po-ts" class="form-label mb-1">เลขที่ใบสั่งซื้อ (PO)</label>
                        <input id="po-ts" name="po" type="text" class="form-control"
                            placeholder="พิมพ์อย่างน้อย 2 ตัวอักษร เช่น POR…" value="{{ $selected['po'] ?? '' }}"
                            autocomplete="off">

                    </div>

                    <div class="col-md-4">
                        <label for="year-select" class="form-label mb-1">ปีที่กำหนดรับ PO</label>
                        <select name="year[]" id="year-select" class="form-select" multiple>
                            @foreach ($filters['years'] ?? [] as $y)
                                <option value="{{ $y }}" @if (in_array($y, $selected['year'] ?? [now()->year])) selected @endif>
                                    {{ $y }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-5">
                        <div class="form-label mb-2">บริษัท</div>
                        <div class="d-flex flex-wrap gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="company[]" value="MENAM PLUS"
                                    id="cPlus" @checked(in_array('MENAM PLUS', $selected['company'] ?? []))>
                                <label class="form-check-label" for="cPlus">MENAM PLUS</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="company[]" value="MENAM WIRE"
                                    id="cWire" @checked(in_array('MENAM WIRE', $selected['company'] ?? []))>
                                <label class="form-check-label" for="cWire">MENAM WIRE</label>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 d-flex align-items-end justify-content-md-end gap-2">
                        <a class="btn btn-light border text-nowrap" href="{{ route('wr.index') }}">ล้างตัวกรอง</a>
                        <button type="submit" class="btn btn-primary px-4 text-nowrap">ค้นหา</button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <div class="row g-3 mb-3 kpi">
        <div class="col-md-3">
            <div class="card card-shadow border-0">
                <div class="card-body">
                    <div class="label">บรรทัด PO (ยังไม่ถึงกำหนด)</div>
                    <div class="value">{{ number_format($kpi['po_lines_not_due'] ?? 0) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-shadow border-0">
                <div class="card-body">
                    <div class="label">ค้างรับ (ยังไม่ถึงกำหนด) (KG.)</div>
                    <div class="value">{{ number_format($kpi['open_not_due'] ?? 0, 2) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-shadow border-0">
                <div class="card-body">
                    <div class="label">ค้างรับเกินกำหนด (KG.)</div>
                    <div class="value">{{ number_format($kpi['open_overdue'] ?? 0, 2) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-shadow border-0">
                <div class="card-body">
                    <div class="label">มูลค่าค้างรับ (ยังไม่ถึงกำหนด) (THB)</div>
                    <div class="value">{{ number_format($kpi['open_value_thb'] ?? 0, 2) }}</div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex align-items-center justify-content-between">
                <div class="fw-semibold">
                    FG Stock (Wire/Plus)
                </div>

                <span class="badge text-bg-{{ $fgItemsAll->count() ?? 0 ? 'success' : 'secondary' }}">
                    {{ $fgItemsAll->count() ?? 0 }} rows
                </span>
            </div>

            <div class="card-body">
                @if (($fgItemsAll->count() ?? 0) > 0)
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Transdate</th>
                                    <th>Site</th>
                                    <th>WO</th>
                                    <th>Customer</th>
                                    <th>Part</th>
                                    <th>Description</th>
                                    <th class="text-end">Receive</th>
                                    <th class="text-end">Issue</th>
                                    <th class="text-end">Balance</th>
                                    <th>Unit</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($fgItemsAll as $r)
                                    <tr>
                                        <td>{{ $r->transdate_max ? \Carbon\Carbon::parse($r->transdate_max)->format('Y-m-d') : '' }}
                                        </td>
                                        <td>{{ $r->site }}</td>
                                        <td class="fw-semibold">{{ $r->workordernumber }}</td>
                                        <td>{{ $r->customer }}</td>
                                        <td class="fw-semibold">{{ $r->rm_partnumber }}</td>
                                        <td class="text-truncate" style="max-width: 360px;">{{ $r->part_desc }}</td>
                                        <td class="text-end">{{ number_format($r->receive_qty, 2) }}</td>
                                        <td class="text-end">{{ number_format($r->issue_qty, 2) }}</td>
                                        <td class="text-end">{{ number_format($r->balance_qty, 2) }}</td>
                                        <td>{{ $r->unit_name }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="text-muted">
                        <div class="fw-semibold mb-1">No FG stock data</div>
                        <div style="font-size: 0.9rem;">
                            ลองระบุ <span class="badge text-bg-light border">SKU</span> (หรือ Item) แล้วค้นหาใหม่
                            หรือเลือก Company ให้ตรง (Wire/Plus)
                        </div>
                    </div>
                @endif
            </div>
        </div>


        {{-- ถ้าต้องการการ์ดสต็อก --}}
        {{-- 
  <div class="col-md-3">
    <div class="card card-shadow border-0">
      <div class="card-body">
        <div class="label">คงเหลือในสต็อก (KG.)</div>
        <div class="value">{{ number_format($kpi['balance_kg'] ?? 0, 2) }}</div>
      </div>
    </div>
  </div>
  --}}
    </div>

    {{-- BODY: 2 COLUMNS --}}
    <div class="row g-3">
        {{-- Left: Monthly summary --}}
        <div class="col-lg-6">
            <div class="card card-shadow border-0">
                <div class="card-header bg-white">
                    <div class="fw-semibold">PO ค้างรับตาม Item (เดือน)</div>
                    <div class="text-muted small">Item ที่ยังไม่ถึงวันที่รับ </div>
                </div>
                <div class="card-body p-0">
                    <div class="h-560">
                        <table class="table table-sm mb-0 table-sticky">
                            <thead class="table-light">
                                <tr>
                                    <th style="min-width:18rem">item</th>
                                    @foreach ($monthOrder as $m)
                                        <th class="text-end">{{ $monthLabels[$m] ?? $m }}</th>
                                    @endforeach
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>

                            <tbody>
                                @forelse($poByItemMonth ?? [] as $row)
                                    <tr>
                                        <td>
                                            <div class="fw-semibold text-uppercase"
                                                title="{{ $row['description'] ?? '' }}">
                                                {{ $row['item'] }}
                                            </div>
                                            @if (!empty($row['description']))
                                                <div class="small text-muted text-truncate">{{ $row['description'] }}
                                                </div>
                                            @endif
                                        </td>

                                        @foreach ($monthOrder as $m)
                                            <td class="text-end">
                                                {{ number_format($row['by_month'][$m] ?? 0, 2) }}
                                            </td>
                                        @endforeach

                                        <td class="text-end fw-semibold">
                                            {{ number_format($row['total'] ?? 0, 2) }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ 2 + count($monthOrder) }}" class="text-center text-muted py-4">—
                                            No data —</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th>รวม</th>
                                    @foreach ($monthOrder as $m)
                                        <th class="text-end">{{ number_format($sumMonth['by_month'][$m] ?? 0, 2) }}</th>
                                    @endforeach
                                    <th class="text-end">{{ number_format($sumMonth['total'] ?? 0, 2) }}</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Right: Balance vs Open --}}
        <div class="col-lg-6">
            <div class="card card-shadow border-0">
                <div class="card-header bg-white">
                    <div class="fw-semibold">ยอดคงเหลือ / จองวัตถุดิบ / สุทธิ (KG.)</div>
                    <div class="text-muted small">ยอดล่าสุด {{ $stockReport['retrieved_at'] }} (เวลาไทย) · สต็อกและยอดจองกรองตามบริษัท/Item<br>ปี, คู่ค้า และ PO ใช้กรองค้างรับเท่านั้น · สุทธิ = คงเหลือ − จองที่ยังไม่เบิก · คลิก Item เพื่อดู Heat</div>
                </div>
                <div class="card-body p-0">
                    <div class="h-560 table-responsive">
                        <table class="table table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>item</th>
                                    <th>บริษัท</th>
                                    <th class="text-end text-nowrap">ยอดคงเหลือ</th>
                                    <th class="text-end text-nowrap">จองคงเหลือ</th>
                                    <th class="text-end text-nowrap">สุทธิ</th>
                                    <th class="text-end">ค้างรับ (KG.)</th>
                                    <th class="text-center" style="width: 120px;">PO ที่ค้าง</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($balanceWithOpen as $r)
                                    @php
                                        $poList = $r['overdue_po'] ?? [];
                                        $countPo = count($poList);
                                        $collapse = 'poRow' . $loop->index;
                                        $rowIndex = $loop->index;
                                    @endphp

                                    {{-- แถว summary --}}
                                    <tr>
                                        <td class="text-nowrap">
                                            @if ($r['stock_details'])
                                                <a class="text-primary text-decoration-none" href="#wr-stock-{{ $rowIndex }}" data-bs-toggle="modal" aria-label="ดู Heat และ MFG ของ {{ $r['item'] }}">{{ $r['item'] }}</a>
                                            @else
                                                {{ $r['item'] }}
                                            @endif
                                        </td>
                                        <td>{{ $r['company'] }}</td>
                                        <td class="text-end">{{ number_format($r['balance'], 2) }}</td>
                                        <td class="text-end">{{ number_format($r['reserved'], 2) }}</td>
                                        <td class="text-end {{ $r['net'] < 0 ? 'text-danger fw-bold' : '' }}">{{ number_format($r['net'], 2) }}</td>
                                        <td class="text-end">{{ number_format($r['open'], 2) }}</td>
                                        <td class="text-center">
                                            @if ($countPo > 0)
                                                <button class="btn btn-sm btn-outline-primary" type="button"
                                                    data-bs-toggle="collapse" data-bs-target="#{{ $collapse }}"
                                                    aria-expanded="false" aria-controls="{{ $collapse }}">
                                                    PO ที่ค้าง ({{ $countPo }})
                                                </button>
                                            @else
                                                <span class="text-muted">-</span>
                                            @endif
                                        </td>
                                    </tr>

                                    {{-- แถวรายละเอียด PO (ขยาย/ย่อ) --}}
                                    @if ($countPo > 0)
                                        <tr class="collapse bg-light" id="{{ $collapse }}">
                                            <td colspan="7">
                                                <ul class="mb-0 small">
                                                    @foreach ($poList as $po)
                                                        <li class="mb-1">
                                                            <span class="fw-semibold">{{ $po['po_no'] }}</span>
                                                            — {{ \Carbon\Carbon::parse($po['req_date'])->format('d/M') }},
                                                            {{ number_format($po['open'], 2) }} KG,
                                                            {{ $po['vendor'] }}
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                            <tfoot class="table-light fw-semibold">
                                <tr>
                                    <td colspan="2">รวม</td>
                                    <td class="text-end">{{ number_format($totals['balance'], 2) }}</td>
                                    <td class="text-end">{{ number_format($totals['reserved'], 2) }}</td>
                                    <td class="text-end {{ $totals['net'] < 0 ? 'text-danger' : '' }}">{{ number_format($totals['net'], 2) }}</td>
                                    <td class="text-end">{{ number_format($totals['open'], 2) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>

                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Keep dialogs outside the scrollable table so they are not clipped. --}}
    @foreach ($balanceWithOpen as $r)
        @if ($r['stock_details'])
            <div class="modal fade" id="wr-stock-{{ $loop->index }}" tabindex="-1" aria-labelledby="wr-stock-title-{{ $loop->index }}" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-lg-down">
                    <div class="modal-content">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title" id="wr-stock-title-{{ $loop->index }}">Heat / MFG · {{ $r['item'] }}</h5>
                                <div class="text-muted small">จำนวนหน่วย KG. · คลิก Heat เพื่อดูใบสั่งผลิต</div>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                        </div>
                        <div class="modal-body">
                            @include('formwr.stock-details', ['r' => $r, 'rowIndex' => $loop->index])
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endforeach

    {{-- DETAILS --}}
    @if (!empty($poLines))
        <div class="card card-shadow border-0 mt-3">
            <div class="card-header bg-white">
                รายการ PO ค้างรับ (รายละเอียด)
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>เลขที่ใบสั่งซื้อ</th>
                                <th>วันที่สั่ง</th>
                                <th>กำหนดรับ</th>
                                <th>คู่ค้า</th>
                                <th>rm_partnumber</th>
                                <th class="text-end">สั่งซื้อ</th>
                                <th class="text-end">รับแล้ว</th>
                                <th class="text-end">ค้างรับ</th>
                                <th class="text-end">ราคา/หน่วย (USD)</th>
                                <th class="text-end">มูลค่าค้างรับ (USD)</th>
                                <th>บริษัท</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($poLines as $l)
                                <tr>
                                    <td class="text-nowrap">{{ $l['po_no'] }}</td>
                                    <td class="text-nowrap">
                                        {{ \Illuminate\Support\Carbon::parse($l['po_date'])->format('Y-m-d') }}</td>
                                    <td class="text-nowrap">
                                        {{ \Illuminate\Support\Carbon::parse($l['req_date'])->format('Y-m-d') }}</td>
                                    <td class="text-truncate" style="max-width:14rem" title="{{ $l['vendor'] }}">
                                        {{ $l['vendor'] }}</td>
                                    <td class="text-uppercase">{{ $l['rm_partnumber'] }}</td>
                                    <td class="text-end">{{ number_format($l['qty'] ?? 0, 2) }}</td>
                                    <td class="text-end">{{ number_format($l['received'] ?? 0, 2) }}</td>
                                    <td class="text-end fw-semibold">{{ number_format($l['open'] ?? 0, 2) }}</td>
                                    <td class="text-end">{{ number_format($l['price_thb'] ?? 0, 2) }}</td>
                                    <td class="text-end">
                                        {{ number_format($l['open_value'] ?? 0, 2) }}</td>
                                    <td class="text-nowrap">{{ $l['company'] ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="5" class="text-end">รวม</th>
                                <th class="text-end">{{ number_format($rawTotals['qty'] ?? 0, 2) }}</th>
                                <th class="text-end">{{ number_format($rawTotals['received'] ?? 0, 2) }}</th>
                                <th class="text-end">{{ number_format($rawTotals['open'] ?? 0, 2) }}</th>
                                <th></th>
                                <th class="text-end">{{ number_format($rawTotals['open_value_thb'] ?? 0, 2) }}</th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    @endif

@endsection

@push('scripts')
    <script>
        document.addEventListener('click', function (event) {
            const link = event.target.closest('a[href^="#wr-"]');
            if (!link) return;
            const detail = document.getElementById(link.getAttribute('href').slice(1));
            if (detail && detail.tagName === 'DETAILS') detail.open = true;
        });
        // -------- simple debounce ----------
        function debounce(fn, wait = 300) {
            let t;
            return function(...args) {
                clearTimeout(t);
                t = setTimeout(() => fn.apply(this, args), wait);
            };
        }

        new TomSelect('#vendor-select', {
            plugins: ['remove_button'], maxItems: null, create: false,
            placeholder: 'ทุกคู่ค้า — พิมพ์เพื่อค้นหา', closeAfterSelect: true,
            hideSelected: true
        });
        new TomSelect('#year-select', {
            plugins: ['remove_button'], maxItems: null, create: false,
            placeholder: 'เลือกปีที่กำหนดรับ', closeAfterSelect: true
        });
        document.getElementById('filter-form').addEventListener('submit', function(event) {
            if (!document.querySelector('#filter-form input[name="company[]"]:checked')) {
                event.preventDefault();
                document.getElementById('cPlus').setCustomValidity('เลือกอย่างน้อยหนึ่งบริษัท');
                document.getElementById('cPlus').reportValidity();
            }
        });
        document.querySelectorAll('#filter-form input[name="company[]"]').forEach(function(input) {
            input.addEventListener('change', function() {
                document.getElementById('cPlus').setCustomValidity('');
            });
        });

        // -------- Item: เฉพาะ R% (เริ่มทำงานตั้งแต่ 1 ตัวอักษร) --------
        new TomSelect('#item-ts', {
            valueField: 'id', // ค่าที่ submit กลับ
            labelField: 'text', // ข้อความโชว์ใน dropdown
            searchField: ['text'], // ให้ TomSelect search ภายในผลลัพธ์ที่โหลดมา
            plugins: ['clear_button'],
            maxItems: 1,
            create: false,
            persist: false,
            preload: false, // โฟกัสแล้วไม่โหลดทันที จนกว่าจะพิมพ์
            placeholder: 'ค้นหารหัสหรือชื่อวัตถุดิบ (อย่างน้อย 2 ตัว)',
            load: debounce(function(query, callback) {
                // ต้องพิมพ์อย่างน้อย 1 ตัว (เพื่อให้ "R" ทำงาน)
                if ((query || '').trim().length < 2) {
                    callback();
                    return;
                }
                fetch(`{{ route('wr.autocomplete.items') }}?q=${encodeURIComponent(query)}`)
                    .then(res => res.ok ? res.json() : [])
                    .then(json => callback(json || []))
                    .catch(() => callback());
            })
        });

        // -------- PO: เฉพาะใบที่มีรายการ R (อนุญาต 1 ตัวอักษรได้) --------
        new TomSelect('#po-ts', {
            valueField: 'id',
            labelField: 'text',
            searchField: ['text'],
            plugins: ['clear_button'],
            maxItems: 1,
            create: false,
            persist: false,
            preload: false,
            placeholder: 'ค้นหาเลข PO (อย่างน้อย 2 ตัว)',
            load: debounce(function(query, callback) {
                if ((query || '').trim().length < 2) {
                    callback();
                    return;
                }
                fetch(`{{ route('wr.autocomplete.po') }}?q=${encodeURIComponent(query)}`)
                    .then(res => res.ok ? res.json() : [])
                    .then(json => callback(json || []))
                    .catch(() => callback());
            })
        });
    </script>
@endpush
