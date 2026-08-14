<?php

namespace Tests\Unit;

use App\Http\Controllers\Po\PoApprovalController;
use App\Models\Po\PoAttached;
use App\Models\Po\PoHeader;
use App\Services\Po\PoAttachmentService;
use App\Services\Po\PoErpService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class PoClosedNotificationRecipientTest extends TestCase
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
    }

    public function test_closed_mail_includes_active_purchase_step_two_approver_without_department(): void
    {
        DB::connection('sqlsrv_menam')->table('users')->insert([
            ['id' => 10, 'name' => 'Purchase Approver', 'email' => 'purchase@example.com', 'is_active' => 1, 'department_id' => null],
            ['id' => 11, 'name' => 'Inactive Approver', 'email' => 'inactive@example.com', 'is_active' => 0, 'department_id' => null],
        ]);
        DB::connection('sqlsrv_menam')->table('wf_form_authorizes')->insert([
            ['wf_form_id' => 126, 'step_no' => 2, 'approver_user_id' => 10, 'status' => 'APPROVED'],
            ['wf_form_id' => 126, 'step_no' => 2, 'approver_user_id' => 11, 'status' => 'APPROVED'],
        ]);

        $po = new PoHeader();
        $po->workflow_id = 126;

        $controller = new PoApprovalController(
            new PoErpService(),
            new PoAttachmentService(new PoAttached()),
        );
        $method = new ReflectionMethod($controller, 'purchaseApproverRecipients');
        $method->setAccessible(true);

        $recipients = $method->invoke($controller, $po);

        $this->assertSame(['purchase@example.com'], $recipients->pluck('email')->all());
    }
}
