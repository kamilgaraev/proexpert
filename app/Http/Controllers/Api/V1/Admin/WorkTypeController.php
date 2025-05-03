<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\WorkType\WorkTypeService;
use App\Http\Requests\Api\V1\Admin\WorkType\StoreWorkTypeRequest;
use App\Http\Requests\Api\V1\Admin\WorkType\UpdateWorkTypeRequest;
use App\Http\Resources\Api\V1\Admin\WorkTypeResource;
use Illuminate\Http\JsonResponse;

class WorkTypeController extends Controller
{
    protected WorkTypeService $workTypeService;

    public function __construct(WorkTypeService $workTypeService)
    {
        $this->workTypeService = $workTypeService;
        // TODO: Добавить middleware для проверки прав ('can:manage_work_types')
    }

    public function index(): JsonResponse
    {
        // TODO: Пагинация, фильтрация, API Resource
        $workTypes = $this->workTypeService->getActiveWorkTypesForCurrentOrg();
        return WorkTypeResource::collection($workTypes)->response();
    }

    public function store(StoreWorkTypeRequest $request): WorkTypeResource
    {
        $workType = $this->workTypeService->createWorkType($request->validated());
        return new WorkTypeResource($workType->load('measurementUnit'));
    }

    public function show(string $id): WorkTypeResource | JsonResponse
    {
        $workType = $this->workTypeService->findWorkTypeById((int)$id);
        if (!$workType) {
            return response()->json(['message' => 'Work type not found'], 404);
        }
        // TODO: Проверка принадлежности (в сервисе)
        // TODO: API Resource
        return new WorkTypeResource($workType->load('measurementUnit'));
    }

    public function update(UpdateWorkTypeRequest $request, string $id): WorkTypeResource | JsonResponse
    {
        $success = $this->workTypeService->updateWorkType((int)$id, $request->validated());
        if (!$success) {
            return response()->json(['message' => 'Work type not found or update failed'], 404);
        }
        $workType = $this->workTypeService->findWorkTypeById((int)$id);
        return new WorkTypeResource($workType->load('measurementUnit'));
    }

    public function destroy(string $id): JsonResponse
    {
        $success = $this->workTypeService->deleteWorkType((int)$id);
        if (!$success) {
            // TODO: Уточнить обработку ошибки (может нельзя удалить из-за связей)
            return response()->json(['message' => 'Work type not found or delete failed'], 404);
        }
        return response()->json(null, 204);
    }
} 