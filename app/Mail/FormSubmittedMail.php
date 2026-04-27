<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class FormSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $appCode,
        public string $docNo,
        public ?string $recipientName,
        public ?string $reviewUrl,
        public int $attachmentsCount = 0,
        public array $extra = []
    ) {}

    public function build()
    {
        $subjectPrefix = [
            'PR'   => 'ใบขอซื้อ (PR)',
            'PP'   => 'แบบฟอร์มวางแผนการผลิต (PP)',
            'WOCR' => 'Work Order Change Request (WOCR)',
        ][$this->appCode] ?? 'แจ้งเตือนการส่งเอกสาร';

        return $this->subject("ยืนยันการส่ง {$subjectPrefix}: {$this->docNo}")
            ->markdown('emails.form_submitted');
    }
}
