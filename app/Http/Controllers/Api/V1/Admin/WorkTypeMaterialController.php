<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\WorkType;
use App\Models\Material;
use App\Services\WorkTypeMaterial\WorkTypeMaterialService; 
use App\Http\Requests\Api\V1\Admin\WorkTypeMaterial\StoreWorkTypeMaterialRequest; 
use App\Http\Resources\Api\V1\Admin\Material\MaterialMiniResource; 
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
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
    public function indexForWorkType(Request $request, WorkType $workType)
    {
        try {
            if ($workType->organization_id !== $this->workTypeMaterialService->getCurrentOrgId($request)) {
                return AdminResponse::error(trans_message('work_type.not_found_in_organization'), 403);
            }
            
            $materials = $this->workTypeMaterialService->getMaterialsForWorkType($workType);
            return MaterialMiniResource::collection($materials);
        } catch (\Throwable $e) {
            Log::error('WorkTypeMaterialController@indexForWorkType Exception', [
                'work_type_id' => $workType->id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('work_type.internal_error_list'), 500);
        }
    }

    /**
     * Привязать материал к виду работ или обновить существующую связь.
     * Принимает массив материалов для привязки.
     */
    public function storeOrUpdateForWorkType(StoreWorkTypeMaterialRequest $request, WorkType $workType)
    {
        if ($workType->organization_id !== $this->workTypeMaterialService->getCurrentOrgId($request)) {
            return AdminResponse::error(trans_message('work_type.not_found_in_organization'), 403);
        }

        try {
            $dtos = $request->toDtos();
            $this->workTypeMaterialService->syncMaterialsForWorkType($workType, $dtos);
            
            $materials = $this->workTypeMaterialService->getMaterialsForWorkType($workType);
            return MaterialMiniResource::collection($materials);
        } catch (BusinessLogicException $e) {
            Log::error('WorkTypeMaterialController@storeOrUpdateForWorkType BusinessLogicException', [
                'work_type_id' => $workType->id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('WorkTypeMaterialController@storeOrUpdateForWorkType Exception', [
                'work_type_id' => $workType->id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('work_type.internal_error_update'), 500);
        }
    }

    /**
     * Отвязать материал от вида работ.
     */
    public function destroyForWorkType(Request $request, WorkType $workType, Material $material): JsonResponse
    {
        if ($workType->organization_id !== $this->workTypeMaterialService->getCurrentOrgId($request) || 
            $material->organization_id !== $this->workTypeMaterialService->getCurrentOrgId($request) ) {
            return AdminResponse::error(trans_message('work_type.resource_not_found_in_organization'), 403);
        }

        try {
            $this->workTypeMaterialService->removeMaterialFromWorkType($workType, $material);
            return AdminResponse::success(null, trans_message('work_type.material_removed'));
        } catch (BusinessLogicException $e) {
            Log::error('WorkTypeMaterialController@destroyForWorkType BusinessLogicException', [
                'work_type_id' => $workType->id,
                'material_id' => $material->id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('WorkTypeMaterialController@destroyForWorkType Exception', [
                'work_type_id' => $workType->id,
                'material_id' => $material->id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('work_type.internal_error_delete'), 500);
        }
    }

    /**
     * Получить предложенные материалы для указанного вида работ и их количества.
     */
    public function getSuggestedMaterialsForWorkType(Request $request, WorkType $workType): JsonResponse
    {
        $organizationId = $this->workTypeMaterialService->getCurrentOrgId($request);
        if ($workType->organization_id !== $organizationId) {
            return AdminResponse::error(trans_message('work_type.not_found_in_organization'), 403);
        }

        $validated = $request->validate([
            'quantity' => 'required|numeric|min:0.0001',
        ], [
            'quantity.required' => trans_message('work_type.quantity_required'),
        ]);

        try {
            $suggestedMaterials = $this->workTypeMaterialService->getSuggestedMaterials(
                $workType->id,
                (float)$validated['quantity'],
                $organizationId
            );
            return AdminResponse::success($suggestedMaterials, trans_message('work_type.suggested_materials_retrieved'));
        } catch (BusinessLogicException $e) {
            Log::error('WorkTypeMaterialController@getSuggestedMaterialsForWorkType BusinessLogicException', [
                'work_type_id' => $workType->id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('errors.validation_failed'), 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('WorkTypeMaterialController@getSuggestedMaterialsForWorkType Exception', [
                'work_type_id' => $workType->id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('work_type.internal_error_list'), 500);
        }
    }
} 