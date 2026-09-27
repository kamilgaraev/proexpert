<?php

declare(strict_types=1);

return [
    'metrics_token' => env('PROMETHEUS_METRICS_TOKEN'),
    'slow_query_threshold_ms' => (int) env('SLOW_QUERY_THRESHOLD_MS', 500),
    'tracing_enabled' => (bool) env('OTEL_TRACING_ENABLED', false),
    'tracing_endpoint' => env('OTEL_TRACING_ENDPOINT', 'http://collector:4318/v1/traces'),
    'tracing_sample_ratio' => (float) env('OTEL_TRACING_SAMPLE_RATIO', 0.1),
];
