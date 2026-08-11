<?php

namespace Tests\Unit;

use App\Http\Controllers\Po\PoController;
use App\Models\Po\PoAttached;
use App\Services\Po\PoAttachmentService;
use App\Services\Po\PoErpService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class PoMyActionsVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.sqlsrv_menam', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('database.workflow_connection', 'sqlsrv_menam');
        DB::purge('sqlsrv_menam');

        Schema::connection('sqlsrv_menam')->create('wf_forms', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('current_step_no');
        });
        Schema::connection('sqlsrv_menam')->create('wf_form_authorizes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wf_form_id');
            $table->unsignedInteger('step_no');
            $table->unsignedBigInteger('approver_user_id');
            $table->string('status');
        });
    }

    public function test_my_actions_keeps_current_pending_and_previously_approved_workflows(): void
    {
        DB::connection('sqlsrv_menam')->table('wf_forms')->insert([
            ['id' => 1, 'current_step_no' => 2],
            ['id' => 2, 'current_step_no' => 3],
            ['id' => 3, 'current_step_no' => 3],
            ['id' => 4, 'current_step_no' => 2],
        ]);
        DB::connection('sqlsrv_menam')->table('wf_form_authorizes')->insert([
            ['wf_form_id' => 1, 'step_no' => 2, 'approver_user_id' => 99, 'status' => 'PENDING'],
            ['wf_form_id' => 2, 'step_no' => 2, 'approver_user_id' => 99, 'status' => 'APPROVED'],
            ['wf_form_id' => 3, 'step_no' => 2, 'approver_user_id' => 99, 'status' => 'PENDING'],
            ['wf_form_id' => 4, 'step_no' => 2, 'approver_user_id' => 99, 'status' => 'REJECTED'],
        ]);

        $controller = new PoController(new PoErpService(), new PoAttachmentService(new PoAttached()));
        $method = new ReflectionMethod($controller, 'workflowIdsVisibleToApprover');
        $method->setAccessible(true);

        $this->assertSame([1, 2], $method->invoke($controller, 99));
    }

    public function test_completed_statuses_are_visible_only_when_requested_by_my_actions(): void
    {
        $service = new PoErpService();
        $method = new ReflectionMethod($service, 'isListStatusVisible');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, 'CLOSED', '', true));
        $this->assertTrue($method->invoke($service, 'APPROVED', '', true));
        $this->assertFalse($method->invoke($service, 'CLOSED', '', false));
        $this->assertFalse($method->invoke($service, 'APPROVED', '', false));
    }
}
