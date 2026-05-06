<?php

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),

    'chat_id' => env('TELEGRAM_CHAT_ID'),

    'notifications' => [
        'contact_forms' => env('TELEGRAM_NOTIFY_CONTACT_FORMS', true),
    ],

    'api_timeout' => env('TELEGRAM_API_TIMEOUT', 30),

    'bot_username' => '@prohelpersbot',
];
