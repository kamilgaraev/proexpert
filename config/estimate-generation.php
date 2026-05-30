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
        'pdf_text_layer_min_chars' => (int) env('ESTIMATE_GENERATION_OCR_PDF_TEXT_LAYER_MIN_CHARS', 20),
        'pdf_parser_memory_limit' => env('ESTIMATE_GENERATION_OCR_PDF_PARSER_MEMORY_LIMIT', '512M'),
        'min_usable_quality_score' => 0.60,
        'min_good_quality_score' => 0.80,
        'queue' => env('REDIS_ESTIMATE_GENERATION_QUEUE', 'estimate-generation'),
        'yandex' => [
            'endpoint' => env('YANDEX_OCR_ENDPOINT', 'https://ocr.api.cloud.yandex.net/ocr/v1/recognizeText'),
            'async_endpoint' => env('YANDEX_OCR_ASYNC_ENDPOINT', 'https://ocr.api.cloud.yandex.net/ocr/v1/recognizeTextAsync'),
            'operations_endpoint' => env('YANDEX_OCR_OPERATIONS_ENDPOINT', 'https://operation.api.cloud.yandex.net/operations'),
            'get_recognition_endpoint' => env('YANDEX_OCR_GET_RECOGNITION_ENDPOINT', 'https://ocr.api.cloud.yandex.net/ocr/v1/getRecognition'),
            'async_pdf_enabled' => (bool) env('YANDEX_OCR_ASYNC_PDF_ENABLED', false),
            'async_max_wait_seconds' => (int) env('YANDEX_OCR_ASYNC_MAX_WAIT_SECONDS', 540),
            'async_poll_interval_ms' => (int) env('YANDEX_OCR_ASYNC_POLL_INTERVAL_MS', 1000),
            'folder_id' => env('YANDEX_VISION_FOLDER_ID'),
            'api_key' => env('YANDEX_VISION_API_KEY'),
            'iam_token' => env('YANDEX_OCR_IAM_TOKEN'),
            'auth_mode' => env('YANDEX_OCR_AUTH_MODE', 'api_key'),
        ],
    ],
    'normative_matching' => [
        'intent_classifier' => [
            'enabled' => (bool) env('ESTIMATE_GENERATION_INTENT_CLASSIFIER_ENABLED', true),
            'default_scope' => 'general',
            'low_confidence_threshold' => 0.6,
        ],
        'reranker' => [
            'provider' => env('ESTIMATE_GENERATION_NORM_RERANKER', 'rule_based'),
            'llm_enabled' => (bool) env('ESTIMATE_GENERATION_NORM_RERANKER_LLM_ENABLED', false),
            'max_candidates' => (int) env('ESTIMATE_GENERATION_NORM_RERANKER_MAX_CANDIDATES', 8),
            'timeout_seconds' => (int) env('ESTIMATE_GENERATION_NORM_RERANKER_TIMEOUT', 15),
        ],
    ],
];
