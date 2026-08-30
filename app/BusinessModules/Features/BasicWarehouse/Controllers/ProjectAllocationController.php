<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Exceptions\ProjectAllocationException;
use App\BusinessModules\Features\BasicWarehouse\Exceptions\WarehouseOperationIdempotencyConflictException;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\RemoveProjectAllocationRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\StoreProjectAllocationRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Resources\ProjectMaterialDeliveryResource;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation;
use App\BusinessModules\Features\BasicWarehouse\Services\ProjectAllocationService;
use App\BusinessModules\Features\BasicWarehouse\Services\ProjectMaterialDeliveryService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use function trans_message;

class ProjectAllocationController extends Controller
{
    public function __construct(
        protected ProjectAllocationService $allocationService,
        protected ProjectMaterialDeliveryService $deliveryService
    ) {}

    public function allocate(StoreProjectAllocationRequest $request): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;
        $validated = $request->validated();

        try {
            $result = $this->allocationService->allocate(
                $organizationId,
                $request->user(),
                $validated,
            );

            return AdminResponse::success(
                [
                    'allocation' => $result->allocation,
                    'delivery' => new ProjectMaterialDeliveryResource($result->delivery),
                ],
                trans_message('basic_warehouse.project_allocations.created'),
                201
            );
        } catch (ProjectAllocationException $exception) {
            return AdminResponse::error(
                $exception->getMessage(),
                422,
                null,
                array_merge(['error_code' => $exception->errorCode], $exception->details ?? []),
            );
        } catch (WarehouseOperationIdempotencyConflictException $exception) {
            return AdminResponse::error($exception->getMessage(), 409);
        } catch (ModelNotFoundException) {
            return AdminResponse::error(
                trans_message('basic_warehouse.project_allocations.material_not_in_warehouse'),
                422,
                null,
                ['error_code' => 'MATERIAL_NOT_IN_WAREHOUSE'],
            );
        } catch (\Throwable $exception) {

            Log::error('warehouse.project_allocations.allocate.error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $validated['warehouse_id'] ?? null,
                'material_id' => $validated['material_id'] ?? null,
                'project_id' => $validated['project_id'] ?? null,
                'exception' => $exception,
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.project_allocations.create_error'), 500);
        }
    }

    public function deallocate(RemoveProjectAllocationRequest $request, int $allocationId): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;
        $validated = $request->validated();

        try {
            $this->allocationService->deallocate(
                $organizationId,
                $request->user(),
                $allocationId,
                $validated,
            );

            return AdminResponse::success(null, trans_message('basic_warehouse.project_allocations.deleted'));
        } catch (ProjectAllocationException $exception) {
            return AdminResponse::error(
                $exception->getMessage(),
                422,
                null,
                array_merge(['error_code' => $exception->errorCode], $exception->details ?? []),
            );
        } catch (WarehouseOperationIdempotencyConflictException $exception) {
            return AdminResponse::error($exception->getMessage(), 409);
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.project_allocations.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('warehouse.project_allocations.deallocate.error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'allocation_id' => $allocationId,
                'exception' => $exception,
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
