<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Analysis;

use App\Models\Project;
use App\Models\Contract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CollectContractsDataAction
{
    /**
     * Собрать данные по контрактам проекта
     *
     * @param int $projectId
     * @param int $organizationId
     * @return array
     */
    public function execute(int $projectId, int $organizationId): array
    {
        $project = Project::where('id', $projectId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $contracts = $project->contracts()
            ->with(['contractor'])
            ->get();

        $contractsAnalysis = [];
        $totalAmount = 0;
        $totalPaid = 0;
        $totalActed = 0;
        $problemContracts = [];

        // Проверяем существование таблиц один раз
        $hasPaymentsTable = $this->tableExists('contract_payments');
        $hasActsTable = $this->tableExists('contract_performance_acts');

        foreach ($contracts as $contract) {
            // Безопасно получаем данные по платежам
            $paid = 0;
            $invoiced = 0;
            if ($hasPaymentsTable) {
                try {
                    $paid = (float) DB::table('contract_payments')
                        ->where('contract_id', $contract->id)
                        ->where('status', 'paid')
                        ->sum('amount');

                    $invoiced = (float) DB::table('contract_payments')
                        ->where('contract_id', $contract->id)
                        ->sum('amount');
                } catch (\Exception $e) {
                    $paid = 0;
                    $invoiced = 0;
                }
            }

            // Безопасно получаем данные по актам
            $acted = 0;
            if ($hasActsTable) {
                try {
                    $acted = (float) DB::table('contract_performance_acts')
                        ->where('contract_id', $contract->id)
                        ->where('status', 'approved')
                        ->sum('amount');
                } catch (\Exception $e) {
                    $acted = 0;
                }
            }

            $completionPercentage = $contract->total_amount > 0 
                ? round(($acted / $contract->total_amount) * 100, 2) 
                : 0;

            $paymentPercentage = $contract->total_amount > 0 
                ? round(($paid / $contract->total_amount) * 100, 2) 
                : 0;

            // Определяем проблемные контракты
            $isProblematic = $this->isContractProblematic($contract, $acted, $paid);
            
            $contractData = [
                'id' => $contract->id,
                'number' => $contract->contract_number,
                'contractor' => $contract->contractor->name ?? 'N/A',
                'contractor_inn' => $contract->contractor->inn ?? null,
                'total_amount' => (float) $contract->total_amount,
                'paid' => (float) $paid,
                'invoiced' => (float) $invoiced,
                'acted' => (float) $acted,
                'remaining_to_pay' => (float) ($contract->total_amount - $paid),
                'completion_percentage' => $completionPercentage,
                'payment_percentage' => $paymentPercentage,
                'status' => $contract->status,
                'start_date' => $contract->start_date?->format('Y-m-d'),
                'end_date' => $contract->end_date?->format('Y-m-d'),
                'is_problematic' => $isProblematic,
            ];

            if ($isProblematic) {
                $contractData['problems'] = $this->identifyContractProblems($contract, $acted, $paid);
                $problemContracts[] = $contractData;
            }

            $contractsAnalysis[] = $contractData;
            
            $totalAmount += (float) $contract->total_amount;
            $totalPaid += (float) $paid;
            $totalActed += (float) $acted;
        }

        return [
            'project_name' => $project->name,
            'contracts' => $contractsAnalysis,
            'summary' => [
                'total_contracts' => $contracts->count(),
                'total_amount' => $totalAmount,
                'total_paid' => $totalPaid,
                'total_acted' => $totalActed,
                'remaining_to_pay' => $totalAmount - $totalPaid,
                'overall_completion' => $totalAmount > 0 ? round(($totalActed / $totalAmount) * 100, 2) : 0,
                'overall_payment' => $totalAmount > 0 ? round(($totalPaid / $totalAmount) * 100, 2) : 0,
            ],
            'problem_contracts' => $problemContracts,
            'problem_contracts_count' => count($problemContracts),
            'contracts_health' => $this->assessContractsHealth($contracts->count(), count($problemContracts)),
        ];
    }

    /**
     * Проверить, является ли контракт проблемным
     */
    private function isContractProblematic(Contract $contract, float $acted, float $paid): bool
    {
        $now = now();
        
        // Просрочен
        if ($contract->end_date && $contract->end_date->isPast() && $contract->status !== 'completed') {
            return true;
        }

        // Низкий процент выполнения при значительном времени
        if ($contract->start_date && $contract->end_date) {
            $totalDays = $contract->start_date->diffInDays($contract->end_date);
            $elapsedDays = $contract->start_date->diffInDays($now);
            
            if ($totalDays > 0) {
                $timePercentage = ($elapsedDays / $totalDays) * 100;
                $completionPercentage = $contract->total_amount > 0 ? ($acted / $contract->total_amount) * 100 : 0;
                
                // Прошло более 50% времени, а выполнено менее 30%
                if ($timePercentage > 50 && $completionPercentage < 30) {
                    return true;
                }
            }
        }

        // Большая разница между выполненным и оплаченным
        if ($acted > 0 && $paid < $acted * 0.5) {
            return true;
        }

        return false;
    }

    /**
     * Идентифицировать проблемы контракта
     */
    private function identifyContractProblems(Contract $contract, float $acted, float $paid): array
    {
        $problems = [];
        $now = now();

        // Просрочка
        if ($contract->end_date && $contract->end_date->isPast() && $contract->status !== 'completed') {
            $daysOverdue = $now->diffInDays($contract->end_date);
            $problems[] = [
                'type' => 'overdue',
                'severity' => 'high',
                'description' => "Контракт просрочен на {$daysOverdue} дней",
            ];
        }

        // Низкий прогресс
        if ($contract->start_date && $contract->end_date && $contract->total_amount > 0) {
            $totalDays = $contract->start_date->diffInDays($contract->end_date);
            $elapsedDays = $contract->start_date->diffInDays($now);
            
            if ($totalDays > 0) {
                $timePercentage = ($elapsedDays / $totalDays) * 100;
                $completionPercentage = ($acted / $contract->total_amount) * 100;
                
                if ($timePercentage > 50 && $completionPercentage < 30) {
                    $problems[] = [
                        'type' => 'low_progress',
                        'severity' => 'medium',
                        'description' => sprintf(
                            "Прошло %.0f%% времени, выполнено только %.0f%% работ",
                            $timePercentage,
                            $completionPercentage
                        ),
                    ];
                }
            }
        }

        // Задолженность по оплате
        if ($acted > 0 && $paid < $acted * 0.5) {
            $debt = $acted - $paid;
            $problems[] = [
                'type' => 'payment_delay',
                'severity' => 'high',
                'description' => sprintf(
                    "Задолженность по оплате: %.2f руб (выполнено работ на %.2f, оплачено %.2f)",
                    $debt,
                    $acted,
                    $paid
                ),
            ];
        }

        return $problems;
    }

    /**
     * Оценить здоровье контрактов
     */
    private function assessContractsHealth(int $totalContracts, int $problemContracts): string
    {
        if ($totalContracts === 0) {
            return 'good';
        }

        $problemPercentage = ($problemContracts / $totalContracts) * 100;

        if ($problemPercentage > 50) {
            return 'critical';
        }

        if ($problemPercentage > 25) {
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
            return Schema::hasTable($tableName);
        } catch (\Exception $e) {
            return false;
        }
    }
}

