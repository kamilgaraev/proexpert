<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SubscriptionAddon;

class SubscriptionAddonSeeder extends Seeder
{
    public function run(): void
    {
        $addons = [
            [
                'name' => 'Интеграции',
                'slug' => 'integrations',
                'description' => 'Доступ к интеграциям с внешними сервисами.',
                'price' => 2900,
                'currency' => 'RUB',
                'features' => json_encode(['Интеграция с 1С', 'Интеграция с CRM', 'Webhooks']),
                'is_active' => true,
                'display_order' => 1,
            ],
            [
                'name' => 'BI-аналитика',
                'slug' => 'bi',
                'description' => 'Доступ к расширенной BI-аналитике и дашбордам.',
                'price' => 4900,
                'currency' => 'RUB',
                'features' => json_encode(['Дашборды', 'Экспорт в Excel', 'Графики и отчёты']),
                'is_active' => true,
                'display_order' => 2,
            ],
            [
                'name' => 'White Label',
                'slug' => 'white_label',
                'description' => 'Кастомизация платформы под бренд клиента.',
                'price' => 9900,
                'currency' => 'RUB',
                'features' => json_encode(['Свой логотип', 'Цветовая схема', 'Кастомные домены']),
                'is_active' => true,
                'display_order' => 3,
            ],
            [
                'name' => 'Премиум-поддержка',
                'slug' => 'premium_support',
                'description' => 'Приоритетная поддержка и SLA.',
                'price' => 3900,
                'currency' => 'RUB',
                'features' => json_encode(['SLA', 'Личный менеджер', 'Приоритетные тикеты']),
                'is_active' => true,
                'display_order' => 4,
            ],
            [
                'name' => 'Дополнительные пользователи',
                'slug' => 'extra_users',
                'description' => 'Пакет из 5 дополнительных пользователей.',
                'price' => 2500,
                'currency' => 'RUB',
                'features' => json_encode(['+5 пользователей']),
                'is_active' => true,
                'display_order' => 5,
            ],
            [
                'name' => 'Дополнительное хранилище',
                'slug' => 'extra_storage',
                'description' => 'Пакет +10 ГБ к основному хранилищу.',
                'price' => 1900,
                'currency' => 'RUB',
                'features' => json_encode(['+10 ГБ']),
                'is_active' => true,
                'display_order' => 6,
            ],
        ];

        foreach ($addons as $addonData) {
            SubscriptionAddon::updateOrCreate([
                'slug' => $addonData['slug']
            ], $addonData);
        }
    }
} 