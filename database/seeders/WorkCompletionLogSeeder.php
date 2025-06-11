<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Carbon\Carbon;
use App\Models\Models\Log\WorkCompletionLog;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use App\Models\Organization;
use App\Models\Role;
use Faker\Factory as Faker;

class WorkCompletionLogSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('ru_RU');
        
        // Получаем ID организации
        $organizationId = Organization::query()->inRandomOrder()->value('id');
        if (!$organizationId) {
            throw new \Exception('Для сидирования work_completion_logs необходима хотя бы одна организация.');
        }

        // Получаем прорабов (пользователей с ролью foreman)
        $foremanRole = Role::where('slug', Role::ROLE_FOREMAN)->first();
        if (!$foremanRole) {
            throw new \Exception('Роль прораба не найдена в базе данных.');
        }

        $foremenIds = User::whereHas('roles', function ($query) use ($foremanRole) {
            $query->where('role_id', $foremanRole->id);
        })->pluck('id')->toArray();

        if (empty($foremenIds)) {
            // Создаем тестового прораба если нет
            $testForeman = User::create([
                'name' => 'Тестовый Прораб',
                'email' => 'foreman@test.com',
                'password' => bcrypt('password'),
                'current_organization_id' => $organizationId,
                'user_type' => 'foreman',
                'phone' => $faker->phoneNumber,
                'email_verified_at' => now(),
            ]);
            
            $testForeman->roles()->attach($foremanRole->id, [
                'organization_id' => $organizationId
            ]);
            
            $foremenIds = [$testForeman->id];
        }

        // Получаем проекты и виды работ
        $projectIds = Project::where('organization_id', $organizationId)->pluck('id')->toArray();
        $workTypeIds = WorkType::pluck('id')->toArray();

        if (empty($projectIds) || empty($workTypeIds)) {
            throw new \Exception('Для сидирования work_completion_logs необходимы проекты и виды работ.');
        }

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

        // Создаем 200 записей для демонстрации активности
        foreach (range(1, 200) as $i) {
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

        $this->command->info('Создано 200 записей логов выполненных работ');
    }
} 