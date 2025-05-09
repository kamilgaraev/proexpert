<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\WorkType\WorkTypeService;
use App\Http\Requests\Api\V1\Admin\WorkType\StoreWorkTypeRequest;
use App\Http\Requests\Api\V1\Admin\WorkType\UpdateWorkTypeRequest;
use App\Http\Resources\Api\V1\Admin\WorkTypeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

class WorkTypeController extends Controller
{
    protected WorkTypeService $workTypeService;

    public function __construct(WorkTypeService $workTypeService)
    {
        Log::info('[WorkTypeController] Constructor CALLED.');
        $this->workTypeService = $workTypeService;
        // Временно закомментируем для проверки
        // $this->middleware('can:manage-catalogs'); 
        Log::info('[WorkTypeController] Constructor FINISHED.');
    }

    public function index(Request $request): JsonResponse
    {
        Log::info('[WorkTypeController@index] Method CALLED.');
        $perPage = $request->query('per_page', 15);
        $workTypes = $this->workTypeService->getWorkTypesPaginated($request, (int)$perPage);
        Log::info('[WorkTypeController@index] Received from service.', ['workTypes_class' => get_class($workTypes), 'workTypes_total' => $workTypes->total()]);
        return response()->json($workTypes);
    }

    public function store(StoreWorkTypeRequest $request): WorkTypeResource
    {
        $workType = $this->workTypeService->createWorkType($request->validated(), $request);
        return new WorkTypeResource($workType->load('measurementUnit'));
    }

    public function show(Request $request, string $id): WorkTypeResource | JsonResponse
    {
        $workType = $this->workTypeService->findWorkTypeById((int)$id, $request);
        if (!$workType) {
            return response()->json(['message' => 'Work type not found'], 404);
        }
        return new WorkTypeResource($workType->load('measurementUnit'));
    }

    public function update(UpdateWorkTypeRequest $request, string $id): WorkTypeResource | JsonResponse
    {
        $success = $this->workTypeService->updateWorkType((int)$id, $request->validated(), $request);
        if (!$success) {
            return response()->json(['message' => 'Work type not found or update failed'], 404);
        }
        $workType = $this->workTypeService->findWorkTypeById((int)$id, $request);
        return new WorkTypeResource($workType->load('measurementUnit'));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $success = $this->workTypeService->deleteWorkType((int)$id, $request);
        if (!$success) {
            return response()->json(['message' => 'Work type not found or delete failed'], 404);
        }
        return response()->json(null, 204);
    }
} 