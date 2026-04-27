<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Purchase Order Batch {{ $department }}</title>
    <style>
        @page {
            size: A4;
            margin: 4mm 4mm 5mm 4mm;
        }

        html, body {
            margin: 0;
            padding: 0;
            width: 210mm;
            min-height: 297mm;
            font-family: Tahoma, Arial, Helvetica, sans-serif;
            color: #000;
            font-size: 12.6px;
            line-height: 1.28;
        }

        body {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .po-print-page {
            width: 190mm;
            height: 287mm;
            margin: 0 auto;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            padding-top: 10mm;
        }

        .po-print-page + .po-print-page {
            page-break-before: always;
        }

        .clearfix::after { content: ""; display: block; clear: both; }
        .left { float: left; }
        .right { float: right; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .fw-bold { font-weight: 700; }
        .small { font-size: 9.8px; }
        .mt-4 { margin-top: 4px; }
        .mt-6 { margin-top: 6px; }
        .mt-8 { margin-top: 8px; }
        .mb-8 { margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #000; padding: 4px 5px; vertical-align: top; }
        .header-logo { width: 24%; }
        .header-logo img { width: 128px; max-width: 100%; display: block; margin-top: 2px; }
        .header-company { width: 46%; padding-top: 1px; }
        .company-title { font-size: 14.4px; font-weight: 700; letter-spacing: 0.2px; margin-bottom: 5px; }
        .header-info { width: 22%; }
        .header-info table { table-layout: fixed; }
        .header-info table td { padding: 4px 6px; font-size: 9.6px; text-align: center; }
        .header-info .po-number { font-size: 16.8px; font-weight: 700; text-align: center; }
        .title-wrap { text-align: center; margin: 22px 0 17px; }
        .title-box { display: inline-block; border: 2px solid #000; padding: 5px 18px 6px; font-size: 22px; font-weight: 700; line-height: 1; }
        .vendor-box { border: 1px solid #000; padding: 9px 10px 11px; }
        .vendor-detail-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .vendor-detail-table td { border: 0; padding: 2px 0; font-size: 12.4px; line-height: 1.28; vertical-align: top; }
        .vendor-detail-table .vendor-label { width: 108px; white-space: nowrap; font-weight: 400; }
        .meta-table th, .meta-table td { text-align: center; font-size: 11.8px; padding: 4px 4px; }
        .meta-table th, .item-table th { font-weight: 400; }
        .item-table th, .item-table td { font-size: 12.5px; padding: 5px 6px; }
        .item-table th { text-align: center; }
        .no-col { width: 4.5%; }
        .desc-col { width: 43.5%; }
        .qty-col { width: 9%; }
        .uom-col { width: 8%; }
        .unit-col { width: 12%; }
        .ext-col { width: 13%; }
        .desc-cell { white-space: pre-line; }
        .filler-row td { border-top: 0; border-bottom: 0; padding: 0; }
        .filler-row td:first-child { border-left: 1px solid #000; }
        .filler-row td:last-child { border-right: 1px solid #000; }
        .summary-label { font-weight: 400; text-align: left; width: 12%; white-space: nowrap; font-size: 12.5px; }
        .comments-cell { padding: 8px 8px; white-space: pre-line; font-size: 12.5px; }
        .notice-cell { text-align: center; font-size: 11.5px; padding: 8px 6px; font-weight: 700; letter-spacing: 0.2px; }
        .policy-box td { font-size: 9.4px; line-height: 1.24; padding: 5px 6px; }
        .sign-table { margin-top: auto; }
        .sign-table td { height: 92px; vertical-align: top; position: relative; }
        .sign-top-note { text-align: center; font-size: 8.5px; line-height: 1.15; margin-bottom: 20px; font-weight: 400; }
        .sign-role { text-align: center; margin-top: 14px; font-size: 10px; font-weight: 400; }
        .sign-subline { text-align: center; font-size: 9px; min-height: 13px; margin-top: 29px; }
        .sign-pair { margin-top: 8px; }
        .dot-line { display: block; margin: 1px auto 6px; width: 82%; border-bottom: 1px dotted #000; height: 0; }
        .date-line { margin-top: 10px; font-size: 10px; }
        .footer-bar { width: 100%; margin-top: 2px; font-size: 8px; }
        .footer-left { float: left; }
        .footer-right { float: right; }

        a,
        a:visited,
        a:hover,
        a:active {
            color: inherit;
            text-decoration: none !important;
        }

        .po-print-page {
            width: 190mm;
            height: 288mm;
            position: relative;
            padding-top: 2mm;
            padding-bottom: 0;
        }

        .small { font-size: 8.8px; }
        .mt-8 { margin-top: 6px; }
        .mb-8 { margin-bottom: 7px; }
        .header-logo { width: 22%; }
        .header-logo img { width: 140px; margin-top: 5px; }
        .header-company { width: 50%; padding-top: 2px; }
        .company-title { font-size: 18px; letter-spacing: 0; margin-bottom: 3px; }
        .header-info { width: 24%; }
        .header-info table td {
            padding: 0 6px;
            font-size: 9.6px;
            vertical-align: middle;
            line-height: 1;
            font-family: Tahoma, Arial, Helvetica, sans-serif;
            text-decoration: none !important;
        }
        .header-info .header-info-head td { height: 16px; font-size: 8px; border-bottom: 0; }
        .header-info .header-info-value td { height: 25px; font-size: 11px; white-space: nowrap; border-top: 0; }
        .header-info .header-info-label td { height: 16px; font-size: 8px; border-bottom: 0; }
        .header-info .po-number {
            height: 26px;
            line-height: 26px;
            border-top: 0;
            font-size: 14px;
            font-weight: 400;
            font-family: Tahoma, Arial, Helvetica, sans-serif;
            text-decoration: none !important;
        }
        .title-wrap { margin: 8px 0 8px; }
        .title-box { padding: 4px 14px 5px; font-size: 22px; }
        .vendor-box { padding: 8px 10px 9px; }
        .vendor-detail-table td { padding: 1px 0; font-size: 12.4px; line-height: 1.26; }
        .vendor-detail-table .vendor-label { width: 96px; }
        .meta-table th, .meta-table td { font-size: 11.4px; padding: 4px 4px; }
        .item-table th, .item-table td {
            font-size: 11px;
            padding: 0 5px;
            line-height: 1.18;
            vertical-align: middle;
            text-decoration: none !important;
        }
        .item-table th {
            height: 34px;
            font-family: Tahoma, Arial, Helvetica, sans-serif;
        }
        .item-table tbody td {
            font-family: Tahoma, Arial, Helvetica, sans-serif;
        }
        .item-table tbody tr.item-row td {
            border-bottom: 0;
            vertical-align: top;
            padding-top: 8px;
            padding-bottom: 8px;
        }
        .item-table tbody tr.item-row td:first-child { text-align: center; }
        .item-table tbody tr.item-row td:nth-child(3),
        .item-table tbody tr.item-row td:nth-child(4),
        .item-table tbody tr.item-row td:nth-child(5),
        .item-table tbody tr.item-row td:nth-child(6) { padding-top: 8px; }
        .item-table tbody tr:not(.item-row):not(.filler-row) td {
            padding-top: 4px;
            padding-bottom: 4px;
            line-height: 1;
        }
        .desc-cell {
            padding-top: 8px !important;
            padding-bottom: 8px !important;
            line-height: 1.34;
        }
        .summary-label { font-size: 10.8px; }
        .comments-cell { padding: 6px 8px; font-size: 11px; line-height: 1.25; }
        .notice-cell { font-size: 10.8px; padding: 6px 6px; letter-spacing: 0.15px; }
        .policy-box td { font-size: 7.6px; line-height: 1.14; padding: 3px 5px; }
        .sign-footer-wrap {
            position: static;
            margin-top: 8px;
            page-break-inside: avoid;
            break-inside: avoid;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
        }
        .sign-table {
            margin-top: 0;
            margin-bottom: 0;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .sign-table td { height: 134px; vertical-align: top; padding: 0; }
        .sign-box {
            height: 134px;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
            padding: 10px 14px 9px;
        }
        .sign-box.sign-box-note { padding-top: 6px; }
        .sign-note {
            text-align: center;
            font-size: 8px;
            line-height: 1.1;
            min-height: 18px;
            margin-bottom: 4px;
        }
        .signature-slot {
            height: 22px;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            gap: 4px;
            margin-bottom: -1px;
            position: relative;
            z-index: 2;
        }
        .signature-slot img {
            max-width: 74px;
            max-height: 20px;
            background: transparent;
        }
        .sign-line {
            position: relative;
            z-index: 1;
            text-align: center;
            font-size: 10px;
            letter-spacing: 0.08px;
            line-height: 1;
            white-space: nowrap;
            overflow: hidden;
        }
        .sign-role {
            text-align: center;
            font-size: 8px;
            margin-top: 4px;
            line-height: 1.1;
        }
        .sign-date {
            font-size: 7.8px;
            line-height: 1.1;
            margin-top: auto;
        }
        .sign-date-fill {
            display: inline-block;
            min-width: 88px;
        }
        .signature-footer {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0;
            margin-bottom: 0;
            font-size: 6.8px;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .signature-footer td { border: 0; padding: 0; }

        html,
        body,
        table,
        th,
        td {
            font-family: "Cordia New", Tahoma, Arial, Helvetica, sans-serif;
        }

        html,
        body {
            font-size: 24px;
            line-height: 1;
        }

        .small { font-size: 15px; }
        .company-title { font-size: 27px; }
        .header-info table td { font-family: "Cordia New", Tahoma, Arial, Helvetica, sans-serif; }
        .header-info .header-info-head td,
        .header-info .header-info-label td { font-size: 14px; }
        .header-info .header-info-value td { font-size: 18px; }
        .header-info .po-number {
            font-family: "Cordia New", Tahoma, Arial, Helvetica, sans-serif;
            font-size: 22px;
            font-weight: 700;
        }
        .title-box { font-size: 31px; }
        .vendor-detail-table td { font-size: 21px; }
        .meta-table th,
        .meta-table td { font-size: 19px; }
        .item-table th,
        .item-table td {
            font-family: "Cordia New", Tahoma, Arial, Helvetica, sans-serif;
            font-size: 21px;
        }
        .item-table tbody td { font-family: "Cordia New", Tahoma, Arial, Helvetica, sans-serif; }
        .summary-label,
        .comments-cell,
        .notice-cell { font-size: 20px; }
        .policy-box td { font-size: 16px; line-height: 1.02; }
        .sign-note,
        .sign-role,
        .sign-date { font-size: 17px; }
        .sign-line { font-size: 19px; }
        .signature-footer { font-size: 10px; }

        .header-logo { width: 22%; }
        .header-logo img { width: 150px; filter: grayscale(1); }
        .header-company {
            width: 53%;
            white-space: nowrap;
        }
        .header-info { width: 22%; }
        .company-title {
            font-size: 21px;
            white-space: nowrap;
        }
        .header-company div:not(.company-title) { font-size: 16px; }
        .title-box { font-size: 25px; padding-left: 18px; padding-right: 18px; }
        .vendor-detail-table .vendor-label { width: 116px; }
        .vendor-detail-table td { font-size: 18px; }
        .meta-table th,
        .meta-table td {
            font-size: 15px;
            white-space: nowrap;
        }
        .meta-table th:nth-child(1),
        .meta-table td:nth-child(1) { width: 15%; }
        .meta-table th:nth-child(2),
        .meta-table td:nth-child(2) { width: 18%; }
        .meta-table th:nth-child(3),
        .meta-table td:nth-child(3) { width: 20%; }
        .meta-table th:nth-child(4),
        .meta-table td:nth-child(4) { width: 21%; }
        .meta-table th:nth-child(5),
        .meta-table td:nth-child(5) { width: 11%; }
        .meta-table th:nth-child(6),
        .meta-table td:nth-child(6) { width: 15%; }
        .desc-col { width: 40%; }
        .qty-col { width: 8.5%; }
        .uom-col { width: 8%; }
        .unit-col { width: 13%; }
        .ext-col { width: 16%; }
        .item-table th,
        .item-table td {
            font-size: 18.5px;
        }
        .item-table th {
            height: 30px;
            white-space: nowrap;
        }
        .summary-label,
        .comments-cell,
        .notice-cell { font-size: 18px; }
        .policy-box {
            margin-top: 4px;
            border: 0;
        }
        .policy-box td {
            font-size: 12px;
            line-height: 1.18;
            padding: 2px 0;
            border: 0;
        }
        .po-print-page { padding-bottom: 0; }
        .sign-table td,
        .sign-box { height: 124px; }
        .sign-note,
        .sign-role,
        .sign-date { font-size: 13px; }
        .sign-line { font-size: 15px; }
        .signature-footer { font-size: 9px; }
        .sign-role { margin-top: 12px; }
    </style>
</head>
<body>
    @foreach ($documents as $document)
        @include('po.partials.print_page', [
            'po' => $document['po'],
            'detailRows' => $document['detailRows'],
            'headerRow' => $document['headerRow'],
            'signatures' => $document['signatures'],
        ])
    @endforeach
</body>
</html>
