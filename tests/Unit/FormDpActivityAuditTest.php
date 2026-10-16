<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FormDpActivityAuditTest extends TestCase
{
    public function test_schema_and_critical_formdp_events_are_wired(): void
    {
        $root = dirname(__DIR__, 2);
        $schema = file_get_contents($root . '/database/sql/formdp_create_delivery_plan_activity_logs.sql');
        $logger = file_get_contents($root . '/app/Services/FormDP/DeliveryPlanActivityLogger.php');
        $sales = file_get_contents($root . '/app/Http/Controllers/FormDP/DeliveryPlanController.php');
        $inquiry = file_get_contents($root . '/app/Http/Controllers/FormDP/DeliveryPlanInquiryController.php');

        $this->assertStringContainsString('CREATE TABLE dbo.delivery_plan_activity_logs', $schema);
        $this->assertStringContainsString('CHECK (before_values IS NULL OR ISJSON(before_values) = 1)', $schema);
        $this->assertStringContainsString("->table('delivery_plan_activity_logs')->insert", $logger);
        $this->assertStringContainsString("'CREATE'", $sales);
        $this->assertStringContainsString("'EDIT'", $sales);

        foreach (['VOID', 'POSTPONE', 'POSTPONE_REPLACEMENT_CREATED', 'ASSIGN_TRUCK', 'UNASSIGN_TRUCK', 'SPECIAL_DISPATCH', 'CLOSE_TRIP'] as $event) {
            $this->assertStringContainsString("'{$event}'", $inquiry);
        }
    }
}
