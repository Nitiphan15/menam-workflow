<?php

namespace App\Services\FormDP;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Planner dashboard: one summary per truck per ship date (gps_trip_daily) and per delivery stop
 * (gps_stop_visit), computed from the real DTC route (getHistory, 1 request per truck/day) and the
 * customer pins. Tables come from database/sql/formdp_gps_dashboard.sql (no migration).
 *
 * Rules
 * - ถึงช้า: arrival at the pin after the DP window_at time of day (+ dtc.late_arrival_grace_minutes).
 * - กลับช้า: last re-entry into the company area after dtc.late_return_time; a finished day where the
 *   truck never came back is also flagged (note explains it).
 * - Stops without a pin are "no_location" and never counted as late.
 */
class GpsDailySummaryService
{
    public const COMPANY = [13.547532, 100.787167];
    public const COMPANY_RADIUS_M = 300; // same as the tracking page geofence

    public function __construct(
        private GpsTripQueueService $queueService,
        private GpsCustomerLocationService $locationService,
        private GpsStopVisitService $stopVisitService,
        private DtcGpsService $dtc
    ) {
    }

    private function db()
    {
        return DB::connection('sqlsrv_menam');
    }

    public function tablesReady(): bool
    {
        return (bool) Cache::remember('dtc_gps.schema.dashboard_tables', now()->addMinutes(10), function () {
            try {
                $schema = Schema::connection('sqlsrv_menam');

                return $schema->hasTable('gps_trip_daily') && $schema->hasTable('gps_stop_visit');
            } catch (Throwable) {
                return false;
            }
        });
    }

    public function forgetSchemaCache(): void
    {
        Cache::forget('dtc_gps.schema.dashboard_tables');
    }

    /**
     * Summarize one ship date. Stops early (status "rate_limited") when the DTC per-minute window is
     * full; call again later and already-summarized trucks are skipped unless $force.
     *
     * @return array{date:string, trucks:int, summarized:int, skipped:int, pending:int, status:string, errors:array}
     */
    public function summarizeDay(string $date, bool $force = false, int $maxRequests = PHP_INT_MAX, ?callable $log = null): array
    {
        if (!$this->tablesReady()) {
            throw new RuntimeException('GPS dashboard tables are not created yet.');
        }
        $log ??= fn (string $message) => null;
        $isPast = $date < now('Asia/Bangkok')->toDateString();
        $trucks = $this->trucksForDate($date);
        $done = $isPast && !$force
            ? $this->db()->table('gps_trip_daily')->where('ship_date', $date)->where('has_route', 1)->pluck('plate_key')->flip()->all()
            : [];

        $result = ['date' => $date, 'trucks' => count($trucks), 'summarized' => 0, 'skipped' => 0, 'pending' => 0, 'status' => 'done', 'errors' => []];
        $requestsAtStart = $this->dtc->requestsSent();

        foreach ($trucks as $index => $truck) {
            if (isset($done[$truck['plate_key']])) {
                $result['skipped']++;
                continue;
            }
            if ($truck['gps_id'] === '') {
                $this->store($date, $truck, null, 'ไม่มี GPS DTC');
                $result['summarized']++;
                $log("{$truck['plate_no']}: no DTC GPS");
                continue;
            }
            if ($this->dtc->requestsSent() - $requestsAtStart >= $maxRequests) {
                $result['pending'] = $this->pendingTruckCount($trucks, $index, $done);
                $result['status'] = 'partial';
                break;
            }

            try {
                $route = $this->dtc->vehicleRoute($truck['gps_id'], $date);
            } catch (DtcRateLimitException $e) {
                $result['pending'] = $this->pendingTruckCount($trucks, $index, $done);
                $result['status'] = 'rate_limited';
                $result['retry_after'] = $e->retryAfterSeconds;
                $log("rate limited, retry after {$e->retryAfterSeconds}s");
                break;
            } catch (Throwable $e) {
                $error = $this->dtc->describeException($e);
                $result['errors'][] = ['plate_no' => $truck['plate_no'], 'title' => $error['title']];
                if (in_array($error['code'], ['quota_locked', 'budget_exhausted', 'configuration'], true)) {
                    $result['status'] = 'stopped';
                    $result['pending'] = $this->pendingTruckCount($trucks, $index, $done);
                    break;
                }
                $this->store($date, $truck, null, mb_substr($error['title'], 0, 255));
                $result['summarized']++;
                continue;
            }

            $this->store($date, $truck, $route['points'] ?? [], null);
            $result['summarized']++;
            $log("{$truck['plate_no']}: " . count($route['points'] ?? []) . ' points' . (!empty($route['from_cache']) ? ' (cache)' : ''));
        }

        return $result;
    }

    /** Count unsummarized trucks from the current loop position onward. */
    private function pendingTruckCount(array $trucks, int $index, array $done): int
    {
        return count(array_filter(
            array_slice($trucks, $index),
            fn (array $truck) => !isset($done[$truck['plate_key']])
        ));
    }

    /**
     * Trucks with a FormDP queue on $date, deliveries carrying their customer pin, and the DTC GPS id
     * (linked dtc_gps_id first, else plate match against the cached Vehicle Master).
     */
    public function trucksForDate(string $date): array
    {
        $fleet = $this->queueService->fleetForDate($date)->filter(fn (array $truck) => $truck['trip_nos'] !== [])->values();
        if ($fleet->isEmpty()) {
            return [];
        }
        $locations = $this->locationService->locationsForDeliveries($fleet->flatMap(fn (array $truck) => $truck['deliveries'])->all());

        if (!$this->dtc->isVehicleMasterCached() && $fleet->contains(fn (array $truck) => ($truck['dtc_gps_id'] ?? '') === '')) {
            try {
                $this->dtc->getVehicleMasterCached();
            } catch (Throwable) {
                // plate matching just finds nothing; linked trucks still work
            }
        }
        $gpsByPlate = [];
        foreach ($this->dtc->cachedVehicleOptions() as $gpsId => $plate) {
            $gpsByPlate[$this->queueService->normalizePlate($plate)] = (string) $gpsId;
        }

        return $fleet->map(function (array $truck) use ($locations, $gpsByPlate) {
            $deliveries = array_map(function (array $delivery) use ($locations) {
                $byAddress = $locations[$this->queueService->customerCompany($delivery['company'] ?? null) . '|' . (int) ($delivery['customer_id'] ?? 0)] ?? [];
                $delivery['location'] = $byAddress[$this->locationService->addressKey($delivery['address'] ?? '')] ?? $byAddress[''] ?? null;

                return $delivery;
            }, $truck['deliveries']);

            $gpsId = (string) ($truck['dtc_gps_id'] ?? '');

            return [
                'plate_key' => $truck['plate_key'],
                'plate_no' => $truck['plate_no'],
                'truck_id' => $truck['truck_id'],
                'driver_name' => $truck['driver_name'],
                'trip_nos' => $truck['trip_nos'],
                'gps_id' => $gpsId !== '' ? $gpsId : ($gpsByPlate[$truck['plate_key']] ?? ''),
                'deliveries' => $deliveries,
            ];
        })->all();
    }

    /** Leave / return / distance / speed of one day of route points. Same rules as the tracking page. */
    public function tripMetrics(array $points): array
    {
        $points = array_values(array_filter($points, fn ($p) => is_array($p) && is_numeric($p['lat'] ?? null) && is_numeric($p['lon'] ?? null)));
        if ($points === []) {
            return ['first_point_at' => null, 'last_point_at' => null, 'left_company_at' => null, 'returned_at' => null, 'km' => null, 'max_speed' => null, 'ends_inside' => null];
        }

        $meters = 0.0;
        $maxSpeed = 0;
        $left = null;
        $back = null;
        $wasInside = $this->inCompany($points[0]);
        foreach ($points as $i => $point) {
            if ($i > 0) {
                $meters += $this->distance((float) $points[$i - 1]['lat'], (float) $points[$i - 1]['lon'], (float) $point['lat'], (float) $point['lon']);
            }
            $maxSpeed = max($maxSpeed, (int) round((float) ($point['speed'] ?? 0)));
            $inside = $this->inCompany($point);
            if ($wasInside && !$inside && $left === null) {
                $left = $point['time'];
            }
            if (!$wasInside && $inside) {
                $back = $point['time'];
            }
            $wasInside = $inside;
        }
        $first = $points[0];
        $last = end($points);
        $odometer = is_numeric($first['distance'] ?? null) && is_numeric($last['distance'] ?? null) && $last['distance'] >= $first['distance']
            ? (float) $last['distance'] - (float) $first['distance']
            : null;

        return [
            'first_point_at' => $first['time'],
            'last_point_at' => $last['time'],
            'left_company_at' => $left,
            'returned_at' => $back,
            'km' => round($odometer ?? $meters / 1000, 1),
            'max_speed' => $maxSpeed,
            'ends_inside' => $wasInside,
        ];
    }

    /** Minutes the arrival is after the window time (+ grace); null when it cannot be judged. */
    public function lateMinutes(?string $arrivedAt, ?string $windowTime): ?int
    {
        if (!$arrivedAt || !$windowTime || !preg_match('/(\d{2}):(\d{2})/', $arrivedAt, $a) || !preg_match('/^(\d{2}):(\d{2})$/', $windowTime, $w)) {
            return null;
        }
        $late = ((int) $a[1] * 60 + (int) $a[2]) - ((int) $w[1] * 60 + (int) $w[2]) - max(0, (int) config('dtc.late_arrival_grace_minutes', 0));

        return max(0, $late);
    }

    public function isLateReturn(?string $returnedAt): bool
    {
        $limit = (string) config('dtc.late_return_time', '18:00');
        if (!$returnedAt || !preg_match('/(\d{2}):(\d{2})/', $returnedAt, $r) || !preg_match('/^(\d{1,2}):(\d{2})$/', $limit, $l)) {
            return false;
        }

        return ((int) $r[1] * 60 + (int) $r[2]) > ((int) $l[1] * 60 + (int) $l[2]);
    }

    /** Upsert the truck/day row and its stop rows. $points = null means "no route" (note says why). */
    public function store(string $date, array $truck, ?array $points, ?string $note): void
    {
        $now = now('Asia/Bangkok')->format('Y-m-d H:i:s');
        $hasRoute = $points !== null && $points !== [];
        $metrics = $this->tripMetrics($points ?? []);
        $visits = $this->stopVisitService->detect($points ?? [], $truck['deliveries']);
        $isPast = $date < now('Asia/Bangkok')->toDateString();

        $stopRows = [];
        $counts = ['planned' => 0, 'with_pin' => 0, 'visited' => 0, 'on_time' => 0, 'late' => 0, 'not_visited' => 0];
        foreach (array_values($truck['deliveries']) as $index => $delivery) {
            $visit = $visits[$index] ?? ['status' => 'no_route', 'arrived_at' => null, 'left_at' => null, 'dwell_minutes' => null];
            if (!$hasRoute && $visit['status'] !== 'no_location') {
                $visit['status'] = 'no_route';
            }
            $arrived = in_array($visit['status'], ['visited', 'at_stop'], true);
            $lateMinutes = $arrived ? $this->lateMinutes($visit['arrived_at'], $delivery['window_time'] ?? null) : null;
            $isLate = $lateMinutes !== null && $lateMinutes > 0;

            $counts['planned']++;
            if ($visit['status'] !== 'no_location') {
                $counts['with_pin']++;
            }
            if ($arrived) {
                $counts['visited']++;
                $isLate ? $counts['late']++ : $counts['on_time']++;
            }
            if (in_array($visit['status'], ['not_visited', 'passed'], true)) {
                $counts['not_visited']++;
            }

            $customerId = isset($delivery['customer_id']) ? (int) $delivery['customer_id'] : null;
            $company = $this->queueService->customerCompany($delivery['company'] ?? null);
            $stopRows[] = [
                'ship_date' => $date,
                'plate_key' => $truck['plate_key'],
                'stop_key' => sha1($company . '|' . ($customerId ?? '') . '|' . $this->locationService->addressKey($delivery['address'] ?? '') . '|' . ($delivery['customer'] ?? '')),
                'stop_no' => $index + 1,
                'company' => $company,
                'customer_id' => $customerId,
                'customer_name' => mb_substr((string) ($delivery['customer'] ?? ''), 0, 255),
                'window_time' => $delivery['window_time'] ?? null,
                'status' => $visit['status'],
                'arrived_at' => $visit['arrived_at'],
                'left_at' => $visit['left_at'],
                'dwell_minutes' => $visit['dwell_minutes'],
                'late_minutes' => $lateMinutes,
                'is_late' => $isLate ? 1 : 0,
                'computed_at' => $now,
            ];
        }

        $lateReturn = $this->isLateReturn($metrics['returned_at']);
        if ($hasRoute && $isPast && $metrics['ends_inside'] === false && $metrics['left_company_at'] !== null) {
            $lateReturn = true;
            $note ??= 'ไม่พบการกลับเข้าบริษัทภายในวัน';
        }

        $tripRow = [
            'ship_date' => $date,
            'plate_key' => $truck['plate_key'],
            'plate_no' => mb_substr((string) $truck['plate_no'], 0, 50),
            'truck_id' => $truck['truck_id'],
            'dtc_gps_id' => $truck['gps_id'] !== '' ? $truck['gps_id'] : null,
            'driver_name' => mb_substr((string) ($truck['driver_name'] ?? ''), 0, 150) ?: null,
            'trip_nos' => implode(',', array_unique($truck['trip_nos'])),
            'has_route' => $hasRoute ? 1 : 0,
            'point_count' => $points === null ? null : count($points),
            'first_point_at' => $metrics['first_point_at'],
            'last_point_at' => $metrics['last_point_at'],
            'left_company_at' => $metrics['left_company_at'],
            'returned_at' => $metrics['returned_at'],
            'late_return' => $lateReturn ? 1 : 0,
            'km' => $metrics['km'],
            'max_speed' => $metrics['max_speed'],
            'stops_planned' => $counts['planned'],
            'stops_with_pin' => $counts['with_pin'],
            'stops_visited' => $counts['visited'],
            'stops_on_time' => $counts['on_time'],
            'stops_late' => $counts['late'],
            'stops_not_visited' => $counts['not_visited'],
            'note' => $note,
            'computed_at' => $now,
        ];

        $this->db()->transaction(function () use ($date, $truck, $tripRow, $stopRows, $now) {
            $this->db()->table('gps_trip_daily')->upsert([$tripRow], ['ship_date', 'plate_key'], array_keys(array_diff_key($tripRow, ['ship_date' => 1, 'plate_key' => 1])));
            if ($stopRows !== []) {
                $this->db()->table('gps_stop_visit')->upsert($stopRows, ['ship_date', 'plate_key', 'stop_key'], array_keys(array_diff_key($stopRows[0], ['ship_date' => 1, 'plate_key' => 1, 'stop_key' => 1])));
            }
            // Stops no longer on this truck/day (plan changed): keep the row, mark it removed.
            $this->db()->table('gps_stop_visit')
                ->where('ship_date', $date)
                ->where('plate_key', $truck['plate_key'])
                ->whereNotIn('stop_key', array_column($stopRows, 'stop_key') ?: ['-'])
                ->update(['status' => 'removed', 'is_late' => 0, 'computed_at' => $now]);
        });
    }

    // ---------- read side (dashboard) ----------

    public function dayBoard(string $date): array
    {
        $trips = $this->db()->table('gps_trip_daily')->where('ship_date', $date)->orderBy('plate_no')->get();
        $stops = $this->db()->table('gps_stop_visit')->where('ship_date', $date)->where('status', '<>', 'removed')
            ->orderBy('plate_key')->orderBy('stop_no')->get()->groupBy('plate_key');

        $rows = $trips->map(fn ($trip) => [
            'plate_no' => $trip->plate_no,
            'driver_name' => $trip->driver_name,
            'trip_nos' => $trip->trip_nos,
            'has_route' => (bool) $trip->has_route,
            'left_company_at' => $this->hhmm($trip->left_company_at),
            'returned_at' => $this->hhmm($trip->returned_at),
            'late_return' => (bool) $trip->late_return,
            'km' => $trip->km !== null ? (float) $trip->km : null,
            'max_speed' => $trip->max_speed !== null ? (int) $trip->max_speed : null,
            'note' => $trip->note,
            'computed_at' => $trip->computed_at ? substr((string) $trip->computed_at, 0, 16) : null,
            'stops' => ($stops->get($trip->plate_key) ?? collect())->map(fn ($stop) => [
                'stop_no' => (int) $stop->stop_no,
                'customer_name' => $stop->customer_name,
                'window_time' => $stop->window_time ? trim((string) $stop->window_time) : null,
                'status' => $stop->status,
                'arrived_at' => $this->hhmm($stop->arrived_at),
                'left_at' => $this->hhmm($stop->left_at),
                'dwell_minutes' => $stop->dwell_minutes !== null ? (int) $stop->dwell_minutes : null,
                'late_minutes' => $stop->late_minutes !== null ? (int) $stop->late_minutes : null,
                'is_late' => (bool) $stop->is_late,
            ])->values()->all(),
        ])->values();

        $onTime = (int) $trips->sum('stops_on_time');
        $late = (int) $trips->sum('stops_late');

        return [
            'date' => $date,
            'summary' => [
                'trucks' => $trips->count(),
                'trucks_with_route' => $trips->where('has_route', 1)->count(),
                'stops_planned' => (int) $trips->sum('stops_planned'),
                'stops_with_pin' => (int) $trips->sum('stops_with_pin'),
                'stops_on_time' => $onTime,
                'stops_late' => $late,
                'stops_not_visited' => (int) $trips->sum('stops_not_visited'),
                'late_returns' => $trips->where('late_return', 1)->count(),
                'on_time_percent' => ($onTime + $late) > 0 ? round($onTime * 100 / ($onTime + $late), 1) : null,
            ],
            'trucks' => $rows->all(),
        ];
    }

    /** Weekly on-time %, late counts by truck and the customers most often reached late. */
    public function trends(string $from, string $to): array
    {
        $trips = $this->db()->table('gps_trip_daily')->whereBetween('ship_date', [$from, $to])->get();

        $weeks = $trips->groupBy(function ($trip) {
            $day = \Carbon\Carbon::parse($trip->ship_date);

            return $day->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString();
        })->sortKeys()->map(function ($items, string $week) {
            $onTime = (int) $items->sum('stops_on_time');
            $late = (int) $items->sum('stops_late');

            return [
                'week' => $week,
                'on_time' => $onTime,
                'late' => $late,
                'not_visited' => (int) $items->sum('stops_not_visited'),
                'late_returns' => $items->where('late_return', 1)->count(),
                'truck_days' => $items->count(),
                'on_time_percent' => ($onTime + $late) > 0 ? round($onTime * 100 / ($onTime + $late), 1) : null,
            ];
        })->values()->all();

        $byTruck = $trips->groupBy('plate_no')->map(fn ($items, string $plate) => [
            'plate_no' => $plate,
            'days' => $items->count(),
            'late_arrivals' => (int) $items->sum('stops_late'),
            'late_returns' => $items->where('late_return', 1)->count(),
            'on_time' => (int) $items->sum('stops_on_time'),
        ])->sortByDesc(fn ($row) => $row['late_arrivals'] + $row['late_returns'])->values()->all();

        $customers = $this->db()->table('gps_stop_visit')
            ->whereBetween('ship_date', [$from, $to])
            ->whereIn('status', ['visited', 'at_stop'])
            ->selectRaw('company, customer_id, MAX(customer_name) AS customer_name, COUNT(*) AS visits, SUM(CAST(is_late AS INT)) AS late_count, AVG(CAST(dwell_minutes AS FLOAT)) AS avg_dwell')
            ->groupBy('company', 'customer_id')
            ->get()
            ->filter(fn ($row) => (int) $row->late_count > 0)
            ->sortByDesc(fn ($row) => [(int) $row->late_count, (int) $row->visits])
            ->take(10)
            ->map(fn ($row) => [
                'customer_name' => $row->customer_name,
                'visits' => (int) $row->visits,
                'late_count' => (int) $row->late_count,
                'avg_dwell' => $row->avg_dwell !== null ? (int) round((float) $row->avg_dwell) : null,
            ])->values()->all();

        $onTime = (int) $trips->sum('stops_on_time');
        $late = (int) $trips->sum('stops_late');

        return [
            'from' => $from,
            'to' => $to,
            'summary' => [
                'truck_days' => $trips->count(),
                'on_time_percent' => ($onTime + $late) > 0 ? round($onTime * 100 / ($onTime + $late), 1) : null,
                'late_arrivals' => $late,
                'late_returns' => $trips->where('late_return', 1)->count(),
                'not_visited' => (int) $trips->sum('stops_not_visited'),
            ],
            'weeks' => $weeks,
            'by_truck' => $byTruck,
            'late_customers' => $customers,
        ];
    }

    private function hhmm($value): ?string
    {
        return $value && preg_match('/(\d{2}):(\d{2})/', (string) $value, $m) ? $m[1] . ':' . $m[2] : null;
    }

    private function inCompany(array $point): bool
    {
        return $this->distance((float) $point['lat'], (float) $point['lon'], self::COMPANY[0], self::COMPANY[1]) <= self::COMPANY_RADIUS_M;
    }

    private function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $rad = M_PI / 180;
        $a = sin(($lat2 - $lat1) * $rad / 2) ** 2 + cos($lat1 * $rad) * cos($lat2 * $rad) * sin(($lon2 - $lon1) * $rad / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
