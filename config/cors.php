<?php

return [
    /*
    |--------------------------------------------------------------------------
    | CORS конфигурация
    |--------------------------------------------------------------------------
    |
    | Тут находятся настройки для Cross-Origin Resource Sharing
    | Эти настройки используются CorsMiddleware
    |
    */

    'paths' => [
        'api/*', 
        'sanctum/csrf-cookie', 
        'api/v1/landing/*',
        'api/v1/mobile/*',
        'api/v1/admin/*',
        'api/v1/holding-api/*',
        // '*'  // Убираем или оставляем только для крайних случаев в разработке, если allow_any_origin_in_dev не используется
    ],

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
        'https://xn--1-xtbgmf.xn--p1ai',
        'http://xn--1-xtbgmf.xn--p1ai',
        'https://lk.xn--1-xtbgmf.xn--p1ai',
        'https://api.xn--1-xtbgmf.xn--p1ai',
        'https://admin.xn--1-xtbgmf.xn--p1ai',
        'https://www.xn--1-xtbgmf.xn--p1ai',
        'http://www.xn--1-xtbgmf.xn--p1ai',
        // '*'  // Убираем, чтобы избежать конфликта с supports_credentials = true
    ],

    'allowed_origins_patterns' => [
        '/^https?:\/\/[a-z0-9-]+\.prohelper\.pro$/',
    ],

    'allowed_methods' => [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
        'OPTIONS',
    ],

    'allowed_headers' => [
        'Content-Type',
        'X-Auth-Token',
        'Origin',
        'Authorization',
        'X-Requested-With',
        'X-CSRF-TOKEN',
        'Accept',
    ],

    'exposed_headers' => [
        'Content-Length',
        'X-JSON',
    ],

    'max_age' => 86400, // 24 часа

    'supports_credentials' => true, // Оставляем true

    /*
    |--------------------------------------------------------------------------
    | Дополнительные настройки
    |--------------------------------------------------------------------------
    */
    
    // Разрешить любой origin в режиме разработки (env=local)
    // ВРЕМЕННО ОТКЛЮЧЕНО для продакшена
    'allow_any_origin_in_dev' => false,
];
