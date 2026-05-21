<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Purchase Order {{ $po->ordnumber }}</title>
    <style>
        @page { size: 893px 1263px; margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body {
            width: 893px;
            min-height: 1263px;
            background: #fff;
            color: #000;
            font-family: "Angsana New", "Cordia New", Tahoma, Arial, Helvetica, sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .po-print-page {
            position: relative;
            width: 893px;
            height: 1263px;
            overflow: hidden;
            page-break-after: always;
            background: #fff;
            font-family: "Angsana New", "Cordia New", Tahoma, Arial, Helvetica, sans-serif;
        }
        .po-print-page:last-child { page-break-after: auto; }
        .box, .line-v, .line-h { position: absolute; border-color: #000; }
        .box { border: 1px solid #000; }
        .line-v { border-left: 1px solid #000; width: 0; }
        .line-h { border-top: 1px solid #000; height: 0; }
        .txt { position: absolute; white-space: nowrap; line-height: 1; color: #000; }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: 700; }
        .wrap { white-space: normal; line-height: 1.08; }
        img.logo { position: absolute; left: 52px; top: 60px; width: 180px; height: auto; }
    </style>
</head>
<body>
    @include('po.partials.print_page', [
        'po' => $po,
        'detailRows' => $detailRows,
        'headerRow' => $headerRow,
        'signatures' => $signatures,
    ])
</body>
</html>
