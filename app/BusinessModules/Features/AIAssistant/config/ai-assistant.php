<?php

return [
    'openai_api_key' => env('OPENAI_API_KEY'),
    
    'openai_model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    
    'max_tokens' => env('OPENAI_MAX_TOKENS', 2000),
    
    'enabled' => env('AI_ASSISTANT_ENABLED', true),
    
    'default_limit' => env('AI_ASSISTANT_DEFAULT_LIMIT', 5000),
    
    'cache_ttl' => env('AI_ASSISTANT_CACHE_TTL', 3600),
    
    'conversation_history_days' => 90,
];

