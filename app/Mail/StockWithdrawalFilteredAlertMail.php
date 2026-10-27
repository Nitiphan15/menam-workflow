<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class StockWithdrawalFilteredAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $groups, public array $attachmentsData, public string $mailType = 'AUTO') {}

    public function build()
    {
        $mail = $this->from(config('mail.from.address'), 'Menam Stock Withdrawal')
            ->subject(($this->mailType === 'SPECIAL' ? '[SPECIAL] งานเบิกด่วน ' : 'แจ้งเตือนรายการเบิก Stock ').now('Asia/Bangkok')->format('d/m/Y'))
            ->view('emails.stock-withdrawal-filtered-alert');
        foreach ($this->attachmentsData as $file) {
            $mail->attachData($file['content'], $file['name'], ['mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }
        return $mail;
    }
}
