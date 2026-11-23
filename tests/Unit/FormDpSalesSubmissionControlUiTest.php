<?php

namespace Tests\Unit;

use App\Http\Controllers\FormDP\DeliveryPlanInquiryController;
use App\Services\FormDP\DeliveryPlanSubmissionLockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class FormDpSalesSubmissionControlUiTest extends TestCase
{
    public function test_locked_status_renders_unlock_modal_with_required_reason(): void
    {
        $html = view('formdp.partials.sales-submission-control', [
            'salesSubmissionStatusDate' => '2026-11-20',
            'salesSubmissionLocked' => true,
            'lockedSalesSubmissionDates' => ['2026-11-20', '2026-11-21'],
            'canDpa' => true,
            'errors' => new ViewErrorBag(),
        ])->render();

        $this->assertStringContainsString('ล็อกอยู่', $html);
        $this->assertStringContainsString('20/11/2026', $html);
        $this->assertStringContainsString('ปลดล็อกให้ Sales', $html);
        $this->assertStringContainsString('เหตุผลที่อนุญาตให้ Sales แก้ไข', $html);
        $this->assertMatchesRegularExpression('/name="remark"[^>]*required/', $html);
        $this->assertStringContainsString('21/11/2026', $html);
        $this->assertStringContainsString(route('dp.inquiry.unlock-sales-submission'), $html);
    }

    public function test_unlocked_status_renders_manual_lock_modal_with_optional_remark(): void
    {
        $html = view('formdp.partials.sales-submission-control', [
            'salesSubmissionStatusDate' => '2026-11-22',
            'salesSubmissionLocked' => false,
            'lockedSalesSubmissionDates' => [],
            'canDpa' => true,
            'errors' => new ViewErrorBag(),
        ])->render();

        $this->assertStringContainsString('เปิดให้ Sales Submit', $html);
        $this->assertStringContainsString('ล็อก Sales Submit คืน', $html);
        $this->assertStringContainsString('หมายเหตุการล็อกคืน', $html);
        $this->assertStringContainsString('วันที่กำลังล็อก (วันนี้เป็นต้นไป):', $html);
        $this->assertStringContainsString('ไม่มี', $html);
        $this->assertDoesNotMatchRegularExpression('/name="remark"[^>]*required/', $html);
        $this->assertStringContainsString(route('dp.inquiry.lock-sales-submission'), $html);
    }

    public function test_unlock_rejects_blank_reason_before_service_or_database_call(): void
    {
        $user = (object) ['id' => 99, 'name' => 'Logistics Test', 'is_superadmin' => 1];
        Auth::shouldReceive('user')->andReturn($user);
        Auth::shouldReceive('check')->andReturn(true);

        $request = Request::create('/dp/inquiry/unlock-sales-submission', 'POST', [
            'ship_date' => '2026-11-20',
            'remark' => '',
        ]);
        $request->setLaravelSession(app('session')->driver());

        $service = Mockery::mock(DeliveryPlanSubmissionLockService::class);
        $service->shouldNotReceive('unlock');

        try {
            app(DeliveryPlanInquiryController::class)->unlockSalesSubmission($request, $service);
            $this->fail('Expected validation failure for blank unlock reason.');
        } catch (ValidationException $e) {
            $this->assertSame('salesSubmissionControl', $e->errorBag);
            $this->assertArrayHasKey('remark', $e->errors());
        }
    }
}
