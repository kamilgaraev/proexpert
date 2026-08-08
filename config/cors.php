<?php

declare(strict_types=1);

return [
    'paths' => [
        'api/*',
    ],
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
    'allowed_methods' => [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
        'OPTIONS',
    ],
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Idempotency-Key',
        'If-None-Match',
        'Origin',
        'X-CSRF-Token',
        'X-Requested-With',
    ],
    'exposed_headers' => [
        'Content-Length',
        'ETag',
    ],
    'max_age' => 86400,
    'supports_credentials' => true,
    'allow_any_origin_in_dev' => false,
];
