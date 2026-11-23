<?php

namespace App\Console\Commands;

use App\Services\Po\RetroactivePoApprovalMailService;
use Illuminate\Console\Command;

class SendRetroactivePoApproval extends Command
{
    protected $signature = 'po:send-retroactive-approval
        {ordnumber : Exact PO number}
        {--send : Dispatch the email; omission performs validation only}';

    protected $description = 'Validate and send one deduplicated retroactive Step 3 approval email';

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

        $recipients = $context['approvers']
            ->reject(fn ($approver) => $approver->already_dispatched)
            ->map(fn ($approver) => "{$approver->name} <{$approver->email}>")
            ->implode(', ');
        $this->line("Ready: {$ordnumber} -> {$recipients}");
        if (!$this->option('send')) {
            $this->info('Validation only. Add --send to dispatch.');
            return self::SUCCESS;
        }

        $result = $service->send($ordnumber);
        $this->info("Email {$result['delivery']}; audit recorded.");
        return self::SUCCESS;
    }
}
