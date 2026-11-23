<?php

namespace App\Console\Commands;

use App\Mail\PpProductionBalanceAlertMail;
use App\Services\FormPP\ProductionBalanceHistoryService;
use App\Services\FormPP\ProductionBalanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendPpProductionBalanceAlert extends Command
{
    protected $signature = 'pp:production-balance-alert
        {--force : Send all current excess rows}
        {--preview : Calculate and display excess rows without saving history or sending mail}';

    protected $description = 'Snapshot PP production balance and send non-duplicate excess alerts';

    public function handle(ProductionBalanceService $service, ProductionBalanceHistoryService $history): int
    {
        if ($this->option('preview')) {
            $rows = $service->report(['site' => 'all'])->where('status', 'EXCESS')->values();
            $this->table(
                ['Site', 'SO', 'Item', 'FG', 'WIP', 'MFG', 'Excess', 'Tolerance'],
                $rows->take(100)->map(fn ($row) => [
                    $row->site, $row->so ?: '-', $row->item, $row->fg, $row->wip, $row->mfg,
                    $row->excess_qty, $row->tolerance_qty,
                ])->all()
            );
            $this->info('Preview only: '.$rows->count().' excess rows; no history saved and no email sent.');

            return self::SUCCESS;
        }

        if (! $history->isReady()) {
            $this->error('PP balance tables are missing. Run database/sql/formpp/01_create_production_balance_history.sql first.');

            return self::SUCCESS;
        }

        $startedAt = microtime(true);
        $runId = $history->startRun($this->option('force') ? 'MANUAL_FORCE' : 'SCHEDULE');
        $to = array_values(array_filter(config('mail.pp_balance_alert.to', [])));
        $cc = array_values(array_filter(config('mail.pp_balance_alert.cc', [])));
        $subject = '[PP Alert] พบรายการเปิด Production Order เกิน SO';

        try {
            $rows = $service->report(['site' => 'all']);
            $history->storeSnapshots($runId, $rows);

            $current = $rows->where('status', 'EXCESS')->mapWithKeys(fn ($row) => [$history->fingerprint($row) => $row]);
            $existing = DB::table('pp_production_balance_alerts')->get()->keyBy('fingerprint');
            $send = $current->filter(function ($row, $fingerprint) use ($existing) {
                if ($this->option('force')) {
                    return true;
                }
                $old = $existing->get($fingerprint);

                return ! $old || ! $old->active || abs((float) $old->last_alerted_qty - (float) $row->excess_qty) > 0.0001;
            })->values();

            $this->resolveMissingAlerts($current);

            if ($to === []) {
                $history->logMail($runId, 'NO_RECIPIENTS', [], $cc, 0, $subject);
                $this->warn('Snapshot saved; PP_BALANCE_ALERT_TO is not configured.');
            } elseif ($send->isEmpty()) {
                $history->logMail($runId, 'NO_CHANGES', $to, $cc, 0, $subject);
                $this->info('Snapshot saved; no new or changed excess rows.');
            } else {
                $mail = Mail::to($to);
                if ($cc !== []) {
                    $mail->cc($cc);
                }
                $mail->send(new PpProductionBalanceAlertMail($send, route('pp.production-balance.index', ['status' => 'EXCESS'])));
                $this->rememberAlerts($current);
                $history->logMail($runId, 'SENT', $to, $cc, $send->count(), $subject);
                $this->info('Snapshot saved; sent '.$send->count().' excess rows.');
            }

            $duration = (int) round((microtime(true) - $startedAt) * 1000);
            $history->completeRun($runId, $rows, $duration);
            $history->purgeOldRuns((int) config('mail.pp_balance_alert.retention_days', 180));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $duration = (int) round((microtime(true) - $startedAt) * 1000);
            $history->failRun($runId, $e->getMessage(), $duration);
            try {
                $history->logMail($runId, 'FAILED', $to, $cc, 0, $subject, $e->getMessage());
            } catch (Throwable) {
                // Preserve the original failure when logging also fails.
            }
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function resolveMissingAlerts(Collection $current): void
    {
        DB::table('pp_production_balance_alerts')->where('active', true)
            ->when($current->isNotEmpty(), fn ($query) => $query->whereNotIn('fingerprint', $current->keys()->all()))
            ->update(['active' => false, 'resolved_at' => now(), 'updated_at' => now()]);
    }

    private function rememberAlerts(Collection $current): void
    {
        DB::transaction(function () use ($current) {
            $current->each(function ($row, $fingerprint) {
                DB::table('pp_production_balance_alerts')->updateOrInsert(['fingerprint' => $fingerprint], [
                    'site' => $row->site,
                    'customer' => $row->customer,
                    'item' => $row->item,
                    'last_alerted_qty' => $row->excess_qty,
                    'active' => true,
                    'alerted_at' => now(),
                    'resolved_at' => null,
                    'updated_at' => now(),
                ]);
            });
        });
    }
}
