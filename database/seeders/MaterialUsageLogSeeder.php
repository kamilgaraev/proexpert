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
        $projectId = Project::query()->inRandomOrder()->value('id');
        $materialId = Material::query()->inRandomOrder()->value('id');
        $userId = User::query()->inRandomOrder()->value('id');
        $organizationId = Organization::query()->inRandomOrder()->value('id');
        $supplierId = Supplier::query()->inRandomOrder()->value('id');
        $workTypeId = WorkType::query()->inRandomOrder()->value('id');

        if (!$projectId || !$materialId || !$userId || !$organizationId) {
            throw new \Exception('Для сидирования material_usage_logs необходимы проекты, материалы, пользователи и организации.');
        }

        foreach (range(1, 10) as $i) {
            $operationType = $i % 2 === 0 ? 'write_off' : 'receipt';
            $quantity = rand(1, 100) + rand(0, 999) / 1000;
            $unitPrice = rand(100, 1000) + rand(0, 99) / 100;
            $totalPrice = $quantity * $unitPrice;
            $usageDate = Carbon::now()->subDays(rand(0, 30));
            $invoiceDate = $operationType === 'receipt' ? $usageDate->copy()->subDays(rand(0, 5)) : null;
            $documentNumber = $operationType === 'receipt' ? Str::upper(Str::random(8)) : null;

            MaterialUsageLog::create([
                'project_id' => $projectId,
                'material_id' => $materialId,
                'user_id' => $userId,
                'organization_id' => $organizationId,
                'operation_type' => $operationType,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
                'supplier_id' => $operationType === 'receipt' ? $supplierId : null,
                'document_number' => $documentNumber,
                'invoice_date' => $invoiceDate,
                'usage_date' => $usageDate,
                'photo_path' => null,
                'notes' => $operationType === 'write_off' ? 'Списание на работы' : 'Поступление от поставщика',
                'work_type_id' => $operationType === 'write_off' ? $workTypeId : null,
            ]);
        }
    }
} 