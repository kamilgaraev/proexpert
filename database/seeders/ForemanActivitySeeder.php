<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Organization;
use App\Models\Models\Log\WorkCompletionLog;
use App\Models\Models\Log\MaterialUsageLog;
use Faker\Factory as Faker;

class ForemanActivitySeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('ru_RU');

        $organizations = Organization::pluck('id');
        if ($organizations->isEmpty()) {
            $this->command->warn('Нет организаций в базе данных. Пропускаем создание активности прорабов.');
            return;
        }

        $this->command->info("Создание активности прорабов для {$organizations->count()} организаций...");
        
        foreach ($organizations as $organizationId) {
            $this->seedForOrganization($organizationId, $faker);
        }
    }

    private function seedForOrganization(int $organizationId, $faker): void
    {
        // Получаем или создаем прорабов через новую систему авторизации
        $foremen = User::whereHas('roleAssignments', function ($query) {
            $query->where('role_slug', 'foreman')
                  ->where('is_active', true);
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

            $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organizationId);
            
            foreach ($foremanNames as $index => $name) {
                $email = 'foreman' . ($index + 1) . '@test.com';
                
                $foreman = User::firstOrCreate(
                    ['email' => $email],
                    [
                        'name' => $name,
                        'password' => bcrypt('password'),
                        'current_organization_id' => $organizationId,
                        'phone' => $faker->phoneNumber,
                        'email_verified_at' => now(),
                    ]
                );
                
                // Назначаем роль через новую систему
                if (!$foreman->hasRole('foreman', $context->id)) {
                    \App\Domain\Authorization\Models\UserRoleAssignment::create([
                        'user_id' => $foreman->id,
                        'role_slug' => 'foreman',
                        'role_type' => \App\Domain\Authorization\Models\UserRoleAssignment::TYPE_SYSTEM,
                        'context_id' => $context->id,
                        'is_active' => true,
                    ]);
                }
                
                $foremen->push($foreman);
            }
        }

        // Проверяем, есть ли уже активность за последние 30 дней
        $startDate = Carbon::now()->subDays(30);
        $endDate = Carbon::now();
        
        $existingActivityCount = MaterialUsageLog::whereBetween('usage_date', [$startDate, $endDate])
            ->whereIn('user_id', $foremen->pluck('id'))
            ->count();
        
        if ($existingActivityCount > 100) {
            $this->command->line("  ⊳ Организация {$organizationId}: пропущено (уже {$existingActivityCount} записей)");
            return;
        }
        
        foreach ($foremen as $foreman) {
            $this->generateForemanActivity($foreman, $startDate, $endDate, $faker);
        }

        $this->command->line("  ✓ Организация {$organizationId}: создана активность для {$foremen->count()} прорабов");
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