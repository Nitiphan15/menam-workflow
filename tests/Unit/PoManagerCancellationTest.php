<?php
namespace Tests\Unit;

use App\Services\WorkflowEngine;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PoManagerCancellationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.workflow_connection' => 'po_cancel_test', 'database.connections.po_cancel_test' => ['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]);
        $db = DB::connection('po_cancel_test');
        $db->statement('CREATE TABLE wf_forms (id INTEGER PRIMARY KEY, app_code TEXT, request_by_user_id INTEGER, current_step_no INTEGER, form_status TEXT, last_action_dt TEXT, updated_at TEXT)');
        $db->statement('CREATE TABLE wf_form_authorizes (wf_form_id INTEGER, step_no INTEGER, approver_user_id INTEGER, status TEXT)');
        $db->statement('CREATE TABLE wf_action_histories (wf_form_id INTEGER, step_no INTEGER, actor_user_id INTEGER, action_type TEXT, comment TEXT, created_at TEXT)');
        $db->table('wf_forms')->insert(['id'=>1,'app_code'=>'po','request_by_user_id'=>10,'current_step_no'=>3,'form_status'=>'REQUESTED']);
        $db->table('wf_form_authorizes')->insert(['wf_form_id'=>1,'step_no'=>3,'approver_user_id'=>20,'status'=>'PENDING']);
    }

    public function test_manager_cancel_records_reason_and_blocks_resubmit(): void
    {
        WorkflowEngine::void(1, 20, 'No longer required', 'po', true);
        $db = DB::connection('po_cancel_test');
        $this->assertSame('CANCELLED', $db->table('wf_forms')->value('form_status'));
        $this->assertEquals(998, $db->table('wf_forms')->value('current_step_no'));
        $this->assertSame('No longer required', $db->table('wf_action_histories')->value('comment'));
        $this->assertEquals(3, $db->table('wf_action_histories')->value('step_no'));
        $this->expectException(HttpException::class);
        WorkflowEngine::resubmit(1, 10, appCode: 'po');
    }

    /** @dataProvider deniedCases */
    public function test_invalid_cancellation_cannot_change_workflow(int $actor, int $step, string $status, string $reason): void
    {
        $db = DB::connection('po_cancel_test');
        $db->table('wf_forms')->update(['current_step_no'=>$step]);
        $db->table('wf_form_authorizes')->update(['status'=>$status]);
        try {
            WorkflowEngine::void(1, $actor, $reason, 'po', true);
            $this->fail('Cancellation should be denied');
        } catch (HttpException $e) {
            $this->assertContains($e->getStatusCode(), [403,422]);
        }
        $this->assertSame('REQUESTED', $db->table('wf_forms')->value('form_status'));
        $this->assertSame(0, $db->table('wf_action_histories')->count());
    }

    public static function deniedCases(): array
    {
        return [[99,3,'PENDING','Reason'],[20,2,'PENDING','Reason'],[20,3,'APPROVED','Reason'],[20,3,'PENDING','   ']];
    }

    public function test_manager_route_is_available_to_po_approvers(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('po.cancelByManager');
        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('permission.any:PO,POPUR', $route->gatherMiddleware());
        $this->assertNotContains('permission.any:POPUR', $route->gatherMiddleware());
    }
}
