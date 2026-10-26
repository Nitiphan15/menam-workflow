<?php

namespace Tests\Unit;

use App\Http\Controllers\Po\PoApprovalController;
use App\Mail\PoClosedNotificationMail;
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

class PoPurchaseDepartmentMailRecipientTest extends TestCase
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
            $table->string('code')->nullable();
            $table->string('name')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::connection('sqlsrv_menam')->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('department_id')->nullable();
        });
        Schema::connection('sqlsrv_menam')->create('wf_form_authorizes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wf_form_id');
            $table->unsignedInteger('step_no');
            $table->unsignedBigInteger('approver_user_id');
            $table->string('status')->nullable();
        });
        Schema::connection('sqlsrv_menam')->create('wf_forms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_by_user_id')->nullable();
        });
        Schema::connection('sqlsrv_menam')->create('wf_action_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wf_form_id');
            $table->string('action_type');
            $table->unsignedBigInteger('actor_user_id');
        });
    }

    public function test_external_department_closed_mail_includes_entire_purchase_department_but_not_store(): void
    {
        DB::connection('sqlsrv_menam')->table('departments')->insert([
            ['id' => 20, 'code' => 'PS', 'name' => 'Purchase and Store', 'parent_id' => null, 'is_active' => 1],
            ['id' => 21, 'code' => 'P', 'name' => 'Purchase', 'parent_id' => 20, 'is_active' => 1],
            ['id' => 22, 'code' => 'S', 'name' => 'Store', 'parent_id' => 20, 'is_active' => 1],
        ]);
        DB::connection('sqlsrv_menam')->table('users')->insert([
            ['id' => 10, 'name' => 'PS Assistant', 'email' => 'assistant@example.com', 'is_active' => 1, 'department_id' => 20],
            ['id' => 11, 'name' => 'PS Manager', 'email' => 'manager@example.com', 'is_active' => 1, 'department_id' => 20],
            ['id' => 12, 'name' => 'Purchase One', 'email' => 'purchase1@example.com', 'is_active' => 1, 'department_id' => 21],
            ['id' => 13, 'name' => 'Purchase Two', 'email' => 'purchase2@example.com', 'is_active' => 1, 'department_id' => 21],
            ['id' => 14, 'name' => 'Store One', 'email' => 'store@example.com', 'is_active' => 1, 'department_id' => 22],
            ['id' => 15, 'name' => 'Inactive Purchase', 'email' => 'inactive@example.com', 'is_active' => 0, 'department_id' => 21],
        ]);
        DB::connection('sqlsrv_menam')->table('wf_form_authorizes')->insert([
            ['wf_form_id' => 126, 'step_no' => 2, 'approver_user_id' => 10, 'status' => 'APPROVED'],
        ]);

        $recipients = $this->invokePurchaseDepartmentRecipients(126);

        $this->assertSame([
            'assistant@example.com',
            'manager@example.com',
            'purchase1@example.com',
            'purchase2@example.com',
        ], $recipients->pluck('email')->sort()->values()->all());
        $this->assertNotContains('store@example.com', $recipients->pluck('email')->all());
        $this->assertNotContains('inactive@example.com', $recipients->pluck('email')->all());
    }

    public function test_store_closed_mail_remains_scoped_to_store_department(): void
    {
        DB::connection('sqlsrv_menam')->table('departments')->insert([
            ['id' => 20, 'code' => 'PS', 'name' => 'Purchase and Store', 'parent_id' => null, 'is_active' => 1],
            ['id' => 21, 'code' => 'P', 'name' => 'Purchase', 'parent_id' => 20, 'is_active' => 1],
            ['id' => 22, 'code' => 'S', 'name' => 'Store', 'parent_id' => 20, 'is_active' => 1],
        ]);
        DB::connection('sqlsrv_menam')->table('users')->insert([
            ['id' => 12, 'name' => 'Purchase One', 'email' => 'purchase@example.com', 'is_active' => 1, 'department_id' => 21],
            ['id' => 14, 'name' => 'Store One', 'email' => 'store1@example.com', 'is_active' => 1, 'department_id' => 22],
            ['id' => 16, 'name' => 'Store Two', 'email' => 'store2@example.com', 'is_active' => 1, 'department_id' => 22],
        ]);
        DB::connection('sqlsrv_menam')->table('wf_form_authorizes')->insert([
            ['wf_form_id' => 127, 'step_no' => 2, 'approver_user_id' => 14, 'status' => 'APPROVED'],
        ]);

        $recipients = $this->invokePurchaseDepartmentRecipients(127);

        $this->assertSame(
            ['store1@example.com', 'store2@example.com'],
            $recipients->pluck('email')->sort()->values()->all()
        );
        $this->assertNotContains('purchase@example.com', $recipients->pluck('email')->all());
    }

    public function test_closed_external_po_sends_individual_notifications_and_one_purchase_group_mail(): void
    {
        Mail::fake();

        DB::connection('sqlsrv_menam')->table('departments')->insert([
            ['id' => 20, 'code' => 'PS', 'name' => 'Purchase and Store', 'parent_id' => null, 'is_active' => 1],
            ['id' => 21, 'code' => 'P', 'name' => 'Purchase', 'parent_id' => 20, 'is_active' => 1],
            ['id' => 30, 'code' => 'ENG', 'name' => 'Engineering', 'parent_id' => null, 'is_active' => 1],
        ]);
        DB::connection('sqlsrv_menam')->table('users')->insert([
            ['id' => 10, 'name' => 'Purchase Manager', 'email' => 'purchase.manager@example.com', 'is_active' => 1, 'department_id' => 20],
            ['id' => 11, 'name' => 'Purchase Submitter', 'email' => 'purchase.submit@example.com', 'is_active' => 1, 'department_id' => 21],
            ['id' => 12, 'name' => 'Purchase One', 'email' => 'purchase1@example.com', 'is_active' => 1, 'department_id' => 21],
            ['id' => 13, 'name' => 'Purchase Two', 'email' => 'purchase2@example.com', 'is_active' => 1, 'department_id' => 21],
            ['id' => 14, 'name' => 'Department Manager', 'email' => 'department.manager@example.com', 'is_active' => 1, 'department_id' => 30],
        ]);
        DB::connection('sqlsrv_menam')->table('wf_forms')->insert([
            'id' => 128,
            'request_by_user_id' => 11,
        ]);
        DB::connection('sqlsrv_menam')->table('wf_form_authorizes')->insert([
            ['wf_form_id' => 128, 'step_no' => 2, 'approver_user_id' => 10, 'status' => 'APPROVED'],
            ['wf_form_id' => 128, 'step_no' => 3, 'approver_user_id' => 14, 'status' => 'APPROVED'],
        ]);

        $po = new PoHeader();
        $po->id = 500;
        $po->workflow_id = 128;
        $po->ordnumber = 'PO-TEST-500';
        $po->site = 'MENAM';
        $po->f1 = 'Engineering';
        $po->vendor_name = 'Test Vendor';

        $controller = $this->makeController();
        $method = new ReflectionMethod($controller, 'notifyPurchaseDepartmentOnClosed');
        $method->setAccessible(true);
        $method->invoke($controller, $po);

        Mail::assertSent(PoClosedNotificationMail::class, 4);

        foreach ([
            'department.manager@example.com',
            'purchase.manager@example.com',
            'purchase.submit@example.com',
        ] as $email) {
            Mail::assertSent(PoClosedNotificationMail::class, fn ($mail) => $mail->hasTo($email));
        }

        Mail::assertSent(PoClosedNotificationMail::class, function ($mail) {
            return $mail->recipientName === 'ทีม Purchase'
                && $mail->hasTo('purchase1@example.com')
                && $mail->hasTo('purchase2@example.com')
                && !empty($mail->poItem['is_group_recipient']);
        });
    }

    private function invokePurchaseDepartmentRecipients(int $workflowId)
    {
        $po = new PoHeader();
        $po->workflow_id = $workflowId;

        $controller = $this->makeController();
        $method = new ReflectionMethod($controller, 'purchaseDepartmentRecipients');
        $method->setAccessible(true);

        return $method->invoke($controller, $po);
    }

    private function makeController(): PoApprovalController
    {
        return new PoApprovalController(
            new PoErpService(),
            new PoAttachmentService(new PoAttached()),
        );
    }
}
