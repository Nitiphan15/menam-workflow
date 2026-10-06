<?php

namespace Tests\Unit;

use App\Http\Controllers\Po\PoController;
use App\Models\Po\PoAttached;
use App\Models\Po\PoHeader;
use App\Models\Users\User;
use App\Services\Po\PoAttachmentService;
use App\Services\Po\PoErpService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Illuminate\Support\Collection;
use Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        Schema::connection('sqlsrv_menam')->create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable();
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
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

    public function test_department_tracking_keeps_only_rows_resolved_to_the_users_department(): void
    {
        DB::connection('sqlsrv_menam')->table('departments')->insert([
            ['id' => 10, 'code' => 'SH1', 'name' => 'Bar 1', 'is_active' => 1],
            ['id' => 20, 'code' => 'P', 'name' => 'Purchase', 'is_active' => 1],
        ]);

        $rows = new Collection([
            (object) ['ordnumber' => 'PO001', 'department' => 'BAR1 - Production'],
            (object) ['ordnumber' => 'PO002', 'department' => 'PURCHASE'],
        ]);

        $filtered = (new PoErpService())->filterByDepartmentId($rows, 10);

        $this->assertSame(['PO001'], $filtered->pluck('ordnumber')->all());
    }

    public function test_tooling_engineer_in_rd_sees_only_tooling_rows(): void
    {
        $rows = new Collection([
            (object) ['ordnumber' => 'PO001', 'department' => 'TOOLING - R&D'],
            (object) ['ordnumber' => 'PO002', 'department' => 'ENGINEERING - R&D'],
            (object) ['ordnumber' => 'PO003', 'department' => 'PURCHASE'],
        ]);

        $service = new PoErpService();
        $filtered = $service->filterForDepartmentViewer($rows, 99, 'Tooling Engineer');

        $this->assertSame(['PO001'], $filtered->pluck('ordnumber')->all());
        $this->assertTrue(PoErpService::canDepartmentViewerAccess('TOOLING - R&D', 99, 'Tooling Engineer'));
        $this->assertFalse(PoErpService::canDepartmentViewerAccess('ENGINEERING - R&D', 99, 'Tooling Engineer'));
    }

    public function test_department_tracking_detail_allows_only_the_users_department(): void
    {
        DB::connection('sqlsrv_menam')->table('departments')->insert([
            ['id' => 10, 'code' => 'SH1', 'name' => 'Bar 1', 'is_active' => 1],
            ['id' => 20, 'code' => 'P', 'name' => 'Purchase', 'is_active' => 1],
        ]);

        $user = new User();
        $user->forceFill(['id' => 99, 'department_id' => 10]);
        $this->actingAs($user);

        $controller = new PoController(new PoErpService(), new PoAttachmentService(new PoAttached()));
        $method = new ReflectionMethod($controller, 'ensureCurrentUserCanViewDepartmentPo');
        $method->setAccessible(true);

        $allowedPo = new PoHeader();
        $allowedPo->f1 = 'BAR1 - Production';
        $method->invoke($controller, $allowedPo);
        $this->addToAssertionCount(1);

        $blockedPo = new PoHeader();
        $blockedPo->f1 = 'PURCHASE';

        try {
            $method->invoke($controller, $blockedPo);
            $this->fail('A POV user must not open a PO from another department');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_head_of_department_cannot_delete_attachments(): void
    {
        $po = new PoHeader();
        $po->status_code = 'DEPT_MANAGER_APPROVAL';

        $controller = new PoController(new PoErpService(), new PoAttachmentService(new PoAttached()));
        $method = new ReflectionMethod($controller, 'canDeleteAttachment');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($controller, $po));
    }
}
