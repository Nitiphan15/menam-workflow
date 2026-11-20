<?php

namespace App\Http\Controllers\FormDP;

use App\Http\Controllers\Controller;
use App\Services\FormDP\DtcGpsService;
use App\Services\FormDP\GpsTripQueueService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class GpsTripTrackingController extends Controller
{
    public function __construct(
        private GpsTripQueueService $queueService,
        private DtcGpsService $dtcGpsService
    )
    {
    }

    public function index(): View
    {
        $queueDate = $this->resolveQueueDate();
        $demoVehicles = $this->demoVehicles($queueDate);
        // Mock DTC rows only for trucks with a queue (they are the ones with a demo position).
        $queuedVehicles = array_values(array_filter($demoVehicles, fn (array $vehicle) => $vehicle['has_queue']));
        $mockSnapshot = $this->dtcGpsService->sanitizeRealtimeResponse(
            $this->dtcGpsService->mockRealtimeResponse($queuedVehicles)
        );
        $mockMaster = $this->dtcGpsService->sanitizeVehicleMaster(
            $this->dtcGpsService->mockVehicleMaster($queuedVehicles)
        );
        $mockSnapshot['data'] = $this->dtcGpsService->enrichRealtimeWithMaster(
            $mockSnapshot['data'],
            $mockMaster['data']
        );
        $matchReport = $this->dtcGpsService->matchVehicles($mockSnapshot['data'], $queuedVehicles);

        return view('formdp.gps-trip-tracking.index', [
            'demoVehicles' => $demoVehicles,
            'queuedVehicleCount' => count($queuedVehicles),
            'dtcMockSnapshot' => $mockSnapshot,
            'dtcMatchReport' => $matchReport,
            'lastSuccessfulSync' => $this->lastSuccessfulSync(),
            'dtcUsage' => $this->dtcGpsService->usageSummary(),
            'queueDate' => $queueDate,
            'reportDate' => Carbon::parse($queueDate, 'Asia/Bangkok')->locale('th')->translatedFormat('d M Y'),
            'refreshSeconds' => 60,
            'dtcSyncMinutes' => 5,
        ]);
    }

    public function demoData(): JsonResponse
    {
        $queueDate = $this->resolveQueueDate();

        return response()->json([
            'mode' => 'demo',
            'generated_at' => now('Asia/Bangkok')->toIso8601String(),
            'queue_date' => $queueDate,
            'vehicles' => $this->demoVehicles($queueDate),
        ]);
    }

    public function testDtcConnection(): JsonResponse
    {
        $startedAt = microtime(true);
        $refreshMaster = request()->boolean('refresh_master');

        try {
            // Master (plate list) comes from a 24h cache unless Admin asks to refresh:
            // a normal test costs 1 realtime request, a refresh costs 2.
            $master = $this->dtcGpsService->getVehicleMasterCached($refreshMaster);
            $snapshot = $this->dtcGpsService->sanitizeRealtimeResponse(
                $this->dtcGpsService->getRealtimeData($this->dtcGpsService->gpsIdsFromMaster($master['data']))
            );
            $snapshot['data'] = $this->dtcGpsService->enrichRealtimeWithMaster($snapshot['data'], $master['data']);
            $snapshot['tested_at'] = now('Asia/Bangkok')->toIso8601String();
            $snapshot['tested_by_user_id'] = auth()->id();
            $snapshot['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
            $snapshot['vehicle_master_count'] = $master['count'];
            $snapshot['vehicle_master_from_cache'] = $master['from_cache'];
            $snapshot['vehicle_master_fetched_at'] = $master['fetched_at'];
            $snapshot['requests_used'] = $this->dtcGpsService->requestsSent();
            $snapshot['source'] = 'dtc_live';
            // Match DTC against the whole fleet (Truck Master + that day's manual plates), not only queued trucks.
            $vehicles = $this->demoVehicles($this->resolveQueueDate());
            $matchReport = $this->dtcGpsService->matchVehicles($snapshot['data'], $vehicles);

            $path = 'dtc-tests/realtime-' . now('Asia/Bangkok')->format('Ymd-His')
                . '-user-' . (auth()->id() ?: 'unknown') . '.json';
            Storage::disk('local')->put(
                $path,
                json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );

            return response()->json([
                'ok' => true,
                'message' => 'เชื่อมต่อ DTC สำเร็จ',
                'snapshot' => $snapshot,
                'match_report' => $matchReport,
                'usage' => $this->dtcGpsService->usageSummary(),
                'last_success_at' => $snapshot['tested_at'],
            ]);
        } catch (Throwable $e) {
            report($e);
            $rawError = $this->dtcGpsService->sanitizeException($e);
            $errorInfo = $this->dtcGpsService->describeException($e);
            $responseStatus = match ($errorInfo['code']) {
                'quota_locked' => 423,
                'rate_limited', 'budget_exhausted' => 429,
                'configuration', 'bad_request', 'api_rejected' => 422,
                default => 502,
            };

            return response()->json([
                'ok' => false,
                'message' => $errorInfo['title'],
                'error_info' => $errorInfo,
                'raw_error' => $rawError,
                'requests_attempted' => $this->dtcGpsService->requestsSent(),
                'usage' => $this->dtcGpsService->usageSummary(),
                'last_success_at' => $this->lastSuccessfulSync(),
            ], $responseStatus);
        }
    }

    private function lastSuccessfulSync(): ?string
    {
        try {
            $disk = Storage::disk('local');
            $latest = collect($disk->files('dtc-tests'))
                ->filter(fn (string $path) => str_starts_with(basename($path), 'realtime-'))
                ->sortByDesc(fn (string $path) => $disk->lastModified($path))
                ->first();
            if (!$latest) {
                return null;
            }

            $snapshot = json_decode($disk->get($latest), true);

            return is_array($snapshot) ? ($snapshot['tested_at'] ?? null) : null;
        } catch (Throwable) {
            return null;
        }
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
     * Plate, driver and deliveries are real FormDP data. Route/positions/times are DEMO only
     * (templates below) and exist only for trucks that have a queue that day.
     */
    private function demoVehicles(string $queueDate): array
    {
        $routeTemplates = [
            ['actual' => '08:07', 'planned' => '08:00', 'return_km' => 31, 'return_eta' => 42, 'route' => [[13.547532, 100.787167], [13.61180, 100.73060], [13.60210, 100.71650], [13.59310, 100.70290], [13.58420, 100.69210]]],
            ['actual' => '08:47', 'planned' => '08:30', 'return_km' => 34, 'return_eta' => 48, 'route' => [[13.547532, 100.787167], [13.62640, 100.73340], [13.63590, 100.72120], [13.64510, 100.70480], [13.64888, 100.68187]]],
            ['actual' => '09:04', 'planned' => '09:00', 'return_km' => 22, 'return_eta' => 31, 'route' => [[13.547532, 100.787167], [13.62570, 100.73090], [13.63260, 100.71700], [13.64090, 100.69980], [13.64840, 100.68190]]],
            ['actual' => '07:36', 'planned' => '07:30', 'return_km' => 79, 'return_eta' => 74, 'route' => [[13.547532, 100.787167], [13.72800, 100.72700], [13.84200, 100.71600], [13.95800, 100.70500], [14.06955, 100.69624]]],
            ['actual' => '10:03', 'planned' => '10:00', 'return_km' => 58, 'return_eta' => 65, 'route' => [[13.547532, 100.787167], [13.62600, 100.66100], [13.63200, 100.57500], [13.63800, 100.49000], [13.64431, 100.40924]]],
        ];
        $company = [13.547532, 100.787167];
        $serviceMinutes = 20;
        $queuedIndex = 0;

        return $this->queueService->fleetForDate($queueDate)->map(function (array $truck) use ($routeTemplates, $company, $serviceMinutes, $queueDate, &$queuedIndex) {
            $hasQueue = $truck['trip_nos'] !== [];
            $vehicle = [
                'gps_id' => 'TRUCK-' . $truck['plate_key'],
                'plate_no' => $truck['plate_no'],
                'truck_id' => $truck['truck_id'],
                'fleet_source' => $truck['source'],
                'has_queue' => $hasQueue,
                'driver_name' => $truck['driver_name'] !== '' ? $truck['driver_name'] : '-',
                'deliveries' => $truck['deliveries'],
            ];

            if (!$hasQueue) {
                return [...$vehicle,
                    'trip_ref' => 'ไม่มีคิว ' . $queueDate,
                    'destination' => '-',
                    'planned_departure' => '-',
                    'actual_departure' => '-',
                    'estimated_return' => '-',
                    'return_distance_km' => 0,
                    'return_eta_minutes' => 0,
                    'route' => [$company, $company],
                    'stops' => [],
                    'estimate_basis' => '',
                ];
            }

            $vehicleIndex = $queuedIndex++;
            $template = $routeTemplates[$vehicleIndex % count($routeTemplates)];
            $stopNames = array_slice($truck['stops'], 0, 4);
            $clock = Carbon::createFromFormat('H:i', $template['actual'], 'Asia/Bangkok');
            $stops = collect($stopNames)->values()->map(function (string $name, int $index) use ($vehicleIndex, &$clock, $serviceMinutes) {
                $eta = 28 + (($vehicleIndex % 5) * 4) + ($index * 9);
                $actualMinutes = $eta + (($vehicleIndex + $index) % 3 === 0 ? 7 : -2);
                $arrival = $clock->copy()->addMinutes($actualMinutes);
                $clock = $arrival->copy()->addMinutes($serviceMinutes);

                return [
                    'name' => $name,
                    'distance_km' => 18 + (($vehicleIndex % 5) * 5) + ($index * 11),
                    'eta_minutes' => $eta,
                    'actual_minutes' => $actualMinutes,
                    'actual_arrival' => $arrival->format('H:i'),
                ];
            })->all();

            return [...$vehicle,
                'trip_ref' => 'คิว ' . $queueDate . ' · เที่ยว ' . implode(', ', array_unique($truck['trip_nos'])),
                'destination' => $stopNames !== [] ? count($truck['stops']) . ' จุดส่ง · ' . $stopNames[0] : '-',
                'planned_departure' => $template['planned'],
                'actual_departure' => $template['actual'],
                'estimated_return' => $clock->copy()->addMinutes($template['return_eta'])->format('H:i'),
                'return_distance_km' => $template['return_km'],
                'return_eta_minutes' => $template['return_eta'],
                'route' => $template['route'],
                'stops' => $stops,
                'estimate_basis' => 'ETA ทุกช่วง + เวลาลงสินค้า ' . $serviceMinutes . ' นาที/จุด',
            ];
        })->values()->all();
    }
}
