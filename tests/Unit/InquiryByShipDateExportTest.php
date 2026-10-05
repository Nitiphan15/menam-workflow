<?php

namespace Tests\Unit;

use App\Exports\FormOTD\InquiryByShipDateExport;
use App\Exports\FormOTD\InquiryShipDateSheet;
use App\Exports\FormOTD\PlannerConfirmedInquiryExport;
use App\Http\Controllers\FormDP\DeliveryPlanInquiryController;
use App\Models\FormDP\DeliveryConfirmation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InquiryByShipDateExportTest extends TestCase
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

    public function test_original_export_contains_only_date_sheets(): void
    {
        $filters = [
            'ship_from' => '2026-09-30',
            'ship_to' => '2026-09-30',
            'customer' => 'ACME',
        ];

        $query = \Mockery::mock();
        $query->shouldReceive('selectRaw')->andReturnSelf();
        $query->shouldReceive('whereNotNull')->andReturnSelf();
        $query->shouldReceive('groupByRaw')->andReturnSelf();
        $query->shouldReceive('orderByRaw')->andReturnSelf();
        $query->shouldReceive('pluck')->andReturn(collect(['2026-09-30']));

        $export = new class($filters, $query) extends InquiryByShipDateExport {
            public function __construct(array $filters, private $query)
            {
                parent::__construct($filters);
            }

            protected function baseQuery()
            {
                return $this->query;
            }
        };

        $sheets = $export->sheets();

        $this->assertCount(1, $sheets);
        $this->assertSame('2026-09-30', $sheets[0]->title());
        $headings = $sheets[0]->headings();
        $this->assertNotSame('Confirm By Planner', end($headings));
    }

    public function test_planner_confirmed_export_contains_only_confirm_sheet(): void
    {
        $sheets = (new PlannerConfirmedInquiryExport(['customer' => 'ACME']))->sheets();

        $this->assertCount(1, $sheets);
        $this->assertSame('Confirm By Planner', $sheets[0]->title());
        $headings = $sheets[0]->headings();
        $this->assertSame('Confirm By Planner', end($headings));
    }

    public function test_confirm_sheet_includes_confirm_postpone_and_pending_rows(): void
    {
        foreach ([
            ['mfg_no' => 'W100', 'site' => 'WIRE', 'confirmation_status' => DeliveryConfirmation::STATUS_CONFIRM],
            ['mfg_no' => 'W200', 'site' => 'WIRE', 'confirmation_status' => DeliveryConfirmation::STATUS_CONFIRM],
            ['mfg_no' => 'W200', 'site' => 'WIRE', 'confirmation_status' => DeliveryConfirmation::STATUS_CANCELLED],
            ['mfg_no' => 'W300', 'site' => 'WIRE', 'confirmation_status' => DeliveryConfirmation::STATUS_CONFIRM],
            ['mfg_no' => 'P300', 'site' => 'PLUS', 'confirmation_status' => DeliveryConfirmation::STATUS_POSTPONE],
        ] as $index => $data) {
            DeliveryConfirmation::create(array_merge($data, [
                'confirmed_at' => now()->addSeconds($index),
            ]));
        }

        $sheet = new InquiryShipDateSheet(
            InquiryShipDateSheet::CONFIRM_BY_PLANNER_SHEET,
            []
        );
        $method = new \ReflectionMethod($sheet, 'withPlannerConfirmationStatus');
        $method->setAccessible(true);

        $rows = collect([
            (object) ['mfg_no' => 'W100'],
            (object) ['mfg_no' => 'W200'],
            (object) ['mfg_no' => 'W300, +P300'],
            (object) ['mfg_no' => 'W400'],
        ]);

        $result = $method->invoke($sheet, $rows);

        $this->assertSame(
            ['Confirm Delivery', 'Pending', 'Request Postpone', 'Pending'],
            $result->pluck('planner_confirmation_label')->all()
        );
    }

    public function test_confirm_sheet_groups_by_ship_date_before_division(): void
    {
        $sheet = new InquiryShipDateSheet(
            InquiryShipDateSheet::CONFIRM_BY_PLANNER_SHEET,
            []
        );
        $method = new \ReflectionMethod($sheet, 'withShipDateDivisionGroupRows');
        $method->setAccessible(true);

        $rows = collect([
            (object) ['ship_posted_at' => '2026-10-06', 'delivery_type' => 'SO', 'sales_name' => 'D1', 'mfg_no' => 'LATE-D1'],
            (object) ['ship_posted_at' => '2026-10-05', 'delivery_type' => 'SO', 'sales_name' => 'D2', 'mfg_no' => 'EARLY-D2'],
            (object) ['ship_posted_at' => '2026-10-05', 'delivery_type' => 'SO', 'sales_name' => 'D1', 'mfg_no' => 'EARLY-D1'],
        ]);

        $result = $method->invoke($sheet, $rows);

        $this->assertSame([
            'วันที่แทงส่ง: 2026-10-05',
            'D1 - ดิลก + ขวัญเรือน (1 รายการ)',
            'EARLY-D1',
            'D2 - ปรียาพรรณ + นิตยา (1 รายการ)',
            'EARLY-D2',
            'วันที่แทงส่ง: 2026-10-06',
            'D1 - ดิลก + ขวัญเรือน (1 รายการ)',
            'LATE-D1',
        ], $result->map(fn($row) => $row->group_label ?? $row->mfg_no)->all());
    }

    public function test_planner_department_check_is_trimmed_and_case_insensitive(): void
    {
        $controller = new DeliveryPlanInquiryController();
        $method = new \ReflectionMethod($controller, 'isPlannerDepartment');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($controller, (object) ['department' => ' planner ']));
        $this->assertTrue($method->invoke($controller, (object) ['id' => 2, 'department' => 'Sales']));
        $this->assertFalse($method->invoke($controller, (object) ['department' => 'Sales']));
        $this->assertFalse($method->invoke($controller, null));
    }
}
