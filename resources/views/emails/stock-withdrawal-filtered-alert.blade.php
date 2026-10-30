<!doctype html>
<html lang="th">
<body style="margin:0;padding:0;background:#eef2f6;font-family:Tahoma,Arial,sans-serif;color:#1f2937;font-size:18px;line-height:1.45">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f6">
<tr><td align="center" style="padding:16px">
    <table role="presentation" width="1100" cellpadding="0" cellspacing="0" style="width:96%;max-width:1100px;background:#ffffff;border:1px solid #dbe3ea">
        <tr><td style="background:#0f766e;padding:0;color:#ffffff">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr><td style="padding:18px 22px 0;font-size:17px;line-height:22px;letter-spacing:1px;color:#ccfbf1">STOCK WITHDRAWAL</td></tr>
                <tr><td style="padding:5px 22px 0;font-size:30px;line-height:38px;font-weight:bold;color:#ffffff">{{ $mailType === 'SPECIAL' ? 'งานเบิกด่วน Special' : 'แจ้งเตือนรายการเบิก Stock' }}</td></tr>
                <tr><td style="padding:6px 22px 18px;font-size:18px;line-height:26px;color:#ccfbf1">เฉพาะรายการที่ยังเบิกไม่ครบ</td></tr>
            </table>
        </td></tr>
        <tr><td style="padding:18px 20px 24px">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ecfdf5;border-left:4px solid #10b981;margin-bottom:18px">
                <tr><td style="padding:12px 14px 3px;font-size:18px;line-height:25px"><strong>Priority:</strong> วันที่เข้าคิว → Special → ลำดับที่ Planner จัด</td></tr>
                <tr><td style="padding:3px 14px 12px;font-size:17px;line-height:24px;color:#475569">รายละเอียดทั้งหมดอยู่ในไฟล์ Excel ที่แนบมาพร้อมอีเมลนี้</td></tr>
            </table>

            @foreach($groups as $date=>$rows)
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0 7px"><tr>
                    <td style="font-size:23px;font-weight:bold;color:#0f766e">คิววันที่ {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</td>
                    <td align="right" style="font-size:17px;color:#475569">{{ count($rows) }} รายการ</td>
                </tr></table>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #cbd5e1;font-size:17px;line-height:24px">
                    <thead><tr style="background:#e8eef4;color:#334155">
                        <th align="left" width="18%" style="padding:11px 10px;border-bottom:2px solid #94a3b8;font-size:17px">MFG / สถานะ</th>
                        <th align="left" width="28%" style="padding:11px 10px;border-bottom:2px solid #94a3b8;font-size:17px">รหัสสินค้า / ลูกค้า</th>
                        <th align="left" width="18%" style="padding:11px 10px;border-bottom:2px solid #94a3b8;font-size:17px">แผนเบิก</th>
                        <th align="right" width="16%" style="padding:11px 10px;border-bottom:2px solid #94a3b8;font-size:17px">จำนวน</th>
                        <th align="left" width="20%" style="padding:11px 10px;border-bottom:2px solid #94a3b8;font-size:17px">กำหนดการ</th>
                    </tr></thead>
                    <tbody>
                    @foreach($rows as $row)
                        @php
                            $priorityColor=($row['is_special']??false)?'#dc2626':(($row['is_backlog']??false)?'#7c3aed':'#64748b');
                            $remaining=(float)($row['remaining_quantity']??$row['quantity']);
                            $rowBackground=$loop->even?'#f8fafc':'#ffffff';
                        @endphp
                        <tr style="background:{{ $rowBackground }}">
                            <td valign="top" style="padding:10px 9px;border-bottom:1px solid #dbe3ea">
                                <strong style="font-size:21px;color:#0f172a">{{ $row['mfg'] }}</strong><br>
                                @if($row['is_special']??false)<span style="display:inline-block;margin-top:5px;background:{{ $priorityColor }};color:#fff;padding:5px 9px;font-size:15px;font-weight:bold">SPECIAL</span>@elseif($row['is_backlog']??false)<span style="display:inline-block;margin-top:5px;background:{{ $priorityColor }};color:#fff;padding:5px 9px;font-size:15px;font-weight:bold">งานค้าง</span>@endif
                                <br><span style="color:#475569;font-size:16px;line-height:26px">{{ $row['site']??'-' }} · {{ $row['status']??'ยังไม่เบิก' }}</span>
                            </td>
                            <td valign="top" style="padding:10px 9px;border-bottom:1px solid #dbe3ea">
                                <strong style="font-size:20px;color:#0f172a">{{ $row['partnumber'] }}</strong><br>
                                <span style="color:#64748b;font-size:16px;line-height:26px">{{ $row['customer']?:'-' }}</span>
                            </td>
                            <td valign="top" style="padding:10px 9px;border-bottom:1px solid #dbe3ea">
                                <strong>{{ $row['job_type'] }}</strong><br>
                                <span style="color:#0f766e;font-size:16px;line-height:26px">เบิกไป: <strong>{{ $row['withdraw_to']?:'-' }}</strong></span><br>
                                <span style="color:#64748b;font-size:16px;line-height:26px">ระยะเวลาเตรียมเบิก: {{ $row['lead_time_days']??'-' }} วันทำงาน</span>
                                @if(!empty($row['special_note']))<br><span style="color:#b91c1c;font-size:15px">{{ $row['special_note'] }}</span>@endif
                            </td>
                            <td valign="top" align="right" style="padding:10px 9px;border-bottom:1px solid #dbe3ea">
                                <span style="color:#64748b;font-size:15px">คงเหลือที่ต้องเบิก</span><br>
                                <strong style="font-size:22px;line-height:30px;color:#c2410c">{{ number_format($remaining,2) }} {{ $row['unit'] }}</strong><br>
                                <span style="color:#64748b;font-size:15px">จำนวนที่ต้องเบิก {{ number_format($row['quantity'],2) }} {{ $row['unit'] }}</span>
                            </td>
                            <td valign="top" style="padding:10px 9px;border-bottom:1px solid #dbe3ea">
                                <span style="font-size:16px;color:#64748b">วันที่ต้องเบิก</span><br>
                                <strong style="font-size:21px;line-height:29px;color:#dc2626">{{ $row['due_date'] }}</strong><br>
                                <span style="color:#64748b;font-size:15px;line-height:23px">แจ้ง {{ $row['request_date'] }} · เข้าคิว {{ $row['queue_date'] }}<br>ขึ้นผลิต {{ $row['production_date']?:'-' }} · ส่งมอบ {{ $row['delivery_date']?:'-' }}</span>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endforeach

            <div style="margin-top:18px;padding-top:12px;border-top:1px solid #e2e8f0;color:#64748b;font-size:16px;line-height:24px">
                อีเมลอัตโนมัติจากระบบ Stock Withdrawal · รายละเอียดทั้งหมดอยู่ในไฟล์ Excel ที่แนบ
            </div>
        </td></tr>
    </table>
</td></tr>
</table>
</body>
</html>
