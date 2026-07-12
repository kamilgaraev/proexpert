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
        'geometry_runtime' => [
            'memory_limit_kib' => (int) env('ESTIMATE_GENERATION_GEOMETRY_MEMORY_LIMIT_KIB', 524_288),
            'cpu_limit_seconds' => (int) env('ESTIMATE_GENERATION_GEOMETRY_CPU_LIMIT_SECONDS', 45),
            'file_size_limit_bytes' => (int) env('ESTIMATE_GENERATION_GEOMETRY_FILE_SIZE_LIMIT_BYTES', 16_777_216),
            'open_file_limit' => (int) env('ESTIMATE_GENERATION_GEOMETRY_OPEN_FILE_LIMIT', 64),
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
        'approved_dataset_version' => $envValue('ESTIMATE_GENERATION_NORM_APPROVED_DATASET_VERSION'),
        'retrieval' => [
            'max_candidates' => (int) env('ESTIMATE_GENERATION_NORM_RETRIEVAL_MAX_CANDIDATES', 16),
            'semantic_index_version' => $envValue('ESTIMATE_GENERATION_NORM_SEMANTIC_INDEX_VERSION'),
        ],
        'intent_classifier' => [
            'enabled' => (bool) env('ESTIMATE_GENERATION_INTENT_CLASSIFIER_ENABLED', true),
            'default_scope' => 'general',
            'low_confidence_threshold' => 0.6,
        ],
        'reranker' => [
            'models' => $envValue('ESTIMATE_GENERATION_NORM_RERANKER_MODELS', 'openai/gpt-5-mini,openai/gpt-5-nano'),
            'max_candidates' => (int) env('ESTIMATE_GENERATION_NORM_RERANKER_MAX_CANDIDATES', 8),
            'timeout_seconds' => (int) env('ESTIMATE_GENERATION_NORM_RERANKER_TIMEOUT', 15),
            'prompt_version' => 'normative-rerank-prompt-v1',
            'schema_version' => 'normative-rerank-v1',
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
        'production_replay_projections' => [
            'reg-replay-vector-wall-opening-001' => [
                'reference' => 'projections/vector-wall-opening-v1.json',
                'sha256' => '43d1faaa47e6d3caa564d5eb61153e76db580a88fd2f1954c0e701566ce83b0f',
            ],
            'reg-replay-vision-sketch-001' => [
                'reference' => 'projections/vision-sketch-v1.json',
                'sha256' => '9dcc2d4ced5ea4b04b1323421255cabf5728273649336cfbc3aabc2cf04e4167',
            ],
            'reg-replay-vector-pdf-001' => ['reference' => 'projections/vector-pdf-001.json', 'sha256' => '707e6fe43e591ed7c59e67cc172d3bcdc0d2f0f6fab15287874779072a0ca36d'],
            'reg-replay-scanned-pdf-001' => ['reference' => 'projections/scanned-pdf-001.json', 'sha256' => '40f3d99325f7319f7ae8bae3ac460f95d4f1cd15eef98a1fc3f039319a2e373b'],
            'reg-replay-dwg-layout-001' => ['reference' => 'projections/dwg-layout-001.json', 'sha256' => '4aa3d89c19ef43366c90343c413bdb1a0eb52cd413c0cba63c8d84df4849404b'],
            'reg-replay-dimensioned-raster-001' => ['reference' => 'projections/dimensioned-raster-001.json', 'sha256' => '7d1fe52d6f07a32b0a7350577ec755fbca043dc2f563915b9732424a69b6d676'],
            'reg-replay-freehand-review-001' => ['reference' => 'projections/freehand-review-001.json', 'sha256' => 'b46151eab9a0b23c97a9474c5aa565d351debb310d338f36e860ae33b13a495e'],
            'reg-replay-engineering-layout-001' => ['reference' => 'projections/engineering-layout-001.json', 'sha256' => '2710e68bbba38ba97d39757de88827c7cc746d3df358e2a78f900258bad1a4ef'],
        ],
        'registered_manifests' => [
            'repository-production-replay:v1' => [
                'locator' => 'production-replay-manifest.json',
                'sha256' => 'd93aa33eac98b8b7931ed09bdbf0ad97d9d5540b636338d91c4fa88ff53bc6ff',
            ],
        ],
    ],
];
