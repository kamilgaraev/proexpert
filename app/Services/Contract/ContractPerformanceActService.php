<?php

namespace App\Services\Contract;

use App\Repositories\Interfaces\ContractPerformanceActRepositoryInterface;
use App\Repositories\Interfaces\ContractRepositoryInterface;
use App\DTOs\Contract\ContractPerformanceActDTO;
use App\Models\ContractPerformanceAct;
use App\Models\Contract;
use Illuminate\Support\Collection;
use Exception;

class ContractPerformanceActService
{
    protected ContractPerformanceActRepositoryInterface $actRepository;
    protected ContractRepositoryInterface $contractRepository; // Для проверки существования контракта

    public function __construct(
        ContractPerformanceActRepositoryInterface $actRepository,
        ContractRepositoryInterface $contractRepository
    ) {
        $this->actRepository = $actRepository;
        $this->contractRepository = $contractRepository;
    }

    protected function getContractOrFail(int $contractId, int $organizationId): Contract
    {
        $contract = $this->contractRepository->find($contractId);
        if (!$contract || $contract->organization_id !== $organizationId) {
            throw new Exception('Contract not found or does not belong to the organization.');
        }
        return $contract;
    }

    public function getAllActsForContract(int $contractId, int $organizationId, array $filters = []): Collection
    {
        $this->getContractOrFail($contractId, $organizationId); // Проверка, что контракт существует и принадлежит организации
        return $this->actRepository->getActsForContract($contractId, $filters);
    }

    public function createActForContract(int $contractId, int $organizationId, ContractPerformanceActDTO $actDTO): ContractPerformanceAct
    {
        $contract = $this->getContractOrFail($contractId, $organizationId);
        
        $actData = $actDTO->toArray();
        $actData['contract_id'] = $contract->id;

        return $this->actRepository->create($actData);
    }

    public function getActById(int $actId, int $contractId, int $organizationId): ?ContractPerformanceAct
    {
        $this->getContractOrFail($contractId, $organizationId);
        $act = $this->actRepository->find($actId);
        // Убедимся, что акт принадлежит указанному контракту
        if ($act && $act->contract_id === $contractId) {
            return $act;
        }
        return null;
    }

    public function updateAct(int $actId, int $contractId, int $organizationId, ContractPerformanceActDTO $actDTO): ContractPerformanceAct
    {
        $this->getContractOrFail($contractId, $organizationId);
        $act = $this->actRepository->find($actId);

        if (!$act || $act->contract_id !== $contractId) {
            throw new Exception('Performance act not found or does not belong to the specified contract.');
        }

        $updateData = $actDTO->toArray();
        $updated = $this->actRepository->update($actId, $updateData);

        if (!$updated) {
            throw new Exception('Failed to update performance act.');
        }
        return $this->actRepository->find($actId); // Возвращаем свежую модель
    }

    public function deleteAct(int $actId, int $contractId, int $organizationId): bool
    {
        $this->getContractOrFail($contractId, $organizationId);
        $act = $this->actRepository->find($actId);

        if (!$act || $act->contract_id !== $contractId) {
            throw new Exception('Performance act not found or does not belong to the specified contract.');
        }
        return $this->actRepository->delete($actId);
    }
    
    public function getTotalPerformedAmountForContract(int $contractId, int $organizationId): float
    {
        $this->getContractOrFail($contractId, $organizationId);
        return $this->actRepository->getTotalAmountForContract($contractId);
    }
} 