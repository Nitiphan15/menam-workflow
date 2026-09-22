<?php

namespace Tests\Unit;

use App\Models\FormDP\DeliveryConfirmation;
use App\Services\FormDP\DeliveryConfirmationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeliveryConfirmationResetTest extends TestCase
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

        Schema::connection('sqlsrv_menam')->create('dp_delivery_confirmation', function (Blueprint $table) {
            $table->id();
            $table->string('mfg_no', 80);
            $table->string('site', 10)->default('');
            $table->string('so_number', 80)->nullable();
            $table->string('confirmation_status', 20);
            $table->date('original_ship_date')->nullable();
            $table->date('new_delivery_date')->nullable();
            $table->string('remark', 500)->nullable();
            $table->unsignedBigInteger('confirmed_by_id')->nullable();
            $table->string('confirmed_by_login', 80)->nullable();
            $table->string('confirmed_by_name', 150)->nullable();
            $table->dateTime('confirmed_at');
            $table->timestamps();
        });
    }

    public function test_actual_postpone_resets_active_wire_and_plus_confirmations_append_only(): void
    {
        foreach ([
            ['mfg_no' => 'W100', 'site' => 'WIRE', 'confirmation_status' => DeliveryConfirmation::STATUS_CONFIRM],
            ['mfg_no' => 'P200', 'site' => 'PLUS', 'confirmation_status' => DeliveryConfirmation::STATUS_POSTPONE],
        ] as $row) {
            DeliveryConfirmation::create(array_merge($row, [
                'so_number' => 'SO-1',
                'original_ship_date' => '2026-08-06',
                'confirmed_at' => now()->subMinute(),
            ]));
        }

        $count = app(DeliveryConfirmationService::class)->resetAfterPlanPostpone(
            'W100, +P200',
            'SO-1',
            '2026-08-06',
            '2026-08-10',
            'ลูกค้าขอเลื่อน'
        );

        $this->assertSame(2, $count);
        $this->assertSame(4, DeliveryConfirmation::count());

        $latest = app(DeliveryConfirmationService::class)->latestMap(['W100', 'P200'], ['WIRE', 'PLUS']);
        $this->assertSame(DeliveryConfirmation::STATUS_RECONFIRM, $latest['WIRE|W100']->confirmation_status);
        $this->assertSame(DeliveryConfirmation::STATUS_RECONFIRM, $latest['PLUS|P200']->confirmation_status);
        $this->assertStringStartsWith('2026-08-10', $latest['WIRE|W100']->new_delivery_date);
        $this->assertStringContainsString('ลูกค้าขอเลื่อน', $latest['WIRE|W100']->remark);
    }

    public function test_actual_postpone_does_not_create_reset_without_active_confirmation(): void
    {
        DeliveryConfirmation::create([
            'mfg_no' => 'W300',
            'site' => 'WIRE',
            'so_number' => 'SO-2',
            'confirmation_status' => DeliveryConfirmation::STATUS_CANCELLED,
            'confirmed_at' => now(),
        ]);

        $count = app(DeliveryConfirmationService::class)->resetAfterPlanPostpone(
            'W300, W999',
            'SO-2',
            '2026-08-06',
            '2026-08-10',
            'เลื่อนจริง'
        );

        $this->assertSame(0, $count);
        $this->assertSame(1, DeliveryConfirmation::count());
    }

    public function test_logistics_postpone_can_reset_without_a_new_delivery_date(): void
    {
        DeliveryConfirmation::create([
            'mfg_no' => 'W400',
            'site' => 'WIRE',
            'so_number' => 'SO-3',
            'confirmation_status' => DeliveryConfirmation::STATUS_CONFIRM,
            'original_ship_date' => '2026-09-22',
            'confirmed_at' => now(),
        ]);

        $count = app(DeliveryConfirmationService::class)->resetAfterPlanPostpone(
            'W400',
            'SO-3',
            '2026-09-22',
            null,
            'Logistics เลื่อนแผน'
        );

        $this->assertSame(1, $count);
        $latest = app(DeliveryConfirmationService::class)->latestMap(['W400'], ['WIRE']);
        $this->assertSame(DeliveryConfirmation::STATUS_RECONFIRM, $latest['WIRE|W400']->confirmation_status);
        $this->assertNull($latest['WIRE|W400']->new_delivery_date);
    }

    public function test_all_plan_change_entry_points_reset_tracking_confirmation(): void
    {
        $root = dirname(__DIR__, 2);
        $salesController = file_get_contents(
            $root . '/app/Http/Controllers/FormDP/DeliveryPlanController.php'
        );
        $inquiryController = file_get_contents(
            $root . '/app/Http/Controllers/FormDP/DeliveryPlanInquiryController.php'
        );
        $trackingView = file_get_contents(
            $root . '/resources/views/formdp/production-status/index.blade.php'
        );

        $this->assertStringContainsString(
            'if ($originalShipDate !== $newShipDate)',
            $salesController
        );
        $this->assertStringContainsString(
            "if (\$dispatchType === 'POSTPONED')",
            $inquiryController
        );
        $this->assertGreaterThanOrEqual(
            3,
            substr_count($salesController . $inquiryController, 'resetAfterPlanPostpone(')
        );
        $this->assertStringContainsString(
            '<option value="" {{ !$confStatus ? \'selected\' : \'\' }}>รอจัดแผน</option>',
            $trackingView
        );
    }
}
