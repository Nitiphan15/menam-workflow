<?php

namespace App\Services\FormDP;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Suggested pins for customers that have none yet, from three sources:
 *   dtc_poi — DTC POI whose name resembles the customer (no request)
 *   longdo  — Longdo name search (1 request per customer when asked, cached 30 days)
 *   stops   — a spot where our trucks stayed on the days the customer was queued (no request;
 *             from gps_trip_stop / gps_stop_visit filled by gps:summarize-day)
 *
 * Evidence: on how many of the customer's queued truck-days a truck stayed within EVIDENCE_RADIUS_M.
 * Confidence:
 *   high   — name matches (score ≥ NAME_MATCH) AND a truck stayed there on a queued day
 *   medium — name matches but no stay evidence yet, or a repeated stop spot (≥2 days, ≥75% specific,
 *            ≥50% of the customer's days) that is not a shared hub
 *   low    — anything else
 * A spot that is the best answer for several customers is downgraded and flagged "shared".
 * Nothing is saved here: a person confirms each pin.
 */
class GpsPinSuggestionService
{
    public const EVIDENCE_RADIUS_M = 300;
    public const SPOT_RADIUS_M = 250;
    public const SAME_PLACE_M = 150;
    public const NAME_MATCH = 80;
    public const SPOT_MIN_SPECIFIC = 0.67;    // share of the spot's truck-days that had this customer queued
    public const SPOT_STRONG_SPECIFIC = 0.75;
    private const MAX_CANDIDATES = 3;

    private ?array $stayIndex = null;

    public function __construct(
        private GpsCustomerLocationService $locations,
        private LongdoPlaceService $longdo,
        private GpsDailySummaryService $summary
    ) {
    }

    /**
     * @param array<int, string>|null $only "company|customer_id" keys to limit the result (null = all unpinned)
     */
    public function suggestions(?array $only = null): array
    {
        $customers = array_values(array_filter(
            $this->locations->customerList(),
            fn (array $c) => $c['status'] !== 'pinned' && ($only === null || in_array($c['company'] . '|' . $c['customer_id'], $only, true))
        ));

        $rows = [];
        foreach ($customers as $customer) {
            $key = $customer['company'] . '|' . $customer['customer_id'];
            $days = $this->queueDays()[$key] ?? [];
            $longdoResult = $this->longdo->enabled() ? $this->longdo->cachedSearchByName($this->searchKeyword($customer['name'])) : null;
            $candidates = $this->dedupe([
                ...$this->fromDtcPois($customer['name'], $days),
                ...$this->fromLongdo($customer['name'], $longdoResult ?? [], $days),
                ...$this->fromStops($days),
            ]);
            $rows[] = [
                'company' => $customer['company'],
                'customer_id' => $customer['customer_id'],
                'name' => $customer['name'],
                'line_count' => $customer['line_count'],
                'queue_days' => count($days),
                'longdo_searched' => $longdoResult !== null,
                'longdo_available' => $this->longdo->enabled(),
                'candidates' => $candidates,
            ];
        }

        return $this->flagShared($rows);
    }

    /** Ask Longdo for these customers (1 request each unless cached), then return their fresh suggestions. */
    public function searchLongdo(array $keys): array
    {
        $byKey = collect($this->locations->customerList())->keyBy(fn ($c) => $c['company'] . '|' . $c['customer_id']);
        $used = 0;
        foreach ($keys as $key) {
            $customer = $byKey->get($key);
            if ($customer && $this->longdo->cachedSearchByName($this->searchKeyword($customer['name'])) === null) {
                $this->longdo->searchByName($this->searchKeyword($customer['name']));
                $used++;
            }
        }

        return ['requests' => $used, 'rows' => $this->suggestions($keys)];
    }

    /** "บจก.ไทยสแตนเลส สกรู(สำนักงานใหญ่)" → "ไทยสแตนเลส สกรู" (spaces kept for the search engine). */
    public function searchKeyword(string $name): string
    {
        $name = (string) preg_replace('/\(.*?\)|（.*?）/u', ' ', $name);
        $name = (string) preg_replace('/(บริษัท|บจก\.?|บจ\.?|หจก\.?|ห้างหุ้นส่วนจำกัด|จำกัด|มหาชน|co\.?,?\s*ltd\.?|company|limited|public|\bpcl\b|\bltd\.?)/iu', ' ', $name);

        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    // ---------- sources ----------

    private function fromDtcPois(string $name, array $days): array
    {
        return array_map(fn (array $poi) => $this->candidate('dtc_poi', $poi['poi_name'], (float) $poi['lat'], (float) $poi['lon'], (int) $poi['score'], $days, [
            'dtc_poi_id' => $poi['poi_id'],
            'radius_m' => max(GpsCustomerLocationService::DEFAULT_RADIUS_M, (int) ($poi['area_m'] ?? 0)),
        ]), $this->locations->suggestPois($name, self::MAX_CANDIDATES));
    }

    private function fromLongdo(string $name, array $places, array $days): array
    {
        $target = $this->locations->normalizeName($name);
        $out = [];
        foreach ($places as $place) {
            $score = $this->nameScore($target, $this->locations->normalizeName($place['name']));
            if ($score >= 55) {
                $out[] = $this->candidate('longdo', $place['name'], $place['lat'], $place['lon'], $score, $days, ['address' => $place['address']]);
            }
        }

        return $out;
    }

    /** Repeated stay spots on the customer's queued days that are specific to this customer. */
    private function fromStops(array $days): array
    {
        if ($days === []) {
            return [];
        }
        $out = [];
        foreach ($this->stayIndex()['spots'] as $spot) {
            $support = count(array_intersect_key($spot['days'], $days));
            if ($support === 0) {
                continue;
            }
            $precision = $support / count($spot['days']);
            $coverage = $support / count($days);
            // A spot also used on many days without this customer (depot, rest stop) is not them.
            if ($precision < self::SPOT_MIN_SPECIFIC || $coverage < 0.5) {
                continue;
            }
            $strong = $support >= 2 && $precision >= self::SPOT_STRONG_SPECIFIC;
            $out[] = [
                'source' => 'stops',
                'label' => $spot['label'] ?: 'จุดที่รถจอดซ้ำ',
                'lat' => round($spot['lat'], 6),
                'lon' => round($spot['lon'], 6),
                'name_score' => null,
                'stayed_days' => $support,
                'queue_days' => count($days),
                'specific_percent' => (int) round($precision * 100),
                'confidence' => $strong ? 'medium' : 'low',
                'radius_m' => GpsCustomerLocationService::DEFAULT_RADIUS_M,
                'dtc_poi_id' => null,
                'address' => null,
                'shared' => false,
            ];
        }

        return $out;
    }

    private function candidate(string $source, string $label, float $lat, float $lon, int $score, array $days, array $extra): array
    {
        $stayed = $this->stayedDays($lat, $lon, $days);
        $nameOk = $score >= self::NAME_MATCH;
        $confidence = $nameOk && $stayed > 0 ? 'high' : ($nameOk ? 'medium' : 'low');

        return [
            'source' => $source,
            'label' => $label,
            'lat' => round($lat, 6),
            'lon' => round($lon, 6),
            'name_score' => $score,
            'stayed_days' => $stayed,
            'queue_days' => count($days),
            'specific_percent' => null,
            'confidence' => $confidence,
            'radius_m' => $extra['radius_m'] ?? GpsCustomerLocationService::DEFAULT_RADIUS_M,
            'dtc_poi_id' => $extra['dtc_poi_id'] ?? null,
            'address' => $extra['address'] ?? null,
            'shared' => false,
        ];
    }

    // ---------- ranking ----------

    /** Merge candidates closer than SAME_PLACE_M (keep the better one, combine evidence), best 3 first. */
    private function dedupe(array $candidates): array
    {
        $rank = fn (array $c) => [['high' => 3, 'medium' => 2, 'low' => 1][$c['confidence']], $c['stayed_days'], $c['specific_percent'] ?? 0, $c['name_score'] ?? 0];
        usort($candidates, fn ($a, $b) => $rank($b) <=> $rank($a));
        $kept = [];
        foreach ($candidates as $candidate) {
            foreach ($kept as $existing) {
                if ($this->distance($existing['lat'], $existing['lon'], $candidate['lat'], $candidate['lon']) <= self::SAME_PLACE_M) {
                    continue 2;
                }
            }
            $kept[] = $candidate;
        }

        return array_slice($kept, 0, self::MAX_CANDIDATES);
    }

    /** The same spot as the best answer of several customers: downgrade and flag it. */
    private function flagShared(array $rows): array
    {
        $tops = [];
        foreach ($rows as $i => $row) {
            if (isset($row['candidates'][0])) {
                $tops[$i] = $row['candidates'][0];
            }
        }
        foreach ($tops as $i => $top) {
            foreach ($tops as $j => $other) {
                if ($i !== $j && $this->distance($top['lat'], $top['lon'], $other['lat'], $other['lon']) <= self::SAME_PLACE_M) {
                    $rows[$i]['candidates'][0]['shared'] = true;
                    $rows[$i]['candidates'][0]['confidence'] = $top['confidence'] === 'high' ? 'medium' : 'low';
                    break;
                }
            }
        }

        return $rows;
    }

    private function nameScore(string $a, string $b): int
    {
        if ($a === '' || $b === '') {
            return 0;
        }
        if ($a === $b) {
            return 100;
        }
        if (min(mb_strlen($a), mb_strlen($b)) >= 4 && (str_contains($a, $b) || str_contains($b, $a))) {
            return 85;
        }
        similar_text($a, $b, $percent);

        return (int) round($percent);
    }

    // ---------- evidence from stored stays ----------

    /** "company|customer_id" => ["ship_date|plate_key" => true] for queued days in the summary tables. */
    private function queueDays(): array
    {
        return $this->stayIndex()['queue'];
    }

    private function stayedDays(float $lat, float $lon, array $days): int
    {
        if ($days === []) {
            return 0;
        }
        $hit = [];
        foreach ($this->stayIndex()['stays'] as $stay) {
            if (isset($days[$stay['day']]) && !isset($hit[$stay['day']])
                && $this->distance($lat, $lon, $stay['lat'], $stay['lon']) <= self::EVIDENCE_RADIUS_M) {
                $hit[$stay['day']] = true;
            }
        }

        return count($hit);
    }

    private function stayIndex(): array
    {
        if ($this->stayIndex !== null) {
            return $this->stayIndex;
        }
        $empty = ['queue' => [], 'stays' => [], 'spots' => []];
        try {
            if (!$this->summary->tablesReady() || !$this->summary->timelineTablesReady()) {
                return $this->stayIndex = $empty;
            }
            $since = now('Asia/Bangkok')->subDays(90)->toDateString();
            $db = DB::connection('sqlsrv_menam');

            // Only truck-days that really have a GPS route count as evidence days.
            $routed = [];
            foreach ($db->table('gps_trip_daily')->where('ship_date', '>=', $since)->where('has_route', 1)->get(['ship_date', 'plate_key']) as $row) {
                $routed[substr((string) $row->ship_date, 0, 10) . '|' . $row->plate_key] = true;
            }
            $queue = [];
            foreach ($db->table('gps_stop_visit')->where('ship_date', '>=', $since)->where('status', '<>', 'removed')
                ->get(['ship_date', 'plate_key', 'company', 'customer_id']) as $row) {
                if ($row->customer_id !== null && isset($routed[substr((string) $row->ship_date, 0, 10) . '|' . $row->plate_key])) {
                    $queue[$row->company . '|' . (int) $row->customer_id][substr((string) $row->ship_date, 0, 10) . '|' . $row->plate_key] = true;
                }
            }

            $stays = [];
            $spots = [];
            foreach ($db->table('gps_trip_stop')->where('ship_date', '>=', $since)->whereIn('kind', ['unknown', 'unplanned', 'customer'])
                ->get(['ship_date', 'plate_key', 'kind', 'lat', 'lon', 'place_label']) as $row) {
                $stay = ['day' => substr((string) $row->ship_date, 0, 10) . '|' . $row->plate_key, 'lat' => (float) $row->lat, 'lon' => (float) $row->lon];
                $stays[] = $stay;
                if ($row->kind === 'customer') {
                    continue; // already explained by a pinned / name-matched customer
                }
                $hit = null;
                foreach ($spots as $i => $spot) {
                    if ($this->distance($spot['lat'], $spot['lon'], $stay['lat'], $stay['lon']) <= self::SPOT_RADIUS_M) {
                        $hit = $i;
                        break;
                    }
                }
                if ($hit === null) {
                    $spots[] = ['lat' => $stay['lat'], 'lon' => $stay['lon'], 'days' => [$stay['day'] => true], 'label' => $row->place_label];
                } else {
                    $spots[$hit]['days'][$stay['day']] = true;
                    $spots[$hit]['label'] ??= $row->place_label;
                }
            }

            return $this->stayIndex = ['queue' => $queue, 'stays' => $stays, 'spots' => $spots];
        } catch (Throwable $e) {
            report($e);

            return $this->stayIndex = $empty;
        }
    }

    private function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $rad = M_PI / 180;
        $a = sin(($lat2 - $lat1) * $rad / 2) ** 2 + cos($lat1 * $rad) * cos($lat2 * $rad) * sin(($lon2 - $lon1) * $rad / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
