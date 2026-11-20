<?php

namespace Tests\Unit;

use App\Http\Controllers\FormDP\GpsTripTrackingController;
use App\Services\FormDP\DtcGpsService;
use App\Services\FormDP\DtcRateLimitException;
use App\Services\FormDP\GpsTripQueueService;
use App\Models\Users\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DtcGpsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Usage counter + saved snapshots must never touch real storage/app during tests.
        Storage::fake('local');
        Cache::flush();
        Http::preventStrayRequests();
        config()->set('dtc.monthly_quota', 25000);
        config()->set('dtc.monthly_reserve', 500);
    }

    private function fakeDtc(?array $masterRows = null, ?array $realtimeRows = null): void
    {
        config()->set('dtc.base_url', 'https://gps.example.test:8099/');
        config()->set('dtc.api_token', 'test-token');
        config()->set('dtc.retry_times', 1);
        Http::fake([
            'gps.example.test:8099/getVehicleMaster' => Http::response(['error' => false, 'status' => '200', 'data' => $masterRows ?? [
                ['gps_id' => '010399900000001', 'vehicle_name' => '1ฒณ-1307', 'license_plate' => '010399900000001', 'sim_no' => '0800000000', 'chassis_no' => 'CH-1'],
            ]]),
            'gps.example.test:8099/getRealtimeData' => Http::response(['error' => false, 'status' => '200', 'data' => $realtimeRows ?? [
                $this->liveRealtimeRow(),
            ]]),
        ]);
    }

    private function fakeFleet(): void
    {
        $this->partialMock(GpsTripQueueService::class, function ($mock) {
            $mock->shouldReceive('activeTrucks')->andReturn(collect([
                ['truck_id' => 14, 'plate_no' => '1ฒณ1307', 'plate_key' => '1ฒณ1307', 'driver_name' => 'Master Driver A'],
                ['truck_id' => 12, 'plate_no' => '83-5505', 'plate_key' => '835505', 'driver_name' => 'Master Driver B'],
                ['truck_id' => 1, 'plate_no' => '71-2519', 'plate_key' => '712519', 'driver_name' => ''],
            ]));
            $mock->shouldReceive('forDate')->andReturn(collect([
                ['plate_key' => '835505', 'plate_no' => '83-5505', 'trip_no' => 2, 'truck_id' => 12, 'driver_name' => 'Queue Driver', 'stops' => ['ลูกค้า B'], 'deliveries' => [['customer' => 'ลูกค้า B', 'items' => [['dp_ref' => 'DP#2', 'so_number' => 'SO2', 'product' => 'P2', 'part_number' => '', 'quantity' => 2, 'unit' => 'ตัน']]]]],
                ['plate_key' => '835505', 'plate_no' => '83-5505', 'trip_no' => 1, 'truck_id' => 12, 'driver_name' => '', 'stops' => ['ลูกค้า A'], 'deliveries' => [['customer' => 'ลูกค้า A', 'items' => [['dp_ref' => 'DP#1', 'so_number' => 'SO1', 'product' => 'P1', 'part_number' => '', 'quantity' => 1, 'unit' => 'ตัน']]]]],
                ['plate_key' => '707494', 'plate_no' => '70-7494', 'trip_no' => 1, 'truck_id' => null, 'driver_name' => 'Manual Driver', 'stops' => ['ลูกค้า C'], 'deliveries' => []],
            ]));
        });
    }

    public function test_fleet_merges_truck_master_with_manual_queue_plates_and_trips(): void
    {
        $this->fakeFleet();
        $list = app(GpsTripQueueService::class)->fleetForDate('2026-11-19');
        $fleet = $list->keyBy('plate_key');

        // Queued trucks first, then the rest of Truck Master.
        $this->assertSame(['707494', '835505', '1ฒณ1307', '712519'], $list->pluck('plate_key')->all());
        $this->assertSame([1, 2], $fleet['835505']['trip_nos']);
        $this->assertSame(['ลูกค้า A', 'ลูกค้า B'], $fleet['835505']['stops']);
        $this->assertCount(2, $fleet['835505']['deliveries']);
        $this->assertSame('Queue Driver', $fleet['835505']['driver_name']);
        $this->assertSame('manual', $fleet['707494']['source']);
        $this->assertSame('master', $fleet['1ฒณ1307']['source']);
        $this->assertSame([], $fleet['1ฒณ1307']['trip_nos']);
        $this->assertSame('Master Driver A', $fleet['1ฒณ1307']['driver_name']);
    }

    public function test_index_uses_real_fleet_and_never_calls_dtc_or_builds_demo_positions(): void
    {
        $this->fakeFleet();
        $this->fakeDtc();
        request()->query->set('date', '2026-11-19');

        $data = app(GpsTripTrackingController::class)->index()->getData();
        $vehicles = collect($data['vehicles'])->keyBy('plate_no');

        $this->assertSame('2026-11-19', $data['queueDate']);
        $this->assertFalse($data['isToday']);
        $this->assertSame(4, $vehicles->count());
        $this->assertSame(2, $data['queuedVehicleCount']);
        $this->assertTrue($vehicles['83-5505']['has_queue']);
        $this->assertSame('คิว 2026-11-19 · เที่ยว 1, 2', $vehicles['83-5505']['trip_ref']);
        $this->assertSame(['ลูกค้า A', 'ลูกค้า B'], $vehicles['83-5505']['stops']);
        $this->assertArrayNotHasKey('route', $vehicles['83-5505']);
        $this->assertArrayNotHasKey('planned_departure', $vehicles['83-5505']);
        $this->assertFalse($vehicles['1ฒณ1307']['has_queue']);
        $this->assertSame('-', $vehicles['71-2519']['driver_name']);
        $this->assertSame([], $data['liveSnapshot']['rows']); // nothing synced yet
        $this->assertSame(0, $data['matchReport']['matched_count']);
        Http::assertNothingSent();
    }

    public function test_invalid_date_falls_back_to_today(): void
    {
        $this->fakeFleet();
        request()->query->set('date', '2026-02-31');

        $data = app(GpsTripTrackingController::class)->index()->getData();

        $this->assertSame(now('Asia/Bangkok')->toDateString(), $data['queueDate']);
    }

    public function test_dtc_test_matches_whole_fleet_including_trucks_without_queue(): void
    {
        $this->fakeFleet();
        $this->fakeDtc(
            [
                ['gps_id' => '010399900000001', 'vehicle_name' => '1ฒณ-1307', 'license_plate' => '010399900000001'],
                ['gps_id' => '010399900000005', 'vehicle_name' => '7กก-312', 'license_plate' => '010399900000005'],
            ],
            [
                $this->liveRealtimeRow(['gps_id' => '010399900000001', 'truck_name' => '1ฒณ-1307']),
                $this->liveRealtimeRow(['gps_id' => '010399900000005', 'truck_name' => '7กก-312']),
            ]
        );

        $payload = app(GpsTripTrackingController::class)->testDtcConnection()->getData(true);
        $rows = collect($payload['match_report']['rows'])->keyBy(fn ($row) => $row['dtc_name'] ?: $row['plate_no']);

        $this->assertTrue($rows['1ฒณ-1307']['matched']);           // in Truck Master, no queue that day
        $this->assertSame('missing_formdp', $rows['7กก-312']['issue']); // in DTC, not in Truck Master
        $this->assertSame('missing_dtc', $rows['70-7494']['issue']);   // manual queue plate without GPS
        $this->assertSame(1, $payload['match_report']['matched_count']);
    }

    public function test_fourth_request_within_a_minute_is_blocked_locally_without_http(): void
    {
        $this->fakeDtc();
        $service = app(DtcGpsService::class);

        $service->getRealtimeData([]);
        $service->getRealtimeData([]);
        $service->getRealtimeData([]);

        try {
            $service->getRealtimeData([]);
            $this->fail('Expected local per-minute limit.');
        } catch (DtcRateLimitException $exception) {
            $info = $service->describeException($exception);
            $this->assertSame('rate_limited_local', $info['code']);
            $this->assertTrue($info['retryable']);
            $this->assertGreaterThanOrEqual(1, $exception->retryAfterSeconds);
            $this->assertLessThanOrEqual(60, $exception->retryAfterSeconds);
        }
        Http::assertSentCount(3);
        $this->assertSame(3, $service->usageSummary()['month_total']);

        $this->travel(61)->seconds();
        $service->getRealtimeData([]);
        Http::assertSentCount(4);
    }

    public function test_window_is_shared_between_service_instances(): void
    {
        $this->fakeDtc();
        app(DtcGpsService::class)->getRealtimeData([]);
        app(DtcGpsService::class)->getRealtimeData([]);
        app(DtcGpsService::class)->getRealtimeData([]);

        $this->expectException(DtcRateLimitException::class);
        app(DtcGpsService::class)->getVehicleMaster();
    }

    public function test_real_http_429_blocks_further_requests_for_a_minute(): void
    {
        config()->set('dtc.base_url', 'https://gps.example.test:8099/');
        config()->set('dtc.api_token', 'test-token');
        config()->set('dtc.retry_times', 1);
        Http::fake(['gps.example.test:8099/*' => Http::response(['error' => true, 'message' => 'too many'], 429)]);
        $service = app(DtcGpsService::class);

        try {
            $service->getRealtimeData([]);
        } catch (RequestException) {
        }

        $this->assertSame(0, $service->minuteWindow()['available']);
        try {
            $service->getRealtimeData([]);
            $this->fail('Expected local block after real 429.');
        } catch (DtcRateLimitException) {
        }
        Http::assertSentCount(1);
    }

    public function test_test_endpoint_checks_slots_before_starting_two_request_flow(): void
    {
        $this->fakeFleet();
        $this->fakeDtc();
        $service = app(DtcGpsService::class);
        $service->getRealtimeData([]);
        $service->getRealtimeData([]); // 1 slot left, but Master is not cached -> needs 2

        $response = app(GpsTripTrackingController::class)->testDtcConnection();
        $payload = $response->getData(true);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('rate_limited_local', $payload['error_info']['code']);
        $this->assertSame(0, $payload['requests_attempted']);
        Http::assertSentCount(2); // nothing new was sent
    }

    public function test_live_snapshot_is_shared_and_calls_dtc_once_per_interval(): void
    {
        $this->fakeDtc();
        config()->set('dtc.sync_interval_minutes', 3);

        $first = app(DtcGpsService::class)->liveSnapshot();
        $second = app(DtcGpsService::class)->liveSnapshot(); // another viewer, same minute

        $this->assertTrue($first['synced_now']);
        $this->assertSame(2, $first['requests_used']);
        $this->assertFalse($second['synced_now']);
        $this->assertSame(0, $second['requests_used']);
        $this->assertSame($first['fetched_at'], $second['fetched_at']);
        $this->assertCount(1, $second['rows']);
        Http::assertSentCount(2);

        $this->travel(181)->seconds();
        $third = app(DtcGpsService::class)->liveSnapshot();
        $this->assertTrue($third['synced_now']);
        $this->assertSame(1, $third['requests_used']); // Vehicle Master still cached
        Http::assertSentCount(3);
    }

    public function test_live_snapshot_keeps_last_data_and_reports_error_when_dtc_fails(): void
    {
        config()->set('dtc.base_url', 'https://gps.example.test:8099/');
        config()->set('dtc.api_token', 'test-token');
        config()->set('dtc.retry_times', 1);
        Http::fake([
            'gps.example.test:8099/getVehicleMaster' => Http::response(['error' => false, 'data' => [
                ['gps_id' => '010399900000001', 'vehicle_name' => '1ฒณ-1307', 'license_plate' => '010399900000001'],
            ]]),
            'gps.example.test:8099/getRealtimeData' => Http::sequence()
                ->push(['error' => false, 'data' => [$this->liveRealtimeRow()]])
                ->push(['error' => true, 'message' => 'server down'], 500),
        ]);
        app(DtcGpsService::class)->liveSnapshot();
        $this->travel(181)->seconds();

        $snapshot = app(DtcGpsService::class)->liveSnapshot();

        $this->assertFalse($snapshot['synced_now']);
        $this->assertSame('dtc_server', $snapshot['error']['code']);
        $this->assertCount(1, $snapshot['rows']); // last good data is still shown
        $this->assertGreaterThanOrEqual(181, $snapshot['age_seconds']);
        $this->assertStringNotContainsString('test-token', json_encode($snapshot));
    }

    public function test_only_admin_can_force_a_sync_through_live_endpoint(): void
    {
        $this->fakeFleet();
        $this->fakeDtc();
        app(DtcGpsService::class)->liveSnapshot();
        Http::assertSentCount(2);

        request()->query->set('force', '1');
        $guest = app(GpsTripTrackingController::class)->live()->getData(true);
        $this->assertFalse($guest['snapshot']['synced_now']);
        Http::assertSentCount(2);

        // Unsaved user (no DB): the app's Gate::before lets superadmins through every ability.
        $admin = (new User())->forceFill(['id' => 99, 'is_superadmin' => true]);
        $this->be($admin);
        $admin = app(GpsTripTrackingController::class)->live()->getData(true);
        $this->assertTrue($admin['snapshot']['synced_now']);
        $this->assertTrue($admin['ok']);
        Http::assertSentCount(3);
    }

    public function test_route_endpoint_returns_sanitized_downsampled_history_and_caches_it(): void
    {
        $this->fakeFleet();
        config()->set('dtc.base_url', 'https://gps.example.test:8099/');
        config()->set('dtc.api_token', 'test-token');
        config()->set('dtc.route_max_points', 100);
        $points = [];
        for ($i = 0; $i < 1000; $i++) {
            $points[] = [
                'time' => now('Asia/Bangkok')->startOfDay()->addSeconds(30 * $i)->format('Y-m-d H:i:s'),
                'gps_speed' => $i % 60, 'status_code' => 30, 'status_name_th' => 'รถวิ่ง', 'status_name_en' => 'running',
                'lat' => 13.5 + $i / 10000, 'lon' => 100.7 + $i / 10000, 'sum_distance' => 1000 + $i / 10, 'heading' => 90,
                'driver_card_id' => 'CARD', 'driver_full_name' => 'SECRET NAME', 'driver_personalcard' => 'ID123',
            ];
        }
        $points[] = ['time' => '2026-01-01 00:00:00', 'lat' => 0, 'lon' => 0]; // no-fix point must be dropped
        Http::fake([
            'gps.example.test:8099/getVehicleMaster' => Http::response(['error' => false, 'data' => [
                ['gps_id' => '010399900000001', 'vehicle_name' => '1ฒณ-1307', 'license_plate' => '010399900000001'],
            ]]),
            'gps.example.test:8099/getHistory' => Http::response(['error' => false, 'gps_id' => '010399900000001', 'truck_name' => '1ฒณ-1307', 'data' => $points]),
        ]);
        app(DtcGpsService::class)->getVehicleMasterCached();

        $first = app(GpsTripTrackingController::class)->route('010399900000001')->getData(true);
        $second = app(GpsTripTrackingController::class)->route('010399900000001')->getData(true);

        $this->assertTrue($first['ok']);
        $route = $first['route'];
        $this->assertSame(1000, $route['point_count']);
        $this->assertLessThanOrEqual(101, count($route['points']));
        $this->assertSame($points[0]['time'], $route['points'][0]['time']);
        $this->assertSame($points[999]['time'], end($route['points'])['time']);
        $this->assertSame(['time', 'lat', 'lon', 'speed', 'status', 'distance'], array_keys($route['points'][0]));
        $this->assertStringNotContainsString('SECRET NAME', json_encode($first, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($second['route']['from_cache']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/getHistory')
            && $request['gps_id'] === '010399900000001'
            && str_ends_with($request['start_period'], '00:00:00'));
        Http::assertSentCount(2); // master + one history call
    }

    public function test_route_endpoint_rejects_gps_ids_not_in_vehicle_master(): void
    {
        $this->fakeFleet();
        $this->fakeDtc();

        $response = app(GpsTripTrackingController::class)->route('999999999999');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('unknown_vehicle', $response->getData(true)['error_info']['code']);
        Http::assertNothingSent();
    }

    public function test_vehicle_master_is_cached_so_next_sync_costs_one_request(): void
    {
        $this->fakeDtc();
        $service = app(DtcGpsService::class);

        $first = $service->getVehicleMasterCached();
        $second = $service->getVehicleMasterCached();
        $refreshed = $service->getVehicleMasterCached(true);

        $this->assertFalse($first['from_cache']);
        $this->assertTrue($second['from_cache']);
        $this->assertFalse($refreshed['from_cache']);
        $this->assertSame('1ฒณ-1307', $second['data'][0]['vehicle_name']);
        $this->assertStringNotContainsString('0800000000', json_encode(Cache::get('dtc_gps.vehicle_master.v1')));
        $this->assertStringNotContainsString('CH-1', json_encode(Cache::get('dtc_gps.vehicle_master.v1')));
        Http::assertSentCount(2);
    }

    public function test_usage_counter_counts_each_request_per_endpoint_and_survives_cache_clear(): void
    {
        $this->fakeDtc();
        $service = app(DtcGpsService::class);

        $service->getVehicleMaster();
        $service->getRealtimeData(['010399900000001']);
        $service->getRealtimeData(['010399900000001']);
        Cache::flush(); // simulates php artisan cache:clear on deploy

        $usage = app(DtcGpsService::class)->usageSummary();

        $this->assertSame(3, $service->requestsSent());
        $this->assertSame(3, $usage['month_total']);
        $this->assertSame(3, $usage['today']);
        $this->assertSame(['getVehicleMaster' => 1, 'getRealtimeData' => 2], $usage['by_endpoint']);
        $this->assertSame(24500, $usage['budget']);
        $this->assertSame(24497, $usage['remaining']);
        Storage::disk('local')->assertExists('dtc-usage/' . now('Asia/Bangkok')->format('Y-m') . '.json');
        $this->assertStringNotContainsString('test-token', Storage::disk('local')->get('dtc-usage/' . now('Asia/Bangkok')->format('Y-m') . '.json'));
    }

    public function test_requests_stop_locally_when_monthly_budget_is_used(): void
    {
        $this->fakeDtc();
        config()->set('dtc.monthly_quota', 3);
        config()->set('dtc.monthly_reserve', 1);
        $service = app(DtcGpsService::class);
        $service->getRealtimeData([]);
        $service->getRealtimeData([]);

        try {
            $service->getRealtimeData([]);
            $this->fail('Expected budget exhaustion.');
        } catch (RuntimeException $exception) {
            $this->assertSame(DtcGpsService::BUDGET_EXHAUSTED_MESSAGE, $exception->getMessage());
            $this->assertSame('budget_exhausted', $service->describeException($exception)['code']);
        }

        Http::assertSentCount(2);
        $this->assertSame(0, $service->usageSummary()['remaining']);
    }

    public function test_http_423_locks_dtc_locally_for_the_rest_of_the_day(): void
    {
        config()->set('dtc.base_url', 'https://gps.example.test:8099/');
        config()->set('dtc.api_token', 'test-token');
        config()->set('dtc.retry_times', 1);
        Http::fake(['gps.example.test:8099/*' => Http::response(['error' => true, 'message' => 'locked'], 423)]);
        $service = app(DtcGpsService::class);

        try {
            $service->getRealtimeData([]);
        } catch (RequestException) {
        }

        try {
            $service->getVehicleMaster();
            $this->fail('Expected local lock.');
        } catch (RuntimeException $exception) {
            $this->assertSame(DtcGpsService::LOCKED_MESSAGE, $exception->getMessage());
            $this->assertSame('quota_locked', $service->describeException($exception)['code']);
        }

        Http::assertSentCount(1);
        $this->assertNotNull($service->usageSummary()['locked_until']);
    }

    public function test_test_endpoint_uses_cached_master_on_second_call(): void
    {
        $this->fakeDtc();
        $this->fakeFleet();

        $first = app(GpsTripTrackingController::class)->testDtcConnection()->getData(true);
        $second = app(GpsTripTrackingController::class)->testDtcConnection()->getData(true);

        $this->assertSame(2, $first['snapshot']['requests_used']);
        $this->assertFalse($first['snapshot']['master_from_cache']);
        $this->assertSame(1, $second['snapshot']['requests_used']);
        $this->assertTrue($second['snapshot']['master_from_cache']);
        $this->assertSame(3, $second['usage']['month_total']);
        Http::assertSentCount(3);
    }
    public function test_realtime_request_uses_official_endpoint_and_batches_gps_ids(): void
    {
        config()->set('dtc.base_url', 'https://gps.example.test:8099/');
        config()->set('dtc.api_token', 'test-token');
        config()->set('dtc.retry_times', 0);

        Http::fake([
            'gps.example.test:8099/getRealtimeData' => Http::response([
                'error' => false,
                'status' => '200',
                'data' => [],
            ]),
        ]);

        app(DtcGpsService::class)->getRealtimeData(['GPS-001', 'GPS-002']);

        Http::assertSent(fn ($request) =>
            $request->url() === 'https://gps.example.test:8099/getRealtimeData'
            && $request['api_token_key'] === 'test-token'
            && $request['gps_list'] === ['GPS-001', 'GPS-002']
        );
    }

    public function test_request_fails_closed_when_token_is_missing(): void
    {
        config()->set('dtc.api_token', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DTC GPS API token is not configured.');

        app(DtcGpsService::class)->getVehicleMaster();
    }

    public function test_realtime_snapshot_keeps_location_but_removes_token_and_driver_identity(): void
    {
        $snapshot = app(DtcGpsService::class)->sanitizeRealtimeResponse([
            'error' => false,
            'status' => '200',
            'message' => 'ok',
            'data' => [[
                'gps_id' => 'GPS-001',
                'truck_name' => '1ฒณ-1307',
                'time' => '2026-11-13 12:00:00',
                'lat' => 13.61,
                'lon' => 100.74,
                'gps_speed' => 42,
                'api_token_key' => 'must-not-be-saved',
                'sim_no' => '0890000000',
                'driver_card_id' => 'secret-card',
                'driver_personalcard' => 'secret-id',
            ]],
        ]);

        $this->assertSame(1, $snapshot['count']);
        $this->assertSame('GPS-001', $snapshot['data'][0]['gps_id']);
        $this->assertSame(13.61, $snapshot['data'][0]['lat']);
        $this->assertArrayNotHasKey('api_token_key', $snapshot['data'][0]);
        $this->assertArrayNotHasKey('sim_no', $snapshot['data'][0]);
        $this->assertArrayNotHasKey('driver_card_id', $snapshot['data'][0]);
        $this->assertArrayNotHasKey('driver_personalcard', $snapshot['data'][0]);
    }

    public function test_raw_exception_detail_redacts_api_token(): void
    {
        config()->set('dtc.api_token', 'super-secret-token');

        $safe = app(DtcGpsService::class)->sanitizeException(
            new RuntimeException('Request failed api_token_key=super-secret-token at vendor')
        );

        $this->assertSame(RuntimeException::class, $safe['type']);
        $this->assertStringContainsString('[REDACTED]', $safe['detail']);
        $this->assertStringNotContainsString('super-secret-token', $safe['detail']);
    }

    public function test_vehicle_master_sanitizer_removes_sim_and_enriches_realtime_plate(): void
    {
        $service = app(DtcGpsService::class);
        $master = $service->sanitizeVehicleMaster(['data' => [[
            'gps_id' => 'GPS-001',
            'vehicle_name' => 'รถส่งสินค้า',
            'license_plate' => '1ฒณ-1307',
            'sim_no' => '0890000000',
        ]]]);
        $rows = $service->enrichRealtimeWithMaster([['gps_id' => 'GPS-001']], $master['data']);

        $this->assertArrayNotHasKey('sim_no', $master['data'][0]);
        $this->assertSame('1ฒณ-1307', $rows[0]['license_plate']);
        $this->assertSame('รถส่งสินค้า', $rows[0]['vehicle_name']);
    }

    public function test_match_report_lists_vehicles_missing_from_each_side(): void
    {
        $report = app(DtcGpsService::class)->matchVehicles(
            [['gps_id' => 'GPS-001', 'license_plate' => 'DTC-ONLY']],
            [['plate_no' => 'FORMDP-ONLY', 'trip_ref' => 'DP-1', 'driver_name' => 'Driver']]
        );

        $this->assertSame(1, $report['missing_in_formdp_count']);
        $this->assertSame(1, $report['missing_in_dtc_count']);
        $this->assertSame(['missing_formdp', 'missing_dtc'], array_column($report['rows'], 'issue'));
    }

    /**
     * Payload shape copied from the DTC manual v1.1 section 3.3 example, including
     * keys with a trailing space ("mileage ", "ecu_rpm ") and driver identity fields.
     */
    private function manualRealtimeRow(array $overrides = []): array
    {
        return array_merge([
            'gps_id' => '144180125834',
            'truck_name' => '70-1231',
            'time' => '2019-09-16 12:37:30',
            'gps_speed' => 45,
            'status_code' => 30,
            'status_name_th' => 'รถวิ่ง',
            'status_name_en' => 'running',
            'lat' => 13.568633,
            'lon' => 100.90025,
            'mileage ' => 134098.3,
            'heading' => 134,
            'io_ch' => '10000000',
            'ecu_rpm ' => 2000,
            'car_volt' => 12.234,
            'driver_card_id' => '6007643410300255633',
            'driver_full_name' => 'MR.TEST DRIVER',
            'driver_personalcard' => '3410300252456',
            'province_en' => 'Samut Prakarn',
            'station_code ' => 'ST-01223',
        ], $overrides);
    }

    public function test_realtime_manual_field_names_map_mileage_and_strip_driver_identity(): void
    {
        config()->set('dtc.base_url', 'https://gps.example.test:8099/');
        config()->set('dtc.api_token', 'test-token');
        config()->set('dtc.retry_times', 1);
        Http::preventStrayRequests();
        Http::fake([
            'gps.example.test:8099/getRealtimeData' => Http::response([
                'error' => false,
                'status' => '200',
                'message' => 'ok',
                'count' => 1,
                'data' => [$this->manualRealtimeRow()],
            ]),
        ]);

        $service = app(DtcGpsService::class);
        $snapshot = $service->sanitizeRealtimeResponse($service->getRealtimeData(['144180125834']));
        $row = $snapshot['data'][0];

        $this->assertSame('144180125834', $row['gps_id']);
        $this->assertSame('70-1231', $row['truck_name']);
        $this->assertSame('2019-09-16 12:37:30', $row['time']);
        $this->assertSame(13.568633, $row['lat']);
        $this->assertSame(100.90025, $row['lon']);
        $this->assertSame(45, $row['gps_speed']);
        $this->assertSame(30, $row['status_code']);
        $this->assertSame('รถวิ่ง', $row['status_name_th']);
        $this->assertSame(134, $row['heading']);
        $this->assertSame(134098.3, $row['gps_mileage']);
        foreach (['mileage', 'mileage ', 'driver_card_id', 'driver_full_name', 'driver_personalcard', 'io_ch', 'ecu_rpm', 'station_code', 'province_en', 'api_token_key'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row);
        }
        Http::assertSentCount(1);
    }

    public function test_realtime_prefers_gps_mileage_and_nulls_non_numeric_coordinates(): void
    {
        $snapshot = app(DtcGpsService::class)->sanitizeRealtimeResponse(['data' => [
            $this->manualRealtimeRow(['gps_mileage' => 500.5, 'lat' => '13.55', 'lon' => 'n/a']),
        ]]);

        $this->assertSame(500.5, $snapshot['data'][0]['gps_mileage']);
        $this->assertSame(13.55, $snapshot['data'][0]['lat']);
        $this->assertNull($snapshot['data'][0]['lon']);
    }

    public function test_vehicle_master_manual_fields_and_gps_ids_for_realtime(): void
    {
        config()->set('dtc.base_url', 'https://gps.example.test:8099/');
        config()->set('dtc.api_token', 'test-token');
        Http::preventStrayRequests();
        Http::fake([
            'gps.example.test:8099/getVehicleMaster' => Http::response([
                'error' => false,
                'status' => '200',
                'message' => 'ok',
                'count' => 2,
                'data' => [
                    ['gps_id' => '144180125834', 'vehicle_name' => '10.313', 'license_plate' => '70-1342', 'sim_no' => '0934234232'],
                    ['gps_id' => 144180125835, 'vehicle_name' => 'รถ 2', 'license_plate' => '', 'sim_no' => '0934234233'],
                ],
            ]),
        ]);

        $service = app(DtcGpsService::class);
        $master = $service->sanitizeVehicleMaster($service->getVehicleMaster());

        $this->assertSame(2, $master['count']);
        $this->assertArrayNotHasKey('sim_no', $master['data'][0]);
        $this->assertSame('70-1342', $master['data'][0]['license_plate']);
        $this->assertSame(['144180125834', '144180125835'], $service->gpsIdsFromMaster($master['data']));
        Http::assertSent(fn ($request) => $request->url() === 'https://gps.example.test:8099/getVehicleMaster'
            && $request['api_token_key'] === 'test-token'
            && !isset($request['gps_list']));
    }

    public function test_history_request_uses_single_gps_id_per_manual(): void
    {
        config()->set('dtc.base_url', 'https://gps.example.test:8099/');
        config()->set('dtc.api_token', 'test-token');
        Http::preventStrayRequests();
        Http::fake(['gps.example.test:8099/getHistory' => Http::response(['error' => false, 'status' => '200', 'data' => []])]);

        app(DtcGpsService::class)->getHistory('144180125834', '2019-05-15 12:37:30', '2019-05-16 00:00:00');

        Http::assertSent(fn ($request) => $request->url() === 'https://gps.example.test:8099/getHistory'
            && $request['gps_id'] === '144180125834'
            && $request['start_period'] === '2019-05-15 12:37:30'
            && $request['end_period'] === '2019-05-16 00:00:00'
            && !isset($request['gps_list']));
    }

    public function test_match_falls_back_to_truck_name_when_master_plate_is_empty(): void
    {
        $service = app(DtcGpsService::class);
        $rows = $service->enrichRealtimeWithMaster(
            [$this->manualRealtimeRow(['gps_id' => 'GPS-9', 'truck_name' => '1ฒณ-1307'])],
            [['gps_id' => 'GPS-9', 'vehicle_name' => '', 'license_plate' => '']]
        );
        $report = $service->matchVehicles($rows, [['plate_no' => '1ฒณ1307', 'trip_ref' => 'T1', 'driver_name' => 'D1']]);

        $this->assertSame(1, $report['matched_count']);
        $this->assertSame('1ฒณ1307', $report['rows'][0]['plate_no']);
        $this->assertTrue($report['rows'][0]['has_position']);
    }

    public function test_master_plate_wins_over_realtime_truck_name(): void
    {
        $service = app(DtcGpsService::class);
        $rows = $service->enrichRealtimeWithMaster(
            [$this->manualRealtimeRow(['gps_id' => 'GPS-1', 'truck_name' => 'ชื่อเล่นรถ'])],
            [['gps_id' => 'GPS-1', 'vehicle_name' => 'x', 'license_plate' => '83-5505']]
        );
        $report = $service->matchVehicles($rows, [['plate_no' => '835505', 'trip_ref' => 'T', 'driver_name' => 'D']]);

        $this->assertSame('83-5505', $report['rows'][0]['dtc_name']);
        $this->assertTrue($report['rows'][0]['matched']);
    }

    /**
     * Shape observed from the live DTC API on 2026-11-20 (values are fake):
     * Master license_plate == gps_id (device id), real plate in vehicle_name;
     * realtime keys have no trailing spaces, "mileage" (not gps_mileage), plus undocumented fields.
     */
    public function test_live_contract_resolves_plate_from_vehicle_name_when_license_plate_is_device_id(): void
    {
        config()->set('dtc.base_url', 'https://gps.example.test:8099/');
        config()->set('dtc.api_token', 'test-token');
        Http::preventStrayRequests();
        Http::fake([
            'gps.example.test:8099/getVehicleMaster' => Http::response(['error' => false, 'status' => '200', 'message' => 'ok', 'count' => 2, 'data' => [
                ['gps_id' => '010399900000001', 'vehicle_name' => '1ฒณ-1307', 'license_plate' => '010399900000001', 'sim_no' => '0800000000', 'chassis_no' => 'CHASSIS-SECRET'],
                ['gps_id' => 'c01000399900000002', 'vehicle_name' => '1ฒห-9999', 'license_plate' => 'c01000399900000002', 'sim_no' => '0800000001', 'chassis_no' => 'CHASSIS-2'],
            ]]),
            'gps.example.test:8099/getRealtimeData' => Http::response(['error' => false, 'status' => '200', 'message' => 'ok', 'count' => 2, 'data' => [
                $this->liveRealtimeRow(['gps_id' => '010399900000001', 'truck_name' => '1ฒณ-1307', 'status_code' => 31, 'status_name_th' => 'จอดไม่ดับเครื่อง', 'status_name_en' => 'Idle']),
                $this->liveRealtimeRow(['gps_id' => 'c01000399900000002', 'truck_name' => '1ฒห-9999', 'r_oil' => '']),
            ]]),
        ]);

        $service = app(DtcGpsService::class);
        $master = $service->sanitizeVehicleMaster($service->getVehicleMaster());
        $snapshot = $service->sanitizeRealtimeResponse($service->getRealtimeData($service->gpsIdsFromMaster($master['data'])));
        $rows = $service->enrichRealtimeWithMaster($snapshot['data'], $master['data']);
        $report = $service->matchVehicles($rows, [['plate_no' => '1ฒณ1307', 'trip_ref' => 'T1', 'driver_name' => 'D1']]);

        $this->assertArrayNotHasKey('chassis_no', $master['data'][0]);
        $this->assertSame('1ฒณ-1307', $rows[0]['license_plate']);
        $this->assertSame('master_vehicle_name', $rows[0]['plate_source']);
        $this->assertSame(208374.4, $rows[0]['gps_mileage']);
        $this->assertSame(31, $rows[0]['status_code']);
        $this->assertSame('Idle', $rows[0]['status_name_en']);
        foreach (['truck_license', 'truck_type_name', 'hourmeter', 'oil', 'r_oil', 'station_id', 'station_name', 'driver_full_name', 'driver_card_id', 'driver_personalcard', 'ecu_rpm'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $rows[0]);
        }

        $this->assertSame(1, $report['matched_count']);
        $this->assertSame('1ฒณ-1307', $report['rows'][0]['dtc_name']);
        $this->assertSame('missing_formdp', $report['rows'][1]['issue']);
        $this->assertSame('1ฒห-9999', $report['rows'][1]['dtc_name']);
        Http::assertSentCount(2);
    }

    public function test_plate_resolution_order_and_device_id_detection(): void
    {
        $service = app(DtcGpsService::class);

        $this->assertSame(['70-1342', 'master_license_plate'], $service->resolvePlate(['gps_id' => 'G1', 'license_plate' => '70-1342', 'vehicle_name' => 'x', 'truck_name' => 'y']));
        $this->assertSame(['83-5505', 'master_vehicle_name'], $service->resolvePlate(['gps_id' => '010399900000003', 'license_plate' => '010399900000003', 'vehicle_name' => '83-5505']));
        $this->assertSame(['84-0953', 'realtime_truck_name'], $service->resolvePlate(['gps_id' => 'G4', 'license_plate' => '', 'vehicle_name' => '', 'truck_name' => '84-0953']));
        $this->assertSame(['', 'none'], $service->resolvePlate(['gps_id' => 'c01000399900000002', 'license_plate' => 'c01000399900000002']));
    }

    private function liveRealtimeRow(array $overrides = []): array
    {
        return array_merge([
            'gps_id' => '010399900000001', 'truck_name' => '1ฒณ-1307', 'truck_license' => 'X', 'truck_type_name' => 'Truck',
            'time' => '2026-11-20 08:25:51', 'gps_speed' => 0, 'status_code' => 33, 'status_name_th' => 'รถจอด', 'status_name_en' => 'Stop',
            'lat' => 13.546184, 'lon' => 100.787285, 'heading' => 112, 'mileage' => 208374.4, 'hourmeter' => '0', 'oil' => '0', 'r_oil' => 0,
            'analog1_value' => 0, 'io_ch' => '0', 'ecu_rpm' => 0, 'car_volt' => 12.1, 'batt_volt' => 4.1,
            'driver_card_id' => 'CARD', 'driver_full_name' => 'NAME', 'driver_personalcard' => 'ID',
            'station_id' => '1', 'station_name' => 'S', 'province_th' => 'สมุทรปราการ',
        ], $overrides);
    }

    public function test_test_endpoint_uses_two_requests_and_sends_master_ids_to_realtime(): void
    {
        config()->set('dtc.base_url', 'https://gps.example.test:8099/');
        config()->set('dtc.api_token', 'test-token');
        Storage::fake('local');
        $this->fakeFleet();
        Http::preventStrayRequests();
        Http::fake([
            'gps.example.test:8099/getVehicleMaster' => Http::response(['error' => false, 'status' => '200', 'data' => [
                ['gps_id' => '144180125834', 'vehicle_name' => 'v', 'license_plate' => '1ฒณ-1307', 'sim_no' => '0934234232'],
            ]]),
            'gps.example.test:8099/getRealtimeData' => Http::response(['error' => false, 'status' => '200', 'data' => [
                $this->manualRealtimeRow(['gps_id' => '144180125834', 'truck_name' => 'other']),
            ]]),
        ]);

        $response = app(GpsTripTrackingController::class)->testDtcConnection();
        $payload = $response->getData(true);

        $this->assertTrue($payload['ok']);
        $this->assertSame(2, $payload['snapshot']['requests_used']);
        $this->assertSame('1ฒณ-1307', $payload['snapshot']['rows'][0]['license_plate']);
        $this->assertSame(1, $payload['match_report']['matched_count']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/getRealtimeData')
            && $request['gps_list'] === ['144180125834']);

        // Shared snapshot in cache must not hold secrets or driver identity.
        $cached = json_encode(Cache::get('dtc_gps.live_snapshot.v1'), JSON_UNESCAPED_UNICODE) . json_encode($payload, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('test-token', $cached);
        $this->assertStringNotContainsString('0934234232', $cached);
        $this->assertStringNotContainsString('MR.TEST DRIVER', $cached);
        $this->assertStringNotContainsString('3410300252456', $cached);
    }

    public function test_test_endpoint_reports_attempted_requests_on_failure(): void
    {
        config()->set('dtc.base_url', 'https://gps.example.test:8099/');
        config()->set('dtc.api_token', 'test-token');
        config()->set('dtc.retry_times', 1);
        Storage::fake('local');
        $this->fakeFleet();
        Http::preventStrayRequests();
        Http::fake(['gps.example.test:8099/getVehicleMaster' => Http::response(['error' => true, 'message' => 'bad'], 429)]);

        $response = app(GpsTripTrackingController::class)->testDtcConnection();
        $payload = $response->getData(true);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertFalse($payload['ok']);
        $this->assertSame('rate_limited', $payload['error_info']['code']);
        $this->assertSame(1, $payload['requests_attempted']);
        $this->assertStringNotContainsString('test-token', json_encode($payload));
        Http::assertSentCount(1);
    }

    public function test_dtc_error_true_with_http_200_is_rejected(): void
    {
        config()->set('dtc.base_url', 'https://gps.example.test:8099/');
        config()->set('dtc.api_token', 'test-token');
        Http::preventStrayRequests();
        Http::fake(['gps.example.test:8099/getVehicleMaster' => Http::response(['error' => true, 'message' => 'invalid token'])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid token');

        app(DtcGpsService::class)->getVehicleMaster();
    }

    public function test_http_423_is_described_as_quota_error(): void
    {
        config()->set('dtc.base_url', 'https://gps.example.test:8099/');
        config()->set('dtc.api_token', 'test-token');
        config()->set('dtc.retry_times', 0);
        Http::fake(['gps.example.test:8099/getVehicleMaster' => Http::response([], 423)]);

        try {
            app(DtcGpsService::class)->getVehicleMaster();
            $this->fail('Expected RequestException was not thrown.');
        } catch (RequestException $exception) {
            $description = app(DtcGpsService::class)->describeException($exception);
            $this->assertSame('quota_locked', $description['code']);
            $this->assertSame(423, $description['http_status']);
            $this->assertFalse($description['retryable']);
        }
    }
}
