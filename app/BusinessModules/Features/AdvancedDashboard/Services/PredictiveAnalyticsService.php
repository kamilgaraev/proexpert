<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use App\Models\Contract;
use App\Models\Project;
use App\Models\CompletedWork;
use App\Models\Material;

/**
 * Сервис предиктивной аналитики
 * 
 * Предоставляет методы для:
 * - Прогноза завершения контрактов
 * - Выявления рисков превышения бюджета
 * - Прогноза потребности в материалах
 */
class PredictiveAnalyticsService
{
    protected int $cacheTTL = 300; // 5 минут

    /**
     * Спрогнозировать дату завершения контракта
     * 
     * @param int $contractId ID контракта
     * @return array
     */
    public function predictContractCompletion(int $contractId): array
    {
        $cacheKey = "contract_prediction_{$contractId}";
        
        return Cache::remember($cacheKey, $this->cacheTTL, function () use ($contractId) {
            $contract = Contract::find($contractId);
            
            if (!$contract) {
                throw new \Exception("Contract not found");
            }
            
            // Текущий прогресс
            $currentProgress = $contract->progress ?? 0;
            
            // История прогресса
            $progressHistory = $this->getProgressHistory($contractId);
            
            // Линейная регрессия для прогноза
            $forecast = $this->calculateLinearRegression($progressHistory);
            
            // Прогноз даты завершения
            $estimatedCompletionDate = $this->estimateCompletionDate(
                $currentProgress,
                $forecast['slope'],
                $forecast['intercept']
            );
            
            // Плановая дата завершения
            $plannedCompletionDate = $contract->end_date ? Carbon::parse($contract->end_date) : null;
            
            // Отклонение от плана
            $deviation = null;
            $risk = 'low';
            
            if ($plannedCompletionDate && $estimatedCompletionDate) {
                $deviation = $estimatedCompletionDate->diffInDays($plannedCompletionDate, false);
                $risk = $this->assessRisk($deviation);
            }
            
            return [
                'contract_id' => $contractId,
                'contract_name' => $contract->name ?? 'N/A',
                'current_progress' => $currentProgress,
                'planned_completion_date' => $plannedCompletionDate?->toIso8601String(),
                'estimated_completion_date' => $estimatedCompletionDate?->toIso8601String(),
                'deviation_days' => $deviation,
                'risk_level' => $risk,
                'confidence' => $this->calculateConfidence($progressHistory),
                'forecast_data' => $forecast,
                'progress_history' => $progressHistory,
            ];
        });
    }

    /**
     * Спрогнозировать риски превышения бюджета
     * 
     * @param int $projectId ID проекта
     * @return array
     */
    public function predictBudgetOverrun(int $projectId): array
    {
        $cacheKey = "budget_risk_{$projectId}";
        
        return Cache::remember($cacheKey, $this->cacheTTL, function () use ($projectId) {
            $project = Project::find($projectId);
            
            if (!$project) {
                throw new \Exception("Project not found");
            }
            
            // Общий бюджет проекта
            $totalBudget = Contract::where('project_id', $projectId)
                ->sum('total_amount');
            
            if ($totalBudget == 0) {
                return [
                    'project_id' => $projectId,
                    'risk_level' => 'unknown',
                    'message' => 'No budget data available',
                ];
            }
            
            // Текущие расходы
            $currentSpending = CompletedWork::where('project_id', $projectId)
                ->join('completed_work_materials', 'completed_works.id', '=', 'completed_work_materials.completed_work_id')
                ->sum('completed_work_materials.total_amount');
            
            // История расходов
            $spendingHistory = $this->getSpendingHistory($projectId);
            
            // Тренд расходов
            $forecast = $this->calculateLinearRegression($spendingHistory);
            
            // Прогноз итоговых расходов
            $estimatedTotalSpending = $this->estimateTotalSpending(
                $currentSpending,
                $forecast['slope'],
                $project
            );
            
            // Риск превышения
            $overrunAmount = $estimatedTotalSpending - $totalBudget;
            $overrunPercentage = ($overrunAmount / $totalBudget) * 100;
            $risk = $this->assessBudgetRisk($overrunPercentage);
            
            return [
                'project_id' => $projectId,
                'project_name' => $project->name,
                'total_budget' => $totalBudget,
                'current_spending' => $currentSpending,
                'spending_percentage' => ($currentSpending / $totalBudget) * 100,
                'estimated_total_spending' => $estimatedTotalSpending,
                'estimated_overrun_amount' => max(0, $overrunAmount),
                'estimated_overrun_percentage' => max(0, $overrunPercentage),
                'risk_level' => $risk,
                'confidence' => $this->calculateConfidence($spendingHistory),
                'spending_history' => $spendingHistory,
                'recommendations' => $this->generateBudgetRecommendations($risk, $overrunPercentage),
            ];
        });
    }

    /**
     * Спрогнозировать потребность в материалах
     * 
     * @param int $organizationId ID организации
     * @param int $months Количество месяцев для прогноза
     * @return array
     */
    public function predictMaterialNeeds(int $organizationId, int $months = 3): array
    {
        $cacheKey = "material_forecast_{$organizationId}_{$months}";
        
        return Cache::remember($cacheKey, $this->cacheTTL, function () use ($organizationId, $months) {
            // Получаем историю использования материалов за последние 6 месяцев
            $historicalPeriod = 6;
            $historicalData = $this->getMaterialUsageHistory($organizationId, $historicalPeriod);
            
            // Прогноз для каждого материала
            $forecasts = [];
            
            foreach ($historicalData as $materialId => $usage) {
                $forecast = $this->calculateLinearRegression($usage);
                $estimatedNeeds = $this->estimateFutureNeeds($usage, $forecast['slope'], $months);
                
                $material = Material::find($materialId);
                
                if ($material) {
                    $currentStock = $material->balance ?? 0;
                    $needsRestocking = $currentStock < $estimatedNeeds;
                    
                    $forecasts[] = [
                        'material_id' => $materialId,
                        'material_name' => $material->name,
                        'current_stock' => $currentStock,
                        'estimated_needs' => $estimatedNeeds,
                        'deficit' => max(0, $estimatedNeeds - $currentStock),
                        'needs_restocking' => $needsRestocking,
                        'usage_trend' => $forecast['slope'] > 0 ? 'increasing' : 'decreasing',
                    ];
                }
            }
            
            // Сортировка по дефициту (от большего к меньшему)
            usort($forecasts, fn($a, $b) => $b['deficit'] <=> $a['deficit']);
            
            return [
                'organization_id' => $organizationId,
                'forecast_months' => $months,
                'materials' => $forecasts,
                'critical_materials' => array_filter($forecasts, fn($m) => $m['needs_restocking']),
            ];
        });
    }

    /**
     * Получить прогноз по всем активным проектам организации
     * 
     * @param int $organizationId ID организации
     * @return array
     */
    public function getOrganizationForecast(int $organizationId): array
    {
        $cacheKey = "org_forecast_{$organizationId}";
        
        return Cache::remember($cacheKey, $this->cacheTTL, function () use ($organizationId) {
            $projects = Project::where('organization_id', $organizationId)
                ->whereHas('contracts', function ($query) {
                    $query->where('status', 'active');
                })
                ->get();
            
            $forecasts = [];
            $overallRisk = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
            
            foreach ($projects as $project) {
                try {
                    $budgetRisk = $this->predictBudgetOverrun($project->id);
                    $forecasts[] = $budgetRisk;
                    $overallRisk[$budgetRisk['risk_level']]++;
                } catch (\Exception $e) {
                    // Пропускаем проекты с ошибками
                    continue;
                }
            }
            
            return [
                'organization_id' => $organizationId,
                'total_projects' => count($forecasts),
                'risk_distribution' => $overallRisk,
                'projects' => $forecasts,
                'high_risk_projects' => array_filter($forecasts, fn($p) => in_array($p['risk_level'], ['high', 'critical'])),
            ];
        });
    }

    // ==================== PROTECTED HELPER METHODS ====================

    /**
     * Получить историю прогресса контракта
     */
    protected function getProgressHistory(int $contractId): array
    {
        $contract = Contract::find($contractId);
        
        if (!$contract) {
            return [];
        }
        
        $historyRecords = DB::table('contract_progress_history')
            ->where('contract_id', $contractId)
            ->orderBy('recorded_at')
            ->get();
        
        if ($historyRecords->count() > 0) {
            $history = [];
            foreach ($historyRecords as $record) {
                $history[] = [
                    'date' => Carbon::parse($record->recorded_at)->toIso8601String(),
                    'progress' => (float) $record->progress,
                ];
            }
            return $history;
        }
        
        $currentProgress = $contract->progress ?? 0;
        $startDate = $contract->start_date ? Carbon::parse($contract->start_date) : Carbon::now()->subMonths(3);
        $daysPassed = $startDate->diffInDays(Carbon::now());
        
        if ($daysPassed <= 0) {
            return [];
        }
        
        $history = [];
        for ($i = 0; $i <= $daysPassed; $i += 7) {
            $progress = ($currentProgress / $daysPassed) * $i;
            $date = $startDate->copy()->addDays($i);
            
            $history[] = [
                'date' => $date->toIso8601String(),
                'progress' => min(100, round($progress, 2)),
            ];
        }
        
        return $history;
    }

    /**
     * Получить историю расходов проекта
     */
    protected function getSpendingHistory(int $projectId): array
    {
        $history = [];
        
        // Группируем расходы по неделям за последние 3 месяца
        $from = Carbon::now()->subMonths(3);
        $completedWorks = CompletedWork::where('project_id', $projectId)
            ->where('created_at', '>=', $from)
            ->withSum('materials as materials_total_cost', 'completed_work_materials.total_amount')
            ->orderBy('created_at')
            ->get();
        
        $weeklySpending = [];
        foreach ($completedWorks as $work) {
            $week = Carbon::parse($work->created_at)->startOfWeek()->toDateString();
            
            if (!isset($weeklySpending[$week])) {
                $weeklySpending[$week] = 0;
            }
            
            $weeklySpending[$week] += $work->materials_total_cost ?? 0;
        }
        
        foreach ($weeklySpending as $week => $amount) {
            $history[] = [
                'date' => $week,
                'amount' => $amount,
            ];
        }
        
        return $history;
    }

    /**
     * Получить историю использования материалов
     */
    protected function getMaterialUsageHistory(int $organizationId, int $months): array
    {
        $from = Carbon::now()->subMonths($months);
        
        $usageHistory = DB::table('material_usage_history')
            ->where('organization_id', $organizationId)
            ->where('used_at', '>=', $from)
            ->orderBy('used_at')
            ->get();
        
        if ($usageHistory->count() > 0) {
            $monthlyUsage = [];
            
            foreach ($usageHistory as $usage) {
                $month = Carbon::parse($usage->used_at)->format('Y-m');
                
                if (!isset($monthlyUsage[$month])) {
                    $monthlyUsage[$month] = [
                        'month' => $month,
                        'total_quantity' => 0,
                        'total_amount' => 0,
                        'materials_count' => 0,
                    ];
                }
                
                $monthlyUsage[$month]['total_quantity'] += $usage->quantity;
                $monthlyUsage[$month]['total_amount'] += $usage->quantity * $usage->unit_price;
                $monthlyUsage[$month]['materials_count']++;
            }
            
            return array_values($monthlyUsage);
        }
        
        $completedWorks = CompletedWork::join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $organizationId)
            ->where('completed_works.created_at', '>=', $from)
            ->orderBy('completed_works.created_at')
            ->select('completed_works.*')
            ->withSum('materials as materials_total_cost', 'completed_work_materials.total_amount')
            ->get();
        
        $monthlyUsage = [];
        
        foreach ($completedWorks as $work) {
            $month = Carbon::parse($work->created_at)->format('Y-m');
            
            if (!isset($monthlyUsage[$month])) {
                $monthlyUsage[$month] = [
                    'month' => $month,
                    'total_quantity' => 0,
                    'total_amount' => 0,
                    'materials_count' => 0,
                ];
            }
            
            $monthlyUsage[$month]['total_quantity'] += $work->quantity ?? 0;
            $monthlyUsage[$month]['total_amount'] += $work->materials_total_cost ?? 0;
            $monthlyUsage[$month]['materials_count']++;
        }
        
        return array_values($monthlyUsage);
    }

    /**
     * Рассчитать линейную регрессию
     */
    protected function calculateLinearRegression(array $data): array
    {
        if (empty($data)) {
            return ['slope' => 0, 'intercept' => 0, 'r_squared' => 0];
        }
        
        $n = count($data);
        $sumX = 0;
        $sumY = 0;
        $sumXY = 0;
        $sumX2 = 0;
        
        foreach ($data as $index => $point) {
            $x = $index;
            $y = $point['progress'] ?? $point['amount'] ?? 0;
            
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }
        
        // y = mx + b
        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);
        $intercept = ($sumY - $slope * $sumX) / $n;
        
        // R-squared (коэффициент детерминации)
        $meanY = $sumY / $n;
        $ssTotal = 0;
        $ssResidual = 0;
        
        foreach ($data as $index => $point) {
            $y = $point['progress'] ?? $point['amount'] ?? 0;
            $predicted = $slope * $index + $intercept;
            
            $ssTotal += pow($y - $meanY, 2);
            $ssResidual += pow($y - $predicted, 2);
        }
        
        $rSquared = $ssTotal > 0 ? 1 - ($ssResidual / $ssTotal) : 0;
        
        return [
            'slope' => $slope,
            'intercept' => $intercept,
            'r_squared' => $rSquared,
        ];
    }

    /**
     * Спрогнозировать дату завершения
     */
    protected function estimateCompletionDate(float $currentProgress, float $slope, float $intercept): ?Carbon
    {
        if ($slope <= 0) {
            // Нет прогресса или регресс
            return null;
        }
        
        // Сколько дней нужно для достижения 100%
        $daysToComplete = (100 - $currentProgress) / $slope;
        
        if ($daysToComplete <= 0) {
            return Carbon::now();
        }
        
        return Carbon::now()->addDays(ceil($daysToComplete));
    }

    /**
     * Спрогнозировать итоговые расходы
     */
    protected function estimateTotalSpending(float $currentSpending, float $slope, Project $project): float
    {
        // Предполагаем, что тренд расходов продолжится до конца проекта
        
        // Получаем длительность проекта
        $contracts = Contract::where('project_id', $project->id)->get();
        $latestEndDate = $contracts->max('end_date');
        
        if (!$latestEndDate) {
            // Если нет даты окончания, прогнозируем на 6 месяцев вперед
            $weeksRemaining = 26; // ~6 месяцев
        } else {
            $weeksRemaining = Carbon::now()->diffInWeeks(Carbon::parse($latestEndDate));
        }
        
        // Прогноз дополнительных расходов
        $additionalSpending = $slope * $weeksRemaining;
        
        return $currentSpending + max(0, $additionalSpending);
    }

    /**
     * Спрогнозировать будущие потребности в материалах
     */
    protected function estimateFutureNeeds(array $usageHistory, float $slope, int $months): float
    {
        $weeksInForecast = $months * 4;
        $estimatedUsage = $slope * $weeksInForecast;
        
        return max(0, $estimatedUsage);
    }

    /**
     * Оценить риск задержки
     */
    protected function assessRisk(int $deviationDays): string
    {
        if ($deviationDays <= 0) {
            return 'low'; // В срок или раньше
        } elseif ($deviationDays <= 7) {
            return 'low'; // Задержка до недели
        } elseif ($deviationDays <= 14) {
            return 'medium'; // Задержка 1-2 недели
        } elseif ($deviationDays <= 30) {
            return 'high'; // Задержка 2-4 недели
        } else {
            return 'critical'; // Задержка больше месяца
        }
    }

    /**
     * Оценить риск превышения бюджета
     */
    protected function assessBudgetRisk(float $overrunPercentage): string
    {
        if ($overrunPercentage <= 0) {
            return 'low';
        } elseif ($overrunPercentage <= 5) {
            return 'low'; // Превышение до 5%
        } elseif ($overrunPercentage <= 10) {
            return 'medium'; // Превышение 5-10%
        } elseif ($overrunPercentage <= 20) {
            return 'high'; // Превышение 10-20%
        } else {
            return 'critical'; // Превышение больше 20%
        }
    }

    /**
     * Рассчитать уровень доверия прогноза
     */
    protected function calculateConfidence(array $data): float
    {
        if (empty($data)) {
            return 0.0;
        }
        
        $dataPoints = count($data);
        
        // Базовый уровень доверия зависит от количества данных
        if ($dataPoints < 3) {
            return 0.3; // Низкое доверие
        } elseif ($dataPoints < 10) {
            return 0.6; // Среднее доверие
        } else {
            return 0.85; // Высокое доверие
        }
    }

    /**
     * Сгенерировать рекомендации по бюджету
     */
    protected function generateBudgetRecommendations(string $risk, float $overrunPercentage): array
    {
        $recommendations = [];
        
        switch ($risk) {
            case 'critical':
                $recommendations[] = 'Немедленно пересмотреть бюджет проекта';
                $recommendations[] = 'Остановить все некритичные работы';
                $recommendations[] = 'Провести аудит расходов';
                break;
            
            case 'high':
                $recommendations[] = 'Оптимизировать расходы на материалы';
                $recommendations[] = 'Рассмотреть возможность увеличения бюджета';
                $recommendations[] = 'Усилить контроль за закупками';
                break;
            
            case 'medium':
                $recommendations[] = 'Внимательно мониторить расходы';
                $recommendations[] = 'Искать возможности для экономии';
                break;
            
            case 'low':
                $recommendations[] = 'Продолжать текущую стратегию';
                $recommendations[] = 'Регулярно проверять прогресс';
                break;
        }
        
        return $recommendations;
    }

    /**
     * Очистить кеш предиктивной аналитики
     */
    public function clearCache(int $organizationId): void
    {
        $patterns = [
            "contract_prediction_*",
            "budget_risk_*",
            "material_forecast_{$organizationId}_*",
            "org_forecast_{$organizationId}",
        ];
        
        foreach ($patterns as $pattern) {
            Cache::forget($pattern);
        }
    }
}

