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

            // Деактивируем все существующие активные распределения
            ContractProjectAllocation::where('contract_id', $contract->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

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
     */
    protected function createOrUpdateAllocation(Contract $contract, array $data): ContractProjectAllocation
    {
        $allocation = ContractProjectAllocation::updateOrCreate(
            [
                'contract_id' => $contract->id,
                'project_id' => $data['project_id'],
                'is_active' => true,
            ],
            [
                'allocation_type' => $data['allocation_type'] ?? ContractAllocationTypeEnum::AUTO->value,
                'allocated_amount' => $data['allocated_amount'] ?? null,
                'allocated_percentage' => $data['allocated_percentage'] ?? null,
                'custom_formula' => $data['custom_formula'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]
        );

        return $allocation;
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

        $allocationsData = $projects->map(function ($project) use ($actsByProject, $totalActs, $totalContractAmount) {
            $projectActs = $actsByProject->get($project->id, 0);
            $percentage = ($projectActs / $totalActs) * 100;

            return [
                'project_id' => $project->id,
                'allocation_type' => ContractAllocationTypeEnum::PERCENTAGE->value,
                'allocated_percentage' => round($percentage, 2),
                'notes' => 'Распределение на основе выполненных работ (актов)',
            ];
        })->toArray();

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

