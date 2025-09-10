<?php

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN', '8153490735:AAHxVV8BQDa9rHVAZWuvEmlEW0pNGu484RE'),
    
    'chat_id' => env('TELEGRAM_CHAT_ID', '-4885866847'),
    
    'notifications' => [
        'contact_forms' => env('TELEGRAM_NOTIFY_CONTACT_FORMS', true),
        'site_requests' => env('TELEGRAM_NOTIFY_SITE_REQUESTS', false),
    ],
    
    'api_timeout' => env('TELEGRAM_API_TIMEOUT', 30),
    
    'bot_username' => '@prohelpersbot',
];
