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

        $act = $this->actRepository->create($actData);

        // Синхронизируем выполненные работы если они переданы
        if (!empty($actDTO->completed_works)) {
            $this->syncCompletedWorks($act, $actDTO->getCompletedWorksForSync());
            // Пересчитываем сумму акта на основе включенных работ
            $act->recalculateAmount();
        }

        return $act;
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

        $act = $this->actRepository->find($actId);

        // Синхронизируем выполненные работы если они переданы
        if (!empty($actDTO->completed_works)) {
            $this->syncCompletedWorks($act, $actDTO->getCompletedWorksForSync());
            // Пересчитываем сумму акта на основе включенных работ
            $act->recalculateAmount();
        }

        return $act;
    }

    /**
     * Синхронизировать выполненные работы с актом
     */
    protected function syncCompletedWorks(ContractPerformanceAct $act, array $completedWorksData): void
    {
        // Проверяем что все работы принадлежат тому же контракту
        $workIds = array_keys($completedWorksData);
        $validWorks = \App\Models\CompletedWork::whereIn('id', $workIds)
            ->where('contract_id', $act->contract_id)
            ->where('status', 'confirmed') // Только подтвержденные работы можно включать в акты
            ->pluck('id')
            ->toArray();

        // Фильтруем только валидные работы
        $filteredData = array_intersect_key($completedWorksData, array_flip($validWorks));

        // Синхронизируем связи
        $act->completedWorks()->sync($filteredData);
    }

    /**
     * Получить доступные для включения в акт работы по контракту
     */
    public function getAvailableWorksForAct(int $contractId, int $organizationId): array
    {
        $this->getContractOrFail($contractId, $organizationId);
        
        // Получаем подтвержденные работы которые еще не включены в утвержденные акты
        $works = \App\Models\CompletedWork::where('contract_id', $contractId)
            ->where('status', 'confirmed')
            ->with(['workType:id,name', 'user:id,name'])
            ->get();

        return $works->map(function ($work) {
            return [
                'id' => $work->id,
                'work_type_name' => $work->workType->name ?? 'Не указано',
                'user_name' => $work->user->name ?? 'Не указано',
                'quantity' => (float) $work->quantity,
                'price' => (float) $work->price,
                'total_amount' => (float) $work->total_amount,
                'completion_date' => $work->completion_date,
                'is_included_in_approved_act' => $this->isWorkIncludedInApprovedAct($work->id),
            ];
        })->toArray();
    }

    /**
     * Проверить включена ли работа в утвержденный акт
     */
    protected function isWorkIncludedInApprovedAct(int $workId): bool
    {
        return \App\Models\PerformanceActCompletedWork::whereHas('performanceAct', function($query) {
            $query->where('is_approved', true);
        })->where('completed_work_id', $workId)->exists();
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