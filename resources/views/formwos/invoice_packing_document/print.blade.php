<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>คำร้องขอส่งของเข้าเขตปลอดอากร</title>
    <style>
        @page { size: A4 portrait; margin: 8mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e5e7eb; color: #000; font-family: Tahoma, Arial, sans-serif; font-size: 10px; line-height: 1.38; }
        .toolbar { position: sticky; top: 0; z-index: 10; padding: 10px; text-align: center; background: #111827; }
        .toolbar button { border: 0; border-radius: 6px; padding: 8px 20px; background: #2563eb; color: white; font-weight: 700; cursor: pointer; }
        .sheet { position: relative; width: 194mm; min-height: 281mm; margin: 10px auto; padding: 5mm 8mm 5mm; background: #fff; page-break-after: always; }
        .sheet:last-child { page-break-after: auto; }
        .form-code { text-align: right; font-weight: 700; margin-bottom: 4mm; }
        .document-title { margin: 0; text-align: center; font-size: 13px; font-weight: 700; }
        .blue { color: #0000d4; }
        .red { color: #f00000; }
        .doc-meta { width: 86mm; margin: 2mm 0 2mm auto; }
        .doc-meta > div { margin-bottom: 1mm; }
        .line { display: inline-block; min-width: 30mm; height: 4mm; border-bottom: 1px dotted #777; vertical-align: bottom; text-align: center; }
        .line.short { min-width: 13mm; }
        .line.medium { min-width: 22mm; }
        .letter-body p { margin: 1mm 0; }
        .indent { text-indent: 14mm; }
        .company-line { display: inline-block; min-width: 82mm; border-bottom: 1px dotted #777; text-align: center; }
        .po-line { min-height: 6mm; margin: 1mm 0; line-height: 1.35; }
        .items-table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 9px; }
        .items-table th, .items-table td { border: 1px solid #000; padding: 1.2mm 1.4mm; vertical-align: top; }
        .items-table th { height: 7mm; padding: 1mm; text-align: center; vertical-align: middle; font-weight: 700; }
        .items-table tbody td { height: 18mm; }
        .items-table tfoot td { height: 8mm; vertical-align: middle; font-weight: 700; }
        .center { text-align: center; }
        .right { text-align: right; }
        .nowrap { white-space: nowrap; }
        .item-description { font-size: 7.5px; line-height: 1.18; overflow-wrap: anywhere; }
        .item-reference { margin-top: 1mm; font-size: 7px; line-height: 1.15; }
        .gross-note { display: block; margin-top: 1mm; font-size: 7.5px; white-space: nowrap; }
        .w-seq { width: 6%; }
        .w-package { width: 14%; }
        .w-net { width: 14%; }
        .w-qty { width: 16%; }
        .w-price { width: 13%; }
        .w-desc { width: 37%; }
        .under-table { min-height: 7mm; padding-top: 1mm; }
        .signature-row { display: flex; justify-content: space-between; align-items: flex-start; min-height: 29mm; padding: 0 7mm 0 10mm; }
        .requester { width: 75mm; padding-top: 2mm; }
        .closing { width: 72mm; text-align: center; }
        .signature-space { height: 7mm; }
        .signature-line { display: inline-block; min-width: 48mm; border-bottom: 1px dotted #777; }
        .signature-name { margin-top: 1mm; color: #f00000; font-weight: 700; }
        .approval-table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 2mm; }
        .approval-table th, .approval-table td { border: 1px solid #000; }
        .approval-table th { height: 6mm; font-size: 9px; font-weight: 400; }
        .approval-table td { height: 20mm; }
        .page-number { position: absolute; right: 8mm; bottom: 3mm; color: #666; font-size: 8px; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { width: auto; min-height: 0; margin: 0; box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="toolbar"><button type="button" onclick="window.print()">พิมพ์เอกสาร A4</button></div>

    @foreach ($pages as $pageIndex => $rows)
        @php
            $pageRows = collect($rows);
            $invoiceDateValues = $pageRows->pluck('invoice_date')->filter()->unique()->values();
            $invoiceDate = $invoiceDateValues->isNotEmpty() ? \Carbon\Carbon::parse($invoiceDateValues->first()) : now();
            $thaiMonths = [1 => 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
            $poReferences = $pageRows
                ->filter(fn ($row) => trim((string) $row->customer_po) !== '')
                ->map(fn ($row) => trim((string) $row->customer_po) . ' ลงวันที่ ' . \Carbon\Carbon::parse($row->invoice_date)->format('d/m/Y'))
                ->unique()
                ->implode(', ');
            $pagePackageQty = $pageRows->sum(fn ($row) => (int) $row->package_qty);
            $pageNetWeight = $pageRows->sum(fn ($row) => (float) $row->net_weight_kg);
            $pageGrossWeight = $pageRows->sum(fn ($row) => (float) $row->gross_weight_kg);
            $pageAmount = $pageRows->sum(fn ($row) => (float) $row->line_amount);
        @endphp

        <section class="sheet">
            <div class="form-code">กศก.๐๒</div>
            <h1 class="document-title">คำร้องขอส่งของในราชอาณาจักรเข้าไปใน<span class="blue">เขตปลอดอากร/เขตประกอบการเสรี</span></h1>

            <div class="doc-meta">
                <div>เลขที่ <span class="line"></span></div>
                <div>
                    วันที่ <span class="line short">{{ $invoiceDate->day }}</span>
                    เดือน <span class="line medium">{{ $thaiMonths[$invoiceDate->month] }}</span>
                    พ.ศ. <span class="line medium">{{ $invoiceDate->year + 543 }}</span>
                </div>
            </div>

            <div class="letter-body">
                <p>เรื่อง ขออนุญาตนำของในราชอาณาจักรเข้าไปใน<span class="blue">เขตปลอดอากร/เขตประกอบการเสรี</span></p>
                <p>เรียน เจ้าหน้าที่ศุลกากรผู้ควบคุมเขตปลอดอากร</p>
                <p class="indent">ข้าพเจ้า <span class="company-line">บริษัท แม่น้ำสแตนเลสไวร์ จำกัด (มหาชน)</span></p>
                <p>เลขประจำตัวนิติบุคคล <span class="blue">0107550000262</span> มีความประสงค์นำสินค้าตามรายการด้านล่างเข้าไปในเขตปลอดอากร</p>
                <p>โดยมีรายละเอียดตาม Invoice และใบสั่งซื้อของลูกค้าดังต่อไปนี้</p>
                <div class="po-line red">เลขที่ใบสั่งซื้อ {{ $poReferences ?: '-' }}</div>
            </div>

            <table class="items-table">
                <thead>
                    <tr>
                        <th class="w-seq">ลำดับ</th>
                        <th class="w-package">จำนวนหีบห่อ</th>
                        <th class="w-net">น้ำหนักสุทธิ</th>
                        <th class="w-qty">ปริมาณ</th>
                        <th class="w-price">ราคาของ</th>
                        <th class="w-desc">ชนิดของ</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $rowIndex => $row)
                        <tr>
                            <td class="center">{{ ($pageIndex * 5) + $rowIndex + 1 }}</td>
                            <td class="center">
                                {{ number_format((int) $row->package_qty) }} ลัง
                                @if (trim((string) $row->package_numbers) !== '')
                                    <div class="item-reference">{{ $row->package_numbers }}</div>
                                @endif
                            </td>
                            <td class="right nowrap">{{ number_format((float) $row->net_weight_kg, 2) }} กก.</td>
                            <td class="center">
                                {{ number_format((float) $row->qty, 2) }} {{ trim((string) $row->unit) }}
                                <span class="gross-note">(น้ำหนักรวม {{ number_format((float) $row->gross_weight_kg, 2) }} กก.)</span>
                            </td>
                            <td class="right nowrap">
                                {{ number_format((float) $row->line_amount, 2) }}
                                <div class="item-reference">@ {{ number_format((float) $row->unit_price, 2) }}</div>
                            </td>
                            <td class="item-description">
                                @if (trim((string) $row->partnumber) !== '')
                                    <strong>{{ $row->partnumber }}</strong><br>
                                @endif
                                {!! nl2br(e(trim((string) $row->description))) !!}
                                <div class="item-reference red">INV {{ $row->invoice_no }} / {{ $row->packing_list_no }}</div>
                            </td>
                        </tr>
                    @endforeach

                    @for ($emptyRow = count($rows); $emptyRow < 5; $emptyRow++)
                        <tr>
                            @for ($column = 0; $column < 6; $column++)
                                <td>&nbsp;</td>
                            @endfor
                        </tr>
                    @endfor
                </tbody>
                <tfoot>
                    <tr>
                        <td class="center">รวม</td>
                        <td class="center">{{ number_format($pagePackageQty) }} ลัง</td>
                        <td class="right">{{ number_format($pageNetWeight, 2) }} กก.</td>
                        <td class="center"><span class="gross-note">(น้ำหนักรวม {{ number_format($pageGrossWeight, 2) }} กก.)</span></td>
                        <td class="right">{{ number_format($pageAmount, 2) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>

            <div class="under-table"><span class="blue">จำนวนหีบห่อ (ตัวอักษร)</span> <span class="line" style="min-width: 75mm;"></span></div>

            <div class="signature-row">
                <div class="requester">ผู้ยื่นคำร้อง <span class="signature-line"></span></div>
                <div class="closing">
                    <div>ขอแสดงความนับถือ</div>
                    <div class="signature-space"></div>
                    <div>ลงชื่อ <span class="signature-line"></span></div>
                    <div class="signature-name">({{ $signerName }})</div>
                    <div>เจ้าของ/ผู้ส่งออก/ตัวแทน</div>
                    <div>ประทับตราบริษัท (ถ้ามี)</div>
                </div>
            </div>

            <table class="approval-table">
                <thead><tr><th>บันทึกการอนุญาตของพนักงานศุลกากร</th><th>บันทึกการตรวจของพนักงานศุลกากร</th></tr></thead>
                <tbody><tr><td></td><td></td></tr></tbody>
            </table>

            <div class="page-number">หน้า {{ $pageIndex + 1 }} / {{ count($pages) }}</div>
        </section>
    @endforeach
</body>
</html>
