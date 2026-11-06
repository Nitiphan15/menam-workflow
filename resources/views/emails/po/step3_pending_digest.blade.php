<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PO Online - Step 3 Pending Approval</title>
</head>
<body style="margin:0; padding:0; background:#eef4f8; font-family:Tahoma, Arial, sans-serif; color:#263241;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef4f8; padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="920" cellspacing="0" cellpadding="0" style="width:920px; max-width:100%; background:#ffffff; border-radius:16px; overflow:hidden;">
                    <tr>
                        <td style="background:#dcebf5; padding:24px 28px; border-bottom:1px solid #cbddea;">
                            <div style="font-size:13px; color:#5f7288; letter-spacing:.08em;">PO ONLINE</div>
                            <div style="font-size:24px; font-weight:700; color:#243b53; margin-top:6px;">รายการ PO รออนุมัติ Step 3</div>
                            <div style="font-size:14px; color:#60758c; margin-top:8px;">รอดำเนินการ {{ count($poItems) }} รายการ</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 28px 14px;">
                            <p style="margin:0 0 10px; font-size:16px;">เรียนคุณ {{ $approverName }}</p>
                            <p style="margin:0; font-size:14px; line-height:1.7; color:#4b5d70;">กรุณาตรวจสอบ PO ที่ยังรอการดำเนินการของท่าน</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:10px 28px 28px; overflow-x:auto;">
                            <table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse; min-width:820px; font-size:13px;">
                                <thead>
                                    <tr style="background:#f1f6fa; color:#35485c;">
                                        <th align="left" style="padding:11px 9px; border:1px solid #d7e3ec;">PO</th>
                                        <th align="left" style="padding:11px 9px; border:1px solid #d7e3ec;">วันที่</th>
                                        <th align="left" style="padding:11px 9px; border:1px solid #d7e3ec;">ผู้ขอซื้อ</th>
                                        <th align="left" style="padding:11px 9px; border:1px solid #d7e3ec;">แผนก</th>
                                        <th align="left" style="padding:11px 9px; border:1px solid #d7e3ec;">Vendor</th>
                                        <th align="right" style="padding:11px 9px; border:1px solid #d7e3ec;">มูลค่า</th>
                                        <th align="center" style="padding:11px 9px; border:1px solid #d7e3ec;">ดำเนินการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($poItems as $item)
                                        <tr>
                                            <td style="padding:10px 9px; border:1px solid #d7e3ec;">
                                                <strong style="color:#2f5f8f;">{{ $item['ordnumber'] }}</strong><br>
                                                <span style="color:#718296; font-size:12px;">{{ $item['source_label'] ?? '-' }}</span>
                                            </td>
                                            <td style="padding:10px 9px; border:1px solid #d7e3ec; white-space:nowrap;">{{ $item['transdate'] ? \Carbon\Carbon::parse($item['transdate'])->format('d/m/Y') : '-' }}</td>
                                            <td style="padding:10px 9px; border:1px solid #d7e3ec;">{{ $item['requester_name'] ?: '-' }}</td>
                                            <td style="padding:10px 9px; border:1px solid #d7e3ec;">{{ $item['department'] ?: '-' }}</td>
                                            <td style="padding:10px 9px; border:1px solid #d7e3ec;">{{ $item['vendor_name'] ?: '-' }}</td>
                                            <td align="right" style="padding:10px 9px; border:1px solid #d7e3ec; white-space:nowrap;">{{ is_numeric($item['netamount']) ? number_format((float) $item['netamount'], 2) : '-' }} {{ $item['curr'] ?: '' }}</td>
                                            <td align="center" style="padding:10px 9px; border:1px solid #d7e3ec; white-space:nowrap;">
                                                <a href="{{ $item['approve_url'] }}" style="display:inline-block; background:#3d7fb1; color:#ffffff; text-decoration:none; padding:9px 14px; border-radius:18px; font-weight:700;">เปิด PO เพื่ออนุมัติ</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
