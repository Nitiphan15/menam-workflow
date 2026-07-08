<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AssignedTruckBoardMail extends Mailable
{
    use Queueable, SerializesModels;

    public array $mailData;
    public string $pdfContent;
    public string $pdfFilename;
    public string $excelContent;
    public string $excelFilename;

    public function __construct(
        array $mailData,
        string $pdfContent = '',
        string $pdfFilename = '',
        string $excelContent = '',
        string $excelFilename = ''
    ) {
        $this->mailData = $mailData;
        $this->pdfContent = $pdfContent;
        $this->pdfFilename = $pdfFilename;
        $this->excelContent = $excelContent;
        $this->excelFilename = $excelFilename;
    }

    public function build()
    {
        $subject = $this->mailData['subject']
            ?? ('[จัดรถส่งสินค้า] ' . ($this->mailData['shipDateText'] ?? ''));

        $mail = $this->from(config('mail.from.address'), config('mail.from.name'))
            ->subject($subject)
            ->view('emails.truck-assign-board-mail')
            ->with($this->mailData);

        if ($this->pdfContent !== '') {
            $mail->attachData($this->pdfContent, $this->pdfFilename ?: 'DeliveryPlan-Assigned.pdf', [
                'mime' => 'application/pdf',
            ]);
        }

        if ($this->excelContent !== '') {
            $mail->attachData($this->excelContent, $this->excelFilename ?: 'DeliveryPlan-Assigned.xlsx', [
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        }

        return $mail;
    }
}
