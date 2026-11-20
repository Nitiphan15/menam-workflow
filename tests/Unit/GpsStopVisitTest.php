<?php

namespace Tests\Unit;

use App\Http\Controllers\FormDP\GpsTripTrackingController;
use App\Services\FormDP\DtcGpsService;
use App\Services\FormDP\GpsCustomerLocationService;
use App\Services\FormDP\GpsStopVisitService;
use App\Services\FormDP\GpsTripQueueService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Arrival / leave times per stop from real route points. No real DB, no real DTC.
 */
class GpsStopVisitTest extends TestCase
{
    private const STOP = ['lat' => 13.6, 'lon' => 100.8, 'radius_m' => 150];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
        Cache::put('dtc_gps.schema.location_tables', false, now()->addHour());
        Cache::put('dtc_gps.schema.truck_gps_column', false, now()->addHour());
        Http::preventStrayRequests();
    }

    /** One point per minute starting at $start; $inside[i] true = at the stop, false = ~2 km away. */
    private function track(string $start, array $inside): array
    {
        $base = new \DateTimeImmutable('2026-11-19 ' . $start, new \DateTimeZone('Asia/Bangkok'));

        return array_map(fn (bool $in, int $i) => [
            'time' => $base->modify("+{$i} minutes")->format('Y-m-d H:i:s'),
            'lat' => $in ? self::STOP['lat'] + 0.0005 : self::STOP['lat'] + 0.02, // ~55 m vs ~2.2 km
            'lon' => self::STOP['lon'],
        ], $inside, array_keys($inside));
    }

    private function detect(array $points, array $deliveries): array
    {
        return app(GpsStopVisitService::class)->detect($points, $deliveries);
    }

    public function test_visit_reports_arrival_leave_and_dwell(): void
    {
        $points = $this->track('10:00:00', [false, false, true, true, true, true, true, true, false, false]);

        $visit = $this->detect($points, [['location' => self::STOP]])[0];

        $this->assertSame('visited', $visit['status']);
        $this->assertSame('2026-11-19 10:02:00', $visit['arrived_at']);
        $this->assertSame('2026-11-19 10:07:00', $visit['left_at']);
        $this->assertSame(5, $visit['dwell_minutes']);
        $this->assertSame(1, $visit['visits']);
    }

    public function test_short_gps_dropout_is_merged_into_one_visit(): void
    {
        // 10:01-10:04 inside, 10:05-10:06 jitter outside, 10:07-10:10 inside again (gap 3 min <= 5).
        $points = $this->track('10:00:00', [false, true, true, true, true, false, false, true, true, true, true, false]);

        $visit = $this->detect($points, [['location' => self::STOP]])[0];

        $this->assertSame('visited', $visit['status']);
        $this->assertSame('2026-11-19 10:01:00', $visit['arrived_at']);
        $this->assertSame('2026-11-19 10:10:00', $visit['left_at']);
        $this->assertSame(1, $visit['visits']);
    }

    public function test_drive_by_is_passed_not_arrived(): void
    {
        $points = $this->track('09:00:00', [false, true, true, false, false]);

        $visit = $this->detect($points, [['location' => self::STOP]])[0];

        $this->assertSame('passed', $visit['status']);
        $this->assertNull($visit['left_at']);
        $this->assertNull($visit['dwell_minutes']);
    }

    public function test_truck_still_at_stop_has_no_leave_time(): void
    {
        $points = $this->track('15:00:00', [false, true, true, true, true, true]);

        $visit = $this->detect($points, [['location' => self::STOP]])[0];

        $this->assertSame('at_stop', $visit['status']);
        $this->assertNull($visit['left_at']);
        $this->assertSame(4, $visit['dwell_minutes']);
    }

    public function test_not_visited_reports_nearest_distance_and_missing_pins(): void
    {
        $points = $this->track('08:00:00', [false, false, false]);

        $visits = $this->detect($points, [
            ['location' => self::STOP],
            ['location' => null],
            [],
        ]);

        $this->assertSame('not_visited', $visits[0]['status']);
        $this->assertGreaterThan(2000, $visits[0]['nearest_m']);
        $this->assertSame('no_location', $visits[1]['status']);
        $this->assertSame('no_location', $visits[2]['status']);
        $this->assertSame('no_route', $this->detect([], [['location' => self::STOP]])[0]['status']);
    }

    public function test_longest_stay_wins_when_the_truck_comes_back(): void
    {
        // 10:01-10:04 (3 min), out 10 min, 10:15-10:25 (10 min).
        $inside = array_merge([false], array_fill(0, 4, true), array_fill(0, 10, false), array_fill(0, 11, true), [false]);
        $points = $this->track('10:00:00', $inside);

        $visit = $this->detect($points, [['location' => self::STOP]])[0];

        $this->assertSame('visited', $visit['status']);
        $this->assertSame('2026-11-19 10:15:00', $visit['arrived_at']);
        $this->assertSame(10, $visit['dwell_minutes']);
        $this->assertSame(2, $visit['visits']);
    }

    public function test_route_endpoint_returns_stop_visits_for_the_linked_truck(): void
    {
        config()->set('dtc.base_url', 'https://gps.example.test:8099/');
        config()->set('dtc.api_token', 'test-token');
        $this->partialMock(GpsTripQueueService::class, function ($mock) {
            $mock->shouldReceive('activeTrucks')->andReturn(collect([
                ['truck_id' => 14, 'plate_no' => '1ฒณ1307', 'plate_key' => '1ฒณ1307', 'driver_name' => 'D', 'dtc_gps_id' => '010399900000001'],
            ]));
            $mock->shouldReceive('forDate')->andReturn(collect([
                ['plate_key' => '1ฒณ1307', 'plate_no' => '1ฒณ1307', 'trip_no' => 1, 'truck_id' => 14, 'driver_name' => 'D', 'stops' => ['A', 'B'], 'deliveries' => [
                    ['customer' => 'A', 'company' => 'MSW', 'customer_id' => 10, 'address' => '', 'items' => []],
                    ['customer' => 'B', 'company' => 'MSW', 'customer_id' => 20, 'address' => '', 'items' => []],
                ]],
            ]));
        });
        $this->partialMock(GpsCustomerLocationService::class, function ($mock) {
            $mock->shouldReceive('locationsForDeliveries')->andReturn([
                'MSW|10' => ['' => [...self::STOP, 'label' => 'A']],
            ]);
        });
        $points = $this->track('10:00:00', [false, true, true, true, true, true, false]);
        Http::fake([
            'gps.example.test:8099/getVehicleMaster' => Http::response(['error' => false, 'data' => [
                ['gps_id' => '010399900000001', 'vehicle_name' => '1ฒณ-1307', 'license_plate' => '010399900000001'],
            ]]),
            'gps.example.test:8099/getHistory' => Http::response(['error' => false, 'data' => array_map(fn ($p) => [...$p, 'gps_speed' => 0], $points)]),
        ]);
        app(DtcGpsService::class)->getVehicleMasterCached();
        request()->query->set('date', '2026-11-19');

        $payload = app(GpsTripTrackingController::class)->route('010399900000001')->getData(true);

        $this->assertTrue($payload['ok']);
        $this->assertSame('visited', $payload['stop_visits'][0]['status']);
        $this->assertSame('2026-11-19 10:01:00', $payload['stop_visits'][0]['arrived_at']);
        $this->assertSame('no_location', $payload['stop_visits'][1]['status']);
        Http::assertSentCount(2); // master + one history call, nothing extra for the visits
    }
}
