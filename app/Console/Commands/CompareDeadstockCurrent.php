<?php

namespace App\Console\Commands;

use App\Models\FormWOS\DeadstockSnapshotMonth;
use App\Services\FormWOS\DeadstockReviewService;
use Illuminate\Console\Command;

class CompareDeadstockCurrent extends Command
{
    protected $signature = 'deadstock:compare-current
        {--month-id= : Compare one snapshot month id}
        {--all : Compare every snapshot month that has items}
        {--write-logs : Write compare log rows for each item}
        {--sync-current : Pull current ERP deadstock missing from every snapshot into the latest month first}';

    protected $description = 'Compare Deadstock snapshot items with current ERP on-hand data.';

    public function handle(
        DeadstockReviewService $reviewService,
        \App\Services\FormWOS\DeadstockSnapshotImportService $importService
    ): int {
        $months = $this->monthsToCompare();

        if ($months->isEmpty()) {
            $this->warn('No Deadstock snapshot months found.');
            return self::SUCCESS;
        }

        if ($this->option('sync-current')) {
            $latest = $months->first();
            $sync = $reviewService->syncCurrentDeadstock($latest, $importService);
            $this->info(sprintf(
                'Synced current ERP deadstock into month id=%d: live=%d missing=%d added=%d',
                $latest->id,
                $sync['live_total'] ?? 0,
                $sync['missing'] ?? 0,
                $sync['added'] ?? 0
            ));
            $latest->refresh();
        }

        $writeLogs = (bool) $this->option('write-logs');

        foreach ($months as $month) {
            $counts = $reviewService->compareMonth($month, $writeLogs);
            $monthLabel = $month->recv_date?->format('Y-m-d')
                ?? $month->as_of_date?->format('Y-m-d')
                ?? $month->snapshot_month?->format('Y-m')
                ?? (string) $month->id;

            $this->info(sprintf(
                'Compared Deadstock month %s (id=%d): active=%d changed=%d cleared=%d',
                $monthLabel,
                $month->id,
                $counts['active'] ?? 0,
                $counts['changed'] ?? 0,
                $counts['cleared'] ?? 0
            ));
        }

        return self::SUCCESS;
    }

    private function monthsToCompare()
    {
        $query = DeadstockSnapshotMonth::query()
            ->where('item_count', '>', 0)
            ->orderByDesc('snapshot_month')
            ->orderByDesc('recv_date');

        $monthId = $this->option('month-id');

        if (is_numeric($monthId)) {
            return $query->whereKey((int) $monthId)->get();
        }

        if ($this->option('all')) {
            return $query->get();
        }

        return $query->limit(1)->get();
    }
}
