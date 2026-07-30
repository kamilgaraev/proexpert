<?php

declare(strict_types=1);

return [
    'queue' => 'reports-subscriptions',
    'max_attempts' => 5,
    'backoff_seconds' => [60, 300, 900, 1800],
    'execution_ttl_seconds' => 86400,
    'retention_days' => 90,
    'poll_min_ms' => 1000,
    'poll_max_ms' => 30000,
    'scheduler_batch_size' => 100,
];
