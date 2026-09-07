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
}
