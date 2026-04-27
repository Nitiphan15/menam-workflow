<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PoNeedsApprovalMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $approverName,
        public array $poItems,
    ) {
    }

    public function build()
    {
        $count = count($this->poItems);
        $firstPoNo = $this->poItems[0]['ordnumber'] ?? '-';
        $subject = $count === 1
            ? "เรียนคุณ {$this->approverName} มีเอกสาร PO รออนุมัติ {$firstPoNo}"
            : "เรียนคุณ {$this->approverName} มีเอกสาร PO รออนุมัติ {$count} รายการ";

        return $this->subject($subject)
            ->view('emails.po.needs_approval');
    }
}
