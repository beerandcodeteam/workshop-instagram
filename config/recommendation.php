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

    'view_signals' => [
        'aggregation_window_minutes' => env('REC_VIEW_AGG_WINDOW_MINUTES', 10),
        'refresh_threshold_delta' => env('REC_VIEW_REFRESH_THRESHOLD', 1.0),
    ],

    'trending' => [
        'window_hours' => env('REC_TRENDING_WINDOW_HOURS', 24),
        'half_life_hours' => env('REC_TRENDING_HALF_LIFE_HOURS', 24),
        'pool_size' => env('REC_TRENDING_POOL_SIZE', 200),
        'reports_threshold' => env('REC_TRENDING_REPORTS_THRESHOLD', 3),
        'redis_key' => env('REC_TRENDING_REDIS_KEY', 'rec:trending:global'),
    ],

    'candidates' => [
        'ann_long_term_limit' => env('REC_CAND_ANN_LT_LIMIT', 300),
        'ann_short_term_limit' => env('REC_CAND_ANN_ST_LIMIT', 200),
        'trending_limit' => env('REC_CAND_TRENDING_LIMIT', 100),
        'exploration_limit' => env('REC_CAND_EXPLORE_LIMIT', 50),
        'reports_threshold' => env('REC_CAND_REPORTS_THRESHOLD', 3),
    ],

    'score' => [
        'alpha_default' => env('REC_SCORE_ALPHA_DEFAULT', 0.8),
        'alpha_active_session' => env('REC_SCORE_ALPHA_ACTIVE_SESSION', 0.3),
        'alpha_active_threshold' => env('REC_SCORE_ALPHA_ACTIVE_THRESHOLD', 5),
        'beta_avoid' => env('REC_SCORE_BETA_AVOID', 0.5),
        'gamma_recency' => env('REC_SCORE_GAMMA_RECENCY', 0.15),
        'delta_trending' => env('REC_SCORE_DELTA_TRENDING', 0.1),
        'epsilon_context' => env('REC_SCORE_EPSILON_CONTEXT', 0.05),
        'recency_half_life_hours' => env('REC_SCORE_RECENCY_HALF_LIFE', 6),
    ],

    'mmr' => [
        'lambda' => env('REC_MMR_LAMBDA', 0.7),
        'pool_size' => env('REC_MMR_POOL_SIZE', 100),
    ],

    'author_quota' => [
        'top_k' => env('REC_QUOTA_TOP_K', 20),
        'per_author' => env('REC_QUOTA_PER_AUTHOR', 2),
    ],

    'seen' => [
        'ttl_seconds' => env('REC_SEEN_TTL_SECONDS', 172800),
        'redis_prefix' => env('REC_SEEN_REDIS_PREFIX', 'rec:user'),
    ],

    'cold_start' => [
        'interactions_threshold' => env('REC_COLD_START_INTERACTIONS', 5),
        'recent_per_trending' => env('REC_COLD_START_RECENT_PER_TRENDING', 5),
    ],

    'exploration' => [
        'per_window' => env('REC_EXPLORATION_PER_WINDOW', 1),
        'window_size' => env('REC_EXPLORATION_WINDOW_SIZE', 10),
    ],

    'source_reasons' => [
        'ann_long_term' => 'parece com conteúdos que você curtiu ao longo do tempo',
        'ann_short_term' => 'parecido com o que você curtiu nas últimas horas',
        'ann_cluster' => 'combina com um dos seus interesses',
        'trending' => 'está em alta entre os usuários agora',
        'following' => 'é de alguém que você segue',
        'locality' => 'é popular na sua região',
        'explore' => 'é algo novo para ampliar suas descobertas',
        'control' => 'foi escolhido aleatoriamente (teste A/B)',
    ],

];
