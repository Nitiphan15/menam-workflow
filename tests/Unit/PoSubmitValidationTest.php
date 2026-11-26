<?php

namespace Tests\Unit;

use App\Http\Controllers\Po\PoApprovalController;
use App\Http\Controllers\Po\PoController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PoSubmitValidationTest extends TestCase
{
    public function test_submit_uses_field_specific_validation_instead_of_generic_422_page(): void
    {
        $source = file_get_contents((new \ReflectionClass(PoApprovalController::class))->getFileName());
        $submit = $this->submitMethodSource($source);

        $this->assertStringContainsString("'erp_connection' =>", $submit);
        $this->assertStringContainsString("'manual_fallback' =>", $submit);
        $this->assertStringContainsString("'po_submit_attachment' =>", $submit);
        $this->assertStringContainsString("'po_workflow' =>", $submit);
        $this->assertStringNotContainsString('abort(422', $submit);
        $this->assertStringNotContainsString('abort_if($po->workflow_id && !$isResubmission, 422', $submit);
    }

    public function test_pdf_attachment_finishes_before_workflow_is_created(): void
    {
        $source = file_get_contents((new \ReflectionClass(PoApprovalController::class))->getFileName());
        $submit = $this->submitMethodSource($source);
        $attachmentPosition = strpos($submit, 'attachIfMissing(');
        $workflowPosition = strpos($submit, 'WorkflowEngine::submit(');

        $this->assertNotFalse($attachmentPosition);
        $this->assertNotFalse($workflowPosition);
        $this->assertLessThan(
            $workflowPosition,
            $attachmentPosition,
            'PO PDF must be downloaded and attached before creating a workflow.',
        );
    }

    public function test_show_page_places_each_error_next_to_its_action(): void
    {
        $view = file_get_contents(resource_path('views/po/show.blade.php'));

        foreach (['erp_connection', 'erp_download', 'erp_phpsessid', 'po_submit_attachment', 'manual_fallback', 'po_workflow'] as $key) {
            $this->assertStringContainsString("\$errors->has('{$key}')", $view);
        }
    }

    public function test_auto_attachment_route_is_post_and_purchase_only(): void
    {
        $route = Route::getRoutes()->getByName('po.autoAttachPo');

        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame(PoController::class . '@autoAttachPo', $route->getActionName());
        $this->assertContains('permission.any:POPUR', $route->gatherMiddleware());
    }

    public function test_show_page_auto_attaches_with_retry_without_get_mutation(): void
    {
        $view = file_get_contents(resource_path('views/po/show.blade.php'));

        $this->assertStringContainsString("route('po.autoAttachPo'", $view);
        $this->assertStringContainsString("method: 'POST'", $view);
        $this->assertStringContainsString('ลองแนบไฟล์ PO อีกครั้ง', $view);
        $this->assertStringContainsString('retry.disabled = loading', $view);
    }

    private function submitMethodSource(string $source): string
    {
        $start = strpos($source, 'public function submit(Request $request, $id)');
        $end = strpos($source, 'private function erpSessionId()', $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($source, $start, $end - $start);
    }
}


