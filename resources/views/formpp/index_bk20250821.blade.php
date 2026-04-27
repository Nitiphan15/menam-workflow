@extends('layouts.layout')

@section('title', 'ฟอร์มวางแผนการผลิต')
@section('page-title', 'ฟอร์มวางแผนการผลิต')

@section('content')
    @php
        use Illuminate\Support\Str;
        use Illuminate\Support\Carbon;
        //dd($datasets['so']);
        // โหมด Planner?
        $plannerMode = $plannerMode ?? false;

        // กัน null กรณี controller อื่นเรียก
        $form = $form ?? ($ppData->wfForm ?? (null ?? null));
        $ppData = $ppData ?? null;
        $canApprove = $canApprove ?? false;
    @endphp
    <div class="container py-3">
        {{-- Search Card --}}
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('pp.index') }}" class="row g-2 align-items-end" id="searchForm">
                    <div class="col-md-4">
                        <label for="sku" class="form-label fw-semibold">รหัสสินค้า (SKU)</label>
                        <input type="text" id="sku" name="sku" value="{{ $sku }}"
                            class="form-control form-control-lg" placeholder="พิมพ์รหัสสินค้า เช่น FC304HXXXX01000HBXXL"
                            autofocus>
                    </div>
                    <div class="col-md-auto">
                        <button type="submit" class="btn btn-primary btn-lg" id="btnSearch">
                            <span class="me-1"><i class="bi bi-search"></i></span> ค้นหา
                        </button>
                    </div>
                    <div class="col-md-auto">
                        <a href="{{ route('pp.index') }}" class="btn btn-outline-secondary btn-lg">ล้างค่า</a>
                    </div>

                    <!--  <div class="row g-2 align-items-center mb-2">
                            <div class="col-auto">
                                <label class="form-label fw-semibold mb-0">แหล่งข้อมูล</label>
                            </div>
                            <div class="col-auto form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" id="srcWire" name="src[]" value="wire"
                                form="searchForm"
                                    {{ empty($selectedSrc) || in_array('wire', $selectedSrc) ? 'checked' : '' }}>
                                  <label class="form-check-label" for="srcWire">Wire</label>
                             </div>
                            <div class="col-auto form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" id="srcPlus" name="src[]" value="plus"
                                form="searchForm"
                                    {{ empty($selectedSrc) || in_array('plus', $selectedSrc) ? 'checked' : '' }}>
                            <label class="form-check-label" for="srcPlus">Plus</label>
                        </div>
                     </div> -->
                </form>
                <small class="text-muted d-block mt-2">
                    ใส่รหัสสินค้าเพียงช่องเดียว แล้วระบบจะแสดงข้อมูลทั้งหมดอัตโนมัติ
                </small>

            </div>
        </div>

        <form method="POST" id="requestForm" action="{{ route('pp.store') }}" enctype="multipart/form-data">
            @csrf
            @if ($result)
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="customer" class="form-label fw-semibold mb-1">ลูกค้า</label>
                        <div class="position-relative">
                            <select id="customer" name="customer"
                                class="form-select form-select-lg rounded-pill shadow-sm pe-5" form="searchForm"
                                onchange="this.form.submit()">
                                <option value="">— ทุกลูกค้า —</option>
                                @foreach ($customers as $cus)
                                    <option value="{{ $cus }}" {{ $selectedCustomer === $cus ? 'selected' : '' }}>
                                        {{ $cus }}</option>
                                @endforeach
                            </select>

                            <button type="button" id="clearCustomer"
                                class="btn btn-sm btn-link position-absolute top-50 end-0 translate-middle-y me-3">ล้าง</button>

                        </div>
                        @if ($selectedCustomer)
                            <div class="form-text mt-1">
                                กำลังกรองลูกค้า: <strong>{{ $selectedCustomer }}</strong>
                                · <a href="{{ route('pp.index') }}">ล้างตัวกรองทั้งหมด</a>
                            </div>
                        @endif
                    </div>
                </div>
                <input type="hidden" name="customer" value="{{ $selectedCustomer }}">

                {{-- Summary Badges --}}
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="text-muted small">รหัสสินค้า</div>
                                <div id="part_no" name="part_no" class="fs-5 fw-semibold">{{ $result['sku'] ?? '-' }}
                                </div>
                                <input type="hidden" name="part_no" value="{{ $result['sku'] ?? '' }}">
                                <div class="text">{{ $result['name'] ?? '-' }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="text-muted small">ประเภท / หมวด / กลุ่ม</div>
                                <div class="fw-semibold">{{ $result['type'] ?? '-' }}</div>
                                <div class="text-truncate">{{ $result['category'] ?? '-' }}</div>
                                <div class="text-truncate">{{ $result['group'] ?? '-' }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="text-muted small">อัปเดตล่าสุด (FG)</div>
                                <div class="fs-5 fw-semibold">
                                    @php
                                        $d = $result['last_date'] ?? null;
                                        $dText = $d ? Carbon::parse($d)->format('d/m/Y') : '-';
                                    @endphp
                                    {{ $dText }}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="text-muted small">ต้องผลิตเพิ่ม</div>
                                <div class="fs-4 fw-bold text-primary">
                                    {{ number_format($result['need_qty'] ?? 0, 2) }}
                                </div>
                            </div>
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
                                        <td id="sales_fg" name="sales_fg" class="text-center fw-semibold">
                                            {{ number_format($result['fg_qty'] ?? 0, 2) }}
                                        </td>
                                        <input type="hidden" name="sales_fg" value="{{ $result['fg_qty'] ?? 0 }}">

                                        <td id="planner_fg" name="planner_fg" class="text-center fw-semibold">0
                                        </td>
                                        <input type="hidden" name="planner_fg" value="0">
                                        <td class="text-muted">จาก CPA 7 (Wire + Plus)</td>
                                    </tr>
                                    <tr>
                                        <td class="text-center">2</td>
                                        <td>จำนวน WIP (สินค้าที่กำลังผลิต)</td>
                                        <td id="sales_wip" name="sales_wip" class="text-center fw-semibold">
                                            {{ number_format($result['wip_qty'] ?? 0, 2) }}
                                        </td>
                                        <input type="hidden" name="sales_wip" value="{{ $result['wip_qty'] ?? 0 }}">

                                        <td id="planner_wip" name="planner_wip" class="text-center fw-semibold">
                                            0
                                        </td>
                                        <input type="hidden" name="planner_wip" value="0">

                                        <td class="text-muted">จาก CPA 30 (Wire + Plus)</td>
                                    </tr>
                                    <tr>
                                        <td class="text-center">3</td>
                                        <td>จำนวนใบคำสั่งที่เปิดแล้ว/จองแผนผลิต</td>
                                        <td id="sales_mfg" name="sales_mfg" class="text-center fw-semibold">
                                            {{ number_format($result['booked_qty'] ?? 0, 2) }}
                                        </td>
                                        <input type="hidden" name="sales_mfg" value="{{ $result['booked_qty'] ?? 0 }}">

                                        <td id="planner_mfg" name="planner_mfg" class="text-center fw-semibold">
                                            0
                                        </td>
                                        <input type="hidden" name="planner_mfg" value="0">
                                        <td class="text-muted">จาก ManuCost</td>
                                    </tr>
                                    <tr>
                                        <td class="text-center">4</td>
                                        <td>จำนวนยอดค้างส่งทุก Sale Order</td>
                                        <td id="sales_order" name="sales_order" class="text-center fw-semibold">
                                            {{ number_format($result['so_qty'] ?? 0, 2) }}
                                        </td>
                                        <input type="hidden" name="sales_order" value="{{ $result['so_qty'] ?? 0 }}">


                                        <td id="planner_order" name="planner_order" class="text-center fw-semibold">
                                            0
                                        </td>
                                        <input type="hidden" name="planner_order" value="0">
                                        <td class="text-muted">จาก CPA 24 (Wire)</td>
                                    </tr>
                                    <tr class="table-primary">
                                        <td class="text-center">5</td>
                                        <td class="fw-semibold">จำนวนที่ต้องผลิตเพิ่ม</td>
                                        <td id="sales_prod" name="sales_prod" class="text-center fw-bold">
                                            {{ number_format($result['need_qty'] ?? 0, 2) }}
                                        </td>
                                        <input type="hidden" name="sales_prod" value="{{ $result['need_qty'] ?? 0 }}">


                                        <td id="planner_prod" name="planner_prod" class="text-center fw-bold">
                                            0
                                        </td>
                                        <input type="hidden" name="planner_prod" value="0">
                                        <td></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3 d-flex justify-content-start gap-2 px-3 pb-3">
                            <button id="btnSubmit" type="submit" class="btn btn-primary">
                                <span class="spinner-border spinner-border-sm me-1 d-none" id="btnSpinner"></span>
                                <i class="fas fa-paper-plane me-2"></i> บันทึก
                            </button>

                            <button type="button" id="exportBtn" class="btn btn-success">
                                <i class="bi bi-file-earmark-arrow-down me-1"></i> Export CSV
                            </button>
                        </div>


                    </div>
                </div>
        </form>

        @if (!empty($datasets ?? []))
            <ul class="nav nav-tabs mt-3">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-fg"
                        type="button">FG</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-wip"
                        type="button">WIP</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-mnc"
                        type="button">ManuCost</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-so"
                        type="button">Sale Orders</button></li>

            </ul>

            <div class="tab-content border-start border-end border-bottom p-3 shadow-sm bg-white">
                {{-- FG --}}
                <div class="tab-pane fade show active" id="tab-fg">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle">
                            <thead class="table-warning">
                                <tr class="text-center">
                                    <th>#</th>
                                    <th>โรงงาน</th>
                                    <th>รหัส</th>
                                    <th>ชื่อ</th>
                                    <th>ประเภท</th>
                                    <th>หมวด</th>
                                    <th>กลุ่ม</th>
                                    <th>รับ</th>
                                    <th>จ่าย</th>
                                    <th>คงเหลือ</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php $fg = $datasets['fg'] ?? null; @endphp
                                @forelse($fg as $i => $r)
                                    @php

                                        $rowNo = ($fg->firstItem() ?? 1) + ($loop->index ?? 0);
                                        $bal = (float) ($r->balance_qty ?? 0);
                                    @endphp
                                    @if ($r->rm_partnumber === 'รวมทั้งหมด')
                                        <tr class="table-success fw-bold">
                                            <td colspan="7">{{ $r->rm_partnumber ?? null }}</td>
                                            <td class="text-end">{{ number_format($r->receive_qty ?? 0, 2) }}</td>
                                            <td class="text-end">{{ number_format($r->issue_qty ?? 0, 2) }}</td>
                                            <td class="text-end {{ $bal < 0 ? 'text-danger fw-bold' : '' }}">
                                                {{ number_format($bal, 2) }}</td>
                                        </tr>
                                    @else
                                        <tr>
                                            <td>{{ $rowNo }}</td>
                                            <td class="text-center">{{ $r->site }}</td>
                                            <td>{{ $r->rm_partnumber }}</td>
                                            <td>{{ $r->part_desc }}</td>
                                            <td>{{ $r->type_desc ?? '' }}</td>
                                            <td>{{ ($r->cat_no ?? '') . ' ' . ($r->cat_desc ?? '') }}</td>
                                            <td>{{ ($r->grp_no ?? '') . ' ' . ($r->grp_desc ?? '') }}</td>
                                            <td class="text-end">{{ number_format($r->receive_qty ?? 0, 2) }}</td>
                                            <td class="text-end">{{ number_format($r->issue_qty ?? 0, 2) }}</td>
                                            <td class="text-end {{ $bal < 0 ? 'text-danger fw-bold' : '' }}">
                                                {{ number_format($bal, 2) }}</td>
                                        </tr>
                                    @endif


                                @empty
                                    <tr>
                                        <td colspan="9" class="text-center text-muted">ไม่มีข้อมูล FG</td>
                                    </tr>
                                @endforelse

                            </tbody>
                        </table>
                        <div>
                            <div class="text-muted small">ข้อมูลในตาราง FG เป็นการรวมของ Plus และ Wire </div>
                        </div>
                    </div>

                    <div class="mt-2">
                        {{ $fg?->withQueryString()->links() }}
                    </div>
                </div>

                {{-- WIP --}}
                <div class="tab-pane fade" id="tab-wip">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle">
                            <thead class="table-warning">
                                <tr class="text-center">
                                    <th>#</th>
                                    <th>โรงงาน</th>
                                    <th>เลขที่ผลิต</th>
                                    <th>เปิด</th>
                                    <th>กำหนดส่ง</th>
                                    <th>ลูกค้า</th>
                                    <th>สั่งผลิต</th>
                                    <th>เบิก</th>
                                    <th>ของดี</th>
                                    <th>ของเสีย</th>
                                    <th>คืนวัตถุดิบ</th>
                                    <th>Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php $wip = $datasets['wip'] ?? null; @endphp
                                @forelse($wip as $i => $r)
                                    @php

                                        $rowNo = ($wip->firstItem() ?? 1) + ($loop->index ?? 0);
                                        $b = (float) ($r->Balance ?? 0);
                                    @endphp
                                    @if ($r->workordernumber === 'รวมทั้งหมด')
                                        <tr class="table-success fw-bold">
                                            <td colspan="6">{{ $r->workordernumber ?? '' }}</td>
                                            <td class="text-end">{{ number_format($r->qty ?? 0, 2) }}</td>
                                            <td class="text-end">{{ number_format($r->issued ?? 0, 2) }}</td>
                                            <td class="text-end">{{ number_format($r->ok ?? 0, 2) }}</td>
                                            <td class="text-end">{{ number_format($r->ng ?? 0, 2) }}</td>
                                            <td class="text-end">{{ number_format($r->return_rm ?? 0, 2) }}</td>
                                            <td class="text-end {{ $b < 0 ? 'text-danger fw-bold' : '' }}">
                                                {{ number_format($b, 2) }}</td>

                                        </tr>
                                    @else
                                        <tr>
                                            <td>{{ $rowNo }}</td>
                                            <td class="text-center">{{ $r->site }}</td>
                                            <td>{{ $r->workordernumber ?? '' }}</td>
                                            <td>{{ Str::of($r->dateopen ?? '')->substr(0, 10) }}</td>
                                            <td>{{ Str::of($r->reqdate ?? '')->substr(0, 10) }}</td>
                                            <td class="text-truncate">{{ $r->customer ?? '' }}</td>
                                            <td class="text-end">{{ number_format($r->qty ?? 0, 2) }}</td>
                                            <td class="text-end">{{ number_format($r->issued ?? 0, 2) }}</td>
                                            <td class="text-end">{{ number_format($r->ok ?? 0, 2) }}</td>
                                            <td class="text-end">{{ number_format($r->ng ?? 0, 2) }}</td>
                                            <td class="text-end">{{ number_format($r->return_rm ?? 0, 2) }}</td>
                                            <td class="text-end {{ $b < 0 ? 'text-danger fw-bold' : '' }}">
                                                {{ number_format($b, 2) }}</td>
                                        </tr>
                                    @endif

                                @empty
                                    <tr>
                                        <td colspan="11" class="text-center text-muted">ไม่มีข้อมูล WIP</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                        <div>
                            <div class="text-muted small">ข้อมูลในตาราง WIP เป็นข้อมูลของปีปฏิทินล่าสุดและรวมจาก
                                Plus
                                และ Wire </div>
                        </div>
                    </div>

                    <div class="mt-2">
                        {{ $wip?->withQueryString()->links() }}
                    </div>
                </div>

                {{-- MNC --}}
                <div class="tab-pane fade" id="tab-mnc">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mnc-table">
                            <thead class="table-warning">
                                <tr class="text-center">
                                    <th>#</th>
                                    <th>โรงงาน</th>
                                    <th>เลขที่คำสั่งผลิต</th>
                                    <th>เปิด</th>
                                    <th>ส่ง</th>
                                    <th>ลูกค้า</th>
                                    <th>สินค้า</th>
                                    <th>รหัสวัตถุดิบ</th>
                                    <th>วัตถุดิบ</th>
                                    <th>จำนวน</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php $mnc = $datasets['mnc'] ?? null; @endphp
                                @forelse($mnc as $i => $r)
                                    @php $rowNo = ($mnc->firstItem() ?? 1) + ($loop->index ?? 0); @endphp
                                    @if ($r->workordernumber === 'รวมทั้งหมด')
                                        <tr class="table-success fw-bold">
                                            <td colspan="9">{{ $r->{'workordernumber'} ?? '' }}</td>
                                            <td class="text-end">{{ number_format($r->quantity ?? 0, 2) }}</td>
                                        </tr>
                                    @else
                                        <tr>
                                            <td>{{ $rowNo }} </td>
                                            <td class="text-center">{{ $r->site }}</td>
                                            <td>{{ $r->{'workordernumber'} ?? '' }}</td>
                                            <td>{{ Str::of($r->{'dateopen'} ?? '')->substr(0, 10) }}
                                            </td>
                                            <td>{{ Str::of($r->{'reqdate'} ?? '')->substr(0, 10) }}
                                            </td>
                                            <td>{{ $r->customer ?? '' }}</td>
                                            <td>{{ $r->description ?? '' }}</td>
                                            <td>{{ $r->{'rm_partnumber'} ?? '' }}</td>
                                            <td>{{ $r->rm_description ?? '' }}
                                            </td>
                                            <td class="text-end">{{ number_format($r->quantity ?? 0, 2) }}</td>
                                        </tr>
                                    @endif

                                @empty
                                    <tr>
                                        <td colspan="9" class="text-center text-muted">ไม่มีข้อมูล ManuCost
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                        <div>
                            <div class="text-muted small">ข้อมูลในตาราง ManuCost เป็นการรวมของ Plus และ Wire </div>
                        </div>
                    </div>

                    <div class="mt-2">
                        {{ $mnc->withQueryString()->links() }}
                    </div>
                </div>

                {{-- SO --}}
                <div class="tab-pane fade" id="tab-so">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle so-table" id="tbl-so">
                            <thead class="table-warning">
                                <tr class="text-center">
                                    <th>#</th>
                                    <th>เลขที่</th>
                                    <th>วันที่เอกสาร</th>
                                    <th>รหัสสินค้า</th>
                                    <th>ชื่อสินค้า</th>
                                    <th>กำหนดส่ง</th>
                                    <th>พนง.ขาย</th>
                                    <th>ลูกค้า</th>
                                    <th>จำนวนสั่ง</th>
                                    <th>ส่งแล้ว</th>
                                    <th>ค้างส่ง</th>
                                    <th>ราคาต่อหน่วย</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php $so = $datasets['so'] ?? null; @endphp
                                @forelse($so as $i => $r)
                                    @php
                                        $rowNo = ($so->firstItem() ?? 1) + ($loop->index ?? 0);
                                        //dump($rowNo);
                                        $over = $r->due_date && Carbon::parse($r->due_date)->isPast();
                                    @endphp

                                    @if ($r->ordnumber === 'รวมทั้งหมด')
                                        <tr class="table-success fw-bold">
                                            <td colspan='8'>{{ $r->ordnumber }}</td>
                                            <td class="text-end">{{ number_format($r->ordered, 2) }}</td>
                                            <td class="text-end">{{ number_format($r->shipped, 2) }}</td>
                                            <td class="text-start" colspan='2'
                                                class="text-end fw-semibold {{ ($r->backorder ?? 0) > 0 ? 'text-danger' : '' }}">
                                                {{ number_format($r->backorder, 2) }}
                                            </td>
                                        </tr>
                                    @else
                                        <tr>
                                            <td>{{ $rowNo }}</td>
                                            <td>{{ $r->ordnumber }}</td>
                                            <td>{{ Str::of($r->transdate ?? '')->substr(0, 10) }}</td>
                                            <td>{{ $r->partnumber }}</td>
                                            <td>{{ $r->part_description }}</td>
                                            <td>
                                                {{ Str::of($r->due_date ?? '')->substr(0, 10) }}

                                            </td>
                                            <td>{{ $r->salesperson }}</td>
                                            <td>{{ $r->customer }}</td>
                                            <td class="text-end">{{ number_format($r->ordered, 2) }}</td>
                                            <td class="text-end">{{ number_format($r->shipped, 2) }}</td>
                                            <td
                                                class="text-end fw-semibold {{ ($r->backorder ?? 0) > 0 ? 'text-danger' : '' }}">
                                                {{ number_format($r->backorder, 2) }}
                                            </td>
                                            <td class="text-end">{{ number_format($r->unit_price, 2) }}</td>
                                        </tr>
                                    @endif

                                @empty
                                    <tr>
                                        <td colspan="12" class="text-center text-muted">ไม่มีข้อมูล SO</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                        <div>
                            <div class="text-muted small">ข้อมูลในตาราง Sale Order เป็นข้อมูลย้อนหลัง 1
                                ปีนับจากวันนี้และเป็นของ Site Wire เท่านั้น </div>
                        </div>
                    </div>

                    <div class="mt-2">
                        {{ $so->withQueryString()->links() }}
                    </div>
                </div>


            </div>
        @endif
    @elseif($sku !== '')
        <div class="alert alert-warning mt-3">
            ไม่พบข้อมูลสำหรับรหัสสินค้า: <b>{{ $sku }}</b>
        </div>
        @endif

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
    </style>
@endpush

@push('scripts')
    <script>
        // Enter เพื่อค้นหา
        document.getElementById('sku')?.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('searchForm').submit();
            }
        });

        // ป้องกันกดซ้ำ & แสดง loading ระหว่างค้นหา
        document.getElementById('searchForm')?.addEventListener('submit', function() {
            const btn = document.getElementById('btnSearch');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>กำลังค้นหา...';
            }
        });

        document.querySelectorAll('input[name="src[]"]').forEach(el => {
            el.addEventListener('change', () => {
                const f = document.getElementById('searchForm');
                if (f?.requestSubmit) f.requestSubmit();
                else f?.submit();
            });
        });

        document.querySelectorAll('[data-bs-toggle="tab"]').forEach(el => {
            el.addEventListener('shown.bs.tab', (e) => {
                const tabId = e.target.getAttribute('data-bs-target'); // เช่น "#tab-wip"
                sessionStorage.setItem('activeTab', tabId);
            });
        });

        // โหลดหน้า -> แสดง tab เดิม
        document.addEventListener('DOMContentLoaded', () => {
            const activeTab = sessionStorage.getItem('activeTab');
            if (activeTab) {
                const trigger = document.querySelector(`[data-bs-toggle="tab"][data-bs-target="${activeTab}"]`);
                if (trigger) new bootstrap.Tab(trigger).show();
            }
        });

        document.getElementById('clearCustomer')?.addEventListener('click', function() {
            const sel = document.getElementById('customer');
            sel.value = '';
            sel.dispatchEvent(new Event('change', {
                bubbles: true
            }));
        });

        // Export CSV (สรุป 5 แถว)
        const exportBtn = document.getElementById('exportBtn');
        if (exportBtn) {
            exportBtn.addEventListener('click', () => {
                try {
                    const unit = @json($result['unit'] ?? '');
                    const rows = [
                        ['ลำดับ', 'รายการ', `QTY (KG.)`, 'หมายเหตุ'],
                        ['1', 'จำนวน FG (สินค้าใน Stock พร้อมส่ง)',
                            '{{ number_format($result['fg_qty'] ?? 0, 2) }} ',
                            'CPA 7 (Wire + Plus)'
                        ],
                        ['2', 'จำนวน WIP (สินค้าที่กำลังผลิต)',
                            '{{ number_format($result['wip_qty'] ?? 0, 2) }} ',
                            'CPA 30 (Wire + Plus)'
                        ],
                        ['3', 'จำนวนใบคำสั่งที่เปิดแล้ว/จองแผนผลิต)',
                            '{{ number_format($result['booked_qty'] ?? 0, 2) }} ',
                            'ManuCost'
                        ],
                        ['4', 'จำนวนยอดค้างส่งทุก Sale Order',
                            '{{ number_format($result['so_qty'] ?? 0, 2) }} ',
                            'CPA 24 (Wire)'
                        ],
                        ['5', 'จำนวนที่ต้องผลิตเพิ่ม',
                            '{{ number_format($result['need_qty'] ?? 0, 2) }}',
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
                    a.download = `PP-Summary-{{ $result['sku'] ?? 'unknown' }}.csv`;
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
    </script>
@endpush
