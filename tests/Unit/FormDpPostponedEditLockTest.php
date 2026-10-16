<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FormDpPostponedEditLockTest extends TestCase
{
    public function test_postponed_plan_is_blocked_in_ui_and_both_controller_entry_points(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents(
            $root . '/app/Http/Controllers/FormDP/DeliveryPlanController.php'
        );
        $inquiryView = file_get_contents(
            $root . '/resources/views/formdp/inquiry.blade.php'
        );

        $this->assertStringContainsString("'status',", $controller);
        $this->assertSame(3, substr_count($controller, 'isPostponedPlanStatus('));
        $this->assertStringContainsString(
            "return strtoupper(trim((string) \$status)) === 'POSTPONED';",
            $controller
        );
        $this->assertStringContainsString(
            "@if (\$statusUpper === 'POSTPONED')",
            $inquiryView
        );
        $this->assertStringContainsString(
            'รายการนี้ถูกเลื่อนแผนแล้ว ไม่สามารถแก้ไขรายการเดิมได้',
            $controller
        );
    }
}
