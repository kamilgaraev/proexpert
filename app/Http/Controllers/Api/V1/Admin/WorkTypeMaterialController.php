<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\WorkType;
use App\Models\Material;
use App\Services\WorkTypeMaterial\WorkTypeMaterialService; 
use App\Http\Requests\Api\V1\Admin\WorkTypeMaterial\StoreWorkTypeMaterialRequest; 
use App\Http\Resources\Api\V1\Admin\Material\MaterialMiniResource; 
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use App\Exceptions\BusinessLogicException;
use Illuminate\Validation\ValidationException;

class WorkTypeMaterialController extends Controller
{
    protected WorkTypeMaterialService $workTypeMaterialService;

    public function __construct(WorkTypeMaterialService $workTypeMaterialService)
    {
        $this->workTypeMaterialService = $workTypeMaterialService;
        // Middleware для прав, например, 'can:manage-work-types' или специфичное 
        // $this->middleware('can:manage-work-material-links');
    }

    /**
     * Получить список материалов, привязанных к виду работ.
     */
    public function indexForWorkType(Request $request, WorkType $workType): JsonResponse
    {
        // Проверка, что вид работ принадлежит текущей организации (если сервис не делает это)
        if ($workType->organization_id !== $this->workTypeMaterialService->getCurrentOrgId($request)) {
            return response()->json(['success' => false, 'message' => 'Вид работ не найден в вашей организации.'], Response::HTTP_FORBIDDEN);
        }
        
        $materials = $this->workTypeMaterialService->getMaterialsForWorkType($workType);
        return MaterialMiniResource::collection($materials)->response(); // Используем MaterialMiniResource или создадим WorkTypeMaterialResource
    }

    /**
     * Привязать материал к виду работ или обновить существующую связь.
     * Принимает массив материалов для привязки.
     */
    public function storeOrUpdateForWorkType(StoreWorkTypeMaterialRequest $request, WorkType $workType): JsonResponse
    {
        if ($workType->organization_id !== $this->workTypeMaterialService->getCurrentOrgId($request)) {
            return response()->json(['success' => false, 'message' => 'Вид работ не найден в вашей организации.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $dtos = $request->toDtos(); // Предполагаем, что Request вернет массив DTO
            $this->workTypeMaterialService->syncMaterialsForWorkType($workType, $dtos);
            
            // Возвращаем обновленный список материалов для этого вида работ
            $materials = $this->workTypeMaterialService->getMaterialsForWorkType($workType);
            return MaterialMiniResource::collection($materials)->response();
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Отвязать материал от вида работ.
     */
    public function destroyForWorkType(Request $request, WorkType $workType, Material $material): JsonResponse
    {
        if ($workType->organization_id !== $this->workTypeMaterialService->getCurrentOrgId($request) || 
            $material->organization_id !== $this->workTypeMaterialService->getCurrentOrgId($request) ) {
            return response()->json(['success' => false, 'message' => 'Ресурс не найден в вашей организации.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->workTypeMaterialService->removeMaterialFromWorkType($workType, $material);
            return response()->json(null, Response::HTTP_NO_CONTENT);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Получить предложенные материалы для указанного вида работ и их количества.
     */
    public function getSuggestedMaterialsForWorkType(Request $request, WorkType $workType): JsonResponse
    {
        $organizationId = $this->workTypeMaterialService->getCurrentOrgId($request);
        if ($workType->organization_id !== $organizationId) {
            return response()->json(['success' => false, 'message' => 'Вид работ не найден в вашей организации.'], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'quantity' => 'required|numeric|min:0.0001',
        ]);

        try {
            $suggestedMaterials = $this->workTypeMaterialService->getSuggestedMaterials(
                $workType->id,
                (float)$validated['quantity'],
                $organizationId
            );
            return response()->json(['success' => true, 'data' => $suggestedMaterials]);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
} 