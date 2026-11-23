<?php

namespace App\Http\Controllers\FormDP;

use App\Http\Controllers\Controller;
use App\Services\FormDP\DtcGpsService;
use App\Services\FormDP\DtcRateLimitException;
use App\Services\FormDP\GpsCustomerLocationService;
use App\Services\FormDP\GpsPlaceResolver;
use App\Services\FormDP\GpsStopVisitService;
use App\Services\FormDP\GpsTripQueueService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Throwable;

/**
 * GPS Trip Tracking with real DTC data only (no demo positions).
 * Positions refresh by "lazy sync": while a page is open it polls live(), and the
 * service calls DTC at most once per dtc.sync_interval_minutes for everyone.
 */
class GpsTripTrackingController extends Controller
{
    public function __construct(
        private GpsTripQueueService $queueService,
        private DtcGpsService $dtcGpsService,
        private GpsCustomerLocationService $locationService,
        private GpsStopVisitService $stopVisitService,
        private GpsPlaceResolver $placeResolver
    )
    {
    }

    public function index(): View
    {
        $queueDate = $this->resolveQueueDate();
        $vehicles = $this->fleetVehicles($queueDate);
        // First render never calls DTC: show the last shared snapshot, the page then polls live().
        $snapshot = $this->dtcGpsService->cachedLiveSnapshot();
        $snapshot['rows'] = $this->placeResolver->withPlaces($snapshot['rows']);

        return view('formdp.gps-trip-tracking.index', [
            'vehicles' => $vehicles,
            'queuedVehicleCount' => count(array_filter($vehicles, fn (array $vehicle) => $vehicle['has_queue'])),
            'liveSnapshot' => $snapshot,
            'matchReport' => $this->dtcGpsService->matchVehicles($snapshot['rows'], $vehicles),
            'dtcUsage' => $this->dtcGpsService->usageSummary(),
            'longdoUsage' => app(\App\Services\FormDP\LongdoPlaceService::class)->usageSummary(),
            'queueDate' => $queueDate,
            'isToday' => $queueDate === now('Asia/Bangkok')->toDateString(),
            'syncIntervalMinutes' => max(1, (int) config('dtc.sync_interval_minutes', 3)),
        ]);
    }

    /**
     * Polled by the page. Returns the shared snapshot; calls DTC only when it is older than the
     * sync interval. ADMINWEB may force a sync (?force=1) or refresh the Vehicle Master (?refresh_master=1).
     */
    public function live(): JsonResponse
    {
        $isAdmin = Gate::allows('ADMINWEB');
        $refreshMaster = $isAdmin && request()->boolean('refresh_master');
        $force = $isAdmin && (request()->boolean('force') || $refreshMaster);

        return $this->snapshotResponse($force, $refreshMaster, false);
    }

    /** Admin "sync now" button (kept for the existing route name). */
    public function testDtcConnection(): JsonResponse
    {
        $refreshMaster = request()->boolean('refresh_master');

        return $this->snapshotResponse(true, $refreshMaster, true);
    }

    /** Real route of one vehicle for the selected day (DTC getHistory, cached 10 min). */
    public function route(string $gpsId): JsonResponse
    {
        $date = $this->resolveQueueDate();

        try {
            $route = $this->dtcGpsService->vehicleRoute($gpsId, $date);
            // Arrival / leave per stop from the same points (no extra DTC request).
            $deliveries = $this->deliveriesForGps($gpsId, $date);

            return response()->json([
                'ok' => true,
                'route' => $route,
                'stop_visits' => $this->stopVisitService->detect($route['points'] ?? [], $deliveries),
                'requests_used' => $this->dtcGpsService->requestsSent(),
                'usage' => $this->dtcGpsService->usageSummary(),
            ]);
        } catch (Throwable $e) {
            if (!$e instanceof DtcRateLimitException && $e->getMessage() !== 'Unknown GPS id.') {
                report($e);
            }
            $errorInfo = $e->getMessage() === 'Unknown GPS id.'
                ? ['code' => 'unknown_vehicle', 'title' => 'ไม่พบรถคันนี้ในรายชื่อ DTC', 'advice' => 'รอ sync รอบถัดไปหรือรีเฟรชรายชื่อรถ', 'http_status' => null, 'retryable' => false]
                : $this->dtcGpsService->describeException($e);

            return response()->json([
                'ok' => false,
                'error_info' => $errorInfo,
                'raw_error' => $this->dtcGpsService->sanitizeException($e),
                'requests_used' => $this->dtcGpsService->requestsSent(),
                'usage' => $this->dtcGpsService->usageSummary(),
            ], $this->statusFor($errorInfo['code']));
        }
    }

    public function demoData(): JsonResponse
    {
        // Kept for the existing route name; now returns the real fleet (no demo positions).
        $queueDate = $this->resolveQueueDate();

        return response()->json([
            'queue_date' => $queueDate,
            'generated_at' => now('Asia/Bangkok')->toIso8601String(),
            'vehicles' => $this->fleetVehicles($queueDate),
        ]);
    }

    private function snapshotResponse(bool $force, bool $refreshMaster, bool $httpErrorStatus): JsonResponse
    {
        $startedAt = microtime(true);
        $snapshot = $this->dtcGpsService->liveSnapshot($force, $refreshMaster);
        $snapshot['rows'] = $this->placeResolver->withPlaces($snapshot['rows'] ?? []);
        $vehicles = $this->fleetVehicles($this->resolveQueueDate());
        $error = $snapshot['error'];
        unset($snapshot['error']);
        $snapshot['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

        $payload = [
            'ok' => $error === null,
            'message' => $error === null ? ($snapshot['synced_now'] ? 'ดึงตำแหน่งจาก DTC สำเร็จ' : 'ใช้ข้อมูลล่าสุดที่ sync ไว้') : $error['title'],
            'snapshot' => $snapshot,
            'match_report' => $this->dtcGpsService->matchVehicles($snapshot['rows'], $vehicles),
            'usage' => $this->dtcGpsService->usageSummary(),
            'error_info' => $error === null ? null : array_diff_key($error, ['raw' => true]),
            'raw_error' => $error['raw'] ?? null,
            'requests_attempted' => $snapshot['requests_used'],
            'last_success_at' => $snapshot['fetched_at'],
        ];

        $status = ($httpErrorStatus && $error !== null) ? $this->statusFor($error['code']) : 200;

        return response()->json($payload, $status);
    }

    private function statusFor(string $code): int
    {
        return match ($code) {
            'quota_locked' => 423,
            'rate_limited', 'rate_limited_local', 'budget_exhausted' => 429,
            'configuration', 'bad_request', 'api_rejected', 'unknown_vehicle' => 422,
            default => 502,
        };
    }

    /**
     * Deliveries of the FormDP truck behind $gpsId on $date (same order as the page list).
     * Linked GPS id first, then the plate match from the last shared snapshot; never calls DTC.
     */
    private function deliveriesForGps(string $gpsId, string $date): array
    {
        $vehicles = $this->fleetVehicles($date);
        $vehicle = collect($vehicles)->first(fn (array $candidate) => $candidate['dtc_gps_id'] === $gpsId);
        if ($vehicle === null) {
            $snapshot = $this->dtcGpsService->cachedLiveSnapshot();
            $plate = collect($this->dtcGpsService->matchVehicles($snapshot['rows'], $vehicles)['rows'])
                ->first(fn (array $row) => ($row['gps_id'] ?? null) === $gpsId && !empty($row['matched']))['plate_no'] ?? null;
            $vehicle = $plate === null ? null : collect($vehicles)->firstWhere('plate_no', $plate);
        }

        return $vehicle['deliveries'] ?? [];
    }

    private function resolveQueueDate(): string
    {
        $date = trim((string) request()->query('date', request()->input('date', '')));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            try {
                $parsed = Carbon::createFromFormat('Y-m-d', $date, 'Asia/Bangkok');
                if ($parsed !== false && $parsed->format('Y-m-d') === $date) {
                    return $date;
                }
            } catch (Throwable) {
                // fall through to today
            }
        }

        return now('Asia/Bangkok')->toDateString();
    }

    /**
     * Fleet = ACTIVE Truck Master + manual plates that have a FormDP queue on $queueDate.
     * Everything here is real FormDP data; positions come only from DTC.
     */
    private function fleetVehicles(string $queueDate): array
    {
        $fleet = $this->queueService->fleetForDate($queueDate);
        // Customer pins (GPS Phase 1): address-specific location first, then the customer default.
        $locations = $this->locationService->locationsForDeliveries(
            $fleet->flatMap(fn (array $truck) => $truck['deliveries'])->all()
        );
        $withLocation = function (array $delivery) use ($locations): array {
            $byAddress = $locations[$this->queueService->customerCompany($delivery['company'] ?? null) . '|' . (int) ($delivery['customer_id'] ?? 0)] ?? [];
            $location = $byAddress[$this->locationService->addressKey($delivery['address'] ?? '')] ?? $byAddress[''] ?? null;

            return [...$delivery, 'location' => $location
                ? ['lat' => $location['lat'], 'lon' => $location['lon'], 'radius_m' => $location['radius_m'], 'label' => $location['label']]
                : null];
        };

        return $fleet->map(function (array $truck) use ($queueDate, $withLocation) {
            $truck['deliveries'] = array_map($withLocation, $truck['deliveries']);
            $hasQueue = $truck['trip_nos'] !== [];

            return [
                'key' => 'TRUCK-' . $truck['plate_key'],
                'plate_no' => $truck['plate_no'],
                'truck_id' => $truck['truck_id'],
                'dtc_gps_id' => (string) ($truck['dtc_gps_id'] ?? ''),
                'fleet_source' => $truck['source'],
                'has_queue' => $hasQueue,
                'driver_name' => $truck['driver_name'] !== '' ? $truck['driver_name'] : '-',
                'trip_ref' => $hasQueue
                    ? 'คิว ' . $queueDate . ' · เที่ยว ' . implode(', ', array_unique($truck['trip_nos']))
                    : 'ไม่มีคิว ' . $queueDate,
                'stops' => array_values($truck['stops']),
                'deliveries' => $truck['deliveries'],
            ];
        })->values()->all();
    }
}
