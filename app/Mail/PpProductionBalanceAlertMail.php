<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class PpProductionBalanceAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Collection $rows, public string $reportUrl, public bool $isTest = false)
    {
    }

    public function build()
    {
        $prefix = $this->isTest ? '[TEST] ' : '';

        return $this->subject($prefix.'[PP Alert] พบรายการเปิด Production Order เกิน SO '.$this->rows->count().' รายการ')
            ->markdown('emails.pp.production-balance-alert');
    }
}
