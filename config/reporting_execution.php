<?php

declare(strict_types=1);

$trustedSealKeys = json_decode(
    (string) env(
        'REPORT_TRUSTED_SEAL_KEYS_JSON',
        '{"unconfigured":{"public_key":"AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA","revoked":true}}',
    ),
    true,
);

return [
    'runs' => [
        'ttl_seconds' => (int) env('REPORT_RUN_TTL_SECONDS', 86400),
        'poll_after_ms' => (int) env('REPORT_RUN_POLL_AFTER_MS', 1250),
    ],
    'exports' => [
        'ttl_seconds' => (int) env('REPORT_EXPORT_TTL_SECONDS', 86400),
        'poll_after_ms' => (int) env('REPORT_EXPORT_POLL_AFTER_MS', 1250),
        'chunk_size' => (int) env('REPORT_EXPORT_CHUNK_SIZE', 1000),
    ],
    'dispatch' => [
        'batch_size' => 100,
        'lease_seconds' => 60,
        'max_attempts' => 12,
    ],
    'audit' => [
        'batch_size' => 100,
        'lease_seconds' => 300,
        'max_attempts' => 12,
    ],
    'execution' => [
        'lease_seconds' => 960,
        'watchdog_batch_size' => 100,
    ],
    'artifacts' => [
        'reconciliation_grace_seconds' => 3600,
    ],
    'trusted_seal_keys' => $trustedSealKeys,
    'pdf_budgets' => [],
    'alerts' => [
        'oldest_pending_seconds' => 300,
        'audit_dead_letters' => 0,
        'dispatch_failure_ratio' => 0.05,
        'lease_reclaims' => 3,
        'execution_error_ratio' => 0.05,
        'duration_regression_ratio' => 1.25,
        'storage_abort_ratio' => 0.01,
    ],
];
