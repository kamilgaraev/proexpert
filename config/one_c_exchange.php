<?php

declare(strict_types=1);

return [
    'delivery' => [
        'enabled' => (bool) env('ONE_C_EXCHANGE_DELIVERY_ENABLED', env('ONE_C_EXCHANGE_ENDPOINT') !== null),
        'endpoint' => env('ONE_C_EXCHANGE_ENDPOINT'),
        'token' => env('ONE_C_EXCHANGE_TOKEN'),
        'timeout_seconds' => (int) env('ONE_C_EXCHANGE_TIMEOUT_SECONDS', 15),
        'connect_timeout_seconds' => (int) env('ONE_C_EXCHANGE_CONNECT_TIMEOUT_SECONDS', 5),
        'scheduled_limit' => (int) env('ONE_C_EXCHANGE_SCHEDULED_LIMIT', 50),
        'processing_timeout_minutes' => (int) env('ONE_C_EXCHANGE_PROCESSING_TIMEOUT_MINUTES', 15),
    ],
];
