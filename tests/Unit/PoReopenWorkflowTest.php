<?php

namespace Tests\Unit;

use App\Http\Controllers\Po\PoApprovalController;
use App\Services\WorkflowEngine;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Tests\TestCase;

class PoReopenWorkflowTest extends TestCase
{
    public function test_reopen_route_points_to_an_existing_controller_action(): void
    {
        $route = Route::getRoutes()->getByName('po.reopen');

        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame(
            PoApprovalController::class . '@reopen',
            $route->getActionName(),
        );

        $method = new ReflectionMethod(PoApprovalController::class, 'reopen');

        $this->assertTrue($method->isPublic());
    }

    public function test_reopen_route_remains_restricted_to_purchase_users(): void
    {
        $route = Route::getRoutes()->getByName('po.reopen');

        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('permission.any:PO,POPUR', $route->gatherMiddleware());
        $this->assertContains('permission.any:POPUR', $route->gatherMiddleware());
    }

    public function test_workflow_engine_supports_reopen_and_resubmit(): void
    {
        $this->assertTrue(method_exists(WorkflowEngine::class, 'reopenCompleted'));
        $this->assertTrue(method_exists(WorkflowEngine::class, 'resubmit'));
    }
}
