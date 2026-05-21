<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Order Due Date Detail</title>
    <style>
        @page { size: A4 landscape; margin: 7mm; }
        body { font-family: DejaVu Sans, Tahoma, sans-serif; font-size: 10px; color: #0f172a; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #d1d5db; padding: 4px; vertical-align: top; }
        th { background: #f1f5f9; }
        .title { font-size: 20px; font-weight: 700; margin-bottom: 4px; }
        .muted { color: #64748b; margin-bottom: 8px; }
        .right { text-align: right; }
        .center { text-align: center; }
        .summary { margin: 8px 0; }
    </style>
</head>
<body>
    @php
        $items = $detail['items'] ?? [];
        $fmt = fn($v) => number_format((float) $v, 2);
    @endphp
    <div class="title">Order Due Date Detail</div>
    <div class="muted">
        {{ $detail['title'] ?? '-' }} · {{ $detail['period_label'] ?? '-' }}
        @if (!empty($detail['type_name'])) · Type: {{ $detail['type_name'] }} @endif
        · Generated {{ ($generatedAt ?? now())->format('Y-m-d H:i') }}
    </div>
    <div class="summary">
        Metric {{ $fmt($detail['total_metric'] ?? 0) }}
        | Qty {{ $fmt($detail['total_qty'] ?? 0) }}
        | Amount {{ $fmt($detail['total_bath'] ?? 0) }}
    </div>
    <table>
        <thead>
            <tr>
                <th>Due Date</th>
                <th>SO No.</th>
                <th>Division</th>
                <th>Customer</th>
                <th>Part</th>
                <th>Description</th>
                <th>Type</th>
                <th class="right">Qty</th>
                <th class="right">Amount</th>
                <th class="right">Metric</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $item)
                <tr>
                    <td class="center">{{ $item->reqdate ? \Carbon\Carbon::parse($item->reqdate)->format('Y-m-d') : '-' }}</td>
                    <td class="center">{{ $item->ordnumber ?? '-' }}</td>
                    <td>{{ $item->group_name ?? '-' }}</td>
                    <td>{{ $item->customer_name ?? '-' }}</td>
                    <td class="center">{{ $item->partnumber ?? '-' }}</td>
                    <td>{{ $item->description ?? '-' }}</td>
                    <td class="center">{{ $item->partstype ?? '-' }}</td>
                    <td class="right">{{ $fmt($item->qty ?? 0) }}</td>
                    <td class="right">{{ $fmt($item->bath ?? 0) }}</td>
                    <td class="right">{{ $fmt($item->metric_value ?? 0) }} {{ $item->metric_unit ?? '' }}</td>
                </tr>
            @empty
                <tr><td colspan="10" class="center">No data</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
