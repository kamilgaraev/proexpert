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

        // Получаем прорабов
        $foremanRole = \App\Models\Role::where('slug', \App\Models\Role::ROLE_FOREMAN)->first();
        $userIds = [];
        
        if ($foremanRole) {
            $userIds = User::whereHas('roles', function ($query) use ($foremanRole) {
                $query->where('role_id', $foremanRole->id);
            })->pluck('id')->toArray();
        }
        
        if (empty($userIds)) {
            $userIds = User::where('current_organization_id', $organizationId)->pluck('id')->toArray();
        }
        
        if (empty($userIds)) {
            throw new \Exception('В организации нет пользователей для привязки логов материалов.');
        }

        $projectIds = Project::where('organization_id', $organizationId)->pluck('id')->toArray();
        $materialIds = Material::pluck('id')->toArray();
        $supplierIds = Supplier::pluck('id')->toArray();
        $workTypeIds = WorkType::pluck('id')->toArray();

        if (empty($projectIds) || empty($materialIds)) {
            throw new \Exception('Для сидирования material_usage_logs необходимы проекты и материалы.');
        }

        // Создаем больше записей для демонстрации активности
        foreach (range(1, 100) as $i) {
            $operationType = $i % 2 === 0 ? 'write_off' : 'receipt';
            $quantity = rand(1, 100) + rand(0, 999) / 1000;
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
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
                'supplier_id' => $operationType === 'receipt' && !empty($supplierIds) ? $faker->randomElement($supplierIds) : null,
                'document_number' => $documentNumber,
                'invoice_date' => $invoiceDate,
                'usage_date' => $usageDate,
                'photo_path' => null,
                'notes' => $operationType === 'write_off' ? 'Списание на работы' : 'Поступление от поставщика',
                'work_type_id' => $operationType === 'write_off' && !empty($workTypeIds) ? $faker->randomElement($workTypeIds) : null,
            ]);
        }

        $this->command->info('Создано 100 записей логов использования материалов');
    }
} 