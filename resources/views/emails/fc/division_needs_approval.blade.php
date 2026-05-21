<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FC Division Approval</title>
</head>
<body style="margin:0; padding:0; background:#eef4f8; font-family:Tahoma, Arial, sans-serif; color:#263241;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef4f8; margin:0; padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="640" cellspacing="0" cellpadding="0" style="width:640px; max-width:100%; background:#ffffff; border-radius:18px; overflow:hidden; box-shadow:0 8px 24px rgba(63, 91, 123, 0.10);">
                    <tr>
                        <td style="background:#dcebf5; padding:26px 32px; border-bottom:1px solid #cbddea;">
                            <div style="font-size:13px; color:#5f7288; letter-spacing:.08em; text-transform:uppercase;">Sales Forecast</div>
                            <div style="font-size:24px; font-weight:700; color:#243b53; margin-top:6px;">Division Forecast รออนุมัติ</div>
                            <div style="font-size:14px; color:#60758c; margin-top:8px;">มีรายการรอดำเนินการ {{ count($items) }} รายการ</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:30px 32px 12px;">
                            <p style="margin:0 0 18px; font-size:16px; line-height:1.7;">เรียนคุณ {{ $approverName }}</p>
                            <p style="margin:0; font-size:15px; line-height:1.8; color:#3c4b5f;">กรุณาเปิดเอกสารด้านล่างเพื่อตรวจสอบและอนุมัติ Division Forecast</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:10px 32px 8px;">
                            @foreach ($items as $item)
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 16px; background:#f7fbfd; border:1px solid #dce8f1; border-radius:14px;">
                                    <tr>
                                        <td style="padding:18px 20px;">
                                            <div style="font-size:18px; font-weight:700; color:#2f5f8f; margin-bottom:10px;">{{ $item['form_no'] ?? '-' }}</div>
                                            <div style="font-size:14px; line-height:1.8; color:#56677b;">
                                                <strong style="color:#35485c;">Division:</strong> {{ $item['division'] ?? '-' }}<br>
                                                <strong style="color:#35485c;">Month:</strong> {{ $item['month'] ?? '-' }}<br>
                                                <strong style="color:#35485c;">Status:</strong> {{ $item['status'] ?? '-' }}<br>
                                                <strong style="color:#35485c;">Step:</strong> {{ $item['step'] ?? '-' }}
                                            </div>
                                            <div style="margin-top:18px;">
                                                <a href="{{ $item['approval_url'] ?? '#' }}" style="display:inline-block; background:#3d7fb1; color:#ffffff; text-decoration:none; padding:11px 18px; border-radius:999px; font-size:14px; font-weight:700;">เปิดเอกสารเพื่ออนุมัติ</a>
                                            </div>
                                        </td>
                                    </tr>
                                </table>
                            @endforeach
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
