<?php

declare(strict_types=1);

return [
    'epm_data_mart' => [
        'queue' => env('EPM_DATA_MART_QUEUE', 'epm-data-mart'),
        'stale_after_minutes' => (int) env('EPM_DATA_MART_STALE_AFTER_MINUTES', 120),
        'slow_after_ms' => (int) env('EPM_DATA_MART_SLOW_AFTER_MS', 30000),
        'running_stuck_after_minutes' => (int) env('EPM_DATA_MART_RUNNING_STUCK_AFTER_MINUTES', 30),
        'health_history_limit' => (int) env('EPM_DATA_MART_HEALTH_HISTORY_LIMIT', 1000),
    ],
];
