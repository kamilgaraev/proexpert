<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\Contract;
use App\Models\ContractProjectAllocation;
use App\Enums\Contract\ContractAllocationTypeEnum;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Создает автоматические распределения для всех существующих мультипроектных контрактов
     */
    public function up(): void
    {
        // Получаем все мультипроектные контракты
        $multiProjectContracts = Contract::where('is_multi_project', true)
            ->with('projects')
            ->get();

        foreach ($multiProjectContracts as $contract) {
            $projects = $contract->projects;
            
            if ($projects->isEmpty()) {
                continue;
            }

            // Проверяем, есть ли уже распределения для этого контракта
            $existingAllocations = ContractProjectAllocation::where('contract_id', $contract->id)
                ->where('is_active', true)
                ->count();

            if ($existingAllocations > 0) {
                // Если распределения уже есть, пропускаем
                continue;
            }

            // Определяем способ распределения
            $totalActsAmount = DB::table('contract_performance_acts')
                ->where('contract_id', $contract->id)
                ->where('is_approved', true)
                ->sum('amount');

            if ($totalActsAmount > 0) {
                // Если есть акты, создаем распределение на основе актов
                $this->createActBasedAllocation($contract, $projects, $totalActsAmount);
            } else {
                // Если актов нет, создаем равномерное распределение
                $this->createEqualAllocation($contract, $projects);
            }
        }
    }

    /**
     * Создать распределение на основе актов
     */
    protected function createActBasedAllocation($contract, $projects, float $totalActsAmount): void
    {
        foreach ($projects as $project) {
            $projectActsAmount = DB::table('contract_performance_acts')
                ->where('contract_id', $contract->id)
                ->where('project_id', $project->id)
                ->where('is_approved', true)
                ->sum('amount');

            if ($projectActsAmount > 0) {
                $percentage = ($projectActsAmount / $totalActsAmount) * 100;

                ContractProjectAllocation::create([
                    'contract_id' => $contract->id,
                    'project_id' => $project->id,
                    'allocation_type' => ContractAllocationTypeEnum::PERCENTAGE->value,
                    'allocated_percentage' => round($percentage, 2),
                    'notes' => 'Автоматически создано при миграции на основе актов',
                    'is_active' => true,
                ]);
            } else {
                // Если для проекта нет актов, но проект привязан к контракту
                // создаем AUTO распределение
                ContractProjectAllocation::create([
                    'contract_id' => $contract->id,
                    'project_id' => $project->id,
                    'allocation_type' => ContractAllocationTypeEnum::AUTO->value,
                    'notes' => 'Автоматически создано при миграции (без актов)',
                    'is_active' => true,
                ]);
            }
        }
    }

    /**
     * Создать равномерное распределение
     */
    protected function createEqualAllocation($contract, $projects): void
    {
        foreach ($projects as $project) {
            ContractProjectAllocation::create([
                'contract_id' => $contract->id,
                'project_id' => $project->id,
                'allocation_type' => ContractAllocationTypeEnum::AUTO->value,
                'notes' => 'Автоматически создано при миграции (равномерное распределение)',
                'is_active' => true,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     * 
     * Удаляет только автоматически созданные распределения при миграции
     */
    public function down(): void
    {
        // Удаляем распределения, созданные этой миграцией
        ContractProjectAllocation::where('notes', 'LIKE', 'Автоматически создано при миграции%')
            ->delete();
    }
};

