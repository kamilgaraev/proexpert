<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Services\MeasurementUnit\MeasurementUnitService;
use App\Http\Requests\Api\V1\Admin\MeasurementUnit\StoreMeasurementUnitRequest;
use App\Http\Requests\Api\V1\Admin\MeasurementUnit\UpdateMeasurementUnitRequest;
use App\Http\Resources\Api\V1\Admin\MeasurementUnitResource;
use App\Http\Resources\Api\V1\Admin\MeasurementUnitCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Exception;

class MeasurementUnitController extends Controller
{
    protected MeasurementUnitService $measurementUnitService;

    public function __construct(MeasurementUnitService $measurementUnitService)
    {
        $this->measurementUnitService = $measurementUnitService;
        // Middleware для авторизации (можно добавить политики позже)
        // $this->authorizeResource(MeasurementUnit::class, 'measurement_unit');
    }

    /**
     * Получить ID организации из запроса
     */
    private function getOrganizationId(Request $request): ?int
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        if (!$organizationId) {
            $user = $request->user();
            if ($user && $user->current_organization_id) {
                $organizationId = $user->current_organization_id;
            }
        }
        
        return $organizationId ? (int) $organizationId : null;
    }

    /**
     * @OA\Get(
     *     path="/api/v1/admin/measurement-units",
     *     summary="Список всех единиц измерения для организации",
     *     tags={"Admin/MeasurementUnits"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="page", in="query", @OA\Schema(type="integer", default=1)),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15)),
     *     @OA\Parameter(name="type", in="query", description="Фильтр по типу (material, work, other)", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Успешно", @OA\JsonContent(ref="#/components/schemas/MeasurementUnitCollection")),
     *     @OA\Response(response=401, description="Не авторизован")
     * )
     */
    public function index(Request $request)
    {
        try {
            $organizationId = $this->getOrganizationId($request);
            if (!$organizationId) {
                return AdminResponse::error(trans_message('measurement_unit.organization_not_found'), 400);
            }

            $perPage = $request->input('per_page', 15);
            $filters = $request->only(['type']);

            $measurementUnits = $this->measurementUnitService->getAllMeasurementUnits($organizationId, $perPage, $filters);
            return new MeasurementUnitCollection($measurementUnits);
        } catch (\Throwable $e) {
            Log::error('MeasurementUnitController@index Exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('measurement_unit.internal_error_list'), 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/admin/measurement-units",
     *     summary="Создание новой единицы измерения",
     *     tags={"Admin/MeasurementUnits"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/StoreMeasurementUnitRequest")),
     *     @OA\Response(response=201, description="Создано", @OA\JsonContent(ref="#/components/schemas/MeasurementUnitResource")),
     *     @OA\Response(response=422, description="Ошибка валидации"),
     *     @OA\Response(response=401, description="Не авторизован")
     * )
     */
    public function store(StoreMeasurementUnitRequest $request)
    {
        $organizationId = $this->getOrganizationId($request);
        if (!$organizationId) {
            return AdminResponse::error(trans_message('measurement_unit.organization_not_found'), 400);
        }

        try {
            $dto = $request->toDto();
            $measurementUnit = $this->measurementUnitService->createMeasurementUnit($dto, $organizationId);
            return (new MeasurementUnitResource($measurementUnit))
                    ->response()
                    ->setStatusCode(Response::HTTP_CREATED);
        } catch (Exception $e) {
            Log::error('MeasurementUnitController@store Exception', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('measurement_unit.internal_error_create'), 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/admin/measurement-units/{id}",
     *     summary="Получение информации о конкретной единице измерения",
     *     tags={"Admin/MeasurementUnits"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Успешно", @OA\JsonContent(ref="#/components/schemas/MeasurementUnitResource")),
     *     @OA\Response(response=404, description="Не найдено"),
     *     @OA\Response(response=401, description="Не авторизован")
     * )
     */
    public function show(int $id, Request $request)
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
            return new MeasurementUnitResource($measurementUnit);
        } catch (\Throwable $e) {
            Log::error('MeasurementUnitController@show Exception', [
                'id' => $id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('measurement_unit.internal_error_get'), 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/v1/admin/measurement-units/{id}",
     *     summary="Обновление существующей единицы измерения",
     *     tags={"Admin/MeasurementUnits"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/UpdateMeasurementUnitRequest")),
     *     @OA\Response(response=200, description="Обновлено", @OA\JsonContent(ref="#/components/schemas/MeasurementUnitResource")),
     *     @OA\Response(response=404, description="Не найдено"),
     *     @OA\Response(response=422, description="Ошибка валидации"),
     *     @OA\Response(response=401, description="Не авторизован")
     * )
     */
    public function update(UpdateMeasurementUnitRequest $request, int $id)
    {
        $organizationId = $this->getOrganizationId($request);
        if (!$organizationId) {
            return AdminResponse::error(trans_message('measurement_unit.organization_not_found'), 400);
        }

        try {
            $dto = $request->toDto();
            $measurementUnit = $this->measurementUnitService->updateMeasurementUnit($id, $dto, $organizationId);
            if (!$measurementUnit) {
                return AdminResponse::error(trans_message('measurement_unit.update_failed'), 404);
            }
            return new MeasurementUnitResource($measurementUnit);
        } catch (Exception $e) {
            Log::error('MeasurementUnitController@update Exception', [
                'id' => $id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('measurement_unit.internal_error_update'), 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/admin/measurement-units/{id}",
     *     summary="Удаление единицы измерения",
     *     tags={"Admin/MeasurementUnits"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=204, description="Удалено (нет контента)"),
     *     @OA\Response(response=404, description="Не найдено"),
     *     @OA\Response(response=403, description="Удаление запрещено (например, системная или используемая единица)"),
     *     @OA\Response(response=401, description="Не авторизован")
     * )
     */
    public function destroy(int $id, Request $request): JsonResponse
    {
        $organizationId = $this->getOrganizationId($request);
        if (!$organizationId) {
            return AdminResponse::error(trans_message('measurement_unit.organization_not_found'), 400);
        }

        try {
            $deleted = $this->measurementUnitService->deleteMeasurementUnit($id, $organizationId);
            if (!$deleted) {
                return AdminResponse::error(trans_message('measurement_unit.delete_failed'), 404);
            }
            return AdminResponse::success(null, trans_message('measurement_unit.deleted'));
        } catch (Exception $e) {
            Log::error('MeasurementUnitController@destroy Exception', [
                'id' => $id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('measurement_unit.internal_error_delete'), 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/admin/measurement-units/material-units",
     *     summary="Получение списка единиц измерения, используемых для материалов",
     *     tags={"Admin/MeasurementUnits"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Успешно", @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/MeasurementUnitResource"))),
     *     @OA\Response(response=401, description="Не авторизован")
     * )
     */
    public function getMaterialUnits(Request $request)
    {
        try {
            $organizationId = $this->getOrganizationId($request);
            if (!$organizationId) {
                return AdminResponse::error(trans_message('measurement_unit.organization_not_found'), 400);
            }

            $units = $this->measurementUnitService->getMaterialMeasurementUnits($organizationId);
            return MeasurementUnitResource::collection($units);
        } catch (\Throwable $e) {
            Log::error('MeasurementUnitController@getMaterialUnits Exception', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('measurement_unit.internal_error_list'), 500);
        }
    }
}