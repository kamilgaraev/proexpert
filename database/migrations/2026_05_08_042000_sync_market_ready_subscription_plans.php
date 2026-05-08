<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach ($this->plans() as $plan) {
            $query = DB::table('subscription_plans')->where('slug', $plan['slug']);

            if ($query->exists()) {
                $query->update(array_merge($plan, ['updated_at' => $now]));
                continue;
            }

            DB::table('subscription_plans')->insert(array_merge($plan, [
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
    }

    private function plans(): array
    {
        return [
            [
                'name' => 'Free',
                'slug' => 'free',
                'description' => 'Бесплатный тариф для знакомства с системой',
                'price' => 0,
                'currency' => 'RUB',
                'duration_in_days' => 30,
                'max_users' => 3,
                'max_foremen' => 1,
                'max_projects' => 1,
                'max_storage_gb' => 1,
                'max_contractor_invitations' => 3,
                'features' => $this->json([
                    'Лимиты' => [
                        '3 пользователя',
                        '1 прораб',
                        '1 проект',
                        '1 ГБ хранилища',
                    ],
                    'Включенные возможности' => [
                        'Управление пользователями',
                        'Управление организацией',
                        'Базовые отчеты',
                    ],
                ]),
                'included_packages' => $this->json([]),
                'is_active' => true,
                'display_order' => 1,
            ],
            [
                'name' => 'Start',
                'slug' => 'start',
                'description' => 'Для небольшой команды на первых проектах',
                'price' => 4900,
                'currency' => 'RUB',
                'duration_in_days' => 30,
                'max_users' => 5,
                'max_foremen' => 2,
                'max_projects' => 3,
                'max_storage_gb' => 1,
                'max_contractor_invitations' => 10,
                'features' => $this->json([
                    'Лимиты' => [
                        '5 пользователей',
                        '2 прораба',
                        '3 проекта',
                        '1 ГБ хранилища',
                    ],
                    'Включенные возможности' => [
                        'Все из Free',
                        'Управление проектами',
                        'Каталог материалов и подрядчиков',
                    ],
                ]),
                'included_packages' => $this->json([
                    ['package_slug' => 'objects-execution', 'tier' => 'base'],
                ]),
                'is_active' => true,
                'display_order' => 2,
            ],
            [
                'name' => 'Business',
                'slug' => 'business',
                'description' => 'Для растущей строительной компании',
                'price' => 19900,
                'currency' => 'RUB',
                'duration_in_days' => 30,
                'max_users' => 10,
                'max_foremen' => 5,
                'max_projects' => 10,
                'max_storage_gb' => 5,
                'max_contractor_invitations' => 50,
                'features' => $this->json([
                    'Лимиты' => [
                        '10 пользователей',
                        '5 прорабов',
                        '10 проектов',
                        '5 ГБ хранилища',
                    ],
                    'Включенные возможности' => [
                        'Все из Start',
                        'Снабжение и склад',
                        'Финансы и акты',
                    ],
                ]),
                'included_packages' => $this->json([
                    ['package_slug' => 'objects-execution', 'tier' => 'base'],
                    ['package_slug' => 'supply-warehouse', 'tier' => 'base'],
                    ['package_slug' => 'finance-acts', 'tier' => 'base'],
                ]),
                'is_active' => true,
                'display_order' => 3,
            ],
            [
                'name' => 'Profi',
                'slug' => 'profi',
                'description' => 'Для профессионального управления портфелем объектов',
                'price' => 29900,
                'currency' => 'RUB',
                'duration_in_days' => 30,
                'max_users' => 30,
                'max_foremen' => 15,
                'max_projects' => 30,
                'max_storage_gb' => 15,
                'max_contractor_invitations' => 150,
                'features' => $this->json([
                    'Лимиты' => [
                        '30 пользователей',
                        '15 прорабов',
                        '30 проектов',
                        '15 ГБ хранилища',
                    ],
                    'Включенные возможности' => [
                        'Все из Business',
                        'Сметы и ПТО',
                        'Холдинговая аналитика',
                        'AI-помощник',
                    ],
                ]),
                'included_packages' => $this->json([
                    ['package_slug' => 'objects-execution', 'tier' => 'pro'],
                    ['package_slug' => 'supply-warehouse', 'tier' => 'pro'],
                    ['package_slug' => 'finance-acts', 'tier' => 'pro'],
                    ['package_slug' => 'estimates-pto', 'tier' => 'pro'],
                    ['package_slug' => 'holding-analytics', 'tier' => 'pro'],
                    ['package_slug' => 'ai-contour', 'tier' => 'pro'],
                ]),
                'is_active' => true,
                'display_order' => 4,
            ],
            [
                'name' => 'Enterprise Конструктор',
                'slug' => 'enterprise',
                'description' => 'Конструктор для крупных компаний и холдингов',
                'price' => 99000,
                'currency' => 'RUB',
                'duration_in_days' => 30,
                'max_users' => 100,
                'max_foremen' => 100,
                'max_projects' => 100,
                'max_storage_gb' => 50,
                'max_contractor_invitations' => 500,
                'features' => $this->json([
                    'Базовые лимиты конструктора' => [
                        '100 пользователей',
                        '100 прорабов',
                        '100 проектов',
                        '50 ГБ хранилища',
                        '2000 AI-запросов',
                    ],
                    'Расширения' => [
                        'До 250 пользователей за 50 000 ₽',
                        'Дополнительная организация за 15 000 ₽',
                        'Расширенный AI за 10 000 ₽',
                        'Дополнительные 100 ГБ за 7 000 ₽',
                        'Приоритетная поддержка за 25 000 ₽',
                    ],
                ]),
                'included_packages' => $this->json([
                    ['package_slug' => 'objects-execution', 'tier' => 'enterprise'],
                    ['package_slug' => 'finance-acts', 'tier' => 'enterprise'],
                    ['package_slug' => 'supply-warehouse', 'tier' => 'enterprise'],
                    ['package_slug' => 'holding-analytics', 'tier' => 'enterprise'],
                    ['package_slug' => 'estimates-pto', 'tier' => 'enterprise'],
                    ['package_slug' => 'ai-contour', 'tier' => 'enterprise'],
                ]),
                'is_active' => true,
                'display_order' => 5,
            ],
        ];
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
};
