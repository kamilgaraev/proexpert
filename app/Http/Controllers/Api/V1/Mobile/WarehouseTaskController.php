<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\BusinessModules\Features\BasicWarehouse\Http\Requests\WarehouseTaskStatusRequest;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use App\Services\Mobile\MobileWarehouseTaskService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WarehouseTaskController extends Controller
{
    public function __construct(
        private readonly MobileWarehouseTaskService $taskService
    ) {
    }

    public function index(Request $request, int $warehouseId): JsonResponse
    {
        try {
            return MobileResponse::success(
                $this->taskService->listTasks(
                    (int) $request->user()->current_organization_id,
                    $warehouseId,
                    $request->only([
                        'status',
                        'task_type',
                        'priority',
                        'assigned_to_id',
                        'zone_id',
                        'cell_id',
                        'logistic_unit_id',
                        'material_id',
                        'project_id',
                        'entity_type',
                        'entity_id',
                        'q',
                        'limit',
                    ])
                )
            );
        } catch (ModelNotFoundException) {
            return MobileResponse::error(trans_message('basic_warehouse.task.warehouse_not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('mobile.warehouse.tasks.index.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'warehouse_id' => $warehouseId,
                'filters' => $request->only([
                    'status',
                    'task_type',
                    'priority',
                    'assigned_to_id',
                    'zone_id',
                    'cell_id',
                    'logistic_unit_id',
                    'material_id',
                    'project_id',
                    'entity_type',
                    'entity_id',
                    'q',
                    'limit',
                ]),
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_warehouse.errors.load_failed'), 500);
        }
    }

    public function show(Request $request, int $warehouseId, int $taskId): JsonResponse
    {
        try {
            return MobileResponse::success(
                $this->taskService->getTask(
                    (int) $request->user()->current_organization_id,
                    $warehouseId,
                    $taskId
                )
            );
        } catch (ModelNotFoundException) {
            return MobileResponse::error(trans_message('basic_warehouse.task.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('mobile.warehouse.tasks.show.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'warehouse_id' => $warehouseId,
                'task_id' => $taskId,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_warehouse.errors.load_failed'), 500);
        }
    }

    public function updateStatus(WarehouseTaskStatusRequest $request, int $warehouseId, int $taskId): JsonResponse
    {
        try {
            $validated = $request->validated();

            return MobileResponse::success(
                $this->taskService->updateTaskStatus(
                    (int) $request->user()->current_organization_id,
                    $warehouseId,
                    $taskId,
                    (string) $validated['status'],
                    $request->user()?->id,
                    isset($validated['completed_quantity']) ? (float) $validated['completed_quantity'] : null,
                    isset($validated['notes']) ? (string) $validated['notes'] : null
                )
            );
        } catch (ModelNotFoundException) {
            return MobileResponse::error(trans_message('basic_warehouse.task.not_found'), 404);
        } catch (\InvalidArgumentException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('mobile.warehouse.tasks.update_status.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'warehouse_id' => $warehouseId,
                'task_id' => $taskId,
                'payload' => $request->only(['status', 'completed_quantity', 'notes']),
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_warehouse.errors.load_failed'), 500);
        }
    }
}
