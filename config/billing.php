<?php

declare(strict_types=1);

return [
    'default_limits' => [
        'max_users' => 3,
        'max_projects' => 1,
        'max_storage_mb' => 100,
        'max_contractor_invitations' => 3,
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
