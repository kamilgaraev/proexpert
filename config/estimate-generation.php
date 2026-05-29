<?php

declare(strict_types=1);

return [
    'ocr' => [
        'provider' => env('ESTIMATE_GENERATION_OCR_PROVIDER', 'yandex_cloud_ocr'),
        'enabled' => (bool) env('ESTIMATE_GENERATION_OCR_ENABLED', true),
        'languages' => ['ru', 'en'],
        'model' => env('ESTIMATE_GENERATION_OCR_MODEL', 'page'),
        'timeout_seconds' => (int) env('ESTIMATE_GENERATION_OCR_TIMEOUT', 60),
        'retry_attempts' => (int) env('ESTIMATE_GENERATION_OCR_RETRY_ATTEMPTS', 3),
        'retry_delay_ms' => (int) env('ESTIMATE_GENERATION_OCR_RETRY_DELAY_MS', 250),
        'max_sync_file_bytes' => 10 * 1024 * 1024,
        'max_spreadsheet_file_bytes' => 50 * 1024 * 1024,
        'max_spreadsheet_rows' => 2_000,
        'max_spreadsheet_columns' => 80,
        'max_image_pixels' => 20_000_000,
        'max_pdf_pages' => 200,
        'min_usable_quality_score' => 0.60,
        'min_good_quality_score' => 0.80,
        'queue' => env('REDIS_ESTIMATE_GENERATION_QUEUE', 'estimate-generation'),
        'yandex' => [
            'endpoint' => env('YANDEX_OCR_ENDPOINT', 'https://ocr.api.cloud.yandex.net/ocr/v1/recognizeText'),
            'folder_id' => env('YANDEX_VISION_FOLDER_ID'),
            'api_key' => env('YANDEX_VISION_API_KEY'),
            'iam_token' => env('YANDEX_OCR_IAM_TOKEN'),
            'auth_mode' => env('YANDEX_OCR_AUTH_MODE', 'api_key'),
        ],
    ],
];
