<?php

namespace Tests\Unit;

use App\Http\Controllers\Po\PoApproverMasterController;
use App\Services\ApproverResolver;
use App\Services\Po\PoErpService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PoDepartmentApproverResolverTest extends TestCase
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

        $schema = Schema::connection('sqlsrv_menam');
        $schema->create('departments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('code')->nullable();
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
        });
        $schema->create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
        });
        $schema->create('po_department_approvers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('department_id');
            $table->unsignedBigInteger('approver_user_id');
            $table->unsignedInteger('sequence_no')->default(1);
            $table->boolean('is_active')->default(true);
        });
        $schema->create('po_department_alias_approvers', function (Blueprint $table) {
            $table->id();
            $table->string('alias_key')->unique();
            $table->unsignedBigInteger('approver_user_id');
            $table->boolean('is_active')->default(true);
        });
        $schema->create('workflows', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->boolean('is_active')->default(true);
        });
        $schema->create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workflow_id');
            $table->unsignedInteger('step_no');
            $table->boolean('is_active')->default(true);
        });
        $schema->create('workflow_step_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workflow_step_id');
            $table->string('source_type');
            $table->unsignedBigInteger('source_ref_id')->nullable();
            $table->boolean('department_scoped')->default(false);
            $table->text('condition_expr')->nullable();
            $table->unsignedInteger('priority')->default(1);
        });
        $schema->create('department_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('department_id');
            $table->string('name');
            $table->unsignedInteger('level_no');
            $table->boolean('is_active')->default(true);
        });
        $schema->create('department_role_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('department_role_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_primary')->default(false);
            $table->date('start_date');
            $table->date('end_date')->nullable();
        });
    }

    public function test_exact_department_mapping_overrides_parent_mapping(): void
    {
        DB::connection('sqlsrv_menam')->table('departments')->insert([
            ['id' => 10, 'parent_id' => null],
            ['id' => 11, 'parent_id' => 10],
        ]);
        DB::connection('sqlsrv_menam')->table('users')->insert([
            ['id' => 101, 'is_active' => 1],
            ['id' => 102, 'is_active' => 1],
        ]);
        DB::connection('sqlsrv_menam')->table('po_department_approvers')->insert([
            ['department_id' => 10, 'approver_user_id' => 101, 'is_active' => 1],
            ['department_id' => 11, 'approver_user_id' => 102, 'is_active' => 1],
        ]);

        $result = ApproverResolver::poDepartmentApprovers(
            (object) ['app_code' => 'po', 'current_step_no' => 3],
            ['document_department_id' => 11, 'workflow_step_no' => 3],
        );

        $this->assertSame([102], $result->all());
    }

    public function test_query_falls_back_to_nearest_active_parent_mapping(): void
    {
        DB::connection('sqlsrv_menam')->table('departments')->insert([
            ['id' => 20, 'parent_id' => null],
            ['id' => 21, 'parent_id' => 20],
        ]);
        DB::connection('sqlsrv_menam')->table('users')->insert([
            ['id' => 201, 'is_active' => 1],
            ['id' => 202, 'is_active' => 0],
        ]);
        DB::connection('sqlsrv_menam')->table('po_department_approvers')->insert([
            ['department_id' => 20, 'approver_user_id' => 201, 'is_active' => 1],
            ['department_id' => 21, 'approver_user_id' => 202, 'is_active' => 1],
        ]);

        $result = ApproverResolver::poDepartmentApprovers(
            (object) ['app_code' => 'po', 'current_step_no' => 3],
            ['document_department_id' => 21, 'workflow_step_no' => 3],
        );

        $this->assertSame([201], $result->all());
    }

    public function test_mapping_query_is_not_used_outside_formpo_step_three(): void
    {
        DB::connection('sqlsrv_menam')->table('departments')->insert(['id' => 30, 'parent_id' => null]);
        DB::connection('sqlsrv_menam')->table('users')->insert(['id' => 301, 'is_active' => 1]);
        DB::connection('sqlsrv_menam')->table('po_department_approvers')->insert([
            'department_id' => 30,
            'approver_user_id' => 301,
            'is_active' => 1,
        ]);

        $wrongApp = ApproverResolver::poDepartmentApprovers(
            (object) ['app_code' => 'fc', 'current_step_no' => 3],
            ['document_department_id' => 30, 'workflow_step_no' => 3],
        );
        $wrongStep = ApproverResolver::poDepartmentApprovers(
            (object) ['app_code' => 'po', 'current_step_no' => 2],
            ['document_department_id' => 30, 'workflow_step_no' => 2],
        );

        $this->assertTrue($wrongApp->isEmpty());
        $this->assertTrue($wrongStep->isEmpty());
    }

    public function test_tooling_alias_overrides_the_resolved_rd_department(): void
    {
        DB::connection('sqlsrv_menam')->table('departments')->insert([
            ['id' => 40, 'parent_id' => null, 'code' => 'R', 'name' => 'R&D', 'is_active' => 1],
        ]);
        DB::connection('sqlsrv_menam')->table('users')->insert(['id' => 401, 'is_active' => 1]);
        DB::connection('sqlsrv_menam')->table('po_department_alias_approvers')->insert([
            'alias_key' => 'TOOLING',
            'approver_user_id' => 401,
            'is_active' => 1,
        ]);

        $result = ApproverResolver::poDepartmentApprovers(
            (object) ['app_code' => 'po', 'current_step_no' => 3],
            [
                'document_department_id' => 40,
                'document_department_name' => 'R&D - Tooling',
                'workflow_step_no' => 3,
            ],
        );

        $this->assertSame([401], $result->all());
    }

    public function test_erp_drawing_and_tooling_aliases_resolve_to_their_real_departments(): void
    {
        DB::connection('sqlsrv_menam')->table('departments')->insert([
            ['id' => 50, 'parent_id' => null, 'code' => 'SQR', 'name' => 'รีดเหลี่ยม', 'is_active' => 1],
            ['id' => 51, 'parent_id' => null, 'code' => 'R', 'name' => 'R&D', 'is_active' => 1],
        ]);

        $this->assertSame(50, PoErpService::resolveDepartmentId('Drawing'));
        $this->assertSame(51, PoErpService::resolveDepartmentId('R&D - Tooling'));
    }

    public function test_po_list_department_groups_resolve_to_department_master(): void
    {
        DB::connection('sqlsrv_menam')->table('departments')->insert([
            ['id' => 80, 'code' => 'SH1', 'name' => 'เพลา1', 'is_active' => 1],
            ['id' => 81, 'code' => 'SH2', 'name' => 'BAR 2', 'is_active' => 1],
            ['id' => 82, 'code' => 'CG', 'name' => 'CG', 'is_active' => 1],
            ['id' => 83, 'code' => 'DD', 'name' => 'แต่งไดร์', 'is_active' => 1],
            ['id' => 84, 'code' => 'SP', 'name' => 'จัดส่ง', 'is_active' => 1],
            ['id' => 85, 'code' => 'PK', 'name' => 'แพ็คกิ้ง', 'is_active' => 1],
            ['id' => 86, 'code' => 'ST', 'name' => 'สต็อกวัตถุดิบ', 'is_active' => 1],
            ['id' => 87, 'code' => 'GT', 'name' => 'Grating', 'is_active' => 1],
            ['id' => 88, 'code' => 'IM', 'name' => 'ขายในประเทศ', 'is_active' => 1],
            ['id' => 89, 'code' => 'EP', 'name' => 'ขายต่างประเทศ', 'is_active' => 1],
        ]);

        $this->assertSame(80, PoErpService::resolveDepartmentId('Bar1 - k.ชวลิต'));
        $this->assertSame(81, PoErpService::resolveDepartmentId('Bar2-k.ชิษณุพงศ์'));
        $this->assertSame(82, PoErpService::resolveDepartmentId('CGM-k.อนุสิชฐ์'));
        $this->assertSame(83, PoErpService::resolveDepartmentId('DIE - k.ไพฑูรย์'));
        $this->assertSame(84, PoErpService::resolveDepartmentId('Logistic-k.ประมวล'));
        $this->assertSame(85, PoErpService::resolveDepartmentId('Pack - K.ภัทรวดี'));
        $this->assertSame(86, PoErpService::resolveDepartmentId('Stock -k.กาญจนา'));
        $this->assertSame(87, PoErpService::resolveDepartmentId('W&F-k.อรุณี'));
        $this->assertSame(88, PoErpService::resolveDepartmentId('Sale - k.Wilawan'));
        $this->assertSame(89, PoErpService::resolveDepartmentId('Export-K.Sarun'));
    }

    public function test_additional_approvers_are_returned_in_configured_sequence(): void
    {
        DB::connection('sqlsrv_menam')->table('departments')->insert([
            'id' => 60,
            'parent_id' => null,
            'code' => 'SPECIAL',
            'name' => 'Special Department',
            'is_active' => 1,
        ]);
        DB::connection('sqlsrv_menam')->table('users')->insert([
            ['id' => 601, 'is_active' => 1],
            ['id' => 602, 'is_active' => 1],
            ['id' => 603, 'is_active' => 1],
        ]);
        DB::connection('sqlsrv_menam')->table('po_department_approvers')->insert([
            ['department_id' => 60, 'approver_user_id' => 601, 'sequence_no' => 1, 'is_active' => 1],
            ['department_id' => 60, 'approver_user_id' => 603, 'sequence_no' => 3, 'is_active' => 1],
            ['department_id' => 60, 'approver_user_id' => 602, 'sequence_no' => 2, 'is_active' => 1],
        ]);

        $workflow = (object) ['app_code' => 'po', 'current_step_no' => 3];
        $context = ['document_department_id' => 60, 'workflow_step_no' => 3];

        $this->assertSame([601], ApproverResolver::poDepartmentApprovers($workflow, $context)->all());
        $this->assertSame([602, 603], ApproverResolver::poAdditionalDepartmentApprovers($workflow, $context)->all());
    }

    public function test_master_read_only_rows_include_fixed_and_position_resolved_departments(): void
    {
        DB::connection('sqlsrv_menam')->table('departments')->insert([
            ['id' => 70, 'code' => 'FIX', 'name' => 'Fixed Department', 'is_active' => 1],
            ['id' => 71, 'code' => 'DYN', 'name' => 'Dynamic Department', 'is_active' => 1],
        ]);
        DB::connection('sqlsrv_menam')->table('users')->insert([
            ['id' => 701, 'department_id' => 70, 'username' => 'fixed_user', 'name' => 'Fixed User', 'is_active' => 1],
            ['id' => 702, 'department_id' => 71, 'username' => 'manager_user', 'name' => 'Manager User', 'is_active' => 1],
        ]);
        DB::connection('sqlsrv_menam')->table('po_department_approvers')->insert([
            'department_id' => 70,
            'approver_user_id' => 701,
            'sequence_no' => 1,
            'is_active' => 1,
        ]);
        DB::connection('sqlsrv_menam')->table('workflows')->insert(['id' => 1, 'code' => 'po', 'is_active' => 1]);
        DB::connection('sqlsrv_menam')->table('workflow_steps')->insert([
            'id' => 2,
            'workflow_id' => 1,
            'step_no' => 3,
            'is_active' => 1,
        ]);
        DB::connection('sqlsrv_menam')->table('workflow_step_rules')->insert([
            'workflow_step_id' => 2,
            'source_type' => 'ROLE',
            'department_scoped' => 1,
            'condition_expr' => json_encode(['role_in' => ['Manager']]),
            'priority' => 1,
        ]);
        DB::connection('sqlsrv_menam')->table('department_roles')->insert([
            'id' => 3,
            'department_id' => 71,
            'name' => 'Manager',
            'level_no' => 3,
            'is_active' => 1,
        ]);
        DB::connection('sqlsrv_menam')->table('department_role_users')->insert([
            'department_role_id' => 3,
            'user_id' => 702,
            'is_primary' => 1,
            'start_date' => now()->subDay()->toDateString(),
        ]);

        $view = (new PoApproverMasterController())->index(Request::create('/po/approver-master', 'GET'));
        $rows = $view->getData()['baseRows']->keyBy('department_code');

        $this->assertSame([701], $rows['FIX']->approvers->pluck('id')->all());
        $this->assertSame('กำหนดเฉพาะ', $rows['FIX']->source);
        $this->assertSame([702], $rows['DYN']->approvers->pluck('id')->all());
        $this->assertSame('ตามตำแหน่ง/ลำดับชั้น', $rows['DYN']->source);
    }
}
