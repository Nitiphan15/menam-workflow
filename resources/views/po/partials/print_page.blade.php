@php
    $subtotal = collect($detailRows)->sum(fn($row) => (float) ($row->extended_price ?? 0));
    $grandAmount = (float) ($po->amount ?? 0);
    $netTotal = (float) ($po->netamount ?? $subtotal);
    $vat = max($grandAmount - $netTotal, 0);
    $detailCount = count($detailRows);
    $fillerHeight = max(0, 315 - $detailCount * 24);
    $prRef = trim((string) ($headerRow->quotenumber ?? '')) !== '' ? trim((string) $headerRow->quotenumber) : '-';
    $commentText =
        trim((string) $po->notes) !== ''
            ? trim((string) $po->notes)
            : '......................................................';
    $logoUrl = asset('assets/logo.png');
    $orderedBySignatures = collect($signatures['ordered_by'] ?? []);
    $authorizedBy = $signatures['authorized_by'] ?? null;
    $orderedByNames = $orderedBySignatures
        ->pluck('actor_name')
        ->filter(fn($name) => trim((string) $name) !== '')
        ->implode(' / ');
    $orderedBySignatureImages = $orderedBySignatures
        ->map(fn($row) => $row->signature_data_uri ?: $row->signature_file_url ?: null)
        ->filter()
        ->values();
    $orderedByDates = $orderedBySignatures
        ->pluck('created_at')
        ->filter()
        ->map(fn($date) => \Illuminate\Support\Carbon::parse($date)->format('d-M-Y'))
        ->unique()
        ->implode(' / ');

    $thaiBahtText = function (float $amount): string {
        $number = number_format($amount, 2, '.', '');
        [$integerPart, $decimalPart] = explode('.', $number);

        $readNumber = function (string $value) use (&$readNumber): string {
            $value = ltrim($value, '0');
            if ($value === '') {
                return '';
            }

            if (strlen($value) > 6) {
                $prefix = substr($value, 0, -6);
                $suffix = substr($value, -6);
                return $readNumber($prefix) . 'ล้าน' . $readNumber($suffix);
            }

            $digits = ['ศูนย์', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'];
            $positions = ['', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน'];
            $result = '';
            $length = strlen($value);

            for ($i = 0; $i < $length; $i++) {
                $digit = (int) $value[$i];
                if ($digit === 0) {
                    continue;
                }

                $pos = $length - $i - 1;

                if ($pos === 0 && $digit === 1 && $length > 1) {
                    $result .= 'เอ็ด';
                } elseif ($pos === 1 && $digit === 1) {
                    $result .= 'สิบ';
                } elseif ($pos === 1 && $digit === 2) {
                    $result .= 'ยี่สิบ';
                } else {
                    $result .= $digits[$digit] . $positions[$pos];
                }
            }

            return $result;
        };

        $bahtText = $readNumber($integerPart);
        if ($bahtText === '') {
            $bahtText = 'ศูนย์';
        }

        if ($decimalPart === '00') {
            return $bahtText . 'บาทถ้วน';
        }

        return $bahtText . 'บาท' . $readNumber($decimalPart) . 'สตางค์';
    };

    $grandAmountText = $thaiBahtText($grandAmount);
@endphp

<div class="po-print-page">
    <div class="clearfix mb-8">
        <div class="left header-logo">
            <img src="{{ $logoUrl }}" alt="MENAM">
            <div class="small">www.menamstainless.com</div>
        </div>

        <div class="left header-company">
            <div class="company-title">MENAM STAINLESS WIRE PUBLIC CO.,LTD.</div>
            <div>299 Moo 6. T.Bangpreang, A.Bangbor, Samutprakarn 10560</div>
            <div class="mt-4">Tel. (+66) 2 725 3999 Fax. (+66) 2 725 3901, 3933</div>
            <div class="mt-4">Tax ID.0107550000262</div>
        </div>

        <div class="right header-info">
            <table>
                <tr class="header-info-head">
                    <td>Date</td>
                    <td>Page</td>
                </tr>
                <tr class="header-info-value">
                    <td>{{ optional($po->transdate)->format('d-M-Y') }}</td>
                    <td>1/1</td>
                </tr>
                <tr class="header-info-label">
                    <td colspan="2" class="text-center">Purchase Order Number</td>
                </tr>
                <tr>
                    <td colspan="2" class="po-number">{{ $po->ordnumber }}</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="title-wrap">
        <div class="title-box">Purchase Order</div>
    </div>

    <div class="vendor-box">
        <table class="vendor-detail-table">
            <tr>
                <td class="vendor-label">Vendor Name :</td>
                <td>{{ $po->vendor_name }}</td>
            </tr>
            <tr>
                <td class="vendor-label">Vendor Address :</td>
                <td>{{ trim(($headerRow->addr1 ?? '') . ' ' . ($headerRow->addr2 ?? '')) }}</td>
            </tr>
            <tr>
                <td class="vendor-label">Contact Person :</td>
                <td>{{ $headerRow->contact ?? '-' }}</td>
            </tr>
            <tr>
                <td class="vendor-label"></td>
                <td>
                    Tel : {{ $headerRow->phone ?? '-' }}
                    <span style="display:inline-block; width: 120px;"></span>
                    Fax : {{ $headerRow->fax ?? '-' }}
                </td>
            </tr>
        </table>
    </div>

    <table class="meta-table mt-8">
        <tr>
            <th>PR No. Ref</th>
            <th>Department Request</th>
            <th>Requestor</th>
            <th>Payment Term</th>
            <th>Currency</th>
            <th>Delivery Date</th>
        </tr>
        <tr>
            <td>{{ $prRef }}</td>
            <td>{{ $po->f1 ?: '-' }}</td>
            <td>{{ $po->requester_name ?: '-' }}</td>
            <td>{{ $po->terms ?: '-' }}</td>
            <td>{{ $po->curr ?: '-' }}</td>
            <td>{{ optional($po->reqdate)->format('d-M-Y') }}</td>
        </tr>
    </table>

    <table class="item-table mt-8">
        <colgroup>
            <col class="no-col">
            <col class="desc-col">
            <col class="qty-col">
            <col class="uom-col">
            <col class="unit-col">
            <col class="ext-col">
        </colgroup>
        <thead>
            <tr>
                <th>NO.</th>
                <th>Description</th>
                <th>Qty</th>
                <th>UOM</th>
                <th>Unit Cost</th>
                <th>Extended Price</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($detailRows as $index => $row)
                <tr class="item-row">
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td class="desc-cell">{{ trim((string) ($row->description ?? '')) }}</td>
                    <td class="text-right">{{ number_format((float) ($row->qty ?? 0), 2) }}</td>
                    <td class="text-center">{{ $row->item_unit ?: ($row->purchase_unit ?: '-') }}</td>
                    <td class="text-right">{{ number_format((float) ($row->sellprice ?? 0), 3) }}</td>
                    <td class="text-right">{{ number_format((float) ($row->extended_price ?? 0), 2) }}</td>
                </tr>
            @endforeach

            @if ($fillerHeight > 0)
                <tr class="filler-row">
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td style="height: {{ $fillerHeight }}px;"></td>
                </tr>
            @endif

            <tr>
                <td colspan="4" rowspan="4" class="comments-cell">
                    <span class="fw-bold">Comments :</span> {{ $commentText }}
                </td>
                <td class="summary-label">Discount</td>
                <td class="text-right">0.00</td>
            </tr>
            <tr>
                <td class="summary-label">Total</td>
                <td class="text-right">{{ number_format($subtotal, 2) }}</td>
            </tr>
            <tr>
                <td class="summary-label">Net Total</td>
                <td class="text-right">{{ number_format($netTotal, 2) }}</td>
            </tr>
            <tr>
                <td class="summary-label">VAT</td>
                <td class="text-right">{{ number_format($vat, 2) }}</td>
            </tr>
            <tr>
                <td colspan="4" class="notice-cell">***{{ $grandAmountText }}***</td>
                <td class="summary-label">Grand Amount</td>
                <td class="text-right">{{ number_format($grandAmount, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="policy-box mt-6">
        <tr>
            <td>
                <div>
                    This Purchase Order shall be governed by Supplier Manual (SP-QMS-03). Additionally, all material
                    and products covered under this Purchase Order shall be delivered per the latest product
                    specification maintained by Menam Stainless Wire Public Co., Ltd.
                </div>
                <div class="mt-4">
                    First article inspection has been certified and signed by the supplier and complies
                    with the requirement associated with the material ordered.
                </div>
            </td>
        </tr>
    </table>

    @php
        $authorizedSignatureImage = $authorizedBy->signature_data_uri ?? ($authorizedBy->signature_file_url ?? null);
    @endphp

    <div class="sign-footer-wrap">
        <table class="sign-table">
            <tr>
                <td style="width: 32%;">
                    <div class="sign-box">
                        <div class="signature-slot">
                            @if ($orderedBySignatureImages->isNotEmpty())
                                @foreach ($orderedBySignatureImages as $signatureImage)
                                    <img src="{{ $signatureImage }}" alt="Ordered by signature">
                                @endforeach
                            @else
                                <span
                                    style="font-size: 10px;">{{ $orderedByNames !== '' ? $orderedByNames : '' }}</span>
                            @endif
                        </div>
                        <div class="sign-line">
                            ................................................................................................
                        </div>
                        <div class="sign-role">Ordered by</div>
                        <div class="sign-date">
                            Date :
                            <span class="sign-date-fill">
                                @if ($orderedByDates !== '')
                                    {{ $orderedByDates }}
                                @else
                                    .......................................
                                @endif
                            </span>
                        </div>
                    </div>
                </td>
                <td style="width: 32%;">
                    <div class="sign-box">
                        <div class="signature-slot">
                            @if (!empty($authorizedSignatureImage))
                                <img src="{{ $authorizedSignatureImage }}" alt="Authorized by signature">
                            @else
                                <span
                                    style="font-size: 10px;">{{ !empty($authorizedBy?->actor_name) ? $authorizedBy->actor_name : '' }}</span>
                            @endif
                        </div>
                        <div class="sign-line">
                            ................................................................................................
                        </div>
                        <div class="sign-role">Authorized by</div>
                        <div class="sign-date">
                            Date :
                            <span class="sign-date-fill">
                                @if (!empty($authorizedBy?->created_at))
                                    {{ \Illuminate\Support\Carbon::parse($authorizedBy->created_at)->format('d-M-Y') }}
                                @else
                                    .......................................
                                @endif
                            </span>
                        </div>
                    </div>
                </td>
                <td style="width: 36%;">
                    <div class="sign-box sign-box-note">
                        <div class="sign-note">
                            Please sign and return to us by fax<br>inacknowledgement
                        </div>
                        <div class="signature-slot"></div>
                        <div class="sign-line">
                            ................................................................................................
                        </div>
                        <div class="sign-role">P/O confirmed by</div>
                        <div class="sign-date">Date : <span
                                class="sign-date-fill">.......................................</span></div>
                    </div>
                </td>
            </tr>
        </table>

        <table class="signature-footer">
            <tr>
                <td style="width: 32%;"></td>
                <td style="width: 32%; text-align: center; font-size: 16px;">Effective Date: 1 June 2019</td>
                <td style="width: 36%; text-align: right; font-size: 16px; padding-right: 12px;">PU-3 Rev.05</td>
            </tr>
        </table>
    </div>
</div>
