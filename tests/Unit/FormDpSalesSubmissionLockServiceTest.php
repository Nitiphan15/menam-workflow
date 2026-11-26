<?php

namespace Tests\Unit;

use App\Services\FormDP\DeliveryPlanSubmissionLockService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FormDpSalesSubmissionLockServiceTest extends TestCase
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
        DB::purge('sqlsrv_menam');

        Schema::connection('sqlsrv_menam')->create('delivery_plan_mail_logs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('ship_posted_at');
            $table->string('mail_type', 30);
            $table->dateTime('sent_at');
        });
        Schema::connection('sqlsrv_menam')->create('delivery_plan_sales_submission_unlocks', function (Blueprint $table) {
            $table->id();
            $table->date('ship_posted_at');
            $table->unsignedBigInteger('mail_log_id');
            $table->dateTime('unlocked_at');
            $table->unsignedBigInteger('unlocked_by')->nullable();
            $table->string('unlocked_by_name', 255)->nullable();
            $table->string('remark', 500)->nullable();
            $table->timestamps();
        });
        Schema::connection('sqlsrv_menam')->create('delivery_plan_sales_submission_controls', function (Blueprint $table) {
            $table->id();
            $table->date('ship_posted_at');
            $table->string('action', 10);
            $table->unsignedBigInteger('mail_log_id')->nullable();
            $table->dateTime('controlled_at');
            $table->unsignedBigInteger('controlled_by')->nullable();
            $table->string('controlled_by_name', 255)->nullable();
            $table->string('remark', 500)->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::purge('sqlsrv_menam');
        parent::tearDown();
    }

    public function test_assign_mail_locks_unlock_opens_and_next_mail_locks_again(): void
    {
        $service = app(DeliveryPlanSubmissionLockService::class);
        $shipDate = '2026-11-20';

        $this->assertFalse($service->isLocked($shipDate));

        $firstMailId = DB::connection('sqlsrv_menam')->table('delivery_plan_mail_logs')->insertGetId([
            'ship_posted_at' => $shipDate.' 00:00:00',
            'mail_type' => 'ASSIGN',
            'sent_at' => '2026-11-13 09:00:00',
        ]);

        $this->assertTrue($service->isLocked($shipDate));
        $this->assertTrue($service->unlock($shipDate, 101, 'Logistics Test', 'dry run'));
        $this->assertFalse($service->isLocked($shipDate));
        $this->assertFalse($service->unlock($shipDate, 101, 'Logistics Test'));

        $unlock = DB::connection('sqlsrv_menam')->table('delivery_plan_sales_submission_controls')->sole();
        $this->assertSame($firstMailId, (int) $unlock->mail_log_id);
        $this->assertSame('UNLOCK', $unlock->action);
        $this->assertSame(101, (int) $unlock->controlled_by);
        $this->assertSame('dry run', $unlock->remark);

        DB::connection('sqlsrv_menam')->table('delivery_plan_mail_logs')->insert([
            'ship_posted_at' => $shipDate.' 00:00:00',
            'mail_type' => 'ASSIGN',
            'sent_at' => '2026-11-13 10:00:00',
        ]);

        $this->assertTrue($service->isLocked($shipDate));
    }

    public function test_logistics_can_lock_and_unlock_without_sending_mail(): void
    {
        $service = app(DeliveryPlanSubmissionLockService::class);
        $shipDate = now()->addDay()->toDateString();

        $this->assertFalse($service->isLocked($shipDate));
        $this->assertTrue($service->lock($shipDate, 202, 'Logistics Test', 'manual relock'));
        $this->assertTrue($service->isLocked($shipDate));
        $this->assertContains($shipDate, $service->lockedDates());
        $this->assertFalse($service->lock($shipDate, 202, 'Logistics Test'));

        $this->assertTrue($service->unlock($shipDate, 202, 'Logistics Test', 'sales may edit'));
        $this->assertFalse($service->isLocked($shipDate));
        $this->assertNotContains($shipDate, $service->lockedDates());

        $actions = DB::connection('sqlsrv_menam')
            ->table('delivery_plan_sales_submission_controls')
            ->orderBy('id')
            ->pluck('action')
            ->all();
        $this->assertSame(['LOCK', 'UNLOCK'], $actions);
    }

    public function test_locked_dates_summary_excludes_past_dates_but_direct_status_still_works(): void
    {
        $service = app(DeliveryPlanSubmissionLockService::class);
        $pastDate = '2026-11-01';

        $this->assertTrue($service->lock($pastDate, 202, 'Logistics Test'));
        $this->assertTrue($service->isLocked($pastDate));
        $this->assertNotContains($pastDate, $service->lockedDates('2026-11-13'));
    }

    public function test_plan_mail_does_not_lock_sales_submission(): void
    {
        DB::connection('sqlsrv_menam')->table('delivery_plan_mail_logs')->insert([
            'ship_posted_at' => '2026-11-21 00:00:00',
            'mail_type' => 'PLAN',
            'sent_at' => '2026-11-13 09:00:00',
        ]);

        $this->assertFalse(app(DeliveryPlanSubmissionLockService::class)->isLocked('2026-11-21'));
    }
}
