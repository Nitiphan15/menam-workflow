<!doctype html>
<html lang="th">

<head>
    <meta charset="utf-8">
    <title>Delivery Plan Assigned Trucks</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 8mm;
        }

        @font-face {
            font-family: "THSarabunNew";
            font-style: normal;
            font-weight: 400;
            src: url("file://{{ public_path('fonts/THSarabunNew.ttf') }}") format("truetype");
        }

        @font-face {
            font-family: "THSarabunNew";
            font-style: normal;
            font-weight: 700;
            src: url("file://{{ public_path('fonts/THSarabunNew-Bold.ttf') }}") format("truetype");
        }

        body {
            margin: 0;
            color: #000;
            font-family: "THSarabunNew", "DejaVu Sans", Tahoma, sans-serif;
            font-size: 15px;
            line-height: 1.05;
        }

        .toolbar {
            position: sticky;
            top: 0;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            padding: 8px 0;
            background: #fff;
        }

        .toolbar button {
            border: 1px solid #1d4ed8;
            background: #1d4ed8;
            color: #fff;
            border-radius: 4px;
            padding: 6px 12px;
            font-weight: 700;
        }

        .logo {
            font-size: 28px;
            font-weight: 700;
            color: #0891b2;
            letter-spacing: 2px;
        }

        .logo span {
            color: #f59e0b;
        }

        .head-table {
            width: 100%;
            border-collapse: collapse;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            margin-bottom: 4px;
        }

        .head-table td {
            border: 0;
            padding: 5px 6px;
            vertical-align: middle;
        }

        .head-left {
            text-align: left;
        }

        .company,
        .report {
            color: #0000ff;
            font-weight: 700;
            text-align: center;
        }

        .title {
            font-size: 18px;
            font-weight: 700;
            text-align: center;
        }

        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }

        .summary-table td {
            border: 0;
            padding: 4px 6px;
            font-weight: 700;
        }

        .truck-block {
            margin-top: 10px;
            page-break-inside: avoid;
        }

        .truck-title {
            background: #dbeafe;
            border: 1px solid #000;
            border-bottom: 0;
            padding: 5px 6px;
            font-size: 16px;
            font-weight: 700;
        }

        .truck-title-row {
            background: #dbeafe;
            color: #000;
            text-align: left;
            font-size: 14px;
            font-weight: 700;
            padding: 4px 6px;
        }

        .special-title {
            background: #fef3c7;
            border: 1px solid #000;
            border-bottom: 0;
            padding: 5px 6px;
            font-size: 16px;
            font-weight: 700;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        th,
        td {
            border: 1px solid #000;
            padding: 3px 4px;
            vertical-align: middle;
            word-break: break-word;
        }

        th {
            color: #0000ff;
            text-align: center;
        }

        .yellow {
            background: #ffff00;
            color: #000;
        }

        .right {
            text-align: right;
        }

        .center {
            text-align: center;
        }

        .red {
            color: #f00;
        }

        .green {
            color: #008000;
            font-weight: 700;
        }

        @media print {
            .toolbar {
                display: none;
            }
        }
    </style>
</head>

<body>
    @php
        // แปลงรหัส delivery_type เป็นป้ายอ่านง่าย (sync กับหน้า inquiry)
        $deliveryTypeLabel = function ($type) {
            return match (strtoupper(trim((string) $type))) {
                'ACID' => 'ส่งกัดกรด',
                'SPECIAL' => 'งานพิเศษ',
                'SO' => 'ปกติ (SO)',
                default => trim((string) $type) ?: '-',
            };
        };
        $fmtTon = fn($kg) => number_format((float) ($kg ?? 0), 0, '.', ',');
        $fmtLoad = function ($kg) {
            $kg = (float) ($kg ?? 0);
            return number_format($kg, 0, '.', ',') . ' kg';
        };
        $shipText = \Carbon\Carbon::parse($shipDate)->translatedFormat('l j F Y');
        $specialGroups = collect($specialGroups ?? []);
        $specialCount = (int) ($summary->special_count ?? $specialGroups->flatten(1)->count());
    @endphp

    @if (empty($exportMode))
        <div class="toolbar">
            <button type="button" onclick="window.print()">Print</button>
            <button type="button" onclick="window.close()">Close</button>
        </div>
    @endif

    <table class="head-table">
        <colgroup>
            <col style="width:25%">
            <col style="width:50%">
            <col style="width:25%">
        </colgroup>
        <tr>
            <td class="head-left">
                <div class="logo">MEN<span>AM</span></div>
                <div class="report">Delivery Plan รายงานยอดส่งมอบจัดส่งสินค้าประจำวัน</div>
            </td>
            <td class="company">Menam Stainless Wire Public Co.,Ltd บริษัทแม่น้ำสแตนเลสไวร์จำกัด(มหาชน)</td>
            <td class="title">เอกสารจัดรถทั้งหมด</td>
        </tr>
    </table>

    <table class="summary-table">
        <tr>
            <td>วันที่: {{ $shipText }}</td>
            <td>รถที่จัดแล้ว: {{ number_format($summary->truck_count) }}</td>
            <td>รายการ: {{ number_format($summary->item_count) }}</td>
            <td>งานพิเศษ: {{ number_format($specialCount) }}</td>
            <td>ขึ้นรถรวม: {{ $fmtTon($summary->total_assigned) }} kg</td>
        </tr>
    </table>

    @php
        $truckHeaderText = function ($truck) use ($fmtTon) {
            $helpers = trim((string) ($truck->helper_names ?? ''));
            $carLength = trim((string) ($truck->car_length ?? ''));
            $truckRemark = trim((string) ($truck->remark ?? ''));
            $tripStatus = !empty($truck->is_closed) ? 'ปิดรอบแล้ว' : 'รอบเปิดอยู่';
            return $truck->truck_label
                . ' | สถานะ: ' . $tripStatus
                . ' | คนขับ: ' . ($truck->driver_name !== '' ? $truck->driver_name : '-')
                . ' | โทร: ' . ($truck->driver_phone !== '' ? $truck->driver_phone : '-')
                . ' | ความยาวรถ: ' . ($carLength !== '' ? $carLength : '-')
                . ' | Max: ' . ($truck->max_load > 0 ? $fmtTon($truck->max_load) . ' kg' : 'ไม่ระบุ')
                . ' | ขึ้นรถ: ' . $fmtTon($truck->total_assigned) . ' kg'
                . ' | คงเหลือ: ' . (!is_null($truck->remaining_load ?? null) ? $fmtTon($truck->remaining_load) . ' kg' : '-')
                . ' | เด็กรถ: ' . ($helpers !== '' ? $helpers : '-')
                . ' | หมายเหตุรถ: ' . ($truckRemark !== '' ? $truckRemark : '-');
        };
    @endphp

    @forelse ($truckGroups as $truck)
        <div class="truck-block">
            @if (($exportMode ?? '') !== 'excel')
                <div class="truck-title">{{ $truckHeaderText($truck) }}</div>
            @endif
            <table>
                <colgroup>
                    <col style="width:38px">
                    <col style="width:135px">
                    <col style="width:95px">
                    <col style="width:90px">
                    <col style="width:175px">
                    <col style="width:170px">
                    <col style="width:80px">
                    <col style="width:80px">
                    <col style="width:85px">
                    <col style="width:180px">
                    <col style="width:165px">
                    <col style="width:120px">
                    <col style="width:150px">
                </colgroup>
                <thead>
                    <tr>
                        <th>#</th>
                        <th class="yellow">CUSTOMER</th>
                        <th>PACKAGE</th>
                        <th>TYPE</th>
                        <th>SIZE x LENGTH</th>
                        <th>MFG/เลขที่</th>
                        <th>SALES QTY</th>
                        <th>LOGISTICS QTY</th>
                        <th>จำนวน/เส้น</th>
                        <th>สถานที่ส่งสินค้า</th>
                        <th>OE / เอกสาร</th>
                        <th>Sales</th>
                        <th>รายงานปัญหา</th>
                    </tr>
                    @if (($exportMode ?? '') === 'excel')
                        <tr>
                            <th colspan="13" class="truck-title-row">{{ $truckHeaderText($truck) }}</th>
                        </tr>
                    @endif
                </thead>
                <tbody>
                    @foreach ($truck->rows as $i => $row)
                        @php
                            $sellByLine = (int) ($row->sell_by_line ?? 0) === 1;
                            $lineQtyNum = (float) ($row->line_qty ?? 0);
                            $qtyNum     = (float) ($row->qty ?? 0);
                            $assignedNum = (float) ($row->assigned_weight ?? 0);
                            // sell_by_line แบบชิ้น: qty=0 + line_qty>0 (ตรงกับ logic หน้า inquiry)
                            $isPieceQty = $sellByLine && $lineQtyNum > 0 && $qtyNum == 0.0;
                            // หน่วย: qty=0 → ชิ้น, qty>0 → เส้น
                            $lineUnit = $isPieceQty ? 'ชิ้น' : 'เส้น';
                        @endphp
                        <tr>
                            <td class="center">{{ $i + 1 }}</td>
                            <td><strong>{{ $row->customer_name ?: '-' }}</strong></td>
                            <td class="center">{{ $row->package_text ?: '-' }}</td>
                            <td class="center">{{ $deliveryTypeLabel($row->delivery_type) }}</td>
                            <td class="center"><strong>{{ $row->part_desc ?: $row->part_number ?: '-' }}</strong></td>
                            <td class="center"><strong>{{ $row->mfg_no ?: '-' }}</strong></td>
                            <td class="right red">
                                @if ($isPieceQty)
                                    {{ number_format($lineQtyNum, 0) }} {{ $lineUnit }}
                                @else
                                    {{ $fmtTon($qtyNum) }}
                                @endif
                            </td>
                            <td class="right green">
                                @if ($isPieceQty && $assignedNum <= 0)
                                    {{ number_format($lineQtyNum, 0) }} {{ $lineUnit }}
                                @else
                                    {{ $fmtTon($assignedNum) }}
                                @endif
                            </td>
                            <td class="center">
                                @if ($sellByLine && !$isPieceQty)
                                    {{ number_format($lineQtyNum, 0) }} เส้น
                                @else
                                    -
                                @endif
                            </td>
                            <td>{{ $row->address ?: '-' }}</td>
                            <td class="red">{{ $row->attach_docs_text ?: '-' }}</td>
                            <td>{{ $row->sales_name ?: '-' }}</td>
                            <td class="red">{{ trim((string) ($row->edit_remark ?: $row->remark ?: '')) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @empty
        @if ($specialGroups->isEmpty())
            <p>ไม่พบรายการที่จัดรถแล้ว</p>
        @endif
    @endforelse

    @if ($specialGroups->isNotEmpty())
        <div class="truck-block">
            @if (($exportMode ?? '') !== 'excel')
                <div class="special-title">งานพิเศษวันนี้ ({{ number_format($specialCount) }} รายการ)</div>
            @endif
            <table>
                <colgroup>
                    <col style="width:38px">
                    <col style="width:135px">
                    <col style="width:95px">
                    <col style="width:90px">
                    <col style="width:175px">
                    <col style="width:170px">
                    <col style="width:80px">
                    <col style="width:80px">
                    <col style="width:85px">
                    <col style="width:180px">
                    <col style="width:165px">
                    <col style="width:120px">
                    <col style="width:150px">
                </colgroup>
                <thead>
                    @if (($exportMode ?? '') === 'excel')
                        <tr>
                            <th colspan="13" class="truck-title-row">งานพิเศษวันนี้ ({{ number_format($specialCount) }} รายการ)</th>
                        </tr>
                    @endif
                    <tr>
                        <th>#</th>
                        <th class="yellow">CUSTOMER</th>
                        <th>PACKAGE</th>
                        <th>TYPE</th>
                        <th>SIZE x LENGTH</th>
                        <th>MFG/เลขที่</th>
                        <th>SALES QTY</th>
                        <th>LOGISTICS QTY</th>
                        <th>จำนวน/เส้น</th>
                        <th>สถานที่ส่งสินค้า</th>
                        <th>OE / เอกสาร</th>
                        <th>Sales</th>
                        <th>รายงานปัญหา</th>
                    </tr>
                </thead>
                <tbody>
                    @php $specialIndex = 1; @endphp
                    @foreach ($specialGroups as $dispatchType => $specialRows)
                        @php
                            $firstSpecial = $specialRows->first();
                            $dispatchLabel = $firstSpecial->dispatch_label ?? $dispatchType;
                        @endphp
                        <tr>
                            <td colspan="13" class="truck-title-row">{{ $dispatchLabel }} ({{ number_format($specialRows->count()) }} รายการ)</td>
                        </tr>
                        @foreach ($specialRows as $row)
                            @php
                                $specialNote = trim(collect([
                                    $row->dp_status ?? '',
                                    $row->edit_remark ?? '',
                                    $row->dp_remark ?? '',
                                    $row->special_remark ?? '',
                                ])->filter()->implode(' | '));

                                $sellByLineS = (int) ($row->sell_by_line ?? 0) === 1;
                                $lineQtyNumS = (float) ($row->line_qty ?? 0);
                                $qtyNumS     = (float) ($row->qty ?? 0);
                                $isPieceQtyS = $sellByLineS && $lineQtyNumS > 0 && $qtyNumS == 0.0;
                                $lineUnitS = $isPieceQtyS ? 'ชิ้น' : 'เส้น';
                            @endphp
                            <tr>
                                <td class="center">{{ $specialIndex++ }}</td>
                                <td><strong>{{ $row->customer_name ?: '-' }}</strong></td>
                                <td class="center">{{ $row->package_text ?: '-' }}</td>
                                <td class="center">{{ $deliveryTypeLabel($row->delivery_type) }}</td>
                                <td class="center"><strong>{{ $row->part_desc ?: $row->part_number ?: '-' }}</strong></td>
                                <td class="center"><strong>{{ $row->mfg_no ?: '-' }}</strong></td>
                                <td class="right red">
                                    @if ($isPieceQtyS)
                                        {{ number_format($lineQtyNumS, 0) }} {{ $lineUnitS }}
                                    @else
                                        {{ $fmtTon($qtyNumS) }}
                                    @endif
                                </td>
                                <td class="right green">-</td>
                                <td class="center">
                                    @if ($sellByLineS && !$isPieceQtyS)
                                        {{ number_format($lineQtyNumS, 0) }} เส้น
                                    @else
                                        -
                                    @endif
                                </td>
                                <td>{{ $row->address ?: '-' }}</td>
                                <td class="red">{{ $row->attach_docs_text ?: '-' }}</td>
                                <td>{{ $row->sales_name ?: '-' }}</td>
                                <td class="red">{{ $specialNote ?: '-' }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</body>

</html>
