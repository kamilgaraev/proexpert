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
use App\Http\Responses\AdminResponse;
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
        
        if (!$projectId) {
            return true;
        }

        if ($contract->is_multi_project) {
            return $contract->projects()->where('projects.id', $projectId)->exists();
        }

        if ((int)$contract->project_id !== (int)$projectId) {
            return false;
        }
        
        return true;
    }

    public function index(Request $request, int $project, int $contract): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        $projectId = $project;
        
        if (!$organizationId) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), 400);
        }

        try {
            // Проверяем существование контракта (включая soft-deleted)
            $contractExists = \App\Models\Contract::withTrashed()->find($contract);
            
            if (!$contractExists) {
                return AdminResponse::error(trans_message('contract.contract_not_found'), Response::HTTP_NOT_FOUND);
            }
            
            // Проверяем, не удален ли контракт
            if ($contractExists->trashed()) {
                return AdminResponse::error(trans_message('contract.contract_deleted'), Response::HTTP_GONE);
            }
            
            // Проверяем принадлежность проекту
            if ($projectId) {
                $belongsToProject = false;
                if ($contractExists->is_multi_project) {
                    $belongsToProject = $contractExists->projects()->where('projects.id', $projectId)->exists();
                } else {
                    $belongsToProject = (int)$contractExists->project_id === (int)$projectId;
                }

                if (!$belongsToProject) {
                    return AdminResponse::error(trans_message('contract.contract_mismatch'), Response::HTTP_NOT_FOUND);
                }
            }
            
            // Проверяем доступ к контракту
            $contractModel = $this->contractService->getContractById($contract, $organizationId);
            
            if (!$contractModel) {
                return AdminResponse::error(trans_message('contract.access_denied'), Response::HTTP_FORBIDDEN);
            }

            $specifications = $contractModel->specifications()->get();

            return AdminResponse::success(SpecificationResource::collection($specifications));
        } catch (Exception $e) {
            return AdminResponse::error(trans_message('contract.specification_retrieve_error'), Response::HTTP_BAD_REQUEST, $e->getMessage());
        }
    }

    public function store(StoreContractSpecificationRequest $request, int $project, int $contract): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        $projectId = $project;
        
        if (!$organizationId) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), 400);
        }

        try {
            // Проверяем существование контракта (включая soft-deleted)
            $contractExists = \App\Models\Contract::withTrashed()->find($contract);
            
            if (!$contractExists) {
                return AdminResponse::error(
                    trans_message('contract.contract_not_found'), 
                    Response::HTTP_NOT_FOUND, 
                    [
                        'contract_id' => $contract,
                        'contract_id_type' => gettype($contract)
                    ]
                );
            }
            
            // Проверяем, не удален ли контракт
            if ($contractExists->trashed()) {
                return AdminResponse::error(
                    trans_message('contract.contract_deleted'), 
                    Response::HTTP_GONE,
                    [
                        'contract_id' => $contract,
                        'deleted_at' => $contractExists->deleted_at
                    ]
                );
            }
            
            // Проверяем принадлежность проекту ДО проверки организации
            if ($projectId) {
                $belongsToProject = false;
                if ($contractExists->is_multi_project) {
                    $belongsToProject = $contractExists->projects()->where('projects.id', $projectId)->exists();
                } else {
                    $belongsToProject = (int)$contractExists->project_id === (int)$projectId;
                }

                if (!$belongsToProject) {
                    return AdminResponse::error(
                        trans_message('contract.contract_mismatch'), 
                        Response::HTTP_NOT_FOUND,
                        [
                            'contract_id' => $contract,
                            'is_multi_project' => $contractExists->is_multi_project,
                            'requested_project_id' => $projectId
                        ]
                    );
                }
            }
            
            // Теперь проверяем доступ через сервис
            $contractModel = $this->contractService->getContractById($contract, $organizationId);
            
            if (!$contractModel) {
                return AdminResponse::error(
                    trans_message('contract.access_denied'), 
                    Response::HTTP_FORBIDDEN,
                    [
                        'contract_id' => $contract,
                        'contract_organization_id' => $contractExists->organization_id ?? null,
                        'your_organization_id' => $organizationId
                    ]
                );
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
                    
                    // ВАЖНО: Привязка спецификации НЕ меняет сумму контракта автоматически!
                    // Спецификация - это просто документ, привязанный к контракту.
                    // Если сумма контракта не изменилась, то amount_delta должен быть 0.
                    // Событие AMENDED создается только если сумма контракта реально изменилась.
                    
                    // Обновляем модель контракта (после привязки спецификации сумма не должна измениться)
                    $contractModel->refresh();
                    $newContractAmount = $contractModel->total_amount ?? 0;
                    
                    // Рассчитываем дельту изменения суммы контракта
                    $amountDelta = $newContractAmount - $oldContractAmount;
                    
                    // Создаем событие ТОЛЬКО если сумма контракта реально изменилась
                    // Если сумма не изменилась (что нормально для привязки спецификации),
                    // то событие не создаем или создаем с amount_delta = 0
                    if (abs($amountDelta) > 0.01) {
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
                    } else {
                        // Если сумма контракта не изменилась, логируем это для отладки
                        \Illuminate\Support\Facades\Log::info('Specification attached but contract amount unchanged', [
                            'contract_id' => $contractModel->id,
                            'specification_id' => $specification->id,
                            'contract_amount' => $newContractAmount,
                            'specification_amount' => $specification->total_amount ?? 0,
                        ]);
                    }
                } catch (Exception $e) {
                    Log::warning('Failed to create state event for specification', [
                        'contract_id' => $contractModel->id,
                        'specification_id' => $specification->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $specification->load(['contracts']);

            return AdminResponse::success(new SpecificationResource($specification), trans_message('contract.specification_created'), Response::HTTP_CREATED);
        } catch (Exception $e) {
            return AdminResponse::error(trans_message('contract.specification_create_error'), Response::HTTP_BAD_REQUEST, $e->getMessage());
        }
    }

    public function attach(AttachSpecificationRequest $request, int $project, int $contract): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        $projectId = $project;
        
        if (!$organizationId) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), 400);
        }

        try {
            // Проверяем существование контракта (включая soft-deleted)
            $contractExists = \App\Models\Contract::withTrashed()->find($contract);
            
            if (!$contractExists) {
                return AdminResponse::error(trans_message('contract.contract_not_found'), Response::HTTP_NOT_FOUND);
            }
            
            // Проверяем, не удален ли контракт
            if ($contractExists->trashed()) {
                return AdminResponse::error(trans_message('contract.contract_deleted'), Response::HTTP_GONE);
            }
            
            // Проверяем принадлежность проекту
            if ($projectId) {
                $belongsToProject = false;
                if ($contractExists->is_multi_project) {
                    $belongsToProject = $contractExists->projects()->where('projects.id', $projectId)->exists();
                } else {
                    $belongsToProject = (int)$contractExists->project_id === (int)$projectId;
                }

                if (!$belongsToProject) {
                    return AdminResponse::error(trans_message('contract.contract_mismatch'), Response::HTTP_NOT_FOUND);
                }
            }
            
            // Проверяем доступ к контракту
            $contractModel = $this->contractService->getContractById($contract, $organizationId);
            
            if (!$contractModel) {
                return AdminResponse::error(trans_message('contract.access_denied'), Response::HTTP_FORBIDDEN);
            }

            $specificationId = $request->input('specification_id');

            if ($contractModel->specifications()->where('specification_id', $specificationId)->exists()) {
                return AdminResponse::error(trans_message('contract.specification_already_attached'), Response::HTTP_CONFLICT);
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
                    
                    // ВАЖНО: Привязка спецификации НЕ меняет сумму контракта автоматически!
                    // Спецификация - это просто документ, привязанный к контракту.
                    // Если сумма контракта не изменилась, то amount_delta должен быть 0.
                    
                    // Обновляем модель контракта (после привязки спецификации сумма не должна измениться)
                    $contractModel->refresh();
                    $newContractAmount = $contractModel->total_amount ?? 0;
                    
                    // Рассчитываем дельту изменения суммы контракта
                    $amountDelta = $newContractAmount - $oldContractAmount;
                    
                    // Создаем событие ТОЛЬКО если сумма контракта реально изменилась
                    if (abs($amountDelta) > 0.01) {
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
                    } else {
                        // Если сумма контракта не изменилась, логируем это для отладки
                        \Illuminate\Support\Facades\Log::info('Specification attached but contract amount unchanged', [
                            'contract_id' => $contractModel->id,
                            'specification_id' => $specificationId,
                            'contract_amount' => $newContractAmount,
                            'specification_amount' => $specification->total_amount ?? 0,
                        ]);
                    }
                } catch (Exception $e) {
                    Log::warning('Failed to create state event for attached specification', [
                        'contract_id' => $contractModel->id,
                        'specification_id' => $specificationId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return AdminResponse::success(new SpecificationResource($specification), trans_message('contract.specification_attached'));
        } catch (Exception $e) {
            return AdminResponse::error(trans_message('contract.specification_attach_error'), Response::HTTP_BAD_REQUEST, $e->getMessage());
        }
    }

    public function destroy(Request $request, int $project, int $contract, int $specification): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        $projectId = $project;
        
        if (!$organizationId) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), 400);
        }

        try {
            // Проверяем существование контракта (включая soft-deleted)
            $contractExists = \App\Models\Contract::withTrashed()->find($contract);
            
            if (!$contractExists) {
                return AdminResponse::error(trans_message('contract.contract_not_found'), Response::HTTP_NOT_FOUND);
            }
            
            // Проверяем, не удален ли контракт
            if ($contractExists->trashed()) {
                return AdminResponse::error(trans_message('contract.contract_deleted'), Response::HTTP_GONE);
            }
            
            // Проверяем принадлежность проекту
            if ($projectId) {
                $belongsToProject = false;
                if ($contractExists->is_multi_project) {
                    $belongsToProject = $contractExists->projects()->where('projects.id', $projectId)->exists();
                } else {
                    $belongsToProject = (int)$contractExists->project_id === (int)$projectId;
                }

                if (!$belongsToProject) {
                    return AdminResponse::error(trans_message('contract.contract_mismatch'), Response::HTTP_NOT_FOUND);
                }
            }
            
            // Проверяем доступ к контракту
            $contractModel = $this->contractService->getContractById($contract, $organizationId);
            
            if (!$contractModel) {
                return AdminResponse::error(trans_message('contract.access_denied'), Response::HTTP_FORBIDDEN);
            }

            if (!$contractModel->specifications()->where('specification_id', $specification)->exists()) {
                return AdminResponse::error(trans_message('contract.specification_not_found'), Response::HTTP_NOT_FOUND);
            }

            $contractModel->specifications()->detach($specification);

            return AdminResponse::success(null, trans_message('contract.specification_detached'));
        } catch (Exception $e) {
            return AdminResponse::error(trans_message('contract.specification_detach_error'), Response::HTTP_BAD_REQUEST, $e->getMessage());
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
