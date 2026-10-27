<?php

namespace App\Console\Commands;

use App\Services\FormWR\WireRodDeadstockService;
use Illuminate\Console\Command;

class SyncWireRodDeadstock extends Command
{
    protected $signature = 'wr-deadstock:sync {--snapshot= : DAILY, MONTH_END, or MANUAL}';

    protected $description = 'Refresh Wire Rod Dead Stock cache from read-only ERP/MFG sources';

    public function handle(WireRodDeadstockService $service): int
    {
        $snapshot = strtoupper(trim((string) $this->option('snapshot')));
        if ($snapshot !== '' && ! in_array($snapshot, ['DAILY', 'MONTH_END', 'MANUAL'], true)) {
            $this->error('Invalid --snapshot value.');
            return self::INVALID;
        }

        $result = $service->refresh(null, 'scheduler', $snapshot ?: 'HOURLY');
        foreach ($result['sites'] as $site => $siteResult) {
            $this->line($site.': '.$siteResult['status'].' ('.($siteResult['rows'] ?? 0).' rows)');
        }

        if ($result['status'] !== 'SUCCEEDED') {
            $this->warn('Refresh incomplete. Previous cache was retained for failed sites; snapshot skipped.');
            return self::FAILURE;
        }

        if ($snapshot !== '') {
            $id = $service->createSnapshot($snapshot);
            $this->info('Snapshot created: '.$id);
        }

        return self::SUCCESS;
    }
}
