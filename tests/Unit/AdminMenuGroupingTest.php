<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AdminMenuGroupingTest extends TestCase
{
    public function test_admin_menu_has_non_route_group_headings(): void
    {
        $root = dirname(__DIR__, 2);
        $menu = file_get_contents($root . '/config/menu.php');
        $sidebar = file_get_contents($root . '/resources/views/layouts/_sidebar.blade.php');

        $this->assertStringContainsString("'text' => 'ผู้ใช้และโครงสร้างองค์กร', 'header' => true", $menu);
        $this->assertStringContainsString("'text' => 'สิทธิ์และการตรวจสอบ', 'header' => true", $menu);
        $this->assertStringContainsString("'text' => 'ดูแลระบบ', 'header' => true", $menu);
        $this->assertSame(2, substr_count($sidebar, "!empty(\$child['header'])"));
        $this->assertStringContainsString('submenu-heading', $sidebar);
    }
}
