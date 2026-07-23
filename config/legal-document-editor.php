<?php

declare(strict_types=1);

return [
    'driver' => env('LEGAL_DOCUMENT_EDITOR_DRIVER', 'onlyoffice'),
    'enabled' => filter_var(env('LEGAL_DOCUMENT_EDITOR_ENABLED', false), FILTER_VALIDATE_BOOL),
    'url' => env('LEGAL_DOCUMENT_EDITOR_URL'),
    'jwt_secret' => env('LEGAL_DOCUMENT_EDITOR_JWT_SECRET'),
    'callback_base_url' => env('LEGAL_DOCUMENT_EDITOR_CALLBACK_BASE_URL', env('APP_URL')),
    'session_ttl_minutes' => (int) env('LEGAL_DOCUMENT_EDITOR_SESSION_TTL_MINUTES', 120),
    'source_url_ttl_minutes' => (int) env('LEGAL_DOCUMENT_EDITOR_SOURCE_URL_TTL_MINUTES', 10),
    'callback' => [
        'max_body_bytes' => (int) env('LEGAL_DOCUMENT_EDITOR_CALLBACK_MAX_BODY_BYTES', 65536),
    ],
    'download' => [
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('LEGAL_DOCUMENT_EDITOR_DOWNLOAD_ALLOWED_HOSTS', ''))))),
        'max_size_bytes' => (int) env('LEGAL_DOCUMENT_EDITOR_DOWNLOAD_MAX_SIZE_BYTES', 104857600),
        'max_redirects' => (int) env('LEGAL_DOCUMENT_EDITOR_DOWNLOAD_MAX_REDIRECTS', 1),
        'connect_timeout_seconds' => (float) env('LEGAL_DOCUMENT_EDITOR_DOWNLOAD_CONNECT_TIMEOUT_SECONDS', 10),
        'idle_timeout_seconds' => (float) env('LEGAL_DOCUMENT_EDITOR_DOWNLOAD_IDLE_TIMEOUT_SECONDS', 30),
        'max_duration_seconds' => (float) env('LEGAL_DOCUMENT_EDITOR_DOWNLOAD_MAX_DURATION_SECONDS', 900),
    ],
];
