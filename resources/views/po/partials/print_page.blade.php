@php
    $rows = collect($detailRows ?? []);
    $subtotal = $rows->sum(fn($row) => (float) ($row->extended_price ?? 0));
    $grandAmount = (float) ($po->amount ?? 0);
    $netTotal = (float) ($po->netamount ?? $subtotal);
    $vat = max($grandAmount - $netTotal, 0);
    $prRef = trim((string) ($headerRow->quotenumber ?? '')) !== '' ? trim((string) $headerRow->quotenumber) : '-';
    $commentText = trim((string) ($po->notes ?? '')) !== '' ? trim((string) $po->notes) : '......................................................';
    $commentLines = collect(preg_split('/\r\n|\r|\n/', $commentText))->map(fn($line) => trim((string) $line))->filter()->values();
    $primaryComment = $commentLines->shift() ?? $commentText;
    $logoUrl = asset('assets/logo.png');
    $vendorAddress = trim((string) ($headerRow->addr1 ?? '') . ' ' . (string) ($headerRow->addr2 ?? ''));
    $vendorAddress = $vendorAddress !== '' ? $vendorAddress : '-';

    $thaiBahtText = function (float $amount): string {
        $number = number_format($amount, 2, '.', '');
        [$integerPart, $decimalPart] = explode('.', $number);
        $readNumber = function (string $value) use (&$readNumber): string {
            $value = ltrim($value, '0');
            if ($value === '') return '';
            if (strlen($value) > 6) return $readNumber(substr($value, 0, -6)) . 'ล้าน' . $readNumber(substr($value, -6));
            $digits = ['ศูนย์', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'];
            $positions = ['', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน'];
            $result = '';
            $length = strlen($value);
            for ($i = 0; $i < $length; $i++) {
                $digit = (int) $value[$i];
                if ($digit === 0) continue;
                $pos = $length - $i - 1;
                if ($pos === 0 && $digit === 1 && $length > 1) $result .= 'เอ็ด';
                elseif ($pos === 1 && $digit === 1) $result .= 'สิบ';
                elseif ($pos === 1 && $digit === 2) $result .= 'ยี่สิบ';
                else $result .= $digits[$digit] . $positions[$pos];
            }
            return $result;
        };
        $bahtText = $readNumber($integerPart) ?: 'ศูนย์';
        return $decimalPart === '00'
            ? $bahtText . 'บาทถ้วน'
            : $bahtText . 'บาท' . $readNumber($decimalPart) . 'สตางค์';
    };
    $grandAmountText = $thaiBahtText($grandAmount);

    $fmtQty = fn($value) => number_format((float) ($value ?? 0), 2);
    $fmtUnit = fn($value) => number_format((float) ($value ?? 0), 3);
    $fmtMoney = fn($value) => number_format((float) ($value ?? 0), 2);
@endphp

<div class="po-print-page">
    <img class="logo" src="{{ $logoUrl }}" alt="MENAM">

    <span class="txt bold" style="left:247.5px;top:58.5px;font-size:16.5px;">MENAM STAINLESS WIRE PUBLIC CO.,LTD.</span>
    <span class="txt" style="left:247.5px;top:84px;font-size:15px;">299 Moo 6, T.Bangpreang, A.Bangbor, Samutprakarn 10560</span>
    <span class="txt" style="left:247.5px;top:105px;font-size:15px;">Tel. (+66) 2 725 3999 Fax. (+66) 2 725 3901, 3933</span>
    <span class="txt" style="left:247.5px;top:126px;font-size:15px;">Tax ID.0107550000262</span>

    <div class="box" style="left:680px;top:53px;width:160px;height:94px;"></div>
    <div class="line-v" style="left:765px;top:53px;height:40px;"></div>
    <div class="line-h" style="left:680px;top:80px;width:160px;"></div>
    <div class="line-h" style="left:680px;top:104px;width:160px;"></div>
    <span class="txt center" style="left:680px;top:55.5px;width:85px;font-size:10.5px;">Date</span>
    <span class="txt center" style="left:680px;top:63px;width:85px;font-size:24px;">{{ optional($po->transdate)->format('d-M-Y') }}</span>
    <span class="txt center" style="left:765px;top:55.5px;width:75px;font-size:10.5px;">Page</span>
    <span class="txt center" style="left:765px;top:63px;width:75px;font-size:24px;">1/1</span>
    <span class="txt center" style="left:680px;top:100.5px;width:160px;font-size:10.5px;">Purchase Order Number</span>
    <span class="txt center" style="left:680px;top:109.5px;width:160px;font-size:24px;">{{ $po->ordnumber }}</span>

    <div class="box" style="left:342px;top:171px;width:230px;height:45px;"></div>
    <span class="txt center bold" style="left:342px;top:178.5px;width:230px;font-size:24px;">Purchase Order</span>

    <div class="box" style="left:75px;top:240px;width:765px;height:132px;"></div>
    <span class="txt" style="left:75px;top:252px;font-size:13.5px;">Vendor Name :</span>
    <span class="txt" style="left:180px;top:241.5px;font-size:24px;">{{ $po->vendor_name ?: '-' }}</span>
    <span class="txt" style="left:75px;top:279px;font-size:13.5px;">Vendor Address :</span>
    <span class="txt" style="left:195px;top:268.5px;font-size:24px;">{{ $vendorAddress }}</span>
    <span class="txt" style="left:75px;top:324px;font-size:13.5px;">Contact Person :</span>
    <span class="txt" style="left:195px;top:313.5px;font-size:24px;">{{ $headerRow->contact ?? '-' }}</span>
    <span class="txt" style="left:195px;top:346.5px;font-size:13.5px;">Tel :</span>
    <span class="txt" style="left:225px;top:336px;font-size:24px;">{{ $headerRow->phone ?? '-' }}</span>
    <span class="txt" style="left:475.5px;top:346.5px;font-size:13.5px;">Fax :</span>
    <span class="txt" style="left:510px;top:336px;font-size:24px;">{{ $headerRow->fax ?? '-' }}</span>

    <div class="box" style="left:75px;top:372px;width:765px;height:42px;"></div>
    <div class="line-h" style="left:75px;top:393px;width:765px;"></div>
    @foreach ([195,345,525,690,755] as $x)
        <div class="line-v" style="left:{{ $x }}px;top:372px;height:42px;"></div>
    @endforeach
    <span class="txt center" style="left:75px;top:379.5px;width:120px;font-size:13.5px;">PR No. Ref</span>
    <span class="txt center" style="left:75px;top:397.5px;width:120px;font-size:24px;">{{ $prRef }}</span>
    <span class="txt center" style="left:195px;top:379.5px;width:150px;font-size:13.5px;">Department Request</span>
    <span class="txt center" style="left:195px;top:397.5px;width:150px;font-size:24px;">{{ $po->f1 ?: '-' }}</span>
    <span class="txt center" style="left:345px;top:379.5px;width:180px;font-size:13.5px;">Requestor</span>
    <span class="txt center" style="left:345px;top:397.5px;width:180px;font-size:24px;">{{ $po->requester_name ?: '-' }}</span>
    <span class="txt center" style="left:525px;top:379.5px;width:165px;font-size:13.5px;">Payment Term</span>
    <span class="txt center" style="left:525px;top:399px;width:165px;font-size:22.5px;">{{ $po->terms ?: '-' }}</span>
    <span class="txt center" style="left:690px;top:379.5px;width:65px;font-size:13.5px;">Currency</span>
    <span class="txt center" style="left:690px;top:397.5px;width:65px;font-size:24px;">{{ $po->curr ?: '-' }}</span>
    <span class="txt center" style="left:755px;top:379.5px;width:85px;font-size:13.5px;">Delivery Date</span>
    <span class="txt center" style="left:755px;top:397.5px;width:85px;font-size:24px;">{{ optional($po->reqdate)->format('d-M-Y') }}</span>

    <div class="box" style="left:75px;top:441px;width:765px;height:570px;"></div>
    <div class="line-h" style="left:75px;top:486px;width:765px;"></div>
    @foreach ([105,431,510,580,700] as $x)
        <div class="line-v" style="left:{{ $x }}px;top:441px;height:570px;"></div>
    @endforeach
    @for ($i = 1; $i <= 5; $i++)
        <div class="line-h" style="left:75px;top:{{ 486 + ($i * 72) }}px;width:765px;"></div>
    @endfor
    <span class="txt center" style="left:75px;top:457.5px;width:30px;font-size:15px;">NO.</span>
    <span class="txt center" style="left:105px;top:457.5px;width:326px;font-size:15px;">Description</span>
    <span class="txt center" style="left:431px;top:457.5px;width:79px;font-size:15px;">Qty</span>
    <span class="txt center" style="left:510px;top:457.5px;width:70px;font-size:15px;">UOM</span>
    <span class="txt center" style="left:580px;top:457.5px;width:120px;font-size:15px;">Unit Cost</span>
    <span class="txt center" style="left:700px;top:457.5px;width:140px;font-size:15px;">Extended Price</span>

    @foreach ($rows->take(5)->values() as $index => $row)
        @php
            $itemTop = 493.5 + ($index * 72);
            $descriptionLines = collect(preg_split('/\r\n|\r|\n/', trim((string) ($row->description ?? ''))))->filter()->values();
        @endphp
        <span class="txt center" style="left:75px;top:{{ $itemTop }}px;width:30px;font-size:24px;">{{ $index + 1 }}</span>
        @foreach ($descriptionLines->take(4) as $lineIndex => $line)
            <span class="txt" style="left:112.5px;top:{{ $itemTop + ($lineIndex * 25.5) }}px;font-size:24px;">{{ $line }}</span>
        @endforeach
        <span class="txt right" style="left:431px;top:{{ $itemTop }}px;width:74px;font-size:24px;">{{ $fmtQty($row->qty ?? 0) }}</span>
        <span class="txt center" style="left:510px;top:{{ $itemTop }}px;width:70px;font-size:24px;">{{ $row->item_unit ?: ($row->purchase_unit ?: '-') }}</span>
        <span class="txt right" style="left:580px;top:{{ $itemTop }}px;width:112px;font-size:24px;">{{ $fmtUnit($row->sellprice ?? 0) }}</span>
        <span class="txt right" style="left:700px;top:{{ $itemTop }}px;width:132px;font-size:24px;">{{ $fmtMoney($row->extended_price ?? 0) }}</span>
    @endforeach

    @foreach ([821,857,893,929,965] as $y)
        <div class="line-h" style="left:580px;top:{{ $y }}px;width:260px;"></div>
    @endforeach
    <div class="line-h" style="left:75px;top:857px;width:765px;"></div>
    <div class="line-h" style="left:75px;top:965px;width:765px;"></div>
    <span class="txt" style="left:588px;top:837px;font-size:15px;">Discount</span>
    <span class="txt" style="left:588px;top:873px;font-size:15px;">Total</span>
    <span class="txt" style="left:588px;top:909px;font-size:15px;">Net Total</span>
    <span class="txt" style="left:588px;top:945px;font-size:15px;">VAT</span>
    <span class="txt" style="left:588px;top:981px;font-size:15px;">Grand Amount</span>
    <span class="txt right" style="left:700px;top:828px;width:132px;font-size:24px;">0.00</span>
    <span class="txt right" style="left:700px;top:864px;width:132px;font-size:24px;">{{ $fmtMoney($subtotal) }}</span>
    <span class="txt right" style="left:700px;top:900px;width:132px;font-size:24px;">{{ $fmtMoney($netTotal) }}</span>
    <span class="txt right" style="left:700px;top:936px;width:132px;font-size:24px;">{{ $fmtMoney($vat) }}</span>
    <span class="txt right" style="left:700px;top:972px;width:132px;font-size:24px;">{{ $fmtMoney($grandAmount) }}</span>

    <span class="txt" style="left:75px;top:873px;font-size:15px;">Comments :</span>
    <span class="txt" style="left:157.5px;top:864px;font-size:24px;">{{ $primaryComment }}</span>
    @foreach ($commentLines->take(2) as $index => $commentLine)
        <span class="txt center" style="left:75px;top:{{ 900 + ($index * 34) }}px;width:505px;font-size:24px;">{{ $commentLine }}</span>
    @endforeach
    <span class="txt center bold" style="left:75px;top:972px;width:505px;font-size:24px;">***{{ $grandAmountText }}***</span>

    <div class="box" style="left:75px;top:1011px;width:765px;height:57px;"></div>
    <span class="txt wrap" style="left:75px;top:1014px;width:760px;font-size:12px;">
        This Purchase Order shall be governed by Supplier Manual (SP-QMS-03). Additionally, all material and products covered under this Purchase
        Order shall be delivered per the latest product specification maintained by Menam Stainless Wire Public Co., Ltd.<br>
        First article inspection has been cerificated and signed by the supplier and complies with the requirement associated with the material ordered.
    </span>

    <div class="box" style="left:75px;top:1086px;width:765px;height:132px;"></div>
    @foreach ([330,585] as $x)
        <div class="line-v" style="left:{{ $x }}px;top:1086px;height:132px;"></div>
    @endforeach
    <span class="txt center" style="left:585px;top:1095px;width:255px;font-size:12px;">Please sign and return to us by fax<br>inacknowledgement</span>
    <span class="txt center" style="left:75px;top:1156.5px;width:255px;font-size:13.5px;">......................................................</span>
    <span class="txt center" style="left:75px;top:1179px;width:255px;font-size:13.5px;">Ordered by</span>
    <span class="txt center" style="left:75px;top:1209px;width:255px;font-size:13.5px;">Date : ...........................................</span>
    <span class="txt center" style="left:330px;top:1156.5px;width:255px;font-size:13.5px;">......................................................</span>
    <span class="txt center" style="left:330px;top:1179px;width:255px;font-size:13.5px;">Authorized by</span>
    <span class="txt center" style="left:330px;top:1209px;width:255px;font-size:13.5px;">Date : ...........................................</span>
    <span class="txt center" style="left:585px;top:1156.5px;width:255px;font-size:13.5px;">......................................................</span>
    <span class="txt center" style="left:585px;top:1179px;width:255px;font-size:13.5px;">P/O confirmed by</span>
    <span class="txt center" style="left:585px;top:1209px;width:255px;font-size:13.5px;">Date : ...........................................</span>
    <span class="txt center" style="left:75px;top:1242px;width:765px;font-size:10.5px;">Effective Date: 1 June 2019</span>
    <span class="txt right" style="left:700px;top:1242px;width:140px;font-size:10.5px;">PU-3 Rev.05</span>
</div>
