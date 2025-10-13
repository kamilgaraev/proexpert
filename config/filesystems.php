<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Основной диск – для всех файлов организаций (Yandex Object Storage)
        's3' => [
            'driver' => 's3',
            'key'    => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'ru-central1'),
            'bucket' => 'prohelper-storage', // основной бакет для всех организаций
            // bucket устанавливается динамически в OrgBucketService
            'endpoint' => env('AWS_ENDPOINT', 'https://storage.yandexcloud.net'),
            'use_path_style_endpoint' => true, // для Yandex Object Storage нужен path-style
            'throw'   => false,
            'report'  => false,
        ],

        // Диск для отчётов (общий бакет, private ACL, YC endpoint)
        'reports' => [
            'driver' => 's3',
            'key'    => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'ru-central1'),
            'bucket' => env('REPORTS_BUCKET', 'official-reports'),
            'endpoint' => env('AWS_ENDPOINT', 'https://storage.yandexcloud.net'),
            'use_path_style_endpoint' => true,
            'throw'   => false,
            'report'  => false,
        ],

        // Диск для персональных файлов пользователей
        'personals' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'ru-msk'),
            'bucket' => env('AWS_PERSONALS_BUCKET', 'personals'),
            // Хост бакета поддерживает virtual-hosted стиль (https://personals.website.regru.cloud)
            // оставляем стандартный endpoint для API-вызовов
            'endpoint' => env('AWS_ENDPOINT', 'https://s3.regru.cloud'),
            'use_path_style_endpoint' => true, // единообразно с другими Regru-дисками
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
