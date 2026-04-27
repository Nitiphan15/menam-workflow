<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PO Online Approval</title>
</head>
<body style="margin:0; padding:0; background:#eef4f8; font-family:Tahoma, Arial, sans-serif; color:#263241;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef4f8; margin:0; padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="640" cellspacing="0" cellpadding="0" style="width:640px; max-width:100%; background:#ffffff; border-radius:18px; overflow:hidden; box-shadow:0 8px 24px rgba(63, 91, 123, 0.10);">
                    <tr>
                        <td style="background:#dcebf5; padding:26px 32px; border-bottom:1px solid #cbddea;">
                            <div style="font-size:13px; color:#5f7288; letter-spacing:.08em; text-transform:uppercase;">PO Online</div>
                            <div style="font-size:24px; font-weight:700; color:#243b53; margin-top:6px;">เอกสาร PO รออนุมัติ</div>
                            <div style="font-size:14px; color:#60758c; margin-top:8px;">มีเอกสารรอดำเนินการ {{ count($poItems) }} รายการ</div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:30px 32px 12px;">
                            <p style="margin:0 0 18px; font-size:16px; line-height:1.7;">เรียนคุณ {{ $approverName }}</p>
                            <p style="margin:0; font-size:15px; line-height:1.8; color:#3c4b5f;">
                                มีเอกสาร PO รออนุมัติ กรุณาเข้าระบบผ่านปุ่มด้านล่างเพื่อตรวจสอบรายละเอียดและอนุมัติเอกสาร
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:10px 32px 8px;">
                            @foreach ($poItems as $item)
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 16px; background:#f7fbfd; border:1px solid #dce8f1; border-radius:14px;">
                                    <tr>
                                        <td style="padding:18px 20px;">
                                            <div style="font-size:18px; font-weight:700; color:#2f5f8f; margin-bottom:10px;">
                                                {{ $item['ordnumber'] }}
                                            </div>
                                            <div style="font-size:14px; line-height:1.8; color:#56677b;">
                                                <strong style="color:#35485c;">Source:</strong> {{ $item['source_label'] ?? '-' }}<br>
                                                <strong style="color:#35485c;">Vendor:</strong> {{ $item['vendor_name'] ?? '-' }}<br>
                                                <strong style="color:#35485c;">Department:</strong> {{ $item['department'] ?? '-' }}<br>
                                                <strong style="color:#35485c;">Status:</strong> {{ $item['status_code'] ?? '-' }}
                                            </div>

                                            <div style="margin-top:18px;">
                                                <a href="{{ $item['approve_url'] }}" style="display:inline-block; background:#3d7fb1; color:#ffffff; text-decoration:none; padding:11px 18px; border-radius:999px; font-size:14px; font-weight:700;">
                                                    เปิดเอกสารเพื่ออนุมัติ
                                                </a>

                                                @if (!empty($item['print_url']))
                                                    <a href="{{ $item['print_url'] }}" style="display:inline-block; margin-left:8px; color:#3d7fb1; text-decoration:none; padding:10px 0; font-size:14px;">
                                                        ดูตัวอย่าง PO
                                                    </a>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                </table>
                            @endforeach
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:10px 32px 30px;">
                            <div style="background:#fff8e8; border:1px solid #f0dfb8; border-radius:12px; padding:14px 16px; color:#6b5a2e; font-size:14px; line-height:1.7;">
                                หากดำเนินการเรียบร้อยแล้ว สามารถละเว้นอีเมลฉบับนี้ได้
                            </div>
                            <p style="margin:22px 0 0; font-size:15px; line-height:1.7; color:#3c4b5f;">
                                ขอบคุณครับ<br>
                                <strong>PO Online</strong>
                            </p>
                        </td>
                    </tr>
                </table>

                <div style="font-size:12px; color:#91a1b3; margin-top:18px;">
                    © {{ date('Y') }} Menam Stainless Wire Public Co., Ltd.
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
