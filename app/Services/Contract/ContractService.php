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
    
    // TODO: Добавить методы для работы с Актами выполнения и Платежами через их сервисы или репозитории
    // Например, calculateContractSummary(int $contractId, int $organizationId): array
    // который вернет общую сумму, сумму выполненного, сумму оплаченного, остаток и т.д.
} 