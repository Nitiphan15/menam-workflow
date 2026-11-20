<?php

namespace App\Services\FormDP;

/**
 * Arrival / leave times per delivery stop, derived only from the real DTC route points
 * (getHistory) and the customer pins. No DTC request, no database.
 *
 * Rules:
 * - A point is "inside" when it is within the pin radius.
 * - Consecutive inside points form a visit; short drop-outs (GPS jitter) up to
 *   MERGE_GAP_MINUTES are merged into the same visit.
 * - A visit shorter than MIN_DWELL_MINUTES is a drive-by ("passed"), not an arrival.
 * - If the last point of the day is still inside, the truck is "still there" (no leave time).
 * We never infer "returning" from heading alone.
 */
class GpsStopVisitService
{
    public const MIN_DWELL_MINUTES = 3;
    public const MERGE_GAP_MINUTES = 5;

    /**
     * @param array<int, array{time:string, lat:float|null, lon:float|null}> $points sorted by time
     * @param array<int, array{location?: array{lat:float, lon:float, radius_m?:int}|null}> $deliveries
     * @return array<int, array{status:string, arrived_at:?string, left_at:?string, dwell_minutes:?int, visits:int, nearest_m:?int}>
     */
    public function detect(array $points, array $deliveries): array
    {
        $points = array_values(array_filter($points, fn ($point) => is_array($point)
            && is_numeric($point['lat'] ?? null) && is_numeric($point['lon'] ?? null)
            && ($this->timestamp($point['time'] ?? '') !== null)));

        $result = [];
        foreach (array_values($deliveries) as $index => $delivery) {
            $location = $delivery['location'] ?? null;
            if (!is_array($location) || !is_numeric($location['lat'] ?? null) || !is_numeric($location['lon'] ?? null)) {
                $result[$index] = $this->row('no_location');
                continue;
            }
            $result[$index] = $this->detectOne($points, (float) $location['lat'], (float) $location['lon'], max(20, (int) ($location['radius_m'] ?? 150)));
        }

        return $result;
    }

    private function detectOne(array $points, float $lat, float $lon, int $radius): array
    {
        if ($points === []) {
            return $this->row('no_route');
        }

        $runs = [];
        $current = null;
        $nearest = null;
        foreach ($points as $point) {
            $distance = $this->distanceMeters($lat, $lon, (float) $point['lat'], (float) $point['lon']);
            $nearest = $nearest === null ? $distance : min($nearest, $distance);
            $time = $this->timestamp($point['time']);
            if ($distance <= $radius) {
                if ($current !== null && $time - $current['last'] <= self::MERGE_GAP_MINUTES * 60) {
                    $current['last'] = $time;
                    $current['last_time'] = $point['time'];
                } else {
                    if ($current !== null) {
                        $runs[] = $current;
                    }
                    $current = ['first' => $time, 'first_time' => $point['time'], 'last' => $time, 'last_time' => $point['time']];
                }
            }
        }
        if ($current !== null) {
            $runs[] = $current;
        }

        $nearestM = $nearest === null ? null : (int) round($nearest);
        if ($runs === []) {
            return [...$this->row('not_visited'), 'nearest_m' => $nearestM];
        }

        $stays = array_values(array_filter($runs, fn (array $run) => ($run['last'] - $run['first']) >= self::MIN_DWELL_MINUTES * 60));
        if ($stays === []) {
            $run = $runs[0];

            return [...$this->row('passed'), 'arrived_at' => $run['first_time'], 'visits' => count($runs), 'nearest_m' => $nearestM];
        }

        // Main visit = the longest stay; report how many separate stays there were.
        usort($stays, fn (array $a, array $b) => ($b['last'] - $b['first']) <=> ($a['last'] - $a['first']));
        $main = $stays[0];
        $lastPointTime = $this->timestamp(end($points)['time']);
        $stillThere = $main['last'] === $lastPointTime;

        return [
            'status' => $stillThere ? 'at_stop' : 'visited',
            'arrived_at' => $main['first_time'],
            'left_at' => $stillThere ? null : $main['last_time'],
            'dwell_minutes' => (int) round(($main['last'] - $main['first']) / 60),
            'visits' => count($stays),
            'nearest_m' => $nearestM,
        ];
    }

    private function row(string $status): array
    {
        return ['status' => $status, 'arrived_at' => null, 'left_at' => null, 'dwell_minutes' => null, 'visits' => 0, 'nearest_m' => null];
    }

    private function timestamp($value): ?int
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new \DateTimeZone('Asia/Bangkok'));

        return $date === false ? null : $date->getTimestamp();
    }

    private function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $rad = M_PI / 180;
        $dLat = ($lat2 - $lat1) * $rad;
        $dLon = ($lon2 - $lon1) * $rad;
        $a = sin($dLat / 2) ** 2 + cos($lat1 * $rad) * cos($lat2 * $rad) * sin($dLon / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
