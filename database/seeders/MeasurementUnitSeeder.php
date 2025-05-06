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

        DB::table('measurement_units')->insert([
            ['name' => 'Штука', 'code' => 'PCE', 'symbol' => 'шт.', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Килограмм', 'code' => 'KGM', 'symbol' => 'кг', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Метр', 'code' => 'MTR', 'symbol' => 'м', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Метр квадратный', 'code' => 'MTK', 'symbol' => 'м²', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Метр кубический', 'code' => 'MTQ', 'symbol' => 'м³', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Литр', 'code' => 'LTR', 'symbol' => 'л', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Тонна', 'code' => 'TNE', 'symbol' => 'т', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Упаковка', 'code' => 'PKG', 'symbol' => 'упак.', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
