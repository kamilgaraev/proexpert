<?php

return [
    'default_channels' => ['in_app'],

    'channels' => [
        'email' => [
            'driver' => \App\BusinessModules\Features\Notifications\Channels\EmailChannel::class,
            'enabled' => true,
            'tracking' => [
                'opens' => true,
                'clicks' => true,
            ],
        ],
        'telegram' => [
            'driver' => \App\BusinessModules\Features\Notifications\Channels\TelegramChannel::class,
            'enabled' => true,
        ],
        'in_app' => [
            'driver' => \App\BusinessModules\Features\Notifications\Channels\InAppChannel::class,
            'enabled' => true,
        ],
        'websocket' => [
            'driver' => \App\BusinessModules\Features\Notifications\Channels\WebSocketChannel::class,
            'enabled' => env('BROADCAST_DRIVER') === 'reverb',
        ],
    ],

    'types' => [
        'transactional' => [
            'name' => 'Транзакционные',
            'description' => 'Подтверждения, инвойсы, платежи',
            'mandatory' => true,
            'default_channels' => ['email', 'in_app'],
            'user_customizable' => false,
        ],
        'system' => [
            'name' => 'Системные',
            'description' => 'Алерты, превышение лимитов, дедлайны',
            'mandatory' => false,
            'default_channels' => ['email', 'telegram', 'in_app', 'websocket'],
            'user_customizable' => true,
        ],
        'communication' => [
            'name' => 'Коммуникационные',
            'description' => 'Приглашения, комментарии, упоминания',
            'mandatory' => false,
            'default_channels' => ['email', 'in_app', 'websocket'],
            'user_customizable' => true,
        ],
        'marketing' => [
            'name' => 'Маркетинговые',
            'description' => 'Анонсы, рассылки, новости',
            'mandatory' => false,
            'default_channels' => ['email'],
            'user_customizable' => true,
        ],
        'custom' => [
            'name' => 'Кастомные',
            'description' => 'Определяемые администратором',
            'mandatory' => false,
            'default_channels' => ['in_app'],
            'user_customizable' => true,
        ],
    ],

    'priorities' => [
        'critical' => [
            'name' => 'Критический',
            'queue' => 'notifications-critical',
            'retry_times' => 5,
            'retry_after' => 60,
        ],
        'high' => [
            'name' => 'Высокий',
            'queue' => 'notifications-high',
            'retry_times' => 3,
            'retry_after' => 120,
        ],
        'normal' => [
            'name' => 'Обычный',
            'queue' => 'notifications',
            'retry_times' => 3,
            'retry_after' => 300,
        ],
        'low' => [
            'name' => 'Низкий',
            'queue' => 'notifications-low',
            'retry_times' => 2,
            'retry_after' => 600,
        ],
    ],

    'rate_limiting' => [
        'enabled' => true,
        'max_per_hour' => 100,
        'max_per_day' => 500,
        'group_similar' => true,
        'grouping_window' => 300,
    ],

    'quiet_hours' => [
        'enabled' => true,
        'default_start' => '22:00',
        'default_end' => '08:00',
        'apply_to_types' => ['marketing', 'custom'],
    ],

    'templates' => [
        'cache_enabled' => true,
        'cache_ttl' => 3600,
        'variables' => [
            'user' => ['id', 'name', 'email', 'phone'],
            'organization' => ['id', 'name'],
            'project' => ['id', 'name', 'number'],
            'contract' => ['id', 'number', 'total_amount'],
            'system' => ['app_name', 'app_url', 'support_email'],
        ],
    ],

    'analytics' => [
        'enabled' => true,
        'tracking_domain' => env('APP_URL'),
        'retention_days' => 90,
        'aggregate_interval' => 'daily',
    ],

    'webhooks' => [
        'enabled' => true,
        'timeout' => 30,
        'max_retries' => 3,
        'verify_ssl' => true,
    ],
];

