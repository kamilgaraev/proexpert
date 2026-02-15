<?php

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\Models\Contractor;
use App\Models\Material;
use Illuminate\Support\Facades\DB;

class ContractorTransferService
{
    public function __construct(
        protected WarehouseService $warehouseService
    ) {}

    /**
     * Выполнить передачу материалов подрядчику
     */
    public function transferToContractor(
        int $sourceOrganizationId,
        int $fromWarehouseId,
        int $contractorId,
        int $materialId,
        float $quantity,
        ?int $projectId = null,
        ?string $documentNumber = null,
        ?string $reason = null,
        int $userId
    ): array
    {
        $contractor = Contractor::findOrFail($contractorId);

        // Сценарий 1: Подрядчик - это другая организация в системе (Holding Member / Invited Organization)
        if ($contractor->source_organization_id) {
            return $this->handleCrossOrganizationTransfer(
                $sourceOrganizationId,
                $fromWarehouseId,
                $contractor,
                $materialId,
                $quantity,
                $projectId,
                $documentNumber,
                $reason,
                $userId
            );
        }

        // Сценарий 2: "Ручной" подрядчик без организации в системе (внутренний учет)
        return $this->handleInternalTransferToVirtualWarehouse(
            $sourceOrganizationId,
            $fromWarehouseId,
            $contractor,
            $materialId,
            $quantity,
            $projectId,
            $documentNumber,
            $reason,
            $userId
        );
    }

    /**
     * Обработка передачи в другую организацию
     */
    protected function handleCrossOrganizationTransfer(
        int $sourceOrganizationId,
        int $fromWarehouseId,
        Contractor $contractor,
        int $materialId,
        float $quantity,
        ?int $projectId,
        ?string $documentNumber,
        ?string $reason,
        int $userId
    ): array
    {
        // 1. Списываем с нашего склада
        $writeOffResult = $this->warehouseService->writeOffAsset(
            $sourceOrganizationId,
            $fromWarehouseId,
            $materialId,
            $quantity,
            [
                'user_id' => $userId,
                'project_id' => $projectId,
                'document_number' => $documentNumber,
                'reason' => $reason ?? "Передача материалов подрядчику {$contractor->name} (Орг. ID: {$contractor->source_organization_id})",
                'metadata' => [
                    'is_contractor_transfer' => true,
                    'contractor_id' => $contractor->id,
                    'target_organization_id' => $contractor->source_organization_id
                ]
            ]
        );

        // Получаем среднюю цену списания
        $totalValue = collect($writeOffResult['movements'])->sum('total_value');
        $avgPrice = $totalValue / $quantity;

        // 2. Находим или создаем склад в целевой организации
        $targetWarehouse = $this->findOrCreateTargetWarehouse($contractor->source_organization_id);

        // 3. Синхронизируем материал
        $targetMaterial = $this->syncMaterial($materialId, $contractor->source_organization_id);

        // 4. Приходуем в целевой организации
        $this->warehouseService->receiveAsset(
            $contractor->source_organization_id,
            $targetWarehouse->id,
            $targetMaterial->id,
            $quantity,
            $avgPrice,
            [
                'user_id' => null, // Системная операция
                'project_id' => null, // TODO: Можно передавать ID проекта, если он синхронизирован
                'document_number' => $documentNumber,
                'reason' => "Получено от заказчика (Орг. ID: {$sourceOrganizationId})",
                'metadata' => [
                    'is_customer_transfer' => true,
                    'source_organization_id' => $sourceOrganizationId,
                    'source_movement_ids' => collect($writeOffResult['movements'])->pluck('id')->toArray()
                ]
            ]
        );

        return [
            'transfer_type' => 'cross_organization',
            'write_off' => $writeOffResult,
            'receipt_warehouse_id' => $targetWarehouse->id,
        ];
    }

    /**
     * Обработка передачи на виртуальный склад внутри организации
     */
    protected function handleInternalTransferToVirtualWarehouse(
        int $organizationId,
        int $fromWarehouseId,
        Contractor $contractor,
        int $materialId,
        float $quantity,
        ?int $projectId,
        ?string $documentNumber,
        ?string $reason,
        int $userId
    ): array
    {
        // 1. Ищем или создаем склад подрядчика
        $contractorWarehouse = OrganizationWarehouse::firstOrCreate(
            [
                'organization_id' => $organizationId,
                'name' => 'Склад подрядчика: ' . $contractor->name,
                'warehouse_type' => OrganizationWarehouse::TYPE_EXTERNAL,
            ],
            [
                'code' => 'CTR-' . $contractor->id . '-' . time(),
                'description' => "Автоматически созданный склад для подрядчика ID: {$contractor->id}",
                'is_active' => true,
                'settings' => ['contractor_id' => $contractor->id],
            ]
        );

        // 2. Выполняем перемещение
        $result = $this->warehouseService->transferAsset(
            $organizationId,
            $fromWarehouseId,
            $contractorWarehouse->id,
            $materialId,
            $quantity,
            [
                'user_id' => $userId,
                'project_id' => $projectId,
                'document_number' => $documentNumber,
                'reason' => $reason ?? "Передача материалов подрядчику {$contractor->name}",
                'metadata' => [
                    'is_contractor_transfer' => true,
                    'contractor_id' => $contractor->id
                ]
            ]
        );

        return [
            'transfer_type' => 'internal_external_warehouse',
            'transfer_result' => $result,
            'contractor_warehouse_id' => $contractorWarehouse->id,
        ];
    }

    protected function findOrCreateTargetWarehouse(int $organizationId): OrganizationWarehouse
    {
        $warehouse = OrganizationWarehouse::where('organization_id', $organizationId)
            ->where('is_main', true)
            ->first();

        if (!$warehouse) {
            $warehouse = OrganizationWarehouse::where('organization_id', $organizationId)
                ->where('is_active', true)
                ->first();
        }

        if (!$warehouse) {
            $warehouse = OrganizationWarehouse::create([
                'organization_id' => $organizationId,
                'name' => 'Основной склад',
                'code' => 'MAIN-' . $organizationId,
                'is_main' => true,
                'is_active' => true,
                'warehouse_type' => 'central',
            ]);
        }

        return $warehouse;
    }

    protected function syncMaterial(int $sourceMaterialId, int $targetOrganizationId): Material
    {
        $sourceMaterial = Material::find($sourceMaterialId);
        
        $targetMaterial = Material::where('organization_id', $targetOrganizationId)
            ->where('name', $sourceMaterial->name)
            ->first();
        
        if (!$targetMaterial) {
            $targetMaterial = $sourceMaterial->replicate();
            $targetMaterial->organization_id = $targetOrganizationId;
            $targetMaterial->code = $sourceMaterial->code ? ($sourceMaterial->code . '-EXT') : null;
            $targetMaterial->push();
            $targetMaterial->save();
        }

        return $targetMaterial;
    }
}
