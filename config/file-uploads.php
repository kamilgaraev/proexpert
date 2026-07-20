<?php

return [
    'legal_archive' => [
        'max_size_bytes' => (int) env('LEGAL_ARCHIVE_MAX_FILE_SIZE_BYTES', 104857600),
        'temporary_url_minutes' => (int) env('LEGAL_ARCHIVE_TEMPORARY_URL_MINUTES', 5),
        'scanner' => env('LEGAL_ARCHIVE_SCANNER', 'fail_closed'),
        'allowed_extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods'],
        'allowed_mime_types' => [
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xls' => ['application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'odt' => ['application/vnd.oasis.opendocument.text'],
            'ods' => ['application/vnd.oasis.opendocument.spreadsheet'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Upload Settings
    |--------------------------------------------------------------------------
    |
    | Настройки загрузки файлов в системе
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Maximum File Sizes (in MB)
    |--------------------------------------------------------------------------
    |
    | Максимальные размеры файлов для разных типов загрузок
    |
    */

    'max_sizes' => [
        // Общий максимальный размер файла
        'default' => env('MAX_FILE_SIZE_MB', 100),

        // Максимальный размер для PDF документов (акты, счета и т.д.)
        'pdf_documents' => env('MAX_PDF_SIZE_MB', 100),

        // Максимальный размер для изображений
        'images' => env('MAX_IMAGE_SIZE_MB', 10),

        // Максимальный размер для Excel/CSV файлов (импорт/экспорт)
        'spreadsheets' => env('MAX_SPREADSHEET_SIZE_MB', 10),

        // Максимальный размер для архивов
        'archives' => env('MAX_ARCHIVE_SIZE_MB', 500),

        // Максимальный размер для видео файлов
        'videos' => env('MAX_VIDEO_SIZE_MB', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed MIME Types
    |--------------------------------------------------------------------------
    |
    | Разрешенные MIME типы для разных категорий файлов
    |
    */

    'allowed_mime_types' => [
        'pdf_documents' => [
            'application/pdf',
        ],
        'images' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
        ],
        'spreadsheets' => [
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv',
            'application/csv',
        ],
        'archives' => [
            'application/zip',
            'application/x-rar-compressed',
            'application/x-7z-compressed',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload Directories
    |--------------------------------------------------------------------------
    |
    | Структура директорий для загрузки файлов
    |
    */

    'directories' => [
        'acts' => 'acts',
        'contracts' => 'contracts',
        'invoices' => 'invoices',
        'materials' => 'materials',
        'projects' => 'projects',
        'reports' => 'reports',
        'users' => 'users',
    ],
];
