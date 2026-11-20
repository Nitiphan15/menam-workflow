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
        $queueDate = now('Asia/Bangkok')->toDateString();
        $demoVehicles = $this->demoVehicles($queueDate);
        $mockSnapshot = $this->dtcGpsService->sanitizeRealtimeResponse(
            $this->dtcGpsService->mockRealtimeResponse($demoVehicles)
        );
        $mockMaster = $this->dtcGpsService->sanitizeVehicleMaster(
            $this->dtcGpsService->mockVehicleMaster($demoVehicles)
        );
        $mockSnapshot['data'] = $this->dtcGpsService->enrichRealtimeWithMaster(
            $mockSnapshot['data'],
            $mockMaster['data']
        );
        $matchReport = $this->dtcGpsService->matchVehicles($mockSnapshot['data'], $demoVehicles);

        return view('formdp.gps-trip-tracking.index', [
            'demoVehicles' => $demoVehicles,
            'dtcMockSnapshot' => $mockSnapshot,
            'dtcMatchReport' => $matchReport,
            'lastSuccessfulSync' => $this->lastSuccessfulSync(),
            'dtcUsage' => $this->dtcGpsService->usageSummary(),
            'queueDate' => $queueDate,
            'reportDate' => now('Asia/Bangkok')->locale('th')->translatedFormat('d M Y'),
            'refreshSeconds' => 60,
            'dtcSyncMinutes' => 5,
        ]);
    }

    public function demoData(): JsonResponse
    {
        $queueDate = now('Asia/Bangkok')->toDateString();

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
            $vehicles = $this->demoVehicles(now('Asia/Bangkok')->toDateString());
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

    private function demoVehicles(string $queueDate): array
    {
        $vehicles = [
            [
                'gps_id' => 'DEMO-GPS-001',
                'plate_no' => '1ฒณ1307',
                'driver_name' => 'นาย ศักดิ์ชัย คำภา',
                'trip_ref' => 'คิว FormDP · เที่ยว 1',
                'destination' => 'บางพลี / บางปู',
                'planned_departure' => '08:00',
                'actual_departure' => '08:07',
                'estimated_return' => '13:05',
                'return_distance_km' => 31,
                'return_eta_minutes' => 42,
                'route' => [[13.547532, 100.787167], [13.61180, 100.73060], [13.60210, 100.71650], [13.59310, 100.70290], [13.58420, 100.69210]],
            ],
            [
                'gps_id' => 'DEMO-GPS-002',
                'plate_no' => '2ฒถ5450',
                'driver_name' => 'นาย กิตติกวิน เสาหงษ์',
                'trip_ref' => 'คิว FormDP · เที่ยว 1',
                'destination' => 'กรุงเทพฯ / บางพลี',
                'planned_departure' => '08:30',
                'actual_departure' => '08:47',
                'estimated_return' => '14:20',
                'return_distance_km' => 34,
                'return_eta_minutes' => 48,
                'route' => [[13.547532, 100.787167], [13.62640, 100.73340], [13.63590, 100.72120], [13.64510, 100.70480], [13.64888, 100.68187]],
            ],
            [
                'gps_id' => 'DEMO-GPS-003',
                'plate_no' => '7กก-312',
                'driver_name' => 'ณรงค์ บุญมา',
                'trip_ref' => 'DP-691113-03',
                'destination' => 'ศูนย์กระจายสินค้าบางแก้ว',
                'planned_departure' => '09:00',
                'actual_departure' => '09:04',
                'estimated_return' => '12:40',
                'return_distance_km' => 22,
                'return_eta_minutes' => 31,
                'route' => [[13.547532, 100.787167], [13.62570, 100.73090], [13.63260, 100.71700], [13.64090, 100.69980], [13.64840, 100.68190]],
            ],
            [
                'gps_id' => 'DEMO-GPS-004',
                'plate_no' => '83-5505',
                'driver_name' => 'นาย อนุวัฒน์ สืบสำราญ',
                'trip_ref' => 'คิว FormDP · เที่ยว 1',
                'destination' => 'อยุธยา 3 จุด',
                'planned_departure' => '07:30',
                'actual_departure' => '07:36',
                'estimated_return' => '15:30',
                'return_distance_km' => 79,
                'return_eta_minutes' => 74,
                'route' => [[13.547532, 100.787167], [13.72800, 100.72700], [13.84200, 100.71600], [13.95800, 100.70500], [14.06955, 100.69624]],
            ],
            [
                'gps_id' => 'DEMO-GPS-005',
                'plate_no' => '84-0953',
                'driver_name' => 'นาย ประหยัด สาริบูรณ์',
                'trip_ref' => 'คิว FormDP · เที่ยว 1',
                'destination' => 'กรุงเทพฯ / นนทบุรี',
                'planned_departure' => '10:00',
                'actual_departure' => '10:03',
                'estimated_return' => '16:00',
                'return_distance_km' => 58,
                'return_eta_minutes' => 65,
                'route' => [[13.547532, 100.787167], [13.62600, 100.66100], [13.63200, 100.57500], [13.63800, 100.49000], [13.64431, 100.40924]],
            ],
        ];

        $queueByPlate = $this->queueService->forDate($queueDate)
            ->keyBy('plate_key');

        return collect($vehicles)->map(function (array $vehicle, int $vehicleIndex) use ($queueByPlate, $queueDate) {
            $queue = $queueByPlate->get($this->queueService->normalizePlate($vehicle['plate_no']));
            $stopNames = array_slice($queue['stops'] ?? [], 0, 4);

            if ($stopNames !== []) {
                $vehicle['driver_name'] = $queue['driver_name'] ?: $vehicle['driver_name'];
                $vehicle['trip_ref'] = 'คิว ' . $queueDate . ' · เที่ยว ' . $queue['trip_no'];
                $vehicle['destination'] = count($stopNames) . ' จุดส่ง · ' . $stopNames[0];
                $vehicle['deliveries'] = $queue['deliveries'] ?? [];
            } elseif ($vehicleIndex === 2) {
                $stopNames = ['จุดส่งตัวอย่าง (ไม่พบคิวที่ตรงทะเบียนวันนี้)'];
                $vehicle['deliveries'] = [[
                    'customer' => $stopNames[0],
                    'items' => [[
                        'dp_ref' => 'DP-DEMO-03',
                        'so_number' => 'SO-DEMO-03',
                        'product' => 'สินค้าตัวอย่างสำหรับรถที่ไม่พบคิวตรงทะเบียน',
                        'part_number' => 'DEMO-PART',
                        'quantity' => 1,
                        'unit' => 'ตัน',
                    ]],
                ]];
            }

            $vehicle['deliveries'] = $vehicle['deliveries'] ?? [];

            $clock = Carbon::createFromFormat('H:i', $vehicle['actual_departure'], 'Asia/Bangkok');
            $serviceMinutes = 20;
            $vehicle['stops'] = collect($stopNames)->values()->map(function (string $name, int $index) use ($vehicleIndex, &$clock, $serviceMinutes) {
                $eta = 28 + ($vehicleIndex * 4) + ($index * 9);
                $actualMinutes = $eta + (($vehicleIndex + $index) % 3 === 0 ? 7 : -2);
                $arrival = $clock->copy()->addMinutes($actualMinutes);
                $clock = $arrival->copy()->addMinutes($serviceMinutes);

                return [
                    'name' => $name,
                    'distance_km' => 18 + ($vehicleIndex * 5) + ($index * 11),
                    'eta_minutes' => $eta,
                    'actual_minutes' => $actualMinutes,
                    'actual_arrival' => $arrival->format('H:i'),
                ];
            })->all();
            $vehicle['estimated_return'] = $clock->copy()->addMinutes((int) $vehicle['return_eta_minutes'])->format('H:i');
            $vehicle['estimate_basis'] = 'ETA ทุกช่วง + เวลาลงสินค้า ' . $serviceMinutes . ' นาที/จุด';

            return $vehicle;
        })->all();
    }
}
