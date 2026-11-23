<?php

namespace App\Services\FormDP;

use Throwable;

/**
 * "Where is this truck?" for a DTC realtime row, from our own data only (no external geocoding:
 * coordinates never leave the company systems). First match wins:
 *   1. company area (same 300 m geofence as the tracking map)
 *   2. a customer pin (gps_customer_locations) — within its radius
 *   3. the DTC station the device reports it is in (station_name)
 *   4. the nearest DTC POI (gps_dtc_pois) — within max(area_m, 200 m)
 *   5. STOPPED trucks only: Longdo Map nearby POI (≤ 500 m) and road name, when LONGDO_MAP_API_KEY is set
 *   6. the closest customer pin / DTC POI within 2 km ("ห่าง 425 ม. จาก …"), with the area as detail
 *   7. sub-district / district / province reported by DTC
 */
class GpsPlaceResolver
{
    public const COMPANY = [13.547532, 100.787167];
    public const COMPANY_RADIUS_M = 300;
    public const COMPANY_NAME = 'บริษัท แม่น้ำ สเตนเลสไวร์';
    private const POI_MIN_RADIUS_M = 200;
    public const NEAR_LIMIT_M = 2000;

    private ?array $pins = null;
    private ?array $pois = null;

    public function __construct(
        private GpsCustomerLocationService $locations,
        private LongdoPlaceService $longdo
    ) {
    }

    /** Adds "place" => ['kind', 'label', 'detail'|null] to every row that has a position. */
    public function withPlaces(array $rows): array
    {
        return array_map(function ($row) {
            if (!is_array($row)) {
                return $row;
            }
            $row['place'] = $this->resolve($row);

            return $row;
        }, $rows);
    }

    public function resolve(array $row): ?array
    {
        $lat = is_numeric($row['lat'] ?? null) ? (float) $row['lat'] : null;
        $lon = is_numeric($row['lon'] ?? null) ? (float) $row['lon'] : null;
        if ($lat === null || $lon === null || ($lat == 0.0 && $lon == 0.0)) {
            return null;
        }
        $area = $this->areaText($row);

        if ($this->distance($lat, $lon, self::COMPANY[0], self::COMPANY[1]) <= self::COMPANY_RADIUS_M) {
            return ['kind' => 'company', 'label' => self::COMPANY_NAME, 'detail' => null];
        }

        $pin = $this->nearest($this->pins(), $lat, $lon, fn (array $p) => max(20, $p['radius_m']));
        if ($pin !== null) {
            return ['kind' => 'customer', 'label' => $pin['name'], 'detail' => $area];
        }

        $station = trim((string) ($row['station_name'] ?? ''));
        if ($station !== '' && $station !== '-') {
            return ['kind' => 'station', 'label' => $station, 'detail' => $area];
        }

        $poi = $this->nearest($this->pois(), $lat, $lon, fn (array $p) => max(self::POI_MIN_RADIUS_M, (int) ($p['area_m'] ?? 0)));
        if ($poi !== null) {
            return ['kind' => 'poi', 'label' => $poi['poi_name'], 'detail' => $area];
        }

        // Stopped somewhere unknown to us: ask Longdo Map for the real nearby place + road (cached).
        if (is_numeric($row['gps_speed'] ?? null) && (float) $row['gps_speed'] == 0.0) {
            $longdo = $this->longdo->lookup($lat, $lon);
            if ($longdo !== null && ($longdo['near'] || $longdo['road'])) {
                $detail = implode(' · ', array_filter([$longdo['near'] ? $longdo['road'] : null, $area]));
                if ($longdo['near']) {
                    return ['kind' => 'near', 'label' => $longdo['near'], 'distance_m' => $longdo['near_distance_m'], 'detail' => $detail ?: null, 'source' => 'longdo'];
                }

                return ['kind' => 'road', 'label' => $longdo['road'], 'detail' => $area, 'source' => 'longdo'];
            }
        }

        // Not at a known place: name the closest known place (customer pin or DTC POI) within NEAR_LIMIT_M.
        $near = $this->closest($lat, $lon);
        if ($near !== null) {
            return ['kind' => 'near', 'label' => $near['name'], 'distance_m' => $near['distance_m'], 'detail' => $area];
        }

        return $area !== null ? ['kind' => 'area', 'label' => $area, 'detail' => null] : null;
    }

    /** @return array{name:string, distance_m:int}|null */
    private function closest(float $lat, float $lon): ?array
    {
        $best = null;
        foreach ([...array_map(fn ($p) => [$p['name'], $p['lat'], $p['lon']], $this->pins()),
                  ...array_map(fn ($p) => [$p['poi_name'], $p['lat'], $p['lon']], $this->pois())] as [$name, $pLat, $pLon]) {
            $distance = $this->distance($lat, $lon, (float) $pLat, (float) $pLon);
            if ($distance <= self::NEAR_LIMIT_M && ($best === null || $distance < $best['distance_m'])) {
                $best = ['name' => trim((string) $name), 'distance_m' => (int) round($distance)];
            }
        }

        return $best !== null && $best['name'] !== '' ? $best : null;
    }

    /** "ต.บางพลีใหญ่ อ.บางพลี จ.สมุทรปราการ" from the DTC address fields (prefixes added once). */
    public function areaText(array $row): ?string
    {
        $parts = [];
        foreach (['sub_district_th' => 'ต.', 'district_th' => 'อ.', 'province_th' => 'จ.'] as $field => $prefix) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value === '' || $value === '-') {
                continue;
            }
            $value = (string) preg_replace('/^(ตำบล|ต\.|แขวง|อำเภอ|อ\.|เขต|จังหวัด|จ\.)\s*/u', '', $value);
            $isBangkok = str_contains((string) ($row['province_th'] ?? ''), 'กรุงเทพ');
            $parts[] = ($isBangkok && $field !== 'province_th' ? ($field === 'sub_district_th' ? 'แขวง' : 'เขต') : $prefix) . $value;
        }

        return $parts !== [] ? implode(' ', $parts) : null;
    }

    private function nearest(array $points, float $lat, float $lon, callable $radiusOf): ?array
    {
        $best = null;
        $bestDistance = INF;
        foreach ($points as $point) {
            $distance = $this->distance($lat, $lon, (float) $point['lat'], (float) $point['lon']);
            if ($distance <= $radiusOf($point) && $distance < $bestDistance) {
                $best = $point;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    private function pins(): array
    {
        try {
            return $this->pins ??= $this->locations->activePins();
        } catch (Throwable) {
            return $this->pins = [];
        }
    }

    private function pois(): array
    {
        try {
            return $this->pois ??= $this->locations->tablesReady() ? $this->locations->poiRows() : [];
        } catch (Throwable) {
            return $this->pois = [];
        }
    }

    private function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $rad = M_PI / 180;
        $a = sin(($lat2 - $lat1) * $rad / 2) ** 2 + cos($lat1 * $rad) * cos($lat2 * $rad) * sin(($lon2 - $lon1) * $rad / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
