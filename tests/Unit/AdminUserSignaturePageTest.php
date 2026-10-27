<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\UserAdminController;
use App\Models\Users\User;
use App\Services\UserSignatureService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Mockery;
use Tests\TestCase;

class AdminUserSignaturePageTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_signature_routes_are_limited_to_authenticated_adminweb_users(): void
    {
        foreach (['adminweb.users.signatures', 'adminweb.users.signature.update'] as $routeName) {
            $route = app('router')->getRoutes()->getByName($routeName);

            $this->assertNotNull($route);
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('can:ADMINWEB', $route->gatherMiddleware());
        }
    }

    public function test_admin_upload_stores_the_file_without_updating_the_user_row(): void
    {
        $file = UploadedFile::fake()->image('signature.png', 300, 100);
        $request = Request::create('/adminweb/users/42/signature', 'PUT', [], [], [
            'signature' => $file,
        ]);
        $user = (new User())->forceFill(['id' => 42, 'name' => 'Test User']);
        $signatureService = Mockery::mock(UserSignatureService::class);
        $signatureService->shouldReceive('store')->once()->with($file, 42);

        $response = app(UserAdminController::class)->updateSignature($request, $user, $signatureService);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/adminweb/users/signatures?user=42', $response->getTargetUrl());
    }
}
