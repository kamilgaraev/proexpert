<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Carbon\Carbon;
use App\Models\CompletedWork;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use App\Models\Organization;
use App\Models\Contract;
use Faker\Factory as Faker;

class CompletedWorkSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('ru_RU');
        
        // Получаем ID организации
        $organizationId = Organization::query()->inRandomOrder()->value('id');
        if (!$organizationId) {
            throw new \Exception('Для сидирования completed_works необходима хотя бы одна организация.');
        }

        // Получаем прорабов через новую систему авторизации
        $foremenIds = User::whereHas('roleAssignments', function ($query) {
            $query->where('role_slug', 'foreman')
                  ->where('is_active', true);
        })->pluck('id')->toArray();

        if (empty($foremenIds)) {
            // Используем любого пользователя из организации
            $foremenIds = User::where('current_organization_id', $organizationId)->pluck('id')->toArray();
            if (empty($foremenIds)) {
                throw new \Exception('В организации нет пользователей для привязки выполненных работ.');
            }
        }

        // Получаем проекты, виды работ и контракты
        $projectIds = Project::where('organization_id', $organizationId)->pluck('id')->toArray();
        $workTypeIds = WorkType::where('organization_id', $organizationId)->pluck('id')->toArray();
        $contractIds = Contract::where('organization_id', $organizationId)->pluck('id')->toArray();

        if (empty($projectIds) || empty($workTypeIds)) {
            $this->command->warn("Пропускаем создание выполненных работ. Нет проектов или видов работ для организации {$organizationId}");
            return;
        }

        // Проверяем существующие записи
        $existingCount = CompletedWork::whereIn('project_id', $projectIds)->count();
        if ($existingCount >= 150) {
            $this->command->info("Пропускаем создание выполненных работ. Уже существует {$existingCount} записей");
            return;
        }

        $recordsToCreate = 150 - $existingCount;
        $this->command->info("Создаем {$recordsToCreate} записей выполненных работ...");

        // Статусы выполненных работ
        $statuses = ['pending', 'approved', 'rejected', 'in_review'];
        
        // Генерируем записи за последние 2 месяца
        $startDate = Carbon::now()->subMonths(2);
        $endDate = Carbon::now();

        // Создаем записи выполненных работ
        foreach (range(1, $recordsToCreate) as $i) {
            $completionDate = $faker->dateTimeBetween($startDate, $endDate);
            $quantity = $faker->randomFloat(3, 0.5, 200);
            $price = $faker->randomFloat(2, 300, 8000);
            $totalAmount = $quantity * $price;

            $notes = $faker->optional(0.6)->paragraph();
            
            // Дополнительная информация в формате JSON
            $additionalInfo = [
                'weather_conditions' => $faker->randomElement(['солнечно', 'дождь', 'облачно', 'снег']),
                'team_size' => $faker->numberBetween(2, 8),
                'equipment_used' => $faker->randomElements([
                    'Бетономешалка', 'Кран', 'Перфоратор', 'Болгарка', 
                    'Сварочный аппарат', 'Экскаватор', 'Вибратор'
                ], $faker->numberBetween(1, 3)),
                'quality_rating' => $faker->numberBetween(3, 5)
            ];

            CompletedWork::create([
                'organization_id' => $organizationId,
                'project_id' => $faker->randomElement($projectIds),
                'contract_id' => $faker->optional(0.8)->randomElement($contractIds),
                'work_type_id' => $faker->randomElement($workTypeIds),
                'user_id' => $faker->randomElement($foremenIds),
                'quantity' => $quantity,
                'price' => $price,
                'total_amount' => $totalAmount,
                'completion_date' => $completionDate,
                'notes' => $notes,
                'status' => $faker->randomElement($statuses),
                'additional_info' => $additionalInfo,
                'created_at' => $completionDate,
                'updated_at' => $completionDate,
            ]);
        }

        $this->command->info("✓ Создано {$recordsToCreate} записей выполненных работ");
    }
} 