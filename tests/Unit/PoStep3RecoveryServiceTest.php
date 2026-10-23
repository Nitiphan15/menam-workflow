<?php

namespace Tests\Unit;

use App\Mail\PoNeedsApprovalMail;
use App\Services\Po\PoStep3RecoveryService;
use App\Services\WorkflowEngine;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PoStep3RecoveryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.sqlsrv_menam', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        config()->set('database.workflow_connection', 'sqlsrv_menam');
        DB::purge('sqlsrv_menam');

        $schema = Schema::connection('sqlsrv_menam');
        $schema->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->string('name');
            $table->string('email')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->boolean('is_active')->default(true);
        });
        $schema->create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->boolean('is_active')->default(true);
        });
        $schema->create('department_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('department_id');
            $table->string('name');
            $table->integer('level_no');
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
        $schema->create('workflows', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->boolean('is_active');
        });
        $schema->create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workflow_id');
            $table->integer('step_no');
            $table->string('key');
            $table->boolean('is_active');
        });
        $schema->create('workflow_step_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workflow_step_id');
            $table->string('source_type');
            $table->unsignedBigInteger('source_ref_id')->nullable();
            $table->boolean('department_scoped');
            $table->text('condition_expr')->nullable();
            $table->integer('priority')->default(1);
        });
        $schema->create('wf_forms', function (Blueprint $table) {
            $table->id();
            $table->string('app_code');
            $table->unsignedBigInteger('ref_id');
            $table->string('form_no');
            $table->string('form_status');
            $table->integer('current_step_no');
            $table->unsignedBigInteger('request_by_user_id');
            $table->dateTime('last_action_dt')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        $schema->create('po_headers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workflow_id');
            $table->string('ordnumber');
            $table->string('site')->default('wire');
            $table->string('f1')->nullable();
            $table->string('vendor_name')->nullable();
            $table->string('status_code');
            $table->dateTime('updated_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
        });
        $schema->create('wf_form_authorizes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wf_form_id');
            $table->integer('step_no');
            $table->unsignedBigInteger('approver_user_id');
            $table->string('status');
            $table->dateTime('decided_at')->nullable();
            $table->text('comment')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        $schema->create('wf_action_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wf_form_id');
            $table->integer('step_no');
            $table->unsignedBigInteger('actor_user_id');
            $table->string('action_type');
            $table->text('comment')->nullable();
            $table->dateTime('created_at');
        });

        DB::connection('sqlsrv_menam')->table('departments')->insert([
            'id' => 39, 'code' => 'PN', 'name' => 'วางแผน', 'is_active' => 1,
        ]);
        DB::connection('sqlsrv_menam')->table('users')->insert([
            ['id' => 2, 'username' => 'admin', 'name' => 'Admin User', 'email' => 'admin@example.test', 'department_id' => 39, 'is_active' => 1],
            ['id' => 15, 'username' => 'assadaporn_m', 'name' => 'Assadaporn', 'email' => 'assadaporn@example.test', 'department_id' => 39, 'is_active' => 1],
            ['id' => 16, 'username' => 'override_user', 'name' => 'Override User', 'email' => 'override@example.test', 'department_id' => 39, 'is_active' => 1],
            ['id' => 69, 'username' => 'submitter', 'name' => 'Submitter', 'email' => 'submitter@example.test', 'department_id' => 39, 'is_active' => 1],
        ]);
        DB::connection('sqlsrv_menam')->table('department_roles')->insert([
            'id' => 12, 'department_id' => 39, 'name' => 'Assist Manager', 'level_no' => 3, 'is_active' => 1,
        ]);
        DB::connection('sqlsrv_menam')->table('department_role_users')->insert([
            'id' => 12, 'department_role_id' => 12, 'user_id' => 15, 'is_primary' => 1, 'start_date' => '2025-09-03', 'end_date' => null,
        ]);
        DB::connection('sqlsrv_menam')->table('workflows')->insert(['id' => 4, 'code' => 'po', 'is_active' => 1]);
        DB::connection('sqlsrv_menam')->table('workflow_steps')->insert([
            'id' => 17, 'workflow_id' => 4, 'step_no' => 3, 'key' => 'dept_manager_approve', 'is_active' => 1,
        ]);
        DB::connection('sqlsrv_menam')->table('workflow_step_rules')->insert([
            'id' => 16,
            'workflow_step_id' => 17,
            'source_type' => 'ROLE',
            'source_ref_id' => null,
            'department_scoped' => 1,
            'condition_expr' => json_encode(['role_in' => ['Assist Manager', 'Manager']]),
            'priority' => 1,
        ]);
        DB::connection('sqlsrv_menam')->table('wf_forms')->insert([
            'id' => 4642,
            'app_code' => 'po',
            'ref_id' => 931,
            'form_no' => 'PO2026100071',
            'form_status' => 'CLOESD',
            'current_step_no' => 999,
            'request_by_user_id' => 69,
        ]);
        DB::connection('sqlsrv_menam')->table('po_headers')->insert([
            'id' => 931,
            'workflow_id' => 4642,
            'ordnumber' => 'PO2026100071',
            'site' => 'wire',
            'f1' => 'วางแผน-k.ดารณี',
            'vendor_name' => 'Vendor',
            'status_code' => 'CLOSED',
        ]);
        DB::connection('sqlsrv_menam')->table('wf_action_histories')->insert([
            'id' => 17500,
            'wf_form_id' => 4642,
            'step_no' => 3,
            'actor_user_id' => 70,
            'action_type' => 'SKIP',
            'comment' => 'No approver resolved',
            'created_at' => '2026-10-08 09:35:26',
        ]);
    }

    public function test_preview_resolves_expected_step_three_approver(): void
    {
        $result = app(PoStep3RecoveryService::class)->inspect('PO2026100071', 15);

        $this->assertSame('skipped', $result['state']);
        $this->assertTrue($result['is_resolver_match']);
        $this->assertSame([15], $result['resolver_user_ids']->all());
    }

    public function test_admin_can_override_recover_and_send_with_audit(): void
    {
        Mail::fake();

        $result = app(PoStep3RecoveryService::class)->recoverAndSend(
            'PO2026100071',
            16,
            2,
            'ผู้จัดการมอบหมายให้ผู้ใช้สำรองอนุมัติ',
        );

        $this->assertSame('sent', $result['delivery']);
        $this->assertDatabaseHas('wf_forms', [
            'id' => 4642, 'form_status' => 'REQUESTED', 'current_step_no' => 3,
        ], 'sqlsrv_menam');
        $this->assertDatabaseHas('po_headers', [
            'id' => 931, 'status_code' => 'DEPT_MANAGER_APPROVAL', 'updated_by' => 2,
        ], 'sqlsrv_menam');
        $this->assertDatabaseHas('wf_form_authorizes', [
            'wf_form_id' => 4642, 'step_no' => 3, 'approver_user_id' => 16, 'status' => 'PENDING',
        ], 'sqlsrv_menam');

        $audit = DB::connection('sqlsrv_menam')->table('wf_action_histories')
            ->where('action_type', 'ADM_FIX')->first();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('operator_user_id=2', $audit->comment);
        $this->assertStringContainsString('selected_user_id=16', $audit->comment);
        $this->assertStringContainsString('resolver_user_ids=15', $audit->comment);
        $this->assertStringContainsString('resolver_match=0', $audit->comment);
        $this->assertStringContainsString('ผู้จัดการมอบหมาย', $audit->comment);

        $this->assertDatabaseHas('wf_action_histories', [
            'wf_form_id' => 4642, 'step_no' => 3, 'actor_user_id' => 2, 'action_type' => 'NOTIFY',
        ], 'sqlsrv_menam');
        Mail::assertSent(PoNeedsApprovalMail::class, fn ($mail) => $mail->hasTo('override@example.test'));
    }

    public function test_legacy_sql_repair_can_send_without_recreating_step_three_authorization(): void
    {
        Mail::fake();

        DB::connection('sqlsrv_menam')->table('wf_forms')->where('id', 4642)->update([
            'form_status' => 'REQUESTED',
            'current_step_no' => 3,
        ]);
        DB::connection('sqlsrv_menam')->table('po_headers')->where('id', 931)->update([
            'status_code' => 'DEPT_MANAGER_APPROVAL',
            'updated_by' => 2,
        ]);
        DB::connection('sqlsrv_menam')->table('wf_form_authorizes')->insert([
            'id' => 27992,
            'wf_form_id' => 4642,
            'step_no' => 3,
            'approver_user_id' => 15,
            'status' => 'PENDING',
            'created_at' => '2026-10-22 17:00:41',
            'updated_at' => '2026-10-22 17:00:41',
        ]);
        DB::connection('sqlsrv_menam')->table('wf_action_histories')->insert([
            'id' => 18206,
            'wf_form_id' => 4642,
            'step_no' => 3,
            'actor_user_id' => 2,
            'action_type' => 'ADM_FIX',
            'comment' => 'Administrative repair: restored skipped Step 3 for PO2026100071; no approver was resolved during the original transition',
            'created_at' => '2026-10-22 17:00:41',
        ]);

        $result = app(PoStep3RecoveryService::class)->recoverAndSend(
            'PO2026100071',
            15,
            2,
            'ส่งเมลต่อจากรายการที่ย้อนด้วย SQL repair',
        );

        $this->assertSame('sent', $result['delivery']);
        $this->assertSame('recovered_pending', $result['state']);
        $this->assertSame(1, DB::connection('sqlsrv_menam')->table('wf_form_authorizes')->where('wf_form_id', 4642)->where('step_no', 3)->count());
        $this->assertSame(1, DB::connection('sqlsrv_menam')->table('wf_action_histories')->where('action_type', 'ADM_FIX')->count());
        $this->assertDatabaseHas('wf_action_histories', [
            'wf_form_id' => 4642, 'step_no' => 3, 'actor_user_id' => 2, 'action_type' => 'NOTIFY',
        ], 'sqlsrv_menam');
        Mail::assertSent(PoNeedsApprovalMail::class, fn ($mail) => $mail->hasTo('assadaporn@example.test'));
    }

    public function test_po_step_three_without_an_approver_fails_closed_instead_of_skipping(): void
    {
        DB::connection('sqlsrv_menam')->table('users')->where('id', 15)->update(['is_active' => 0]);

        $method = new \ReflectionMethod(WorkflowEngine::class, 'moveToStep');
        $method->setAccessible(true);

        try {
            $method->invoke(null, 4642, 3, 70, [
                'department_id' => 39,
                'document_department_id' => 39,
                'document_department_name' => 'วางแผน-k.ดารณี',
                'originator_id' => 69,
            ], [], 'po');
            $this->fail('Expected Step 3 resolution to be blocked.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $actual = $exception instanceof \ReflectionException ? $exception : ($exception->getPrevious() ?: $exception);
            $this->assertInstanceOf(HttpException::class, $actual);
            $this->assertSame(422, $actual->getStatusCode());
            $this->assertSame('No approver resolved for workflow step 3', $actual->getMessage());
        }

        $this->assertSame(1, DB::connection('sqlsrv_menam')->table('wf_action_histories')->count());
        $this->assertFalse(DB::connection('sqlsrv_menam')->table('wf_action_histories')->where('action_type', 'SKIP')->where('id', '!=', 17500)->exists());
    }
}
