<?php

namespace Tests\Unit;

use App\Http\Controllers\FormDP\GpsCustomerLocationController;
use App\Services\FormDP\DtcGpsService;
use App\Services\FormDP\GpsCustomerLocationService;
use App\Services\FormDP\GpsStopSuggestionService;
use App\Services\FormDP\GpsTripQueueService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Bulk-confirm DTC POIs and pin suggestions from real truck stops.
 * sqlsrv_menam is redirected to an in-memory sqlite inside the test; DTC is Http::fake.
 */
class GpsLocationSuggestionTest extends TestCase
{
    private const CUSTOMER_SPOT = [13.70, 100.90];
    private const OTHER_SPOT = [13.80, 100.60];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
        Http::preventStrayRequests();

        config()->set('database.connections.sqlsrv_menam', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
        DB::purge('sqlsrv_menam');
        Schema::connection('sqlsrv_menam')->create('gps_dtc_pois', function (Blueprint $table) {
            $table->string('poi_id')->primary();
            $table->string('poi_name');
            $table->string('location_info')->nullable();
            $table->string('poi_type')->nullable();
            $table->decimal('lat', 9, 6);
            $table->decimal('lon', 9, 6);
            $table->integer('area_m')->nullable();
            $table->dateTime('synced_at')->nullable();
        });
        Schema::connection('sqlsrv_menam')->create('gps_customer_locations', function (Blueprint $table) {
            $table->id();
            $table->string('company');
            $table->integer('customer_id');
            $table->string('address_key')->default('');
            $table->string('address_text')->nullable();
            $table->string('label')->nullable();
            $table->decimal('lat', 9, 6);
            $table->decimal('lon', 9, 6);
            $table->integer('radius_m')->default(150);
            $table->string('source');
            $table->string('dtc_poi_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->bigInteger('created_by')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->bigInteger('updated_by')->nullable();
        });
        Cache::put('dtc_gps.schema.location_tables', true, now()->addHour());
        Cache::put('dtc_gps.schema.truck_gps_column', true, now()->addHour());
    }

    private function seedPois(): void
    {
        DB::connection('sqlsrv_menam')->table('gps_dtc_pois')->insert([
            ['poi_id' => 'P1', 'poi_name' => 'เอบีซี สตีล', 'location_info' => 'บางพลี', 'lat' => 13.6, 'lon' => 100.7, 'area_m' => 30],
            ['poi_id' => 'P2', 'poi_name' => 'ไทยโลหะการ', 'location_info' => '', 'lat' => 13.7, 'lon' => 100.6, 'area_m' => 400],
        ]);
    }

    private function fakeCustomers(array $customers): void
    {
        $this->partialMock(GpsCustomerLocationService::class, fn ($mock) => $mock->shouldReceive('customerList')->andReturn($customers));
    }

    private function customer(int $id, string $name): array
    {
        return ['company' => 'MSW', 'customer_id' => $id, 'name' => $name, 'line_count' => 10, 'address_count' => 1, 'last_ship_at' => null, 'location_count' => 0, 'status' => 'suggested'];
    }

    // ---------- bulk confirm ----------

    public function test_bulk_candidates_skip_pinned_customers_and_flag_shared_pois(): void
    {
        $this->seedPois();
        DB::connection('sqlsrv_menam')->table('gps_customer_locations')->insert(
            ['company' => 'MSW', 'customer_id' => 3, 'address_key' => '', 'lat' => 13.6, 'lon' => 100.7, 'radius_m' => 150, 'source' => 'manual', 'is_active' => 1]
        );
        $this->fakeCustomers([
            $this->customer(1, 'บริษัท เอบีซี สตีล จำกัด'),
            $this->customer(2, 'เอบีซี สตีล (สาขา 2)'),
            $this->customer(3, 'ไทยโลหะการ'),          // already pinned
            $this->customer(4, 'ร้านอื่นไม่เกี่ยวข้องเลย'),  // no match
        ]);

        $rows = collect(app(GpsCustomerLocationService::class)->bulkCandidates(85))->keyBy('customer_id');

        $this->assertSame([1, 2], $rows->keys()->all());
        $this->assertSame('P1', $rows[1]['poi_id']);
        $this->assertSame(100, $rows[1]['score']);
        $this->assertTrue($rows[1]['shared']);
        Http::assertNothingSent();
    }

    public function test_bulk_confirm_saves_default_pins_with_min_radius_and_never_overwrites(): void
    {
        $this->seedPois();
        $db = DB::connection('sqlsrv_menam');
        $db->table('gps_customer_locations')->insert(
            ['company' => 'MSW', 'customer_id' => 2, 'address_key' => '', 'label' => 'pinned by hand', 'lat' => 13.1, 'lon' => 100.1, 'radius_m' => 150, 'source' => 'manual', 'is_active' => 1]
        );

        $result = app(GpsCustomerLocationService::class)->bulkConfirm([
            ['company' => 'MSW', 'customer_id' => 1, 'poi_id' => 'P1'],
            ['company' => 'MSW', 'customer_id' => 2, 'poi_id' => 'P2'],   // has a pin -> skipped
            ['company' => 'MSW', 'customer_id' => 5, 'poi_id' => 'NOPE'], // unknown POI -> skipped
            ['company' => 'MSW', 'customer_id' => 6, 'poi_id' => 'P2'],
        ], 7);

        $this->assertSame(2, $result['saved']);
        $this->assertCount(2, $result['skipped']);
        $one = $db->table('gps_customer_locations')->where('customer_id', 1)->first();
        $this->assertSame('dtc_poi', $one->source);
        $this->assertSame('P1', $one->dtc_poi_id);
        $this->assertSame(150, (int) $one->radius_m);   // DTC 30 m raised to 150
        $this->assertSame('', $one->address_key);
        $this->assertSame(7, (int) $one->created_by);
        $this->assertSame(400, (int) $db->table('gps_customer_locations')->where('customer_id', 6)->value('radius_m'));
        $this->assertSame('pinned by hand', $db->table('gps_customer_locations')->where('customer_id', 2)->value('label'));
    }

    public function test_bulk_confirm_validates_items(): void
    {
        $this->expectException(ValidationException::class);

        app(GpsCustomerLocationController::class)->bulkConfirm(Request::create('/dp/gps-locations/bulk-confirm', 'POST', [
            'items' => [['company' => 'MSW; DROP', 'customer_id' => 1, 'poi_id' => 'P1']],
        ]));
    }

    // ---------- stops from real routes ----------

    /** Points every 2 minutes; $plan = [[lat, lon, minutes], ...]. */
    private function route(string $date, array $plan): array
    {
        $time = new \DateTimeImmutable($date . ' 07:00:00', new \DateTimeZone('Asia/Bangkok'));
        $points = [];
        foreach ($plan as [$lat, $lon, $minutes]) {
            for ($m = 0; $m < $minutes; $m += 2) {
                $points[] = ['time' => $time->format('Y-m-d H:i:s'), 'lat' => $lat, 'lon' => $lon, 'gps_speed' => 0];
                $time = $time->modify('+2 minutes');
            }
            // driving to the next place
            $points[] = ['time' => $time->format('Y-m-d H:i:s'), 'lat' => $lat + 0.05, 'lon' => $lon + 0.05, 'gps_speed' => 60];
            $time = $time->modify('+2 minutes');
        }

        return $points;
    }

    public function test_stops_need_ten_minutes(): void
    {
        $service = app(GpsStopSuggestionService::class);
        $stops = $service->stopsFromPoints($this->route('2026-11-10', [[13.70, 100.90, 20], [13.90, 100.95, 4]]));

        $this->assertCount(1, $stops);
        $this->assertEqualsWithDelta(13.70, $stops[0]['lat'], 0.0001);
        $this->assertGreaterThanOrEqual(10, $stops[0]['minutes']);
    }

    public function test_cluster_ranks_spots_seen_on_more_trips_first(): void
    {
        $service = app(GpsStopSuggestionService::class);
        $trip = fn (string $date) => ['date' => $date, 'plate_no' => 'X', 'gps_id' => 'G'];
        $stop = fn (float $lat, float $lon, int $minutes) => ['lat' => $lat, 'lon' => $lon, 'arrived_at' => '2026-11-10 08:00:00', 'left_at' => '2026-11-10 09:00:00', 'minutes' => $minutes];

        $candidates = $service->cluster([
            ['trip' => $trip('2026-11-10'), 'stops' => [$stop(13.7000, 100.9000, 20), $stop(13.95, 100.95, 90)]],
            ['trip' => $trip('2026-11-12'), 'stops' => [$stop(13.7008, 100.9005, 25)]], // ~100 m from the first
        ]);

        $this->assertSame(2, $candidates[0]['trips']);
        $this->assertSame(45, $candidates[0]['total_minutes']);
        $this->assertSame(1, $candidates[1]['trips']);
    }

    public function test_suggest_uses_recent_trips_and_excludes_company_and_other_pinned_customers(): void
    {
        config()->set('dtc.base_url', 'https://gps.example.test:8099/');
        config()->set('dtc.api_token', 'test-token');
        $company = GpsStopSuggestionService::COMPANY;
        $routes = [
            '2026-11-10' => $this->route('2026-11-10', [[$company[0], $company[1], 30], [self::OTHER_SPOT[0], self::OTHER_SPOT[1], 20], [self::CUSTOMER_SPOT[0], self::CUSTOMER_SPOT[1], 16]]),
            '2026-11-12' => $this->route('2026-11-12', [[self::CUSTOMER_SPOT[0], self::CUSTOMER_SPOT[1], 24], [13.95, 100.95, 12]]),
        ];
        Http::fake([
            'gps.example.test:8099/getVehicleMaster' => Http::response(['error' => false, 'data' => [
                ['gps_id' => '010399900000001', 'vehicle_name' => '83-5505', 'license_plate' => '010399900000001'],
            ]]),
            'gps.example.test:8099/getHistory' => function ($request) use ($routes) {
                return Http::response(['error' => false, 'data' => $routes[substr($request['start_period'], 0, 10)]]);
            },
        ]);
        app(DtcGpsService::class)->getVehicleMasterCached();

        $this->partialMock(GpsTripQueueService::class, function ($mock) {
            $mock->shouldReceive('forDate')->andReturn(collect([
                ['plate_key' => '835505', 'plate_no' => '83-5505', 'deliveries' => [
                    ['customer' => 'Other', 'company' => 'MSW', 'customer_id' => 99, 'address' => '', 'items' => []],
                    ['customer' => 'Me', 'company' => 'MSW', 'customer_id' => 10, 'address' => '', 'items' => []],
                ]],
            ]));
        });
        DB::connection('sqlsrv_menam')->table('gps_customer_locations')->insert(
            ['company' => 'MSW', 'customer_id' => 99, 'address_key' => '', 'lat' => self::OTHER_SPOT[0], 'lon' => self::OTHER_SPOT[1], 'radius_m' => 150, 'source' => 'manual', 'is_active' => 1]
        );
        $service = \Mockery::mock(GpsStopSuggestionService::class, [
            app(GpsTripQueueService::class), app(GpsCustomerLocationService::class), app(DtcGpsService::class),
        ])->makePartial();
        $service->shouldReceive('recentTrips')->andReturn([
            ['date' => '2026-11-12', 'plate_no' => '83-5505', 'gps_id' => '010399900000001'],
            ['date' => '2026-11-10', 'plate_no' => '83-5505', 'gps_id' => '010399900000001'],
            ['date' => '2026-11-01', 'plate_no' => '71-2519', 'gps_id' => ''], // no GPS: skipped, no request
        ]);

        $result = $service->suggest('MSW', 10);

        $this->assertSame(3, $result['trips_found']);
        $this->assertSame(2, $result['trips_checked']);
        $top = $result['candidates'][0];
        $this->assertSame(2, $top['trips']);
        $this->assertEqualsWithDelta(self::CUSTOMER_SPOT[0], $top['lat'], 0.001);
        foreach ($result['candidates'] as $candidate) {
            $this->assertGreaterThan(0.01, abs($candidate['lat'] - $company[0]), 'company stop must be excluded');
            $this->assertGreaterThan(0.01, abs($candidate['lat'] - self::OTHER_SPOT[0]), 'other customer stop must be excluded');
        }
        Http::assertSentCount(3); // master + one history per trip with GPS

        // Second run: both routes come from cache, no DTC request.
        $service->suggest('MSW', 10);
        Http::assertSentCount(3);
    }
}
