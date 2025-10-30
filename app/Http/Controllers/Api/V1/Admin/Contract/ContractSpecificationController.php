<?php

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Services\Contract\ContractService;
use App\Services\Contract\SpecificationService;
use App\Services\Contract\ContractStateEventService;
use App\Services\Contract\ContractStateCalculatorService;
use App\Http\Requests\Api\V1\Admin\Contract\Specification\StoreContractSpecificationRequest;
use App\Http\Requests\Api\V1\Admin\Contract\Specification\AttachSpecificationRequest;
use App\Http\Resources\Api\V1\Admin\Contract\Specification\SpecificationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Exception;

class ContractSpecificationController extends Controller
{
    protected ContractService $contractService;
    protected SpecificationService $specificationService;
    protected ?ContractStateEventService $stateEventService = null;
    protected ?ContractStateCalculatorService $stateCalculatorService = null;

    public function __construct(
        ContractService $contractService,
        SpecificationService $specificationService
    ) {
        $this->contractService = $contractService;
        $this->specificationService = $specificationService;
    }
    
    /**
     * Проверить, принадлежит ли контракт указанному проекту из URL
     */
    private function validateProjectContext(Request $request, $contract): bool
    {
        $projectId = $request->route('project');
        if ($projectId && (int)$contract->project_id !== (int)$projectId) {
            return false;
        }
        return true;
    }

    public function index(Request $request, int $project, int $contract): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        $projectId = $project;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            // Проверяем существование контракта (включая soft-deleted)
            $contractExists = \App\Models\Contract::withTrashed()->find($contract);
            
            if (!$contractExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт не найден в системе'
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Проверяем, не удален ли контракт
            if ($contractExists->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт был удален'
                ], Response::HTTP_GONE);
            }
            
            // Проверяем принадлежность проекту
            if ($projectId && (int)$contractExists->project_id !== (int)$projectId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт принадлежит другому проекту'
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Проверяем доступ к контракту
            $contractModel = $this->contractService->getContractById($contract, $organizationId);
            
            if (!$contractModel) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нет доступа к контракту'
                ], Response::HTTP_FORBIDDEN);
            }

            $specifications = $contractModel->specifications()->get();

            return response()->json([
                'success' => true,
                'data' => SpecificationResource::collection($specifications)
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении спецификаций',
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    public function store(StoreContractSpecificationRequest $request, int $project, int $contract): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        $projectId = $project;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            // Проверяем существование контракта (включая soft-deleted)
            $contractExists = \App\Models\Contract::withTrashed()->find($contract);
            
            if (!$contractExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт не найден в системе',
                    'debug' => [
                        'contract_id' => $contract,
                        'contract_id_type' => gettype($contract)
                    ]
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Проверяем, не удален ли контракт
            if ($contractExists->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт был удален',
                    'debug' => [
                        'contract_id' => $contract,
                        'deleted_at' => $contractExists->deleted_at
                    ]
                ], Response::HTTP_GONE);
            }
            
            // Проверяем принадлежность проекту ДО проверки организации
            if ($projectId && (int)$contractExists->project_id !== (int)$projectId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт принадлежит другому проекту',
                    'debug' => [
                        'contract_id' => $contract,
                        'contract_project_id' => $contractExists->project_id,
                        'requested_project_id' => $projectId
                    ]
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Теперь проверяем доступ через сервис
            $contractModel = $this->contractService->getContractById($contract, $organizationId);
            
            if (!$contractModel) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нет доступа к контракту',
                    'debug' => [
                        'contract_id' => $contract,
                        'contract_organization_id' => $contractExists->organization_id ?? null,
                        'your_organization_id' => $organizationId
                    ]
                ], Response::HTTP_FORBIDDEN);
            }

            $specificationDTO = $request->toDto();
            $specification = $this->specificationService->create($specificationDTO);

            // Деактивируем все предыдущие спецификации
            $contractModel->specifications()->updateExistingPivot(
                $contractModel->specifications()->pluck('specifications.id')->toArray(),
                ['is_active' => false]
            );

            // Прикрепляем новую спецификацию как активную
            $contractModel->specifications()->attach($specification->id, [
                'attached_at' => now(),
                'is_active' => true
            ]);

            // Если договор не использует Event Sourcing, активируем его
            // создав событие CREATED (если его нет), а затем AMENDED для спецификации
            if (!$contractModel->usesEventSourcing()) {
                try {
                    // Создаем начальное событие CREATED для активации Event Sourcing
                    $this->getStateEventService()->createContractCreatedEvent($contractModel);
                    Log::info('Event Sourcing activated for contract via specification', [
                        'contract_id' => $contractModel->id,
                        'specification_id' => $specification->id
                    ]);
                } catch (Exception $e) {
                    Log::warning('Failed to activate Event Sourcing for contract', [
                        'contract_id' => $contractModel->id,
                        'specification_id' => $specification->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Создаем событие AMENDED для новой спецификации (если Event Sourcing активен или только что активирован)
            if ($contractModel->usesEventSourcing() || $contractModel->fresh(['stateEvents'])->usesEventSourcing()) {
                try {
                    // Получаем текущую сумму контракта ДО привязки спецификации
                    $oldContractAmount = $contractModel->total_amount ?? 0;
                    
                    // Обновляем сумму контракта на сумму спецификации (если нужно)
                    // НО! Мы не должны менять сумму контракта автоматически!
                    // amount_delta должен быть разницей между новой суммой контракта и старой
                    // Если спецификация меняет сумму контракта, то это должно быть сделано вручную через обновление контракта
                    
                    // ВАЖНО: amount_delta должен быть разницей между новой и старой суммой контракта
                    // Если контракт не обновляется автоматически при привязке спецификации,
                    // то amount_delta должен быть 0, или сумма контракта должна быть обновлена перед созданием события
                    
                    // Получаем свежую модель контракта после всех изменений
                    $contractModel->refresh();
                    $newContractAmount = $contractModel->total_amount ?? 0;
                    
                    // Рассчитываем дельту изменения суммы контракта
                    $amountDelta = $newContractAmount - $oldContractAmount;
                    
                    $this->getStateEventService()->createAmendedEvent(
                        $contractModel,
                        $specification->id,
                        $amountDelta, // Используем разницу, а не абсолютное значение
                        $contractModel,
                        now(),
                        [
                            'specification_number' => $specification->number,
                            'specification_amount' => $specification->total_amount ?? 0,
                            'reason' => 'Прикреплена новая спецификация',
                            'old_contract_amount' => $oldContractAmount,
                            'new_contract_amount' => $newContractAmount,
                        ]
                    );

                    // Обновляем материализованное представление
                    $this->getStateCalculatorService()->recalculateContractState($contractModel);
                } catch (Exception $e) {
                    Log::warning('Failed to create state event for specification', [
                        'contract_id' => $contractModel->id,
                        'specification_id' => $specification->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $specification->load(['contracts']);

            return response()->json([
                'success' => true,
                'message' => 'Спецификация успешно создана и привязана к контракту',
                'data' => new SpecificationResource($specification)
            ], Response::HTTP_CREATED);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при создании спецификации',
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    public function attach(AttachSpecificationRequest $request, int $project, int $contract): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        $projectId = $project;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            // Проверяем существование контракта (включая soft-deleted)
            $contractExists = \App\Models\Contract::withTrashed()->find($contract);
            
            if (!$contractExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт не найден в системе'
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Проверяем, не удален ли контракт
            if ($contractExists->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт был удален'
                ], Response::HTTP_GONE);
            }
            
            // Проверяем принадлежность проекту
            if ($projectId && (int)$contractExists->project_id !== (int)$projectId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт принадлежит другому проекту'
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Проверяем доступ к контракту
            $contractModel = $this->contractService->getContractById($contract, $organizationId);
            
            if (!$contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нет доступа к контракту'
                ], Response::HTTP_FORBIDDEN);
            }

            $specificationId = $request->input('specification_id');

            if ($contractModel->specifications()->where('specification_id', $specificationId)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Спецификация уже привязана к контракту'
                ], Response::HTTP_CONFLICT);
            }

            // Деактивируем все предыдущие спецификации
            $contractModel->specifications()->updateExistingPivot(
                $contractModel->specifications()->pluck('specifications.id')->toArray(),
                ['is_active' => false]
            );

            // Прикрепляем новую спецификацию как активную
            $contractModel->specifications()->attach($specificationId, [
                'attached_at' => now(),
                'is_active' => true
            ]);

            $specification = $contractModel->specifications()->find($specificationId);

            // Если договор использует Event Sourcing, создаем событие
            if ($contractModel->usesEventSourcing() && $specification) {
                try {
                    // Получаем текущую сумму контракта ДО привязки спецификации
                    $oldContractAmount = $contractModel->total_amount ?? 0;
                    
                    // Обновляем модель контракта после привязки спецификации
                    $contractModel->refresh();
                    $newContractAmount = $contractModel->total_amount ?? 0;
                    
                    // Рассчитываем дельту изменения суммы контракта
                    // amount_delta = новая_сумма_контракта - старая_сумма_контракта
                    $amountDelta = $newContractAmount - $oldContractAmount;
                    
                    $this->getStateEventService()->createAmendedEvent(
                        $contractModel,
                        $specificationId,
                        $amountDelta,
                        $contractModel,
                        now(),
                        [
                            'specification_number' => $specification->number,
                            'specification_amount' => $specification->total_amount ?? 0,
                            'reason' => 'Прикреплена существующая спецификация',
                            'old_contract_amount' => $oldContractAmount,
                            'new_contract_amount' => $newContractAmount,
                        ]
                    );

                    // Обновляем материализованное представление
                    $this->getStateCalculatorService()->recalculateContractState($contractModel);
                } catch (Exception $e) {
                    Log::warning('Failed to create state event for attached specification', [
                        'contract_id' => $contractModel->id,
                        'specification_id' => $specificationId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Спецификация успешно привязана к контракту',
                'data' => new SpecificationResource($specification)
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при привязке спецификации',
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    public function destroy(Request $request, int $project, int $contract, int $specification): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        $projectId = $project;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            // Проверяем существование контракта (включая soft-deleted)
            $contractExists = \App\Models\Contract::withTrashed()->find($contract);
            
            if (!$contractExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт не найден в системе'
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Проверяем, не удален ли контракт
            if ($contractExists->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт был удален'
                ], Response::HTTP_GONE);
            }
            
            // Проверяем принадлежность проекту
            if ($projectId && (int)$contractExists->project_id !== (int)$projectId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт принадлежит другому проекту'
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Проверяем доступ к контракту
            $contractModel = $this->contractService->getContractById($contract, $organizationId);
            
            if (!$contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нет доступа к контракту'
                ], Response::HTTP_FORBIDDEN);
            }

            if (!$contractModel->specifications()->where('specification_id', $specification)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Спецификация не привязана к контракту'
                ], Response::HTTP_NOT_FOUND);
            }

            $contractModel->specifications()->detach($specification);

            return response()->json([
                'success' => true,
                'message' => 'Спецификация успешно отвязана от контракта'
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при отвязке спецификации',
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Получить сервис для работы с событиями состояния договора (lazy loading)
     */
    protected function getStateEventService(): ContractStateEventService
    {
        if ($this->stateEventService === null) {
            $this->stateEventService = app(ContractStateEventService::class);
        }
        return $this->stateEventService;
    }

    /**
     * Получить сервис для расчета состояний договора (lazy loading)
     */
    protected function getStateCalculatorService(): ContractStateCalculatorService
    {
        if ($this->stateCalculatorService === null) {
            $this->stateCalculatorService = app(ContractStateCalculatorService::class);
        }
        return $this->stateCalculatorService;
    }
}
