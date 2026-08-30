<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\DTOs\ProjectAllocationResult;
use App\BusinessModules\Features\BasicWarehouse\Exceptions\ProjectAllocationException;
use App\BusinessModules\Features\BasicWarehouse\Exceptions\WarehouseOperationIdempotencyConflictException;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDeliveryEvent;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class ProjectAllocationService
{
    public function __construct(
        private readonly WarehouseService $warehouseService,
        private readonly ProjectMaterialDeliveryService $deliveryService,
    ) {}

    public function allocate(int $organizationId, User $actor, array $data): ProjectAllocationResult
    {
        return DB::transaction(function () use ($organizationId, $actor, $data): ProjectAllocationResult {
            Organization::query()->lockForUpdate()->findOrFail($organizationId);

            $fingerprint = WarehouseOperationIdempotency::fingerprint('project_allocation_create', $data);
            $existingEvent = ProjectMaterialDeliveryEvent::query()
                ->where('metadata->organization_id', $organizationId)
                ->where('metadata->allocation_idempotency_key', $data['idempotency_key'])
                ->with('delivery.allocation')
                ->first();

            if ($existingEvent !== null) {
                if (($existingEvent->metadata['allocation_idempotency_fingerprint'] ?? null) !== $fingerprint) {
                    throw new WarehouseOperationIdempotencyConflictException(
                        trans_message('warehouse_basic.idempotency_conflict')
                    );
                }

                $delivery = $existingEvent->delivery;
                $allocation = $delivery?->allocation;
                if ($delivery === null || $allocation === null) {
                    throw new ModelNotFoundException;
                }

                return new ProjectAllocationResult(
                    $allocation->load(['project', 'material', 'warehouse']),
                    $delivery->load(['project', 'material.measurementUnit', 'warehouse', 'latestEvent']),
                );
            }

            WarehouseBalance::query()
                ->where('organization_id', $organizationId)
                ->where('warehouse_id', $data['warehouse_id'])
                ->where('material_id', $data['material_id'])
                ->lockForUpdate()
                ->get();

            $balance = $this->warehouseService->getAssetBalance(
                $organizationId,
                (int) $data['warehouse_id'],
                (int) $data['material_id'],
            );

            if ($balance === null) {
                throw new ProjectAllocationException(
                    trans_message('basic_warehouse.project_allocations.material_not_in_warehouse'),
                    'MATERIAL_NOT_IN_WAREHOUSE',
                );
            }

            if ($balance->availableQuantity <= 0) {
                throw new ProjectAllocationException(
                    trans_message('basic_warehouse.project_allocations.insufficient_stock'),
                    'INSUFFICIENT_STOCK',
                    ['available_quantity' => 0],
                );
            }

            $availability = $balance->checkAllocationAvailability((float) $data['quantity']);
            if (! $availability['can_allocate']) {
                throw new ProjectAllocationException(
                    trans_message('basic_warehouse.project_allocations.insufficient_available_quantity', [
                        'quantity' => $availability['available_for_allocation'],
                    ]),
                    'INSUFFICIENT_AVAILABLE_QUANTITY',
                    ['details' => $availability],
                );
            }

            $allocation = WarehouseProjectAllocation::query()->firstOrNew([
                'organization_id' => $organizationId,
                'warehouse_id' => $data['warehouse_id'],
                'material_id' => $data['material_id'],
                'project_id' => $data['project_id'],
            ]);
            $allocation->allocated_quantity = (float) $allocation->allocated_quantity + (float) $data['quantity'];
            $allocation->allocated_by_user_id = $actor->id;
            $allocation->allocated_at = now();
            $allocation->notes = $data['notes'] ?? $allocation->notes;
            $allocation->save();

            $delivery = $this->deliveryService->createFromAllocation($allocation, $actor, [
                'quantity' => (float) $data['quantity'],
                'total_quantity' => (float) $allocation->allocated_quantity,
                'notes' => $data['notes'] ?? null,
                'event_metadata' => [
                    'organization_id' => $organizationId,
                    'allocation_idempotency_key' => $data['idempotency_key'],
                    'allocation_idempotency_fingerprint' => $fingerprint,
                ],
            ]);

            return new ProjectAllocationResult(
                $allocation->load(['project', 'material', 'warehouse']),
                $delivery,
            );
        });
    }

    public function deallocate(int $organizationId, User $actor, int $allocationId, array $data): void
    {
        DB::transaction(function () use ($organizationId, $actor, $allocationId, $data): void {
            Organization::query()->lockForUpdate()->findOrFail($organizationId);

            $fingerprint = WarehouseOperationIdempotency::fingerprint('project_allocation_remove', [
                ...$data,
                'allocation_id' => $allocationId,
            ]);
            $existingEvent = ProjectMaterialDeliveryEvent::query()
                ->where('metadata->organization_id', $organizationId)
                ->where('metadata->deallocation_idempotency_key', $data['idempotency_key'])
                ->first();

            if ($existingEvent !== null) {
                if (($existingEvent->metadata['deallocation_idempotency_fingerprint'] ?? null) !== $fingerprint) {
                    throw new WarehouseOperationIdempotencyConflictException(
                        trans_message('warehouse_basic.idempotency_conflict')
                    );
                }

                return;
            }

            $allocation = WarehouseProjectAllocation::query()
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->findOrFail($allocationId);
            $delivery = ProjectMaterialDelivery::query()
                ->where('organization_id', $organizationId)
                ->where('warehouse_project_allocation_id', $allocation->id)
                ->lockForUpdate()
                ->first();

            if ($delivery === null) {
                $delivery = $this->deliveryService->createFromAllocation($allocation, $actor, [
                    'quantity' => (float) $allocation->allocated_quantity,
                    'total_quantity' => (float) $allocation->allocated_quantity,
                ]);
            }

            $allocatedQuantity = (float) $allocation->allocated_quantity;
            $removedQuantity = array_key_exists('quantity', $data) && $data['quantity'] !== null
                ? (float) $data['quantity']
                : $allocatedQuantity;

            if ($removedQuantity > $allocatedQuantity) {
                throw new ProjectAllocationException(
                    trans_message('basic_warehouse.project_allocations.quantity_exceeds_allocated'),
                    'QUANTITY_EXCEEDS_ALLOCATED',
                );
            }

            $remainingQuantity = max(0.0, $allocatedQuantity - $removedQuantity);
            $minimumQuantity = max(
                (float) $delivery->shipped_quantity,
                (float) $delivery->accepted_quantity,
            );
            if ($remainingQuantity < $minimumQuantity) {
                throw new ProjectAllocationException(
                    trans_message('basic_warehouse.project_allocations.quantity_below_shipped', [
                        'quantity' => $minimumQuantity,
                    ]),
                    'ALLOCATION_BELOW_SHIPPED_QUANTITY',
                    ['minimum_quantity' => $minimumQuantity],
                );
            }

            $this->deliveryService->syncAfterDeallocation(
                $delivery,
                $actor,
                $removedQuantity,
                $remainingQuantity,
                [
                    'organization_id' => $organizationId,
                    'deallocation_idempotency_key' => $data['idempotency_key'],
                    'deallocation_idempotency_fingerprint' => $fingerprint,
                    'allocation_id' => $allocationId,
                ],
            );

            if ($remainingQuantity <= 0) {
                $allocation->delete();

                return;
            }

            $allocation->forceFill([
                'allocated_quantity' => $remainingQuantity,
                'allocated_by_user_id' => $actor->id,
                'allocated_at' => now(),
            ])->save();
        });
    }
}
