@component('mail::message')
    # เอกสารรออนุมัติ

    เลขที่เอกสาร: **{{ $docuNo }}**

    @component('mail::button', ['url' => $approveUrl])
        เปิดหน้าอนุมัติ
    @endcomponent

@endcomponent
