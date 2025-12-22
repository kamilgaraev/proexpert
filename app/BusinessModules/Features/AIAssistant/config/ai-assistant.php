<?php

return [
    'enabled' => env('AI_ASSISTANT_ENABLED', true),
    
    'default_limit' => env('AI_ASSISTANT_DEFAULT_LIMIT', 5000),
    
    'cache_ttl' => env('AI_ASSISTANT_CACHE_TTL', 3600),
    
    'conversation_history_days' => 90,

    // LLM Provider Configuration
    'llm' => [
        // Выбор провайдера: 'yandex', 'openai' или 'deepseek'
        'provider' => env('LLM_PROVIDER', 'yandex'),
        
        // YandexGPT Configuration
        'yandex' => [
            'api_key' => env('YANDEX_API_KEY'),
            'folder_id' => env('YANDEX_FOLDER_ID', 'b1gbp06r4m40cduru9dg'),
            'model_uri' => env('YANDEX_MODEL_URI', 'gpt://b1gbp06r4m40cduru9dg/aliceai-llm/latest'),
            'max_tokens' => env('YANDEX_MAX_TOKENS', 2000),
            'temperature' => env('YANDEX_TEMPERATURE', 0.7),
            // Использовать асинхронный режим для Alice AI (дешевле, но медленнее)
            'use_async' => env('YANDEX_USE_ASYNC', false),
        ],
        
        // OpenAI Configuration (для переключения при необходимости)
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'max_tokens' => env('OPENAI_MAX_TOKENS', 2000),
            'temperature' => env('OPENAI_TEMPERATURE', 0.7),
        ],
        
        // DeepSeek Configuration (рекомендуется - дешевле и качественно)
        'deepseek' => [
            'api_key' => env('DEEPSEEK_API_KEY'),
            'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
            'max_tokens' => env('DEEPSEEK_MAX_TOKENS', 2000),
            'temperature' => env('DEEPSEEK_TEMPERATURE', 1),
        ],
    ],
    
    // Backward compatibility
    'openai_api_key' => env('OPENAI_API_KEY'),
    'openai_model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    'max_tokens' => env('OPENAI_MAX_TOKENS', 2000),
    
    // System Analysis Configuration
    'system_analysis' => [
        'enabled' => env('SYSTEM_ANALYSIS_ENABLED', true),
        'cache_ttl' => env('SYSTEM_ANALYSIS_CACHE_TTL', 3600), // 1 час
        'rate_limit_per_project' => env('SYSTEM_ANALYSIS_RATE_LIMIT', 1), // 1 анализ в минуту на проект
        
        // Какие разделы анализировать по умолчанию
        'sections' => [
            'budget' => true,
            'schedule' => true,
            'materials' => true,
            'workers' => true,
            'contracts' => true,
            'risks' => true,
            'performance' => true,
            'recommendations' => true,
        ],
        
        // Настройки экспорта
        'pdf_export' => [
            'enabled' => true,
            'orientation' => 'portrait', // portrait или landscape
            'page_size' => 'A4', // A4, Letter, Legal
        ],
        
        // Автоматическое сохранение истории
        'save_history' => true,
        
        // Максимальное количество хранимых анализов на проект
        'max_reports_per_project' => 50,
        
        // Автоматическая очистка старых отчетов (в днях)
        'auto_cleanup_days' => 90,
    ],
];

