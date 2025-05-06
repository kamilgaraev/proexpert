<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\DB;

class SubscriptionPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Очистка таблицы перед заполнением, если это необходимо
        // DB::table('subscription_plans')->delete(); // или truncate()

        $plans = [
            [
                'name' => 'Старт',
                'slug' => 'start',
                'description' => 'Для индивидуальных предпринимателей и очень маленьких бригад.',
                'price' => 499.00,
                'currency' => 'RUB',
                'duration_in_days' => 30,
                'max_foremen' => 1,
                'max_projects' => 1,
                'max_storage_gb' => 1,
                'features' => json_encode([
                    'Мобильное приложение: приемка, списание, фиксация работ.',
                    'Веб-админка: управление 1 объектом, базовые справочники (до 10 позиций).',
                    'Просмотр логов (последние 30 дней).',
                    'Выгрузка отчета CSV (базовый).',
                    'Поддержка: База знаний, FAQ.'
                ]),
                'is_active' => true,
                'display_order' => 1,
            ],
            [
                'name' => 'Рост',
                'slug' => 'growth',
                'description' => 'Для малых строительных компаний и растущих бригад.',
                'price' => 1499.00,
                'currency' => 'RUB',
                'duration_in_days' => 30,
                'max_foremen' => 3,
                'max_projects' => 5,
                'max_storage_gb' => 10,
                'features' => json_encode([
                    'Все функции тарифа "Старт".',
                    'Расширенные справочники (до 50 позиций).',
                    'Просмотр логов (без ограничений).',
                    'Выгрузка отчетов CSV/Excel.',
                    'Управление пользователями-прорабами.',
                    'Поддержка: Email.'
                ]),
                'is_active' => true,
                'display_order' => 2,
            ],
            [
                'name' => 'Команда',
                'slug' => 'team',
                'description' => 'Для стабильных небольших и средних строительных компаний.',
                'price' => 3999.00,
                'currency' => 'RUB',
                'duration_in_days' => 30,
                'max_foremen' => 10,
                'max_projects' => 15,
                'max_storage_gb' => 50,
                'features' => json_encode([
                    'Все функции тарифа "Рост".',
                    'Неограниченные справочники.',
                    'Назначение прорабов на объекты.',
                    'Базовые фото-отчеты.',
                    'Поддержка: Email, Чат.'
                ]),
                'is_active' => true,
                'display_order' => 3,
            ],
            [
                'name' => 'Бизнес',
                'slug' => 'business',
                'description' => 'Для средних компаний с несколькими объектами и командами.',
                'price' => 8999.00,
                'currency' => 'RUB',
                'duration_in_days' => 30,
                'max_foremen' => 25,
                'max_projects' => 40,
                'max_storage_gb' => 200,
                'features' => json_encode([
                    'Все функции тарифа "Команда".',
                    'Email-уведомления о ключевых событиях.',
                    'Базовая аналитика и дашборды.',
                    'Поддержка: Приоритетная Email/Чат, Персональный менеджер.'
                ]),
                'is_active' => true,
                'display_order' => 4,
            ],
            [
                'name' => 'Профи',
                'slug' => 'pro',
                'description' => 'Для крупных компаний или компаний с особыми требованиями.',
                'price' => 15999.00,
                'currency' => 'RUB',
                'duration_in_days' => 30,
                'max_foremen' => 50, // Пример, может быть null для "обсуждается индивидуально"
                'max_projects' => 100, // Пример
                'max_storage_gb' => 500, // Пример
                'features' => json_encode([
                    'Все функции тарифа "Бизнес".',
                    'API доступ для кастомных интеграций (чтение).',
                    'Детализированный учет рабочего времени (в будущем).',
                    'Возможность кастомизации отчетов.',
                    'Поддержка: Выделенная линия, SLA.'
                ]),
                'is_active' => true,
                'display_order' => 5,
            ],
        ];

        foreach ($plans as $planData) {
            SubscriptionPlan::updateOrCreate([
                'slug' => $planData['slug']
            ], $planData);
        }
    }
} 