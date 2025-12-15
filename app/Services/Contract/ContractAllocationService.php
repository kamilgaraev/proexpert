<?php

namespace App\Services\Contract;

use App\Models\Contract;
use App\Models\ContractProjectAllocation;
use App\Models\Project;
use App\Enums\Contract\ContractAllocationTypeEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Collection;

class ContractAllocationService
{
    /**
     * Создать или обновить распределение контракта по проектам
     * 
     * @param Contract $contract
     * @param array $allocationsData - массив с данными распределений
     * [
     *   ['project_id' => 1, 'allocation_type' => 'fixed', 'allocated_amount' => 1000000],
     *   ['project_id' => 2, 'allocation_type' => 'percentage', 'allocated_percentage' => 50]
     * ]
     * @return Collection
     */
    public function syncAllocations(Contract $contract, array $allocationsData): Collection
    {
        return DB::transaction(function () use ($contract, $allocationsData) {
            $allocations = collect();

            // Получаем ID проектов из новых данных
            $newProjectIds = collect($allocationsData)->pluck('project_id')->toArray();

            // Деактивируем старые распределения, которых НЕТ в новых данных
            // Это предотвращает конфликт при обновлении существующих
            ContractProjectAllocation::where('contract_id', $contract->id)
                ->where('is_active', true)
                ->whereNotIn('project_id', $newProjectIds)
                ->update(['is_active' => false, 'updated_by' => Auth::id()]);

            foreach ($allocationsData as $allocationData) {
                $allocation = $this->createOrUpdateAllocation($contract, $allocationData);
                $allocations->push($allocation);
            }

            // Валидируем все распределения
            $this->validateTotalAllocations($contract, $allocations);

            return $allocations;
        });
    }

    /**
     * Создать или обновить одно распределение
     * 
     * Стратегия:
     * 1. Ищем активное распределение для этой пары contract-project
     * 2. Если найдено - обновляем его
     * 3. Если не найдено - создаем новое активное
     */
    protected function createOrUpdateAllocation(Contract $contract, array $data): ContractProjectAllocation
    {
        // Сначала пытаемся найти существующее активное распределение
        $existingAllocation = ContractProjectAllocation::where('contract_id', $contract->id)
            ->where('project_id', $data['project_id'])
            ->where('is_active', true)
            ->first();

        $allocationData = [
            'allocation_type' => $data['allocation_type'] ?? ContractAllocationTypeEnum::AUTO->value,
            'allocated_amount' => $data['allocated_amount'] ?? null,
            'allocated_percentage' => $data['allocated_percentage'] ?? null,
            'custom_formula' => $data['custom_formula'] ?? null,
            'notes' => $data['notes'] ?? null,
            'updated_by' => Auth::id(),
        ];

        if ($existingAllocation) {
            // Обновляем существующее
            $existingAllocation->update($allocationData);
            return $existingAllocation->fresh();
        } else {
            // Создаем новое активное распределение
            $allocationData['contract_id'] = $contract->id;
            $allocationData['project_id'] = $data['project_id'];
            $allocationData['is_active'] = true;
            $allocationData['created_by'] = Auth::id();
            
            return ContractProjectAllocation::create($allocationData);
        }
    }

    /**
     * Создать автоматическое равномерное распределение для контракта
     */
    public function createAutoEqualDistribution(Contract $contract): Collection
    {
        if (!$contract->is_multi_project) {
            return collect();
        }

        $projects = $contract->projects;
        $projectsCount = $projects->count();

        if ($projectsCount === 0) {
            return collect();
        }

        $allocationsData = $projects->map(function ($project) use ($projectsCount) {
            return [
                'project_id' => $project->id,
                'allocation_type' => ContractAllocationTypeEnum::AUTO->value,
                'notes' => 'Автоматическое равномерное распределение',
            ];
        })->toArray();

        return $this->syncAllocations($contract, $allocationsData);
    }

    /**
     * Создать распределение на основе актов
     */
    public function createDistributionBasedOnActs(Contract $contract): Collection
    {
        if (!$contract->is_multi_project) {
            return collect();
        }

        $projects = $contract->projects;
        $totalContractAmount = (float) $contract->total_amount;

        // Получаем суммы актов по каждому проекту
        $actsByProject = DB::table('contract_performance_acts')
            ->where('contract_id', $contract->id)
            ->where('is_approved', true)
            ->select('project_id', DB::raw('SUM(amount) as total'))
            ->groupBy('project_id')
            ->pluck('total', 'project_id');

        $totalActs = $actsByProject->sum();

        // Если актов нет, создаем равномерное распределение
        if ($totalActs == 0) {
            return $this->createAutoEqualDistribution($contract);
        }

        // ИСПРАВЛЕНИЕ: Используем FIXED суммы вместо процентов для точности
        $allocationsData = [];
        $allocatedTotal = 0;
        $projectsList = $projects->values();
        
        foreach ($projectsList as $index => $project) {
            $projectActs = $actsByProject->get($project->id, 0);
            
            // Для последнего проекта - считаем как остаток (избегаем погрешности округления)
            if ($index === $projectsList->count() - 1) {
                $allocatedAmount = $totalContractAmount - $allocatedTotal;
            } else {
                // Для остальных - пропорционально актам
                $allocatedAmount = round(($projectActs / $totalActs) * $totalContractAmount, 2);
                $allocatedTotal += $allocatedAmount;
            }

            $allocationsData[] = [
                'project_id' => $project->id,
                'allocation_type' => ContractAllocationTypeEnum::FIXED->value,
                'allocated_amount' => $allocatedAmount,
                'notes' => sprintf(
                    'Распределение на основе актов (%.2f млн из %.2f млн)', 
                    $projectActs / 1000000, 
                    $totalActs / 1000000
                ),
            ];
        }

        return $this->syncAllocations($contract, $allocationsData);
    }

    /**
     * Валидация общей суммы распределений
     */
    protected function validateTotalAllocations(Contract $contract, Collection $allocations): void
    {
        $totalAllocated = $allocations->sum(function ($allocation) {
            return $allocation->calculateAllocatedAmount();
        });

        $contractTotal = (float) $contract->total_amount;
        $tolerance = 0.01; // Допустимая погрешность в 1 копейку

        // Для фиксированных и процентных распределений проверяем, что сумма не превышает контракт
        $hasFixedOrPercentage = $allocations->contains(function ($allocation) {
            return in_array($allocation->allocation_type, [
                ContractAllocationTypeEnum::FIXED,
                ContractAllocationTypeEnum::PERCENTAGE
            ]);
        });

        if ($hasFixedOrPercentage && ($totalAllocated > $contractTotal + $tolerance)) {
            throw new \Exception(
                "Общая сумма распределений ({$totalAllocated}) превышает сумму контракта ({$contractTotal})"
            );
        }
    }

    /**
     * Получить информацию о распределении контракта
     */
    public function getAllocationSummary(Contract $contract): array
    {
        $allocations = $contract->activeAllocations()->with('project')->get();
        $contractTotal = (float) $contract->total_amount;

        $allocated = $allocations->sum(function ($allocation) {
            return $allocation->calculateAllocatedAmount();
        });

        $unallocated = max(0, $contractTotal - $allocated);

        return [
            'contract_id' => $contract->id,
            'contract_total' => $contractTotal,
            'total_allocated' => $allocated,
            'unallocated' => $unallocated,
            'allocation_percentage' => $contractTotal > 0 ? ($allocated / $contractTotal) * 100 : 0,
            'allocations' => $allocations->map(function ($allocation) {
                return [
                    'id' => $allocation->id,
                    'project_id' => $allocation->project_id,
                    'project_name' => $allocation->project->name ?? 'N/A',
                    'allocation_type' => $allocation->allocation_type->value,
                    'allocation_type_label' => $allocation->allocation_type->label(),
                    'allocated_amount' => $allocation->calculateAllocatedAmount(),
                    'allocated_percentage' => $allocation->allocated_percentage,
                    'notes' => $allocation->notes,
                    'created_at' => $allocation->created_at,
                    'updated_at' => $allocation->updated_at,
                ];
            })->values(),
        ];
    }

    /**
     * Удалить распределение
     */
    public function deleteAllocation(int $allocationId): bool
    {
        $allocation = ContractProjectAllocation::findOrFail($allocationId);
        return $allocation->delete();
    }

    /**
     * Получить историю изменений распределения
     */
    public function getAllocationHistory(int $allocationId): Collection
    {
        return ContractAllocationHistory::where('allocation_id', $allocationId)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Пересчитать все автоматические распределения для контракта
     */
    public function recalculateAutoAllocations(Contract $contract): Collection
    {
        $autoAllocations = $contract->activeAllocations()
            ->where('allocation_type', ContractAllocationTypeEnum::AUTO->value)
            ->get();

        foreach ($autoAllocations as $allocation) {
            // Пересчет произойдет автоматически при следующем вызове calculateAllocatedAmount()
            // Но мы можем принудительно обновить timestamp для отслеживания
            $allocation->touch();
        }

        return $autoAllocations;
    }

    /**
     * Конвертировать автоматическое распределение в фиксированное
     * Полезно для "заморозки" текущего состояния
     */
    public function convertAutoToFixed(int $allocationId): ContractProjectAllocation
    {
        return DB::transaction(function () use ($allocationId) {
            $allocation = ContractProjectAllocation::findOrFail($allocationId);

            if ($allocation->allocation_type !== ContractAllocationTypeEnum::AUTO) {
                throw new \Exception('Можно конвертировать только автоматические распределения');
            }

            $calculatedAmount = $allocation->calculateAllocatedAmount();

            $allocation->update([
                'allocation_type' => ContractAllocationTypeEnum::FIXED->value,
                'allocated_amount' => $calculatedAmount,
                'notes' => ($allocation->notes ?? '') . ' [Конвертировано из автоматического]',
            ]);

            return $allocation->fresh();
        });
    }
}

