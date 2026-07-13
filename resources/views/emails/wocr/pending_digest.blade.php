@component('mail::message')
# WOCR — งานรอดำเนินการ

เรียนคุณ {{ $recipientName ?? 'Planner' }},

ด้านล่างคือรายการคำขอ WOCR ที่ยังไม่ได้ดำเนินการ (รออนุมัติ) จำนวน **{{ $items->count() }}** รายการ

@component('mail::table')
| เลขที่เอกสาร | ความเร่งด่วน | MFG No. | ผู้ขอ | วันที่ขอ |
|:-------------|:------------:|:--------|:------|:--------:|
@foreach ($items as $it)
| {{ $it->docu_no ?? '-' }} | {{ $it->urgency_label ?? '-' }} | {{ $it->mfg_no ?? '-' }} | {{ $it->requester_name ?? '-' }} | {{ $it->req_date ? \Illuminate\Support\Carbon::parse($it->req_date)->format('d/m/Y') : '-' }} |
@endforeach
@endcomponent

กรุณาเข้าระบบเพื่อตรวจสอบและดำเนินการ

@isset($items[0]->review_url)
@component('mail::button', ['url' => $items[0]->review_url])
เปิดระบบ WOCR
@endcomponent
@endisset

ขอบคุณค่ะ
@endcomponent
