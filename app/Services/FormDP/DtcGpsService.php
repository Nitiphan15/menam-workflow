<?php

namespace App\Services\FormDP;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class DtcGpsService
{
    private const REALTIME_SAFE_FIELDS = [
        'gps_id',
        'truck_name',
        'time',
        'gps_speed',
        'status_code',
        'status_name_th',
        'status_name_en',
        'lat',
        'lon',
        'heading',
        'gps_mileage',
        'license_plate',
        'vehicle_name',
    ];

    private const MASTER_SAFE_FIELDS = [
        'gps_id',
        'vehicle_name',
        'license_plate',
    ];

    /**
     * Fallback names => canonical name. The DTC manual (v1.1) lists "gps_mileage" in the
     * field table but its example payload uses "mileage " (with a trailing space).
     * Keys are trimmed first, then the first non-empty alias fills the canonical field.
     */
    private const REALTIME_FIELD_ALIASES = [
        'gps_mileage' => ['gps_mileage', 'mileage'],
        'lat' => ['lat', 'latitude'],
        'lon' => ['lon', 'lng', 'longitude'],
    ];

    private const NUMERIC_REALTIME_FIELDS = ['lat', 'lon', 'gps_speed', 'heading', 'gps_mileage', 'status_code'];

    private const MASTER_CACHE_KEY = 'dtc_gps.vehicle_master.v1';
    private const USAGE_DIR = 'dtc-usage';
    private const RECENT_REQUESTS_KEY = 'dtc_gps.recent_requests';
    private const WINDOW_SECONDS = 60;
    public const BUDGET_EXHAUSTED_MESSAGE = 'DTC monthly request budget reached.';
    public const LOCKED_MESSAGE = 'DTC API is locked locally after HTTP 423.';

    /** Requests actually sent by this service instance (one per HTTP attempt). */
    private int $requestsSent = 0;

    public function getVehicleMaster(): array
    {
        return $this->post('getVehicleMaster');
    }

    /**
     * Sanitized Vehicle Master, cached (default 24h) so a normal sync needs only 1 realtime request.
     * Only sanitized rows are cached: no SIM / chassis numbers ever reach the cache store.
     *
     * @return array{count: int, data: array, fetched_at: string, from_cache: bool}
     */
    public function getVehicleMasterCached(bool $refresh = false): array
    {
        $cached = $refresh ? null : Cache::get(self::MASTER_CACHE_KEY);
        if (is_array($cached) && isset($cached['data'])) {
            return [...$cached, 'from_cache' => true];
        }

        $master = $this->sanitizeVehicleMaster($this->getVehicleMaster());
        $entry = [...$master, 'fetched_at' => now('Asia/Bangkok')->toIso8601String()];
        Cache::put(self::MASTER_CACHE_KEY, $entry, now()->addHours(max(1, (int) config('dtc.master_cache_hours', 24))));

        return [...$entry, 'from_cache' => false];
    }

    public function isVehicleMasterCached(): bool
    {
        $cached = Cache::get(self::MASTER_CACHE_KEY);

        return is_array($cached) && isset($cached['data']);
    }

    public function requestsSent(): int
    {
        return $this->requestsSent;
    }

    /**
     * Monthly usage is kept in storage/app/dtc-usage/Y-m.json (not the cache store),
     * so "php artisan cache:clear" during deploy never resets the counter.
     */
    public function usageSummary(): array
    {
        $now = now('Asia/Bangkok');
        $usage = $this->readUsage($now->format('Y-m'));
        $quota = max(0, (int) config('dtc.monthly_quota', 25000));
        $reserve = max(0, (int) config('dtc.monthly_reserve', 0));
        $budget = max(0, $quota - $reserve);
        $lockedUntil = $usage['locked_until'] ?? null;

        return [
            'month' => $now->format('Y-m'),
            'month_total' => (int) $usage['total'],
            'today' => (int) ($usage['days'][$now->toDateString()] ?? 0),
            'quota' => $quota,
            'budget' => $budget,
            'remaining' => max(0, $budget - (int) $usage['total']),
            'percent' => $quota > 0 ? round(((int) $usage['total'] / $quota) * 100, 1) : 0,
            'by_endpoint' => $usage['endpoints'],
            'locked_until' => $lockedUntil && now('Asia/Bangkok')->lt($lockedUntil) ? $lockedUntil : null,
            'master_cached_at' => Cache::get(self::MASTER_CACHE_KEY)['fetched_at'] ?? null,
            'per_minute_limit' => $this->perMinuteLimit(),
            'minute_used' => $this->minuteWindow()['used'],
        ];
    }

    private function usagePath(string $month): string
    {
        return self::USAGE_DIR . '/' . $month . '.json';
    }

    private function readUsage(string $month): array
    {
        $empty = ['month' => $month, 'total' => 0, 'days' => [], 'endpoints' => [], 'locked_until' => null];

        try {
            $disk = Storage::disk('local');
            $path = $this->usagePath($month);
            $data = $disk->exists($path) ? json_decode((string) $disk->get($path), true) : null;

            return is_array($data) ? array_merge($empty, $data) : $empty;
        } catch (Throwable) {
            return $empty;
        }
    }

    private function updateUsage(callable $mutate): void
    {
        $month = now('Asia/Bangkok')->format('Y-m');

        try {
            Cache::lock('dtc_gps.usage_lock', 5)->block(3, function () use ($month, $mutate) {
                $usage = $mutate($this->readUsage($month));
                $usage['updated_at'] = now('Asia/Bangkok')->toIso8601String();
                Storage::disk('local')->put(
                    $this->usagePath($month),
                    json_encode($usage, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );
            });
        } catch (Throwable $e) {
            // Counting must never break the API call itself; leave a trace instead.
            report($e);
        }
    }

    private function recordRequest(string $endpoint): void
    {
        $this->requestsSent++;
        $today = now('Asia/Bangkok')->toDateString();

        $this->updateUsage(function (array $usage) use ($endpoint, $today) {
            $usage['total'] = (int) $usage['total'] + 1;
            $usage['days'][$today] = (int) ($usage['days'][$today] ?? 0) + 1;
            $usage['endpoints'][$endpoint] = (int) ($usage['endpoints'][$endpoint] ?? 0) + 1;

            return $usage;
        });
    }

    private function lockAfterQuotaResponse(): void
    {
        $until = now('Asia/Bangkok')->endOfDay()->toIso8601String();
        $this->updateUsage(function (array $usage) use ($until) {
            $usage['locked_until'] = $until;

            return $usage;
        });
    }

    /**
     * DTC manual: HTTP 429 when the same client IP sends more than 3 requests in 1 minute.
     * Every caller (all users, buttons, future scheduler) shares this sliding window,
     * so the server never sends the request that would trigger 429.
     */
    public function perMinuteLimit(): int
    {
        return max(1, (int) config('dtc.per_minute_limit', 3));
    }

    /** @return array{used: int, available: int, retry_after: int} */
    public function minuteWindow(): array
    {
        $recent = $this->recentRequests();
        $limit = $this->perMinuteLimit();
        $oldest = $recent[0] ?? null;

        return [
            'used' => count($recent),
            'available' => max(0, $limit - count($recent)),
            'retry_after' => $oldest === null ? 0 : max(1, (int) ceil($oldest + self::WINDOW_SECONDS - $this->nowSeconds())),
        ];
    }

    /**
     * Call before a flow that needs several requests (e.g. Master + Realtime = 2),
     * so it does not stop halfway with only part of the data.
     */
    public function assertRequestSlots(int $needed): void
    {
        $window = $this->minuteWindow();
        if ($window['available'] < $needed) {
            throw new DtcRateLimitException($window['retry_after'] ?: self::WINDOW_SECONDS);
        }
    }

    private function nowSeconds(): float
    {
        return now()->getPreciseTimestamp(3) / 1000;
    }

    private function recentRequests(): array
    {
        $cutoff = $this->nowSeconds() - self::WINDOW_SECONDS;
        $recent = array_values(array_filter(
            (array) Cache::get(self::RECENT_REQUESTS_KEY, []),
            fn ($timestamp) => is_numeric($timestamp) && (float) $timestamp > $cutoff
        ));
        sort($recent);

        return $recent;
    }

    private function reserveMinuteSlot(): void
    {
        Cache::lock('dtc_gps.minute_lock', 5)->block(3, function () {
            $recent = $this->recentRequests();
            if (count($recent) >= $this->perMinuteLimit()) {
                throw new DtcRateLimitException((int) ceil($recent[0] + self::WINDOW_SECONDS - $this->nowSeconds()));
            }
            $recent[] = $this->nowSeconds();
            Cache::put(self::RECENT_REQUESTS_KEY, $recent, self::WINDOW_SECONDS * 2);
        });
    }

    /** After a real 429 from DTC, treat the whole window as used so nobody retries for 60 seconds. */
    private function fillMinuteWindow(): void
    {
        $now = $this->nowSeconds();
        Cache::put(self::RECENT_REQUESTS_KEY, array_fill(0, $this->perMinuteLimit(), $now), self::WINDOW_SECONDS * 2);
    }

    private function assertWithinBudget(): void
    {
        $usage = $this->usageSummary();

        if ($usage['locked_until'] !== null) {
            throw new RuntimeException(self::LOCKED_MESSAGE);
        }
        if ($usage['quota'] > 0 && $usage['remaining'] <= 0) {
            throw new RuntimeException(self::BUDGET_EXHAUSTED_MESSAGE);
        }
    }

    public function getRealtimeData(array $gpsIds = []): array
    {
        return $this->post('getRealtimeData', ['gps_list' => array_values(array_map('strval', $gpsIds))]);
    }

    /**
     * Manual section 3.4: getHistory takes a single "gps_id" (not gps_list)
     * and "start_period"/"end_period" as "Y-m-d H:i:s" (GMT+7).
     */
    public function getHistory(string $gpsId, string $startPeriod, string $endPeriod): array
    {
        return $this->post('getHistory', [
            'gps_id' => $gpsId,
            'start_period' => $startPeriod,
            'end_period' => $endPeriod,
        ]);
    }

    /**
     * Keep only fields needed to prove the connection and plot vehicles.
     * Token, SIM and driver identity fields can never reach the saved test file.
     */
    public function sanitizeRealtimeResponse(array $response): array
    {
        $rows = is_array($response['data'] ?? null) ? $response['data'] : [];

        return [
            'error' => (bool) ($response['error'] ?? false),
            'status' => (string) ($response['status'] ?? ''),
            'message' => (string) ($response['message'] ?? ''),
            'count' => count($rows),
            'data' => array_values(array_map(
                fn ($row) => is_array($row) ? $this->normalizeRealtimeRow($row) : [],
                $rows
            )),
        ];
    }

    public function sanitizeVehicleMaster(array $response): array
    {
        $rows = is_array($response['data'] ?? null) ? $response['data'] : [];

        return [
            'count' => count($rows),
            'data' => array_values(array_map(
                fn ($row) => is_array($row)
                    ? array_intersect_key($this->trimKeys($row), array_flip(self::MASTER_SAFE_FIELDS))
                    : [],
                $rows
            )),
        ];
    }

    public function gpsIdsFromMaster(array $masterRows): array
    {
        return collect($masterRows)
            ->map(fn ($row) => trim((string) (is_array($row) ? ($row['gps_id'] ?? '') : '')))
            ->filter(fn (string $id) => $id !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeRealtimeRow(array $row): array
    {
        $row = $this->trimKeys($row);

        foreach (self::REALTIME_FIELD_ALIASES as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $row) && $row[$alias] !== null && $row[$alias] !== '') {
                    $row[$canonical] = $row[$alias];
                    break;
                }
            }
        }

        $safe = array_intersect_key($row, array_flip(self::REALTIME_SAFE_FIELDS));

        foreach (self::NUMERIC_REALTIME_FIELDS as $field) {
            if (array_key_exists($field, $safe)) {
                $safe[$field] = is_numeric($safe[$field]) ? $safe[$field] + 0 : null;
            }
        }
        if (array_key_exists('gps_id', $safe)) {
            $safe['gps_id'] = (string) $safe['gps_id'];
        }

        return $safe;
    }

    private function trimKeys(array $row): array
    {
        $trimmed = [];
        foreach ($row as $key => $value) {
            $trimmed[is_string($key) ? trim($key) : $key] = $value;
        }

        return $trimmed;
    }

    public function enrichRealtimeWithMaster(array $realtimeRows, array $masterRows): array
    {
        $masterByGpsId = collect($masterRows)->keyBy(fn ($row) => (string) ($row['gps_id'] ?? ''));

        return array_values(array_map(function ($row) use ($masterByGpsId) {
            $row = is_array($row) ? $row : [];
            $master = $masterByGpsId->get((string) ($row['gps_id'] ?? ''), []);

            $enriched = [
                ...$row,
                'vehicle_name' => (string) ($master['vehicle_name'] ?? $row['vehicle_name'] ?? ''),
                'license_plate' => (string) ($master['license_plate'] ?? $row['license_plate'] ?? ''),
            ];
            [$plate, $source] = $this->resolvePlate($enriched);

            // license_plate always holds the resolved plate; plate_source says where it came from.
            return [...$enriched, 'license_plate' => $plate, 'plate_source' => $source];
        }, $realtimeRows));
    }

    /**
     * Real DTC data (2026-11-20) puts the GPS device id in Master "license_plate"
     * (same value as gps_id) and the actual plate in Master "vehicle_name" / realtime "truck_name".
     * Use license_plate only when it does not look like a device id.
     *
     * @return array{0: string, 1: string}
     */
    public function resolvePlate(array $row): array
    {
        $gpsId = trim((string) ($row['gps_id'] ?? ''));
        $candidates = [
            'master_license_plate' => $row['license_plate'] ?? '',
            'master_vehicle_name' => $row['vehicle_name'] ?? '',
            'realtime_truck_name' => $row['truck_name'] ?? '',
        ];

        foreach ($candidates as $source => $value) {
            $value = trim((string) $value);
            if ($value !== '' && !$this->looksLikeDeviceId($value, $gpsId)) {
                return [$value, $source];
            }
        }

        return ['', 'none'];
    }

    private function looksLikeDeviceId(string $value, string $gpsId): bool
    {
        if ($gpsId !== '' && strcasecmp($value, $gpsId) === 0) {
            return true;
        }

        // Thai plates have Thai letters or a short digit group; device ids are long alphanumerics.
        return (bool) preg_match('/^[A-Za-z]{0,2}\d{10,}$/', $value);
    }

    public function sanitizeException(Throwable $exception): array
    {
        $detail = $exception->getMessage();
        $token = trim((string) config('dtc.api_token'));

        if ($token !== '') {
            $detail = str_replace($token, '[REDACTED]', $detail);
        }

        $detail = (string) preg_replace(
            '/(["\']?api_token_key["\']?\s*[:=]\s*["\']?)[^"\'&\s}]+/i',
            '$1[REDACTED]',
            $detail
        );

        return [
            'type' => $exception::class,
            'detail' => mb_substr($detail, 0, 2000),
        ];
    }

    public function describeException(Throwable $exception): array
    {
        $status = $exception instanceof RequestException ? $exception->response?->status() : null;

        if ($exception instanceof ConnectionException) {
            return ['code' => 'network', 'title' => 'เชื่อมต่อเครือข่ายไม่ได้', 'advice' => 'ตรวจ Firewall, Proxy และ TCP port 8099', 'http_status' => null, 'retryable' => true];
        }
        if ($status === 400) {
            return ['code' => 'bad_request', 'title' => 'รูปแบบคำขอไม่ถูกต้อง', 'advice' => 'ตรวจ payload ตามคู่มือ DTC', 'http_status' => 400, 'retryable' => false];
        }
        if ($status === 423) {
            return ['code' => 'quota_locked', 'title' => 'โควตา DTC ถูกระงับ', 'advice' => 'ตรวจจำนวน request ในหน้า DTC หรือติดต่อผู้ให้บริการ', 'http_status' => 423, 'retryable' => false];
        }
        if ($status === 429) {
            return ['code' => 'rate_limited', 'title' => 'เรียก DTC ถี่เกินไป', 'advice' => 'รออย่างน้อย 1 นาทีแล้วลองใหม่', 'http_status' => 429, 'retryable' => true];
        }
        if ($status !== null && $status >= 500) {
            return ['code' => 'dtc_server', 'title' => 'ระบบ DTC ขัดข้อง', 'advice' => 'รอสักครู่แล้วลองใหม่ หรือติดต่อ DTC หากยังเกิดซ้ำ', 'http_status' => $status, 'retryable' => true];
        }
        if ($exception instanceof DtcRateLimitException) {
            return ['code' => 'rate_limited_local', 'title' => 'เรียก DTC ครบ ' . $this->perMinuteLimit() . ' ครั้งในนาทีนี้แล้ว', 'advice' => 'DTC จะตอบ 429 ถ้าเกิน 3 ครั้ง/นาที/IP · รออีกประมาณ ' . $exception->retryAfterSeconds . ' วินาทีแล้วลองใหม่', 'http_status' => null, 'retryable' => true, 'retry_after' => $exception->retryAfterSeconds];
        }
        if ($exception->getMessage() === self::BUDGET_EXHAUSTED_MESSAGE) {
            return ['code' => 'budget_exhausted', 'title' => 'ใช้ request DTC ครบงบประจำเดือนแล้ว', 'advice' => 'ระบบหยุดเรียกเองเพื่อไม่ให้เกินโควตา · ปรับ DTC_GPS_MONTHLY_QUOTA หากได้โควตาเพิ่ม', 'http_status' => null, 'retryable' => false];
        }
        if ($exception->getMessage() === self::LOCKED_MESSAGE) {
            return ['code' => 'quota_locked', 'title' => 'หยุดเรียก DTC ชั่วคราวหลังได้ HTTP 423', 'advice' => 'ระบบจะลองได้อีกครั้งในวันถัดไป · ตรวจโควตากับ DTC', 'http_status' => 423, 'retryable' => false];
        }
        if ($exception->getMessage() === 'DTC GPS API token is not configured.') {
            return ['code' => 'configuration', 'title' => 'ยังไม่ได้ตั้งค่า Token', 'advice' => 'กำหนด DTC_GPS_API_TOKEN ใน .env แล้วล้าง config cache', 'http_status' => null, 'retryable' => false];
        }

        return ['code' => 'api_rejected', 'title' => 'DTC ปฏิเสธคำขอ', 'advice' => 'ตรวจ Token, สิทธิ์ API และ Raw error', 'http_status' => $status, 'retryable' => false];
    }

    public function mockRealtimeResponse(array $vehicles): array
    {
        $now = now('Asia/Bangkok');

        return [
            'error' => false,
            'status' => '200',
            'message' => 'mock-ok',
            'count' => count($vehicles),
            'data' => array_values(array_map(function (array $vehicle, int $index) use ($now) {
                $route = $vehicle['route'] ?? [];
                $point = $route[min($index + 1, max(0, count($route) - 1))] ?? [null, null];

                return [
                    'gps_id' => 'DTC-MOCK-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                    'truck_name' => (string) ($vehicle['plate_no'] ?? ''),
                    'time' => $now->copy()->subMinutes($index === 4 ? 18 : ($index + 1) * 2)->format('Y-m-d H:i:s'),
                    'gps_speed' => [46, 32, 0, 58, 21][$index] ?? 0,
                    'status_code' => $index === 2 ? 10 : 30,
                    'status_name_th' => $index === 2 ? 'จอดรถ' : 'รถวิ่ง',
                    'status_name_en' => $index === 2 ? 'parking' : 'running',
                    'lat' => $point[0],
                    'lon' => $point[1],
                    'heading' => 85 + ($index * 24),
                ];
            }, $vehicles, array_keys($vehicles))),
        ];
    }

    public function mockVehicleMaster(array $vehicles): array
    {
        return [
            'error' => false,
            'status' => '200',
            'message' => 'mock-ok',
            'count' => count($vehicles),
            'data' => array_values(array_map(fn (array $vehicle, int $index) => [
                'gps_id' => 'DTC-MOCK-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'vehicle_name' => (string) ($vehicle['plate_no'] ?? ''),
                'license_plate' => (string) ($vehicle['plate_no'] ?? ''),
                'sim_no' => 'REDACTED-MOCK',
            ], $vehicles, array_keys($vehicles))),
        ];
    }

    public function matchVehicles(array $dtcRows, array $formDpVehicles): array
    {
        $formDpByPlate = collect($formDpVehicles)->keyBy(
            fn (array $vehicle) => $this->normalizePlate($vehicle['plate_no'] ?? '')
        );

        $matchedFormDpKeys = collect();
        $rows = collect($dtcRows)->map(function ($dtcRow) use ($formDpByPlate, $matchedFormDpKeys) {
            $dtcRow = is_array($dtcRow) ? $dtcRow : [];
            // Vehicle Master plate first (when it is a real plate), then vehicle_name / truck_name.
            [$dtcName] = $this->resolvePlate($dtcRow);
            $plateKey = $this->normalizePlate($dtcName);
            $match = $plateKey !== '' ? $formDpByPlate->get($plateKey) : null;
            if ($match !== null) {
                $matchedFormDpKeys->push($plateKey);
            }

            return [
                'gps_id' => (string) ($dtcRow['gps_id'] ?? ''),
                'dtc_name' => $dtcName,
                'matched' => $match !== null,
                'plate_no' => (string) ($match['plate_no'] ?? ''),
                'trip_ref' => (string) ($match['trip_ref'] ?? ''),
                'driver_name' => (string) ($match['driver_name'] ?? ''),
                'last_time' => (string) ($dtcRow['time'] ?? ''),
                'status_name' => $this->firstFilled($dtcRow, ['status_name_th', 'status_name_en']),
                'has_position' => is_numeric($dtcRow['lat'] ?? null) && is_numeric($dtcRow['lon'] ?? null),
                'issue' => $match !== null ? 'matched' : 'missing_formdp',
            ];
        })->values();

        $missingInDtc = collect($formDpVehicles)
            ->reject(fn (array $vehicle) => $matchedFormDpKeys->contains($this->normalizePlate($vehicle['plate_no'] ?? '')))
            ->map(fn (array $vehicle) => [
                'gps_id' => '',
                'dtc_name' => '',
                'matched' => false,
                'plate_no' => (string) ($vehicle['plate_no'] ?? ''),
                'trip_ref' => (string) ($vehicle['trip_ref'] ?? ''),
                'driver_name' => (string) ($vehicle['driver_name'] ?? ''),
                'last_time' => '',
                'status_name' => '',
                'has_position' => false,
                'issue' => 'missing_dtc',
            ])->values();

        $allRows = $rows->concat($missingInDtc)->values();

        return [
            'matched_count' => $rows->where('matched', true)->count(),
            'dtc_count' => $rows->count(),
            'formdp_count' => count($formDpVehicles),
            'missing_in_formdp_count' => $rows->where('issue', 'missing_formdp')->count(),
            'missing_in_dtc_count' => $missingInDtc->count(),
            'rows' => $allRows->all(),
        ];
    }

    private function firstFilled(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function normalizePlate(?string $plate): string
    {
        return mb_strtoupper((string) preg_replace('/[^0-9A-Zก-๙]/u', '', trim((string) $plate)));
    }

    private function post(string $endpoint, array $payload = []): array
    {
        $token = trim((string) config('dtc.api_token'));
        if ($token === '') {
            throw new RuntimeException('DTC GPS API token is not configured.');
        }

        $this->assertWithinBudget();
        $this->reserveMinuteSlot();

        // beforeSending fires once per HTTP attempt (including retries), so the counter
        // matches what DTC bills. Connection failures are counted too (conservative).
        $response = $this->client()
            ->beforeSending(fn () => $this->recordRequest($endpoint))
            ->post($endpoint, [
                'api_token_key' => $token,
                ...$payload,
            ]);

        if ($response->status() === 423) {
            $this->lockAfterQuotaResponse();
        }
        if ($response->status() === 429) {
            $this->fillMinuteWindow();
        }

        $response->throw();
        $data = $response->json();

        if (!is_array($data)) {
            throw new RuntimeException('DTC GPS API returned an invalid JSON response.');
        }

        if (($data['error'] ?? false) === true) {
            throw new RuntimeException((string) ($data['message'] ?? 'DTC GPS API request failed.'));
        }

        return $data;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('dtc.base_url'), '/') . '/')
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('dtc.connect_timeout', 5))
            ->timeout((int) config('dtc.timeout', 15))
            ->retry((int) config('dtc.retry_times', 1), 500, throw: false);
    }
}
