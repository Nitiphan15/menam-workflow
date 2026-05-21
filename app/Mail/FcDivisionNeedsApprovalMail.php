<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class FcDivisionNeedsApprovalMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $approverName,
        public array $items,
    ) {
    }

    public function build()
    {
        $count = count($this->items);
        $formNo = $this->items[0]['form_no'] ?? '-';
        $subject = $count === 1
            ? "[FC Division] Waiting approval {$formNo}"
            : "[FC Division] Waiting approval {$count} items";

        return $this->subject($subject)
            ->view('emails.fc.division_needs_approval');
    }
}
