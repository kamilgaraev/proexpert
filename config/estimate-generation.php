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
        'cad_runtime' => [
            'python_binary' => env('ESTIMATE_GENERATION_CAD_PYTHON', PHP_OS_FAMILY === 'Windows' ? 'python' : '/opt/geometry-venv/bin/python'),
            'script_path' => env('ESTIMATE_GENERATION_CAD_SCRIPT', base_path('app/BusinessModules/Addons/EstimateGeneration/bin/cad_geometry_extract.py')),
            'script_sha256' => env('ESTIMATE_GENERATION_CAD_SCRIPT_SHA256', ''),
            'dwgread_binary' => env('ESTIMATE_GENERATION_CAD_DWGREAD', PHP_OS_FAMILY === 'Windows' ? 'dwgread.exe' : '/opt/libredwg/bin/dwgread'),
            'libredwg_version' => '0.13.4',
            'sandbox_binary' => env('ESTIMATE_GENERATION_CAD_SANDBOX', PHP_OS_FAMILY === 'Linux' ? '/usr/local/bin/geometry-sandbox' : ''),
            'requirements_lock_path' => env('ESTIMATE_GENERATION_CAD_REQUIREMENTS_LOCK', base_path('docker/geometry/requirements.lock')),
            'requirements_sha256' => env('ESTIMATE_GENERATION_CAD_REQUIREMENTS_SHA256', ''),
            'timeout_seconds' => (int) env('ESTIMATE_GENERATION_CAD_TIMEOUT', 45),
            'max_input_bytes' => (int) env('ESTIMATE_GENERATION_CAD_MAX_INPUT_BYTES', 52_428_800),
            'max_output_bytes' => (int) env('ESTIMATE_GENERATION_CAD_MAX_OUTPUT_BYTES', 16_777_216),
            'max_entities' => (int) env('ESTIMATE_GENERATION_CAD_MAX_ENTITIES', 250_000),
            'memory_limit_kib' => (int) env('ESTIMATE_GENERATION_CAD_MEMORY_LIMIT_KIB', 524_288),
            'cpu_limit_seconds' => (int) env('ESTIMATE_GENERATION_CAD_CPU_LIMIT_SECONDS', 45),
            'file_size_limit_bytes' => (int) env('ESTIMATE_GENERATION_CAD_FILE_SIZE_LIMIT_BYTES', 16_777_216),
            'open_file_limit' => (int) env('ESTIMATE_GENERATION_CAD_OPEN_FILE_LIMIT', 64),
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
            'image_detail' => $envValue('ESTIMATE_GENERATION_OCR_IMAGE_DETAIL', 'high'),
        ],
    ],
    'normative_matching' => [
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
    'ai_pricing_catalog' => [
        'vision' => ['timeweb' => [(string) env('ESTIMATE_GENERATION_VISION_MODEL', 'gemini/gemini-3.1-flash') => [[
            'input_per_million' => (string) $envValue('ESTIMATE_GENERATION_VISION_PRICE_INPUT_PER_MILLION', ''),
            'cached_input_per_million' => (string) $envValue('ESTIMATE_GENERATION_VISION_PRICE_CACHED_INPUT_PER_MILLION', ''),
            'output_per_million' => (string) $envValue('ESTIMATE_GENERATION_VISION_PRICE_OUTPUT_PER_MILLION', ''),
            'image_unit' => (string) $envValue('ESTIMATE_GENERATION_VISION_PRICE_IMAGE_UNIT', ''),
            'currency' => (string) $envValue('ESTIMATE_GENERATION_VISION_PRICE_CURRENCY', ''),
            'version' => (string) $envValue('ESTIMATE_GENERATION_VISION_PRICE_VERSION', ''),
            'effective_at' => (string) $envValue('ESTIMATE_GENERATION_VISION_PRICE_EFFECTIVE_AT', ''),
        ]]]],
        'ocr' => ['timeweb' => [(string) env('ESTIMATE_GENERATION_OCR_MODEL', 'gemini/gemini-3.1-flash-lite') => [[
            'input_per_million' => (string) $envValue('ESTIMATE_GENERATION_OCR_PRICE_INPUT_PER_MILLION', ''),
            'cached_input_per_million' => (string) $envValue('ESTIMATE_GENERATION_OCR_PRICE_CACHED_INPUT_PER_MILLION', ''),
            'output_per_million' => (string) $envValue('ESTIMATE_GENERATION_OCR_PRICE_OUTPUT_PER_MILLION', ''),
            'page_unit' => (string) $envValue('ESTIMATE_GENERATION_OCR_PRICE_PAGE_UNIT', ''),
            'image_unit' => (string) $envValue('ESTIMATE_GENERATION_OCR_PRICE_IMAGE_UNIT', ''),
            'currency' => (string) $envValue('ESTIMATE_GENERATION_OCR_PRICE_CURRENCY', ''),
            'version' => (string) $envValue('ESTIMATE_GENERATION_OCR_PRICE_VERSION', ''),
            'effective_at' => (string) $envValue('ESTIMATE_GENERATION_OCR_PRICE_EFFECTIVE_AT', ''),
        ]]]],
        'rerank' => ['timeweb' => [(string) $envValue('ESTIMATE_GENERATION_NORM_RERANKER_PRIMARY_MODEL', 'openai/gpt-5-mini') => [[
            'input_per_million' => (string) $envValue('ESTIMATE_GENERATION_RERANK_PRICE_INPUT_PER_MILLION', ''),
            'cached_input_per_million' => (string) $envValue('ESTIMATE_GENERATION_RERANK_PRICE_CACHED_INPUT_PER_MILLION', ''),
            'output_per_million' => (string) $envValue('ESTIMATE_GENERATION_RERANK_PRICE_OUTPUT_PER_MILLION', ''),
            'currency' => (string) $envValue('ESTIMATE_GENERATION_RERANK_PRICE_CURRENCY', ''),
            'version' => (string) $envValue('ESTIMATE_GENERATION_RERANK_PRICE_VERSION', ''),
            'effective_at' => (string) $envValue('ESTIMATE_GENERATION_RERANK_PRICE_EFFECTIVE_AT', ''),
        ]]]],
    ],
    'generation' => [
        'max_draft_jobs_per_minute' => (int) env('ESTIMATE_GENERATION_MAX_DRAFT_JOBS_PER_MINUTE', 3),
        'pipeline_lease_seconds' => (int) env('ESTIMATE_GENERATION_PIPELINE_LEASE_SECONDS', 2100),
    ],
    'ai_budget' => [
        'reconciliation_batch' => (int) env('ESTIMATE_GENERATION_AI_BUDGET_RECONCILIATION_BATCH', 100),
    ],
    'training' => [
        'max_dataset_jobs_per_minute' => (int) env('ESTIMATE_GENERATION_TRAINING_MAX_DATASET_JOBS_PER_MINUTE', 2),
    ],
    'benchmark' => [
        'production_pipeline_version' => $envValue('ESTIMATE_GENERATION_PRODUCTION_PIPELINE_VERSION'),
        'admin_case_timeout_ms' => (int) env('ESTIMATE_GENERATION_ADMIN_BENCHMARK_CASE_TIMEOUT_MS', 300000),
        'repository_replay_enabled' => (bool) env('ESTIMATE_GENERATION_REPOSITORY_REPLAY_ENABLED', true),
        'production_output_store' => env('ESTIMATE_GENERATION_BENCHMARK_PRODUCTION_OUTPUT_STORE', 's3'),
        'acceptance_manifest' => $envValue('ESTIMATE_GENERATION_ACCEPTANCE_BENCHMARK_MANIFEST'),
        'acceptance_organization_id' => (int) env('ESTIMATE_GENERATION_ACCEPTANCE_BENCHMARK_ORGANIZATION_ID', 0),
        'production_replay_projections' => [
            'reg-replay-vector-wall-opening-001' => [
                'reference' => 'projections/vector-wall-opening-v1.json',
                'sha256' => '1debc0e4da66695d32f9c6f1335d885b000c42b323226ea3d125ac16734d1d2a',
            ],
            'reg-replay-vision-sketch-001' => [
                'reference' => 'projections/vision-sketch-v1.json',
                'sha256' => '65e41198fa8118378e4001c812b6c9bf365c592434d2036ab77e6c09c4b86e3c',
            ],
            'reg-replay-vector-pdf-001' => ['reference' => 'projections/vector-pdf-001.json', 'sha256' => '1844b0e9aa0a54d54fe5b3500510e34cac47417d7b3a44c7f146019ed22b4a43'],
            'reg-replay-scanned-pdf-001' => ['reference' => 'projections/scanned-pdf-001.json', 'sha256' => 'bc43f37e2b37a2c7663546cd69ca63f97a62f74b288f302e590636a310e814b0'],
            'reg-replay-dwg-layout-001' => ['reference' => 'projections/dwg-layout-001.json', 'sha256' => 'd04132f233a8ceb67fcc6669a4e885d0c2e465b8eb4b056f6b7669d180a51f02'],
            'reg-replay-dimensioned-raster-001' => ['reference' => 'projections/dimensioned-raster-001.json', 'sha256' => 'fe76cb4a6be5af2a1d716bf68b13a8afa5814f6c56eee092c229da7e4d34beac'],
            'reg-replay-freehand-review-001' => ['reference' => 'projections/freehand-review-001.json', 'sha256' => '0292bf79d7ebb71bd115967577cb12ce31754ceaf41818e0348ce8d999f6fee4'],
            'reg-replay-engineering-layout-001' => ['reference' => 'projections/engineering-layout-001.json', 'sha256' => 'dd94e170fd47dd0ec118c4bf253ff7e62037555e4215b7ab7a379a4a289452e4'],
        ],
        'registered_manifests' => [
            'repository-production-replay:v1' => [
                'locator' => 'production-replay-manifest.json',
                'sha256' => '83b522e6206f6771254ccd759b4853ac6272dd411d9caa73cb3b3d8ecd32b07f',
            ],
        ],
    ],
];
