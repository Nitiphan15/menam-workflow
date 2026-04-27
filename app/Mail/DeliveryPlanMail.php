<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DeliveryPlanMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $shipDateText;
    public string $mailTypeText;
    public int $total;
    public array $rows;
    public ?string $inquiryUrl;
    public ?string $mailRemark;

    public function __construct(
        string $shipDateText,
        string $mailTypeText,
        int $total,
        array $rows,
        ?string $inquiryUrl = null,
        ?string $mailRemark = null
    ) {
        $this->shipDateText = $shipDateText;
        $this->mailTypeText = $mailTypeText;
        $this->total = $total;
        $this->rows = $rows;
        $this->inquiryUrl = $inquiryUrl;
        $this->mailRemark = $mailRemark;
    }

    public function build()
    {
        return $this->from(config('mail.from.address'), config('mail.from.name'))
            ->subject("[Delivery Plan] {$this->mailTypeText} วันที่ {$this->shipDateText}")
            ->view('emails.delivery-plan-mail');
    }
}
