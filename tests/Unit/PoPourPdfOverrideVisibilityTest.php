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

        $this->assertStringContainsString(
            "return redirect()->route('po.myActions')->with('ok', 'อนุมัติ PO เรียบร้อยแล้ว');",
            $controller,
        );
    }

    public function test_existing_po_uses_stored_header_when_erp_temporarily_returns_no_rows(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root . '/app/Http/Controllers/Po/PoController.php');
        $service = file_get_contents($root . '/app/Services/Po/PoErpService.php');

        $this->assertStringContainsString('useStoredWhenErpMissing: true', $controller);
        $this->assertStringContainsString('if ($useStoredWhenErpMissing && $record)', $service);
        $this->assertStringContainsString("abort(404, 'PO not found in ERP')", $service);
    }
}
