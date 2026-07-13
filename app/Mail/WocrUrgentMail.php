<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * แจ้งเตือน Planner ทันที เมื่อมีคำขอ WOCR ระดับความเร่งด่วน "มาก"
 */
class WocrUrgentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $docuNo,
        public ?string $recipientName,
        public ?string $requesterName,
        public string $urgencyLabel,
        public ?string $mfgNo,
        public ?string $detail,
        public ?string $reviewUrl
    ) {}

    public function build()
    {
        return $this->subject("[ด่วนมาก] WOCR รออนุมัติทันที: {$this->docuNo}")
            ->markdown('emails.wocr.urgent');
    }
}
