<?php

return [
    'fallback_email' => env('STOCK_WITHDRAWAL_FALLBACK_TO', ''),
    'refresh_seconds' => 300,
    'working_days' => [1, 2, 3, 4, 5, 6],
    'job_types' => [
        'RAW_MATERIAL' => ['name' => 'วัตถุดิบ', 'lead_time_days' => 2],
        'WIRE_PRODUCT' => ['name' => 'สินค้าลวด', 'lead_time_days' => 2],
        'SHAFT' => ['name' => 'เพลา', 'lead_time_days' => 3],
        'PLATE' => ['name' => 'เพลท', 'lead_time_days' => 3],
    ],
    'wire_fcats' => ['MIG', 'TIG', 'WIRE', 'PROFILE WIRE', 'STEEL WIRE', 'CUT WIRE'],
    'shaft_fcats' => ['BAR', 'MM BAR', 'STEEL BAR', 'PROFILE BAR', 'STD BAR', 'CG BAR', 'MM BAR (CG)'],
];
