<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class GpsTripTrackingViewTest extends TestCase
{
    public function test_real_data_page_renders_controls_without_any_demo_content(): void
    {
        View::share('errors', new ViewErrorBag());

        $html = view('formdp.gps-trip-tracking.index', [
            'vehicles' => [[
                'key' => 'TRUCK-1ฒณ1307',
                'plate_no' => '1ฒณ1307',
                'truck_id' => 14,
                'fleet_source' => 'master',
                'has_queue' => true,
                'driver_name' => 'Test Driver',
                'trip_ref' => 'คิว 2026-11-19 · เที่ยว 1',
                'stops' => ['บริษัทลูกค้าทดสอบ'],
                'deliveries' => [[
                    'customer' => 'บริษัทลูกค้าทดสอบ',
                    'items' => [[
                        'dp_ref' => 'DP#123',
                        'so_number' => 'SO-TEST-01',
                        'product' => 'สินค้าทดสอบ',
                        'part_number' => 'PART-01',
                        'quantity' => 2.5,
                        'unit' => 'kg',
                    ]],
                ]],
            ]],
            'queuedVehicleCount' => 1,
            'liveSnapshot' => ['rows' => [], 'fetched_at' => null, 'age_seconds' => null, 'synced_now' => false, 'requests_used' => 0, 'master_from_cache' => null],
            'matchReport' => ['matched_count' => 0, 'dtc_count' => 0, 'missing_in_formdp_count' => 0, 'missing_in_dtc_count' => 1, 'rows' => []],
            'dtcUsage' => ['month' => '2026-11', 'month_total' => 1234, 'today' => 5, 'quota' => 25000, 'budget' => 24500, 'remaining' => 23266, 'percent' => 4.9, 'by_endpoint' => [], 'locked_until' => null, 'master_cached_at' => null],
            'queueDate' => '2026-11-19',
            'isToday' => false,
            'syncIntervalMinutes' => 3,
        ])->render();

        $this->assertStringContainsString('gps-source-console', $html);
        $this->assertStringContainsString('id="gps-map"', $html);
        $this->assertStringContainsString('gps-view-all', $html);
        $this->assertStringContainsString('data-filter="gps"', $html);
        $this->assertStringContainsString('ดูการจับคู่รถ', $html);
        $this->assertStringContainsString('id="gps-dtc-usage"', $html);
        $this->assertStringContainsString('DTC เดือนนี้ 1,234 / 25,000 · วันนี้ 5', $html);
        $this->assertStringContainsString('อัปเดตอัตโนมัติทุก 3 นาที', $html);
        $this->assertStringContainsString('/dp/gps-trip-tracking/live', $html);
        $this->assertStringContainsString('/dp/gps-trip-tracking/route/__GPS__', $html);
        $this->assertStringContainsString('ตำแหน่งบนแผนที่เป็นปัจจุบัน', $html); // past-date note

        // Real data only: no simulation, demo labels, demo tabs or demo events.
        foreach (['DEMO', 'Simulation', 'gps-simulation-toggle', 'data-gps-view="history"', 'data-gps-view="reports"', 'รถตัวอย่าง', 'จำลอง', 'กำลังกลับ'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, "Found demo content: {$forbidden}");
        }

        preg_match("/data-vehicles='(.*?)'/s", $html, $matches);
        $vehicles = json_decode(html_entity_decode($matches[1] ?? '', ENT_QUOTES), true);
        $item = $vehicles[0]['deliveries'][0]['items'][0] ?? [];
        $this->assertSame('SO-TEST-01', $item['so_number'] ?? null);
        $this->assertSame(2.5, $item['quantity'] ?? null);
        $this->assertSame('kg', $item['unit'] ?? null);
    }
}
