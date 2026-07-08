<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PoSubmittedNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public array $poItem,
    ) {
    }

    public function build()
    {
        $subject = 'PO submitted: ' . ($this->poItem['ordnumber'] ?? '-');

        return $this->subject($subject)
            ->view('emails.po.submitted_notification');
    }
}
