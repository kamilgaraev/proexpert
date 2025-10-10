<?php

namespace App\Services\Contract;

use App\Repositories\Interfaces\ContractRepositoryInterface;
use App\Repositories\Interfaces\ContractorRepositoryInterface;
use App\Repositories\Interfaces\ContractPerformanceActRepositoryInterface;
use App\Repositories\Interfaces\ContractPaymentRepositoryInterface;
use App\DTOs\Contract\ContractDTO; // Создадим его позже
use App\Models\Contract;
use App\Services\Logging\LoggingService;
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

    public function __construct(
        ContractRepositoryInterface $contractRepository,
        ContractorRepositoryInterface $contractorRepository,
        ContractPerformanceActRepositoryInterface $actRepository,
        ContractPaymentRepositoryInterface $paymentRepository,
        LoggingService $logging
    ) {
        $this->contractRepository = $contractRepository;
        $this->contractorRepository = $contractorRepository;
        $this->actRepository = $actRepository;
        $this->paymentRepository = $paymentRepository;
        $this->logging = $logging;
    }

    public function getAllContracts(int $organizationId, int $perPage = 15, array $filters = [], string $sortBy = 'date', string $sortDirection = 'desc'): LengthAwarePaginator
    {
        return $this->contractRepository->getContractsForOrganizationPaginated($organizationId, $perPage, $filters, $sortBy, $sortDirection);
    }

    public function createContract(int $organizationId, ContractDTO $contractDTO): Contract
    {
        // BUSINESS: Начало создания договора - важная бизнес-операция
        $this->logging->business('contract.creation.started', [
            'organization_id' => $organizationId,
            'contractor_id' => $contractDTO->contractor_id ?? null,
            'total_amount' => $contractDTO->total_amount ?? null,
            'contract_number' => $contractDTO->number ?? null,
            'user_id' => Auth::id()
        ]);

        // Убедимся, что подрядчик существует и принадлежит этой организации
        // $contractor = $this->contractorRepository->find($contractDTO->contractor_id);
        // if (!$contractor || $contractor->organization_id !== $organizationId) {
        //     throw new Exception('Contractor not found or does not belong to the organization.');
        // }

        // Дополнительные проверки, например, для parent_contract_id

        $contractData = $contractDTO->toArray();
        $contractData['organization_id'] = $organizationId;

        try {
            $contract = $this->contractRepository->create($contractData);

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

    public function getContractById(int $contractId, int $organizationId): ?Contract
    {
        $contract = $this->contractRepository->find($contractId);
        if ($contract && $contract->organization_id === $organizationId) {
            return $contract->load([
                'contractor', 
                'project', 
                'parentContract', 
                'childContracts', 
                'performanceActs',
                'performanceActs.completedWorks',
                'payments'
            ]);
        }
        return null;
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
        
        try {
            $updated = $this->contractRepository->update($contract->id, $updateData);

            if (!$updated) {
                // Можно добавить более специфичную ошибку или логирование
                throw new Exception('Failed to update contract.');
            }

            $updatedContract = $this->getContractById($contractId, $organizationId);

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
        $this->logging->security('contract.deletion.attempt', [
            'organization_id' => $organizationId,
            'contract_id' => $contractId,
            'contract_number' => $contract->number,
            'contract_amount' => $contract->total_amount,
            'contract_status' => $contract->status->value ?? $contract->status,
            'has_performance_acts' => $contract->performanceActs->count() > 0,
            'has_payments' => $contract->payments->count() > 0,
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
            'related_payments_count' => $contract->payments->count(),
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
            'payments:id,contract_id,payment_date,amount,payment_type,reference_document_number,description',
            'completedWorks:id,contract_id,work_type_id,user_id,quantity,total_amount,status,completion_date',
            'completedWorks.workType:id,name',
            'completedWorks.user:id,name',
            'completedWorks.materials',
            'agreements:id,contract_id,number,agreement_date,change_amount,subject_changes,created_at,updated_at',
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
        $totalPaidAmount = $contract->payments->sum('amount');

        // --- Расширяем стоимость контракта ---
        $agreementsDelta = $contract->relationLoaded('agreements') ? $contract->agreements->sum('change_amount') : 0;
        $childContractsTotal = $contract->relationLoaded('childContracts') ? $contract->childContracts->sum('total_amount') : 0;
        $specificationsTotal = $contract->relationLoaded('specifications') ? $contract->specifications->sum('total_amount') : 0;

        // Итоговая «стоимость контракта» с учётом доп. соглашений, дочерних контрактов и спецификаций
        $aggregatedContractAmount = (float) $contract->total_amount + (float) $agreementsDelta + (float) $childContractsTotal + (float) $specificationsTotal;

        // Пересчитываем GP и связанную сумму
        $gpPercentage = (float) $contract->gp_percentage;
        $gpAmountAgg = $gpPercentage > 0 ? round(($aggregatedContractAmount * $gpPercentage) / 100, 2) : 0.0;
        $totalWithGpAgg = $aggregatedContractAmount + $gpAmountAgg;

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
                'payments_count' => $contract->payments->count(),
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

}