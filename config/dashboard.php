<?php

return [
    'widgets_registry' => [
        'version' => 1,
        'widgets' => [
            [
                'id' => 'summary',
                'title' => 'Ключевые показатели',
                'default_enabled' => true,
                'default_size' => ['xs' => 12, 'md' => 12, 'lg' => 12],
                'min_size' => ['xs' => 12, 'md' => 6, 'lg' => 4],
                'roles' => ['admin','manager']
            ],
            [
                'id' => 'activityChart',
                'title' => 'Динамика активности',
                'default_enabled' => true,
                'default_size' => ['xs' => 12, 'md' => 12, 'lg' => 8],
            ],
            [
                'id' => 'recentActivity',
                'title' => 'Последняя активность',
                'default_enabled' => true,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 4],
            ],
            [
                'id' => 'contractsAttention',
                'title' => 'Контракты требуют внимания',
                'default_enabled' => true,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 4],
            ],
            [
                'id' => 'topContracts',
                'title' => 'Топ контрактов',
                'default_enabled' => true,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 4],
            ],
            [
                'id' => 'materialsAnalytics',
                'title' => 'Материалы — аналитика',
                'default_enabled' => true,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 6],
            ],
            [
                'id' => 'lowStockMaterials',
                'title' => 'Материалы — низкие остатки',
                'default_enabled' => true,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 6],
            ],
            [
                'id' => 'siteRequestsStats',
                'title' => 'Заявки с площадки',
                'default_enabled' => true,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 6],
            ],
            [
                'id' => 'scheduleStats',
                'title' => 'Статистика графиков работ',
                'default_enabled' => true,
                'default_size' => ['xs' => 12, 'md' => 12, 'lg' => 12],
            ],
        ],
    ],
];


