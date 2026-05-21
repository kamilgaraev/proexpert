<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Http\Controllers;

use App\BusinessModules\Features\MachineryOperations\Http\Resources\MachineryAssetResource;
use App\BusinessModules\Features\MachineryOperations\Http\Resources\MachineryOperationRecordResource;
use App\BusinessModules\Features\MachineryOperations\Http\Resources\MachineryShiftReportResource;
use App\BusinessModules\Features\MachineryOperations\Services\MachineryOperationsService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class MachineryOperationsController extends Controller
{
    public function __construct(
        private readonly MachineryOperationsService $service,
    ) {
    }

    public function assets(Request $request): JsonResponse
    {
        try {
            return $this->paginated($this->service->paginateAssets(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                $request->only(['project_id', 'status'])
            ), MachineryAssetResource::class);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'assets.index');
        }
    }

    public function storeAsset(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate($this->assetRules());

            return AdminResponse::success(
                new MachineryAssetResource($this->service->createAsset(
                    (int) $request->attributes->get('current_organization_id'),
                    $validated
                )),
                trans_message('machinery_operations.messages.asset_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'assets.store');
        }
    }

    public function assignAsset(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => ['required', 'integer'],
                'schedule_task_id' => ['nullable', 'integer'],
                'planned_start_at' => ['required', 'date'],
                'planned_end_at' => ['nullable', 'date', 'after:planned_start_at'],
                'planned_hours' => ['nullable', 'numeric', 'min:0'],
                'comment' => ['nullable', 'string', 'max:2000'],
            ]);
            $asset = $this->findAssetOrFail($request, $id);

            return AdminResponse::success(new MachineryOperationRecordResource($this->service->assignAsset(
                $asset,
                (int) $request->user()?->id,
                $validated
            )));
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'assets.assign');
        }
    }

    public function startOperation(Request $request, int $id): JsonResponse
    {
        return $this->assetAction($request, $id, fn ($asset) => $this->service->startOperation($asset));
    }

    public function setMaintenance(Request $request, int $id): JsonResponse
    {
        return $this->assetAction($request, $id, fn ($asset) => $this->service->setMaintenance($asset));
    }

    public function setUnavailable(Request $request, int $id): JsonResponse
    {
        return $this->assetAction($request, $id, fn ($asset) => $this->service->setUnavailable($asset));
    }

    public function returnAvailable(Request $request, int $id): JsonResponse
    {
        return $this->assetAction($request, $id, fn ($asset) => $this->service->returnAvailable($asset));
    }

    public function archiveAsset(Request $request, int $id): JsonResponse
    {
        return $this->assetAction($request, $id, fn ($asset) => $this->service->archiveAsset($asset));
    }

    public function shifts(Request $request): JsonResponse
    {
        try {
            return $this->paginated($this->service->paginateShifts(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                $request->only(['project_id', 'asset_id', 'status'])
            ), MachineryShiftReportResource::class);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'shifts.index');
        }
    }

    public function storeShift(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate($this->shiftRules());

            return AdminResponse::success(
                new MachineryShiftReportResource($this->service->createShiftReport(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('machinery_operations.messages.shift_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'shifts.store');
        }
    }

    public function submitShift(Request $request, int $id): JsonResponse
    {
        return $this->shiftAction($request, $id, fn ($shift) => $this->service->submitShift($shift));
    }

    public function approveShift(Request $request, int $id): JsonResponse
    {
        return $this->shiftAction($request, $id, fn ($shift) => $this->service->approveShift($shift, (int) $request->user()?->id));
    }

    public function rejectShift(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->shiftAction($request, $id, fn ($shift) => $this->service->rejectShift($shift, (int) $request->user()?->id, $validated['reason']));
    }

    public function storeDowntime(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'asset_id' => ['required', 'integer'],
                'project_id' => ['required', 'integer'],
                'shift_report_id' => ['nullable', 'integer'],
                'reason' => ['required', 'string', 'max:80'],
                'started_at' => ['required', 'date'],
                'ended_at' => ['nullable', 'date', 'after:started_at'],
                'duration_minutes' => ['required', 'integer', 'min:1'],
                'comment' => ['nullable', 'string', 'max:2000'],
            ]);

            return AdminResponse::success(
                new MachineryOperationRecordResource($this->service->createDowntime(
                    (int) $request->attributes->get('current_organization_id'),
                    $validated
                )),
                trans_message('machinery_operations.messages.downtime_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'downtime.store');
        }
    }

    public function storeFuelIssue(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate($this->fuelRules());

            return AdminResponse::success(
                new MachineryOperationRecordResource($this->service->createFuelIssue(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('machinery_operations.messages.fuel_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'fuel.store');
        }
    }

    public function maintenanceOrders(Request $request): JsonResponse
    {
        try {
            return $this->paginated($this->service->paginateMaintenanceOrders(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                $request->only(['project_id', 'asset_id', 'status'])
            ), MachineryOperationRecordResource::class);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'maintenance.index');
        }
    }

    public function storeMaintenanceOrder(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'asset_id' => ['required', 'integer'],
                'project_id' => ['nullable', 'integer'],
                'title' => ['required', 'string', 'max:255'],
                'maintenance_type' => ['nullable', 'string', 'max:80'],
                'priority' => ['nullable', 'string', Rule::in(['low', 'normal', 'high', 'urgent'])],
                'description' => ['nullable', 'string', 'max:5000'],
                'planned_at' => ['nullable', 'date'],
                'cost' => ['nullable', 'numeric', 'min:0'],
            ]);

            return AdminResponse::success(
                new MachineryOperationRecordResource($this->service->createMaintenanceOrder(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('machinery_operations.messages.maintenance_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'maintenance.store');
        }
    }

    public function completeMaintenanceOrder(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate(['completion_comment' => ['nullable', 'string', 'max:2000']]);
            $order = $this->service->findMaintenanceOrder((int) $request->attributes->get('current_organization_id'), $id);

            if ($order === null) {
                return AdminResponse::error(trans_message('machinery_operations.errors.maintenance_not_found'), 404);
            }

            return AdminResponse::success(new MachineryOperationRecordResource($this->service->completeMaintenanceOrder(
                $order,
                (int) $request->user()?->id,
                $validated['completion_comment'] ?? null
            )));
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'maintenance.complete');
        }
    }

    public function reports(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->reports(
                (int) $request->attributes->get('current_organization_id'),
                $request->only(['project_id'])
            ));
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'reports');
        }
    }

    private function assetAction(Request $request, int $id, callable $action): JsonResponse
    {
        try {
            return AdminResponse::success(new MachineryAssetResource($action($this->findAssetOrFail($request, $id))));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'asset.action');
        }
    }

    private function shiftAction(Request $request, int $id, callable $action): JsonResponse
    {
        try {
            $shift = $this->service->findShift((int) $request->attributes->get('current_organization_id'), $id);

            if ($shift === null) {
                return AdminResponse::error(trans_message('machinery_operations.errors.shift_not_found'), 404);
            }

            return AdminResponse::success(new MachineryShiftReportResource($action($shift)));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'shift.action');
        }
    }

    private function findAssetOrFail(Request $request, int $id)
    {
        $asset = $this->service->findAsset((int) $request->attributes->get('current_organization_id'), $id);

        if ($asset === null) {
            throw new DomainException(trans_message('machinery_operations.errors.asset_not_found'));
        }

        return $asset;
    }

    private function paginated(LengthAwarePaginator $paginator, string $resourceClass): JsonResponse
    {
        return AdminResponse::paginated($resourceClass::collection($paginator->getCollection()), [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    private function failed(Request $request, \Throwable $exception, string $scope): JsonResponse
    {
        Log::error("machinery_operations.{$scope}.error", [
            'user_id' => $request->user()?->id,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'error' => $exception->getMessage(),
        ]);

        return AdminResponse::error(trans_message('machinery_operations.errors.action_failed'), 500);
    }

    private function assetRules(): array
    {
        return [
            'machinery_id' => ['nullable', 'integer'],
            'current_project_id' => ['nullable', 'integer'],
            'current_schedule_task_id' => ['nullable', 'integer'],
            'asset_code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:255'],
            'inventory_number' => ['nullable', 'string', 'max:120'],
            'ownership_type' => ['nullable', 'string', Rule::in(['owned', 'leased', 'subcontractor'])],
            'operating_cost_per_hour' => ['nullable', 'numeric', 'min:0'],
            'fuel_type' => ['nullable', 'string', 'max:80'],
            'fuel_consumption_rate' => ['nullable', 'numeric', 'min:0'],
            'meter_hours' => ['nullable', 'numeric', 'min:0'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    private function shiftRules(): array
    {
        return [
            'asset_id' => ['required', 'integer'],
            'project_id' => ['required', 'integer'],
            'assignment_id' => ['nullable', 'integer'],
            'report_date' => ['required', 'date', 'before_or_equal:today'],
            'planned_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'actual_hours' => ['required', 'numeric', 'min:0', 'max:24'],
            'fuel_consumed' => ['required', 'numeric', 'min:0'],
            'meter_start' => ['nullable', 'numeric', 'min:0'],
            'meter_end' => ['nullable', 'numeric', 'min:0'],
            'work_description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    private function fuelRules(): array
    {
        return [
            'asset_id' => ['required', 'integer'],
            'project_id' => ['required', 'integer'],
            'issued_at' => ['required', 'date'],
            'fuel_type' => ['required', 'string', 'max:80'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'unit' => ['required', 'string', 'max:20'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
