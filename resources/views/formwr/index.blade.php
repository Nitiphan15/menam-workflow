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
    <div class="d-flex align-items-center mb-2">
        <div>
            <h3 class="mb-0 fw-bold">รายงานวัตถุดิบ - ยอดค้างรับ</h3>
            <div class="text-muted small">
                ภาพรวม PO ค้างรับ + ยอดคงเหลือวัสดุ (ตามตัวกรอง)

            </div>
        </div>
        <div class="ms-auto d-flex gap-2">
            <a href="{{ route('wr.export', request()->query()) }}" class="btn btn-outline-primary">
                <i class="bi bi-filetype-xlsx me-1"></i> Export (3 Sheets)
            </a>
            <button type="button" class="btn btn-light border" onclick="location.href='{{ route('wr.index') }}'">
                <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
            </button>
        </div>
    </div>

    {{-- FILTERS --}}
    <form method="GET" action="{{ route('wr.index') }}" id="filter-form" class="mb-3">
        <div class="card card-shadow border-0">
            <div class="card-body">
                <div class="row g-3">
                    {{-- Vendor (multi) + client-side filter --}}
                    <div class="col-lg-4">
                        <label class="form-label mb-1">คู่ค้า</label>

                        <div class="vendor-filter mb-2">
                            <i class="bi bi-search"></i>
                            <input type="text" id="vendor-filter-input" class="form-control"
                                placeholder="ค้นหาในรายชื่อคู่ค้า…">
                        </div>

                        <select name="vendor[]" multiple class="form-select" id="vendor-select" size="10">
                            @foreach ($filters['vendors'] ?? [] as $v)
                                <option value="{{ $v }}" @selected(in_array($v, $selected['vendor'] ?? []))>{{ $v }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-hint">เลือกได้หลายรายการ (Ctrl/Cmd + Click)</div>
                    </div>

                    <div class="col-lg-4">
                        <label class="form-label mb-1">Item (Part / Description)</label>
                        <input id="item-ts" name="item" type="text" class="form-control"
                            placeholder="พิมพ์อย่างน้อย 2 ตัวอักษร (R…)" value="{{ $selected['item'] ?? '' }}"
                            autocomplete="off">

                    </div>

                    <div class="col-lg-4">
                        <label class="form-label mb-1">เลขที่ใบสั่งซื้อ</label>
                        <input id="po-ts" name="po" type="text" class="form-control"
                            placeholder="พิมพ์อย่างน้อย 2 ตัวอักษร เช่น POR…" value="{{ $selected['po'] ?? '' }}"
                            autocomplete="off">

                    </div>

                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1">Year</label>
                        <select name="year[]" class="form-select" multiple size="5">
                            @foreach ($filters['years'] ?? [] as $y)
                                <option value="{{ $y }}" @if (in_array($y, $selected['year'] ?? [now()->year])) selected @endif>
                                    {{ $y }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1">Company</label>
                        <div class="d-flex gap-3">
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

                    <div class="col-md-7 d-flex align-items-end justify-content-end">
                        <button class="btn btn-primary px-4"><i class="bi bi-funnel me-1"></i> Apply</button>
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
                    <div class="fw-semibold">ยอดคงเหลือ (KG.) / ค้างรับที่เหลือ (KG.)</div>
                    <div class="text-muted small">รวมตามตัวกรองด้านบน</div>
                </div>
                <div class="card-body p-0">
                    <div class="h-560">
                        <table class="table table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>item</th>
                                    <th>บริษัท</th>
                                    <th class="text-end">ยอดคงเหลือ (KG.)</th>
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
                                    @endphp

                                    {{-- แถว summary --}}
                                    <tr>
                                        <td>{{ $r['item'] }}</td>
                                        <td>{{ $r['company'] }}</td>
                                        <td class="text-end">{{ number_format($r['balance'], 2) }}</td>
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
                                            <td colspan="5">
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
        // -------- simple debounce ----------
        function debounce(fn, wait = 300) {
            let t;
            return function(...args) {
                clearTimeout(t);
                t = setTimeout(() => fn.apply(this, args), wait);
            };
        }

        // -------- vendor filter (client-side) ----------
        (function vendorFilter() {
            const input = document.getElementById('vendor-filter-input');
            const select = document.getElementById('vendor-select');
            if (!input || !select) return;

            const allOptions = Array.from(select.options);
            input.addEventListener('input', () => {
                const q = input.value.toLowerCase();
                // clear
                select.innerHTML = '';
                const filtered = !q ? allOptions :
                    allOptions.filter(o => o.text.toLowerCase().includes(q));
                filtered.forEach(o => select.appendChild(o));
            });
        })();

        // -------- Item: เฉพาะ R% (เริ่มทำงานตั้งแต่ 1 ตัวอักษร) --------
        new TomSelect('#item-ts', {
            valueField: 'id', // ค่าที่ submit กลับ
            labelField: 'text', // ข้อความโชว์ใน dropdown
            searchField: ['text'], // ให้ TomSelect search ภายในผลลัพธ์ที่โหลดมา
            maxItems: 1,
            create: false,
            persist: false,
            preload: false, // โฟกัสแล้วไม่โหลดทันที จนกว่าจะพิมพ์
            placeholder: 'พิมพ์อย่างน้อย 1 ตัวอักษร (R…)',
            load: debounce(function(query, callback) {
                // ต้องพิมพ์อย่างน้อย 1 ตัว (เพื่อให้ "R" ทำงาน)
                if ((query || '').trim().length < 1) {
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
            maxItems: 1,
            create: false,
            persist: false,
            preload: false,
            placeholder: 'พิมพ์อย่างน้อย 1–2 ตัวอักษร เช่น POR…',
            load: debounce(function(query, callback) {
                if ((query || '').trim().length < 1) {
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
