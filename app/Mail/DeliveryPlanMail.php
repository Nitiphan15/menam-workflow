<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DeliveryPlanMail extends Mailable
{
    use Queueable, SerializesModels;

    public array $mailData;
    public array $pdfData;
    public array $excelData;

    public function __construct(array $mailData, array $pdfData = [], array $excelData = [])
    {
        $this->mailData = $mailData;
        $this->pdfData = $pdfData;
        $this->excelData = $excelData;
    }

    public function build()
    {
        $subject = $this->mailData['subject']
            ?? ('[Delivery Plan] ' . ($this->mailData['shipDateText'] ?? ''));

        $mail = $this->from(config('mail.from.address'), config('mail.from.name'))
            ->subject($subject)
            ->view('emails.delivery-plan-mail')
            ->with($this->mailData);

        $pdfFacade = \Barryvdh\DomPDF\Facade\Pdf::class;

        if (!empty($this->pdfData) && class_exists($pdfFacade) && view()->exists('pdf.delivery-plan-pdf')) {
            $pdf = $pdfFacade::loadView('pdf.delivery-plan-pdf', $this->pdfData)
                ->setPaper('a4', 'landscape');

            $filename = 'DeliveryPlan-' . ($this->pdfData['shipDateFile'] ?? now()->format('Ymd_His')) . '.pdf';

            $mail->attachData($pdf->output(), $filename, [
                'mime' => 'application/pdf',
            ]);
        }

        if (!empty($this->excelData['content'] ?? null)) {
            $filename = $this->excelData['filename']
                ?? ('DeliveryPlan-' . ($this->pdfData['shipDateFile'] ?? now()->format('Ymd_His')) . '.xlsx');

            $mail->attachData($this->excelData['content'], $filename, [
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        }

        return $mail;
    }
}
