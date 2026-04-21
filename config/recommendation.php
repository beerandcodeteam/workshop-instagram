<?php

return [

    'long_term' => [
        'window_days' => env('REC_LT_WINDOW_DAYS', 180),
        'half_life_days' => env('REC_LT_HALF_LIFE_DAYS', 30),
        'activity_window_days' => env('REC_LT_ACTIVITY_WINDOW_DAYS', 7),
        'weight_threshold' => env('REC_LT_WEIGHT_THRESHOLD', 2.0),
    ],

    'short_term' => [
        'window_hours' => env('REC_ST_WINDOW_HOURS', 48),
        'half_life_hours' => env('REC_ST_HALF_LIFE_HOURS', 6),
        'weight_threshold' => env('REC_ST_WEIGHT_THRESHOLD', 1.0),
        'debounce_seconds' => env('REC_ST_DEBOUNCE_SECONDS', 5),
        'cache_ttl_seconds' => env('REC_ST_CACHE_TTL', 3600),
        'buffer_max_items' => env('REC_ST_BUFFER_MAX', 50),
    ],

    'avoid' => [
        'window_days' => env('REC_AV_WINDOW_DAYS', 90),
        'half_life_days' => env('REC_AV_HALF_LIFE_DAYS', 30),
        'weight_threshold' => env('REC_AV_WEIGHT_THRESHOLD', 1.0),
    ],

];
