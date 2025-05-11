<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\WorkType\WorkTypeService;
use App\Http\Resources\Api\V1\Mobile\WorkType\MobileWorkTypeResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Exceptions\BusinessLogicException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

class WorkTypeController extends Controller
{
    protected WorkTypeService $workTypeService;

    public function __construct(WorkTypeService $workTypeService)
    {
        $this->workTypeService = $workTypeService;
    }

    /**
     * Получить список видов работ для текущей организации пользователя (прораба).
     * Возвращает все активные виды работ, не пагинированные.
     */
    public function index(Request $request): AnonymousResourceCollection | JsonResponse
    {
        try {
            // Используем существующий метод сервиса, который получает активные виды работ для текущей организации
            // Auth::user()->current_organization_id будет неявно использован сервисом через Request $request
            // или напрямую через Auth::user() в сервисе.
            // getActiveWorkTypesForCurrentOrg должен загружать 'measurementUnit' для ресурса.
            $workTypes = $this->workTypeService->getActiveWorkTypesForCurrentOrg($request); // Передаем Request
            
            return MobileWorkTypeResource::collection($workTypes);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('[Mobile\WorkTypeController@index] Error fetching work types for mobile', [
                'user_id' => $request->user()?->id,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return response()->json(['success' => false, 'message' => 'Внутренняя ошибка сервера при получении списка видов работ.'], 500);
        }
    }
} 