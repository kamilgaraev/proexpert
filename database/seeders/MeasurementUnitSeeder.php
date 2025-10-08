<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MeasurementUnitSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $unitsData = [
            ['name' => 'Штука', 'short_name' => 'шт.'],
            ['name' => 'Килограмм', 'short_name' => 'кг'],
            ['name' => 'Метр', 'short_name' => 'м'],
            ['name' => 'Метр квадратный', 'short_name' => 'м²'],
            ['name' => 'Метр кубический', 'short_name' => 'м³'],
            ['name' => 'Литр', 'short_name' => 'л'],
            ['name' => 'Тонна', 'short_name' => 'т'],
            ['name' => 'Упаковка', 'short_name' => 'упак.'],
        ];

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
                $exists = DB::table('measurement_units')
                    ->where('organization_id', $organizationId)
                    ->where('short_name', $unit['short_name'])
                    ->exists();

                DB::table('measurement_units')->updateOrInsert(
                    [
                        'organization_id' => $organizationId,
                        'short_name' => $unit['short_name'],
                    ],
                    [
                        'name' => $unit['name'],
                        'updated_at' => $now,
                        'created_at' => DB::raw('COALESCE(created_at, "' . $now->toDateTimeString() . '")'),
                    ]
                );

                if ($exists) {
                    $totalUpdated++;
                } else {
                    $totalCreated++;
                }
            }
        }
        
        $this->command->info("✓ Обработано организаций: {$organizations->count()}");
        $this->command->info("✓ Создано новых единиц: {$totalCreated}");
        $this->command->info("✓ Обновлено существующих: {$totalUpdated}");
    }
}
