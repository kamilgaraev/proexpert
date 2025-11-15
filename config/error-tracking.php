<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Error Tracking Mode
    |--------------------------------------------------------------------------
    |
    | Режим работы error tracking:
    | - 'sync' - синхронная запись в БД (быстро, но может не сработать при падении)
    | - 'async' - через очередь (медленнее, но надежнее)
    |
    */
    'mode' => env('ERROR_TRACKING_MODE', 'async'),

    /*
    |--------------------------------------------------------------------------
    | Queue Name
    |--------------------------------------------------------------------------
    |
    | Имя очереди для обработки ошибок (только для async режима)
    |
    */
    'queue' => env('ERROR_TRACKING_QUEUE', 'logging'),

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Включить/выключить error tracking
    |
    */
    'enabled' => env('ERROR_TRACKING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Fallback Log File
    |--------------------------------------------------------------------------
    |
    | Файл для записи ошибок если БД и очередь недоступны
    |
    */
    'fallback_log' => storage_path('logs/error-tracking-fallback.log'),

    /*
    |--------------------------------------------------------------------------
    | Ignored Exceptions
    |--------------------------------------------------------------------------
    |
    | Список exception'ов которые НЕ нужно сохранять в error tracking
    |
    */
    'ignored_exceptions' => [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Validation\ValidationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention Days
    |--------------------------------------------------------------------------
    |
    | Сколько дней хранить ошибки в БД (для автоочистки)
    |
    */
    'retention_days' => env('ERROR_TRACKING_RETENTION_DAYS', 30),
];

