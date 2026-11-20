<?php

return [
    'base_url' => env('DTC_GPS_BASE_URL', 'https://gps.dtc.co.th:8099/'),
    'api_token' => env('DTC_GPS_API_TOKEN'),
    'timeout' => (int) env('DTC_GPS_TIMEOUT', 15),
    'connect_timeout' => (int) env('DTC_GPS_CONNECT_TIMEOUT', 5),
    'retry_times' => (int) env('DTC_GPS_RETRY_TIMES', 1),
    // Lazy sync: DTC realtime is called at most once per interval, and only while someone views the page.
    'sync_interval_minutes' => (int) env('DTC_GPS_SYNC_INTERVAL_MINUTES', 3),
    // Route (getHistory) points sent to the browser per vehicle/day after downsampling.
    'route_max_points' => (int) env('DTC_GPS_ROUTE_MAX_POINTS', 800),

    // Vehicle Master rarely changes: cache the sanitized list so each sync costs 1 request.
    'master_cache_hours' => (int) env('DTC_GPS_MASTER_CACHE_HOURS', 24),

    // DTC manual: HTTP 429 when one client IP sends more than 3 requests per minute.
    // All callers share this sliding window, so keep it at 3 or lower.
    'per_minute_limit' => (int) env('DTC_GPS_PER_MINUTE_LIMIT', 3),

    // Monthly request quota from DTC (unconfirmed: ~25,000). Calls stop locally at quota - reserve.
    'monthly_quota' => (int) env('DTC_GPS_MONTHLY_QUOTA', 25000),
    'monthly_reserve' => (int) env('DTC_GPS_MONTHLY_RESERVE', 500),
];
