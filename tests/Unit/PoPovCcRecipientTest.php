<?php

namespace Tests\Unit;

use App\Http\Controllers\Po\PoApprovalController;
use App\Mail\PoNeedsApprovalMail;
use App\Models\Po\PoAttached;
use App\Models\Po\PoHeader;
use App\Services\Po\PoAttachmentService;
use App\Services\Po\PoErpService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class PoPovCcRecipientTest extends TestCase
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

        Schema::connection('sqlsrv_menam')->create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
        });
        Schema::connection('sqlsrv_menam')->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('department_id')->nullable();
        });
        Schema::connection('sqlsrv_menam')->create('dept_roles', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->boolean('is_active')->default(true);
        });
        Schema::connection('sqlsrv_menam')->create('user_dept_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('department_id');
            $table->unsignedBigInteger('role_id');
        });
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

    public function test_only_active_pov_users_in_po_department_are_cc_recipients(): void
    {
        DB::connection('sqlsrv_menam')->table('departments')->insert([
            ['id' => 11, 'code' => 'ITH', 'name' => 'IT Hardware', 'is_active' => 1],
            ['id' => 12, 'code' => 'ITS', 'name' => 'IT Solution', 'is_active' => 1],
        ]);
        DB::connection('sqlsrv_menam')->table('dept_roles')->insert([
            ['id' => 100, 'code' => 'POV', 'is_active' => 1],
            ['id' => 101, 'code' => 'PO', 'is_active' => 1],
        ]);
        DB::connection('sqlsrv_menam')->table('users')->insert([
            ['id' => 1, 'name' => 'ITH Viewer', 'email' => 'viewer@example.com', 'is_active' => 1, 'department_id' => 11],
            ['id' => 2, 'name' => 'Inactive Viewer', 'email' => 'inactive@example.com', 'is_active' => 0, 'department_id' => 11],
            ['id' => 3, 'name' => 'Wrong Department', 'email' => 'other@example.com', 'is_active' => 1, 'department_id' => 12],
            ['id' => 4, 'name' => 'Wrong Role', 'email' => 'po@example.com', 'is_active' => 1, 'department_id' => 11],
        ]);
        DB::connection('sqlsrv_menam')->table('user_dept_roles')->insert([
            ['user_id' => 1, 'department_id' => 11, 'role_id' => 100],
            ['user_id' => 2, 'department_id' => 11, 'role_id' => 100],
            ['user_id' => 3, 'department_id' => 12, 'role_id' => 100],
            ['user_id' => 4, 'department_id' => 11, 'role_id' => 101],
        ]);

        $po = new PoHeader();
        $po->f1 = 'IT Hardware';

        $method = new ReflectionMethod($this->makeController(), 'povDepartmentRecipients');
        $method->setAccessible(true);
        $recipients = $method->invoke($this->makeController(), $po);

        $this->assertSame(['viewer@example.com'], $recipients->pluck('email')->all());
    }

    public function test_step_three_mail_ccs_department_pov_with_tracking_link(): void
    {
        Mail::fake();
        config(['queue.po_mail_enabled' => false]);

        DB::connection('sqlsrv_menam')->table('departments')->insert([
            'id' => 11, 'code' => 'ITH', 'name' => 'IT Hardware', 'is_active' => 1,
        ]);
        DB::connection('sqlsrv_menam')->table('dept_roles')->insert([
            'id' => 100, 'code' => 'POV', 'is_active' => 1,
        ]);
        DB::connection('sqlsrv_menam')->table('users')->insert([
            ['id' => 1, 'name' => 'Department Viewer', 'email' => 'viewer@example.com', 'is_active' => 1, 'department_id' => 11],
            ['id' => 2, 'name' => 'Department Manager', 'email' => 'manager@example.com', 'is_active' => 1, 'department_id' => 11],
        ]);
        DB::connection('sqlsrv_menam')->table('user_dept_roles')->insert([
            'user_id' => 1, 'department_id' => 11, 'role_id' => 100,
        ]);
        DB::connection('sqlsrv_menam')->table('wf_forms')->insert([
            'id' => 500, 'current_step_no' => 3,
        ]);
        DB::connection('sqlsrv_menam')->table('wf_form_authorizes')->insert([
            'wf_form_id' => 500, 'step_no' => 3, 'approver_user_id' => 2, 'status' => 'PENDING',
        ]);

        $po = new PoHeader();
        $po->id = 700;
        $po->workflow_id = 500;
        $po->ordnumber = 'PO-ITH-700';
        $po->site = 'wire';
        $po->f1 = 'IT Hardware';
        $po->vendor_name = 'Hardware Vendor';
        $po->status_code = 'DEPT_MANAGER_APPROVAL';

        $method = new ReflectionMethod($this->makeController(), 'notifyPendingApprovers');
        $method->setAccessible(true);
        $method->invoke($this->makeController(), collect([$po]));

        Mail::assertSent(PoNeedsApprovalMail::class, function ($mail) {
            return $mail->hasTo('manager@example.com')
                && $mail->hasCc('viewer@example.com')
                && ($mail->poItems[0]['tracking_url'] ?? null) === route('po.departmentTracking.show', 700);
        });
    }

    private function makeController(): PoApprovalController
    {
        return new PoApprovalController(
            new PoErpService(),
            new PoAttachmentService(new PoAttached()),
        );
    }
}
