<?php

namespace App\Services\FormDP;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Suggest a customer pin from where our trucks really parked on recent trips to that customer.
 *
 * - Trips: last MAX_TRIPS past ship days with this customer on a truck that has a DTC GPS
 *   (linked dtc_gps_id first, else plate match against the cached Vehicle Master).
 * - Routes: DtcGpsService::vehicleRoute (cached 12 h for past days, otherwise 1 getHistory each).
 * - Stops: stayed within STOP_RADIUS_M for at least MIN_STOP_MINUTES, outside the company area,
 *   and not at another customer of the same trip that is already pinned.
 * - Candidates: stops of different trips within CLUSTER_RADIUS_M are merged; a spot seen on
 *   more trips ranks first. Nothing is saved: a person confirms the pin.
 */
class GpsStopSuggestionService
{
    public const MAX_TRIPS = 3;
    public const MIN_STOP_MINUTES = 10;
    public const STOP_RADIUS_M = 120;
    public const CLUSTER_RADIUS_M = 250;
    public const COMPANY = [13.547532, 100.787167];
    // Wider than the 300 m tracking geofence: on DEV data, trucks also wait 300-800 m from the gate.
    public const COMPANY_RADIUS_M = 1000;

    public function __construct(
        private GpsTripQueueService $queueService,
        private GpsCustomerLocationService $locationService,
        private DtcGpsService $dtc
    ) {
    }

    public function suggest(string $company, int $customerId): array
    {
        $trips = $this->recentTrips($company, $customerId);
        $usable = array_values(array_filter($trips, fn (array $trip) => $trip['gps_id'] !== ''));
        $stopsByTrip = [];
        $errors = [];

        foreach (array_slice($usable, 0, self::MAX_TRIPS) as $trip) {
            try {
                $route = $this->dtc->vehicleRoute($trip['gps_id'], $trip['date']);
            } catch (Throwable $e) {
                $errors[] = ['date' => $trip['date'], 'plate_no' => $trip['plate_no'], 'title' => $this->dtc->describeException($e)['title']];
                if ($e instanceof DtcRateLimitException) {
                    break; // no point trying the next trip this minute
                }
                continue;
            }
            $exclude = $this->otherPinnedStops($trip, $company, $customerId);
            $stops = array_values(array_filter(
                $this->stopsFromPoints($route['points'] ?? []),
                fn (array $stop) => !$this->isNear($stop, [self::COMPANY[0], self::COMPANY[1], self::COMPANY_RADIUS_M])
                    && !collect($exclude)->contains(fn (array $pin) => $this->isNear($stop, $pin))
            ));
            $stopsByTrip[] = ['trip' => $trip, 'stops' => $stops];
        }

        return [
            'trips_found' => count($trips),
            'trips_with_gps' => count($usable),
            'trips_checked' => count($stopsByTrip),
            'candidates' => $this->cluster($stopsByTrip),
            'errors' => $errors,
            'requests_used' => $this->dtc->requestsSent(),
        ];
    }

    /**
     * Stays: consecutive points within STOP_RADIUS_M of the first point of the stay,
     * lasting at least MIN_STOP_MINUTES.
     *
     * @return array<int, array{lat:float, lon:float, arrived_at:string, left_at:string, minutes:int}>
     */
    public function stopsFromPoints(array $points): array
    {
        $points = array_values(array_filter($points, fn ($point) => is_array($point)
            && is_numeric($point['lat'] ?? null) && is_numeric($point['lon'] ?? null) && $this->timestamp($point['time'] ?? null) !== null));
        $stops = [];
        $count = count($points);
        $i = 0;
        while ($i < $count) {
            $anchor = $points[$i];
            $j = $i;
            $sumLat = 0.0;
            $sumLon = 0.0;
            while ($j < $count && $this->distance((float) $anchor['lat'], (float) $anchor['lon'], (float) $points[$j]['lat'], (float) $points[$j]['lon']) <= self::STOP_RADIUS_M) {
                $sumLat += (float) $points[$j]['lat'];
                $sumLon += (float) $points[$j]['lon'];
                $j++;
            }
            $last = $points[$j - 1];
            $seconds = $this->timestamp($last['time']) - $this->timestamp($anchor['time']);
            if ($seconds >= self::MIN_STOP_MINUTES * 60) {
                $n = $j - $i;
                $stops[] = [
                    'lat' => round($sumLat / $n, 6),
                    'lon' => round($sumLon / $n, 6),
                    'arrived_at' => $anchor['time'],
                    'left_at' => $last['time'],
                    'minutes' => (int) round($seconds / 60),
                ];
                $i = $j;
            } else {
                $i++;
            }
        }

        return $stops;
    }

    /** Merge stops of different trips that are close; most trips first, then longest total stay. */
    public function cluster(array $stopsByTrip, int $limit = 5): array
    {
        $clusters = [];
        foreach ($stopsByTrip as $tripIndex => $entry) {
            foreach ($entry['stops'] as $stop) {
                $found = null;
                foreach ($clusters as $index => $cluster) {
                    if ($this->distance($cluster['lat'], $cluster['lon'], $stop['lat'], $stop['lon']) <= self::CLUSTER_RADIUS_M) {
                        $found = $index;
                        break;
                    }
                }
                $visit = [
                    'date' => $entry['trip']['date'],
                    'plate_no' => $entry['trip']['plate_no'],
                    'arrived_at' => $stop['arrived_at'],
                    'left_at' => $stop['left_at'],
                    'minutes' => $stop['minutes'],
                ];
                if ($found === null) {
                    $clusters[] = ['lat' => $stop['lat'], 'lon' => $stop['lon'], 'trip_keys' => [$tripIndex => true], 'minutes' => $stop['minutes'], 'visits' => [$visit], 'n' => 1];
                    continue;
                }
                $cluster = &$clusters[$found];
                $cluster['lat'] = ($cluster['lat'] * $cluster['n'] + $stop['lat']) / ($cluster['n'] + 1);
                $cluster['lon'] = ($cluster['lon'] * $cluster['n'] + $stop['lon']) / ($cluster['n'] + 1);
                $cluster['n']++;
                $cluster['trip_keys'][$tripIndex] = true;
                $cluster['minutes'] += $stop['minutes'];
                $cluster['visits'][] = $visit;
                unset($cluster);
            }
        }

        return collect($clusters)
            ->map(fn (array $cluster) => [
                'lat' => round($cluster['lat'], 6),
                'lon' => round($cluster['lon'], 6),
                'trips' => count($cluster['trip_keys']),
                'total_minutes' => $cluster['minutes'],
                'visits' => $cluster['visits'],
            ])
            ->sort(fn (array $a, array $b) => [$b['trips'], $b['total_minutes']] <=> [$a['trips'], $a['total_minutes']])
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Past ship days (newest first) on which this customer was on a truck, with the truck's GPS id.
     *
     * @return array<int, array{date:string, plate_no:string, gps_id:string}>
     */
    public function recentTrips(string $company, int $customerId, int $days = 90): array
    {
        $since = now('Asia/Bangkok')->subDays($days)->startOfDay()->format('Y-m-d H:i:s');
        $today = now('Asia/Bangkok')->startOfDay()->format('Y-m-d H:i:s');
        $hasGps = $this->queueService->truckHasDtcGpsColumn();

        $rows = DB::connection('sqlsrv_menam')->select(
            "SELECT TOP 30 CAST(ta.ship_posted_at AS date) AS ship_date, ta.truck_source, ta.manual_plate_no,
                    tm.plate_no AS master_plate_no" . ($hasGps ? ', tm.dtc_gps_id' : '') . "
             FROM delivery_plan_data dp
             JOIN delivery_plan_truck_assign ta ON ta.id = (
                 SELECT MAX(x.id) FROM delivery_plan_truck_assign x
                 WHERE x.ord_id = dp.ord_id AND CAST(x.ship_posted_at AS date) = CAST(dp.ship_posted_at AS date))
             LEFT JOIN delivery_plan_truck_master tm ON tm.id = ta.truck_id
             WHERE dp.customer_id = ? AND COALESCE(NULLIF(dp.company, N''), N'MSW') = ?
               AND dp.ship_posted_at >= ? AND dp.ship_posted_at < ?
             GROUP BY CAST(ta.ship_posted_at AS date), ta.truck_source, ta.manual_plate_no, tm.plate_no" . ($hasGps ? ', tm.dtc_gps_id' : '') . "
             ORDER BY CAST(ta.ship_posted_at AS date) DESC",
            [$customerId, $company, $since, $today]
        );

        $gpsByPlate = [];
        foreach ($this->dtc->cachedVehicleOptions() as $gpsId => $plate) {
            $gpsByPlate[$this->queueService->normalizePlate($plate)] = (string) $gpsId;
        }

        $trips = [];
        foreach ($rows as $row) {
            $manual = strtoupper(trim((string) $row->truck_source)) === 'MANUAL';
            $plate = trim((string) ($manual ? $row->manual_plate_no : $row->master_plate_no));
            if ($plate === '') {
                continue;
            }
            $gpsId = trim((string) ($row->dtc_gps_id ?? ''));
            if ($gpsId === '') {
                $gpsId = $gpsByPlate[$this->queueService->normalizePlate($plate)] ?? '';
            }
            $date = substr((string) $row->ship_date, 0, 10);
            $trips[$date . '|' . $gpsId . '|' . $plate] = ['date' => $date, 'plate_no' => $plate, 'gps_id' => $gpsId];
        }

        return array_values($trips);
    }

    /** Pins of the other customers on the same truck and day (their stops are not this customer). */
    private function otherPinnedStops(array $trip, string $company, int $customerId): array
    {
        $plateKey = $this->queueService->normalizePlate($trip['plate_no']);
        $deliveries = $this->queueService->forDate($trip['date'])
            ->filter(fn (array $queued) => $queued['plate_key'] === $plateKey)
            ->flatMap(fn (array $queued) => $queued['deliveries'])
            ->reject(fn (array $delivery) => (int) ($delivery['customer_id'] ?? 0) === $customerId
                && $this->queueService->customerCompany($delivery['company'] ?? null) === $company)
            ->values()
            ->all();

        $pins = [];
        foreach ($this->locationService->locationsForDeliveries($deliveries) as $byAddress) {
            foreach ($byAddress as $location) {
                $pins[] = [(float) $location['lat'], (float) $location['lon'], max(self::STOP_RADIUS_M, (int) $location['radius_m'])];
            }
        }

        return $pins;
    }

    /** @param array{0:float,1:float,2:int} $pin */
    private function isNear(array $stop, array $pin): bool
    {
        return $this->distance($stop['lat'], $stop['lon'], $pin[0], $pin[1]) <= $pin[2];
    }

    private function timestamp($value): ?int
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new \DateTimeZone('Asia/Bangkok'));

        return $date === false ? null : $date->getTimestamp();
    }

    private function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $rad = M_PI / 180;
        $a = sin(($lat2 - $lat1) * $rad / 2) ** 2 + cos($lat1 * $rad) * cos($lat2 * $rad) * sin(($lon2 - $lon1) * $rad / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
