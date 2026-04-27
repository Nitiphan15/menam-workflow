<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>แจ้งแผนส่งมอบสินค้า</title>
</head>

<body style="margin:0; padding:0; background:#f4f6f8; font-family:Tahoma, Arial, Helvetica, sans-serif; color:#1f2937;">
    <div style="max-width:1180px; margin:0 auto; padding:20px; font-family:Tahoma, Arial, Helvetica, sans-serif;">
        <div style="background:#ffffff; border-radius:12px; overflow:hidden; border:1px solid #d9e0e7;">

            {{-- ส่วนหัว --}}
            <div style="background:#1f4e78; color:#ffffff; padding:18px 24px;">
                <div style="font-size:28px; font-weight:700; line-height:1.2; margin-bottom:6px;">
                    แจ้งแผนส่งมอบสินค้า
                </div>
                <div style="font-size:16px; line-height:1.6;">
                    วันที่ส่งสินค้า: <strong>{{ $shipDateText }}</strong>
                </div>
            </div>

            {{-- เนื้อหา --}}
            <div style="padding:24px;">
                <p style="margin:0 0 14px 0; font-size:17px; line-height:1.7;">เรียนทุกท่าน</p>

                <p style="margin:0 0 18px 0; font-size:17px; line-height:1.8;">
                    ขอแจ้งแผนส่งมอบสินค้า สำหรับวันที่ส่งสินค้า
                    <strong>{{ $shipDateText }}</strong><br>
                    จำนวนทั้งหมด <strong>{{ number_format($total) }}</strong> รายการ
                </p>

                {{-- สรุปข้อมูล --}}
                <div
                    style="margin:18px 0; padding:16px 18px; background:#f8fafc; border:1px solid #d9e0e7; border-radius:10px;">
                    <div style="font-size:17px; font-weight:700; margin-bottom:8px;">สรุปข้อมูล</div>
                    <div style="font-size:15px; line-height:1.9;">
                        วันที่ส่งสินค้า: <strong>{{ $shipDateText }}</strong><br>
                        ประเภทการแจ้ง: <strong>{{ $mailTypeText }}</strong><br>
                        จำนวนรายการ: <strong>{{ number_format($total) }}</strong> รายการ
                    </div>
                </div>

                {{-- หมายเหตุการแจ้ง --}}
                @if (!empty($mailRemark))
                    <div
                        style="margin:18px 0; padding:16px 18px; background:#fff8e7; border:1px solid #f0d98a; border-radius:10px;">
                        <div style="font-size:16px; font-weight:700; margin-bottom:8px; color:#7a5a00;">
                            หมายเหตุการแจ้ง
                        </div>
                        <div style="font-size:15px; line-height:1.8; color:#4b5563;">
                            {{ $mailRemark }}
                        </div>
                    </div>
                @endif

                {{-- รายละเอียดรายการ --}}
                <div style="margin-top:22px; font-size:17px; font-weight:700; margin-bottom:10px;">
                    รายละเอียดรายการ
                </div>

                <div style="overflow-x:auto;">
                    <table width="100%" cellpadding="0" cellspacing="0" border="0"
                        style="border-collapse:collapse; width:100%; font-size:14px; font-family:Tahoma, Arial, Helvetica, sans-serif;">
                        <thead>
                            <tr>
                                <th
                                    style="width:60px; background:#eaf2f8; border:1px solid #c8d2dc; padding:10px 8px; text-align:center;">
                                    ลำดับ</th>
                                <th
                                    style="width:150px; background:#eaf2f8; border:1px solid #c8d2dc; padding:10px 8px; text-align:left;">
                                    SO No.</th>
                                <th
                                    style="width:220px; background:#eaf2f8; border:1px solid #c8d2dc; padding:10px 8px; text-align:left;">
                                    ลูกค้า</th>
                                <th
                                    style="width:420px; background:#eaf2f8; border:1px solid #c8d2dc; padding:10px 8px; text-align:left;">
                                    รายละเอียดสินค้า</th>
                                <th
                                    style="width:220px; background:#eaf2f8; border:1px solid #c8d2dc; padding:10px 8px; text-align:left;">
                                    MFG No.</th>
                                <th
                                    style="width:120px; background:#eaf2f8; border:1px solid #c8d2dc; padding:10px 8px; text-align:right;">
                                    จำนวน</th>
                                <th
                                    style="width:140px; background:#eaf2f8; border:1px solid #c8d2dc; padding:10px 8px; text-align:left;">
                                    หมายเหตุ</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $row)
                                <tr>
                                    <td
                                        style="border:1px solid #d1d5db; padding:10px 8px; text-align:center; line-height:1.6;">
                                        {{ $row['no'] ?? '' }}
                                    </td>
                                    <td style="border:1px solid #d1d5db; padding:10px 8px; line-height:1.6;">
                                        {{ $row['so_number'] ?? '-' }}
                                    </td>
                                    <td style="border:1px solid #d1d5db; padding:10px 8px; line-height:1.6;">
                                        {{ $row['customer'] ?? '-' }}
                                    </td>
                                    <td style="border:1px solid #d1d5db; padding:10px 8px; line-height:1.6;">
                                        {{ $row['part_desc'] ?? '-' }}
                                    </td>
                                    <td style="border:1px solid #d1d5db; padding:10px 8px; line-height:1.6;">
                                        {{ $row['mfg_no'] ?? '-' }}
                                    </td>
                                    <td
                                        style="border:1px solid #d1d5db; padding:10px 8px; text-align:right; font-weight:700; line-height:1.6;">
                                        {{ isset($row['qty']) ? number_format((float) $row['qty'], 3) : '-' }}
                                    </td>
                                    <td style="border:1px solid #d1d5db; padding:10px 8px; line-height:1.6;">
                                        {{ filled($row['remark'] ?? null) ? $row['remark'] : '-' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7"
                                        style="border:1px solid #d1d5db; padding:14px; text-align:center; color:#6b7280;">
                                        ไม่พบข้อมูล
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- ปุ่ม / ลิงก์ --}}
                @if (!empty($inquiryUrl))
                    <div style="margin-top:22px;">
                        <a href="{{ $inquiryUrl }}"
                            style="display:inline-block; background:#1f4e78; color:#ffffff; text-decoration:none; padding:12px 18px; border-radius:8px; font-size:15px; font-weight:700; border:1px solid #163a5b;">
                            เปิดหน้า Inquiry
                        </a>
                    </div>

                    <div style="margin-top:10px; font-size:13px; color:#6b7280; line-height:1.8;">
                        หากไม่สามารถกดปุ่มได้ กรุณาใช้ลิงก์นี้:<br>
                        <span style="word-break:break-all;">{{ $inquiryUrl }}</span>
                    </div>
                @endif

                <div style="margin-top:22px; font-size:16px; line-height:1.8;">
                    กรุณาตรวจสอบรายละเอียดเพิ่มเติมในระบบ Inquiry<br>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
