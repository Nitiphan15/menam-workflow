<?php

namespace Tests\Unit;

use App\Services\FormDP\GpsDailySummaryService;
use App\Services\FormDP\GpsPlaceResolver;
use App\Services\FormDP\GpsTripTimelineService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Unplanned stops and real travel legs from one day of route points.
 * sqlsrv_menam is an in-memory sqlite inside the test; place names are mocked (no Longdo / DB call).
 */
class GpsTripTimelineTest extends TestCase
{
    private const DATE = '2026-11-19';
    private const CUSTOMER = [13.70, 100.90];
    private const FUEL = [13.62, 100.84];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
        Http::preventStrayRequests();
        config()->set('dtc.longdo_key', '');
        config()->set('database.connections.sqlsrv_menam', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
        DB::purge('sqlsrv_menam');
        Cache::put('dtc_gps.schema.location_tables', false, now()->addHour());
        Cache::put('dtc_gps.schema.truck_gps_column', true, now()->addHour());
        $this->mock(GpsPlaceResolver::class, fn ($mock) => $mock->shouldReceive('resolve')
            ->andReturn(['kind' => 'near', 'label' => 'ปั๊มน้ำมันทดสอบ', 'distance_m' => 40, 'detail' => 'ต.ทดสอบ อ.ทดสอบ จ.ทดสอบ']));
    }

    /** Points every 2 minutes; $plan = [[lat, lon, minutes], ...], starting $start. */
    private function route(array $plan, string $start = '07:00:00'): array
    {
        $time = new \DateTimeImmutable(self::DATE . ' ' . $start, new \DateTimeZone('Asia/Bangkok'));
        $points = [];
        foreach ($plan as [$lat, $lon, $minutes]) {
            for ($m = 0; $m < $minutes; $m += 2) {
                $points[] = ['time' => $time->format('Y-m-d H:i:s'), 'lat' => $lat, 'lon' => $lon, 'speed' => 0];
                $time = $time->modify('+2 minutes');
            }
        }

        return $points;
    }

    /** company 20' → drive 30' → fuel stop 20' → drive 20' → customer 40' → drive 30' → company 20' */
    private function day(): array
    {
        $company = GpsDailySummaryService::COMPANY;

        return $this->route([
            [$company[0], $company[1], 20],
            [13.58, 100.80, 2], [13.60, 100.82, 2], [13.61, 100.83, 2],               // driving (no stay)
            [self::FUEL[0], self::FUEL[1], 20],
            [13.65, 100.86, 2], [13.68, 100.88, 2],
            [self::CUSTOMER[0], self::CUSTOMER[1], 40],
            [13.66, 100.86, 2], [13.60, 100.82, 2],
            [$company[0], $company[1], 20],
        ]);
    }

    private function delivery(): array
    {
        return ['customer' => 'ลูกค้า A', 'company' => 'MSW', 'customer_id' => 10, 'address' => '', 'stop_key' => 'KEY-A', 'items' => [],
            'location' => ['lat' => self::CUSTOMER[0], 'lon' => self::CUSTOMER[1], 'radius_m' => 150, 'label' => null]];
    }

    public function test_stops_are_classified_and_legs_exclude_unplanned_time(): void
    {
        $points = $this->day();
        $metrics = app(GpsDailySummaryService::class)->tripMetrics($points);

        $timeline = app(GpsTripTimelineService::class)->build($points, [$this->delivery()], $metrics);

        $this->assertSame(['company', 'unplanned', 'customer', 'company'], array_column($timeline['stops'], 'kind'));
        $fuel = $timeline['stops'][1];
        $this->assertSame('ปั๊มน้ำมันทดสอบ', $fuel['place']['label']);
        $this->assertGreaterThanOrEqual(10, $fuel['minutes']);
        $this->assertSame('KEY-A', $timeline['stops'][2]['stop_key']);

        $this->assertCount(2, $timeline['legs']);
        [$out, $back] = $timeline['legs'];
        $this->assertSame(['company', 'customer', 10], [$out['from_kind'], $out['to_kind'], $out['customer_id']]);
        $this->assertSame($fuel['minutes'], $out['unplanned_minutes']);
        $this->assertSame($out['minutes'] - $fuel['minutes'], $out['drive_minutes']);
        $this->assertGreaterThan(5, $out['km']);
        $this->assertSame(['customer', 'company'], [$back['from_kind'], $back['to_kind']]);
        $this->assertNull($back['customer_id']);
    }

    public function test_unpinned_customers_make_stops_unknown_or_match_by_place_name(): void
    {
        $points = $this->day();
        $metrics = app(GpsDailySummaryService::class)->tripMetrics($points);
        $pinned = $this->delivery();
        $unpinnedOther = ['customer' => 'บจก. อื่นๆ ไม่เกี่ยว', 'company' => 'MSW', 'customer_id' => 20, 'stop_key' => 'KEY-B', 'location' => null];
        $unpinnedSameName = ['customer' => 'บริษัท ปั๊มน้ำมันทดสอบ จำกัด', 'company' => 'MSW', 'customer_id' => 30, 'stop_key' => 'KEY-C', 'location' => null];

        // Someone on the queue has no pin → the fuel stop could be them: "unknown", not "unplanned".
        $unknown = app(GpsTripTimelineService::class)->build($points, [$pinned, $unpinnedOther], $metrics);
        $this->assertSame(['company', 'unknown', 'customer', 'company'], array_column($unknown['stops'], 'kind'));

        // The place found there (mocked: "ปั๊มน้ำมันทดสอบ", 40 m) matches an unpinned customer → counted as that customer.
        $named = app(GpsTripTimelineService::class)->build($points, [$pinned, $unpinnedSameName], $metrics);
        $this->assertSame(['company', 'customer', 'customer', 'company'], array_column($named['stops'], 'kind'));
        $this->assertSame('KEY-C', $named['stops'][1]['stop_key']);
        $this->assertSame(30, $named['legs'][0]['customer_id']);       // company → that customer is now a real leg
        $this->assertSame(0, $named['legs'][0]['unplanned_minutes']);
    }

    public function test_company_departure_comes_from_metrics_when_truck_left_quickly(): void
    {
        // Only 4 minutes in the company at the start (no 10-minute stay) → departure from left_company_at.
        $company = GpsDailySummaryService::COMPANY;
        $points = $this->route([[$company[0], $company[1], 4], [13.6, 100.82, 2], [self::CUSTOMER[0], self::CUSTOMER[1], 30]]);
        $metrics = app(GpsDailySummaryService::class)->tripMetrics($points);

        $timeline = app(GpsTripTimelineService::class)->build($points, [$this->delivery()], $metrics);

        $this->assertSame(['customer'], array_column($timeline['stops'], 'kind'));
        $this->assertCount(1, $timeline['legs']);
        $this->assertSame('company', $timeline['legs'][0]['from_kind']);
        $this->assertSame($metrics['left_company_at'], $timeline['legs'][0]['depart_at']);
    }

    // ---------- storage + read side ----------

    private function createTables(): void
    {
        $schema = Schema::connection('sqlsrv_menam');
        $schema->create('gps_trip_daily', function (Blueprint $t) {
            $t->id();
            $t->date('ship_date'); $t->string('plate_key'); $t->string('plate_no'); $t->integer('truck_id')->nullable();
            $t->string('dtc_gps_id')->nullable(); $t->string('driver_name')->nullable(); $t->string('trip_nos')->nullable();
            $t->boolean('has_route')->default(false); $t->integer('point_count')->nullable();
            foreach (['first_point_at', 'last_point_at', 'left_company_at', 'returned_at', 'computed_at'] as $c) {
                $t->dateTime($c)->nullable();
            }
            $t->boolean('late_return')->default(false); $t->decimal('km', 9, 1)->nullable(); $t->integer('max_speed')->nullable();
            foreach (['stops_planned', 'stops_with_pin', 'stops_visited', 'stops_on_time', 'stops_late', 'stops_not_visited'] as $c) {
                $t->integer($c)->default(0);
            }
            $t->string('note')->nullable();
            $t->unique(['ship_date', 'plate_key']);
        });
        $schema->create('gps_stop_visit', function (Blueprint $t) {
            $t->id();
            $t->date('ship_date'); $t->string('plate_key'); $t->string('stop_key'); $t->integer('stop_no');
            $t->string('company')->nullable(); $t->integer('customer_id')->nullable(); $t->string('customer_name')->nullable();
            $t->string('window_time')->nullable(); $t->string('status');
            $t->dateTime('arrived_at')->nullable(); $t->dateTime('left_at')->nullable();
            $t->integer('dwell_minutes')->nullable(); $t->integer('late_minutes')->nullable(); $t->boolean('is_late')->default(false);
            $t->dateTime('computed_at')->nullable();
            $t->unique(['ship_date', 'plate_key', 'stop_key']);
        });
        $schema->create('gps_trip_stop', function (Blueprint $t) {
            $t->id();
            $t->date('ship_date'); $t->string('plate_key'); $t->integer('seq'); $t->string('kind');
            $t->string('stop_key')->nullable(); $t->string('customer_name')->nullable();
            $t->dateTime('arrived_at'); $t->dateTime('left_at'); $t->integer('minutes');
            $t->decimal('lat', 9, 6); $t->decimal('lon', 9, 6);
            $t->string('place_kind')->nullable(); $t->string('place_label')->nullable(); $t->string('place_detail')->nullable();
            $t->dateTime('computed_at')->nullable();
            $t->unique(['ship_date', 'plate_key', 'seq']);
        });
        $schema->create('gps_trip_leg', function (Blueprint $t) {
            $t->id();
            $t->date('ship_date'); $t->string('plate_key'); $t->integer('seq');
            $t->string('from_kind'); $t->string('from_key')->nullable(); $t->string('from_name')->nullable();
            $t->string('to_kind'); $t->string('to_key')->nullable(); $t->string('to_name')->nullable();
            $t->string('company')->nullable(); $t->integer('customer_id')->nullable();
            $t->dateTime('depart_at'); $t->dateTime('arrive_at');
            $t->integer('minutes'); $t->integer('unplanned_minutes')->default(0); $t->integer('drive_minutes');
            $t->decimal('km', 9, 1)->nullable(); $t->dateTime('computed_at')->nullable();
            $t->unique(['ship_date', 'plate_key', 'seq']);
        });
        Cache::put('dtc_gps.schema.dashboard_tables', true, now()->addHour());
        Cache::put('dtc_gps.schema.timeline_tables', true, now()->addHour());
    }

    private function truck(array $deliveries): array
    {
        return ['plate_key' => '835505', 'plate_no' => '83-5505', 'truck_id' => 12, 'driver_name' => 'D', 'trip_nos' => [1], 'gps_id' => 'G1', 'deliveries' => $deliveries];
    }

    public function test_same_customer_on_two_trips_is_stored_as_one_stop(): void
    {
        $this->createTables();
        $delivery = $this->delivery();
        unset($delivery['stop_key']);
        $trip1 = [...$delivery, 'window_time' => '16:00', 'items' => [['dp_ref' => 'DP#1']]];
        $trip2 = [...$delivery, 'window_time' => '10:00', 'items' => [['dp_ref' => 'DP#2']]];

        app(GpsDailySummaryService::class)->store(self::DATE, $this->truck([$trip1, $trip2]), $this->day(), null);

        $db = DB::connection('sqlsrv_menam');
        $this->assertSame(1, $db->table('gps_stop_visit')->count());
        $this->assertSame('10:00', trim((string) $db->table('gps_stop_visit')->value('window_time')));   // earliest window
        $this->assertSame(1, (int) $db->table('gps_trip_daily')->value('stops_planned'));
    }

    public function test_store_writes_stops_and_legs_idempotently_and_feeds_board_and_travel_times(): void
    {
        $this->createTables();
        $service = app(GpsDailySummaryService::class);
        $delivery = $this->delivery();
        unset($delivery['stop_key']); // the summary computes its own key

        $service->store(self::DATE, $this->truck([$delivery]), $this->day(), null);
        $service->store(self::DATE, $this->truck([$delivery]), $this->day(), null);   // re-run: same rows

        $db = DB::connection('sqlsrv_menam');
        $this->assertSame(4, $db->table('gps_trip_stop')->count());
        $this->assertSame(2, $db->table('gps_trip_leg')->count());
        $this->assertSame('ปั๊มน้ำมันทดสอบ', $db->table('gps_trip_stop')->where('kind', 'unplanned')->value('place_label'));
        $this->assertSame('ห่าง 40 ม. · ต.ทดสอบ อ.ทดสอบ จ.ทดสอบ', $db->table('gps_trip_stop')->where('kind', 'unplanned')->value('place_detail'));
        $customerKey = $db->table('gps_stop_visit')->value('stop_key');
        $this->assertSame($customerKey, $db->table('gps_trip_stop')->where('kind', 'customer')->value('stop_key'));

        $board = $service->dayBoard(self::DATE);
        $this->assertSame(1, $board['summary']['unplanned_count']);
        $this->assertCount(1, $board['trucks'][0]['unplanned']);
        $this->assertSame('ปั๊มน้ำมันทดสอบ', $board['trucks'][0]['unplanned'][0]['place_label']);

        $times = $service->travelTimes('2026-11-01', '2026-11-30');
        $this->assertCount(1, $times);
        $this->assertSame(['ลูกค้า A', 1], [$times[0]['customer_name'], $times[0]['trips']]);
        $unplannedOnTheWay = (int) $db->table('gps_trip_leg')->where('to_kind', 'customer')->value('unplanned_minutes');
        $this->assertGreaterThanOrEqual(10, $unplannedOnTheWay);
        $this->assertSame($times[0]['median_minutes'] - $unplannedOnTheWay, $times[0]['median_drive_minutes']);
        $this->assertSame(1, $times[0]['with_unplanned']);
        $this->assertNotNull($times[0]['typical_arrival']);

        $trends = $service->trends('2026-11-01', '2026-11-30');
        $this->assertSame(1, $trends['summary']['unplanned_stops']);
        $this->assertSame(1, $trends['by_truck'][0]['unplanned_stops']);

        // Route without the fuel stop: fewer stops/legs → old extra rows kept but marked removed.
        $company = GpsDailySummaryService::COMPANY;
        $shorter = $this->route([[$company[0], $company[1], 20], [self::CUSTOMER[0], self::CUSTOMER[1], 40], [$company[0], $company[1], 20]]);
        $service->store(self::DATE, $this->truck([$delivery]), $shorter, null);
        $this->assertSame(4, $db->table('gps_trip_stop')->count());
        $this->assertSame(1, $db->table('gps_trip_stop')->where('kind', 'removed')->count());
        $this->assertSame(0, $service->dayBoard(self::DATE)['summary']['unplanned_count']);
        Http::assertNothingSent();
    }
}
