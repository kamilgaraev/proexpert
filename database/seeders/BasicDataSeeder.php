<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Material;
use App\Models\WorkType;
use App\Models\MeasurementUnit;
use App\Models\User;
use App\Models\Role;
use App\Models\Supplier;
use Faker\Factory as Faker;

class BasicDataSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('ru_RU');
        
        $organizations = Organization::all();
        if ($organizations->isEmpty()) {
            $this->command->warn('Нет организаций в базе данных. Создаю тестовую организацию...');
            $organization = Organization::create([
                'name' => 'Тестовая организация',
                'email' => 'test@example.com',
                'is_active' => true,
            ]);
            $organizations = collect([$organization]);
        }

        $this->command->info("Создание базовых данных для {$organizations->count()} организаций...");
        
        foreach ($organizations as $organization) {
            $this->seedForOrganization($organization, $faker);
        }
    }

    private function seedForOrganization(Organization $organization, $faker): void
    {
        // Получаем единицы измерения (создаются в MeasurementUnitSeeder)
        $measurementUnits = MeasurementUnit::where('organization_id', $organization->id)->pluck('id', 'short_name');
        
        if ($measurementUnits->isEmpty()) {
            $this->command->line("  ⊳ {$organization->name} (ID: {$organization->id}): пропущено (нет единиц измерения)");
            return;
        }

        // Создаем поставщиков
        $suppliers = [
            'ООО "СтройМатериалы"',
            'ТД "Бетон и Ко"',
            'ИП Петров А.В.',
            'ООО "МегаСтрой"',
            'ТД "Стройбаза"'
        ];

        foreach ($suppliers as $supplierName) {
            Supplier::firstOrCreate(
                ['name' => $supplierName, 'organization_id' => $organization->id],
                [
                    'contact_person' => $faker->name,
                    'phone' => $faker->phoneNumber,
                    'email' => $faker->companyEmail,
                    'address' => $faker->address,
                ]
            );
        }

        // Создаем проекты (ИСПРАВЛЕНО: правильные статусы)
        $projects = [
            'Строительство жилого комплекса "Солнечный"',
            'Реконструкция офисного центра',
            'Строительство торгового центра',
            'Частный дом в коттеджном поселке',
            'Промышленный склад'
        ];

        foreach ($projects as $projectName) {
            Project::firstOrCreate(
                ['name' => $projectName, 'organization_id' => $organization->id],
                [
                    'organization_id' => $organization->id,
                    'description' => 'Описание проекта: ' . $projectName,
                    'start_date' => $faker->dateTimeBetween('-6 months', '-3 months'),
                    'end_date' => $faker->dateTimeBetween('+3 months', '+12 months'),
                    'status' => $faker->randomElement(['active', 'completed', 'paused', 'cancelled']),
                    'address' => $faker->address,
                    'customer' => $faker->company,
                    'designer' => $faker->optional(0.7)->name,
                    'customer_organization' => 'ООО "НЕО СТРОЙ"',
                    'customer_representative' => 'Шарафутдинов А.А.',
                    'contract_number' => 'ПД-' . $faker->numberBetween(100, 999) . '/2025',
                    'contract_date' => $faker->dateTimeBetween('-12 months', '-6 months'),
                ]
            );
        }

        // Создаем виды работ
        $workTypes = [
            'Земляные работы',
            'Фундаментные работы',
            'Кладочные работы',
            'Бетонные работы',
            'Арматурные работы',
            'Кровельные работы',
            'Штукатурные работы',
            'Малярные работы',
            'Электромонтажные работы',
            'Сантехнические работы',
            'Отделочные работы',
            'Монтаж окон и дверей'
        ];

        foreach ($workTypes as $workTypeName) {
            $unit = $faker->randomElement(['м²', 'м³', 'м', 'шт']);
            $unitId = $measurementUnits[$unit] ?? $measurementUnits->first();

            WorkType::firstOrCreate(
                ['name' => $workTypeName, 'organization_id' => $organization->id],
                [
                    'organization_id' => $organization->id, // ИСПРАВЛЕНО
                    'description' => 'Выполнение работ: ' . $workTypeName,
                    'measurement_unit_id' => $unitId,
                    'default_price' => $faker->randomFloat(2, 500, 5000), // ИСПРАВЛЕНО: base_price -> default_price
                ]
            );
        }

        // Создаем материалы (ИСПРАВЛЕНО: правильные поля)
        $materials = [
            ['name' => 'Цемент М400', 'unit' => 'кг', 'price' => 15.50],
            ['name' => 'Песок строительный', 'unit' => 'т', 'price' => 1200.00],
            ['name' => 'Щебень фракция 5-20', 'unit' => 'т', 'price' => 1500.00],
            ['name' => 'Кирпич красный', 'unit' => 'шт', 'price' => 25.00],
            ['name' => 'Арматура 12мм', 'unit' => 'м', 'price' => 45.00],
            ['name' => 'Доска обрезная 50x150', 'unit' => 'м', 'price' => 280.00],
            ['name' => 'Гипсокартон 12.5мм', 'unit' => 'м²', 'price' => 320.00],
            ['name' => 'Утеплитель минвата', 'unit' => 'м²', 'price' => 150.00],
            ['name' => 'Металлочерепица', 'unit' => 'м²', 'price' => 450.00],
            ['name' => 'Краска водоэмульсионная', 'unit' => 'кг', 'price' => 180.00],
        ];

        foreach ($materials as $materialData) {
            $unitId = $measurementUnits[$materialData['unit']] ?? $measurementUnits->first();
            
            Material::firstOrCreate(
                ['name' => $materialData['name'], 'organization_id' => $organization->id],
                [
                    'organization_id' => $organization->id, // ИСПРАВЛЕНО
                    'description' => 'Строительный материал: ' . $materialData['name'],
                    'measurement_unit_id' => $unitId,
                    'default_price' => $materialData['price'], // ИСПРАВЛЕНО: price -> default_price
                ]
            );
        }

        $projectsCount = Project::where('organization_id', $organization->id)->count();
        $workTypesCount = WorkType::where('organization_id', $organization->id)->count();
        $materialsCount = Material::where('organization_id', $organization->id)->count();
        $suppliersCount = Supplier::where('organization_id', $organization->id)->count();
        
        $this->command->line("  ✓ {$organization->name} (ID: {$organization->id}): проектов {$projectsCount}, работ {$workTypesCount}, материалов {$materialsCount}, поставщиков {$suppliersCount}");
    }
} 