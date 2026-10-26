<?php

namespace Tests\Unit;

use App\Mail\PoNeedsApprovalMail;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SendRetroactivePoApprovalTest extends TestCase
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
        config()->set('queue.po_mail_enabled', false);
        DB::purge('sqlsrv_menam');

        $schema = Schema::connection('sqlsrv_menam');
        $schema->create('wf_forms', function (Blueprint $table) {
            $table->id();
            $table->string('app_code');
            $table->string('form_status');
            $table->unsignedInteger('current_step_no');
        });
        $schema->create('po_headers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workflow_id');
            $table->string('site')->nullable();
            $table->string('ordnumber');
            $table->string('f1');
            $table->string('vendor_name')->nullable();
            $table->string('status_code');
        });
        $schema->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active');
        });
        $schema->create('wf_form_authorizes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wf_form_id');
            $table->unsignedInteger('step_no');
            $table->unsignedBigInteger('approver_user_id');
            $table->string('status');
        });
        $schema->create('wf_action_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wf_form_id');
            $table->unsignedInteger('step_no');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('action_type');
            $table->text('comment')->nullable();
            $table->dateTime('created_at');
        });

        DB::connection('sqlsrv_menam')->table('wf_forms')->insert([
            'id' => 4345,
            'app_code' => 'po',
            'form_status' => 'REQUESTED',
            'current_step_no' => 3,
        ]);
        DB::connection('sqlsrv_menam')->table('po_headers')->insert([
            'id' => 632,
            'workflow_id' => 4345,
            'site' => 'wire',
            'ordnumber' => 'PO2026090147',
            'f1' => 'Factory-k.Test',
            'vendor_name' => 'Vendor',
            'status_code' => 'DEPT_MANAGER_APPROVAL',
        ]);
        DB::connection('sqlsrv_menam')->table('users')->insert([
            'id' => 411,
            'username' => 'jittinan_k',
            'name' => 'Jittinan',
            'email' => 'jittinan@example.com',
            'is_active' => 1,
        ]);
        DB::connection('sqlsrv_menam')->table('wf_form_authorizes')->insert([
            'wf_form_id' => 4345,
            'step_no' => 3,
            'approver_user_id' => 411,
            'status' => 'PENDING',
        ]);

        Mail::fake();
    }

    public function test_send_dispatches_once_and_records_audit(): void
    {
        $this->artisan('po:send-retroactive-approval', [
            'ordnumber' => 'PO2026090147',
            '--send' => true,
        ])->assertExitCode(0);

        Mail::assertSent(PoNeedsApprovalMail::class, 1);
        $this->assertSame(1, DB::connection('sqlsrv_menam')
            ->table('wf_action_histories')
            ->where('action_type', 'NOTIFY')
            ->count());
        $this->assertSame(411, (int) DB::connection('sqlsrv_menam')
            ->table('wf_action_histories')
            ->where('action_type', 'NOTIFY')
            ->value('actor_user_id'));

        $this->artisan('po:send-retroactive-approval', [
            'ordnumber' => 'PO2026090147',
            '--send' => true,
        ])->assertExitCode(0);

        Mail::assertSent(PoNeedsApprovalMail::class, 1);
    }
}
