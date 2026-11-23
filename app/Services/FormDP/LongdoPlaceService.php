<?php

namespace App\Services\FormDP;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Real place names around a coordinate from Longdo Map (only used for STOPPED trucks).
 *   - Nearby POI:  GET https://api.longdo.com/POIService/json/search?lon&lat&span&limit&locale&key
 *   - Address:     GET https://api.longdo.com/map/services/address?lon&lat&noelevation=1&key
 *
 * Request saving: one lookup per ~11 m grid cell, cached 30 days. Longdo's quota resets every calendar
 * month (Active Cycle 1st–last day), so usage is counted per month in storage/app/longdo-usage/YYYY-MM.json
 * (survives cache:clear) with a monthly cap (dtc.longdo_monthly_limit) and a daily cap
 * (dtc.longdo_daily_limit). Any error or cap just returns null → the page falls back to our own data.
 * Disabled (no request at all) until LONGDO_MAP_API_KEY is set.
 */
class LongdoPlaceService
{
    private const BASE = 'https://api.longdo.com';
    private const CACHE_PREFIX = 'longdo.place.v1.';
    private const USAGE_DIR = 'longdo-usage';

    public function enabled(): bool
    {
        return trim((string) config('dtc.longdo_key', '')) !== '';
    }

    /**
     * @return array{near:?string, near_distance_m:?int, road:?string}|null  null = not available
     */
    public function lookup(float $lat, float $lon): ?array
    {
        if (!$this->enabled()) {
            return null;
        }
        $cacheKey = self::CACHE_PREFIX . number_format($lat, 4, '.', '') . ',' . number_format($lon, 4, '.', '');
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        if (!$this->reserve(2)) {
            return null; // daily cap reached: fall back silently
        }

        try {
            $near = $this->nearestPoi($lat, $lon);
            $road = $this->road($lat, $lon);
        } catch (Throwable $e) {
            Log::warning('Longdo place lookup failed', ['message' => mb_substr($e->getMessage(), 0, 200)]);

            return null;
        }

        $result = [
            'near' => $near['name'] ?? null,
            'near_distance_m' => $near['distance_m'] ?? null,
            'road' => $road,
        ];
        Cache::put($cacheKey, $result, now()->addDays(30));

        return $result;
    }

    public function requestsToday(): int
    {
        return $this->usageSummary()['today'];
    }

    /** Usage of the current Longdo cycle (calendar month, Asia/Bangkok). */
    /**
     * Places whose name matches $keyword (company search for suggested customer pins), cached 30 days.
     * GET https://search.longdo.com/mapsearch/json/search?keyword&limit&locale&key — 1 request.
     *
     * @return array<int, array{name:string, lat:float, lon:float, address:?string}>|null  null = not available
     */
    public function searchByName(string $keyword, int $limit = 5): ?array
    {
        $keyword = trim($keyword);
        if (!$this->enabled() || mb_strlen($keyword) < 2) {
            return null;
        }
        $cacheKey = 'longdo.search.v1.' . sha1(mb_strtolower($keyword));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        if (!$this->reserve(1)) {
            return null;
        }

        try {
            $response = $this->client()->get('https://search.longdo.com/mapsearch/json/search', [
                'keyword' => $keyword,
                'limit' => max(1, min(10, $limit)),
                'locale' => 'th',
                'key' => (string) config('dtc.longdo_key'),
            ])->throw()->json();
        } catch (Throwable $e) {
            Log::warning('Longdo name search failed', ['message' => mb_substr($e->getMessage(), 0, 200)]);

            return null;
        }

        $places = [];
        foreach ((array) ($response['data'] ?? []) as $place) {
            if (!is_array($place) || !empty($place['obsoleted']) || !is_numeric($place['lat'] ?? null) || !is_numeric($place['lon'] ?? null)) {
                continue;
            }
            $name = trim((string) ($place['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $places[] = [
                'name' => mb_substr($name, 0, 200),
                'lat' => round((float) $place['lat'], 6),
                'lon' => round((float) $place['lon'], 6),
                'address' => isset($place['address']) && trim((string) $place['address']) !== '' ? mb_substr(trim((string) $place['address']), 0, 200) : null,
            ];
        }
        Cache::put($cacheKey, $places, now()->addDays(30));

        return $places;
    }

    /** Cached name-search result only (never sends a request); null = not searched yet. */
    public function cachedSearchByName(string $keyword): ?array
    {
        $cached = Cache::get('longdo.search.v1.' . sha1(mb_strtolower(trim($keyword))));

        return is_array($cached) ? $cached : null;
    }

    public function usageSummary(): array
    {
        $now = now('Asia/Bangkok');
        $usage = $this->readUsage($now->format('Y-m'));

        return [
            'enabled' => $this->enabled(),
            'cycle_start' => $now->copy()->startOfMonth()->toDateString(),
            'cycle_end' => $now->copy()->endOfMonth()->toDateString(),
            'month_total' => (int) $usage['total'],
            'monthly_limit' => max(0, (int) config('dtc.longdo_monthly_limit', 90000)),
            'today' => (int) ($usage['by_day'][$now->toDateString()] ?? 0),
            'daily_limit' => max(0, (int) config('dtc.longdo_daily_limit', 1000)),
        ];
    }

    /** @return array{name:string, distance_m:int}|null */
    private function nearestPoi(float $lat, float $lon): ?array
    {
        $response = $this->client()->get(self::BASE . '/POIService/json/search', [
            'lon' => $lon,
            'lat' => $lat,
            'span' => max(50, (int) config('dtc.longdo_nearby_span_m', 500)) . 'm',
            'limit' => 5,
            'locale' => 'th',
            'key' => (string) config('dtc.longdo_key'),
        ])->throw()->json();

        $best = null;
        foreach ((array) ($response['data'] ?? []) as $poi) {
            if (!is_array($poi) || !empty($poi['obsoleted'])) {
                continue;
            }
            $name = trim((string) ($poi['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $distance = $this->distanceOf($poi, $lat, $lon);
            if ($best === null || $distance < $best['distance_m']) {
                $best = ['name' => mb_substr($name, 0, 120), 'distance_m' => $distance];
            }
        }

        return $best;
    }

    private function road(float $lat, float $lon): ?string
    {
        $response = $this->client()->get(self::BASE . '/map/services/address', [
            'lon' => $lon,
            'lat' => $lat,
            'noelevation' => 1,
            'nowater' => 1,
            'locale' => 'th',
            'key' => (string) config('dtc.longdo_key'),
        ])->throw()->json();

        $road = trim((string) ($response['road'] ?? ''));

        return $road !== '' ? mb_substr($road, 0, 120) : null;
    }

    /** Longdo "distance" may be "120 m" / "1.2 km" / a number; fall back to our own calculation. */
    private function distanceOf(array $poi, float $lat, float $lon): int
    {
        $raw = trim((string) ($poi['distance'] ?? ''));
        if (preg_match('/^([\d.]+)\s*(km|m)?$/i', $raw, $m)) {
            return (int) round((float) $m[1] * (strtolower($m[2] ?? 'm') === 'km' ? 1000 : 1));
        }
        if (is_numeric($poi['lat'] ?? null) && is_numeric($poi['lon'] ?? null)) {
            $rad = M_PI / 180;
            $a = sin(((float) $poi['lat'] - $lat) * $rad / 2) ** 2 + cos($lat * $rad) * cos((float) $poi['lat'] * $rad) * sin(((float) $poi['lon'] - $lon) * $rad / 2) ** 2;

            return (int) round(6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a)));
        }

        return PHP_INT_MAX;
    }

    /** Count $requests against this month's cycle and today, refusing when either cap would be passed. */
    private function reserve(int $requests): bool
    {
        $now = now('Asia/Bangkok');
        $month = $now->format('Y-m');
        $today = $now->toDateString();
        $monthlyLimit = max(0, (int) config('dtc.longdo_monthly_limit', 90000));
        $dailyLimit = max(0, (int) config('dtc.longdo_daily_limit', 1000));

        try {
            return (bool) Cache::lock('longdo.usage_lock', 5)->block(3, function () use ($month, $today, $requests, $monthlyLimit, $dailyLimit) {
                $usage = $this->readUsage($month);
                $day = (int) ($usage['by_day'][$today] ?? 0);
                if ((int) $usage['total'] + $requests > $monthlyLimit || $day + $requests > $dailyLimit) {
                    return false;
                }
                $usage['total'] = (int) $usage['total'] + $requests;
                $usage['by_day'][$today] = $day + $requests;
                $usage['updated_at'] = now('Asia/Bangkok')->toIso8601String();
                Storage::disk('local')->put(self::USAGE_DIR . "/{$month}.json", json_encode($usage, JSON_PRETTY_PRINT));

                return true;
            });
        } catch (Throwable) {
            return false; // cannot count safely → do not call Longdo
        }
    }

    private function readUsage(string $month): array
    {
        $path = self::USAGE_DIR . "/{$month}.json";
        $data = Storage::disk('local')->exists($path) ? json_decode((string) Storage::disk('local')->get($path), true) : null;

        return [
            'month' => $month,
            'total' => (int) ($data['total'] ?? 0),
            'by_day' => is_array($data['by_day'] ?? null) ? $data['by_day'] : [],
        ];
    }

    private function client()
    {
        return Http::acceptJson()->timeout(4)->connectTimeout(3);
    }
}
