<?php

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

use function trans_message;

class ProjectAllocationController extends Controller
{
    public function __construct(
        protected WarehouseService $warehouseService
    ) {}

    public function allocate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'warehouse_id' => 'required|exists:organization_warehouses,id',
                'material_id' => 'required|exists:materials,id',
                'project_id' => 'required|exists:projects,id',
                'quantity' => 'required|numeric|min:0.001',
                'notes' => 'nullable|string',
            ]);
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('errors.validation_failed'), 422, $e->errors());
        }

        $organizationId = $request->user()->current_organization_id;

        DB::beginTransaction();

        try {
            $balance = $this->warehouseService->getAssetBalance(
                $organizationId,
                $validated['warehouse_id'],
                $validated['material_id']
            );

            if (!$balance) {
                DB::rollBack();

                return AdminResponse::error(
                    trans_message('basic_warehouse.project_allocations.material_not_in_warehouse'),
                    422,
                    null,
                    ['error_code' => 'MATERIAL_NOT_IN_WAREHOUSE']
                );
            }

            if ($balance->availableQuantity <= 0) {
                DB::rollBack();

                return AdminResponse::error(
                    trans_message('basic_warehouse.project_allocations.insufficient_stock'),
                    422,
                    null,
                    [
                        'error_code' => 'INSUFFICIENT_STOCK',
                        'available_quantity' => 0,
                    ]
                );
            }

            $availabilityCheck = $balance->checkAllocationAvailability($validated['quantity']);

            if (!$availabilityCheck['can_allocate']) {
                DB::rollBack();

                return AdminResponse::error(
                    trans_message('basic_warehouse.project_allocations.insufficient_available_quantity', [
                        'quantity' => $availabilityCheck['available_for_allocation'],
                    ]),
                    422,
                    null,
                    [
                        'error_code' => 'INSUFFICIENT_AVAILABLE_QUANTITY',
                        'details' => $availabilityCheck,
                    ]
                );
            }

            $allocation = WarehouseProjectAllocation::firstOrNew([
                'warehouse_id' => $validated['warehouse_id'],
                'material_id' => $validated['material_id'],
                'project_id' => $validated['project_id'],
            ]);

            if ($allocation->exists) {
                $allocation->allocated_quantity += $validated['quantity'];
            } else {
                $allocation->organization_id = $organizationId;
                $allocation->allocated_quantity = $validated['quantity'];
            }

            $allocation->allocated_by_user_id = $request->user()->id;
            $allocation->allocated_at = now();
            $allocation->notes = $validated['notes'] ?? $allocation->notes;
            $allocation->save();

            DB::commit();

            return AdminResponse::success(
                $allocation->load(['project', 'material', 'warehouse']),
                trans_message('basic_warehouse.project_allocations.created'),
                201
            );
        } catch (ModelNotFoundException) {
            DB::rollBack();

            return AdminResponse::error(
                trans_message('basic_warehouse.project_allocations.material_not_in_warehouse'),
                422,
                null,
                ['error_code' => 'MATERIAL_NOT_IN_WAREHOUSE']
            );
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('warehouse.project_allocations.allocate.error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'payload' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.project_allocations.create_error'), 500);
        }
    }

    public function deallocate(Request $request, int $allocationId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'quantity' => 'nullable|numeric|min:0.001',
            ]);
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('errors.validation_failed'), 422, $e->errors());
        }

        $organizationId = $request->user()->current_organization_id;

        DB::beginTransaction();

        try {
            $allocation = WarehouseProjectAllocation::where('organization_id', $organizationId)
                ->findOrFail($allocationId);

            if (isset($validated['quantity'])) {
                if ($validated['quantity'] > $allocation->allocated_quantity) {
                    DB::rollBack();

                    return AdminResponse::error(
                        trans_message('basic_warehouse.project_allocations.quantity_exceeds_allocated'),
                        422
                    );
                }

                $allocation->allocated_quantity -= $validated['quantity'];

                if ($allocation->allocated_quantity <= 0) {
                    $allocation->delete();
                } else {
                    $allocation->save();
                }
            } else {
                $allocation->delete();
            }

            DB::commit();

            return AdminResponse::success(null, trans_message('basic_warehouse.project_allocations.deleted'));
        } catch (ModelNotFoundException) {
            DB::rollBack();

            return AdminResponse::error(trans_message('basic_warehouse.project_allocations.not_found'), 404);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('warehouse.project_allocations.deallocate.error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'allocation_id' => $allocationId,
                'payload' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.project_allocations.delete_error'), 500);
        }
    }

    public function getProjectAllocations(Request $request, int $projectId): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;

            $allocations = WarehouseProjectAllocation::where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->with(['warehouse', 'material.measurementUnit', 'allocatedBy'])
                ->get();

            return AdminResponse::success($allocations->map(static function (WarehouseProjectAllocation $allocation): array {
                return [
                    'id' => $allocation->id,
                    'warehouse_id' => $allocation->warehouse_id,
                    'warehouse_name' => $allocation->warehouse->name,
                    'material_id' => $allocation->material_id,
                    'material_name' => $allocation->material->name,
                    'material_code' => $allocation->material->code,
                    'allocated_quantity' => (float) $allocation->allocated_quantity,
                    'measurement_unit' => $allocation->material->measurementUnit->short_name ?? null,
                    'allocated_by' => $allocation->allocatedBy->name ?? null,
                    'allocated_at' => $allocation->allocated_at?->toDateTimeString(),
                    'notes' => $allocation->notes,
                ];
            }));
        } catch (\Exception $e) {
            Log::error('warehouse.project_allocations.index.error', [
                'organization_id' => $request->user()?->current_organization_id,
                'user_id' => $request->user()?->id,
                'project_id' => $projectId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.project_allocations.list_error'), 500);
        }
    }
}
