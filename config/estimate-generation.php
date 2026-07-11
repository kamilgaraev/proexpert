<?php

declare(strict_types=1);

$envValue = static function (string $key, mixed $default = null): mixed {
    $value = env($key);

    return $value !== null && $value !== '' ? $value : $default;
};

return [
    'vision' => [
        'provider' => env('ESTIMATE_GENERATION_VISION_PROVIDER', 'timeweb'),
        'model' => env('ESTIMATE_GENERATION_VISION_MODEL', 'gemini/gemini-3.1-flash'),
        'model_version' => env('ESTIMATE_GENERATION_VISION_MODEL_VERSION', '2026-07-11'),
        'api_key' => $envValue('ESTIMATE_GENERATION_VISION_API_KEY', $envValue('TIMEWEB_AI_API_KEY')),
        'base_uri' => $envValue('ESTIMATE_GENERATION_VISION_BASE_URI', $envValue('TIMEWEB_AI_BASE_URI', 'https://api.timeweb.ai/v1')),
        'timeout_seconds' => (int) env('ESTIMATE_GENERATION_VISION_TIMEOUT', 60),
        'retry_attempts' => (int) env('ESTIMATE_GENERATION_VISION_RETRY_ATTEMPTS', 3),
        'retry_delay_ms' => (int) env('ESTIMATE_GENERATION_VISION_RETRY_DELAY_MS', 250),
        'max_tokens' => (int) env('ESTIMATE_GENERATION_VISION_MAX_TOKENS', 4096),
        'max_response_bytes' => (int) env('ESTIMATE_GENERATION_VISION_MAX_RESPONSE_BYTES', 1_000_000),
        'max_elements' => (int) env('ESTIMATE_GENERATION_VISION_MAX_ELEMENTS', 500),
        'max_depth' => (int) env('ESTIMATE_GENERATION_VISION_MAX_DEPTH', 16),
        'image_detail' => env('ESTIMATE_GENERATION_VISION_IMAGE_DETAIL', 'high'),
        'preprocessing' => [
            'max_bytes' => (int) env('ESTIMATE_GENERATION_VISION_MAX_IMAGE_BYTES', 20_000_000),
            'max_pixels' => (int) env('ESTIMATE_GENERATION_VISION_MAX_IMAGE_PIXELS', 20_000_000),
            'max_dimension' => (int) env('ESTIMATE_GENERATION_VISION_MAX_DIMENSION', 4096),
            'version' => 'raster-preprocessor:v1',
        ],
        'pricing' => [
            'input_per_million' => $envValue('ESTIMATE_GENERATION_VISION_PRICE_INPUT_PER_MILLION'),
            'cached_input_per_million' => $envValue('ESTIMATE_GENERATION_VISION_PRICE_CACHED_INPUT_PER_MILLION'),
            'output_per_million' => $envValue('ESTIMATE_GENERATION_VISION_PRICE_OUTPUT_PER_MILLION'),
            'image_unit' => $envValue('ESTIMATE_GENERATION_VISION_PRICE_IMAGE_UNIT'),
            'reasoning_mode' => 'excluded_from_output',
            'currency' => $envValue('ESTIMATE_GENERATION_VISION_PRICE_CURRENCY'),
            'source' => 'contract',
            'version' => $envValue('ESTIMATE_GENERATION_VISION_PRICE_VERSION'),
            'effective_at' => $envValue('ESTIMATE_GENERATION_VISION_PRICE_EFFECTIVE_AT'),
        ],
    ],
    'ocr' => [
        'provider' => env('ESTIMATE_GENERATION_OCR_PROVIDER', 'timeweb'),
        'enabled' => (bool) env('ESTIMATE_GENERATION_OCR_ENABLED', true),
        'languages' => ['ru', 'en'],
        'model' => env('ESTIMATE_GENERATION_OCR_MODEL', 'gemini/gemini-3.1-flash-lite'),
        'timeout_seconds' => (int) env('ESTIMATE_GENERATION_OCR_TIMEOUT', 60),
        'retry_attempts' => (int) env('ESTIMATE_GENERATION_OCR_RETRY_ATTEMPTS', 3),
        'retry_delay_ms' => (int) env('ESTIMATE_GENERATION_OCR_RETRY_DELAY_MS', 250),
        'max_tokens' => (int) env('ESTIMATE_GENERATION_OCR_MAX_TOKENS', 4096),
        'max_sync_file_bytes' => 10 * 1024 * 1024,
        'max_pdf_file_bytes' => (int) env('ESTIMATE_GENERATION_OCR_MAX_PDF_FILE_BYTES', 200 * 1024 * 1024),
        'max_cad_file_bytes' => (int) env('ESTIMATE_GENERATION_OCR_MAX_CAD_FILE_BYTES', 200 * 1024 * 1024),
        'max_spreadsheet_file_bytes' => 50 * 1024 * 1024,
        'max_spreadsheet_rows' => 2_000,
        'max_spreadsheet_columns' => 80,
        'max_image_pixels' => 20_000_000,
        'max_pdf_pages' => 200,
        'max_document_jobs_per_minute' => (int) env('ESTIMATE_GENERATION_OCR_MAX_DOCUMENT_JOBS_PER_MINUTE', 6),
        'pdf_text_layer_min_chars' => (int) env('ESTIMATE_GENERATION_OCR_PDF_TEXT_LAYER_MIN_CHARS', 20),
        'pdf_parser_memory_limit' => env('ESTIMATE_GENERATION_OCR_PDF_PARSER_MEMORY_LIMIT', '512M'),
        'geometry' => [
            'enabled' => (bool) env('ESTIMATE_GENERATION_PDF_GEOMETRY_ENABLED', true),
            'python_binary' => env('ESTIMATE_GENERATION_PDF_GEOMETRY_PYTHON', 'python'),
            'script_path' => env(
                'ESTIMATE_GENERATION_PDF_GEOMETRY_SCRIPT',
                base_path('app/BusinessModules/Addons/EstimateGeneration/bin/pdf_geometry_extract.py')
            ),
            'timeout_seconds' => (int) env('ESTIMATE_GENERATION_PDF_GEOMETRY_TIMEOUT', 45),
            'max_pages' => (int) env('ESTIMATE_GENERATION_PDF_GEOMETRY_MAX_PAGES', 200),
            'max_vector_elements' => (int) env('ESTIMATE_GENERATION_PDF_GEOMETRY_MAX_VECTOR_ELEMENTS', 5000),
        ],
        'min_usable_quality_score' => 0.60,
        'min_good_quality_score' => 0.80,
        'queue' => env('REDIS_ESTIMATE_GENERATION_QUEUE', 'estimate-generation'),
        'timeweb' => [
            'api_key' => $envValue('ESTIMATE_GENERATION_OCR_API_KEY', $envValue('TIMEWEB_AI_API_KEY')),
            'base_uri' => $envValue('ESTIMATE_GENERATION_OCR_BASE_URI', $envValue('TIMEWEB_AI_BASE_URI', 'https://api.timeweb.ai/v1')),
            'models' => $envValue('ESTIMATE_GENERATION_OCR_MODELS', $envValue('ESTIMATE_GENERATION_OCR_MODEL', 'gemini/gemini-3.1-flash-lite').',gemini/gemini-3.1-flash,openai/gpt-5-mini'),
            'pdf_models' => $envValue('ESTIMATE_GENERATION_OCR_PDF_MODELS', 'openai/gpt-5-mini,openai/gpt-5-nano'),
            'image_detail' => $envValue('ESTIMATE_GENERATION_OCR_IMAGE_DETAIL', 'high'),
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
    'generation' => [
        'max_draft_jobs_per_minute' => (int) env('ESTIMATE_GENERATION_MAX_DRAFT_JOBS_PER_MINUTE', 3),
        'pipeline_lease_seconds' => (int) env('ESTIMATE_GENERATION_PIPELINE_LEASE_SECONDS', 2100),
    ],
    'training' => [
        'max_dataset_jobs_per_minute' => (int) env('ESTIMATE_GENERATION_TRAINING_MAX_DATASET_JOBS_PER_MINUTE', 2),
    ],
    'benchmark' => [
        'acceptance_manifest' => $envValue('ESTIMATE_GENERATION_ACCEPTANCE_BENCHMARK_MANIFEST'),
        'acceptance_organization_id' => (int) env('ESTIMATE_GENERATION_ACCEPTANCE_BENCHMARK_ORGANIZATION_ID', 0),
    ],
];
