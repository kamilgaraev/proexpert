<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Carbon\Carbon;
use App\Models\Models\Log\WorkCompletionLog;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use App\Models\Organization;
use Faker\Factory as Faker;

class WorkCompletionLogSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('ru_RU');
        
        $organizations = Organization::pluck('id');
        if ($organizations->isEmpty()) {
            $this->command->warn('Нет организаций в базе данных. Пропускаем создание логов работ.');
            return;
        }

        $this->command->info("Создание логов работ для {$organizations->count()} организаций...");
        
        foreach ($organizations as $organizationId) {
            $this->seedForOrganization($organizationId, $faker);
        }
    }

    private function seedForOrganization(int $organizationId, $faker): void
    {
        // Получаем прорабов через новую систему авторизации
        $foremenIds = User::whereHas('roleAssignments', function ($query) {
            $query->where('role_slug', 'foreman')
                  ->where('is_active', true);
        })->pluck('id')->toArray();

        if (empty($foremenIds)) {
            // Создаем тестового прораба если нет
            $testForeman = User::firstOrCreate(
                ['email' => 'foreman@test.com'],
                [
                    'name' => 'Тестовый Прораб',
                    'password' => bcrypt('password'),
                    'current_organization_id' => $organizationId,
                    'phone' => $faker->phoneNumber,
                    'email_verified_at' => now(),
                ]
            );
            
            // Назначаем роль через новую систему
            $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organizationId);
            if (!$testForeman->hasRole('foreman', $context->id)) {
                \App\Domain\Authorization\Models\UserRoleAssignment::create([
                    'user_id' => $testForeman->id,
                    'role_slug' => 'foreman',
                    'role_type' => \App\Domain\Authorization\Models\UserRoleAssignment::TYPE_SYSTEM,
                    'context_id' => $context->id,
                    'is_active' => true,
                ]);
            }
            
            $foremenIds = [$testForeman->id];
        }

        // Получаем проекты и виды работ
        $projectIds = Project::where('organization_id', $organizationId)->pluck('id')->toArray();
        $workTypeIds = WorkType::where('organization_id', $organizationId)->pluck('id')->toArray();

        if (empty($projectIds) || empty($workTypeIds)) {
            $this->command->line("  ⊳ Организация {$organizationId}: пропущено (нет проектов/видов работ)");
            return;
        }

        $existingCount = WorkCompletionLog::whereIn('project_id', $projectIds)->count();
        if ($existingCount >= 50) {
            $this->command->line("  ⊳ Организация {$organizationId}: пропущено (уже {$existingCount} записей)");
            return;
        }
        
        $recordsToCreate = 50 - $existingCount;

        // Генерируем записи за последние 3 месяца
        $startDate = Carbon::now()->subMonths(3);
        $endDate = Carbon::now();

        $workDescriptions = [
            'Установка опалубки',
            'Заливка бетона',
            'Монтаж арматуры',
            'Кладка кирпича',
            'Штукатурные работы',
            'Малярные работы',
            'Установка окон',
            'Монтаж кровли',
            'Устройство фундамента',
            'Отделочные работы',
            'Электромонтажные работы',
            'Сантехнические работы',
            'Утепление стен',
            'Устройство стяжки',
            'Облицовочные работы'
        ];

        // Создаем записи для демонстрации активности
        foreach (range(1, $recordsToCreate) as $i) {
            $completionDate = $faker->dateTimeBetween($startDate, $endDate);
            $quantity = $faker->randomFloat(2, 1, 100);
            $unitPrice = $faker->randomFloat(2, 500, 5000);
            $totalPrice = $quantity * $unitPrice;

            WorkCompletionLog::create([
                'project_id' => $faker->randomElement($projectIds),
                'work_type_id' => $faker->randomElement($workTypeIds),
                'user_id' => $faker->randomElement($foremenIds),
                'organization_id' => $organizationId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
                'completion_date' => $completionDate,
                'performers_description' => $faker->randomElement($workDescriptions),
                'photo_path' => null, // В реальности здесь должны быть пути к фото
                'notes' => $faker->optional(0.7)->sentence(),
                'created_at' => $completionDate,
                'updated_at' => $completionDate,
            ]);
        }

        $this->command->line("  ✓ Организация {$organizationId}: создано {$recordsToCreate} записей");
    }
} 