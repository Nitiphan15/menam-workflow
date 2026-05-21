<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Order Due Date Dashboard</title>
    <style>
        @page { size: A4 landscape; margin: 8mm; }
        body { font-family: DejaVu Sans, Tahoma, sans-serif; font-size: 11px; color: #0f172a; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #d1d5db; padding: 5px; vertical-align: top; }
        th { background: #f1f5f9; }
        .title { font-size: 22px; font-weight: 700; margin-bottom: 4px; }
        .muted { color: #64748b; }
        .grid td { border: 0; padding: 0 6px 8px 0; width: 25%; }
        .card { border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px; min-height: 52px; }
        .label { color: #64748b; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .value { font-size: 18px; font-weight: 700; }
        .section { margin-top: 10px; }
        .right { text-align: right; }
    </style>
</head>
<body>
    @php
        $dash = $dashboard ?? [];
        $fmt = fn($v) => number_format((float) $v, 0);
        $pct = fn($v) => $v === null ? '-' : number_format((float) $v, 1) . '%';
    @endphp

    <div class="title">Order Due Date Dashboard</div>
    <div class="muted">
        {{ $dash['labels']['selected_month'] ?? '' }} · generated {{ ($generatedAt ?? now())->format('Y-m-d H:i') }}
    </div>

    <table class="grid section">
        <tr>
            <td><div class="card"><div class="label">Current</div><div class="value">{{ $fmt($dash['current_total'] ?? 0) }}</div></div></td>
            <td><div class="card"><div class="label">YoY</div><div class="value">{{ $pct($dash['change_percent'] ?? null) }}</div></div></td>
            <td><div class="card"><div class="label">Target Achievement</div><div class="value">{{ $pct($dash['target_achievement'] ?? null) }}</div></div></td>
            <td><div class="card"><div class="label">YTD YoY</div><div class="value">{{ $pct($dash['ytd_change_percent'] ?? null) }}</div></div></td>
        </tr>
    </table>

    <div class="section">
        <strong>Division Comparison</strong>
        <table>
            <thead><tr><th>Division</th><th class="right">Current</th><th class="right">Previous</th><th class="right">YoY</th></tr></thead>
            <tbody>
                @foreach ($dash['division_compare'] ?? [] as $row)
                    <tr>
                        <td>{{ $row['code'] }} · {{ $row['label'] }}</td>
                        <td class="right">{{ $fmt($row['current'] ?? 0) }}</td>
                        <td class="right">{{ $fmt($row['previous'] ?? 0) }}</td>
                        <td class="right">{{ $pct($row['change_percent'] ?? null) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section">
        <strong>Product Type Achievement</strong>
        <table>
            <thead><tr><th>Product Type</th><th class="right">Order</th><th class="right">Target</th><th class="right">Achievement</th></tr></thead>
            <tbody>
                @foreach ($dash['type_compare'] ?? [] as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td class="right">{{ $fmt($row['current'] ?? 0) }}</td>
                        <td class="right">{{ $fmt($row['target'] ?? 0) }}</td>
                        <td class="right">{{ $pct($row['achievement'] ?? null) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</body>
</html>
