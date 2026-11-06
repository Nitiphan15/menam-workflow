<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PoStep3PendingDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $approverName,
        public array $poItems,
        public string $stepName,
    ) {
    }

    public function build()
    {
        return $this->from(config('mail.from.address'), 'Menam PO Online')
            ->subject("เรียนคุณ {$this->approverName} มีเอกสาร PO รออนุมัติ {$this->stepName} ".count($this->poItems).' รายการ')
            ->view('emails.po.step3_pending_digest');
    }
}
