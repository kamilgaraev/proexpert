<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\OrganizationOneTimePurchase;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;

class OrganizationOneTimePurchaseSeeder extends Seeder
{
    public function run(): void
    {
        // Для демонстрации — создаём тестовые одноразовые услуги для первой организации и пользователя
        $organization = Organization::first();
        $user = User::first();
        if (!$organization || !$user) return;

        $purchases = [
            [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'type' => 'export_over_limit',
                'description' => 'Экспорт данных сверх лимита тарифа',
                'amount' => 500,
                'currency' => 'RUB',
                'status' => 'paid',
                'external_payment_id' => null,
                'purchased_at' => Carbon::now()->subDays(2),
                'expires_at' => null,
            ],
            [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'type' => 'mass_import',
                'description' => 'Массовый импорт данных',
                'amount' => 1200,
                'currency' => 'RUB',
                'status' => 'paid',
                'external_payment_id' => null,
                'purchased_at' => Carbon::now()->subDays(1),
                'expires_at' => null,
            ],
            [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'type' => 'api_request',
                'description' => 'Пакет 1000 API-запросов',
                'amount' => 900,
                'currency' => 'RUB',
                'status' => 'paid',
                'external_payment_id' => null,
                'purchased_at' => Carbon::now(),
                'expires_at' => Carbon::now()->addMonth(),
            ],
        ];

        foreach ($purchases as $purchaseData) {
            OrganizationOneTimePurchase::create($purchaseData);
        }
    }
} 