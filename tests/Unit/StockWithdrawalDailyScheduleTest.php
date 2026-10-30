<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class StockWithdrawalDailyScheduleTest extends TestCase
{
    public function test_daily_alert_command_and_schedule_stay_registered(): void
    {
        $root = dirname(__DIR__, 2);
        $command = file_get_contents($root.'/app/Console/Commands/SendStockWithdrawalDailyAlert.php');
        $kernel = file_get_contents($root.'/app/Console/Kernel.php');

        $this->assertStringContainsString("stock-withdrawal:send-daily-alert", $command);
        $this->assertStringContainsString("command('stock-withdrawal:send-daily-alert')", $kernel);
        $this->assertMatchesRegularExpression(
            "/command\('stock-withdrawal:send-daily-alert'\).*?days\(\[1, 2, 3, 4, 5, 6\]\).*?dailyAt\('08:30'\).*?timezone\('Asia\\/Bangkok'\)/s",
            $kernel
        );
    }
}
