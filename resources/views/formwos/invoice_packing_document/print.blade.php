<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice Packing List</title>
    <style>
        @page { size: A4 landscape; margin: 8mm; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #e5e7eb;
            color: #111827;
            font-family: Arial, "Tahoma", sans-serif;
            font-size: 11px;
        }
        .toolbar {
            position: sticky;
            top: 0;
            z-index: 10;
            padding: 10px;
            text-align: center;
            background: #111827;
        }
        .toolbar button {
            border: 0;
            border-radius: 6px;
            padding: 8px 20px;
            background: #2563eb;
            color: white;
            font-weight: 700;
            cursor: pointer;
        }
        .sheet {
            position: relative;
            width: 281mm;
            min-height: 194mm;
            margin: 10px auto;
            padding: 10mm 9mm 8mm;
            background: white;
            page-break-after: always;
        }
        .sheet:last-child { page-break-after: auto; }
        .document-title {
            margin: 0;
            text-align: center;
            font-size: 20px;
            letter-spacing: .4px;
        }
        .document-subtitle {
            margin: 4px 0 10px;
            text-align: center;
            color: #4b5563;
        }
        .meta {
            display: flex;
            justify-content: space-between;
            margin-bottom: 7px;
            font-size: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        th, td {
            border: 1px solid #111827;
            padding: 4px 5px;
            vertical-align: middle;
        }
        th {
            height: 26px;
            background: #f3f4f6;
            text-align: center;
            font-size: 9px;
        }
        tbody td { height: 18mm; }
        .center { text-align: center; }
        .right { text-align: right; }
        .description {
            font-size: 9px;
            line-height: 1.25;
            overflow-wrap: anywhere;
        }
        .muted { color: #6b7280; }
        .signature-area {
            display: flex;
            justify-content: flex-end;
            margin-top: 8mm;
            padding-right: 15mm;
        }
        .signature {
            width: 72mm;
            text-align: center;
        }
        .signature-line {
            height: 13mm;
            border-bottom: 1px solid #111827;
        }
        .signature-name {
            margin-top: 3px;
            font-size: 12px;
            font-weight: 700;
        }
        .page-number {
            position: absolute;
            right: 9mm;
            bottom: 5mm;
            color: #6b7280;
            font-size: 9px;
        }
        .w-no { width: 4%; }
        .w-invoice { width: 9%; }
        .w-date { width: 7%; }
        .w-po { width: 10%; }
        .w-dob { width: 8%; }
        .w-part { width: 10%; }
        .w-desc { width: 22%; }
        .w-package { width: 6%; }
        .w-net, .w-gross { width: 7%; }
        .w-price, .w-amount { width: 7%; }

        @media print {
            body { background: white; }
            .toolbar { display: none; }
            .sheet {
                width: auto;
                min-height: 194mm;
                margin: 0;
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">พิมพ์เอกสาร</button>
    </div>

    @foreach ($pages as $pageIndex => $rows)
        <section class="sheet">
            <h1 class="document-title">INVOICE / PACKING LIST</h1>
            <div class="document-subtitle">ERP Invoice Packing Detail</div>

            <div class="meta">
                <div>Invoice: {{ collect($rows)->pluck('invoice_no')->unique()->implode(', ') }}</div>
                <div>Site: {{ collect($rows)->pluck('site')->unique()->implode(', ') }}</div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th class="w-no">No.</th>
                        <th class="w-invoice">Invoice</th>
                        <th class="w-date">Invoice Date</th>
                        <th class="w-po">Customer PO</th>
                        <th class="w-dob">DOB</th>
                        <th class="w-part">Part No.</th>
                        <th class="w-desc">Description</th>
                        <th class="w-package">Package</th>
                        <th class="w-net">Net Weight</th>
                        <th class="w-gross">Gross Weight</th>
                        <th class="w-price">Unit Price</th>
                        <th class="w-amount">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $rowIndex => $row)
                        <tr>
                            <td class="center">{{ ($pageIndex * 5) + $rowIndex + 1 }}</td>
                            <td class="center">{{ $row->invoice_no }}</td>
                            <td class="center">{{ \Carbon\Carbon::parse($row->invoice_date)->format('d/m/Y') }}</td>
                            <td class="center">{{ trim((string) $row->customer_po) ?: '-' }}</td>
                            <td class="center">{{ $row->packing_list_no }}</td>
                            <td>{{ $row->partnumber }}</td>
                            <td class="description">{!! nl2br(e(trim((string) $row->description))) !!}</td>
                            <td class="center">
                                {{ number_format((int) $row->package_qty) }}
                                @if (trim((string) $row->package_numbers) !== '')
                                    <div class="muted">{{ $row->package_numbers }}</div>
                                @endif
                            </td>
                            <td class="right">{{ number_format((float) $row->net_weight_kg, 2) }}</td>
                            <td class="right">{{ number_format((float) $row->gross_weight_kg, 2) }}</td>
                            <td class="right">{{ number_format((float) $row->unit_price, 2) }}</td>
                            <td class="right">{{ number_format((float) $row->line_amount, 2) }}</td>
                        </tr>
                    @endforeach

                    @for ($emptyRow = count($rows); $emptyRow < 5; $emptyRow++)
                        <tr>
                            @for ($column = 0; $column < 12; $column++)
                                <td>&nbsp;</td>
                            @endfor
                        </tr>
                    @endfor
                </tbody>
            </table>

            <div class="signature-area">
                <div class="signature">
                    <div class="signature-line"></div>
                    <div>ลงชื่อ / Signature</div>
                    <div class="signature-name">({{ $signerName }})</div>
                </div>
            </div>

            <div class="page-number">Page {{ $pageIndex + 1 }} / {{ count($pages) }}</div>
        </section>
    @endforeach
</body>
</html>
