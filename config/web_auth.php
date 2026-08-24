<?php

declare(strict_types=1);

$origins = static function (string $key, string $default): array {
    return array_values(array_unique(array_filter(array_map(
        static fn (string $origin): string => trim($origin),
        explode(',', (string) env($key, $default)),
    ))));
};

return [
    'issuer' => env('WEB_AUTH_ISSUER', env('APP_URL', 'https://api.1мост.рф')),
    'origins' => [
        'lk' => $origins(
            'WEB_AUTH_LK_ALLOWED_ORIGINS',
            'https://lk.1мост.рф,https://lk.xn--1-xtbgmf.xn--p1ai,https://lk.prohelper.pro',
        ),
        'admin' => $origins(
            'WEB_AUTH_ADMIN_ALLOWED_ORIGINS',
            'https://admin.1мост.рф,https://admin.xn--1-xtbgmf.xn--p1ai,https://admin.prohelper.pro',
        ),
        'customer' => $origins(
            'WEB_AUTH_CUSTOMER_ALLOWED_ORIGINS',
            'https://customer.1мост.рф,https://customer.xn--1-xtbgmf.xn--p1ai,https://customer.prohelper.pro',
        ),
        'public' => $origins(
            'CORS_PUBLIC_ALLOWED_ORIGINS',
            'https://1мост.рф,https://www.1мост.рф,https://xn--1-xtbgmf.xn--p1ai,https://www.xn--1-xtbgmf.xn--p1ai,https://prohelper.pro,https://www.prohelper.pro',
        ),
    ],
    'access_ttl_minutes' => max(1, (int) env('WEB_AUTH_ACCESS_TTL_MINUTES', 15)),
    'refresh_ttl_minutes' => max(1, (int) env('WEB_AUTH_REFRESH_TTL_MINUTES', 1440)),
    'refresh_concurrency_window_seconds' => max(1, (int) env('WEB_AUTH_REFRESH_CONCURRENCY_WINDOW_SECONDS', 5)),
    'remember_refresh_ttl_minutes' => max(1, (int) env('WEB_AUTH_REMEMBER_REFRESH_TTL_MINUTES', 20160)),
    'registration' => [
        'idempotency_ttl_hours' => max(1, (int) env('WEB_AUTH_REGISTRATION_IDEMPOTENCY_TTL_HOURS', 24)),
        'terms_version' => env('WEB_AUTH_TERMS_VERSION', '2026-08-24'),
        'privacy_version' => env('WEB_AUTH_PRIVACY_VERSION', '2026-08-24'),
    ],
    'keys' => [
        'lk' => env('WEB_AUTH_LK_SIGNING_KEY'),
        'admin' => env('WEB_AUTH_ADMIN_SIGNING_KEY'),
        'customer' => env('WEB_AUTH_CUSTOMER_SIGNING_KEY', env('WEB_AUTH_LK_SIGNING_KEY')),
    ],
    'cookies' => [
        'lk' => [
            'name' => '__Host-most-lk-refresh',
        ],
        'admin' => [
            'name' => '__Host-most-admin-refresh',
        ],
        'customer' => [
            'name' => '__Host-most-customer-refresh',
        ],
    ],
];
