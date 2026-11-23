<?php

namespace Tests\Unit;

use App\Services\FormDP\DtcGpsService;
use App\Services\FormDP\GpsCustomerLocationService;
use App\Services\FormDP\GpsPlaceResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Place name for a stopped truck: company → customer pin → DTC station → DTC POI → area.
 * No real DB (location service mocked), no DTC call.
 */
class GpsPlaceResolverTest extends TestCase
{
    private const CUSTOMER = [13.700000, 100.900000];
    private const POI = [13.800000, 100.600000];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
        Http::preventStrayRequests();
        config()->set('dtc.longdo_key', ''); // never use a real key from .env in tests
        $this->partialMock(GpsCustomerLocationService::class, function ($mock) {
            $mock->shouldReceive('tablesReady')->andReturn(true);
            $mock->shouldReceive('activePins')->andReturn([
                ['name' => 'ลูกค้า A', 'label' => 'โกดัง A', 'lat' => self::CUSTOMER[0], 'lon' => self::CUSTOMER[1], 'radius_m' => 150],
            ]);
            $mock->shouldReceive('poiRows')->andReturn([
                ['poi_id' => 'P1', 'poi_name' => 'ปั๊มน้ำมัน X', 'location_info' => '', 'lat' => self::POI[0], 'lon' => self::POI[1], 'area_m' => 30],
            ]);
        });
    }

    private function row(float $lat, float $lon, array $extra = []): array
    {
        return ['gps_id' => 'G1', 'lat' => $lat, 'lon' => $lon, 'gps_speed' => 0,
            'sub_district_th' => 'บางพลีใหญ่', 'district_th' => 'อำเภอบางพลี', 'province_th' => 'สมุทรปราการ', ...$extra];
    }

    public function test_resolution_order_company_customer_station_poi_area(): void
    {
        $resolver = app(GpsPlaceResolver::class);

        $company = $resolver->resolve($this->row(GpsPlaceResolver::COMPANY[0] + 0.001, GpsPlaceResolver::COMPANY[1]));
        $this->assertSame('company', $company['kind']);

        // ~55 m from the customer pin: customer wins even when DTC reports a station.
        $customer = $resolver->resolve($this->row(self::CUSTOMER[0] + 0.0005, self::CUSTOMER[1], ['station_name' => 'จุด DTC อื่น']));
        $this->assertSame(['customer', 'ลูกค้า A'], [$customer['kind'], $customer['label']]);
        $this->assertSame('ต.บางพลีใหญ่ อ.บางพลี จ.สมุทรปราการ', $customer['detail']);

        $station = $resolver->resolve($this->row(13.9, 100.95, ['station_name' => 'ลานจอดรถบรรทุก']));
        $this->assertSame(['station', 'ลานจอดรถบรรทุก'], [$station['kind'], $station['label']]);

        // ~150 m from a POI whose own area is 30 m: still matched (minimum 200 m).
        $poi = $resolver->resolve($this->row(self::POI[0] + 0.00135, self::POI[1], ['station_name' => '-']));
        $this->assertSame(['poi', 'ปั๊มน้ำมัน X'], [$poi['kind'], $poi['label']]);

        // ~1.1 km from the POI: not "at" it, but named as the closest known place.
        $near = $resolver->resolve($this->row(self::POI[0] + 0.01, self::POI[1]));
        $this->assertSame(['near', 'ปั๊มน้ำมัน X'], [$near['kind'], $near['label']]);
        $this->assertEqualsWithDelta(1112, $near['distance_m'], 5);
        $this->assertSame('ต.บางพลีใหญ่ อ.บางพลี จ.สมุทรปราการ', $near['detail']);

        $area = $resolver->resolve($this->row(14.2, 101.2));
        $this->assertSame(['area', 'ต.บางพลีใหญ่ อ.บางพลี จ.สมุทรปราการ'], [$area['kind'], $area['label']]);

        $this->assertNull($resolver->resolve(['lat' => 14.2, 'lon' => 101.2]));       // no area fields
        $this->assertNull($resolver->resolve(['lat' => 0, 'lon' => 0]));             // no fix
        Http::assertNothingSent();
    }

    private function fakeLongdo(): void
    {
        config()->set('dtc.longdo_key', 'test-longdo-key');
        Http::fake([
            'api.longdo.com/POIService/json/search*' => Http::response(['meta' => ['hasmore' => false], 'data' => [
                ['type' => 'poi', 'id' => 'A1', 'name' => 'ปั๊มน้ำมัน ไกล', 'lat' => 14.21, 'lon' => 101.2, 'distance' => '320 m'],
                ['type' => 'poi', 'id' => 'A2', 'name' => 'เซเว่นอีเลฟเว่น สาขาทดสอบ', 'lat' => 14.2005, 'lon' => 101.2, 'distance' => '56 m'],
                ['type' => 'poi', 'id' => 'A3', 'name' => 'ร้านปิดแล้ว', 'lat' => 14.2, 'lon' => 101.2, 'distance' => '5 m', 'obsoleted' => true],
            ]]),
            'api.longdo.com/map/services/address*' => Http::response(['geocode' => '110203', 'province' => 'สมุทรปราการ', 'road' => 'ถนนเทพารักษ์']),
        ]);
    }

    public function test_stopped_truck_gets_longdo_nearby_place_and_road_once_per_spot(): void
    {
        $this->fakeLongdo();
        $resolver = app(GpsPlaceResolver::class);

        $place = $resolver->resolve($this->row(14.2, 101.2));                       // far from our own data
        $this->assertSame(['near', 'เซเว่นอีเลฟเว่น สาขาทดสอบ', 56], [$place['kind'], $place['label'], $place['distance_m']]);
        $this->assertSame('ถนนเทพารักษ์ · ต.บางพลีใหญ่ อ.บางพลี จ.สมุทรปราการ', $place['detail']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/POIService/json/search') && $request['key'] === 'test-longdo-key' && $request['span'] === '500m');
        Http::assertSentCount(2);

        app(GpsPlaceResolver::class)->resolve($this->row(14.20001, 101.20001));     // same ~11 m spot → cache
        Http::assertSentCount(2);

        app(GpsPlaceResolver::class)->resolve($this->row(14.3, 101.3, ['gps_speed' => 45]));   // moving → never asked
        Http::assertSentCount(2);
    }

    public function test_longdo_is_off_without_key_and_respects_daily_cap(): void
    {
        $this->assertSame('area', app(GpsPlaceResolver::class)->resolve($this->row(14.2, 101.2))['kind']);
        Http::assertNothingSent();                                                     // no key → no request

        $this->fakeLongdo();
        config()->set('dtc.longdo_daily_limit', 3);                                   // room for one spot (2 requests)
        app(GpsPlaceResolver::class)->resolve($this->row(14.2, 101.2));
        $capped = app(GpsPlaceResolver::class)->resolve($this->row(14.5, 101.5));
        Http::assertSentCount(2);
        $this->assertSame('area', $capped['kind']);                                   // falls back to DTC area
    }

    public function test_longdo_usage_is_counted_per_calendar_month_cycle(): void
    {
        $this->fakeLongdo();
        config()->set('dtc.longdo_monthly_limit', 2);
        $longdo = app(\App\Services\FormDP\LongdoPlaceService::class);

        $this->assertNotNull($longdo->lookup(14.2, 101.2));          // 2 requests: cycle now full
        $this->assertNull($longdo->lookup(14.6, 101.6));             // monthly cap → no request
        Http::assertSentCount(2);

        $usage = $longdo->usageSummary();
        $this->assertSame(2, $usage['month_total']);
        $this->assertSame(now('Asia/Bangkok')->startOfMonth()->toDateString(), $usage['cycle_start']);
        $this->assertSame(now('Asia/Bangkok')->endOfMonth()->toDateString(), $usage['cycle_end']);
        Storage::disk('local')->assertExists('longdo-usage/' . now('Asia/Bangkok')->format('Y-m') . '.json');
    }

    public function test_bangkok_uses_khwaeng_and_khet(): void
    {
        $text = app(GpsPlaceResolver::class)->areaText(['sub_district_th' => 'แขวงบางนาเหนือ', 'district_th' => 'เขตบางนา', 'province_th' => 'กรุงเทพมหานคร']);

        $this->assertSame('แขวงบางนาเหนือ เขตบางนา จ.กรุงเทพมหานคร', $text);
    }

    public function test_realtime_sanitizer_keeps_place_fields_but_not_driver_data(): void
    {
        $rows = app(DtcGpsService::class)->sanitizeRealtimeResponse(['data' => [[
            'gps_id' => '010399900000001', 'lat' => 13.6, 'lon' => 100.7, 'time' => '2026-11-23 09:00:00',
            'station_name' => 'ลานจอด', 'sub_district_th' => 'บางพลีใหญ่', 'district_th' => 'บางพลี', 'province_th' => 'สมุทรปราการ',
            'driver_full_name' => 'SECRET', 'driver_card_id' => '123',
        ]]]);
        $row = $rows['data'][0];

        $this->assertSame('ลานจอด', $row['station_name'] ?? null);
        $this->assertSame('สมุทรปราการ', $row['province_th'] ?? null);
        $this->assertArrayNotHasKey('driver_full_name', $row);
        $this->assertArrayNotHasKey('driver_card_id', $row);
    }
}
