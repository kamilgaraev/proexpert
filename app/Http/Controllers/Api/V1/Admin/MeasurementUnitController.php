<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\MeasurementUnit\MeasurementUnitService;
use App\Http\Requests\Api\V1\Admin\MeasurementUnit\StoreMeasurementUnitRequest;
use App\Http\Requests\Api\V1\Admin\MeasurementUnit\UpdateMeasurementUnitRequest;
use App\Http\Resources\Api\V1\Admin\MeasurementUnitResource; // Уже существует
use App\Http\Resources\Api\V1\Admin\MeasurementUnitCollection; // Создадим
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
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
        $user = Auth::user();
        if (!$user || !$user->organization_id) {
            return response()->json(['message' => 'Organization ID not found for current user.'], Response::HTTP_BAD_REQUEST);
        }
        $organizationId = $user->organization_id;

        $perPage = $request->input('per_page', 15);
        $filters = $request->only(['type']); // Добавляем фильтры, которые может поддерживать getAllPaginated
        $filters['organization_id'] = $organizationId; // Добавляем organization_id в фильтры для репозитория

        $measurementUnits = $this->measurementUnitService->getAllMeasurementUnits($organizationId, $perPage, $filters);
        return new MeasurementUnitCollection($measurementUnits);
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
        $user = Auth::user();
        if (!$user || !$user->organization_id) {
            return response()->json(['message' => 'Organization ID not found for current user.'], Response::HTTP_BAD_REQUEST);
        }
        $organizationId = $user->organization_id;

        try {
            $dto = $request->toDto();
            $measurementUnit = $this->measurementUnitService->createMeasurementUnit($dto, $organizationId);
            return (new MeasurementUnitResource($measurementUnit))
                    ->response()
                    ->setStatusCode(Response::HTTP_CREATED);
        } catch (Exception $e) {
            return response()->json(['message' => 'Ошибка создания единицы измерения', 'error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
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
    public function show(int $id)
    {
        $user = Auth::user();
        if (!$user || !$user->organization_id) {
            return response()->json(['message' => 'Organization ID not found for current user.'], Response::HTTP_BAD_REQUEST);
        }
        $organizationId = $user->organization_id;
        $measurementUnit = $this->measurementUnitService->getMeasurementUnitById($id, $organizationId);

        if (!$measurementUnit) {
            return response()->json(['message' => 'Единица измерения не найдена'], Response::HTTP_NOT_FOUND);
        }
        return new MeasurementUnitResource($measurementUnit);
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
        $user = Auth::user();
        if (!$user || !$user->organization_id) {
            return response()->json(['message' => 'Organization ID not found for current user.'], Response::HTTP_BAD_REQUEST);
        }
        $organizationId = $user->organization_id;
        try {
            $dto = $request->toDto();
            $measurementUnit = $this->measurementUnitService->updateMeasurementUnit($id, $dto, $organizationId);
            if (!$measurementUnit) {
                return response()->json(['message' => 'Единица измерения не найдена или не может быть обновлена'], Response::HTTP_NOT_FOUND);
            }
            return new MeasurementUnitResource($measurementUnit);
        } catch (Exception $e) {
            return response()->json(['message' => 'Ошибка обновления единицы измерения', 'error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
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
    public function destroy(int $id)
    {
        $user = Auth::user();
        if (!$user || !$user->organization_id) {
            return response()->json(['message' => 'Organization ID not found for current user.'], Response::HTTP_BAD_REQUEST);
        }
        $organizationId = $user->organization_id;
        try {
            $deleted = $this->measurementUnitService->deleteMeasurementUnit($id, $organizationId);
            if (!$deleted) {
                return response()->json(['message' => 'Единица измерения не найдена'], Response::HTTP_NOT_FOUND);
            }
            return response()->json(null, Response::HTTP_NO_CONTENT);
        } catch (Exception $e) {
            // Более специфичные коды ошибок можно вернуть на основе типа Exception
            return response()->json(['message' => 'Ошибка удаления единицы измерения', 'error' => $e->getMessage()], Response::HTTP_FORBIDDEN); // Или HTTP_INTERNAL_SERVER_ERROR
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
        $user = Auth::user();
        if (!$user || !$user->organization_id) {
            return response()->json(['message' => 'Organization ID not found for current user.'], Response::HTTP_BAD_REQUEST);
        }
        $organizationId = $user->organization_id;
        $units = $this->measurementUnitService->getMaterialMeasurementUnits($organizationId);
        return MeasurementUnitResource::collection($units);
    }
} 