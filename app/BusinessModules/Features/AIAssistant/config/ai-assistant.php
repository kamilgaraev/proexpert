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
        ],

        'openai' => [
            'api_key' => $configEnv('OPENAI_API_KEY'),
            'model' => $configEnv('OPENAI_MODEL', 'gpt-4o-mini'),
            'max_tokens' => $configEnv('OPENAI_MAX_TOKENS', 2000),
            'temperature' => $configEnv('OPENAI_TEMPERATURE', 0.7),
        ],

        'deepseek' => [
            'api_key' => $configEnv('DEEPSEEK_API_KEY'),
            'model' => $configEnv('DEEPSEEK_MODEL', 'deepseek-chat'),
            'max_tokens' => $configEnv('DEEPSEEK_MAX_TOKENS', 2000),
            'temperature' => $configEnv('DEEPSEEK_TEMPERATURE', 1),
        ],
    ],

    'openai_api_key' => $configEnv('OPENAI_API_KEY'),
    'openai_model' => $configEnv('OPENAI_MODEL', 'gpt-4o-mini'),
    'max_tokens' => $configEnv('OPENAI_MAX_TOKENS', 2000),

    'rag' => [
        'enabled' => true,
        'embedding_provider' => $configEnv('AI_RAG_EMBEDDING_PROVIDER', 'yandex'),
        'embedding_model' => $configEnv('AI_RAG_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'embedding_document_model_uri' => $configEnv('AI_RAG_EMBEDDING_DOCUMENT_MODEL_URI'),
        'embedding_query_model_uri' => $configEnv('AI_RAG_EMBEDDING_QUERY_MODEL_URI'),
        'embedding_endpoint' => $configEnv(
            'AI_RAG_EMBEDDING_ENDPOINT',
            'https://ai.api.cloud.yandex.net/foundationModels/v1/textEmbedding'
        ),
        'embedding_dimensions' => $configEnv('AI_RAG_EMBEDDING_DIMENSIONS', 256),
        'queue' => $configEnv('AI_RAG_QUEUE', 'ai-rag'),
        'scheduled_limit' => $configEnv('AI_RAG_SCHEDULED_LIMIT', 50),
        'stale_after_hours' => $configEnv('AI_RAG_STALE_AFTER_HOURS', 24),
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
