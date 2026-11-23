<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FormDpSalesSubmissionLockContractTest extends TestCase
{
    public function test_assign_mail_lock_and_logistics_unlock_are_wired_server_side(): void
    {
        $root = dirname(__DIR__, 2);
        $service = file_get_contents($root.'/app/Services/FormDP/DeliveryPlanSubmissionLockService.php');
        $salesController = file_get_contents($root.'/app/Http/Controllers/FormDP/DeliveryPlanController.php');
        $logisticsController = file_get_contents($root.'/app/Http/Controllers/FormDP/DeliveryPlanInquiryController.php');
        $routes = file_get_contents($root.'/routes/web.php');
        $view = file_get_contents($root.'/resources/views/formdp/inquiry.blade.php');
        $truckBoard = file_get_contents($root.'/resources/views/formdp/truck-board.blade.php');
        $controlPartial = file_get_contents($root.'/resources/views/formdp/partials/sales-submission-control.blade.php');

        $this->assertStringContainsString("->where('mail_type', 'ASSIGN')", $service);
        $this->assertStringContainsString('delivery_plan_sales_submission_unlocks', $service);
        $this->assertStringContainsString('$submissionLock->isLocked($shipPostedDate)', $salesController);
        $this->assertStringContainsString('$submissionLock->isLocked($originalShipDate)', $salesController);
        $this->assertStringContainsString('function unlockSalesSubmission(', $logisticsController);
        $this->assertStringContainsString('function lockSalesSubmission(', $logisticsController);
        $this->assertStringContainsString("hasRoleCode('DPA')", $logisticsController);
        $this->assertStringContainsString("name('inquiry.unlock-sales-submission')", $routes);
        $this->assertStringContainsString("name('inquiry.lock-sales-submission')", $routes);
        $this->assertStringContainsString('formdp.partials.sales-submission-control', $view);
        $this->assertStringContainsString('formdp.partials.sales-submission-control', $truckBoard);
        $this->assertStringContainsString('ล็อก Sales Submit คืน', $controlPartial);
        $this->assertStringContainsString('วันที่กำลังล็อก (วันนี้เป็นต้นไป)', $controlPartial);
        $this->assertStringContainsString('เหตุผลที่อนุญาตให้ Sales แก้ไข', $controlPartial);
        $this->assertStringContainsString("'remark' => ['required', 'string', 'max:500']", $logisticsController);

        $truckBoardStart = strpos($logisticsController, 'public function truckBoard(');
        $truckBoardEnd = strpos($logisticsController, 'private function getSpecialDispatchGroups(', $truckBoardStart);
        $truckBoardMethod = substr($logisticsController, $truckBoardStart, $truckBoardEnd - $truckBoardStart);
        $this->assertStringContainsString('$salesSubmissionLocked = $submissionLock->isLocked($shipDate);', $truckBoardMethod);
        $this->assertStringContainsString("'salesSubmissionLocked'", $truckBoardMethod);
    }
}
