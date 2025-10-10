<?php

return [
    'enabled' => env('AI_ASSISTANT_ENABLED', true),
    
    'default_limit' => env('AI_ASSISTANT_DEFAULT_LIMIT', 5000),
    
    'cache_ttl' => env('AI_ASSISTANT_CACHE_TTL', 3600),
    
    'conversation_history_days' => 90,

    // LLM Provider Configuration
    'llm' => [
        // Выбор провайдера: 'yandex' или 'openai'
        'provider' => env('LLM_PROVIDER', 'yandex'),
        
        // YandexGPT Configuration
        'yandex' => [
            'api_key' => env('YANDEX_API_KEY'),
            'folder_id' => env('YANDEX_FOLDER_ID', 'b1gbp06r4m40cduru9dg'),
            'model_uri' => env('YANDEX_MODEL_URI', 'gpt://b1gbp06r4m40cduru9dg/yandexgpt/latest'),
            'max_tokens' => env('YANDEX_MAX_TOKENS', 2000),
            'temperature' => env('YANDEX_TEMPERATURE', 0.7),
        ],
        
        // OpenAI Configuration (для переключения при необходимости)
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'max_tokens' => env('OPENAI_MAX_TOKENS', 2000),
            'temperature' => env('OPENAI_TEMPERATURE', 0.7),
        ],
    ],
    
    // Backward compatibility
    'openai_api_key' => env('OPENAI_API_KEY'),
    'openai_model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    'max_tokens' => env('OPENAI_MAX_TOKENS', 2000),
];

