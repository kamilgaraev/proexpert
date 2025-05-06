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
        $defaultOrganizationId = 12; // ПРЕДУПРЕЖДЕНИЕ: Укажите корректный ID организации, если он отличается

        DB::table('measurement_units')->insert([
            ['organization_id' => $defaultOrganizationId, 'name' => 'Штука', 'short_name' => 'шт.', 'created_at' => $now, 'updated_at' => $now],
            ['organization_id' => $defaultOrganizationId, 'name' => 'Килограмм', 'short_name' => 'кг', 'created_at' => $now, 'updated_at' => $now],
            ['organization_id' => $defaultOrganizationId, 'name' => 'Метр', 'short_name' => 'м', 'created_at' => $now, 'updated_at' => $now],
            ['organization_id' => $defaultOrganizationId, 'name' => 'Метр квадратный', 'short_name' => 'м²', 'created_at' => $now, 'updated_at' => $now],
            ['organization_id' => $defaultOrganizationId, 'name' => 'Метр кубический', 'short_name' => 'м³', 'created_at' => $now, 'updated_at' => $now],
            ['organization_id' => $defaultOrganizationId, 'name' => 'Литр', 'short_name' => 'л', 'created_at' => $now, 'updated_at' => $now],
            ['organization_id' => $defaultOrganizationId, 'name' => 'Тонна', 'short_name' => 'т', 'created_at' => $now, 'updated_at' => $now],
            ['organization_id' => $defaultOrganizationId, 'name' => 'Упаковка', 'short_name' => 'упак.', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
