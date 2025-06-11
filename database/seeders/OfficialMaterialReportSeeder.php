<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Carbon\Carbon;
use App\Models\Project;
use App\Models\Material;
use App\Models\Supplier;
use App\Models\WorkType;
use App\Models\MaterialReceipt;
use App\Models\Models\Log\MaterialUsageLog;
use App\Models\MeasurementUnit;

class OfficialMaterialReportSeeder extends Seeder
{
    public function run(): void
    {
        $faker = \Faker\Factory::create('ru_RU');
        
        // Создаем тестовый проект с полными договорными данными
        $project = Project::firstOrCreate(
            ['name' => 'Тестовый проект для официального отчета'],
            [
                'organization_id' => 1,
                'name' => 'Строительство ЖК "Солнечный"',
                'address' => 'г. Москва, Солнечногорский район, д. Поварово, ул. Центральная, участок 15',
                'description' => 'Строительство многоэтажного жилого комплекса',
                'start_date' => Carbon::now()->subMonths(6),
                'end_date' => Carbon::now()->addMonths(12),
                'status' => 'active',
                'customer' => 'ООО "НЕО СТРОЙ"',
                'customer_organization' => 'ООО "НЕО СТРОЙ"',
                'customer_representative' => 'Шарафутдинов Александр Александрович',
                'contract_number' => 'ПД-156/2025',
                'contract_date' => Carbon::create(2025, 1, 15),
                'designer' => 'ООО "ПроектСтрой"',
            ]
        );

        // Создаем единицы измерения если их нет
        $units = [
            ['name' => 'кубический метр', 'short_name' => 'м³', 'type' => 'volume'],
            ['name' => 'штуки', 'short_name' => 'шт', 'type' => 'piece'],
            ['name' => 'килограмм', 'short_name' => 'кг', 'type' => 'weight'],
            ['name' => 'квадратный метр', 'short_name' => 'м²', 'type' => 'area'],
            ['name' => 'метр', 'short_name' => 'м', 'type' => 'length'],
        ];

        foreach ($units as $unitData) {
            MeasurementUnit::firstOrCreate(
                ['short_name' => $unitData['short_name'], 'organization_id' => 1],
                array_merge($unitData, ['organization_id' => 1])
            );
        }

        // Создаем поставщиков
        $suppliers = [
            'ООО "БетонСервис"' => '+7(495)123-45-67',
            'ООО "СтройМатериалы Плюс"' => '+7(495)234-56-78',
            'ТД "АрматураТорг"' => '+7(495)345-67-89',
        ];

        foreach ($suppliers as $name => $phone) {
            Supplier::firstOrCreate(
                ['name' => $name, 'organization_id' => 1],
                [
                    'organization_id' => 1,
                    'contact_person' => $faker->name,
                    'phone' => $phone,
                    'email' => $faker->companyEmail,
                    'address' => $faker->address,
                ]
            );
        }

        // Создаем виды работ
        $workTypes = [
            'Устройство бетонной подготовки',
            'Армирование фундаментов',
            'Кладочные работы наружных стен',
            'Монтаж кровельных материалов',
            'Отделочные работы',
        ];

        $measurementUnits = MeasurementUnit::where('organization_id', 1)->pluck('id', 'short_name');

        foreach ($workTypes as $workTypeName) {
            WorkType::firstOrCreate(
                ['name' => $workTypeName, 'organization_id' => 1],
                [
                    'organization_id' => 1,
                    'description' => 'Выполнение работ: ' . $workTypeName,
                    'measurement_unit_id' => $measurementUnits['м²'] ?? $measurementUnits->first(),
                    'default_price' => $faker->randomFloat(2, 1000, 5000),
                ]
            );
        }

        // Создаем материалы для отчета
        $materials = [
            ['name' => 'Бетон В15 М200', 'unit' => 'м³', 'price' => 3500.00],
            ['name' => 'Арматура А500С d12', 'unit' => 'кг', 'price' => 45.00],
            ['name' => 'Кирпич керамический рядовой', 'unit' => 'шт', 'price' => 8.50],
            ['name' => 'Металлочерепица "Монтеррей"', 'unit' => 'м²', 'price' => 450.00],
            ['name' => 'Утеплитель базальтовый 100мм', 'unit' => 'м²', 'price' => 280.00],
        ];

        $createdMaterials = [];
        foreach ($materials as $materialData) {
            $unitId = $measurementUnits[$materialData['unit']] ?? $measurementUnits->first();
            
            $material = Material::firstOrCreate(
                ['name' => $materialData['name'], 'organization_id' => 1],
                [
                    'organization_id' => 1,
                    'description' => 'Строительный материал: ' . $materialData['name'],
                    'measurement_unit_id' => $unitId,
                    'default_price' => $materialData['price'],
                    'category' => 'Основные материалы',
                ]
            );
            $createdMaterials[] = $material;
        }

        // Получаем созданные объекты
        $supplierIds = Supplier::where('organization_id', 1)->pluck('id')->toArray();
        $workTypeIds = WorkType::where('organization_id', 1)->pluck('id')->toArray();

        // СОЗДАЕМ ПРИХОДЫ МАТЕРИАЛОВ (за предыдущие месяцы)
        $receipts = [
            [
                'material' => $createdMaterials[0], // Бетон
                'quantity' => 150.0,
                'price' => 3500.00,
                'document' => 'ТТН-001',
                'date' => Carbon::create(2025, 4, 15),
                'supplier_name' => 'ООО "БетонСервис"'
            ],
            [
                'material' => $createdMaterials[1], // Арматура
                'quantity' => 2500.0,
                'price' => 45.00,
                'document' => 'ТТН-002',
                'date' => Carbon::create(2025, 4, 20),
                'supplier_name' => 'ТД "АрматураТорг"'
            ],
            [
                'material' => $createdMaterials[2], // Кирпич
                'quantity' => 10000.0,
                'price' => 8.50,
                'document' => 'ТТН-003',
                'date' => Carbon::create(2025, 5, 5),
                'supplier_name' => 'ООО "СтройМатериалы Плюс"'
            ],
            [
                'material' => $createdMaterials[3], // Металлочерепица
                'quantity' => 500.0,
                'price' => 450.00,
                'document' => 'ТТН-004',
                'date' => Carbon::create(2025, 5, 10),
                'supplier_name' => 'ООО "СтройМатериалы Плюс"'
            ],
            [
                'material' => $createdMaterials[4], // Утеплитель
                'quantity' => 800.0,
                'price' => 280.00,
                'document' => 'ТТН-005',
                'date' => Carbon::create(2025, 5, 15),
                'supplier_name' => 'ООО "СтройМатериалы Плюс"'
            ],
        ];

        foreach ($receipts as $receiptData) {
            $supplier = Supplier::where('name', $receiptData['supplier_name'])->first();
            
            MaterialReceipt::create([
                'organization_id' => 1,
                'project_id' => $project->id,
                'supplier_id' => $supplier->id,
                'material_id' => $receiptData['material']->id,
                'user_id' => 1, // Предполагаем что есть пользователь с ID 1
                'quantity' => $receiptData['quantity'],
                'price' => $receiptData['price'],
                'total_amount' => $receiptData['quantity'] * $receiptData['price'],
                'document_number' => $receiptData['document'],
                'receipt_date' => $receiptData['date'],
                'notes' => 'Поступление материалов от поставщика',
                'status' => 'confirmed',
            ]);

            // Создаем лог прихода
            MaterialUsageLog::create([
                'project_id' => $project->id,
                'material_id' => $receiptData['material']->id,
                'user_id' => 1,
                'organization_id' => 1,
                'operation_type' => 'receipt',
                'quantity' => $receiptData['quantity'],
                'unit_price' => $receiptData['price'],
                'total_price' => $receiptData['quantity'] * $receiptData['price'],
                'supplier_id' => $supplier->id,
                'document_number' => $receiptData['document'],
                'invoice_date' => $receiptData['date'],
                'usage_date' => $receiptData['date'],
                'notes' => 'Поступление от поставщика',
                'receipt_document_reference' => "№{$receiptData['document']} от " . $receiptData['date']->format('d.m.Y'),
            ]);
        }

        // СОЗДАЕМ СПИСАНИЯ МАТЕРИАЛОВ (за май 2025)
        $usages = [
            [
                'material' => $createdMaterials[0], // Бетон
                'work_description' => 'Устройство бетонной подготовки',
                'quantity' => 47.0,
                'production_norm' => 50.0, // Норма больше факта = экономия
                'previous_balance' => 0.0,
                'date' => Carbon::create(2025, 5, 20),
            ],
            [
                'material' => $createdMaterials[0], // Бетон (вторая операция)
                'work_description' => 'Устройство бетонной подготовки',
                'quantity' => 23.0,
                'production_norm' => 22.0, // Норма меньше факта = перерасход
                'previous_balance' => 103.0, // 150 - 47
                'date' => Carbon::create(2025, 5, 25),
            ],
            [
                'material' => $createdMaterials[1], // Арматура
                'work_description' => 'Армирование фундаментов',
                'quantity' => 1200.0,
                'production_norm' => 1250.0,
                'previous_balance' => 0.0,
                'date' => Carbon::create(2025, 5, 22),
            ],
            [
                'material' => $createdMaterials[2], // Кирпич
                'work_description' => 'Кладочные работы наружных стен',
                'quantity' => 8500.0,
                'production_norm' => 8500.0, // Точно по норме
                'previous_balance' => 0.0,
                'date' => Carbon::create(2025, 5, 28),
            ],
            [
                'material' => $createdMaterials[3], // Металлочерепица
                'work_description' => 'Монтаж кровельных материалов',
                'quantity' => 420.0,
                'production_norm' => 450.0,
                'previous_balance' => 0.0,
                'date' => Carbon::create(2025, 5, 30),
            ],
        ];

        foreach ($usages as $usageData) {
            $workType = WorkType::where('name', $usageData['work_description'])->first();
            $currentBalance = $usageData['previous_balance'] + ($receipts[array_search($usageData['material'], array_column($receipts, 'material'))] ?? ['quantity' => 0])['quantity'] - $usageData['quantity'];
            
            MaterialUsageLog::create([
                'project_id' => $project->id,
                'material_id' => $usageData['material']->id,
                'user_id' => 1,
                'organization_id' => 1,
                'operation_type' => 'write_off',
                'quantity' => $usageData['quantity'],
                'production_norm_quantity' => $usageData['production_norm'],
                'fact_quantity' => $usageData['quantity'],
                'previous_month_balance' => $usageData['previous_balance'],
                'current_balance' => max(0, $currentBalance),
                'unit_price' => $usageData['material']->default_price,
                'total_price' => $usageData['quantity'] * $usageData['material']->default_price,
                'usage_date' => $usageData['date'],
                'notes' => 'Списание на производство работ',
                'work_type_id' => $workType->id ?? null,
                'work_description' => $usageData['work_description'],
            ]);
        }

        $this->command->info('✅ Создан тестовый проект для официального отчета:');
        $this->command->info("   Проект: {$project->name} (ID: {$project->id})");
        $this->command->info('   Период тестирования: 01.05.2025 - 31.05.2025');
        $this->command->info('   Материалов: ' . count($createdMaterials));
        $this->command->info('   Приходов: ' . count($receipts));
        $this->command->info('   Списаний: ' . count($usages));
        $this->command->info('');
        $this->command->info('🔗 Тестовый URL:');
        $this->command->info("   GET /api/v1/admin/reports/official-material-usage?project_id={$project->id}&date_from=2025-05-01&date_to=2025-05-31&format=xlsx");
    }
} 