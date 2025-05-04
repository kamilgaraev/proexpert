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
        '*'  // В крайнем случае, для режима разработки
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
        '*'  // В крайнем случае, для режима разработки
    ],

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

    'supports_credentials' => true,

    /*
    |--------------------------------------------------------------------------
    | Дополнительные настройки
    |--------------------------------------------------------------------------
    */
    
    // Разрешить любой origin в режиме разработки (env=local)
    'allow_any_origin_in_dev' => true,
]; 