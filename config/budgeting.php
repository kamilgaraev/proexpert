<?php

declare(strict_types=1);

return [
    'epm_data_mart' => [
        'queue' => env('EPM_DATA_MART_QUEUE', 'epm-data-mart'),
        'stale_after_minutes' => (int) env('EPM_DATA_MART_STALE_AFTER_MINUTES', 120),
    ],
];
