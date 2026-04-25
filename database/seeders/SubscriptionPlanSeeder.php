<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Free',
                'slug' => 'free',
                'description' => 'Бесплатный тариф для знакомства с системой',
                'price' => 0,
                'currency' => 'RUB',
                'duration_in_days' => 30,
                'max_foremen' => 1,
                'max_projects' => 1,
                'max_storage_gb' => 1,
                'features' => [
                    'Лимиты' => [
                        '1 прораб',
                        '1 проект',
                        '3 пользователя',
                        '1 ГБ хранилища',
                    ],
                    'Включенные модули' => [
                        'Управление пользователями',
                        'Управление организациями',
                        'Базовые отчеты',
                        'Фильтрация данных',
                        'Виджеты дашборда',
                    ],
                    'Ограничения' => [
                        'Только базовый функционал',
                        '10 операций/мес',
                    ],
                ],
                'included_packages' => [],
                'is_active' => true,
                'display_order' => 1,
            ],
            [
                'name' => 'Start',
                'slug' => 'start',
                'description' => 'Для малого бизнеса и стартапов',
                'price' => 4900,
                'currency' => 'RUB',
                'duration_in_days' => 30,
                'max_foremen' => 2,
                'max_projects' => 3,
                'max_storage_gb' => 1,
                'features' => [
                    'Лимиты' => [
                        '2 прораба',
                        '3 проекта',
                        '5 пользователей',
                        '1 ГБ хранилища',
                    ],
                    'Всё из Free +' => [
                        'Управление проектами',
                        'Управление контрактами',
                        'Управление справочниками',
                        'Каталог материалов и подрядчиков',
                    ],
                    'Дополнительно' => [
                        'Базовые отчеты без ограничений',
                        'Техническая поддержка',
                    ],
                ],
                'included_packages' => [],
                'is_active' => true,
                'display_order' => 2,
            ],
            [
                'name' => 'Business',
                'slug' => 'business',
                'description' => 'Для растущего бизнеса с расширенными возможностями',
                'price' => 9900,
                'currency' => 'RUB',
                'duration_in_days' => 30,
                'max_foremen' => 10,
                'max_projects' => 15,
                'max_storage_gb' => 5,
                'features' => [
                    'Лимиты' => [
                        '10 прорабов',
                        '15 проектов',
                        '15 пользователей',
                        '5 ГБ хранилища',
                        '2 администратора',
                    ],
                    'Всё из Start +' => [
                        '🔥 Интеграции (1С, CRM, API)',
                        '🔥 Управление рабочими процессами',
                        '🔥 Учет рабочего времени',
                        '🔥 Продвинутые отчеты и аналитика',
                        'Webhooks',
                        'Автоматизация процессов',
                    ],
                    'Дополнительно' => [
                        'Приоритетная поддержка',
                        'Обучение команды',
                    ],
                ],
                'included_packages' => [
                    ['package_slug' => 'objects-execution', 'tier' => 'base'],
                ],
                'is_active' => true,
                'display_order' => 3,
            ],
            [
                'name' => 'Profi',
                'slug' => 'profi',
                'description' => 'Профессиональное решение для крупных компаний',
                'price' => 19900,
                'currency' => 'RUB',
                'duration_in_days' => 30,
                'max_foremen' => 30,
                'max_projects' => 50,
                'max_storage_gb' => 20,
                'features' => [
                    'Лимиты' => [
                        '30 прорабов',
                        '50 проектов',
                        '50 пользователей',
                        '20 ГБ хранилища',
                        '5 администраторов',
                    ],
                    'Всё из Business +' => [
                        '🔥 Управление расписанием работ',
                        '🔥 Планирование ресурсов',
                        'API доступ с расширенными лимитами',
                        'BI и бизнес-аналитика',
                        'White Label (свой брендинг)',
                    ],
                    'Дополнительно' => [
                        'Персональный менеджер',
                        'SLA 99.9%',
                        'Еженедельные консультации',
                    ],
                ],
                'included_packages' => [
                    ['package_slug' => 'objects-execution', 'tier' => 'pro'],
                    ['package_slug' => 'finance-acts', 'tier' => 'base'],
                    ['package_slug' => 'supply-warehouse', 'tier' => 'base'],
                    ['package_slug' => 'holding-analytics', 'tier' => 'base'],
                ],
                'is_active' => true,
                'display_order' => 4,
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'description' => 'Корпоративное решение с индивидуальными условиями',
                'price' => 49900,
                'currency' => 'RUB',
                'duration_in_days' => 30,
                'max_foremen' => null,
                'max_projects' => null,
                'max_storage_gb' => null,
                'features' => [
                    'Безлимитные возможности' => [
                        'Неограниченное количество прорабов',
                        'Неограниченное количество проектов',
                        'Неограниченное количество пользователей',
                        'Индивидуальный объём хранилища',
                    ],
                    'Всё из Profi +' => [
                        '🔥 Система холдингов и дочерних организаций',
                        '🔥 Корпоративные сайты холдингов',
                        '🔥 Консолидированная отчётность',
                        'Иерархия доступа между организациями',
                        'До 50 дочерних компаний',
                    ],
                    'Корпоративные услуги' => [
                        'Выделенный сервер',
                        'Индивидуальная доработка функций',
                        'Интеграция с корп. системами',
                        'SLA 99.99%',
                        'Круглосуточная поддержка',
                        'Регулярные стратегические сессии',
                    ],
                ],
                'included_packages' => [
                    ['package_slug' => 'objects-execution', 'tier' => 'enterprise'],
                    ['package_slug' => 'finance-acts', 'tier' => 'enterprise'],
                    ['package_slug' => 'supply-warehouse', 'tier' => 'enterprise'],
                    ['package_slug' => 'holding-analytics', 'tier' => 'enterprise'],
                    ['package_slug' => 'estimates-pto', 'tier' => 'enterprise'],
                    ['package_slug' => 'ai-contour', 'tier' => 'enterprise'],
                ],
                'is_active' => true,
                'display_order' => 5,
            ],
        ];

        foreach ($plans as $planData) {
            SubscriptionPlan::updateOrCreate(
                ['slug' => $planData['slug']],
                $planData
            );
        }
    }
}
