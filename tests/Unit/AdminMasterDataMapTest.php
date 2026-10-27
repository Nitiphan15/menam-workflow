<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\MasterDataMapController;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class AdminMasterDataMapTest extends TestCase
{
    public function test_master_data_map_route_is_limited_to_authenticated_adminweb_users(): void
    {
        $route = app('router')->getRoutes()->getByName('adminweb.master-data-map.index');

        $this->assertNotNull($route);
        $this->assertSame(MasterDataMapController::class . '@index', $route->getActionName());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('can:ADMINWEB', $route->gatherMiddleware());
    }

    public function test_master_data_map_lists_existing_master_routes(): void
    {
        $view = app(MasterDataMapController::class)->index();
        $masters = $view->getData()['masters'];

        $this->assertGreaterThanOrEqual(21, $masters->count());
        $this->assertTrue($masters->every(fn (array $master) => $master['available']));
        $this->assertSame(1, $masters->where('route', 'adminweb.users.register')->count());
        $this->assertSame(1, $masters->where('route', 'stock-withdrawal.master.index')->count());
        $this->assertSame(1, $masters->where('route', 'accounting.cpt.masters')->count());
        $this->assertSame(1, $masters->where('module', 'Wire Rod')->count());
        $this->assertSame('wr.master', $masters->firstWhere('module', 'Wire Rod')['route']);
    }

    public function test_wire_rod_master_map_route_uses_wr_permission_and_prefilters_module(): void
    {
        $route = app('router')->getRoutes()->getByName('wr.master');

        $this->assertNotNull($route);
        $this->assertSame(MasterDataMapController::class . '@wireRod', $route->getActionName());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('can:WR', $route->gatherMiddleware());

        $view = app(MasterDataMapController::class)->wireRod();
        $this->assertSame('Wire Rod', $view->getData()['initialModule']);
    }

    public function test_master_data_map_view_contains_filters_and_existing_page_links(): void
    {
        View::share('errors', new ViewErrorBag());
        $html = app(MasterDataMapController::class)->index()->render();

        $this->assertStringContainsString('id="master-map-search"', $html);
        $this->assertStringContainsString('id="master-map-module"', $html);
        $this->assertStringContainsString('User Master', $html);
        $this->assertStringContainsString(route('adminweb.users.register'), $html);
    }
}
