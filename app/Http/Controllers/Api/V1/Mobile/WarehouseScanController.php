<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\BusinessModules\Features\BasicWarehouse\Http\Requests\WarehouseScanEventRequest;
use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseIdentifier;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseLogisticUnit;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseScanEvent;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseStorageCell;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseZone;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use App\Services\Mobile\MobileWarehouseTaskService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WarehouseScanController extends Controller
{
    public function __construct(
        private readonly MobileWarehouseTaskService $taskService
    ) {
    }

    public function resolve(WarehouseScanEventRequest $request): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $validated = $request->validated();
            $code = trim((string) $validated['code']);
            $warehouseId = isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null;

            if ($warehouseId !== null) {
                $this->findWarehouse($organizationId, $warehouseId);
            }

            $resolved = $this->resolveCode($organizationId, $code, $warehouseId);
            $event = $this->storeScanEvent(
                $organizationId,
                $request->user()?->id,
                $warehouseId,
                $code,
                $resolved['identifier'] ?? null,
                (string) ($validated['source'] ?? WarehouseScanEvent::SOURCE_MOBILE),
                isset($validated['scan_context']) ? (string) $validated['scan_context'] : null,
                is_array($validated['metadata'] ?? null) ? $validated['metadata'] : [],
                isset($validated['notes']) ? (string) $validated['notes'] : null
            );

            $entityPayload = null;
            $relatedTasks = [];
            $availableActions = [];
            $recommendedAction = null;

            if (($resolved['entity_type'] ?? null) !== null && ($resolved['entity_id'] ?? null) !== null) {
                $entityPayload = $this->makeEntityPayload(
                    (string) $resolved['entity_type'],
                    (int) $resolved['entity_id'],
                    $organizationId,
                    $warehouseId
                );
                $relatedTasks = $this->taskService->findRelatedTasks(
                    $organizationId,
                    $warehouseId,
                    (string) $resolved['entity_type'],
                    (int) $resolved['entity_id'],
                    12
                );
                $availableActions = $this->resolveAvailableActions((string) $resolved['entity_type'], $relatedTasks);
                $recommendedAction = $this->resolveRecommendedAction((string) $resolved['entity_type'], $relatedTasks);
            }

            return MobileResponse::success([
                'resolved' => ($resolved['entity_type'] ?? null) !== null,
                'resolved_by' => $resolved['resolved_by'] ?? null,
                'warehouse' => $warehouseId !== null ? $this->makeWarehousePayload($this->findWarehouse($organizationId, $warehouseId)) : null,
                'scan_event' => $this->makeScanEventPayload($event),
                'identifier' => isset($resolved['identifier']) && $resolved['identifier'] instanceof WarehouseIdentifier
                    ? $this->makeIdentifierPayload($resolved['identifier'])
                    : null,
                'entity_type' => $resolved['entity_type'] ?? null,
                'entity_id' => $resolved['entity_id'] ?? null,
                'entity_summary' => $entityPayload !== null ? $this->makeEntitySummaryFromPayload($entityPayload) : null,
                'entity' => $entityPayload,
                'related_tasks' => $relatedTasks,
                'available_actions' => $availableActions,
                'recommended_action' => $recommendedAction,
            ]);
        } catch (ModelNotFoundException) {
            return MobileResponse::error(trans_message('basic_warehouse.scan_event.warehouse_not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('mobile.warehouse.scan.resolve.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $organizationId,
                'payload' => $request->only(['warehouse_id', 'code', 'source', 'scan_context']),
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_warehouse.errors.load_failed'), 500);
        }
    }

    private function resolveCode(int $organizationId, string $code, ?int $warehouseId): array
    {
        $identifier = WarehouseIdentifier::query()
            ->where('organization_id', $organizationId)
            ->where('code', $code)
            ->when(
                $warehouseId !== null,
                fn (Builder $query) => $query->where(function (Builder $nestedQuery) use ($warehouseId): void {
                    $nestedQuery->where('warehouse_id', $warehouseId)->orWhereNull('warehouse_id');
                })
            )
            ->with('warehouse:id,name,code')
            ->orderByDesc('is_primary')
            ->orderByDesc('updated_at')
            ->first();

        if ($identifier) {
            $identifier->forceFill(['last_scanned_at' => now()])->save();

            return [
                'identifier' => $identifier,
                'entity_type' => $identifier->entity_type,
                'entity_id' => (int) $identifier->entity_id,
                'resolved_by' => 'identifier',
            ];
        }

        $asset = $this->resolveAssetByQrCode($organizationId, $code);

        if (! $asset) {
            return [
                'identifier' => null,
                'entity_type' => null,
                'entity_id' => null,
                'resolved_by' => null,
            ];
        }

        $assetIdentifier = $this->ensureAssetQrIdentifier($organizationId, $asset);
        $assetIdentifier->forceFill(['last_scanned_at' => now()])->save();

        return [
            'identifier' => $assetIdentifier,
            'entity_type' => 'asset',
            'entity_id' => $asset->id,
            'resolved_by' => 'asset_qr',
        ];
    }

    private function resolveAssetByQrCode(int $organizationId, string $code): ?Asset
    {
        if (! preg_match('/^AST-(\d+)-(\d+)$/i', $code, $matches)) {
            return null;
        }

        if ((int) $matches[1] !== $organizationId) {
            return null;
        }

        return Asset::query()
            ->with([
                'measurementUnit:id,name,short_name',
                'warehouseBalances.warehouse:id,name,code',
                'photos',
            ])
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->find((int) $matches[2]);
    }

    private function ensureAssetQrIdentifier(int $organizationId, Asset $asset): WarehouseIdentifier
    {
        $identifier = WarehouseIdentifier::query()
            ->where('organization_id', $organizationId)
            ->where('entity_type', 'asset')
            ->where('entity_id', $asset->id)
            ->where('identifier_type', WarehouseIdentifier::TYPE_QR)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        if ($identifier) {
            return $identifier;
        }

        $hasPrimary = WarehouseIdentifier::query()
            ->where('organization_id', $organizationId)
            ->where('entity_type', 'asset')
            ->where('entity_id', $asset->id)
            ->where('is_primary', true)
            ->exists();

        return WarehouseIdentifier::create([
            'organization_id' => $organizationId,
            'warehouse_id' => null,
            'identifier_type' => WarehouseIdentifier::TYPE_QR,
            'code' => sprintf('AST-%d-%06d', $organizationId, $asset->id),
            'entity_type' => 'asset',
            'entity_id' => $asset->id,
            'label' => Str::limit(Str::squish($asset->name), 255, ''),
            'status' => WarehouseIdentifier::STATUS_ACTIVE,
            'is_primary' => ! $hasPrimary,
            'assigned_at' => now(),
        ]);
    }

    private function storeScanEvent(
        int $organizationId,
        ?int $userId,
        ?int $warehouseId,
        string $code,
        ?WarehouseIdentifier $identifier,
        string $source,
        ?string $scanContext,
        array $metadata,
        ?string $notes
    ): WarehouseScanEvent {
        $event = WarehouseScanEvent::create([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId ?? $identifier?->warehouse_id,
            'identifier_id' => $identifier?->id,
            'logistic_unit_id' => $identifier?->entity_type === 'logistic_unit' ? (int) $identifier->entity_id : null,
            'scanned_by_id' => $userId,
            'code' => $code,
            'source' => $source,
            'result' => $identifier ? WarehouseScanEvent::RESULT_RESOLVED : WarehouseScanEvent::RESULT_NOT_FOUND,
            'entity_type' => $identifier?->entity_type,
            'entity_id' => $identifier?->entity_id !== null ? (int) $identifier->entity_id : null,
            'scan_context' => $scanContext,
            'metadata' => $metadata,
            'notes' => $notes,
            'scanned_at' => now(),
        ]);

        return $event->load([
            'warehouse:id,name,code,warehouse_type,address',
            'identifier:id,warehouse_id,identifier_type,code,entity_type,entity_id,label,status,is_primary,last_scanned_at',
            'logisticUnit:id,name,code,unit_type,status',
        ]);
    }

    private function makeEntityPayload(string $entityType, int $entityId, int $organizationId, ?int $warehouseId): ?array
    {
        return match ($entityType) {
            'asset' => $this->makeAssetPayload($organizationId, $entityId, $warehouseId),
            'cell' => $this->makeCellPayload($organizationId, $entityId),
            'logistic_unit' => $this->makeLogisticUnitPayload($organizationId, $entityId),
            'warehouse' => $this->makeWarehouseEntityPayload($organizationId, $entityId),
            'zone' => $this->makeZonePayload($organizationId, $entityId),
            'inventory_act' => $this->makeInventoryActPayload($organizationId, $entityId),
            'movement' => $this->makeMovementPayload($organizationId, $entityId),
            default => null,
        };
    }

    private function makeAssetPayload(int $organizationId, int $assetId, ?int $warehouseId): ?array
    {
        $asset = Asset::query()
            ->with([
                'measurementUnit:id,name,short_name',
                'warehouseBalances.warehouse:id,name,code',
                'photos',
            ])
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->find($assetId);

        if (! $asset) {
            return null;
        }

        $selectedBalance = $warehouseId !== null
            ? $asset->warehouseBalances->firstWhere('warehouse_id', $warehouseId)
            : $asset->warehouseBalances->first();
        $available = (float) $asset->warehouseBalances->sum('available_quantity');
        $reserved = (float) $asset->warehouseBalances->sum('reserved_quantity');

        return [
            'id' => $asset->id,
            'entity_type' => 'asset',
            'name' => $asset->name,
            'code' => $asset->code,
            'asset_type' => $asset->asset_type,
            'asset_type_label' => Asset::getAssetTypes()[$asset->asset_type] ?? $asset->asset_type,
            'category' => $asset->asset_category ?: $asset->category,
            'description' => $asset->description,
            'default_price' => (float) ($asset->default_price ?? 0),
            'measurement_unit' => $asset->measurementUnit ? [
                'id' => $asset->measurementUnit->id,
                'name' => $asset->measurementUnit->name,
                'short_name' => $asset->measurementUnit->short_name,
            ] : null,
            'warehouse_balance' => $selectedBalance ? [
                'warehouse_id' => $selectedBalance->warehouse_id,
                'warehouse_name' => $selectedBalance->warehouse?->name,
                'warehouse_code' => $selectedBalance->warehouse?->code,
                'available_quantity' => (float) $selectedBalance->available_quantity,
                'reserved_quantity' => (float) $selectedBalance->reserved_quantity,
                'total_quantity' => (float) $selectedBalance->total_quantity,
                'location_code' => $selectedBalance->location_code,
                'last_movement_at' => optional($selectedBalance->last_movement_at)?->toDateTimeString(),
            ] : null,
            'total_available_quantity' => $available,
            'total_reserved_quantity' => $reserved,
            'total_quantity' => $available + $reserved,
            'photos_count' => $asset->photos->count(),
        ];
    }

    private function makeCellPayload(int $organizationId, int $cellId): ?array
    {
        $cell = WarehouseStorageCell::query()
            ->with('zone:id,name,code')
            ->where('organization_id', $organizationId)
            ->find($cellId);

        if (! $cell) {
            return null;
        }

        $storedQuantity = (float) (DB::table('warehouse_balances')
            ->where('warehouse_id', $cell->warehouse_id)
            ->where('location_code', $cell->code)
            ->selectRaw('SUM(available_quantity + reserved_quantity) as stored_quantity')
            ->value('stored_quantity') ?? 0);
        $capacity = $cell->capacity !== null ? (float) $cell->capacity : null;
        $utilization = null;

        if ($capacity !== null && $capacity > 0) {
            $utilization = round(min(100, ($storedQuantity / $capacity) * 100), 1);
        }

        return [
            'id' => $cell->id,
            'entity_type' => 'cell',
            'name' => $cell->name,
            'code' => $cell->code,
            'status' => $cell->status,
            'cell_type' => $cell->cell_type,
            'full_address' => $cell->full_address,
            'capacity' => $capacity,
            'stored_quantity' => round($storedQuantity, 3),
            'current_utilization' => $utilization,
            'zone' => $cell->zone ? [
                'id' => $cell->zone->id,
                'name' => $cell->zone->name,
                'code' => $cell->zone->code,
            ] : null,
        ];
    }

    private function makeLogisticUnitPayload(int $organizationId, int $unitId): ?array
    {
        $unit = WarehouseLogisticUnit::query()
            ->with([
                'zone:id,name,code',
                'cell:id,name,code,zone_id,rack_number,shelf_number,bin_number',
                'cell.zone:id,name,code',
                'parentUnit:id,name,code',
            ])
            ->withCount(['identifiers', 'scanEvents', 'childUnits'])
            ->where('organization_id', $organizationId)
            ->find($unitId);

        if (! $unit) {
            return null;
        }

        $capacity = $unit->capacity !== null ? (float) $unit->capacity : null;
        $currentLoad = $unit->current_load !== null ? (float) $unit->current_load : null;
        $utilization = null;

        if ($capacity !== null && $capacity > 0 && $currentLoad !== null) {
            $utilization = round(min(100, ($currentLoad / $capacity) * 100), 1);
        }

        return [
            'id' => $unit->id,
            'entity_type' => 'logistic_unit',
            'name' => $unit->name,
            'code' => $unit->code,
            'unit_type' => $unit->unit_type,
            'status' => $unit->status,
            'capacity' => $capacity,
            'current_load' => $currentLoad,
            'current_utilization' => $utilization,
            'storage_address' => $unit->storage_address,
            'last_scanned_at' => optional($unit->last_scanned_at)?->toDateTimeString(),
            'identifiers_count' => (int) ($unit->identifiers_count ?? 0),
            'scan_events_count' => (int) ($unit->scan_events_count ?? 0),
            'child_units_count' => (int) ($unit->child_units_count ?? 0),
            'zone' => $unit->zone ? [
                'id' => $unit->zone->id,
                'name' => $unit->zone->name,
                'code' => $unit->zone->code,
            ] : null,
            'cell' => $unit->cell ? [
                'id' => $unit->cell->id,
                'name' => $unit->cell->name,
                'code' => $unit->cell->code,
                'full_address' => $unit->cell->full_address,
            ] : null,
        ];
    }

    private function makeWarehouseEntityPayload(int $organizationId, int $warehouseId): ?array
    {
        $warehouse = $this->findWarehouse($organizationId, $warehouseId);

        return [
            'id' => $warehouse->id,
            'entity_type' => 'warehouse',
            'name' => $warehouse->name,
            'code' => $warehouse->code,
            'warehouse_type' => $warehouse->warehouse_type,
            'address' => $warehouse->address,
        ];
    }

    private function makeZonePayload(int $organizationId, int $zoneId): ?array
    {
        $zone = WarehouseZone::query()
            ->with('warehouse:id,name,code')
            ->whereHas('warehouse', fn (Builder $query) => $query->where('organization_id', $organizationId))
            ->find($zoneId);

        if (! $zone) {
            return null;
        }

        return [
            'id' => $zone->id,
            'entity_type' => 'zone',
            'name' => $zone->name,
            'code' => $zone->code,
            'status' => $zone->status,
            'warehouse' => $zone->warehouse ? [
                'id' => $zone->warehouse->id,
                'name' => $zone->warehouse->name,
                'code' => $zone->warehouse->code,
            ] : null,
        ];
    }

    private function makeInventoryActPayload(int $organizationId, int $inventoryActId): ?array
    {
        $act = InventoryAct::query()
            ->where('organization_id', $organizationId)
            ->find($inventoryActId);

        if (! $act) {
            return null;
        }

        return [
            'id' => $act->id,
            'entity_type' => 'inventory_act',
            'name' => $act->act_number,
            'code' => $act->act_number,
            'status' => $act->status,
            'inventory_date' => optional($act->inventory_date)?->toDateString(),
        ];
    }

    private function makeMovementPayload(int $organizationId, int $movementId): ?array
    {
        $movement = WarehouseMovement::query()
            ->with(['warehouse:id,name,code', 'material:id,name,code'])
            ->where('organization_id', $organizationId)
            ->find($movementId);

        if (! $movement) {
            return null;
        }

        return [
            'id' => $movement->id,
            'entity_type' => 'movement',
            'name' => $movement->material?->name ?? $movement->movement_type,
            'code' => $movement->document_number,
            'movement_type' => $movement->movement_type,
            'document_number' => $movement->document_number,
            'movement_date' => optional($movement->movement_date)?->toDateTimeString(),
        ];
    }

    private function resolveAvailableActions(string $entityType, array $relatedTasks): array
    {
        $actions = match ($entityType) {
            'asset' => ['receipt', 'transfer'],
            'cell' => ['placement'],
            'logistic_unit' => ['placement', 'transfer'],
            'warehouse' => ['receipt', 'transfer'],
            default => [],
        };

        $taskTypes = collect($relatedTasks)->pluck('task_type')->filter()->unique()->values()->all();

        if (in_array('cycle_count', $taskTypes, true)) {
            $actions[] = 'cycle_count';
        }

        if (in_array('inspection', $taskTypes, true)) {
            $actions[] = 'inspection';
        }

        return array_values(array_unique($actions));
    }

    private function resolveRecommendedAction(string $entityType, array $relatedTasks): ?string
    {
        if ($relatedTasks !== []) {
            return (string) ($relatedTasks[0]['task_type'] ?? null);
        }

        return match ($entityType) {
            'asset', 'warehouse' => 'receipt',
            'cell', 'logistic_unit' => 'placement',
            default => null,
        };
    }

    private function makeWarehousePayload(OrganizationWarehouse $warehouse): array
    {
        return [
            'id' => $warehouse->id,
            'name' => $warehouse->name,
            'code' => $warehouse->code,
            'warehouse_type' => $warehouse->warehouse_type,
            'address' => $warehouse->address,
        ];
    }

    private function makeIdentifierPayload(WarehouseIdentifier $identifier): array
    {
        return [
            'id' => $identifier->id,
            'warehouse_id' => $identifier->warehouse_id,
            'identifier_type' => $identifier->identifier_type,
            'code' => $identifier->code,
            'entity_type' => $identifier->entity_type,
            'entity_id' => (int) $identifier->entity_id,
            'label' => $identifier->label,
            'status' => $identifier->status,
            'is_primary' => (bool) $identifier->is_primary,
            'last_scanned_at' => optional($identifier->last_scanned_at)?->toDateTimeString(),
            'warehouse' => $identifier->warehouse ? [
                'id' => $identifier->warehouse->id,
                'name' => $identifier->warehouse->name,
                'code' => $identifier->warehouse->code,
            ] : null,
        ];
    }

    private function makeScanEventPayload(WarehouseScanEvent $event): array
    {
        return [
            'id' => $event->id,
            'warehouse_id' => $event->warehouse_id,
            'identifier_id' => $event->identifier_id,
            'logistic_unit_id' => $event->logistic_unit_id,
            'code' => $event->code,
            'source' => $event->source,
            'result' => $event->result,
            'entity_type' => $event->entity_type,
            'entity_id' => $event->entity_id !== null ? (int) $event->entity_id : null,
            'scan_context' => $event->scan_context,
            'metadata' => $event->metadata ?? [],
            'notes' => $event->notes,
            'scanned_at' => optional($event->scanned_at)?->toDateTimeString(),
            'warehouse' => $event->warehouse ? $this->makeWarehousePayload($event->warehouse) : null,
        ];
    }

    private function makeEntitySummaryFromPayload(array $payload): array
    {
        return [
            'id' => (int) ($payload['id'] ?? 0),
            'name' => (string) ($payload['name'] ?? ''),
            'code' => $payload['code'] ?? null,
        ];
    }

    private function findWarehouse(int $organizationId, int $warehouseId): OrganizationWarehouse
    {
        return OrganizationWarehouse::query()
            ->where('organization_id', $organizationId)
            ->findOrFail($warehouseId);
    }
}
