<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * อีเมลสรุป "งาน WOCR ที่ยังไม่ได้ดำเนินการ" ส่งให้ Planner ทุกเช้า
 */
class WocrPendingDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param Collection $items  รายการงานค้าง (object: docu_no, urgency_label, mfg_no, requester_name, req_date, review_url)
     */
    public function __construct(
        public ?string $recipientName,
        public Collection $items
    ) {}

    public function build()
    {
        $count = $this->items->count();

        return $this->subject("[WOCR] งานรอดำเนินการ {$count} รายการ")
            ->markdown('emails.wocr.pending_digest');
    }
}
