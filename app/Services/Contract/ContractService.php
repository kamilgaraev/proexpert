<?php

namespace App\Services\Contract;

use App\Repositories\Interfaces\ContractRepositoryInterface;
use App\Repositories\Interfaces\ContractorRepositoryInterface;
use App\Repositories\Interfaces\ContractPerformanceActRepositoryInterface;
use App\Repositories\Interfaces\ContractPaymentRepositoryInterface;
use App\DTOs\Contract\ContractDTO; // Создадим его позже
use App\Models\Contract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Exception;

class ContractService
{
    protected ContractRepositoryInterface $contractRepository;
    protected ContractorRepositoryInterface $contractorRepository; 
    protected ContractPerformanceActRepositoryInterface $actRepository;
    protected ContractPaymentRepositoryInterface $paymentRepository;

    public function __construct(
        ContractRepositoryInterface $contractRepository,
        ContractorRepositoryInterface $contractorRepository,
        ContractPerformanceActRepositoryInterface $actRepository,
        ContractPaymentRepositoryInterface $paymentRepository
    ) {
        $this->contractRepository = $contractRepository;
        $this->contractorRepository = $contractorRepository;
        $this->actRepository = $actRepository;
        $this->paymentRepository = $paymentRepository;
    }

    public function getAllContracts(int $organizationId, int $perPage = 15, array $filters = [], string $sortBy = 'date', string $sortDirection = 'desc'): LengthAwarePaginator
    {
        return $this->contractRepository->getContractsForOrganizationPaginated($organizationId, $perPage, $filters, $sortBy, $sortDirection);
    }

    public function createContract(int $organizationId, ContractDTO $contractDTO): Contract
    {
        // Убедимся, что подрядчик существует и принадлежит этой организации
        // $contractor = $this->contractorRepository->find($contractDTO->contractor_id);
        // if (!$contractor || $contractor->organization_id !== $organizationId) {
        //     throw new Exception('Contractor not found or does not belong to the organization.');
        // }

        // Дополнительные проверки, например, для parent_contract_id

        $contractData = $contractDTO->toArray();
        $contractData['organization_id'] = $organizationId;

        return $this->contractRepository->create($contractData);
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
        
        // Дополнительные проверки перед обновлением

        $updateData = $contractDTO->toArray();
        $updated = $this->contractRepository->update($contract->id, $updateData);

        if (!$updated) {
            // Можно добавить более специфичную ошибку или логирование
            throw new Exception('Failed to update contract.');
        }

        return $this->getContractById($contractId, $organizationId); // Возвращаем свежую модель
    }

    public function deleteContract(int $contractId, int $organizationId): bool
    {
        $contract = $this->getContractById($contractId, $organizationId);
        if (!$contract) {
            throw new Exception('Contract not found or does not belong to organization.');
        }
        // Возможно, стоит проверить наличие связанных актов/платежей перед удалением
        // или настроить каскадное удаление/soft deletes на уровне БД
        return $this->contractRepository->delete($contract->id);
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
            'completedWorks.materials'
        ]);

        // Принудительно загружаем дочерние контракты отдельно
        $contract->setRelation('childContracts', 
            Contract::where('parent_contract_id', $contract->id)
                   ->where('organization_id', $contract->organization_id)
                   ->select('id', 'number', 'total_amount', 'status')
                   ->get()
        );

        // Временная диагностика дочерних контрактов
        $dbChildren = \App\Models\Contract::where('parent_contract_id', $contract->id)->get();
        Log::info('Contract child contracts check:', [
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
        
        // Новый расчет суммы актов на основе включенных работ
        $totalPerformedAmount = $this->calculateActualPerformedAmount($approvedActs);

        return [
            'financial' => [
                'total_amount' => (float) $contract->total_amount,
                'gp_percentage' => (float) $contract->gp_percentage,
                'gp_amount' => (float) $contract->gp_amount,
                'total_amount_with_gp' => (float) $contract->total_amount_with_gp,
                'completed_works_amount' => (float) $completedWorksAmount,
                'remaining_amount' => (float) max(0, $contract->total_amount - $totalPerformedAmount),
                'completion_percentage' => $contract->total_amount > 0 ? 
                    round(($totalPerformedAmount / $contract->total_amount) * 100, 2) : 0.0,
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
                'is_nearing_limit' => $totalPerformedAmount >= ($contract->total_amount * 0.9),
                'can_add_work' => !in_array($contract->status->value, ['completed', 'terminated']),
                'is_overdue' => $contract->end_date && $contract->end_date->isPast(),
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


} 