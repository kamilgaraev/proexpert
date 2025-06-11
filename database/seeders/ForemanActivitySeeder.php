<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Models\Log\WorkCompletionLog;
use App\Models\Models\Log\MaterialUsageLog;
use Faker\Factory as Faker;

class ForemanActivitySeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('ru_RU');
        
        // Получаем ID организации
        $organizationId = Organization::query()->inRandomOrder()->value('id');
        if (!$organizationId) {
            throw new \Exception('Для сидирования активности прорабов необходима хотя бы одна организация.');
        }

        // Получаем или создаем прорабов
        $foremanRole = Role::where('slug', Role::ROLE_FOREMAN)->first();
        if (!$foremanRole) {
            throw new \Exception('Роль прораба не найдена в базе данных.');
        }

        $foremen = User::whereHas('roles', function ($query) use ($foremanRole) {
            $query->where('role_id', $foremanRole->id);
        })->get();

        // Если нет прорабов, создаем тестовых
        if ($foremen->isEmpty()) {
            $foremanNames = [
                'Алексей Иванов',
                'Дмитрий Петров', 
                'Сергей Сидоров',
                'Михаил Козлов',
                'Андрей Смирнов'
            ];

            foreach ($foremanNames as $index => $name) {
                $foreman = User::create([
                    'name' => $name,
                    'email' => 'foreman' . ($index + 1) . '@test.com',
                    'password' => bcrypt('password'),
                    'current_organization_id' => $organizationId,
                    'user_type' => 'foreman',
                    'phone' => $faker->phoneNumber,
                    'email_verified_at' => now(),
                ]);
                
                $foreman->roles()->attach($foremanRole->id, [
                    'organization_id' => $organizationId
                ]);
                
                $foremen->push($foreman);
            }
        }

        // Генерируем активность за последние 30 дней
        $startDate = Carbon::now()->subDays(30);
        $endDate = Carbon::now();

        // Создаем реалистичную активность для каждого прораба
        foreach ($foremen as $foreman) {
            $this->generateForemanActivity($foreman, $startDate, $endDate, $faker);
        }

        $this->command->info('Создана активность для ' . $foremen->count() . ' прорабов за последние 30 дней');
    }

    private function generateForemanActivity(User $foreman, Carbon $startDate, Carbon $endDate, $faker): void
    {
        // Генерируем активность для каждого дня
        $currentDate = $startDate->copy();
        
        while ($currentDate <= $endDate) {
            // Пропускаем выходные иногда (80% вероятность работы в выходные)
            if ($currentDate->isWeekend() && $faker->boolean(20)) {
                $currentDate->addDay();
                continue;
            }

            // Вероятность активности в день (90% для рабочих дней, 30% для выходных)
            $activityChance = $currentDate->isWeekend() ? 30 : 90;
            
            if ($faker->boolean($activityChance)) {
                // Генерируем разное количество активности в зависимости от дня
                $activitiesCount = $currentDate->isWeekend() ? 
                    $faker->numberBetween(1, 3) : 
                    $faker->numberBetween(2, 8);

                for ($i = 0; $i < $activitiesCount; $i++) {
                    // Случайное время в течение рабочего дня (8:00 - 18:00)
                    $activityTime = $currentDate->copy()
                        ->setHour($faker->numberBetween(8, 18))
                        ->setMinute($faker->numberBetween(0, 59))
                        ->setSecond($faker->numberBetween(0, 59));

                    // 70% вероятность работ, 30% использования материалов
                    if ($faker->boolean(70)) {
                        $this->createWorkCompletionActivity($foreman, $activityTime, $faker);
                    } else {
                        $this->createMaterialUsageActivity($foreman, $activityTime, $faker);
                    }
                }
            }

            $currentDate->addDay();
        }
    }

    private function createWorkCompletionActivity(User $foreman, Carbon $activityTime, $faker): void
    {
        // Проверяем, есть ли уже логи выполненных работ для этого пользователя
        $existingLogCount = WorkCompletionLog::where('user_id', $foreman->id)->count();
        
        // Если логов мало, создаем новые
        if ($existingLogCount < 50) {
            // Эта логика уже реализована в WorkCompletionLogSeeder
            // Здесь мы можем добавить дополнительную логику или просто пропустить
        }
    }

    private function createMaterialUsageActivity(User $foreman, Carbon $activityTime, $faker): void
    {
        // Проверяем, есть ли уже логи использования материалов для этого пользователя
        $existingLogCount = MaterialUsageLog::where('user_id', $foreman->id)->count();
        
        // Если логов мало, создаем новые
        if ($existingLogCount < 30) {
            // Эта логика уже реализована в MaterialUsageLogSeeder
            // Здесь мы можем добавить дополнительную логику или просто пропустить
        }
    }
} 