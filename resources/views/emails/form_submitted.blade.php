@component('mail::message')
    # ยืนยันการส่งเอกสาร

    เรียนคุณ {{ $recipientName ?? 'ผู้ใช้งาน' }},

    เอกสารถูกส่งเรียบร้อยแล้วค่ะ

    - ประเภทเอกสาร: **{{ $appCode }}**
    - เลขที่เอกสาร: **{{ $docNo }}**
    - จำนวนไฟล์ที่แนบ: **{{ $attachmentsCount }}** ไฟล์

    @isset($extra['part_no'])
        - รหัสสินค้า (SKU): **{{ $extra['part_no'] }}**
    @endisset
    @isset($extra['customer'])
        - ลูกค้า: **{{ $extra['customer'] }}**
    @endisset

    @isset($reviewUrl)
        @component('mail::button', ['url' => $reviewUrl])
            ดูสถานะ / ตรวจสอบเอกสาร
        @endcomponent
    @endisset

@endcomponent
