<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class GpsTripTrackingViewTest extends TestCase
{
    public function test_demo_dashboard_renders_required_controls_and_disclosure(): void
    {
        View::share('errors', new ViewErrorBag());

        $html = view('formdp.gps-trip-tracking.index', [
            'demoVehicles' => [[
                'gps_id' => 'DEMO-GPS-001',
                'plate_no' => '1ฒณ-1307',
                'driver_name' => 'Demo Driver',
                'trip_ref' => 'DP-DEMO-01',
                'destination' => 'Demo Destination',
                'planned_departure' => '08:00',
                'estimated_return' => '12:00',
                'actual_departure' => '08:05',
                'stops' => [],
                'deliveries' => [[
                    'customer' => 'บริษัทลูกค้าทดสอบ',
                    'items' => [[
                        'dp_ref' => 'DP#123',
                        'so_number' => 'SO-TEST-01',
                        'product' => 'สินค้าทดสอบ',
                        'part_number' => 'PART-01',
                        'quantity' => 2.5,
                        'unit' => 'ตัน',
                    ]],
                ]],
                'route' => [[13.61, 100.74], [13.62, 100.73]],
            ]],
            'queueDate' => '2026-11-13',
            'dtcMockSnapshot' => ['data' => []],
            'dtcMatchReport' => ['matched_count' => 0, 'dtc_count' => 0, 'rows' => []],
            'lastSuccessfulSync' => null,
            'dtcUsage' => ['month' => '2026-11', 'month_total' => 1234, 'today' => 5, 'quota' => 25000, 'budget' => 24500, 'remaining' => 23266, 'percent' => 4.9, 'by_endpoint' => [], 'locked_until' => null, 'master_cached_at' => null],
            'reportDate' => '13 พ.ย. 2026',
            'refreshSeconds' => 60,
            'dtcSyncMinutes' => 5,
        ])->render();

        $this->assertStringContainsString('DEMO DATA', $html);
        $this->assertStringContainsString('id="gps-data-badge-text"', $html);
        $this->assertStringContainsString('ตำแหน่งรถบนแผนที่เป็นข้อมูลจำลอง', $html);
        $this->assertStringNotContainsString('LIVE DTC', $html);
        $this->assertStringContainsString('id="gps-dtc-usage"', $html);
        $this->assertStringContainsString('DTC เดือนนี้ 1,234 / 25,000 · วันนี้ 5', $html);
        $this->assertStringContainsString('gps-simulation-toggle', $html);
        $this->assertStringContainsString('gps-simulation-reset', $html);
        $this->assertStringContainsString('gps-view-all', $html);
        $this->assertStringContainsString('data-gps-view="live"', $html);
        $this->assertStringContainsString('data-gps-view="history"', $html);
        $this->assertStringContainsString('data-gps-view="reports"', $html);
        $this->assertStringContainsString('gps-history-list', $html);
        $this->assertStringContainsString('gps-report-rows', $html);
        $this->assertStringContainsString('gps-destination-count', $html);
        $this->assertStringContainsString('gps-source-console', $html);
        $this->assertStringContainsString('gps-last-success', $html);
        $this->assertStringContainsString('ทะเบียน Vehicle Master', $html);
        $this->assertStringContainsString('ดูการจับคู่รถ', $html);
        $this->assertStringContainsString('กำลังใช้ตำแหน่งจำลอง', $html);
        $this->assertStringNotContainsString('กำลังกลับ', $html);
        preg_match('/data-vehicles=\'(.*?)\' data-dtc-mock=/s', $html, $matches);
        $vehicles = json_decode(html_entity_decode($matches[1] ?? '', ENT_QUOTES), true);
        $item = $vehicles[0]['deliveries'][0]['items'][0] ?? [];
        $this->assertSame('บริษัทลูกค้าทดสอบ', $vehicles[0]['deliveries'][0]['customer'] ?? null);
        $this->assertSame('SO-TEST-01', $item['so_number'] ?? null);
        $this->assertSame('DP#123', $item['dp_ref'] ?? null);
        $this->assertSame('สินค้าทดสอบ', $item['product'] ?? null);
        $this->assertSame(2.5, $item['quantity'] ?? null);
        $this->assertSame('ตัน', $item['unit'] ?? null);
        $this->assertStringNotContainsString('gps-driver-demo-open', $html);
        $this->assertStringNotContainsString('gps-driver-demo-body', $html);
        $this->assertStringContainsString('DEMO-GPS-001', $html);
        $this->assertStringContainsString('GPS Trip Tracking', $html);
    }
}
