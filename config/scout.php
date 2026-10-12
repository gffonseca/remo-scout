<?php

return [
    'season_label' => env('SCOUT_SEASON_LABEL', '2026/2027'),
    'demo_mode' => (bool) env('SCOUT_DEMO_MODE', false),
    'display_timezone' => env('DISPLAY_TIMEZONE', 'America/Belem'),
    // Hipóteses configuráveis (prompt v2 §3): não são padrões universais.
    'min_minutes_ranking' => (int) env('SCOUT_MIN_MINUTES', 900),
    'min_coverage' => (float) env('SCOUT_MIN_COVERAGE', 0.8),
    'import_max_rows' => (int) env('SCOUT_IMPORT_MAX_ROWS', 5000),
    // Credenciais de fontes (lidas do ambiente; config:cache exige passar por aqui). Nunca versionar valores.
    'credentials' => [
        'API_FUTEBOL_KEY' => env('API_FUTEBOL_KEY'),
        'API_FOOTBALL_KEY' => env('API_FOOTBALL_KEY'),
        'SPORTMONKS_TOKEN' => env('SPORTMONKS_TOKEN'),
        'SOCCERSAPI_TOKEN' => env('SOCCERSAPI_TOKEN'),
        'SPIDER_CLOUD_KEY' => env('SPIDER_CLOUD_KEY'),
        'CBF_PARTNER_CREDENTIAL' => env('CBF_PARTNER_CREDENTIAL'),
    ],
];
