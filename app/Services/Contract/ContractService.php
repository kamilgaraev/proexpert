<?php

namespace App\Services\Contract;

use App\Repositories\Interfaces\ContractRepositoryInterface;
use App\Repositories\Interfaces\ContractorRepositoryInterface;
use App\Repositories\Interfaces\ContractPerformanceActRepositoryInterface;
use App\Repositories\Interfaces\ContractPaymentRepositoryInterface;
use App\Services\Contract\ContractStateEventService;
use App\DTOs\Contract\ContractDTO;
use App\Models\Contract;
use App\Models\Project;
use App\Models\Organization;
use App\Services\Logging\LoggingService;
use App\Services\Project\ProjectContextService;
use App\Domain\Project\ValueObjects\ProjectContext;
use App\BusinessModules\Core\MultiOrganization\Contracts\OrganizationScopeInterface;
use App\BusinessModules\Core\MultiOrganization\Contracts\ContractorSharingInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Exception;

class ContractService
{
    protected ContractRepositoryInterface $contractRepository;
    protected ContractorRepositoryInterface $contractorRepository; 
    protected ContractPerformanceActRepositoryInterface $actRepository;
    protected ContractPaymentRepositoryInterface $paymentRepository;
    protected LoggingService $logging;
    protected ProjectContextService $projectContextService;
    protected OrganizationScopeInterface $orgScope;
    protected ContractorSharingInterface $contractorSharing;
    protected ?ContractStateEventService $stateEventService = null;

    public function __construct(
        ContractRepositoryInterface $contractRepository,
        ContractorRepositoryInterface $contractorRepository,
        ContractPerformanceActRepositoryInterface $actRepository,
        ContractPaymentRepositoryInterface $paymentRepository,
        LoggingService $logging,
        ProjectContextService $projectContextService,
        OrganizationScopeInterface $orgScope,
        ContractorSharingInterface $contractorSharing
    ) {
        $this->contractRepository = $contractRepository;
        $this->contractorRepository = $contractorRepository;
        $this->actRepository = $actRepository;
        $this->paymentRepository = $paymentRepository;
        $this->logging = $logging;
        $this->projectContextService = $projectContextService;
        $this->orgScope = $orgScope;
        $this->contractorSharing = $contractorSharing;
    }

    public function getAllContracts(int $organizationId, int $perPage = 15, array $filters = [], string $sortBy = 'date', string $sortDirection = 'desc'): LengthAwarePaginator
    {
        return $this->contractRepository->getContractsForOrganizationPaginated($organizationId, $perPage, $filters, $sortBy, $sortDirection);
    }

    public function createContract(
        int $organizationId, 
        ContractDTO $contractDTO,
        ?ProjectContext $projectContext = null
    ): Contract
    {
        // Project-Based RBAC: валидация прав и auto-fill contractor_id
        if ($projectContext) {
            // Проверка: может ли роль создавать контракты
            if (!$projectContext->roleConfig->canManageContracts) {
                throw new Exception(
                    'Ваша роль "' . $projectContext->roleConfig->displayLabel . 
                    '" не позволяет создавать контракты в этом проекте'
                );
            }

            // Auto-fill contractor_id для contractor/subcontractor ролей
            $contractorId = $contractDTO->contractor_id;
            
            if (in_array($projectContext->roleConfig->role->value, ['contractor', 'subcontractor'])) {
                // Для подрядчика: organization_id контракта должен быть организация ЗАКАЗЧИКА (владелец проекта)
                // Contractor должен быть в базе организации заказчика, а не подрядчика
                $project = Project::find($contractDTO->project_id);
                if (!$project) {
                    throw new Exception('Проект не найден');
                }
                
                // Организация заказчика = владелец проекта
                $customerOrganizationId = $project->organization_id;
                
                // Находим или создаём Contractor в базе организации ЗАКАЗЧИКА
                // source_organization_id = организация подрядчика (текущий пользователь)
                $contractor = \App\Models\Contractor::firstOrCreate(
                    [
                        'organization_id' => $customerOrganizationId,  // Организация заказчика
                        'source_organization_id' => $projectContext->organizationId,  // Организация подрядчика
                    ],
                    [
                        'name' => $projectContext->organizationName ?? 'Подрядчик',
                        'contractor_type' => \App\Models\Contractor::TYPE_INVITED_ORGANIZATION,
                        'connected_at' => now(),
                    ]
                );
                
                // Проверяем: если пытаются указать другого подрядчика - ошибка
                if ($contractorId && $contractorId !== $contractor->id) {
                    throw new Exception('Подрядчик может создавать контракты только для себя');
                }
                
                // Auto-fill правильным contractor_id
                $contractorId = $contractor->id;
                
                // Для подрядчика: organization_id контракта = организация заказчика
                $organizationId = $customerOrganizationId;
                
                // Создаем новый DTO с исправленным contractor_id
                $contractDTO = new ContractDTO(
                    project_id: $contractDTO->project_id,
                    contractor_id: $contractorId,
                    parent_contract_id: $contractDTO->parent_contract_id,
                    number: $contractDTO->number,
                    date: $contractDTO->date,
                    subject: $contractDTO->subject,
                    work_type_category: $contractDTO->work_type_category,
                    payment_terms: $contractDTO->payment_terms,
                    base_amount: $contractDTO->base_amount,
                    total_amount: $contractDTO->total_amount,
                    gp_percentage: $contractDTO->gp_percentage,
                    gp_calculation_type: $contractDTO->gp_calculation_type,
                    gp_coefficient: $contractDTO->gp_coefficient,
                    warranty_retention_calculation_type: $contractDTO->warranty_retention_calculation_type,
                    warranty_retention_percentage: $contractDTO->warranty_retention_percentage,
                    warranty_retention_coefficient: $contractDTO->warranty_retention_coefficient,
                    subcontract_amount: $contractDTO->subcontract_amount,
                    planned_advance_amount: $contractDTO->planned_advance_amount,
                    actual_advance_amount: $contractDTO->actual_advance_amount,
                    status: $contractDTO->status,
                    start_date: $contractDTO->start_date,
                    end_date: $contractDTO->end_date,
                    notes: $contractDTO->notes,
                    advance_payments: $contractDTO->advance_payments,
                    is_fixed_amount: $contractDTO->is_fixed_amount
                );
                
                $this->logging->technical('contractor_id auto-filled', [
                    'organization_id' => $organizationId,
                    'contractor_id' => $contractorId,
                    'role' => $projectContext->roleConfig->role->value,
                ]);
            }

            // УДАЛЕНА НЕВЕРНАЯ ВАЛИДАЦИЯ:
            // Подрядчик НЕ обязан быть участником проекта!
            // Подрядчик может быть внешним контрагентом (не зарегистрирован как организация)
            // Проверка доступности подрядчика происходит ниже через ContractorSharing
        }
        
        // Финальная проверка: contractor_id обязателен
        if (!$contractDTO->contractor_id) {
            throw new Exception('Не указан подрядчик для контракта');
        }
        
        // Проверка для контрактов с фиксированной суммой: base_amount обязателен
        if ($contractDTO->is_fixed_amount && $contractDTO->base_amount === null) {
            throw new Exception('Для контракта с фиксированной суммой необходимо указать базовую сумму (base_amount)');
        }
        
        // Для контрактов с нефиксированной суммой: base_amount и total_amount могут быть null
        // Сумма будет определяться по фактически выполненным работам/оказанным услугам
        
        // Проверяем доступность подрядчика для организации (через ContractorSharing)
        if (!$this->contractorSharing->canUseContractor($contractDTO->contractor_id, $organizationId)) {
            $contractor = \App\Models\Contractor::find($contractDTO->contractor_id);
            $contractorName = $contractor ? $contractor->name : "ID {$contractDTO->contractor_id}";
            
            throw new Exception(
                "Подрядчик \"{$contractorName}\" недоступен для вашей организации. " .
                "Возможно, это подрядчик из другой организации, не входящей в ваш холдинг."
            );
        }
        
        // BUSINESS: Начало создания договора - важная бизнес-операция
        $this->logging->business('contract.creation.started', [
            'organization_id' => $organizationId,
            'contractor_id' => $contractDTO->contractor_id,
            'total_amount' => $contractDTO->total_amount,
            'subcontract_amount' => $contractDTO->subcontract_amount,
            'contract_number' => $contractDTO->number,
            'project_id' => $contractDTO->project_id,
            'user_id' => Auth::id(),
            'has_project_context' => $projectContext !== null,
        ]);

        $contractData = $contractDTO->toArray();
        $contractData['organization_id'] = $organizationId;

        // Если warranty_retention_percentage не указан, не передаем его в массив,
        // чтобы БД использовала значение по умолчанию (2.5)
        if (!isset($contractData['warranty_retention_percentage']) || $contractData['warranty_retention_percentage'] === null) {
            unset($contractData['warranty_retention_percentage']);
        }

        // Для контрактов с нефиксированной суммой: если total_amount не указан, устанавливаем 0
        // Сумма будет пересчитываться автоматически при создании актов и ДС
        if (!$contractDTO->is_fixed_amount && (!isset($contractData['total_amount']) || $contractData['total_amount'] === null)) {
            $contractData['total_amount'] = 0;
        }

        // Сохраняем project_ids для мультипроектных контрактов
        $projectIds = $contractData['project_ids'] ?? null;
        unset($contractData['project_ids']);

        try {
            DB::beginTransaction();
            
            $advancePayments = $contractDTO->advance_payments;
            unset($contractData['advance_payments']);
            
            $contract = $this->contractRepository->create($contractData);
            
            // Синхронизируем проекты для мультипроектного контракта
            if ($contractDTO->is_multi_project && !empty($projectIds)) {
                // Валидация: все проекты должны принадлежать организации
                $validProjects = Project::whereIn('id', $projectIds)
                    ->where('organization_id', $organizationId)
                    ->pluck('id')
                    ->toArray();
                
                if (count($validProjects) !== count($projectIds)) {
                    throw new Exception('Некоторые проекты не найдены или не принадлежат вашей организации');
                }
                
                $contract->syncProjects($projectIds);
            } elseif (!$contractDTO->is_multi_project && $contractDTO->project_id) {
                // Для обычного контракта синхронизируем один проект
                $contract->syncProjects([$contractDTO->project_id]);
            }
            
            if ($advancePayments && is_array($advancePayments)) {
                foreach ($advancePayments as $advance) {
                    $this->paymentRepository->create([
                        'contract_id' => $contract->id,
                        'amount' => $advance['amount'],
                        'payment_date' => $advance['payment_date'] ?? null,
                        'payment_type' => 'advance',
                        'description' => $advance['description'] ?? null,
                    ]);
                }
            }
            
            DB::commit();

            // Создание события Event Sourcing для нового договора
            try {
                $this->getStateEventService()->createContractCreatedEvent($contract);
            } catch (Exception $e) {
                // Не критично, если событие не создалось - логируем и продолжаем
                Log::warning('Failed to create contract state event', [
                    'contract_id' => $contract->id,
                    'error' => $e->getMessage()
                ]);
            }

            // BUSINESS: Договор успешно создан
            $this->logging->business('contract.created', [
                'organization_id' => $organizationId,
                'contract_id' => $contract->id,
                'contract_number' => $contract->number,
                'contractor_id' => $contract->contractor_id,
                'total_amount' => $contract->total_amount,
                'start_date' => $contract->start_date,
                'end_date' => $contract->end_date,
                'status' => $contract->status->value ?? $contract->status,
                'user_id' => Auth::id()
            ]);

            // AUDIT: Создание договора для compliance
            $this->logging->audit('contract.created', [
                'organization_id' => $organizationId,
                'contract_id' => $contract->id,
                'contract_number' => $contract->number,
                'transaction_type' => 'contract_created',
                'performed_by' => Auth::id() ?? 'system',
                'contract_amount' => $contract->total_amount
            ]);

            return $contract;

        } catch (Exception $e) {
            // BUSINESS: Неудачное создание договора
            $this->logging->business('contract.creation.failed', [
                'organization_id' => $organizationId,
                'contract_number' => $contractDTO->number ?? null,
                'error_message' => $e->getMessage(),
                'user_id' => Auth::id()
            ], 'error');
            
            throw $e;
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

    public function getContractById(int $contractId, int $organizationId): ?Contract
    {
        $contract = $this->contractRepository->find($contractId);
        if (!$contract) {
            Log::warning('[ContractService] Contract not found', [
                'contract_id' => $contractId,
                'organization_id' => $organizationId
            ]);
            return null;
        }
        
        // Проверяем доступ: либо это организация-заказчик, либо организация-подрядчик (через source_organization_id)
        $isCustomer = $contract->organization_id === $organizationId;
        $isContractor = $contract->contractor && $contract->contractor->source_organization_id === $organizationId;
        $hasAccess = $isCustomer || $isContractor;
        
        if (!$hasAccess) {
            Log::warning('[ContractService] Contract access denied - not customer or contractor', [
                'contract_id' => $contractId,
                'organization_id' => $organizationId,
                'contract_organization_id' => $contract->organization_id,
                'contractor_id' => $contract->contractor_id ?? null,
                'contractor_source_org_id' => $contract->contractor->source_organization_id ?? null
            ]);
            return null;
        }
        
        // 🔐 ДОПОЛНИТЕЛЬНАЯ ПРОВЕРКА: если это подрядчик, проверяем ограничения
        if ($isContractor) {
            $organization = \App\Models\Organization::find($organizationId);
            $activeRestriction = \App\Models\OrganizationAccessRestriction::where('organization_id', $organizationId)
                ->where('restriction_type', 'new_contractor_verification')
                ->where(function($q) {
                    $q->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
                })
                ->first();
            
            if ($activeRestriction) {
                Log::warning('[ContractService] Contract access denied - contractor pending verification', [
                    'contract_id' => $contractId,
                    'organization_id' => $organizationId,
                    'restriction_id' => $activeRestriction->id,
                    'restriction_reason' => $activeRestriction->reason,
                    'access_level' => $activeRestriction->access_level
                ]);
                
                // Возвращаем null или можно выбросить исключение
                return null;
            }
        }
        
        Log::info('[ContractService] Contract access granted', [
            'contract_id' => $contractId,
            'organization_id' => $organizationId,
            'access_type' => $isCustomer ? 'customer' : 'contractor',
            'contractor_id' => $contract->contractor_id ?? null,
            'source_organization_id' => $contract->contractor->source_organization_id ?? null
        ]);
        
        return $contract->load([
            'contractor', 
            'project', 
            'project.organization',           // Для customer (заказчик)
            'agreements',                     // Дополнительные соглашения
            'specifications',                 // Спецификации
            'performanceActs',
            'performanceActs.completedWorks'
            // 'payments' - УДАЛЕНО: платежи теперь в модуле Payments (invoices)
        ]);
    }

    public function updateContract(int $contractId, int $organizationId, ContractDTO $contractDTO): Contract
    {
        $contract = $this->getContractById($contractId, $organizationId);
        if (!$contract) {
            throw new Exception('Contract not found.');
        }

        // Сохраняем старые значения для аудита
        $oldValues = [
            'number' => $contract->number,
            'total_amount' => $contract->total_amount,
            'status' => $contract->status->value ?? $contract->status,
            'contractor_id' => $contract->contractor_id,
            'start_date' => $contract->start_date,
            'end_date' => $contract->end_date
        ];
        
        Log::info('ContractService::updateContract - DTO RECEIVED', [
            'contract_id' => $contractId,
            'organization_id' => $organizationId,
            'dto_total_amount' => $contractDTO->total_amount,
            'current_db_total_amount' => $contract->total_amount
        ]);
        
        // BUSINESS: Начало обновления договора
        $this->logging->business('contract.update.started', [
            'organization_id' => $organizationId,
            'contract_id' => $contractId,
            'contract_number' => $contract->number,
            'old_total_amount' => $contract->total_amount,
            'new_total_amount' => $contractDTO->total_amount ?? null,
            'user_id' => Auth::id()
        ]);

        // Дополнительные проверки перед обновлением

        $updateData = $contractDTO->toArray();
        
        // Если warranty_retention_percentage не указан или равен null, не передаем его в массив,
        // чтобы сохранить текущее значение или использовать значение по умолчанию из БД (2.5)
        if (!isset($updateData['warranty_retention_percentage']) || $updateData['warranty_retention_percentage'] === null) {
            unset($updateData['warranty_retention_percentage']);
        }
        
        // Сохраняем project_ids для мультипроектных контрактов
        $projectIds = $updateData['project_ids'] ?? null;
        unset($updateData['project_ids']);
        
        Log::info('ContractService::updateContract - UPDATE DATA', [
            'contract_id' => $contractId,
            'update_data_keys' => array_keys($updateData),
            'update_data_total_amount' => $updateData['total_amount'] ?? 'NOT SET',
            'update_data' => $updateData
        ]);
        
        try {
            DB::beginTransaction();
            
            $updated = $this->contractRepository->update($contract->id, $updateData);

            if (!$updated) {
                // Можно добавить более специфичную ошибку или логирование
                throw new Exception('Failed to update contract.');
            }

            $updatedContract = $this->getContractById($contractId, $organizationId);
            
            // Синхронизируем проекты для мультипроектного контракта
            if ($contractDTO->is_multi_project && !empty($projectIds)) {
                // Валидация: все проекты должны принадлежать организации
                $validProjects = Project::whereIn('id', $projectIds)
                    ->where('organization_id', $organizationId)
                    ->pluck('id')
                    ->toArray();
                
                if (count($validProjects) !== count($projectIds)) {
                    throw new Exception('Некоторые проекты не найдены или не принадлежат вашей организации');
                }
                
                $updatedContract->syncProjects($projectIds);
            } elseif (!$contractDTO->is_multi_project && $contractDTO->project_id) {
                // Для обычного контракта синхронизируем один проект
                $updatedContract->syncProjects([$contractDTO->project_id]);
            }
            
            DB::commit();
            
            Log::info('ContractService::updateContract - AFTER UPDATE', [
                'contract_id' => $contractId,
                'updated_total_amount' => $updatedContract->total_amount,
                'old_total_amount' => $oldValues['total_amount'],
                'change_detected' => $updatedContract->total_amount != $oldValues['total_amount']
            ]);

            // Создаем событие истории, если контракт использует Event Sourcing и изменилась сумма
            if ($updatedContract->usesEventSourcing() && $updatedContract->total_amount != $oldValues['total_amount']) {
                try {
                    $amountDelta = $updatedContract->total_amount - $oldValues['total_amount'];
                    
                    // Находим активную спецификацию для события (если есть)
                    $activeSpecification = $updatedContract->specifications()->wherePivot('is_active', true)->first();
                    
                    $this->getStateEventService()->createAmendedEvent(
                        $updatedContract,
                        $activeSpecification?->id ?? null,
                        $amountDelta,
                        $updatedContract, // triggeredBy - сам контракт
                        now(),
                        [
                            'reason' => 'Изменение суммы контракта',
                            'old_amount' => $oldValues['total_amount'],
                            'new_amount' => $updatedContract->total_amount,
                            'contract_number' => $updatedContract->number,
                        ]
                    );

                    // Обновляем материализованное представление
                    app(\App\Services\Contract\ContractStateCalculatorService::class)->recalculateContractState($updatedContract);
                } catch (Exception $e) {
                    // Не критично, если событие не создалось - логируем и продолжаем
                    Log::warning('Failed to create contract update event', [
                        'contract_id' => $updatedContract->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // BUSINESS: Договор успешно обновлён
            $this->logging->business('contract.updated', [
                'organization_id' => $organizationId,
                'contract_id' => $contractId,
                'contract_number' => $updatedContract->number,
                'old_total_amount' => $oldValues['total_amount'],
                'new_total_amount' => $updatedContract->total_amount,
                'amount_changed' => $updatedContract->total_amount != $oldValues['total_amount'],
                'status_changed' => ($updatedContract->status->value ?? $updatedContract->status) != $oldValues['status'],
                'user_id' => Auth::id()
            ]);

            // AUDIT: Изменение договора для compliance
            $this->logging->audit('contract.updated', [
                'organization_id' => $organizationId,
                'contract_id' => $contractId,
                'contract_number' => $updatedContract->number,
                'transaction_type' => 'contract_updated',
                'performed_by' => Auth::id() ?? 'system',
                'changes' => [
                    'old_values' => $oldValues,
                    'new_values' => [
                        'number' => $updatedContract->number,
                        'total_amount' => $updatedContract->total_amount,
                        'status' => $updatedContract->status->value ?? $updatedContract->status,
                        'contractor_id' => $updatedContract->contractor_id
                    ]
                ]
            ]);

            return $updatedContract; // Возвращаем свежую модель

        } catch (Exception $e) {
            DB::rollBack();
            
            // BUSINESS: Неудачное обновление договора
            $this->logging->business('contract.update.failed', [
                'organization_id' => $organizationId,
                'contract_id' => $contractId,
                'contract_number' => $contract->number,
                'error_message' => $e->getMessage(),
                'user_id' => Auth::id()
            ], 'error');
            
            throw $e;
        }
    }

    public function deleteContract(int $contractId, int $organizationId): bool
    {
        $contract = $this->getContractById($contractId, $organizationId);
        if (!$contract) {
            throw new Exception('Contract not found or does not belong to organization.');
        }

        // SECURITY: Попытка удаления договора - критично для аудита
        // Получаем количество платежей из таблицы payment_documents
        $paymentsCount = DB::table('payment_documents')
            ->where('invoiceable_type', 'App\\Models\\Contract')
            ->where('invoiceable_id', $contractId)
            ->whereNull('deleted_at')
            ->count();

        $this->logging->security('contract.deletion.attempt', [
            'organization_id' => $organizationId,
            'contract_id' => $contractId,
            'contract_number' => $contract->number,
            'contract_amount' => $contract->total_amount,
            'contract_status' => $contract->status->value ?? $contract->status,
            'has_performance_acts' => $contract->performanceActs->count() > 0,
            'has_payments' => $paymentsCount > 0,
            'user_id' => Auth::id(),
            'user_ip' => request()->ip()
        ], 'warning');

        // BUSINESS: Начало удаления договора
        $this->logging->business('contract.deletion.started', [
            'organization_id' => $organizationId,
            'contract_id' => $contractId,
            'contract_number' => $contract->number,
            'contract_amount' => $contract->total_amount,
            'related_acts_count' => $contract->performanceActs->count(),
            'related_payments_count' => $paymentsCount,
            'user_id' => Auth::id()
        ]);

        try {
            // Возможно, стоит проверить наличие связанных актов/платежей перед удалением
            // или настроить каскадное удаление/soft deletes на уровне БД
            $deleted = $this->contractRepository->delete($contract->id);

            if ($deleted) {
                // BUSINESS: Договор успешно удалён
                $this->logging->business('contract.deleted', [
                    'organization_id' => $organizationId,
                    'contract_id' => $contractId,
                    'contract_number' => $contract->number,
                    'contract_amount' => $contract->total_amount,
                    'user_id' => Auth::id()
                ]);

                // AUDIT: Удаление договора - критично для compliance
                $this->logging->audit('contract.deleted', [
                    'organization_id' => $organizationId,
                    'contract_id' => $contractId,
                    'contract_number' => $contract->number,
                    'transaction_type' => 'contract_deleted',
                    'performed_by' => Auth::id() ?? 'system',
                    'contract_details' => [
                        'total_amount' => $contract->total_amount,
                        'contractor_id' => $contract->contractor_id,
                        'status' => $contract->status->value ?? $contract->status,
                        'start_date' => $contract->start_date,
                        'end_date' => $contract->end_date
                    ]
                ]);
            }

            return $deleted;

        } catch (Exception $e) {
            // BUSINESS: Неудачное удаление договора
            $this->logging->business('contract.deletion.failed', [
                'organization_id' => $organizationId,
                'contract_id' => $contractId,
                'contract_number' => $contract->number,
                'error_message' => $e->getMessage(),
                'user_id' => Auth::id()
            ], 'error');
            
            throw $e;
        }
    }
    
    /**
     * Получить полную детальную информацию по контракту
     */
    public function getFullContractDetails(int $contractId, int $organizationId): array
    {
        $contract = $this->contractRepository->find($contractId);
        
        if (!$contract || $contract->organization_id !== $organizationId) {
            throw new Exception('Contract not found or does not belong to organization.');
        }

        // Загружаем все связи одним запросом
        $contract->load([
            'contractor:id,name,legal_address,inn,kpp,phone,email',
            'project:id,name,address,description',
            'parentContract:id,number,total_amount,status',
            'performanceActs:id,contract_id,act_document_number,act_date,amount,description,is_approved,approval_date',
            'performanceActs.completedWorks:id,work_type_id,user_id,quantity,total_amount,status,completion_date',
            'performanceActs.completedWorks.workType:id,name',
            'performanceActs.completedWorks.user:id,name',
            // 'payments' - УДАЛЕНО: платежи теперь в модуле Payments (invoices)
            'completedWorks:id,contract_id,work_type_id,user_id,quantity,total_amount,status,completion_date',
            'completedWorks.workType:id,name',
            'completedWorks.user:id,name',
            'completedWorks.materials',
            'agreements:id,contract_id,number,agreement_date,change_amount,subject_changes,supersede_agreement_ids,created_at,updated_at',
            'specifications:id,number,spec_date,total_amount,status,scope_items'
        ]);

        // Принудительно загружаем дочерние контракты отдельно
        $contract->setRelation('childContracts', 
            Contract::where('parent_contract_id', $contract->id)
                   ->where('organization_id', $contract->organization_id)
                   ->select('id', 'number', 'total_amount', 'status')
                   ->get()
        );

        // TECHNICAL: Диагностика дочерних контрактов для системного анализа
        $dbChildren = \App\Models\Contract::where('parent_contract_id', $contract->id)->get();
        $this->logging->technical('contract.child_contracts.diagnostic', [
            'contract_id' => $contract->id,
            'contract_number' => $contract->number,
            'contract_org_id' => $contract->organization_id,
            'childContracts_loaded' => $contract->relationLoaded('childContracts'),
            'childContracts_count' => $contract->childContracts->count(),
            'childContracts_ids' => $contract->childContracts->pluck('id')->toArray(),
            'db_children_count' => $dbChildren->count(),
            'db_children_details' => $dbChildren->map(function($child) {
                return [
                    'id' => $child->id,
                    'number' => $child->number,
                    'organization_id' => $child->organization_id,
                    'parent_contract_id' => $child->parent_contract_id,
                    'deleted_at' => $child->deleted_at,
                ];
            })->toArray(),
            'user_id' => Auth::id()
        ]);

        // Аналитика на основе загруженных связей (не новых запросов!)
        $analytics = $this->buildContractAnalyticsFromLoaded($contract);
        
        // Статистика по работам из уже загруженной коллекции
        $worksStatistics = $this->buildWorksStatisticsFromLoaded($contract);
        
        // Форматируем уже загруженные работы
        $recentWorks = $this->formatCompletedWorksFromLoaded($contract);
        
        return [
            'contract' => $contract,
            'analytics' => $analytics,
            'works_statistics' => $worksStatistics,
            'recent_works' => $recentWorks,
        ];
    }

    /**
     * Построить аналитические данные из уже загруженных связей
     */
    private function buildContractAnalyticsFromLoaded(Contract $contract): array
    {
        // Работаем только с уже загруженными данными
        $confirmedWorks = $contract->completedWorks->where('status', 'confirmed');
        $pendingWorks = $contract->completedWorks->where('status', 'pending');
        $approvedActs = $contract->performanceActs->where('is_approved', true);
        
        $completedWorksAmount = $confirmedWorks->sum('total_amount');
        
        // Получаем оплаченную сумму и количество платежей из таблицы payment_documents
        $documentsQuery = DB::table('payment_documents')
            ->where('invoiceable_type', 'App\\Models\\Contract')
            ->where('invoiceable_id', $contract->id)
            ->whereNull('deleted_at');
        
        $totalPaidAmount = $documentsQuery->sum('paid_amount');
        $paymentsCount = $documentsQuery->count();

        // --- Расширяем стоимость контракта ---
        $agreementsDelta = $contract->relationLoaded('agreements') ? $contract->agreements->sum('change_amount') : 0;
        $childContractsTotal = $contract->relationLoaded('childContracts') ? $contract->childContracts->sum('total_amount') : 0;
        $specificationsTotal = $contract->relationLoaded('specifications') ? $contract->specifications->sum('total_amount') : 0;

        // Итоговая «стоимость контракта» с учётом доп. соглашений, дочерних контрактов и спецификаций
        $aggregatedContractAmount = (float) $contract->total_amount + (float) $agreementsDelta + (float) $childContractsTotal + (float) $specificationsTotal;

        // Расчет ГП: используем accessor модели для правильного расчета от base_amount
        $gpPercentage = (float) $contract->gp_percentage;
        $gpAmountAgg = (float) $contract->gp_amount; // Accessor рассчитывает от base_amount
        $totalWithGpAgg = (float) $contract->total_amount_with_gp; // base_amount + gp_amount

        // Новый расчёт суммы актов на основе включённых работ
        $totalPerformedAmount = $this->calculateActualPerformedAmount($approvedActs);

        return [
            'financial' => [
                'total_amount' => $aggregatedContractAmount,
                'base_contract_amount' => (float) $contract->total_amount,
                'agreements_delta' => (float) $agreementsDelta,
                'child_contracts_amount' => (float) $childContractsTotal,
                'specifications_amount' => (float) $specificationsTotal,
                'gp_percentage' => $gpPercentage,
                'gp_amount' => $gpAmountAgg,
                'total_amount_with_gp' => $totalWithGpAgg,
                'completed_works_amount' => (float) $completedWorksAmount,
                'remaining_amount' => (float) max(0, $aggregatedContractAmount - $totalPerformedAmount),
                'completion_percentage' => $aggregatedContractAmount > 0 ? 
                    round(($totalPerformedAmount / $aggregatedContractAmount) * 100, 2) : 0.0,
                'total_paid_amount' => (float) $totalPaidAmount,
                'total_performed_amount' => (float) $totalPerformedAmount,
                'planned_advance_amount' => (float) $contract->planned_advance_amount,
                'actual_advance_amount' => (float) $contract->actual_advance_amount,
                'remaining_advance_amount' => (float) $contract->remaining_advance_amount,
                'advance_payment_percentage' => (float) $contract->advance_payment_percentage,
                'is_advance_fully_paid' => $contract->is_advance_fully_paid,
            ],
            'status' => [
                'current_status' => $contract->status->value,
                'is_nearing_limit' => $totalPerformedAmount >= ($aggregatedContractAmount * 0.9),
                'can_add_work' => !in_array($contract->status->value, ['completed', 'terminated']),
                'is_overdue' => $contract->is_overdue,
                'days_until_deadline' => $contract->end_date ? now()->diffInDays($contract->end_date, false) : null,
            ],
            'counts' => [
                'total_works' => $contract->completedWorks->count(),
                'confirmed_works' => $confirmedWorks->count(),
                'pending_works' => $pendingWorks->count(),
                'performance_acts' => $contract->performanceActs->count(),
                'approved_acts' => $approvedActs->count(),
                'payments_count' => $paymentsCount,
                'child_contracts' => $contract->childContracts->count(),
                'agreements_count' => $contract->agreements->count(),
                'specifications_count' => $contract->specifications->count(),
            ]
        ];
    }

    /**
     * Рассчитать фактическую сумму выполненных работ по актам
     */
    private function calculateActualPerformedAmount($approvedActs): float
    {
        $totalAmount = 0;
        
        foreach ($approvedActs as $act) {
            // Если у акта есть связанные работы - считаем по ним
            if ($act->relationLoaded('completedWorks') && $act->completedWorks->count() > 0) {
                $totalAmount += $act->completedWorks->sum('pivot.included_amount');
            } else {
                // Если работы не связаны - используем старое поле amount (для совместимости)
                $totalAmount += $act->amount ?? 0;
            }
        }
        
        return $totalAmount;
    }

    /**
     * Построить статистику работ из загруженных данных
     */
    private function buildWorksStatisticsFromLoaded(Contract $contract): array
    {
        $works = $contract->completedWorks;
        
        $pending = $works->where('status', 'pending');
        $confirmed = $works->where('status', 'confirmed');
        $rejected = $works->where('status', 'rejected');

        return [
            'pending' => [
                'count' => $pending->count(),
                'amount' => (float) $pending->sum('total_amount'),
                'avg_amount' => $pending->count() > 0 ? (float) $pending->avg('total_amount') : 0
            ],
            'confirmed' => [
                'count' => $confirmed->count(),
                'amount' => (float) $confirmed->sum('total_amount'),
                'avg_amount' => $confirmed->count() > 0 ? (float) $confirmed->avg('total_amount') : 0
            ],
            'rejected' => [
                'count' => $rejected->count(),
                'amount' => (float) $rejected->sum('total_amount'),
                'avg_amount' => $rejected->count() > 0 ? (float) $rejected->avg('total_amount') : 0
            ]
        ];
    }

    /**
     * Форматировать загруженные выполненные работы
     */
    private function formatCompletedWorksFromLoaded(Contract $contract): array
    {
        return $contract->completedWorks->map(function ($work) {
            return [
                'id' => $work->id,
                'work_type_name' => $work->workType->name ?? 'Не указано',
                'user_name' => $work->user->name ?? 'Не указано',
                'quantity' => (float) $work->quantity,
                'total_amount' => (float) $work->total_amount,
                'status' => $work->status,
                'completion_date' => $work->completion_date,
                'materials_count' => $work->materials->count() ?? 0,
                'materials_amount' => (float) ($work->materials->sum('pivot.total_amount') ?? 0),
            ];
        })->toArray();
    }

    public function attachToParentContract(int $contractId, int $organizationId, int $parentContractId): Contract
    {
        $this->logging->business('contract.parent.attach.started', [
            'contract_id' => $contractId,
            'organization_id' => $organizationId,
            'parent_contract_id' => $parentContractId,
        ]);

        DB::beginTransaction();
        try {
            $contract = $this->contractRepository->findAccessible($contractId, $organizationId);
            
            if (!$contract) {
                throw new Exception('Контракт не найден');
            }

            $parentContract = $this->contractRepository->findAccessible($parentContractId, $organizationId);
            
            if (!$parentContract) {
                throw new Exception('Родительский контракт не найден');
            }

            if ($contract->parent_contract_id === $parentContractId) {
                throw new Exception('Контракт уже привязан к этому родительскому контракту');
            }

            $contract->parent_contract_id = $parentContractId;
            $contract->save();

            DB::commit();

            $this->logging->business('contract.parent.attach.success', [
                'contract_id' => $contractId,
                'parent_contract_id' => $parentContractId,
            ]);

            return $contract->fresh(['contractor', 'project', 'parentContract']);

        } catch (Exception $e) {
            DB::rollBack();
            
            $this->logging->technical('contract.parent.attach.failed', [
                'contract_id' => $contractId,
                'parent_contract_id' => $parentContractId,
                'error' => $e->getMessage()
            ], 'error');

            throw $e;
        }
    }

    public function detachFromParentContract(int $contractId, int $organizationId): Contract
    {
        $this->logging->business('contract.parent.detach.started', [
            'contract_id' => $contractId,
            'organization_id' => $organizationId,
        ]);

        DB::beginTransaction();
        try {
            $contract = $this->contractRepository->findAccessible($contractId, $organizationId);
            
            if (!$contract) {
                throw new Exception('Контракт не найден');
            }

            if (!$contract->parent_contract_id) {
                throw new Exception('Контракт не привязан к родительскому контракту');
            }

            $oldParentId = $contract->parent_contract_id;
            $contract->parent_contract_id = null;
            $contract->save();

            DB::commit();

            $this->logging->business('contract.parent.detach.success', [
                'contract_id' => $contractId,
                'old_parent_contract_id' => $oldParentId,
            ]);

            return $contract->fresh(['contractor', 'project', 'parentContract']);

        } catch (Exception $e) {
            DB::rollBack();
            
            $this->logging->technical('contract.parent.detach.failed', [
                'contract_id' => $contractId,
                'error' => $e->getMessage()
            ], 'error');

            throw $e;
        }
    }

    public function getContractsSummary(int $organizationId, array $filters = []): array
    {
        $query = Contract::query();
        
        // Если указан contractor_context - не фильтруем по organization_id
        if (empty($filters['contractor_context'])) {
            $query->where('organization_id', $organizationId);
        }

        if (!empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        if (!empty($filters['contractor_id'])) {
            $query->where('contractor_id', $filters['contractor_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['work_type_category'])) {
            $query->where('work_type_category', $filters['work_type_category']);
        }

        $statusCounts = (clone $query)->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $financialData = (clone $query)->select(
            DB::raw('SUM(CASE 
                WHEN is_fixed_amount = true THEN COALESCE(base_amount, 0)
                ELSE COALESCE(total_amount, 0)
            END) as base_sum'),
            DB::raw('SUM(CASE 
                WHEN is_fixed_amount = true AND gp_calculation_type = \'coefficient\' 
                    THEN COALESCE(base_amount, 0) + (COALESCE(base_amount, 0) * (COALESCE(gp_coefficient, 1) - 1))
                WHEN is_fixed_amount = true 
                    THEN COALESCE(base_amount, 0) + (COALESCE(base_amount, 0) * COALESCE(gp_percentage, 0) / 100)
                ELSE COALESCE(total_amount, 0)
            END) as total_with_gp'),
            DB::raw('SUM(COALESCE(planned_advance_amount, 0)) as total_planned_advance'),
            DB::raw('SUM(COALESCE(actual_advance_amount, 0)) as total_actual_advance')
        )->first();

        $totalContracts = (clone $query)->count();
        $activeContracts = $statusCounts['active'] ?? 0;
        $completedContracts = $statusCounts['completed'] ?? 0;
        $cancelledContracts = $statusCounts['cancelled'] ?? 0;

        $baseSum = $financialData->base_sum ?? 0;
        $totalAmountWithGp = $financialData->total_with_gp ?? 0;
        $totalPlannedAdvance = $financialData->total_planned_advance ?? 0;
        $totalActualAdvance = $financialData->total_actual_advance ?? 0;

        $totalPerformedAmount = (float) DB::table('contract_performance_acts')
            ->join('contracts', 'contract_performance_acts.contract_id', '=', 'contracts.id')
            ->when(empty($filters['contractor_context']), fn($q) => $q->where('contracts.organization_id', $organizationId))
            ->whereNull('contracts.deleted_at')
            ->where('contract_performance_acts.is_approved', true)
            ->when(!empty($filters['project_id']), fn($q) => $q->where('contracts.project_id', $filters['project_id']))
            ->when(!empty($filters['contractor_id']), fn($q) => $q->where('contracts.contractor_id', $filters['contractor_id']))
            ->when(!empty($filters['status']), fn($q) => $q->where('contracts.status', $filters['status']))
            ->when(!empty($filters['work_type_category']), fn($q) => $q->where('contracts.work_type_category', $filters['work_type_category']))
            ->sum('contract_performance_acts.amount') ?: 0;

        // Используем таблицу payment_documents вместо устаревшей invoices
        $totalPaidAmount = (float) DB::table('payment_documents')
            ->join('contracts', function($join) {
                $join->on('payment_documents.invoiceable_id', '=', 'contracts.id')
                     ->where('payment_documents.invoiceable_type', '=', 'App\\Models\\Contract');
            })
            ->when(empty($filters['contractor_context']), fn($q) => $q->where('contracts.organization_id', $organizationId))
            ->whereNull('contracts.deleted_at')
            ->whereNull('payment_documents.deleted_at')
            ->when(!empty($filters['project_id']), fn($q) => $q->where('contracts.project_id', $filters['project_id']))
            ->when(!empty($filters['contractor_id']), fn($q) => $q->where('contracts.contractor_id', $filters['contractor_id']))
            ->when(!empty($filters['status']), fn($q) => $q->where('contracts.status', $filters['status']))
            ->when(!empty($filters['work_type_category']), fn($q) => $q->where('contracts.work_type_category', $filters['work_type_category']))
            ->sum('payment_documents.paid_amount') ?: 0;

        $overdueContracts = (clone $query)
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->where('end_date', '<', now())
            ->count();

        $nearingLimitSubquery = DB::table('contracts as c')
            ->leftJoin('completed_works as cw', function($join) {
                $join->on('cw.contract_id', '=', 'c.id')
                     ->where('cw.status', '=', 'confirmed');
            })
            ->select('c.id', 'c.total_amount', DB::raw('COALESCE(SUM(cw.total_amount), 0) as completed_amount'))
            ->when(empty($filters['contractor_context']), fn($q) => $q->where('c.organization_id', $organizationId))
            ->whereNull('c.deleted_at')
            ->when(!empty($filters['project_id']), fn($q) => $q->where('c.project_id', $filters['project_id']))
            ->when(!empty($filters['contractor_id']), fn($q) => $q->where('c.contractor_id', $filters['contractor_id']))
            ->when(!empty($filters['status']), fn($q) => $q->where('c.status', $filters['status']))
            ->when(!empty($filters['work_type_category']), fn($q) => $q->where('c.work_type_category', $filters['work_type_category']))
            ->groupBy('c.id', 'c.total_amount')
            ->havingRaw('(c.total_amount - COALESCE(SUM(cw.total_amount), 0)) <= (c.total_amount * 0.1)')
            ->havingRaw('(c.total_amount - COALESCE(SUM(cw.total_amount), 0)) > 0');
        
        $nearingLimitContracts = DB::table(DB::raw("({$nearingLimitSubquery->toSql()}) as subquery"))
            ->mergeBindings($nearingLimitSubquery)
            ->count();

        return [
            'total_contracts' => $totalContracts,
            'by_status' => [
                'active' => $activeContracts,
                'completed' => $completedContracts,
                'cancelled' => $cancelledContracts,
            ],
            'financial' => [
                'total_amount' => round($baseSum, 2),
                'total_amount_with_gp' => round($totalAmountWithGp, 2),
                'total_performed_amount' => round($totalPerformedAmount, 2),
                'total_paid_amount' => round($totalPaidAmount, 2),
                'remaining_to_perform' => round($totalAmountWithGp - $totalPerformedAmount, 2),
                'remaining_to_pay' => round($totalPerformedAmount - $totalPaidAmount, 2),
                'performance_percentage' => $totalAmountWithGp > 0 ? round(($totalPerformedAmount / $totalAmountWithGp) * 100, 2) : 0,
                'payment_percentage' => $totalPerformedAmount > 0 ? round(($totalPaidAmount / $totalPerformedAmount) * 100, 2) : 0,
            ],
            'advances' => [
                'total_planned' => round($totalPlannedAdvance, 2),
                'total_actual' => round($totalActualAdvance, 2),
                'remaining' => round($totalPlannedAdvance - $totalActualAdvance, 2),
            ],
            'alerts' => [
                'overdue_contracts' => $overdueContracts,
                'nearing_limit_contracts' => $nearingLimitContracts,
            ],
        ];
    }

}