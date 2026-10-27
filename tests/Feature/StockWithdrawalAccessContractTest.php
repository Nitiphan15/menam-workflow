<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StockWithdrawalAccessContractTest extends TestCase
{
    public function test_tracking_menu_is_visible_to_guests_without_duplicate_after_login(): void
    {
        $guestTracking = collect(config('menu.menu.guest'))
            ->firstWhere('route', 'stock-withdrawal.part-alert');

        $this->assertNotNull($guestTracking);
        $this->assertContains('stock-withdrawal.part-alert', config('menu.hide_guest_menu_routes_when_auth'));
    }

    public function test_master_configuration_menus_are_temporarily_hidden(): void
    {
        $routes = collect(config('menu.menu.stock.0.children'))->pluck('route');

        $this->assertNotContains('stock-withdrawal.master.index', $routes);
        $this->assertNotContains('stock-withdrawal.types.index', $routes);
    }

    public function test_tracking_routes_are_available_to_every_authenticated_user(): void
    {
        foreach (['stock-withdrawal.part-alert', 'stock-withdrawal.export', 'stock-withdrawal.refresh-erp', 'stock-withdrawal.issues', 'stock-withdrawal.items.history'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertContains('auth', $middleware, $name);
            $this->assertNotContains('permission.any:STOCK,STOCK_ACTION,STOCK_ADMIN', $middleware, $name);
        }
    }

    public function test_all_jobs_routes_still_require_a_stock_role(): void
    {
        foreach (['stock-withdrawal.all-mfg', 'stock-withdrawal.all-mfg.export'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertContains('permission.any:STOCK,STOCK_ACTION,STOCK_ADMIN', $middleware, $name);
        }
    }

    public function test_planner_routes_require_action_or_admin_role(): void
    {
        foreach (['stock-withdrawal.index', 'stock-withdrawal.create', 'stock-withdrawal.plans.store', 'stock-withdrawal.items.close', 'stock-withdrawal.items.reorder', 'stock-withdrawal.items.special'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertContains('permission.any:STOCK_ACTION,STOCK_ADMIN', $middleware, $name);
        }
    }

    public function test_reopen_and_master_routes_require_admin(): void
    {
        foreach (['stock-withdrawal.items.reopen', 'stock-withdrawal.plans.reopen-cancelled', 'stock-withdrawal.master.index', 'stock-withdrawal.types.index'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertContains('permission.any:STOCK_ADMIN', $middleware, $name);
        }
    }
}
