<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PpNeedsApprovalMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $docuNo,
        public string $approveUrl
    ) {}

    public function build()
    {
        return $this->subject("ต้องการอนุมัติ PP: {$this->docuNo}")
            ->markdown('emails.pp.needs_approval');
    }
}
