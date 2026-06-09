<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>Delivery Plan PDF</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 3mm;
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
            padding: 0;
            color: #000;
            font-family: "THSarabunNew", "DejaVu Sans", Tahoma, sans-serif;
            font-size: 7px;
            line-height: 1;
        }

        table {
            width: 100%;
            margin: 0;
            border-collapse: collapse;
            table-layout: fixed;
        }

        th,
        td {
            border: 0.6px solid #111;
            padding: 0.7px 2px;
            vertical-align: middle;
            word-break: break-word;
            overflow-wrap: break-word;
        }

        th {
            text-align: center;
            font-weight: 700;
            font-size: 6.2px;
            line-height: 1;
        }

        td {
            font-size: 6.8px;
            line-height: 1;
        }

        .top-rule {
            border-top: 1.1px solid #111;
            margin: 0 33px 1px 20px;
        }

        .header-table td {
            border: none;
            padding: 0 2px;
        }

        .logo-text {
            font-size: 28px;
            font-weight: 700;
            line-height: 0.9;
            letter-spacing: 0;
        }

        .company-text {
            text-align: center;
            color: #0000ff;
            font-size: 9.8px;
            font-weight: 700;
            line-height: 1;
            padding-top: 3px;
        }

        .title-text {
            color: #0000ff;
            font-size: 7.2px;
            font-weight: 700;
            line-height: 1;
            padding-top: 0;
        }

        .badge {
            display: inline-block;
            background: #fff200;
            padding: 2px 18px;
            font-size: 7.5px;
            font-weight: 700;
            color: #000;
            border: 0.6px solid #111;
        }

        .report-table {
            border-top: 1.2px solid #111;
        }

        .report-table thead th {
            height: 9px;
        }

        .pink {
            color: #ff00ff;
        }

        .blue {
            color: #0000ff;
        }

        .red {
            color: #ff0000;
        }

        .green {
            color: #008000;
        }

        .yellow-bg {
            background: #fff200;
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

        .group-row td {
            height: 8px;
            border-top: 0.8px solid #111;
            border-bottom: 0.6px solid #111;
        }

        .group-title {
            color: #ff6600;
            font-weight: 700;
            text-decoration: underline;
            text-align: left;
            padding-left: 22px;
            font-size: 7.1px;
        }

        .row-item td {
            height: 8px;
            font-weight: 700;
        }

        .cell-item,
        .cell-package,
        .cell-type,
        .cell-size,
        .cell-mfg,
        .cell-piece,
        .cell-logistics,
        .cell-priority,
        .cell-place,
        .cell-oe,
        .cell-attach,
        .cell-remark,
        .cell-plan {
            text-align: center;
        }

        .customer-text {
            text-align: center;
        }

        .cell-qty {
            text-align: right;
            white-space: nowrap;
        }

        .summary-cell {
            background: #fff200;
            color: #ff0000;
            font-weight: 700;
            text-align: right;
        }

        .summary-row td {
            height: 16px;
            border-top: 1px solid #111;
            font-size: 7.2px;
            font-weight: 700;
        }

        .w-item {
            width: 16px;
        }

        .w-customer {
            width: 110px;
        }

        .w-package {
            width: 58px;
        }

        .w-type {
            width: 52px;
        }

        .w-size {
            width: 96px;
        }

        .w-mfg {
            width: 108px;
        }

        .w-piece {
            width: 38px;
        }

        .w-qty {
            width: 52px;
        }

        .w-logistics {
            width: 74px;
        }

        .w-priority {
            width: 22px;
        }

        .w-place {
            width: 92px;
        }

        .w-oe {
            width: 62px;
        }

        .w-attach {
            width: 78px;
        }

        .w-remark {
            width: 80px;
        }

        .w-plan {
            width: 92px;
        }
    </style>
</head>

<body>
    <div class="top-rule"></div>
    <table class="header-table">
        <tr>
            <td style="width:120px;">
                <div class="logo-text">
                    <span style="color:#00a3d9;">MEN</span><span style="color:#f4b400;">AM</span>
                </div>
            </td>
            <td class="company-text">
                Menam Stainless WirePublic Co.,Ltd บริษัทแม่น้ำสแตนเลสไวร์จำกัด(มหาชน)
            </td>
            <td style="width:190px;"></td>
        </tr>
        <tr>
            <td colspan="2" class="title-text">
                Delivery Plan รายงานยอดส่งมอบจัดส่งสินค้าประจำวัน (เดือน {{ $monthText ?? '-' }}) เป้าหมาย {{ $targetText ?? '-' }}
            </td>
            <td class="center">
                <span class="badge">{{ $revisionBadgeText ?? '-' }} {{ $revisionBadgeTime ?? '' }}</span>
            </td>
        </tr>
    </table>

    <table class="report-table">
        <thead>
            <tr>
                <th class="w-item">ITEM</th>
                <th colspan="6" class="pink">REQUESTED SALES</th>
                <th class="pink w-qty">SALES</th>
                <th class="pink w-qty">STOCK</th>
                <th class="pink w-qty">Production</th>
                <th class="blue w-logistics">LOGISTICS</th>
                <th class="blue w-priority">Priority</th>
                <th colspan="5" class="red">CONFIRMED</th>
            </tr>
            <tr>
                <th class="w-item">ลำดับ</th>
                <th colspan="6" class="yellow-bg blue" style="font-size:9px;">{{ $shipDateThaiText ?? $shipDateText ?? '-' }}</th>
                <th class="blue">การตลาด</th>
                <th class="blue">Inventory</th>
                <th class="pink">ฝ่ายผลิต</th>
                <th class="blue">จัดส่ง</th>
                <th></th>
                <th class="blue w-place">สถานที่ส่งสินค้า</th>
                <th class="pink w-oe">OE</th>
                <th class="blue w-attach">เอกสารแนบ</th>
                <th class="green w-remark">รายงานปัญหา</th>
                <th class="blue w-plan">แผนการรับมือ</th>
            </tr>
            <tr>
                <th>ที่</th>
                <th class="blue w-customer">CUSTOMER</th>
                <th class="blue w-package">PACKAGE</th>
                <th class="blue w-type">TYPE</th>
                <th class="blue w-size">SIZE x LENGTH</th>
                <th class="blue w-mfg">MFG/เลขที่</th>
                <th class="blue w-piece">จำนวน/เส้น/ชิ้น</th>
                <th class="blue w-qty">QUANTITY</th>
                <th class="blue w-qty">QUANTITY</th>
                <th class="blue w-qty">QUANTITY</th>
                <th class="blue w-logistics">QUANTITY</th>
                <th></th>
                <th class="blue">ที่อยู่</th>
                <th class="blue">ลูกค้า</th>
                <th class="blue">Attach</th>
                <th class="blue">ประจำวัน</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @php
                $revColorMap = [
                    0 => 'black',
                    1 => 'red',
                    2 => 'green',
                    3 => '#ff008c',
                    4 => 'blue',
                    5 => 'deepskyblue',
                ];
            @endphp

            @foreach ($pdfRows as $row)
                @if (($row['row_type'] ?? '') === 'group')
                    <tr class="group-row">
                        <td></td>
                        <td colspan="6" class="group-title">{{ $row['group_name'] ?? '' }}</td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                    </tr>
                @else
                    @php
                        $rev = (int) ($row['revision_number'] ?? ($row['revision'] ?? 0));
                        $fontColor = $revColorMap[$rev] ?? 'black';
                        $logisticsText = trim((string) ($row['logistics_note'] ?? ''));
                        if ($logisticsText === '' && (float) ($row['logistics_qty'] ?? 0) > 0) {
                            $logisticsText = number_format((float) $row['logistics_qty'], 2);
                        }
                    @endphp

                    <tr class="row-item" style="color: {{ $fontColor }};">
                        <td class="cell-item">{{ $row['item_no'] }}</td>
                        <td class="customer-text">{{ $row['customer'] }}</td>
                        <td class="cell-package">{{ $row['package'] }}</td>
                        <td class="cell-type">{{ $row['type'] }}</td>
                        <td class="cell-size">{{ $row['size_length'] }}</td>
                        <td class="cell-mfg">{{ $row['mfg_no'] }}</td>
                        <td class="cell-piece">{{ $row['pieces'] }}</td>
                        <td class="cell-qty">{{ number_format((float) $row['sales_qty'], 2) }}</td>
                        <td class="cell-qty">{{ (float) ($row['stock_qty'] ?? 0) > 0 ? number_format((float) $row['stock_qty'], 2) : '' }}</td>
                        <td class="cell-qty">{{ number_format((float) $row['production_qty'], 2) }}</td>
                        <td class="cell-logistics">{{ $logisticsText }}</td>
                        <td class="cell-priority">{{ $row['priority'] ?? '' }}</td>
                        <td class="cell-place">{{ $row['delivery_place'] }}</td>
                        <td class="cell-oe">{{ $row['oe_no'] }}</td>
                        <td class="cell-attach">{{ $row['attach_docs'] ?? '-' }}</td>
                        <td class="cell-remark">{{ $row['problem_note'] ?? '' }}</td>
                        <td class="cell-plan">{{ $row['action_plan'] ?? '' }}</td>
                    </tr>
                @endif
            @endforeach

            <tr class="summary-row">
                <td colspan="7"></td>
                <td class="summary-cell">{{ number_format((float) $sumSalesQty, 2) }}</td>
                <td class="summary-cell">{{ number_format((float) $sumStockQty, 2) }}</td>
                <td class="summary-cell">{{ number_format((float) $sumProductionQty, 2) }}</td>
                <td colspan="7"></td>
            </tr>
        </tbody>
    </table>

</body>

</html>
