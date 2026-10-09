<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AdminPasswordResetTest extends TestCase
{
    public function test_admin_password_reset_surface_is_wired_and_audited(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = file_get_contents($root . '/routes/web.php');
        $controller = file_get_contents($root . '/app/Http/Controllers/Admin/UserAdminController.php');
        $view = file_get_contents($root . '/resources/views/adminweb/users/register.blade.php');
        $middleware = file_get_contents($root . '/app/Http/Middleware/LogUserActivity.php');

        $this->assertStringContainsString("->name('users.password.update')", $routes);
        $this->assertStringContainsString('public function resetPassword(Request $r, User $user)', $controller);
        $this->assertStringContainsString("'password'      => 'required|string|min:4|confirmed'", $controller);
        $this->assertStringContainsString("Hash::make(\$data['password'])", $controller);
        $this->assertStringContainsString("'activity_menu_name'", $controller);
        $this->assertStringContainsString("attributes->get('activity_menu_name')", $middleware);
        $this->assertStringContainsString('btn-reset-password', $view);
        $this->assertStringContainsString('name="password_confirmation"', $view);
    }
}
