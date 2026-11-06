<?php

namespace Tests\Unit;

use App\Mail\PoStep3PendingDigestMail;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SendPoStep3PendingDigestTest extends TestCase
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
        config()->set('po.step3_digest_enabled', true);
        DB::purge('sqlsrv_menam');

        Schema::connection('sqlsrv_menam')->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::connection('sqlsrv_menam')->create('wf_forms', function (Blueprint $table) {
            $table->id();
            $table->string('app_code');
            $table->unsignedInteger('current_step_no');
        });
        Schema::connection('sqlsrv_menam')->create('wf_form_authorizes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wf_form_id');
            $table->unsignedInteger('step_no');
            $table->unsignedBigInteger('approver_user_id');
            $table->string('status');
        });
        Schema::connection('sqlsrv_menam')->create('workflows', function (Blueprint $table) {
            $table->id();
            $table->string('code');
        });
        Schema::connection('sqlsrv_menam')->create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workflow_id');
            $table->unsignedInteger('step_no');
            $table->string('name');
            $table->boolean('is_active')->default(true);
        });
        Schema::connection('sqlsrv_menam')->create('po_headers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workflow_id');
            $table->string('site')->nullable();
            $table->string('ordnumber');
            $table->date('transdate')->nullable();
            $table->string('vendor_name')->nullable();
            $table->string('requester_name')->nullable();
            $table->string('f1')->nullable();
            $table->decimal('netamount', 12, 2)->nullable();
            $table->string('curr')->nullable();
        });
        DB::connection('sqlsrv_menam')->table('workflows')->insert([
            'id' => 1, 'code' => 'po',
        ]);
        DB::connection('sqlsrv_menam')->table('workflow_steps')->insert([
            'id' => 3,
            'workflow_id' => 1,
            'step_no' => 3,
            'name' => 'Department Manager Approval',
            'is_active' => 1,
        ]);
    }

    public function test_it_sends_one_table_digest_per_step_three_approver_without_cc(): void
    {
        Mail::fake();

        DB::connection('sqlsrv_menam')->table('users')->insert([
            ['id' => 10, 'name' => 'Manager One', 'email' => 'manager1@example.com', 'is_active' => 1],
            ['id' => 20, 'name' => 'Manager Two', 'email' => 'manager2@example.com', 'is_active' => 1],
            ['id' => 30, 'name' => 'Inactive', 'email' => 'inactive@example.com', 'is_active' => 0],
        ]);
        DB::connection('sqlsrv_menam')->table('wf_forms')->insert([
            ['id' => 101, 'app_code' => 'po', 'current_step_no' => 3],
            ['id' => 102, 'app_code' => 'PO', 'current_step_no' => 3],
            ['id' => 103, 'app_code' => 'po', 'current_step_no' => 2],
            ['id' => 104, 'app_code' => 'po', 'current_step_no' => 3],
        ]);
        DB::connection('sqlsrv_menam')->table('wf_form_authorizes')->insert([
            ['wf_form_id' => 101, 'step_no' => 3, 'approver_user_id' => 10, 'status' => 'PENDING'],
            ['wf_form_id' => 102, 'step_no' => 3, 'approver_user_id' => 10, 'status' => 'PENDING'],
            ['wf_form_id' => 101, 'step_no' => 3, 'approver_user_id' => 20, 'status' => 'PENDING'],
            ['wf_form_id' => 102, 'step_no' => 3, 'approver_user_id' => 30, 'status' => 'PENDING'],
            ['wf_form_id' => 103, 'step_no' => 3, 'approver_user_id' => 10, 'status' => 'PENDING'],
            ['wf_form_id' => 104, 'step_no' => 3, 'approver_user_id' => 10, 'status' => 'APPROVED'],
        ]);
        DB::connection('sqlsrv_menam')->table('po_headers')->insert([
            $this->po(1, 101, 'PO-001'),
            $this->po(2, 102, 'PO-002'),
            $this->po(3, 103, 'PO-STEP2'),
            $this->po(4, 104, 'PO-APPROVED'),
        ]);

        $this->assertSame(0, Artisan::call('po:send-step3-pending-digest'));

        Mail::assertSent(PoStep3PendingDigestMail::class, 2);
        Mail::assertSent(PoStep3PendingDigestMail::class, function (PoStep3PendingDigestMail $mail) {
            return $mail->hasTo('manager1@example.com')
                && ! $mail->hasCc('manager2@example.com')
                && ! $mail->hasBcc('manager2@example.com')
                && $mail->stepName === 'Department Manager Approval'
                && collect($mail->poItems)->pluck('ordnumber')->all() === ['PO-001', 'PO-002'];
        });
        Mail::assertSent(PoStep3PendingDigestMail::class, function (PoStep3PendingDigestMail $mail) {
            return $mail->hasTo('manager2@example.com')
                && count($mail->poItems) === 1
                && $mail->poItems[0]['ordnumber'] === 'PO-001';
        });
        Mail::assertNotSent(PoStep3PendingDigestMail::class, fn (PoStep3PendingDigestMail $mail) => $mail->hasTo('inactive@example.com')
        );
    }

    public function test_dry_run_does_not_send_mail(): void
    {
        Mail::fake();

        DB::connection('sqlsrv_menam')->table('users')->insert([
            'id' => 10, 'name' => 'Manager One', 'email' => 'manager1@example.com', 'is_active' => 1,
        ]);
        DB::connection('sqlsrv_menam')->table('wf_forms')->insert([
            'id' => 101, 'app_code' => 'po', 'current_step_no' => 3,
        ]);
        DB::connection('sqlsrv_menam')->table('wf_form_authorizes')->insert([
            'wf_form_id' => 101, 'step_no' => 3, 'approver_user_id' => 10, 'status' => 'PENDING',
        ]);
        DB::connection('sqlsrv_menam')->table('po_headers')->insert($this->po(1, 101, 'PO-001'));

        $this->assertSame(0, Artisan::call('po:send-step3-pending-digest', ['--dry-run' => true]));
        Mail::assertNothingSent();
    }

    public function test_it_sends_nothing_when_no_step_three_po_is_pending(): void
    {
        Mail::fake();

        $this->assertSame(0, Artisan::call('po:send-step3-pending-digest'));
        Mail::assertNothingSent();
    }

    public function test_disabled_digest_does_not_query_or_send_for_normal_run(): void
    {
        Mail::fake();
        config()->set('po.step3_digest_enabled', false);

        $this->assertSame(0, Artisan::call('po:send-step3-pending-digest'));
        Mail::assertNothingSent();
        $this->assertStringContainsString('disabled', Artisan::output());
    }

    public function test_to_option_redirects_each_approver_digest_to_test_recipient(): void
    {
        Mail::fake();
        config()->set('po.step3_digest_enabled', false);

        DB::connection('sqlsrv_menam')->table('users')->insert([
            ['id' => 10, 'name' => 'Manager One', 'email' => 'manager1@example.com', 'is_active' => 1],
            ['id' => 20, 'name' => 'Manager Two', 'email' => 'manager2@example.com', 'is_active' => 1],
        ]);
        DB::connection('sqlsrv_menam')->table('wf_forms')->insert([
            ['id' => 101, 'app_code' => 'po', 'current_step_no' => 3],
        ]);
        DB::connection('sqlsrv_menam')->table('wf_form_authorizes')->insert([
            ['wf_form_id' => 101, 'step_no' => 3, 'approver_user_id' => 10, 'status' => 'PENDING'],
            ['wf_form_id' => 101, 'step_no' => 3, 'approver_user_id' => 20, 'status' => 'PENDING'],
        ]);
        DB::connection('sqlsrv_menam')->table('po_headers')->insert($this->po(1, 101, 'PO-001'));

        $this->assertSame(0, Artisan::call('po:send-step3-pending-digest', [
            '--to' => 'reviewer@example.com',
        ]));

        Mail::assertSent(PoStep3PendingDigestMail::class, 2);
        Mail::assertSent(PoStep3PendingDigestMail::class, fn (PoStep3PendingDigestMail $mail) => $mail->hasTo('reviewer@example.com')
            && ! $mail->hasTo('manager1@example.com')
            && ! $mail->hasTo('manager2@example.com'));
    }

    private function po(int $id, int $workflowId, string $number): array
    {
        return [
            'id' => $id,
            'workflow_id' => $workflowId,
            'site' => 'wire',
            'ordnumber' => $number,
            'transdate' => '2026-11-01',
            'vendor_name' => 'Vendor',
            'requester_name' => 'Requester',
            'f1' => 'Production',
            'netamount' => 1000,
            'curr' => 'THB',
        ];
    }
}
