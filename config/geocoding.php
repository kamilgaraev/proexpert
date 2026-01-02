<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Geocoding Provider
    |--------------------------------------------------------------------------
    |
    | This option controls the default geocoding provider that will be used
    | by the geocoding service. You may change this to any of the providers
    | configured below.
    |
    */
    'default_provider' => env('GEOCODING_PROVIDER', 'dadata'),

    /*
    |--------------------------------------------------------------------------
    | Geocoding Providers
    |--------------------------------------------------------------------------
    |
    | Here you may configure the geocoding providers for your application.
    | Each provider has its own configuration options.
    |
    */
    'providers' => [
        'dadata' => [
            'enabled' => env('DADATA_ENABLED', true),
            'api_key' => env('DADATA_API_KEY', 'c2110ee53431438f940545629894ebb5dc1fb1a4'),
            'secret_key' => env('DADATA_SECRET_KEY', '9acd90e91b45e9105f0a7fac58bfebca6addf914'),
            'url' => 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address',
            'clean_url' => 'https://cleaner.dadata.ru/api/v1/clean/address',
            'timeout' => 5,
            'priority' => 1, // Highest priority for Russian addresses
        ],

        'yandex' => [
            'enabled' => env('YANDEX_GEOCODER_ENABLED', true),
            'api_key' => env('YANDEX_GEOCODER_KEY'),
            'url' => 'https://geocode-maps.yandex.ru/1.x/',
            'timeout' => 5,
            'priority' => 2,
        ],

        'nominatim' => [
            'enabled' => env('NOMINATIM_ENABLED', true),
            'url' => 'https://nominatim.openstreetmap.org/search',
            'user_agent' => env('APP_NAME', 'ProHelper') . ' Geocoding Service',
            'timeout' => 10,
            'priority' => 3, // Fallback option
            'rate_limit' => 1, // Max 1 request per second
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Configure retry behavior when geocoding fails.
    |
    */
    'retry' => [
        'attempts' => 3,
        'delay' => 60, // seconds between retries
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Geocoding results are cached to reduce API calls and improve performance.
    |
    */
    'cache_ttl' => 86400 * 30, // 30 days (addresses rarely change)
    'cache_prefix' => 'geocode:',

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the queue for background geocoding jobs.
    |
    */
    'queue' => [
        'enabled' => env('GEOCODING_QUEUE_ENABLED', true),
        'connection' => env('GEOCODING_QUEUE_CONNECTION', 'redis'),
        'queue_name' => 'geocoding',
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch Processing
    |--------------------------------------------------------------------------
    |
    | Configuration for batch geocoding operations.
    |
    */
    'batch' => [
        'chunk_size' => 100, // Process N projects at a time
        'rate_limit' => 10, // Max requests per second
        'delay_between_chunks' => 1000, // milliseconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Confidence Threshold
    |--------------------------------------------------------------------------
    |
    | Minimum confidence level (0.0 - 1.0) to accept geocoding results.
    | Results below this threshold will be marked as failed.
    |
    */
    'min_confidence' => 0.5,

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Enable detailed logging of geocoding requests and responses.
    |
    */
    'logging' => [
        'enabled' => env('GEOCODING_LOG_ENABLED', true),
        'log_requests' => env('GEOCODING_LOG_REQUESTS', false),
        'log_responses' => env('GEOCODING_LOG_RESPONSES', false),
    ],
];

