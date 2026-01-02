<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\MeasurementUnit;

class MeasurementUnitSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Список единиц измерения берем из модели
        $unitsData = MeasurementUnit::getDefaultUnits();

        // Получаем все организации
        $organizations = DB::table('organizations')->pluck('id');
        
        if ($organizations->isEmpty()) {
            $this->command->warn('Нет организаций в базе данных. Пропускаем создание единиц измерения.');
            return;
        }

        $this->command->info("Обработка единиц измерения для {$organizations->count()} организаций...");
        
        $totalCreated = 0;
        $totalUpdated = 0;
        $now = Carbon::now();
        
        foreach ($organizations as $organizationId) {
            foreach ($unitsData as $unit) {
                // Ищем по short_name внутри организации
                $exists = DB::table('measurement_units')
                    ->where('organization_id', $organizationId)
                    ->where('short_name', $unit['short_name'])
                    ->first();

                if ($exists) {
                    // Обновляем существующую запись
                    DB::table('measurement_units')
                        ->where('id', $exists->id)
                        ->update([
                            'name' => $unit['name'],
                            'type' => $unit['type'],
                            // 'is_system' => true, // Раскомментируйте, если хотите сделать их системными
                            'updated_at' => $now,
                        ]);
                    $totalUpdated++;
                } else {
                    // Создаем новую запись
                    DB::table('measurement_units')->insert([
                        'organization_id' => $organizationId,
                        'short_name' => $unit['short_name'],
                        'name' => $unit['name'],
                        'type' => $unit['type'],
                        'is_system' => true, // Новые базовые единицы помечаем как системные
                        'is_default' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $totalCreated++;
                }
            }
        }
        
        $this->command->info("✓ Обработано организаций: {$organizations->count()}");
        $this->command->info("✓ Создано новых единиц: {$totalCreated}");
        $this->command->info("✓ Обновлено существующих: {$totalUpdated}");
    }
}
