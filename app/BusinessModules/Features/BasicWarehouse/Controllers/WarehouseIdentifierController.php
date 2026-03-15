<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Http\Requests\WarehouseIdentifierRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\WarehouseIdentifierResolveRequest;
use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseIdentifier;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseLogisticUnit;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseStorageCell;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseZone;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WarehouseIdentifierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $identifiers = WarehouseIdentifier::query()
                ->where('organization_id', $organizationId)
                ->when($request->filled('warehouse_id'), fn ($query) => $query->where('warehouse_id', (int) $request->input('warehouse_id')))
                ->when($request->filled('identifier_type'), fn ($query) => $query->where('identifier_type', (string) $request->input('identifier_type')))
                ->when($request->filled('entity_type'), fn ($query) => $query->where('entity_type', (string) $request->input('entity_type')))
                ->when($request->filled('status'), fn ($query) => $query->where('status', (string) $request->input('status')))
                ->when(
                    $request->filled('q'),
                    fn ($query) => $query->where(function ($nestedQuery) use ($request): void {
                        $search = '%' . trim((string) $request->input('q')) . '%';
                        $nestedQuery->where('code', 'like', $search)->orWhere('label', 'like', $search);
                    })
                )
                ->with('warehouse:id,name,code')
                ->orderByDesc('is_primary')
                ->orderByDesc('updated_at')
                ->get();

            return AdminResponse::success(
                $identifiers->map(fn (WarehouseIdentifier $identifier) => $this->makeIdentifierPayload($identifier))->values()->all()
            );
        } catch (\Throwable $exception) {
            Log::error('WarehouseIdentifierController::index error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'filters' => $request->only(['warehouse_id', 'identifier_type', 'entity_type', 'status', 'q']),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.identifier.list_error'), 500);
        }
    }

    public function store(WarehouseIdentifierRequest $request): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $validated = $request->validated();
            $warehouseId = $this->resolveWarehouseId($organizationId, $validated);
            $this->assertEntityExists($organizationId, $validated['entity_type'], (int) $validated['entity_id'], $warehouseId);

            $exists = WarehouseIdentifier::query()
                ->where('organization_id', $organizationId)
                ->where('code', $validated['code'])
                ->exists();

            if ($exists) {
                return AdminResponse::error(trans_message('basic_warehouse.identifier.code_exists'), 422);
            }

            if (($validated['is_primary'] ?? false) === true) {
                $this->clearPrimaryIdentifier($organizationId, $validated['entity_type'], (int) $validated['entity_id']);
            }

            $identifier = WarehouseIdentifier::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                ...$validated,
            ]);

            return AdminResponse::success(
                $this->makeIdentifierPayload($identifier->load('warehouse:id,name,code')),
                trans_message('basic_warehouse.identifier.created'),
                201
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.identifier.not_found'), 404);
        } catch (\InvalidArgumentException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('WarehouseIdentifierController::store error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'payload' => $request->only(['warehouse_id', 'identifier_type', 'code', 'entity_type', 'entity_id']),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.identifier.create_error'), 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $identifier = $this->findIdentifier($organizationId, $id);

            return AdminResponse::success($this->makeIdentifierPayload($identifier));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.identifier.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('WarehouseIdentifierController::show error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'identifier_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.identifier.show_error'), 500);
        }
    }

    public function update(WarehouseIdentifierRequest $request, int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $identifier = $this->findIdentifier($organizationId, $id);
            $validated = $request->validated();
            $entityType = $validated['entity_type'] ?? $identifier->entity_type;
            $entityId = (int) ($validated['entity_id'] ?? $identifier->entity_id);
            $warehouseId = $this->resolveWarehouseId($organizationId, $validated, $identifier->warehouse_id);

            $this->assertEntityExists($organizationId, $entityType, $entityId, $warehouseId);

            if (isset($validated['code']) && $validated['code'] !== $identifier->code) {
                $exists = WarehouseIdentifier::query()
                    ->where('organization_id', $organizationId)
                    ->where('code', $validated['code'])
                    ->where('id', '!=', $identifier->id)
                    ->exists();

                if ($exists) {
                    return AdminResponse::error(trans_message('basic_warehouse.identifier.code_exists'), 422);
                }
            }

            if (($validated['is_primary'] ?? false) === true) {
                $this->clearPrimaryIdentifier($organizationId, $entityType, $entityId, $identifier->id);
            }

            $identifier->update([
                ...$validated,
                'warehouse_id' => $warehouseId,
            ]);

            return AdminResponse::success(
                $this->makeIdentifierPayload($identifier->fresh()->load('warehouse:id,name,code')),
                trans_message('basic_warehouse.identifier.updated')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.identifier.not_found'), 404);
        } catch (\InvalidArgumentException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('WarehouseIdentifierController::update error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'identifier_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.identifier.update_error'), 500);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $identifier = $this->findIdentifier($organizationId, $id);
            $identifier->delete();

            return AdminResponse::success(null, trans_message('basic_warehouse.identifier.deleted'));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.identifier.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('WarehouseIdentifierController::destroy error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'identifier_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.identifier.delete_error'), 500);
        }
    }

    public function resolve(WarehouseIdentifierResolveRequest $request): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $identifier = WarehouseIdentifier::query()
                ->where('organization_id', $organizationId)
                ->where('code', (string) $request->input('code'))
                ->when(
                    $request->filled('warehouse_id'),
                    fn ($query) => $query->where(function ($nestedQuery) use ($request): void {
                        $warehouseId = (int) $request->input('warehouse_id');
                        $nestedQuery
                            ->where('warehouse_id', $warehouseId)
                            ->orWhereNull('warehouse_id');
                    })
                )
                ->with('warehouse:id,name,code')
                ->firstOrFail();

            return AdminResponse::success($this->makeIdentifierPayload($identifier));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.identifier.resolve_not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('WarehouseIdentifierController::resolve error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'code' => $request->input('code'),
                'warehouse_id' => $request->input('warehouse_id'),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.identifier.resolve_error'), 500);
        }
    }

    private function findIdentifier(int $organizationId, int $identifierId): WarehouseIdentifier
    {
        return WarehouseIdentifier::query()
            ->with('warehouse:id,name,code')
            ->where('organization_id', $organizationId)
            ->findOrFail($identifierId);
    }

    private function resolveWarehouseId(int $organizationId, array $validated, ?int $fallbackWarehouseId = null): ?int
    {
        $warehouseId = isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : $fallbackWarehouseId;

        if ($warehouseId === null) {
            return $this->resolveEntityWarehouseId($organizationId, (string) $validated['entity_type'], (int) $validated['entity_id']);
        }

        OrganizationWarehouse::query()
            ->where('organization_id', $organizationId)
            ->findOrFail($warehouseId);

        return $warehouseId;
    }

    private function clearPrimaryIdentifier(int $organizationId, string $entityType, int $entityId, ?int $exceptId = null): void
    {
        WarehouseIdentifier::query()
            ->where('organization_id', $organizationId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->update(['is_primary' => false]);
    }

    private function assertEntityExists(int $organizationId, string $entityType, int $entityId, ?int $warehouseId): void
    {
        $entity = $this->resolveEntityModel($entityType, $organizationId, $entityId, $warehouseId);

        if (! $entity) {
            throw new \InvalidArgumentException(trans_message('basic_warehouse.identifier.entity_invalid'));
        }
    }

    private function resolveEntityWarehouseId(int $organizationId, string $entityType, int $entityId): ?int
    {
        $entity = $this->resolveEntityModel($entityType, $organizationId, $entityId);

        if ($entityType === 'warehouse' && $entity !== null) {
            return $entityId;
        }

        return $entity?->warehouse_id;
    }

    private function resolveEntityModel(string $entityType, int $organizationId, int $entityId, ?int $warehouseId = null): ?Model
    {
        return match ($entityType) {
            'warehouse' => OrganizationWarehouse::query()->where('organization_id', $organizationId)->find($entityId),
            'zone' => WarehouseZone::query()
                ->whereHas('warehouse', fn ($query) => $query->where('organization_id', $organizationId))
                ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
                ->find($entityId),
            'cell' => WarehouseStorageCell::query()
                ->where('organization_id', $organizationId)
                ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
                ->find($entityId),
            'asset' => Asset::query()
                ->where('organization_id', $organizationId)
                ->find($entityId),
            'inventory_act' => InventoryAct::query()
                ->where('organization_id', $organizationId)
                ->find($entityId),
            'movement' => WarehouseMovement::query()
                ->where('organization_id', $organizationId)
                ->find($entityId),
            'logistic_unit' => WarehouseLogisticUnit::query()
                ->where('organization_id', $organizationId)
                ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
                ->find($entityId),
            default => null,
        };
    }

    private function makeIdentifierPayload(WarehouseIdentifier $identifier): array
    {
        return [
            'id' => $identifier->id,
            'organization_id' => $identifier->organization_id,
            'warehouse_id' => $identifier->warehouse_id,
            'identifier_type' => $identifier->identifier_type,
            'code' => $identifier->code,
            'entity_type' => $identifier->entity_type,
            'entity_id' => (int) $identifier->entity_id,
            'label' => $identifier->label,
            'status' => $identifier->status,
            'is_primary' => (bool) $identifier->is_primary,
            'assigned_at' => optional($identifier->assigned_at)?->toDateTimeString(),
            'last_scanned_at' => optional($identifier->last_scanned_at)?->toDateTimeString(),
            'metadata' => $identifier->metadata ?? [],
            'notes' => $identifier->notes,
            'warehouse' => $identifier->warehouse ? [
                'id' => $identifier->warehouse->id,
                'name' => $identifier->warehouse->name,
                'code' => $identifier->warehouse->code,
            ] : null,
            'entity_summary' => $this->makeEntitySummary($identifier->entity_type, (int) $identifier->entity_id, $identifier->organization_id),
            'created_at' => optional($identifier->created_at)?->toDateTimeString(),
            'updated_at' => optional($identifier->updated_at)?->toDateTimeString(),
        ];
    }

    private function makeEntitySummary(string $entityType, int $entityId, int $organizationId): ?array
    {
        $entity = $this->resolveEntityModel($entityType, $organizationId, $entityId);

        if (! $entity) {
            return null;
        }

        return match ($entityType) {
            'warehouse' => [
                'id' => $entity->id,
                'name' => $entity->name,
                'code' => $entity->code,
            ],
            'zone' => [
                'id' => $entity->id,
                'name' => $entity->name,
                'code' => $entity->code,
            ],
            'cell' => [
                'id' => $entity->id,
                'name' => $entity->name,
                'code' => $entity->code,
            ],
            'asset' => [
                'id' => $entity->id,
                'name' => $entity->name,
                'code' => $entity->code,
            ],
            'inventory_act' => [
                'id' => $entity->id,
                'name' => $entity->act_number,
                'code' => $entity->act_number,
            ],
            'movement' => [
                'id' => $entity->id,
                'name' => $entity->movement_type,
                'code' => $entity->document_number,
            ],
            'logistic_unit' => [
                'id' => $entity->id,
                'name' => $entity->name,
                'code' => $entity->code,
            ],
            default => null,
        };
    }
}
