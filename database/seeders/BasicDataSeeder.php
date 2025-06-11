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
        
        // Создаем или находим организацию
        $organization = Organization::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Тестовая организация',
                'email' => 'test@example.com',
                'is_active' => true,
            ]
        );

        // Создаем единицы измерения если их нет
        $units = [
            ['name' => 'штуки', 'short_name' => 'шт', 'type' => 'piece'],
            ['name' => 'квадратный метр', 'short_name' => 'м²', 'type' => 'area'],
            ['name' => 'кубический метр', 'short_name' => 'м³', 'type' => 'volume'],
            ['name' => 'метр', 'short_name' => 'м', 'type' => 'length'],
            ['name' => 'килограмм', 'short_name' => 'кг', 'type' => 'weight'],
            ['name' => 'тонна', 'short_name' => 'т', 'type' => 'weight'],
        ];

        foreach ($units as $unitData) {
            MeasurementUnit::firstOrCreate(
                ['short_name' => $unitData['short_name'], 'organization_id' => $organization->id],
                array_merge($unitData, ['organization_id' => $organization->id])
            );
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

        // Получаем единицы измерения для дальнейшего использования
        $measurementUnits = MeasurementUnit::where('organization_id', $organization->id)->pluck('id', 'short_name');

        // Создаем виды работ (ИСПРАВЛЕНО: добавлена organization_id)
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

        $this->command->info('Созданы базовые данные:');
        $this->command->info('- Используется организация: ' . $organization->name . ' (ID: ' . $organization->id . ')');
        $this->command->info('- Проектов: ' . Project::count());
        $this->command->info('- Видов работ: ' . WorkType::count());
        $this->command->info('- Материалов: ' . Material::count());
        $this->command->info('- Поставщиков: ' . Supplier::count());
    }
} 