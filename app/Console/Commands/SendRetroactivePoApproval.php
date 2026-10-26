<?php

namespace App\Console\Commands;

use App\Services\Po\RetroactivePoApprovalMailService;
use Illuminate\Console\Command;

class SendRetroactivePoApproval extends Command
{
    protected $signature = 'po:send-retroactive-approval
        {ordnumber : Exact PO number}
        {--send : Dispatch the email; omission performs validation only}';

    protected $description = 'Validate and send one deduplicated retroactive Factory Step 3 approval email';

    public function handle(RetroactivePoApprovalMailService $service): int
    {
        $ordnumber = trim((string) $this->argument('ordnumber'));
        try {
            $context = $service->inspect($ordnumber);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        if ($context['already_dispatched']) {
            $this->warn('Email was already dispatched; duplicate blocked by workflow audit.');
            return self::SUCCESS;
        }

        $approver = $context['approver'];
        $this->line("Ready: {$ordnumber} -> {$approver->name} <{$approver->email}>");
        if (!$this->option('send')) {
            $this->info('Validation only. Add --send to dispatch.');
            return self::SUCCESS;
        }

        $result = $service->send($ordnumber);
        $this->info("Email {$result['delivery']}; audit recorded.");
        return self::SUCCESS;
    }
}
