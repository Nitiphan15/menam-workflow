<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>WOS Qty Dashboard</title>
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
            background: #f8fafc;
            color: #0f172a;
            font-family: "THSarabunNew", "DejaVu Sans", Tahoma, sans-serif;
            font-size: 13px;
            line-height: 1.18;
            margin: 0;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        th,
        td {
            border: 0.5px solid #d8dee9;
            padding: 4px 5px;
            vertical-align: top;
        }

        th {
            background: #f1f5f9;
            color: #334155;
            font-weight: 700;
        }

        .header {
            margin-bottom: 8px;
        }

        .title {
            font-size: 24px;
            font-weight: 700;
            line-height: 1;
        }

        .muted {
            color: #64748b;
        }

        .unit {
            border: 1px solid #86efac;
            border-radius: 14px;
            color: #166534;
            float: right;
            font-weight: 700;
            padding: 3px 10px;
        }

        .filter-box,
        .card {
            background: #fff;
            border: 0.6px solid #d1d5db;
            border-radius: 6px;
            margin-bottom: 8px;
            padding: 7px;
        }

        .kpi td {
            background: #fff;
            border: 0;
            padding: 0 5px 0 0;
            width: 25%;
        }

        .kpi-card {
            border: 0.6px solid #e2e8f0;
            border-radius: 6px;
            min-height: 54px;
            padding: 7px;
        }

        .kpi-label {
            color: #64748b;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .kpi-value {
            font-size: 22px;
            font-weight: 700;
            line-height: 1.05;
        }

        .section-title {
            font-size: 16px;
            font-weight: 700;
            margin: 0 0 5px;
        }

        .right {
            text-align: right;
        }

        .center {
            text-align: center;
        }

        .positive {
            color: #15803d;
        }

        .negative {
            color: #dc2626;
        }

        .layout td {
            border: 0;
            padding: 0;
            vertical-align: top;
        }

        .left {
            padding-right: 6px !important;
            width: 58%;
        }

        .right-col {
            padding-left: 6px !important;
            width: 42%;
        }

        .trend-cell {
            height: 18px;
        }

        .trend-chart {
            height: 250px;
            width: 100%;
        }

        .legend {
            margin-top: 2px;
            text-align: center;
        }

        .legend-item {
            display: inline-block;
            margin: 0 12px;
        }

        .legend-line {
            border-top: 3px solid #16a34a;
            display: inline-block;
            margin-right: 4px;
            vertical-align: middle;
            width: 28px;
        }

        .legend-line.dashed {
            border-top-style: dashed;
        }

        .legend + table {
            display: none;
        }

        .small {
            font-size: 11px;
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
        $unit = $dash['unit'] ?? 'Qty';
        $decimals = in_array($unit, ['Qty', 'Baht'], true) ? 0 : 2;
        $comparisonRows = $dash['comparison_rows'] ?? [];
        $typeCompareRows = $dash['all_type_compare'] ?? ($dash['type_compare'] ?? []);
        $customerRows = $dash['all_customer_drivers'] ?? ($dash['customer_drivers'] ?? []);
        $typeRows = $dash['all_type_drivers'] ?? ($dash['type_drivers'] ?? []);
        $monthLabels = $dash['month_labels'] ?? [];
        $currentTrend = $dash['current_year_trend'] ?? [];
        $previousTrend = $dash['previous_year_trend'] ?? [];
        $trendDatasets = $dash['division_trend_datasets'] ?? [];
        if (empty($trendDatasets)) {
            $trendDatasets = [
                [
                    'label' => trim(($dash['division_code'] ?? '') . ' ' . ($filters['year'] ?? '')),
                    'data' => $currentTrend,
                    'borderColor' => '#16a34a',
                ],
                [
                    'label' => trim(($dash['division_code'] ?? '') . ' ' . (((int) ($filters['year'] ?? 0)) - 1)),
                    'data' => $previousTrend,
                    'borderColor' => '#16a34a',
                    'borderDash' => [6, 4],
                ],
            ];
        }
        $trendValues = [];
        foreach ($trendDatasets as $dataset) {
            foreach (($dataset['data'] ?? []) as $value) {
                if ($value !== null) {
                    $trendValues[] = $value;
                }
            }
        }
        $maxTrend = max($trendValues ?: [1]);
        $scaleStep = max(1, ceil($maxTrend / 5));
        $scaleMax = $scaleStep * 5;
        $chartWidth = 700;
        $chartHeight = 250;
        $plotLeft = 52;
        $plotTop = 16;
        $plotWidth = 610;
        $plotHeight = 178;
        $plotBottom = $plotTop + $plotHeight;
        $pointFor = function (int $index, $value) use ($plotLeft, $plotTop, $plotWidth, $plotHeight, $scaleMax) {
            $x = $plotLeft + (($plotWidth / 11) * $index);
            $y = $plotTop + ($plotHeight - (((float) $value / $scaleMax) * $plotHeight));

            return round($x, 2) . ',' . round($y, 2);
        };
        $segmentsFor = function (array $values) use ($pointFor) {
            $segments = [];
            $segment = [];

            foreach (array_values($values) as $index => $value) {
                if ($value === null) {
                    if (count($segment) > 1) {
                        $segments[] = implode(' ', $segment);
                    }
                    $segment = [];
                    continue;
                }

                $segment[] = $pointFor($index, $value);
            }

            if (count($segment) > 1) {
                $segments[] = implode(' ', $segment);
            }

            return $segments;
        };
        $scaleTicks = [];
        for ($i = 0; $i <= 5; $i++) {
            $scaleTicks[] = [
                'value' => ($scaleMax / 5) * $i,
                'y' => $plotBottom - (($plotHeight / 5) * $i),
            ];
        }
        $monthPositions = [];
        foreach ($monthLabels as $index => $label) {
            $monthPositions[] = [
                'label' => $label,
                'x' => $plotLeft + (($plotWidth / 11) * $index),
            ];
        }
        $pointsFor = function (array $values) use ($pointFor) {
            $points = [];
            foreach (array_values($values) as $index => $value) {
                if ($value === null) {
                    continue;
                }
                $point = explode(',', $pointFor($index, $value));
                $points[] = [
                    'x' => $point[0] ?? 0,
                    'y' => $point[1] ?? 0,
                ];
            }
            return $points;
        };
        $svgDatasets = [];
        foreach ($trendDatasets as $dataset) {
            $values = $dataset['data'] ?? [];
            $svgDatasets[] = [
                'label' => $dataset['label'] ?? '',
                'color' => $dataset['borderColor'] ?? '#16a34a',
                'dashed' => !empty($dataset['borderDash']),
                'segments' => $segmentsFor($values),
                'points' => $pointsFor($values),
            ];
        }
        $svg = '<svg width="' . $chartWidth . '" height="' . $chartHeight . '" viewBox="0 0 ' . $chartWidth . ' ' . $chartHeight . '" xmlns="http://www.w3.org/2000/svg">';
        $svg .= '<rect x="0" y="0" width="' . $chartWidth . '" height="' . $chartHeight . '" fill="#ffffff"/>';
        foreach ($scaleTicks as $tick) {
            $svg .= '<line x1="' . $plotLeft . '" y1="' . $tick['y'] . '" x2="' . ($plotLeft + $plotWidth) . '" y2="' . $tick['y'] . '" stroke="#d9dee7" stroke-width="0.7"/>';
            $svg .= '<text x="' . ($plotLeft - 7) . '" y="' . ($tick['y'] + 3) . '" font-family="DejaVu Sans,Tahoma,sans-serif" font-size="9" fill="#64748b" text-anchor="end">' . number_format($tick['value'], 0) . '</text>';
        }
        foreach ($monthPositions as $month) {
            $svg .= '<line x1="' . $month['x'] . '" y1="' . $plotTop . '" x2="' . $month['x'] . '" y2="' . $plotBottom . '" stroke="#e5e7eb" stroke-width="0.6"/>';
            $svg .= '<text x="' . $month['x'] . '" y="' . ($plotBottom + 17) . '" font-family="DejaVu Sans,Tahoma,sans-serif" font-size="10" fill="#334155" text-anchor="middle">' . htmlspecialchars((string) $month['label'], ENT_QUOTES, 'UTF-8') . '</text>';
        }
        $svg .= '<line x1="' . $plotLeft . '" y1="' . $plotTop . '" x2="' . $plotLeft . '" y2="' . $plotBottom . '" stroke="#cbd5e1" stroke-width="0.9"/>';
        $svg .= '<line x1="' . $plotLeft . '" y1="' . $plotBottom . '" x2="' . ($plotLeft + $plotWidth) . '" y2="' . $plotBottom . '" stroke="#cbd5e1" stroke-width="0.9"/>';
        foreach ($svgDatasets as $dataset) {
            foreach ($dataset['segments'] as $points) {
                $svg .= '<polyline points="' . htmlspecialchars($points, ENT_QUOTES, 'UTF-8') . '" fill="none" stroke="' . htmlspecialchars((string) $dataset['color'], ENT_QUOTES, 'UTF-8') . '" stroke-width="3"' . ($dataset['dashed'] ? ' stroke-dasharray="7 5"' : '') . '/>';
            }
            foreach ($dataset['points'] as $point) {
                $svg .= '<circle cx="' . $point['x'] . '" cy="' . $point['y'] . '" r="3" fill="#ffffff" stroke="' . htmlspecialchars((string) $dataset['color'], ENT_QUOTES, 'UTF-8') . '" stroke-width="1.6"/>';
            }
        }
        $svg .= '<text x="13" y="' . ($plotTop + ($plotHeight / 2)) . '" font-family="DejaVu Sans,Tahoma,sans-serif" font-size="10" fill="#334155" transform="rotate(-90 13 ' . ($plotTop + ($plotHeight / 2)) . ')">' . htmlspecialchars($unit, ENT_QUOTES, 'UTF-8') . '</text>';
        $svg .= '</svg>';
        $trendChartSrc = 'data:image/svg+xml;base64,' . base64_encode($svg);
        $fmt = fn($v) => number_format((float) $v, $decimals);
        $fmtSigned = fn($v) => ((float) $v > 0 ? '+' : '') . number_format((float) $v, $decimals);
        $fmtChange = function ($v) {
            if ($v === null) {
                return 'รายการใหม่';
            }

            return ((float) $v > 0 ? '+' : '') . number_format((float) $v, 2) . '%';
        };
        $changeClass = fn($v) => (float) ($v ?? 0) < 0 ? 'negative' : 'positive';
    @endphp

    <div class="header">
        <span class="unit">หน่วย: {{ $unit }}</span>
        <div class="title">Dashboard เปรียบเทียบยอดขาย Qty</div>
        <div class="muted">{{ $dash['division_name'] ?? '' }}</div>
    </div>

    <div class="filter-box">
        <strong>Division:</strong> {{ implode(', ', (array) ($filters['division'] ?? [])) }}
        <span style="margin-left:24px;"><strong>เดือน:</strong> {{ $dash['kpis']['selected_label'] ?? '' }}</span>
        <span style="margin-left:24px;"><strong>Generated:</strong> {{ optional($generatedAt ?? null)->format('Y-m-d H:i') }}</span>
    </div>

    @if (count($comparisonRows) > 1)
        <div class="card">
            <div class="section-title">Division comparison</div>
            <table>
                <tr>
                    <th>Division</th>
                    <th class="right">Current</th>
                    <th class="right">Previous</th>
                    <th class="right">YoY</th>
                    <th class="right">YTD</th>
                    <th class="right">Order</th>
                    <th class="right">Customer</th>
                </tr>
                @foreach ($comparisonRows as $row)
                    @php($rowDecimals = in_array(($row['unit'] ?? $unit), ['Qty', 'Baht'], true) ? 0 : 2)
                    <tr>
                        <td><strong>{{ $row['division_code'] ?? '' }}</strong> <span class="muted">{{ $row['division_name'] ?? '' }}</span></td>
                        <td class="right">{{ number_format((float) ($row['current_total'] ?? 0), $rowDecimals) }}</td>
                        <td class="right">{{ number_format((float) ($row['previous_total'] ?? 0), $rowDecimals) }}</td>
                        <td class="right {{ $changeClass($row['change_percent'] ?? 0) }}">{{ $fmtChange($row['change_percent'] ?? null) }}</td>
                        <td class="right">{{ number_format((float) ($row['ytd_current_total'] ?? 0), $rowDecimals) }}</td>
                        <td class="right">{{ number_format((int) ($row['order_count'] ?? 0)) }}</td>
                        <td class="right">{{ number_format((int) ($row['customer_count'] ?? 0)) }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    <table class="kpi">
        <tr>
            <td>
                <div class="kpi-card">
                    <div class="kpi-label">ยอดเดือนนี้</div>
                    <div class="kpi-value">{{ $fmt($current['total'] ?? 0) }}</div>
                    <div class="muted small">{{ $dash['kpis']['selected_label'] ?? '' }} · {{ $unit }}</div>
                </div>
            </td>
            <td>
                <div class="kpi-card">
                    <div class="kpi-label">ยอดเดือนเดียวกันปีก่อน</div>
                    <div class="kpi-value">{{ $fmt($previous['total'] ?? 0) }}</div>
                    <div class="muted small">{{ $dash['kpis']['previous_label'] ?? '' }} · {{ $unit }}</div>
                </div>
            </td>
            <td>
                <div class="kpi-card">
                    <div class="kpi-label">เปลี่ยนแปลง YoY</div>
                    <div class="kpi-value {{ $changeClass($dash['change_percent'] ?? 0) }}">{{ $fmtChange($dash['change_percent'] ?? null) }}</div>
                    <div class="muted small">เทียบ division เดิม</div>
                </div>
            </td>
            <td>
                <div class="kpi-card">
                    <div class="kpi-label">เทียบเป้าหมาย</div>
                    <div class="kpi-value">-</div>
                    <div class="muted small">ยังไม่มีเป้าหมาย Qty คงที่</div>
                </div>
            </td>
        </tr>
    </table>

    <table class="kpi" style="margin-top:8px;">
        <tr>
            <td>
                <div class="kpi-card">
                    <div class="kpi-label">YTD เทียบปีก่อน</div>
                    <div class="kpi-value {{ $changeClass($dash['ytd_change_percent'] ?? 0) }}">{{ $fmtChange($dash['ytd_change_percent'] ?? null) }}</div>
                    <div class="muted small">{{ $fmt($ytdCurrent['total'] ?? 0) }} vs {{ $fmt($ytdPrevious['total'] ?? 0) }} {{ $unit }}</div>
                </div>
            </td>
            <td>
                <div class="kpi-card">
                    <div class="kpi-label">เฉลี่ยต่อวันทำงาน</div>
                    <div class="kpi-value">{{ $fmt($pace['average_per_day'] ?? 0) }}</div>
                    <div class="muted small">{{ number_format((int) ($pace['elapsed_working_days'] ?? 0)) }} / {{ number_format((int) ($pace['total_working_days'] ?? 0)) }} วันทำงาน</div>
                </div>
            </td>
            <td>
                <div class="kpi-card">
                    <div class="kpi-label">คาดการณ์สิ้นเดือน</div>
                    <div class="kpi-value">{{ $fmt($pace['projected_total'] ?? 0) }}</div>
                    <div class="muted small">จากยอดจริงต่อวันทำงาน</div>
                </div>
            </td>
            <td>
                <div class="kpi-card">
                    <div class="kpi-label">Order / ลูกค้า</div>
                    <div><strong>Order:</strong> {{ number_format((int) ($current['order_count'] ?? 0)) }}</div>
                    <div><strong>ลูกค้า:</strong> {{ number_format((int) ($current['customer_count'] ?? 0)) }}</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="card" style="margin-top:8px;">
        <strong>Insight:</strong> {{ $dash['insight'] ?? 'ข้อมูลไม่เพียงพอสำหรับสรุป Insight' }}
    </div>

    <table class="layout">
        <tr>
            <td class="left">
                <div class="card">
                    <div class="section-title">แนวโน้ม 12 เดือน</div>
                    @if (!empty($dash['trend_note']))
                        <div class="muted small">{{ $dash['trend_note'] }}</div>
                    @endif
                    <img class="trend-chart" src="{{ $trendChartSrc }}" alt="12 month trend chart">
                    <div class="legend">
                        @foreach ($svgDatasets as $dataset)
                            <span class="legend-item">
                                <span class="legend-line{{ $dataset['dashed'] ? ' dashed' : '' }}" style="border-top-color: {{ $dataset['color'] }};"></span>{{ $dataset['label'] }}
                            </span>
                        @endforeach
                    </div>
                    <table>
                        <tr>
                            <th>เดือน</th>
                            @foreach ($monthLabels as $label)
                                <th class="right">{{ $label }}</th>
                            @endforeach
                        </tr>
                        <tr>
                            <td>{{ $filters['year'] ?? '' }}</td>
                            @foreach ($currentTrend as $value)
                                <td class="right trend-cell">{{ $value === null ? '-' : $fmt($value) }}</td>
                            @endforeach
                        </tr>
                        <tr>
                            <td>{{ ((int) ($filters['year'] ?? 0)) - 1 }}</td>
                            @foreach ($previousTrend as $value)
                                <td class="right trend-cell">{{ $value === null ? '-' : $fmt($value) }}</td>
                            @endforeach
                        </tr>
                    </table>
                </div>
            </td>
            <td class="right-col">
                <div class="card">
                    <div class="section-title">สรุปตามประเภทสินค้า</div>
                    <table>
                        <tr>
                            <th>ประเภทสินค้า</th>
                            <th class="right">{{ $filters['year'] ?? '' }}</th>
                            <th class="right">{{ ((int) ($filters['year'] ?? 0)) - 1 }}</th>
                            <th class="right">YoY</th>
                        </tr>
                        @forelse ($typeCompareRows as $row)
                            @php($typeDiff = (float) ($row['current'] ?? 0) - (float) ($row['previous'] ?? 0))
                            <tr>
                                <td>{{ $row['type'] ?? '' }}</td>
                                <td class="right">{{ $fmt($row['current'] ?? 0) }}</td>
                                <td class="right">{{ $fmt($row['previous'] ?? 0) }}</td>
                                <td class="right {{ $changeClass($typeDiff) }}">{{ $fmtChange($row['change_percent'] ?? null) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="center muted">ไม่มีข้อมูล</td>
                            </tr>
                        @endforelse
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <table class="layout">
        <tr>
            <td class="left">
                <div class="card">
                    <div class="section-title">ลูกค้าที่ทำให้ยอดเปลี่ยนมากสุด</div>
                    <table>
                        <tr>
                            <th>ลูกค้า</th>
                            <th class="right">ปีนี้</th>
                            <th class="right">ปีก่อน</th>
                            <th class="right">ส่วนต่าง</th>
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
                                <td colspan="4" class="center muted">ไม่มีข้อมูล</td>
                            </tr>
                        @endforelse
                    </table>
                </div>
            </td>
            <td class="right-col">
                <div class="card">
                    <div class="section-title">ประเภทสินค้าที่ทำให้ยอดเปลี่ยนมากสุด</div>
                    <table>
                        <tr>
                            <th>ประเภทสินค้า</th>
                            <th class="right">ปีนี้</th>
                            <th class="right">ปีก่อน</th>
                            <th class="right">ส่วนต่าง</th>
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
                                <td colspan="4" class="center muted">ไม่มีข้อมูล</td>
                            </tr>
                        @endforelse
                    </table>
                </div>
            </td>
        </tr>
    </table>
</body>

</html>
