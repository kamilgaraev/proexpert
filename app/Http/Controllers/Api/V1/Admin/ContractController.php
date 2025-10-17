<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Contract\ContractService;
use App\Http\Requests\Api\V1\Admin\Contract\StoreContractRequest;
use App\Http\Requests\Api\V1\Admin\Contract\UpdateContractRequest;
use App\Http\Requests\Api\V1\Admin\Contract\AttachToParentContractRequest;
use App\Http\Requests\Api\V1\Admin\Contract\DetachFromParentContractRequest;
use App\Http\Resources\Api\V1\Admin\Contract\ContractResource;
use App\Http\Resources\Api\V1\Admin\Contract\ContractCollection;
use App\Http\Resources\Api\V1\Admin\Contract\ContractMiniResource;
use App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct\ContractPerformanceActResource;
use App\Http\Resources\Api\V1\Admin\Contract\Payment\ContractPaymentResource;
use App\Http\Resources\Api\V1\Admin\Contract\Agreement\SupplementaryAgreementResource;
use App\Http\Resources\Api\V1\Admin\Contract\Specification\SpecificationResource;
use App\Http\Middleware\ProjectContextMiddleware;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Exception;

class ContractController extends Controller
{
    protected ContractService $contractService;

    public function __construct(ContractService $contractService)
    {
        $this->contractService = $contractService;
        // Middleware для авторизации можно добавить здесь или в роутах
        // $this->middleware('can:viewAny,App\Models\Contract')->only('index');
        // $this->middleware('can:create,App\Models\Contract')->only('store');
        // $this->middleware('can:view,contract')->only('show');
        // $this->middleware('can:update,contract')->only('update');
        // $this->middleware('can:delete,contract')->only('destroy');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }
        
        // Получаем project_id из URL (обязательный параметр для project-based маршрутов)
        $projectId = $request->route('project');
        
        // Расширенная фильтрация
        $filters = $request->only([
            'contractor_id', 
            'status', 
            'type', 
            'number', 
            'date_from', 
            'date_to',
            'start_date_from',      // Дата начала работ от
            'start_date_to',        // Дата начала работ до
            'end_date_from',        // Дата окончания работ от
            'end_date_to',          // Дата окончания работ до
            'completion_from',      // Процент выполнения от
            'completion_to',        // Процент выполнения до
            'amount_from',          // Сумма контракта от
            'amount_to',            // Сумма контракта до
            'gp_percentage_from',   // Процент ГП от
            'gp_percentage_to',     // Процент ГП до
            'work_type_category',   // Категория работ
            'has_advance',          // Наличие аванса (boolean)
            'advance_paid_status',  // Статус выплаты аванса: paid/partial/not_paid
            'has_parent',           // Наличие родительского контракта (boolean)
            'has_children',         // Наличие дочерних контрактов (boolean)
            'requiring_attention',  // Требуют внимания
            'is_nearing_limit',     // Приближаются к лимиту
            'is_overdue',           // Просроченные
            'search',               // Общий поиск по номеру/проекту/подрядчику
            'contractor_search',    // Поиск по подрядчику (имя, ИНН, КПП, email, телефон)
            'project_search'        // Поиск по проекту (название, адрес, код)
        ]);
        
        // ЖЕСТКО устанавливаем project_id из URL (игнорируем любые другие значения)
        $filters['project_id'] = $projectId;
        
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDirection = $request->input('sort_direction', 'desc');
        $perPage = $request->input('per_page', 15);

        $contracts = $this->contractService->getAllContracts($organizationId, $perPage, $filters, $sortBy, $sortDirection);
        return new ContractCollection($contracts);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreContractRequest $request)
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }
        
        try {
            $contractDTO = $request->toDto();
            
            // Получаем ProjectContext если доступен (для project-based routes)
            $projectContext = ProjectContextMiddleware::getProjectContext($request);
            
            $contract = $this->contractService->createContract($organizationId, $contractDTO, $projectContext);
            
            return (new ContractResource($contract))
                    ->response()
                    ->setStatusCode(Response::HTTP_CREATED);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create contract',
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(int $contract, Request $request)
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }
        $contractData = $this->contractService->getContractById($contract, $organizationId);
        if (!$contractData) {
            return response()->json(['message' => 'Contract not found'], Response::HTTP_NOT_FOUND);
        }
        return new ContractResource($contractData);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateContractRequest $request, int $contract)
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }
        try {
            $contractDTO = $request->toDto();
            $updatedContract = $this->contractService->updateContract($contract, $organizationId, $contractDTO);
            return new ContractResource($updatedContract);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Контракт не найден'], Response::HTTP_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Некорректные данные', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (Exception $e) {
            Log::error('Ошибка обновления контракта', [
                'contract_id' => $contract,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Не удалось обновить контракт', 'error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $contract, Request $request)
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }
        try {
            $this->contractService->deleteContract($contract, $organizationId);
            return response()->json(null, Response::HTTP_NO_CONTENT);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to delete contract', 'error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Получить аналитику по контракту
     */
    public function analytics(int $contract, Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        $contract = $this->contractService->getContractById($contract, $organizationId);
        
        if (!$contract) {
            return response()->json(['message' => 'Contract not found'], Response::HTTP_NOT_FOUND);
        }

        $analytics = [
            'contract_id' => $contract->id,
            'contract_number' => $contract->number,
            'total_amount' => (float) $contract->total_amount,
            'completed_works_amount' => $contract->completed_works_amount,
            'remaining_amount' => $contract->remaining_amount,
            'completion_percentage' => $contract->completion_percentage,
            'total_paid_amount' => $contract->total_paid_amount,
            'total_performed_amount' => $contract->total_performed_amount,
            'status' => $contract->status->value,
            'is_nearing_limit' => $contract->isNearingLimit(),
            'can_add_work' => $contract->canAddWork(0), // Проверка общей возможности
            'completed_works_count' => $contract->completedWorks()->count(),
            'confirmed_works_count' => $contract->completedWorks()->where('status', 'confirmed')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $analytics
        ]);
    }

    /**
     * Получить выполненные работы по контракту
     */
    public function completedWorks(int $contract, Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        $contract = $this->contractService->getContractById($contract, $organizationId);
        
        if (!$contract) {
            return response()->json(['message' => 'Contract not found'], Response::HTTP_NOT_FOUND);
        }

        $perPage = $request->query('per_page', 15);
        $completedWorks = $contract->completedWorks()
            ->with(['project', 'workType', 'user', 'materials.measurementUnit'])
            ->orderBy('completion_date', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $completedWorks
        ]);
    }

    /**
     * Получить полную информацию по контракту (все данные в одном запросе)
     */
    public function fullDetails(int $contract, Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            $fullDetails = $this->contractService->getFullContractDetails($contract, $organizationId);
            $contract = $fullDetails['contract'];
            
            return response()->json([
                'success' => true,
                'data' => [
                    'contract' => new ContractResource($contract),
                    'analytics' => $fullDetails['analytics'],
                    'works_statistics' => $fullDetails['works_statistics'],
                    'recent_works' => $fullDetails['recent_works'],
                    'performance_acts' => $contract->relationLoaded('performanceActs') ? 
                        ContractPerformanceActResource::collection($contract->performanceActs) : [],
                    'payments' => $contract->relationLoaded('payments') ? 
                        ContractPaymentResource::collection($contract->payments) : [],
                    'child_contracts' => $contract->relationLoaded('childContracts') ? 
                        ContractMiniResource::collection($contract->childContracts) : [],
                    'agreements' => $contract->relationLoaded('agreements') ?
                        SupplementaryAgreementResource::collection($contract->agreements) : [],
                    'specifications' => $contract->relationLoaded('specifications') ?
                        SpecificationResource::collection($contract->specifications) : [],
                ]
            ]);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }
    }

    public function attachToParent(AttachToParentContractRequest $request, int $contract): JsonResponse
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            $parentContractId = $request->input('parent_contract_id');
            $contract = $this->contractService->attachToParentContract($contract, $organizationId, $parentContractId);
            
            return response()->json([
                'success' => true,
                'message' => 'Контракт успешно привязан к родительскому контракту',
                'data' => new ContractResource($contract)
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при привязке контракта',
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    public function detachFromParent(DetachFromParentContractRequest $request, int $contract): JsonResponse
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            $contract = $this->contractService->detachFromParentContract($contract, $organizationId);
            
            return response()->json([
                'success' => true,
                'message' => 'Контракт успешно отвязан от родительского контракта',
                'data' => new ContractResource($contract)
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при отвязке контракта',
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }
} 