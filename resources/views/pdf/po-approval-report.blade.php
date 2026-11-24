@php
    use App\Services\Po\PoApprovalReportService as Report;
    $s = $report['summary'];
    $f = $report['filters'];
    $fmt = fn ($h) => Report::formatHours($h);
@endphp
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>PO Approval Report</title>
    <style>
        @font-face {
            font-family: "PoThai";
            font-style: normal;
            font-weight: normal;
            src: url("file://{{ public_path('fonts/THSarabunNew.ttf') }}") format("truetype");
        }
        @font-face {
            font-family: "PoThai";
            font-style: normal;
            font-weight: bold;
            src: url("file://{{ public_path('fonts/THSarabunNew-Bold.ttf') }}") format("truetype");
        }
        @page { size: A4 landscape; margin: 7mm; }
        body { color: #111827; font-family: "PoThai", "DejaVu Sans", Tahoma, sans-serif; font-size: 14px; line-height: 1.2; }
        .title { font-size: 22px; font-weight: bold; }
        .meta { color: #4b5563; font-size: 13px; margin-bottom: 6px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #9ca3af; padding: 2px 4px; vertical-align: top; }
        th { background: #e5e7eb; font-weight: bold; }
        .cards td { border: 1px solid #d1d5db; text-align: center; width: 12.5%; }
        .cards .v { font-size: 20px; font-weight: bold; }
        .cards .l { color: #4b5563; font-size: 12px; }
        .r { text-align: right; }
        .c { text-align: center; }
        .nw { white-space: nowrap; }
        .bad { color: #b91c1c; font-weight: bold; }
        h3 { font-size: 16px; margin: 8px 0 3px; }
        .detail td, .detail th { font-size: 12px; }
        thead { display: table-header-group; }
    </style>
</head>
<body>
    <div class="title">รายงานการอนุมัติ PO Online</div>
    <div class="meta">
        Submit ระหว่าง {{ \Carbon\Carbon::parse($f['date_from'])->format('d/m/Y') }} – {{ \Carbon\Carbon::parse($f['date_to'])->format('d/m/Y') }}
        · {{ $scopeLabel }}
        @if ($f['site']) · Site {{ ucfirst($f['site']) }} @endif
        @if ($f['department']) · แผนก {{ $f['department'] }} @endif
        @if ($f['status']) · สถานะ {{ $statusLabels[$f['status']] ?? $f['status'] }} @endif
        · ออกรายงาน {{ $report['generated_at'] }}
    </div>

    <table class="cards">
        <tr>
            <td><div class="v">{{ number_format($s['total']) }}</div><div class="l">PO ทั้งหมด</div></td>
            <td><div class="v">{{ number_format($s['approved']) }}</div><div class="l">อนุมัติครบ</div></td>
            <td><div class="v">{{ number_format($s['pending']) }}</div><div class="l">รออนุมัติ@if ($s['erp_done'] ?? 0) (ไม่นับ {{ number_format($s['erp_done']) }} ใบที่ปิดใน ERP)@endif</div></td>
            <td><div class="v">{{ number_format($s['rejected']) }}</div><div class="l">ส่งกลับแก้ไข</div></td>
            <td><div class="v">{{ $fmt($s['avg_hours']) }}</div><div class="l">เวลาอนุมัติเฉลี่ย</div></td>
            <td><div class="v">{{ $fmt($s['median_hours']) }}</div><div class="l">มัธยฐาน</div></td>
            <td><div class="v">{{ $fmt($s['avg_purchase_hours']) }} / {{ $fmt($s['avg_department_hours']) }}</div><div class="l">เฉลี่ย Purchase / หัวหน้าแผนก</div></td>
            <td><div class="v">{{ $s['sla_rate'] === null ? '-' : $s['sla_rate'] . '%' }}</div><div class="l">ทัน SLA {{ $slaHours }} ชม./ขั้น</div></td>
        </tr>
    </table>

    @if (!empty($report['departments']))
        <h3>สรุปแยกตามแผนก</h3>
        <table>
            <thead>
                <tr><th>แผนก</th><th class="r">PO</th><th class="r">อนุมัติครบ</th><th class="r">รออนุมัติ</th><th class="r">เฉลี่ยรวม</th><th class="r">เฉลี่ย Purchase</th><th class="r">เฉลี่ยหัวหน้าแผนก</th></tr>
            </thead>
            <tbody>
                @foreach ($report['departments'] as $d)
                    <tr>
                        <td>{{ $d['department'] }}</td>
                        <td class="r">{{ number_format($d['total']) }}</td>
                        <td class="r">{{ number_format($d['approved']) }}</td>
                        <td class="r">{{ number_format($d['pending']) }}</td>
                        <td class="r">{{ $fmt($d['avg_hours']) }}</td>
                        <td class="r">{{ $fmt($d['avg_purchase_hours']) }}</td>
                        <td class="r">{{ $fmt($d['avg_department_hours']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h3>รายละเอียดการอนุมัติ</h3>
    @if ($totalRows > $rowLimit)
        <div class="meta bad">แสดง {{ number_format($rowLimit) }} จาก {{ number_format($totalRows) }} ใบ — ดูครบทุกใบได้ในไฟล์ Excel</div>
    @endif
    <table class="detail">
        <thead>
            <tr>
                <th>PO No</th><th>Site</th><th>แผนก</th><th>สถานะ</th><th>Submit</th>
                <th>Purchase อนุมัติ</th><th class="r">ชม.</th>
                <th>หัวหน้าแผนก อนุมัติ</th><th class="r">ชม.</th>
                <th>อนุมัติครบ</th><th class="r">รวม</th><th class="c">ส่งกลับ</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr>
                    <td class="nw">{{ $row['ordnumber'] }}</td>
                    <td>{{ $row['site'] }}</td>
                    <td>{{ $row['department'] }}</td>
                    <td>{{ $row['status_label'] }}@if ($row['current_step_label']) ({{ $row['current_step_label'] }})@endif @if (!empty($row['erp_note']))<br>ERP: {{ $row['erp_note'] }}@endif</td>
                    <td class="nw">{{ $row['submitted_at'] }}</td>
                    <td>{!! nl2br(e($row['purchase_approvers'] ?: '-')) !!}</td>
                    <td class="r">{{ $row['purchase_hours'] ?? '-' }}</td>
                    <td>{!! nl2br(e($row['department_approvers'] ?: '-')) !!}</td>
                    <td class="r">{{ $row['department_hours'] ?? '-' }}</td>
                    <td class="nw">{{ $row['completed_at'] ?: '-' }}</td>
                    <td class="r nw {{ $row['within_sla'] === false ? 'bad' : '' }}">{{ $fmt($row['total_hours']) }}</td>
                    <td class="c">{{ $row['reject_count'] ?: '' }}</td>
                </tr>
            @empty
                <tr><td colspan="12" class="c">ไม่พบ PO ในช่วงที่เลือก</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
