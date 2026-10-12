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
    // Coletor local (Mac Mini com IP brasileiro): CBF bloqueia acesso de fora do Brasil.
    'collector_token' => env('COLLECTOR_TOKEN'),
    'collector_season' => env('COLLECTOR_SEASON', '2026'),
    'collector_competitions' => [
        'campeonato-brasileiro/serie-a' => 'Série A',
        'campeonato-brasileiro/serie-b' => 'Série B',
        'campeonato-brasileiro/serie-c' => 'Série C',
        'campeonato-brasileiro/serie-d' => 'Série D',
        'copa-do-brasil/masculino' => 'Copa do Brasil',
        'copa-do-nordeste/masculino' => 'Copa do Nordeste',
        'copa-verde/masculino' => 'Copa Verde',
        'copa-sul-sudeste/masculino' => 'Copa Sul-Sudeste',
        // Base (Sub-20 inclui atletas de 17 anos): só nome/clube no registro; detalhe apenas de quem vocês cadastrarem no scout.
        'campeonato-brasileiro/sub-20' => 'Brasileiro Sub-20',
        'campeonato-brasileiro/sub-20-b' => 'Brasileiro Sub-20 B',
        'copa-do-brasil/sub-20' => 'Copa do Brasil Sub-20',
        // Fora de propósito: Sub-17/Sub-15/Sub-13 (todos menores de idade) e feminino.
    ],

    'credentials' => [
        'API_FUTEBOL_KEY' => env('API_FUTEBOL_KEY'),
        'API_FOOTBALL_KEY' => env('API_FOOTBALL_KEY'),
        'SPORTMONKS_TOKEN' => env('SPORTMONKS_TOKEN'),
        'SOCCERSAPI_TOKEN' => env('SOCCERSAPI_TOKEN'),
        'SPIDER_CLOUD_KEY' => env('SPIDER_CLOUD_KEY'),
        'CBF_PARTNER_CREDENTIAL' => env('CBF_PARTNER_CREDENTIAL'),
    ],
];
