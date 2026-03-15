<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller
{
    public function __construct(
        protected WarehouseService $warehouseService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $warehouses = OrganizationWarehouse::query()
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->orderByDesc('is_main')
                ->orderBy('name')
                ->get();

            return AdminResponse::success($warehouses);
        } catch (\Throwable $exception) {
            Log::error('WarehouseController::index error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.warehouse.list_error'), 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'code' => [
                    'required',
                    'string',
                    'max:50',
                    Rule::unique('organization_warehouses', 'code')
                        ->where('organization_id', $organizationId),
                ],
                'warehouse_type' => 'nullable|in:central,project,external',
                'description' => 'nullable|string',
                'address' => 'nullable|string',
                'contact_person' => 'nullable|string|max:255',
                'contact_phone' => 'nullable|string|max:50',
                'working_hours' => 'nullable|string|max:255',
                'is_main' => 'nullable|boolean',
                'is_active' => 'nullable|boolean',
                'settings' => 'nullable|array',
                'storage_conditions' => 'nullable|array',
            ]);

            $warehouse = OrganizationWarehouse::create([
                'organization_id' => $organizationId,
                'name' => $validated['name'],
                'code' => $validated['code'],
                'warehouse_type' => $validated['warehouse_type'] ?? 'central',
                'description' => $validated['description'] ?? null,
                'address' => $validated['address'] ?? null,
                'contact_person' => $validated['contact_person'] ?? null,
                'contact_phone' => $validated['contact_phone'] ?? null,
                'working_hours' => $validated['working_hours'] ?? null,
                'is_main' => $validated['is_main'] ?? false,
                'is_active' => $validated['is_active'] ?? true,
                'settings' => $validated['settings'] ?? [],
                'storage_conditions' => $validated['storage_conditions'] ?? [],
            ]);

            return AdminResponse::success(
                $warehouse,
                trans_message('basic_warehouse.warehouse.created'),
                201
            );
        } catch (\Throwable $exception) {
            Log::error('WarehouseController::store error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'payload' => $request->except(['settings', 'storage_conditions']),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.warehouse.create_error'), 500);
        }
    }

    public function show(Request $request, string|int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;
        $warehouseId = is_int($id) ? $id : (ctype_digit($id) ? (int) $id : null);

        try {
            $warehouseId = $this->normalizeWarehouseId($id);
            $warehouse = $this->findWarehouse($organizationId, $warehouseId, ['balances.material']);

            return AdminResponse::success($warehouse);
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.warehouse.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('WarehouseController::show error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.warehouse.show_error'), 500);
        }
    }

    public function update(Request $request, string|int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;
        $warehouseId = is_int($id) ? $id : (ctype_digit($id) ? (int) $id : null);

        try {
            $warehouseId = $this->normalizeWarehouseId($id);
            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'code' => [
                    'sometimes',
                    'string',
                    'max:50',
                    Rule::unique('organization_warehouses', 'code')
                        ->where('organization_id', $organizationId)
                        ->ignore($warehouseId),
                ],
                'warehouse_type' => 'sometimes|in:central,project,external',
                'description' => 'nullable|string',
                'address' => 'nullable|string',
                'contact_person' => 'nullable|string|max:255',
                'contact_phone' => 'nullable|string|max:50',
                'working_hours' => 'nullable|string|max:255',
                'is_main' => 'sometimes|boolean',
                'is_active' => 'sometimes|boolean',
                'settings' => 'nullable|array',
                'storage_conditions' => 'nullable|array',
            ]);

            $warehouse = $this->findWarehouse($organizationId, $warehouseId);
            $warehouse->update($validated);

            return AdminResponse::success(
                $warehouse->fresh(),
                trans_message('basic_warehouse.warehouse.updated')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.warehouse.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('WarehouseController::update error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'payload' => $request->except(['settings', 'storage_conditions']),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.warehouse.update_error'), 500);
        }
    }

    public function destroy(Request $request, string|int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;
        $warehouseId = is_int($id) ? $id : (ctype_digit($id) ? (int) $id : null);

        try {
            $warehouseId = $this->normalizeWarehouseId($id);
            $warehouse = $this->findWarehouse($organizationId, $warehouseId);
            $warehouse->delete();

            return AdminResponse::success(null, trans_message('basic_warehouse.warehouse.deleted'));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.warehouse.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('WarehouseController::destroy error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.warehouse.delete_error'), 500);
        }
    }

    public function balances(Request $request, string|int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;
        $warehouseId = is_int($id) ? $id : (ctype_digit($id) ? (int) $id : null);

        try {
            $warehouseId = $this->normalizeWarehouseId($id);
            $this->findWarehouse($organizationId, $warehouseId);

            $filters = [
                'warehouse_id' => $warehouseId,
                'asset_type' => $request->input('asset_type'),
                'low_stock' => $request->boolean('low_stock'),
                'project_id' => $request->input('project_id'),
                'location_code' => $request->input('location_code'),
            ];

            $balances = $this->warehouseService->getStockData($organizationId, $filters);

            return AdminResponse::success($balances);
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.warehouse.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('WarehouseController::balances error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'filters' => $request->only(['asset_type', 'low_stock', 'project_id', 'location_code']),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.warehouse.balances_error'), 500);
        }
    }

    public function movements(Request $request, string|int $id): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;
        $warehouseId = is_int($id) ? $id : (ctype_digit($id) ? (int) $id : null);

        try {
            $warehouseId = $this->normalizeWarehouseId($id);
            $this->findWarehouse($organizationId, $warehouseId);

            $filters = [
                'warehouse_id' => $warehouseId,
                'movement_type' => $request->input('movement_type'),
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
            ];

            $movements = $this->warehouseService->getMovementsData($organizationId, $filters);

            return AdminResponse::success($movements);
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.warehouse.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('WarehouseController::movements error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $warehouseId,
                'filters' => $request->only(['movement_type', 'date_from', 'date_to']),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.warehouse.movements_error'), 500);
        }
    }

    private function findWarehouse(int $organizationId, int $warehouseId, array $relations = []): OrganizationWarehouse
    {
        return OrganizationWarehouse::query()
            ->with($relations)
            ->where('organization_id', $organizationId)
            ->findOrFail($warehouseId);
    }

    private function normalizeWarehouseId(string|int $id): int
    {
        if (is_int($id)) {
            return $id;
        }

        if (ctype_digit($id)) {
            return (int) $id;
        }

        throw (new ModelNotFoundException())->setModel(OrganizationWarehouse::class, [$id]);
    }
}
