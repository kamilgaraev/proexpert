<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Analysis;

use App\Models\Project;
use App\Models\Contract;
use App\Models\CompletedWork;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CollectBudgetDataAction
{
    /**
     * Собрать данные по бюджету проекта
     *
     * @param int $projectId
     * @param int $organizationId
     * @return array
     */
    public function execute(int $projectId, int $organizationId): array
    {
        $project = Project::with(['contracts', 'completedWorks'])
            ->where('id', $projectId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        // Базовые данные бюджета
        $plannedBudget = (float) $project->budget_amount;
        
        // Рассчитываем потраченные средства
        $spentAmount = $this->calculateSpentAmount($project);
        
        // Контракты
        $contractsData = $this->collectContractsData($project);
        
        // Расходы по категориям
        $expensesByCategory = $this->collectExpensesByCategory($project);
        
        // Выполненные работы
        $completedWorksAmount = $project->completedWorks()
            ->sum('total_amount');
        
        // Расчет показателей
        $percentage = $plannedBudget > 0 ? round(($spentAmount / $plannedBudget) * 100, 2) : 0;
        $remaining = $plannedBudget - $spentAmount;
        
        // Временные показатели
        $now = now();
        $totalDays = $project->start_date && $project->end_date 
            ? $project->start_date->diffInDays($project->end_date) 
            : 0;
        $elapsedDays = $project->start_date 
            ? $project->start_date->diffInDays($now) 
            : 0;
        $remainingDays = $project->end_date 
            ? $now->diffInDays($project->end_date) 
            : 0;
        
        $timePercentage = $totalDays > 0 ? round(($elapsedDays / $totalDays) * 100, 2) : 0;
        
        // Процент выполнения работ (на основе выполненных работ vs контрактов)
        $totalContracted = $contractsData['total_amount'];
        $completionPercentage = $totalContracted > 0 
            ? round(($completedWorksAmount / $totalContracted) * 100, 2) 
            : 0;

        return [
            'project_name' => $project->name,
            'planned_budget' => $plannedBudget,
            'spent_amount' => $spentAmount,
            'remaining_budget' => $remaining,
            'percentage_spent' => $percentage,
            'completed_works_amount' => $completedWorksAmount,
            'completion_percentage' => $completionPercentage,
            'time_data' => [
                'total_days' => $totalDays,
                'elapsed_days' => $elapsedDays,
                'remaining_days' => $remainingDays,
                'time_percentage' => $timePercentage,
                'start_date' => $project->start_date?->format('Y-m-d'),
                'end_date' => $project->end_date?->format('Y-m-d'),
            ],
            'contracts' => $contractsData,
            'expenses_by_category' => $expensesByCategory,
            'budget_health' => $this->assessBudgetHealth($percentage, $timePercentage, $completionPercentage),
        ];
    }

    /**
     * Рассчитать потраченные средства
     */
    private function calculateSpentAmount(Project $project): float
    {
        // Сумма выполненных работ
        $completedWorksTotal = (float) $project->completedWorks()->sum('total_amount');
        
        // Проверяем существование таблицы contract_payments
        if ($this->tableExists('contract_payments')) {
            try {
                $contractsPaid = (float) $project->contracts()
                    ->join('contract_payments', 'contracts.id', '=', 'contract_payments.contract_id')
                    ->where('contract_payments.status', 'paid')
                    ->sum('contract_payments.amount');
            } catch (\Exception $e) {
                $contractsPaid = 0;
            }
        } else {
            // Если таблица не существует, используем сумму контрактов
            $contractsPaid = (float) $project->contracts()->sum('total_amount');
        }

        // Берем максимум из двух методов расчета
        return max($completedWorksTotal, $contractsPaid);
    }

    /**
     * Собрать данные по контрактам
     */
    private function collectContractsData(Project $project): array
    {
        try {
            $contracts = $project->contracts()
                ->with(['contractor'])
                ->get();
        } catch (\Exception $e) {
            $contracts = collect([]);
        }

        $contractsArray = [];
        $totalAmount = 0;
        $totalPaid = 0;
        $totalActed = 0;

        $hasPaymentsTable = $this->tableExists('contract_payments');
        $hasActsTable = $this->tableExists('contract_performance_acts');

        foreach ($contracts as $contract) {
            $paid = 0;
            $acted = 0;

            // Получаем данные по платежам только если таблица существует
            if ($hasPaymentsTable) {
                try {
                    $paid = DB::table('contract_payments')
                        ->where('contract_id', $contract->id)
                        ->where('status', 'paid')
                        ->sum('amount');
                } catch (\Exception $e) {
                    $paid = 0;
                }
            }
                
            // Получаем данные по актам только если таблица существует
            if ($hasActsTable) {
                try {
                    $acted = DB::table('contract_performance_acts')
                        ->where('contract_id', $contract->id)
                        ->where('status', 'approved')
                        ->sum('amount');
                } catch (\Exception $e) {
                    $acted = 0;
                }
            }

            $contractsArray[] = [
                'id' => $contract->id,
                'number' => $contract->contract_number ?? 'N/A',
                'contractor' => $contract->contractor->name ?? 'N/A',
                'total_amount' => (float) $contract->total_amount,
                'paid' => (float) $paid,
                'acted' => (float) $acted,
                'status' => $contract->status ?? 'unknown',
            ];

            $totalAmount += (float) $contract->total_amount;
            $totalPaid += (float) $paid;
            $totalActed += (float) $acted;
        }

        return [
            'contracts' => $contractsArray,
            'count' => count($contractsArray),
            'total_amount' => $totalAmount,
            'total_paid' => $totalPaid,
            'total_acted' => $totalActed,
            'remaining_to_pay' => $totalAmount - $totalPaid,
        ];
    }

    /**
     * Собрать расходы по категориям
     */
    private function collectExpensesByCategory(Project $project): array
    {
        if (!$this->tableExists('completed_works') || !$this->tableExists('work_types')) {
            return [];
        }

        try {
            $expenses = DB::table('completed_works')
                ->select('work_type_id', DB::raw('SUM(total_amount) as total'))
                ->where('project_id', $project->id)
                ->groupBy('work_type_id')
                ->get();

            $categoriesArray = [];
            
            foreach ($expenses as $expense) {
                try {
                    $workType = DB::table('work_types')->find($expense->work_type_id);
                    
                    $categoriesArray[] = [
                        'category' => $workType->name ?? 'Прочее',
                        'amount' => (float) $expense->total,
                    ];
                } catch (\Exception $e) {
                    $categoriesArray[] = [
                        'category' => 'Прочее',
                        'amount' => (float) $expense->total,
                    ];
                }
            }

            return $categoriesArray;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Оценить здоровье бюджета
     */
    private function assessBudgetHealth(float $budgetPercentage, float $timePercentage, float $completionPercentage): string
    {
        // Если потрачено значительно больше, чем прошло времени - проблема
        if ($budgetPercentage > $timePercentage + 15) {
            return 'critical';
        }
        
        if ($budgetPercentage > $timePercentage + 10) {
            return 'warning';
        }
        
        // Если выполнено мало при большом времени - проблема
        if ($timePercentage > 50 && $completionPercentage < 30) {
            return 'warning';
        }
        
        return 'good';
    }

    /**
     * Проверить существование таблицы в БД
     */
    private function tableExists(string $tableName): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasTable($tableName);
        } catch (\Exception $e) {
            return false;
        }
    }
}

