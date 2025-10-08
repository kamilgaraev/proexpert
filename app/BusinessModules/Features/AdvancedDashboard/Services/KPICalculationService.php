<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use App\Models\User;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\Project;

/**
 * Сервис расчета KPI сотрудников
 * 
 * Предоставляет методы для:
 * - Расчета KPI сотрудников
 * - Получения топ исполнителей
 * - Анализа загрузки ресурсов
 */
class KPICalculationService
{
    protected int $cacheTTL = 300; // 5 минут

    /**
     * Рассчитать KPI конкретного пользователя
     * 
     * @param int $userId ID пользователя
     * @param int $organizationId ID организации
     * @param Carbon $from Начальная дата
     * @param Carbon $to Конечная дата
     * @return array
     */
    public function calculateUserKPI(int $userId, int $organizationId, Carbon $from, Carbon $to): array
    {
        $cacheKey = "user_kpi_{$userId}_{$organizationId}_{$from->format('Y-m-d')}_{$to->format('Y-m-d')}";
        
        return Cache::remember($cacheKey, $this->cacheTTL, function () use ($userId, $organizationId, $from, $to) {
            $user = User::find($userId);
            
            if (!$user) {
                throw new \Exception("User not found");
            }
            
            // Метрики производительности
            $completedWorks = $this->getCompletedWorksCount($userId, $from, $to);
            $workVolume = $this->getWorkVolume($userId, $from, $to);
            $onTimeCompletion = $this->getOnTimeCompletionRate($userId, $from, $to);
            $qualityScore = $this->getQualityScore($userId, $from, $to);
            
            // Финансовые метрики
            $revenueGenerated = $this->getRevenueGenerated($userId, $from, $to);
            $costEfficiency = $this->getCostEfficiency($userId, $from, $to);
            
            // Общий KPI (взвешенная сумма)
            $overallKPI = $this->calculateOverallKPI([
                'completed_works' => $completedWorks,
                'on_time_completion' => $onTimeCompletion,
                'quality_score' => $qualityScore,
                'revenue_generated' => $revenueGenerated,
                'cost_efficiency' => $costEfficiency,
            ]);
            
            return [
                'user_id' => $userId,
                'user_name' => $user->name,
                'period' => [
                    'from' => $from->toIso8601String(),
                    'to' => $to->toIso8601String(),
                ],
                'metrics' => [
                    'completed_works_count' => $completedWorks,
                    'work_volume' => $workVolume,
                    'on_time_completion_rate' => $onTimeCompletion,
                    'quality_score' => $qualityScore,
                    'revenue_generated' => $revenueGenerated,
                    'cost_efficiency' => $costEfficiency,
                ],
                'overall_kpi' => $overallKPI,
                'performance_level' => $this->getPerformanceLevel($overallKPI),
            ];
        });
    }

    /**
     * Получить топ исполнителей организации
     * 
     * @param int $organizationId ID организации
     * @param Carbon $from Начальная дата
     * @param Carbon $to Конечная дата
     * @param int $limit Количество исполнителей
     * @return array
     */
    public function getTopPerformers(int $organizationId, Carbon $from, Carbon $to, int $limit = 10): array
    {
        $cacheKey = "top_performers_{$organizationId}_{$from->format('Y-m-d')}_{$to->format('Y-m-d')}_{$limit}";
        
        return Cache::remember($cacheKey, $this->cacheTTL, function () use ($organizationId, $from, $to, $limit) {
            // Получаем всех пользователей организации, у которых есть выполненные работы
            $userIds = CompletedWork::join('projects', 'completed_works.project_id', '=', 'projects.id')
                ->where('projects.organization_id', $organizationId)
                ->whereBetween('completed_works.created_at', [$from, $to])
                ->distinct()
                ->pluck('completed_works.user_id');
            
            $performers = [];
            
            foreach ($userIds as $userId) {
                try {
                    $kpi = $this->calculateUserKPI($userId, $organizationId, $from, $to);
                    $performers[] = $kpi;
                } catch (\Exception $e) {
                    // Пропускаем пользователей с ошибками
                    continue;
                }
            }
            
            // Сортировка по общему KPI
            usort($performers, fn($a, $b) => $b['overall_kpi'] <=> $a['overall_kpi']);
            
            // Ограничиваем количество
            $topPerformers = array_slice($performers, 0, $limit);
            
            return [
                'period' => [
                    'from' => $from->toIso8601String(),
                    'to' => $to->toIso8601String(),
                ],
                'total_employees' => count($performers),
                'top_performers' => $topPerformers,
                'average_kpi' => $this->calculateAverageKPI($performers),
            ];
        });
    }

    /**
     * Получить анализ загрузки ресурсов (утилизации)
     * 
     * @param int $organizationId ID организации
     * @param Carbon $from Начальная дата
     * @param Carbon $to Конечная дата
     * @return array
     */
    public function getResourceUtilization(int $organizationId, Carbon $from, Carbon $to): array
    {
        $cacheKey = "resource_utilization_{$organizationId}_{$from->format('Y-m-d')}_{$to->format('Y-m-d')}";
        
        return Cache::remember($cacheKey, $this->cacheTTL, function () use ($organizationId, $from, $to) {
            // Все пользователи организации
            // TODO: Реализовать связь User -> Organization
            $users = User::all(); // Placeholder
            
            $utilization = [];
            $totalWorkingDays = $from->diffInWeekdays($to);
            
            foreach ($users as $user) {
                $workedDays = $this->getWorkedDays($user->id, $from, $to);
                $utilizationRate = $totalWorkingDays > 0 ? ($workedDays / $totalWorkingDays) * 100 : 0;
                
                $utilization[] = [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'worked_days' => $workedDays,
                    'total_working_days' => $totalWorkingDays,
                    'utilization_rate' => round($utilizationRate, 2),
                    'status' => $this->getUtilizationStatus($utilizationRate),
                ];
            }
            
            // Сортировка по загрузке (от наименьшей к наибольшей)
            usort($utilization, fn($a, $b) => $a['utilization_rate'] <=> $b['utilization_rate']);
            
            return [
                'period' => [
                    'from' => $from->toIso8601String(),
                    'to' => $to->toIso8601String(),
                ],
                'total_employees' => count($utilization),
                'average_utilization' => $this->calculateAverageUtilization($utilization),
                'employees' => $utilization,
                'underutilized' => array_filter($utilization, fn($u) => $u['utilization_rate'] < 50),
                'optimal' => array_filter($utilization, fn($u) => $u['utilization_rate'] >= 50 && $u['utilization_rate'] <= 90),
                'overutilized' => array_filter($utilization, fn($u) => $u['utilization_rate'] > 90),
            ];
        });
    }

    /**
     * Получить сравнение KPI сотрудников по отделам
     * 
     * @param int $organizationId ID организации
     * @param Carbon $from Начальная дата
     * @param Carbon $to Конечная дата
     * @return array
     */
    public function getKPIByDepartment(int $organizationId, Carbon $from, Carbon $to): array
    {
        // TODO: Реализовать после добавления функционала отделов
        return [
            'message' => 'Department functionality not implemented yet',
        ];
    }

    /**
     * Получить тренд KPI пользователя за период
     * 
     * @param int $userId ID пользователя
     * @param int $organizationId ID организации
     * @param int $months Количество месяцев для анализа
     * @return array
     */
    public function getUserKPITrend(int $userId, int $organizationId, int $months = 6): array
    {
        $cacheKey = "user_kpi_trend_{$userId}_{$organizationId}_{$months}";
        
        return Cache::remember($cacheKey, $this->cacheTTL, function () use ($userId, $organizationId, $months) {
            $trend = [];
            $current = Carbon::now();
            
            for ($i = $months - 1; $i >= 0; $i--) {
                $monthStart = $current->copy()->subMonths($i)->startOfMonth();
                $monthEnd = $current->copy()->subMonths($i)->endOfMonth();
                
                try {
                    $kpi = $this->calculateUserKPI($userId, $organizationId, $monthStart, $monthEnd);
                    
                    $trend[] = [
                        'month' => $monthStart->format('Y-m'),
                        'month_name' => $monthStart->translatedFormat('F Y'),
                        'kpi' => $kpi['overall_kpi'],
                        'completed_works' => $kpi['metrics']['completed_works_count'],
                    ];
                } catch (\Exception $e) {
                    continue;
                }
            }
            
            return [
                'user_id' => $userId,
                'months' => $months,
                'trend' => $trend,
                'average_kpi' => $this->calculateAverageFromTrend($trend),
                'trend_direction' => $this->determineTrendDirection($trend),
            ];
        });
    }

    // ==================== PROTECTED HELPER METHODS ====================

    /**
     * Получить количество выполненных работ
     */
    protected function getCompletedWorksCount(int $userId, Carbon $from, Carbon $to): int
    {
        return CompletedWork::where('user_id', $userId)
            ->whereBetween('created_at', [$from, $to])
            ->count();
    }

    /**
     * Получить объем выполненных работ
     */
    protected function getWorkVolume(int $userId, Carbon $from, Carbon $to): float
    {
        return CompletedWork::where('user_id', $userId)
            ->whereBetween('created_at', [$from, $to])
            ->sum('quantity') ?? 0;
    }

    /**
     * Получить процент выполнения в срок
     */
    protected function getOnTimeCompletionRate(int $userId, Carbon $from, Carbon $to): float
    {
        $totalWorks = CompletedWork::where('user_id', $userId)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('deadline')
            ->count();
        
        if ($totalWorks == 0) {
            return 100.0;
        }
        
        $onTimeWorks = CompletedWork::where('user_id', $userId)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('deadline')
            ->where(function($query) {
                $query->whereNull('completed_at')
                    ->orWhereRaw('completed_at <= deadline');
            })
            ->count();
        
        return round(($onTimeWorks / $totalWorks) * 100, 2);
    }

    /**
     * Получить оценку качества работы
     */
    protected function getQualityScore(int $userId, Carbon $from, Carbon $to): float
    {
        $worksWithRating = CompletedWork::where('user_id', $userId)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('quality_rating')
            ->get();
        
        if ($worksWithRating->isEmpty()) {
            $totalWorks = CompletedWork::where('user_id', $userId)
                ->whereBetween('created_at', [$from, $to])
                ->count();
            
            $reworked = DB::table('completed_works')
                ->where('user_id', $userId)
                ->whereBetween('created_at', [$from, $to])
                ->where('status', 'reworked')
                ->count();
            
            if ($totalWorks == 0) {
                return 100.0;
            }
            
            $reworkRate = ($reworked / $totalWorks);
            $qualityScore = (1 - $reworkRate) * 100;
            
            return round(max(0, min(100, $qualityScore)), 2);
        }
        
        $avgRating = $worksWithRating->avg('quality_rating');
        
        return round(min(100, $avgRating * 20), 2);
    }

    /**
     * Получить сгенерированную выручку
     */
    protected function getRevenueGenerated(int $userId, Carbon $from, Carbon $to): float
    {
        // Выручка от выполненных работ пользователя
        return CompletedWork::where('user_id', $userId)
            ->whereBetween('created_at', [$from, $to])
            ->sum(DB::raw('quantity * unit_price')) ?? 0;
    }

    /**
     * Получить эффективность затрат
     */
    protected function getCostEfficiency(int $userId, Carbon $from, Carbon $to): float
    {
        $revenue = $this->getRevenueGenerated($userId, $from, $to);
        
        $costs = CompletedWork::where('user_id', $userId)
            ->whereBetween('created_at', [$from, $to])
            ->join('completed_work_materials', 'completed_works.id', '=', 'completed_work_materials.completed_work_id')
            ->sum('completed_work_materials.total_amount') ?? 0;
        
        if ($costs == 0) {
            return 100.0;
        }
        
        // Процент прибыли от выручки
        $profit = $revenue - $costs;
        $efficiency = ($profit / $revenue) * 100;
        
        return round(max(0, $efficiency), 2);
    }

    /**
     * Рассчитать общий KPI (взвешенная сумма метрик)
     */
    protected function calculateOverallKPI(array $metrics): float
    {
        // Веса для разных метрик (сумма = 1.0)
        $weights = [
            'completed_works' => 0.2,      // 20% - количество работ
            'on_time_completion' => 0.25,  // 25% - выполнение в срок
            'quality_score' => 0.25,       // 25% - качество
            'cost_efficiency' => 0.3,      // 30% - эффективность затрат
        ];
        
        // Нормализация метрик к шкале 0-100
        $normalized = [
            'completed_works' => min(100, $metrics['completed_works'] * 2), // Предполагаем 50 работ = 100%
            'on_time_completion' => $metrics['on_time_completion'],
            'quality_score' => $metrics['quality_score'],
            'cost_efficiency' => $metrics['cost_efficiency'],
        ];
        
        // Взвешенная сумма
        $kpi = 0;
        foreach ($weights as $metric => $weight) {
            $kpi += $normalized[$metric] * $weight;
        }
        
        return round($kpi, 2);
    }

    /**
     * Определить уровень производительности
     */
    protected function getPerformanceLevel(float $kpi): string
    {
        if ($kpi >= 90) {
            return 'exceptional';
        } elseif ($kpi >= 75) {
            return 'high';
        } elseif ($kpi >= 60) {
            return 'good';
        } elseif ($kpi >= 40) {
            return 'average';
        } else {
            return 'low';
        }
    }

    /**
     * Рассчитать средний KPI
     */
    protected function calculateAverageKPI(array $performers): float
    {
        if (empty($performers)) {
            return 0;
        }
        
        $totalKPI = array_sum(array_column($performers, 'overall_kpi'));
        
        return round($totalKPI / count($performers), 2);
    }

    /**
     * Получить количество отработанных дней
     */
    protected function getWorkedDays(int $userId, Carbon $from, Carbon $to): int
    {
        // Подсчитываем уникальные дни с выполненными работами
        return CompletedWork::where('user_id', $userId)
            ->whereBetween('created_at', [$from, $to])
            ->select(DB::raw('DATE(created_at) as date'))
            ->distinct()
            ->count();
    }

    /**
     * Определить статус загрузки
     */
    protected function getUtilizationStatus(float $rate): string
    {
        if ($rate < 50) {
            return 'underutilized';
        } elseif ($rate <= 90) {
            return 'optimal';
        } else {
            return 'overutilized';
        }
    }

    /**
     * Рассчитать среднюю загрузку
     */
    protected function calculateAverageUtilization(array $utilization): float
    {
        if (empty($utilization)) {
            return 0;
        }
        
        $totalRate = array_sum(array_column($utilization, 'utilization_rate'));
        
        return round($totalRate / count($utilization), 2);
    }

    /**
     * Рассчитать средний KPI из тренда
     */
    protected function calculateAverageFromTrend(array $trend): float
    {
        if (empty($trend)) {
            return 0;
        }
        
        $totalKPI = array_sum(array_column($trend, 'kpi'));
        
        return round($totalKPI / count($trend), 2);
    }

    /**
     * Определить направление тренда
     */
    protected function determineTrendDirection(array $trend): string
    {
        if (count($trend) < 2) {
            return 'stable';
        }
        
        $firstHalf = array_slice($trend, 0, ceil(count($trend) / 2));
        $secondHalf = array_slice($trend, ceil(count($trend) / 2));
        
        $firstAvg = $this->calculateAverageFromTrend($firstHalf);
        $secondAvg = $this->calculateAverageFromTrend($secondHalf);
        
        $difference = $secondAvg - $firstAvg;
        
        if ($difference > 5) {
            return 'improving';
        } elseif ($difference < -5) {
            return 'declining';
        } else {
            return 'stable';
        }
    }

    /**
     * Очистить кеш KPI
     */
    public function clearCache(int $organizationId): void
    {
        $patterns = [
            "user_kpi_*_{$organizationId}_*",
            "top_performers_{$organizationId}_*",
            "resource_utilization_{$organizationId}_*",
            "user_kpi_trend_*_{$organizationId}_*",
        ];
        
        foreach ($patterns as $pattern) {
            Cache::forget($pattern);
        }
    }
}

