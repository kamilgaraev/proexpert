<?php

namespace App\Services\Contract;

use App\Repositories\Interfaces\ContractRepositoryInterface;
use App\Repositories\Interfaces\ContractorRepositoryInterface;
use App\Repositories\Interfaces\ContractPerformanceActRepositoryInterface;
use App\Repositories\Interfaces\ContractPaymentRepositoryInterface;
use App\DTOs\Contract\ContractDTO; // Создадим его позже
use App\Models\Contract;
use Illuminate\Support\Facades\DB;
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
            return $contract->load(['contractor', 'project', 'parentContract', 'childContracts', 'performanceActs', 'payments']);
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
        // Получаем контракт с загруженными связями
        $contract = $this->getContractById($contractId, $organizationId);
        
        if (!$contract) {
            throw new Exception('Contract not found or does not belong to organization.');
        }

        // Загружаем все связанные данные одним запросом
        $contract->load([
            'contractor:id,name,legal_address,inn,kpp,phone,email',
            'project:id,name,address,description',
            'parentContract:id,number,total_amount,status',
            'childContracts:id,number,total_amount,status',
            'performanceActs:id,act_document_number,act_date,amount,description,is_approved,approval_date',
            'payments:id,payment_date,amount,payment_type,reference_document_number,description'
        ]);

        // Получаем аналитические данные
        $analytics = $this->buildContractAnalytics($contract);
        
        // Получаем статистику по работам
        $worksStatistics = $this->contractRepository->getContractWorksStatistics($contractId);
        
        // Получаем последние работы
        $recentWorks = $this->contractRepository->getRecentCompletedWorks($contractId, 10);
        
        return [
            'contract' => $contract,
            'analytics' => $analytics,
            'works_statistics' => $worksStatistics,
            'recent_works' => $this->formatRecentWorks($recentWorks),
            'performance_acts' => $this->formatPerformanceActs($contract->performanceActs),
            'payments' => $this->formatPayments($contract->payments),
            'child_contracts' => $this->formatChildContracts($contract->childContracts),
        ];
    }

    /**
     * Построить аналитические данные контракта
     */
    private function buildContractAnalytics(Contract $contract): array
    {
        return [
            'financial' => [
                'total_amount' => (float) $contract->total_amount,
                'completed_works_amount' => $contract->completed_works_amount,
                'remaining_amount' => $contract->remaining_amount,
                'completion_percentage' => $contract->completion_percentage,
                'total_paid_amount' => $contract->total_paid_amount,
                'total_performed_amount' => $contract->total_performed_amount,
                'gp_amount' => (float) $contract->gp_amount,
                'planned_advance_amount' => (float) $contract->planned_advance_amount,
            ],
            'status' => [
                'current_status' => $contract->status->value,
                'is_nearing_limit' => $contract->isNearingLimit(),
                'can_add_work' => $contract->canAddWork(0),
                'is_overdue' => $contract->end_date && $contract->end_date->isPast(),
                'days_until_deadline' => $contract->end_date ? now()->diffInDays($contract->end_date, false) : null,
            ],
            'counts' => [
                'total_works' => $contract->completedWorks()->count(),
                'confirmed_works' => $contract->completedWorks()->where('status', 'confirmed')->count(),
                'pending_works' => $contract->completedWorks()->where('status', 'pending')->count(),
                'performance_acts' => $contract->performanceActs()->count(),
                'approved_acts' => $contract->performanceActs()->where('is_approved', true)->count(),
                'payments_count' => $contract->payments()->count(),
                'child_contracts' => $contract->childContracts()->count(),
            ]
        ];
    }

    /**
     * Форматировать последние работы
     */
    private function formatRecentWorks($works): array
    {
        return $works->map(function ($work) {
            return [
                'id' => $work->id,
                'work_type_name' => $work->work_type_name,
                'user_name' => $work->user_name,
                'quantity' => (float) $work->quantity,
                'total_amount' => (float) $work->total_amount,
                'status' => $work->status,
                'completion_date' => $work->completion_date,
                'materials_count' => $work->materials_count,
                'materials_amount' => (float) $work->materials_amount,
            ];
        })->toArray();
    }

    /**
     * Форматировать акты выполненных работ
     */
    private function formatPerformanceActs($acts): array
    {
        return $acts->map(function ($act) {
            return [
                'id' => $act->id,
                'act_document_number' => $act->act_document_number,
                'act_date' => $act->act_date->format('Y-m-d'),
                'amount' => (float) $act->amount,
                'description' => $act->description,
                'is_approved' => $act->is_approved,
                'approval_date' => $act->approval_date?->format('Y-m-d'),
            ];
        })->toArray();
    }

    /**
     * Форматировать платежи
     */
    private function formatPayments($payments): array
    {
        return $payments->map(function ($payment) {
            return [
                'id' => $payment->id,
                'payment_date' => $payment->payment_date->format('Y-m-d'),
                'amount' => (float) $payment->amount,
                'payment_type' => $payment->payment_type,
                'reference_document_number' => $payment->reference_document_number,
                'description' => $payment->description,
            ];
        })->toArray();
    }

    /**
     * Форматировать дочерние контракты
     */
    private function formatChildContracts($childContracts): array
    {
        return $childContracts->map(function ($child) {
            return [
                'id' => $child->id,
                'number' => $child->number,
                'total_amount' => (float) $child->total_amount,
                'status' => $child->status->value,
                'completion_percentage' => $child->completion_percentage,
            ];
        })->toArray();
    }
} 