<?php

return [
    'widgets_registry' => [
        'version' => 2,
        'widgets' => [
            // Базовые виджеты
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
            
            // Финансовые виджеты
            [
                'id' => 'financialMetrics',
                'title' => 'Финансовые показатели',
                'default_enabled' => true,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 6],
                'min_size' => ['xs' => 12, 'md' => 6, 'lg' => 4],
            ],
            [
                'id' => 'financialFlow',
                'title' => 'Движение финансов',
                'default_enabled' => true,
                'default_size' => ['xs' => 12, 'md' => 12, 'lg' => 8],
                'min_size' => ['xs' => 12, 'md' => 6, 'lg' => 6],
            ],
            [
                'id' => 'contractsAmount',
                'title' => 'Суммы по контрактам',
                'default_enabled' => false,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 4],
            ],
            [
                'id' => 'worksAmount',
                'title' => 'Суммы по работам',
                'default_enabled' => false,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 4],
            ],
            
            // Виджеты контрактов
            [
                'id' => 'contractsAnalytics',
                'title' => 'Аналитика контрактов',
                'default_enabled' => true,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 6],
            ],
            [
                'id' => 'contractsByStatus',
                'title' => 'Контракты по статусам',
                'default_enabled' => true,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 4],
            ],
            [
                'id' => 'contractsByContractor',
                'title' => 'Контракты по подрядчикам',
                'default_enabled' => false,
                'default_size' => ['xs' => 12, 'md' => 12, 'lg' => 8],
            ],
            [
                'id' => 'contractsPerformance',
                'title' => 'Производительность контрактов',
                'default_enabled' => false,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 6],
            ],
            [
                'id' => 'contractsTimeline',
                'title' => 'Временная шкала контрактов',
                'default_enabled' => false,
                'default_size' => ['xs' => 12, 'md' => 12, 'lg' => 12],
            ],
            
            // Виджеты проектов
            [
                'id' => 'projectsProgress',
                'title' => 'Прогресс проектов',
                'default_enabled' => true,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 6],
            ],
            [
                'id' => 'projectsByStatus',
                'title' => 'Проекты по статусам',
                'default_enabled' => false,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 4],
            ],
            [
                'id' => 'projectsBudget',
                'title' => 'Бюджеты проектов',
                'default_enabled' => false,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 6],
            ],
            [
                'id' => 'projectsCompletion',
                'title' => 'Процент выполнения проектов',
                'default_enabled' => false,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 6],
            ],
            
            // Виджеты материалов
            [
                'id' => 'materialsStatus',
                'title' => 'Статус материалов',
                'default_enabled' => false,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 4],
            ],
            [
                'id' => 'materialsByCategory',
                'title' => 'Материалы по категориям',
                'default_enabled' => false,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 4],
            ],
            [
                'id' => 'materialsByProject',
                'title' => 'Материалы по проектам',
                'default_enabled' => false,
                'default_size' => ['xs' => 12, 'md' => 12, 'lg' => 8],
            ],
            [
                'id' => 'materialsConsumption',
                'title' => 'Расход материалов',
                'default_enabled' => false,
                'default_size' => ['xs' => 12, 'md' => 12, 'lg' => 8],
            ],
            [
                'id' => 'materialsTopUsed',
                'title' => 'Топ используемых материалов',
                'default_enabled' => false,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 6],
            ],
            
            // Виджеты работ
            [
                'id' => 'completedWorksAnalytics',
                'title' => 'Аналитика выполненных работ',
                'default_enabled' => true,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 6],
            ],
            [
                'id' => 'worksByType',
                'title' => 'Работы по типам',
                'default_enabled' => false,
                'default_size' => ['xs' => 12, 'md' => 12, 'lg' => 8],
            ],
            [
                'id' => 'worksEfficiency',
                'title' => 'Эффективность работ',
                'default_enabled' => false,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 6],
            ],
            
            // Виджеты подрядчиков и поставщиков
            [
                'id' => 'contractorsAnalytics',
                'title' => 'Аналитика подрядчиков',
                'default_enabled' => false,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 6],
            ],
            [
                'id' => 'suppliersAnalytics',
                'title' => 'Аналитика поставщиков',
                'default_enabled' => false,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 6],
            ],
            [
                'id' => 'topContractors',
                'title' => 'Топ подрядчиков',
                'default_enabled' => false,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 4],
            ],
            [
                'id' => 'topSuppliers',
                'title' => 'Топ поставщиков',
                'default_enabled' => false,
                'default_size' => ['xs' => 12, 'md' => 6, 'lg' => 4],
            ],
            
            // Виджеты сравнений и трендов
            [
                'id' => 'comparisonChart',
                'title' => 'Сравнение периодов',
                'default_enabled' => false,
                'default_size' => ['xs' => 12, 'md' => 12, 'lg' => 8],
            ],
            [
                'id' => 'monthlyTrends',
                'title' => 'Месячные тренды',
                'default_enabled' => false,
                'default_size' => ['xs' => 12, 'md' => 12, 'lg' => 12],
            ],
            [
                'id' => 'yearOverYear',
                'title' => 'Год к году',
                'default_enabled' => false,
                'default_size' => ['xs' => 12, 'md' => 12, 'lg' => 8],
            ],
        ],
    ],
];


