<?php

namespace App\Console\Commands;

use App\Http\Controllers\FormStock\StockWithdrawalPlanController;
use App\Services\FormStock\StockWithdrawalPlanningService;
use Illuminate\Console\Command;
use Throwable;

class SendStockWithdrawalDailyAlert extends Command
{
    protected $signature = 'stock-withdrawal:send-daily-alert';

    protected $description = 'Send due and incomplete stock withdrawal items by email';

    public function handle(StockWithdrawalPlanController $controller, StockWithdrawalPlanningService $service): int
    {
        try {
            $result = $controller->sendDueMail($service);
            $this->info($result['count'] > 0
                ? "ส่งเมลแจ้งเตือน {$result['count']} รายการไป {$result['recipient']} แล้ว"
                : 'ไม่มีรายการที่ถึงกำหนดแล้วและยังเบิกไม่ครบ');

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error('ส่งเมลไม่สำเร็จ: '.$error->getMessage());

            return self::FAILURE;
        }
    }
}
