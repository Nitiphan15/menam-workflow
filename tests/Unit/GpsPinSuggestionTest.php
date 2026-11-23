<?php

namespace Tests\Unit;

use App\Http\Controllers\FormDP\GpsCustomerLocationController;
use App\Services\FormDP\GpsCustomerLocationService;
use App\Services\FormDP\GpsPinSuggestionService;
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
 * Suggested pins for customers without one. sqlsrv_menam = in-memory sqlite; Longdo = Http::fake.
 */
class GpsPinSuggestionTest extends TestCase
{
    private const ACME = [13.700000, 100.900000];      // where ACME really is (trucks stayed there)
    private const HUB = [13.550000, 100.660000];       // a spot trucks visit every day (not a customer)

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
        Http::preventStrayRequests();
        config()->set('dtc.longdo_key', '');
        config()->set('database.connections.sqlsrv_menam', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
        DB::purge('sqlsrv_menam');

        $schema = Schema::connection('sqlsrv_menam');
        $schema->create('gps_dtc_pois', function (Blueprint $t) {
            $t->string('poi_id')->primary(); $t->string('poi_name'); $t->string('location_info')->nullable(); $t->string('poi_type')->nullable();
            $t->decimal('lat', 9, 6); $t->decimal('lon', 9, 6); $t->integer('area_m')->nullable(); $t->dateTime('synced_at')->nullable();
        });
        $schema->create('gps_customer_locations', function (Blueprint $t) {
            $t->id(); $t->string('company'); $t->integer('customer_id'); $t->string('address_key')->default(''); $t->string('address_text')->nullable();
            $t->string('label')->nullable(); $t->decimal('lat', 9, 6); $t->decimal('lon', 9, 6); $t->integer('radius_m')->default(150);
            $t->string('source'); $t->string('dtc_poi_id')->nullable(); $t->boolean('is_active')->default(true);
            $t->dateTime('created_at')->nullable(); $t->bigInteger('created_by')->nullable(); $t->dateTime('updated_at')->nullable(); $t->bigInteger('updated_by')->nullable();
        });
        $schema->create('gps_stop_visit', function (Blueprint $t) {
            $t->id(); $t->date('ship_date'); $t->string('plate_key'); $t->string('stop_key'); $t->integer('stop_no')->default(1);
            $t->string('company')->nullable(); $t->integer('customer_id')->nullable(); $t->string('customer_name')->nullable();
            $t->string('window_time')->nullable(); $t->string('status');
        });
        $schema->create('gps_trip_daily', function (Blueprint $t) {
            $t->id(); $t->date('ship_date'); $t->string('plate_key'); $t->boolean('has_route')->default(true);
        });
        $schema->create('gps_trip_stop', function (Blueprint $t) {
            $t->id(); $t->date('ship_date'); $t->string('plate_key'); $t->integer('seq'); $t->string('kind');
            $t->decimal('lat', 9, 6); $t->decimal('lon', 9, 6); $t->string('place_label')->nullable();
        });
        Cache::put('dtc_gps.schema.location_tables', true, now()->addHour());
        Cache::put('dtc_gps.schema.dashboard_tables', true, now()->addHour());
        Cache::put('dtc_gps.schema.timeline_tables', true, now()->addHour());

        $this->partialMock(GpsCustomerLocationService::class, fn ($mock) => $mock->shouldReceive('customerList')->andReturn([
            ['company' => 'MSW', 'customer_id' => 1, 'name' => 'บริษัท แอคมี สตีล จำกัด (สำนักงานใหญ่)', 'line_count' => 30, 'status' => 'suggested'],
            ['company' => 'MSW', 'customer_id' => 2, 'name' => 'บจก. ไม่มีใครรู้จัก', 'line_count' => 10, 'status' => 'missing'],
            ['company' => 'MSW', 'customer_id' => 3, 'name' => 'ปักแล้ว', 'line_count' => 5, 'status' => 'pinned'],
        ]));

        $db = DB::connection('sqlsrv_menam');
        // DTC POI named like customer 1, at the place trucks really stopped
        $db->table('gps_dtc_pois')->insert(['poi_id' => 'P1', 'poi_name' => 'แอคมี สตีล', 'lat' => self::ACME[0], 'lon' => self::ACME[1], 'area_m' => 30]);
        // Queue: customer 1 on 3 truck-days, customer 2 on 2 truck-days; trucks stay at ACME on customer-1 days
        // and at the hub every day.
        $day = 0;
        foreach ([['2026-11-10', 1], ['2026-11-11', 1], ['2026-11-12', 1], ['2026-11-13', 2], ['2026-11-14', 2]] as [$date, $customer]) {
            $db->table('gps_trip_daily')->insert(['ship_date' => $date, 'plate_key' => 'T1', 'has_route' => 1]);
            $db->table('gps_stop_visit')->insert(['ship_date' => $date, 'plate_key' => 'T1', 'stop_key' => "K{$customer}", 'company' => 'MSW', 'customer_id' => $customer, 'customer_name' => "C{$customer}", 'status' => 'no_location']);
            $db->table('gps_trip_stop')->insert(['ship_date' => $date, 'plate_key' => 'T1', 'seq' => 1, 'kind' => 'unknown', 'lat' => self::HUB[0], 'lon' => self::HUB[1], 'place_label' => 'ฮับ']);
            if ($customer === 1) {
                $db->table('gps_trip_stop')->insert(['ship_date' => $date, 'plate_key' => 'T1', 'seq' => 2, 'kind' => 'unknown', 'lat' => self::ACME[0] + 0.0004, 'lon' => self::ACME[1], 'place_label' => null]);
            }
        }
    }

    public function test_days_without_gps_route_are_not_evidence_days(): void
    {
        $db = DB::connection('sqlsrv_menam');
        $db->table('gps_trip_daily')->insert(['ship_date' => '2026-11-15', 'plate_key' => 'NOGPS', 'has_route' => 0]);
        $db->table('gps_stop_visit')->insert(['ship_date' => '2026-11-15', 'plate_key' => 'NOGPS', 'stop_key' => 'K1', 'company' => 'MSW', 'customer_id' => 1, 'customer_name' => 'C1', 'status' => 'no_location']);

        $this->assertSame(3, $this->rows()[1]['queue_days']);
    }

    private function rows(): array
    {
        return collect(app(GpsPinSuggestionService::class)->suggestions())->keyBy('customer_id')->all();
    }

    public function test_name_match_with_truck_stays_is_high_and_hub_is_never_suggested(): void
    {
        Http::fake(); // no request may be sent while listing
        $rows = $this->rows();

        $this->assertArrayNotHasKey(3, $rows);                                     // pinned customers excluded
        $top = $rows[1]['candidates'][0];
        $this->assertSame(['dtc_poi', 'high', 'P1'], [$top['source'], $top['confidence'], $top['dtc_poi_id']]);
        $this->assertSame([3, 3], [$top['stayed_days'], $top['queue_days']]);
        $this->assertCount(1, $rows[1]['candidates']);                             // ACME stop spot merged into the POI
        foreach ($rows[2]['candidates'] as $candidate) {                           // the hub is visited on every day
            $this->assertGreaterThan(1000, abs($candidate['lat'] - self::HUB[0]) * 111000);
        }
        Http::assertNothingSent();
    }

    public function test_repeated_specific_stop_spot_is_suggested_without_any_name(): void
    {
        DB::connection('sqlsrv_menam')->table('gps_dtc_pois')->delete();           // no POI for ACME
        Cache::forget('dtc_gps.poi_rows.v1');

        $top = $this->rows()[1]['candidates'][0];

        $this->assertSame(['stops', 'medium'], [$top['source'], $top['confidence']]);
        $this->assertSame(100, $top['specific_percent']);
        $this->assertEqualsWithDelta(self::ACME[0], $top['lat'], 0.001);
    }

    public function test_longdo_name_search_runs_only_on_request_and_is_cached(): void
    {
        config()->set('dtc.longdo_key', 'test-longdo');
        Http::fake(['search.longdo.com/*' => Http::response(['data' => [
            ['name' => 'บริษัท ไม่มีใครรู้จัก จำกัด', 'lat' => 14.1, 'lon' => 100.6, 'address' => 'นิคมทดสอบ'],
            ['name' => 'ร้านอื่น', 'lat' => 14.2, 'lon' => 100.7],
        ]])]);
        $service = app(GpsPinSuggestionService::class);

        $this->assertFalse(collect($service->suggestions())->firstWhere('customer_id', 2)['longdo_searched']);
        Http::assertNothingSent();

        $result = $service->searchLongdo(['MSW|2']);
        $row = collect($result['rows'])->firstWhere('customer_id', 2);
        $this->assertSame(1, $result['requests']);
        $this->assertTrue($row['longdo_searched']);
        $this->assertSame(['longdo', 'medium'], [$row['candidates'][0]['source'], $row['candidates'][0]['confidence']]); // name ok, no stay there
        Http::assertSent(fn ($r) => str_contains($r->url(), 'mapsearch/json/search') && $r['keyword'] === 'ไม่มีใครรู้จัก');

        app(GpsPinSuggestionService::class)->searchLongdo(['MSW|2']);               // cached
        Http::assertSentCount(1);
    }

    public function test_save_suggested_pins_never_overwrites_and_marks_origin(): void
    {
        $db = DB::connection('sqlsrv_menam');
        $db->table('gps_customer_locations')->insert(['company' => 'MSW', 'customer_id' => 9, 'address_key' => '', 'label' => 'เดิม', 'lat' => 13.1, 'lon' => 100.1, 'radius_m' => 150, 'source' => 'manual', 'is_active' => 1]);

        $result = app(GpsCustomerLocationService::class)->saveSuggestedPins([
            ['company' => 'MSW', 'customer_id' => 1, 'lat' => 13.7, 'lon' => 100.9, 'label' => 'แอคมี สตีล', 'source' => 'dtc_poi', 'dtc_poi_id' => 'P1', 'radius_m' => 30],
            ['company' => 'MSW', 'customer_id' => 2, 'lat' => 14.1, 'lon' => 100.6, 'label' => 'บริษัท ไม่มีใครรู้จัก', 'source' => 'longdo', 'dtc_poi_id' => null, 'radius_m' => null],
            ['company' => 'MSW', 'customer_id' => 9, 'lat' => 14.0, 'lon' => 100.0, 'label' => 'x', 'source' => 'stops', 'dtc_poi_id' => null, 'radius_m' => null],
        ], 7);

        $this->assertSame(2, $result['saved']);
        $this->assertCount(1, $result['skipped']);
        $poi = $db->table('gps_customer_locations')->where('customer_id', 1)->first();
        $this->assertSame(['dtc_poi', 'P1', 150], [$poi->source, $poi->dtc_poi_id, (int) $poi->radius_m]);
        $this->assertSame(['manual', 'Longdo: บริษัท ไม่มีใครรู้จัก'], [
            $db->table('gps_customer_locations')->where('customer_id', 2)->value('source'),
            $db->table('gps_customer_locations')->where('customer_id', 2)->value('label'),
        ]);
        $this->assertSame('เดิม', $db->table('gps_customer_locations')->where('customer_id', 9)->value('label'));
    }

    public function test_save_endpoint_validates_source_and_coordinates(): void
    {
        $this->expectException(ValidationException::class);

        app(GpsCustomerLocationController::class)->savePinSuggestions(Request::create('/x', 'POST', ['items' => [
            ['company' => 'MSW', 'customer_id' => 1, 'lat' => 0, 'lon' => 0, 'source' => 'google'],
        ]]));
    }

    public function test_search_keyword_strips_company_words(): void
    {
        $service = app(GpsPinSuggestionService::class);

        $this->assertSame('ไทยสแตนเลส สกรู', $service->searchKeyword('บจก.ไทยสแตนเลส สกรู(สำนักงานใหญ่)'));
        $this->assertSame('INNOVALUES PRECISION', $service->searchKeyword('INNOVALUES PRECISION (THAILAND) LTD. (HEAD OFFICE)'));
    }
}
