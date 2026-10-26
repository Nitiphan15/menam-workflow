<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendPoNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 60;
    public array $backoff = [60, 300];

    public function __construct(public Mailable $mail, public array $context)
    {
        $this->onConnection('po-mail')->onQueue('po-mail');
    }

    public function handle(): void
    {
        Mail::send($this->mail);
        Log::info('PO notification sent', $this->context);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('PO notification exhausted retries', $this->context + [
            'exception' => $exception::class,
            'error' => $exception->getMessage(),
        ]);
    }
}
