<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Заполнение базы тестовыми данными');

        $this->seedSystemData();
        $this->seedBusinessData();
        $this->seedTestData();

        $this->command->info('Заполнение завершено');
    }

    private function seedSystemData(): void
    {
        $this->command->info('Системные данные');
        $this->command->info('Единицы измерения...');
        $this->call(MeasurementUnitSeeder::class);
        $this->command->newLine();
    }

    private function seedBusinessData(): void
    {
        $this->command->info('Бизнес-данные');
        $this->command->info('Базовые данные...');
        $this->call(BasicDataSeeder::class);
        $this->callIfExists('Контракты...', ContractSeeder::class);
        $this->command->newLine();
    }

    private function seedTestData(): void
    {
        $this->command->info('Тестовые данные');
        $this->command->info('Активность прорабов...');
        $this->call(ForemanActivitySeeder::class);
        $this->command->info('Логи использования материалов...');
        $this->call(MaterialUsageLogSeeder::class);
        $this->command->info('Логи выполненных работ...');
        $this->call(WorkCompletionLogSeeder::class);
        $this->command->info('Выполненные работы...');
        $this->call(CompletedWorkSeeder::class);
        $this->callIfExists('Данные для официального отчета...', OfficialMaterialReportSeeder::class);
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
                $this->command->warn("Пропущен {$seeder}: " . $e->getMessage());
            }
        }
    }
}
