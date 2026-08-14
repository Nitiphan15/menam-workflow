<?php

namespace Tests\Unit;

use App\Http\Controllers\Po\PoApprovalController;
use App\Http\Controllers\Po\PoController;
use App\Http\Middleware\EnsureAnyPermission;
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

    public function test_attachment_delete_route_is_restored_and_purchase_restricted(): void
    {
        $route = Route::getRoutes()->getByName('po.attachments.destroy');

        $this->assertNotNull($route);
        $this->assertSame(['DELETE'], $route->methods());
        $this->assertSame(PoController::class . '@destroyAttachment', $route->getActionName());
        $this->assertContains('permission.any:POPUR', $route->gatherMiddleware());
    }

    public function test_department_tracking_routes_require_pov_permission(): void
    {
        $route = Route::getRoutes()->getByName('po.departmentTracking');
        $showRoute = Route::getRoutes()->getByName('po.departmentTracking.show');
        $attachmentRoute = Route::getRoutes()->getByName('po.departmentTracking.attachments.show');
        $erpShowRoute = Route::getRoutes()->getByName('po.departmentTracking.erp.show');

        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame(PoController::class . '@departmentTracking', $route->getActionName());
        $this->assertPovMiddleware($route->gatherMiddleware());

        $this->assertNotNull($showRoute);
        $this->assertSame(PoController::class . '@departmentTrackingShow', $showRoute->getActionName());
        $this->assertPovMiddleware($showRoute->gatherMiddleware());

        $this->assertNotNull($attachmentRoute);
        $this->assertSame(PoController::class . '@departmentTrackingAttachment', $attachmentRoute->getActionName());
        $this->assertPovMiddleware($attachmentRoute->gatherMiddleware());

        $this->assertNotNull($erpShowRoute);
        $this->assertSame(PoController::class . '@departmentTrackingErpShow', $erpShowRoute->getActionName());
        $this->assertPovMiddleware($erpShowRoute->gatherMiddleware());
    }

    public function test_department_tracking_menu_uses_pov_permission(): void
    {
        $poMenu = collect(config('menu.menu.po.0.children'));
        $tracking = $poMenu->firstWhere('route', 'po.departmentTracking');

        $this->assertNotNull($tracking);
        $this->assertSame('POV', $tracking['permission']);
    }

    private function assertPovMiddleware(array $middleware): void
    {
        $this->assertTrue(
            in_array('permission.any:POV', $middleware, true)
                || in_array(EnsureAnyPermission::class . ':POV', $middleware, true),
            'POV permission middleware is missing',
        );
    }
}
