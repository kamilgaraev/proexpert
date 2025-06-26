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
        'http://localhost:8081',
        'http://127.0.0.1:8000',
        'http://127.0.0.1:8081',
        'http://127.0.0.1:3000',
        'http://89.111.152.112',
        'https://89.111.152.112',
        'http://89.104.68.13',
        'http://89.111.153.146',
        'https://89.111.153.146',
        'https://prohelper.pro',
        'http://prohelper.pro',
        // '*'  // Убираем, чтобы избежать конфликта с supports_credentials = true
    ],

    'allowed_origins_patterns' => [
        '/^https?:\/\/.*\.prohelper\.pro$/',
        '/^https?:\/\/(?!www|api|admin|mail|ftp)[a-z0-9-]+\.prohelper\.pro$/',
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
    'allow_any_origin_in_dev' => true,
]; 