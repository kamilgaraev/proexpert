<?php

declare(strict_types=1);

return [
    'marketing_frontend_url' => env('MARKETING_FRONTEND_URL', 'https://1мост.рф'),
    'platform_content_organization_id' => env('PLATFORM_CONTENT_ORGANIZATION_ID'),
    'preview_ttl_minutes' => (int) env('BLOG_PREVIEW_TTL_MINUTES', 30),
    'indexnow' => [
        'enabled' => filter_var(env('INDEXNOW_ENABLED', true), FILTER_VALIDATE_BOOL),
        'endpoint' => env('INDEXNOW_ENDPOINT', 'https://yandex.com/indexnow'),
        'public_base_url' => env('INDEXNOW_PUBLIC_BASE_URL', 'https://xn--1-xtbgmf.xn--p1ai'),
        'host' => env('INDEXNOW_HOST', 'xn--1-xtbgmf.xn--p1ai'),
        'key' => env('INDEXNOW_KEY', 'e2f7db5fbb76f480cbe256ff9e718074'),
        'key_location' => env(
            'INDEXNOW_KEY_LOCATION',
            'https://xn--1-xtbgmf.xn--p1ai/e2f7db5fbb76f480cbe256ff9e718074.txt',
        ),
        'timeout_seconds' => (int) env('INDEXNOW_TIMEOUT_SECONDS', 10),
    ],
];
