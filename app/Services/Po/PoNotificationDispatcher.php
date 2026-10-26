<?php

namespace App\Services\Po;

use App\Jobs\SendPoNotification;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

class PoNotificationDispatcher
{
    /** Returns queued or sent; never labels an enqueued message as sent. */
    public function send(string|array $recipient, string $bcc, Mailable $mail): string
    {
        $recipientContext = is_array($recipient)
            ? array_values(array_unique(array_map(
                fn ($email) => strtolower(trim((string) $email)),
                $recipient
            )))
            : $recipient;

        $context = [
            'notification_id' => (string) Str::uuid(),
            'recipient' => $recipientContext,
            'mail_type' => $mail::class,
            'ordnumbers' => array_values(array_filter(array_column($mail->poItems ?? [$mail->poItem ?? []], 'ordnumber'))),
        ];
        $job = new SendPoNotification($mail->to($recipient)->bcc($bcc), $context);

        // Enable only after the dedicated tables and worker are installed.
        if (config('queue.po_mail_enabled', false)) {
            try {
                $id = Queue::connection('po-mail')->push($job, '', 'po-mail');
                Log::info('PO notification queued', $context + ['job_id' => $id]);
                return 'queued';
            } catch (Throwable $exception) {
                Log::error('PO notification enqueue failed; sending synchronously', $context + [
                    'exception' => $exception::class,
                ]);
            }
        }

        $job->handle();
        return 'sent';
    }
}
