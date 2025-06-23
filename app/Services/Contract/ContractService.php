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
        // Получаем контракт напрямую с необходимыми связями
        $contract = $this->contractRepository->find($contractId);
        
        if (!$contract || $contract->organization_id !== $organizationId) {
            throw new Exception('Contract not found or does not belong to organization.');
        }

        // Получаем аналитические данные
        $analytics = $this->buildContractAnalytics($contract);
        
        // Получаем статистику по работам
        $worksStatistics = $this->contractRepository->getContractWorksStatistics($contractId);
        
        // Получаем все работы по контракту
        $recentWorks = $this->contractRepository->getAllCompletedWorks($contractId);

        // Принудительно обновляем модель и загружаем связи
        $contract = $this->contractRepository->find($contractId);
        $contract->load([
            'contractor:id,name,legal_address,inn,kpp,phone,email',
            'project:id,name,address,description',
            'parentContract:id,number,total_amount,status',
            'childContracts:id,number,total_amount,status',
            'performanceActs:id,act_document_number,act_date,amount,description,is_approved,approval_date',
            'payments:id,payment_date,amount,payment_type,reference_document_number,description'
        ]);

        // Debug: проверяем что загрузилось
        Log::info('Contract relations loaded:', [
            'contract_id' => $contract->id,
            'performanceActs_count' => $contract->performanceActs->count(),
            'payments_count' => $contract->payments->count(),
            'childContracts_count' => $contract->childContracts->count(),
            'performanceActs_ids' => $contract->performanceActs->pluck('id')->toArray(),
            'payments_ids' => $contract->payments->pluck('id')->toArray(),
        ]);
        
        return [
            'contract' => $contract,
            'analytics' => $analytics,
            'works_statistics' => $worksStatistics,
            'recent_works' => $this->formatRecentWorks($recentWorks),
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
                'gp_percentage' => (float) $contract->gp_percentage,
                'gp_amount' => (float) $contract->gp_amount,
                'total_amount_with_gp' => (float) $contract->total_amount_with_gp,
                'completed_works_amount' => $contract->completed_works_amount,
                'remaining_amount' => $contract->remaining_amount,
                'completion_percentage' => $contract->completion_percentage,
                'total_paid_amount' => $contract->total_paid_amount,
                'total_performed_amount' => $contract->total_performed_amount,
                'planned_advance_amount' => (float) $contract->planned_advance_amount,
                'actual_advance_amount' => (float) $contract->actual_advance_amount,
                'remaining_advance_amount' => (float) $contract->remaining_advance_amount,
                'advance_payment_percentage' => (float) $contract->advance_payment_percentage,
                'is_advance_fully_paid' => $contract->is_advance_fully_paid,
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
     * Форматировать все работы по контракту
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