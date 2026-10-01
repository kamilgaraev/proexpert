<?php

declare(strict_types=1);

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

$lunaModelsEnv = static function (string $key, string $default = 'openai/gpt-6-luna'): array {
    return [$default];
};
return [
    'enabled' => $configEnv('AI_ASSISTANT_ENABLED', true),

    'default_limit' => $configEnv('AI_ASSISTANT_DEFAULT_LIMIT', 5000),

    'cache_ttl' => $configEnv('AI_ASSISTANT_CACHE_TTL', 3600),

    'conversation_history_days' => 90,

    'retention' => [
        'enabled' => $configEnv('AI_ASSISTANT_RETENTION_ENABLED', false),
        'days' => 90,
    ],

    'llm' => [
        'provider' => $configEnv('LLM_PROVIDER', 'timeweb'),

        'openai' => [
            'api_key' => $configEnv('OPENAI_API_KEY'),
            'base_uri' => $configEnv('OPENAI_BASE_URI'),
            'model' => $lunaModelsEnv('OPENAI_MODEL', 'gpt-6-luna')[0],
            'max_tokens' => $configEnv('OPENAI_MAX_TOKENS', 2000),
            'temperature' => $configEnv('OPENAI_TEMPERATURE', 0.7),
            'timeout' => $configEnv('OPENAI_TIMEOUT', 45),
        ],

        'deepseek' => [
            'api_key' => $configEnv('DEEPSEEK_API_KEY'),
            'model' => 'gpt-6-luna',
            'max_tokens' => $configEnv('DEEPSEEK_MAX_TOKENS', 2000),
            'temperature' => $configEnv('DEEPSEEK_TEMPERATURE', 1),
            'timeout' => $configEnv('DEEPSEEK_TIMEOUT', 45),
        ],

        'timeweb' => [
            'api_key' => $configEnv('TIMEWEB_AI_API_KEY', $configEnv('TIMEWEB_API_KEY', $configEnv('TIMEWEB_AI_PROXY_KEY'))),
            'base_uri' => $configEnv('TIMEWEB_AI_BASE_URI', 'https://api.timeweb.ai/v1'),
            'model' => $lunaModelsEnv('TIMEWEB_AI_MODEL')[0],
            'max_tokens' => $configEnv('TIMEWEB_AI_MAX_TOKENS', 2000),
            'temperature' => $configEnv('TIMEWEB_AI_TEMPERATURE', 0.7),
            'timeout' => $configEnv('TIMEWEB_AI_TIMEOUT', 25),
            'input_price_per_million' => $configEnv('TIMEWEB_AI_INPUT_PRICE_PER_MILLION', 13.5),
            'output_price_per_million' => $configEnv('TIMEWEB_AI_OUTPUT_PRICE_PER_MILLION', 67.5),
            'default_profile' => $configEnv('TIMEWEB_AI_DEFAULT_PROFILE', 'assistant'),
            'profiles' => [
                'assistant' => [
                    'models' => $lunaModelsEnv('TIMEWEB_AI_ASSISTANT_MODELS'),
                    'timeout' => $configEnv('TIMEWEB_AI_ASSISTANT_TIMEOUT', $configEnv('TIMEWEB_AI_TIMEOUT', 25)),
                    'max_tokens' => $configEnv('TIMEWEB_AI_ASSISTANT_MAX_TOKENS', 2048),
                    'temperature' => $configEnv('TIMEWEB_AI_ASSISTANT_TEMPERATURE', $configEnv('TIMEWEB_AI_TEMPERATURE', 0.7)),
                ],
                'json' => [
                    'models' => $lunaModelsEnv('TIMEWEB_AI_JSON_MODELS'),
                    'timeout' => $configEnv('TIMEWEB_AI_JSON_TIMEOUT', 20),
                    'max_tokens' => $configEnv('TIMEWEB_AI_JSON_MAX_TOKENS', 2048),
                    'temperature' => $configEnv('TIMEWEB_AI_JSON_TEMPERATURE', 0.1),
                ],
                'fast' => [
                    'models' => $lunaModelsEnv('TIMEWEB_AI_FAST_MODELS'),
                    'timeout' => $configEnv('TIMEWEB_AI_FAST_TIMEOUT', 12),
                    'max_tokens' => $configEnv('TIMEWEB_AI_FAST_MAX_TOKENS', 1024),
                    'temperature' => $configEnv('TIMEWEB_AI_FAST_TEMPERATURE', 0.2),
                ],
                'premium' => [
                    'models' => $lunaModelsEnv('TIMEWEB_AI_PREMIUM_MODELS'),
                    'timeout' => $configEnv('TIMEWEB_AI_PREMIUM_TIMEOUT', 35),
                    'max_tokens' => $configEnv('TIMEWEB_AI_PREMIUM_MAX_TOKENS', 4096),
                    'temperature' => $configEnv('TIMEWEB_AI_PREMIUM_TEMPERATURE', $configEnv('TIMEWEB_AI_TEMPERATURE', 0.7)),
                ],
            ],
        ],
    ],

    'openai_api_key' => $configEnv('OPENAI_API_KEY'),
    'openai_model' => $lunaModelsEnv('OPENAI_MODEL', 'gpt-6-luna')[0],
    'max_tokens' => $configEnv('OPENAI_MAX_TOKENS', 2000),

    'token_budgets' => [
        'short' => ['input' => 8192, 'output' => 1024, 'calls' => 2],
        'normal' => ['input' => 16384, 'output' => 2048, 'calls' => 4],
        'detailed' => ['input' => 32768, 'output' => 4096, 'calls' => 6],
    ],

    'request_deadline_seconds' => [
        'short' => min(60, max(15, (int) $configEnv('AI_ASSISTANT_SHORT_DEADLINE_SECONDS', 30))),
        'normal' => min(120, max(30, (int) $configEnv('AI_ASSISTANT_NORMAL_DEADLINE_SECONDS', 60))),
        'detailed' => min(300, max(60, (int) $configEnv('AI_ASSISTANT_DETAILED_DEADLINE_SECONDS', 180))),
    ],

    'rag' => [
        'enabled' => true,
        'embedding_provider' => $configEnv('AI_RAG_EMBEDDING_PROVIDER', 'timeweb'),
        'embedding_api_key' => $configEnv('AI_RAG_EMBEDDING_API_KEY'),
        'embedding_base_uri' => $configEnv('AI_RAG_EMBEDDING_BASE_URI'),
        'embedding_model' => $configEnv('AI_RAG_EMBEDDING_MODEL', 'openai/text-embedding-3-large'),
        'embedding_dimensions' => $configEnv('AI_RAG_EMBEDDING_DIMENSIONS', 256),
        'embedding_input_price_per_million' => $configEnv('AI_RAG_EMBEDDING_INPUT_PRICE_PER_MILLION', 45.0),
        'new_index_embedding_provider' => $configEnv('AI_RAG_NEW_INDEX_EMBEDDING_PROVIDER', 'timeweb'),
        'new_index_embedding_model' => $configEnv('AI_RAG_NEW_INDEX_EMBEDDING_MODEL', 'dashscope/text-embedding-v4'),
        'new_index_embedding_dimensions' => 256,
        'new_index_embedding_input_price_per_million' => $configEnv('AI_RAG_NEW_INDEX_EMBEDDING_INPUT_PRICE_PER_MILLION', 9.0),
        'queue_connection' => $configEnv('AI_RAG_QUEUE_CONNECTION', 'redis_ai_rag'),
        'queue' => $configEnv('AI_RAG_QUEUE', 'ai-rag'),
        'live_queue' => $configEnv('AI_RAG_LIVE_QUEUE', 'ai-rag-live'),
        'job_tries' => $configEnv('AI_RAG_JOB_TRIES', 3),
        'job_timeout' => max(7200, (int) $configEnv('AI_RAG_JOB_TIMEOUT', 7200)),
        'scheduled_limit' => $configEnv('AI_RAG_SCHEDULED_LIMIT', 2),
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
