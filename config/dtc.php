<?php

return [
    'base_url' => env('DTC_GPS_BASE_URL', 'https://gps.dtc.co.th:8099/'),
    'api_token' => env('DTC_GPS_API_TOKEN'),
    'timeout' => (int) env('DTC_GPS_TIMEOUT', 15),
    'connect_timeout' => (int) env('DTC_GPS_CONNECT_TIMEOUT', 5),
    'retry_times' => (int) env('DTC_GPS_RETRY_TIMES', 1),
    'sync_interval_minutes' => (int) env('DTC_GPS_SYNC_INTERVAL_MINUTES', 5),

    // Vehicle Master rarely changes: cache the sanitized list so each sync costs 1 request.
    'master_cache_hours' => (int) env('DTC_GPS_MASTER_CACHE_HOURS', 24),

    // Monthly request quota from DTC (unconfirmed: ~25,000). Calls stop locally at quota - reserve.
    'monthly_quota' => (int) env('DTC_GPS_MONTHLY_QUOTA', 25000),
    'monthly_reserve' => (int) env('DTC_GPS_MONTHLY_RESERVE', 500),
];
