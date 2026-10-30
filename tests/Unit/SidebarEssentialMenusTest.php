<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SidebarEssentialMenusTest extends TestCase
{
    public function test_authenticated_sidebar_keeps_only_essential_public_entries(): void
    {
        $root = dirname(__DIR__, 2);
        $menu = require $root . '/config/menu.php';

        $authRoutes = collect($menu['menu']['auth'])->pluck('route')->all();

        $this->assertSame(['home', 'menus.index'], $authRoutes);
        $this->assertSame(['pkg.packaging.usage'], $menu['auth_sidebar_public_routes']);
    }

    public function test_all_menus_page_and_sidebar_use_the_public_menu_contract(): void
    {
        $root = dirname(__DIR__, 2);
        $sidebar = file_get_contents($root . '/resources/views/layouts/_sidebar.blade.php');
        $page = file_get_contents($root . '/resources/views/menus/index.blade.php');
        $routes = file_get_contents($root . '/routes/web.php');

        $this->assertStringContainsString("config('menu.auth_sidebar_public_routes', [])", $sidebar);
        $this->assertStringContainsString("config('menu.menu.guest', [])", $page);
        $this->assertStringContainsString('allMenuSearch', $page);
        $this->assertMatchesRegularExpression(
            "/Route::view\('\/menus', 'menus\.index'\)\s*->middleware\('auth'\)\s*->name\('menus\.index'\)/",
            $routes,
        );
    }
}
