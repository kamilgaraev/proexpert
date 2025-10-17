<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\CompletedWork\CompletedWorkService;
use App\Http\Requests\Api\V1\Admin\CompletedWork\StoreCompletedWorkRequest;
use App\Http\Requests\Api\V1\Admin\CompletedWork\UpdateCompletedWorkRequest;
use App\Http\Requests\Api\V1\Admin\CompletedWork\SyncCompletedWorkMaterialsRequest;
use App\Http\Resources\Api\V1\Admin\CompletedWork\CompletedWorkResource;
use App\Http\Resources\Api\V1\Admin\CompletedWork\CompletedWorkCollection;
use App\Http\Resources\Api\V1\Admin\CompletedWork\CompletedWorkMaterialResource;
use App\Http\Middleware\ProjectContextMiddleware;
use App\Models\CompletedWork;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use App\Exceptions\BusinessLogicException;
use Illuminate\Support\Facades\Auth;

class CompletedWorkController extends Controller
{
    protected CompletedWorkService $completedWorkService;

    public function __construct(CompletedWorkService $completedWorkService)
    {
        $this->completedWorkService = $completedWorkService;
        // Middleware для прав доступа, например, $this->middleware('can:manage-completed-works');
    }

    public function index(Request $request): CompletedWorkCollection
    {
        $organizationId = Auth::user()->current_organization_id;
        
        // Получаем project_id из URL (обязательный параметр для project-based маршрутов)
        $projectId = $request->route('project');
        
        // Расширенная фильтрация выполненных работ
        $filters = $request->only([
            'contract_id',          // По контракту  
            'work_type_id',         // По типу работ
            'user_id',              // По прорабу/исполнителю
            'status',               // По статусу (pending, confirmed, rejected)
            'completion_date_from', // Дата выполнения от
            'completion_date_to',   // Дата выполнения до
            'amount_from',          // Сумма работы от
            'amount_to',            // Сумма работы до
            'quantity_from',        // Количество от
            'quantity_to',          // Количество до
            'with_materials',       // Только работы с материалами (boolean)
            'contractor_id',        // По подрядчику (через контракт)
            'search',               // Поиск по описанию/комментарию
        ]);
        
        $filters['organization_id'] = $organizationId;
        // ЖЕСТКО устанавливаем project_id из URL (игнорируем любые другие значения)
        $filters['project_id'] = $projectId;
        
        $sortBy = $request->query('sortBy', 'completion_date');
        $sortDirection = $request->query('sortDirection', 'desc');
        $perPage = $request->query('perPage', 15);

        $completedWorks = $this->completedWorkService->getAll(
            $filters, 
            $perPage, 
            $sortBy, 
            $sortDirection, 
            ['project', 'contract.contractor', 'workType', 'user', 'contractor', 'materials.measurementUnit']
        );
        
        return new CompletedWorkCollection($completedWorks);
    }

    public function store(StoreCompletedWorkRequest $request): JsonResponse
    {
        try {
            $dto = $request->toDto();
            
            // Получаем ProjectContext если доступен (для project-based routes)
            $projectContext = ProjectContextMiddleware::getProjectContext($request);
            
            $completedWork = $this->completedWorkService->create($dto, $projectContext);
            
            return response()->json(
                [
                    'success' => true, 
                    'message' => 'Запись о выполненной работе успешно создана.',
                    'data' => new CompletedWorkResource($completedWork->load(['project', 'contract', 'workType', 'user', 'contractor', 'materials.measurementUnit']))
                ],
                Response::HTTP_CREATED
            );
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function show(CompletedWork $completedWork): CompletedWorkResource // Используем Route Model Binding
    {
        // Проверка принадлежности организации
        if ($completedWork->organization_id !== Auth::user()->current_organization_id) {
            abort(404, 'Запись о выполненной работе не найдена.');
        }
        return new CompletedWorkResource($completedWork->load(['project', 'contract', 'workType', 'user', 'contractor', 'materials.measurementUnit', 'files']));
    }

    public function update(UpdateCompletedWorkRequest $request, CompletedWork $completedWork): JsonResponse
    {
        // Проверка принадлежности организации (FormRequest делает это через $this->route('completed_work')->organization_id)
        if ($completedWork->organization_id !== Auth::user()->current_organization_id) {
             abort(403, 'Это действие не авторизовано.');
        }

        try {
            $dto = $request->toDto(); // DTO содержит ID и organization_id из существующей модели
            $updatedWork = $this->completedWorkService->update($completedWork->id, $dto);
            return response()->json(
                [
                    'success' => true, 
                    'message' => 'Запись о выполненной работе успешно обновлена.',
                    'data' => new CompletedWorkResource($updatedWork->load(['project', 'contract', 'workType', 'user', 'contractor', 'materials.measurementUnit']))
                ]
            );
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function destroy(CompletedWork $completedWork): JsonResponse
    {
        if ($completedWork->organization_id !== Auth::user()->current_organization_id) {
            abort(403, 'Это действие не авторизовано.');
        }

        try {
            $this->completedWorkService->delete($completedWork->id, $completedWork->organization_id);
            return response()->json(null, Response::HTTP_NO_CONTENT);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function syncMaterials(SyncCompletedWorkMaterialsRequest $request, CompletedWork $completedWork): JsonResponse
    {
        if ($completedWork->organization_id !== Auth::user()->current_organization_id) {
            abort(403, 'Это действие не авторизовано.');
        }

        try {
            $materials = $request->getMaterialsArray();
            $updatedWork = $this->completedWorkService->syncCompletedWorkMaterials(
                $completedWork->id, 
                $materials, 
                $completedWork->organization_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Материалы выполненной работы успешно синхронизированы.',
                'data' => new CompletedWorkResource($updatedWork->load(['project', 'contract', 'workType', 'user', 'contractor', 'materials.measurementUnit']))
            ]);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    public function getWorkTypeMaterialDefaults(Request $request): JsonResponse
    {
        $request->validate([
            'work_type_id' => 'required|integer|exists:work_types,id'
        ]);

        $organizationId = Auth::user()->current_organization_id;
        $workTypeId = $request->input('work_type_id');

        try {
            $defaults = $this->completedWorkService->getWorkTypeMaterialDefaults($workTypeId, $organizationId);

            return response()->json([
                'success' => true,
                'data' => $defaults
            ]);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }
} 