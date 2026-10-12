<?php

return [
    'season_label' => env('SCOUT_SEASON_LABEL', '2026/2027'),
    'demo_mode' => (bool) env('SCOUT_DEMO_MODE', false),
    'display_timezone' => env('DISPLAY_TIMEZONE', 'America/Belem'),
    // Hipóteses configuráveis (prompt v2 §3): não são padrões universais.
    'min_minutes_ranking' => (int) env('SCOUT_MIN_MINUTES', 900),
    'min_coverage' => (float) env('SCOUT_MIN_COVERAGE', 0.8),
    'import_max_rows' => (int) env('SCOUT_IMPORT_MAX_ROWS', 5000),
];
