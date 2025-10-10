<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Contract\ContractService;
use App\Http\Requests\Api\V1\Admin\Contract\StoreContractRequest;
use App\Http\Requests\Api\V1\Admin\Contract\UpdateContractRequest;
use App\Http\Requests\Api\V1\Admin\Contract\AttachToParentContractRequest;
use App\Http\Requests\Api\V1\Admin\Contract\DetachFromParentContractRequest;
use App\Http\Resources\Api\V1\Admin\Contract\ContractResource; // Создадим позже
use App\Http\Resources\Api\V1\Admin\Contract\ContractCollection; // Создадим позже
use App\Http\Resources\Api\V1\Admin\Contract\ContractMiniResource;
use App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct\ContractPerformanceActResource;
use App\Http\Resources\Api\V1\Admin\Contract\Payment\ContractPaymentResource;
use App\Http\Resources\Api\V1\Admin\Contract\Agreement\SupplementaryAgreementResource;
use App\Http\Resources\Api\V1\Admin\Contract\Specification\SpecificationResource;
use App\Models\Organization; // Для получения ID организации, например, из аутентифицированного пользователя
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
        
        // Расширенная фильтрация
        $filters = $request->only([
            'contractor_id', 
            'project_id', 
            'status', 
            'type', 
            'number', 
            'date_from', 
            'date_to',
            'completion_from',      // Процент выполнения от
            'completion_to',        // Процент выполнения до
            'amount_from',          // Сумма контракта от
            'amount_to',            // Сумма контракта до
            'requiring_attention',  // Требуют внимания
            'is_nearing_limit',     // Приближаются к лимиту
            'is_overdue',           // Просроченные
            'search'                // Поиск по номеру/названию
        ]);
        
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
            $contractDTO = $request->toDto(); // Метод toDto() должен быть реализован
            $contract = $this->contractService->createContract($organizationId, $contractDTO);
            return (new ContractResource($contract))
                    ->response()
                    ->setStatusCode(Response::HTTP_CREATED);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to create contract', 'error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(int $contractId, Request $request)
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }
        $contract = $this->contractService->getContractById($contractId, $organizationId);
        if (!$contract) {
            return response()->json(['message' => 'Contract not found'], Response::HTTP_NOT_FOUND);
        }
        return new ContractResource($contract);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateContractRequest $request, int $contractId)
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }
        try {
            $contractDTO = $request->toDto();
            $contract = $this->contractService->updateContract($contractId, $organizationId, $contractDTO);
            return new ContractResource($contract);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Контракт не найден'], Response::HTTP_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Некорректные данные', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (Exception $e) {
            Log::error('Ошибка обновления контракта', [
                'contract_id' => $contractId,
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
    public function destroy(int $contractId, Request $request)
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }
        try {
            $this->contractService->deleteContract($contractId, $organizationId);
            return response()->json(null, Response::HTTP_NO_CONTENT);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to delete contract', 'error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Получить аналитику по контракту
     */
    public function analytics(int $contractId, Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        $contract = $this->contractService->getContractById($contractId, $organizationId);
        
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
    public function completedWorks(int $contractId, Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        $contract = $this->contractService->getContractById($contractId, $organizationId);
        
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
    public function fullDetails(int $contractId, Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            $fullDetails = $this->contractService->getFullContractDetails($contractId, $organizationId);
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

    public function attachToParent(AttachToParentContractRequest $request, int $contractId): JsonResponse
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            $parentContractId = $request->input('parent_contract_id');
            $contract = $this->contractService->attachToParentContract($contractId, $organizationId, $parentContractId);
            
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

    public function detachFromParent(DetachFromParentContractRequest $request, int $contractId): JsonResponse
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            $contract = $this->contractService->detachFromParentContract($contractId, $organizationId);
            
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