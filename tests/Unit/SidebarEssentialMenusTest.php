<?php

namespace Tests\Unit;

use App\Support\MenuCatalog;
use PHPUnit\Framework\TestCase;

class SidebarEssentialMenusTest extends TestCase
{
    private function config(): array
    {
        return require dirname(__DIR__, 2) . '/config/menu.php';
    }

    private function allRoutesExist(): callable
    {
        return fn(string $route) => true;
    }

    public function test_authenticated_sidebar_keeps_only_essential_public_entries(): void
    {
        $menu = $this->config();

        $authRoutes = collect($menu['menu']['auth'])->pluck('route')->all();

        $this->assertSame(['home', 'menus.index'], $authRoutes);
        $this->assertSame(
            ['dp.inquiry', 'deadstock.dashboard', 'wos.sales_unit_summary', 'pkg.packaging.usage'],
            $menu['auth_sidebar_public_routes'],
        );
    }

    public function test_every_whitelisted_route_exists_in_guest_menu(): void
    {
        $menu = $this->config();

        $guestRoutes = [];
        foreach ($menu['menu']['guest'] as $item) {
            $guestRoutes[] = $item['route'] ?? null;
            foreach ($item['children'] ?? [] as $child) {
                $guestRoutes[] = $child['route'] ?? null;
            }
        }

        foreach ($menu['auth_sidebar_public_routes'] as $route) {
            $this->assertContains($route, $guestRoutes, "{$route} ไม่อยู่ใน menu.guest → sidebar จะไม่แสดง");
        }
    }

    public function test_sidebar_matches_whitelist_against_child_routes(): void
    {
        $sidebar = file_get_contents(dirname(__DIR__, 2) . '/resources/views/layouts/_sidebar.blade.php');

        $this->assertStringContainsString("array_intersect(\$childRoutes, \$publicSidebarRoutes)", $sidebar);
        $this->assertStringContainsString('$plainItemIsActive($item)', $sidebar);
    }

    public function test_menus_route_uses_controller_behind_auth(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2) . '/routes/web.php');

        $this->assertMatchesRegularExpression(
            "/Route::get\('\/menus', \[\\\\?App\\\\Http\\\\Controllers\\\\MenuController::class, 'index'\]\)\s*->middleware\('auth'\)\s*->name\('menus\.index'\)/",
            $routes,
        );
    }

    public function test_catalog_excludes_home_login_and_self(): void
    {
        $groups = MenuCatalog::groups([
            ['text' => 'หน้าหลัก', 'route' => 'home'],
            ['text' => 'เข้าสู่ระบบ', 'route' => 'login'],
            ['text' => 'เมนูทั้งหมด', 'route' => 'menus.index'],
            ['text' => 'Packaging', 'icon' => 'fa-box', 'route' => 'pkg.packaging.usage'],
        ], [], $this->allRoutesExist());

        $this->assertCount(1, $groups);
        $this->assertSame('pkg.packaging.usage', $groups[0]['links'][0]['route']);
        $this->assertSame('fas fa-box', $groups[0]['icon']);
    }

    public function test_catalog_skips_missing_routes_instead_of_crashing(): void
    {
        $groups = MenuCatalog::groups([
            ['text' => 'Gone', 'route' => 'removed.route'],
            [
                'text' => 'Group',
                'children' => [
                    ['text' => 'Alive', 'route' => 'alive.route'],
                    ['text' => 'Dead', 'route' => 'dead.route'],
                ],
            ],
        ], [], fn(string $route) => $route === 'alive.route');

        $this->assertCount(1, $groups);
        $this->assertSame(['alive.route'], array_column($groups[0]['links'], 'route'));
    }

    public function test_catalog_filters_by_child_permission(): void
    {
        $items = [[
            'text' => 'Accounting Reports',
            'children' => [
                ['text' => 'Public', 'route' => 'acc.public'],
                ['text' => 'VC only', 'route' => 'acc.vc', 'permission' => ['VC', 'VCC']],
                ['text' => 'MG only', 'route' => 'acc.mg', 'permission' => 'mg'],
            ],
        ]];

        $noRole = MenuCatalog::groups($items, [], $this->allRoutesExist());
        $this->assertSame(['acc.public'], array_column($noRole[0]['links'], 'route'));

        $vcc = MenuCatalog::groups($items, ['VCC', 'MG'], $this->allRoutesExist());
        $this->assertSame(['acc.public', 'acc.vc', 'acc.mg'], array_column($vcc[0]['links'], 'route'));
    }

    public function test_catalog_hides_whole_group_when_parent_permission_missing(): void
    {
        $items = [[
            'text' => 'Secret',
            'permission' => 'SSC',
            'children' => [
                ['text' => 'No permission on child', 'route' => 'secret.child'],
            ],
        ]];

        $this->assertSame([], MenuCatalog::groups($items, [], $this->allRoutesExist()));
        $this->assertCount(1, MenuCatalog::groups($items, ['SSC'], $this->allRoutesExist()));
    }

    public function test_catalog_deduplicates_routes_and_drops_empty_groups(): void
    {
        $groups = MenuCatalog::groups([
            ['text' => 'A', 'children' => [['text' => 'X', 'route' => 'x']]],
            ['text' => 'B', 'children' => [['text' => 'X again', 'route' => 'x']]],
            ['text' => 'Locked', 'children' => [['text' => 'Y', 'route' => 'y', 'permission' => 'NOPE']]],
        ], [], $this->allRoutesExist());

        $this->assertSame(['A'], array_column($groups, 'text'));
    }

    public function test_real_guest_config_respects_permissions(): void
    {
        $guest = $this->config()['menu']['guest'];

        $routes = fn(array $groups) => array_merge(...array_map(
            fn($g) => array_column($g['links'], 'route'),
            $groups,
        ));

        $plain = $routes(MenuCatalog::groups($guest, [], $this->allRoutesExist()));
        $this->assertNotContains('ssc.index', $plain);
        $this->assertNotContains('variable-cost.summary', $plain);
        $this->assertNotContains('home', $plain);
        $this->assertContains('deadstock.dashboard', $plain);

        $ssc = $routes(MenuCatalog::groups($guest, ['SSC'], $this->allRoutesExist()));
        $this->assertContains('ssc.index', $ssc);
    }
}
