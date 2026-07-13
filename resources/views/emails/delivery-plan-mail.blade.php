<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>แจ้งแผนส่งมอบสินค้า</title>
</head>

<body style="margin:0; padding:0; background:#ffffff; font-family:Tahoma, Arial, sans-serif; color:#005cab;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">
        <tr>
            <td align="left">
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="padding:18px 16px 10px 16px; font-size:24px; font-weight:700;">
                            Dear All และหน่วยงานที่เกี่ยวข้อง
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:0 16px; font-size:22px; font-weight:700;">
                            อ้างอิงตามเอกสารแนบ แผนการส่งมอบสินค้าของฝ่ายขาย
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 16px 0 20px; font-size:14px; font-weight:700; color:#ff0000;">
                            **ตามงานขอน้ำหนักที่ยังไม่ได้เปิดบิล**
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:10px 16px;">
                            <span
                                style="background:#fff200; font-weight:700; padding:4px 12px; text-decoration:underline;">
                                ***ขอความร่วมมือสำหรับงาน "ขอน้ำหนัก" แจ้งน้ำหนักก่อน 12:00 น.
                            </span>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 16px 10px 16px; font-size:22px; font-weight:700;">
                            • แผนการส่งมอบสินค้าประจำวันที่
                            <span style="margin-left:8px;">{{ $shipDateText ?? '-' }}</span>
                            @if (!empty($mailTypeText))
                                <span style="margin-left:10px; color:#ff33cc;">{{ $mailTypeText }}</span>
                            @endif
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:10px 16px;">
                            <table width="100%" cellpadding="0" cellspacing="0" border="0"
                                style="border:1px solid #d1d5db;">
                                <tr>
                                    <td style="padding:12px; font-size:18px; line-height:1.8; color:#374151;">
                                        วันที่ส่งสินค้า: <strong>{{ $shipDateText ?? '-' }}</strong><br>
                                        ประเภทการแจ้ง: <strong>{{ $mailTypeText ?? '-' }}</strong><br>
                                        จำนวนรายการ: <strong>{{ number_format($total ?? 0) }}</strong> รายการ
                                    </td>
                                </tr>

                                @if (!empty($mailRemark))
                                    <tr>
                                        <td
                                            style="padding:10px; background:#fff8e7; border-top:1px solid #f0d98a; font-size:17px;">
                                            <strong>หมายเหตุ:</strong> {{ $mailRemark }}
                                        </td>
                                    </tr>
                                @endif
                            </table>
                        </td>
                    </tr>

                    @if (!empty($inquiryUrl))
                        <tr>
                            <td style="padding:0 16px 20px 16px; font-size:18px;">
                                ดูรายละเอียดเพิ่มเติมและเลือกรถได้ที่ :
                                <a href="{{ $inquiryUrl }}" style="color:#005cab; font-weight:700;">เปิดหน้า
                                    Inquiry</a>
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td style="padding:10px 16px;">
                            <table width="100%" cellpadding="0" cellspacing="0" border="0"
                                style="border-collapse:collapse; font-size:17px;">
                                <tr style="background:#f3f4f6;">
                                    <th style="border:1px solid #9ca3af; padding:6px;">ลำดับ</th>
                                    <th style="border:1px solid #9ca3af; padding:6px;">SO</th>
                                    <th style="border:1px solid #9ca3af; padding:6px;">ลูกค้า</th>
                                    <th style="border:1px solid #9ca3af; padding:6px;">สินค้า</th>
                                    <th style="border:1px solid #9ca3af; padding:6px;">MFG</th>
                                    <th style="border:1px solid #9ca3af; padding:6px;">QTY</th>
                                    <th style="border:1px solid #9ca3af; padding:6px;">สถานที่ส่ง</th>
                                </tr>

                                @forelse ($rows ?? [] as $row)
                                    <tr>
                                        <td style="border:1px solid #d1d5db; padding:6px; text-align:center;">
                                            {{ $row['no'] ?? '' }}</td>
                                        <td style="border:1px solid #d1d5db; padding:6px;">
                                            {{ $row['so_number'] ?? '-' }}</td>
                                        <td style="border:1px solid #d1d5db; padding:6px;">{{ $row['customer'] ?? '-' }}
                                        </td>
                                        <td style="border:1px solid #d1d5db; padding:6px;">
                                            {{ $row['part_desc'] ?? '-' }}</td>
                                        <td style="border:1px solid #d1d5db; padding:6px;">{{ $row['mfg_no'] ?? '-' }}
                                        </td>
                                        <td
                                            style="border:1px solid #d1d5db; padding:6px; text-align:right; font-weight:bold;">
                                            {{ $row['qty_display'] ?? (isset($row['qty']) ? number_format((float) $row['qty'], 3) : '-') }}
                                        </td>
                                        <td style="border:1px solid #d1d5db; padding:6px;">{{ $row['address'] ?? '-' }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" style="padding:10px; text-align:center; color:#999;">
                                            ไม่พบข้อมูล</td>
                                    </tr>
                                @endforelse
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:16px; font-size:18px; color:#374151;">
                            ได้แนบไฟล์ PDF แผนการส่งมอบสินค้าเพื่อใช้ตรวจสอบเพิ่มเติมแล้ว
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>
