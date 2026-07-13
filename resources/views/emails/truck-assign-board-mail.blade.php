<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>รายการแผนจัดรถส่งสินค้า</title>
</head>

<body style="margin:0; padding:0; background:#ffffff; font-family:Tahoma, Arial, sans-serif; color:#005cab;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">
        <tr>
            <td align="left">
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="padding:18px 16px 6px 16px; font-size:20px; font-weight:700;">
                            เรียนผู้เกี่ยวข้องทุกท่าน
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:0 16px 14px 16px; font-size:20px; font-weight:700;">
                            รายการแผนจัดรถส่งสินค้าประจำวันที่ {{ $shipDateText ?? '-' }}
                        </td>
                    </tr>

                    @if (!empty($mailRemark))
                        <tr>
                            <td style="padding:0 16px 12px 16px; font-size:17px; color:#374151;">
                                <strong>หมายเหตุ:</strong> {{ $mailRemark }}
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td style="padding:6px 16px 18px 16px; font-size:18px; color:#374151;">
                            ได้แนบไฟล์ PDF และ Excel ตารางจัดรถส่งสินค้าเพื่อใช้ตรวจสอบเพิ่มเติมแล้ว
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>
