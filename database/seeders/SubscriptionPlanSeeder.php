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
                'name' => 'Free',
                'slug' => 'free',
                'description' => 'Бесплатный тариф: 1 прораб, 1 объект, 100 МБ, 10 операций/мес, только базовые функции.',
                'price' => 0,
                'currency' => 'RUB',
                'duration_in_days' => 30,
                'max_foremen' => 1,
                'max_projects' => 1,
                'max_storage_gb' => 0.1,
                'features' => json_encode([
                    'Базовые функции',
                    'Ограничение: 10 операций/мес',
                ]),
                'is_active' => true,
                'display_order' => 1,
            ],
            [
                'name' => 'Start',
                'slug' => 'start',
                'description' => '2 прораба, 3 объекта, 500 МБ, базовые отчёты.',
                'price' => 4900,
                'currency' => 'RUB',
                'duration_in_days' => 30,
                'max_foremen' => 2,
                'max_projects' => 3,
                'max_storage_gb' => 0.5,
                'features' => json_encode([
                    'Базовые отчёты',
                ]),
                'is_active' => true,
                'display_order' => 2,
            ],
            [
                'name' => 'Business',
                'slug' => 'business',
                'description' => '10 прорабов, 15 объектов, 2 админа, 5 ГБ, интеграции.',
                'price' => 9900,
                'currency' => 'RUB',
                'duration_in_days' => 30,
                'max_foremen' => 10,
                'max_projects' => 15,
                'max_storage_gb' => 5,
                'features' => json_encode([
                    '2 администратора',
                    'Интеграции',
                ]),
                'is_active' => true,
                'display_order' => 3,
            ],
            [
                'name' => 'Profi',
                'slug' => 'profi',
                'description' => '30 прорабов, 50 объектов, 5 админов, 20 ГБ, API, BI, White Label.',
                'price' => 19900,
                'currency' => 'RUB',
                'duration_in_days' => 30,
                'max_foremen' => 30,
                'max_projects' => 50,
                'max_storage_gb' => 20,
                'features' => json_encode([
                    '5 администраторов',
                    'API',
                    'BI',
                    'White Label',
                ]),
                'is_active' => true,
                'display_order' => 4,
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'description' => 'Индивидуальные условия, от 49 900 руб./мес.',
                'price' => 49900,
                'currency' => 'RUB',
                'duration_in_days' => 30,
                'max_foremen' => null,
                'max_projects' => null,
                'max_storage_gb' => null,
                'features' => json_encode([
                    'Индивидуальные лимиты',
                    'SLA',
                    'White Label',
                    'BI',
                    'API',
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