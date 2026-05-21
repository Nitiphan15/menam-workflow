<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class FcDivisionClosedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public array $item,
    ) {
    }

    public function build()
    {
        return $this->subject('[FC Division] Approved ' . ($this->item['form_no'] ?? '-'))
            ->view('emails.fc.division_closed');
    }
}
