<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Exceptions\InventoryApprovalStatusException;
use App\BusinessModules\Features\BasicWarehouse\Exceptions\InventoryReservationConflictException;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\InventoryIndexRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\StoreInventoryActRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\UpdateInventoryItemRequest;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryActItem;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Queries\InventoryActRegistryQuery;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\WarehouseExportManager;
use App\BusinessModules\Features\BasicWarehouse\Services\InventoryActPayloadPresenter;
use App\BusinessModules\Features\BasicWarehouse\Services\InventoryWorkflowService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InventoryController extends Controller
{
    public function __construct(
        protected WarehouseExportManager $exportManager,
        private readonly InventoryWorkflowService $inventoryWorkflowService,
        private readonly InventoryActRegistryQuery $inventoryActRegistryQuery,
        private readonly InventoryActPayloadPresenter $inventoryActPayloadPresenter,
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
                'data' => $this->inventoryActPayloadPresenter->presentMany($organizationId, $acts->items()),
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

    public function store(StoreInventoryActRequest $request): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;
        $validated = $request->validated();

        try {
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
                'items.material.measurementUnit',
                'items.cell.zone',
            ]);

            return AdminResponse::success(
                $this->inventoryActPayloadPresenter->present($organizationId, $act, true),
                trans_message('basic_warehouse.inventory.created'),
                201
            );
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
                'items.material.measurementUnit',
                'items.cell.zone',
            ]);

            return AdminResponse::success($this->inventoryActPayloadPresenter->present($organizationId, $act, true));
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

            $act->load('warehouse');

            return AdminResponse::success(
                $this->inventoryActPayloadPresenter->present($organizationId, $act),
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
                $this->inventoryActPayloadPresenter->presentItem($item),
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
            $act = $this->findAct($organizationId, $id, ['warehouse', 'items.material.measurementUnit', 'items.cell.zone']);

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

            $act->refresh()->load(['warehouse', 'items.material.measurementUnit']);

            return AdminResponse::success(
                $this->inventoryActPayloadPresenter->present($organizationId, $act, true),
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
                'items.material.measurementUnit',
                'items.cell.zone',
            ]);

            return AdminResponse::success(
                $this->inventoryActPayloadPresenter->present($organizationId, $act, true),
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
}
