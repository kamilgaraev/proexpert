<?php

return [
    'enabled' => env('AI_ASSISTANT_ENABLED', true),

    'default_limit' => env('AI_ASSISTANT_DEFAULT_LIMIT', 5000),

    'cache_ttl' => env('AI_ASSISTANT_CACHE_TTL', 3600),

    'conversation_history_days' => 90,

    'llm' => [
        'provider' => env('LLM_PROVIDER', 'yandex'),

        'yandex' => [
            'api_key' => env('YANDEX_API_KEY'),
            'folder_id' => env('YANDEX_FOLDER_ID', 'b1gbp06r4m40cduru9dg'),
            'model_uri' => env('YANDEX_MODEL_URI', 'gpt://b1gbp06r4m40cduru9dg/aliceai-llm/latest'),
            'max_tokens' => env('YANDEX_MAX_TOKENS', 2000),
            'temperature' => env('YANDEX_TEMPERATURE', 0.7),
            'use_async' => env('YANDEX_USE_ASYNC', false),
        ],

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'max_tokens' => env('OPENAI_MAX_TOKENS', 2000),
            'temperature' => env('OPENAI_TEMPERATURE', 0.7),
        ],

        'deepseek' => [
            'api_key' => env('DEEPSEEK_API_KEY'),
            'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
            'max_tokens' => env('DEEPSEEK_MAX_TOKENS', 2000),
            'temperature' => env('DEEPSEEK_TEMPERATURE', 1),
        ],
    ],

    'openai_api_key' => env('OPENAI_API_KEY'),
    'openai_model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    'max_tokens' => env('OPENAI_MAX_TOKENS', 2000),

    'project_pulse' => [
        'enabled' => env('PROJECT_PULSE_ENABLED', true),
        'ai_enabled' => env('PROJECT_PULSE_AI_ENABLED', true),
        'cache_ttl' => env('PROJECT_PULSE_CACHE_TTL', 3600),
        'auto_cleanup_days' => 90,
        'periods' => ['today', 'yesterday', 'week'],
    ],
];
