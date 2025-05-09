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
use App\Exceptions\BusinessLogicException;

class WorkTypeController extends Controller
{
    protected WorkTypeService $workTypeService;

    public function __construct(WorkTypeService $workTypeService)
    {
        Log::info('[WorkTypeController] Constructor CALLED.');
        $this->workTypeService = $workTypeService;
        // $this->middleware('can:manage-catalogs'); // Удалено согласно указанию
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

    public function store(StoreWorkTypeRequest $request): WorkTypeResource | JsonResponse
    {
        Log::info('[WorkTypeController@store] Method CALLED.');
        try {
            $validatedData = $request->validated();
            Log::info('[WorkTypeController@store] Validation passed.', $validatedData);

            // organization_id уже должен быть добавлен сервисом или быть частью validatedData, 
            // если бы мы его оставили в StoreWorkTypeRequest и заполняли там.
            // Но WorkTypeService->createWorkType сам получает organization_id из request.

            $workType = $this->workTypeService->createWorkType($validatedData, $request); // Передаем $request для getCurrentOrgId в сервисе
            Log::info('[WorkTypeController@store] Service createWorkType returned.', ['workType_id' => $workType->id ?? 'null']);

            if (!$workType) { // Добавим проверку на случай, если сервис может вернуть null
                Log::error('[WorkTypeController@store] Service createWorkType returned null/false.');
                return response()->json(['success' => false, 'message' => 'Failed to create work type after service call.'], 500);
            }

            // Временно возвращаем простой JSON для отладки, чтобы исключить ресурс
            return response()->json([
                'success' => true, 
                'message' => 'Work Type created successfully (raw response)',
                'data' => $workType->load('measurementUnit') // Загружаем связь для полноты ответа
            ], 201);
            
            // Позже вернуть ресурс:
            // return new WorkTypeResource($workType->load('measurementUnit'));

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('[WorkTypeController@store] ValidationException.', ['errors' => $e->errors()]);
            return response()->json(['message' => 'Validation Failed', 'errors' => $e->errors()], 422); // Возвращаем 422
        } catch (BusinessLogicException $e) { // Ловим BusinessLogicException из сервиса
            Log::error('[WorkTypeController@store] BusinessLogicException.', ['error' => $e->getMessage(), 'code' => $e->getCode()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::critical('[WorkTypeController@store] CRITICAL ERROR.', [
                'error_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['success' => false, 'message' => 'Server error during work type creation.'], 500);
        }
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