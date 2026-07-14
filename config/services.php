<?php

return [

    'yookassa' => [
        'mode' => env('YOOKASSA_MODE', 'test'),
        'shop_id' => env('YOOKASSA_SHOP_ID'),
        'secret_key' => env('YOOKASSA_SECRET_KEY'),
        'api_url' => env('YOOKASSA_API_URL', 'https://api.yookassa.ru/v3'),
        'return_url' => env('YOOKASSA_RETURN_URL', 'https://1мост.рф/dashboard/billing'),
        'webhook_url' => env('YOOKASSA_WEBHOOK_URL', 'https://1мост.рф/api/v1/webhooks/yookassa'),
        'timeout' => (int) env('YOOKASSA_TIMEOUT', 10),
        'retry_attempts' => (int) env('YOOKASSA_RETRY_ATTEMPTS', 3),
        'retry_delay_ms' => (int) env('YOOKASSA_RETRY_DELAY_MS', 150),
        'webhook_source_cidrs' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('YOOKASSA_WEBHOOK_SOURCE_CIDRS', '185.71.76.0/27,185.71.77.0/27,77.75.153.0/25,77.75.156.11/32,77.75.156.35/32,77.75.154.128/25,2a02:5180::/32')),
        ))),
        'trusted_proxy_cidrs' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('YOOKASSA_TRUSTED_PROXY_CIDRS', '')),
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => (string) env('RESEND_KEY', ''),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'dadata' => [
        'api_key' => (string) env('DADATA_API_KEY', ''),
        'secret_key' => (string) env('DADATA_SECRET_KEY', ''),
        'base_url' => 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/',
        'clean_url' => 'https://cleaner.dadata.ru/api/v1/clean/',
    ],

    'yandexgpt' => [
        'api_key' => env('YANDEX_API_KEY'),
        'folder_id' => env('YANDEX_FOLDER_ID'),
        'model_uri' => env('YANDEX_MODEL_URI'),
    ],

    'video_monitoring' => [
        'driver' => env('VIDEO_MONITORING_MEDIA_DRIVER', 'none'),
        'timeout' => (int) env('VIDEO_MONITORING_MEDIA_TIMEOUT', 5),
        'verify_tls' => (bool) env('VIDEO_MONITORING_MEDIA_VERIFY_TLS', true),
        'preferred_live_protocol' => env('VIDEO_MONITORING_PREFERRED_LIVE_PROTOCOL', 'webrtc'),
        'autofill_playback_url' => (bool) env('VIDEO_MONITORING_AUTOFILL_PLAYBACK_URL', false),
        'mediamtx' => [
            'manage_paths' => (bool) env('VIDEO_MONITORING_MEDIAMTX_MANAGE_PATHS', false),
            'api_base_url' => env('VIDEO_MONITORING_MEDIAMTX_API_BASE_URL'),
            'api_token' => env('VIDEO_MONITORING_MEDIAMTX_API_TOKEN'),
            'path_prefix' => env('VIDEO_MONITORING_MEDIAMTX_PATH_PREFIX', 'prohelper'),
            'source_on_demand' => (bool) env('VIDEO_MONITORING_MEDIAMTX_SOURCE_ON_DEMAND', true),
            'public_urls' => [
                'webrtc' => env('VIDEO_MONITORING_MEDIAMTX_WEBRTC_URL'),
                'hls' => env('VIDEO_MONITORING_MEDIAMTX_HLS_URL'),
            ],
        ],
    ],

    'public_contact' => [
        'recipients' => array_values(array_filter(array_map(
            static fn (string $recipient): string => trim($recipient),
            explode(',', (string) env('PUBLIC_CONTACT_NOTIFICATION_EMAILS', 'request@xn--1-xtbgmf.xn--p1ai'))
        ))),
    ],

];
