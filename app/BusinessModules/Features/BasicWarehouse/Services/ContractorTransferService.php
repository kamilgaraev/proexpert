<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Exceptions\WarehouseOperationIdempotencyConflictException;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\Models\Contractor;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function trans_message;

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
        int $userId,
        string $idempotencyKey,
        ?int $projectId = null,
        ?string $documentNumber = null,
        ?string $reason = null
    ): array {
        return DB::transaction(function () use (
            $sourceOrganizationId,
            $fromWarehouseId,
            $contractorId,
            $materialId,
            $quantity,
            $userId,
            $idempotencyKey,
            $projectId,
            $documentNumber,
            $reason
        ): array {
            Organization::query()->whereKey($sourceOrganizationId)->lockForUpdate()->firstOrFail();
            $fingerprint = WarehouseOperationIdempotency::fingerprint('transfer_to_contractor', [
                'from_warehouse_id' => $fromWarehouseId,
                'contractor_id' => $contractorId,
                'material_id' => $materialId,
                'quantity' => $quantity,
                'project_id' => $projectId,
                'document_number' => $documentNumber,
                'reason' => $reason,
            ]);
            $existingMovement = WarehouseMovement::query()
                ->where('organization_id', $sourceOrganizationId)
                ->where('metadata->idempotency_key', $idempotencyKey)
                ->where('metadata->is_contractor_transfer', true)
                ->first();

            if ($existingMovement !== null
                && ($existingMovement->metadata['contractor_transfer_fingerprint'] ?? null) !== $fingerprint) {
                throw new WarehouseOperationIdempotencyConflictException(
                    trans_message('warehouse_basic.idempotency_conflict')
                );
            }

            $contractor = Contractor::query()
                ->where('organization_id', $sourceOrganizationId)
                ->findOrFail($contractorId);

            if ($contractor->source_organization_id) {
                return $this->handleCrossOrganizationTransfer(
                    $sourceOrganizationId,
                    $fromWarehouseId,
                    $contractor,
                    $materialId,
                    $quantity,
                    $userId,
                    $idempotencyKey,
                    $fingerprint,
                    $projectId,
                    $documentNumber,
                    $reason
                );
            }

            return $this->handleInternalTransferToVirtualWarehouse(
                $sourceOrganizationId,
                $fromWarehouseId,
                $contractor,
                $materialId,
                $quantity,
                $userId,
                $idempotencyKey,
                $fingerprint,
                $projectId,
                $documentNumber,
                $reason
            );
        });
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
        int $userId,
        string $idempotencyKey,
        string $fingerprint,
        ?int $projectId,
        ?string $documentNumber,
        ?string $reason
    ): array {
        $sourceOrganization = Organization::query()->findOrFail($sourceOrganizationId);

        // 1. Списываем с нашего склада
        $writeOffResult = $this->warehouseService->writeOffAsset(
            $sourceOrganizationId,
            $fromWarehouseId,
            $materialId,
            $quantity,
            [
                'user_id' => $userId,
                'idempotency_key' => $idempotencyKey,
                'contractor_transfer_fingerprint' => $fingerprint,
                'project_id' => $projectId,
                'document_number' => $documentNumber,
                'reason' => $reason ?? trans_message('warehouse_basic.transfer_to_contractor_default_reason', [
                    'contractor' => $contractor->name,
                ]),
                'is_contractor_transfer' => true,
                'contractor_id' => $contractor->id,
                'contractor_name' => $contractor->name,
                'target_organization_id' => $contractor->source_organization_id,
            ]
        );

        // Получаем среднюю цену списания
        $totalValue = collect($writeOffResult['write_off_details'])
            ->sum(static fn (array $detail): float => (float) $detail['quantity'] * (float) $detail['unit_price']);
        $avgPrice = $quantity > 0 ? $totalValue / $quantity : 0;

        // 2. Находим или создаем склад в целевой организации
        $targetWarehouse = $this->findOrCreateTargetWarehouse($contractor->source_organization_id);

        // 3. Синхронизируем материал
        $targetMaterial = $this->syncMaterial(
            $sourceOrganizationId,
            $materialId,
            $contractor->source_organization_id,
        );
        $targetProjectId = $this->resolveTargetProjectId($projectId, $contractor->source_organization_id);

        // 4. Приходуем в целевой организации
        $this->warehouseService->receiveAsset(
            $contractor->source_organization_id,
            $targetWarehouse->id,
            $targetMaterial->id,
            $quantity,
            $avgPrice,
            [
                'user_id' => null, // Системная операция
                'idempotency_key' => $idempotencyKey,
                'contractor_transfer_fingerprint' => $fingerprint,
                'project_id' => $targetProjectId,
                'document_number' => $documentNumber,
                'reason' => trans_message('warehouse_basic.received_from_customer_reason', [
                    'organization' => $sourceOrganization->legal_name ?? $sourceOrganization->name,
                ]),
                'is_customer_transfer' => true,
                'source_organization_id' => $sourceOrganizationId,
                'source_organization_name' => $sourceOrganization->legal_name ?? $sourceOrganization->name,
                'source_project_id' => $projectId,
                'source_movement_ids' => [$writeOffResult['movement']->id],
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
        int $userId,
        string $idempotencyKey,
        string $fingerprint,
        ?int $projectId,
        ?string $documentNumber,
        ?string $reason
    ): array {
        $contractorWarehouse = $this->findOrCreateContractorWarehouse($organizationId, $contractor);

        // 2. Выполняем перемещение
        $result = $this->warehouseService->transferAsset(
            $organizationId,
            $fromWarehouseId,
            $contractorWarehouse->id,
            $materialId,
            $quantity,
            [
                'user_id' => $userId,
                'idempotency_key' => $idempotencyKey,
                'contractor_transfer_fingerprint' => $fingerprint,
                'project_id' => $projectId,
                'document_number' => $documentNumber,
                'reason' => $reason ?? trans_message('warehouse_basic.transfer_to_contractor_default_reason', [
                    'contractor' => $contractor->name,
                ]),
                'is_contractor_transfer' => true,
                'contractor_id' => $contractor->id,
                'contractor_name' => $contractor->name,
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

        if (! $warehouse) {
            $warehouse = OrganizationWarehouse::where('organization_id', $organizationId)
                ->where('is_active', true)
                ->first();
        }

        if (! $warehouse) {
            $warehouse = OrganizationWarehouse::create([
                'organization_id' => $organizationId,
                'name' => trans_message('warehouse_basic.main_warehouse_name'),
                'code' => 'MAIN',
                'is_main' => true,
                'is_active' => true,
                'warehouse_type' => 'central',
            ]);
        }

        return $warehouse;
    }

    protected function resolveTargetProjectId(?int $projectId, int $targetOrganizationId): ?int
    {
        if ($projectId === null) {
            return null;
        }

        $isAccessible = Project::query()
            ->accessibleByOrganization($targetOrganizationId)
            ->whereKey($projectId)
            ->exists();

        return $isAccessible ? $projectId : null;
    }

    protected function syncMaterial(
        int $sourceOrganizationId,
        int $sourceMaterialId,
        int $targetOrganizationId,
    ): Material {
        $sourceMaterial = Material::query()
            ->where('organization_id', $sourceOrganizationId)
            ->findOrFail($sourceMaterialId);

        $targetMaterial = Material::where('organization_id', $targetOrganizationId)
            ->where('name', $sourceMaterial->name)
            ->first();

        if (! $targetMaterial) {
            $targetMaterial = $sourceMaterial->replicate();
            $targetMaterial->organization_id = $targetOrganizationId;
            $targetMaterial->code = $sourceMaterial->code ? ($sourceMaterial->code.'-EXT') : null;
            $targetMaterial->push();
            $targetMaterial->save();
        }

        return $targetMaterial;
    }

    private function findOrCreateContractorWarehouse(
        int $organizationId,
        Contractor $contractor,
    ): OrganizationWarehouse {
        $existing = OrganizationWarehouse::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_type', OrganizationWarehouse::TYPE_EXTERNAL)
            ->where('settings->contractor_id', $contractor->id)
            ->first();

        if ($existing instanceof OrganizationWarehouse) {
            return $existing;
        }

        return OrganizationWarehouse::query()->create([
            'organization_id' => $organizationId,
            'name' => trans_message('warehouse_basic.contractor_warehouse_name', [
                'contractor' => $contractor->name,
            ]),
            'warehouse_type' => OrganizationWarehouse::TYPE_EXTERNAL,
            'code' => $this->uniqueContractorWarehouseCode($organizationId, $contractor->name),
            'description' => trans_message('warehouse_basic.contractor_warehouse_description', [
                'contractor' => $contractor->name,
            ]),
            'is_active' => true,
            'settings' => ['contractor_id' => $contractor->id],
        ]);
    }

    private function uniqueContractorWarehouseCode(int $organizationId, string $contractorName): string
    {
        $slug = Str::upper(Str::slug($contractorName));
        $base = mb_substr('CTR-'.($slug !== '' ? $slug : 'CONTRACTOR'), 0, 42);
        $candidate = $base;
        $suffix = 2;

        while (OrganizationWarehouse::query()
            ->where('organization_id', $organizationId)
            ->where('code', $candidate)
            ->exists()) {
            $candidate = mb_substr($base, 0, 42 - strlen((string) $suffix)).'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
