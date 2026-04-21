<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pesos por sinal (espelho do §6 do overview)
    |--------------------------------------------------------------------------
    |
    | Pesos base aplicados a cada tipo de interação ao agregar embeddings.
    | Valores podem ser sobrescritos em runtime via `recommendation_settings`.
    |
    */

    'weights' => [
        'like' => env('REC_WEIGHT_LIKE', 1.0),
        'comment' => env('REC_WEIGHT_COMMENT', 1.5),
        'share' => env('REC_WEIGHT_SHARE', 2.0),
        'view_3s' => env('REC_WEIGHT_VIEW_3S', 0.3),
        'view_10s' => env('REC_WEIGHT_VIEW_10S', 0.5),
        'view_30s' => env('REC_WEIGHT_VIEW_30S', 0.8),
        'skip_fast' => env('REC_WEIGHT_SKIP_FAST', -0.3),
        'hide' => env('REC_WEIGHT_HIDE', -1.5),
        'report' => env('REC_WEIGHT_REPORT', -3.0),
        'unlike' => env('REC_WEIGHT_UNLIKE', -0.5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Half-life por escopo
    |--------------------------------------------------------------------------
    |
    | Decay exponencial usado para esquecer interações antigas em cada escopo.
    |
    */

    'half_life' => [
        'long_term_days' => env('REC_HL_LT_DAYS', 30),
        'short_term_hours' => env('REC_HL_ST_HOURS', 6),
        'avoid_days' => env('REC_HL_AV_DAYS', 30),
        'recency_hours' => env('REC_HL_RECENCY_HOURS', 6),
        'trending_hours' => env('REC_HL_TRENDING_HOURS', 24),
    ],

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

    /*
    |--------------------------------------------------------------------------
    | Trending pool
    |--------------------------------------------------------------------------
    */

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

    /*
    |--------------------------------------------------------------------------
    | Score composto (alpha, beta, gamma, delta, epsilon)
    |--------------------------------------------------------------------------
    */

    'score' => [
        'alpha' => env('REC_SCORE_ALPHA', 0.6),
        'alpha_default' => env('REC_SCORE_ALPHA_DEFAULT', 0.8),
        'alpha_active_session' => env('REC_SCORE_ALPHA_ACTIVE_SESSION', 0.3),
        'alpha_active_threshold' => env('REC_SCORE_ALPHA_ACTIVE_THRESHOLD', 5),
        'beta' => env('REC_SCORE_BETA', 0.5),
        'beta_avoid' => env('REC_SCORE_BETA_AVOID', 0.5),
        'gamma' => env('REC_SCORE_GAMMA', 0.15),
        'gamma_recency' => env('REC_SCORE_GAMMA_RECENCY', 0.15),
        'delta' => env('REC_SCORE_DELTA', 0.1),
        'delta_trending' => env('REC_SCORE_DELTA_TRENDING', 0.1),
        'epsilon' => env('REC_SCORE_EPSILON', 0.05),
        'epsilon_context' => env('REC_SCORE_EPSILON_CONTEXT', 0.05),
        'recency_half_life_hours' => env('REC_SCORE_RECENCY_HALF_LIFE', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | MMR (Maximal Marginal Relevance)
    |--------------------------------------------------------------------------
    */

    'mmr' => [
        'lambda' => env('REC_MMR_LAMBDA', 0.7),
        'pool_size' => env('REC_MMR_POOL_SIZE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Quota por criador no top-K
    |--------------------------------------------------------------------------
    */

    'quota' => [
        'top_k' => env('REC_QUOTA_TOP_K', 20),
        'per_author' => env('REC_QUOTA_PER_AUTHOR', 2),
    ],

    'author_quota' => [
        'top_k' => env('REC_QUOTA_TOP_K', 20),
        'per_author' => env('REC_QUOTA_PER_AUTHOR', 2),
    ],

    'seen' => [
        'ttl_seconds' => env('REC_SEEN_TTL_SECONDS', 172800),
        'redis_prefix' => env('REC_SEEN_REDIS_PREFIX', 'rec:user'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cold start
    |--------------------------------------------------------------------------
    */

    'cold_start' => [
        'threshold' => env('REC_COLD_START_THRESHOLD', 5),
        'interactions_threshold' => env('REC_COLD_START_INTERACTIONS', 5),
        'recent_per_trending' => env('REC_COLD_START_RECENT_PER_TRENDING', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Moderação
    |--------------------------------------------------------------------------
    */

    'moderation' => [
        'reports_threshold' => env('REC_MODERATION_REPORTS_THRESHOLD', 3),
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

    /*
    |--------------------------------------------------------------------------
    | Kill-switch
    |--------------------------------------------------------------------------
    |
    | Quando o pipeline está desligado, `RecommendationService::feedFor`
    | retorna direto o feed cronológico (`latest('posts.created_at')`).
    |
    */

    'kill_switch' => [
        'cache_key' => env('REC_KILL_SWITCH_KEY', 'rec:kill_switch'),
        'cache_ttl_seconds' => env('REC_KILL_SWITCH_TTL', 86400 * 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Healthcheck
    |--------------------------------------------------------------------------
    */

    'healthcheck' => [
        'embedding_error_rate_threshold' => env('REC_HEALTH_EMBEDDING_ERROR_PCT', 5.0),
        'realtime_queue_lag_seconds' => env('REC_HEALTH_REALTIME_LAG', 60),
        'window_minutes' => env('REC_HEALTH_WINDOW_MINUTES', 15),
        'alert_dedup_prefix' => env('REC_HEALTH_DEDUP_PREFIX', 'rec:health:alert'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tooling de operador (admin gate)
    |--------------------------------------------------------------------------
    |
    | Lista de e-mails que podem acessar /admin/rec/metrics. Defina via
    | env REC_ADMIN_EMAILS=alice@x.com,bob@y.com.
    |
    */

    'admin_emails' => array_filter(array_map(
        'trim',
        explode(',', (string) env('REC_ADMIN_EMAILS', ''))
    )),

    /*
    |--------------------------------------------------------------------------
    | A/B testing (US-021, US-024)
    |--------------------------------------------------------------------------
    |
    | Experimentos ativos. A atribuição é determinística por hash
    | (user_id + experiment_name, opcionalmente + dia) sem necessidade de
    | gravar em banco. A tabela `recommendation_experiments` existe para
    | casos de atribuição persistente/auditoria.
    |
    */

    'experiments' => [
        'random_serving' => [
            'enabled' => env('REC_EXPERIMENT_RANDOM_SERVING_ENABLED', true),
            'control_pct' => env('REC_EXPERIMENT_RANDOM_SERVING_PCT', 1),
        ],
        'ranking_formula_v2' => [
            'enabled' => env('REC_EXPERIMENT_RANKING_V2_ENABLED', false),
            'variant_b_pct' => env('REC_EXPERIMENT_RANKING_V2_PCT', 50),
            'variant_b' => [
                'beta_avoid' => env('REC_EXPERIMENT_V2_BETA', 0.3),
                'gamma_recency' => env('REC_EXPERIMENT_V2_GAMMA', 0.5),
                'delta_trending' => env('REC_EXPERIMENT_V2_DELTA', 0.25),
                'epsilon_context' => env('REC_EXPERIMENT_V2_EPSILON', 0.0),
            ],
        ],
    ],

];
