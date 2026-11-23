<?php

namespace App\Services\FormDP;

/**
 * One truck/day route → stops and travel legs (pure, except naming unplanned stops).
 *
 * Stops: every stay of GpsStopSuggestionService::MIN_STOP_MINUTES+ within STOP_RADIUS_M, classified as
 *   company   — inside the company area
 *   customer  — at the pin of a customer on this truck's queue that day
 *   customer  — or, when the customer has no pin yet, the place name there matches the customer name
 *   unknown   — not matched while some customers on the queue have no pin (could be one of them)
 *   unplanned — anywhere else while every queued customer is pinned (named by GpsPlaceResolver)
 * Legs: travel between consecutive known places (company / customer). Time stopped in between at
 * unplanned or not-yet-identified stops is reported separately (unplanned_minutes), so
 * drive_minutes = minutes - unplanned_minutes.
 * The company departure / return come from the trip metrics when the truck did not stay 10+ minutes there.
 */
class GpsTripTimelineService
{
    public const COMPANY = GpsDailySummaryService::COMPANY;
    public const COMPANY_RADIUS_M = GpsDailySummaryService::COMPANY_RADIUS_M;
    private const CUSTOMER_EXTRA_M = 50; // pin radius + tolerance for GPS drift of a parked truck

    private const NAME_MATCH_MAX_M = 300; // a "near X" place further than this is not a name match

    public function __construct(
        private GpsStopSuggestionService $stopFinder,
        private GpsPlaceResolver $placeResolver,
        private GpsCustomerLocationService $locations
    ) {
    }

    /**
     * @param array $deliveries each: customer, company, customer_id, stop_key, location{lat,lon,radius_m}|null
     * @param array $metrics    GpsDailySummaryService::tripMetrics() of the same points
     * @return array{stops: array<int, array>, legs: array<int, array>}
     */
    public function build(array $points, array $deliveries, array $metrics, bool $namePlaces = true): array
    {
        $stops = [];
        foreach ($this->stopFinder->stopsFromPoints($points) as $stay) {
            $stops[] = $this->classify($stay, $deliveries, $namePlaces);
        }

        return ['stops' => $stops, 'legs' => $this->legs($points, $stops, $metrics)];
    }

    private function classify(array $stay, array $deliveries, bool $namePlaces): array
    {
        $base = [
            'arrived_at' => $stay['arrived_at'],
            'left_at' => $stay['left_at'],
            'minutes' => $stay['minutes'],
            'lat' => $stay['lat'],
            'lon' => $stay['lon'],
            'stop_key' => null,
            'customer_name' => null,
            'company' => null,
            'customer_id' => null,
            'place' => null,
        ];

        if ($this->distance($stay['lat'], $stay['lon'], self::COMPANY[0], self::COMPANY[1]) <= self::COMPANY_RADIUS_M) {
            return [...$base, 'kind' => 'company'];
        }

        $best = null;
        $bestDistance = INF;
        foreach ($deliveries as $delivery) {
            $location = $delivery['location'] ?? null;
            if (!is_array($location)) {
                continue;
            }
            $distance = $this->distance($stay['lat'], $stay['lon'], (float) $location['lat'], (float) $location['lon']);
            if ($distance <= max(20, (int) ($location['radius_m'] ?? 150)) + self::CUSTOMER_EXTRA_M && $distance < $bestDistance) {
                $best = $delivery;
                $bestDistance = $distance;
            }
        }
        if ($best !== null) {
            return [...$base, 'kind' => 'customer', 'stop_key' => $best['stop_key'] ?? null, 'customer_name' => $best['customer'] ?? null,
                'company' => $best['company'] ?? null, 'customer_id' => isset($best['customer_id']) ? (int) $best['customer_id'] : null];
        }

        $place = $namePlaces ? $this->placeResolver->resolve(['lat' => $stay['lat'], 'lon' => $stay['lon'], 'gps_speed' => 0]) : null;
        $unpinned = array_values(array_filter($deliveries, fn (array $delivery) => !is_array($delivery['location'] ?? null)));

        $byName = $this->matchByName($place, $unpinned);
        if ($byName !== null) {
            return [...$base, 'kind' => 'customer', 'stop_key' => $byName['stop_key'] ?? null, 'customer_name' => $byName['customer'] ?? null,
                'company' => $byName['company'] ?? null, 'customer_id' => isset($byName['customer_id']) ? (int) $byName['customer_id'] : null,
                'place' => $place];
        }

        // Some queued customers have no pin: this stay may be one of them, so it is not "unplanned".
        return [...$base, 'kind' => $unpinned !== [] ? 'unknown' : 'unplanned', 'place' => $place];
    }

    /** Unpinned queued customer whose name matches the place found at the stop (DTC POI / Longdo / pin). */
    private function matchByName(?array $place, array $unpinned): ?array
    {
        if ($place === null || $unpinned === [] || empty($place['label'])) {
            return null;
        }
        if (($place['kind'] ?? '') === 'area' || (isset($place['distance_m']) && $place['distance_m'] > self::NAME_MATCH_MAX_M)) {
            return null;
        }
        $label = $this->locations->normalizeName($place['label']);
        if (mb_strlen($label) < 3) {
            return null;
        }
        $best = null;
        $bestScore = 0;
        foreach ($unpinned as $delivery) {
            $name = $this->locations->normalizeName($delivery['customer'] ?? '');
            if (mb_strlen($name) < 3) {
                continue;
            }
            if ($name === $label || (min(mb_strlen($name), mb_strlen($label)) >= 4 && (str_contains($name, $label) || str_contains($label, $name)))) {
                $score = 100;
            } else {
                similar_text($name, $label, $score);
            }
            if ($score >= 80 && $score > $bestScore) {
                $best = $delivery;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private function legs(array $points, array $stops, array $metrics): array
    {
        // Known places in time order; add the company departure / return when there was no 10-minute stay.
        $known = array_values(array_filter($stops, fn (array $stop) => in_array($stop['kind'], ['company', 'customer'], true)));
        $left = $metrics['left_company_at'] ?? null;
        if ($left && $known !== [] && $known[0]['kind'] !== 'company' && $left < $known[0]['arrived_at']) {
            array_unshift($known, ['kind' => 'company', 'arrived_at' => $left, 'left_at' => $left, 'stop_key' => null, 'customer_name' => null, 'company' => null, 'customer_id' => null]);
        }
        $back = $metrics['returned_at'] ?? null;
        $last = $known === [] ? null : $known[count($known) - 1];
        if ($back && $last !== null && $last['kind'] !== 'company' && $last['left_at'] < $back) {
            $known[] = ['kind' => 'company', 'arrived_at' => $back, 'left_at' => $back, 'stop_key' => null, 'customer_name' => null, 'company' => null, 'customer_id' => null];
        }

        // Time stopped on the way at places that are not this leg's ends (unplanned, or not identified yet).
        $unplanned = array_values(array_filter($stops, fn (array $stop) => in_array($stop['kind'], ['unplanned', 'unknown'], true)));
        $legs = [];
        for ($i = 0; $i + 1 < count($known); $i++) {
            $from = $known[$i];
            $to = $known[$i + 1];
            if ($from['kind'] === 'company' && $to['kind'] === 'company') {
                continue;
            }
            $depart = $from['left_at'];
            $arrive = $to['arrived_at'];
            $minutes = (int) round(($this->ts($arrive) - $this->ts($depart)) / 60);
            if ($minutes < 0) {
                continue;
            }
            $onTheWay = array_sum(array_map(
                fn (array $stop) => $stop['arrived_at'] >= $depart && $stop['left_at'] <= $arrive ? $stop['minutes'] : 0,
                $unplanned
            ));
            $legs[] = [
                'from_kind' => $from['kind'],
                'from_key' => $from['stop_key'],
                'from_name' => $from['customer_name'],
                'to_kind' => $to['kind'],
                'to_key' => $to['stop_key'],
                'to_name' => $to['customer_name'],
                'company' => $to['kind'] === 'customer' ? $to['company'] : null,
                'customer_id' => $to['kind'] === 'customer' ? $to['customer_id'] : null,
                'depart_at' => $depart,
                'arrive_at' => $arrive,
                'minutes' => $minutes,
                'unplanned_minutes' => min($minutes, (int) $onTheWay),
                'drive_minutes' => max(0, $minutes - (int) $onTheWay),
                'km' => $this->kmBetween($points, $depart, $arrive),
            ];
        }

        return $legs;
    }

    private function kmBetween(array $points, string $from, string $to): ?float
    {
        $meters = 0.0;
        $previous = null;
        foreach ($points as $point) {
            $time = (string) ($point['time'] ?? '');
            if ($time < $from || $time > $to || !is_numeric($point['lat'] ?? null) || !is_numeric($point['lon'] ?? null)) {
                continue;
            }
            if ($previous !== null) {
                $meters += $this->distance((float) $previous['lat'], (float) $previous['lon'], (float) $point['lat'], (float) $point['lon']);
            }
            $previous = $point;
        }

        return $previous === null ? null : round($meters / 1000, 1);
    }

    private function ts(string $value): int
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($value, 0, 19), new \DateTimeZone('Asia/Bangkok'));

        return $date === false ? 0 : $date->getTimestamp();
    }

    private function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $rad = M_PI / 180;
        $a = sin(($lat2 - $lat1) * $rad / 2) ** 2 + cos($lat1 * $rad) * cos($lat2 * $rad) * sin(($lon2 - $lon1) * $rad / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
