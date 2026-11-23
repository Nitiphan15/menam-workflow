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
        private DtcGpsService $dtc,
        private GpsTripTimelineService $timeline
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
        Cache::forget('dtc_gps.schema.timeline_tables');
    }

    /** gps_trip_stop + gps_trip_leg (database/sql/formdp_gps_stops_legs.sql). Optional: summary works without them. */
    public function timelineTablesReady(): bool
    {
        return (bool) Cache::remember('dtc_gps.schema.timeline_tables', now()->addMinutes(10), function () {
            try {
                $schema = Schema::connection('sqlsrv_menam');

                return $schema->hasTable('gps_trip_stop') && $schema->hasTable('gps_trip_leg');
            } catch (Throwable) {
                return false;
            }
        });
    }

    /** Merge deliveries that share a stop_key (same customer/address on several trips); earliest window wins. */
    private function uniqueDeliveries(array $deliveries): array
    {
        $unique = [];
        foreach (array_values($deliveries) as $delivery) {
            $key = $this->stopKey($delivery);
            if (!isset($unique[$key])) {
                $unique[$key] = $delivery;
                continue;
            }
            $windows = array_filter([$unique[$key]['window_time'] ?? null, $delivery['window_time'] ?? null]);
            $unique[$key]['window_time'] = $windows === [] ? null : min($windows);
            $unique[$key]['items'] = [...($unique[$key]['items'] ?? []), ...($delivery['items'] ?? [])];
            $unique[$key]['location'] ??= $delivery['location'] ?? null;
        }

        return array_values($unique);
    }

    /** Same key for a delivery stop in gps_stop_visit, gps_trip_stop and gps_trip_leg. */
    public function stopKey(array $delivery): string
    {
        $company = $this->queueService->customerCompany($delivery['company'] ?? null);
        $customerId = isset($delivery['customer_id']) ? (int) $delivery['customer_id'] : null;

        return sha1($company . '|' . ($customerId ?? '') . '|' . $this->locationService->addressKey($delivery['address'] ?? '') . '|' . ($delivery['customer'] ?? ''));
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
        // Several trips of the same truck to the same customer/address are one stop (one stop_key).
        $truck['deliveries'] = $this->uniqueDeliveries($truck['deliveries']);
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
                'stop_key' => $this->stopKey($delivery),
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

        // Stops (incl. unplanned) and travel legs, only when their tables exist.
        $timelineRows = null;
        if ($this->timelineTablesReady()) {
            $deliveries = array_map(fn (array $delivery) => [
                ...$delivery,
                'company' => $this->queueService->customerCompany($delivery['company'] ?? null),
                'stop_key' => $this->stopKey($delivery),
            ], array_values($truck['deliveries']));
            $timeline = $hasRoute ? $this->timeline->build($points, $deliveries, $metrics) : ['stops' => [], 'legs' => []];
            $timelineRows = $this->timelineRows($date, $truck['plate_key'], $timeline, $now);
        }

        $this->db()->transaction(function () use ($date, $truck, $tripRow, $stopRows, $now, $timelineRows) {
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

            if ($timelineRows !== null) {
                foreach (['gps_trip_stop' => ['kind' => 'removed'], 'gps_trip_leg' => ['to_kind' => 'removed']] as $table => $removed) {
                    $rows = $timelineRows[$table];
                    if ($rows !== []) {
                        $this->db()->table($table)->upsert($rows, ['ship_date', 'plate_key', 'seq'], array_keys(array_diff_key($rows[0], ['ship_date' => 1, 'plate_key' => 1, 'seq' => 1])));
                    }
                    // Fewer stops/legs than last time: keep the old rows, mark them removed.
                    $this->db()->table($table)
                        ->where('ship_date', $date)
                        ->where('plate_key', $truck['plate_key'])
                        ->where('seq', '>', count($rows))
                        ->update([...$removed, 'computed_at' => $now]);
                }
            }
        });
    }

    private function timelineRows(string $date, string $plateKey, array $timeline, string $now): array
    {
        $stops = [];
        foreach ($timeline['stops'] as $index => $stop) {
            $place = $stop['place'] ?? null;
            $detail = trim(implode(' · ', array_filter([
                isset($place['distance_m']) ? 'ห่าง ' . $this->distanceText((int) $place['distance_m']) : null,
                $place['detail'] ?? null,
            ])));
            $stops[] = [
                'ship_date' => $date,
                'plate_key' => $plateKey,
                'seq' => $index + 1,
                'kind' => $stop['kind'],
                'stop_key' => $stop['stop_key'],
                'customer_name' => $stop['customer_name'] !== null ? mb_substr((string) $stop['customer_name'], 0, 255) : null,
                'arrived_at' => $stop['arrived_at'],
                'left_at' => $stop['left_at'],
                'minutes' => (int) $stop['minutes'],
                'lat' => round((float) $stop['lat'], 6),
                'lon' => round((float) $stop['lon'], 6),
                'place_kind' => $place['kind'] ?? null,
                'place_label' => isset($place['label']) ? mb_substr((string) $place['label'], 0, 255) : null,
                'place_detail' => $detail !== '' ? mb_substr($detail, 0, 255) : null,
                'computed_at' => $now,
            ];
        }

        $legs = [];
        foreach ($timeline['legs'] as $index => $leg) {
            $legs[] = [
                'ship_date' => $date,
                'plate_key' => $plateKey,
                'seq' => $index + 1,
                'from_kind' => $leg['from_kind'],
                'from_key' => $leg['from_key'],
                'from_name' => $leg['from_name'] !== null ? mb_substr((string) $leg['from_name'], 0, 255) : null,
                'to_kind' => $leg['to_kind'],
                'to_key' => $leg['to_key'],
                'to_name' => $leg['to_name'] !== null ? mb_substr((string) $leg['to_name'], 0, 255) : null,
                'company' => $leg['company'],
                'customer_id' => $leg['customer_id'],
                'depart_at' => $leg['depart_at'],
                'arrive_at' => $leg['arrive_at'],
                'minutes' => (int) $leg['minutes'],
                'unplanned_minutes' => (int) $leg['unplanned_minutes'],
                'drive_minutes' => (int) $leg['drive_minutes'],
                'km' => $leg['km'],
                'computed_at' => $now,
            ];
        }

        return ['gps_trip_stop' => $stops, 'gps_trip_leg' => $legs];
    }

    private function distanceText(int $meters): string
    {
        return $meters < 1000 ? (round($meters / 10) * 10) . ' ม.' : number_format($meters / 1000, 1) . ' กม.';
    }

    // ---------- read side (dashboard) ----------

    public function dayBoard(string $date): array
    {
        $trips = $this->db()->table('gps_trip_daily')->where('ship_date', $date)->orderBy('plate_no')->get();
        $stops = $this->db()->table('gps_stop_visit')->where('ship_date', $date)->where('status', '<>', 'removed')
            ->orderBy('plate_key')->orderBy('stop_no')->get()->groupBy('plate_key');
        $unplanned = $this->timelineTablesReady()
            ? $this->db()->table('gps_trip_stop')->where('ship_date', $date)->whereIn('kind', ['unplanned', 'unknown'])
                ->orderBy('plate_key')->orderBy('seq')->get()->groupBy('plate_key')
            : collect();

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
            'unplanned' => ($unplanned->get($trip->plate_key) ?? collect())->map(fn ($stop) => [
                'kind' => $stop->kind,
                'arrived_at' => $this->hhmm($stop->arrived_at),
                'left_at' => $this->hhmm($stop->left_at),
                'minutes' => (int) $stop->minutes,
                'place_kind' => $stop->place_kind,
                'place_label' => $stop->place_label,
                'place_detail' => $stop->place_detail,
                'lat' => (float) $stop->lat,
                'lon' => (float) $stop->lon,
            ])->values()->all(),
        ])->values();
        $unplannedAll = $unplanned->flatten(1);

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
                'unplanned_count' => $unplannedAll->where('kind', 'unplanned')->count(),
                'unplanned_minutes' => (int) $unplannedAll->where('kind', 'unplanned')->sum('minutes'),
                'unknown_count' => $unplannedAll->where('kind', 'unknown')->count(),
                'timeline_ready' => $this->timelineTablesReady(),
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

        $unplannedByPlate = $this->timelineTablesReady()
            ? $this->db()->table('gps_trip_stop')->whereBetween('ship_date', [$from, $to])->where('kind', 'unplanned')
                ->selectRaw('plate_key, COUNT(*) AS stops, SUM(minutes) AS minutes')->groupBy('plate_key')->get()->keyBy('plate_key')
            : collect();
        $byTruck = $trips->groupBy('plate_no')->map(fn ($items, string $plate) => [
            'plate_no' => $plate,
            'days' => $items->count(),
            'late_arrivals' => (int) $items->sum('stops_late'),
            'late_returns' => $items->where('late_return', 1)->count(),
            'on_time' => (int) $items->sum('stops_on_time'),
            'unplanned_stops' => (int) ($unplannedByPlate->get($items->first()->plate_key)->stops ?? 0),
            'unplanned_minutes' => (int) ($unplannedByPlate->get($items->first()->plate_key)->minutes ?? 0),
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
                'unplanned_stops' => (int) $unplannedByPlate->sum('stops'),
                'unplanned_minutes' => (int) $unplannedByPlate->sum('minutes'),
            ],
            'weeks' => $weeks,
            'by_truck' => $byTruck,
            'late_customers' => $customers,
        ];
    }

    /**
     * Real travel time from the company to each customer (median, so one bad day does not skew it),
     * drive time without unplanned stops, distance, typical arrival time and unloading (dwell) time.
     */
    public function travelTimes(string $from, string $to, int $limit = 30): array
    {
        if (!$this->timelineTablesReady()) {
            return [];
        }
        $legs = $this->db()->table('gps_trip_leg')
            ->whereBetween('ship_date', [$from, $to])
            ->where('from_kind', 'company')
            ->where('to_kind', 'customer')
            ->whereNotNull('customer_id')
            ->get(['company', 'customer_id', 'to_name', 'minutes', 'drive_minutes', 'unplanned_minutes', 'km', 'arrive_at']);
        // Unloading time = real stays matched to the customer (pin or place name), via the shared stop_key.
        $dwell = $this->db()->table('gps_trip_stop as ts')
            ->join('gps_stop_visit as sv', function ($join) {
                $join->on('sv.ship_date', '=', 'ts.ship_date')->on('sv.plate_key', '=', 'ts.plate_key')->on('sv.stop_key', '=', 'ts.stop_key');
            })
            ->whereBetween('ts.ship_date', [$from, $to])
            ->where('ts.kind', 'customer')
            ->selectRaw('sv.company, sv.customer_id, AVG(CAST(ts.minutes AS FLOAT)) AS avg_dwell')
            ->groupBy('sv.company', 'sv.customer_id')
            ->get()
            ->keyBy(fn ($row) => $row->company . '|' . (int) $row->customer_id);

        return $legs->groupBy(fn ($leg) => $leg->company . '|' . (int) $leg->customer_id)
            ->map(function ($items, string $key) use ($dwell) {
                $arrivals = $items->map(function ($leg) {
                    return preg_match('/(\d{2}):(\d{2})/', (string) $leg->arrive_at, $m) ? (int) $m[1] * 60 + (int) $m[2] : null;
                })->filter(fn ($value) => $value !== null)->values()->all();
                $arrival = $this->median($arrivals);

                return [
                    'customer_name' => (string) ($items->pluck('to_name')->filter()->last() ?? '-'),
                    'trips' => $items->count(),
                    'median_minutes' => $this->median($items->pluck('minutes')->map(fn ($v) => (int) $v)->all()),
                    'median_drive_minutes' => $this->median($items->pluck('drive_minutes')->map(fn ($v) => (int) $v)->all()),
                    'with_unplanned' => $items->filter(fn ($leg) => (int) $leg->unplanned_minutes > 0)->count(),
                    'median_km' => $this->median($items->pluck('km')->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v)->all(), 1),
                    'typical_arrival' => $arrival === null ? null : sprintf('%02d:%02d', intdiv((int) $arrival, 60), (int) $arrival % 60),
                    'avg_dwell' => isset($dwell[$key]) && $dwell[$key]->avg_dwell !== null ? (int) round((float) $dwell[$key]->avg_dwell) : null,
                ];
            })
            ->sortByDesc('trips')
            ->take($limit)
            ->values()
            ->all();
    }

    private function median(array $values, int $precision = 0): int|float|null
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);
        $median = $count % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;

        return $precision > 0 ? round((float) $median, $precision) : (int) round($median);
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
