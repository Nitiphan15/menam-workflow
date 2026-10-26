<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class WorkPoMail extends Command
{
    protected $signature = 'po:mail-work {--once : Process at most one job} {--retry=* : Requeue these failed PO notification UUIDs before processing}';
    protected $description = 'Drain the dedicated PO mail queue; schedule every minute with no overlapping instances';

    public function handle(): int
    {
        if (config('mail.mailers.smtp.timeout') === null) {
            config(['mail.mailers.smtp.timeout' => 30]);
        }
        config(['queue.failed' => [
            'driver' => 'database-uuids',
            'database' => 'sqlsrv_menam',
            'table' => 'po_mail_failed_jobs',
        ]]);
        app()->forgetInstance('queue.failer');

        if ($ids = $this->option('retry')) {
            $result = $this->call('queue:retry', ['id' => $ids]);
            if ($result !== 0) {
                return $result;
            }
        }

        return $this->call('queue:work', [
            'connection' => 'po-mail',
            '--queue' => 'po-mail',
            '--stop-when-empty' => true,
            '--once' => (bool) $this->option('once'),
            '--tries' => 3,
            '--timeout' => 60,
            '--max-time' => 50,
        ]);
    }
}
