<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\Models\Log\MaterialUsageLog;
use App\Models\Project;
use App\Models\User;
use App\Models\Material;
use App\Models\Supplier;
use App\Models\WorkType;
use App\Models\Organization;

class MaterialUsageLogSeeder extends Seeder
{
    public function run(): void
    {
        $faker = \Faker\Factory::create('ru_RU');
        
        $organizationId = Organization::query()->inRandomOrder()->value('id');
        if (!$organizationId) {
            throw new \Exception('Для сидирования material_usage_logs необходима хотя бы одна организация.');
        }

        $existingCount = MaterialUsageLog::where('organization_id', $organizationId)->count();
        if ($existingCount >= 100) {
            $this->command->info("Пропускаем создание логов материалов. Уже существует {$existingCount} записей для организации {$organizationId}");
            return;
        }
        
        $recordsToCreate = 100 - $existingCount;
        $this->command->info("Создаем {$recordsToCreate} записей логов материалов...");

        // Получаем прорабов через новую систему авторизации
        $userIds = User::whereHas('roleAssignments', function ($query) {
            $query->where('role_slug', 'foreman')
                  ->where('is_active', true);
        })->pluck('id')->toArray();
        
        if (empty($userIds)) {
            $userIds = User::where('current_organization_id', $organizationId)->pluck('id')->toArray();
        }
        
        if (empty($userIds)) {
            throw new \Exception('В организации нет пользователей для привязки логов материалов.');
        }

        $projectIds = Project::where('organization_id', $organizationId)->pluck('id')->toArray();
        $materialIds = Material::where('organization_id', $organizationId)->pluck('id')->toArray();
        $supplierIds = Supplier::where('organization_id', $organizationId)->pluck('id')->toArray();
        $workTypeIds = WorkType::where('organization_id', $organizationId)->pluck('id')->toArray();

        if (empty($projectIds) || empty($materialIds)) {
            $this->command->warn("Пропускаем создание логов материалов. Нет проектов или материалов для организации {$organizationId}");
            return;
        }

        // Создаем больше записей для демонстрации активности
        foreach (range(1, $recordsToCreate) as $i) {
            $operationType = $i % 2 === 0 ? 'write_off' : 'receipt';
            $quantity = rand(1, 100) + rand(0, 999) / 1000;
            $productionNormQuantity = $quantity * (0.95 + rand(0, 10) / 100); // ±5% от количества
            $factQuantity = $quantity;
            $unitPrice = rand(100, 1000) + rand(0, 99) / 100;
            $totalPrice = $quantity * $unitPrice;
            $usageDate = Carbon::now()->subDays(rand(0, 30));
            $invoiceDate = $operationType === 'receipt' ? $usageDate->copy()->subDays(rand(0, 5)) : null;
            $documentNumber = $operationType === 'receipt' ? Str::upper(Str::random(8)) : null;

            MaterialUsageLog::create([
                'project_id' => $faker->randomElement($projectIds),
                'material_id' => $faker->randomElement($materialIds),
                'user_id' => $faker->randomElement($userIds),
                'organization_id' => $organizationId,
                'operation_type' => $operationType,
                'quantity' => $quantity,
                'production_norm_quantity' => $productionNormQuantity,
                'fact_quantity' => $factQuantity,
                'previous_month_balance' => $operationType === 'write_off' ? rand(0, 50) : null,
                'current_balance' => $operationType === 'write_off' ? rand(0, 20) : $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
                'supplier_id' => $operationType === 'receipt' && !empty($supplierIds) ? $faker->randomElement($supplierIds) : null,
                'document_number' => $documentNumber,
                'invoice_date' => $invoiceDate,
                'usage_date' => $usageDate,
                'photo_path' => null,
                'notes' => $operationType === 'write_off' ? 'Списание на работы' : 'Поступление от поставщика',
                'work_type_id' => $operationType === 'write_off' && !empty($workTypeIds) ? $faker->randomElement($workTypeIds) : null,
                'work_description' => $operationType === 'write_off' ? $faker->randomElement([
                    'Устройство бетонной подготовки',
                    'Армирование фундаментных работ', 
                    'Кладочные работы наружных стен',
                    'Монтаж кровельных материалов',
                    'Отделочные работы'
                ]) : null,
                'receipt_document_reference' => $operationType === 'receipt' ? "№{$documentNumber} от " . $usageDate->format('d.m.Y') : null,
            ]);
        }

        $this->command->info('Создано 100 записей логов использования материалов');
    }
} 