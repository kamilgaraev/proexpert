<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\MeasurementUnit\StoreMeasurementUnitRequest;
use App\Http\Requests\Api\V1\Admin\MeasurementUnit\UpdateMeasurementUnitRequest;
use App\Http\Resources\Api\V1\Admin\MeasurementUnitResource;
use App\Http\Responses\AdminResponse;
use App\Services\MeasurementUnit\MeasurementUnitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class MeasurementUnitController extends Controller
{
    public function __construct(
        protected MeasurementUnitService $measurementUnitService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->getOrganizationId($request);
            if (!$organizationId) {
                return AdminResponse::error(trans_message('measurement_unit.organization_not_found'), 400);
            }

            $measurementUnits = $this->measurementUnitService->getAllMeasurementUnits(
                $organizationId,
                $this->normalizePerPage($request->input('per_page', 15)),
                $this->compactFilters($request->only(['type']))
            );

            return AdminResponse::paginated(
                MeasurementUnitResource::collection($measurementUnits),
                [
                    'current_page' => $measurementUnits->currentPage(),
                    'last_page' => $measurementUnits->lastPage(),
                    'per_page' => $measurementUnits->perPage(),
                    'total' => $measurementUnits->total(),
                ]
            );
        } catch (Throwable $e) {
            Log::error('MeasurementUnitController@index failed', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(trans_message('measurement_unit.internal_error_list'), 500);
        }
    }

    public function store(StoreMeasurementUnitRequest $request): JsonResponse
    {
        try {
            $organizationId = $this->getOrganizationId($request);
            if (!$organizationId) {
                return AdminResponse::error(trans_message('measurement_unit.organization_not_found'), 400);
            }

            $measurementUnit = $this->measurementUnitService->createMeasurementUnit($request->toDto(), $organizationId);

            return AdminResponse::success(new MeasurementUnitResource($measurementUnit), null, Response::HTTP_CREATED);
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 422);
        } catch (Throwable $e) {
            Log::error('MeasurementUnitController@store failed', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(trans_message('measurement_unit.internal_error_create'), 500);
        }
    }

    public function show(int $id, Request $request): JsonResponse
    {
        try {
            $organizationId = $this->getOrganizationId($request);
            if (!$organizationId) {
                return AdminResponse::error(trans_message('measurement_unit.organization_not_found'), 400);
            }

            $measurementUnit = $this->measurementUnitService->getMeasurementUnitById($id, $organizationId);
            if (!$measurementUnit) {
                return AdminResponse::error(trans_message('measurement_unit.not_found'), 404);
            }

            return AdminResponse::success(new MeasurementUnitResource($measurementUnit));
        } catch (Throwable $e) {
            Log::error('MeasurementUnitController@show failed', [
                'id' => $id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(trans_message('measurement_unit.internal_error_get'), 500);
        }
    }

    public function update(UpdateMeasurementUnitRequest $request, int $id): JsonResponse
    {
        try {
            $organizationId = $this->getOrganizationId($request);
            if (!$organizationId) {
                return AdminResponse::error(trans_message('measurement_unit.organization_not_found'), 400);
            }

            $measurementUnit = $this->measurementUnitService->updateMeasurementUnit($id, $request->toDto(), $organizationId);
            if (!$measurementUnit) {
                return AdminResponse::error(trans_message('measurement_unit.update_failed'), 404);
            }

            return AdminResponse::success(new MeasurementUnitResource($measurementUnit));
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 422);
        } catch (Throwable $e) {
            Log::error('MeasurementUnitController@update failed', [
                'id' => $id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(trans_message('measurement_unit.internal_error_update'), 500);
        }
    }

    public function destroy(int $id, Request $request): JsonResponse
    {
        try {
            $organizationId = $this->getOrganizationId($request);
            if (!$organizationId) {
                return AdminResponse::error(trans_message('measurement_unit.organization_not_found'), 400);
            }

            if (!$this->measurementUnitService->deleteMeasurementUnit($id, $organizationId)) {
                return AdminResponse::error(trans_message('measurement_unit.delete_failed'), 404);
            }

            return AdminResponse::success(null, trans_message('measurement_unit.deleted'));
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 422);
        } catch (Throwable $e) {
            Log::error('MeasurementUnitController@destroy failed', [
                'id' => $id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(trans_message('measurement_unit.internal_error_delete'), 500);
        }
    }

    public function getMaterialUnits(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->getOrganizationId($request);
            if (!$organizationId) {
                return AdminResponse::error(trans_message('measurement_unit.organization_not_found'), 400);
            }

            return AdminResponse::success(
                MeasurementUnitResource::collection(
                    $this->measurementUnitService->getMaterialMeasurementUnits($organizationId)
                )
            );
        } catch (Throwable $e) {
            Log::error('MeasurementUnitController@getMaterialUnits failed', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(trans_message('measurement_unit.internal_error_list'), 500);
        }
    }

    private function getOrganizationId(Request $request): ?int
    {
        $organizationId = $request->attributes->get('current_organization_id')
            ?? $request->user()?->current_organization_id;

        return $organizationId ? (int) $organizationId : null;
    }

    private function normalizePerPage(mixed $perPage): int
    {
        $value = (int) $perPage;

        if ($value <= 0) {
            return 1000;
        }

        return min($value, 1000);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function compactFilters(array $filters): array
    {
        return array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
