<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('═══════════════════════════════════════════════════');
        $this->command->info('   ЗАПОЛНЕНИЕ БАЗЫ ДАННЫХ ТЕСТОВЫМИ ДАННЫМИ');
        $this->command->info('═══════════════════════════════════════════════════');
        $this->command->newLine();

        $this->seedSystemData();
        $this->seedBusinessData();
        $this->seedTestData();

        $this->command->newLine();
        $this->command->info('═══════════════════════════════════════════════════');
        $this->command->info('   ✓ ЗАПОЛНЕНИЕ ЗАВЕРШЕНО УСПЕШНО');
        $this->command->info('═══════════════════════════════════════════════════');
    }

    private function seedSystemData(): void
    {
        $this->command->info('┌─ СИСТЕМНЫЕ ДАННЫЕ');
        
        $this->command->info('│  ├─ Роли и права...');
        $this->call(RolePermissionSeeder::class);
        
        $this->command->info('│  ├─ Единицы измерения...');
        $this->call(MeasurementUnitSeeder::class);
        
        $this->command->info('│  └─ Тарифные планы...');
        $this->call(SubscriptionPlanSeeder::class);
        
        $this->command->newLine();
    }

    private function seedBusinessData(): void
    {
        $this->command->info('┌─ БИЗНЕС-ДАННЫЕ');
        
        $this->command->info('│  ├─ Базовые данные (организации, проекты, материалы)...');
        $this->call(BasicDataSeeder::class);
        
        $this->callIfExists('│  └─ Контракты...', ContractSeeder::class);
        
        $this->command->newLine();
    }

    private function seedTestData(): void
    {
        $this->command->info('┌─ ТЕСТОВЫЕ ДАННЫЕ (для демонстрации)');
        
        $this->command->info('│  ├─ Активность прорабов...');
        $this->call(ForemanActivitySeeder::class);
        
        $this->command->info('│  ├─ Логи использования материалов...');
        $this->call(MaterialUsageLogSeeder::class);
        
        $this->command->info('│  ├─ Логи выполненных работ...');
        $this->call(WorkCompletionLogSeeder::class);
        
        $this->command->info('│  ├─ Выполненные работы...');
        $this->call(CompletedWorkSeeder::class);
        
        $this->callIfExists('│  └─ Данные для официального отчета...', OfficialMaterialReportSeeder::class);
        
        $this->command->newLine();
    }

    private function callIfExists(string $message, string|array $seeders): void
    {
        $seeders = is_array($seeders) ? $seeders : [$seeders];
        
        foreach ($seeders as $seeder) {
            try {
                if (class_exists($seeder)) {
                    $this->command->info($message);
                    $this->call($seeder);
                }
            } catch (\Exception $e) {
                $this->command->warn("│  ⚠ Пропущен {$seeder}: " . $e->getMessage());
            }
        }
    }
}
