<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Http\Requests\WarehouseScanEventRequest;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseIdentifier;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseLogisticUnit;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseScanEvent;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WarehouseScanEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            if ($request->filled('warehouse_id')) {
                $this->assertWarehouseExists($organizationId, (int) $request->input('warehouse_id'));
            }

            $events = WarehouseScanEvent::query()
                ->with([
                    'warehouse:id,name,code',
                    'identifier:id,code,identifier_type,entity_type,entity_id,label,status',
                    'logisticUnit:id,name,code,unit_type,status',
                ])
                ->where('organization_id', $organizationId)
                ->when($request->filled('warehouse_id'), fn (Builder $query) => $query->where('warehouse_id', (int) $request->input('warehouse_id')))
                ->when($request->filled('result'), fn (Builder $query) => $query->where('result', (string) $request->input('result')))
                ->when($request->filled('source'), fn (Builder $query) => $query->where('source', (string) $request->input('source')))
                ->when($request->filled('entity_type'), fn (Builder $query) => $query->where('entity_type', (string) $request->input('entity_type')))
                ->when(
                    $request->filled('q'),
                    fn (Builder $query) => $query->where('code', 'like', '%' . trim((string) $request->input('q')) . '%')
                )
                ->orderByDesc('scanned_at')
                ->limit(200)
                ->get();

            return AdminResponse::success(
                $events->map(fn (WarehouseScanEvent $event) => $this->makeEventPayload($event))->values()->all()
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.scan_event.warehouse_not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('WarehouseScanEventController::index error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'filters' => $request->only(['warehouse_id', 'result', 'source', 'entity_type', 'q']),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.scan_event.list_error'), 500);
        }
    }

    public function store(WarehouseScanEventRequest $request): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $validated = $request->validated();
            $warehouseId = isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null;

            if ($warehouseId !== null) {
                $this->assertWarehouseExists($organizationId, $warehouseId);
            }

            $identifier = WarehouseIdentifier::query()
                ->where('organization_id', $organizationId)
                ->where('code', trim((string) $validated['code']))
                ->when(
                    $warehouseId !== null,
                    fn (Builder $query) => $query->where(function (Builder $nestedQuery) use ($warehouseId): void {
                        $nestedQuery->where('warehouse_id', $warehouseId)->orWhereNull('warehouse_id');
                    })
                )
                ->with('warehouse:id,name,code')
                ->first();

            $eventData = [
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'scanned_by_id' => $request->user()?->id,
                'code' => trim((string) $validated['code']),
                'source' => (string) ($validated['source'] ?? WarehouseScanEvent::SOURCE_ADMIN),
                'result' => $identifier ? WarehouseScanEvent::RESULT_RESOLVED : WarehouseScanEvent::RESULT_NOT_FOUND,
                'entity_type' => $identifier?->entity_type,
                'entity_id' => $identifier?->entity_id,
                'scan_context' => $validated['scan_context'] ?? null,
                'metadata' => $validated['metadata'] ?? [],
                'notes' => $validated['notes'] ?? null,
                'scanned_at' => now(),
            ];

            if ($identifier) {
                $eventData['identifier_id'] = $identifier->id;
                $eventData['warehouse_id'] = $eventData['warehouse_id'] ?? $identifier->warehouse_id;

                $identifier->forceFill(['last_scanned_at' => now()])->save();

                if ($identifier->entity_type === 'logistic_unit') {
                    $logisticUnit = WarehouseLogisticUnit::query()
                        ->where('organization_id', $organizationId)
                        ->find((int) $identifier->entity_id);

                    if ($logisticUnit) {
                        $eventData['logistic_unit_id'] = $logisticUnit->id;
                        $logisticUnit->forceFill(['last_scanned_at' => now()])->save();
                    }
                }
            }

            $event = WarehouseScanEvent::create($eventData);

            return AdminResponse::success(
                $this->makeEventPayload(
                    $event->load([
                        'warehouse:id,name,code',
                        'identifier:id,code,identifier_type,entity_type,entity_id,label,status',
                        'logisticUnit:id,name,code,unit_type,status',
                    ])
                ),
                trans_message('basic_warehouse.scan_event.created'),
                201
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.scan_event.warehouse_not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('WarehouseScanEventController::store error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'payload' => $request->only(['warehouse_id', 'code', 'source', 'scan_context']),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.scan_event.create_error'), 500);
        }
    }

    private function assertWarehouseExists(int $organizationId, int $warehouseId): void
    {
        OrganizationWarehouse::query()
            ->where('organization_id', $organizationId)
            ->findOrFail($warehouseId);
    }

    private function makeEventPayload(WarehouseScanEvent $event): array
    {
        return [
            'id' => $event->id,
            'organization_id' => $event->organization_id,
            'warehouse_id' => $event->warehouse_id,
            'identifier_id' => $event->identifier_id,
            'logistic_unit_id' => $event->logistic_unit_id,
            'scanned_by_id' => $event->scanned_by_id,
            'code' => $event->code,
            'source' => $event->source,
            'result' => $event->result,
            'entity_type' => $event->entity_type,
            'entity_id' => $event->entity_id !== null ? (int) $event->entity_id : null,
            'scan_context' => $event->scan_context,
            'metadata' => $event->metadata ?? [],
            'notes' => $event->notes,
            'scanned_at' => optional($event->scanned_at)?->toDateTimeString(),
            'warehouse' => $event->warehouse ? [
                'id' => $event->warehouse->id,
                'name' => $event->warehouse->name,
                'code' => $event->warehouse->code,
            ] : null,
            'identifier' => $event->identifier ? [
                'id' => $event->identifier->id,
                'code' => $event->identifier->code,
                'identifier_type' => $event->identifier->identifier_type,
                'entity_type' => $event->identifier->entity_type,
                'entity_id' => (int) $event->identifier->entity_id,
                'label' => $event->identifier->label,
                'status' => $event->identifier->status,
            ] : null,
            'logistic_unit' => $event->logisticUnit ? [
                'id' => $event->logisticUnit->id,
                'name' => $event->logisticUnit->name,
                'code' => $event->logisticUnit->code,
                'unit_type' => $event->logisticUnit->unit_type,
                'status' => $event->logisticUnit->status,
            ] : null,
            'entity_summary' => $this->makeEntitySummary($event),
            'created_at' => optional($event->created_at)?->toDateTimeString(),
            'updated_at' => optional($event->updated_at)?->toDateTimeString(),
        ];
    }

    private function makeEntitySummary(WarehouseScanEvent $event): ?array
    {
        if ($event->logisticUnit) {
            return [
                'id' => $event->logisticUnit->id,
                'name' => $event->logisticUnit->name,
                'code' => $event->logisticUnit->code,
            ];
        }

        if ($event->identifier) {
            return [
                'id' => (int) $event->identifier->entity_id,
                'name' => $event->identifier->label ?: $event->identifier->code,
                'code' => $event->identifier->code,
            ];
        }

        return null;
    }
}
