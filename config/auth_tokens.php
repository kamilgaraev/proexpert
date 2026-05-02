<?php

declare(strict_types=1);

return [
    'landing_cookie' => [
        'name' => env('LANDING_JWT_COOKIE_NAME', 'prohelper_landing_token'),
        'domain' => env('AUTH_COOKIE_DOMAIN', env('SESSION_DOMAIN')),
        'secure' => (bool) env('AUTH_COOKIE_SECURE', env('APP_ENV') === 'production'),
        'same_site' => env('AUTH_COOKIE_SAME_SITE', 'lax'),
        'ttl_minutes' => (int) env('JWT_TTL', 60),
    ],
];
