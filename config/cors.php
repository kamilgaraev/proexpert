<?php

declare(strict_types=1);

return [
    'paths' => [
        'api/*',
    ],
<<<<<<< HEAD
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
=======

    'allowed_origins' => [
        'http://localhost:5173',
        'http://localhost:3000',
        'http://localhost:3001',
        'http://localhost:8081',
        'http://127.0.0.1:8000',
        'http://127.0.0.1:8081',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:3001',
        'http://89.111.152.112',
        'https://89.111.152.112',
        'http://89.104.68.13',
        'http://89.111.153.146',
        'https://89.111.153.146',
        'https://1мост.рф',
        'http://1мост.рф',
        'https://lk.1мост.рф',
        'https://api.1мост.рф',
        'https://admin.1мост.рф',
        'https://www.1мост.рф',
        'http://www.1мост.рф',
        // '*'  // Убираем, чтобы избежать конфликта с supports_credentials = true
    ],

    'allowed_origins_patterns' => [
        '/^https?:\/\/[a-z0-9-]+\.prohelper\.pro$/',
    ],

>>>>>>> fix/glitchtip-257-upload-error-reporting
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
