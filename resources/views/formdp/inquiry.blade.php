{{-- resources/views/formdp/inquiry.blade.php --}}
@extends('layouts.layout')
@section('page-title', 'Inquiry Data')
@section('title', 'Inquiry')

@section('content')
    @php
        $isLoggedIn = auth()->check();
        $loginUrl = route('login');
        $u = auth()->user();

        $canDp =
            $isLoggedIn &&
            (($u->is_superadmin ?? 0) == 1 || (method_exists($u, 'hasRoleCode') && $u->hasRoleCode('DP')));

        $canDpa =
            $isLoggedIn &&
            (($u->is_superadmin ?? 0) == 1 || (method_exists($u, 'hasRoleCode') && $u->hasRoleCode('DPA')));
        // เปิดฟังก์ชัน "จัดรถหลายงานพร้อมกัน" (bulk truck) ให้ผู้มีสิทธิ์ DPA
        $showInquiryBulkTruck = $canDpa;

        $canDpMail =
            $isLoggedIn &&
            (($u->is_superadmin ?? 0) == 1 ||
                (method_exists($u, 'hasRoleCode') && $u->hasRoleCode(['DPEMAIL', 'DPMAIL'])));

        $tableColspan = 18 + ($canDp ? 1 : 0) + ($showInquiryBulkTruck ? 1 : 0);

        // return URL หลัง assign truck — เก็บแค่ filter ระดับวันที่/สถานะ ไม่เก็บ SO/MFG/Customer/Ord ID
        // เพื่อให้ user ดูรายการอื่นต่อได้ทันทีโดยไม่ต้องล้าง filter เอง
        $cleanReturnParams = array_filter(
            [
                'ship_from' => request('ship_from'),
                'ship_to' => request('ship_to'),
                'status' => request('status'),
                'mode' => request('mode'),
                'order_by' => request('order_by'),
                'order_dir' => request('order_dir'),
                'searched' => request('ship_from') || request('ship_to') ? 1 : null,
            ],
            fn($v) => $v !== null && $v !== '',
        );
        $cleanReturnUrl = route('dp.inquiry', $cleanReturnParams);
        $fmtWeight = function ($kg) {
            $kg = (float) ($kg ?? 0);
            return number_format($kg, 0, '.', '');
        };
    @endphp

    <div class="container-fluid">

        <div class="dp-inquiry-filter mb-2">
            @if (session('erp_sync_output'))
                <details class="alert alert-secondary py-2 mb-2">
                    <summary class="fw-semibold">ผลลัพธ์จาก ERP sync</summary>
                    <pre class="bg-dark text-light p-3 rounded small mt-2 mb-0" style="max-height: 280px; overflow:auto;">{{ session('erp_sync_output') }}</pre>
                </details>
            @endif
            @if ($canDpa)
                @php
                    $tnShipForNav = request('ship_from') ?: (request('ship_to') ?: now()->toDateString());
                @endphp
                @include('formdp.partials.transport-nav', [
                    'tnActive' => 'inquiry',
                    'tnShipDate' => $tnShipForNav,
                    'tnSo' => request('so', ''),
                    'tnCustomer' => request('customer', ''),
                    'tnMfg' => request('mfg', ''),
                ])
            @endif

            @php
                $activeFilters = [];
                if (request('so', '') !== '') {
                    $activeFilters[] = ['key' => 'so', 'label' => 'SO', 'value' => request('so')];
                }
                if (request('mfg', '') !== '') {
                    $activeFilters[] = ['key' => 'mfg', 'label' => 'MFG', 'value' => request('mfg')];
                }
                if (request('customer', '') !== '') {
                    $activeFilters[] = ['key' => 'customer', 'label' => 'Customer', 'value' => request('customer')];
                }
                if (request('shipto', '') !== '') {
                    $activeFilters[] = ['key' => 'shipto', 'label' => 'Ship To', 'value' => request('shipto')];
                }
                if (request('ord_id', '') !== '') {
                    $activeFilters[] = ['key' => 'ord_id', 'label' => 'Ord ID', 'value' => request('ord_id')];
                }
                if (request('divsales', '') !== '') {
                    $activeFilters[] = ['key' => 'divsales', 'label' => 'Sales', 'value' => request('divsales')];
                }
                if (request('revision', '') !== '') {
                    $activeFilters[] = ['key' => 'revision', 'label' => 'Rev', 'value' => request('revision')];
                }
            @endphp

            @if (!empty($activeFilters))
                <div class="dp-active-filters mb-2 d-flex flex-wrap align-items-center gap-2">
                    <span class="small text-muted"><i class="fas fa-filter me-1"></i>ตัวกรองที่ใช้อยู่:</span>
                    @foreach ($activeFilters as $f)
                        @php
                            $removeUrl = request()->fullUrlWithQuery([$f['key'] => null]);
                        @endphp
                        <a href="{{ $removeUrl }}"
                            class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle text-decoration-none"
                            title="คลิกเพื่อลบตัวกรองนี้">
                            {{ $f['label'] }}: <span class="fw-semibold">{{ $f['value'] }}</span>
                            <i class="fas fa-times ms-1"></i>
                        </a>
                    @endforeach
                    <a href="{{ route('dp.inquiry') }}" class="btn btn-sm btn-outline-danger py-0 px-2"
                        title="ล้างทุกตัวกรอง">
                        <i class="fas fa-eraser me-1"></i>ล้างทั้งหมด
                    </a>
                </div>
            @endif

            @if (!empty($mailSentLogs) && count($mailSentLogs) > 0)
                @php
                    $mailLogTotal = count($mailSentLogs);
                    $mailLogPreviewLimit = 5;
                    $mailLogHasMore = $mailLogTotal > $mailLogPreviewLimit;
                @endphp
                <div class="alert alert-info py-2 mb-2 dp-mail-sent-summary">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-1 mb-1">
                        <div class="fw-semibold">
                            <i class="fas fa-envelope-circle-check me-1"></i>
                            ประวัติส่งเมล (แผน / ตารางจัดรถ)
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                                {{ $mailLogTotal }} ครั้ง
                            </span>
                            @if ($mailLogHasMore)
                                <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none collapsed"
                                    data-bs-toggle="collapse" data-bs-target="#mailSentLogsMore"
                                    aria-expanded="false" aria-controls="mailSentLogsMore"
                                    id="mailSentLogsToggle">
                                    <i class="fas fa-chevron-down me-1"></i>
                                    <span class="dp-mail-log-toggle-text">
                                        แสดงทั้งหมด ({{ $mailLogTotal - $mailLogPreviewLimit }} รายการ)
                                    </span>
                                </button>
                            @endif
                        </div>
                    </div>
                    <ul class="mb-0 small ps-3">
                        @foreach ($mailSentLogs as $i => $log)
                            @php
                                try {
                                    $logShip = !empty($log->ship_posted_at)
                                        ? \Carbon\Carbon::parse($log->ship_posted_at)->format('d/m/Y')
                                        : '-';
                                } catch (\Throwable $e) { $logShip = '-'; }
                                try {
                                    $logSent = !empty($log->sent_at)
                                        ? \Carbon\Carbon::parse($log->sent_at)->format('d/m H:i')
                                        : '-';
                                } catch (\Throwable $e) { $logSent = '-'; }
                                $isOverflow = $i >= $mailLogPreviewLimit;
                                $isAssignMail = strtoupper(trim((string) ($log->mail_type ?? ''))) === 'ASSIGN';
                            @endphp
                            @if ($isOverflow && $loop->iteration === $mailLogPreviewLimit + 1)
                                </ul>
                                <ul class="mb-0 small ps-3 collapse" id="mailSentLogsMore">
                            @endif
                            <li>
                                <span class="fw-semibold">{{ $logShip }}</span>
                                @if ($isAssignMail)
                                    <span class="badge bg-success ms-1">
                                        <i class="fas fa-truck me-1"></i>ตารางจัดรถ
                                    </span>
                                @else
                                    <span class="badge bg-secondary ms-1">
                                        <i class="fas fa-clipboard-list me-1"></i>แผนส่งมอบ
                                    </span>
                                    <span class="badge bg-primary ms-1">Rev {{ (int) $log->revision_number }}</span>
                                @endif
                                <span class="text-muted ms-1">ส่ง {{ $logSent }}</span>
                                @if (!empty($log->sent_by_name))
                                    <span class="text-muted">โดย {{ $log->sent_by_name }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
                @if ($mailLogHasMore)
                    <script>
                        (function() {
                            var btn = document.getElementById('mailSentLogsToggle');
                            var box = document.getElementById('mailSentLogsMore');
                            if (!btn || !box) return;
                            box.addEventListener('show.bs.collapse', function() {
                                btn.querySelector('.dp-mail-log-toggle-text').textContent = 'ย่อ';
                                btn.querySelector('.fas').classList.remove('fa-chevron-down');
                                btn.querySelector('.fas').classList.add('fa-chevron-up');
                            });
                            box.addEventListener('hide.bs.collapse', function() {
                                btn.querySelector('.dp-mail-log-toggle-text').textContent =
                                    'แสดงทั้งหมด ({{ $mailLogTotal - $mailLogPreviewLimit }} รายการ)';
                                btn.querySelector('.fas').classList.remove('fa-chevron-up');
                                btn.querySelector('.fas').classList.add('fa-chevron-down');
                            });
                        })();
                    </script>
                @endif
            @endif

            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="fw-bold fs-5">กรองการค้นหา</div>

                <div class="d-flex flex-wrap gap-2 align-items-center">

                    @if ($canDp)
                        <form method="POST" action="{{ route('dp.inquiry.sync-saleorder') }}" id="erpSyncSaleOrderForm">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-warning">
                                <i class="fa fa-rotate me-1"></i> ดึงข้อมูล ERP
                            </button>
                        </form>
                    @endif

                    <div class="dropdown">
                        <button type="button" class="btn btn-sm btn-outline-success dropdown-toggle"
                            data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fa fa-download me-1"></i> Export
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item" id="btnExportExcel"
                                    href="{{ route('dp.inquiry.export', request()->query()) }}">
                                    <i class="fa fa-file-excel text-success me-2"></i> Export Excel
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" id="btnExportPdf" target="_blank" rel="noopener"
                                    href="{{ route('dp.inquiry.export-pdf', request()->query()) }}">
                                    <i class="fa fa-file-pdf text-danger me-2"></i> Export PDF
                                </a>
                            </li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li>
                                <button type="button" class="dropdown-item" id="btnExportBoth"
                                    data-excel-url="{{ route('dp.inquiry.export', request()->query()) }}"
                                    data-pdf-url="{{ route('dp.inquiry.export-pdf', request()->query()) }}">
                                    <i class="fa fa-download text-primary me-2"></i> Export ทั้งหมด (Excel + PDF)
                                </button>
                            </li>
                        </ul>
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnToggleKpi"
                        aria-controls="kpiCollapse" aria-expanded="true" title="แสดง/ซ่อน KPI">
                        <i class="fas fa-chart-bar me-1"></i>KPI
                    </button>

                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnToggleFilter"
                        aria-controls="filterCollapse" aria-expanded="true" title="แสดง/ซ่อนตัวกรอง">
                        <i class="fas fa-filter me-1"></i>Filters
                    </button>

                    @if ($canDpMail)
                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal"
                            data-bs-target="#sendPlanMailModal">
                            <i class="fas fa-paper-plane me-1"></i> ส่งเมลแจ้งแผน
                        </button>
                    @endif
                </div>


            </div>

            <div class="collapse show" id="kpiCollapse">
                <div class="dp-kpi-strip mb-2" id="kpiBar">
                    <div class="dp-kpi-chip" title="จำนวน Line ที่แสดง / ทั้งหมด">
                        <i class="fa fa-list-ul text-muted"></i>
                        <div>
                            <div class="dp-kpi-chip-label">รายการ</div>
                            <div class="dp-kpi-chip-value"><span id="kpi_visible">0</span> / <span id="kpi_total">0</span>
                            </div>
                        </div>
                    </div>

                    <div class="dp-kpi-chip" title="น้ำหนักรวม / ยังไม่ขึ้นรถ (KG)">
                        <i class="fa fa-weight-hanging text-primary"></i>
                        <div>
                            <div class="dp-kpi-chip-label">น้ำหนัก (KG)</div>
                            <div class="dp-kpi-chip-value">
                                <span id="kpi_total_kg">0</span>
                                <span class="dp-kpi-chip-sub">เหลือ <span id="kpi_remain_kg">0</span></span>
                            </div>
                        </div>
                    </div>

                    <div class="dp-kpi-chip dp-kpi-danger" title="Line ที่ planner เลื่อน (สถานะ POSTPONED)">
                        <i class="fas fa-clock-rotate-left"></i>
                        <div>
                            <div class="dp-kpi-chip-label">เลื่อนโดย planner</div>
                            <div class="dp-kpi-chip-value"><span id="kpi_postponed">0</span></div>
                        </div>
                    </div>

                    <div class="dp-kpi-chip dp-kpi-warning" title="Line ที่ยังไม่ได้จัดรถ">
                        <i class="fa fa-truck"></i>
                        <div>
                            <div class="dp-kpi-chip-label">รอจัดรถ</div>
                            <div class="dp-kpi-chip-value"><span id="kpi_no_truck">0</span></div>
                        </div>
                    </div>

                    <div class="dp-kpi-chip dp-kpi-info" title="Line ที่มีเพิ่มเติม (Revision &gt; 0)">
                        <i class="fa fa-pen-to-square"></i>
                        <div>
                            <div class="dp-kpi-chip-label">เพิ่มเติม</div>
                            <div class="dp-kpi-chip-value"><span id="kpi_rev">0</span></div>
                        </div>
                    </div>
                </div>

                {{-- Legend: สีในตาราง = เพิ่มเติมกี่ครั้ง --}}
                @if (!empty($revColorMap))
                    <div class="dp-rev-legend mb-2">
                        <span class="dp-rev-legend-title">สี = เพิ่มเติม:</span>
                        @php
                            $legendOrder = [0, 1, 2, 3, 4, 5];
                        @endphp
                        @foreach ($legendOrder as $rev)
                            @php
                                $color = $revColorMap[$rev] ?? null;
                                $label = $rev === 0 ? 'ไม่มี' : ($rev >= 5 ? '5+' : (string) $rev . ' ครั้ง');
                            @endphp
                            @if ($color)
                                <span class="dp-rev-legend-item">
                                    <span class="dp-rev-legend-dot" style="background: {{ $color }};"></span>
                                    <span
                                        style="color: {{ $color }}; font-weight: 600;">{{ $label }}</span>
                                </span>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>

            <hr class="mt-0">

            <div class="collapse show" id="filterCollapse">
                <form method="GET" action="{{ route('dp.inquiry') }}" id="filterForm">
                    <input type="hidden" name="searched" value="1">

                    <div class="dp-filter-grid">
                        <div class="dp-fg-col">
                            <label class="form-label mb-1 small">Mode</label>
                            <select id="f_mode" name="mode" class="form-select form-select-sm">
                                <option value="" {{ request('mode') === '' ? 'selected' : '' }}>ทั้งหมด</option>
                                <option value="SO" {{ request('mode') === 'SO' ? 'selected' : '' }}>ปกติ</option>
                                <option value="ACID" {{ request('mode') === 'ACID' ? 'selected' : '' }}>กัดกรด</option>
                                <option value="SPECIAL" {{ request('mode') === 'SPECIAL' ? 'selected' : '' }}>งานพิเศษ (Special)</option>
                            </select>
                        </div>

                        <div class="dp-fg-col">
                            <label class="form-label mb-1 small">วันที่ส่ง (เริ่ม)</label>
                            <input id="f_ship_from" name="ship_from" type="date"
                                value="{{ request('ship_from', '') }}" class="form-control form-control-sm">
                        </div>

                        <div class="dp-fg-col">
                            <label class="form-label mb-1 small">วันที่ส่ง (ถึง)</label>
                            <input id="f_ship_to" name="ship_to" type="date" value="{{ request('ship_to', '') }}"
                                class="form-control form-control-sm">
                        </div>

                        <div class="dp-fg-col position-relative">
                            <label class="form-label mb-1 small">Sales Order</label>
                            <div class="dp-ac-wrap">
                                <input id="f_so" name="so" value="{{ request('so', '') }}" autocomplete="off"
                                    class="form-control form-control-sm dp-ac-input" data-ac-source="so"
                                    placeholder="ค้นหา SO">
                                <button type="button" class="dp-ac-clear" data-ac-clear="f_so" title="ล้าง"
                                    aria-label="ล้าง">&times;</button>
                            </div>
                            <div class="dp-suggest d-none" data-ac-for="f_so"></div>
                        </div>

                        <div class="dp-fg-col position-relative">
                            <label class="form-label mb-1 small">Customer</label>
                            <div class="dp-ac-wrap">
                                <input id="f_customer" name="customer" value="{{ request('customer', '') }}"
                                    autocomplete="off" class="form-control form-control-sm dp-ac-input"
                                    data-ac-source="customer" placeholder="ค้นหาลูกค้า">
                                <button type="button" class="dp-ac-clear" data-ac-clear="f_customer" title="ล้าง"
                                    aria-label="ล้าง">&times;</button>
                            </div>
                            <div class="dp-suggest d-none" data-ac-for="f_customer"></div>
                        </div>

                        <div class="dp-fg-col position-relative">
                            <label class="form-label mb-1 small">MFG No</label>
                            <div class="dp-ac-wrap">
                                <input id="f_mfg" name="mfg" value="{{ request('mfg', '') }}"
                                    autocomplete="off" class="form-control form-control-sm" placeholder="ค้นหา MFG">
                                <button type="button" class="dp-ac-clear" data-ac-clear="f_mfg" title="ล้าง"
                                    aria-label="ล้าง">&times;</button>
                            </div>
                        </div>

                        <div class="dp-fg-col position-relative">
                            <label class="form-label mb-1 small">Ship To</label>
                            <div class="dp-ac-wrap">
                                <input id="f_shipto" name="shipto" value="{{ request('shipto', '') }}"
                                    autocomplete="off" class="form-control form-control-sm dp-ac-input"
                                    data-ac-source="shipto" placeholder="ค้นหา Ship To">
                                <button type="button" class="dp-ac-clear" data-ac-clear="f_shipto" title="ล้าง"
                                    aria-label="ล้าง">&times;</button>
                            </div>
                            <div class="dp-suggest d-none" data-ac-for="f_shipto"></div>
                        </div>

                        <div class="dp-fg-col position-relative">
                            <label class="form-label mb-1 small">Division/Sales</label>
                            <div class="dp-ac-wrap">
                                <input id="f_sales" name="divsales" value="{{ request('divsales', '') }}"
                                    autocomplete="off" class="form-control form-control-sm dp-ac-input"
                                    data-ac-source="divsales" placeholder="เช่น D3 / ภควดี">
                                <button type="button" class="dp-ac-clear" data-ac-clear="f_sales" title="ล้าง"
                                    aria-label="ล้าง">&times;</button>
                            </div>
                            <div class="dp-suggest d-none" data-ac-for="f_sales"></div>
                        </div>

                        <div class="dp-fg-col">
                            <label class="form-label mb-1 small">Status</label>
                            @php $st = strtoupper(request('status', 'NEW')); @endphp
                            <select id="f_status" name="status" class="form-select form-select-sm">
                                <option value="NEW" {{ $st === 'NEW' ? 'selected' : '' }}>NEW</option>
                                <option value="ASSIGN" {{ $st === 'ASSIGN' ? 'selected' : '' }}>ASSIGN</option>
                                <option value="SPECIAL" {{ $st === 'SPECIAL' ? 'selected' : '' }}>SPECIAL</option>
                                <option value="POSTPONED" {{ $st === 'POSTPONED' ? 'selected' : '' }}>POSTPONED</option>
                                <option value="CLOSED" {{ $st === 'CLOSED' ? 'selected' : '' }}>CLOSED</option>
                                <option value="VOID" {{ $st === 'VOID' ? 'selected' : '' }}>VOID</option>
                                <option value="ALL" {{ $st === 'ALL' ? 'selected' : '' }}>ALL</option>
                            </select>
                        </div>

                        <div class="dp-fg-col">
                            <label class="form-label mb-1 small">Revision</label>
                            <input id="f_revision" name="revision" type="number" min="0" step="1"
                                value="{{ request('revision', '') }}" class="form-control form-control-sm"
                                placeholder="0, 1, 2">
                        </div>

                        <div class="dp-fg-col">
                            <label class="form-label mb-1 small">Order By</label>
                            @php $ob = request('order_by',''); @endphp
                            <select id="f_order_by" name="order_by" class="form-select form-select-sm">
                                <option value="" {{ $ob === '' ? 'selected' : '' }}></option>
                                <option value="ship_posted_at" {{ $ob === 'ship_posted_at' ? 'selected' : '' }}>
                                    วันที่ส่งสินค้า</option>
                                <option value="window_at" {{ $ob === 'window_at' ? 'selected' : '' }}>ช่วงเวลารับส่ง
                                </option>
                                <option value="so" {{ $ob === 'so' ? 'selected' : '' }}>SO</option>
                                <option value="customer" {{ $ob === 'customer' ? 'selected' : '' }}>Customer</option>
                                <option value="shipto" {{ $ob === 'shipto' ? 'selected' : '' }}>Ship To</option>
                                <option value="qty" {{ $ob === 'qty' ? 'selected' : '' }}>KG</option>
                                <option value="revision" {{ $ob === 'revision' ? 'selected' : '' }}>Revision</option>
                            </select>
                        </div>

                        <div class="dp-fg-col">
                            <label class="form-label mb-1 small">Direction</label>
                            @php $od = request('order_dir',''); @endphp
                            <select id="f_order_dir" name="order_dir" class="form-select form-select-sm">
                                <option value="" {{ $od === '' ? 'selected' : '' }}></option>
                                <option value="desc" {{ $od === 'desc' ? 'selected' : '' }}>DESC</option>
                                <option value="asc" {{ $od === 'asc' ? 'selected' : '' }}>ASC</option>
                            </select>
                        </div>

                        <div class="dp-fg-actions">
                            <button type="submit" class="btn btn-primary btn-sm" id="btnSearch">
                                <i class="fas fa-search me-1"></i>ค้นหา
                            </button>
                            <a class="btn btn-outline-secondary btn-sm" href="{{ route('dp.inquiry') }}">Reset</a>
                        </div>
                    </div>
                </form>
            </div>

            <div class="fw-bold fs-5 mt-2">ตารางข้อมูล</div>

            <div class="d-flex justify-content-end mb-2">
                <div class="dropdown">
                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" id="inqColumnMenuBtn"
                        data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                        <i class="fas fa-table-columns me-1"></i> Columns
                        <span class="badge bg-secondary ms-1" id="inqHiddenColumnCount">0</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end p-2 inq-column-menu" aria-labelledby="inqColumnMenuBtn">
                        <div class="d-flex gap-2 mb-2">
                            <button type="button" class="btn btn-sm btn-outline-primary flex-fill"
                                id="inqShowAllColumnsBtn">
                                <i class="fas fa-eye me-1"></i> Show all
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary flex-fill"
                                id="inqResetColumnsBtn">
                                <i class="fas fa-rotate-left me-1"></i> Reset
                            </button>
                        </div>
                        <div id="inqColumnToggleList"></div>
                    </div>
                </div>
            </div>

            @if ($canDp)
                <div
                    class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2 p-2 border rounded bg-light">
                    <div class="fw-semibold">
                        เลือกแล้ว <span id="bulkPostponeCount">0</span> รายการ
                        <span class="text-muted small ms-1">(<span id="bulkPostponeSoCount">0</span> SO)</span>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="bulkPostponeClearBtn"
                            disabled>
                            ล้างที่เลือก
                        </button>
                        <button type="button" class="btn btn-sm btn-warning" id="bulkPostponeOpenBtn" disabled>
                            <i class="fas fa-calendar-alt"></i>
                        </button>
                    </div>
                </div>
            @endif

            @if ($showInquiryBulkTruck)
                <div
                    class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2 p-2 border rounded bg-light">
                    <div class="fw-semibold">
                        จัดรถที่เลือก <span id="bulkTruckCount">0</span> รายการ
                        <span class="text-muted small ms-1" id="bulkTruckCustomer">ยังไม่ได้เลือกรายการ</span>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="bulkTruckClearBtn"
                            disabled>
                            ล้างที่เลือก
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="bulkTruckSameCustomerBtn"
                            disabled>
                            เลือกทั้งหมดที่แสดง
                        </button>
                        <button type="button" class="btn btn-sm btn-info" id="bulkTruckOpenBtn" disabled>
                            <i class="fas fa-truck me-1"></i> จัดรถ
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-warning" id="bulkTruckUnassignOpenBtn"
                            disabled>
                            <i class="fas fa-times me-1"></i> ยกเลิกรถที่เลือก
                        </button>
                    </div>
                </div>
            @endif

            <div class="table-responsive dp-table-wrap">
                <table class="table table-bordered table-sm align-middle mb-0" id="inqTable">
                    <thead class="table-light">
                        <tr class="dp-inquiry-header-row">
                            @if ($showInquiryBulkTruck)
                                <th class="text-center" style="width:46px;">
                                    <input type="checkbox" class="form-check-input" id="bulkTruckCheckAll"
                                        title="เลือกจัดรถทั้งหมดที่แสดง">
                                </th>
                            @endif
                            @if ($canDp)
                                <th class="text-center" style="width:46px;">
                                    <input type="checkbox" class="form-check-input" id="bulkPostponeCheckAll"
                                        title="เลือกทั้งหมดที่แสดง">
                                </th>
                            @endif
                            <th class="sticky-col sticky-1">#</th>

                            <th class="sticky-col sticky-2">วันที่ส่งสินค้า</th>
                            <th class="sticky-col sticky-truck text-center" style="min-width:160px;">Truck</th>
                            <th class="sticky-col sticky-3">ลูกค้า</th>
                            <th class="sticky-col sticky-4">Part Desc</th>
                            <th>เลข MFG</th>
                            <th class="text-end">จำนวน/KG</th>
                            <th class="text-end">Stock FG</th>
                            <th class="text-end">ขายระบุเส้น/ชิ้น</th>
                            <th>สถานที่ส่ง</th>
                            <th>เลข SO</th>
                            <th>เอกสารแนบ</th>
                            <th>เพิ่มเติม</th>
                            <th>เบอร์โทร/ชื่อผู้ติดต่อ</th>
                            <th class="text-center" style="width:90px;">เพิ่มเติม</th>
                            <th style="min-width:170px;">Status</th>
                            <th class="text-center" style="min-width:{{ $canDp ? '230px' : '170px' }};">Action</th>
                            <th class="text-center" style="min-width:120px;">History</th>
                        </tr>
                    </thead>

                    <tbody id="inqTbody">
                        @forelse ($divGroups as $divKey => $items)
                            @php
                                $groupTitle = $divLabel[$divKey] ?? (string) $divKey;
                                $groupCount = is_countable($items) ? count($items) : 0;
                                $no = 1;
                            @endphp

                            <tr class="table-secondary group-row" data-group="{{ e($groupTitle) }}">
                                <td colspan="{{ $tableColspan }}" class="fw-semibold">
                                    @if ($showInquiryBulkTruck)
                                        <label class="me-2 fw-normal small text-info-emphasis"
                                            style="cursor:pointer;"
                                            title="เลือก/ยกเลิกทุกรายการในกลุ่มนี้เพื่อจัดรถ (วันส่งเดียวกัน)">
                                            <input type="checkbox"
                                                class="form-check-input align-middle me-1 jsGroupTruckCheckAll"
                                                data-group="{{ e($groupTitle) }}">
                                            <i class="fas fa-truck"></i> เลือกทั้งกลุ่มจัดรถ
                                        </label>
                                    @endif
                                    {{ $groupTitle }} <span class="text-muted">({{ $groupCount }} รายการ)</span>
                                </td>
                            </tr>

                            @foreach ($items as $r)
                                @php
                                    $revNo = (int) ($r->revision_number ?? 0);
                                    $revLookup = $revNo > 5 ? 5 : $revNo;
                                    $revColor = $revColorMap[$revLookup] ?? ($revColorMap[5] ?? '#111827');
                                    $assignedQty = is_numeric($r->assigned_weight_sum ?? null)
                                        ? (float) $r->assigned_weight_sum
                                        : 0.0;
                                    $remainingQty = is_numeric($r->remaining_assign_qty ?? null)
                                        ? (float) $r->remaining_assign_qty
                                        : max(0, $qty - $assignedQty);

                                    $docText = trim((string) ($r->attach_docs_text ?? ''));

                                    $mode = strtoupper(trim((string) ($r->delivery_type ?? '')));

                                    $sellByLine = (int) ($r->sell_by_line ?? 0) ? 1 : 0;
                                    $lineQty = is_numeric($r->line_qty ?? null) ? (float) $r->line_qty : null;
                                    $qty = is_numeric($r->qty ?? null) ? (float) $r->qty : 0.0;
                                    $lineUnit = $sellByLine && $qty == 0.0 ? 'ชิ้น' : 'เส้น';
                                    $linePrefix = $lineUnit === 'ชิ้น' ? 'ชิ้น : ' : 'ระบุเส้น : ';

                                    $lineQtyText =
                                        $lineQty !== null && $lineQty > 0
                                            ? $linePrefix . number_format($lineQty, 0) . ' ' . $lineUnit . ' '
                                            : '-';

                                    $customerText = (string) ($r->customer_name ?? '#' . ($r->customer_id ?? ''));
                                    $shiptoText = (string) ($r->address ?? '');
                                    $soText = (string) ($r->so_number ?? '');
                                    $mfgText = (string) ($r->mfg_no ?? '');
                                    $telText = (string) ($r->tel ?? '');

                                    $moreText = trim((string) ($r->more_text ?? ''));

                                    $qty = is_numeric($r->qty ?? null) ? (float) $r->qty : 0.0;
                                    $stock = is_numeric($r->stock_qty_rt ?? null) ? (float) $r->stock_qty_rt : 0.0;

                                    try {
                                        $shipDateOnly = !empty($r->ship_posted_at)
                                            ? \Carbon\Carbon::parse($r->ship_posted_at)->format('Y-m-d')
                                            : '';
                                    } catch (\Throwable $e) {
                                        $shipDateOnly = '';
                                    }

                                    try {
                                        $shipDMY =
                                            $shipDateOnly !== ''
                                                ? \Carbon\Carbon::parse($shipDateOnly)->format('d/m/Y')
                                                : '';
                                    } catch (\Throwable $e) {
                                        $shipDMY = '';
                                    }

                                    try {
                                        $timeFromWindow = !empty($r->window_at)
                                            ? \Carbon\Carbon::parse($r->window_at)->format('H:i')
                                            : '';
                                    } catch (\Throwable $e) {
                                        $timeFromWindow = '';
                                    }

                                    $shipDateTimeText =
                                        $shipDMY !== '' && $timeFromWindow !== ''
                                            ? $shipDMY . ' ' . $timeFromWindow
                                            : ($shipDMY ?:
                                            '-');

                                    $shipSort = $shipDateOnly !== '' ? $shipDateOnly . 'T' . $timeFromWindow : '';

                                    try {
                                        $windowSort = !empty($r->window_at)
                                            ? \Carbon\Carbon::parse($r->window_at)->format('Y-m-d\TH:i')
                                            : '';
                                    } catch (\Throwable $e) {
                                        $windowSort = '';
                                    }

                                    $partText =
                                        trim((string) ($r->part_number ?? '')) .
                                        ' ' .
                                        trim((string) ($r->part_desc ?? ''));

                                    $statusUpper = strtoupper(trim((string) ($r->status ?? '')));
                                    $isVoidStatus = in_array(
                                        $statusUpper,
                                        ['VOID', 'VOIDED', 'CANCEL', 'CANCELED', 'CANCELLED'],
                                        true,
                                    );
                                    // งานขายเป็นชิ้น (sell_by_line=1, qty=0) จะไม่มีน้ำหนัก
                                    // จึงต้องนับจาก line_qty (จำนวนชิ้น) แทน ไม่งั้น checkbox จะถูก disable
                                    $isPieceLineUi = $sellByLine === 1 && $qty == 0.0 && $lineQty !== null && $lineQty > 0;
                                    $canAssignTruckUi =
                                        !$isVoidStatus &&
                                        !in_array($statusUpper, ['CLOSED', 'SPECIAL', 'POSTPONED'], true) &&
                                        !empty($r->can_pick_truck) &&
                                        ($remainingQty > 0 || $assignedQty > 0 || $isPieceLineUi);
                                    $canSpecialDispatchUi = $canDpa && !$isVoidStatus && $statusUpper !== 'CLOSED';
                                    $canOpenDispatchModalUi = $canDpa && !$isVoidStatus && $statusUpper !== 'CLOSED';
                                    $canBulkPostpone =
                                        $canDp &&
                                        !$isVoidStatus &&
                                        !in_array($statusUpper, ['CLOSED', 'POSTPONED'], true);
                                    $specialLabel = trim((string) ($r->special_dispatch_label ?? ''));
                                    $specialIsOpen =
                                        strtoupper(trim((string) ($r->special_dispatch_status ?? ''))) === 'OPEN' &&
                                        strtoupper(trim((string) ($r->special_dispatch_type ?? ''))) !== 'POSTPONED';
                                @endphp

                                <tr class="rev-row data-row" style="--rev: {{ $revColor }};"
                                    data-group="{{ e($groupTitle) }}" data-mode="{{ e($mode) }}"
                                    data-so="{{ e($soText) }}" data-customer="{{ e($customerText) }}"
                                    data-part="{{ e($partText) }}" data-shipto="{{ e($shiptoText) }}"
                                    data-ship="{{ e($shipSort) }}" data-window="{{ e($windowSort) }}"
                                    data-qty="{{ e(number_format($qty, 3, '.', '')) }}" data-rev="{{ e($revNo) }}"
                                    data-sbl="{{ e($sellByLine) }}"
                                    data-assigned="{{ e(number_format($assignedQty, 3, '.', '')) }}"
                                    data-remaining="{{ e(number_format($remainingQty, 3, '.', '')) }}"
                                    data-stock="{{ e(number_format($stock, 3, '.', '')) }}"
                                    data-has-truck="{{ trim((string) ($r->truck_plate_list ?? '')) !== '' ? '1' : '0' }}"
                                    data-status="{{ e($statusUpper) }}"
                                    data-pc-status="{{ e(strtoupper((string) ($r->planner_confirmation_status ?? ''))) }}"
                                    data-mfg="{{ e($mfgText) }}">

                                    @if ($showInquiryBulkTruck)
                                        <td class="text-center">
                                            <input type="checkbox" class="form-check-input jsBulkTruckCheck"
                                                value="{{ $r->ord_id }}" {{ $canAssignTruckUi ? '' : 'disabled' }}
                                                data-ord-id="{{ $r->ord_id }}" data-so="{{ e($soText) }}"
                                                data-customer-id="{{ e($r->customer_id ?? '') }}"
                                                data-customer="{{ e($customerText) }}"
                                                data-ship-date="{{ e($shipDateOnly) }}"
                                                data-ship-label="{{ e($shipDateTimeText) }}"
                                                data-shipto="{{ e($shiptoText) }}"
                                                data-mfg="{{ e($mfgText) }}"
                                                data-part="{{ e($r->part_number ?? '') }}"
                                                data-part-desc="{{ e($r->part_desc ?? '') }}"
                                                data-assigned="{{ e(number_format($assignedQty, 3, '.', '')) }}"
                                                data-remaining="{{ e(number_format($remainingQty, 3, '.', '')) }}"
                                                data-qty="{{ e(number_format($qty, 3, '.', '')) }}"
                                                data-has-truck="{{ trim((string) ($r->truck_plate_list ?? '')) !== '' ? '1' : '0' }}"
                                                data-plate="{{ e($r->truck_plate_display ?? $r->truck_plate_list ?? '') }}"
                                                data-sell-by-line="{{ e($sellByLine) }}"
                                                data-line-qty="{{ e($lineQty !== null ? number_format($lineQty, 3, '.', '') : '') }}"
                                                data-line-text="{{ e($lineQtyText) }}"
                                                data-status="{{ e($statusUpper) }}">
                                        </td>
                                    @endif

                                    @if ($canDp)
                                        <td class="text-center">
                                            <input type="checkbox" class="form-check-input jsBulkPostponeCheck"
                                                value="{{ $r->ord_id }}" {{ $canBulkPostpone ? '' : 'disabled' }}
                                                data-ord-id="{{ $r->ord_id }}" data-so="{{ e($soText) }}"
                                                data-part="{{ e($r->part_number ?? '') }}"
                                                data-part-desc="{{ e($r->part_desc ?? '') }}"
                                                data-ship-date="{{ e($shipDateOnly) }}"
                                                data-ship-label="{{ e($shipDateTimeText) }}"
                                                data-status="{{ e($statusUpper) }}"
                                                data-window-time="{{ e($timeFromWindow) }}">
                                        </td>
                                    @endif

                                    <td class="sticky-col sticky-1 text-center">{{ $no++ }}</td>

                                    <td class="sticky-col sticky-2">{{ $shipDateTimeText }}</td>

                                    <td class="sticky-col sticky-truck truck-cell">
                                        @php
                                            $truckList = collect(explode(',', (string) ($r->truck_plate_list ?? '')))
                                                ->map(fn($x) => trim($x))
                                                ->filter()
                                                ->unique()
                                                ->values();
                                        @endphp

                                        @if ($truckList->isNotEmpty())
                                            @foreach ($truckList as $plate)
                                                <div class="truck-plate">{{ $plate }}</div>
                                            @endforeach

                                            @if ($truckList->count() > 1)
                                                <div class="truck-meta">รวม {{ $truckList->count() }} คัน</div>
                                            @endif
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>

                                    <td class="sticky-col sticky-3">{{ $customerText }}</td>
                                    <td class="sticky-col sticky-4">
                                        <div>{{ $r->part_desc ?? '' }}</div>
                                        @if (trim((string) ($r->part_number ?? '')) !== '')
                                            <div class="part-number-muted">{{ $r->part_number }}</div>
                                        @endif
                                    </td>

                                    <td class="text-wrap" style="white-space:normal;min-width:160px;">
                                        {{ $mfgText !== '' ? $mfgText : '-' }}
                                    </td>

                                    <td class="text-end qty-cell">
                                        <div class="qty-main">{{ $fmtWeight($qty) }}</div>

                                        @if ($assignedQty > 0)
                                            <div class="qty-sub qty-sub-muted">
                                                <span class="qty-label">ขึ้นรถแล้ว</span>
                                                <span class="qty-value">{{ $fmtWeight($assignedQty) }}</span>
                                            </div>

                                            <div class="qty-sub {{ $remainingQty > 0 ? 'qty-sub-warn' : 'qty-sub-ok' }}">
                                                <span class="qty-label">คงเหลือ</span>
                                                <span class="qty-value">{{ $fmtWeight($remainingQty) }}</span>
                                            </div>
                                        @endif
                                    </td>

                                    <td class="text-end">{{ number_format($stock, 3) }}</td>

                                    <td class="text-wrap" style="white-space:normal;min-width:220px;">
                                        <div>{{ $lineQtyText }}</div>
                                    </td>

                                    <td class="text-wrap" style="white-space:normal;min-width:240px;">
                                        {{ $shiptoText !== '' ? $shiptoText : '-' }}
                                    </td>

                                    <td>{{ $soText !== '' ? $soText : '-' }}</td>

                                    <td class="text-wrap" style="white-space:normal;min-width:220px;">
                                        {{ $docText !== '' ? $docText : '-' }}
                                    </td>



                                    <td class="text-wrap" style="white-space:normal;min-width:220px;">
                                        {{ $moreText !== '' ? $moreText : '-' }}
                                    </td>

                                    <td class="text-wrap" style="white-space:normal;min-width:160px;">
                                        {{ $telText !== '' ? $telText : '-' }}
                                    </td>

                                    <td class="text-center">{{ $revNo }}</td>
                                    <td>
                                        <div>{{ $r->status ?? '-' }}</div>
                                        @if ($specialLabel !== '')
                                            <div class="small text-primary">{{ $specialLabel }}</div>
                                        @endif
                                        @php
                                            $pcStatus = $r->planner_confirmation_status ?? null;
                                            $pcLabel = $r->planner_confirmation_label ?? '-';
                                            $pcBadge = $r->planner_confirmation_badge ?? 'light text-dark border';
                                            $pcNewDate = !empty($r->planner_confirmation_new_date)
                                                ? \Carbon\Carbon::parse($r->planner_confirmation_new_date)->format(
                                                    'd/m/Y',
                                                )
                                                : null;
                                            $pcAt = !empty($r->planner_confirmation_confirmed_at)
                                                ? \Carbon\Carbon::parse($r->planner_confirmation_confirmed_at)->format(
                                                    'd/m H:i',
                                                )
                                                : null;
                                            $pcBy = trim((string) ($r->planner_confirmation_by ?? ''));
                                            $pcByParts = $pcBy !== '' ? preg_split('/\s+/', $pcBy) : [];
                                            $pcByShort = trim((string) ($pcByParts[0] ?? ''));
                                            $statusUpperForPc = strtoupper(trim((string) ($r->status ?? '')));
                                        @endphp
                                        @if (!$isVoidStatus && !empty($pcStatus))
                                            @php
                                                $tip =
                                                    'ฝ่ายวางแผน: ' .
                                                    $pcLabel .
                                                    ($pcNewDate ? ' → ' . $pcNewDate : '') .
                                                    ($pcAt ? "\nบันทึก " . $pcAt : '') .
                                                    ($pcBy !== '' ? ' โดย ' . $pcBy : '');
                                            @endphp
                                            <div class="small mt-1 planner-confirm-wrap" title="{{ $tip }}">
                                                <span class="badge bg-{{ $pcBadge }} planner-confirm-badge">
                                                    <i class="fas fa-clipboard-check me-1"></i>Planner:
                                                    {{ $pcLabel }}
                                                </span>
                                                @if ($pcBy !== '' || $pcAt)
                                                    <div class="text-muted small mt-1 planner-confirm-meta">
                                                        โดย
                                                        {{ $pcByShort !== '' ? $pcByShort : '-' }}{{ $pcAt ? ' - ' . $pcAt : '' }}
                                                    </div>
                                                @endif
                                                @if ($pcStatus === 'POSTPONE' && $pcNewDate)
                                                    <div class="text-warning fw-semibold mt-1">
                                                        วันส่งใหม่: {{ $pcNewDate }}
                                                    </div>
                                                @endif
                                                @if (!empty($r->planner_confirmation_remark))
                                                    <div class="text-muted small mt-1" style="white-space:normal;">
                                                        <i
                                                            class="fas fa-comment me-1"></i>{{ $r->planner_confirmation_remark }}
                                                    </div>
                                                @endif
                                            </div>
                                        @elseif (!$isVoidStatus && !in_array($statusUpperForPc, ['VOID', 'CLOSED'], true))
                                            <div class="small mt-1"
                                                title="ฝ่ายวางแผนยังไม่ได้ยืนยัน — ระบบถือว่าส่งได้ตามแผนเดิมโดย default">
                                                <span class="badge bg-light text-dark border">
                                                    Planner:
                                                    <i class="far fa-clock me-1"></i>รอวางแผนยืนยัน
                                                </span>
                                            </div>
                                        @endif
                                    </td>

                                    <td class="text-center">
                                        @php
                                            $shipDateKey = $shipDateOnly ?: '';
                                            $soLineKey = (string) ($r->so_number ?? '') . '|' . $shipDateKey;
                                            $shipRaw = trim((string) ($r->ship_posted_at ?? ''));
                                            $shipDt = null;

                                            try {
                                                $shipDt =
                                                    $shipRaw !== ''
                                                        ? \Carbon\Carbon::parse($shipRaw)->startOfDay()
                                                        : null;
                                            } catch (\Throwable $e) {
                                                $shipDt = null;
                                            }

                                            $cutoff = $shipDt ? $shipDt->copy()->subDays(3)->endOfDay() : null;
                                            $now = \Carbon\Carbon::now();
                                            $canVoid = $canDp && $cutoff ? $now->lte($cutoff) : false;

                                            $voidReason = !$shipDt
                                                ? 'ยกเลิกไม่ได้ เพราะวันที่ส่งสินค้า(ship_posted_at) ไม่ถูกต้อง/ว่าง'
                                                : 'ยกเลิกได้เฉพาะก่อนวันส่งสินค้าอย่างน้อย 3 วัน (ภายใน ' .
                                                    $cutoff->format('d/m/Y') .
                                                    ')';
                                            $canPlanMore = !in_array(
                                                $statusUpper,
                                                ['VOID', 'CLOSED', 'POSTPONED'],
                                                true,
                                            );
                                            $truckPlateForAction = trim((string) ($r->truck_plate_display ?? ''));
                                            if ($truckPlateForAction === '') {
                                                $truckPlateForAction = trim((string) ($r->truck_plate_list ?? ''));
                                            }
                                            $hasTruck = $truckPlateForAction !== '';
                                            $truckActionLabel = $hasTruck ? 'เปลี่ยนรถ' : 'จัดรถ';
                                            $showActionMenu = !$isVoidStatus && $specialIsOpen;
                                        @endphp

                                        @unless ($isVoidStatus)
                                            <div class="d-inline-flex gap-1 justify-content-center flex-wrap action-compact">
                                                @if (!$isLoggedIn)
                                                    <a class="btn btn-sm btn-outline-primary" href="{{ $loginUrl }}">
                                                        เข้าสู่ระบบ
                                                    </a>
                                                @else
                                                    @if ($canDp)
                                                        @if (!empty($r->edit_locked))
                                                            <button type="button"
                                                                class="btn btn-sm btn-outline-secondary action-primary jsEditLockedBtn"
                                                                data-ship-date="{{ e($shipDateOnly) }}"
                                                                data-so="{{ e($soText) }}"
                                                                title="งานนี้ถูกจัดรถหรือกำหนดเป็นงานพิเศษแล้ว">
                                                                แก้ไข
                                                            </button>
                                                        @else
                                                            <a class="btn btn-sm btn-outline-primary action-primary"
                                                                href="{{ route('dp.day', ['date' => $shipDateOnly ?: now()->toDateString()]) }}?edit={{ $r->ord_id }}">
                                                                แก้ไข
                                                            </a>
                                                        @endif
                                                        @if ($canPlanMore)
                                                            <button type="button"
                                                                class="btn btn-sm btn-outline-warning action-icon jsPostponeBtn"
                                                                data-ord-id="{{ $r->ord_id }}"
                                                                data-so="{{ e($soText) }}"
                                                                data-part="{{ e($r->part_number ?? '') }}"
                                                                data-ship-date="{{ e($shipDateOnly) }}"
                                                                data-window-time="{{ e($timeFromWindow) }}"
                                                                title="เลื่อนแผน" aria-label="เลื่อนแผน">
                                                                <i class="fas fa-calendar-alt"></i>
                                                            </button>
                                                            <button type="button"
                                                                class="btn btn-sm btn-outline-success action-icon jsDuplicateBtn"
                                                                data-ord-id="{{ $r->ord_id }}"
                                                                data-so="{{ e($soText) }}"
                                                                data-customer-id="{{ e($r->customer_id ?? '') }}"
                                                                data-customer-name="{{ e($customerText) }}"
                                                                data-sales-name="{{ e($r->sales_name ?? '') }}"
                                                                data-part="{{ e($r->part_number ?? '') }}"
                                                                data-part-desc="{{ e($r->part_desc ?? '') }}"
                                                                data-parts-id="{{ e($r->parts_id ?? '') }}"
                                                                data-delivery-type="{{ e($r->delivery_type ?? '') }}"
                                                                data-revision="{{ e($revNo) }}"
                                                                data-ship-date="{{ e($shipDateOnly) }}"
                                                                data-window-time="{{ e($timeFromWindow) }}"
                                                                data-mfg="{{ e($mfgText) }}"
                                                                data-qty="{{ e(number_format($qty, 3, '.', '')) }}"
                                                                data-sell-by-line="{{ e($sellByLine) }}"
                                                                data-line-qty="{{ e($lineQty !== null ? (string) $lineQty : '') }}"
                                                                data-address="{{ e($shiptoText) }}"
                                                                data-tel="{{ e($telText) }}"
                                                                data-remark="{{ e($r->remark ?? '') }}"
                                                                data-attach-docs="{{ e($r->attach_docs ?? '') }}"
                                                                data-attach-docs-other="{{ e($r->attach_docs_other ?? '') }}"
                                                                title="คัดลอกแผน" aria-label="คัดลอกแผน">
                                                                <i class="fas fa-copy"></i>
                                                            </button>
                                                        @endif
                                                        <button type="button"
                                                            class="btn btn-sm {{ $canVoid ? 'btn-outline-danger jsVoidBtn' : 'btn-outline-secondary disabled' }} action-icon"
                                                            {{ $canVoid ? '' : 'disabled' }}
                                                            data-ord-id="{{ $r->ord_id }}"
                                                            data-so="{{ e($soText) }}"
                                                            data-part="{{ e($r->part_number ?? '') }}"
                                                            data-ship="{{ e($shipRaw) }}"
                                                            title="{{ $canVoid ? 'ยกเลิกรายการ' : $voidReason }}">
                                                            <i class="fas fa-ban"></i>
                                                        </button>
                                                    @endif

                                                    @if ($canOpenDispatchModalUi && $canDpa)
                                                        {{-- ปุ่มจัดรถ ลิงก์ไปหน้า "จัดรถส่งสินค้า"
                                                             กรองด้วย MFG (unique ต่อแถว) แทน SO เพื่อให้โชว์เฉพาะ MFG ที่ผู้ใช้กด
                                                             ไม่ให้ติด MFG อื่นของ SO เดียวกันที่จัดรถไปแล้ว --}}
                                                        <a href="{{ route('dp.dashboard.logistics-summary', ['ship_date' => $shipDateOnly, 'q' => $mfgText !== '' ? $mfgText : ($r->so_number ?? ''), 'selected' => 'ord-' . (int) $r->ord_id]) }}"
                                                            class="btn btn-sm btn-outline-info action-primary"
                                                            title="ไปหน้าจัดรถส่งสินค้า (filter MFG อัตโนมัติ)">
                                                            <i class="fas fa-truck me-1"></i> จัดรถ
                                                        </a>
                                                        @if ($hasTruck)
                                                            <button type="button"
                                                                class="btn btn-sm btn-outline-warning action-icon jsUnassignTruckBtn"
                                                                data-ord-id="{{ $r->ord_id }}"
                                                                data-so="{{ e($soText) }}"
                                                                data-mfg="{{ e($mfgText) }}"
                                                                data-plate="{{ e($truckPlateForAction) }}"
                                                                data-return-url="{{ e(url()->full()) }}"
                                                                title="ยกเลิกรถ" aria-label="ยกเลิกรถ">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                        @endif
                                                    @endif

                                                    @if ($showActionMenu)
                                                        <div class="dropdown">
                                                            <button type="button"
                                                                class="btn btn-sm btn-outline-secondary action-more"
                                                                data-bs-toggle="dropdown" data-bs-auto-close="outside"
                                                                aria-expanded="false" title="เพิ่มเติม"
                                                                aria-label="เพิ่มเติม">
                                                                <i class="fas fa-ellipsis-h"></i>
                                                            </button>
                                                            <div class="dropdown-menu dropdown-menu-end action-menu">
                                                                @if ($specialIsOpen)
                                                                    <form method="POST"
                                                                        action="{{ route('dp.inquiry.special-dispatch.close', ['ordId' => $r->ord_id]) }}"
                                                                        onsubmit="return confirm('ยืนยันปิดงานพิเศษนี้?');">
                                                                        @csrf
                                                                        <button type="submit" class="dropdown-item">
                                                                            <i class="fas fa-check-circle text-success"></i>
                                                                            ปิดงานพิเศษ
                                                                        </button>
                                                                    </form>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    @endif
                                                @endif
                                            </div>
                                        @endunless
                                    </td>

                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-dark jsHistoryBtn"
                                            data-ord-id="{{ $r->ord_id }}" data-current-rev="{{ $revNo }}"
                                            data-bs-toggle="modal" data-bs-target="#historyModal">
                                            ดู history
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        @empty
                            <tr>
                                <td colspan="{{ $tableColspan }}" class="text-center text-muted py-4">ไม่พบข้อมูล</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $rows->links() }}
            </div>
        </div>

        {{-- History Modal --}}
        <div class="modal fade" id="historyModal" tabindex="-1">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title">History</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                            <div id="historyMeta" class="text-muted small"></div>

                            <div class="btn-group btn-group-sm" role="group" aria-label="history view">
                                <button type="button" class="btn btn-outline-primary"
                                    id="btnHistCurrent">เทียบกับปัจจุบัน</button>
                                <button type="button" class="btn btn-outline-secondary"
                                    id="btnHistStep">ดูการแก้ไขแต่ละครั้ง</button>
                                <button type="button" class="btn btn-outline-dark"
                                    id="btnHistDb">ภาพรวมการแก้ไขข้อมูล</button>
                            </div>
                        </div>

                        <div id="histViewTop">
                            <table class="table table-sm table-bordered align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:70px" class="text-center">Rev</th>
                                        <th style="width:260px">Period</th>
                                        <th style="width:140px">Revise By</th>
                                        <th id="histColTitle">Changed Fields (เทียบกับปัจจุบัน)</th>
                                    </tr>
                                </thead>
                                <tbody id="historyTbody"></tbody>
                            </table>
                        </div>

                        <div id="histViewDb" class="d-none">
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr id="histDbThead"></tr>
                                    </thead>
                                    <tbody id="histDbTbody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Postpone Modal --}}
        <div class="modal fade" id="postponeModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="POST" id="postponeForm">
                    @csrf
                    <input type="hidden" name="return_url" value="{{ url()->full() }}">

                    <div class="modal-header">
                        <h5 class="modal-title">เลื่อนแผนส่ง</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-2">
                            <div class="small text-muted">รายการเดิม</div>
                            <div class="fw-semibold" id="postponeInfo">-</div>
                        </div>

                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">วันที่ส่งใหม่</label>
                                <input type="date" class="form-control" name="new_ship_posted_date"
                                    id="postponeShipDate" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">เวลาใหม่</label>
                                <input type="time" class="form-control" name="new_window_time"
                                    id="postponeWindowTime" required>
                            </div>
                        </div>

                        <div class="mt-2">
                            <label class="form-label fw-semibold">เหตุผลเลื่อนแผน</label>
                            <textarea class="form-control" name="postpone_reason" id="postponeReason" rows="3" placeholder="กรอกเหตุผล"
                                required></textarea>
                        </div>

                        <div class="alert alert-warning small mb-0 mt-3">
                            ระบบจะสร้างแผนใหม่จากข้อมูลเดิม และเก็บรายการเดิมไว้เป็น reference สถานะ POSTPONED
                        </div>
                        <div class="alert alert-info small mb-0 mt-2">
                            Tip: รายการเดิมจะอัปเดต Revision อัตโนมัติเมื่อยืนยันเลื่อนแผน ส่วนแผนใหม่จะเริ่ม Revision 0
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button>
                        <button type="submit" class="btn btn-warning" id="btnConfirmPostpone">ยืนยันเลื่อนแผน</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Bulk Postpone Modal --}}
        @if ($canDp)
            <div class="modal fade" id="bulkPostponeModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                    <form class="modal-content" method="POST" id="bulkPostponeForm"
                        action="{{ route('dp.inquiry.bulk-postpone') }}">
                        @csrf
                        <input type="hidden" name="return_url" value="{{ url()->full() }}">
                        <div id="bulkPostponeIds"></div>

                        <div class="modal-header">
                            <h5 class="modal-title">ย้ายแผนที่เลือก</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                aria-label="Close"></button>
                        </div>

                        <div class="modal-body">
                            <div class="alert alert-warning py-2">
                                เลือกแล้ว <strong id="bulkPostponeModalCount">0</strong> รายการ /
                                <strong id="bulkPostponeModalSoCount">0</strong> SO
                            </div>
                            <div class="alert alert-info py-2 small">
                                Tip: รายการเดิมจะอัปเดต Revision อัตโนมัติเมื่อยืนยันย้ายแผน ส่วนแผนใหม่จะเริ่ม Revision 0
                            </div>

                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">วันที่ส่งใหม่</label>
                                    <input type="date" class="form-control" name="new_ship_posted_date"
                                        id="bulkPostponeShipDate" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">เวลาใหม่</label>
                                    <input type="time" class="form-control" name="new_window_time"
                                        id="bulkPostponeWindowTime" required>
                                </div>
                            </div>

                            <div class="mt-2">
                                <label class="form-label fw-semibold">เหตุผลเลื่อนแผน</label>
                                <textarea class="form-control" name="postpone_reason" id="bulkPostponeReason" rows="3"
                                    placeholder="กรอกเหตุผลครั้งเดียว ระบบจะใช้กับทุกรายการที่เลือก" required></textarea>
                            </div>

                            <div class="table-responsive mt-3">
                                <table class="table table-sm table-bordered align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>SO</th>
                                            <th>Part</th>
                                            <th>วันที่เดิม</th>
                                            <th>วันที่ใหม่</th>
                                        </tr>
                                    </thead>
                                    <tbody id="bulkPostponePreview"></tbody>
                                </table>
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button>
                            <button type="submit" class="btn btn-warning" id="bulkPostponeSubmit">
                                ยืนยันย้ายแผนที่เลือก
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        {{-- Duplicate Modal --}}
        <div class="modal fade" id="duplicateModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                <form class="modal-content" method="POST" id="duplicateForm">
                    @csrf
                    <input type="hidden" name="return_url" value="{{ url()->full() }}">

                    <div class="modal-header">
                        <h5 class="modal-title">คัดลอกแผนส่ง</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-2">
                            <div class="small text-muted">รายการเดิม</div>
                            <div class="fw-semibold" id="duplicateInfo">-</div>
                        </div>

                        @php
                            $isSales8User = auth()->check() && in_array((int) auth()->id(), [50, 51], true);
                        @endphp

                        <div class="mb-3">
                            <label class="form-label fw-semibold d-block">โหมด</label>
                            <div class="d-flex gap-3 flex-wrap">
                                <label class="form-check mb-0">
                                    <input class="form-check-input" type="radio" id="duplicateModeSo" disabled>
                                    <span class="form-check-label">ปกติ (SO)</span>
                                </label>
                                <label class="form-check mb-0">
                                    <input class="form-check-input" type="radio" id="duplicateModeAcid" disabled>
                                    <span class="form-check-label">ส่งกัดกรด</span>
                                </label>
                                <label class="form-check mb-0">
                                    <input class="form-check-input" type="radio" id="duplicateModeSpecial" disabled>
                                    <span class="form-check-label">งานพิเศษ (Special)</span>
                                </label>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">วันที่ส่งสินค้า <span
                                        class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="duplicate_ship_posted_date"
                                    id="duplicateShipDate" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">ช่วงเวลารับส่ง <span
                                        class="text-danger">*</span></label>
                                <input type="time" class="form-control" name="duplicate_window_time"
                                    id="duplicateWindowTime" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Sales Order</label>
                                <input type="text" class="form-control" id="duplicateSoDisplay" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Customer</label>
                                <input type="text" class="form-control" id="duplicateCustomerDisplay" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Sales</label>
                                <input type="text" class="form-control" id="duplicateSalesDisplay" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Part No</label>
                                <input type="text" class="form-control" id="duplicatePartDisplay" readonly>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label fw-semibold">Part Desc</label>
                                <input type="text" class="form-control" id="duplicatePartDescDisplay" readonly>
                            </div>

                            <div class="col-md-12">
                                <label class="form-label fw-semibold">MFG No</label>
                                <div class="position-relative">
                                    <input type="text" class="form-control" name="duplicate_mfg_no" id="duplicateMfg"
                                        placeholder="พิมพ์ W26... แล้วเลือก (manual ได้)" autocomplete="off">
                                    <div id="duplicateMfgSuggest" class="duplicate-mfg-suggest d-none"></div>
                                </div>
                                <div class="form-text">รองรับหลายค่า คั่นด้วย ,</div>
                                <input type="hidden" id="duplicatePartsId" value="">
                                <input type="hidden" id="duplicateSoNumber" value="">
                                <input type="hidden" id="duplicateCustomerId" value="">
                            </div>
                        </div>

                        @if ($isSales8User)
                            <input type="hidden" name="duplicate_sell_by_line" id="duplicateSellByLine" value="1">
                        @else
                            <div class="mt-3" id="duplicateSellByLineChoiceWrap">
                                <label class="form-label fw-semibold">ขายแบบระบุเส้น</label>
                                <div class="d-flex gap-3 flex-wrap">
                                    <label class="form-check mb-0">
                                        <input class="form-check-input duplicateSellByLineChoice" type="radio"
                                            name="duplicate_sell_by_line" value="1">
                                        <span class="form-check-label">ใช่</span>
                                    </label>
                                    <label class="form-check mb-0">
                                        <input class="form-check-input duplicateSellByLineChoice" type="radio"
                                            name="duplicate_sell_by_line" value="0">
                                        <span class="form-check-label">ไม่ใช่</span>
                                    </label>
                                </div>
                            </div>
                        @endif

                        <div class="row g-3 mt-1">
                            <div class="col-md-6" id="duplicateLineQtyWrap">
                                <label class="form-label fw-semibold"><span id="duplicateLineQtyLabel">{{ $isSales8User ? 'จำนวนชิ้น' : 'Qty ระบุเส้น' }}</span>
                                    <span class="text-danger">*</span></label>
                                <input type="number" min="1" step="1" class="form-control"
                                    name="duplicate_line_qty" id="duplicateLineQty">
                            </div>
                            <div class="col-md-6" id="duplicateQtyWrap">
                                <label class="form-label fw-semibold">จำนวน (KG) <span
                                        class="text-danger">*</span></label>
                                <input type="number" step="0.001" min="0" class="form-control"
                                    name="duplicate_qty" id="duplicateQty" placeholder="เช่น 1000">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label fw-semibold">สถานที่ส่ง <span
                                        class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="duplicate_address"
                                    id="duplicateAddress" placeholder="สถานที่ส่ง" required>
                            </div>
                        </div>

                        <hr class="my-3">

                        <div class="mt-2">
                            <label class="form-label fw-semibold">เบอร์โทร</label>
                            <input type="text" class="form-control" name="duplicate_tel" id="duplicateTel">
                        </div>

                        <div class="mt-3">
                            <label class="form-label fw-semibold">หมายเหตุ (text)</label>
                            <textarea class="form-control" name="duplicate_remark" id="duplicateRemark" rows="3"></textarea>
                        </div>

                        <div class="mt-2">
                            <label class="form-label fw-semibold">เอกสารแนบ</label>
                            <div class="row g-1">
                                @foreach ($docMap as $code => $name)
                                    <div class="col-md-4 col-sm-6">
                                        <label class="form-check small mb-1">
                                            <input class="form-check-input duplicateDocCheck" type="checkbox"
                                                name="duplicate_attach_docs[]" value="{{ e($code) }}">
                                            <span class="form-check-label">{{ $name }}</span>
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                            <input type="text" class="form-control mt-1" name="duplicate_attach_docs_other"
                                id="duplicateAttachDocsOther" placeholder="เอกสารอื่นๆ">
                        </div>

                        <div class="mt-2">
                            <label class="form-label fw-semibold">Revision</label>
                            <input type="number" class="form-control" name="duplicate_revision_number"
                                id="duplicateRevisionNumber" min="0" step="1" value="0">
                            <div class="form-text">เลือก Revision ที่ต้องการบันทึกเอง ระบบจะไม่คำนวณจากเวลาแล้ว</div>
                        </div>

                        <div class="alert alert-info small mb-0 mt-3">
                            ระบบจะสร้างรายการใหม่ใน delivery plan เท่านั้น โดยไม่ copy หรือเปลี่ยน truck assignment
                            ของรายการเดิม
                        </div>

                        <label class="form-check d-flex align-items-center gap-2 mt-3 mb-0">
                            <input class="form-check-input mt-0" type="checkbox" name="duplicate_continue_same_plan"
                                id="duplicateContinueSamePlan" value="1">
                            <span class="form-check-label">บันทึกแล้วเพิ่มรายการจากแผนเดิมต่อ</span>
                        </label>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button>
                        <button type="submit" class="btn btn-success"
                            id="btnConfirmDuplicate">บันทึกเป็นรายการใหม่</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Void Modal --}}
        <div class="modal fade" id="voidModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="POST" id="voidForm">
                    @csrf

                    <div class="modal-header">
                        <h5 class="modal-title">ยกเลิก PlanDelivery</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-2">
                            <div class="small text-muted">รายการ</div>
                            <div class="fw-semibold" id="voidInfo">-</div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-semibold">เหตุผลยกเลิก</label>
                            <textarea class="form-control" name="remark_void" id="remarkVoid" rows="3" placeholder="กรอกเหตุผล" required></textarea>
                        </div>

                        <div class="alert alert-warning small mb-0">
                            ยกเลิกได้เฉพาะรายการก่อนถึงวันส่งสินค้า 3 วันเท่านั้น
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button>
                        <button type="submit" class="btn btn-danger" id="btnConfirmVoid">Void</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Unassign Truck Modal --}}
        <div class="modal fade" id="unassignTruckModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="POST" id="unassignTruckForm">
                    @csrf
                    <input type="hidden" name="return_url" id="unassignReturnUrl" value="{{ url()->full() }}">
                    <div id="unassignTruckOrdIds"></div>

                    <div class="modal-header">
                        <h5 class="modal-title">ยกเลิกรถ</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-2">
                            <div class="small text-muted">รายการ</div>
                            <div class="fw-semibold" id="unassignTruckInfo">-</div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-semibold">เหตุผลยกเลิกรถ</label>
                            <textarea class="form-control" name="remark_unassign" id="remarkUnassign" rows="3" placeholder="กรอกเหตุผล"
                                required></textarea>
                        </div>

                        <div class="alert alert-warning small mb-0">
                            จะลบการ assign รถของงานชิ้นนี้ และเปลี่ยนสถานะกลับเป็น NEW
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button>
                        <button type="submit" class="btn btn-warning"
                            id="btnConfirmUnassignTruck">ยืนยันยกเลิกรถ</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Truck Modal --}}
        <div class="modal fade" id="truckModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <form method="POST" id="truckAssignForm"
                        action="{{ route('dp.inquiry.truck.assign', ['ordId' => 0]) }}">
                        @csrf

                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title mb-1">เลือกรถ</h5>
                                <div class="small text-muted">
                                    SO: <span id="tmSoText" class="fw-semibold">-</span>
                                    <span class="mx-2">|</span>
                                    วันที่ส่งสินค้า: <span id="tmShipDateText" class="fw-semibold">-</span>
                                </div>
                                <div class="small text-muted">
                                    สถานที่ส่ง: <span id="tmShipToText" class="fw-semibold">-</span>
                                </div>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>

                        <div class="modal-body truck-modal-body">
                            <input type="hidden" name="ord_id" id="tmOrdId">
                            <input type="hidden" name="so_number" id="tmSoHidden">
                            <input type="hidden" name="ship_posted_at" id="tmShipDateHidden">
                            <input type="hidden" name="truck_id" id="tmTruckId">
                            <input type="hidden" name="return_url" id="tmReturnUrl" value="{{ $cleanReturnUrl }}">
                            <input type="hidden" name="manual_plate_no" id="tmManualPlateHidden">
                            <input type="hidden" name="manual_driver_name" id="tmManualDriverHidden">
                            <input type="hidden" name="manual_driver_phone" id="tmManualPhoneHidden">
                            <input type="hidden" name="manual_max_load" id="tmManualMaxLoadHidden">
                            <input type="hidden" name="manual_car_length" id="tmManualLengthHidden">
                            <input type="hidden" name="manual_remark" id="tmManualRemarkHidden">

                            <div class="dp-dispatch-panel mb-3">
                                <div class="d-flex flex-wrap align-items-end gap-3">
                                    <div class="flex-grow-1">
                                        <label class="form-label small fw-semibold mb-2">รูปแบบการจัดส่ง</label>
                                        <div class="btn-group btn-group-sm dispatch-mode-group" role="group"
                                            aria-label="dispatch mode">
                                            <input type="radio" class="btn-check" name="tm_dispatch_mode"
                                                id="tmDispatchTruckMode" value="TRUCK" checked>
                                            <label class="btn btn-outline-primary" for="tmDispatchTruckMode">
                                                จัดรถปกติ
                                            </label>

                                            <input type="radio" class="btn-check" name="tm_dispatch_mode"
                                                id="tmDispatchSpecialMode" value="SPECIAL">
                                            <label class="btn btn-outline-warning" for="tmDispatchSpecialMode">
                                                ช่องทางพิเศษ
                                            </label>
                                        </div>
                                    </div>

                                    <div class="dp-trip-control" id="tmTripControl">
                                        <label class="form-label small fw-semibold mb-2"
                                            for="tmTripNo">เที่ยวที่</label>
                                        <div class="input-group input-group-sm">
                                            <button class="btn btn-outline-secondary" type="button"
                                                id="tmTripMinus">-</button>
                                            <input type="number" class="form-control text-center" name="trip_no"
                                                id="tmTripNo" min="1" max="99" step="1"
                                                value="1">
                                            <button class="btn btn-outline-secondary" type="button"
                                                id="tmTripPlus">+</button>
                                        </div>
                                    </div>
                                </div>

                                <div class="dp-special-panel mt-3 d-none" id="tmSpecialDispatchPanel">
                                    <div class="row g-2">
                                        <div class="col-md-5">
                                            <label class="form-label small fw-semibold mb-1"
                                                for="tmSpecialDispatchType">ประเภทช่องทางพิเศษ</label>
                                            <select class="form-select form-select-sm" name="dispatch_type"
                                                id="tmSpecialDispatchType">
                                                @foreach ($specialDispatchTypes as $type => $label)
                                                    <option value="{{ $type }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-7">
                                            <label class="form-label small fw-semibold mb-1"
                                                for="tmSpecialDispatchRemark">หมายเหตุ</label>
                                            <textarea class="form-control form-control-sm" name="remark" id="tmSpecialDispatchRemark" rows="2"
                                                placeholder="ระบุรายละเอียดเพิ่มเติม"></textarea>
                                        </div>
                                    </div>
                                    <div class="form-text">
                                        ช่องทางพิเศษจะไม่คิด capacity รถ และจะลบ assignment รถเดิมของรายการนี้ออก
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3" id="tmLineSelectPanel">
                                <div class="d-flex gap-2 mb-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                        id="tmCheckAll">เลือกทั้งหมด</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                        id="tmUncheckAll">ล้างเลือก</button>
                                </div>

                                <div class="table-responsive truck-lines-wrap">
                                    <table class="table table-sm table-bordered align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>เลือก</th>
                                                <th>เลข MFG</th>
                                                <th>Part</th>
                                                <th>Part Desc</th>
                                                <th>ระบุเส้น</th>
                                                <th>สถานที่ส่ง</th>
                                                <th class="text-end">ขึ้นรถแล้ว</th>
                                                <th class="text-end">คงเหลือ</th>
                                                <th class="text-end">น้ำหนักขึ้นรถ (kg)</th>
                                            </tr>
                                        </thead>
                                        <tbody id="tmLineTbody"></tbody>
                                        <tfoot>
                                            <tr>
                                                <th colspan="7" class="text-end">รวมที่เลือก</th>
                                                <th class="text-end" id="tmTotalQty">0</th>
                                                <th></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>

                            <div class="row g-3" id="tmTruckPickPanel">
                                <div class="col-lg-7">
                                    <div class="card h-100">
                                        <div class="card-header py-2 fw-semibold">รถในระบบ</div>

                                        <div class="card-body truck-system-body">
                                            <div class="mb-2">
                                                <input type="text" id="tmTruckSearch"
                                                    class="form-control form-control-sm"
                                                    placeholder="ค้นหาทะเบียน / คนขับ / หมายเหตุ / ลูกค้า / ประเภทงาน">
                                            </div>

                                            <div class="alert alert-secondary py-2 px-3 small mb-2">
                                                น้ำหนักที่เลือก: <span class="fw-bold" id="tmSelectedWeight">0</span>
                                                <span class="mx-2">|</span>
                                                รถจะเหลือ: <span class="fw-bold" id="tmTruckRemainAfter">-</span>
                                            </div>

                                            <div id="tmTruckPickedInfo"
                                                class="alert alert-info py-2 px-3 small mb-2 d-none">
                                                <div><span class="fw-semibold">รถที่เลือก:</span> <span
                                                        id="tmPickedPlate">-</span></div>
                                                <div><span class="fw-semibold">งานที่บรรทุกอยู่:</span> <span
                                                        id="tmPickedJobs">-</span></div>
                                            </div>

                                            <div class="truck-master-area">
                                                <div class="table-responsive truck-master-wrap">
                                                    <table
                                                        class="table table-sm table-hover align-middle mb-0 truck-master-table">
                                                        <colgroup>
                                                            <col style="width:70px;">
                                                            <col style="width:180px;">
                                                            <col style="width:90px;">
                                                            <col style="width:90px;">
                                                            <col style="width:90px;">
                                                            <col style="width:80px;">
                                                            <col style="width:240px;">
                                                            <col style="width:260px;">
                                                        </colgroup>
                                                        <thead class="table-light">
                                                            <tr>
                                                                <th class="text-center">เลือก</th>
                                                                <th>ทะเบียน / คนขับ</th>
                                                                <th class="text-end">Max</th>
                                                                <th class="text-end">Current</th>
                                                                <th class="text-end">Remain</th>
                                                                <th class="text-end">Length</th>
                                                                <th>ลูกค้า / ประเภทงาน</th>
                                                                <th>SO / MFG / หมายเหตุ</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody id="tmTruckTableBody">
                                                            @foreach ($trucks as $t)
                                                                @php
                                                                    $isManualTemp =
                                                                        ($t->truck_pick_type ?? '') === 'MANUAL_TEMP';
                                                                    $rowKey = $t->row_key ?? '';
                                                                    $jobSummary = collect($t->job_summary ?? []);
                                                                    $jobSummaryText = $jobSummary
                                                                        ->map(function ($x) use ($fmtWeight) {
                                                                            return trim(
                                                                                ($x['customer_name'] ?? '-') .
                                                                                    ' | ' .
                                                                                    ($x['job_type'] ?? '-') .
                                                                                    ' (' .
                                                                                    $fmtWeight(
                                                                                        $x['assigned_weight'] ?? 0,
                                                                                    ) .
                                                                                    ')',
                                                                            );
                                                                        })
                                                                        ->implode(' || ');
                                                                    $soSummaryText = trim(
                                                                        (string) ($t->so_summary_text ?? ''),
                                                                    );
                                                                    $mfgSummaryText = trim(
                                                                        (string) ($t->mfg_summary_text ?? ''),
                                                                    );
                                                                    $remarkText = trim((string) ($t->remark ?? ''));
                                                                    $capacityUnlimited =
                                                                        !empty($t->capacity_unlimited) ||
                                                                        (float) ($t->max_load ?? 0) <= 0;
                                                                @endphp

                                                                <tr class="tmTruckRow"
                                                                    data-row-key="{{ e($rowKey) }}"
                                                                    data-truck-id="{{ e($t->truck_id ?? '') }}"
                                                                    data-manual-plate="{{ e($t->manual_plate_no ?? '') }}"
                                                                    data-job-summary="{{ e($jobSummaryText) }}"
                                                                    data-search="{{ mb_strtolower(trim(($t->plate_no ?? '') . ' ' . ($t->driver_name ?? '') . ' ' . $remarkText . ' ' . $jobSummaryText . ' ' . $soSummaryText . ' ' . $mfgSummaryText)) }}">
                                                                    <td class="text-center">
                                                                        <input type="radio"
                                                                            class="form-check-input tmTruckRadio"
                                                                            name="truck_pick_mode"
                                                                            value="{{ $isManualTemp ? 'MANUAL_TEMP' : 'MASTER' }}"
                                                                            data-row-key="{{ e($rowKey) }}"
                                                                            data-truck-id="{{ e($t->truck_id ?? '') }}"
                                                                            data-manual-plate="{{ e($t->manual_plate_no ?? '') }}"
                                                                            data-plate="{{ e($t->plate_no ?? '') }}"
                                                                            data-driver-name="{{ e($t->driver_name ?? '') }}"
                                                                            data-driver-phone="{{ e($t->driver_phone ?? '') }}"
                                                                            data-max="{{ e((float) ($t->max_load ?? 0)) }}"
                                                                            data-current="{{ e((float) ($t->current_load ?? 0)) }}"
                                                                            data-remaining="{{ e((float) ($t->remaining_capacity ?? 0)) }}"
                                                                            data-capacity-unlimited="{{ $capacityUnlimited ? '1' : '0' }}"
                                                                            data-car-length="{{ e($t->car_length ?? '') }}"
                                                                            data-remark="{{ e($remarkText) }}"
                                                                            data-job-summary="{{ e($jobSummaryText) }}"
                                                                            {{ !$capacityUnlimited && (float) ($t->remaining_capacity ?? 0) <= 0 ? 'disabled' : '' }}>
                                                                    </td>

                                                                    <td>
                                                                        <div class="fw-semibold">
                                                                            {{ $t->plate_no ?? '-' }}
                                                                            @if ($isManualTemp)
                                                                                <span
                                                                                    class="badge bg-info-subtle text-info-emphasis border ms-1">รถนอกวันนี้</span>
                                                                            @else
                                                                                <span
                                                                                    class="badge bg-light text-dark border ms-1">ในระบบ</span>
                                                                            @endif
                                                                        </div>
                                                                        <div class="small text-muted">
                                                                            {{ $t->driver_name ?? '-' }}</div>
                                                                        <div class="small text-muted">
                                                                            {{ $t->driver_phone ?? '-' }}</div>
                                                                    </td>

                                                                    <td class="text-end">
                                                                        {{ $capacityUnlimited ? 'ไม่ระบุ' : $fmtWeight($t->max_load ?? 0) }}
                                                                    </td>

                                                                    <td class="text-end">
                                                                        {{ $fmtWeight($t->current_load ?? 0) }}
                                                                    </td>

                                                                    @php
                                                                        $tooltipLines = [];

                                                                        if (!empty($t->job_summary)) {
                                                                            foreach ($t->job_summary as $job) {
                                                                                $tooltipLines[] =
                                                                                    ($job['so_number'] ??
                                                                                    $soSummaryText ?:
                                                                                        '-') .
                                                                                    ' || ' .
                                                                                    ($job['mfg_no'] ??
                                                                                    $mfgSummaryText ?:
                                                                                        '-') .
                                                                                    ' || ' .
                                                                                    $fmtWeight(
                                                                                        $job['assigned_weight'] ?? 0,
                                                                                    ) .
                                                                                    ' || ' .
                                                                                    ($job['address'] ?? '-');
                                                                            }
                                                                        }

                                                                        if (empty($tooltipLines)) {
                                                                            $tooltipLines[] = 'ยังไม่มีรายการ';
                                                                        }

                                                                        $remainTooltip = implode("\n", $tooltipLines);
                                                                    @endphp

                                                                    <td class="text-end {{ !$capacityUnlimited && (float) ($t->remaining_capacity ?? 0) < 0 ? 'text-danger fw-bold' : 'fw-semibold' }}"
                                                                        data-bs-toggle="tooltip" data-bs-placement="top"
                                                                        data-bs-html="false"
                                                                        data-bs-custom-class="truck-remain-tooltip"
                                                                        title="{{ $remainTooltip }}">
                                                                        {{ $capacityUnlimited ? 'ตามน้ำหนักที่กรอก' : $fmtWeight($t->remaining_capacity ?? 0) }}
                                                                    </td>

                                                                    <td class="text-end">
                                                                        {{ $t->car_length ? number_format((float) $t->car_length, 0) : '-' }}
                                                                    </td>

                                                                    <td class="small">
                                                                        @forelse(($t->job_summary ?? []) as $job)
                                                                            <div class="mb-1">
                                                                                <span
                                                                                    class="fw-semibold">{{ $job['customer_name'] ?? '-' }}</span>
                                                                                <span class="text-muted">|
                                                                                    {{ $job['job_type'] ?? '-' }}</span>
                                                                                <span
                                                                                    class="text-primary">({{ $fmtWeight($job['assigned_weight'] ?? 0) }})</span>
                                                                            </div>
                                                                        @empty
                                                                            <span class="text-muted">ยังไม่มีรายการ</span>
                                                                        @endforelse
                                                                    </td>

                                                                    <td class="small">
                                                                        @if ($soSummaryText !== '')
                                                                            <div><span class="fw-semibold">SO:</span>
                                                                                {{ $soSummaryText }}</div>
                                                                        @endif
                                                                        @if ($mfgSummaryText !== '')
                                                                            <div><span class="fw-semibold">MFG:</span>
                                                                                {{ $mfgSummaryText }}</div>
                                                                        @endif
                                                                        <div><span class="fw-semibold">Remark:</span>
                                                                            {{ $remarkText !== '' ? $remarkText : '-' }}
                                                                        </div>
                                                                    </td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>

                                            <div class="form-check mt-3 flex-shrink-0">
                                                <input class="form-check-input" type="checkbox" id="tmReplaceMode"
                                                    name="replace_mode" value="1">
                                                <label class="form-check-label" for="tmReplaceMode">
                                                    เปลี่ยนรถใหม่สำหรับรายการที่เลือก
                                                </label>
                                                <div class="form-text">
                                                    เมื่อติ๊ก ระบบจะลบ assignment เดิมของรายการที่เลือกก่อน
                                                    แล้วค่อยจัดรถใหม่
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-lg-5">
                                    <div class="card h-100">
                                        <div class="card-header py-2 fw-semibold">รถนอก</div>
                                        <div class="card-body tm-manual-staff-body">
                                            <div class="tm-manual-section">
                                                <div class="form-check mb-3">
                                                    <input class="form-check-input" type="radio"
                                                        name="truck_pick_mode" id="tmManualMode" value="MANUAL">
                                                    <label class="form-check-label" for="tmManualMode">
                                                        ใช้ข้อมูลรถนอกนี้
                                                    </label>
                                                </div>
                                                <div class="row g-2">
                                                    <div class="col-md-6">
                                                        <label class="form-label small mb-1">ทะเบียนรถ</label>
                                                        <input type="text" class="form-control form-control-sm"
                                                            id="tmManualPlate">
                                                    </div>

                                                    <div class="col-md-6">
                                                        <label class="form-label small mb-1">คนขับ</label>
                                                        <input type="text" class="form-control form-control-sm"
                                                            id="tmManualDriver">
                                                    </div>

                                                    <div class="col-md-6">
                                                        <label class="form-label small mb-1">เบอร์โทร</label>
                                                        <input type="text" class="form-control form-control-sm"
                                                            id="tmManualPhone">
                                                    </div>

                                                    <div class="col-md-6">
                                                        <label class="form-label small mb-1">Max Load (kg)</label>
                                                        <input type="number" step="1"
                                                            class="form-control form-control-sm" id="tmManualMaxLoad"
                                                            placeholder="เช่น 25000">
                                                    </div>

                                                    <div class="col-md-6">
                                                        <label class="form-label small mb-1">ความยาวรถ</label>
                                                        <input type="number" step="1"
                                                            class="form-control form-control-sm" id="tmManualLength">
                                                    </div>

                                                    <div class="col-md-6">
                                                        <label class="form-label small mb-1">หมายเหตุ</label>
                                                        <input type="text" class="form-control form-control-sm"
                                                            id="tmManualRemark"
                                                            placeholder="เช่น รถนอก / เปิดข้าง / ตู้">
                                                    </div>
                                                </div>

                                                <div class="alert alert-info small mt-3 mb-2">
                                                    ถ้าใช้ทะเบียนเดิมในวันส่งเดียวกัน ระบบจะถือเป็นรถนอกคันเดิม และจะนำ
                                                    capacity
                                                    ที่เหลือมาใช้ต่อกับ SO อื่นได้
                                                </div>

                                            </div>

                                            <div class="tm-staff-section mt-3">
                                                <div class="fw-semibold mb-2">พนักงานประจำรถ</div>

                                                <div class="row g-2 tm-staff-grid">
                                                    <div class="col-md-12 tm-staff-field tm-staff-driver">
                                                        <label class="form-label small mb-1">คนขับ</label>
                                                        <select class="form-select form-select-sm"
                                                            name="driver_staff_id" id="tmDriverStaffId">
                                                            <option value="">-- เลือกคนขับ --</option>
                                                        </select>
                                                    </div>

                                                    <div class="col-md-12 tm-staff-field">
                                                        <label class="form-label small mb-1">เด็กรถ 1</label>
                                                        <select class="form-select form-select-sm"
                                                            name="helper1_staff_id" id="tmHelper1StaffId">
                                                            <option value="">-- เลือกเด็กรถ 1 --</option>
                                                        </select>
                                                    </div>

                                                    <div class="col-md-12 tm-staff-field">
                                                        <label class="form-label small mb-1">เด็กรถ 2</label>
                                                        <select class="form-select form-select-sm"
                                                            name="helper2_staff_id" id="tmHelper2StaffId">
                                                            <option value="">-- เลือกเด็กรถ 2 --</option>
                                                        </select>
                                                    </div>

                                                    <div class="col-md-12 tm-staff-field">
                                                        <label class="form-label small mb-1">เด็กรถ 3</label>
                                                        <select class="form-select form-select-sm"
                                                            name="helper3_staff_id" id="tmHelper3StaffId">
                                                            <option value="">-- เลือกเด็กรถ 3 --</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-12 tm-staff-field">
                                                        <label class="form-label small mb-1">เด็กรถ 4</label>
                                                        <select class="form-select form-select-sm"
                                                            name="helper4_staff_id" id="tmHelper4StaffId">
                                                            <option value="">-- เลือกเด็กรถ 4 --</option>
                                                        </select>
                                                    </div>

                                                    <div class="col-md-12 tm-staff-field">
                                                        <label class="form-label small mb-1">เด็กรถ 5</label>
                                                        <select class="form-select form-select-sm"
                                                            name="helper5_staff_id" id="tmHelper5StaffId">
                                                            <option value="">-- เลือกเด็กรถ 5 --</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>

                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="modal-footer">
                            <div class="me-auto small text-muted" id="tmFooterHelp">
                                ระบบจะบันทึกตามน้ำหนักที่รถยังรับได้จริง และถ้าเต็มก่อนจะบันทึกเฉพาะบางส่วน
                            </div>
                            <button type="button" class="btn btn-outline-secondary"
                                data-bs-dismiss="modal">ปิด</button>
                            <button type="submit" class="btn btn-primary" id="btnTruckAssignSubmit">บันทึก</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>


        <div class="modal fade" id="sendPlanMailModal" tabindex="-1" aria-labelledby="sendPlanMailModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <form id="formSendMail" method="POST" action="{{ route('dp.inquiry.send-plan-mail') }}"
                    class="modal-content">
                    @csrf

                    <div class="modal-header">
                        <h5 class="modal-title" id="sendPlanMailModalLabel">ส่งเมลแจ้งแผนส่งมอบ</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"
                            aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">วันที่ส่งสินค้า</label>
                                <input type="date" name="ship_posted_at" class="form-control"
                                    value="{{ old('ship_posted_at', request('ship_posted_at')) }}" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Revision</label>
                                <input type="number" name="revision_number" class="form-control"
                                    value="{{ old('revision_number', request('revision_number', 0)) }}" min="0"
                                    required>

                            </div>

                            <div class="col-md-12">
                                <label class="form-label">Remark</label>
                                <textarea name="remark" class="form-control" rows="2" placeholder="หมายเหตุเพิ่มเติม">{{ old('remark') }}</textarea>
                            </div>

                            <div class="col-md-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="1" id="forceSend"
                                        name="force_send">
                                    <label class="form-check-label" for="forceSend">
                                        Force Send (ส่งซ้ำแม้เคยส่งแล้ว)
                                    </label>
                                </div>
                            </div>
                        </div>

                        @if ($errors->has('mail') || $errors->has('ship_posted_at') || $errors->has('revision_number'))
                            <div class="alert alert-danger mt-3 mb-0">
                                @foreach ($errors->all() as $error)
                                    <div>{{ $error }}</div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">ปิด</button>

                        <button type="submit" id="btnSendMail" class="btn btn-primary">
                            <span class="btn-text">
                                <i class="fas fa-paper-plane me-1"></i> ส่งเมล
                            </span>

                            <span class="btn-loading d-none">
                                <span class="spinner-border spinner-border-sm me-1" role="status"></span>
                                กำลังส่ง...
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>
@endsection

@section('styles')
    <link rel="stylesheet" href="{{ asset('css/formdp/inquiry.css') }}?v=20260609_action_compact_v1">
@endsection

@push('scripts')
    <script>
        window.DP_AC_SOURCES = {
            so: @json($autocompleteSources['so'] ?? []),
            customer: @json($autocompleteSources['customer'] ?? []),
            shipto: @json($autocompleteSources['shipto'] ?? []),
            divsales: @json(array_values($divLabel ?? [])),
        };
        window.DP_INQUIRY = {
            docMap: @json($docMap),
            piecePartNumbers: @json(\App\Support\FormDP\PieceSalePolicy::PART_NUMBERS),
            duplicateContinue: @json(session('dp_duplicate_continue')),
            routes: {
                history: @json(route('dp.inquiry.history', ['ordId' => '__ID__'])),
                historyDb: @json(route('dp.history.db', ['ord_id' => '__ID__'])),
                void: @json(route('dp.void', ['ordId' => '__ID__'])),
                postpone: @json(route('dp.inquiry.postpone', ['ordId' => '__ID__'])),
                bulkPostpone: @json(route('dp.inquiry.bulk-postpone')),
                duplicate: @json(route('dp.inquiry.duplicate', ['ordId' => '__ID__'])),
                mfgLookup: @json(route('dp.mfgLookup')),
                specialDispatch: @json(route('dp.inquiry.special-dispatch', ['ordId' => '__ID__'])),
                truckAssign: @json(route('dp.inquiry.truck.assign', ['ordId' => '__ID__'])),
                truckUnassign: @json(route('dp.inquiry.truck.unassign', ['ordId' => '__ID__'])),
                truckUnassignBulk: @json(route('dp.inquiry.truck.unassign.bulk')),
                logisticsSummary: @json($showInquiryBulkTruck ? route('dp.dashboard.logistics-summary') : null),
                base: @json(route('dp.inquiry')),
                truckCapacity: @json(route('dp.truck.capacity')),
                truckStaffOptions: @json(route('dp.truck.staff.options')),
                truckStaffDefaults: @json(route('dp.truck.staff.defaults')),
            }
        };

        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('erpSyncSaleOrderForm');
            if (!form) return;

            form.addEventListener('submit', function(event) {
                event.preventDefault();

                if (!window.Swal) {
                    if (confirm('ดึงข้อมูล Sale/Work Order จาก ERP ตอนนี้เลยหรือไม่?')) {
                        form.submit();
                    }
                    return;
                }

                Swal.fire({
                    title: 'ดึงข้อมูล ERP?',
                    text: 'ระบบจะดึงข้อมูล Sale Order และ Work Order จาก ERP ตอนนี้',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'ดึงข้อมูล',
                    cancelButtonText: 'ยกเลิก',
                    confirmButtonColor: '#f59e0b',
                    cancelButtonColor: '#64748b',
                    reverseButtons: true,
                    focusCancel: true
                }).then(function(result) {
                    if (result.isConfirmed) {
                        Swal.fire({
                            title: 'กำลังดึงข้อมูล',
                            text: 'กรุณารอสักครู่',
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                            didOpen: function() {
                                Swal.showLoading();
                            }
                        });

                        form.submit();
                    }
                });
            });
        });
    </script>

    <script src="{{ asset('js/formdp/inquiry.js') }}?v=20260629_bulk_unassign_v1"></script>
@endpush
