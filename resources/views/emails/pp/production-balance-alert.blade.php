@component('mail::message')
# แจ้งเตือน Production Order เกิน SO

@if($isTest)
> **อีเมลทดสอบ:** ไม่มีการบันทึกสถานะ Alert และไม่กระทบรอบส่งอัตโนมัติ
@endif

พบรายการเปิดเกิน **{{ number_format($rows->count()) }} รายการ**<br>
จำนวนเปิดเกินรวม **{{ number_format($rows->sum('excess_qty'), 2) }} KG**

ข้อมูล ณ {{ now()->format('d/m/Y H:i') }} น.<br>
คำนวณระดับ **SO + Item** จากสูตร `SO ค้างส่ง - (FG allocated + WIP + MFG)` โดยใช้ tolerance เดียวกับ Form Tracking

@component('mail::table')
| Site | SO / Item | SO ค้างส่ง | FG allocated | WIP | MFG | เกิน tolerance |
|:--|:--|--:|--:|--:|--:|--:|
@foreach($rows as $row)
| {{ $row->site }} | {{ $row->so ?: 'FG ไม่มี SO รองรับ' }} / {{ $row->item }} | {{ number_format($row->so_backorder,2) }} | {{ number_format($row->fg,2) }} | {{ number_format($row->wip,2) }} | {{ number_format($row->mfg,2) }} | **−{{ number_format($row->excess_qty,2) }} KG** |
@endforeach
@endcomponent

@component('mail::button', ['url' => $reportUrl])
เปิดรายงานรายการเกิน SO
@endcomponent

เครื่องหมาย **−** หมายถึงจำนวนที่เปิดเกินยอดรับ Order<br>
ระบบจะส่งซ้ำเมื่อรายการเกิดใหม่หรือจำนวนเกินเปลี่ยนแปลงเท่านั้น
@endcomponent
