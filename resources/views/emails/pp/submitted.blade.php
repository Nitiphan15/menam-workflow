@component('mail::message')
    # ยืนยันการส่ง Production Planning

    เรียนคุณ {{ $recipientName }},

    เอกสารถูกส่งเรียบร้อยแล้ว

    - เลขที่เอกสาร: **{{ $docuNo }}**
    @isset($partNo)
        - Part No: **{{ $partNo }}**
    @endisset
    @isset($customer)
        - ลูกค้า: **{{ $customer }}**
    @endisset

    @component('mail::button', ['url' => $reviewUrl])
        เปิดดูเอกสาร
    @endcomponent

@endcomponent
