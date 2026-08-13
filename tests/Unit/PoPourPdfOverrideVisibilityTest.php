<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PoPourPdfOverrideVisibilityTest extends TestCase
{
    public function test_pdf_override_access_uses_only_pour_permission(): void
    {
        $root = dirname(__DIR__, 2);
        $provider = file_get_contents($root . '/app/Providers/AuthServiceProvider.php');
        $controller = file_get_contents($root . '/app/Http/Controllers/Po/PoController.php');
        $view = file_get_contents($root . '/resources/views/po/show.blade.php');

        $this->assertStringContainsString("Gate::define('POUR'", $provider);
        $this->assertStringContainsString("Gate::allows('POUR')", $controller);
        $this->assertStringNotContainsString('isPurchaseUser', $controller);
        $this->assertStringNotContainsString('isNitiphanUser', $controller);
        $this->assertStringContainsString('@if ($canEditPdfOverride)', $view);
        $this->assertStringContainsString('เพิ่มข้อความใน PDF', $view);
    }

    public function test_successful_approval_returns_to_my_actions_instead_of_po_detail(): void
    {
        $controller = file_get_contents(
            dirname(__DIR__, 2) . '/app/Http/Controllers/Po/PoApprovalController.php',
        );

        $this->assertStringContainsString("->route('po.myActions')->with('ok', \$message)", $controller);
        $this->assertStringContainsString('catch (NotFoundHttpException $exception)', $controller);
        $this->assertStringContainsString('PO approval saved but notification failed', $controller);
    }

    public function test_opening_existing_po_is_read_only(): void
    {
        $controller = file_get_contents(
            dirname(__DIR__, 2) . '/app/Http/Controllers/Po/PoController.php',
        );
        $showStart = strpos($controller, 'public function show($id)');
        $showEnd = strpos($controller, 'public function edit($id)', $showStart);
        $showMethod = substr($controller, $showStart, $showEnd - $showStart);

        $this->assertStringNotContainsString('syncHeaderFromErp', $showMethod);
        $this->assertStringNotContainsString('->save()', $showMethod);
        $this->assertStringNotContainsString('findOrFail', $showMethod);
        $this->assertStringContainsString('getDetailRows', $showMethod);
        $this->assertStringContainsString("->route('po.myActions')", $showMethod);
    }
}
