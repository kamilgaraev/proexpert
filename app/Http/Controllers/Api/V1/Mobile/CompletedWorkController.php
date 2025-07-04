<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\CompletedWork\CompletedWorkService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Resources\Api\V1\Admin\CompletedWork\CompletedWorkCollection as CompletedWorkCollectionResource;
use App\Http\Resources\Api\V1\Admin\CompletedWork\CompletedWorkResource;
use App\Http\Requests\Api\V1\Admin\CompletedWork\StoreCompletedWorkRequest;
use App\Http\Requests\Api\V1\Admin\CompletedWork\UpdateCompletedWorkRequest;
use App\Http\Requests\Api\V1\Admin\CompletedWork\SyncCompletedWorkMaterialsRequest;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;

class CompletedWorkController extends Controller
{
    protected CompletedWorkService $completedWorkService;

    public function __construct(CompletedWorkService $completedWorkService)
    {
        $this->completedWorkService = $completedWorkService;
    }

    /**
     * Получить список выполненных работ.
     */
    public function index(Request $request): CompletedWorkCollectionResource
    {
        $organizationId = Auth::user()->current_organization_id;

        $filters = $request->only([
            'project_id',
            'contract_id',
            'work_type_id',
            'user_id',
            'status',
            'completion_date_from',
            'completion_date_to',
            'amount_from',
            'amount_to',
            'quantity_from',
            'quantity_to',
            'with_materials',
            'contractor_id',
            'search',
        ]);
        $filters['organization_id'] = $organizationId;

        $sortBy = $request->query('sortBy', 'completion_date');
        $sortDirection = $request->query('sortDirection', 'desc');
        $perPage = $request->query('perPage', 15);

        $completedWorks = $this->completedWorkService->getAll(
            $filters,
            $perPage,
            $sortBy,
            $sortDirection,
            ['project', 'contract.contractor', 'workType', 'user', 'materials.measurementUnit']
        );

        return new CompletedWorkCollectionResource($completedWorks);
    }

    /**
     * Создать запись о выполненной работе.
     */
    public function store(StoreCompletedWorkRequest $request): JsonResponse
    {
        try {
            $dto = $request->toDto();
            $completedWork = $this->completedWorkService->create($dto);
            return response()->json([
                'success' => true,
                'message' => 'Запись о выполненной работе успешно создана.',
                'data' => new CompletedWorkResource($completedWork->load(['project', 'contract', 'workType', 'user', 'materials.measurementUnit']))
            ], Response::HTTP_CREATED);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Показать детальную информацию о выполненной работе.
     */
    public function show(CompletedWork $completedWork): CompletedWorkResource
    {
        if ($completedWork->organization_id !== Auth::user()->current_organization_id) {
            abort(404, 'Запись о выполненной работе не найдена.');
        }
        return new CompletedWorkResource($completedWork->load(['project', 'contract', 'workType', 'user', 'materials.measurementUnit', 'files']));
    }

    /**
     * Обновить запись о выполненной работе.
     */
    public function update(UpdateCompletedWorkRequest $request, CompletedWork $completedWork): JsonResponse
    {
        try {
            $dto = $request->toDto();
            $updatedWork = $this->completedWorkService->update($completedWork->id, $dto);
            return response()->json([
                'success' => true,
                'message' => 'Запись о выполненной работе успешно обновлена.',
                'data' => new CompletedWorkResource($updatedWork->load(['project', 'contract', 'workType', 'user', 'materials.measurementUnit']))
            ]);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Синхронизация материалов выполненной работы.
     */
    public function syncMaterials(SyncCompletedWorkMaterialsRequest $request, CompletedWork $completedWork): CompletedWorkResource
    {
        $materials = $request->getMaterialsArray();
        $updatedWork = $this->completedWorkService->syncCompletedWorkMaterials($completedWork->id, $materials, $completedWork->organization_id);
        return new CompletedWorkResource($updatedWork->load(['materials.measurementUnit']));
    }

    /**
     * Получить дефолтные материалы для типа работ.
     */
    public function getWorkTypeMaterialDefaults(Request $request): JsonResponse
    {
        $request->validate(['work_type_id' => 'required|integer']);
        $workTypeId = (int) $request->query('work_type_id');
        $organizationId = Auth::user()->current_organization_id;

        $materials = $this->completedWorkService->getWorkTypeMaterialDefaults($workTypeId, $organizationId);

        return response()->json(['success' => true, 'data' => $materials]);
    }
} 