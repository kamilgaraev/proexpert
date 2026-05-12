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
    'sessions' => [
        'enabled' => env('AUTH_SESSIONS_ENABLED', true),
        'enforce' => env('AUTH_SESSIONS_ENFORCE', false),
        'notify_new_device' => env('AUTH_SESSIONS_NOTIFY_NEW_DEVICE', true),
        'max_active_per_user' => (int) env('AUTH_MAX_ACTIVE_SESSIONS_PER_USER', 3),
        'last_seen_update_seconds' => (int) env('AUTH_SESSION_LAST_SEEN_UPDATE_SECONDS', 300),
    ],
];
