<?php

namespace Tests\Unit;

use App\Services\FormPP\ProductionBalanceCalculator;
use App\Services\FormPP\ProductionBalanceService;
use App\Services\FormWIP\WipMonitoringService;
use PHPUnit\Framework\TestCase;

class ProductionBalanceIntegrationContractTest extends TestCase
{
    public function test_balance_reuses_wip_monitoring_buckets_and_tracking_tolerance(): void
    {
        $service = file_get_contents(__DIR__.'/../../app/Services/FormPP/ProductionBalanceService.php');
        $calculator = file_get_contents(__DIR__.'/../../app/Services/FormPP/ProductionBalanceCalculator.php');

        $this->assertStringContainsString('WipMonitoringService::SYNC_DAYS', $service);
        $this->assertStringContainsString("['IN_PROCESS', 'WAITING_PACKAGING', 'WAITING_FG', 'HOLD']", $service);
        $this->assertStringContainsString("['buckets']['WAITING_PRODUCTION']", $service);
        $this->assertStringContainsString('ProductionWeightTolerance::allowedShortageKg', $calculator);
    }

    public function test_scheduler_is_disabled_by_default_and_missing_tables_do_not_raise_failure_alert(): void
    {
        $kernel = file_get_contents(__DIR__.'/../../app/Console/Kernel.php');
        $config = file_get_contents(__DIR__.'/../../config/mail.php');
        $command = file_get_contents(__DIR__.'/../../app/Console/Commands/SendPpProductionBalanceAlert.php');

        $this->assertStringContainsString("config('mail.pp_balance_alert.enabled', false)", $kernel);
        $this->assertStringContainsString("env('PP_BALANCE_ALERT_ENABLED', false)", $config);
        $this->assertMatchesRegularExpression('/tables are missing.*?return self::SUCCESS;/s', $command);
    }

    public function test_data_issue_is_filterable_but_email_only_selects_excess(): void
    {
        $controller = file_get_contents(__DIR__.'/../../app/Http/Controllers/FormPP/ProductionBalanceController.php');
        $command = file_get_contents(__DIR__.'/../../app/Console/Commands/SendPpProductionBalanceAlert.php');

        $this->assertStringContainsString('DATA_ISSUE,EXCESS,SHORT,COMPLETE', $controller);
        $this->assertStringContainsString("where('status', 'EXCESS')", $command);
        $this->assertStringNotContainsString("where('status', 'DATA_ISSUE')", $command);
        $this->assertStringContainsString('{--preview : Calculate and display excess rows without saving history or sending mail}', $command);
        $this->assertStringContainsString('no history saved and no email sent', $command);
    }

    public function test_so_quantity_conversion_keeps_kg_converts_reference_units_and_flags_missing_factor(): void
    {
        $service = new ProductionBalanceService(
            new ProductionBalanceCalculator(),
            $this->createMock(WipMonitoringService::class),
        );

        $this->assertSame([100.0, []], $service->convertBackorderToKg(100, 'KG', null));
        $this->assertSame([250.0, []], $service->convertBackorderToKg(100, 'PCS', 2.5));
        [$qty, $issues] = $service->convertBackorderToKg(100, 'PCS', null);
        $this->assertSame(0.0, $qty);
        $this->assertNotEmpty($issues);
    }
}
