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
                'included_packages' => [
                    ['package_slug' => 'objects-execution', 'tier' => 'base'],
                ],
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
                        'Снабжение и склад уровня Старт',
                        'Финансы и акты уровня Старт',
                        'Заявки, закупки и платежи в одном контуре',
                        'Контроль нескольких объектов',
                    ],
                    'Дополнительно' => [
                        'Расширенные лимиты команды и объектов',
                        'Базовая помощь при запуске',
                    ],
                ],
                'included_packages' => [
                    ['package_slug' => 'objects-execution', 'tier' => 'base'],
                    ['package_slug' => 'supply-warehouse', 'tier' => 'base'],
                    ['package_slug' => 'finance-acts', 'tier' => 'base'],
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
                        'Пакеты уровня Рост по всем строительным контурам',
                        'Графики работ и заявки с объекта',
                        'Снабжение, склад и материальная аналитика',
                        'Сметы, ПТО и шаблоны отчетов',
                        'AI-помощник и AI-старт смет как пилотные сценарии',
                    ],
                    'Дополнительно' => [
                        'Больше лимитов для команды и объектов',
                        'Поддержка запуска по основным рабочим контурам',
                        'Расширение через отдельные проектные услуги',
                    ],
                ],
                'included_packages' => [
                    ['package_slug' => 'objects-execution', 'tier' => 'pro'],
                    ['package_slug' => 'supply-warehouse', 'tier' => 'pro'],
                    ['package_slug' => 'finance-acts', 'tier' => 'pro'],
                    ['package_slug' => 'estimates-pto', 'tier' => 'pro'],
                    ['package_slug' => 'holding-analytics', 'tier' => 'pro'],
                    ['package_slug' => 'ai-contour', 'tier' => 'pro'],
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
                        'Корпоративные уровни всех пакетов',
                        'Холдинговая структура и несколько юрлиц',
                        'Консолидация управленческих данных',
                        'Интеграции проектируются отдельным контуром',
                        'Индивидуальные лимиты по КП',
                    ],
                    'Корпоративные услуги' => [
                        'Внедрение и настройка по согласованному плану',
                        'Интеграции с корпоративными системами по ТЗ',
                        'Дополнительные условия поддержки фиксируются в договоре',
                        'Регулярные проектные сессии при необходимости',
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
