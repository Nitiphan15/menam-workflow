<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>คำร้องขอส่งของเข้าเขตปลอดอากร</title>
    <style>
        @page { size: A4 portrait; margin: 8mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e5e7eb; color: #000; font-family: "Cordia New", Tahoma, Arial, sans-serif; font-size: 12pt; line-height: 1; }
        .toolbar { position: sticky; top: 0; z-index: 10; padding: 10px; text-align: center; background: #111827; }
        .toolbar button { border: 0; border-radius: 6px; padding: 8px 20px; background: #2563eb; color: white; font-weight: 700; cursor: pointer; }
        .sheet { position: relative; width: 194mm; min-height: 281mm; margin: 10px auto; padding: 15mm 8mm 5mm; background: #fff; page-break-after: always; }
        .sheet:last-child { page-break-after: auto; }
        .stamp-space { position: absolute; top: 5mm; left: 8mm; width: 38mm; height: 29mm; }
        .form-code { text-align: right; font-weight: 700; margin-bottom: 7mm; }
        .document-title { margin: 0; text-align: center; font-size: 12pt; font-weight: 700; }
        .blue { color: #0000d4; }
        .red { color: #f00000; }
        .doc-meta { width: 86mm; margin: 4mm 0 4mm auto; }
        .doc-meta > div { margin-bottom: 1.5mm; }
        .line { display: inline-block; min-width: 30mm; height: 4mm; border-bottom: 1px dotted #777; vertical-align: bottom; text-align: center; }
        .line.short { min-width: 13mm; }
        .line.medium { min-width: 22mm; }
        .letter-body { line-height: 1.18; }
        .letter-body p { margin: .8mm 0; }
        .indent { text-indent: 14mm; }
        .company-line { display: inline-block; min-width: 82mm; border-bottom: 1px dotted #777; text-align: center; }
        .fill-line { display: inline-block; border-bottom: 1px dotted #777; text-align: center; vertical-align: bottom; }
        .customer-line { min-width: 92mm; color: #f00000; font-weight: 700; }
        .po-line { min-height: 6mm; margin: 1.5mm 0; line-height: 1.35; }
        .items-table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 12pt; }
        .items-table th, .items-table td { border: 1px solid #000; padding: .6mm .8mm; vertical-align: top; }
        .items-table th { height: 6mm; padding: .5mm; text-align: center; vertical-align: middle; font-weight: 700; }
        .items-table tbody td { border-top: 0; border-bottom: 0; vertical-align: middle; }
        .items-table tbody tr:first-child td { border-top: 1px solid #000; }
        .items-table tbody tr:last-child td { border-bottom: 1px solid #000; }
        .items-table tfoot td { height: 8mm; vertical-align: middle; font-weight: 700; }
        .center { text-align: center; }
        .right { text-align: right; }
        .nowrap { white-space: nowrap; }
        .item-description { font-size: 12pt; line-height: 1; overflow-wrap: anywhere; }
        .item-reference { margin-top: .5mm; font-size: 12pt; line-height: 1; }
        .gross-note { display: block; margin-top: .5mm; font-size: 12pt; line-height: 1; white-space: normal; }
        .w-seq { width: 6%; }
        .w-package { width: 14%; }
        .w-net { width: 14%; }
        .w-qty { width: 16%; }
        .w-price { width: 13%; }
        .w-desc { width: 37%; }
        .under-table { min-height: 5mm; padding-top: .5mm; }
        .signature-row { display: flex; justify-content: space-between; align-items: flex-start; min-height: 24mm; padding: 0 7mm 0 10mm; }
        .requester { width: 75mm; min-height: 22mm; padding-top: 1mm; }
        .closing { width: 72mm; text-align: center; }
        .signature-space { height: 3mm; }
        .signature-line { display: inline-block; min-width: 48mm; border-bottom: 1px dotted #777; }
        .signature-name { margin-top: 1mm; color: #f00000; font-weight: 700; }
        .approval-section { position: absolute; left: 8mm; right: 8mm; bottom: 3mm; }
        .approval-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .approval-table th, .approval-table td { border: 1px solid #000; }
        .approval-table th { height: 5mm; font-size: 12pt; font-weight: 400; }
        .approval-table td { height: 18mm; }
        .page-number { position: absolute; right: 8mm; bottom: 3mm; color: #666; font-size: 12pt; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { width: auto; height: auto; min-height: 270mm; margin: 0; box-shadow: none; }
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
            $bodyRowHeight = 80 / max($pageRows->count(), 1);
            $pageAmountText = \App\Services\FormWOS\InvoicePackingDocumentService::amountInThaiText($pageAmount);
            $invoiceReferences = $pageRows
                ->pluck('invoice_no')
                ->map(fn ($number) => trim((string) $number))
                ->filter()
                ->unique()
                ->implode(',');
            $invoiceDates = $pageRows
                ->pluck('invoice_date')
                ->filter()
                ->map(fn ($date) => \Carbon\Carbon::parse($date)->format('d/m/Y'))
                ->unique()
                ->implode(', ');
            $customerNames = $pageRows
                ->pluck('customer_name')
                ->map(fn ($name) => trim((string) $name))
                ->filter()
                ->unique()
                ->implode(', ');
            $customerProvinces = $pageRows
                ->pluck('customer_province')
                ->map(fn ($province) => trim((string) $province))
                ->filter()
                ->unique()
                ->implode(', ');
        @endphp

        <section class="sheet">
            <div class="stamp-space" aria-hidden="true"></div>
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
                <p>เรียน หัวหน้าฝ่ายบริการศุลกากรที่ ๒ คทม.</p>
                <p class="indent">ด้วยข้าพเจ้า บริษัท/ห้าง/ร้าน <span class="company-line">บริษัท แม่น้ำสแตนเลสไวร์ จำกัด (มหาชน)</span></p>
                <p><span class="blue">เลขทะเบียนนิติบุคคล</span> <span class="fill-line" style="min-width: 35mm;">0107550000262</span> ที่ตั้งเลขที่ <span class="fill-line" style="min-width: 16mm;">299</span> หมู่ <span class="fill-line" style="min-width: 12mm;">6</span> ซอย <span class="fill-line" style="min-width: 18mm;">-</span> ถนน <span class="fill-line" style="min-width: 31mm;">-</span></p>
                <p>แขวง/ตำบล <span class="fill-line" style="min-width: 29mm;">บางเพรียง</span> อำเภอ <span class="fill-line" style="min-width: 27mm;">บางบ่อ</span> จังหวัด <span class="fill-line" style="min-width: 34mm;">สมุทรปราการ</span></p>
                <p>รหัสไปรษณีย์ <span class="fill-line" style="min-width: 28mm;">10560</span> โทรศัพท์ <span class="fill-line" style="min-width: 38mm;">(02)725 3999</span></p>
                <p class="indent">มีความประสงค์จะนำผลิตภัณฑ์ภายในประเทศเข้า<span class="blue">เขตปลอดอากร/เขตประกอบการเสรี</span> ซึ่งจำหน่ายให้แก่</p>
                <p>บริษัท <span class="fill-line customer-line">{{ $customerNames ?: '-' }}</span> ซึ่งตั้งอยู่ใน<span class="blue">เขตปลอดอากร/เขตประกอบการเสรี</span> นิคมอุตสาหกรรมภาคเหนือ จังหวัด{{ $customerProvinces ?: '-' }}</p>
                <div class="po-line">ตามใบสั่งซื้อเลขที่ <span class="red">{{ $poReferences ?: '-' }}</span> ดังรายการต่อไปนี้</div>
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
                        <tr style="height: {{ number_format($bodyRowHeight, 2, '.', '') }}mm;">
                            <td class="center">{{ ($pageIndex * 5) + $rowIndex + 1 }}</td>
                            <td class="center">
                                {{ number_format((int) $row->package_qty) }} ลัง
                            </td>
                            <td class="center nowrap">{{ number_format((float) $row->net_weight_kg, 2) }} กก.</td>
                            <td class="center">
                                {{ number_format((int) $row->package_qty) }} ลัง
                                <span class="gross-note">(น้ำหนักรวม {{ number_format((float) $row->gross_weight_kg, 2) }} กก.)</span>
                            </td>
                            <td class="center nowrap">{{ number_format((float) $row->line_amount, 2) }}</td>
                            <td class="item-description">
                                @php($materialType = \App\Services\FormWOS\InvoicePackingDocumentService::materialType($row->description))
                                @if ($materialType !== '')
                                    {{ $materialType }}<br>
                                @endif
                                {!! nl2br(e(trim((string) $row->description))) !!}
                            </td>
                        </tr>
                    @endforeach

                </tbody>
                <tfoot>
                    <tr>
                        <td class="center">รวม</td>
                        <td class="center">{{ number_format($pagePackageQty) }} ลัง</td>
                        <td class="center">{{ number_format($pageNetWeight, 2) }} กก.</td>
                        <td class="center"><span class="gross-note">(น้ำหนักรวม {{ number_format($pageGrossWeight, 2) }} กก.)</span></td>
                        <td class="center">{{ number_format($pageAmount, 2) }}</td>
                        <td class="item-description">
                            <div>รายละเอียด ตามบัญชีราคาสินค้า/</div>
                            <div>ใบกำกับภาษีเลขที่ <span class="red">{{ $invoiceReferences }} ({{ $invoiceDates }})</span></div>
                        </td>
                    </tr>
                </tfoot>
            </table>

            <div class="under-table"><span class="blue">จำนวนเงิน (ตัวอักษร)</span> {{ $pageAmountText }} <span class="line" style="min-width: 25mm;"></span></div>

            <div class="signature-row">
                <div class="requester">จึงเรียนมาเพื่อโปรดทราบ</div>
                <div class="closing">
                    <div>ขอแสดงความนับถือ</div>
                    <div class="signature-space"></div>
                    <div>ลงชื่อ <span class="signature-line"></span></div>
                    <div class="signature-name">({{ $signerName }})</div>
                    <div>เจ้าของ/ผู้ส่งออก/ตัวแทน</div>
                    <div>ประทับตราบริษัท (ถ้ามี)</div>
                </div>
            </div>

            <div class="approval-section">
                <table class="approval-table">
                    <thead><tr><th>บันทึกการอนุญาตของพนักงานศุลกากร</th><th>บันทึกการตรวจของพนักงานศุลกากร</th></tr></thead>
                    <tbody><tr><td></td><td></td></tr></tbody>
                </table>
            </div>

            <div class="page-number">หน้า {{ $pageIndex + 1 }} / {{ count($pages) }}</div>
        </section>
    @endforeach
</body>
</html>
