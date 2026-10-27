<?php

namespace App\Console\Commands;

use App\Services\FormStock\StockWithdrawalPlanningService;
use Illuminate\Console\Command;

class SyncStockWithdrawalErpSnapshots extends Command
{
    protected $signature = 'stock-withdrawal:sync-erp-snapshots {--all : Recheck completed items too}';
    protected $description = 'Refresh stock withdrawal ERP quantity snapshots';

    public function handle(StockWithdrawalPlanningService $service): int
    {
        if (!$service->erpSnapshotAvailable()) {
            $this->warn('ERP snapshot columns are not installed. Run the stock withdrawal migration first.');
            return self::FAILURE;
        }

        $count = $service->syncActiveErpSnapshots((bool) $this->option('all'));
        $this->info("Synced {$count} stock withdrawal item(s).");
        return self::SUCCESS;
    }
}
