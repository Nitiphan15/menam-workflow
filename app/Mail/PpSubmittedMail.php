<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PpSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $docuNo,
        public string $reviewUrl,
        public ?string $partNo = null,
        public ?string $customer = null,
    ) {}

    public function build()
    {
        return $this->subject("ยืนยันการส่ง Production Planning (PP): {$this->docuNo}")
            ->markdown('emails.pp.submitted');
    }
}
