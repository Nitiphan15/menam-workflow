<!doctype html>
<html lang="th">

<head>
    <meta charset="utf-8">
    <title>{{ $summary->truck_label }} - Delivery Plan</title>
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

        * {
            box-sizing: border-box;
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
            cursor: pointer;
        }

        .sheet {
            border-top: 2px solid #000;
        }

        .header {
            display: grid;
            grid-template-columns: 190px 1fr 310px;
            align-items: start;
            gap: 8px;
            padding: 4px 2px 8px;
            border-bottom: 2px solid #000;
        }

        .logo {
            font-size: 30px;
            font-weight: 900;
            letter-spacing: 2px;
            color: #0891b2;
            line-height: 1;
        }

        .logo span {
            color: #f59e0b;
        }

        .company {
            text-align: center;
            color: #0000ff;
            font-weight: 700;
            font-size: 12px;
        }

        .report-title {
            margin-top: 16px;
            color: #0000ff;
            font-weight: 700;
        }

        .truck-title {
            text-align: center;
            font-size: 20px;
            font-weight: 900;
            padding-top: 28px;
        }

        .meta {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 4px 12px;
            padding: 6px 2px;
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
            text-align: center;
            color: #0000ff;
            font-weight: 700;
        }

        .th-yellow {
            background: #ffff00;
            color: #000;
            font-size: 13px;
        }

        .group-pink {
            color: #ff00ff;
        }

        .group-red {
            color: #ff0000;
        }

        .group-green {
            color: #008000;
        }

        .center {
            text-align: center;
        }

        .right {
            text-align: right;
        }

        .bold {
            font-weight: 700;
        }

        .red {
            color: #ff0000;
        }

        .green {
            color: #008000;
        }

        .problem {
            color: #ff0000;
            font-weight: 700;
        }

        .total-row td {
            font-weight: 900;
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
        $fmtTon = fn($kg) => number_format(((float) ($kg ?? 0)) / 1000, 3);
        $shipText = \Carbon\Carbon::parse($summary->ship_date)->translatedFormat('l j F Y');
    @endphp

    @if (empty($exportMode))
        <div class="toolbar">
            <button type="button" onclick="window.print()">Print</button>
            <button type="button" onclick="window.close()">Close</button>
        </div>
    @endif

    <div class="sheet">
        <div class="header">
            <div>
                <div class="logo">MEN<span>AM</span></div>
                <div class="report-title">
                    Delivery Plan รายงานยอดส่งมอบจัดส่งสินค้าประจำวัน
                </div>
            </div>
            <div class="company">
                Menam Stainless Wire Public Co.,Ltd บริษัทแม่น้ำสแตนเลสไวร์จำกัด(มหาชน)
            </div>
            <div class="truck-title">{{ $summary->truck_label }} เอกสารจัดรอบรถ</div>
        </div>

        <div class="meta">
            <div>วันที่: {{ $shipText }}</div>
            <div>คนขับ: {{ $summary->driver_name }}</div>
            <div>โทร: {{ $summary->driver_phone }}</div>
            <div>Max: {{ $summary->max_load > 0 ? $fmtTon($summary->max_load) . ' ตัน' : 'ไม่ระบุ' }}</div>
            <div>เด็กรถ: {{ trim((string) ($summary->helper_names ?? '')) !== '' ? $summary->helper_names : '-' }}</div>
            <div>รายการ: {{ number_format($summary->item_count) }}</div>
            <div>QTY รวม: {{ $fmtTon($summary->total_qty) }} ตัน</div>
            <div>ขึ้นรถรวม: {{ $fmtTon($summary->total_assigned) }} ตัน</div>
            <div>หมายเหตุรถ: {{ $summary->remark ?: '-' }}</div>
        </div>

        <table>
            <colgroup>
                <col style="width:40px">
                <col style="width:140px">
                <col style="width:95px">
                <col style="width:90px">
                <col style="width:185px">
                <col style="width:190px">
                <col style="width:68px">
                <col style="width:80px">
                <col style="width:80px">
                <col style="width:82px">
                <col style="width:190px">
                <col style="width:165px">
                <col style="width:120px">
                <col style="width:160px">
            </colgroup>
            <thead>
                <tr>
                    <th rowspan="3">ITEM<br>ลำดับ<br>ที่</th>
                    <th class="th-yellow" rowspan="2">{{ $shipText }}</th>
                    <th class="group-pink" colspan="5">REQUESTED SALES<br>DESCRIPTION</th>
                    <th>SALES<br>การตลาด</th>
                    <th>STOCK<br>Inventory</th>
                    <th>Production<br>ฝ่ายผลิต</th>
                    <th>LOGISTICS<br>จัดส่ง</th>
                    <th class="group-red" colspan="3">CONFIRMED</th>
                </tr>
                <tr>
                    <th>PACKAGE</th>
                    <th>TYPE</th>
                    <th>SIZE x LENGTH</th>
                    <th>MFG/เลขที่</th>
                    <th>จำนวน/เส้น</th>
                    <th>QUANTITY</th>
                    <th>QUANTITY</th>
                    <th>QUANTITY</th>
                    <th>QUANTITY</th>
                    <th>สถานที่ส่งสินค้า<br>ที่อยู่</th>
                    <th>OE<br>ลูกค้า</th>
                    <th class="group-green">รายงานปัญหา<br>ประจำวัน</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $i => $row)
                    <tr>
                        <td class="center">{{ $i + 1 }}</td>
                        <td class="bold">{{ $row->customer_name ?: '-' }}</td>
                        <td class="center">{{ $row->package_text ?: '-' }}</td>
                        <td class="center bold">{{ $row->type_display ?: '-' }}</td>
                        <td class="center bold">{{ $row->part_desc ?: $row->part_number ?: '-' }}</td>
                        <td class="center bold">{{ $row->mfg_no ?: '-' }}</td>
                        <td class="center">
                            @if (!is_null($row->line_qty_display) && (float) $row->line_qty_display > 0)
                                {{ number_format((float) $row->line_qty_display, 0) }}
                                {{ $row->line_qty_unit }}
                            @else
                                -
                            @endif
                        </td>
                        <td class="right red">{{ $fmtTon($row->qty ?? 0) }}</td>
                        <td class="right red">{{ $fmtTon($row->qty ?? 0) }}</td>
                        <td class="right red">{{ $fmtTon($row->assigned_weight ?? 0) }}</td>
                        <td class="center bold">{{ $fmtTon($row->assigned_weight ?? 0) }}</td>
                        <td class="center bold">{{ $row->address ?: '-' }}</td>
                        <td class="center red">{{ $row->attach_docs_text ?: '-' }}</td>
                        <td class="problem">{{ trim((string) ($row->edit_remark ?: $row->remark ?: '')) }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="7" class="right">รวม</td>
                    <td class="right red">{{ $fmtTon($summary->total_qty) }}</td>
                    <td class="right red">{{ $fmtTon($summary->total_qty) }}</td>
                    <td class="right red">{{ $fmtTon($summary->total_assigned) }}</td>
                    <td class="center green">{{ $fmtTon($summary->total_assigned) }}</td>
                    <td colspan="3"></td>
                </tr>
            </tbody>
        </table>
    </div>
</body>

</html>
