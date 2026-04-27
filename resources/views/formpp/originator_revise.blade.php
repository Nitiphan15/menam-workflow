@extends('layouts.layout')

@section('title', 'ฟอร์มวางแผนการผลิต Revise')
@section('page-title', 'ฟอร์มวางแผนการผลิต Revise')

@section('content')
    @php
        use Illuminate\Support\Carbon;

        // ตัวแปรที่หน้า Originator ต้องใช้เท่านั้น
        $sku = $sku ?? '';
        $result = $result ?? null;
        $customers = $customers ?? collect();
        $wfId = $form->id ?? ($ppData->wfForm->id ?? ($wfId ?? null));

        $nf = fn($v) => number_format((float) $v, 2);

        $selectedCustomers = (array) ($selectedCustomers ?? ($selectedCustomer ?? []));

    @endphp

    <div class="container py-3">

        {{-- Search Card --}}
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('pp.sales', ['id' => $wfId]) }}" class="row g-2 align-items-end"
                    id="searchForm">

                    <div class="col-md-4 position-relative">
                        <label for="sku" class="form-label fw-semibold">รหัสสินค้า (SKU)</label>
                        <input type="text" id="sku" name="sku" value="{{ $sku }}"
                            class="form-control form-control-lg" placeholder="พิมพ์รหัสสินค้า เช่น FC304HXXXX01000HBXXL"
                            autocomplete="off" autofocus data-url="{{ route('api.parts.search') }}">
                        <input type="hidden" id="sku_id" name="sku_id">
                        <div id="sku-list" class="list-group position-absolute w-100 shadow-sm"
                            style="z-index:1050;max-height:260px;overflow:auto;display:none;"></div>
                    </div>

                    <div class="col-md-auto">
                        <button type="submit" class="btn btn-primary btn-lg" id="btnSearch">
                            <span class="me-1"><i class="bi bi-search"></i></span> ค้นหา
                        </button>
                    </div>
                    <div class="col-md-auto">
                        <a href="{{ route('pp.sales', ['id' => $wfId]) }}"
                            class="btn btn-outline-secondary btn-lg">ล้างค่า</a>
                    </div>
                </form>
                <small class="text-muted d-block mt-2">
                    ใส่รหัสสินค้าเพียงช่องเดียว แล้วระบบจะแสดงข้อมูลทั้งหมดอัตโนมัติ
                </small>

            </div>
        </div>

        {{-- เมื่อมีผลลัพธ์ให้บันทึกแบบฟอร์ม --}}
        @if ($result)
            {{-- แสดงสรุปสินค้าแบบย่อ --}}
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small">รหัสสินค้า</div>
                            <div class="fs-5 fw-semibold">{{ $result['sku'] ?? '-' }}</div>
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
                            <div class="text-muted small">ต้องผลิตเพิ่ม (Sales)</div>
                            <div class="fs-4 fw-bold text-primary">
                                {{ $nf($result['need_qty'] ?? 0) }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small">ต้องผลิตเพิ่ม (Planner)</div>
                            <div class="fs-4 fw-bold text-primary">
                                0
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ตัวกรองลูกค้า (สำหรับปรับมุมมองก่อนบันทึก) --}}
            <div class="row g-3 mb-3">
                <div class="col-md-7">
                    <label for="customers" class="form-label fw-semibold mb-1">ลูกค้า</label>

                    <div class="d-flex gap-2 align-items-center">
                        <select id="customers" name="customers[]" multiple class="form-select form-select-lg"
                            form="searchForm" data-placeholder="— ทุกลูกค้า —">
                            @foreach ($customers as $cus)
                                <option value="{{ $cus }}" @selected(in_array($cus, (array) ($selectedCustomers ?? [])))>
                                    {{ $cus }}
                                </option>
                            @endforeach
                        </select>

                        <div class="btn-group">
                            <button type="button" id="btn-clear" class="btn btn-outline-secondary btn-sm">ล้าง</button>
                        </div>
                    </div>

                    <div class="form-text mt-1" id="selected-summary"></div>
                </div>

            </div>

            {{-- ฟอร์ม Originator: POST เฉพาะข้อมูลที่ "ต้องบันทึก" --}}
            <form method="POST" action="{{ route('pp.sales', ['id' => $wfId]) }}" id="originatorForm" class="mb-4">
                @csrf

                {{-- Hidden fields ที่จำเป็นต่อการบันทึก Step 1 --}}
                <input type="hidden" name="part_no" value="{{ $result['sku'] }}">
                @foreach ($selectedCustomers as $cus)
                    <input type="text" name="customers[]" value="{{ $cus }}">
                @endforeach
                <input type="hidden" name="part_name" value="{{ $result['name'] ?? '-' }}">
                <input type="hidden" name="type" value="{{ $result['type'] ?? '-' }}">
                <input type="hidden" name="category" value="{{ $result['category'] ?? '-' }}">
                <input type="hidden" name="group" value="{{ $result['group'] ?? '-' }}">
                <input type="hidden" name="sales_fg" value="{{ (float) ($result['fg_qty'] ?? 0) }}">
                <input type="hidden" name="sales_wip" value="{{ (float) ($result['wip_qty'] ?? 0) }}">
                <input type="hidden" name="sales_mfg" value="{{ (float) ($result['booked_qty'] ?? 0) }}">
                <input type="hidden" name="sales_order" value="{{ (float) ($result['so_qty'] ?? 0) }}">
                <input type="hidden" name="sales_prod" value="{{ (float) ($result['need_qty'] ?? 0) }}">
                <input type="hidden" name="sales_rm" value="{{ (float) ($result['rm_qty'] ?? 0) }}">

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-light fw-semibold">สรุปยอดสำหรับบันทึก (Originator)</div>
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
                                        <td class="text-center fw-semibold">{{ $nf($result['fg_qty'] ?? 0) }}</td>
                                        <td class="text-center fw-semibold">0</td>
                                        <td class="text-muted">จาก CPA 7 (Wire + Plus)</td>
                                    </tr>
                                    <tr>
                                        <td class="text-center">2</td>
                                        <td>จำนวน WIP (สินค้าที่กำลังผลิต)</td>
                                        <td class="text-center fw-semibold">{{ $nf($result['wip_qty'] ?? 0) }}</td>
                                        <td class="text-center fw-semibold">0</td>
                                        <td class="text-muted">จาก CPA 30 (Wire + Plus)</td>
                                    </tr>
                                    <tr>
                                        <td class="text-center">3</td>
                                        <td>จำนวนใบคำสั่งที่เปิดแล้ว/จองแผนผลิต</td>
                                        <td class="text-center fw-semibold">{{ $nf($result['booked_qty'] ?? 0) }}</td>
                                        <td class="text-center fw-semibold">0</td>
                                        <td class="text-muted">จาก ManuCost</td>
                                    </tr>
                                    <tr>
                                        <td class="text-center">4</td>
                                        <td>จำนวนยอดค้างส่งทุก Sale Order</td>
                                        <td class="text-center fw-semibold">{{ $nf($result['so_qty'] ?? 0) }}</td>
                                        <td class="text-center fw-semibold">0</td>
                                        <td class="text-muted">จาก CPA 24 (Wire)</td>
                                    </tr>
                                    <tr class="table-primary">
                                        <td class="text-center">5</td>
                                        <td class="fw-semibold">จำนวนที่ต้องผลิตเพิ่ม</td>
                                        <td class="text-center fw-bold">{{ $nf($result['need_qty'] ?? 0) }}</td>
                                        <td class="text-center fw-semibold">0</td>
                                        <td class="text-muted"></td>
                                    </tr>
                                    <tr class="table-info">
                                        <td class="text-center">6</td>
                                        <td class="fw-semibold">จำนวนคงเหลือวัตถุดิบหลังจากหักยอดจองผลิตแล้ว</td>
                                        <td class="text-center fw-bold">{{ $nf($result['rm_qty'] ?? 0) }}</td>
                                        <td class="text-center fw-semibold">0</td>
                                        <td class="text-muted">1 RM ผลิตมากกว่า 1 FG
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="p-3">
                            @if ($canApprove)
                                <div class="mt-2">
                                    <label for="reject_reason" class="form-label fw-semibold">Comment</label>
                                    <textarea id="reject_reason" name="reason" class="form-control mb-2" placeholder="กรุณาระบุเหตุผล" rows="2"></textarea>

                                </div>
                                <button type="submit" name="action" value="approve" class="btn btn-success">
                                    <i class="fas fa-check me-1"></i> อนุมัติ
                                </button>
                                <button type="submit" name="action" value="reject" class="btn btn-danger">
                                    <i class="fa fa-times"></i> Reject
                                </button>
                            @endif
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
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-rm"
                            type="button">RM</button></li>

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
                                <div class="text-muted small">ข้อมูลในตาราง WIP เป็นข้อมูลของปีปฏิทินล่าสุดและรวมจาก Plus
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
                                        <th>เลขที่คำสั่งซื้อ</th>
                                        <th>รหัสสินค้า</th>
                                        <th>ชื่อสินค้า</th>
                                        <th>กำหนดส่ง</th>
                                        <th>พนง.ขาย</th>
                                        <th>ลูกค้า</th>
                                        <th>จำนวนสั่ง</th>
                                        <th>ส่งแล้ว</th>
                                        <th>ค้างส่ง</th>
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
                                                <td colspan='9'>{{ $r->ordnumber }}</td>
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
                                                <td>{{ $r->po }}</td>
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

                    {{-- RM --}}
                    <div class="tab-pane fade" id="tab-rm">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle">
                                <thead class="table-warning">
                                    <tr class="text-center">
                                        <th>#</th>
                                        <th>โรงงาน</th>
                                        <th>ลูกค้า</th> {{-- เพิ่ม --}}
                                        <th>RM Part</th>
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
                                    @php $rm = $datasets['rm'] ?? null; @endphp
                                    @forelse($rm as $i => $r)
                                        @php
                                            $rowNo = ($rm->firstItem() ?? 1) + ($loop->index ?? 0);
                                            $bal = (float) ($r->balance_qty ?? 0);
                                        @endphp

                                        @if ($r->rm_partnumber === 'รวมทั้งหมด')
                                            <tr class="table-success fw-bold">
                                                {{-- เดิม colspan="7" ตอนนี้มีคอลัมน์ "ลูกค้า" เพิ่ม -> เป็น 8 --}}
                                                <td colspan="8">{{ $r->rm_partnumber ?? null }}</td>
                                                <td class="text-end">{{ number_format($r->receive_qty ?? 0, 2) }}</td>
                                                <td class="text-end">{{ number_format($r->issue_qty ?? 0, 2) }}</td>
                                                <td class="text-end {{ $bal < 0 ? 'text-danger fw-bold' : '' }}">
                                                    {{ number_format($bal, 2) }}
                                                </td>
                                            </tr>
                                        @else
                                            <tr>
                                                <td>{{ $rowNo }}</td>
                                                <td class="text-center">{{ $r->site }}</td>
                                                <td>{{ $r->customer ?? '-' }}</td> {{-- แสดงลูกค้า --}}
                                                <td>{{ $r->rm_partnumber }}</td>
                                                <td>{{ $r->part_desc }}</td>
                                                <td>{{ $r->type_desc ?? '' }}</td>
                                                <td>{{ $r->cat_desc ?? '' }}</td>
                                                <td>{{ $r->grp_desc ?? '' }}</td>
                                                <td class="text-end">{{ number_format($r->receive_qty ?? 0, 2) }}</td>
                                                <td class="text-end">{{ number_format($r->issue_qty ?? 0, 2) }}</td>
                                                <td class="text-end {{ $bal < 0 ? 'text-danger fw-bold' : '' }}">
                                                    {{ number_format($bal, 2) }}
                                                </td>
                                            </tr>
                                        @endif
                                    @empty
                                        <tr>
                                            <td colspan="11" class="text-center text-muted">ไม่มีข้อมูล RM</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>

                            <div>
                                <div class="text-muted small">ข้อมูลในตาราง RM เป็นการรวมของ Plus และ Wire แยกตามลูกค้า
                                </div>
                            </div>
                        </div>

                        <div class="mt-2">
                            {{ $rm?->withQueryString()->links() }}
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

<style>
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

    /* สูงพอดี ไม่ล้น */
</style>



@push('scripts')
    <script>
        const input = document.getElementById('sku');
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
                a.dataset.label = r.label; // "part :: description"
                a.textContent = r.label;

                // ใช้ mousedown เพื่อไม่ให้ blur ทำลิสต์หายก่อน select
                a.addEventListener('mousedown', () => select(r));
                listEl.appendChild(a);
            });

            listEl.style.display = 'block';
        };

        const select = (row) => {
            input.value = row.value; // ถ้าอยากให้แสดง "part :: description" เปลี่ยนเป็น row.label
            hid.value = row.id || '';
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
            hid.value = '';
            const q = input.value.trim();
            if (q.length < 1) {
                listEl.style.display = 'none';
                return;
            }
            search(q);
        }, 200));

        // Enter เพื่อค้นหา
        document.getElementById('sku')?.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('searchForm').submit();
            }
        }); // ป้องกันกดซ้ำ & แสดง loadingระหว่ างค้ นหา 
        document.getElementById('searchForm')?.addEventListener('submit', function() {
            const
                btn = document.getElementById('btnSearch');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>กำลังค้นหา...';
            }
        });
        document.getElementById('approveBtn')?.addEventListener('click', function() {
            const btn = this;
            btn.disabled = true;
            btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true">
        </span>กำลังบันทึก...`;
            btn.form.submit(); // ส่งฟอร์ม
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


        const ts = new TomSelect('#customers', {
            maxItems: null,
            plugins: ['remove_button', 'checkbox_options', 'clear_button'],
            placeholder: document.querySelector('#customers').dataset.placeholder,
            hideSelected: true,
            closeAfterSelect: false,
            // เปลี่ยนแล้ว submit auto
            onChange: () => document.getElementById('searchForm').submit(),

        });

        // เลือกทั้งหมด / ล้าง
        document.getElementById('btn-select-all')?.addEventListener('click', () => {
            ts.setValue([...ts.options.keys()]); // ทุกตัว
            document.getElementById('searchForm').submit();
        });
        document.getElementById('btn-clear')?.addEventListener('click', () => {
            ts.clear();
            document.getElementById('searchForm').submit();
        });

        // แสดงสรุปจำนวน
        const summary = document.getElementById('selected-summary');
        const renderSummary = () => {
            const values = ts.getValue();
            summary.textContent = values.length ? `เลือกแล้ว ${values.length} รายการ` : '— ทุกลูกค้า —';
        };
        ts.on('change', renderSummary);
        renderSummary();

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

                    const csv = rows.map(r => r.map(x => `"${String(x).replace(/"/g,'""')}"`).join(','))
                        .join(
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
