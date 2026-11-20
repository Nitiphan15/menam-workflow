<?php

namespace App\Services\FormDP;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Customer / ship-to locations for GPS (Phase 1).
 * Tables come from database/sql/formdp_gps_phase1_locations.sql (run by the user, not by migrations).
 * dbo.customer is an ERP ETL copy and is only read, never altered.
 */
class GpsCustomerLocationService
{
    public const DEFAULT_RADIUS_M = 150;
    private const POI_CACHE_KEY = 'dtc_gps.poi_rows.v1';

    public function __construct(private GpsTripQueueService $queueService)
    {
    }

    private function db()
    {
        return DB::connection('sqlsrv_menam');
    }

    /** True once the Phase 1 SQL script has been run (cached 10 min). */
    public function tablesReady(): bool
    {
        return (bool) Cache::remember('dtc_gps.schema.location_tables', now()->addMinutes(10), function () {
            try {
                $schema = Schema::connection('sqlsrv_menam');

                return $schema->hasTable('gps_customer_locations') && $schema->hasTable('gps_dtc_pois');
            } catch (Throwable) {
                return false;
            }
        });
    }

    public function forgetSchemaCache(): void
    {
        Cache::forget('dtc_gps.schema.location_tables');
        Cache::forget('dtc_gps.schema.truck_gps_column');
    }

    /**
     * Customers delivered in the last $days days, most frequent first, with their pin status.
     */
    public function customerList(int $days = 90): array
    {
        $since = now('Asia/Bangkok')->subDays(max(1, $days))->startOfDay()->format('Y-m-d H:i:s');
        $customers = collect($this->db()->select(
            "SELECT COALESCE(NULLIF(dp.company, N''), N'MSW') AS company,
                    dp.customer_id,
                    MAX(COALESCE(NULLIF(c.name COLLATE DATABASE_DEFAULT, N''), dp.customer_name COLLATE DATABASE_DEFAULT)) AS name,
                    COUNT(*) AS line_count,
                    COUNT(DISTINCT dp.address) AS address_count,
                    MAX(dp.ship_posted_at) AS last_ship_at
             FROM delivery_plan_data dp
             LEFT JOIN customer c
               ON c.id = dp.customer_id
              AND c.company COLLATE DATABASE_DEFAULT = COALESCE(NULLIF(dp.company, N''), N'MSW') COLLATE DATABASE_DEFAULT
             WHERE dp.ship_posted_at >= ? AND dp.customer_id IS NOT NULL
             GROUP BY COALESCE(NULLIF(dp.company, N''), N'MSW'), dp.customer_id",
            [$since]
        ));

        $pinned = $this->tablesReady()
            ? $this->db()->table('gps_customer_locations')
                ->where('is_active', 1)
                ->select(['company', 'customer_id', DB::raw('COUNT(*) AS location_count'), DB::raw('MAX([source]) AS [source]')])
                ->groupBy('company', 'customer_id')
                ->get()
                ->keyBy(fn ($row) => $row->company . '|' . (int) $row->customer_id)
            : collect();
        $poiIndex = $this->poiNameIndex();

        return $customers
            ->map(function ($row) use ($pinned, $poiIndex) {
                $key = $row->company . '|' . (int) $row->customer_id;
                $pin = $pinned->get($key);
                $normalized = $this->normalizeName($row->name);

                return [
                    'company' => (string) $row->company,
                    'customer_id' => (int) $row->customer_id,
                    'name' => trim((string) $row->name),
                    'line_count' => (int) $row->line_count,
                    'address_count' => (int) $row->address_count,
                    'last_ship_at' => $row->last_ship_at ? substr((string) $row->last_ship_at, 0, 10) : null,
                    'location_count' => (int) ($pin->location_count ?? 0),
                    'status' => $pin ? 'pinned' : ($this->hasQuickPoiMatch($normalized, $poiIndex) ? 'suggested' : 'missing'),
                ];
            })
            ->sortByDesc('line_count')
            ->values()
            ->all();
    }

    /** Detail for one customer: saved locations, recent DP addresses, DTC POI suggestions. */
    public function customerDetail(string $company, int $customerId, int $days = 90): array
    {
        $since = now('Asia/Bangkok')->subDays(max(1, $days))->startOfDay()->format('Y-m-d H:i:s');
        $rows = collect($this->db()->select(
            "SELECT dp.address, MAX(COALESCE(NULLIF(c.name COLLATE DATABASE_DEFAULT, N''), dp.customer_name COLLATE DATABASE_DEFAULT)) AS name, COUNT(*) AS line_count
             FROM delivery_plan_data dp
             LEFT JOIN customer c
               ON c.id = dp.customer_id
              AND c.company COLLATE DATABASE_DEFAULT = COALESCE(NULLIF(dp.company, N''), N'MSW') COLLATE DATABASE_DEFAULT
             WHERE dp.ship_posted_at >= ? AND dp.customer_id = ? AND COALESCE(NULLIF(dp.company, N''), N'MSW') = ?
             GROUP BY dp.address",
            [$since, $customerId, $company]
        ));
        $name = trim((string) ($rows->pluck('name')->filter()->first() ?? ''));

        $locations = $this->tablesReady()
            ? $this->db()->table('gps_customer_locations')
                ->where('company', $company)
                ->where('customer_id', $customerId)
                ->where('is_active', 1)
                ->orderBy('address_key')
                ->get()
                ->map(fn ($row) => $this->presentLocation($row))
                ->values()
                ->all()
            : [];

        return [
            'company' => $company,
            'customer_id' => $customerId,
            'name' => $name,
            'addresses' => $rows
                ->filter(fn ($row) => trim((string) $row->address) !== '')
                ->sortByDesc('line_count')
                ->map(fn ($row) => [
                    'address' => trim((string) $row->address),
                    'address_key' => $this->addressKey($row->address),
                    'line_count' => (int) $row->line_count,
                ])
                ->values()
                ->all(),
            'locations' => $locations,
            'suggestions' => $this->suggestPois($name),
        ];
    }

    /** Top DTC POIs whose name resembles the customer name. */
    public function suggestPois(string $customerName, int $limit = 3): array
    {
        $target = $this->normalizeName($customerName);
        if ($target === '' || !$this->tablesReady()) {
            return [];
        }

        return collect($this->poiRows())
            ->map(function (array $poi) use ($target) {
                $name = $this->normalizeName($poi['poi_name']);
                if ($name === '') {
                    return null;
                }
                if ($name === $target) {
                    $score = 100;
                } elseif (mb_strlen($target) >= 4 && mb_strlen($name) >= 4 && (str_contains($name, $target) || str_contains($target, $name))) {
                    $score = 85;
                } else {
                    similar_text($name, $target, $percent);
                    $score = (int) round($percent);
                }

                return $score >= 55 ? [...$poi, 'score' => $score] : null;
            })
            ->filter()
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();
    }

    /** Pull DTC POIs (1 request) into gps_dtc_pois. Existing rows are updated, nothing is deleted. */
    public function importPois(DtcGpsService $dtc): array
    {
        if (!$this->tablesReady()) {
            throw new RuntimeException('GPS location tables are not created yet.');
        }

        $pois = $dtc->getPoiList();
        $now = now('Asia/Bangkok')->format('Y-m-d H:i:s');
        $rows = array_map(fn (array $poi) => [...$poi, 'synced_at' => $now], $pois);

        $this->db()->transaction(function () use ($rows) {
            foreach (array_chunk($rows, 100) as $chunk) {
                $this->db()->table('gps_dtc_pois')->upsert(
                    $chunk,
                    ['poi_id'],
                    ['poi_name', 'location_info', 'poi_type', 'lat', 'lon', 'area_m', 'synced_at']
                );
            }
        });
        Cache::forget(self::POI_CACHE_KEY);

        return ['imported' => count($rows), 'requests_used' => $dtc->requestsSent()];
    }

    public function poiStats(): array
    {
        if (!$this->tablesReady()) {
            return ['count' => 0, 'synced_at' => null];
        }
        $row = $this->db()->table('gps_dtc_pois')->selectRaw('COUNT(*) AS cnt, MAX(synced_at) AS synced_at')->first();

        return ['count' => (int) ($row->cnt ?? 0), 'synced_at' => $row->synced_at ?? null];
    }

    /**
     * Create or update the active location for (company, customer, address).
     * $data: company, customer_id, address (nullable), label, lat, lon, radius_m, source, dtc_poi_id.
     */
    public function saveLocation(array $data, ?int $userId): array
    {
        if (!$this->tablesReady()) {
            throw new RuntimeException('GPS location tables are not created yet.');
        }

        $address = trim((string) ($data['address'] ?? ''));
        $addressKey = $address === '' ? '' : $this->addressKey($address);
        $now = now('Asia/Bangkok')->format('Y-m-d H:i:s');
        $values = [
            'address_text' => $address !== '' ? mb_substr($address, 0, 500) : null,
            'label' => ($label = trim((string) ($data['label'] ?? ''))) !== '' ? mb_substr($label, 0, 255) : null,
            'lat' => round((float) $data['lat'], 6),
            'lon' => round((float) $data['lon'], 6),
            'radius_m' => (int) ($data['radius_m'] ?? self::DEFAULT_RADIUS_M),
            'source' => $data['source'] === 'dtc_poi' ? 'dtc_poi' : 'manual',
            'dtc_poi_id' => $data['source'] === 'dtc_poi' ? ($data['dtc_poi_id'] ?? null) : null,
        ];

        $id = $this->db()->transaction(function () use ($data, $addressKey, $values, $now, $userId) {
            $existing = $this->db()->table('gps_customer_locations')
                ->where('company', $data['company'])
                ->where('customer_id', (int) $data['customer_id'])
                ->where('address_key', $addressKey)
                ->where('is_active', 1)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->db()->table('gps_customer_locations')->where('id', $existing->id)
                    ->update([...$values, 'updated_at' => $now, 'updated_by' => $userId]);

                return (int) $existing->id;
            }

            return (int) $this->db()->table('gps_customer_locations')->insertGetId([
                ...$values,
                'company' => $data['company'],
                'customer_id' => (int) $data['customer_id'],
                'address_key' => $addressKey,
                'is_active' => 1,
                'created_at' => $now,
                'created_by' => $userId,
            ]);
        });

        return $this->presentLocation($this->db()->table('gps_customer_locations')->where('id', $id)->first());
    }

    /** Soft delete: is_active = 0 (history kept). */
    public function deactivateLocation(int $id, ?int $userId): bool
    {
        if (!$this->tablesReady()) {
            return false;
        }

        return $this->db()->table('gps_customer_locations')
            ->where('id', $id)
            ->where('is_active', 1)
            ->update(['is_active' => 0, 'updated_at' => now('Asia/Bangkok')->format('Y-m-d H:i:s'), 'updated_by' => $userId]) > 0;
    }

    /**
     * Active locations for the customers in the given deliveries, keyed "company|customer_id".
     * Address-specific pins are listed per address_key; '' is the customer default.
     */
    public function locationsForDeliveries(array $deliveries): array
    {
        if (!$this->tablesReady()) {
            return [];
        }
        $pairs = collect($deliveries)
            ->filter(fn ($delivery) => is_array($delivery) && !empty($delivery['customer_id']))
            ->map(fn (array $delivery) => [$this->queueService->customerCompany($delivery['company'] ?? null), (int) $delivery['customer_id']])
            ->unique(fn (array $pair) => $pair[0] . '|' . $pair[1])
            ->values();
        if ($pairs->isEmpty()) {
            return [];
        }

        $result = [];
        foreach ($pairs->chunk(200) as $chunk) {
            $query = $this->db()->table('gps_customer_locations')->where('is_active', 1)
                ->where(function ($where) use ($chunk) {
                    foreach ($chunk as [$company, $customerId]) {
                        $where->orWhere(fn ($pair) => $pair->where('company', $company)->where('customer_id', $customerId));
                    }
                });
            foreach ($query->get() as $row) {
                $result[$row->company . '|' . (int) $row->customer_id][$row->address_key] = $this->presentLocation($row);
            }
        }

        return $result;
    }

    public function addressKey(?string $address): string
    {
        $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(trim((string) $address)));

        return $normalized === '' ? '' : sha1($normalized);
    }

    public function normalizeName(?string $name): string
    {
        $name = mb_strtolower((string) $name);
        $name = (string) preg_replace('/(บริษัท|บจก\.?|หจก\.?|ห้างหุ้นส่วนจำกัด|จำกัด|\(มหาชน\)|มหาชน|co\.?,?\s*ltd\.?|company|limited|public|สาขา.*$|\(.*?\))/u', '', $name);

        return (string) preg_replace('/[^0-9a-zก-๙]/u', '', $name);
    }

    private function presentLocation($row): array
    {
        return [
            'id' => (int) $row->id,
            'company' => (string) $row->company,
            'customer_id' => (int) $row->customer_id,
            'address_key' => (string) $row->address_key,
            'address_text' => $row->address_text,
            'label' => $row->label,
            'lat' => (float) $row->lat,
            'lon' => (float) $row->lon,
            'radius_m' => (int) $row->radius_m,
            'source' => (string) $row->source,
            'dtc_poi_id' => $row->dtc_poi_id,
        ];
    }

    private function poiRows(): array
    {
        return Cache::remember(self::POI_CACHE_KEY, now()->addMinutes(10), fn () => $this->db()->table('gps_dtc_pois')
            ->select(['poi_id', 'poi_name', 'location_info', 'lat', 'lon', 'area_m'])
            ->get()
            ->map(fn ($row) => [
                'poi_id' => (string) $row->poi_id,
                'poi_name' => (string) $row->poi_name,
                'location_info' => (string) ($row->location_info ?? ''),
                'lat' => (float) $row->lat,
                'lon' => (float) $row->lon,
                'area_m' => $row->area_m !== null ? (int) $row->area_m : null,
            ])
            ->all());
    }

    /** Normalized POI names for the cheap "has suggestion" check in the list. */
    private function poiNameIndex(): Collection
    {
        if (!$this->tablesReady()) {
            return collect();
        }

        return collect($this->poiRows())->map(fn (array $poi) => $this->normalizeName($poi['poi_name']))->filter()->unique()->values();
    }

    private function hasQuickPoiMatch(string $normalized, Collection $poiIndex): bool
    {
        if ($normalized === '' || $poiIndex->isEmpty()) {
            return false;
        }

        return $poiIndex->contains(fn (string $poi) => $poi === $normalized
            || (mb_strlen($normalized) >= 4 && mb_strlen($poi) >= 4 && (str_contains($poi, $normalized) || str_contains($normalized, $poi))));
    }
}
