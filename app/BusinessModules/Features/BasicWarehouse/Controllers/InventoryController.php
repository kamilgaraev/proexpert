<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Exceptions\InventoryApprovalStatusException;
use App\BusinessModules\Features\BasicWarehouse\Exceptions\InventoryReservationConflictException;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\InventoryIndexRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\UpdateInventoryItemRequest;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryActItem;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Queries\InventoryActRegistryQuery;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\WarehouseExportManager;
use App\BusinessModules\Features\BasicWarehouse\Services\InventoryWorkflowService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function __construct(
        protected WarehouseExportManager $exportManager,
        private readonly InventoryWorkflowService $inventoryWorkflowService,
        private readonly InventoryActRegistryQuery $inventoryActRegistryQuery,
    ) {}

    public function export(Request $request, int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $act = $this->findAct($organizationId, $id, [
                'organization',
                'warehouse',
                'items.material.measurementUnit',
            ]);

            $path = $this->exportManager->export('inv3', $act);
            $url = $this->exportManager->getTemporaryUrl($path);

            return AdminResponse::success(
                ['url' => $url],
                trans_message('basic_warehouse.inventory.export_success')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.inventory.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('InventoryController::export error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'inventory_act_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.inventory.export_error'), 500);
        }
    }

    public function index(InventoryIndexRequest $request): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $validated = $request->validated();
            $perPage = max(1, min((int) $request->input('per_page', 20), 100));

            $registry = $this->inventoryActRegistryQuery->get(
                $organizationId,
                $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null,
                $request->filled('status') ? (string) $request->input('status') : null,
                $validated['search'] ?? null,
                $perPage,
            );
            $acts = $registry['acts'];

            return AdminResponse::success([
                'data' => collect($acts->items())
                    ->map(fn (InventoryAct $act) => $this->makeInventoryActPayload($act))
                    ->values()
                    ->all(),
                'meta' => [
                    'current_page' => $acts->currentPage(),
                    'from' => $acts->firstItem(),
                    'last_page' => $acts->lastPage(),
                    'path' => $acts->path(),
                    'per_page' => $acts->perPage(),
                    'to' => $acts->lastItem(),
                    'total' => $acts->total(),
                    'metrics' => $registry['metrics'],
                ],
                'links' => [
                    'first' => $acts->url(1),
                    'last' => $acts->url($acts->lastPage()),
                    'prev' => $acts->previousPageUrl(),
                    'next' => $acts->nextPageUrl(),
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::error('InventoryController::index error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'filters' => $request->only(['warehouse_id', 'status', 'search', 'page', 'per_page']),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.inventory.index_error'), 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $validated = $request->validate([
                'warehouse_id' => [
                    'required',
                    'integer',
                    Rule::exists('organization_warehouses', 'id')->where(
                        fn ($query) => $query->where('organization_id', $organizationId)
                    ),
                ],
                'inventory_date' => 'required|date',
                'commission_members' => 'nullable|array',
                'commission_members.*' => 'integer|exists:users,id',
                'notes' => 'nullable|string',
            ]);

            $warehouse = $this->findWarehouse($organizationId, (int) $validated['warehouse_id']);

            $act = $this->inventoryWorkflowService->createAct(
                $organizationId,
                $warehouse->id,
                (string) $validated['inventory_date'],
                (int) $request->user()->id,
                array_map('intval', $validated['commission_members'] ?? []),
                isset($validated['notes']) ? (string) $validated['notes'] : null,
            );

            $act->load([
                'warehouse',
                'creator',
                'items.material.measurementUnit',
                'items.cell.zone',
            ]);

            return AdminResponse::success(
                $this->makeInventoryActPayload($act, true),
                trans_message('basic_warehouse.inventory.created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error(trans_message('errors.validation_failed'), 422, $exception->errors());
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.inventory.warehouse_not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('InventoryController::store error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'payload' => $request->only(['warehouse_id', 'inventory_date', 'notes']),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.inventory.store_error'), 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $act = $this->findAct($organizationId, $id, [
                'warehouse',
                'creator',
                'approver',
                'items.material.measurementUnit',
                'items.cell.zone',
            ]);

            return AdminResponse::success($this->makeInventoryActPayload($act, true));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.inventory.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('InventoryController::show error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'inventory_act_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.inventory.show_error'), 500);
        }
    }

    public function start(Request $request, int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $act = $this->findAct($organizationId, $id);

            if ($act->status !== InventoryAct::STATUS_DRAFT) {
                return AdminResponse::error(trans_message('basic_warehouse.inventory.start_invalid_status'), 400);
            }

            $act->update([
                'status' => InventoryAct::STATUS_IN_PROGRESS,
                'started_at' => now(),
            ]);

            $act->load(['warehouse', 'creator']);

            return AdminResponse::success(
                $this->makeInventoryActPayload($act),
                trans_message('basic_warehouse.inventory.started')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.inventory.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('InventoryController::start error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'inventory_act_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.inventory.start_error'), 500);
        }
    }

    public function updateItem(UpdateInventoryItemRequest $request, int $actId, int $itemId): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;
        $validated = $request->validated();

        try {
            $act = $this->findAct($organizationId, $actId);

            if ($act->status !== InventoryAct::STATUS_IN_PROGRESS) {
                return AdminResponse::error(
                    trans_message('basic_warehouse.inventory.update_item_invalid_status'),
                    400
                );
            }

            $item = $this->findActItem($act, $itemId);

            $item->actual_quantity = (float) $validated['actual_quantity'];
            $item->notes = $validated['notes'] ?? null;
            $item->calculateDifference();
            $item->save();
            $item->load(['material.measurementUnit']);

            return AdminResponse::success(
                $this->makeInventoryItemPayload($item),
                trans_message('basic_warehouse.inventory.item_updated')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.inventory.item_not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('InventoryController::updateItem error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'inventory_act_id' => $actId,
                'inventory_item_id' => $itemId,
                'payload' => $request->only(['actual_quantity', 'notes']),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.inventory.update_item_error'), 500);
        }
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $act = $this->findAct($organizationId, $id, ['warehouse', 'creator', 'items.material.measurementUnit', 'items.cell.zone']);

            if ($act->status !== InventoryAct::STATUS_IN_PROGRESS) {
                return AdminResponse::error(trans_message('basic_warehouse.inventory.complete_invalid_status'), 400);
            }

            $unfilledItems = $act->items
                ->filter(fn (InventoryActItem $item) => $item->actual_quantity === null)
                ->count();

            if ($unfilledItems > 0) {
                return AdminResponse::error(
                    trans_message('basic_warehouse.inventory.complete_unfilled_items', ['count' => $unfilledItems]),
                    400
                );
            }

            $summary = [
                'total_items' => $act->items->count(),
                'items_with_discrepancy' => $act->items
                    ->filter(fn (InventoryActItem $item) => $item->hasDiscrepancy())
                    ->count(),
                'total_difference_value' => $act->items->sum(
                    fn (InventoryActItem $item) => (float) ($item->total_value ?? 0)
                ),
            ];

            $act->update([
                'status' => InventoryAct::STATUS_COMPLETED,
                'completed_at' => now(),
                'summary' => $summary,
            ]);

            $act->refresh()->load(['warehouse', 'creator', 'items.material.measurementUnit']);

            return AdminResponse::success(
                $this->makeInventoryActPayload($act, true),
                trans_message('basic_warehouse.inventory.completed')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.inventory.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('InventoryController::complete error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'inventory_act_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.inventory.complete_error'), 500);
        }
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $act = $this->inventoryWorkflowService->approveAct(
                $organizationId,
                $id,
                (int) $request->user()->id,
            );

            $act->refresh()->load([
                'warehouse',
                'creator',
                'approver',
                'items.material.measurementUnit',
                'items.cell.zone',
            ]);

            return AdminResponse::success(
                $this->makeInventoryActPayload($act, true),
                trans_message('basic_warehouse.inventory.approved')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.inventory.not_found'), 404);
        } catch (InventoryApprovalStatusException) {
            return AdminResponse::error(trans_message('basic_warehouse.inventory.approve_invalid_status'), 400);
        } catch (InventoryReservationConflictException) {
            return AdminResponse::error(
                trans_message('basic_warehouse.inventory.reservation_conflict'),
                409
            );
        } catch (\Throwable $exception) {
            Log::error('InventoryController::approve error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'inventory_act_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.inventory.approve_error'), 500);
        }
    }

    private function findWarehouse(int $organizationId, int $warehouseId): OrganizationWarehouse
    {
        return OrganizationWarehouse::query()
            ->where('organization_id', $organizationId)
            ->findOrFail($warehouseId);
    }

    private function findAct(int $organizationId, int $actId, array $relations = []): InventoryAct
    {
        return InventoryAct::query()
            ->with($relations)
            ->where('organization_id', $organizationId)
            ->findOrFail($actId);
    }

    private function findActItem(InventoryAct $act, int $itemId): InventoryActItem
    {
        return InventoryActItem::query()
            ->where('inventory_act_id', $act->id)
            ->findOrFail($itemId);
    }

    private function makeInventoryActPayload(InventoryAct $act, bool $includeItems = false): array
    {
        $payload = [
            'id' => $act->id,
            'act_number' => $act->act_number,
            'warehouse_id' => $act->warehouse_id,
            'status' => $act->status,
            'inventory_date' => optional($act->inventory_date)?->toDateString(),
            'created_by' => $act->created_by,
            'commission_members' => $act->commission_members ?? [],
            'started_at' => optional($act->started_at)?->toDateTimeString(),
            'completed_at' => optional($act->completed_at)?->toDateTimeString(),
            'approved_at' => optional($act->approved_at)?->toDateTimeString(),
            'approved_by' => $act->approved_by,
            'notes' => $act->notes,
            'summary' => $act->summary ?? [
                'total_items' => (int) ($act->getAttribute('items_count')
                    ?? ($act->relationLoaded('items') ? $act->items->count() : 0)),
                'items_with_discrepancy' => (int) ($act->getAttribute('items_with_discrepancy_count')
                    ?? ($act->relationLoaded('items')
                        ? $act->items->filter(fn (InventoryActItem $item) => $item->hasDiscrepancy())->count()
                        : 0)),
                'total_difference_value' => (float) ($act->getAttribute('items_total_difference_value')
                    ?? ($act->relationLoaded('items')
                        ? $act->items->sum(fn (InventoryActItem $item) => (float) ($item->total_value ?? 0))
                        : 0)),
            ],
            'warehouse' => $act->warehouse ? [
                'id' => $act->warehouse->id,
                'name' => $act->warehouse->name,
            ] : null,
            'creator' => $act->creator ? [
                'id' => $act->creator->id,
                'name' => $act->creator->name,
            ] : null,
            'approver' => $act->approver ? [
                'id' => $act->approver->id,
                'name' => $act->approver->name,
            ] : null,
        ];

        if ($includeItems) {
            $payload['items'] = $act->items
                ->map(fn (InventoryActItem $item) => $this->makeInventoryItemPayload($item))
                ->values()
                ->all();
        }

        return $payload;
    }

    private function makeInventoryItemPayload(InventoryActItem $item): array
    {
        $material = $item->material;
        $measurementUnit = $material?->measurementUnit;

        return [
            'id' => $item->id,
            'inventory_act_id' => $item->inventory_act_id,
            'material_id' => $item->material_id,
            'expected_quantity' => (float) $item->expected_quantity,
            'actual_quantity' => $item->actual_quantity !== null ? (float) $item->actual_quantity : null,
            'difference_quantity' => $item->difference !== null ? (float) $item->difference : null,
            'unit_price' => (float) $item->unit_price,
            'difference_value' => $item->total_value !== null ? (float) $item->total_value : null,
            'cell_id' => $item->cell_id,
            'cell' => $item->cell ? [
                'id' => $item->cell->id,
                'code' => $item->cell->code,
                'name' => $item->cell->name,
                'full_address' => $item->cell->full_address,
                'zone' => $item->cell->zone ? [
                    'id' => $item->cell->zone->id,
                    'code' => $item->cell->zone->code,
                    'name' => $item->cell->zone->name,
                ] : null,
            ] : null,
            'storage_address' => $item->cell?->full_address ?? $item->location_code,
            'location_code' => $item->location_code,
            'batch_number' => $item->batch_number,
            'notes' => $item->notes,
            'material' => $material ? [
                'id' => $material->id,
                'name' => $material->name,
                'unit' => $measurementUnit?->short_name ?? $measurementUnit?->name,
                'article' => $material->code,
            ] : null,
        ];
    }
}
