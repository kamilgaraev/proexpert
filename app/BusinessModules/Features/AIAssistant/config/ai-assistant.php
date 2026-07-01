<?php

$configEnv = static function (string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    if (is_bool($default)) {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    if (is_int($default)) {
        return (int) $value;
    }

    if (is_float($default)) {
        return (float) $value;
    }

    return $value;
};

$csvEnv = static function (string $key, string $default = '') use ($configEnv): array {
    $value = (string) $configEnv($key, $default);

    return array_values(array_filter(array_map(
        static fn (string $item): string => trim($item),
        explode(',', $value)
    )));
};

return [
    'enabled' => $configEnv('AI_ASSISTANT_ENABLED', true),

    'default_limit' => $configEnv('AI_ASSISTANT_DEFAULT_LIMIT', 5000),

    'cache_ttl' => $configEnv('AI_ASSISTANT_CACHE_TTL', 3600),

    'conversation_history_days' => 90,

    'llm' => [
        'provider' => $configEnv('LLM_PROVIDER', 'yandex'),

        'yandex' => [
            'api_key' => $configEnv('YANDEX_API_KEY'),
            'folder_id' => $configEnv('YANDEX_FOLDER_ID', 'b1gbp06r4m40cduru9dg'),
            'model_uri' => $configEnv('YANDEX_MODEL_URI', 'gpt://b1gbp06r4m40cduru9dg/aliceai-llm/latest'),
            'max_tokens' => $configEnv('YANDEX_MAX_TOKENS', 2000),
            'temperature' => $configEnv('YANDEX_TEMPERATURE', 0.7),
            'use_async' => $configEnv('YANDEX_USE_ASYNC', false),
            'timeout' => $configEnv('YANDEX_TIMEOUT', 60),
        ],

        'openai' => [
            'api_key' => $configEnv('OPENAI_API_KEY'),
            'base_uri' => $configEnv('OPENAI_BASE_URI'),
            'model' => $configEnv('OPENAI_MODEL', 'gpt-4o-mini'),
            'max_tokens' => $configEnv('OPENAI_MAX_TOKENS', 2000),
            'temperature' => $configEnv('OPENAI_TEMPERATURE', 0.7),
            'timeout' => $configEnv('OPENAI_TIMEOUT', 45),
        ],

        'deepseek' => [
            'api_key' => $configEnv('DEEPSEEK_API_KEY'),
            'model' => $configEnv('DEEPSEEK_MODEL', 'deepseek-chat'),
            'max_tokens' => $configEnv('DEEPSEEK_MAX_TOKENS', 2000),
            'temperature' => $configEnv('DEEPSEEK_TEMPERATURE', 1),
            'timeout' => $configEnv('DEEPSEEK_TIMEOUT', 45),
        ],

        'timeweb' => [
            'api_key' => $configEnv('TIMEWEB_AI_API_KEY', $configEnv('TIMEWEB_API_KEY', $configEnv('TIMEWEB_AI_PROXY_KEY'))),
            'base_uri' => $configEnv('TIMEWEB_AI_BASE_URI', 'https://api.timeweb.ai/v1'),
            'model' => $configEnv('TIMEWEB_AI_MODEL', 'gemini/gemini-3.1-flash-lite'),
            'max_tokens' => $configEnv('TIMEWEB_AI_MAX_TOKENS', 2000),
            'temperature' => $configEnv('TIMEWEB_AI_TEMPERATURE', 0.7),
            'timeout' => $configEnv('TIMEWEB_AI_TIMEOUT', 25),
            'input_price_per_million' => $configEnv('TIMEWEB_AI_INPUT_PRICE_PER_MILLION'),
            'output_price_per_million' => $configEnv('TIMEWEB_AI_OUTPUT_PRICE_PER_MILLION'),
            'default_profile' => $configEnv('TIMEWEB_AI_DEFAULT_PROFILE', 'assistant'),
            'profiles' => [
                'assistant' => [
                    'models' => $csvEnv(
                        'TIMEWEB_AI_ASSISTANT_MODELS',
                        (string) $configEnv(
                            'TIMEWEB_AI_MODEL',
                            'gemini/gemini-3.1-flash-lite,gemini/gemini-2.5-flash'
                        )
                    ),
                    'timeout' => $configEnv('TIMEWEB_AI_ASSISTANT_TIMEOUT', $configEnv('TIMEWEB_AI_TIMEOUT', 25)),
                    'max_tokens' => $configEnv('TIMEWEB_AI_ASSISTANT_MAX_TOKENS', $configEnv('TIMEWEB_AI_MAX_TOKENS', 2000)),
                    'temperature' => $configEnv('TIMEWEB_AI_ASSISTANT_TEMPERATURE', $configEnv('TIMEWEB_AI_TEMPERATURE', 0.7)),
                ],
                'json' => [
                    'models' => $csvEnv(
                        'TIMEWEB_AI_JSON_MODELS',
                        'gemini/gemini-3.1-flash-lite,gemini/gemini-2.5-flash-lite'
                    ),
                    'timeout' => $configEnv('TIMEWEB_AI_JSON_TIMEOUT', 20),
                    'max_tokens' => $configEnv('TIMEWEB_AI_JSON_MAX_TOKENS', $configEnv('TIMEWEB_AI_MAX_TOKENS', 2000)),
                    'temperature' => $configEnv('TIMEWEB_AI_JSON_TEMPERATURE', 0.1),
                ],
                'fast' => [
                    'models' => $csvEnv(
                        'TIMEWEB_AI_FAST_MODELS',
                        'gemini/gemini-2.5-flash-lite,gemini/gemini-3.1-flash-lite'
                    ),
                    'timeout' => $configEnv('TIMEWEB_AI_FAST_TIMEOUT', 12),
                    'max_tokens' => $configEnv('TIMEWEB_AI_FAST_MAX_TOKENS', 800),
                    'temperature' => $configEnv('TIMEWEB_AI_FAST_TEMPERATURE', 0.2),
                ],
                'premium' => [
                    'models' => $csvEnv(
                        'TIMEWEB_AI_PREMIUM_MODELS',
                        'anthropic/claude-4.6-sonnet,gemini/gemini-3.1-pro-preview'
                    ),
                    'timeout' => $configEnv('TIMEWEB_AI_PREMIUM_TIMEOUT', 35),
                    'max_tokens' => $configEnv('TIMEWEB_AI_PREMIUM_MAX_TOKENS', $configEnv('TIMEWEB_AI_MAX_TOKENS', 2000)),
                    'temperature' => $configEnv('TIMEWEB_AI_PREMIUM_TEMPERATURE', $configEnv('TIMEWEB_AI_TEMPERATURE', 0.7)),
                ],
            ],
        ],
    ],

    'openai_api_key' => $configEnv('OPENAI_API_KEY'),
    'openai_model' => $configEnv('OPENAI_MODEL', 'gpt-4o-mini'),
    'max_tokens' => $configEnv('OPENAI_MAX_TOKENS', 2000),

    'rag' => [
        'enabled' => true,
        'embedding_provider' => $configEnv('AI_RAG_EMBEDDING_PROVIDER', 'yandex'),
        'embedding_api_key' => $configEnv('AI_RAG_EMBEDDING_API_KEY'),
        'embedding_base_uri' => $configEnv('AI_RAG_EMBEDDING_BASE_URI'),
        'embedding_model' => $configEnv('AI_RAG_EMBEDDING_MODEL', 'openai/text-embedding-3-large'),
        'embedding_document_model_uri' => $configEnv('AI_RAG_EMBEDDING_DOCUMENT_MODEL_URI'),
        'embedding_query_model_uri' => $configEnv('AI_RAG_EMBEDDING_QUERY_MODEL_URI'),
        'embedding_endpoint' => $configEnv(
            'AI_RAG_EMBEDDING_ENDPOINT',
            'https://ai.api.cloud.yandex.net/foundationModels/v1/textEmbedding'
        ),
        'embedding_dimensions' => $configEnv('AI_RAG_EMBEDDING_DIMENSIONS', 256),
        'queue_connection' => $configEnv('AI_RAG_QUEUE_CONNECTION', 'redis_ai_rag'),
        'queue' => $configEnv('AI_RAG_QUEUE', 'ai-rag'),
        'job_tries' => $configEnv('AI_RAG_JOB_TRIES', 3),
        'job_timeout' => max(7200, (int) $configEnv('AI_RAG_JOB_TIMEOUT', 7200)),
        'scheduled_limit' => $configEnv('AI_RAG_SCHEDULED_LIMIT', 50),
        'scheduled_project_scoped_source_types' => array_values(array_filter(array_map(
            static fn (string $sourceType): string => trim($sourceType),
            explode(',', (string) $configEnv('AI_RAG_SCHEDULED_PROJECT_SCOPED_SOURCE_TYPES', 'estimate'))
        ))),
        'stale_after_hours' => $configEnv('AI_RAG_STALE_AFTER_HOURS', 24),
        'failed_retry_after_hours' => $configEnv('AI_RAG_FAILED_RETRY_AFTER_HOURS', 12),
        'max_chunks' => $configEnv('AI_RAG_MAX_CHUNKS', 8),
        'min_similarity' => $configEnv('AI_RAG_MIN_SIMILARITY', 0.72),
        'chunk_chars' => $configEnv('AI_RAG_CHUNK_CHARS', 1200),
    ],

    'project_pulse' => [
        'enabled' => $configEnv('PROJECT_PULSE_ENABLED', true),
        'ai_enabled' => $configEnv('PROJECT_PULSE_AI_ENABLED', true),
        'cache_ttl' => $configEnv('PROJECT_PULSE_CACHE_TTL', 3600),
        'auto_cleanup_days' => 90,
        'periods' => ['today', 'yesterday', 'week'],
        'categories' => [
            'project' => 'Проекты',
            'request' => 'Заявки',
            'procurement' => 'Закупки',
            'warehouse' => 'Склад',
            'finance' => 'Финансы',
            'contract' => 'Договоры',
            'schedule' => 'График',
            'quality' => 'Качество',
            'documentation' => 'Исполнительная документация',
            'safety' => 'HSE',
            'machinery' => 'Техника',
            'labor' => 'Выработка',
            'change' => 'Изменения',
            'handover' => 'Сдача',
            'report' => 'Отчеты',
            'work' => 'Работы',
            'people' => 'Исполнители',
            'system' => 'Система',
        ],
        'limits' => [
            'facts_per_source' => 30,
            'facts_total' => 250,
            'recommendations' => 12,
            'next_actions' => 10,
        ],
        'thresholds' => [
            'high_daily_expense' => 100000,
            'overload_warning_facts' => 8,
        ],
    ],
];
