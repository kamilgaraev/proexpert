<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);

        // Базовые системные данные
        $this->call([
            RolePermissionSeeder::class,
            MeasurementUnitSeeder::class,
            SubscriptionPlanSeeder::class,
        ]);

        // Базовые данные для работы (организации, проекты, материалы)
        $this->call(BasicDataSeeder::class);

        // Сидеры для активности прорабов и выполненных работ
        $this->call([
            ForemanActivitySeeder::class,
            MaterialUsageLogSeeder::class,
            WorkCompletionLogSeeder::class,
            CompletedWorkSeeder::class,
        ]);
    }
}
