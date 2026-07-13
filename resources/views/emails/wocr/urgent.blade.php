@component('mail::message')
# 🔴 WOCR ด่วนมาก — รอดำเนินการทันที

เรียนคุณ {{ $recipientName ?? 'Planner' }},

มีคำขอ Work Order Change Request ระดับความเร่งด่วน **{{ $urgencyLabel }}** ที่ต้องดำเนินการทันที

- เลขที่เอกสาร: **{{ $docuNo }}**
- ผู้ขอ: **{{ $requesterName ?? '-' }}**
- MFG No.: **{{ $mfgNo ?? '-' }}**

@isset($detail)
**รายละเอียด/เหตุผล:**

{{ $detail }}
@endisset

@isset($reviewUrl)
@component('mail::button', ['url' => $reviewUrl, 'color' => 'error'])
เปิดหน้าดำเนินการ
@endcomponent
@endisset

ขอบคุณค่ะ
@endcomponent
