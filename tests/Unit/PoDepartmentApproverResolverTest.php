<?php

namespace Tests\Unit;

use App\Services\ApproverResolver;
use App\Services\Po\PoErpService;
use Illuminate\Database\Schema\Blueprint;
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
}
