<?php

return [
    'default_limits' => [
        'max_users' => 3,
        'max_projects' => 1,
        'max_storage_mb' => 100,
        'max_contractor_invitations' => 3,
    ],

    'enterprise_constructor' => [
        'name' => 'Enterprise Конструктор',
        'base' => [
            'price' => 99000,
            'users' => 100,
            'projects' => 100,
            'storage_gb' => 50,
            'ai_requests' => 2000,
            'contractor_invitations' => 500,
            'organizations' => 1,
        ],
        'extensions' => [
            'users_to_250' => [
                'label' => 'До 250 пользователей',
                'price' => 50000,
            ],
            'next_100_users' => [
                'label' => 'Каждые следующие 100 пользователей',
                'price' => 35000,
            ],
            'additional_organization' => [
                'label' => 'Дополнительная организация',
                'price' => 15000,
            ],
            'extended_ai' => [
                'label' => 'Расширенный AI',
                'price' => 10000,
                'ai_requests' => 2000,
            ],
            'extra_storage_100gb' => [
                'label' => 'Дополнительные 100 ГБ',
                'price' => 7000,
            ],
            'priority_support' => [
                'label' => 'Приоритетная поддержка',
                'price' => 25000,
            ],
        ],
        'implementation_project_options' => [
            'needs_integrations' => 'Интеграции с корпоративными системами',
            'needs_migration' => 'Перенос данных из старых систем',
            'needs_sla' => 'Расширенные условия обслуживания',
            'more_than_250_users' => 'Команда больше 250 пользователей',
        ],
        'cta' => [
            'standard' => 'Рассчитать стоимость',
            'implementation_project' => 'Подготовить проект внедрения',
        ],
    ],

    'testing' => [
        'enabled' => true,
        'initial_balance' => 3000000,
        'description' => 'Тестовый баланс при регистрации',
        'meta' => [
            'type' => 'testing_mode_grant',
            'reason' => 'open_beta_testing',
            'auto_granted' => true,
        ],
    ],

    'payment_gateway' => [
        'driver' => 'mock',
        'webhook_url' => null,
        'webhook_secret' => null,
        'stripe' => [
            'key' => '',
            'secret' => '',
        ],
        'yookassa' => [
            'shop_id' => '',
            'secret_key' => '',
        ],
        'robokassa' => [
            'merchant_login' => '',
            'password1' => '',
            'password2' => '',
            'test_mode' => true,
        ],
    ],
];
