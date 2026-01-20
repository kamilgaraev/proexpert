<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Estimates Module Configuration
    |--------------------------------------------------------------------------
    |
    | Конфигурация модуля автоматической генерации смет с помощью AI
    |
    */

    // Кеширование
    'cache' => [
        'enabled' => env('AI_ESTIMATES_CACHE_ENABLED', true),
        'ttl' => env('AI_ESTIMATES_CACHE_TTL', 3600), // 1 час
    ],

    // Файлы и OCR (Yandex Vision)
    'max_file_size' => env('AI_ESTIMATES_MAX_FILE_SIZE', 50), // MB
    'allowed_file_types' => ['pdf', 'jpg', 'jpeg', 'png', 'xlsx', 'xls'],

    // AI настройки
    'ai' => [
        'temperature' => 0.3,
        'max_tokens' => 2000,
        'confidence_threshold' => 0.75,
    ],

    // Каталог маппинг
    'catalog_matching' => [
        'fuzzy_search' => true,
        'min_confidence' => 0.6,
        'max_alternatives' => 3,
    ],
];
