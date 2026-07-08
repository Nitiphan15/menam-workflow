<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PO Submitted</title>
</head>
<body style="margin:0; padding:0; background:#eef4f8; font-family:Tahoma, Arial, sans-serif; color:#263241;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef4f8; margin:0; padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="640" cellspacing="0" cellpadding="0" style="width:640px; max-width:100%; background:#ffffff; border-radius:18px; overflow:hidden; box-shadow:0 8px 24px rgba(63, 91, 123, 0.10);">
                    <tr>
                        <td style="background:#e8f1ff; padding:26px 32px; border-bottom:1px solid #cbddea;">
                            <div style="font-size:13px; color:#5f7288; letter-spacing:.08em; text-transform:uppercase;">PO Online</div>
                            <div style="font-size:24px; font-weight:700; color:#1e3a5f; margin-top:6px;">ส่ง PO เข้า workflow แล้ว</div>
                            <div style="font-size:14px; color:#60758c; margin-top:8px;">ตอนนี้อยู่ที่: {{ $poItem['step_label'] ?? '-' }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:30px 32px 12px;">
                            <p style="margin:0 0 18px; font-size:16px; line-height:1.7;">เรียนคุณ {{ $recipientName }}</p>
                            <p style="margin:0; font-size:15px; line-height:1.8; color:#3c4b5f;">
                                ระบบรับเอกสาร PO เข้า workflow แล้ว และส่งแจ้งเตือนไปยังผู้ที่ต้องอนุมัติใน step ปัจจุบันเรียบร้อย
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:10px 32px 8px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 16px; background:#f7fbfd; border:1px solid #dce8f1; border-radius:14px;">
                                <tr>
                                    <td style="padding:18px 20px;">
                                        <div style="font-size:18px; font-weight:700; color:#2f5f8f; margin-bottom:10px;">
                                            {{ $poItem['ordnumber'] ?? '-' }}
                                        </div>
                                        <div style="font-size:14px; line-height:1.8; color:#56677b;">
                                            <strong style="color:#35485c;">Source:</strong> {{ $poItem['source_label'] ?? '-' }}<br>
                                            <strong style="color:#35485c;">Vendor:</strong> {{ $poItem['vendor_name'] ?? '-' }}<br>
                                            <strong style="color:#35485c;">Department:</strong> {{ $poItem['department'] ?? '-' }}<br>
                                            <strong style="color:#35485c;">Status:</strong> {{ $poItem['status_code'] ?? '-' }}
                                        </div>

                                        @include('emails.po.partials.flow', [
                                            'flowSteps' => $poItem['flow_steps'] ?? [],
                                            'currentStepNo' => $poItem['step_no'] ?? 0,
                                        ])

                                        <div style="margin-top:18px;">
                                            <a href="{{ $poItem['show_url'] ?? '#' }}" style="display:inline-block; background:#3d7fb1; color:#ffffff; text-decoration:none; padding:11px 18px; border-radius:999px; font-size:14px; font-weight:700;">
                                                เปิดเอกสาร
                                            </a>
                                            @if (!empty($poItem['print_url']))
                                                <a href="{{ $poItem['print_url'] }}" style="display:inline-block; margin-left:8px; color:#3d7fb1; text-decoration:none; padding:10px 0; font-size:14px;">
                                                    Download PDF
                                                </a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:10px 32px 30px;">
                            <p style="margin:22px 0 0; font-size:15px; line-height:1.7; color:#3c4b5f;">
                                ขอบคุณครับ<br>
                                <strong>PO Online</strong>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
