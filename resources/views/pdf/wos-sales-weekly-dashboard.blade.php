<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>WOS Delivery Volume Dashboard</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 7mm;
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
            color: #111827;
            font-family: "THSarabunNew", "DejaVu Sans", Tahoma, sans-serif;
            font-size: 13px;
            line-height: 1.2;
            margin: 0;
        }

        h1,
        h2,
        h3,
        p {
            margin: 0;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        th,
        td {
            border: 0.5px solid #d1d5db;
            padding: 4px 5px;
            vertical-align: top;
        }

        th {
            background: #eef2ff;
            color: #1f2937;
            font-weight: 700;
        }

        .muted {
            color: #6b7280;
        }

        .header {
            border-bottom: 2px solid #1d4ed8;
            margin-bottom: 8px;
            padding-bottom: 6px;
        }

        .title {
            color: #111827;
            font-size: 22px;
            font-weight: 700;
        }

        .subtitle {
            font-size: 13px;
            margin-top: 2px;
        }

        .kpi-table td {
            border: 0.5px solid #bfdbfe;
            width: 25%;
        }

        .kpi-label {
            color: #475569;
            font-size: 11px;
            font-weight: 700;
        }

        .kpi-value {
            color: #0f172a;
            font-size: 22px;
            font-weight: 700;
            line-height: 1.1;
        }

        .section {
            margin-top: 8px;
        }

        .section-title {
            color: #111827;
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .right {
            text-align: right;
        }

        .center {
            text-align: center;
        }

        .positive {
            color: #047857;
        }

        .negative {
            color: #dc2626;
        }

        .two-col {
            width: 100%;
        }

        .two-col>tbody>tr>td {
            border: none;
            padding: 0;
            width: 50%;
        }

        .two-col>tbody>tr>td:first-child {
            padding-right: 5px;
        }

        .two-col>tbody>tr>td:last-child {
            padding-left: 5px;
        }
    </style>
</head>

<body>
    @php
        $dash = $dashboard ?? [];
        $current = $dash['current'] ?? [];
        $previous = $dash['previous'] ?? [];
        $ytdCurrent = $dash['ytd_current'] ?? [];
        $ytdPrevious = $dash['ytd_previous'] ?? [];
        $pace = $dash['working_day_pace'] ?? [];
        $unit = $dash['unit'] ?? 'Ton';
        $decimals = $unit === 'Baht' ? 0 : 2;
        $comparisonRows = $dash['comparison_rows'] ?? [];
        $typeRows = $dash['all_type_drivers'] ?? ($dash['type_drivers'] ?? []);
        $customerRows = $dash['customer_drivers'] ?? [];
        $fmt = fn($v) => number_format((float) $v, $decimals);
        $fmtSigned = fn($v) => ((float) $v > 0 ? '+' : '') . number_format((float) $v, $decimals);
        $fmtChange = function ($v) {
            if ($v === null) {
                return 'New';
            }

            return ((float) $v > 0 ? '+' : '') . number_format((float) $v, 2) . '%';
        };
        $changeClass = fn($v) => (float) ($v ?? 0) < 0 ? 'negative' : 'positive';
    @endphp

    <div class="header">
        <div class="title">{{ $pdfTitle ?? 'WOS Delivery Volume Dashboard' }}</div>
        <div class="subtitle">
            {{ $dash['division_name'] ?? '' }} | {{ $dash['kpis']['selected_label'] ?? '' }} | Unit: {{ $unit }}
        </div>
        <div class="muted">Generated: {{ optional($generatedAt ?? null)->format('Y-m-d H:i') }}</div>
    </div>

    <table class="kpi-table">
        <tr>
            <td>
                <div class="kpi-label">Current Month</div>
                <div class="kpi-value">{{ $fmt($current['total'] ?? 0) }}</div>
                <div class="muted">{{ $dash['kpis']['selected_label'] ?? '' }}</div>
            </td>
            <td>
                <div class="kpi-label">Same Month Last Year</div>
                <div class="kpi-value">{{ $fmt($previous['total'] ?? 0) }}</div>
                <div class="muted">{{ $dash['kpis']['previous_label'] ?? '' }}</div>
            </td>
            <td>
                <div class="kpi-label">YoY Change</div>
                <div class="kpi-value {{ $changeClass($dash['change_percent'] ?? 0) }}">{{ $fmtChange($dash['change_percent'] ?? null) }}</div>
                <div class="muted">Current vs previous year</div>
            </td>
            <td>
                <div class="kpi-label">Target</div>
                <div class="kpi-value">{{ ($dash['target_achievement_percent'] ?? null) === null ? '-' : number_format((float) $dash['target_achievement_percent'], 2) . '%' }}</div>
                <div class="muted">{{ ($dash['target'] ?? 0) > 0 ? $fmt($dash['target']) . ' ' . $unit : '-' }}</div>
            </td>
        </tr>
    </table>

    <div class="section">
        <div class="section-title">Summary</div>
        <table>
            <tr>
                <th>YTD Current</th>
                <th>YTD Previous</th>
                <th>YTD Change</th>
                <th>Average / Working Day</th>
                <th>Projected Month End</th>
                <th>SO</th>
                <th>Customer</th>
            </tr>
            <tr>
                <td class="right">{{ $fmt($ytdCurrent['total'] ?? 0) }}</td>
                <td class="right">{{ $fmt($ytdPrevious['total'] ?? 0) }}</td>
                <td class="right {{ $changeClass($dash['ytd_change_percent'] ?? 0) }}">{{ $fmtChange($dash['ytd_change_percent'] ?? null) }}</td>
                <td class="right">{{ $fmt($pace['average_per_day'] ?? 0) }}</td>
                <td class="right">{{ $fmt($pace['projected_total'] ?? 0) }}</td>
                <td class="right">{{ number_format((int) ($current['so_count'] ?? ($current['order_count'] ?? 0))) }}</td>
                <td class="right">{{ number_format((int) ($current['customer_count'] ?? 0)) }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Weekly Volume</div>
        <table>
            <tr>
                <th></th>
                <th class="right">W1</th>
                <th class="right">W2</th>
                <th class="right">W3</th>
                <th class="right">W4</th>
                <th class="right">W5</th>
                <th class="right">Total</th>
            </tr>
            <tr>
                <td>{{ $dash['kpis']['selected_label'] ?? 'Current' }}</td>
                @foreach (($current['weeks'] ?? []) as $value)
                    <td class="right">{{ $fmt($value) }}</td>
                @endforeach
                <td class="right">{{ $fmt($current['total'] ?? 0) }}</td>
            </tr>
            <tr>
                <td>{{ $dash['kpis']['previous_label'] ?? 'Previous' }}</td>
                @foreach (($previous['weeks'] ?? []) as $value)
                    <td class="right">{{ $fmt($value) }}</td>
                @endforeach
                <td class="right">{{ $fmt($previous['total'] ?? 0) }}</td>
            </tr>
        </table>
    </div>

    @if (count($comparisonRows) > 1)
        <div class="section">
            <div class="section-title">Division Comparison</div>
            <table>
                <tr>
                    <th>Division</th>
                    <th class="right">Current</th>
                    <th class="right">Previous</th>
                    <th class="right">YoY</th>
                    <th class="right">YTD</th>
                    <th class="right">SO</th>
                    <th class="right">Customer</th>
                </tr>
                @foreach ($comparisonRows as $row)
                    @php($rowDecimals = ($row['unit'] ?? $unit) === 'Baht' ? 0 : 2)
                    <tr>
                        <td>{{ $row['division_code'] ?? '' }} - {{ $row['division_name'] ?? '' }} ({{ $row['unit'] ?? $unit }})</td>
                        <td class="right">{{ number_format((float) ($row['current_total'] ?? 0), $rowDecimals) }}</td>
                        <td class="right">{{ number_format((float) ($row['previous_total'] ?? 0), $rowDecimals) }}</td>
                        <td class="right {{ $changeClass($row['change_percent'] ?? 0) }}">{{ $fmtChange($row['change_percent'] ?? null) }}</td>
                        <td class="right">{{ number_format((float) ($row['ytd_current_total'] ?? 0), $rowDecimals) }}</td>
                        <td class="right">{{ number_format((int) ($row['so_count'] ?? ($row['order_count'] ?? 0))) }}</td>
                        <td class="right">{{ number_format((int) ($row['customer_count'] ?? 0)) }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    <table class="two-col section">
        <tr>
            <td>
                <div class="section-title">Top Customer Movement</div>
                <table>
                    <tr>
                        <th>Customer</th>
                        <th class="right">Current</th>
                        <th class="right">Previous</th>
                        <th class="right">Diff</th>
                    </tr>
                    @forelse ($customerRows as $row)
                        <tr>
                            <td>{{ $row['label'] ?? '' }}</td>
                            <td class="right">{{ $fmt($row['current'] ?? 0) }}</td>
                            <td class="right">{{ $fmt($row['previous'] ?? 0) }}</td>
                            <td class="right {{ $changeClass($row['diff'] ?? 0) }}">{{ $fmtSigned($row['diff'] ?? 0) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="center muted">No data</td>
                        </tr>
                    @endforelse
                </table>
            </td>
            <td>
                <div class="section-title">Product Type Movement (All Types)</div>
                <table>
                    <tr>
                        <th>Product</th>
                        <th class="right">Current</th>
                        <th class="right">Previous</th>
                        <th class="right">Diff</th>
                    </tr>
                    @forelse ($typeRows as $row)
                        <tr>
                            <td>{{ $row['label'] ?? '' }}</td>
                            <td class="right">{{ $fmt($row['current'] ?? 0) }}</td>
                            <td class="right">{{ $fmt($row['previous'] ?? 0) }}</td>
                            <td class="right {{ $changeClass($row['diff'] ?? 0) }}">{{ $fmtSigned($row['diff'] ?? 0) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="center muted">No data</td>
                        </tr>
                    @endforelse
                </table>
            </td>
        </tr>
    </table>
</body>

</html>
