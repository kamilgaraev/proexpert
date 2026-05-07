<?php

return [
    'bot_token' => (string) env('TELEGRAM_BOT_TOKEN', ''),

    'chat_id' => (string) env('TELEGRAM_CHAT_ID', ''),

    'notifications' => [
        'contact_forms' => env('TELEGRAM_NOTIFY_CONTACT_FORMS', true),
    ],

    'api_timeout' => env('TELEGRAM_API_TIMEOUT', 30),

    'bot_username' => '@prohelpersbot',
];
