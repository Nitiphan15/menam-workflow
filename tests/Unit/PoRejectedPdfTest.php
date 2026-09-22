<?php

namespace Tests\Unit;

use App\Http\Controllers\Po\PoExportController;
use App\Models\Po\PoHeader;
use App\Models\WF\WfForm;
use App\Models\WF\WfActionHistory;
use App\Services\Po\PoErpService;
use ReflectionMethod;
use Tests\TestCase;

class PoRejectedPdfTest extends TestCase
{
    public function test_latest_rejection_reason_and_reopen_reason_are_used(): void
    {
        $po = new PoHeader(['status_code' => 'REJECTED']);
        $workflow = new WfForm;
        $workflow->setRelation('histories', collect([
            new WfActionHistory(['id' => 1, 'action_type' => 'REJECT', 'comment' => 'Old reason']),
            new WfActionHistory(['id' => 2, 'action_type' => 'REOPEN', 'comment' => 'แก้ไขราคาไม่ถูกต้อง']),
        ])->each(function ($row, $index) { $row->id = $index + 1; }));
        $po->setRelation('workflow', $workflow);
        $method = new ReflectionMethod(PoExportController::class, 'rejectionRemark');
        $controller = new PoExportController($this->app->make(PoErpService::class));
        $this->assertSame('แก้ไขราคาไม่ถูกต้อง', $method->invoke($controller, $po));
        $po->status_code = 'CLOSED';
        $this->assertNull($method->invoke($controller, $po));
        $po->status_code = 'REQUESTED';
        $this->assertNull($method->invoke($controller, $po));
    }

    public function test_rejected_without_history_still_has_a_visible_reason(): void
    {
        $po = new PoHeader(['status_code' => 'REJECTED']);
        $po->setRelation('workflow', null);
        $controller = new PoExportController($this->app->make(PoErpService::class));
        $this->assertSame('ไม่ระบุเหตุผล', (new ReflectionMethod($controller, 'rejectionRemark'))->invoke($controller, $po));
    }

    public function test_cancelled_pdf_uses_cancellation_reason_instead_of_rejection(): void
    {
        $po = new PoHeader(['status_code' => 'CANCELLED']);
        $workflow = new WfForm;
        $cancel = new WfActionHistory(['action_type' => 'CANCEL', 'comment' => 'ไม่ต้องการสั่งซื้อแล้ว']);
        $cancel->id = 2;
        $reject = new WfActionHistory(['action_type' => 'REJECT', 'comment' => 'Old rejection']);
        $reject->id = 1;
        $workflow->setRelation('histories', collect([$reject, $cancel]));
        $po->setRelation('workflow', $workflow);
        $controller = new PoExportController($this->app->make(PoErpService::class));
        $this->assertSame('ไม่ต้องการสั่งซื้อแล้ว', (new ReflectionMethod($controller, 'rejectionRemark'))->invoke($controller, $po));
    }

    public function test_actor_matches_latest_action_for_each_status(): void
    {
        $reject = new WfActionHistory(['action_type' => 'REJECT', 'actor_user_id' => 10]);
        $reject->id = 1;
        $reject->setRelation('actor', new \App\Models\Users\User(['name' => 'ผู้รีเจค ทดสอบ']));
        $cancel = new WfActionHistory(['action_type' => 'CANCEL', 'actor_user_id' => 20]);
        $cancel->id = 2;
        $cancel->setRelation('actor', new \App\Models\Users\User(['name' => 'ผู้ยกเลิก ทดสอบ']));
        $workflow = new WfForm;
        $workflow->setRelation('histories', collect([$reject, $cancel]));
        $po = new PoHeader(['status_code' => 'REJECTED']);
        $po->setRelation('workflow', $workflow);
        $controller = new PoExportController($this->app->make(PoErpService::class));
        $method = new ReflectionMethod($controller, 'rejectionActor');
        $this->assertSame('ผู้รีเจค: ผู้รีเจค ทดสอบ', $method->invoke($controller, $po));
        $po->status_code = 'CANCELLED';
        $this->assertSame('ผู้ยกเลิก: ผู้ยกเลิก ทดสอบ', $method->invoke($controller, $po));
        $cancel->setRelation('actor', null);
        $this->assertSame('ผู้ยกเลิก: User ID 20', $method->invoke($controller, $po));
    }
}
