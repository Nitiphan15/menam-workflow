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
        .detail-table th,
        .detail-table td {
            padding: 4px 3px;
            font-size: 11px;
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
        $overdueItems = $performance['overdue_item_list'] ?? [];
        $rescheduled = $report['rescheduled_items'] ?? [];
        $rescheduledRows = $rescheduled['rows'] ?? [];
        $rescheduledDivisions = $rescheduled['division_summary'] ?? [];
    @endphp

    <div class="title">Deadstock Summary Report</div>
    <div class="meta">
        Update {{ $reportDate->format('d/m/y') }} · {{ $report['report_range_label'] ?? 'ตาม Filter ที่เลือก' }}
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

    <div class="page-break"></div>
    <div class="title">รายการที่ระบุวันส่งแล้วแต่เลยกำหนด</div>
    <div class="meta">{{ $performanceMonth }} · ตาม Filter ที่เลือก · {{ count($overdueItems) }} รายการ</div>
    <table class="detail-table">
        <thead><tr>
            <th>Div.</th><th>Sales</th><th>Part / Serial</th><th>ลูกค้า</th><th>Qty</th>
            <th>วันที่แจ้งส่งล่าสุด</th><th>เลยกำหนด (วัน)</th><th>Site</th><th>รหัสสาเหตุ</th>
        </tr></thead>
        <tbody>
            @forelse ($overdueItems as $item)
                <tr>
                    <td>{{ $item['division'] }}</td><td class="left">{{ $item['salesperson'] }}</td>
                    <td class="left">{{ $item['partnumber'] }}<br>{{ $item['serialnumber'] }}</td>
                    <td class="left">{{ $item['customer'] }}</td><td class="num">{{ number_format($item['qty'], 2) }}</td>
                    <td>{{ $item['promised_due_date'] }}</td><td class="danger">{{ number_format($item['overdue_days']) }}</td>
                    <td>{{ $item['site'] }}</td><td>{{ $item['reason_code'] }}</td>
                </tr>
            @empty
                <tr><td colspan="9">ไม่มีรายการตาม Filter ที่เลือก</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="page-break"></div>
    <div class="title">รายการที่มีการเลื่อนกำหนดส่ง</div>
    <div class="meta">{{ $rescheduled['range_label'] ?? 'ตาม Filter ที่เลือก' }} · {{ count($rescheduledRows) }} รายการ</div>
    <table class="detail-table" style="margin-bottom: 10px;">
        <thead><tr><th>Div.</th><th>จำนวนรายการ</th><th>Qty (KGS)</th><th>จำนวนวันที่เลื่อนรวม</th><th>จำนวนครั้งที่เลื่อนรวม</th></tr></thead>
        <tbody>
            @forelse ($rescheduledDivisions as $summary)
                <tr><td>{{ $summary['division'] }}</td><td>{{ $summary['item_count'] }}</td><td class="num">{{ number_format($summary['qty'], 2) }}</td><td>{{ $summary['postponed_days'] }}</td><td>{{ $summary['postpone_count'] }}</td></tr>
            @empty
                <tr><td colspan="5">ไม่มีรายการตาม Filter ที่เลือก</td></tr>
            @endforelse
        </tbody>
    </table>
    <table class="detail-table">
        <thead><tr>
            <th>Div.</th><th>Sales</th><th>Part / Serial</th><th>ลูกค้า</th><th>Qty</th>
            <th>กำหนดส่งเดิม</th><th>กำหนดส่งใหม่ล่าสุด</th><th>เลื่อน (วัน)</th><th>เลื่อน (ครั้ง)</th>
        </tr></thead>
        <tbody>
            @forelse ($rescheduledRows as $item)
                <tr>
                    <td>{{ $item['division'] }}</td><td class="left">{{ $item['salesperson'] }}</td>
                    <td class="left">{{ $item['partnumber'] }}<br>{{ $item['serialnumber'] }}</td>
                    <td class="left">{{ $item['customer'] }}</td><td class="num">{{ number_format($item['qty'], 2) }}</td>
                    <td>{{ $item['original_due_date'] }}</td><td>{{ $item['latest_due_date'] }}</td>
                    <td>{{ $item['postponed_days'] }}</td><td>{{ $item['postpone_count'] }}</td>
                </tr>
            @empty
                <tr><td colspan="9">ไม่มีรายการตาม Filter ที่เลือก</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
