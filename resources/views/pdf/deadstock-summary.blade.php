<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Deadstock Summary</title>
    <style>
        @font-face {
            font-family: "DeadstockThai";
            font-style: normal;
            font-weight: normal;
            src: url("file://{{ public_path('fonts/THSarabunNew.ttf') }}") format("truetype");
        }
        @font-face {
            font-family: "DeadstockThai";
            font-style: normal;
            font-weight: bold;
            src: url("file://{{ public_path('fonts/THSarabunNew-Bold.ttf') }}") format("truetype");
        }
        @page { size: A4 landscape; margin: 7mm; }
        body {
            color: #111827;
            font-family: "DeadstockThai", "DejaVu Sans", Tahoma, sans-serif;
            font-size: 15px;
            line-height: 1.35;
        }
        .title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 2px;
        }
        .meta {
            color: #4b5563;
            font-size: 13px;
            margin-bottom: 8px;
        }
        table {
            border-collapse: collapse;
            width: 100%;
        }
        th,
        td {
            border: 1.2px solid #111827;
            padding: 7px 6px;
            text-align: center;
            vertical-align: middle;
        }
        th {
            background: #e2f0d9;
            font-weight: 700;
        }
        tbody td {
            font-size: 15px;
            font-weight: 700;
        }
        .left {
            text-align: left;
        }
        .num {
            text-align: right;
            white-space: nowrap;
        }
        .danger {
            color: #c62828;
        }
        .positive {
            color: #15803d;
        }
        th.positive {
            background: #dcfce7;
        }
        th.danger {
            background: #fee2e2;
        }
        .total-row td {
            background: #ffff00;
            font-weight: 700;
        }
        .note {
            color: #ff0000;
            font-size: 15px;
            font-weight: 700;
            margin-top: 14px;
        }
        .page-break {
            page-break-before: always;
        }
        .performance-table th,
        .performance-table td {
            padding: 6px 4px;
            font-size: 13px;
        }
        .performance-description {
            color: #374151;
            font-size: 14px;
            margin: 0 0 10px;
        }
    </style>
</head>
<body>
    @php
        $rows = $report['rows'] ?? [];
        $total = $report['totals'] ?? [];
        $fmt = fn($value) => abs((float) $value) < 0.005 ? '0' : number_format((float) $value, 0);
        $pct = fn($value) => $value === null ? '-' : number_format((float) $value, 2) . '%';
        $reportDate = $report['report_date'] ?? now();
        $targetMonth = $report['target_month_label'] ?? '';
        $performance = $report['delivery_performance'] ?? [];
        $performanceRows = $performance['rows'] ?? [];
        $performanceTotal = $performance['totals'] ?? [];
        $performanceMonth = $performance['target_month_label'] ?? '';
        $performanceCutoff = $performance['cutoff_date'] ?? now();
    @endphp

    <div class="title">Deadstock Summary Report</div>
    <div class="meta">
        Update {{ $reportDate->format('d/m/y') }} (Summary เดือนปัจจุบัน ไม่อิง Filter รายละเอียด)
        &nbsp;|&nbsp; Generated {{ ($generatedAt ?? now())->format('d/m/Y H:i') }}
    </div>

    <table>
        <thead>
            <tr>
                <th rowspan="3" style="width: 16%;">วันรับเข้า Stock</th>
                <th rowspan="3" style="width: 10%;">{{ $report['start_label'] ?? 'Start TOTAL (KGS)' }}</th>
                <th colspan="7">Update {{ $reportDate->format('d/m/y') }}</th>
                <th rowspan="3" style="width: 9%;">% ที่ลดลง</th>
            </tr>
            <tr>
                <th colspan="3">Sales Problem (SS)</th>
                <th colspan="3">Factory Problem (FF)</th>
                <th rowspan="2" style="width: 9%;">Grand<br>Total(KGS)</th>
            </tr>
            <tr>
                <th class="positive">ส่งได้ภายใน<br>เดือน {{ $targetMonth }}</th>
                <th class="danger">มีปัญหาไม่ได้ส่ง<br>ภายใน {{ $targetMonth }}</th>
                <th>Total</th>
                <th class="positive">ส่งได้ภายใน<br>เดือน {{ $targetMonth }}</th>
                <th class="danger">มีปัญหาไม่ได้ส่ง<br>ภายใน {{ $targetMonth }}</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="num">{{ $fmt($row['start_total'] ?? 0) }}</td>
                    <td class="num positive">{{ $fmt(data_get($row, 'sales.within_month', 0)) }}</td>
                    <td class="num danger">{{ $fmt(data_get($row, 'sales.problem', 0)) }}</td>
                    <td class="num">{{ $fmt(data_get($row, 'sales.total', 0)) }}</td>
                    <td class="num positive">{{ $fmt(data_get($row, 'factory.within_month', 0)) }}</td>
                    <td class="num danger">{{ $fmt(data_get($row, 'factory.problem', 0)) }}</td>
                    <td class="num">{{ $fmt(data_get($row, 'factory.total', 0)) }}</td>
                    <td class="num">{{ $fmt($row['grand_total'] ?? 0) }}</td>
                    <td>{{ $pct($row['decrease_percent'] ?? null) }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td>{{ $total['label'] ?? 'TOTAL(KGS)' }}</td>
                <td class="num">{{ $fmt($total['start_total'] ?? 0) }}</td>
                <td class="num positive">{{ $fmt(data_get($total, 'sales.within_month', 0)) }}</td>
                <td class="num danger">{{ $fmt(data_get($total, 'sales.problem', 0)) }}</td>
                <td class="num">{{ $fmt(data_get($total, 'sales.total', 0)) }}</td>
                <td class="num positive">{{ $fmt(data_get($total, 'factory.within_month', 0)) }}</td>
                <td class="num danger">{{ $fmt(data_get($total, 'factory.problem', 0)) }}</td>
                <td class="num">{{ $fmt(data_get($total, 'factory.total', 0)) }}</td>
                <td class="num">{{ $fmt($total['grand_total'] ?? 0) }}</td>
                <td>{{ $pct($total['decrease_percent'] ?? null) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="note">*งานปกติฝั่ง Sales หมายถึง WIP, Stock Grating, สินค้าที่ได้ส่งภายในเดือน</div>
    <div class="note">**งานปกติฝั่ง Factory หมายถึงงาน Stock FG, WIP, Stock Grating, สินค้าที่ได้ส่งภายในเดือน</div>
    <div class="note">% ที่ลดลง = (Start TOTAL - Grand Total) / Start TOTAL และจะแสดง "-" เมื่อ Start TOTAL เป็น 0</div>

    <div class="page-break"></div>

    <div class="title">ผลการส่งตามกำหนดภายในเดือน {{ $performanceMonth }}</div>
    <div class="meta">
        Generated {{ ($generatedAt ?? now())->format('d/m/Y H:i') }}
    </div>
    <p class="performance-description">
        เทียบรายการที่ “กำหนดส่งปัจจุบัน” อยู่ในเดือนเป้าหมาย กับรายการที่เคลียร์ภายใน
        {{ $performanceCutoff->format('d/m/Y H:i') }}
        โดยรายการที่ยังไม่เคลียร์และ Due Date หลังวันตัดยอดจะแสดงเป็น “ยังไม่ถึงกำหนด”
    </p>

    <table class="performance-table">
        <thead>
            <tr>
                <th style="width: 16%;">ผู้รับผิดชอบ</th>
                <th style="width: 17%;">Qty ที่แจ้งว่าจะส่ง (KGS)</th>
                <th class="positive" style="width: 16%;">ส่งได้จริง (KGS)</th>
                <th class="danger" style="width: 17%;">ส่งไม่ได้/ไม่ทัน (KGS)</th>
                <th style="width: 17%;">ยังไม่ถึงกำหนด (KGS)</th>
                <th class="positive" style="width: 17%;">% ส่งได้จริงของรายการที่ถึงกำหนด (KGS)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($performanceRows as $row)
                <tr>
                    <td class="left">{{ $row['label'] }}</td>
                    <td class="num">{{ number_format((float) ($row['promised_qty'] ?? 0), 2) }}</td>
                    <td class="num positive">{{ number_format((float) ($row['cleared_qty'] ?? 0), 2) }}</td>
                    <td class="num danger">{{ number_format((float) ($row['not_cleared_qty'] ?? 0), 2) }}</td>
                    <td class="num">{{ number_format((float) ($row['pending_qty'] ?? 0), 2) }}</td>
                    <td class="positive">{{ $pct($row['qty_success_percent'] ?? null) }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td class="left">{{ $performanceTotal['label'] ?? 'รวม' }}</td>
                <td class="num">{{ number_format((float) ($performanceTotal['promised_qty'] ?? 0), 2) }}</td>
                <td class="num positive">{{ number_format((float) ($performanceTotal['cleared_qty'] ?? 0), 2) }}</td>
                <td class="num danger">{{ number_format((float) ($performanceTotal['not_cleared_qty'] ?? 0), 2) }}</td>
                <td class="num">{{ number_format((float) ($performanceTotal['pending_qty'] ?? 0), 2) }}</td>
                <td class="positive">{{ $pct($performanceTotal['qty_success_percent'] ?? null) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="note">
        * “ส่งได้จริง” หมายถึงรายการถูกเคลียร์ไม่เกินวันตัดยอด และเปอร์เซ็นต์คำนวณเฉพาะรายการที่ถึงกำหนดแล้ว
    </div>
</body>
</html>
