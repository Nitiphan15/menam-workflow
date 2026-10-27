<?php

namespace App\Console\Commands;

use App\Http\Controllers\FormStock\StockWithdrawalPlanController;
use App\Services\FormStock\StockWithdrawalPlanningService;
use Illuminate\Console\Command;

class RolloverStockWithdrawalQueue extends Command
{
    protected $signature = 'stock-withdrawal:rollover-queue';
    protected $description = 'Move incomplete stock withdrawal items to the current working-day queue';

    public function handle(StockWithdrawalPlanController $controller, StockWithdrawalPlanningService $service): int
    {
        $count = $controller->rolloverQueue($service);
        $this->info("Rolled over {$count} stock withdrawal item(s).");
        return self::SUCCESS;
    }
}
