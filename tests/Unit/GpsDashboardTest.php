<?php

namespace Tests\Unit;

use App\Http\Controllers\FormDP\GpsDashboardController;
use App\Services\FormDP\DtcGpsService;
use App\Services\FormDP\GpsCustomerLocationService;
use App\Services\FormDP\GpsDailySummaryService;
use App\Services\FormDP\GpsTripQueueService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * GPS planner dashboard (late arrival / late return / trends).
 * sqlsrv_menam is an in-memory sqlite inside the test; DTC is Http::fake.
 */
class GpsDashboardTest extends TestCase
{
    private const DATE = '2026-11-19';
    private const CUSTOMER_A = [13.70, 100.90];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
        Http::preventStrayRequests();
        config()->set('dtc.late_return_time', '18:00');
        config()->set('dtc.late_arrival_grace_minutes', 0);
        config()->set('database.connections.sqlsrv_menam', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
        DB::purge('sqlsrv_menam');
        Cache::put('dtc_gps.schema.location_tables', false, now()->addHour());
        Cache::put('dtc_gps.schema.truck_gps_column', true, now()->addHour());
    }

    private function createTables(): void
    {
        $schema = Schema::connection('sqlsrv_menam');
        $schema->create('gps_trip_daily', function (Blueprint $t) {
            $t->id();
            $t->date('ship_date');
            $t->string('plate_key');
            $t->string('plate_no');
            $t->integer('truck_id')->nullable();
            $t->string('dtc_gps_id')->nullable();
            $t->string('driver_name')->nullable();
            $t->string('trip_nos')->nullable();
            $t->boolean('has_route')->default(false);
            $t->integer('point_count')->nullable();
            foreach (['first_point_at', 'last_point_at', 'left_company_at', 'returned_at'] as $column) {
                $t->dateTime($column)->nullable();
            }
            $t->boolean('late_return')->default(false);
            $t->decimal('km', 9, 1)->nullable();
            $t->integer('max_speed')->nullable();
            foreach (['stops_planned', 'stops_with_pin', 'stops_visited', 'stops_on_time', 'stops_late', 'stops_not_visited'] as $column) {
                $t->integer($column)->default(0);
            }
            $t->string('note')->nullable();
            $t->dateTime('computed_at')->nullable();
            $t->unique(['ship_date', 'plate_key']);
        });
        $schema->create('gps_stop_visit', function (Blueprint $t) {
            $t->id();
            $t->date('ship_date');
            $t->string('plate_key');
            $t->string('stop_key');
            $t->integer('stop_no');
            $t->string('company')->nullable();
            $t->integer('customer_id')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('window_time')->nullable();
            $t->string('status');
            $t->dateTime('arrived_at')->nullable();
            $t->dateTime('left_at')->nullable();
            $t->integer('dwell_minutes')->nullable();
            $t->integer('late_minutes')->nullable();
            $t->boolean('is_late')->default(false);
            $t->dateTime('computed_at')->nullable();
            $t->unique(['ship_date', 'plate_key', 'stop_key']);
        });
        Cache::put('dtc_gps.schema.dashboard_tables', true, now()->addHour());
    }

    /** Points every 2 min from 07:00; $plan = [[lat, lon, minutes], ...]. */
    private function route(array $plan, string $start = '07:00:00'): array
    {
        $time = new \DateTimeImmutable(self::DATE . ' ' . $start, new \DateTimeZone('Asia/Bangkok'));
        $points = [];
        foreach ($plan as [$lat, $lon, $minutes]) {
            for ($m = 0; $m < $minutes; $m += 2) {
                $points[] = ['time' => $time->format('Y-m-d H:i:s'), 'lat' => $lat, 'lon' => $lon, 'speed' => 0, 'distance' => null];
                $time = $time->modify('+2 minutes');
            }
        }

        return $points;
    }

    private function truck(array $deliveries, string $plate = '83-5505'): array
    {
        return ['plate_key' => str_replace('-', '', $plate), 'plate_no' => $plate, 'truck_id' => 12, 'driver_name' => 'D', 'trip_nos' => [1], 'gps_id' => 'G1', 'deliveries' => $deliveries];
    }

    private function delivery(string $name, int $customerId, ?string $window, ?array $pin): array
    {
        return ['customer' => $name, 'company' => 'MSW', 'customer_id' => $customerId, 'address' => '', 'window_time' => $window, 'items' => [],
            'location' => $pin ? ['lat' => $pin[0], 'lon' => $pin[1], 'radius_m' => 150, 'label' => null] : null];
    }

    private function fakeSummarizeFleet(): GpsDailySummaryService
    {
        config()->set('dtc.base_url', 'https://gps.example.test:8099/');
        config()->set('dtc.api_token', 'test-token');
        $company = GpsDailySummaryService::COMPANY;
        Http::fake([
            'gps.example.test:8099/getVehicleMaster' => Http::response(['error' => false, 'data' => [
                ['gps_id' => '010399900000001', 'vehicle_name' => '83-5505', 'license_plate' => '010399900000001'],
                ['gps_id' => '010399900000002', 'vehicle_name' => '84-0953', 'license_plate' => '010399900000002'],
            ]]),
            'gps.example.test:8099/getHistory' => Http::response(['error' => false, 'data' => array_map(
                fn ($p) => ['time' => $p['time'], 'lat' => $p['lat'], 'lon' => $p['lon'], 'gps_speed' => 0],
                $this->route([[$company[0], $company[1], 10], [13.7, 100.9, 30], [$company[0], $company[1], 10]])
            )]),
        ]);
        app(DtcGpsService::class)->getVehicleMasterCached();
        $this->partialMock(GpsTripQueueService::class, function ($mock) {
            $mock->shouldReceive('fleetForDate')->andReturn(collect([
                ['plate_key' => '835505', 'plate_no' => '83-5505', 'truck_id' => 12, 'dtc_gps_id' => '', 'driver_name' => 'A', 'trip_nos' => [1], 'deliveries' => [
                    ['customer' => 'X', 'company' => 'MSW', 'customer_id' => 5, 'address' => '', 'window_time' => '17:00', 'items' => []],
                ]],
                ['plate_key' => '840953', 'plate_no' => '84-0953', 'truck_id' => 13, 'dtc_gps_id' => '010399900000002', 'driver_name' => 'B', 'trip_nos' => [1], 'deliveries' => []],
                ['plate_key' => '712519', 'plate_no' => '71-2519', 'truck_id' => 1, 'dtc_gps_id' => '', 'driver_name' => 'C', 'trip_nos' => [1], 'deliveries' => []], // no GPS
                ['plate_key' => '700000', 'plate_no' => '70-0000', 'truck_id' => 2, 'dtc_gps_id' => '', 'driver_name' => '', 'trip_nos' => [], 'deliveries' => []], // no queue
            ]));
        });
        $this->partialMock(GpsCustomerLocationService::class, fn ($mock) => $mock->shouldReceive('locationsForDeliveries')->andReturn([]));

        return app(GpsDailySummaryService::class);
    }

    // ---------- rules ----------

    public function test_window_time_uses_only_the_time_of_day(): void
    {
        $queue = app(GpsTripQueueService::class);

        $this->assertSame('16:00', $queue->windowTime('2026-11-16 16:00:00.000'));
        $this->assertSame('09:30', $queue->windowTime('2026-10-01T09:30:00'));
        $this->assertNull($queue->windowTime(null));
        $this->assertNull($queue->windowTime(''));
    }

    public function test_late_arrival_and_late_return_rules(): void
    {
        $service = app(GpsDailySummaryService::class);

        $this->assertSame(30, $service->lateMinutes('2026-11-19 10:30:00', '10:00'));
        $this->assertSame(0, $service->lateMinutes('2026-11-19 09:59:00', '10:00'));
        $this->assertNull($service->lateMinutes('2026-11-19 10:30:00', null));
        config()->set('dtc.late_arrival_grace_minutes', 15);
        $this->assertSame(15, $service->lateMinutes('2026-11-19 10:30:00', '10:00'));

        $this->assertFalse($service->isLateReturn('2026-11-19 17:59:00'));
        $this->assertTrue($service->isLateReturn('2026-11-19 18:01:00'));
        $this->assertFalse($service->isLateReturn(null));
    }

    public function test_trip_metrics_find_leave_and_last_return_to_company(): void
    {
        $company = GpsDailySummaryService::COMPANY;
        $points = $this->route([[$company[0], $company[1], 10], [13.7, 100.9, 30], [$company[0], $company[1], 20], [13.8, 100.6, 20], [$company[0], $company[1], 10]]);

        $metrics = app(GpsDailySummaryService::class)->tripMetrics($points);

        $this->assertSame('2026-11-19 07:10:00', $metrics['left_company_at']);
        $this->assertSame('2026-11-19 08:20:00', $metrics['returned_at']); // last re-entry (first one was 07:40)
        $this->assertTrue($metrics['ends_inside']);
        $this->assertGreaterThan(10, $metrics['km']);
    }

    // ---------- storage ----------

    public function test_store_writes_trip_and_stops_and_is_idempotent(): void
    {
        $this->createTables();
        $company = GpsDailySummaryService::COMPANY;
        $service = app(GpsDailySummaryService::class);
        // leave 07:10, at customer A 10:30-11:00 (window 10:00 -> late 30), return 18:30 (late)
        $points = [
            ...$this->route([[$company[0], $company[1], 10], [13.6, 100.85, 200]]),
            ...$this->route([[self::CUSTOMER_A[0], self::CUSTOMER_A[1], 32]], '10:30:00'),
            ...$this->route([[$company[0], $company[1], 20]], '18:30:00'),
        ];
        $truck = $this->truck([
            $this->delivery('A', 1, '10:00', self::CUSTOMER_A),
            $this->delivery('B', 2, '17:00', null),
        ]);

        $service->store(self::DATE, $truck, $points, null);
        $service->store(self::DATE, $truck, $points, null); // re-run: same rows

        $db = DB::connection('sqlsrv_menam');
        $this->assertSame(1, $db->table('gps_trip_daily')->count());
        $this->assertSame(2, $db->table('gps_stop_visit')->count());
        $trip = $db->table('gps_trip_daily')->first();
        $this->assertSame(1, (int) $trip->late_return);
        $this->assertSame(1, (int) $trip->stops_late);
        $this->assertSame(1, (int) $trip->stops_with_pin);
        $this->assertSame(0, (int) $trip->stops_on_time);
        $stopA = $db->table('gps_stop_visit')->where('customer_id', 1)->first();
        $this->assertSame('visited', $stopA->status);
        $this->assertSame(30, (int) $stopA->late_minutes);
        $this->assertSame('no_location', $db->table('gps_stop_visit')->where('customer_id', 2)->value('status'));

        // Plan changed: B removed from the truck -> row kept but marked removed, never deleted.
        $service->store(self::DATE, $this->truck([$this->delivery('A', 1, '10:00', self::CUSTOMER_A)]), $points, null);
        $this->assertSame(2, $db->table('gps_stop_visit')->count());
        $this->assertSame('removed', $db->table('gps_stop_visit')->where('customer_id', 2)->value('status'));

        $board = $service->dayBoard(self::DATE);
        $this->assertSame(1, $board['summary']['stops_late']);
        $this->assertSame(0.0, $board['summary']['on_time_percent']);
        $this->assertCount(1, $board['trucks'][0]['stops']);           // removed stop hidden
        $this->assertSame('10:30', $board['trucks'][0]['stops'][0]['arrived_at']);
        $this->assertSame('18:30', $board['trucks'][0]['returned_at']);
    }

    public function test_truck_that_never_returns_on_a_past_day_is_a_late_return(): void
    {
        $this->createTables();
        $company = GpsDailySummaryService::COMPANY;
        $points = $this->route([[$company[0], $company[1], 10], [13.9, 100.95, 60]]);

        app(GpsDailySummaryService::class)->store(self::DATE, $this->truck([]), $points, null);

        $trip = DB::connection('sqlsrv_menam')->table('gps_trip_daily')->first();
        $this->assertSame(1, (int) $trip->late_return);
        $this->assertSame('ไม่พบการกลับเข้าบริษัทภายในวัน', $trip->note);
    }

    // ---------- whole day (DTC fake) ----------

    public function test_summarize_day_fetches_each_gps_truck_once_and_respects_the_request_cap(): void
    {
        $this->createTables();
        $service = $this->fakeSummarizeFleet();
        $first = $service->summarizeDay(self::DATE, false, 1);

        $this->assertSame(3, $first['trucks']);                 // trucks without a queue are ignored
        $this->assertSame('partial', $first['status']);
        $this->assertSame(1, $first['summarized']);
        $this->assertSame(2, $first['pending']);
        Http::assertSentCount(2);                                // master + 1 history (cap 1)

        $second = $service->summarizeDay(self::DATE);
        $this->assertSame('done', $second['status']);
        $this->assertSame(1, $second['skipped']);               // past day already summarized
        Http::assertSentCount(3);                                // + 1 history; no-GPS truck costs nothing

        $db = DB::connection('sqlsrv_menam');
        $this->assertSame(3, $db->table('gps_trip_daily')->count());
        $this->assertSame('ไม่มี GPS DTC', $db->table('gps_trip_daily')->where('plate_key', '712519')->value('note'));
        $this->assertSame('010399900000001', $db->table('gps_trip_daily')->where('plate_key', '835505')->value('dtc_gps_id')); // plate match

        $response = app(GpsDashboardController::class)->refresh(Request::create('/dp/gps-dashboard/refresh', 'POST', ['date' => self::DATE]));
        $payload = $response->getData(true);
        $this->assertTrue($payload['ok']);
        $this->assertSame(0, $payload['requests_used']);
        $this->assertStringContainsString('สรุป 3/3 คัน · ใช้ DTC 0 request (ข้อมูลเส้นทางจาก cache)', $payload['message']);
    }

    public function test_pending_count_does_not_double_subtract_already_skipped_trucks(): void
    {
        $this->createTables();
        $service = $this->fakeSummarizeFleet();
        $service->summarizeDay(self::DATE, false, 1); // first truck completed

        $result = $service->summarizeDay(self::DATE, false, 0);

        $this->assertSame('partial', $result['status']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(2, $result['pending']);
        Http::assertSentCount(2); // vehicle master + first history; capped call sends nothing
    }

    public function test_pending_count_excludes_completed_trucks_later_in_queue(): void
    {
        $this->createTables();
        $service = $this->fakeSummarizeFleet();
        DB::connection('sqlsrv_menam')->table('gps_trip_daily')->insert([
            'ship_date' => self::DATE,
            'plate_key' => '712519',
            'plate_no' => '71-2519',
            'has_route' => 1,
        ]);

        $result = $service->summarizeDay(self::DATE, false, 0);

        $this->assertSame('partial', $result['status']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(2, $result['pending']);
        Http::assertSentCount(1); // vehicle master only
    }

    public function test_trends_group_by_week_truck_and_customer(): void
    {
        $this->createTables();
        $db = DB::connection('sqlsrv_menam');
        $row = fn (string $date, string $plate, int $onTime, int $late, int $lateReturn) => [
            'ship_date' => $date, 'plate_key' => $plate, 'plate_no' => $plate, 'has_route' => 1, 'late_return' => $lateReturn,
            'stops_planned' => $onTime + $late, 'stops_with_pin' => $onTime + $late, 'stops_visited' => $onTime + $late,
            'stops_on_time' => $onTime, 'stops_late' => $late, 'stops_not_visited' => 0,
        ];
        $db->table('gps_trip_daily')->insert([
            $row('2026-11-10', 'T1', 3, 1, 0), $row('2026-11-11', 'T2', 2, 2, 1), $row('2026-11-17', 'T1', 4, 0, 0),
        ]);
        $db->table('gps_stop_visit')->insert([
            ['ship_date' => '2026-11-10', 'plate_key' => 'T1', 'stop_key' => 'a', 'stop_no' => 1, 'company' => 'MSW', 'customer_id' => 1, 'customer_name' => 'Slow Co', 'status' => 'visited', 'dwell_minutes' => 40, 'is_late' => 1],
            ['ship_date' => '2026-11-11', 'plate_key' => 'T2', 'stop_key' => 'b', 'stop_no' => 1, 'company' => 'MSW', 'customer_id' => 1, 'customer_name' => 'Slow Co', 'status' => 'visited', 'dwell_minutes' => 20, 'is_late' => 1],
            ['ship_date' => '2026-11-11', 'plate_key' => 'T2', 'stop_key' => 'c', 'stop_no' => 2, 'company' => 'MSW', 'customer_id' => 2, 'customer_name' => 'Fine Co', 'status' => 'visited', 'dwell_minutes' => 10, 'is_late' => 0],
        ]);

        $trends = app(GpsDailySummaryService::class)->trends('2026-11-01', '2026-11-30');

        $this->assertSame(['2026-11-09', '2026-11-16'], array_column($trends['weeks'], 'week')); // weeks start Monday
        $this->assertSame(62.5, $trends['weeks'][0]['on_time_percent']); // 5 on time / 8
        $this->assertSame(1, $trends['weeks'][0]['late_returns']);
        $this->assertSame(75.0, $trends['summary']['on_time_percent']);   // 9 / 12
        $this->assertSame('T2', $trends['by_truck'][0]['plate_no']);
        $this->assertSame(['Slow Co'], array_column($trends['late_customers'], 'customer_name'));
        $this->assertSame(30, $trends['late_customers'][0]['avg_dwell']);
    }

    // ---------- page / command ----------

    public function test_page_shows_setup_notice_before_sql_and_board_after(): void
    {
        View::share('errors', new ViewErrorBag());
        Cache::put('dtc_gps.schema.dashboard_tables', false, now()->addHour());
        $html = app(GpsDashboardController::class)
            ->index(Request::create('/dp/gps-dashboard', 'GET', ['date' => self::DATE]))
            ->with('canRefresh', true)
            ->render();
        $this->assertStringContainsString('formdp_gps_dashboard.sql', $html);

        $this->createTables();
        app(GpsDailySummaryService::class)->store(self::DATE, $this->truck([
            $this->delivery('A', 1, '10:00', self::CUSTOMER_A),
            $this->delivery('B', 2, '17:00', null),
        ]),
            $this->route([[self::CUSTOMER_A[0], self::CUSTOMER_A[1], 30]], '10:30:00'), null);
        $html = app(GpsDashboardController::class)
            ->index(Request::create('/dp/gps-dashboard', 'GET', ['date' => self::DATE]))
            ->with('canRefresh', true)
            ->render();

        $this->assertStringContainsString('83-5505', $html);
        $this->assertStringContainsString('ช้า 30 นาที', $html);
        $this->assertStringContainsString('จาก 1 จุดที่ประเมินได้', $html);
        $this->assertStringContainsString('คุณภาพข้อมูล: ประเมินได้ 50%', $html);
        $this->assertStringContainsString('ยังไม่ควรใช้ตัดสินผลงาน', $html);
        $this->assertStringContainsString('data-gdash-filter="late"', $html);
        $this->assertStringContainsString('data-gdash-filter="no-pin"', $html);
        $this->assertStringContainsString('data-gdash-filter="no-return"', $html);
        $this->assertStringContainsString('id="gdash-hide-normal"', $html);
        $this->assertStringContainsString('DTC เดือนนี้', $html);
        $this->assertStringContainsString('gdash-week-chart', $html);
        Http::assertNothingSent();
    }

    public function test_command_skips_quietly_when_tables_are_missing(): void
    {
        Cache::put('dtc_gps.schema.dashboard_tables', false, now()->addHour());

        $exit = Artisan::call('gps:summarize-day', ['--yesterday' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('formdp_gps_dashboard.sql', Artisan::output());
        Http::assertNothingSent();
    }
}
