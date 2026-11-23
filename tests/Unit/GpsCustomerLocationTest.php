<?php

namespace Tests\Unit;

use App\Http\Controllers\FormDP\GpsCustomerLocationController;
use App\Http\Controllers\FormDP\GpsTripTrackingController;
use App\Services\FormDP\DtcGpsService;
use App\Services\FormDP\GpsCustomerLocationService;
use App\Services\FormDP\GpsTripQueueService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * GPS Phase 1 (truck <-> GPS id, customer locations). No real DB, no real DTC.
 */
class GpsCustomerLocationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
        Cache::put('dtc_gps.schema.location_tables', false, now()->addHour());
        Cache::put('dtc_gps.schema.truck_gps_column', false, now()->addHour());
        Http::preventStrayRequests();
    }

    public function test_trucks_linked_by_gps_id_match_by_id_not_by_plate(): void
    {
        $report = app(DtcGpsService::class)->matchVehicles(
            [
                ['gps_id' => 'G1', 'license_plate' => 'G1', 'vehicle_name' => 'ชื่อในDTCไม่ตรง', 'truck_name' => 'x', 'lat' => 13.5, 'lon' => 100.7],
                ['gps_id' => 'G2', 'license_plate' => 'G2', 'vehicle_name' => '83-5505', 'lat' => 13.6, 'lon' => 100.8],
            ],
            [
                ['plate_no' => '1ฒณ1307', 'dtc_gps_id' => 'G1', 'trip_ref' => 'T1', 'driver_name' => 'A'],
                // Linked to another device: must NOT be matched by plate to G2.
                ['plate_no' => '83-5505', 'dtc_gps_id' => 'G9', 'trip_ref' => 'T2', 'driver_name' => 'B'],
            ]
        );
        $rows = collect($report['rows']);

        $this->assertSame(1, $report['matched_count']);
        $this->assertSame('1ฒณ1307', $rows->firstWhere('gps_id', 'G1')['plate_no']);
        $this->assertSame('gps_id', $rows->firstWhere('gps_id', 'G1')['match_by']);
        $this->assertSame('missing_formdp', $rows->firstWhere('gps_id', 'G2')['issue']);
        $this->assertSame('missing_dtc', $rows->firstWhere('plate_no', '83-5505')['issue']);
    }

    public function test_unlinked_trucks_still_match_by_plate(): void
    {
        $report = app(DtcGpsService::class)->matchVehicles(
            [['gps_id' => 'G2', 'license_plate' => 'G2', 'vehicle_name' => '83-5505']],
            [['plate_no' => '835505', 'dtc_gps_id' => '', 'trip_ref' => 'T', 'driver_name' => 'D']]
        );

        $this->assertSame(1, $report['matched_count']);
        $this->assertSame('plate', $report['rows'][0]['match_by']);
    }

    public function test_poi_response_is_sanitized(): void
    {
        $pois = app(DtcGpsService::class)->sanitizePoiResponse(['data' => [
            ['poi_id' => 'ST-1', 'poi_name' => ' ลูกค้า A ', 'poi_type' => 'C', 'location_info' => 'บางพลี', 'area_m' => 100, 'lat' => 13.6, 'lon' => 100.7, 'geo_polygon' => 'POLYGON(...)', 'remark' => 'secret note'],
            ['poi_id' => 'ST-1', 'poi_name' => 'duplicate', 'lat' => 13.6, 'lon' => 100.7],
            ['poi_id' => 'ST-2', 'poi_name' => 'no fix', 'lat' => 0, 'lon' => 0],
            ['poi_id' => '', 'poi_name' => 'no id', 'lat' => 13.6, 'lon' => 100.7],
            ['poi_id' => 'ST-3', 'poi_name' => 'bad', 'lat' => 'x', 'lon' => 100.7],
        ]]);

        $this->assertCount(1, $pois);
        $this->assertSame(['poi_id', 'poi_name', 'location_info', 'poi_type', 'lat', 'lon', 'area_m'], array_keys($pois[0]));
        $this->assertSame('ลูกค้า A', $pois[0]['poi_name']);
        $this->assertStringNotContainsString('secret note', json_encode($pois, JSON_UNESCAPED_UNICODE));
    }

    public function test_get_poi_list_calls_dtc_once_through_the_guards(): void
    {
        config()->set('dtc.base_url', 'https://gps.example.test:8099/');
        config()->set('dtc.api_token', 'test-token');
        Http::fake(['gps.example.test:8099/getPOI' => Http::response(['error' => false, 'data' => [
            ['poi_id' => 'ST-1', 'poi_name' => 'A', 'lat' => 13.6, 'lon' => 100.7, 'area_m' => 100],
        ]])]);

        $service = app(DtcGpsService::class);
        $pois = $service->getPoiList();

        $this->assertCount(1, $pois);
        $this->assertSame(1, $service->requestsSent());
        $this->assertSame(['getPOI' => 1], $service->usageSummary()['by_endpoint']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/getPOI') && $request['api_token_key'] === 'test-token');
    }

    public function test_name_normalization_and_address_key(): void
    {
        $service = app(GpsCustomerLocationService::class);

        $this->assertSame($service->normalizeName('บริษัท เอบีซี สตีล จำกัด (มหาชน)'), $service->normalizeName('เอบีซี สตีล'));
        $this->assertSame($service->normalizeName('ABC Steel Co., Ltd.'), $service->normalizeName('abc steel'));
        $this->assertSame('', $service->addressKey('   '));
        $this->assertSame($service->addressKey('12 ถ.สุขุมวิท  บางพลี'), $service->addressKey(' 12 ถ.สุขุมวิท บางพลี '));
        $this->assertSame(40, strlen($service->addressKey('12 ถ.สุขุมวิท')));
    }

    public function test_poi_suggestions_rank_similar_names_first(): void
    {
        Cache::put('dtc_gps.schema.location_tables', true, now()->addHour());
        Cache::put('dtc_gps.poi_rows.v1', [
            ['poi_id' => 'P1', 'poi_name' => 'บจก. เอบีซี สตีล', 'location_info' => '', 'lat' => 13.6, 'lon' => 100.7, 'area_m' => 100],
            ['poi_id' => 'P2', 'poi_name' => 'เอบีซี สตีล สาขาบางนา', 'location_info' => '', 'lat' => 13.7, 'lon' => 100.6, 'area_m' => 150],
            ['poi_id' => 'P3', 'poi_name' => 'ร้านอื่นไม่เกี่ยว', 'location_info' => '', 'lat' => 13.8, 'lon' => 100.5, 'area_m' => 100],
        ], now()->addHour());

        $suggestions = app(GpsCustomerLocationService::class)->suggestPois('บริษัท เอบีซี สตีล จำกัด');

        $this->assertSame('P1', $suggestions[0]['poi_id']);
        $this->assertSame(100, $suggestions[0]['score']);
        $this->assertNotContains('P3', array_column($suggestions, 'poi_id'));
    }

    public function test_store_rejects_invalid_coordinates_before_touching_the_database(): void
    {
        $this->expectException(ValidationException::class);

        app(GpsCustomerLocationController::class)->store(Request::create('/dp/gps-locations', 'POST', [
            'company' => 'MSW', 'customer_id' => 10, 'lat' => 120, 'lon' => 100.5, 'radius_m' => 150, 'source' => 'manual',
        ]));
    }

    public function test_store_requires_poi_id_when_source_is_dtc_poi(): void
    {
        try {
            app(GpsCustomerLocationController::class)->store(Request::create('/dp/gps-locations', 'POST', [
                'company' => 'MSW', 'customer_id' => 10, 'lat' => 13.6, 'lon' => 100.5, 'radius_m' => 150, 'source' => 'dtc_poi',
            ]));
            $this->fail('Expected validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dtc_poi_id', $exception->errors());
        }
    }

    public function test_tracking_fleet_attaches_address_location_then_customer_default(): void
    {
        $this->partialMock(GpsTripQueueService::class, function ($mock) {
            $mock->shouldReceive('activeTrucks')->andReturn(collect([
                ['truck_id' => 12, 'plate_no' => '83-5505', 'plate_key' => '835505', 'driver_name' => 'D', 'dtc_gps_id' => 'G2'],
            ]));
            $mock->shouldReceive('forDate')->andReturn(collect([
                ['plate_key' => '835505', 'plate_no' => '83-5505', 'trip_no' => 1, 'truck_id' => 12, 'driver_name' => 'D', 'stops' => ['A', 'B'], 'deliveries' => [
                    ['customer' => 'A', 'company' => 'MSW', 'customer_id' => 10, 'address' => 'โกดัง 2', 'items' => []],
                    ['customer' => 'B', 'company' => 'MSW', 'customer_id' => 20, 'address' => 'ที่อื่น', 'items' => []],
                    ['customer' => 'C', 'company' => 'MSW', 'customer_id' => 30, 'address' => '', 'items' => []],
                ]],
            ]));
        });
        $locationService = app(GpsCustomerLocationService::class);
        $warehouseKey = $locationService->addressKey('โกดัง 2');
        $this->partialMock(GpsCustomerLocationService::class, function ($mock) use ($warehouseKey) {
            $mock->shouldReceive('locationsForDeliveries')->andReturn([
                'MSW|10' => [
                    '' => ['lat' => 13.1, 'lon' => 100.1, 'radius_m' => 150, 'label' => 'default A'],
                    $warehouseKey => ['lat' => 13.2, 'lon' => 100.2, 'radius_m' => 200, 'label' => 'warehouse 2'],
                ],
                'MSW|20' => ['' => ['lat' => 13.3, 'lon' => 100.3, 'radius_m' => 120, 'label' => null]],
            ]);
        });
        View::share('errors', new ViewErrorBag());

        $data = app(GpsTripTrackingController::class)->index()->getData();
        $deliveries = $data['vehicles'][0]['deliveries'];

        $this->assertSame('G2', $data['vehicles'][0]['dtc_gps_id']);
        $this->assertSame('warehouse 2', $deliveries[0]['location']['label']); // address-specific wins
        $this->assertSame(13.3, $deliveries[1]['location']['lat']);           // customer default
        $this->assertNull($deliveries[2]['location']);                         // not pinned yet
        Http::assertNothingSent();
    }

    public function test_location_page_renders_setup_notice_when_sql_not_run(): void
    {
        View::share('errors', new ViewErrorBag());

        $html = view('formdp.gps-locations.index', [
            'tablesReady' => false,
            'customers' => [],
            'poiStats' => ['count' => 0, 'synced_at' => null],
            'canEdit' => true,
            'defaultRadius' => 150,
        ])->render();

        $this->assertStringContainsString('formdp_gps_phase1_locations.sql', $html);
        $this->assertStringContainsString('id="gloc-map"', $html);
        $this->assertStringContainsString('gloc-schema-check', $html);
        $this->assertStringNotContainsString('id="gloc-import"', $html); // import hidden until tables exist
    }
}
