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
        $now = Carbon::now();
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

        for ($organizationId = 1; $organizationId <= 20; $organizationId++) {
            // Проверяем, существует ли организация с таким ID
            $organizationExists = DB::table('organizations')->where('id', $organizationId)->exists();

            if ($organizationExists) {
                $dataToInsert = [];
                foreach ($unitsData as $unit) {
                    $dataToInsert[] = [
                        'organization_id' => $organizationId,
                        'name' => $unit['name'],
                        'short_name' => $unit['short_name'],
                        // 'type' => 'material', // Будет использовано значение по умолчанию из миграции
                        // 'is_default' => false, // Будет использовано значение по умолчанию из миграции
                        // 'is_system' => false, // Будет использовано значение по умолчанию из миграции
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('measurement_units')->insert($dataToInsert);
                $this->command->info("Seeded measurement units for existing organization ID: {$organizationId}");
            } else {
                $this->command->info("Skipped measurement units for non-existing organization ID: {$organizationId}");
            }
        }
    }
}
