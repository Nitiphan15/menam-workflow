<?php

namespace App\Services\FormPP;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProductionBalanceHistoryService
{
    private const TABLES = [
        'pp_production_balance_runs',
        'pp_production_balance_snapshots',
        'pp_production_balance_alerts',
        'pp_production_balance_mail_logs',
    ];

    public function isReady(): bool
    {
        return collect(self::TABLES)->every(fn (string $table) => Schema::hasTable($table));
    }

    public function startRun(string $triggerType = 'SCHEDULE'): int
    {
        return (int) DB::table('pp_production_balance_runs')->insertGetId([
            'run_uuid' => (string) Str::uuid(),
            'trigger_type' => $triggerType,
            'status' => 'RUNNING',
            'source_as_of' => now(),
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function storeSnapshots(int $runId, Collection $rows): void
    {
        $rows->map(fn ($row) => [
            'run_id' => $runId,
            'fingerprint' => $this->fingerprint($row),
            'site' => $row->site,
            'customer' => $row->customer,
            'item' => $row->item,
            'description' => $row->description,
            'unit' => $row->unit,
            'so_backorder' => $row->so_backorder,
            'fg_qty' => $row->fg,
            'wip_qty' => $row->wip,
            'mfg_qty' => $row->mfg,
            'need_qty' => $row->need_qty,
            'excess_qty' => $row->excess_qty,
            'balance_status' => $row->status,
            'sales_orders_json' => json_encode($row->sales_orders, JSON_UNESCAPED_UNICODE),
            'mfg_numbers_json' => json_encode($row->mfg_numbers, JSON_UNESCAPED_UNICODE),
            'missing_so_mfg_json' => json_encode($row->missing_so_mfg, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ])->chunk(300)->each(fn (Collection $chunk) => DB::table('pp_production_balance_snapshots')->insert($chunk->all()));
    }

    public function completeRun(int $runId, Collection $rows, int $durationMs): void
    {
        DB::table('pp_production_balance_runs')->where('id', $runId)->update([
            'status' => 'COMPLETED',
            'finished_at' => now(),
            'duration_ms' => max(0, $durationMs),
            'total_count' => $rows->count(),
            'excess_count' => $rows->where('status', 'EXCESS')->count(),
            'short_count' => $rows->where('status', 'SHORT')->count(),
            'complete_count' => $rows->where('status', 'COMPLETE')->count(),
            'excess_qty' => $rows->where('status', 'EXCESS')->sum('excess_qty'),
            'short_qty' => $rows->where('status', 'SHORT')->sum('need_qty'),
            'updated_at' => now(),
        ]);
    }

    public function failRun(int $runId, string $message, int $durationMs): void
    {
        DB::table('pp_production_balance_runs')->where('id', $runId)->update([
            'status' => 'FAILED',
            'finished_at' => now(),
            'duration_ms' => max(0, $durationMs),
            'error_message' => Str::limit($message, 60000, ''),
            'updated_at' => now(),
        ]);
    }

    public function logMail(int $runId, string $status, array $to, array $cc, int $rowCount, ?string $subject = null, ?string $error = null): void
    {
        DB::table('pp_production_balance_mail_logs')->insert([
            'run_id' => $runId,
            'mail_status' => $status,
            'recipient_to' => implode(',', $to),
            'recipient_cc' => implode(',', $cc),
            'row_count' => $rowCount,
            'subject' => $subject,
            'error_message' => $error ? Str::limit($error, 60000, '') : null,
            'sent_at' => $status === 'SENT' ? now() : null,
            'created_at' => now(),
        ]);
    }

    public function decorateWithPrevious(Collection $rows): array
    {
        $run = DB::table('pp_production_balance_runs')->where('status', 'COMPLETED')->orderByDesc('source_as_of')->first();
        if (! $run) {
            return ['rows' => $rows, 'snapshot_at' => null];
        }

        $previous = DB::table('pp_production_balance_snapshots')->where('run_id', $run->id)->get()->keyBy('fingerprint');
        $rows->each(function ($row) use ($previous) {
            $old = $previous->get($this->fingerprint($row));
            $row->previous_need_qty = $old ? (float) $old->need_qty : null;
            $row->need_change = $old ? round((float) $row->need_qty - (float) $old->need_qty, 4) : null;
            $row->previous_status = $old->balance_status ?? null;
        });

        return ['rows' => $rows, 'snapshot_at' => $run->source_as_of];
    }

    public function purgeOldRuns(int $retentionDays): int
    {
        return DB::table('pp_production_balance_runs')
            ->where('started_at', '<', now()->subDays(max(1, $retentionDays)))
            ->delete();
    }

    public function fingerprint($row): string
    {
        return hash('sha256', mb_strtoupper(trim($row->site).'|'.trim($row->so ?? '').'|'.trim($row->item).'|'.trim($row->customer), 'UTF-8'));
    }
}
